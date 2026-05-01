<?php

class Account
{
    public readonly int $id;
    public readonly string $accessKeyId;
    public readonly string $accessKeySecret;
    public readonly string $regionId;
    public readonly string $instanceId;
    public readonly string $groupKey;
    public readonly float $maxTraffic;
    public readonly float $trafficUsed;
    public readonly string $trafficBillingMonth;
    public readonly string $instanceStatus;
    public readonly string $healthStatus;
    public readonly string $stoppedMode;
    public readonly int $updatedAt;
    public readonly int $lastKeepAliveAt;
    public readonly int $isDeleted;
    public readonly bool $scheduleEnabled;
    public readonly bool $scheduleStartEnabled;
    public readonly bool $scheduleStopEnabled;
    public readonly string $startTime;
    public readonly string $stopTime;
    public readonly string $scheduleLastStartDate;
    public readonly string $scheduleLastStopDate;
    public readonly bool $scheduleBlockedByTraffic;
    public readonly bool $autoStartBlocked;
    public readonly string $remark;
    public readonly string $siteType;
    public readonly string $instanceName;
    public readonly string $instanceType;
    public readonly int $internetMaxBandwidthOut;
    public readonly string $publicIp;
    public readonly string $publicIpMode;
    public readonly string $eipAllocationId;
    public readonly string $eipAddress;
    public readonly bool $eipManaged;
    public readonly string $privateIp;
    public readonly int $cpu;
    public readonly int $memory;
    public readonly string $osName;
    public readonly string $trafficApiStatus;
    public readonly string $trafficApiMessage;
    public readonly bool $protectionSuspended;
    public readonly string $protectionSuspendReason;
    public readonly int $protectionSuspendNotifiedAt;

    private function __construct() {}

    public static function fromDbRow(array $row, ?string $decryptedSecret = null): self
    {
        $self = new self();
        $self->id = (int) ($row['id'] ?? 0);
        $self->accessKeyId = (string) ($row['access_key_id'] ?? '');
        $self->accessKeySecret = $decryptedSecret ?? (string) ($row['access_key_secret'] ?? '');
        $self->regionId = (string) ($row['region_id'] ?? '');
        $self->instanceId = (string) ($row['instance_id'] ?? '');
        $self->groupKey = (string) ($row['group_key'] ?? '');
        $self->maxTraffic = (float) ($row['max_traffic'] ?? 0);
        $self->trafficUsed = (float) ($row['traffic_used'] ?? 0);
        $self->trafficBillingMonth = (string) ($row['traffic_billing_month'] ?? '');
        $self->instanceStatus = (string) ($row['instance_status'] ?? 'Unknown');
        $self->healthStatus = (string) ($row['health_status'] ?? 'Unknown');
        $self->stoppedMode = (string) ($row['stopped_mode'] ?? '');
        $self->updatedAt = (int) ($row['updated_at'] ?? 0);
        $self->lastKeepAliveAt = (int) ($row['last_keep_alive_at'] ?? 0);
        $self->isDeleted = (int) ($row['is_deleted'] ?? 0);
        $self->scheduleEnabled = !empty($row['schedule_enabled']);
        $self->scheduleStartEnabled = !empty($row['schedule_start_enabled']);
        $self->scheduleStopEnabled = !empty($row['schedule_stop_enabled']);
        $self->startTime = (string) ($row['start_time'] ?? '');
        $self->stopTime = (string) ($row['stop_time'] ?? '');
        $self->scheduleLastStartDate = (string) ($row['schedule_last_start_date'] ?? '');
        $self->scheduleLastStopDate = (string) ($row['schedule_last_stop_date'] ?? '');
        $self->scheduleBlockedByTraffic = !empty($row['schedule_blocked_by_traffic']);
        $self->autoStartBlocked = !empty($row['auto_start_blocked']);
        $self->remark = (string) ($row['remark'] ?? '');
        $self->siteType = (string) ($row['site_type'] ?? 'international');
        $self->instanceName = (string) ($row['instance_name'] ?? '');
        $self->instanceType = (string) ($row['instance_type'] ?? '');
        $self->internetMaxBandwidthOut = (int) ($row['internet_max_bandwidth_out'] ?? 0);
        $self->publicIp = (string) ($row['public_ip'] ?? '');
        $self->publicIpMode = (string) ($row['public_ip_mode'] ?? 'ecs_public_ip');
        $self->eipAllocationId = (string) ($row['eip_allocation_id'] ?? '');
        $self->eipAddress = (string) ($row['eip_address'] ?? '');
        $self->eipManaged = !empty($row['eip_managed']);
        $self->privateIp = (string) ($row['private_ip'] ?? '');
        $self->cpu = (int) ($row['cpu'] ?? 0);
        $self->memory = (int) ($row['memory'] ?? 0);
        $self->osName = (string) ($row['os_name'] ?? '');
        $self->trafficApiStatus = (string) ($row['traffic_api_status'] ?? 'ok');
        $self->trafficApiMessage = (string) ($row['traffic_api_message'] ?? '');
        $self->protectionSuspended = !empty($row['protection_suspended']);
        $self->protectionSuspendReason = (string) ($row['protection_suspend_reason'] ?? '');
        $self->protectionSuspendNotifiedAt = (int) ($row['protection_suspend_notified_at'] ?? 0);
        return $self;
    }

