<?php

class AccountSyncService
{
    private Database $db;
    private array $configCache;
    private string $encryptionKey;

    public function __construct(Database $db, array $configCache, string $encryptionKey)
    {
        $this->db = $db;
        $this->configCache = $configCache;
        $this->encryptionKey = $encryptionKey;
    }

    public function getAccountGroups(): array
    {
        $raw = $this->configCache['account_groups'] ?? '';
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalizeAccountGroups($decoded, true);
            }
        }
        return [];
    }

    public function getAccountGroupMetrics(array $accounts): array
    {
        $groups = $this->getAccountGroups();
        $metrics = [];

        foreach ($groups as $group) {
            $gk = $group['groupKey'];
            $metrics[$gk] = ['usageUsed' => 0.0, 'usageRemaining' => (float) ($group['maxTraffic'] ?? 0),
                'usagePercent' => 0.0, 'instanceCount' => 0, 'lastUpdated' => 0,
                'trafficStatus' => 'ok', 'trafficMessage' => '',
                '_trafficErrorCount' => 0, '_trafficFirstStatus' => '', '_trafficFirstMessage' => ''];
        }

        foreach ($accounts as $acc) {
            $gk = $acc['group_key'] ?: self::buildGroupKey($acc['access_key_id'] ?? '', $acc['region_id'] ?? '');
            if (!isset($metrics[$gk])) {
                $metrics[$gk] = ['usageUsed' => 0.0, 'usageRemaining' => (float) ($acc['max_traffic'] ?? 0),
                    'usagePercent' => 0.0, 'instanceCount' => 0, 'lastUpdated' => 0,
                    'trafficStatus' => 'ok', 'trafficMessage' => '',
                    '_trafficErrorCount' => 0, '_trafficFirstStatus' => '', '_trafficFirstMessage' => ''];
            }
            if (!empty($acc['instance_id'])) {
                $metrics[$gk]['instanceCount']++;
                $ts = trim((string) ($acc['traffic_api_status'] ?? 'ok'));
                if ($ts !== '' && $ts !== 'ok') {
                    $metrics[$gk]['_trafficErrorCount']++;
                    if ($metrics[$gk]['_trafficFirstStatus'] === '') {
                        $metrics[$gk]['_trafficFirstStatus'] = $ts;
                        $metrics[$gk]['_trafficFirstMessage'] = trim((string) ($acc['traffic_api_message'] ?? ''));
                    }
                }
            }
            $isCurrent = ($acc['traffic_billing_month'] ?? '') === date('Y-m');
            $tu = $isCurrent ? (float) ($acc['traffic_used'] ?? 0) : 0.0;
            if ($tu > $metrics[$gk]['usageUsed']) {
                $metrics[$gk]['usageUsed'] = $tu;
            }
            $metrics[$gk]['lastUpdated'] = max($metrics[$gk]['lastUpdated'], (int) ($acc['updated_at'] ?? 0));
        }

        foreach ($groups as $group) {
            $gk = $group['groupKey'];
            $max = (float) ($group['maxTraffic'] ?? 0);
            $used = (float) ($metrics[$gk]['usageUsed'] ?? 0);
            $metrics[$gk]['usageRemaining'] = max($max - $used, 0);
            $metrics[$gk]['usagePercent'] = $max > 0 ? min(round(($used / $max) * 100, 2), 100) : 0;
            $errCount = (int) ($metrics[$gk]['_trafficErrorCount'] ?? 0);
            $instCount = (int) ($metrics[$gk]['instanceCount'] ?? 0);
            if ($instCount > 0 && $errCount > 0) {
                if ($errCount >= $instCount) {
                    $metrics[$gk]['trafficStatus'] = $metrics[$gk]['_trafficFirstStatus'] ?: 'error';
                    $metrics[$gk]['trafficMessage'] = $metrics[$gk]['_trafficFirstMessage'] ?: '账号下实例流量同步失败';
                } else {
                    $metrics[$gk]['trafficStatus'] = 'partial';
                    $metrics[$gk]['trafficMessage'] = '部分实例流量同步失败';
                }
            }
            unset($metrics[$gk]['_trafficErrorCount'], $metrics[$gk]['_trafficFirstStatus'], $metrics[$gk]['_trafficFirstMessage']);
        }
        return $metrics;
    }

    public function syncAccountGroups(array &$accountsCache, ?array $groups = null, ?callable $onLog = null): void
    {
        $groups = $groups ?? $this->getAccountGroups();

        $pdo = $this->db->getPdo();
        $existingRows = $pdo->query("SELECT * FROM accounts ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $existingByGroup = [];
        $existingByComposite = [];

        foreach ($existingRows as $row) {
            $gk = $row['group_key'] ?: self::buildGroupKey($row['access_key_id'], $row['region_id']);
            $existingByGroup[$gk][] = $row;
            $existingByComposite[$gk . '|' . $row['instance_id']] = $row;
        }

        $configuredGroupKeys = [];
        $insertSql = "INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_used,traffic_billing_month,instance_status,updated_at,last_keep_alive_at,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,private_ip,cpu,memory,os_name,stopped_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $insertStmt = $pdo->prepare($insertSql);

        $updateSql = "UPDATE accounts SET access_key_id=?,access_key_secret=?,region_id=?,instance_id=?,max_traffic=?,schedule_enabled=?,schedule_start_enabled=?,schedule_stop_enabled=?,start_time=?,stop_time=?,schedule_blocked_by_traffic=?,instance_status=?,remark=?,site_type=?,group_key=?,instance_name=?,instance_type=?,internet_max_bandwidth_out=?,public_ip=?,public_ip_mode=?,eip_allocation_id=?,eip_address=?,eip_managed=?,private_ip=?,cpu=?,memory=?,os_name=?,stopped_mode=? WHERE id=?";
        $updateStmt = $pdo->prepare($updateSql);

        foreach ($groups as $group) {
            $configuredGroupKeys[] = $group['groupKey'];
            try {
                $service = new AliyunService();
                $instances = $service->getInstances($group['AccessKeyId'], $group['AccessKeySecret'], $group['regionId']);
            } catch (\Exception $e) {
                if ($onLog) $onLog('warning', "实例同步失败 [" . substr($group['AccessKeyId'], 0, 7) . "***] {$group['regionId']}: " . strip_tags($e->getMessage()));
                $this->updateGroupBaseSettings($group['groupKey'], $group);
                continue;
            }

            $remoteInstanceIds = [];
            foreach ($instances as $instance) {
                $remoteInstanceIds[] = $instance['instanceId'];
                $compositeKey = $group['groupKey'] . '|' . $instance['instanceId'];
                $existingRow = $existingByComposite[$compositeKey] ?? null;
                $net = self::resolveNetworkMetadata($instance, $existingRow);

                if ($existingRow) {
                    $updateStmt->execute([
                        $group['AccessKeyId'], $this->encryptValue($group['AccessKeySecret']),
                        $group['regionId'], $instance['instanceId'], $group['maxTraffic'],
                        !empty($group['scheduleEnabled']) ? 1 : 0, !empty($group['scheduleStartEnabled']) ? 1 : 0,
                        !empty($group['scheduleStopEnabled']) ? 1 : 0, $group['startTime'] ?? '', $group['stopTime'] ?? '',
                        !empty($group['scheduleBlockedByTraffic']) ? 1 : 0,
                        $instance['status'] ?: ($existingRow['instance_status'] ?? 'Unknown'),
                        self::resolveRemark($group, $instance, $existingRow), $group['siteType'], $group['groupKey'],
                        $instance['instanceName'] ?? '', $instance['instanceType'] ?? '',
                        (int) ($instance['internetMaxBandwidthOut'] ?? 0), $instance['publicIp'] ?? '',
                        $net['public_ip_mode'], $net['eip_allocation_id'],
                        $net['eip_address'], $net['eip_managed'],
                        $instance['privateIp'] ?? '', (int) ($instance['cpu'] ?? 0), (int) ($instance['memory'] ?? 0),
                        $instance['osName'] ?? '', $instance['stoppedMode'] ?? '', $existingRow['id']
                    ]);
                } else {
                    $insertStmt->execute([
                        $group['AccessKeyId'], $this->encryptValue($group['AccessKeySecret']),
                        $group['regionId'], $instance['instanceId'], $group['maxTraffic'],
                        !empty($group['scheduleEnabled']) ? 1 : 0, !empty($group['scheduleStartEnabled']) ? 1 : 0,
                        !empty($group['scheduleStopEnabled']) ? 1 : 0, $group['startTime'] ?? '', $group['stopTime'] ?? '',
                        !empty($group['scheduleBlockedByTraffic']) ? 1 : 0,
                        date('Y-m'), $instance['status'] ?? 'Unknown',
                        self::resolveRemark($group, $instance), $group['siteType'], $group['groupKey'],
                        $instance['instanceName'] ?? '', $instance['instanceType'] ?? '',
                        (int) ($instance['internetMaxBandwidthOut'] ?? 0), $instance['publicIp'] ?? '',
                        $net['public_ip_mode'], $net['eip_allocation_id'],
                        $net['eip_address'], $net['eip_managed'],
                        $instance['privateIp'] ?? '', (int) ($instance['cpu'] ?? 0), (int) ($instance['memory'] ?? 0),
                        $instance['osName'] ?? '', $instance['stoppedMode'] ?? ''
                    ]);
                }
            }

            if (!empty($existingByGroup[$group['groupKey']])) {
                foreach ($existingByGroup[$group['groupKey']] as $row) {
                    if (!in_array($row['instance_id'], $remoteInstanceIds, true)) {
                        $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$row['id']]);
                    }
                }
            }
        }

        if (!empty($existingRows)) {
            foreach ($existingRows as $row) {
                $gk = $row['group_key'] ?: self::buildGroupKey($row['access_key_id'], $row['region_id']);
                if (!in_array($gk, $configuredGroupKeys, true)) {
                    $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$row['id']]);
                }
            }
        }
    }

    public static function normalizeAccountGroups(array $groups, bool $allowEmpty = false): array
    {
        $normalized = [];
        foreach ($groups as $group) {
            $accessKeyId = trim((string) ($group['AccessKeyId'] ?? ''));
            $accessKeySecret = trim((string) ($group['AccessKeySecret'] ?? ''));
            $regionId = trim((string) ($group['regionId'] ?? ''));
            if ($accessKeySecret === '********') $accessKeySecret = '';

            if (!$allowEmpty && $accessKeyId === '' && $accessKeySecret === '' && $regionId === '') continue;
            if ($accessKeyId === '' || $accessKeySecret === '' || $regionId === '') {
                if ($allowEmpty) continue;
                throw new \Exception('账号配置缺少必填项');
            }

            $groupKey = trim((string) ($group['groupKey'] ?? ''));
            if ($groupKey === '') $groupKey = self::buildGroupKey($accessKeyId, $regionId);

            $se = !empty($group['scheduleEnabled']) || !empty($group['schedule_enabled']);
            $st = trim((string) ($group['startTime'] ?? $group['start_time'] ?? ''));
            $sp = trim((string) ($group['stopTime'] ?? $group['stop_time'] ?? ''));
            $sse = array_key_exists('scheduleStartEnabled', $group) || array_key_exists('schedule_start_enabled', $group)
                ? (!empty($group['scheduleStartEnabled']) || !empty($group['schedule_start_enabled']))
                : ($se && $st !== '');
            $sso = array_key_exists('scheduleStopEnabled', $group) || array_key_exists('schedule_stop_enabled', $group)
                ? (!empty($group['scheduleStopEnabled']) || !empty($group['schedule_stop_enabled']))
                : ($se && $sp !== '');

            $normalized[] = [
                'groupKey' => $groupKey, 'AccessKeyId' => $accessKeyId, 'AccessKeySecret' => $accessKeySecret,
                'regionId' => $regionId, 'siteType' => $group['siteType'] ?? self::inferSiteType($regionId),
                'maxTraffic' => (float) ($group['maxTraffic'] ?? 200),
                'remark' => trim((string) ($group['remark'] ?? '')),
                'scheduleEnabled' => $se || $sse || $sso,
                'scheduleStartEnabled' => $sse, 'scheduleStopEnabled' => $sso,
                'startTime' => $st, 'stopTime' => $sp,
                'scheduleBlockedByTraffic' => !empty($group['scheduleBlockedByTraffic']) || !empty($group['schedule_blocked_by_traffic'])
            ];
        }
        return array_values($normalized);
    }

    public static function buildGroupKey(string $accessKeyId, string $regionId): string
    {
        return substr(sha1($accessKeyId . '|' . $regionId), 0, 16);
    }

    public static function inferSiteType(string $regionId): string
    {
        return (str_starts_with($regionId, 'cn-') && $regionId !== 'cn-hongkong') ? 'china' : 'international';
    }

    public static function resolveRemark(array $group, array $instance, ?array $existingRow = null): string
    {
        if (!empty($group['remark'])) return $group['remark'];
        if ($existingRow) {
            $er = trim((string) ($existingRow['remark'] ?? ''));
            $en = trim((string) ($existingRow['instance_name'] ?? ''));
            if ($er !== '' && $er !== $en) return $er;
        }
        if (!empty($instance['instanceName'])) return $instance['instanceName'];
        return $instance['instanceId'] ?? '';
    }

    public static function resolveNetworkMetadata(array $instance, ?array $existingRow = null): array
    {
        $eipAllocationId = trim((string) ($instance['eipAllocationId'] ?? ''));
        $eipAddress = trim((string) ($instance['eipAddress'] ?? ''));
        $existingMode = trim((string) ($existingRow['public_ip_mode'] ?? ''));
        $existingManaged = (int) ($existingRow['eip_managed'] ?? 0);

        $mode = $eipAllocationId !== '' ? 'eip' : 'ecs_public_ip';
        if ($existingMode === 'eip' && $eipAllocationId !== '') {
            $mode = 'eip';
        }

        return [
            'public_ip_mode' => $mode,
            'eip_allocation_id' => $eipAllocationId !== '' ? $eipAllocationId : trim((string) ($existingRow['eip_allocation_id'] ?? '')),
            'eip_address' => $eipAddress !== '' ? $eipAddress : ($mode === 'eip' ? trim((string) ($instance['publicIp'] ?? '')) : ''),
            'eip_managed' => $existingManaged
        ];
    }

    private function updateGroupBaseSettings(string $groupKey, array $group): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE accounts SET access_key_id=?,access_key_secret=?,region_id=?,max_traffic=?,schedule_enabled=?,schedule_start_enabled=?,schedule_stop_enabled=?,start_time=?,stop_time=?,schedule_blocked_by_traffic=?,site_type=?,group_key=? WHERE group_key=?")
            ->execute([
                $group['AccessKeyId'], $this->encryptValue($group['AccessKeySecret']),
                $group['regionId'], $group['maxTraffic'],
                !empty($group['scheduleEnabled']) ? 1 : 0, !empty($group['scheduleStartEnabled']) ? 1 : 0,
                !empty($group['scheduleStopEnabled']) ? 1 : 0, $group['startTime'] ?? '', $group['stopTime'] ?? '',
                !empty($group['scheduleBlockedByTraffic']) ? 1 : 0,
                $group['siteType'], $groupKey, $groupKey
            ]);
    }

    private function encryptValue(string $value): string
    {
        if (!function_exists('sodium_crypto_secretbox') || empty($value)) return $value;
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'ENC1' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->encryptionKey));
    }
}
