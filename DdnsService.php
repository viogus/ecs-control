<?php

class DdnsService
{
    private $config;
    private $db;
    private $configManager;

    public function __construct(array $config, $db = null, $configManager = null)
    {
        $this->config = $config;
        $this->db = $db;
        $this->configManager = $configManager;
    }

    public function isEnabled()
    {
        return ($this->config['ddns_enabled'] ?? '0') === '1'
            && ($this->config['ddns_provider'] ?? 'cloudflare') === 'cloudflare'
            && !empty($this->config['ddns_cf_token'])
            && !empty($this->config['ddns_domain']);
    }

    public function buildRecordName($account, $sameGroupInstanceCount = 1)
    {
        $domain = $this->normalizeDomain($this->config['ddns_domain'] ?? '');
        if ($domain === '') {
            throw new Exception('请先填写 DDNS 根域名');
        }

        $accountSlug = $this->slug($account['account_remark'] ?? $account['remark'] ?? '');
        $instanceSlug = $this->slug($account['instance_name'] ?? '');
        $instanceId = trim((string) ($account['instance_id'] ?? ''));
        $shortId = $this->slug($instanceId !== '' ? preg_replace('/^i-/', '', $instanceId) : '');

        if ($accountSlug === '') {
            $accountSlug = $instanceSlug ?: $shortId;
        }
        if ($accountSlug === '') {
            throw new Exception('DDNS 记录名生成失败，请检查账号备注或实例名称');
        }

        $subdomain = $accountSlug;
        if ((int) $sameGroupInstanceCount > 1) {
            $suffix = $instanceSlug ?: $shortId;
            if ($suffix !== '' && $suffix !== $accountSlug) {
                $subdomain .= '-' . $suffix;
            }
        }

        $subdomain = trim($subdomain, '-');
        return $subdomain . '.' . $domain;
    }

    public function syncARecord($recordName, $ip)
    {
        if (!$this->isEnabled()) {
            return ['success' => true, 'skipped' => true, 'message' => 'DDNS 未启用'];
        }

        $recordName = strtolower(trim((string) $recordName));
        $ip = trim((string) $ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['success' => false, 'message' => '公网 IP 为空或不是公网 IPv4'];
        }

        $existing = $this->findRecord($recordName);

        // IP 未变化时跳过，避免不必要的 Cloudflare API 调用。
        if ($existing && ($existing['content'] ?? '') === $ip) {
            return [
                'success' => true,
                'skipped' => true,
                'record' => $recordName,
                'ip' => $ip
            ];
        }

        $payload = [
            'type' => 'A',
            'name' => $recordName,
            'content' => $ip,
            'ttl' => 1,
            'proxied' => ($this->config['ddns_cf_proxied'] ?? '0') === '1',
            'comment' => 'Managed by CDT Monitor'
        ];

        if ($existing) {
            $response = $this->request('PUT', '/dns_records/' . $existing['id'], $payload);
        } else {
            $response = $this->request('POST', '/dns_records', $payload);
        }

        if (empty($response['success'])) {
            return ['success' => false, 'message' => $this->formatErrors($response)];
        }

        return [
            'success' => true,
            'record' => $recordName,
            'ip' => $ip,
            'action' => $existing ? 'updated' : 'created'
        ];
    }

    public function deleteARecord($recordName)
    {
        if (!$this->isEnabled()) {
            return ['success' => true, 'skipped' => true, 'message' => 'DDNS 未启用'];
        }

        $recordName = strtolower(trim((string) $recordName));
        if ($recordName === '') {
            return ['success' => false, 'message' => 'DDNS 记录名为空'];
        }

        $existing = $this->findRecord($recordName);
        if (!$existing) {
            return ['success' => true, 'skipped' => true, 'record' => $recordName, 'message' => '记录不存在'];
        }

        $response = $this->request('DELETE', '/dns_records/' . $existing['id']);
        if (empty($response['success'])) {
            return ['success' => false, 'message' => $this->formatErrors($response)];
        }

        return [
            'success' => true,
            'record' => $recordName,
            'action' => 'deleted'
        ];
    }

