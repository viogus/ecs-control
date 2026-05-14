<?php

declare(strict_types=1);

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class MonitorService
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $notificationService;
    private $ddnsService;
    private $bssService;

    public function __construct($db, $configManager, $aliyunService, $notificationService, $ddnsService, $bssService = null)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->aliyunService = $aliyunService;
        $this->notificationService = $notificationService;
        $this->ddnsService = $ddnsService;
        $this->bssService = $bssService;
    }

    public function run(): string
    {
        $this->db->pruneLogs(30, 3);
        $this->db->pruneStats();

        if (date('H') === '04' && date('i') === '00') {
            $this->db->vacuum();
        }

        $logs = [];
        $currentTime = time();

        $threshold = (int) $this->configManager->get('traffic_threshold', 95);
        $shutdownMode = $this->configManager->get('shutdown_mode', 'KeepCharging');
        $thresholdAction = $this->configManager->get('threshold_action', 'stop_and_notify');
        $keepAlive = $this->configManager->get('keep_alive', '0') === '1';
        $monthlyAutoStart = $this->configManager->get('monthly_auto_start', '0') === '1';
        $userInterval = (int) $this->configManager->get('api_interval', 600);

        $accounts = $this->configManager->getAccounts();

        foreach ($accounts as $account) {
            $this->processAccount($account, $currentTime, $threshold, $shutdownMode, $thresholdAction, $keepAlive, $monthlyAutoStart, $userInterval, $logs);
        }

        $this->configManager->updateLastRunTime(time());

        $lastDdnsSync = (int) ($this->configManager->get('last_ddns_sync', 0));
        if ((time() - $lastDdnsSync) >= 600) {
            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), 'Cron 周期同步');
            $pdo = $this->db->getPdo();
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('last_ddns_sync', ?)")->execute([time()]);
            $this->configManager->load();
        }

        return implode(PHP_EOL, $logs);
    }

    private function notifyStatusChangeIfNeeded($account, $fromStatus, $toStatus, $reason = '')
    {
        $fromStatus = InstanceStatus::tryFrom((string) ($fromStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;
        $toStatus = InstanceStatus::tryFrom((string) ($toStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;

        if ($fromStatus === $toStatus || !in_array($toStatus, [InstanceStatus::Running, InstanceStatus::Stopped], true)) {
            return;
        }

        if ($fromStatus === InstanceStatus::Unknown || $this->isRecentlyCreatedInstance($account)) {
            return;
        }

        $accountLabel = Helpers::getAccountLogLabel($account);
        $result = $this->notificationService->notifyInstanceStatusChanged($accountLabel, $account, $fromStatus->value, $toStatus->value, $reason);
        Helpers::logNotificationResult($this->db, $result, $accountLabel);
    }

    private function isRecentlyCreatedInstance($account)
    {
        $instanceId = trim((string) ($account['instance_id'] ?? ''));
        if ($instanceId === '') {
            return false;
        }

        try {
            $stmt = $this->db->getPdo()->prepare("
                SELECT updated_at
                FROM ecs_create_tasks
                WHERE instance_id = ?
                    AND status = 'success'
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $updatedAt = (int) $stmt->fetchColumn();
            return $updatedAt > 0 && (time() - $updatedAt) < 900;
        } catch (Exception $e) {
            return false;
        }
    }

    private function isSameMonth($timestamp, $currentTime)
    {
        if (empty($timestamp)) return false;
        return date('Y-m', (int) $timestamp) === date('Y-m', (int) $currentTime);
    }

    private function shouldRunScheduleAt($currentTime, $targetTime, $lastRunDate)
    {
        $targetTime = trim((string) $targetTime);
        if ($targetTime === '' || !preg_match('/^\d{2}:\d{2}$/', $targetTime)) return false;

        $today = date('Y-m-d', $currentTime);
        if ((string) $lastRunDate === $today) return false;

        $targetMinutes = $this->timeToMinutes($targetTime);
        $currentMinutes = (int) date('G', $currentTime) * 60 + (int) date('i', $currentTime);
        return abs($currentMinutes - $targetMinutes) <= 5;
    }

    private function timeToMinutes($hhmm)
    {
        $parts = explode(':', $hhmm);
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    private function isCredentialInvalidTrafficStatus($status)
    {
        return trim((string) $status) === 'auth_error';
    }

    private function safeGetTraffic($account)
    {
        return Helpers::safeGetCdtTraffic($this->aliyunService, $account, $this->db);
    }

    private function getGroupTrafficUsed($account)
    {
        $pdo = $this->db->getPdo();
        $groupKey = trim((string) ($account['group_key'] ?? ''));
        $billingMonth = date('Y-m');

        if ($groupKey !== '') {
            $stmt = $pdo->prepare("SELECT traffic_used FROM accounts WHERE group_key = ? AND traffic_billing_month = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$groupKey, $billingMonth]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT traffic_used FROM accounts WHERE access_key_id = ? AND region_id = ? AND traffic_billing_month = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$account['access_key_id'] ?? '', $account['region_id'] ?? '', $billingMonth]);
        return (float) $stmt->fetchColumn();
    }

    private function getMeteredOutboundTraffic($account)
    {
        if (empty($account['id']) || empty($account['instance_id'])) {
            throw new Exception('缺少账号 ID 或 Instance ID，无法按实例统计公网出口流量');
        }

        $billingMonth = date('Y-m');
        $monthStartMs = strtotime($billingMonth . '-01 00:00:00') * 1000;
        $record = $this->db->getInstanceTrafficUsage($account['id'], $account['instance_id'], $billingMonth);

        $trafficBytes = $record ? (float) ($record['traffic_bytes'] ?? 0) : 0.0;
        $lastSampleMs = $record ? (int) ($record['last_sample_ms'] ?? 0) : 0;
        if ($lastSampleMs < $monthStartMs) {
            $lastSampleMs = $monthStartMs;
            $trafficBytes = 0.0;
        }

        $safeEndSeconds = max(strtotime($billingMonth . '-01 00:00:00'), time() - 90);
        $endMs = (int) (floor($safeEndSeconds / 60) * 60 * 1000);

        if ($endMs > $lastSampleMs) {
            $delta = $this->aliyunService->getInstanceOutboundTrafficDelta($account, $lastSampleMs, $endMs);
            $trafficBytes += (float) ($delta['bytes'] ?? 0);
            $lastSampleMs = max($lastSampleMs, (int) ($delta['lastSampleMs'] ?? $lastSampleMs));
        }

        $this->db->upsertInstanceTrafficUsage(
            (int) $account['id'],
            $account['instance_id'],
            $billingMonth,
            $trafficBytes,
            $lastSampleMs
        );

        return $trafficBytes / 1024 / 1024 / 1024;
    }

    private function safeGetInstanceStatus($account)
    {
        try {
            return $this->aliyunService->getInstanceStatus($account);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例状态查询失败 [" . Helpers::getAccountLogLabel($account) . "]: " . strip_tags($e->getMessage()));
            return 'Unknown';
        }
    }

    private function safeControlInstance($account, InstanceAction $action, $shutdownMode = 'KeepCharging')
    {
        try {
            return $this->aliyunService->controlInstance($account, $action, $shutdownMode);
        } catch (ClientException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: 权限不足或配置错误 (" . $e->getErrorCode() . ")");
            return false;
        } catch (ServerException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: " . $e->getErrorCode() . " - " . strip_tags($e->getErrorMessage()));
            return false;
        } catch (\Exception $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: " . strip_tags($e->getMessage()));
            return false;
        }
    }

    // ---- processAccount orchestrator ----

    private function processAccount($account, int $currentTime, int $threshold, string $shutdownMode, string $thresholdAction, bool $keepAlive, bool $monthlyAutoStart, int $userInterval, array &$logs): void
    {
        $accountLabel = Helpers::getAccountLogLabel($account);
        $s = [
            'accountLabel' => $accountLabel,
            'logPrefix' => "[{$accountLabel}]",
            'accountGroupKey' => $account['group_key'] ?: substr(sha1(($account['access_key_id'] ?? '') . '|' . ($account['region_id'] ?? '')), 0, 16),
            'actions' => [],
            'status' => $account['instance_status'] ?? 'Unknown',
            'traffic' => 0.0,
            'apiStatusLog' => '',
            'scheduleBlockedByTraffic' => !empty($account['schedule_blocked_by_traffic']),
            'protectionSuspended' => !empty($account['protection_suspended']),
            'protectionSuspendReason' => trim((string) ($account['protection_suspend_reason'] ?? '')),
            'protectionSuspendNotifiedAt' => (int) ($account['protection_suspend_notified_at'] ?? 0),
        ];

        // 1. 自适应心跳
        $this->handleAdaptiveHeartbeat($account, $currentTime, $userInterval, $s);

        // 2. 流量熔断
        $s['requiresTrafficProtection'] = $this->handleTrafficCircuitBreaker($account, $currentTime, $threshold, $shutdownMode, $thresholdAction, $s);

        // 2b. 费用熔断
        $this->handleCostCircuitBreaker($account, $currentTime, $shutdownMode, $s);

        // 3. 定时开关机
        $this->handleScheduledOps($account, $currentTime, $shutdownMode, $s);

        // 4. 每月自动开机
        $this->handleMonthlyAutoStart($account, $currentTime, $monthlyAutoStart, $s);

        // 5. 保活逻辑
        $this->handleKeepAlive($account, $currentTime, $keepAlive, $s);

        // 汇总日志 (traffic data from phase 2 to avoid duplicate DB query)
        $actionLog = empty($s['actions']) ? "无动作" : implode(", ", $s['actions']);
        $usagePercent = $s['trafficUsagePercent'] ?? 0;
        $trafficDesc = "账号出口流量:{$usagePercent}%";
        $logLine = sprintf("%s %s | %s | %s | %s", $s['logPrefix'], $actionLog, $trafficDesc, $s['status'], $s['apiStatusLog']);

        $this->db->addLog('heartbeat', $logLine);
        $logs[] = $logLine;
    }

    // ---- Phase 1: 自适应心跳 ----

    private function handleAdaptiveHeartbeat($account, int $currentTime, int $userInterval, array &$s): void
    {
        $lastUpdate = $account['updated_at'] ?? 0;
        $cachedStatus = InstanceStatus::tryFrom($account['instance_status'] ?? 'Unknown') ?? InstanceStatus::Unknown;
        $isTransientState = $cachedStatus->isTransient();
        $currentInterval = $isTransientState ? 60 : $userInterval;

        $shouldCheckApi = ($currentTime - $lastUpdate) > $currentInterval;
        if (date('i') === '00') {
            $shouldCheckApi = true;
        }

        $newUpdateTime = $currentTime;

        if ($shouldCheckApi) {
            $trafficResult = $this->safeGetTraffic($account);
            $status = $this->safeGetInstanceStatus($account);

            if ($status === 'Unknown') {
                usleep(500000);
                $status = $this->safeGetInstanceStatus($account);
            }

            $metadata = [
                'traffic_api_status' => $trafficResult['status'] ?? 'ok',
                'traffic_api_message' => $trafficResult['message'] ?? ''
            ];
            $authInvalid = $this->isCredentialInvalidTrafficStatus($trafficResult['status'] ?? '');

            if ($authInvalid) {
                $metadata['protection_suspended'] = 1;
                $metadata['protection_suspend_reason'] = 'credential_invalid';
                $metadata['protection_suspend_notified_at'] = $s['protectionSuspendNotifiedAt'];
                $s['protectionSuspended'] = true;
                $s['protectionSuspendReason'] = 'credential_invalid';
            } elseif ($s['protectionSuspended'] && $s['protectionSuspendReason'] === 'credential_invalid') {
                $metadata['protection_suspended'] = 0;
                $metadata['protection_suspend_reason'] = '';
                $metadata['protection_suspend_notified_at'] = 0;
                $s['protectionSuspended'] = false;
                $s['protectionSuspendReason'] = '';
                $s['protectionSuspendNotifiedAt'] = 0;
                $this->db->addLog('info', "账号鉴权已恢复，自动停机保护已重新启用 [{$s['accountLabel']}]");
            }

            if (empty($trafficResult['success'])) {
                $s['traffic'] = $account['traffic_used'];
                $s['apiStatusLog'] = "流量接口异常";
                $newUpdateTime = $lastUpdate;
            } else {
                $s['traffic'] = (float) ($trafficResult['value'] ?? 0);
                $s['apiStatusLog'] = "已更新";
                $this->db->addHourlyStat($account['id'], $s['traffic']);
                $this->db->addDailyStat($account['id'], $s['traffic']);
            }

            $statusEnum = InstanceStatus::tryFrom($status) ?? InstanceStatus::Unknown;

            if ($statusEnum === InstanceStatus::Unknown) {
                $newUpdateTime = $lastUpdate;
                $s['apiStatusLog'] .= "(状态Unknown)";
            } else {
                $s['apiStatusLog'] .= $statusEnum->isTransient() ? " [过渡态]" : " [稳定态]";
            }

            $this->notifyStatusChangeIfNeeded($account, $cachedStatus->value, $status, '系统同步检测到实例状态变化。');
            $this->configManager->updateAccountStatus($account['id'], $s['traffic'], $status, $newUpdateTime, $metadata);
            $s['status'] = $status;
        } else {
            $s['traffic'] = $account['traffic_used'];
            $s['status'] = $account['instance_status'];
            $timeLeft = $currentInterval - ($currentTime - $lastUpdate);
            $s['apiStatusLog'] = "缓存({$timeLeft}s)";
        }
    }

    // ---- Phase 2: 流量熔断 ----

    private function handleTrafficCircuitBreaker($account, int $currentTime, int $threshold, string $shutdownMode, string $thresholdAction, array &$s): bool
    {
        $maxTraffic = $account['max_traffic'];
        $accountTraffic = $this->getGroupTrafficUsed($account);
        $usagePercent = ($maxTraffic > 0) ? round(($accountTraffic / $maxTraffic) * 100, 2) : 0;
        $isOverThreshold = $usagePercent >= $threshold;
        $isHardLimitExceeded = $maxTraffic > 0 && $accountTraffic >= $maxTraffic;
        $requiresTrafficProtection = $isOverThreshold || $isHardLimitExceeded;

        // Save for log line in orchestrator (avoids duplicate DB query)
        $s['trafficUsagePercent'] = $usagePercent;
        $s['trafficAccountUsed'] = $accountTraffic;

        if (!$requiresTrafficProtection) {
            return false;
        }

        if ($thresholdAction === 'stop_and_notify') {
            if ($s['protectionSuspended'] && $s['protectionSuspendReason'] === 'credential_invalid') {
                if ($s['protectionSuspendNotifiedAt'] <= 0) {
                    $s['actions'][] = "账号密钥失效，已暂停自动停机";
                    $notifyResult = $this->notificationService->notifyCredentialInvalid($account['access_key_id'], $accountTraffic, $usagePercent, $threshold);
                    Helpers::logNotificationResult($this->db, $notifyResult, $s['accountLabel']);
                    $this->db->addLog('warning', "检测到账号鉴权失效，已暂停自动停机保护 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
                    $s['protectionSuspendNotifiedAt'] = $currentTime;
                    $this->configManager->updateAccountStatus($account['id'], $s['traffic'], $s['status'], $account['updated_at'] ?? 0, [
                        'protection_suspended' => 1, 'protection_suspend_reason' => 'credential_invalid',
                        'protection_suspend_notified_at' => $s['protectionSuspendNotifiedAt']
                    ]);
                } else {
                    $s['apiStatusLog'] .= " [鉴权失效,已暂停自动停机]";
                }
            } else {
                $canAttemptStop = !in_array($s['status'], [InstanceStatus::Stopped->value, InstanceStatus::Stopping->value, InstanceStatus::Released->value], true);
                if ($canAttemptStop) {
                    if ($this->safeControlInstance($account, InstanceAction::Stop, $shutdownMode)) {
                        $previousStatus = $s['status'];
                        $s['actions'][] = $isHardLimitExceeded ? "已超量自动停机" : "接近上限自动停机";
                        $this->db->addLog('warning', "账号出口流量达到保护线，已自动停机 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
                        $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
                        $this->configManager->updateScheduleBlockedByTrafficForGroup($s['accountGroupKey'], true);
                        $this->notifyStatusChangeIfNeeded($account, $previousStatus, InstanceStatus::Stopping->value, '流量达到保护线，已自动停机。');
                        $s['status'] = InstanceStatus::Stopping->value;
                        $s['scheduleBlockedByTraffic'] = true;
                    } else {
                        $s['actions'][] = "自动停机失败";
                        $this->db->addLog('error', "账号出口流量达到保护线，但自动停机失败 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
                    }
                }
            }

            if (!empty($s['actions']) && !($s['protectionSuspended'] && $s['protectionSuspendReason'] === 'credential_invalid')) {
                $mailRes = $this->notificationService->sendTrafficWarning($account['access_key_id'], $accountTraffic, $usagePercent, implode(',', $s['actions']), $threshold);
                Helpers::logNotificationResult($this->db, $mailRes, $s['accountLabel']);
            }
        } else {
            // notify_only mode — always dispatch notification
            $s['actions'][] = "超量提醒";
            $this->db->addLog('warning', "账号出口流量超限触发提醒 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
            $mailRes = $this->notificationService->sendTrafficWarning($account['access_key_id'], $accountTraffic, $usagePercent, '超量提醒', $threshold);
            Helpers::logNotificationResult($this->db, $mailRes, $s['accountLabel']);
        }

        return true;
    }

    // ---- Phase 2b: 费用熔断 ----

    private function handleCostCircuitBreaker($account, int $currentTime, string $shutdownMode, array &$s): bool
    {
        if (!$this->bssService) return false;
        $enabled = $this->configManager->get('cost_threshold_enabled', '0') === '1';
        if (!$enabled) return false;

        $threshold = (float) $this->configManager->get('cost_threshold', '0.48');
        if ($threshold <= 0) return false;

        $canAttemptStop = !in_array($s['status'], [InstanceStatus::Stopped->value, InstanceStatus::Stopping->value, InstanceStatus::Released->value], true);
        if (!$canAttemptStop || $s['protectionSuspended']) return false;
        if ($this->isCostQueryInCooldown($account, $currentTime)) return false;

        try {
            $bill = $this->bssService->getInstanceBill(
                $account['access_key_id'], $account['access_key_secret'],
                $account['instance_id'], date('Y-m'), $account['site_type'] ?? 'china'
            );
            $cost = (float) ($bill['TotalCost'] ?? 0);
            $this->clearCostQueryFailureCooldown($account);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "费用查询失败 [{$s['accountLabel']}]: " . strip_tags($e->getMessage()));
            $this->setCostQueryFailureCooldown($account, $currentTime + 300);
            return false;
        }

        if ($cost >= $threshold) {
            $previousStatus = $s['status'];
            if ($this->safeControlInstance($account, InstanceAction::Stop, $shutdownMode)) {
                $s['actions'][] = "费用超限自动停机";
                $this->db->addLog('warning', "当月费用 \${$cost} 超过阈值 \${$threshold}，已自动停机 [{$s['accountLabel']}]");
                $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
                $this->configManager->updateAutoStartBlocked($account['id'], true);
                $this->configManager->updateScheduleBlockedByTrafficForGroup($s['accountGroupKey'], true);
                $this->notifyStatusChangeIfNeeded($account, $previousStatus, InstanceStatus::Stopping->value, "当月费用 \${$cost} 超过阈值，已自动停机。");
                $notifyRes = $this->notificationService->sendTrafficWarning($account['access_key_id'], $cost, ($cost / $threshold) * 100, '费用超限自动停机', (int) $threshold);
                Helpers::logNotificationResult($this->db, $notifyRes, $s['accountLabel']);
                $s['status'] = InstanceStatus::Stopping->value;
                $s['scheduleBlockedByTraffic'] = true;
                return true;
            } else {
                $s['actions'][] = "费用超限停机失败";
                $this->db->addLog('error', "当月费用 \${$cost} 超过阈值 \${$threshold}，但自动停机失败 [{$s['accountLabel']}]");
            }
        }
        return false;
    }

    private function costQueryFailureCooldownKey($account): string
    {
        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId > 0) {
            return "cost_query_failure_until_{$accountId}";
        }

        return 'cost_query_failure_until_' . md5(($account['access_key_id'] ?? '') . '|' . ($account['instance_id'] ?? ''));
    }

    private function isCostQueryInCooldown($account, int $currentTime): bool
    {
        $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
        $stmt->execute([$this->costQueryFailureCooldownKey($account)]);
        return (int) $stmt->fetchColumn() > $currentTime;
    }

    private function setCostQueryFailureCooldown($account, int $cooldownUntil): void
    {
        $this->db->getPdo()
            ->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
            ->execute([$this->costQueryFailureCooldownKey($account), (string) $cooldownUntil]);
    }

    private function clearCostQueryFailureCooldown($account): void
    {
        $this->db->getPdo()
            ->prepare("DELETE FROM settings WHERE key = ?")
            ->execute([$this->costQueryFailureCooldownKey($account)]);
    }

    // ---- Phase 3: 定时开关机 ----

    private function handleScheduledOps($account, int $currentTime, string $shutdownMode, array &$s): void
    {
        $scheduleEnabled = !empty($account['schedule_enabled']);
        $scheduleStartEnabled = !empty($account['schedule_start_enabled']);
        $scheduleStopEnabled = !empty($account['schedule_stop_enabled']);
        $startTime = trim((string) ($account['start_time'] ?? ''));
        $stopTime = trim((string) ($account['stop_time'] ?? ''));
        $today = date('Y-m-d', $currentTime);

        $scheduleAllowed = $scheduleEnabled && !$s['scheduleBlockedByTraffic'] && !($s['requiresTrafficProtection'] ?? false);
        $isStableState = !in_array($s['status'], [InstanceStatus::Starting->value, InstanceStatus::Stopping->value, InstanceStatus::Pending->value, InstanceStatus::Releasing->value, InstanceStatus::Released->value], true);

        // 定时停机
        if ($scheduleAllowed && $scheduleStopEnabled && $this->shouldRunScheduleAt($currentTime, $stopTime, $account['schedule_last_stop_date'] ?? '')) {
            if ($isStableState && $s['status'] === InstanceStatus::Running->value) {
                if ($this->safeControlInstance($account, InstanceAction::Stop, $shutdownMode)) {
                    $s['actions'][] = "定时停机";
                    $this->db->addLog('info', "执行定时停机 [{$s['accountLabel']}] {$stopTime}");
                    $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
                    $this->configManager->updateAutoStartBlocked($account['id'], true);
                    $this->configManager->updateScheduleExecutionState($account['id'], 'stop', $today);
                    $scheduleNotify = $this->notificationService->notifySchedule('定时停机', $account, "已按计划时间 {$stopTime} 执行停机，停机方式沿用系统设置。");
                    Helpers::logNotificationResult($this->db, $scheduleNotify, $s['accountLabel']);
                    $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Running->value, InstanceStatus::Stopping->value, '已按计划执行定时停机。');
                    $s['status'] = InstanceStatus::Stopping->value;
                } else {
                    $s['apiStatusLog'] .= " [定时停机失败]";
                }
            } else {
                $this->configManager->updateScheduleExecutionState($account['id'], 'stop', $today);
            }
        }

        // 定时开机
        if ($scheduleAllowed && $scheduleStartEnabled && $this->shouldRunScheduleAt($currentTime, $startTime, $account['schedule_last_start_date'] ?? '')) {
            if ($isStableState && $s['status'] === InstanceStatus::Stopped->value) {
                if ($this->safeControlInstance($account, InstanceAction::Start)) {
                    $s['actions'][] = "定时开机";
                    $this->db->addLog('info', "执行定时开机 [{$s['accountLabel']}] {$startTime}");
                    $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Starting->value, $currentTime);
                    $this->configManager->updateAutoStartBlocked($account['id'], false);
                    $this->configManager->updateScheduleExecutionState($account['id'], 'start', $today);
                    $scheduleNotify = $this->notificationService->notifySchedule('定时开机', $account, "已按计划时间 {$startTime} 执行开机。");
                    Helpers::logNotificationResult($this->db, $scheduleNotify, $s['accountLabel']);
                    $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Stopped->value, InstanceStatus::Starting->value, '已按计划执行定时开机。');
                    $this->ddnsService->syncForAccounts([$account], '定时开机后');
                    $s['status'] = InstanceStatus::Starting->value;
                } else {
                    $s['apiStatusLog'] .= " [定时开机失败]";
                }
            } else {
                $this->configManager->updateScheduleExecutionState($account['id'], 'start', $today);
            }
        }
    }

    // ---- Phase 4: 每月自动开机 ----

    private function handleMonthlyAutoStart($account, int $currentTime, bool $monthlyAutoStart, array &$s): void
    {
        $autoStartBlocked = !empty($account['auto_start_blocked']);
        if (!$monthlyAutoStart || $autoStartBlocked || ($s['requiresTrafficProtection'] ?? false) || $s['scheduleBlockedByTraffic'] || date('j', $currentTime) !== '1') {
            return;
        }

        $lastMonthlyStart = (int) ($account['last_keep_alive_at'] ?? 0);
        if ($s['status'] !== InstanceStatus::Stopped->value || $this->isSameMonth($lastMonthlyStart, $currentTime)) {
            return;
        }

        if ($this->safeControlInstance($account, InstanceAction::Start)) {
            $s['actions'][] = "月初自动开机";
            $this->db->addLog('info', "执行月初自动开机 [{$s['accountLabel']}]");
            $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Starting->value, $currentTime);
            $this->configManager->updateLastKeepAlive($account['id'], $currentTime);
            $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Stopped->value, InstanceStatus::Starting->value, '每月 1 号自动开机已执行。');
            $this->ddnsService->syncForAccounts([$account], '月初自动开机后');
            $s['status'] = InstanceStatus::Starting->value;
        } else {
            $s['apiStatusLog'] .= " [月初自动开机失败,下次重试]";
        }
    }

    // ---- Phase 5: 保活逻辑 ----

    private function handleKeepAlive($account, int $currentTime, bool $keepAlive, array &$s): void
    {
        $autoStartBlocked = !empty($account['auto_start_blocked']);
        if (!$keepAlive || $autoStartBlocked || ($s['requiresTrafficProtection'] ?? false) || $s['scheduleBlockedByTraffic']) {
            return;
        }

        if ($s['status'] !== InstanceStatus::Stopped->value) {
            return;
        }

        if ($this->safeControlInstance($account, InstanceAction::Start)) {
            $s['actions'][] = "保活启动";
            $this->db->addLog('info', "执行保活启动 [{$s['accountLabel']}]");

            $mailRes = $this->notificationService->notifySchedule("保活启动", $account, "检测到实例非预期关机，已尝试自动启动。");
            Helpers::logNotificationResult($this->db, $mailRes, $s['accountLabel']);

            $this->configManager->updateAccountStatus($account['id'], $s['traffic'], InstanceStatus::Starting->value, $currentTime);
            $this->configManager->updateLastKeepAlive($account['id'], $currentTime);
            $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Stopped->value, InstanceStatus::Starting->value, '检测到实例非预期关机，保活已尝试自动启动。');
            $this->ddnsService->syncForAccounts([$account], '保活启动后');
            $s['status'] = InstanceStatus::Starting->value;
        } else {
            $s['apiStatusLog'] .= " [保活启动失败,下次重试]";
        }
    }
}
