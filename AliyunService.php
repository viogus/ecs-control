<?php

declare(strict_types=1);

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class AliyunService
{
    private $regionCache = [];
    private $managedTagKey = 'ecs-controller-managed';
    private $managedTagValue = 'true';

    private $trafficCache = [];

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
            return RetryHandler::execute(function () use ($account) {
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
                            'Force' => true,
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
}
