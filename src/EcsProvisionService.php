<?php

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class EcsProvisionService
{
    private $managedTagKey = 'ecs-controller-managed';
    private $managedTagValue = 'true';

    private function setDefaultClient($key, $secret, $regionId)
    {
        AlibabaCloud::accessKeyClient($key, $secret)
            ->regionId($regionId)
            ->asDefaultClient();
    }

    private function ecsHost($regionId) { return "ecs.{$regionId}.aliyuncs.com"; }
    private function vpcHost($regionId) { return "vpc.{$regionId}.aliyuncs.com"; }

    public function buildEcsCreatePreview($account, array $request, $clientIp = '')
    {
        $regionId = trim((string) ($request['regionId'] ?? $account['region_id'] ?? ''));
        $instanceType = trim((string) ($request['instanceType'] ?? '')) ?: 'ecs.e-c4m1.large';
        $osKey = trim((string) ($request['osKey'] ?? 'debian_12'));
        $publicIpMode = trim((string) ($request['publicIpMode'] ?? 'ecs_public_ip'));
        if (!in_array($publicIpMode, ['ecs_public_ip', 'eip'], true)) { $publicIpMode = 'ecs_public_ip'; }
        $instanceName = trim((string) ($request['instanceName'] ?? ''));
        if ($instanceName === '') { $instanceName = 'launch-' . date('Ymd-His'); }
        $requestedDiskSize = (int) ($request['systemDiskSize'] ?? 20);
        $requestedDiskCategory = trim((string) ($request['systemDiskCategory'] ?? 'cloud_essd_entry'));

        if ($regionId === '') { throw new \Exception('请选择区域'); }

        $key = $account['access_key_id'];
        $secret = $account['access_key_secret'];

        $zone = $this->selectAvailableZone($key, $secret, $regionId, $instanceType);
        $instanceTypeInfo = $this->describeInstanceType($key, $secret, $regionId, $instanceType);
        $image = $this->selectSystemImage($key, $secret, $regionId, $osKey, $instanceTypeInfo['CpuArchitecture'] ?? '');
        $diskCategory = $this->selectDiskCategory($zone, $requestedDiskCategory);
        $diskRange = $this->getSystemDiskSizeRange($key, $secret, $regionId, $zone['zoneId'], $instanceType, $diskCategory);
        $diskSize = $this->normalizeSystemDiskSize($requestedDiskSize, $diskRange);
        $bandwidth = $this->estimateMaxBandwidthOut($instanceType, $regionId);
        $loginPort = ($image['osType'] ?? 'linux') === 'windows' ? 3389 : 22;
        $loginUser = ($image['osType'] ?? 'linux') === 'windows' ? 'Administrator' : 'root';
        $securityRule = '默认全开：允许 0.0.0.0/0 入方向 TCP/UDP/ICMP';

        return [
            'account' => [
                'groupKey' => $account['group_key'] ?? '',
                'label' => trim((string) ($account['remark'] ?? '')) ?: substr($key, 0, 7) . '***'
            ],
            'regionId' => $regionId, 'zoneId' => $zone['zoneId'],
            'instanceType' => $instanceType, 'instanceName' => $instanceName,
            'osKey' => $osKey, 'osLabel' => $image['label'], 'imageId' => $image['imageId'],
            'imageSize' => (int) ($image['size'] ?? 0), 'loginUser' => $loginUser, 'loginPort' => $loginPort,
            'clientCidrIp' => '0.0.0.0/0', 'chargeType' => 'PostPaid',
            'internetChargeType' => 'PayByTraffic', 'internetMaxBandwidthOut' => $bandwidth,
            'publicIpMode' => $publicIpMode,
            'publicIpModeLabel' => $publicIpMode === 'eip' ? 'EIP 弹性公网 IP' : 'ECS 普通公网 IP',
            'systemDisk' => [
                'category' => $diskCategory, 'size' => $diskSize,
                'min' => $diskRange['min'], 'max' => $diskRange['max'], 'unit' => $diskRange['unit']
            ],
            'network' => [
                'vpc' => ['mode' => 'auto', 'name' => "ecs-controller-vpc-{$regionId}", 'cidr' => '172.31.0.0/16'],
                'vswitch' => ['mode' => 'auto', 'name' => "ecs-controller-vsw-{$zone['zoneId']}", 'cidr' => $this->cidrForZone($zone['zoneId'])],
                'securityGroup' => ['mode' => 'auto', 'name' => "ecs-controller-sg-{$regionId}", 'rules' => [$securityRule]]
            ],
            'cdtCompatible' => true, 'backupEnabled' => false,
            'pricing' => [
                'available' => false,
                'currency' => ($account['site_type'] ?? 'international') === 'international' ? 'USD' : 'CNY',
                'message' => '费用预估暂不可用。实例按量计费，公网按实际出口流量计费，最终以阿里云账单为准。',
                'trafficNote' => '公网按使用流量计费，并按 CDT 兼容方式创建。'
            ],
            'warnings' => array_values(array_filter([
                $publicIpMode === 'eip'
                    ? 'EIP 模式会先创建无普通公网 IP 的 ECS，再申请并绑定 EIP；停机不会释放 EIP，释放实例时会自动释放系统创建的 EIP。'
                    : 'ECS 普通公网 IP 由实例直接分配，停机后再启动可能变化；如需可控更换 IP，建议选择 EIP 模式。',
                '公网带宽峰值会自动尝试最高可用值，若账号配额或规格限制不支持，会自动降级重试。',
                "系统盘将严格按 {$diskSize} GB 创建；当前 API 返回范围为 {$diskRange['min']}-{$diskRange['max']} {$diskRange['unit']}，超出范围会直接报错。",
                '文件备份默认不启用；如需备份，请创建后在阿里云控制台单独开启。',
                '安全组默认全开，便于测试和交付；生产环境建议创建后收紧来源 IP 和端口。'
            ]))
        ];
    }

    public function getAvailableSystemDiskOptions($account, array $request)
    {
        $regionId = trim((string) ($request['regionId'] ?? $account['region_id'] ?? ''));
        $instanceType = trim((string) ($request['instanceType'] ?? '')) ?: 'ecs.e-c4m1.large';
        if ($regionId === '') { throw new \Exception('请选择区域'); }

        $key = $account['access_key_id'];
        $secret = $account['access_key_secret'];
        $zone = $this->selectAvailableZone($key, $secret, $regionId, $instanceType);
        $rawCategories = $zone['raw']['AvailableDiskCategories']['DiskCategories'] ?? $zone['raw']['AvailableDiskCategories']['DiskCategory'] ?? [];
        $rawCategories = is_array($rawCategories) ? $rawCategories : [];
        $candidates = $rawCategories ?: ['cloud_essd_entry', 'cloud_essd', 'cloud_efficiency', 'cloud'];
        $candidates = array_values(array_filter($candidates, fn($c) => $c !== 'cloud_auto'));
        $preferredOrder = ['cloud_essd_entry', 'cloud_essd', 'cloud_efficiency', 'cloud'];
        $candidates = array_values(array_unique(array_filter($candidates)));
        usort($candidates, function ($a, $b) use ($preferredOrder) {
            $aIdx = array_search($a, $preferredOrder, true);
            $bIdx = array_search($b, $preferredOrder, true);
            return ($aIdx === false ? 99 : $aIdx) <=> ($bIdx === false ? 99 : $bIdx) ?: strcmp($a, $b);
        });

        $options = []; $errors = [];
        foreach ($candidates as $category) {
            try {
                $range = $this->getSystemDiskSizeRange($key, $secret, $regionId, $zone['zoneId'], $instanceType, $category);
                $options[] = ['value' => $category, 'label' => $this->diskCategoryLabel($category),
                    'min' => $range['min'], 'max' => $range['max'], 'unit' => $range['unit'],
                    'zoneId' => $zone['zoneId'], 'status' => $range['status'] ?? '', 'statusCategory' => $range['statusCategory'] ?? ''];
            } catch (\Exception $e) { $errors[$category] = $e->getMessage(); }
        }
        if (empty($options)) { throw new \Exception('当前账号区域和实例规格没有可用的系统盘类型，请更换实例规格后重试'); }
        return ['regionId' => $regionId, 'zoneId' => $zone['zoneId'], 'instanceType' => $instanceType, 'options' => $options, 'errors' => $errors];
    }

    public function createManagedEcsFromPreview($account, array $preview, callable $progress = null)
    {
        $key = $account['access_key_id']; $secret = $account['access_key_secret'];
        $regionId = $preview['regionId']; $zoneId = $preview['zoneId']; $instanceType = $preview['instanceType'];
        $password = $this->generateInstancePassword();

        $this->emitProgress($progress, '准备 VPC');
        $vpc = $this->ensureVpc($key, $secret, $regionId, $preview['network']['vpc']['name'], $preview['network']['vpc']['cidr']);
        $this->emitProgress($progress, '准备交换机');
        $vswitch = $this->ensureVSwitch($key, $secret, $regionId, $zoneId, $vpc['VpcId'], $preview['network']['vswitch']['name'], $preview['network']['vswitch']['cidr']);
        $this->emitProgress($progress, '准备安全组');
        $securityGroup = $this->ensureSecurityGroup($key, $secret, $regionId, $vpc['VpcId'], $preview['network']['securityGroup']['name']);
        $this->authorizeOpenSecurityGroupRules($key, $secret, $regionId, $securityGroup['SecurityGroupId']);

        $bandwidthCandidates = $this->bandwidthCandidates((int) ($preview['internetMaxBandwidthOut'] ?? 100));
        $diskCategories = array_unique(array_filter([$preview['systemDisk']['category'] ?? 'cloud_essd_entry']));
        $publicIpMode = ($preview['publicIpMode'] ?? 'ecs_public_ip') === 'eip' ? 'eip' : 'ecs_public_ip';
        $diskSize = $this->normalizeSystemDiskSize($preview['systemDisk']['size'] ?? 20, $preview['systemDisk'] ?? []);
        $lastError = null;

        foreach ($bandwidthCandidates as $bandwidth) {
            foreach ($diskCategories as $diskCategory) {
                $allocatedEip = null;
                try {
                    $this->emitProgress($progress, "创建 ECS（{$bandwidth} Mbps / {$diskCategory}）");
                    $instanceIds = $this->runInstance($key, $secret, $regionId, [
                        'zoneId' => $zoneId, 'instanceType' => $instanceType, 'imageId' => $preview['imageId'],
                        'securityGroupId' => $securityGroup['SecurityGroupId'], 'vSwitchId' => $vswitch['VSwitchId'],
                        'instanceName' => $preview['instanceName'], 'password' => $password,
                        'internetMaxBandwidthOut' => $publicIpMode === 'eip' ? 0 : $bandwidth,
                        'systemDiskCategory' => $diskCategory, 'systemDiskSize' => $diskSize
                    ]);
                    $instanceId = $instanceIds[0] ?? '';
                    if ($instanceId === '') { throw new \Exception('RunInstances 未返回 InstanceId'); }

                    $this->emitProgress($progress, '等待实例启动');
                    $instance = $this->waitInstanceReady($key, $secret, $regionId, $instanceId);

                    if ($publicIpMode === 'eip') {
                        $this->emitProgress($progress, "申请 EIP（{$bandwidth} Mbps）");
                        $allocatedEip = $this->allocateEipAddress($key, $secret, $regionId, $bandwidth, $preview['instanceName']);
                        $this->emitProgress($progress, '绑定 EIP');
                        $this->associateEipAddress($key, $secret, $regionId, $allocatedEip['allocationId'], $instanceId);
                        $this->waitEipStatus($key, $secret, $regionId, $allocatedEip['allocationId'], 'InUse');
                        $instance = $this->waitInstanceReady($key, $secret, $regionId, $instanceId);
                        $instance['publicIp'] = $allocatedEip['ipAddress'] ?: ($instance['publicIp'] ?? '');
                        $instance['eipAllocationId'] = $allocatedEip['allocationId'];
                        $instance['eipAddress'] = $allocatedEip['ipAddress'];
                    }

                    return [
                        'instanceId' => $instanceId, 'publicIp' => $instance['publicIp'] ?? '',
                        'privateIp' => $instance['privateIp'] ?? '', 'publicIpMode' => $publicIpMode,
                        'eipAllocationId' => $instance['eipAllocationId'] ?? '', 'eipAddress' => $instance['eipAddress'] ?? '',
                        'eipManaged' => $publicIpMode === 'eip', 'status' => $instance['status'] ?? 'Unknown',
                        'instanceName' => $preview['instanceName'], 'instanceType' => $instanceType,
                        'vpcId' => $vpc['VpcId'], 'vswitchId' => $vswitch['VSwitchId'],
                        'securityGroupId' => $securityGroup['SecurityGroupId'], 'internetMaxBandwidthOut' => $bandwidth,
                        'systemDiskCategory' => $diskCategory, 'systemDiskSize' => $diskSize,
                        'loginUser' => $preview['loginUser'] ?? 'root', 'loginPassword' => $password
                    ];
                } catch (\Exception $e) {
                    if ($allocatedEip && !empty($allocatedEip['allocationId'])) {
                        $this->releaseEipAddressSilently($key, $secret, $regionId, $allocatedEip['allocationId']);
                    }
                    if (!empty($instanceId)) {
                        $this->deleteOrphanedInstance($key, $secret, $regionId, $instanceId);
                    }
                    $lastError = $e;
                    $message = $e->getMessage();
                    if ($this->isDiskSizeError($message)) {
                        throw new \Exception("系统盘 {$diskSize} GB 不被当前镜像或实例规格支持，请手动调整系统盘大小后重新创建。阿里云返回：" . $message);
                    }
                    continue;
                }
            }
        }
        throw $lastError ?: new \Exception('ECS 创建失败');
    }

    // -- provisioning helpers --

    private function selectAvailableZone($key, $secret, $regionId, $instanceType)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $instanceType) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeZones')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'InstanceType' => $instanceType, 'AvailableResourceCreation.1' => 'Instance'], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'selectAvailableZone');
        $zones = $result['Zones']['Zone'] ?? [];
        foreach ($zones as $zone) {
            if (!empty($zone['ZoneId'])) { return ['zoneId' => $zone['ZoneId'], 'raw' => $zone]; }
        }
        throw new \Exception("当前区域 {$regionId} 下未找到规格 {$instanceType} 的可用区库存");
    }

    private function describeInstanceType($key, $secret, $regionId, $instanceType)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $instanceType) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeInstanceTypes')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'InstanceTypes' => json_encode([$instanceType])], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'describeInstanceType');
        $types = $result['InstanceTypes']['InstanceType'] ?? [];
        foreach ($types as $type) { if (($type['InstanceTypeId'] ?? '') === $instanceType) return $type; }
        return $types[0] ?? [];
    }

    private function selectSystemImage($key, $secret, $regionId, $osKey, $cpuArchitecture = '')
    {
        $profiles = [
            'alibaba_cloud_linux_3' => ['label' => 'Alibaba Cloud Linux 3', 'osType' => 'linux', 'platform' => 'Aliyun', 'patterns' => ['aliyun_3', 'alibaba cloud linux 3']],
            'ubuntu_22' => ['label' => 'Ubuntu 22.04', 'osType' => 'linux', 'platform' => 'Ubuntu', 'patterns' => ['ubuntu_22', 'ubuntu 22', '22_04']],
            'ubuntu_24' => ['label' => 'Ubuntu 24.04', 'osType' => 'linux', 'platform' => 'Ubuntu', 'patterns' => ['ubuntu_24', 'ubuntu 24', '24_04']],
            'debian_12' => ['label' => 'Debian 12', 'osType' => 'linux', 'platform' => 'Debian', 'patterns' => ['debian_12', 'debian 12']],
            'centos_stream_9' => ['label' => 'CentOS Stream 9', 'osType' => 'linux', 'platform' => 'CentOS', 'patterns' => ['centos_stream_9', 'centos stream 9']],
            'windows_2022' => ['label' => 'Windows Server 2022', 'osType' => 'windows', 'platform' => 'Windows Server', 'patterns' => ['win2022', 'windows server 2022']]
        ];
        $profile = $profiles[$osKey] ?? $profiles['debian_12'];
        $architecture = $this->normalizeImageArchitecture($cpuArchitecture);

        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $profile, $architecture) {
            $this->setDefaultClient($key, $secret, $regionId);
            $query = ['RegionId' => $regionId, 'ImageOwnerAlias' => 'system', 'OSType' => $profile['osType'], 'Status' => 'Available', 'PageSize' => 100];
            if (!empty($profile['platform'])) $query['Platform'] = $profile['platform'];
            if ($architecture !== '') $query['Architecture'] = $architecture;
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeImages')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => $query, 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'selectSystemImage');

        $images = $result['Images']['Image'] ?? [];
        usort($images, fn($a, $b) => strcmp((string) ($b['CreationTime'] ?? ''), (string) ($a['CreationTime'] ?? '')));
        foreach ($images as $image) {
            $haystack = strtolower(($image['ImageId'] ?? '') . ' ' . ($image['ImageName'] ?? '') . ' ' . ($image['Description'] ?? ''));
            foreach ($profile['patterns'] as $pattern) {
                if (strpos($haystack, strtolower($pattern)) !== false) {
                    return ['imageId' => $image['ImageId'], 'label' => $profile['label'], 'osType' => $profile['osType'], 'size' => (int) ($image['Size'] ?? 0)];
                }
            }
        }
        throw new \Exception("当前区域未找到可用系统镜像：{$profile['label']}");
    }

    private function normalizeImageArchitecture($cpuArchitecture): string
    {
        $value = strtolower((string) $cpuArchitecture);
        if (strpos($value, 'arm') !== false || strpos($value, 'aarch64') !== false) return 'arm64';
        if (strpos($value, 'x86') !== false || strpos($value, 'amd64') !== false || strpos($value, 'i386') !== false) return 'x86_64';
        return '';
    }

    private function ensureVpc($key, $secret, $regionId, $name, $cidr)
    {
        $existing = $this->describeManagedVpcs($key, $secret, $regionId);
        if (!empty($existing)) return $existing[0];
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $name, $cidr) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('CreateVpc')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'VpcName' => $name, 'CidrBlock' => $cidr, 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'ensureVpc');
        return ['VpcId' => $result['VpcId'] ?? '', 'VpcName' => $name, 'CidrBlock' => $cidr];
    }

    private function ensureVSwitch($key, $secret, $regionId, $zoneId, $vpcId, $name, $cidr)
    {
        $existing = $this->describeManagedVSwitches($key, $secret, $regionId, $vpcId, $zoneId);
        if (!empty($existing)) return $existing[0];
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $zoneId, $vpcId, $name, $cidr) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('CreateVSwitch')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'ZoneId' => $zoneId, 'VpcId' => $vpcId, 'VSwitchName' => $name, 'CidrBlock' => $cidr, 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 20.0])
                ->request();
        }, 'ensureVSwitch');
        return ['VSwitchId' => $result['VSwitchId'] ?? '', 'VSwitchName' => $name, 'ZoneId' => $zoneId, 'CidrBlock' => $cidr];
    }

    private function ensureSecurityGroup($key, $secret, $regionId, $vpcId, $name)
    {
        $existing = $this->describeManagedSecurityGroups($key, $secret, $regionId, $vpcId);
        if (!empty($existing)) return $existing[0];
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $vpcId, $name) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('CreateSecurityGroup')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'VpcId' => $vpcId, 'SecurityGroupName' => $name, 'Description' => 'Managed by CDT Monitor', 'SecurityGroupType' => 'normal', 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'ensureSecurityGroup');
        return ['SecurityGroupId' => $result['SecurityGroupId'] ?? '', 'SecurityGroupName' => $name];
    }

    private function authorizeOpenSecurityGroupRules($key, $secret, $regionId, $securityGroupId)
    {
        $rules = [['protocol' => 'tcp', 'port' => '1/65535'], ['protocol' => 'udp', 'port' => '1/65535'], ['protocol' => 'icmp', 'port' => '-1/-1']];
        foreach ($rules as $rule) {
            try {
                RetryHandler::execute(function () use ($key, $secret, $regionId, $securityGroupId, $rule) {
                    $this->setDefaultClient($key, $secret, $regionId);
                    return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                        ->action('AuthorizeSecurityGroup')->method('POST')->host($this->ecsHost($regionId))
                        ->options(['query' => ['RegionId' => $regionId, 'SecurityGroupId' => $securityGroupId, 'IpProtocol' => $rule['protocol'], 'PortRange' => $rule['port'], 'SourceCidrIp' => '0.0.0.0/0', 'Policy' => 'accept', 'Priority' => '1', 'Description' => 'CDT Monitor open access'], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                        ->request();
                }, 'authorizeOpenSecurityGroupRules');
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'InvalidPermission.Duplicate') === false) throw $e;
            }
        }
    }

    private function runInstance($key, $secret, $regionId, array $params)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $params) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('RunInstances')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => [
                    'RegionId' => $regionId, 'ZoneId' => $params['zoneId'], 'InstanceType' => $params['instanceType'],
                    'ImageId' => $params['imageId'], 'SecurityGroupId' => $params['securityGroupId'],
                    'VSwitchId' => $params['vSwitchId'], 'InstanceName' => $params['instanceName'],
                    'HostName' => preg_replace('/[^a-zA-Z0-9-]/', '-', strtolower($params['instanceName'])),
                    'Password' => $params['password'], 'InstanceChargeType' => 'PostPaid',
                    'InternetChargeType' => 'PayByTraffic', 'InternetMaxBandwidthOut' => (int) $params['internetMaxBandwidthOut'],
                    'SystemDisk.Category' => $params['systemDiskCategory'], 'SystemDisk.Size' => (int) $params['systemDiskSize'],
                    'DeletionProtection' => 'false', 'IoOptimized' => 'optimized', 'Amount' => 1,
                    'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue
                ], 'connect_timeout' => 5.0, 'timeout' => 25.0])
                ->request();
        }, 'runInstance', 2);
        return $result['InstanceIdSets']['InstanceIdSet'] ?? [];
    }

    // -- EIP operations --

    private function allocateEipAddress($key, $secret, $regionId, $bandwidth, $instanceName)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $bandwidth, $instanceName) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('AllocateEipAddress')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'Bandwidth' => max(1, (int) $bandwidth), 'InternetChargeType' => 'PayByTraffic', 'Name' => $instanceName . '-eip', 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 20.0])
                ->request();
        }, 'allocateEipAddress');
        $allocationId = $result['AllocationId'] ?? '';
        if ($allocationId === '') throw new \Exception('EIP 申请成功但未返回 AllocationId');
        $ipAddress = $result['EipAddress'] ?? '';
        if ($ipAddress === '') { $detail = $this->waitEipStatus($key, $secret, $regionId, $allocationId, 'Available', 6); $ipAddress = $detail['IpAddress'] ?? ''; }
        return ['allocationId' => $allocationId, 'ipAddress' => $ipAddress];
    }

    private function associateEipAddress($key, $secret, $regionId, $allocationId, $instanceId)
    {
        if ($allocationId === '' || $instanceId === '') throw new \Exception('EIP 绑定参数缺失');
        return RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId, $instanceId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('AssociateEipAddress')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'AllocationId' => $allocationId, 'InstanceId' => $instanceId, 'InstanceType' => 'EcsInstance'], 'connect_timeout' => 5.0, 'timeout' => 20.0])
                ->request();
        }, 'associateEipAddress');
    }

    private function unassociateEipAddress($key, $secret, $regionId, $allocationId, $instanceId)
    {
        if ($allocationId === '') return true;
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId, $instanceId) {
                $this->setDefaultClient($key, $secret, $regionId);
                $query = ['RegionId' => $regionId, 'AllocationId' => $allocationId, 'InstanceType' => 'EcsInstance'];
                if ($instanceId !== '') $query['InstanceId'] = $instanceId;
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('UnassociateEipAddress')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => $query, 'connect_timeout' => 5.0, 'timeout' => 20.0])
                    ->request();
            }, 'unassociateEipAddress');
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (stripos($message, 'IncorrectEipStatus') === false
                && stripos($message, 'InvalidAllocationId.NotFound') === false
                && stripos($message, 'not exist') === false) throw $e;
        }
        return true;
    }

    private function releaseEipAddress($key, $secret, $regionId, $allocationId)
    {
        if ($allocationId === '') return true;
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                    ->action('ReleaseEipAddress')->method('POST')->host($this->vpcHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'AllocationId' => $allocationId], 'connect_timeout' => 5.0, 'timeout' => 20.0])
                    ->request();
            }, 'releaseEipAddress');
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (stripos($message, 'InvalidAllocationId.NotFound') === false && stripos($message, 'not exist') === false) throw $e;
        }
        return true;
    }

    private function releaseEipAddressSilently($key, $secret, $regionId, $allocationId)
    {
        try {
            $this->unassociateEipAddress($key, $secret, $regionId, $allocationId, '');
            $this->waitEipStatus($key, $secret, $regionId, $allocationId, 'Available', 6);
            $this->releaseEipAddress($key, $secret, $regionId, $allocationId);
        } catch (\Exception $e) { error_log("EIP release rollback failed [{$allocationId}]: " . $e->getMessage()); }
    }

    private function describeEipAddress($key, $secret, $regionId, $allocationId)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $allocationId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('DescribeEipAddresses')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'AllocationId' => $allocationId], 'connect_timeout' => 5.0, 'timeout' => 15.0])
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
            if (!$last) continue;
            if (($last['Status'] ?? '') === $targetStatus) return $last;
        }
        throw new \Exception("EIP 状态等待超时: {$targetStatus} (最后观测状态: " . ($last['Status'] ?? 'null') . ")");
    }

    private function waitInstanceReady($key, $secret, $regionId, $instanceId)
    {
        $last = null;
        for ($i = 0; $i < 18; $i++) {
            sleep($i === 0 ? 2 : 5);
            $instances = $this->describeInstancesByIds($key, $secret, $regionId, [$instanceId]);
            if (!empty($instances)) {
                $last = $instances[0];
                if (in_array($last['status'], ['Running', 'Stopped'], true)) return $last;
            }
        }
        throw new \Exception("实例创建超时，等待实例状态就绪超时 90 秒");
    }

    // -- describe managed resources --

    private function describeManagedVpcs($key, $secret, $regionId)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('DescribeVpcs')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'describeManagedVpcs');
        return $result['Vpcs']['Vpc'] ?? [];
    }

    private function describeManagedVSwitches($key, $secret, $regionId, $vpcId, $zoneId)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $vpcId, $zoneId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Vpc')->scheme('https')->version('2016-04-28')
                ->action('DescribeVSwitches')->method('POST')->host($this->vpcHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'VpcId' => $vpcId, 'ZoneId' => $zoneId, 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'describeManagedVSwitches');
        return $result['VSwitches']['VSwitch'] ?? [];
    }

    private function describeManagedSecurityGroups($key, $secret, $regionId, $vpcId)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $vpcId) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeSecurityGroups')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'VpcId' => $vpcId, 'Tag.1.Key' => $this->managedTagKey, 'Tag.1.Value' => $this->managedTagValue], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'describeManagedSecurityGroups');
        return $result['SecurityGroups']['SecurityGroup'] ?? [];
    }

    private function describeInstancesByIds($key, $secret, $regionId, array $instanceIds)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $instanceIds) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeInstances')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'InstanceIds' => json_encode(array_values($instanceIds))], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'describeInstancesByIds');
        $items = $result['Instances']['Instance'] ?? [];
        return array_map(function ($instance) {
            return [
                'instanceId' => $instance['InstanceId'] ?? '',
                'instanceName' => $instance['InstanceName'] ?? '',
                'status' => $instance['Status'] ?? 'Unknown',
                'instanceType' => $instance['InstanceType'] ?? '',
                'internetMaxBandwidthOut' => (int) (($instance['EipAddress']['Bandwidth'] ?? 0) ?: ($instance['InternetMaxBandwidthOut'] ?? 0)),
                'publicIp' => $instance['PublicIpAddress']['IpAddress'][0] ?? $instance['EipAddress']['IpAddress'] ?? '',
                'eipAllocationId' => $instance['EipAddress']['AllocationId'] ?? '',
                'eipAddress' => $instance['EipAddress']['IpAddress'] ?? '',
                'privateIp' => $instance['VpcAttributes']['PrivateIpAddress']['IpAddress'][0] ?? ''
            ];
        }, $items);
    }

    // -- disk utilities --

    private function getSystemDiskSizeRange($key, $secret, $regionId, $zoneId, $instanceType, $diskCategory)
    {
        $result = RetryHandler::execute(function () use ($key, $secret, $regionId, $zoneId, $instanceType, $diskCategory) {
            $this->setDefaultClient($key, $secret, $regionId);
            return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                ->action('DescribeAvailableResource')->method('POST')->host($this->ecsHost($regionId))
                ->options(['query' => ['RegionId' => $regionId, 'ZoneId' => $zoneId, 'DestinationResource' => 'SystemDisk', 'ResourceType' => 'instance', 'InstanceType' => $instanceType, 'SystemDiskCategory' => $diskCategory, 'IoOptimized' => 'optimized', 'NetworkCategory' => 'vpc', 'InstanceChargeType' => 'PostPaid'], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                ->request();
        }, 'getSystemDiskSizeRange');
        $zones = $result['AvailableZones']['AvailableZone'] ?? [];
        foreach ($zones as $zone) {
            $resources = $zone['AvailableResources']['AvailableResource'] ?? [];
            foreach ($resources as $resource) {
                if (($resource['Type'] ?? '') !== 'SystemDisk') continue;
                $supported = $resource['SupportedResources']['SupportedResource'] ?? [];
                foreach ($supported as $item) {
                    $value = $item['Value'] ?? '';
                    if ($value !== '' && $value !== $diskCategory) continue;
                    return ['min' => max(1, (int) ($item['Min'] ?? 20)), 'max' => max(1, (int) ($item['Max'] ?? 2048)), 'unit' => $item['Unit'] ?? 'GiB', 'status' => $item['Status'] ?? '', 'statusCategory' => $item['StatusCategory'] ?? ''];
                }
            }
        }
        throw new \Exception("当前可用区/规格/磁盘类型未返回系统盘容量范围，请更换磁盘类型或实例规格后重试");
    }

    private function normalizeSystemDiskSize($value, array $range = []): int
    {
        $size = (int) $value; $min = (int) ($range['min'] ?? 20); $max = (int) ($range['max'] ?? 2048); $unit = $range['unit'] ?? 'GiB';
        if ($size < $min || $size > $max) throw new \Exception("系统盘大小必须在当前 API 返回范围 {$min}-{$max} {$unit} 之间");
        return $size;
    }

    private function isDiskSizeError($message): bool
    {
        $message = strtolower((string) $message);
        return strpos($message, 'systemdisk.size') !== false || strpos($message, 'invalidsystemdisksize') !== false
            || strpos($message, 'invaliddatadisksize') !== false || strpos($message, 'disk size') !== false;
    }

    private function selectDiskCategory($zone, $requested = 'cloud_essd_entry'): string
    {
        $raw = $zone['raw']['AvailableDiskCategories']['DiskCategories'] ?? $zone['raw']['AvailableDiskCategories']['DiskCategory'] ?? [];
        $categories = is_array($raw) ? $raw : [];
        $requested = trim((string) $requested);
        if ($requested !== '') {
            if (empty($categories) || in_array($requested, $categories, true)) return $requested;
            throw new \Exception("当前可用区不支持所选硬盘类型 {$requested}，请更换硬盘类型或实例规格后重试");
        }
        foreach (['cloud_essd_entry', 'cloud_essd', 'cloud_efficiency', 'cloud'] as $preferred) {
            if (empty($categories) || in_array($preferred, $categories, true)) return $preferred;
        }
        return 'cloud_essd_entry';
    }

    private function diskCategoryLabel($category): string
    {
        $map = ['cloud_essd_entry' => 'ESSD Entry 云盘', 'cloud_essd' => 'ESSD 云盘', 'cloud_efficiency' => '高效云盘', 'cloud' => '普通云盘'];
        return $map[$category] ?? $category;
    }

    private function estimateMaxBandwidthOut($instanceType, $regionId): int
    {
        // Estimated default; createManagedEcsFromPreview auto-downgrades if rejected
        return 200;
    }

    private function bandwidthCandidates($max): array
    {
        $base = [200, 100, 50, 30, 20, 10, 5, 1];
        $candidates = array_values(array_filter($base, fn($v) => $v <= max(1, $max)));
        if (!in_array($max, $candidates, true)) array_unshift($candidates, $max);
        return array_values(array_unique($candidates));
    }

    private function cidrForZone($zoneId): string
    {
        $hash = abs(crc32($zoneId));
        $third = 1 + ($hash % 200);
        return "172.31.{$third}.0/24";
    }

    private function generateInstancePassword(): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz'; $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789'; $symbols = '!@#%^*'; $all = $lower . $upper . $digits . $symbols;
        $password = $lower[random_int(0, strlen($lower) - 1)] . $upper[random_int(0, strlen($upper) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)] . $symbols[random_int(0, strlen($symbols) - 1)];
        for ($i = strlen($password); $i < 16; $i++) { $password .= $all[random_int(0, strlen($all) - 1)]; }
        return str_shuffle($password);
    }

    private function emitProgress($progress, $step): void
    {
        if (is_callable($progress)) $progress($step);
    }

    private function deleteOrphanedInstance($key, $secret, $regionId, $instanceId): void
    {
        try {
            RetryHandler::execute(function () use ($key, $secret, $regionId, $instanceId) {
                $this->setDefaultClient($key, $secret, $regionId);
                return AlibabaCloud::rpc()->product('Ecs')->scheme('https')->version('2014-05-26')
                    ->action('DeleteInstance')->method('POST')->host($this->ecsHost($regionId))
                    ->options(['query' => ['RegionId' => $regionId, 'InstanceId' => $instanceId, 'Force' => true], 'connect_timeout' => 10.0, 'timeout' => 25.0])
                    ->request();
            }, 'deleteOrphanedInstance');
        } catch (\Exception $e) {
            error_log("Failed to clean up orphaned instance [{$instanceId}]: " . $e->getMessage());
        }
    }
}
