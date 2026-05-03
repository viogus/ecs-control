<?php

class ConfigManager
{
    private $database;
    private $db;
    private $configCache = [];
    private $accountsCache = [];
    private $encryptionKey = null;
    private AccountSyncService $accountSync;

    public function __construct(Database $db)
    {
        $this->database = $db;
        $this->db = $db->getPdo();
        $this->encryptionKey = $this->getEncryptionKey();
        $this->load();
        $this->accountSync = new AccountSyncService($this->database, $this->configCache, $this->encryptionKey);
    }

    private function getEncryptionKey()
    {
        return EncryptionManager::loadKey();
    }

    private function encryptValue($value)
    {
        return EncryptionManager::encrypt($value, $this->encryptionKey);
    }

    private function decryptValue($value)
    {
        return EncryptionManager::decrypt($value, $this->encryptionKey);
    }

    private function isEncryptedValue($value)
    {
        return EncryptionManager::isEncrypted($value);
    }

    public function load()
    {
        $this->configCache = [];
        $stmt = $this->db->query("SELECT key, value FROM settings");
        while ($row = $stmt->fetch()) {
            $this->configCache[$row['key']] = $row['value'];
        }

        $this->resetMonthlyTrafficCacheIfNeeded();

        $stmt = $this->db->query("SELECT * FROM accounts WHERE is_deleted = 0 ORDER BY region_id ASC, remark ASC, id ASC");
        $rows = $stmt->fetchAll();
        $this->accountsCache = [];
        foreach ($rows as $row) {
            $secret = $row['access_key_secret'] ?? '';
            if (!empty($secret) && $this->isEncryptedValue($secret)) {
                $secret = $this->decryptValue($secret);
            }
            $this->accountsCache[] = Account::fromDbRow($row, $secret);
        }
    }

    private function resetMonthlyTrafficCacheIfNeeded()
    {
        $currentMonth = date('Y-m');

        // 首次升级时，已有缓存默认视为当前月，避免上线当天误清空。
        $stmt = $this->db->prepare("UPDATE accounts SET traffic_billing_month = ? WHERE traffic_billing_month IS NULL OR traffic_billing_month = ''");
        $stmt->execute([$currentMonth]);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM accounts WHERE traffic_billing_month <> ?");
        $stmt->execute([$currentMonth]);
        $needsMonthlyReset = (int) $stmt->fetchColumn() > 0;

        // 阿里云 CDT/公网流量按自然月结算。月切换后，展示值和熔断判断都必须从当月重新开始。
        $stmt = $this->db->prepare("
            UPDATE accounts
            SET traffic_used = 0,
                traffic_billing_month = ?,
                updated_at = 0,
                schedule_blocked_by_traffic = 0
            WHERE traffic_billing_month <> ?
        ");
        $stmt->execute([$currentMonth, $currentMonth]);

        if ($needsMonthlyReset) {
            $this->clearStoredAccountGroupScheduleBlocks();
            $this->database->addLog('info', '检测到新的自然月，已重置账号流量并恢复定时开关机');
        }
    }

    public function get($key, $default = null)
    {
        return $this->configCache[$key] ?? $default;
    }

    public function getAllSettings()
    {
        return $this->configCache;
    }

    public function getAccounts()
    {
        return $this->accountsCache;
    }

    public function getAccountById($id)
    {
        foreach ($this->accountsCache as $acc) {
            if ($acc->id === (int) $id) {
                return $acc;
            }
        }
        return null;
    }

    public function getAccountByInstanceId($instanceId)
    {
        foreach ($this->accountsCache as $acc) {
            if ($acc->instanceId === $instanceId) {
                return $acc;
            }
        }
        return null;
    }

    public function decryptAccountSecret($secretFromDb)
    {
        if (empty($secretFromDb)) {
            return '';
        }
        return $this->decryptValue($secretFromDb);
    }

    public function getAccountGroups()
    {
        $groups = $this->accountSync->getAccountGroups();
        if (!empty($groups)) {
            $blockedByGroup = [];
            foreach ($this->accountsCache as $row) {
                $groupKey = $row['group_key'] ?: AccountSyncService::buildGroupKey($row['access_key_id'], $row['region_id']);
                $blockedByGroup[$groupKey] = !empty($row['schedule_blocked_by_traffic']);
            }
            foreach ($groups as &$group) {
                $group['scheduleBlockedByTraffic'] = !empty($blockedByGroup[$group['groupKey'] ?? '']);
            }
            unset($group);
            return $groups;
        }
        return $this->deriveAccountGroupsFromAccounts();
    }


    public function isInitialized()
    {
        return !empty($this->configCache['admin_password']);
    }

    private function saveSetting($key, $value)
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        $this->configCache[$key] = $value;
    }

