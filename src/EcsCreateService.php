<?php

class EcsCreateService
{
    private $db;
    private $configManager;
    private $ecsProvisionService;
    private $ddnsService;
    private $notificationService;

    public function __construct($db, $configManager, $ecsProvisionService, $ddnsService, $notificationService)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->ecsProvisionService = $ecsProvisionService;
        $this->ddnsService = $ddnsService;
        $this->notificationService = $notificationService;
    }

    public function previewEcsCreate($data): array
    {
        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        $preview = $this->ecsProvisionService->buildEcsCreatePreview($account, $data, $this->detectClientPublicIp());
        $previewId = 'preview_' . bin2hex(random_bytes(12));

        $this->db->addLog('info', "ECS 创建预检完成 [{$preview['account']['label']}] {$preview['regionId']} {$preview['instanceType']}");

        return [
            'success' => true,
            'previewId' => $previewId,
            'summary' => $preview,
            'pricing' => $preview['pricing'],
            'warnings' => $preview['warnings']
        ];
    }

    public function getEcsDiskOptions($data)
    {
        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        return [
            'success' => true,
            'data' => $this->ecsProvisionService->getAvailableSystemDiskOptions($account, $data)
        ];
    }

    public function createEcsFromPreview($previewId, array $preview): array
    {
        if (empty($preview['account']['groupKey'])) {
            throw new Exception('创建预检已失效，请重新预检');
        }

        $groupKey = $preview['account']['groupKey'];
        $account = $this->resolveAccountGroupForCreate($groupKey, $preview['regionId'] ?? '');
        $taskId = 'ecs_' . bin2hex(random_bytes(10));

        // 创建新 ECS 不应顺手拉起客户已有的停机实例。先把当前已停机实例视为“有意停机”，保活逻辑会跳过它们。
        $this->configManager->blockCurrentlyStoppedInstances();

        $this->db->createEcsCreateTask(
            $taskId,
            $previewId,
            $groupKey,
            $preview['regionId'],
            $preview['instanceType'],
            $preview
        );

        $progress = function ($step) use ($taskId) {
            $this->db->updateEcsCreateTask($taskId, ['step' => $step]);
        };

        try {
            $result = $this->ecsProvisionService->createManagedEcsFromPreview($account, $preview, $progress);
            $this->db->updateEcsCreateTask($taskId, [
                'zone_id' => $preview['zoneId'] ?? '',
                'image_id' => $preview['imageId'] ?? '',
                'os_label' => $preview['osLabel'] ?? '',
                'instance_name' => $preview['instanceName'] ?? '',
                'vpc_id' => $result['vpcId'] ?? '',
                'vswitch_id' => $result['vswitchId'] ?? '',
                'security_group_id' => $result['securityGroupId'] ?? '',
                'internet_max_bandwidth_out' => $result['internetMaxBandwidthOut'] ?? 0,
                'system_disk_category' => $result['systemDiskCategory'] ?? '',
                'system_disk_size' => $result['systemDiskSize'] ?? 0,
                'instance_id' => $result['instanceId'] ?? '',
                'public_ip' => $result['publicIp'] ?? '',
                'public_ip_mode' => $result['publicIpMode'] ?? 'ecs_public_ip',
                'eip_allocation_id' => $result['eipAllocationId'] ?? '',
                'eip_address' => $result['eipAddress'] ?? '',
                'eip_managed' => !empty($result['eipManaged']) ? 1 : 0,
                'login_user' => $result['loginUser'] ?? '',
                'login_password' => '',
                'status' => 'success',
                'step' => '创建完成'
            ]);

            $this->configManager->syncAccountGroups(true);
            $this->configManager->load();
            $createdAccount = $this->configManager->getAccountByInstanceId($result['instanceId'] ?? '');
            if ($createdAccount && (($result['publicIpMode'] ?? '') === 'eip')) {
                $this->configManager->updateAccountNetworkMetadata($createdAccount['id'], [
                    'public_ip' => $result['publicIp'] ?? '',
                    'public_ip_mode' => 'eip',
                    'eip_allocation_id' => $result['eipAllocationId'] ?? '',
                    'eip_address' => $result['eipAddress'] ?? '',
                    'eip_managed' => 1,
                    'internet_max_bandwidth_out' => $result['internetMaxBandwidthOut'] ?? 0
                ]);
            }
            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), "ECS 创建后");
            $createLabel = Helpers::getAccountLogLabel($account);
            $this->db->addLog('info', "一键创建 ECS成功 [{$createLabel}] {$result['instanceId']} {$preview['instanceType']} {$preview['regionId']} {$result['internetMaxBandwidthOut']}Mbps");
            $notifyResult = $this->notificationService->notifyEcsCreated(Helpers::getAccountLogLabel($account), $result, $preview);
            Helpers::logNotificationResult($this->db, $notifyResult, Helpers::getAccountLogLabel($account));

            return [
                'success' => true,
                'taskId' => $taskId,
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->db->updateEcsCreateTask($taskId, [
                'status' => 'failed',
                'step' => '创建失败',
                'error_message' => strip_tags($e->getMessage())
            ]);
            $failLabel = Helpers::getAccountLogLabel($account);
            $this->db->addLog('error', "一键创建 ECS 失败 [{$failLabel}]: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function getEcsCreateTask($taskId): ?array
    {
        return $this->db->getEcsCreateTask($taskId);
    }

    private function resolveAccountGroupForCreate($groupKey, $regionId = '')
    {
        $groups = $this->configManager->getAccountGroups();
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') !== $groupKey) {
                continue;
            }

            $resolvedRegion = trim((string) $regionId) ?: ($group['regionId'] ?? '');
            return [
                'id' => 0,
                'access_key_id' => $group['AccessKeyId'],
                'access_key_secret' => $group['AccessKeySecret'],
                'region_id' => $resolvedRegion,
                'group_key' => $group['groupKey'],
                'remark' => $group['remark'] ?? '',
                'site_type' => $group['siteType'] ?? 'international',
                'max_traffic' => (float) ($group['maxTraffic'] ?? 200),
                'instance_id' => '',
                'instance_name' => ''
            ];
        }

        throw new Exception('未找到对应账号，请先在账号管理中保存账号');
    }

    private function detectClientPublicIp()
    {
        // Only trust Cloudflare headers when behind Cloudflare (REMOTE_ADDR is a CF IP)
        $fromCf = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

        $candidates = [];
        if ($fromCf && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        foreach (['HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates[] = trim((string) $_SERVER[$key]);
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $item) {
                $candidates[] = trim($item);
            }
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        $context = stream_context_create(['http' => ['timeout' => 3]]);
        $externalIp = @file_get_contents('https://api.ipify.org', false, $context);
        if ($externalIp === false) return '';
        $externalIp = trim((string) $externalIp);
        if (filter_var($externalIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $externalIp;
        }

        return '';
    }
}
