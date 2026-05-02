<?php

class MonitorService
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $notificationService;
    private $ddnsService;

    public function __construct($db, $configManager, $aliyunService, $notificationService, $ddnsService)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->aliyunService = $aliyunService;
        $this->notificationService = $notificationService;
        $this->ddnsService = $ddnsService;
    }

    public function run(): string
    {
        // 优化：分级清理日志
        // 普通/重要日志保留 30 天，高频心跳日志仅保留 3 天
        $this->db->pruneLogs(30, 3);

        $this->db->pruneStats();

        // 优化：每天凌晨 04:xx 执行一次 VACUUM 整理数据库碎片
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
            $accountLabel = $this->getAccountLogLabel($account);
            $logPrefix = "[{$accountLabel}]";
            $accountGroupKey = $account['group_key'] ?: substr(sha1(($account['access_key_id'] ?? '') . '|' . ($account['region_id'] ?? '')), 0, 16);
            $actions = [];
            $protectionSuspended = !empty($account['protection_suspended']);
            $protectionSuspendReason = trim((string) ($account['protection_suspend_reason'] ?? ''));
            $protectionSuspendNotifiedAt = (int) ($account['protection_suspend_notified_at'] ?? 0);

            // 1. 自适应心跳
            $lastUpdate = $account['updated_at'] ?? 0;
            $cachedStatus = $account['instance_status'] ?? 'Unknown';
            $isTransientState = in_array($cachedStatus, ['Starting', 'Stopping', 'Pending', 'Unknown']);
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
                    $metadata['protection_suspend_notified_at'] = $protectionSuspendNotifiedAt;
                    $protectionSuspended = true;
                    $protectionSuspendReason = 'credential_invalid';
                } elseif ($protectionSuspended && $protectionSuspendReason === 'credential_invalid') {
                    $metadata['protection_suspended'] = 0;
                    $metadata['protection_suspend_reason'] = '';
                    $metadata['protection_suspend_notified_at'] = 0;
                    $protectionSuspended = false;
                    $protectionSuspendReason = '';
                    $protectionSuspendNotifiedAt = 0;
                    $this->db->addLog('info', "账号鉴权已恢复，自动停机保护已重新启用 [{$accountLabel}]");
                }

                if (empty($trafficResult['success'])) {
                    $traffic = $account['traffic_used'];
                    $apiStatusLog = "流量接口异常";
                    $newUpdateTime = $lastUpdate;
                } else {
                    $traffic = (float) ($trafficResult['value'] ?? 0);
                    $apiStatusLog = "已更新";

                    $this->db->addHourlyStat($account['id'], $traffic);
                    $this->db->addDailyStat($account['id'], $traffic);
                }

                if ($status === 'Unknown') {
                    $newUpdateTime = $lastUpdate;
                    $apiStatusLog .= "(状态Unknown)";
                } else {
                    $apiStatusLog .= in_array($status, ['Starting', 'Stopping', 'Pending']) ? " [过渡态]" : " [稳定态]";
                }

                $this->notifyStatusChangeIfNeeded($account, $cachedStatus, $status, '系统同步检测到实例状态变化。');
                $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $newUpdateTime, $metadata);
            } else {
                $traffic = $account['traffic_used'];
                $status = $account['instance_status'];
                $timeLeft = $currentInterval - ($currentTime - $lastUpdate);
                $apiStatusLog = "缓存({$timeLeft}s)";
            }

            $maxTraffic = $account['max_traffic'];
            $accountTraffic = $this->getGroupTrafficUsed($account);
            $usagePercent = ($maxTraffic > 0) ? round(($accountTraffic / $maxTraffic) * 100, 2) : 0;
            $trafficDesc = "账号出口流量:{$usagePercent}%";
            $isOverThreshold = $usagePercent >= $threshold;
            $isHardLimitExceeded = $maxTraffic > 0 && $accountTraffic >= $maxTraffic;
            $requiresTrafficProtection = $isOverThreshold || $isHardLimitExceeded;
            $scheduleBlockedByTraffic = !empty($account['schedule_blocked_by_traffic']);

            // 2. 流量熔断
            if ($requiresTrafficProtection) {
                $trafficDesc .= $isHardLimitExceeded ? "[已超出上限]" : "[接近上限]";

                if ($thresholdAction === 'stop_and_notify') {
                    if ($protectionSuspended && $protectionSuspendReason === 'credential_invalid') {
                        if ($protectionSuspendNotifiedAt <= 0) {
                            $actions[] = "账号密钥失效，已暂停自动停机";
                            $notifyResult = $this->notificationService->notifyCredentialInvalid($accountLabel, $accountTraffic, $usagePercent, $threshold);
                            $this->logNotificationResult($notifyResult, $accountLabel);
                            $this->db->addLog('warning', "检测到账号鉴权失效，已暂停自动停机保护 [{$accountLabel}] 当前使用率:{$usagePercent}%");
                            $protectionSuspendNotifiedAt = $currentTime;
                            $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $lastUpdate, [
                                'protection_suspended' => 1,
                                'protection_suspend_reason' => 'credential_invalid',
                                'protection_suspend_notified_at' => $protectionSuspendNotifiedAt
                            ]);
                        } else {
                            $apiStatusLog .= " [鉴权失效,已暂停自动停机]";
                        }
                    } else {
                        $canAttemptStop = !in_array($status, ['Stopped', 'Stopping', 'Released'], true);

                        // 达到账号流量上限后必须立即保护，不再等待下一次接口刷新窗口。
                        if ($canAttemptStop) {
                            if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                                $previousStatus = $status;
                                $actions[] = $isHardLimitExceeded ? "已超量自动停机" : "接近上限自动停机";
                                $this->db->addLog('warning', "账号出口流量达到保护线，已自动停机 [{$accountLabel}] 当前使用率:{$usagePercent}%");
                                $this->configManager->updateAccountStatus($account['id'], $traffic, 'Stopping', $currentTime);
                                $this->configManager->updateScheduleBlockedByTrafficForGroup($accountGroupKey, true);
                                $this->notifyStatusChangeIfNeeded($account, $previousStatus, 'Stopping', '流量达到保护线，已自动停机。');
                                $status = 'Stopping';
                                $scheduleBlockedByTraffic = true;
                            } else {
                                $actions[] = "自动停机失败";
                                $this->db->addLog('error', "账号出口流量达到保护线，但自动停机失败 [{$accountLabel}] 当前使用率:{$usagePercent}%");
                            }
                        }
                    }
                } elseif ($shouldCheckApi) {
                    $actions[] = "超量提醒";
                    $this->db->addLog('warning', "账号出口流量超限触发提醒 [{$accountLabel}] 当前使用率:{$usagePercent}%");
                }

                if (!empty($actions) && !($protectionSuspended && $protectionSuspendReason === 'credential_invalid')) {
                    $mailRes = $this->notificationService->sendTrafficWarning($accountLabel, $accountTraffic, $usagePercent, implode(',', $actions), $threshold);
                    $this->logNotificationResult($mailRes, $accountLabel);
                }
            }

            // 3. 定时开关机：本月一旦触发流量保护就暂停，月初重置或手动恢复后才重新接入。
            $scheduleEnabled = !empty($account['schedule_enabled']);
            $scheduleStartEnabled = !empty($account['schedule_start_enabled']);
            $scheduleStopEnabled = !empty($account['schedule_stop_enabled']);
            $startTime = trim((string) ($account['start_time'] ?? ''));
            $stopTime = trim((string) ($account['stop_time'] ?? ''));
            $today = date('Y-m-d', $currentTime);

            $scheduleAllowed = $scheduleEnabled && !$scheduleBlockedByTraffic && !$requiresTrafficProtection;
            $isStableState = !in_array($status, ['Starting', 'Stopping', 'Pending', 'Releasing', 'Released'], true);

            if ($scheduleAllowed && $scheduleStopEnabled && $this->shouldRunScheduleAt($currentTime, $stopTime, $account['schedule_last_stop_date'] ?? '')) {
                if ($isStableState && $status === 'Running') {
                    if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                        $actions[] = "定时停机";
                        $this->db->addLog('info', "执行定时停机 [{$accountLabel}] {$stopTime}");
                        $this->configManager->updateAccountStatus($account['id'], $traffic, 'Stopping', $currentTime);
                        $this->configManager->updateScheduleExecutionState($account['id'], 'stop', $today);
                        $scheduleNotify = $this->notificationService->notifySchedule('定时停机', $account, "已按计划时间 {$stopTime} 执行停机，停机方式沿用系统设置。");
                        $this->logNotificationResult($scheduleNotify, $accountLabel);
                        $this->notifyStatusChangeIfNeeded($account, 'Running', 'Stopping', '已按计划执行定时停机。');
                        $status = 'Stopping';
                    } else {
                        $apiStatusLog .= " [定时停机失败]";
                    }
                } else {
                    $this->configManager->updateScheduleExecutionState($account['id'], 'stop', $today);
                }
            }

            if ($scheduleAllowed && $scheduleStartEnabled && $this->shouldRunScheduleAt($currentTime, $startTime, $account['schedule_last_start_date'] ?? '')) {
                if ($isStableState && $status === 'Stopped') {
                    if ($this->safeControlInstance($account, 'start')) {
                        $actions[] = "定时开机";
                        $this->db->addLog('info', "执行定时开机 [{$accountLabel}] {$startTime}");
                        $this->configManager->updateAccountStatus($account['id'], $traffic, 'Starting', $currentTime);
                        $this->configManager->updateScheduleExecutionState($account['id'], 'start', $today);
                        $scheduleNotify = $this->notificationService->notifySchedule('定时开机', $account, "已按计划时间 {$startTime} 执行开机。");
                        $this->logNotificationResult($scheduleNotify, $accountLabel);
                        $this->notifyStatusChangeIfNeeded($account, 'Stopped', 'Starting', '已按计划执行定时开机。');
                        $this->ddnsService->syncForAccounts([$account], '定时开机后');
                        $status = 'Starting';
                    } else {
                        $apiStatusLog .= " [定时开机失败]";
                    }
                } else {
                    $this->configManager->updateScheduleExecutionState($account['id'], 'start', $today);
                }
            }

            // 4. 每月自动开机：只在每月 1 号执行，且不会启动已触发流量保护或被流量熔断封锁的实例。
            $autoStartBlocked = !empty($account['auto_start_blocked']);
            if ($monthlyAutoStart && !$autoStartBlocked && !$requiresTrafficProtection && !$scheduleBlockedByTraffic && date('j', $currentTime) === '1') {
                $lastMonthlyStart = (int) ($account['last_keep_alive_at'] ?? 0);
                if ($status === 'Stopped' && !$this->isSameMonth($lastMonthlyStart, $currentTime)) {
                    if ($this->safeControlInstance($account, 'start')) {
                        $actions[] = "月初自动开机";
                        $this->db->addLog('info', "执行月初自动开机 [{$accountLabel}]");
                        $this->configManager->updateAccountStatus($account['id'], $traffic, 'Starting', $currentTime);
                        $this->configManager->updateLastKeepAlive($account['id'], $currentTime);
                        $this->notifyStatusChangeIfNeeded($account, 'Stopped', 'Starting', '每月 1 号自动开机已执行。');
                        $this->ddnsService->syncForAccounts([$account], '月初自动开机后');
                        $status = 'Starting';
                    } else {
                        $apiStatusLog .= " [月初自动开机失败,下次重试]";
                    }
                }
            }

            // 5. 保活逻辑
            if ($keepAlive && !$autoStartBlocked && !$requiresTrafficProtection) {
                if ($status === 'Stopped') {
                    if ($this->safeControlInstance($account, 'start')) {
                        $actions[] = "保活启动";
                        $this->db->addLog('info', "执行保活启动 [{$accountLabel}]");

                        $mailRes = $this->notificationService->notifySchedule("保活启动", $account, "检测到实例非预期关机，已尝试自动启动。");
                        $this->logNotificationResult($mailRes, $accountLabel);

                        $this->configManager->updateAccountStatus($account['id'], $traffic, 'Starting', $currentTime);
                        $this->configManager->updateLastKeepAlive($account['id'], $currentTime);
                        $this->notifyStatusChangeIfNeeded($account, 'Stopped', 'Starting', '检测到实例非预期关机，保活已尝试自动启动。');
                        $this->ddnsService->syncForAccounts([$account], '保活启动后');
                        $status = 'Starting';
                    } else {
                        $apiStatusLog .= " [保活启动失败,下次重试]";
                    }
                }
            }


            $actionLog = empty($actions) ? "无动作" : implode(", ", $actions);
            $logLine = sprintf("%s %s | %s | %s | %s", $logPrefix, $actionLog, $trafficDesc, $status, $apiStatusLog);

            // --- 修改：将心跳日志写入数据库 ---
            $this->db->addLog('heartbeat', $logLine);
            $logs[] = $logLine;
        }

        $this->configManager->updateLastRunTime(time());

        // DDNS 同步：每 10 分钟检查一次公网 IP 变化。
        $lastDdnsSync = (int) ($this->configManager->get('last_ddns_sync', 0));
        if ((time() - $lastDdnsSync) >= 600) {
            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), 'Cron 周期同步');
            $pdo = $this->db->getPdo();
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('last_ddns_sync', ?)")->execute([time()]);
            $this->configManager->load();
        }

        return implode(PHP_EOL, $logs);
    }
    private function logNotificationResult($result, $key)
    {
        if ($result === true) {
            $this->db->addLog('info', "通知推送成功 [$key]");
        } elseif ($result !== false && $result !== true) {
            $this->db->addLog('warning', "通知推送异常/失败 [$key]: " . strip_tags($result));
        }
    }

    private function notifyStatusChangeIfNeeded($account, $fromStatus, $toStatus, $reason = '')
    {
        $fromStatus = (string) ($fromStatus ?: 'Unknown');
        $toStatus = (string) ($toStatus ?: 'Unknown');

        // 核心过滤：
        // 1. 状态未变则不通知
        // 2. 只通知进入稳定态（Running/Stopped）的变化
        // 3. 过滤瞬态跳转，例如从 Starting 到 Running 是预期行为，但如果是在同步中由于 API 抖动导致的跳变则需谨慎
        if ($fromStatus === $toStatus || !in_array($toStatus, ['Running', 'Stopped'], true)) {
            return;
        }

        // 初次发现 (Unknown) 不通知，避免重启程序时大量刷屏
        // ECS 创建完成后的首次状态同步不通过此逻辑通知（已有专门的 notifyEcsCreated）
        if ($fromStatus === 'Unknown' || $this->isRecentlyCreatedInstance($account)) {
            return;
        }

        // 避免从过渡态到其目标态的冗余通知
        // 例如：刚刚手动触发了 Start，状态变为了 Starting，然后 API 检测到 Running。
        // 这时通常用户已经在界面看到了，或者已有操作成功的提示，可根据需要决定是否通知。
        // 这里保留过渡态到稳定态的通知，但过滤从一个稳定态快速切换到另一个稳定态（如通过脚本极速重启）时的中间干扰。

        $accountLabel = $this->getAccountLogLabel($account);
        $result = $this->notificationService->notifyInstanceStatusChanged($accountLabel, $account, $fromStatus, $toStatus, $reason);
        $this->logNotificationResult($result, $accountLabel);
    }

    private function isRecentlyCreatedInstance(array $account)
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
        if (empty($timestamp)) {
            return false;
        }
        return date('Y-m', (int) $timestamp) === date('Y-m', (int) $currentTime);
    }

    private function shouldRunScheduleAt($currentTime, $targetTime, $lastRunDate)
    {
        $targetTime = trim((string) $targetTime);
        if ($targetTime === '' || !preg_match('/^\d{2}:\d{2}$/', $targetTime)) {
            return false;
        }

        $today = date('Y-m-d', $currentTime);
        if ((string) $lastRunDate === $today) {
            return false;
        }

        // 宽松窗口：目标时间前后 5 分钟内均可触发。
        // API 偶发失败重试后时间已过也会错过，此处确保一次失败仍有挽回余地。
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

    private function isCredentialInvalidError($code, $message = '')
    {
        $normalizedCode = strtolower(trim((string) $code));
        $normalizedMessage = strtolower(trim((string) $message));
        if ($normalizedCode === '') {
            return false;
        }

        $credentialErrorCodes = [
            'invalidaccesskeyid.notfound',
            'invalidaccesskeyid',
            'signaturedoesnotmatch',
            'incompletesignature',
            'forbidden.accesskeydisabled',
            'invalidsecuritytoken.expired',
            'invalidsecuritytoken.malformed',
            'missingsecuritytoken'
        ];

        if (in_array($normalizedCode, $credentialErrorCodes, true)) {
            return true;
        }

        if ($normalizedMessage === '') {
            return false;
        }

        return strpos($normalizedMessage, 'access key is not found') !== false
            || strpos($normalizedMessage, 'access key id does not exist') !== false
            || strpos($normalizedMessage, 'signature does not match') !== false
            || strpos($normalizedMessage, 'incomplete signature') !== false
            || strpos($normalizedMessage, 'accesskeydisabled') !== false;
    }

    private function safeGetTraffic($account)
    {
        try {
            $value = $this->aliyunService->getTraffic(
                $account['access_key_id'],
                $account['access_key_secret'],
                $account['region_id']
            );
            return [
                'success' => true,
                'value' => $value,
                'status' => 'ok',
                'message' => ''
            ];
        } catch (ClientException $e) {
            $code = trim((string) $e->getErrorCode());
            if ($this->isCredentialInvalidError($code, $e->getMessage())) {
                $this->db->addLog('error', "CDT 流量查询失败 [{$this->getAccountLogLabel($account)}]: AK 已失效");
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            $this->db->addLog('error', "CDT 流量查询配置错误 [{$this->getAccountLogLabel($account)}]: " . ($code ?: "鉴权失败") . "，请确认 AK 拥有 CDT 权限");
            return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => 'CDT 权限不足，请确认 AK 拥有 cdt:ListCdtInternetTraffic 权限'];
        } catch (ServerException $e) {
            $code = trim((string) $e->getErrorCode());
            if ($this->isCredentialInvalidError($code, $e->getErrorMessage())) {
                $this->db->addLog('error', "CDT 流量查询失败 [{$this->getAccountLogLabel($account)}]: {$code} - " . $e->getErrorMessage());
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            $this->db->addLog('error', "CDT 流量查询失败 [{$this->getAccountLogLabel($account)}]: " . $e->getErrorCode() . " - " . $e->getErrorMessage());
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 接口异常'];
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'cURL error') !== false) {
                $this->db->addLog('error', "CDT 流量查询失败 [{$this->getAccountLogLabel($account)}]: 网络连接超时");
                return ['success' => false, 'value' => null, 'status' => 'timeout', 'message' => 'CDT 请求超时'];
            }

            $this->db->addLog('error', "CDT 流量查询失败 [{$this->getAccountLogLabel($account)}]: " . strip_tags($e->getMessage()));
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 流量同步失败'];
        }
    }

    private function getGroupTrafficUsed($account)
    {
        $pdo = $this->db->getPdo();
        $groupKey = trim((string) ($account['group_key'] ?? ''));
        $billingMonth = date('Y-m');

        // CDT 返回的是 per-AK 月度总流量，同一 group 下所有实例共享 AK 和流量值。
        // 取第一条记录的 traffic_used，不 SUM。
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

        // 云监控分钟点有轻微延迟，只同步到上一个完整分钟，避免把未收敛的数据点算进去。
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
            return 'Unknown';
        }
    }

    private function safeGetInstanceFullStatus($account)
    {
        try {
            return $this->aliyunService->getInstanceFullStatus($account);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function safeControlInstance($account, $action, $shutdownMode = 'KeepCharging')
    {
        try {
            return $this->aliyunService->controlInstance($account, $action, $shutdownMode);
        } catch (ClientException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: 权限不足或配置错误");
            return false;
        } catch (ServerException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: " . $e->getErrorCode() . " - " . $e->getErrorMessage());
            return false;
        } catch (\Exception $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: 无法连接接口");
            return false;
        }
    }

    private function getAccountLogLabel($account): string
    {
        $remark = trim((string) ($account['remark'] ?? ''));
        if ($remark !== '') return $remark;
        $name = trim((string) ($account['instance_name'] ?? ''));
        if ($name !== '') return $name;
        $id = trim((string) ($account['instance_id'] ?? ''));
        if ($id !== '') return $id;
        return substr((string) ($account['access_key_id'] ?? ''), 0, 7) . '***';
    }

}
