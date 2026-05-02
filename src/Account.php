<?php

class Account implements \ArrayAccess
{
    private const FIELD_MAP = [
        'id' => 'id', 'access_key_id' => 'accessKeyId', 'access_key_secret' => 'accessKeySecret',
        'region_id' => 'regionId', 'instance_id' => 'instanceId', 'group_key' => 'groupKey',
        'max_traffic' => 'maxTraffic', 'traffic_used' => 'trafficUsed', 'traffic_billing_month' => 'trafficBillingMonth',
        'instance_status' => 'instanceStatus', 'health_status' => 'healthStatus', 'stopped_mode' => 'stoppedMode',
        'updated_at' => 'updatedAt', 'last_keep_alive_at' => 'lastKeepAliveAt', 'is_deleted' => 'isDeleted',
        'schedule_enabled' => 'scheduleEnabled', 'schedule_start_enabled' => 'scheduleStartEnabled',
        'schedule_stop_enabled' => 'scheduleStopEnabled', 'start_time' => 'startTime', 'stop_time' => 'stopTime',
        'schedule_last_start_date' => 'scheduleLastStartDate', 'schedule_last_stop_date' => 'scheduleLastStopDate',
        'schedule_blocked_by_traffic' => 'scheduleBlockedByTraffic', 'auto_start_blocked' => 'autoStartBlocked',
        'remark' => 'remark', 'site_type' => 'siteType', 'instance_name' => 'instanceName',
        'instance_type' => 'instanceType', 'internet_max_bandwidth_out' => 'internetMaxBandwidthOut',
        'public_ip' => 'publicIp', 'public_ip_mode' => 'publicIpMode', 'eip_allocation_id' => 'eipAllocationId',
        'eip_address' => 'eipAddress', 'eip_managed' => 'eipManaged', 'private_ip' => 'privateIp',
        'cpu' => 'cpu', 'memory' => 'memory', 'os_name' => 'osName',
        'traffic_api_status' => 'trafficApiStatus', 'traffic_api_message' => 'trafficApiMessage',
        'protection_suspended' => 'protectionSuspended', 'protection_suspend_reason' => 'protectionSuspendReason',
        'protection_suspend_notified_at' => 'protectionSuspendNotifiedAt',
    ];

    public int $id = 0;
    public string $accessKeyId = '';
    public string $accessKeySecret = '';
    public string $regionId = '';
    public string $instanceId = '';
    public string $groupKey = '';
    public float $maxTraffic = 0;
    public float $trafficUsed = 0;
    public string $trafficBillingMonth = '';
    public string $instanceStatus = 'Unknown';
    public string $healthStatus = 'Unknown';
    public string $stoppedMode = '';
    public int $updatedAt = 0;
    public int $lastKeepAliveAt = 0;
    public int $isDeleted = 0;
    public bool $scheduleEnabled = false;
    public bool $scheduleStartEnabled = false;
    public bool $scheduleStopEnabled = false;
    public string $startTime = '';
    public string $stopTime = '';
    public string $scheduleLastStartDate = '';
    public string $scheduleLastStopDate = '';
    public bool $scheduleBlockedByTraffic = false;
    public bool $autoStartBlocked = false;
    public string $remark = '';
    public string $siteType = 'international';
    public string $instanceName = '';
    public string $instanceType = '';
    public int $internetMaxBandwidthOut = 0;
    public string $publicIp = '';
    public string $publicIpMode = 'ecs_public_ip';
    public string $eipAllocationId = '';
    public string $eipAddress = '';
    public bool $eipManaged = false;
    public string $privateIp = '';
    public int $cpu = 0;
    public int $memory = 0;
    public string $osName = '';
    public string $trafficApiStatus = 'ok';
    public string $trafficApiMessage = '';
    public bool $protectionSuspended = false;
    public string $protectionSuspendReason = '';
    public int $protectionSuspendNotifiedAt = 0;

    public static function fromDbRow(array $row, ?string $decryptedSecret = null): self
    {
        $a = new self();
        $a->id = (int) ($row['id'] ?? 0);
        $a->accessKeyId = (string) ($row['access_key_id'] ?? '');
        $a->accessKeySecret = $decryptedSecret ?? (string) ($row['access_key_secret'] ?? '');
        $a->regionId = (string) ($row['region_id'] ?? '');
        $a->instanceId = (string) ($row['instance_id'] ?? '');
        $a->groupKey = (string) ($row['group_key'] ?? '');
        $a->maxTraffic = (float) ($row['max_traffic'] ?? 0);
        $a->trafficUsed = (float) ($row['traffic_used'] ?? 0);
        $a->trafficBillingMonth = (string) ($row['traffic_billing_month'] ?? '');
        $a->instanceStatus = (string) ($row['instance_status'] ?? 'Unknown');
        $a->healthStatus = (string) ($row['health_status'] ?? 'Unknown');
        $a->stoppedMode = (string) ($row['stopped_mode'] ?? '');
        $a->updatedAt = (int) ($row['updated_at'] ?? 0);
        $a->lastKeepAliveAt = (int) ($row['last_keep_alive_at'] ?? 0);
        $a->isDeleted = (int) ($row['is_deleted'] ?? 0);
        $a->scheduleEnabled = !empty($row['schedule_enabled']);
        $a->scheduleStartEnabled = !empty($row['schedule_start_enabled']);
        $a->scheduleStopEnabled = !empty($row['schedule_stop_enabled']);
        $a->startTime = (string) ($row['start_time'] ?? '');
        $a->stopTime = (string) ($row['stop_time'] ?? '');
        $a->scheduleLastStartDate = (string) ($row['schedule_last_start_date'] ?? '');
        $a->scheduleLastStopDate = (string) ($row['schedule_last_stop_date'] ?? '');
        $a->scheduleBlockedByTraffic = !empty($row['schedule_blocked_by_traffic']);
        $a->autoStartBlocked = !empty($row['auto_start_blocked']);
        $a->remark = (string) ($row['remark'] ?? '');
        $a->siteType = (string) ($row['site_type'] ?? 'international');
        $a->instanceName = (string) ($row['instance_name'] ?? '');
        $a->instanceType = (string) ($row['instance_type'] ?? '');
        $a->internetMaxBandwidthOut = (int) ($row['internet_max_bandwidth_out'] ?? 0);
        $a->publicIp = (string) ($row['public_ip'] ?? '');
        $a->publicIpMode = (string) ($row['public_ip_mode'] ?? 'ecs_public_ip');
        $a->eipAllocationId = (string) ($row['eip_allocation_id'] ?? '');
        $a->eipAddress = (string) ($row['eip_address'] ?? '');
        $a->eipManaged = !empty($row['eip_managed']);
        $a->privateIp = (string) ($row['private_ip'] ?? '');
        $a->cpu = (int) ($row['cpu'] ?? 0);
        $a->memory = (int) ($row['memory'] ?? 0);
        $a->osName = (string) ($row['os_name'] ?? '');
        $a->trafficApiStatus = (string) ($row['traffic_api_status'] ?? 'ok');
        $a->trafficApiMessage = (string) ($row['traffic_api_message'] ?? '');
        $a->protectionSuspended = !empty($row['protection_suspended']);
        $a->protectionSuspendReason = (string) ($row['protection_suspend_reason'] ?? '');
        $a->protectionSuspendNotifiedAt = (int) ($row['protection_suspend_notified_at'] ?? 0);
        return $a;
    }

