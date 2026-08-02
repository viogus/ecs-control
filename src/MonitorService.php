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
    private $accountRefresher;

    public function __construct($db, $configManager, $aliyunService, $notificationService, $ddnsService, $bssService = null, $accountRefresher = null)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->aliyunService = $aliyunService;
        $this->notificationService = $notificationService;
        $this->ddnsService = $ddnsService;
        $this->bssService = $bssService;
        $this->accountRefresher = $accountRefresher;
    }

    public function run(): string
    {
        $this->db->pruneLogs(30, 3);
        $this->db->pruneStats();
        $this->configManager->maybeVacuum();

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
            // 逐账号隔离异常:单个账号失败不中断整轮(后续账号、释放队列、Telegram 仍会执行)
            try {
                $this->processAccount($account, $currentTime, $threshold, $shutdownMode, $thresholdAction, $keepAlive, $monthlyAutoStart, $userInterval, $logs);
            } catch (\Throwable $e) {
                $label = Helpers::getAccountLogLabel($account);
                $logs[] = "[异常] 账号巡检失败 [{$label}]: " . strip_tags($e->getMessage());
                $this->db->addLog('error', "账号巡检异常 [{$label}]: " . strip_tags($e->getMessage()));
            }
        }

        $this->configManager->updateLastRunTime(time());

        $lastDdnsSync = $this->configManager->getLastDdnsSyncTime();
        if ((time() - $lastDdnsSync) >= 600) {
            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), 'Cron 周期同步');
            $this->configManager->updateLastDdnsSyncTime(time());
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

    /**
     * 带冷却的流量告警:同一账号同账单月内重复触发(停机失败/notify_only)时,
     * 每轮 cron 只发一次通知,冷却期内静默跳过,避免刷屏与重复通知。
     */
    private function notifyTrafficWarningWithCooldown($account, $traffic, $percent, $actionText, $threshold, int $currentTime): void
    {
        $accountId = (int) ($account->id ?? 0);
        $keySuffix = $accountId > 0
            ? (string) $accountId
            : md5(($account->accessKeyId ?? '') . '|' . ($account->instanceId ?? ''));
        $key = 'traffic_alert_' . $keySuffix . '_' . date('Y-m', $currentTime);

        $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
        $stmt->execute([$key]);
        $lastSent = (int) $stmt->fetchColumn();
        if ($lastSent > 0 && ($currentTime - $lastSent) < 3600) {
            return;
        }

        $res = $this->notificationService->sendTrafficWarning($account->accessKeyId, $traffic, $percent, $actionText, $threshold);
        Helpers::logNotificationResult($this->db, $res, Helpers::getAccountLogLabel($account));
        $this->db->getPdo()
            ->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
            ->execute([$key, (string) $currentTime]);
    }

    private function isRecentlyCreatedInstance($account)
    {
        $instanceId = trim((string) ($account->instanceId ?? ''));
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
            $this->db->addLog('warning', '新建实例判定查询失败: ' . strip_tags($e->getMessage()));
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
        // 目标时间已到且当天未执行过:不提前执行;
        // 60 分钟宽限窗口内补执行(cron 错过整点仍可执行),超窗后当天不再触发,
        // 避免目标时间设为 00:xx 时一整天任意时刻都被误触发
        $minutesSinceTarget = $currentMinutes - $targetMinutes;
        return $minutesSinceTarget >= 0 && $minutesSinceTarget <= 60;
    }

    private function timeToMinutes($hhmm)
    {
        $parts = explode(':', $hhmm);
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    /**
     * CDT 失败标记的 key 后缀:优先账号 id,缺省时用 AK+实例的哈希,与 AccountRefresher 保持一致。
     */
    private function cdtFailureKeySuffix($account): string
    {
        $accountId = (int) ($account->id ?? 0);
        if ($accountId > 0) {
            return (string) $accountId;
        }
        return md5(($account->accessKeyId ?? '') . '|' . ($account->instanceId ?? ''));
    }


    private function getGroupTrafficUsed($account)
    {
        $pdo = $this->db->getPdo();
        $groupKey = trim((string) ($account->groupKey ?? ''));
        $billingMonth = date('Y-m');

        if ($groupKey !== '') {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(traffic_used), 0) FROM accounts WHERE group_key = ? AND traffic_billing_month = ?");
            $stmt->execute([$groupKey, $billingMonth]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(traffic_used), 0) FROM accounts WHERE access_key_id = ? AND region_id = ? AND traffic_billing_month = ?");
        $stmt->execute([$account->accessKeyId ?? '', $account->regionId ?? '', $billingMonth]);
        return (float) $stmt->fetchColumn();
    }

    private function safeControlInstance($account, string $action, $shutdownMode = 'KeepCharging')
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
            'accountGroupKey' => $account->groupKey ?: substr(sha1(($account->accessKeyId ?? '') . '|' . ($account->regionId ?? '')), 0, 16),
            'actions' => [],
            'status' => $account->instanceStatus ?? InstanceStatus::Unknown->value,
            'traffic' => 0.0,
            'apiStatusLog' => '',
            'scheduleBlockedByTraffic' => !empty($account->scheduleBlockedByTraffic),
            'protectionSuspended' => !empty($account->protectionSuspended),
            'protectionSuspendReason' => trim((string) ($account->protectionSuspendReason ?? '')),
            'protectionSuspendNotifiedAt' => (int) ($account->protectionSuspendNotifiedAt ?? 0),
        ];

        // 1. 自适应心跳
        $this->handleAdaptiveHeartbeat($account, $currentTime, $userInterval, $s);

        // 2. 流量熔断
        $s['requiresTrafficProtection'] = $this->handleTrafficCircuitBreaker($account, $currentTime, $threshold, $shutdownMode, $thresholdAction, $s, $userInterval);

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
    // Traffic/status refresh delegated to AccountRefresher. Caller handles auth recovery, status logging,
    // and notifyStatusChangeIfNeeded (not suitable for the shared refresher).

    private function handleAdaptiveHeartbeat($account, int $currentTime, int $userInterval, array &$s): void
    {
        $lastUpdate = $account->updatedAt ?? 0;
        $cachedStatus = InstanceStatus::tryFrom($account->instanceStatus ?? InstanceStatus::Unknown->value) ?? InstanceStatus::Unknown;
        $isTransientState = $cachedStatus->isTransient();
        $currentInterval = $isTransientState ? 60 : $userInterval;

        $shouldCheckApi = ($currentTime - $lastUpdate) > $currentInterval;
        if (date('i') === '00') {
            $shouldCheckApi = true;
        }

        if ($shouldCheckApi) {
            $result = $this->accountRefresher->refresh($account, $currentTime);

            $s['traffic'] = $result->traffic;
            $s['status'] = $result->status;
            $s['apiStatusLog'] = $result->trafficSuccess ? '已更新' : '流量接口异常';

            // Caller-specific: auth recovery / suspended flag management
            if ($result->authInvalid) {
                $this->configManager->updateAccountStatus(
                    $account->id, $result->traffic, $result->status, $result->newUpdateTime,
                    ['protection_suspend_notified_at' => $s['protectionSuspendNotifiedAt']]
                );
                $s['protectionSuspended'] = true;
                $s['protectionSuspendReason'] = 'credential_invalid';
            } elseif ($s['protectionSuspended'] && $s['protectionSuspendReason'] === 'credential_invalid') {
                $this->configManager->updateAccountStatus(
                    $account->id, $result->traffic, $result->status, $result->newUpdateTime,
                    ['protection_suspended' => 0, 'protection_suspend_reason' => '', 'protection_suspend_notified_at' => 0]
                );
                $s['protectionSuspended'] = false;
                $s['protectionSuspendReason'] = '';
                $s['protectionSuspendNotifiedAt'] = 0;
                $this->db->addLog('info', "账号鉴权已恢复，自动停机保护已重新启用 [{$s['accountLabel']}]");
            }

            // Status logging (caller-specific)
            $statusEnum = InstanceStatus::tryFrom($result->status) ?? InstanceStatus::Unknown;
            if ($statusEnum === InstanceStatus::Unknown) {
                $s['apiStatusLog'] .= '(状态Unknown)';
            } else {
                $s['apiStatusLog'] .= $statusEnum->isTransient() ? ' [过渡态]' : ' [稳定态]';
            }

            $this->notifyStatusChangeIfNeeded($account, $cachedStatus->value, $result->status, '系统同步检测到实例状态变化。');
        } else {
            $s['traffic'] = $account->trafficUsed;
            $s['status'] = $account->instanceStatus;
            $timeLeft = $currentInterval - ($currentTime - $lastUpdate);
            $s['apiStatusLog'] = "缓存({$timeLeft}s)";
        }
    }

    // ---- Phase 2: 流量熔断 ----

    private function handleTrafficCircuitBreaker($account, int $currentTime, int $threshold, string $shutdownMode, string $thresholdAction, array &$s, int $userInterval = 600): bool
    {
        // 数据新鲜度保护:CDT 持续失败超过 15 分钟时,基于陈旧数据熔断可能误停/反复告警,跳过本轮
        $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute(['cdt_failure_at_' . $this->cdtFailureKeySuffix($account)]);
        $cdtFailureAt = (int) $stmt->fetchColumn();
        if ($cdtFailureAt > 0 && ($currentTime - $cdtFailureAt) > 900) {
            $s['apiStatusLog'] .= " [流量数据持续异常" . ($currentTime - $cdtFailureAt) . "s,跳过熔断]";
            return false;
        }
        // AccountRefresher 完全未运行时(updatedAt 停滞)的兜底
        $dataAge = $currentTime - (int) ($account->updatedAt ?? 0);
        $maxDataAge = max(1200, $userInterval * 2);
        if ($dataAge > $maxDataAge) {
            $s['apiStatusLog'] .= " [流量数据过期{$dataAge}s,跳过熔断]";
            return false;
        }

        $maxTraffic = $account->maxTraffic;
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
                    $notifyResult = $this->notificationService->notifyCredentialInvalid($account->accessKeyId, $accountTraffic, $usagePercent, $threshold);
                    Helpers::logNotificationResult($this->db, $notifyResult, $s['accountLabel']);
                    $this->db->addLog('warning', "检测到账号鉴权失效，已暂停自动停机保护 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
                    $s['protectionSuspendNotifiedAt'] = $currentTime;
                    $this->configManager->updateAccountStatus($account->id, $s['traffic'], $s['status'], $account->updatedAt ?? 0, [
                        'protection_suspended' => 1, 'protection_suspend_reason' => 'credential_invalid',
                        'protection_suspend_notified_at' => $s['protectionSuspendNotifiedAt']
                    ]);
                } else {
                    $s['apiStatusLog'] .= " [鉴权失效,已暂停自动停机]";
                }
            } else {
                // 排除过渡态(Starting/Pending):避免上一轮刚开机、状态尚未稳定时被熔断立即打断
                $canAttemptStop = !in_array($s['status'], [InstanceStatus::Stopped->value, InstanceStatus::Stopping->value, InstanceStatus::Starting->value, InstanceStatus::Pending->value, InstanceStatus::Released->value], true);
                if ($canAttemptStop) {
                    if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                        $previousStatus = $s['status'];
                        $s['actions'][] = $isHardLimitExceeded ? "已超量自动停机" : "接近上限自动停机";
                        $this->db->addLog('warning', "账号出口流量达到保护线，已自动停机 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
                        $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
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
                $this->notifyTrafficWarningWithCooldown($account, $accountTraffic, $usagePercent, implode(',', $s['actions']), $threshold, $currentTime);
            }
        } else {
            // notify_only mode — always dispatch notification
            $s['actions'][] = "超量提醒";
            $this->db->addLog('warning', "账号出口流量超限触发提醒 [{$s['accountLabel']}] 当前使用率:{$usagePercent}%");
            $this->notifyTrafficWarningWithCooldown($account, $accountTraffic, $usagePercent, '超量提醒', $threshold, $currentTime);
        }

        return true;
    }

    // ---- Phase 2b: 费用熔断 ----

    private function handleCostCircuitBreaker($account, int $currentTime, string $shutdownMode, array &$s): bool
    {
        if (!$this->bssService) return false;
        // cost_threshold_enabled controls auto-stop on cost overrun (distinct from enable_billing which controls frontend display)
        $enabled = $this->configManager->get('cost_threshold_enabled', '0') === '1';
        if (!$enabled) return false;

        $threshold = (float) $this->configManager->get('cost_threshold', '0.48');
        if ($threshold <= 0) return false;

        // 排除过渡态:避免对刚开机尚未稳定的实例直接下发停机
        $canAttemptStop = !in_array($s['status'], [InstanceStatus::Stopped->value, InstanceStatus::Stopping->value, InstanceStatus::Starting->value, InstanceStatus::Pending->value, InstanceStatus::Released->value], true);
        if (!$canAttemptStop || $s['protectionSuspended']) return false;
        if ($this->isCostQueryInCooldown($account, $currentTime)) return false;

        try {
            $bill = $this->bssService->getInstanceBill(
                $account->accessKeyId, $account->accessKeySecret,
                $account->instanceId, date('Y-m'), $account->siteType ?? 'china'
            );
            $cost = (float) ($bill['TotalCost'] ?? 0);
            // 成功查询也设置冷却(10 分钟),避免每分钟对每个账号调用 BSS API 触发限流;
            // 冷却 key 与失败共用一个,失败 300s / 成功 600s
            $this->setCostQueryFailureCooldown($account, $currentTime + 600);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "费用查询失败 [{$s['accountLabel']}]: " . strip_tags($e->getMessage()));
            $this->setCostQueryFailureCooldown($account, $currentTime + 300);
            return false;
        }

        if ($cost >= $threshold) {
            $previousStatus = $s['status'];
            if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                $s['actions'][] = "费用超限自动停机";
                $this->db->addLog('warning', "当月费用 \${$cost} 超过阈值 \${$threshold}，已自动停机 [{$s['accountLabel']}]");
                $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
                $this->configManager->updateAutoStartBlocked($account->id, true);
                $account->autoStartBlocked = true;
                $this->configManager->updateScheduleBlockedByTrafficForGroup($s['accountGroupKey'], true);
                $this->notifyStatusChangeIfNeeded($account, $previousStatus, InstanceStatus::Stopping->value, "当月费用 \${$cost} 超过阈值，已自动停机。");
                $notifyRes = $this->notificationService->sendCostWarning($account->accessKeyId, $cost, $threshold);
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
        $accountId = (int) ($account->id ?? 0);
        if ($accountId > 0) {
            return "cost_query_failure_until_{$accountId}";
        }

        return 'cost_query_failure_until_' . md5(($account->accessKeyId ?? '') . '|' . ($account->instanceId ?? ''));
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
        $scheduleEnabled = !empty($account->scheduleEnabled);
        $scheduleStartEnabled = !empty($account->scheduleStartEnabled);
        $scheduleStopEnabled = !empty($account->scheduleStopEnabled);
        $startTime = trim((string) ($account->startTime ?? ''));
        $stopTime = trim((string) ($account->stopTime ?? ''));
        $today = date('Y-m-d', $currentTime);

        $scheduleAllowed = $scheduleEnabled && !$s['scheduleBlockedByTraffic'] && !($s['requiresTrafficProtection'] ?? false);
        $isStableState = !in_array($s['status'], [InstanceStatus::Starting->value, InstanceStatus::Stopping->value, InstanceStatus::Pending->value, InstanceStatus::Releasing->value, InstanceStatus::Released->value], true);

        // 定时停机
        if ($scheduleAllowed && $scheduleStopEnabled && $this->shouldRunScheduleAt($currentTime, $stopTime, $account->scheduleLastStopDate ?? '')) {
            if ($isStableState && $s['status'] === InstanceStatus::Running->value) {
                if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                    $s['actions'][] = "定时停机";
                    $this->db->addLog('info', "执行定时停机 [{$s['accountLabel']}] {$stopTime}");
                    $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Stopping->value, $currentTime);
                    $this->configManager->updateAutoStartBlocked($account->id, true);
                    $account->autoStartBlocked = true;
                    $this->configManager->updateScheduleExecutionState($account->id, 'stop', $today);
                    $scheduleNotify = $this->notificationService->notifySchedule('定时停机', $account, "已按计划时间 {$stopTime} 执行停机，停机方式沿用系统设置。");
                    Helpers::logNotificationResult($this->db, $scheduleNotify, $s['accountLabel']);
                    $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Running->value, InstanceStatus::Stopping->value, '已按计划执行定时停机。');
                    $s['status'] = InstanceStatus::Stopping->value;
                } else {
                    $s['apiStatusLog'] .= " [定时停机失败]";
                }
            } else {
                // 实例已停止或正处于过渡态:仅在过渡态(停机指令可能已发出)设置保活阻塞;
                // 已停止的实例不设 block,否则未配置定时开机时保活会被永久阻塞
                if ($s['status'] !== InstanceStatus::Stopped->value) {
                    $this->configManager->updateAutoStartBlocked($account->id, true);
                    $account->autoStartBlocked = true;
                }
                $this->configManager->updateScheduleExecutionState($account->id, 'stop', $today);
            }
        }

        // 定时开机
        if ($scheduleAllowed && $scheduleStartEnabled && $this->shouldRunScheduleAt($currentTime, $startTime, $account->scheduleLastStartDate ?? '')) {
            if ($isStableState && $s['status'] === InstanceStatus::Stopped->value) {
                if ($this->safeControlInstance($account, 'start')) {
                    $s['actions'][] = "定时开机";
                    $this->db->addLog('info', "执行定时开机 [{$s['accountLabel']}] {$startTime}");
                    $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Starting->value, $currentTime);
                    $this->configManager->updateAutoStartBlocked($account->id, false);
                    $account->autoStartBlocked = false;
                    $this->configManager->updateScheduleExecutionState($account->id, 'start', $today);
                    $scheduleNotify = $this->notificationService->notifySchedule('定时开机', $account, "已按计划时间 {$startTime} 执行开机。");
                    Helpers::logNotificationResult($this->db, $scheduleNotify, $s['accountLabel']);
                    $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Stopped->value, InstanceStatus::Starting->value, '已按计划执行定时开机。');
                    $this->ddnsService->syncForAccounts([$account], '定时开机后');
                    $s['status'] = InstanceStatus::Starting->value;
                } else {
                    $s['apiStatusLog'] .= " [定时开机失败]";
                }
            } else {
                $this->configManager->updateAutoStartBlocked($account->id, false);
                $account->autoStartBlocked = false;
                $this->configManager->updateScheduleExecutionState($account->id, 'start', $today);
            }
        }
    }

    // ---- Phase 4: 每月自动开机 ----

    private function handleMonthlyAutoStart($account, int $currentTime, bool $monthlyAutoStart, array &$s): void
    {
        $autoStartBlocked = !empty($account->autoStartBlocked);
        if (!$monthlyAutoStart || $autoStartBlocked || ($s['requiresTrafficProtection'] ?? false) || $s['scheduleBlockedByTraffic'] || date('j', $currentTime) !== '1') {
            return;
        }

        $lastMonthlyStart = (int) ($account->lastKeepAliveAt ?? 0);
        if ($s['status'] !== InstanceStatus::Stopped->value || $this->isSameMonth($lastMonthlyStart, $currentTime)) {
            return;
        }

        if ($this->safeControlInstance($account, 'start')) {
            $s['actions'][] = "月初自动开机";
            $this->db->addLog('info', "执行月初自动开机 [{$s['accountLabel']}]");
            $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Starting->value, $currentTime);
            $this->configManager->updateLastKeepAlive($account->id, $currentTime);
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
        $autoStartBlocked = !empty($account->autoStartBlocked);
        if (!$keepAlive || $autoStartBlocked || ($s['requiresTrafficProtection'] ?? false) || $s['scheduleBlockedByTraffic']) {
            return;
        }

        if ($s['status'] !== InstanceStatus::Stopped->value) {
            return;
        }

        if ($this->safeControlInstance($account, 'start')) {
            $s['actions'][] = "保活启动";
            $this->db->addLog('info', "执行保活启动 [{$s['accountLabel']}]");

            $mailRes = $this->notificationService->notifySchedule("保活启动", $account, "检测到实例非预期关机，已尝试自动启动。");
            Helpers::logNotificationResult($this->db, $mailRes, $s['accountLabel']);

            $this->configManager->updateAccountStatus($account->id, $s['traffic'], InstanceStatus::Starting->value, $currentTime);
            $this->configManager->updateLastKeepAlive($account->id, $currentTime);
            $this->notifyStatusChangeIfNeeded($account, InstanceStatus::Stopped->value, InstanceStatus::Starting->value, '检测到实例非预期关机，保活已尝试自动启动。');
            $this->ddnsService->syncForAccounts([$account], '保活启动后');
            $s['status'] = InstanceStatus::Starting->value;
        } else {
            $s['apiStatusLog'] .= " [保活启动失败,下次重试]";
        }
    }
}
