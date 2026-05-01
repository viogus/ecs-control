<?php

class FrontendResponseBuilder
{
    private ConfigManager $configManager;
    private Database $db;
    private AliyunService $aliyunService;

    public function __construct(
        ConfigManager $configManager,
        Database $db,
        AliyunService $aliyunService
    ) {
        $this->configManager = $configManager;
        $this->db = $db;
        $this->aliyunService = $aliyunService;
    }

    public function getConfigForFrontend(): array
    {
        $settings = $this->configManager->getAllSettings();
        $accountGroups = $this->configManager->getAccountGroups();
        $groupMetrics = $this->configManager->getAccountGroupMetrics();
        $billingMetrics = $this->getAccountGroupBillingMetrics();

        $config = [
            'admin_password' => !empty($settings['admin_password']) ? '********' : '',
            'admin_password_set' => !empty($settings['admin_password']),
            'traffic_threshold' => (int) ($settings['traffic_threshold'] ?? 95),
            'shutdown_mode' => $settings['shutdown_mode'] ?? 'KeepCharging',
            'threshold_action' => $settings['threshold_action'] ?? 'stop_and_notify',
            'keep_alive' => ($settings['keep_alive'] ?? '0') === '1',
            'monthly_auto_start' => ($settings['monthly_auto_start'] ?? '0') === '1',
            'api_interval' => (int) ($settings['api_interval'] ?? 600),
            'enable_billing' => ($settings['enable_billing'] ?? '0') === '1',
            'AppBrand' => ['logo_url' => $settings['app_logo_url'] ?? ''],
            'Notification' => [
                'email_enabled' => ($settings['notify_email_enabled'] ?? '1') === '1',
                'email' => $settings['notify_email'] ?? '',
                'host' => $settings['notify_host'] ?? '',
                'port' => $settings['notify_port'] ?? 465,
                'username' => $settings['notify_username'] ?? '',
                'password' => !empty($settings['notify_password']) ? '********' : '',
                'secure' => $settings['notify_secure'] ?? 'ssl',
                'telegram' => [
                    'enabled' => ($settings['notify_tg_enabled'] ?? '0') === '1',
                    'token' => !empty($settings['notify_tg_token']) ? '********' : '',
                    'chat_id' => $settings['notify_tg_chat_id'] ?? '',
                    'proxy_type' => $settings['notify_tg_proxy_type'] ?? 'none',
                    'proxy_url' => $settings['notify_tg_proxy_url'] ?? '',
                    'proxy_ip' => $settings['notify_tg_proxy_ip'] ?? '',
                    'proxy_port' => $settings['notify_tg_proxy_port'] ?? '',
                    'proxy_user' => $settings['notify_tg_proxy_user'] ?? '',
                    'proxy_pass' => !empty($settings['notify_tg_proxy_pass']) ? '********' : '',
                    'allowed_user_ids' => $settings['notify_tg_allowed_user_ids'] ?? '',
                    'confirm_ttl' => (int) ($settings['notify_tg_confirm_ttl'] ?? 60)
                ],
                'webhook' => [
                    'enabled' => ($settings['notify_wh_enabled'] ?? '0') === '1',
                    'url' => $settings['notify_wh_url'] ?? '',
                    'method' => $settings['notify_wh_method'] ?? 'GET',
                    'request_type' => $settings['notify_wh_request_type'] ?? 'JSON',
                    'headers' => $settings['notify_wh_headers'] ?? '',
                    'body' => $settings['notify_wh_body'] ?? ''
                ]
            ],
            'Ddns' => [
                'enabled' => ($settings['ddns_enabled'] ?? '0') === '1',
                'provider' => $settings['ddns_provider'] ?? 'cloudflare',
                'domain' => $settings['ddns_domain'] ?? '',
                'cloudflare' => [
                    'zone_id' => $settings['ddns_cf_zone_id'] ?? '',
                    'token' => !empty($settings['ddns_cf_token']) ? '********' : '',
                    'proxied' => ($settings['ddns_cf_proxied'] ?? '0') === '1'
                ]
            ],
            'Accounts' => []
        ];

        foreach ($accountGroups as $row) {
            $metrics = $groupMetrics[$row['groupKey']] ?? [
                'usageUsed' => 0, 'usageRemaining' => (float) ($row['maxTraffic'] ?? 0),
                'usagePercent' => 0, 'instanceCount' => 0, 'lastUpdated' => 0,
                'trafficStatus' => 'ok', 'trafficMessage' => ''
            ];
            $config['Accounts'][] = [
                'AccessKeyId' => $row['AccessKeyId'],
                'AccessKeySecret' => '********',
                'AccessKeySecretSet' => !empty($row['AccessKeySecret']),
                'regionId' => $row['regionId'],
                'maxTraffic' => (float) $row['maxTraffic'],
                'remark' => $row['remark'] ?? '',
                'siteType' => $row['siteType'] ?? 'international',
                'groupKey' => $row['groupKey'] ?? '',
                'scheduleEnabled' => !empty($row['scheduleEnabled']),
                'scheduleStartEnabled' => !empty($row['scheduleStartEnabled']),
                'scheduleStopEnabled' => !empty($row['scheduleStopEnabled']),
                'startTime' => $row['startTime'] ?? '',
                'stopTime' => $row['stopTime'] ?? '',
                'scheduleBlockedByTraffic' => !empty($row['scheduleBlockedByTraffic']),
                'usageUsed' => round((float) ($metrics['usageUsed'] ?? 0), 6),
                'usageRemaining' => round((float) ($metrics['usageRemaining'] ?? 0), 6),
                'usagePercent' => round((float) ($metrics['usagePercent'] ?? 0), 2),
                'instanceCount' => (int) ($metrics['instanceCount'] ?? 0),
                'usageLastUpdated' => !empty($metrics['lastUpdated']) ? date('Y-m-d H:i:s', (int) $metrics['lastUpdated']) : '',
                'trafficStatus' => $metrics['trafficStatus'] ?? 'ok',
                'trafficMessage' => $metrics['trafficMessage'] ?? '',
                'billing' => $billingMetrics[$row['groupKey']] ?? [
                    'enabled' => ($settings['enable_billing'] ?? '0') === '1',
                    'monthly_cost' => null, 'balance' => null,
                    'currency' => ($row['siteType'] ?? 'international') === 'international' ? 'USD' : 'CNY',
                    'last_updated' => null, 'error' => null
                ]
            ];
        }

        return $config;
    }

