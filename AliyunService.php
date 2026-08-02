<?php

declare(strict_types=1);

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ServerException;

class AliyunService
{
    private $regionCache = [];
    private $managedTagKey = 'ecs-control-managed';
    private $managedTagValue = 'true';

    private $trafficCache = [];

    // Note: asDefaultClient() mutates global SDK state. Safe under PHP-FPM's
    // single-threaded process-per-request model. Would need named clients
    // (->name('client-' . md5($key))) if ever moved to an async/event-loop runtime.
    private function setDefaultClient($key, $secret, $regionId)
    {
        AlibabaCloud::accessKeyClient($key, $secret)
            ->regionId($regionId)
            ->asDefaultClient();
    }

    private function ecsHost($regionId)
    {
        return "ecs.{$regionId}.aliyuncs.com";
    }

    private function vpcHost($regionId)
    {
        return "vpc.{$regionId}.aliyuncs.com";
    }

    /**
     * 判断是否为海外区域
     * 国内区域：cn-* (排除 cn-hongkong)
     * 海外区域：其他所有区域 + cn-hongkong
     */
    private function isOverseas($regionId)
    {
        if (strpos($regionId, 'cn-') === 0 && $regionId !== 'cn-hongkong') {
            return false;
        }
        return true;
    }

    /**
     * 获取 CDT 流量
     * @param string $key AccessKey
     * @param string $secret Secret
     * @param string $targetRegion 目标实例的区域ID
     * @throws \Exception
     */
    public function getTraffic($key, $secret, $targetRegion)
    {
        $cacheKey = md5($key);
        if (isset($this->trafficCache[$cacheKey])) {
            $result = $this->trafficCache[$cacheKey];
        } else {
            $result = RetryHandler::execute(function () use ($key, $secret) {
                AlibabaCloud::accessKeyClient($key, $secret)
                    ->regionId('cn-hongkong')
                    ->asDefaultClient();

                return AlibabaCloud::rpc()
                    ->product('CDT')
                    ->scheme('https')
                    ->version('2021-08-13')
                    ->action('ListCdtInternetTraffic')
                    ->method('POST')
                    ->host('cdt.aliyuncs.com')
                    ->options([
                        'connect_timeout' => 10.0,
                        'timeout' => 20.0
                    ])
                    ->request();
            }, 'getTraffic');

            $this->trafficCache[$cacheKey] = $result;
        }

        if (isset($result['TrafficDetails'])) {
            $isTargetOverseas = $this->isOverseas($targetRegion);
            $totalTraffic = 0;

            foreach ($result['TrafficDetails'] as $detail) {
                $trafficRegion = $detail['BusinessRegionId'] ?? '';
                if ($this->isOverseas($trafficRegion) === $isTargetOverseas) {
                    $totalTraffic += $detail['Traffic'];
                }
            }

            return $totalTraffic / (1024 * 1024 * 1024);
        }

        throw new \Exception("API 响应缺少 TrafficDetails 字段");
    }