    public function upgradePasswordHash($plainPassword)
    {
        $hashed = password_hash($plainPassword, PASSWORD_BCRYPT);
        $this->saveSetting('admin_password', $hashed);
    }

    public function getMonitorKey()
    {
        return $this->get('monitor_key', '');
    }

    public function saveMonitorKey($key)
    {
        $this->saveSetting('monitor_key', $key);
    }

    public function updateLastRunTime($time)
    {
        $this->saveSetting('last_monitor_run', $time);
    }

    public function getLastRunTime()
    {
        return (int) ($this->configCache['last_monitor_run'] ?? 0);
    }

    public function updateLastInstanceSyncTime($time)
    {
        $this->saveSetting('last_instance_sync', $time);
    }

    public function getLastInstanceSyncTime()
    {
        return (int) ($this->configCache['last_instance_sync'] ?? 0);
    }

    public function updateConfig($data)
    {
        try {
            $this->db->beginTransaction();

            $adminPassword = $data['admin_password'] ?? '';
            if (!empty($adminPassword) && $adminPassword !== '********') {
                if (!preg_match('/^\$2[aby]?\$/', $adminPassword) && !preg_match('/^\$argon2[aid]\$/', $adminPassword)) {
                    $adminPassword = password_hash($adminPassword, PASSWORD_BCRYPT);
                }
                $this->saveSetting('admin_password', $adminPassword);
            }
            $this->saveSetting('traffic_threshold', $data['traffic_threshold'] ?? 95);
            $this->saveSetting('shutdown_mode', $data['shutdown_mode'] ?? 'KeepCharging');
            $this->saveSetting('threshold_action', $data['threshold_action'] ?? 'stop_and_notify');
            $this->saveSetting('keep_alive', !empty($data['keep_alive']) ? '1' : '0');
            $this->saveSetting('monthly_auto_start', !empty($data['monthly_auto_start']) ? '1' : '0');
            $this->saveSetting('api_interval', $data['api_interval'] ?? 600);
            $this->saveSetting('enable_billing', !empty($data['enable_billing']) ? '1' : '0');
            $appBrand = is_array($data['AppBrand'] ?? null) ? $data['AppBrand'] : [];
            $this->saveSetting('app_logo_url', trim((string) ($appBrand['logo_url'] ?? '')));
            $this->saveDdnsSettings($data['Ddns'] ?? []);

            if (isset($data['Notification'])) {
                $this->saveNotificationSettings($data['Notification']);
            }

            $groups = AccountSyncService::normalizeAccountGroups($data['Accounts'] ?? []);
            $this->saveSetting('account_groups', json_encode($groups, JSON_UNESCAPED_UNICODE));
            $this->syncAccountGroups(true, $groups);

            $this->db->commit();
            $this->load();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->database->addLog('error', "配置保存失败: " . strip_tags($e->getMessage()));
            return false;
        }
    }

