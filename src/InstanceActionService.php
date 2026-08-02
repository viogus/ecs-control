<?php

declare(strict_types=1);

class InstanceActionService
{
    private AliyunService $aliyunService;
    private ConfigManager $configManager;
    private Database $db;
    private NotificationService $notificationService;
    private DdnsService $ddnsService;
    private BssService $bssService;

    public function __construct(
        AliyunService $aliyunService,
        ConfigManager $configManager,
        Database $db,
        NotificationService $notificationService,
        DdnsService $ddnsService,
        BssService $bssService
    ) {
        $this->aliyunService = $aliyunService;
        $this->configManager = $configManager;
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->ddnsService = $ddnsService;
        $this->bssService = $bssService;
    }

    // ---- public API ----

    public function controlInstance($accountId, string $action, $shutdownMode = 'KeepCharging', $waitForSync = true, callable $onStatusChanged = null): bool
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return false;

        try {
            $result = $this->aliyunService->controlInstance($targetAccount, $action, $shutdownMode);
            if ($result) {
                $label = Helpers::getAccountLogLabel($targetAccount);
                $instId = $targetAccount->instanceId;
                $this->db->addLog('info', "实例操作 [{$action}] 成功 [{$label}] {$instId}");
                $newStatus = $action === 'stop' ? InstanceStatus::Stopping->value : InstanceStatus::Starting->value;
                $this->configManager->updateAccountStatus($accountId, $targetAccount->trafficUsed, $newStatus, time());
                $this->configManager->updateAutoStartBlocked($accountId, $action === 'stop');
                if ($onStatusChanged) {
                    $onStatusChanged($targetAccount, $targetAccount->instanceStatus, $newStatus, '用户手动操作。');
                }
                if ($action === 'start' && $waitForSync) {
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

        // forceStop=true:记录强制释放标记,队列删除时 Force=true(对运行中实例直接强删)
        if ($forceStop) {
            $this->db->getPdo()
                ->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, '1')")
                ->execute(['force_stop_' . (int) $accountId]);
        }

        $this->db->addLog('warning', "操作成功：秒级标记释放指令已提交，后台安全队列正在接管" . ($forceStop ? '（强制释放）' : '') . " [{$targetAccount->instanceId}]");
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

    // ---- IPv6 管理 ----
    // 阿里云无 IPv6 EIP:公网 IPv6 走 VPC IPv6 网关路径。
    // 分配 = 自动开通前置(VPC/交换机 IPv6 + IPv6 网关) + 网卡分配地址 + 开通公网带宽(按量计费)。

    public function allocateIpv6($accountId, int $bandwidth = 5): array
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return ['success' => false, 'message' => '实例不存在'];

        if (!empty($targetAccount->ipv6Address)) {
            return ['success' => false, 'message' => "该实例已分配 IPv6: {$targetAccount->ipv6Address}"];
        }

        $label = Helpers::getAccountLogLabel($targetAccount);
        $lockKey = 'ipv6_allocating_' . (int) $accountId;

        // 后端并发锁(原子获取 + 10 分钟过期,分配流程含多个串行 API):防止极速双击/重复请求同时执行分配
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$lockKey]);
        $lockValue = (int) $stmt->fetchColumn();
        if ($lockValue > 0) {
            if ((time() - $lockValue) < 600) {
                return ['success' => false, 'message' => '该实例正在分配 IPv6，请稍候'];
            }
            // 锁过期(进程异常退出):清除后继续
            $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([$lockKey]);
        }
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$lockKey, (string) time()]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => '该实例正在分配 IPv6，请稍候'];
        }

        try {
            $key = $targetAccount->accessKeyId;
            $secret = $targetAccount->accessKeySecret;
            $regionId = $targetAccount->regionId;

            $this->db->addLog('info', "开始分配 IPv6 [{$label}]");

            // 幂等恢复:云端已有 IPv6 则复用(上次分配中断/半成功场景),补齐带宽并落库
            $existing = $this->aliyunService->describeIpv6Addresses($key, $secret, $regionId, $targetAccount->instanceId);
            if (!empty($existing)) {
                $item = $existing[0];
                $ipv6Address = $item['ipv6Address'] ?? '';
                $bandwidthId = $item['internetBandwidthId'] ?? '';
                if ($bandwidthId === '' && !empty($item['ipv6AddressId'])) {
                    $alloc = $this->aliyunService->allocateIpv6InternetBandwidth($key, $secret, $regionId, $item['ipv6AddressId'], $bandwidth);
                    $bandwidthId = $alloc['internetBandwidthId'] ?? '';
                    if ($bandwidthId === '' && ($alloc['status'] ?? '') === 'already_exists') {
                        // 带宽已存在但查询未返回 ID:重查一次避免覆盖本地记录
                        foreach ($this->aliyunService->describeIpv6Addresses($key, $secret, $regionId, $targetAccount->instanceId) as $item2) {
                            if ($this->normalizeIpv6($item2['ipv6Address'] ?? '') === $this->normalizeIpv6($item['ipv6Address'] ?? '')) {
                                $bandwidthId = $item2['internetBandwidthId'] ?? '';
                                break;
                            }
                        }
                    }
                }
                $this->configManager->updateAccountIpv6Metadata($accountId, [
                    'ipv6_address' => $ipv6Address,
                    'ipv6_internet_bandwidth_id' => $bandwidthId,
                    'ipv6_gateway_id' => $targetAccount->ipv6GatewayId ?? '',
                ]);
                $this->db->addLog('info', "IPv6 云端已存在,已复用 [{$label}] {$ipv6Address}");
                return ['success' => true, 'message' => 'IPv6 已存在并复用', 'data' => ['ipv6Address' => $ipv6Address, 'ipv6InternetBandwidthId' => $bandwidthId]];
            }

            // 1. 获取主网卡/VPC/交换机
            $eni = $this->aliyunService->getNetworkInterfaceInfo($targetAccount);

            // 2. 自动开通前置(尽力而为:已开通的步骤会被视为成功;地域/CDT 限制会在此报出)
            $this->aliyunService->enableVpcIpv6($key, $secret, $regionId, $eni['vpcId']);
            $this->aliyunService->enableVSwitchIpv6($key, $secret, $regionId, $eni['vswitchId']);
            $gatewayId = $this->aliyunService->ensureIpv6Gateway(
                $key, $secret, $regionId, $eni['vpcId'],
                'ecs-control-ipv6gw-' . substr((string) $targetAccount->instanceId, -8)
            );

            // 3. 分配 IPv6 地址
            $ipv6Address = $this->aliyunService->assignIpv6Address($key, $secret, $regionId, $eni['networkInterfaceId']);

            // 4. 查询 Ipv6AddressId 并开通公网带宽(按流量计费,PayByTraffic)
            // 查询有最终一致性延迟:短重试 3 次 × 2 秒;地址比对前规范化(inet_pton)
            $ipv6AddressId = '';
            $normalized = $this->normalizeIpv6($ipv6Address);
            for ($i = 0; $i < 3; $i++) {
                foreach ($this->aliyunService->describeIpv6Addresses($key, $secret, $regionId, $targetAccount->instanceId) as $item) {
                    if ($this->normalizeIpv6($item['ipv6Address'] ?? '') === $normalized) {
                        $ipv6AddressId = $item['ipv6AddressId'] ?? '';
                        break 2;
                    }
                }
                if ($i < 2) {
                    usleep(2000000 + rand(0, 500000)); // 2~2.5s 带抖动,避免与云端一致性延迟同频白等
                }
            }

            $bandwidthId = '';
            if ($ipv6AddressId === '') {
                // 查询不到 Ipv6AddressId:地址已分配但无法开带宽,返回失败并保留云端地址供幂等恢复
                $this->db->addLog('warning', "IPv6 地址已分配但查询 Ipv6AddressId 超时 [{$label}] {$ipv6Address},可再次点击分配进行幂等恢复");
                return ['success' => false, 'message' => 'IPv6 地址已分配，但公网带宽开通前查询超时，请稍后再次点击"分配 IPv6"自动补齐'];
            }
            $alloc = $this->aliyunService->allocateIpv6InternetBandwidth($key, $secret, $regionId, $ipv6AddressId, $bandwidth);
            $bandwidthId = $alloc['internetBandwidthId'] ?? '';
            if ($bandwidthId === '' && ($alloc['status'] ?? '') === 'already_exists') {
                // 带宽已存在(如并发下另一请求刚开通):重查一次获取真实 ID
                foreach ($this->aliyunService->describeIpv6Addresses($key, $secret, $regionId, $targetAccount->instanceId) as $item) {
                    if ($this->normalizeIpv6($item['ipv6Address'] ?? '') === $normalized) {
                        $bandwidthId = $item['internetBandwidthId'] ?? '';
                        break;
                    }
                }
            }

            // 5. 落库
            $this->configManager->updateAccountIpv6Metadata($accountId, [
                'ipv6_address' => $ipv6Address,
                'ipv6_internet_bandwidth_id' => $bandwidthId,
                'ipv6_gateway_id' => $gatewayId,
            ]);
            $this->db->addLog('info', "IPv6 分配成功 [{$label}] {$ipv6Address}" . ($bandwidthId !== '' ? " (公网带宽 {$bandwidth}Mbps 按量计费)" : ' (仅内网 IPv6,未开通公网带宽)'));

            return [
                'success' => true,
                'message' => $bandwidthId !== '' ? 'IPv6 公网地址分配成功' : 'IPv6 地址已分配(公网带宽暂未确认,可稍后再次点击"分配 IPv6"自动补齐)',
                'data' => ['ipv6Address' => $ipv6Address, 'ipv6InternetBandwidthId' => $bandwidthId]
            ];
        } catch (\Throwable $e) {
            $this->db->addLog('error', "IPv6 分配失败 [{$label}]: " . strip_tags($e->getMessage()));
            return ['success' => false, 'message' => strip_tags($e->getMessage())];
        } finally {
            // 清除并发锁
            $this->db->getPdo()
                ->prepare("DELETE FROM settings WHERE key = ?")
                ->execute([$lockKey]);
        }
    }

    public function releaseIpv6($accountId): array
    {
        $targetAccount = $this->configManager->getAccountById($accountId);
        if (!$targetAccount) return ['success' => false, 'message' => '实例不存在'];

        $label = Helpers::getAccountLogLabel($targetAccount);
        // 与分配互斥:分配进行中禁止释放(锁带 5 分钟过期,与分配路径一致)
        $lockKey = 'ipv6_allocating_' . (int) $accountId;
        $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$lockKey]);
        $lockValue = (int) $stmt->fetchColumn();
        if ($lockValue > 0 && (time() - $lockValue) < 600) {
            return ['success' => false, 'message' => '该实例正在分配 IPv6，请稍候再释放'];
        }

        try {
            $key = $targetAccount->accessKeyId;
            $secret = $targetAccount->accessKeySecret;
            $regionId = $targetAccount->regionId;

            // 1. 查询云端已分配的 IPv6(含公网带宽)
            $list = $this->aliyunService->describeIpv6Addresses($key, $secret, $regionId, $targetAccount->instanceId);
            $addressesToRemove = [];
            $bandwidthsReleased = 0;
            $releasedBandwidthIds = []; // 已释放集合,避免同一带宽重复释放
            foreach ($list as $item) {
                if (!empty($item['ipv6Address'])) {
                    $addressesToRemove[] = $item['ipv6Address'];
                }
                $bwId = $item['internetBandwidthId'] ?? '';
                if ($bwId !== '' && !isset($releasedBandwidthIds[$bwId])) {
                    $this->aliyunService->releaseIpv6InternetBandwidth($key, $secret, $regionId, $bwId);
                    $releasedBandwidthIds[$bwId] = true;
                    $bandwidthsReleased++;
                }
            }
            // 本地兜底:云端查询不到时,按本地记录回收地址并释放带宽(防止带宽残留持续计费)
            if (empty($addressesToRemove) && !empty($targetAccount->ipv6Address)) {
                $addressesToRemove[] = $targetAccount->ipv6Address;
            }
            $localBwId = $targetAccount->ipv6InternetBandwidthId ?? '';
            if ($localBwId !== '' && !isset($releasedBandwidthIds[$localBwId])) {
                $this->aliyunService->releaseIpv6InternetBandwidth($key, $secret, $regionId, $localBwId);
                $releasedBandwidthIds[$localBwId] = true;
                $bandwidthsReleased++;
            }

            // 2. 回收网卡地址
            if (!empty($addressesToRemove)) {
                $eni = $this->aliyunService->getNetworkInterfaceInfo($targetAccount);
                foreach ($addressesToRemove as $addr) {
                    $this->aliyunService->unassignIpv6Addresses($key, $secret, $regionId, $eni['networkInterfaceId'], $addr);
                }
            }

            // 3. 清库
            $this->configManager->updateAccountIpv6Metadata($accountId, [
                'ipv6_address' => '', 'ipv6_internet_bandwidth_id' => '', 'ipv6_gateway_id' => '',
            ]);
            $this->db->addLog('warning', "IPv6 已释放 [{$label}] " . (implode(',', $addressesToRemove) ?: '无') . ($bandwidthsReleased > 0 ? " (释放公网带宽 {$bandwidthsReleased} 条)" : ''));

            return ['success' => true, 'message' => 'IPv6 已释放'];
        } catch (\Throwable $e) {
            $this->db->addLog('error', "IPv6 释放失败 [{$label}]: " . strip_tags($e->getMessage()));
            return ['success' => false, 'message' => strip_tags($e->getMessage())];
        }
    }

    /**
     * IPv6 地址规范化:统一小写并展开为全写形式,用于跨 API 响应比对。
     */
    private function normalizeIpv6(string $address): string
    {
        $address = strtolower(trim($address));
        if ($address === '' || !str_contains($address, ':')) {
            return $address;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return $address;
        }
        $expanded = inet_ntop($packed);
        return $expanded !== false ? $expanded : $address;
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
                    $balance = $this->bssService->getAccountBalance($targetAccount['access_key_id'], $targetAccount['access_key_secret'], $targetAccount['site_type'] ?? 'china');
                    $this->db->setBillingCache($targetAccount['id'], 'balance', '', $balance);
                } catch (\Exception $e) { $billingError = '余额查询失败: ' . strip_tags($e->getMessage()); }
            }
            if (!empty($targetAccount['instance_id'])) {
                $billCache = $this->db->getBillingCache($targetAccount['id'], 'instance_bill', $billingCycle, 21600);
                if (!$billCache) {
                    try {
                        $bill = $this->bssService->getInstanceBill($targetAccount['access_key_id'], $targetAccount['access_key_secret'], $targetAccount['instance_id'], $billingCycle, $targetAccount['site_type'] ?? 'china');
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
            $snap['instanceStatus'] = InstanceStatus::Releasing->value;
            $snap['status'] = InstanceStatus::Releasing->value;
            $snap['operationLocked'] = true;
            $snap['operationLockedReason'] = '实例正在释放中，后台队列会继续处理。';
            $allInstances[] = $snap;
        }

        return $allInstances;
    }

    public function processPendingReleases(callable $onReleased = null): void
    {
        // 整体时间预算:释放队列逐账号串行(每账号含多次 API + 指数退避),
        // 超时留待下一轮,避免长时间占用 cron 周期
        $startedAt = time();
        $timeBudget = 300;
        $pendingAccounts = $this->configManager->getPendingReleaseAccounts();
        foreach ($pendingAccounts as $account) {
            if (time() - $startedAt > $timeBudget) {
                $this->db->addLog('warning', '后台释放队列时间预算耗尽，剩余账号留待下一轮');
                break;
            }
            $accountLabel = Helpers::getAccountLogLabel($account);
            // 读取强制释放标记(用户勾选"强制释放"时,删除请求 Force=true)
            $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute(['force_stop_' . (int) $account->id]);
            $forceStop = ((string) $stmt->fetchColumn()) === '1';
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
                if ($status === InstanceStatus::Stopped->value) {
                    if (!$this->releaseManagedEipForPendingAccount($account, $accountLabel)) continue;
                    $result = $this->aliyunService->deleteInstance($account, $forceStop);
                    if ($result) {
                        $this->db->addLog('warning', "后台异步彻底销毁成功 [{$accountLabel}] {$account->instanceId}");
                        if ($onReleased) $onReleased($accountLabel, $account);
                        $accountsBeforeDelete = $this->configManager->getAccounts();
                        $this->ddnsService->deleteForAccount($account, $accountsBeforeDelete, '后台实例彻底释放');
                        $this->configManager->physicallyDeleteAccount($account->id);
                        $this->ddnsService->reconcileAfterSync($accountsBeforeDelete, $this->configManager->getAccounts(), '异步释放后同步');
                        $this->clearForceStopFlag($account->id);
                    }
                } elseif ($status === 'NotFound') {
                    if (!$this->releaseManagedEipForPendingAccount($account, $accountLabel)) continue;
                    $this->db->addLog('warning', "待释放实例云端已灭迹，自动擦除本地账本 [{$accountLabel}]");
                    $accountsBeforeDelete = $this->configManager->getAccounts();
                    $this->ddnsService->deleteForAccount($account, $accountsBeforeDelete, '实例已灭迹后清理');
                    $this->configManager->physicallyDeleteAccount($account->id);
                    $this->ddnsService->reconcileAfterSync($accountsBeforeDelete, $this->configManager->getAccounts(), '实例灭迹后同步');
                    $this->clearForceStopFlag($account->id);
                } elseif ($status === InstanceStatus::Unknown->value) {
                    $this->db->addLog('warning', "后台异步释放引擎暂时无法确认实例状态，将于下一轮重试 [{$accountLabel}]");
                } elseif ($status !== InstanceStatus::Stopping->value) {
                    if ($forceStop) {
                        // 强制释放:直接对运行中实例下发 Force 删除(阿里云会先停止再删除)
                        $this->db->addLog('warning', "后台异步释放引擎：强制释放运行中实例 [{$accountLabel}]");
                        $result = $this->aliyunService->deleteInstance($account, true);
                        if ($result) {
                            $this->db->addLog('warning', "后台异步彻底销毁成功 [{$accountLabel}] {$account->instanceId}");
                            if ($onReleased) $onReleased($accountLabel, $account);
                            $accountsBeforeDelete = $this->configManager->getAccounts();
                            $this->ddnsService->deleteForAccount($account, $accountsBeforeDelete, '后台强制释放');
                            $this->configManager->physicallyDeleteAccount($account->id);
                            $this->ddnsService->reconcileAfterSync($accountsBeforeDelete, $this->configManager->getAccounts(), '强制释放后同步');
                            $this->clearForceStopFlag($account->id);
                        }
                    } else {
                        $this->db->addLog('info', "后台异步释放引擎：向活跃实例下发强制离线指令 [{$accountLabel}]");
                        $this->aliyunService->controlInstance($account, 'stop');
                    }
                }
            } catch (\Exception $e) {
                $this->db->addLog('error', "后台异步释放行动异常，将于下一分钟轮询重试 [{$accountLabel}]: " . strip_tags($e->getMessage()));
            }
        }
    }

    private function clearForceStopFlag(int $accountId): void
    {
        $this->db->getPdo()
            ->prepare("DELETE FROM settings WHERE key = ?")
            ->execute(['force_stop_' . $accountId]);
    }

    // ---- helpers ----

    private function safeGetTraffic($account): array
    {
        return Helpers::safeGetCdtTraffic($this->aliyunService, $account);
    }

    private function safeGetInstanceStatus($account): string
    {
        try { return $this->aliyunService->getInstanceStatus($account); }
        catch (\Exception $e) {
            $this->db->addLog('warning', '实例状态探测失败 [' . Helpers::getAccountLogLabel($account) . ']: ' . strip_tags($e->getMessage()));
            return InstanceStatus::Unknown->value;
        }
    }

    private function releaseManagedEipForPendingAccount(Account $account, string $accountLabel): bool
    {
        if (($account->publicIpMode ?? '') !== 'eip' || empty($account->eipManaged)) return true;
        try {
            if ($this->aliyunService->releaseManagedEip($account)) {
                $this->db->addLog('info', "托管 EIP 已释放 [{$accountLabel}] " . ($account->eipAddress ?? ''));
                $this->configManager->updateAccountNetworkMetadata($account->id, [
                    'public_ip' => '', 'public_ip_mode' => 'eip', 'eip_allocation_id' => '',
                    'eip_address' => '', 'eip_managed' => 0,
                    'internet_max_bandwidth_out' => $account->internetMaxBandwidthOut ?? 0
                ]);
                $account->publicIp = ''; $account->eipAllocationId = ''; $account->eipAddress = ''; $account->eipManaged = 0;
            }
            return true;
        } catch (\Exception $e) {
            $this->db->addLog('warning', "托管 EIP 释放失败，将于下一轮重试 [{$accountLabel}]: " . strip_tags($e->getMessage()));
            return false;
        }
    }

}