    public function logLabel(): string
    {
        if (trim($this->remark) !== '') return trim($this->remark);
        if (trim($this->instanceName) !== '') return trim($this->instanceName);
        if (trim($this->instanceId) !== '') return trim($this->instanceId);
        return $this->maskedKey();
    }

    public function maskedKey(): string
    {
        return substr($this->accessKeyId, 0, 7) . '***';
    }

    public function effectiveGroupKey(): string
    {
        return $this->groupKey !== '' ? $this->groupKey : substr(sha1($this->accessKeyId . '|' . $this->regionId), 0, 16);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'access_key_id' => $this->accessKeyId, 'access_key_secret' => $this->accessKeySecret,
            'region_id' => $this->regionId, 'instance_id' => $this->instanceId, 'group_key' => $this->groupKey,
            'max_traffic' => $this->maxTraffic, 'traffic_used' => $this->trafficUsed,
            'traffic_billing_month' => $this->trafficBillingMonth, 'instance_status' => $this->instanceStatus,
            'health_status' => $this->healthStatus, 'stopped_mode' => $this->stoppedMode,
            'updated_at' => $this->updatedAt, 'last_keep_alive_at' => $this->lastKeepAliveAt,
            'is_deleted' => $this->isDeleted, 'schedule_enabled' => $this->scheduleEnabled ? 1 : 0,
            'schedule_start_enabled' => $this->scheduleStartEnabled ? 1 : 0,
            'schedule_stop_enabled' => $this->scheduleStopEnabled ? 1 : 0,
            'start_time' => $this->startTime, 'stop_time' => $this->stopTime,
            'schedule_last_start_date' => $this->scheduleLastStartDate,
            'schedule_last_stop_date' => $this->scheduleLastStopDate,
            'schedule_blocked_by_traffic' => $this->scheduleBlockedByTraffic ? 1 : 0,
            'auto_start_blocked' => $this->autoStartBlocked ? 1 : 0,
            'remark' => $this->remark, 'site_type' => $this->siteType,
            'instance_name' => $this->instanceName, 'instance_type' => $this->instanceType,
            'internet_max_bandwidth_out' => $this->internetMaxBandwidthOut,
            'public_ip' => $this->publicIp, 'public_ip_mode' => $this->publicIpMode,
            'eip_allocation_id' => $this->eipAllocationId, 'eip_address' => $this->eipAddress,
            'eip_managed' => $this->eipManaged ? 1 : 0, 'private_ip' => $this->privateIp,
            'cpu' => $this->cpu, 'memory' => $this->memory, 'os_name' => $this->osName,
            'traffic_api_status' => $this->trafficApiStatus, 'traffic_api_message' => $this->trafficApiMessage,
            'protection_suspended' => $this->protectionSuspended ? 1 : 0,
            'protection_suspend_reason' => $this->protectionSuspendReason,
            'protection_suspend_notified_at' => $this->protectionSuspendNotifiedAt,
        ];
    }

    // ArrayAccess for backward compatibility with array-based code
    private function propFor(string $dbCol): ?string { return self::FIELD_MAP[$dbCol] ?? null; }

    public function offsetExists(mixed $offset): bool { return isset(self::FIELD_MAP[$offset]); }

    public function offsetGet(mixed $offset): mixed
    {
        $prop = self::FIELD_MAP[$offset] ?? null;
        if ($prop === null) return null;
        $value = $this->$prop;
        // Booleans should return int for backward compat (DB stores 0/1)
        if (is_bool($value)) return $value ? 1 : 0;
        return $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $prop = self::FIELD_MAP[$offset] ?? null;
        if ($prop === null) return;
        // Cast to correct type
        $rp = new \ReflectionProperty(self::class, $prop);
        $type = $rp->getType();
        if ($type instanceof \ReflectionNamedType) {
            $value = match ($type->getName()) {
                'int' => (int) $value,
                'float' => (float) $value,
                'string' => (string) $value,
                'bool' => (bool) $value,
                default => $value,
            };
        }
        $this->$prop = $value;
    }

    public function offsetUnset(mixed $offset): void {}
}