    public function syncAccountGroups($force = false, $groups = null)
    {
        $lastSync = $this->getLastInstanceSyncTime();
        if (!$force && (time() - $lastSync) < 60) {
            $this->load();
            return;
        }

        $groups = $groups === null ? $this->getAccountGroups() : AccountSyncService::normalizeAccountGroups($groups, true);
        $this->accountSync->syncAccountGroups($this->accountsCache, $groups, function($type, $msg) {
            $this->database->addLog($type, $msg);
        });
        $this->updateLastInstanceSyncTime(time());
        $this->load();
    }

    public function updateAppLogoUrl($url)
    {
        $this->saveSetting('app_logo_url', trim((string) $url));
        $this->load();
    }

    private function saveNotificationSettings($notification)
    {
        $this->saveSetting('notify_email_enabled', !empty($notification['email_enabled']) ? '1' : '0');
        $this->saveSetting('notify_email', $notification['email'] ?? '');
        $this->saveSetting('notify_host', $notification['host'] ?? '');
        $this->saveSetting('notify_port', $notification['port'] ?? 465);
        $this->saveSetting('notify_username', $notification['username'] ?? '');

        if (isset($notification['password']) && $notification['password'] !== '********') {
            $this->saveSetting('notify_password', $notification['password'] ?? '');
        }

        $this->saveSetting('notify_secure', $notification['secure'] ?? 'ssl');

        $telegram = $notification['telegram'] ?? [];
        $this->saveSetting('notify_tg_enabled', !empty($telegram['enabled']) ? '1' : '0');

        if (isset($telegram['token']) && $telegram['token'] !== '********') {
            $this->saveSetting('notify_tg_token', $telegram['token'] ?? '');
        }

        $this->saveSetting('notify_tg_chat_id', $telegram['chat_id'] ?? '');
        $this->saveSetting('notify_tg_proxy_type', $telegram['proxy_type'] ?? 'none');
        $this->saveSetting('notify_tg_proxy_url', $telegram['proxy_url'] ?? '');
        $this->saveSetting('notify_tg_proxy_ip', $telegram['proxy_ip'] ?? '');
        $this->saveSetting('notify_tg_proxy_port', $telegram['proxy_port'] ?? '');
        $this->saveSetting('notify_tg_proxy_user', $telegram['proxy_user'] ?? '');
        $this->saveSetting('notify_tg_allowed_user_ids', trim((string) ($telegram['allowed_user_ids'] ?? '')));
        $this->saveSetting('notify_tg_confirm_ttl', max(30, (int) ($telegram['confirm_ttl'] ?? 60)));

        if (isset($telegram['proxy_pass']) && $telegram['proxy_pass'] !== '********') {
            $this->saveSetting('notify_tg_proxy_pass', $telegram['proxy_pass'] ?? '');
        }

        $webhook = $notification['webhook'] ?? [];
        $this->saveSetting('notify_wh_enabled', !empty($webhook['enabled']) ? '1' : '0');
        $this->saveSetting('notify_wh_url', $webhook['url'] ?? '');
        $this->saveSetting('notify_wh_method', $webhook['method'] ?? 'GET');
        $this->saveSetting('notify_wh_request_type', $webhook['request_type'] ?? 'JSON');
        $this->saveSetting('notify_wh_headers', $webhook['headers'] ?? '');
        $this->saveSetting('notify_wh_body', $webhook['body'] ?? '');
    }

    private function saveDdnsSettings($ddns)
    {
        $cloudflare = is_array($ddns['cloudflare'] ?? null) ? $ddns['cloudflare'] : [];
        $this->saveSetting('ddns_enabled', !empty($ddns['enabled']) ? '1' : '0');
        $this->saveSetting('ddns_provider', $ddns['provider'] ?? 'cloudflare');
        $this->saveSetting('ddns_domain', trim((string) ($ddns['domain'] ?? '')));
        $this->saveSetting('ddns_cf_zone_id', trim((string) ($cloudflare['zone_id'] ?? '')));

        $token = $cloudflare['token'] ?? '';
        if ($token !== '********') {
            $this->saveSetting('ddns_cf_token', trim((string) $token));
        }

        $this->saveSetting('ddns_cf_proxied', !empty($cloudflare['proxied']) ? '1' : '0');
    }