    public function getStatusForFrontend(bool $includeSensitive = false): array
    {
        $this->configManager->syncAccountGroups();

        $data = [];
        $threshold = (int) $this->configManager->get('traffic_threshold', 95);
        $userInterval = (int) $this->configManager->get('api_interval', 600);
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        $accounts = array_values(array_filter($this->configManager->getAccounts(), function ($account) {
            return !empty($account['instance_id']);
        }));

        foreach ($accounts as $account) {
            $data[] = $this->buildInstanceSnapshot($account, $threshold, $userInterval, $billingEnabled, $includeSensitive);
        }

        $pendingAccounts = $this->configManager->getPendingReleaseAccounts();
        foreach ($pendingAccounts as $account) {
            $snap = $this->buildInstanceSnapshot($account, $threshold, $userInterval, $billingEnabled, $includeSensitive);
            $snap['instanceStatus'] = 'Releasing';
            $snap['status'] = 'Releasing';
            $snap['operationLocked'] = true;
            $snap['operationLockedReason'] = '实例正在释放中，后台队列会继续处理。';
            $data[] = $snap;
        }

        return [
            'data' => $data,
            'system_last_run' => $this->configManager->getLastRunTime(),
            'sync_interval' => $userInterval,
            'sensitive_visible' => $includeSensitive
        ];
    }