    /**
     * 获取 ECS 实例公网出口分钟带宽点，并换算为字节增量。
     * 阿里云 ECS 公网按出方向流量计费；这里优先使用 VPC 公网 IP 维度指标，经典网络回退到实例维度指标。
     *
     * @return array ['bytes' => float, 'lastSampleMs' => int, 'points' => int, 'metric' => string]
     * @throws \Exception
     */
    public function getInstanceOutboundTrafficDelta($account, $startMs, $endMs)
    {
        if (empty($account->instanceId)) {
            throw new \Exception('未配置 Instance ID');
        }

        if ($endMs <= $startMs) {
            return [
                'bytes' => 0.0,
                'lastSampleMs' => (int) $startMs,
                'points' => 0,
                'metric' => ''
            ];
        }

        $metricCandidates = [];
        $publicIp = trim((string) ($account->publicIp ?? ''));
        if ($publicIp !== '') {
            $metricCandidates[] = [
                'name' => 'VPC_PublicIP_InternetOutRate',
                'dimensions' => [[
                    'instanceId' => $account->instanceId,
                    'ip' => $publicIp
                ]]
            ];
        }

        $metricCandidates[] = [
            'name' => 'InternetOutRate',
            'dimensions' => [[
                'instanceId' => $account->instanceId
            ]]
        ];

        $lastException = null;
        foreach ($metricCandidates as $candidate) {
            try {
                $result = $this->queryMetricRateAsBytes(
                    $account->accessKeyId,
                    $account->accessKeySecret,
                    $candidate['name'],
                    $candidate['dimensions'],
                    $startMs,
                    $endMs
                );

                if ($result['points'] > 0 || $candidate['name'] === 'InternetOutRate') {
                    $result['metric'] = $candidate['name'];
                    return $result;
                }
                $lastException = null;
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return [
            'bytes' => 0.0,
            'lastSampleMs' => (int) $startMs,
            'points' => 0,
            'metric' => ''
        ];
    }

    private function queryMetricRateAsBytes($key, $secret, $metricName, array $dimensions, $startMs, $endMs)
    {
        $period = 60;
        $chunkMs = 24 * 3600 * 1000;
        $cursor = (int) $startMs;
        $totalBytes = 0.0;
        $lastSampleMs = (int) $startMs;
        $pointCount = 0;

        while ($cursor < $endMs) {
            $chunkEnd = min($cursor + $chunkMs, (int) $endMs);
            $nextToken = null;

            do {
                $query = [
                    'Namespace' => 'acs_ecs_dashboard',
                    'MetricName' => $metricName,
                    'Period' => (string) $period,
                    'StartTime' => (string) $cursor,
                    'EndTime' => (string) $chunkEnd,
                    'Dimensions' => json_encode($dimensions, JSON_UNESCAPED_SLASHES),
                    'Length' => '1440'
                ];

                if (!empty($nextToken)) {
                    $query['NextToken'] = $nextToken;
                }

                $result = RetryHandler::execute(function () use ($key, $secret, $query) {
                    AlibabaCloud::accessKeyClient($key, $secret)
                        ->regionId('cn-hangzhou')
                        ->asDefaultClient();

                    return AlibabaCloud::rpc()
                        ->product('Cms')
                        ->scheme('https')
                        ->version('2019-01-01')
                        ->action('DescribeMetricList')
                        ->method('POST')
                        ->host('metrics.aliyuncs.com')
                        ->options([
                            'query' => $query,
                            'connect_timeout' => 10.0,
                            'timeout' => 25.0
                        ])
                        ->request();
                }, 'queryMetricRateAsBytes');

                $datapoints = $result['Datapoints'] ?? '[]';
                if (is_string($datapoints)) {
                    $datapoints = json_decode($datapoints, true);
                }
                if (!is_array($datapoints)) {
                    $datapoints = [];
                }

                usort($datapoints, function ($a, $b) {
                    return ((int) ($a['timestamp'] ?? 0)) <=> ((int) ($b['timestamp'] ?? 0));
                });

                foreach ($datapoints as $point) {
                    $timestamp = (int) ($point['timestamp'] ?? 0);
                    if ($timestamp <= $startMs || $timestamp > $endMs) {
                        continue;
                    }

                    $rateBitsPerSecond = (float) ($point['Average'] ?? $point['Maximum'] ?? $point['Minimum'] ?? 0);
                    if ($rateBitsPerSecond < 0) {
                        $rateBitsPerSecond = 0;
                    }

                    $totalBytes += ($rateBitsPerSecond * $period) / 8;
                    $lastSampleMs = max($lastSampleMs, $timestamp);
                    $pointCount++;
                }

                $nextToken = $result['NextToken'] ?? null;
            } while (!empty($nextToken));

            $cursor = $chunkEnd;
        }

        return [
            'bytes' => $totalBytes,
            'lastSampleMs' => $lastSampleMs,
            'points' => $pointCount,
            'metric' => $metricName
        ];
    }

    /**
     * 获取实例状态
     * @throws \Exception
     */
    public function getInstanceStatus($account)
    {
        if (empty($account->instanceId)) {
            throw new \Exception("未配置 Instance ID");
        }

        return RetryHandler::execute(function () use ($account) {
            AlibabaCloud::accessKeyClient($account->accessKeyId, $account->accessKeySecret)
                ->regionId($account->regionId)
                ->asDefaultClient();

            $options = [
                'query' => ['RegionId' => $account->regionId],
                'connect_timeout' => 10.0,
                'timeout' => 20.0
            ];

            if (!empty($account->instanceId)) {
                $options['query']['InstanceId.1'] = $account->instanceId;
            }

            $result = AlibabaCloud::rpc()
                ->product('Ecs')
                ->scheme('https')
                ->version('2014-05-26')
                ->action('DescribeInstanceStatus')
                ->method('POST')
                ->host("ecs.{$account->regionId}.aliyuncs.com")
                ->options($options)
                ->request();

            $statuses = $result['InstanceStatuses']['InstanceStatus'] ?? [];
            foreach ($statuses as $item) {
                if (($item['InstanceId'] ?? '') === $account->instanceId) {
                    return $item['Status'];
                }
            }

            throw new \Exception("API 响应未找到匹配的实例状态 (ID: {$account->instanceId})");
        }, 'getInstanceStatus');
    }

    /**
     * 获取实例详细健康状态 (用于识别操作系统启动中等状态)
     */
    public function getInstanceFullStatus($account)
    {
        return RetryHandler::execute(function () use ($account) {
            AlibabaCloud::accessKeyClient($account->accessKeyId, $account->accessKeySecret)
                ->regionId($account->regionId)
                ->asDefaultClient();

            $options = [
                'query' => [
                    'RegionId' => $account->regionId,
                    'InstanceId.1' => $account->instanceId
                ],
                'connect_timeout' => 10.0,
                'timeout' => 20.0
            ];

            $result = AlibabaCloud::rpc()
                ->product('Ecs')
                ->scheme('https')
                ->version('2014-05-26')
                ->action('DescribeInstancesFullStatus')
                ->method('POST')
                ->host("ecs.{$account->regionId}.aliyuncs.com")
                ->options($options)
                ->request();

            $statusSet = $result['InstanceFullStatusSet']['InstanceFullStatus'][0] ?? null;
            // 兼容:单元素时 SDK 可能返回关联数组而非列表
            if (($statusSet['InstanceId'] ?? null) === null && isset($result['InstanceFullStatusSet']['InstanceFullStatus']['InstanceId'])) {
                $statusSet = $result['InstanceFullStatusSet']['InstanceFullStatus'];
            }
            if ($statusSet && ($statusSet['InstanceId'] ?? '') === $account->instanceId) {
                return [
                    'status' => $statusSet['Status']['Name'] ?? InstanceStatus::Unknown->value,
                    'healthStatus' => $statusSet['HealthStatus']['Name'] ?? InstanceStatus::Unknown->value,
                ];
            }

            return null;
        }, 'getInstanceFullStatus');
    }

    /**
     * 释放（删除）实例
     * @throws \Exception
     */
    public function deleteInstance($account, $forceStop = false)
    {
        if (empty($account->instanceId)) {
            throw new \Exception("未配置 Instance ID");
        }

        try {
            return RetryHandler::execute(function () use ($account, $forceStop) {
                AlibabaCloud::accessKeyClient($account->accessKeyId, $account->accessKeySecret)
                    ->regionId($account->regionId)
                    ->asDefaultClient();

                AlibabaCloud::rpc()
                    ->product('Ecs')
                    ->scheme('https')
                    ->version('2014-05-26')
                    ->action('DeleteInstance')
                    ->method('POST')
                    ->host("ecs.{$account->regionId}.aliyuncs.com")
                    ->options([
                        'query' => [
                            'RegionId' => $account->regionId,
                            'InstanceId' => $account->instanceId,
                            // 仅显式请求强制删除时才设 Force=true;队列流程先停后删,无需强制
                            'Force' => $forceStop ? true : false,
                        ],
                        'connect_timeout' => 10.0,
                        'timeout' => 25.0
                    ])
                    ->request();

                return true;
            }, 'deleteInstance');
        } catch (ServerException $e) {
            $code = $e->getErrorCode();
            if (stripos($code, 'NotFound') !== false || stripos($code, 'InvalidInstanceId') !== false) {
                return true;
            }
            throw $e;
        }
    }

    /**
     * 控制实例开关机
     * @throws \Exception
     */
    public function controlInstance($account, string $action, $shutdownMode = 'KeepCharging')
    {
        return RetryHandler::execute(function () use ($account, $action, $shutdownMode) {
            AlibabaCloud::accessKeyClient($account->accessKeyId, $account->accessKeySecret)
                ->regionId($account->regionId)
                ->asDefaultClient();

            if (empty($account->instanceId)) {
                throw new \Exception("未配置 Instance ID");
            }

            $options = [
                'query' => [
                    'RegionId' => $account->regionId,
                    'InstanceId' => $account->instanceId
                ],
                'connect_timeout' => 10.0,
                'timeout' => 20.0
            ];

            if ($action === 'stop') {
                $options['query']['StoppedMode'] = $shutdownMode;
            }

            AlibabaCloud::rpc()
                ->product('Ecs')
                ->scheme('https')
                ->version('2014-05-26')
                ->action($action === 'stop' ? 'StopInstance' : 'StartInstance')
                ->method('POST')
                ->host("ecs.{$account->regionId}.aliyuncs.com")
                ->options($options)
                ->request();

            return true;
        }, 'controlInstance');
    }

    /**
     * 获取当前账号可访问的地域列表
     * @param string $key
     * @param string $secret
     * @return array
     * @throws \Exception
     */
    public function getRegions($key, $secret)
    {
        $cacheKey = md5($key);
        if (isset($this->regionCache[$cacheKey])) {
            return $this->regionCache[$cacheKey];
        }

        $result = RetryHandler::execute(function () use ($key, $secret) {
            AlibabaCloud::accessKeyClient($key, $secret)
                ->regionId('cn-hangzhou')
                ->asDefaultClient();

            return AlibabaCloud::rpc()
                ->product('Ecs')
                ->scheme('https')
                ->version('2014-05-26')
                ->action('DescribeRegions')
                ->method('POST')
                ->host('ecs.cn-hangzhou.aliyuncs.com')
                ->options([
                    'connect_timeout' => 5.0,
                    'timeout' => 10.0
                ])
                ->request();
        }, 'getRegions');

        $regions = [];
        foreach (($result['Regions']['Region'] ?? []) as $region) {
            if (empty($region['RegionId'])) {
                continue;
            }

            $regions[] = [
                'regionId' => $region['RegionId'],
                'localName' => $region['LocalName'] ?? $region['RegionId']
            ];
        }

        $this->regionCache[$cacheKey] = $regions;
        return $regions;
    }

    /**
     * 列出当前账号下所有 ECS 实例
     * @param string $key
     * @param string $secret
     * @return array
     * @throws \Exception
     */
    public function getInstances($key, $secret, $targetRegionId = null)
    {
        if (!empty($targetRegionId)) {
            $localName = $targetRegionId;
            $cacheKey = md5($key);
            foreach (($this->regionCache[$cacheKey] ?? []) as $cachedRegion) {
                if (($cachedRegion['regionId'] ?? '') === $targetRegionId) {
                    $localName = $cachedRegion['localName'] ?? $targetRegionId;
                    break;
                }
            }

            $regions = [[
                'regionId' => $targetRegionId,
                'localName' => $localName
            ]];
        } else {
            $regions = $this->getRegions($key, $secret);
        }

        $instances = [];

        foreach ($regions as $region) {
            $pageNumber = 1;

            try {
                do {
                    $result = RetryHandler::execute(function () use ($key, $secret, $region, $pageNumber) {
                        return $this->requestDescribeInstancesPage($key, $secret, $region['regionId'], $pageNumber);
                    }, 'getInstances');

                    $items = $result['Instances']['Instance'] ?? [];
                    foreach ($items as $instance) {
                        $instances[] = [
                            'instanceId' => $instance['InstanceId'] ?? '',
                            'instanceName' => $instance['InstanceName'] ?? '',
                            'status' => $instance['Status'] ?? InstanceStatus::Unknown->value,
                            'regionId' => $region['regionId'],
                            'regionName' => $region['localName'],
                            'instanceType' => $instance['InstanceType'] ?? '',
                            'cpu' => $instance['Cpu'] ?? 0,
                            'memory' => $instance['Memory'] ?? 0,
                            'internetMaxBandwidthOut' => (int) (($instance['EipAddress']['Bandwidth'] ?? 0) ?: ($instance['InternetMaxBandwidthOut'] ?? 0)),
                            'osName' => $instance['OSName'] ?? '',
                            'publicIp' => $instance['PublicIpAddress']['IpAddress'][0] ?? $instance['EipAddress']['IpAddress'] ?? '',
                            'eipAllocationId' => $instance['EipAddress']['AllocationId'] ?? '',
                            'eipAddress' => $instance['EipAddress']['IpAddress'] ?? '',
                            'privateIp' => $instance['VpcAttributes']['PrivateIpAddress']['IpAddress'][0] ?? '',
                            'stoppedMode' => $instance['StoppedMode'] ?? '',
                            'chargeType' => $instance['InstanceChargeType'] ?? ''
                        ];
                    }

                    $totalCount = (int) ($result['TotalCount'] ?? count($items));
                    $pageSize = (int) ($result['PageSize'] ?? 100);
                    $pageNumber++;
                } while ($totalCount > 0 && (($pageNumber - 1) * $pageSize) < $totalCount);
            } catch (\Exception $e) {
                if (!empty($targetRegionId)) {
                    throw $e;
                }
                error_log("Aliyun getInstances region {$region['regionId']} failed: " . $e->getMessage());
                continue;
            }
        }

        usort($instances, function ($a, $b) {
            $regionCompare = strcmp($a['regionId'], $b['regionId']);
            if ($regionCompare !== 0) {
                return $regionCompare;
            }
            return strcmp($a['instanceId'], $b['instanceId']);
        });

        return $instances;
    }

    protected function requestDescribeInstancesPage($key, $secret, $regionId, $pageNumber)
    {
        AlibabaCloud::accessKeyClient($key, $secret)
            ->regionId($regionId)
            ->asDefaultClient();

        return AlibabaCloud::rpc()
            ->product('Ecs')
            ->scheme('https')
            ->version('2014-05-26')
            ->action('DescribeInstances')
            ->method('POST')
            ->host("ecs.{$regionId}.aliyuncs.com")
            ->options([
                'query' => [
                    'RegionId' => $regionId,
                    'PageSize' => 100,
                    'PageNumber' => $pageNumber
                ],
                'connect_timeout' => 15.0,
                'timeout' => 30.0
            ])
            ->request();
    }

    // ---- EIP management ----

    public function releaseManagedEip($account)
    {
        $allocationId = trim((string) ($account->eipAllocationId ?? ''));
        if (($account->publicIpMode ?? '') !== 'eip' || empty($account->eipManaged) || $allocationId === '') {
            return false;
        }

        $key = $account->accessKeyId;
        $secret = $account->accessKeySecret;
        $regionId = $account->regionId;
        $this->unassociateEipAddress($key, $secret, $regionId, $allocationId, $account->instanceId ?? '');
        $this->waitEipStatus($key, $secret, $regionId, $allocationId, 'Available', 8);
        $this->releaseEipAddress($key, $secret, $regionId, $allocationId);
        return true;
    }

    public function replaceManagedEip($account)
    {
        $oldAllocationId = trim((string) ($account->eipAllocationId ?? ''));
        if (($account->publicIpMode ?? '') !== 'eip' || empty($account->eipManaged) || $oldAllocationId === '') {
            throw new \Exception('当前实例不是系统托管 EIP，无法更换公网 IP');
        }

        $key = $account->accessKeyId;
        $secret = $account->accessKeySecret;
        $regionId = $account->regionId;
        $instanceId = $account->instanceId ?? '';
        $bandwidth = max(1, (int) ($account->internetMaxBandwidthOut ?? 100));

        $newEip = $this->allocateEipAddress($key, $secret, $regionId, $bandwidth, ($account->instanceName ?? $instanceId) . '-replace');

        try {
            $this->unassociateEipAddress($key, $secret, $regionId, $oldAllocationId, $instanceId);
            $this->waitEipStatus($key, $secret, $regionId, $oldAllocationId, 'Available', 8);
            $this->associateEipAddress($key, $secret, $regionId, $newEip['allocationId'], $instanceId);
            $this->waitEipStatus($key, $secret, $regionId, $newEip['allocationId'], 'InUse', 12);
            $this->releaseEipAddress($key, $secret, $regionId, $oldAllocationId);
        } catch (\Exception $e) {
            $this->releaseEipAddressSilently($key, $secret, $regionId, $newEip['allocationId'] ?? '');
            throw $e;
        }

        return [
            'publicIp' => $newEip['ipAddress'] ?? '',
            'publicIpMode' => 'eip',
            'eipAllocationId' => $newEip['allocationId'] ?? '',
            'eipAddress' => $newEip['ipAddress'] ?? '',
            'eipManaged' => true,
            'internetMaxBandwidthOut' => $bandwidth
        ];
    }

    // ---- EIP low-level helpers ----

    private function allocateEipAddress($key, $secret, $regionId, $bandwidth, $instanceName)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $bandwidth, $instanceName) {
            $this->setDefaultClient($key, $secret, $regionId);

            return AlibabaCloud::rpc()
                ->product('Vpc')
                ->scheme('https')
                ->version('2016-04-28')
                ->action('AllocateEipAddress')
                ->method('POST')
                ->host($this->vpcHost($regionId))
                ->options([
                    'query' => [
                        'RegionId' => $regionId,
                        'Bandwidth' => max(1, (int) $bandwidth),
                        'InternetChargeType' => 'PayByTraffic',
                        'Name' => $instanceName . '-eip',
                        'Tag.1.Key' => $this->managedTagKey,
                        'Tag.1.Value' => $this->managedTagValue
                    ],
                    'connect_timeout' => 5.0,
                    'timeout' => 20.0
                ])
                ->request();
        }, 'allocateEipAddress');