    private function resolveExistingSecret($accessKeyId, $regionId, $groupKey = '')
    {
        $accessKeyId = trim((string) $accessKeyId);
        $regionId = trim((string) $regionId);
        $requestedGroupKey = trim((string) $groupKey);

        if ($requestedGroupKey !== '') {
            foreach ($this->accountsCache as $row) {
                if (($row['group_key'] ?? '') === $requestedGroupKey && !empty($row['access_key_secret'])) {
                    return $row['access_key_secret'];
                }
            }
        }

        foreach ($this->accountsCache as $row) {
            if ($row['access_key_id'] === $accessKeyId && $row['region_id'] === $regionId) {
                return $row['access_key_secret'];
            }
        }

        $derivedGroupKey = AccountSyncService::buildGroupKey($accessKeyId, $regionId);
        foreach ($this->accountsCache as $row) {
            if (($row['group_key'] === $derivedGroupKey) && !empty($row['access_key_secret'])) {
                return $row['access_key_secret'];
            }
        }

        $rawGroups = json_decode((string) ($this->configCache['account_groups'] ?? ''), true);
        if (is_array($rawGroups)) {
            foreach ($rawGroups as $group) {
                $savedGroupKey = trim((string) ($group['groupKey'] ?? ''));
                $savedAccessKeyId = trim((string) ($group['AccessKeyId'] ?? ''));
                $savedRegionId = trim((string) ($group['regionId'] ?? ''));
                $savedSecret = trim((string) ($group['AccessKeySecret'] ?? ''));

                if (
                    (
                        ($requestedGroupKey !== '' && $savedGroupKey === $requestedGroupKey)
                        || ($savedAccessKeyId === $accessKeyId && $savedRegionId === $regionId)
                    )
                    && $savedSecret !== ''
                    && $savedSecret !== '********'
                ) {
                    return $savedSecret;
                }
            }
        }

        return '';
    }

    private function deriveAccountGroupsFromAccounts()
    {
        $groups = [];

        foreach ($this->accountsCache as $row) {
            $accessKeyId = trim((string) ($row['access_key_id'] ?? ''));
            $regionId = trim((string) ($row['region_id'] ?? ''));
            if ($accessKeyId === '' || $regionId === '') {
                continue;
            }

            $groupKey = $row['group_key'] ?: AccountSyncService::buildGroupKey($accessKeyId, $regionId);
            if (isset($groups[$groupKey])) {
                continue;
            }

            $groups[$groupKey] = [
                'groupKey' => $groupKey,
                'AccessKeyId' => $accessKeyId,
                'AccessKeySecret' => $row['access_key_secret'] ?? '',
                'regionId' => $regionId,
                'siteType' => $row['site_type'] ?? AccountSyncService::inferSiteType($regionId),
                'maxTraffic' => (float) ($row['max_traffic'] ?? 200),
                'remark' => $row['remark'] ?? '',
                'scheduleEnabled' => !empty($row['schedule_enabled']),
                'scheduleStartEnabled' => !empty($row['schedule_start_enabled']),
                'scheduleStopEnabled' => !empty($row['schedule_stop_enabled']),
                'startTime' => $row['start_time'] ?? '',
                'stopTime' => $row['stop_time'] ?? '',
                'scheduleBlockedByTraffic' => !empty($row['schedule_blocked_by_traffic'])
            ];
        }

        return array_values($groups);
    }




    private function resolveNetworkMetadata($instance, $existingRow = null)
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