    public function buildInstanceSnapshot(
        array $account,
        int $threshold,
        int $userInterval,
        bool $billingEnabled,
        bool $includeSensitive = true,
        bool $forceRefresh = false
    ): array {
        $currentTime = time();
        $lastUpdate = (int) ($account['updated_at'] ?? 0);
        $cachedStatus = $account['instance_status'] ?? 'Unknown';
        $newUpdateTime = $currentTime;
        $trafficApiStatus = $account['traffic_api_status'] ?? 'ok';
        $trafficApiMessage = $account['traffic_api_message'] ?? '';

        $isTransientState = in_array($cachedStatus, ['Starting', 'Stopping', 'Pending', 'Unknown'], true);
        $checkInterval = $isTransientState ? 60 : $userInterval;

        if ($forceRefresh || ($currentTime - $lastUpdate) > $checkInterval) {
            $trafficResult = $this->safeGetTraffic($account);
            $status = $this->safeGetInstanceStatus($account);

            if ($status === 'Unknown') {
                $status = $cachedStatus;
            }

            $metadata = [
                'traffic_api_status' => $trafficResult['status'] ?? 'ok',
                'traffic_api_message' => $trafficResult['message'] ?? ''
            ];
            if ($this->isCredentialInvalidTrafficStatus($trafficResult['status'] ?? '')) {
                $metadata['protection_suspended'] = 1;
                $metadata['protection_suspend_reason'] = 'credential_invalid';
            } else {
                $metadata['protection_suspended'] = 0;
                $metadata['protection_suspend_reason'] = '';
                $metadata['protection_suspend_notified_at'] = 0;
            }
            $trafficApiStatus = $metadata['traffic_api_status'];
            $trafficApiMessage = $metadata['traffic_api_message'];

            if (empty($trafficResult['success'])) {
                $traffic = (float) ($account['traffic_used'] ?? 0);
                $newUpdateTime = $lastUpdate;
            } else {
                $traffic = (float) ($trafficResult['value'] ?? 0);
                $this->db->addHourlyStat($account['id'], $traffic);
                $this->db->addDailyStat($account['id'], $traffic);
            }

            if ($newUpdateTime <= 0) {
                $newUpdateTime = $currentTime;
            }

            if ($status === 'Running' && ($account['health_status'] ?? '') !== 'OK') {
                $full = $this->safeGetInstanceFullStatus($account);
                if ($full) {
                    $metadata['health_status'] = $full['healthStatus'];
                }
            }

            $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $newUpdateTime, $metadata);
            $lastUpdate = $newUpdateTime;
        } else {
            $traffic = (float) ($account['traffic_used'] ?? 0);
            $status = $cachedStatus;
        }

        $maxTraffic = (float) ($account['max_traffic'] ?? 0);
        $usagePercent = $maxTraffic > 0 ? round(($traffic / $maxTraffic) * 100, 2) : 0;
        $instanceName = $account['instance_name'] ?? '';
        $remark = $account['remark'] ?? '';
        $accountDisplayLabel = $this->getAccountLogLabel($account);

        $item = [
            'id' => (int) $account['id'],
            'accountId' => (int) $account['id'],
            'groupKey' => $account['group_key'] ?? '',
            'account' => substr($account['access_key_id'], 0, 7) . '***',
            'accountMasked' => substr($account['access_key_id'], 0, 7) . '***',
            'accountLabel' => $accountDisplayLabel . ' / ' . $this->getRegionName($account['region_id']),
            'flow_total' => $maxTraffic,
            'flow_used' => round($traffic, 6),
            'percentageOfUse' => $usagePercent,
            'trafficStatus' => $trafficApiStatus,
            'trafficMessage' => $trafficApiMessage,
            'region' => $account['region_id'],
            'regionId' => $account['region_id'],
            'regionName' => $this->getRegionName($account['region_id']),
            'rate95' => $usagePercent >= $threshold,
            'threshold' => $threshold,
            'instanceStatus' => $status,
            'status' => $status,
            'healthStatus' => $account['health_status'] ?? 'Unknown',
            'stoppedMode' => $account['stopped_mode'] ?? 'KeepCharging',
            'cpu' => (int) ($account['cpu'] ?? 0),
            'memory' => (int) ($account['memory'] ?? 0),
            'lastUpdated' => date('Y-m-d H:i:s', $lastUpdate > 0 ? $lastUpdate : $currentTime),
            'remark' => $remark !== '' ? $remark : ($instanceName !== '' ? $instanceName : ($account['instance_id'] ?? '')),
            'instanceId' => $account['instance_id'] ?? '',
            'instanceName' => $instanceName,
            'instanceType' => $account['instance_type'] ?? '',
            'osName' => $account['os_name'] ?? '',
            'internetMaxBandwidthOut' => (int) ($account['internet_max_bandwidth_out'] ?? 0),
            'publicIp' => $includeSensitive ? ($account['public_ip'] ?? '') : '',
            'publicIpMode' => $account['public_ip_mode'] ?? 'ecs_public_ip',
            'eipAllocationId' => $includeSensitive ? ($account['eip_allocation_id'] ?? '') : '',
            'eipAddress' => $includeSensitive ? ($account['eip_address'] ?? '') : '',
            'eipManaged' => !empty($account['eip_managed']),
            'privateIp' => $includeSensitive ? ($account['private_ip'] ?? '') : '',
            'maxTraffic' => $maxTraffic,
            'siteType' => $account['site_type'] ?? 'international'
        ];