        $allocationId = $result['AllocationId'] ?? '';
        if ($allocationId === '') {
            throw new \Exception('EIP 申请成功但未返回 AllocationId');
        }

        $ipAddress = $result['EipAddress'] ?? '';
        if ($ipAddress === '') {
            $detail = $this->waitEipStatus($key, $secret, $regionId, $allocationId, 'Available', 6);
            $ipAddress = $detail['IpAddress'] ?? '';
        }

        return [
            'allocationId' => $allocationId,
            'ipAddress' => $ipAddress
        ];
    }

    private function associateEipAddress($key, $secret, $regionId, $allocationId, $instanceId)
    {
        if ($allocationId === '' || $instanceId === '') {
            throw new \Exception('EIP 绑定参数缺失');
        }

        return RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId, $instanceId) {
            $this->setDefaultClient($key, $secret, $regionId);

            return AlibabaCloud::rpc()
                ->product('Vpc')
                ->scheme('https')
                ->version('2016-04-28')
                ->action('AssociateEipAddress')
                ->method('POST')
                ->host($this->vpcHost($regionId))
                ->options([
                    'query' => [
                        'RegionId' => $regionId,
                        'AllocationId' => $allocationId,
                        'InstanceId' => $instanceId,
                        'InstanceType' => 'EcsInstance'
                    ],
                    'connect_timeout' => 5.0,
                    'timeout' => 20.0
                ])
                ->request();
        }, 'associateEipAddress');
    }

    private function unassociateEipAddress($key, $secret, $regionId, $allocationId, $instanceId)
    {
        if ($allocationId === '') {
            return true;
        }

        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId, $instanceId) {
                $this->setDefaultClient($key, $secret, $regionId);

                $query = [
                    'RegionId' => $regionId,
                    'AllocationId' => $allocationId,
                    'InstanceType' => 'EcsInstance'
                ];
                if ($instanceId !== '') {
                    $query['InstanceId'] = $instanceId;
                }

                return AlibabaCloud::rpc()
                    ->product('Vpc')
                    ->scheme('https')
                    ->version('2016-04-28')
                    ->action('UnassociateEipAddress')
                    ->method('POST')
                    ->host($this->vpcHost($regionId))
                    ->options([
                        'query' => $query,
                        'connect_timeout' => 5.0,
                        'timeout' => 20.0
                    ])
                    ->request();
            }, 'unassociateEipAddress');
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (
                stripos($message, 'IncorrectEipStatus') === false
                && stripos($message, 'InvalidAllocationId.NotFound') === false
                && stripos($message, 'not exist') === false
            ) {
                throw $e;
            }
        }

        return true;
    }

    private function releaseEipAddress($key, $secret, $regionId, $allocationId)
    {
        if ($allocationId === '') {
            return true;
        }

        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId) {
                $this->setDefaultClient($key, $secret, $regionId);

                return AlibabaCloud::rpc()
                    ->product('Vpc')
                    ->scheme('https')
                    ->version('2016-04-28')
                    ->action('ReleaseEipAddress')
                    ->method('POST')
                    ->host($this->vpcHost($regionId))
                    ->options([
                        'query' => [
                            'RegionId' => $regionId,
                            'AllocationId' => $allocationId
                        ],
                        'connect_timeout' => 5.0,
                        'timeout' => 20.0
                    ])
                    ->request();
            }, 'releaseEipAddress');
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (stripos($message, 'InvalidAllocationId.NotFound') === false && stripos($message, 'not exist') === false) {
                throw $e;
            }
        }

        return true;
    }

    private function releaseEipAddressSilently($key, $secret, $regionId, $allocationId)
    {
        try {
            $this->unassociateEipAddress($key, $secret, $regionId, $allocationId, '');
            $this->waitEipStatus($key, $secret, $regionId, $allocationId, 'Available', 6);
            $this->releaseEipAddress($key, $secret, $regionId, $allocationId);
        } catch (\Exception $e) {
            error_log("EIP release rollback failed [{$allocationId}]: " . $e->getMessage());
        }
    }

    private function describeEipAddress($key, $secret, $regionId, $allocationId)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId) {
            $this->setDefaultClient($key, $secret, $regionId);

            return AlibabaCloud::rpc()
                ->product('Vpc')
                ->scheme('https')
                ->version('2016-04-28')
                ->action('DescribeEipAddresses')
                ->method('POST')
                ->host($this->vpcHost($regionId))
                ->options([
                    'query' => [
                        'RegionId' => $regionId,
                        'AllocationId' => $allocationId
                    ],
                    'connect_timeout' => 5.0,
                    'timeout' => 15.0
                ])
                ->request();
        }, 'describeEipAddress');

        return $result['EipAddresses']['EipAddress'][0] ?? null;
    }

    private function waitEipStatus($key, $secret, $regionId, $allocationId, $targetStatus, $maxAttempts = 12)
    {
        $last = null;
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep($i === 0 ? 2 : 4);
            $last = $this->describeEipAddress($key, $secret, $regionId, $allocationId);
            if (!$last) {
                continue;
            }
            if (($last['Status'] ?? '') === $targetStatus) {
                return $last;
            }
        }

        throw new \Exception("EIP 状态等待超时: {$targetStatus} (最后观测状态: " . ($last['Status'] ?? 'null') . ")");
    }

    // ---- IPv6 management ----
    // 公网 IPv6 路径(阿里云无 IPv6 EIP):VPC 开通 IPv6 → 交换机开通 IPv6 → IPv6 网关 →
    // AssignIpv6Addresses 分配网卡地址 → AllocateIpv6InternetBandwidth 开通公网带宽。

    /**
     * 查询实例主网卡信息(NetworkInterfaceId/VpcId/VSwitchId),用于 IPv6 分配。
     * @throws \Exception
     */
    public function getNetworkInterfaceInfo($account): array
    {
        if (empty($account->instanceId)) {
            throw new \Exception("未配置 Instance ID");
        }

        $result = RetryHandler::execute(function () use ($account) {
            $this->setDefaultClient($account->accessKeyId, $account->accessKeySecret, $account->regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeNetworkInterfaces')->method('POST')->host($this->ecsHost($account->regionId))
                ->options(['query' => ['RegionId' => $account->regionId, 'InstanceId' => $account->instanceId], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                ->request();
        }, 'getNetworkInterfaceInfo');

        $interfaces = $result['NetworkInterfaceSets']['NetworkInterfaceSet'] ?? [];
        foreach ($interfaces as $eni) {
            if (($eni['Type'] ?? '') === 'Primary' || count($interfaces) === 1) {
                return [
                    'networkInterfaceId' => (string) ($eni['NetworkInterfaceId'] ?? ''),
                    'vpcId' => (string) ($eni['VpcId'] ?? ''),
                    'vswitchId' => (string) ($eni['VSwitchId'] ?? ''),
                ];
            }
        }
        throw new \Exception("未找到实例的主网卡信息 (ID: {$account->instanceId})");
    }

    /**
     * VPC 开通 IPv6 网段(AssociateVpcCidrBlock,IpVersion=IPV6,不传 Ipv6CidrBlock 由系统自动分配)。
     * 已开通时(错误码 Ipv6CidrBlockExisted)视为成功。
     */
    public function enableVpcIpv6($key, $secret, $regionId, $vpcId): void
    {
        if ($vpcId === '') {
            throw new \Exception("缺少 VPC ID");
        }
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $vpcId) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('AssociateVpcCidrBlock')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'VpcId' => $vpcId, 'IpVersion' => 'IPV6'], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'enableVpcIpv6');
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'Ipv6CidrBlockExisted') !== false
                || stripos($code, 'Ipv6CidrBlockExists') !== false) {
                return; // VPC 已开通 IPv6(不同代际的错误码变体)
            }
            throw $e;
        }
    }

    /**
     * 查询交换机是否已开通 IPv6(DescribeVSwitches 返回 Ipv6CidrBlock 即已开通)。
     */
    public function isVSwitchIpv6Enabled($key, $secret, $regionId, $vswitchId): bool
    {
        if ($vswitchId === '') {
            return false;
        }
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $vswitchId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('DescribeVSwitches')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'VSwitchId' => $vswitchId], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                ->request();
        }, 'isVSwitchIpv6Enabled');
        $vswitches = $result['VSwitches']['VSwitch'] ?? [];
        if (isset($vswitches['VSwitchId'])) {
            $vswitches = [$vswitches];
        }
        foreach ($vswitches as $vsw) {
            if (($vsw['VSwitchId'] ?? '') === $vswitchId && !empty($vsw['Ipv6CidrBlock'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 交换机开通 IPv6(ModifyVSwitchAttribute:EnableIPv6=true + Ipv6CidrBlock 最后 8 位,十进制 0~255)。
     * 先查询已开通则跳过,保证幂等;随机网段只在首次开通时使用。
     */
    public function enableVSwitchIpv6($key, $secret, $regionId, $vswitchId): void
    {
        if ($vswitchId === '') {
            throw new \Exception("缺少 VSwitch ID");
        }
        if ($this->isVSwitchIpv6Enabled($key, $secret, $regionId, $vswitchId)) {
            return; // 已开通,幂等跳过
        }
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $vswitchId) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('ModifyVSwitchAttribute')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'VSwitchId' => $vswitchId, 'EnableIPv6' => true, 'Ipv6CidrBlock' => rand(0, 255)], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'enableVSwitchIpv6');
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'Ipv6AlreadyEnabled') !== false) {
                return; // 并发下其他请求已开通
            }
            throw $e;
        }
    }

    /**
     * 确保 VPC 下存在 IPv6 网关(CreateIpv6Gateway)。已存在时返回空字符串,否则返回 Ipv6GatewayId。
     */
    public function ensureIpv6Gateway($key, $secret, $regionId, $vpcId, $name = 'ecs-control-ipv6gw'): string
    {
        if ($vpcId === '') {
            throw new \Exception("缺少 VPC ID");
        }
        try {
            $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $vpcId, $name) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('CreateIpv6Gateway')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'VpcId' => $vpcId, 'Name' => $name], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'ensureIpv6Gateway');
            return (string) ($result['Ipv6GatewayId'] ?? '');
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'OnlyOneIpv6GatewayInVpc') !== false) {
                return ''; // 已存在 IPv6 网关
            }
            throw $e;
        }
    }

    /**
     * 为实例主网卡分配一个 IPv6 地址(AssignIpv6Addresses),返回 IPv6 地址字符串。
     * 前置:交换机已开通 IPv6。
     */
    public function assignIpv6Address($key, $secret, $regionId, $networkInterfaceId): string
    {
        if ($networkInterfaceId === '') {
            throw new \Exception("缺少弹性网卡 ID");
        }
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $networkInterfaceId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('AssignIpv6Addresses')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'NetworkInterfaceId' => $networkInterfaceId, 'Ipv6AddressCount' => 1], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                ->request();
        }, 'assignIpv6Address');
        $addresses = $result['Ipv6Sets']['Ipv6Address'] ?? [];
        $address = $addresses[0] ?? '';
        if ($address === '') {
            throw new \Exception('IPv6 地址分配成功但未返回地址');
        }
        return $address;
    }

    /**
     * 查询实例已分配的 IPv6 地址列表(DescribeIpv6Addresses)。
     * @return array<int, array{ipv6AddressId: string, ipv6Address: string, internetBandwidthId: string, status: string}>
     */
    public function describeIpv6Addresses($key, $secret, $regionId, $instanceId): array
    {
        if ($instanceId === '') {
            return [];
        }
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $instanceId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('DescribeIpv6Addresses')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'AssociatedInstanceId' => $instanceId, 'AssociatedInstanceType' => 'EcsInstance', 'PageSize' => 50], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                ->request();
        }, 'describeIpv6Addresses');

        $list = [];
        $items = $result['Ipv6Addresses']['Ipv6Address'] ?? [];
        if (isset($items['Ipv6AddressId'])) {
            $items = [$items]; // 单元素时 SDK 可能返回关联数组
        }
        foreach ($items as $item) {
            if (($item['AssociatedInstanceId'] ?? '') !== $instanceId) {
                continue;
            }
            $list[] = [
                'ipv6AddressId' => (string) ($item['Ipv6AddressId'] ?? ''),
                'ipv6Address' => (string) ($item['Ipv6Address'] ?? ''),
                'internetBandwidthId' => (string) (($item['Ipv6InternetBandwidth']['Ipv6InternetBandwidthId'] ?? '') ?: ($item['Ipv6InternetBandwidthId'] ?? '')),
                'status' => (string) ($item['Status'] ?? ''),
            ];
        }
        return $list;
    }

    /**
     * 为 IPv6 地址开通公网带宽(AllocateIpv6InternetBandwidth,按流量计费)。
     * @return array{internetBandwidthId: string, status: string} status: allocated|already_exists|failed
     */
    public function allocateIpv6InternetBandwidth($key, $secret, $regionId, $ipv6AddressId, $bandwidth = 5): array
    {
        if ($ipv6AddressId === '') {
            throw new \Exception("缺少 IPv6 地址 ID");
        }
        try {
            $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $ipv6AddressId, $bandwidth) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('AllocateIpv6InternetBandwidth')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'Ipv6AddressId' => $ipv6AddressId, 'Bandwidth' => max(1, (int) $bandwidth), 'InternetChargeType' => 'PayByTraffic'], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'allocateIpv6InternetBandwidth');
            $bandwidthId = (string) ($result['InternetBandwidthId'] ?? '');
            return ['internetBandwidthId' => $bandwidthId, 'status' => $bandwidthId !== '' ? 'allocated' : 'failed'];
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'InternetBandwidthAlreadyExisted') !== false) {
                return ['internetBandwidthId' => '', 'status' => 'already_exists'];
            }
            throw $e;
        }
    }

    /**
     * 释放 IPv6 公网带宽(ReleaseIpv6InternetBandwidth)。带宽不存在时视为成功。
     */
    public function releaseIpv6InternetBandwidth($key, $secret, $regionId, $internetBandwidthId): void
    {
        if ($internetBandwidthId === '') {
            return;
        }
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $internetBandwidthId) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('ReleaseIpv6InternetBandwidth')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'InternetBandwidthId' => $internetBandwidthId], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'releaseIpv6InternetBandwidth');
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'InvalidIpv6Instance') !== false || stripos($code, 'ResourceNotFound') !== false) {
                return;
            }
            throw $e;
        }
    }

    /**
     * 回收弹性网卡上的 IPv6 地址(UnassignIpv6Addresses)。地址不存在时视为成功。
     */
    public function unassignIpv6Addresses($key, $secret, $regionId, $networkInterfaceId, $ipv6Address): void
    {
        if ($networkInterfaceId === '' || $ipv6Address === '') {
            return;
        }
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $networkInterfaceId, $ipv6Address) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                    ->action('UnassignIpv6Addresses')->method('POST')->host($this->ecsHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'NetworkInterfaceId' => $networkInterfaceId, 'Ipv6Address.1' => $ipv6Address], 'connect_timeout' => 10.0, 'timeout' => 20.0])
                    ->request();
            }, 'unassignIpv6Addresses');
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = (string) $e->getErrorCode();
            if (stripos($code, 'IpUnassigned') !== false) {
                return;
            }
            throw $e;
        }
    }
}