    public function updateAccountNetworkMetadata($id, array $metadata)
    {
        $stmt = $this->db->prepare("
            UPDATE accounts
            SET public_ip = ?,
                public_ip_mode = ?,
                eip_allocation_id = ?,
                eip_address = ?,
                eip_managed = ?,
                internet_max_bandwidth_out = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $metadata['public_ip'] ?? '',
            $metadata['public_ip_mode'] ?? 'ecs_public_ip',
            $metadata['eip_allocation_id'] ?? '',
            $metadata['eip_address'] ?? '',
            !empty($metadata['eip_managed']) ? 1 : 0,
            (int) ($metadata['internet_max_bandwidth_out'] ?? 0),
            $id
        ]);
        $this->load();
    }

    private function updateGroupBaseSettings($groupKey, $group)
    {
        $stmt = $this->db->prepare("
            UPDATE accounts
            SET access_key_id = ?,
                access_key_secret = ?,
                region_id = ?,
                max_traffic = ?,
                schedule_enabled = ?,
                schedule_start_enabled = ?,
                schedule_stop_enabled = ?,
                start_time = ?,
                stop_time = ?,
                schedule_blocked_by_traffic = ?,
                site_type = ?,
                group_key = ?
            WHERE group_key = ?
        ");

        $stmt->execute([
            $group['AccessKeyId'],
            $this->encryptValue($group['AccessKeySecret']),
            $group['regionId'],
            $group['maxTraffic'],
            !empty($group['scheduleEnabled']) ? 1 : 0,
            !empty($group['scheduleStartEnabled']) ? 1 : 0,
            !empty($group['scheduleStopEnabled']) ? 1 : 0,
            $group['startTime'] ?? '',
            $group['stopTime'] ?? '',
            !empty($group['scheduleBlockedByTraffic']) ? 1 : 0,
            $group['siteType'],
            $groupKey,
            $groupKey
        ]);
    }

    public function deleteAccountById($id)
    {
        $stmt = $this->db->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $this->load();
    }

    public function markAccountAsDeleted($id, $forceStop = false)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET is_deleted = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $this->load();
    }

    public function getPendingReleaseAccounts()
    {
        $stmt = $this->db->query("SELECT * FROM accounts WHERE is_deleted = 1");
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$row) {
            if (!empty($row['access_key_secret']) && $this->isEncryptedValue($row['access_key_secret'])) {
                $row['access_key_secret'] = $this->decryptValue($row['access_key_secret']);
            }
        }
        return $accounts;
    }

    public function physicallyDeleteAccount($id)
    {
        // 软删除机制：标记为 2 (彻底销毁完毕状态)。
        // 目的：阻断刚执行完释放后，阿里云 API 缓存尚未更新，导致同步器误认为这是"新冒出来的机器"从而执行重新插入。
        // 此尸体记录会在下一次或下下一次定时同步时，由于彻底在阿里云失联，被同步器的 deleteStmt 收尸清理。
        $stmt = $this->db->prepare("UPDATE accounts SET is_deleted = 2, instance_status = 'Released' WHERE id = ?");
        $stmt->execute([$id]);
        // 不强制更新 cache，后台无声息处理。
    }

    public function updateAccountStatus($id, $traffic, $status, $updatedAt, $metadata = [])
    {
        $sql = "UPDATE accounts SET traffic_used = ?, traffic_billing_month = ?, instance_status = ?, updated_at = ?";
        $params = [$traffic, date('Y-m'), $status, $updatedAt];

        if (isset($metadata['health_status'])) {
            $sql .= ", health_status = ?";
            $params[] = $metadata['health_status'];
        }
        if (isset($metadata['stopped_mode'])) {
            $sql .= ", stopped_mode = ?";
            $params[] = $metadata['stopped_mode'];
        }
        if (isset($metadata['traffic_api_status'])) {
            $sql .= ", traffic_api_status = ?";
            $params[] = $metadata['traffic_api_status'];
        }
        if (isset($metadata['traffic_api_message'])) {
            $sql .= ", traffic_api_message = ?";
            $params[] = $metadata['traffic_api_message'];
        }
        if (isset($metadata['protection_suspended'])) {
            $sql .= ", protection_suspended = ?";
            $params[] = $metadata['protection_suspended'] ? 1 : 0;
        }
        if (isset($metadata['protection_suspend_reason'])) {
            $sql .= ", protection_suspend_reason = ?";
            $params[] = (string) $metadata['protection_suspend_reason'];
        }
        if (isset($metadata['protection_suspend_notified_at'])) {
            $sql .= ", protection_suspend_notified_at = ?";
            $params[] = (int) $metadata['protection_suspend_notified_at'];
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateLastKeepAlive($id, $time)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET last_keep_alive_at = ? WHERE id = ?");
        return $stmt->execute([$time, $id]);
    }

    public function updateAutoStartBlocked($id, $blocked)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET auto_start_blocked = ? WHERE id = ?");
        return $stmt->execute([$blocked ? 1 : 0, $id]);
    }

    public function updateScheduleExecutionState($id, $type, $date)
    {
        $column = $type === 'start' ? 'schedule_last_start_date' : 'schedule_last_stop_date';
        $stmt = $this->db->prepare("UPDATE accounts SET {$column} = ? WHERE id = ?");
        return $stmt->execute([(string) $date, $id]);
    }

    public function updateScheduleBlockedByTraffic($id, $blocked)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET schedule_blocked_by_traffic = ? WHERE id = ?");
        $result = $stmt->execute([$blocked ? 1 : 0, $id]);
        if ($result) {
            $rowStmt = $this->db->prepare("SELECT access_key_id, region_id, group_key FROM accounts WHERE id = ? LIMIT 1");
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch();
            if ($row) {
                $groupKey = $row['group_key'] ?: AccountSyncService::buildGroupKey($row['access_key_id'] ?? '', $row['region_id'] ?? '');
                $this->updateStoredAccountGroupScheduleBlock($groupKey, $blocked);
            }
        }
        return $result;
    }

    public function updateScheduleBlockedByTrafficForGroup($groupKey, $blocked)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET schedule_blocked_by_traffic = ? WHERE group_key = ?");
        $result = $stmt->execute([$blocked ? 1 : 0, (string) $groupKey]);
        if ($result) {
            $this->updateStoredAccountGroupScheduleBlock((string) $groupKey, $blocked);
        }
        return $result;
    }

    public function restoreScheduleAfterTrafficBlock($groupKey)
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            return false;
        }

        $result = $this->updateScheduleBlockedByTrafficForGroup($groupKey, false);
        $this->load();
        return $result;
    }

    private function updateStoredAccountGroupScheduleBlock($groupKey, $blocked)
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            return;
        }

        $raw = $this->configCache['account_groups'] ?? '';
        $groups = json_decode((string) $raw, true);
        if (!is_array($groups)) {
            return;
        }

        $changed = false;
        foreach ($groups as &$group) {
            if (($group['groupKey'] ?? '') !== $groupKey) {
                continue;
            }
            $group['scheduleBlockedByTraffic'] = (bool) $blocked;
            $changed = true;
        }
        unset($group);

        if ($changed) {
            $this->saveSetting('account_groups', json_encode($groups, JSON_UNESCAPED_UNICODE));
        }
    }

    private function clearStoredAccountGroupScheduleBlocks()
    {
        $raw = $this->configCache['account_groups'] ?? '';
        $groups = json_decode((string) $raw, true);
        if (!is_array($groups)) {
            return;
        }

        $changed = false;
        foreach ($groups as &$group) {
            if (!empty($group['scheduleBlockedByTraffic']) || !empty($group['schedule_blocked_by_traffic'])) {
                $group['scheduleBlockedByTraffic'] = false;
                unset($group['schedule_blocked_by_traffic']);
                $changed = true;
            }
        }
        unset($group);

        if ($changed) {
            $this->saveSetting('account_groups', json_encode($groups, JSON_UNESCAPED_UNICODE));
        }
    }

    public function blockCurrentlyStoppedInstances()
    {
        $stmt = $this->db->prepare("UPDATE accounts SET auto_start_blocked = 1 WHERE instance_status = 'Stopped'");
        $stmt->execute();
        $this->load();
    }
}
