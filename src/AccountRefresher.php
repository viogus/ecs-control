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
            $newUpdateTime = $account->updatedAt;
        } else {
            $traffic = (float) ($trafficResult['value'] ?? 0);
            $this->db->addHourlyStat($account->id, $traffic);
            $this->db->addDailyStat($account->id, $traffic);
        }

        if ($status === InstanceStatus::Unknown->value) {
            $newUpdateTime = $account->updatedAt;
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

    private function isCredentialInvalidTrafficStatus($status): bool
    {
        return trim((string) $status) === 'auth_error';
    }
}
