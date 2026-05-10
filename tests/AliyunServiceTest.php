<?php

require_once __DIR__ . '/../src/RetryHandler.php';
require_once __DIR__ . '/../AliyunService.php';

final class TargetRegionAliyunService extends AliyunService
{
    public int $getRegionsCalls = 0;
    public array $describeCalls = [];

    public function getRegions($key, $secret)
    {
        $this->getRegionsCalls++;
        throw new Exception('DescribeRegions should not run when target region is provided');
    }

    protected function requestDescribeInstancesPage($key, $secret, $regionId, $pageNumber)
    {
        $this->describeCalls[] = [$key, $secret, $regionId, $pageNumber];

        return [
            'TotalCount' => 1,
            'PageSize' => 100,
            'Instances' => [
                'Instance' => [[
                    'InstanceId' => 'i-eu-1',
                    'InstanceName' => 'de-node',
                    'Status' => 'Running',
                    'InstanceType' => 'ecs.t6-c1m1.large',
                    'Cpu' => 2,
                    'Memory' => 2048,
                    'InternetMaxBandwidthOut' => 5,
                    'OSName' => 'Debian',
                    'PublicIpAddress' => ['IpAddress' => ['203.0.113.10']],
                    'VpcAttributes' => ['PrivateIpAddress' => ['IpAddress' => ['10.0.0.5']]],
                    'StoppedMode' => 'KeepCharging',
                    'InstanceChargeType' => 'PostPaid',
                ]]
            ]
        ];
    }
}

function assert_same_aliyun_service($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function test_get_instances_for_target_region_does_not_require_describe_regions(): void
{
    $service = new TargetRegionAliyunService();

    $instances = $service->getInstances('key', 'secret', 'eu-central-1');

    assert_same_aliyun_service(0, $service->getRegionsCalls, 'target region lookup should not call DescribeRegions first');
    assert_same_aliyun_service([['key', 'secret', 'eu-central-1', 1]], $service->describeCalls, 'target region lookup should query only the requested region');
    assert_same_aliyun_service('i-eu-1', $instances[0]['instanceId'], 'target region instance should be returned');
    assert_same_aliyun_service('eu-central-1', $instances[0]['regionId'], 'instance region should use target region');
    assert_same_aliyun_service('eu-central-1', $instances[0]['regionName'], 'target region should fall back to region id as local name');
}

test_get_instances_for_target_region_does_not_require_describe_regions();

echo "AliyunService tests passed\n";