    public function logLabel(): string
    {
        $remark = trim($this->remark);
        if ($remark !== '') {
            return $remark;
        }
        $instanceName = trim($this->instanceName);
        if ($instanceName !== '') {
            return $instanceName;
        }
        $instanceId = trim($this->instanceId);
        if ($instanceId !== '') {
            return $instanceId;
        }
        return $this->maskedKey();
    }

    public function maskedKey(): string
    {
        return substr($this->accessKeyId, 0, 7) . '***';
    }

    public function effectiveGroupKey(): string
    {
        if ($this->groupKey !== '') {
            return $this->groupKey;
        }
        return substr(sha1($this->accessKeyId . '|' . $this->regionId), 0, 16);
    }

    /** @return array for backward compatibility with code still using arrays */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'access_key_id' => $this->accessKeyId,
            'access_key_secret' => $this->accessKeySecret,
            'region_id' => $this->regionId,
            'instance_id' => $this->instanceId,
            'group_key' => $this->groupKey,
            'max_traffic' => $this->maxTraffic,
            'traffic_used' => $this->trafficUsed,
            'traffic_billing_month' => $this->trafficBillingMonth,
            'instance_status' => $this->instanceStatus,
            'health_status' => $this->healthStatus,
            'stopped_mode' => $this->stoppedMode,
            'updated_at' => $this->updatedAt,
            'last_keep_alive_at' => $this->lastKeepAliveAt,
            'is_deleted' => $this->isDeleted,
            'schedule_enabled' => $this->scheduleEnabled ? 1 : 0,
            'schedule_start_enabled' => $this->scheduleStartEnabled ? 1 : 0,
            'schedule_stop_enabled' => $this->scheduleStopEnabled ? 1 : 0,
            'start_time' => $this->startTime,
            'stop_time' => $this->stopTime,
            'schedule_last_start_date' => $this->scheduleLastStartDate,
            'schedule_last_stop_date' => $this->scheduleLastStopDate,
            'schedule_blocked_by_traffic' => $this->scheduleBlockedByTraffic ? 1 : 0,
            'auto_start_blocked' => $this->autoStartBlocked ? 1 : 0,
            'remark' => $this->remark,
            'site_type' => $this->siteType,
            'instance_name' => $this->instanceName,
            'instance_type' => $this->instanceType,
            'internet_max_bandwidth_out' => $this->internetMaxBandwidthOut,
            'public_ip' => $this->publicIp,
            'public_ip_mode' => $this->publicIpMode,
            'eip_allocation_id' => $this->eipAllocationId,
            'eip_address' => $this->eipAddress,
            'eip_managed' => $this->eipManaged ? 1 : 0,
            'private_ip' => $this->privateIp,
            'cpu' => $this->cpu,
            'memory' => $this->memory,
            'os_name' => $this->osName,
            'traffic_api_status' => $this->trafficApiStatus,
            'traffic_api_message' => $this->trafficApiMessage,
            'protection_suspended' => $this->protectionSuspended ? 1 : 0,
            'protection_suspend_reason' => $this->protectionSuspendReason,
            'protection_suspend_notified_at' => $this->protectionSuspendNotifiedAt,
        ];
    }
}
