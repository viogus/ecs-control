<?php

declare(strict_types=1);

class RefreshResult
{
    public float $traffic;
    public string $status;
    public array $metadata;
    public int $newUpdateTime;
    public bool $authInvalid;
    public bool $trafficSuccess;

    public function __construct(
        float $traffic,
        string $status,
        array $metadata,
        int $newUpdateTime,
        bool $authInvalid,
        bool $trafficSuccess
    ) {
        $this->traffic = $traffic;
        $this->status = $status;
        $this->metadata = $metadata;
        $this->newUpdateTime = $newUpdateTime;
        $this->authInvalid = $authInvalid;
        $this->trafficSuccess = $trafficSuccess;
    }
}

class AccountRefresher
{
    private Database $db;
    private AliyunService $aliyunService;
    private ConfigManager $configManager;

    public function __construct(Database $db, AliyunService $aliyunService, ConfigManager $configManager)
    {
        $this->db = $db;
        $this->aliyunService = $aliyunService;
        $this->configManager = $configManager;
    }

    /**
     * Fetch fresh traffic + status from Alibaba Cloud and persist.
     * Callers apply their own post-processing (auth recovery, health check, DTO building).
     */
    public function refresh(Account $account, int $currentTime): RefreshResult
    {
        // 1. Fetch CDT traffic
        $trafficResult = Helpers::safeGetCdtTraffic($this->aliyunService, $account, $this->db);

        // 2. Fetch instance status
        $status = $this->safeGetInstanceStatus($account);

        // 3. Retry Unknown with 500ms delay
        if ($status === InstanceStatus::Unknown->value) {
            usleep(500000);
            $status = $this->safeGetInstanceStatus($account);
        }

        // 4. Build base metadata
        $metadata = [
            'traffic_api_status' => $trafficResult['status'] ?? 'ok',
            'traffic_api_message' => $trafficResult['message'] ?? '',
        ];

        // 5. Detect credential invalid
        $authInvalid = $this->isCredentialInvalidTrafficStatus($trafficResult['status'] ?? '');

        if ($authInvalid) {
            $metadata['protection_suspended'] = 1;
            $metadata['protection_suspend_reason'] = 'credential_invalid';
        }

        // 6. Handle traffic success/failure
        $trafficSuccess = !empty($trafficResult['success']);
        $newUpdateTime = $currentTime;

        if (!$trafficSuccess) {
            $traffic = $account->trafficUsed;
            // 失败也推进更新时间(保底 5 分钟冷却):
            // 否则 shouldCheckApi 恒为 true,每分钟全量重试 CDT+状态接口,易触发阿里云限流
            $newUpdateTime = max((int) $account->updatedAt, $currentTime - 300);
            // 记录首次失败时间(供熔断层识别"持续失败",避免基于陈旧数据误停)
            $failKey = 'cdt_failure_at_' . $this->cdtFailureKeySuffix($account);
            $stmt = $this->db->getPdo()->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([$failKey]);
            if ((int) $stmt->fetchColumn() <= 0) {
                $this->db->getPdo()
                    ->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
                    ->execute([$failKey, (string) $currentTime]);
            }
        } else {
            $traffic = (float) ($trafficResult['value'] ?? 0);
            $this->db->addHourlyStat($account->id, $traffic);
            $this->db->addDailyStat($account->id, $traffic);
            // 恢复成功:清除失败标记
            $this->db->getPdo()
                ->prepare("DELETE FROM settings WHERE key = ?")
                ->execute(['cdt_failure_at_' . $this->cdtFailureKeySuffix($account)]);
        }

        if ($status === InstanceStatus::Unknown->value) {
            // 状态查询失败同样推进(保底 5 分钟),避免每分钟重试
            $newUpdateTime = max((int) $account->updatedAt, $currentTime - 300);
        }

        if ($newUpdateTime <= 0) {
            $newUpdateTime = $currentTime;
        }

        // 7. Persist
        $this->configManager->updateAccountStatus($account->id, $traffic, $status, $newUpdateTime, $metadata);

        return new RefreshResult($traffic, $status, $metadata, $newUpdateTime, $authInvalid, $trafficSuccess);
    }

    private function safeGetInstanceStatus(Account $account): string
    {
        try {
            return $this->aliyunService->getInstanceStatus($account);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例状态查询失败 [" . Helpers::getAccountLogLabel($account) . "]: " . strip_tags($e->getMessage()));
            return InstanceStatus::Unknown->value;
        }
    }

    /**
     * CDT 失败标记的 key 后缀:优先账号 id,缺省时用 AK+实例的哈希,避免公共 key 串扰。
     */
    private function cdtFailureKeySuffix(Account $account): string
    {
        $accountId = (int) $account->id;
        if ($accountId > 0) {
            return (string) $accountId;
        }
        return md5(($account->accessKeyId ?? '') . '|' . ($account->instanceId ?? ''));
    }

    private function isCredentialInvalidTrafficStatus($status): bool
    {
        return trim((string) $status) === 'auth_error';
    }
}