    private function findRecord($recordName)
    {
        $response = $this->request('GET', '/dns_records?type=A&name=' . rawurlencode($recordName));
        if (empty($response['success'])) {
            throw new Exception('Cloudflare 查询记录失败: ' . $this->formatErrors($response));
        }

        $records = $response['result'] ?? [];
        return $records[0] ?? null;
    }

    private function request($method, $path, array $payload = null)
    {
        $zoneId = $this->resolveZoneId();
        $token = trim((string) ($this->config['ddns_cf_token'] ?? ''));
        $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zoneId) . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception('Cloudflare 网络请求失败: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new Exception("Cloudflare 响应解析失败，状态码 {$httpCode}");
        }

        return $decoded;
    }

    private function resolveZoneId()
    {
        $zoneId = trim((string) ($this->config['ddns_cf_zone_id'] ?? ''));
        if ($zoneId !== '') {
            return $zoneId;
        }

        $domain = $this->normalizeDomain($this->config['ddns_domain'] ?? '');
        if ($domain === '') {
            throw new Exception('请先填写 DDNS 根域名');
        }

        $token = trim((string) ($this->config['ddns_cf_token'] ?? ''));
        $url = 'https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode($domain) . '&status=active';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('Cloudflare Zone 查询失败: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (empty($decoded['success'])) {
            throw new Exception('Cloudflare Zone 查询失败: ' . $this->formatErrors(is_array($decoded) ? $decoded : []));
        }

        $zone = $decoded['result'][0] ?? null;
        if (empty($zone['id'])) {
            throw new Exception("Cloudflare 未找到域名 {$domain}，请确认 Token 有该域名的 DNS 编辑权限，或手动填写 Zone ID");
        }

        return $zone['id'];
    }

    private function formatErrors(array $response)
    {
        $errors = $response['errors'] ?? [];
        if (empty($errors)) {
            return '未知错误';
        }

        $messages = [];
        foreach ($errors as $error) {
            $messages[] = $error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE);
        }
        return implode('；', $messages);
    }

    private function slug($value)
    {
        $original = trim((string) $value);
        $value = strtolower($original);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        if ($value === '') {
            $value = substr(sha1($original), 0, 8);
        }
        return substr($value, 0, 48);
    }

    private function normalizeDomain($domain)
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim(explode('/', $domain)[0] ?? '', '.');
        return $domain;
    }

    // ---- DDNS orchestration (requires db + configManager) ----

    public function syncForAccounts(array $accounts, string $source = '同步'): void
    {
        if (!$this->isEnabled() || !$this->db) return;
        $groupCounts = $this->getGroupCounts($accounts);
        foreach ($accounts as $account) {
            $publicIp = $this->getEffectivePublicIp($account);
            if (empty($account['instance_id']) || $publicIp === '') continue;
            try {
                $recordName = $this->buildRecordNameForAccount($account, $groupCounts);
                $result = $this->syncARecord($recordName, $publicIp);
                if (!empty($result['success']) && empty($result['skipped'])) {
                    $this->db->addLog('info', "DDNS 已同步 [{$this->getAccountLogLabel($account)}] {$recordName} -> {$publicIp} ({$source})");
                } elseif (empty($result['success'])) {
                    $this->db->addLog('warning', "DDNS 同步失败 [{$this->getAccountLogLabel($account)}]: " . strip_tags($result['message'] ?? '未知错误'));
                }
            } catch (\Exception $e) {
                $this->db->addLog('warning', "DDNS 同步失败 [{$this->getAccountLogLabel($account)}]: " . strip_tags($e->getMessage()));
            }
        }
    }

    public function reconcileAfterSync(array $before, array $after, string $source = '同步'): void
    {
        if (!$this->isEnabled() || !$this->db) return;
        $beforeRecords = $this->getRecordNames($before);
        $afterRecords = $this->getRecordNames($after);
        foreach ($beforeRecords as $instanceId => $recordName) {
            if ($recordName === '' || in_array($recordName, $afterRecords, true)) continue;
            $this->deleteRecordAndLog($recordName, $source . '清理');
        }
        $this->syncForAccounts($after, $source);
    }

    public function deleteForAccount($account, array $accountsBefore, string $source = '释放'): void
    {
        if (!$this->isEnabled() || !$this->db) return;
        try {
            $recordName = $this->buildRecordNameForAccount($account, $this->getGroupCounts($accountsBefore));
            $this->deleteRecordAndLog($recordName, $source);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "DDNS 清理失败 [{$this->getAccountLogLabel($account)}]: " . strip_tags($e->getMessage()));
        }
    }

    public function getEffectivePublicIp($account): string
    {
        if (($account['public_ip_mode'] ?? '') === 'eip') {
            $eip = trim((string) ($account['eip_address'] ?? ''));
            if ($eip !== '' && filter_var($eip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $eip;
            }
        }
        return trim((string) ($account['public_ip'] ?? ''));
    }

    public function getGroupCounts(array $accounts): array
    {
        $counts = [];
        foreach ($accounts as $account) {
            if (empty($account['instance_id'])) continue;
            $gk = $this->getGroupKey($account);
            $counts[$gk] = ($counts[$gk] ?? 0) + 1;
        }
        return $counts;
    }

    public function getGroupKey($account): string
    {
        return $account['group_key'] ?: (($account['access_key_id'] ?? '') . '|' . ($account['region_id'] ?? ''));
    }

    public function buildRecordNameForAccount($account, array $groupCounts): string
    {
        $groupKey = $this->getGroupKey($account);
        return $this->buildRecordName([
            'account_remark' => $this->resolveGroupRemark($account),
            'remark' => $account['remark'] ?? '',
            'instance_name' => $account['instance_name'] ?? '',
            'instance_id' => $account['instance_id'] ?? ''
        ], $groupCounts[$groupKey] ?? 1);
    }

    public function resolveGroupRemark($account): string
    {
        if (!$this->configManager) return trim((string) ($account['remark'] ?? ''));
        $groupKey = trim((string) ($account['group_key'] ?? ''));
        if ($groupKey !== '') {
            foreach ($this->configManager->getAccountGroups() as $group) {
                if (($group['groupKey'] ?? '') === $groupKey) {
                    return trim((string) ($group['remark'] ?? ''));
                }
            }
        }
        return trim((string) ($account['remark'] ?? ''));
    }

    public function getAccountLogLabel($account): string
    {
        $remark = trim((string) ($account['remark'] ?? ''));
        if ($remark !== '') return $remark;
        $name = trim((string) ($account['instance_name'] ?? ''));
        if ($name !== '') return $name;
        $id = trim((string) ($account['instance_id'] ?? ''));
        if ($id !== '') return $id;
        return substr((string) ($account['access_key_id'] ?? ''), 0, 7) . '***';
    }

    private function getRecordNames(array $accounts): array
    {
        $groupCounts = $this->getGroupCounts($accounts);
        $records = [];
        foreach ($accounts as $account) {
            if (empty($account['instance_id'])) continue;
            try { $records[$account['instance_id']] = $this->buildRecordNameForAccount($account, $groupCounts); }
            catch (\Exception $e) {
                if ($this->db) $this->db->addLog('warning', "DDNS 记录名生成失败 [{$this->getAccountLogLabel($account)}]: " . strip_tags($e->getMessage()));
            }
        }
        return $records;
    }

    private function deleteRecordAndLog(string $recordName, string $source = '清理'): void
    {
        try {
            $result = $this->deleteARecord($recordName);
            if (!empty($result['success']) && empty($result['skipped'])) {
                $this->db->addLog('info', "DDNS 已删除 {$recordName} ({$source})");
            } elseif (empty($result['success'])) {
                $this->db->addLog('warning', "DDNS 删除失败 {$recordName}: " . strip_tags($result['message'] ?? '未知错误'));
            }
        } catch (\Exception $e) {
            $this->db->addLog('warning', "DDNS 删除失败 {$recordName}: " . strip_tags($e->getMessage()));
        }
    }
}
