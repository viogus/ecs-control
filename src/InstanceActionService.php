<?php

class InstanceActionService
{
    private AliyunService $aliyunService;
    private ConfigManager $configManager;
    private Database $db;
    private NotificationService $notificationService;
    private DdnsService $ddnsService;

    public function __construct(
        AliyunService $aliyunService,
        ConfigManager $configManager,
        Database $db,
        NotificationService $notificationService,
        DdnsService $ddnsService
    ) {
        $this->aliyunService = $aliyunService;
        $this->configManager = $configManager;
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->ddnsService = $ddnsService;
    }

    // ---- public API ----

    public function controlInstance($accountId, $action, $shutdownMode = 'KeepCharging', $waitForSync = true, callable $onStatusChanged = null): bool
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return false;

        try {
            $result = $this->aliyunService->controlInstance($targetAccount, $action, $shutdownMode);
            if ($result) {
                $label = Helpers::getAccountLogLabel($targetAccount);
                $instId = $targetAccount->instanceId;
                $this->db->addLog('info', "实例操作 [{$action}] 成功 [{$label}] {$instId}");
                $newStatus = $action === 'stop' ? 'Stopping' : 'Starting';
                $this->configManager->updateAccountStatus($accountId, $targetAccount->trafficUsed, $newStatus, time());
                $this->configManager->updateAutoStartBlocked($accountId, $action === 'stop');
                if ($action === 'start' && $waitForSync) {
                    // Defer post-start sync to next cron cycle to avoid blocking PHP-FPM worker
                    $this->db->addLog('info', "实例启动成功，DDNS 和状态同步将在下一轮 cron 中自动完成 [{$label}]");
                }
            }
            return $result;
        } catch (\Throwable $e) {
            $code = $e instanceof \AlibabaCloud\Client\Exception\ClientException ? 'ClientException' : ($e instanceof \AlibabaCloud\Client\Exception\ServerException ? 'ServerException' : 'Exception');
            $this->db->addLog('error', "实例操作失败 [{$action}]: " . strip_tags($e->getMessage()));
            return false;
        }
    }

    public function deleteInstance($accountId, bool $forceStop = false): bool
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return false;

        $this->db->addLog('warning', "操作成功：秒级标记释放指令已提交，后台安全队列正在接管 [{$targetAccount->instanceId}]");
        $this->configManager->markAccountAsDeleted($accountId);
        return true;
    }

    public function replaceInstanceIp($accountId): array
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return ['success' => false, 'message' => '实例不存在'];

        if ($targetAccount->publicIpMode !== 'eip' || !$targetAccount->eipManaged) {
            return ['success' => false, 'message' => '当前实例不是系统托管 EIP，无法更换公网 IP'];
        }

        $label = Helpers::getAccountLogLabel($targetAccount);
        try {
            $oldIp = $targetAccount['public_ip'] ?? '';
            $result = $this->aliyunService->replaceManagedEip($targetAccount);
            $this->configManager->updateAccountNetworkMetadata($accountId, [
                'public_ip' => $result['publicIp'] ?? '',
                'public_ip_mode' => 'eip',
                'eip_allocation_id' => $result['eipAllocationId'] ?? '',
                'eip_address' => $result['eipAddress'] ?? '',
                'eip_managed' => 1,
                'internet_max_bandwidth_out' => $result['internetMaxBandwidthOut'] ?? ($targetAccount->internetMaxBandwidthOut ?? 0)
            ]);

            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), 'EIP 更换后');
            $newIp = $result['publicIp'] ?? '';
            $instId = $targetAccount->instanceId;
            $this->db->addLog('info', "EIP 已更换 [{$label}] {$instId} {$oldIp} -> {$newIp}");
            $notifyResult = $this->notificationService->notifyPublicIpChanged(
                $label, $targetAccount, $oldIp, $newIp,
                '用户在控制台手动更换公网 IP，DDNS 解析已同步更新。'
            );
            Helpers::logNotificationResult($this->db, $notifyResult, $label);

            return ['success' => true, 'message' => '公网 IP 已更换',
                'data' => [
                    'publicIp' => $newIp, 'publicIpMode' => 'eip',
                    'eipAllocationId' => $result['eipAllocationId'] ?? '',
                    'eipAddress' => $result['eipAddress'] ?? '',
                    'internetMaxBandwidthOut' => $result['internetMaxBandwidthOut'] ?? 0
                ]];
        } catch (\Throwable $e) {
            $this->db->addLog('error', "EIP 更换失败 [{$label}]: " . strip_tags($e->getMessage()));
            return ['success' => false, 'message' => strip_tags($e->getMessage())];
        }
    }

    public function refreshAccount($id): array|bool
    {
        $targetAccount = $this->configManager->getAccountById($id);
        if (!$targetAccount) return false;

        $currentTime = time();
        $trafficResult = $this->safeGetTraffic($targetAccount);
        $status = $this->safeGetInstanceStatus($targetAccount);
        $metadata = [
            'traffic_api_status' => $trafficResult['status'] ?? 'ok',
            'traffic_api_message' => $trafficResult['message'] ?? ''
        ];
        if (trim((string) ($trafficResult['status'] ?? '')) === 'auth_error') {
            $metadata['protection_suspended'] = 1;
            $metadata['protection_suspend_reason'] = 'credential_invalid';
        } else {
            $metadata['protection_suspended'] = 0;
            $metadata['protection_suspend_reason'] = '';
            $metadata['protection_suspend_notified_at'] = 0;
        }

        if (empty($trafficResult['success'])) {
            $traffic = $targetAccount['traffic_used'];
        } else {
            $traffic = (float) ($trafficResult['value'] ?? 0);
            $this->db->addHourlyStat($targetAccount['id'], $traffic);
            $this->db->addDailyStat($targetAccount['id'], $traffic);
        }

        $this->configManager->updateAccountStatus($id, $traffic, $status, $currentTime, $metadata);

        $billingError = null;
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        if ($billingEnabled) {
            $billingCycle = date('Y-m');
            $balanceCache = $this->db->getBillingCache($targetAccount['id'], 'balance', '', 21600);
            if (!$balanceCache) {
                try {
                    $balance = $this->aliyunService->getAccountBalance($targetAccount['access_key_id'], $targetAccount['access_key_secret'], $targetAccount['site_type'] ?? 'china');
                    $this->db->setBillingCache($targetAccount['id'], 'balance', '', $balance);
                } catch (\Exception $e) { $billingError = '余额查询失败: ' . strip_tags($e->getMessage()); }
            }
            if (!empty($targetAccount['instance_id'])) {
                $billCache = $this->db->getBillingCache($targetAccount['id'], 'instance_bill', $billingCycle, 21600);
                if (!$billCache) {
                    try {
                        $bill = $this->aliyunService->getInstanceBill($targetAccount['access_key_id'], $targetAccount['access_key_secret'], $targetAccount['instance_id'], $billingCycle, $targetAccount['site_type'] ?? 'china');
                        $this->db->setBillingCache($targetAccount['id'], 'instance_bill', $billingCycle, $bill);
                    } catch (\Exception $e) { $billingError = ($billingError ? $billingError . '; ' : '') . '账单查询失败: ' . strip_tags($e->getMessage()); }
                }
            }
        }

        $response = ['success' => true, 'traffic_status' => $trafficResult['status'] ?? 'ok', 'traffic_message' => $trafficResult['message'] ?? ''];
        if ($billingError) {
            $billingLabel = Helpers::getAccountLogLabel($targetAccount);
            $this->db->addLog('warning', "账单刷新异常 [{$billingLabel}]: {$billingError}");
            $response['billing_error'] = $billingError;
        }
        return $response;
    }

    public function getAllManagedInstances(bool $sync, callable $buildSnapshot): array
    {
        if ($sync) {
            $accountsBefore = $this->configManager->getAccounts();
            $this->configManager->syncAccountGroups(true);
            $this->configManager->load();
            $this->ddnsService->reconcileAfterSync($accountsBefore, $this->configManager->getAccounts(), '实例手动同步');
        } else {
            $this->configManager->load();
        }

        $threshold = (int) ($this->configManager->get('traffic_threshold', 95) ?? 95);
        $userInterval = (int) ($this->configManager->get('api_interval', 600) ?? 600);
        $accounts = array_values(array_filter($this->configManager->getAccounts(), fn($a) => !empty($a['instance_id'])));
        $allInstances = [];

        foreach ($accounts as $account) {
            $allInstances[] = $buildSnapshot($account, ['threshold' => $threshold, 'userInterval' => $userInterval, 'includeSensitive' => true, 'forceRefresh' => $sync]);
        }

        $pendingAccounts = $this->configManager->getPendingReleaseAccounts();
        foreach ($pendingAccounts as $account) {
            $snap = $buildSnapshot($account, ['threshold' => $threshold, 'userInterval' => $userInterval, 'includeSensitive' => true, 'forceRefresh' => $sync]);
            $snap['instanceStatus'] = 'Releasing';
            $snap['status'] = 'Releasing';
            $snap['operationLocked'] = true;
            $snap['operationLockedReason'] = '实例正在释放中，后台队列会继续处理。';
            $allInstances[] = $snap;
        }

        return $allInstances;
    }

    public function processPendingReleases(callable $onReleased = null): void
    {
        $pendingAccounts = $this->configManager->getPendingReleaseAccounts();
        foreach ($pendingAccounts as $account) {
            $accountLabel = Helpers::getAccountLogLabel($account);
            try {
                $status = $this->aliyunService->getInstanceStatus($account);
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'NotFound') !== false || stripos($e->getMessage(), 'InvalidInstanceId') !== false) {
                    $status = 'NotFound';
                } else {
                    $this->db->addLog('error', "后台异步释放引擎探测异常 [{$accountLabel}]: " . strip_tags($e->getMessage()));
                    continue;
                }
            }

            try {
                if ($status === 'Stopped') {
                    if (!$this->releaseManagedEipForPendingAccount($account, $accountLabel)) continue;
                    $result = $this->aliyunService->deleteInstance($account, false);
                    if ($result) {
                        $this->db->addLog('warning', "后台异步彻底销毁成功 [{$accountLabel}] {$account['instance_id']}");
                        if ($onReleased) $onReleased($accountLabel, $account);
                        $accountsBeforeDelete = $this->configManager->getAccounts();
                        $this->ddnsService->deleteForAccount($account, $accountsBeforeDelete, '后台实例彻底释放');
                        $this->configManager->physicallyDeleteAccount($account['id']);
                        $this->ddnsService->reconcileAfterSync($accountsBeforeDelete, $this->configManager->getAccounts(), '异步释放后同步');
                    }
                } elseif ($status === 'NotFound') {
                    if (!$this->releaseManagedEipForPendingAccount($account, $accountLabel)) continue;
                    $this->db->addLog('warning', "待释放实例云端已灭迹，自动擦除本地账本 [{$accountLabel}]");
                    $accountsBeforeDelete = $this->configManager->getAccounts();
                    $this->ddnsService->deleteForAccount($account, $accountsBeforeDelete, '实例已灭迹后清理');
                    $this->configManager->physicallyDeleteAccount($account['id']);
                    $this->ddnsService->reconcileAfterSync($accountsBeforeDelete, $this->configManager->getAccounts(), '实例灭迹后同步');
                } elseif ($status === 'Unknown') {
                    $this->db->addLog('warning', "后台异步释放引擎暂时无法确认实例状态，将于下一轮重试 [{$accountLabel}]");
                } elseif (!in_array($status, ['Stopping'])) {
                    $this->db->addLog('info', "后台异步释放引擎：向活跃实例下发强制离线指令 [{$accountLabel}]");
                    $this->aliyunService->controlInstance($account, 'stop');
                }
            } catch (\Exception $e) {
                $this->db->addLog('error', "后台异步释放行动异常，将于下一分钟轮询重试 [{$accountLabel}]: " . strip_tags($e->getMessage()));
            }
        }
    }

    // ---- helpers ----

    private function safeGetTraffic($account): array
    {
        return Helpers::safeGetCdtTraffic($this->aliyunService, $account);
    }

    private function safeGetInstanceStatus($account): string
    {
        try { return $this->aliyunService->getInstanceStatus($account); }
        catch (\Exception $e) { return 'Unknown'; }
    }

    private function releaseManagedEipForPendingAccount(array &$account, string $accountLabel): bool
    {
        if (($account['public_ip_mode'] ?? '') !== 'eip' || empty($account['eip_managed'])) return true;
        try {
            if ($this->aliyunService->releaseManagedEip($account)) {
                $this->db->addLog('info', "托管 EIP 已释放 [{$accountLabel}] " . ($account['eip_address'] ?? ''));
                $this->configManager->updateAccountNetworkMetadata($account['id'], [
                    'public_ip' => '', 'public_ip_mode' => 'eip', 'eip_allocation_id' => '',
                    'eip_address' => '', 'eip_managed' => 0,
                    'internet_max_bandwidth_out' => $account['internet_max_bandwidth_out'] ?? 0
                ]);
                $account['public_ip'] = ''; $account['eip_allocation_id'] = ''; $account['eip_address'] = ''; $account['eip_managed'] = 0;
            }
            return true;
        } catch (\Exception $e) {
            $this->db->addLog('warning', "托管 EIP 释放失败，将于下一轮重试 [{$accountLabel}]: " . strip_tags($e->getMessage()));
            return false;
        }
    }

}