        if ($billingEnabled) {
            $item['cost'] = $this->safeGetBillingInfo($account, date('Y-m'));
        }

        return $item;
    }

    private function getAccountLogLabel(array $account): string
    {
        $remark = trim((string) ($account['remark'] ?? ''));
        if ($remark !== '') return $remark;
        $instanceName = trim((string) ($account['instance_name'] ?? ''));
        if ($instanceName !== '') return $instanceName;
        $instanceId = trim((string) ($account['instance_id'] ?? ''));
        if ($instanceId !== '') return $instanceId;
        return substr((string) ($account['access_key_id'] ?? ''), 0, 7) . '***';
    }

    private function getRegionName($regionId): string
    {
        $regions = [
            'cn-hongkong' => '中国香港', 'ap-southeast-1' => '新加坡', 'us-west-1' => '美国(硅谷)',
            'us-east-1' => '美国(弗吉尼亚)', 'cn-hangzhou' => '华东1(杭州)', 'cn-shanghai' => '华东2(上海)',
            'cn-qingdao' => '华北1(青岛)', 'cn-beijing' => '华北2(北京)', 'cn-zhangjiakou' => '华北3(张家口)',
            'cn-huhehaote' => '华北5(呼和浩特)', 'cn-wulanchabu' => '华北6(乌兰察布)',
            'cn-shenzhen' => '华南1(深圳)', 'cn-heyuan' => '华南2(河源)', 'cn-guangzhou' => '华南3(广州)',
            'cn-chengdu' => '西南1(成都)', 'ap-northeast-1' => '日本(东京)',
        ];
        return $regions[$regionId] ?? $regionId;
    }

    private function safeGetTraffic(array $account): array
    {
        try {
            $value = $this->aliyunService->getTraffic(
                $account['access_key_id'], $account['access_key_secret'], $account['region_id']
            );
            return ['success' => true, 'value' => $value, 'status' => 'ok', 'message' => ''];
        } catch (\Exception $e) {
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => ''];
        }
    }

    private function safeGetInstanceStatus(array $account): string
    {
        try { return $this->aliyunService->getInstanceStatus($account); }
        catch (\Exception $e) { return 'Unknown'; }
    }

    private function safeGetInstanceFullStatus(array $account): ?array
    {
        try { return $this->aliyunService->getInstanceFullStatus($account); }
        catch (\Exception $e) { return null; }
    }

    private function isCredentialInvalidTrafficStatus($status): bool
    {
        return trim((string) $status) === 'auth_error';
    }

    private function safeGetBillingInfo(array $account, string $billingCycle): array
    {
        $costInfo = ['enabled' => true, 'monthly_cost' => null, 'balance' => null,
            'currency' => 'CNY', 'last_updated' => null, 'error' => null];

        $balanceCache = $this->db->getBillingCache($account['id'], 'balance', '', 21600);
        if ($balanceCache) {
            $costInfo['balance'] = $balanceCache['AvailableAmount'];
            $costInfo['currency'] = $balanceCache['Currency'] ?? 'CNY';
        } else {
            try {
                $balance = $this->aliyunService->getAccountBalance(
                    $account['access_key_id'], $account['access_key_secret'], $account['site_type'] ?? 'china'
                );
                $costInfo['balance'] = $balance['AvailableAmount'];
                $costInfo['currency'] = $balance['Currency'] ?? 'CNY';
                $this->db->setBillingCache($account['id'], 'balance', '', $balance);
            } catch (\Exception $e) {
                $costInfo['error'] = '余额查询失败';
            }
        }

        if (!empty($account['instance_id'])) {
            $billCache = $this->db->getBillingCache($account['id'], 'instance_bill', $billingCycle, 21600);
            if ($billCache) {
                $costInfo['monthly_cost'] = $billCache['TotalCost'];
            } else {
                try {
                    $bill = $this->aliyunService->getInstanceBill(
                        $account['access_key_id'], $account['access_key_secret'],
                        $account['instance_id'], $billingCycle, $account['site_type'] ?? 'china'
                    );
                    $costInfo['monthly_cost'] = $bill['TotalCost'];
                    $this->db->setBillingCache($account['id'], 'instance_bill', $billingCycle, $bill);
                } catch (\Exception $e) {
                    $costInfo['error'] = $costInfo['error'] ? '费用中心权限不足' : '账单查询失败';
                }
            }
        }

        $costInfo['last_updated'] = date('Y-m-d H:i:s');
        return $costInfo;
    }

    public function getAccountGroupBillingMetrics(bool $forceRefresh = false): array
    {
        if ($this->configManager->get('enable_billing', '0') !== '1') {
            return [];
        }

        $billingCycle = date('Y-m');
        $groups = $this->configManager->getAccountGroups();
        $accounts = $this->configManager->getAccounts();
        $accountsByGroup = [];

        foreach ($accounts as $account) {
            $groupKey = $account['group_key'] ?: ($account['access_key_id'] . '@' . $account['region_id']);
            if (!isset($accountsByGroup[$groupKey])) {
                $accountsByGroup[$groupKey] = [];
            }
            $accountsByGroup[$groupKey][] = $account;
        }

        $metrics = [];
        foreach ($groups as $group) {
            $groupKey = $group['groupKey'] ?? '';
            $row = $accountsByGroup[$groupKey][0] ?? null;
            $currency = ($group['siteType'] ?? 'international') === 'international' ? 'USD' : 'CNY';
            $summary = ['enabled' => true, 'monthly_cost' => null, 'balance' => null,
                'currency' => $currency, 'last_updated' => null, 'error' => null];

            if (!$row) {
                $summary['error'] = '尚未同步实例';
                $metrics[$groupKey] = $summary;
                continue;
            }

            try {
                $balanceCache = $this->db->getBillingCache($row['id'], 'balance', '', 21600);
                if ($balanceCache) {
                    $summary['balance'] = $balanceCache['AvailableAmount'] ?? null;
                    $summary['currency'] = $balanceCache['Currency'] ?? $currency;
                } else {
                    $balance = $this->aliyunService->getAccountBalance(
                        $row['access_key_id'], $row['access_key_secret'],
                        $row['site_type'] ?? ($group['siteType'] ?? 'international')
                    );
                    $summary['balance'] = $balance['AvailableAmount'] ?? null;
                    $summary['currency'] = $balance['Currency'] ?? $currency;
                    $this->db->setBillingCache($row['id'], 'balance', '', $balance);
                }
            } catch (\Exception $e) {
                $summary['error'] = '余额查询失败';
            }

            try {
                $overviewCache = $this->db->getBillingCache($row['id'], 'bill_overview', $billingCycle, 21600);
                if ($overviewCache) {
                    $summary['monthly_cost'] = $overviewCache['TotalCost'] ?? null;
                } else {
                    $overview = $this->aliyunService->getBillOverview(
                        $row['access_key_id'], $row['access_key_secret'], $billingCycle,
                        $row['site_type'] ?? ($group['siteType'] ?? 'international')
                    );
                    $summary['monthly_cost'] = $overview['TotalCost'] ?? null;
                    $this->db->setBillingCache($row['id'], 'bill_overview', $billingCycle, $overview);
                }
            } catch (\Exception $e) {
                $summary['error'] = $summary['error'] ? '费用中心权限不足' : '账单查询失败';
            }

            $summary['last_updated'] = date('Y-m-d H:i:s');
            $metrics[$groupKey] = $summary;
        }

        return $metrics;
    }
}
