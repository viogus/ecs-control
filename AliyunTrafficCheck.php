<?php

require 'vendor/autoload.php';
require_once 'Database.php';
require_once 'ConfigManager.php';
require_once 'AliyunService.php';
require_once 'NotificationService.php';
require_once 'DdnsService.php';
require_once 'TelegramControlService.php';

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class AliyunTrafficCheck
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $notificationService;
    private $ddnsService;
    private $responseBuilder;
    private $instanceActionService;
    private $initError = null;



    public function __construct()
    {
        try {
            $this->db = new Database();
            $this->configManager = new ConfigManager($this->db);
            $this->aliyunService = new AliyunService();
            $this->notificationService = new NotificationService();
            $this->ddnsService = new DdnsService($this->configManager->getAllSettings(), $this->db, $this->configManager);
            $this->responseBuilder = new FrontendResponseBuilder(
                $this->configManager, $this->db, $this->aliyunService
            );
            $this->instanceActionService = new InstanceActionService(
                $this->aliyunService, $this->configManager, $this->db,
                $this->notificationService, $this->ddnsService
            );

            // 注入配置到通知服务
            $this->notificationService->setConfig($this->configManager->getAllSettings());

        } catch (Exception $e) {
            $this->initError = $e->getMessage();
        }
    }

    public function getInitError()
    {
        return $this->initError;
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    public function getConfigManager(): ConfigManager
    {
        return $this->configManager;
    }

    public function isInitialized()
    {
        if ($this->initError)
            return false;
        return $this->configManager->isInitialized();
    }

    public function getAdminPassword()
    {
        return $this->configManager->get('admin_password', '');
    }

    public function getMonitorKey()
    {
        $key = $this->configManager->get('monitor_key', '');
        if (empty($key)) {
            $key = bin2hex(random_bytes(32));
            $this->configManager->saveMonitorKey($key);
        }
        return $key;
    }

    public function getPublicBrand()
    {
        if ($this->initError) {
            return ['logo_url' => ''];
        }

        return [
            'logo_url' => $this->configManager->get('app_logo_url', '')
        ];
    }

    public function login($password)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }

        $attempts = $this->db->getRecentFailedAttempts($ip, 900);
        if ($attempts >= 5) {
            $this->db->addLog('warning', "登录被锁定: 地址 {$ip} 尝试次数过多");
            throw new Exception("错误次数过多，请 15 分钟后再试。");
        }

        $adminPass = $this->getAdminPassword();
        if (empty($adminPass))
            return false;

        $passwordValid = false;

        if ($this->isPasswordHashed($adminPass)) {
            $passwordValid = password_verify($password, $adminPass);
        } else {
            $passwordValid = hash_equals($adminPass, $password);
            if ($passwordValid) {
                $this->configManager->upgradePasswordHash($password);
            }
        }

        if ($passwordValid) {
            $this->db->clearLoginAttempts($ip);
            $this->db->addLog('info', "管理员登录成功 [地址: {$ip}]");
            return true;
        }

        $this->db->recordLoginAttempt($ip);
        $this->db->addLog('warning', "管理员登录失败 [地址: {$ip}]");
        return false;
    }

    private function isPasswordHashed($password)
    {
        return preg_match('/^\$2[aby]?\$/', $password) === 1 || preg_match('/^\$argon2[aid]\$/', $password) === 1;
    }


    private function resolveSecretFromDatabase($accessKeyId, $regionId, $groupKey = '')
    {
        $pdo = $this->db->getPdo();
        $groupKey = trim((string) $groupKey);

        if ($groupKey !== '') {
            $stmt = $pdo->prepare("SELECT access_key_secret FROM accounts WHERE group_key = ? LIMIT 1");
            $stmt->execute([$groupKey]);
            $row = $stmt->fetch();

            if ($row && !empty($row['access_key_secret'])) {
                $secret = $this->configManager->decryptAccountSecret($row['access_key_secret']);
                if (!empty($secret)) {
                    return $secret;
                }
            }
        }

        $stmt = $pdo->prepare("SELECT access_key_secret FROM accounts WHERE access_key_id = ? AND region_id = ? LIMIT 1");
        $stmt->execute([$accessKeyId, $regionId]);
        $row = $stmt->fetch();

        if ($row && !empty($row['access_key_secret'])) {
            $secret = $this->configManager->decryptAccountSecret($row['access_key_secret']);
            if (!empty($secret)) {
                return $secret;
            }
        }

        foreach ($this->configManager->getAccountGroups() as $group) {
            if (
                (
                    ($groupKey !== '' && ($group['groupKey'] ?? '') === $groupKey)
                    || (($group['AccessKeyId'] ?? '') === $accessKeyId && ($group['regionId'] ?? '') === $regionId)
                )
                && !empty($group['AccessKeySecret'])
                && $group['AccessKeySecret'] !== '********'
            ) {
                return $group['AccessKeySecret'];
            }
        }

        throw new Exception('无法读取该账号的AK Secret，请重新输入后保存');
    }

    public function setup($data)
    {
        if ($this->initError)
            throw new Exception($this->initError);
        if ($this->isInitialized())
            return false;
        return $this->configManager->updateConfig($data);
    }

    public function updateConfig($data)
    {
        $success = $this->configManager->updateConfig($data);
        if ($success) {
            $this->notificationService->setConfig($this->configManager->getAllSettings());
        }
        return $success;
    }

    public function uploadLogo(array $file)
    {
        if ($this->initError) {
            return ['success' => false, 'message' => $this->initError];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Logo 上传失败，请重新选择图片'];
        }

        if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Logo 图片大小需小于 2MB'];
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'message' => 'Logo 文件无效'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp'
        ];

        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => '仅支持 PNG、JPG、WebP 图片'];
        }

        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['success' => false, 'message' => 'Logo 存储目录不可写'];
        }

        foreach (glob($dir . '/brand-logo.*') ?: [] as $oldFile) {
            @unlink($oldFile);
        }

        $target = $dir . '/brand-logo.' . $allowed[$mime];
        if (!@move_uploaded_file($tmp, $target)) {
            return ['success' => false, 'message' => 'Logo 保存失败，请检查 data 目录权限'];
        }

        @chmod($target, 0644);
        $url = 'index.php?action=brand_logo&v=' . filemtime($target);
        $this->configManager->updateAppLogoUrl($url);
        $this->db->addLog('info', '页面 Logo 已更新');

        return ['success' => true, 'url' => $url];
    }

    public function getConfigForFrontend()
    {
        if ($this->initError) return [];
        return $this->responseBuilder->getConfigForFrontend();
    }
    // --- 修改：支持按 Tab 获取日志 ---
    public function getSystemLogs($tab = 'action')
    {
        if ($this->initError)
            return [];

        if ($tab === 'heartbeat') {
            // 心跳日志：只看 heartbeat 类型
            $types = ['heartbeat'];
        } else {
            // 动作日志：只看 info 和 warning，排除 error (超时/接口错误)
            $types = ['info', 'warning'];
        }

        // 仅返回最近 20 条
        $logs = $this->db->getLogsByTypes($types, 20);
        $accounts = $this->configManager->getAccounts();
        $accessKeyMap = [];

        foreach ($accounts as $account) {
            $label = Helpers::getAccountLogLabel($account);
            $accessKeyId = trim((string) ($account['access_key_id'] ?? ''));
            if ($accessKeyId === '') {
                continue;
            }

            $accessKeyMap[$accessKeyId] = $label;
            $accessKeyMap[substr($accessKeyId, 0, 7) . '***'] = $label;
        }

        foreach ($logs as &$log) {
            foreach ($accessKeyMap as $key => $label) {
                $log['message'] = str_replace("[$key]", "[$label]", $log['message']);
                $log['message'] = str_replace($key, $label, $log['message']);
            }
            $log['time_str'] = date('Y-m-d H:i:s', $log['created_at']);
        }
        return $logs;
    }

    // --- 新增：清空日志并重排 ID ---
    public function clearSystemLogs($tab = 'action')
    {
        if ($this->initError)
            return false;

        $result = false;
        if ($tab === 'heartbeat') {
            $result = $this->db->clearLogsByTypes(['heartbeat']);
        } else {
            $result = $this->db->clearLogsByTypes(['info', 'warning', 'error']);
        }

        // 关键改动：清空后立即重排剩余 ID
        if ($result) {
            $this->db->reorderLogsIds();
        }

        return $result;
    }

    public function getAccountHistory($id)
    {
        if ($this->initError)
            return [];

        $account = $this->configManager->getAccountById($id);
        if (!$account)
            return ['error' => 'Account not found'];

        // Use account ID for stats query
        $rawHourly = $this->db->getHourlyStats($id);
        $chartHourly = [];
        foreach ($rawHourly as $row) {
            $chartHourly[] = [
                'time' => date('H:00', $row['recorded_at']),
                'full_time' => date('Y-m-d H:i', $row['recorded_at']),
                'value' => round($row['traffic'], 3)
            ];
        }

        $rawDaily = $this->db->getDailyStats($id);
        $chartDaily = [];
        foreach ($rawDaily as $row) {
            $chartDaily[] = [
                'date' => date('Y-m-d', $row['recorded_at']),
                'value' => round($row['traffic'], 3)
            ];
        }

        return [
            'history_24h' => $chartHourly,
            'history_30d' => $chartDaily
        ];
    }

    // --- 核心监控逻辑 ---

    public function monitor()
    {
        if ($this->initError) return "错误: " . $this->initError;
        $monitor = new MonitorService($this->db, $this->configManager, $this->aliyunService, $this->notificationService, $this->ddnsService);
        $result = $monitor->run();
        $this->instanceActionService->processPendingReleases(function($label, $account) {
            $notifyResult = $this->notificationService->notifyInstanceReleased(
                $label, $account, '用户前端提交指令后，后台成功执行安全彻底销毁。'
            );
            if ($notifyResult === true) {
                $this->db->addLog('info', "通知推送成功 [$label]");
            } elseif ($notifyResult !== false && $notifyResult !== true) {
                $this->db->addLog('warning', "通知推送异常/失败 [$label]: " . strip_tags($notifyResult));
            }
        });
        $this->processTelegramControl();
        return $result;
    }
    private function processTelegramControl()
    {
        try {
            $service = new TelegramControlService($this->db, $this->configManager, $this);
            $service->processUpdates();
        } catch (\Exception $e) {
            $this->db->addLog('error', 'Telegram 控制处理失败: ' . strip_tags($e->getMessage()));
        }
    }

    public function getStatusForFrontend($includeSensitive = false)
    {
        if ($this->initError)
            return ['error' => $this->initError];
        return $this->responseBuilder->getStatusForFrontend($includeSensitive);
    }

    public function refreshAccount($id)
    {
        if ($this->initError) return false;
        return $this->instanceActionService->refreshAccount($id);
    }

    public function fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        if (empty($accessKeyId) || empty($accessKeySecret)) {
            throw new Exception('请先填写AK ID和AK Secret');
        }

        try {
            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret, $regionId ?: null);
            $maskedKey = substr($accessKeyId, 0, 7) . '***';
            $this->db->addLog('info', "实例列表获取成功 [{$maskedKey}] 共 " . count($instances) . " 台");
            return $instances;
        } catch (ClientException $e) {
            $this->db->addLog('warning', "实例列表获取失败: 鉴权错误");
            throw new Exception('阿里云鉴权失败，请检查AK权限或密钥是否正确');
        } catch (ServerException $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . $e->getErrorCode() . " - " . strip_tags($e->getErrorMessage()));
            throw new Exception('阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage());
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function testAccountCredentials($account)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        $accessKeyId = trim((string) ($account['AccessKeyId'] ?? ''));
        $accessKeySecret = trim((string) ($account['AccessKeySecret'] ?? ''));
        $regionId = trim((string) ($account['regionId'] ?? ''));
        $maxTraffic = (float) ($account['maxTraffic'] ?? 0);
        $accountLabel = trim((string) ($account['remark'] ?? '')) ?: (substr($accessKeyId, 0, 7) . '***');

        if ($accessKeyId === '' || $accessKeySecret === '' || $regionId === '') {
            throw new Exception('请先填写完整的AK、区域和账号流量');
        }

        if ($accessKeySecret === '********') {
            $accessKeySecret = $this->resolveSecretFromDatabase($accessKeyId, $regionId, $account['groupKey'] ?? '');
        }

        try {
            $regions = $this->aliyunService->getRegions($accessKeyId, $accessKeySecret);
            $regionIds = array_column($regions, 'regionId');
            if (!in_array($regionId, $regionIds, true)) {
                throw new Exception('当前AK无法访问所选区域，请检查权限范围');
            }

            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret);
            $regionInstances = array_values(array_filter($instances, function ($instance) use ($regionId) {
                return ($instance['regionId'] ?? '') === $regionId;
            }));
            $instanceCount = count($regionInstances);

            $monitorWarning = '';
            $monitorChecked = false;
            try {
                $cdtTraffic = $this->aliyunService->getTraffic($accessKeyId, $accessKeySecret, $regionId);
                $monitorChecked = true;
            } catch (\Exception $e) {
                $monitorWarning = 'CDT 流量查询未通过：' . strip_tags($e->getMessage());
                $this->db->addLog('warning', "账号 CDT 探测异常 [{$accountLabel}]: {$monitorWarning}");
            }

            $trafficUsed = (float) ($account['usageUsed'] ?? 0);
            $trafficRemaining = max(round($maxTraffic - $trafficUsed, 2), 0);
            $trafficPercent = $maxTraffic > 0 ? min(round(($trafficUsed / $maxTraffic) * 100, 2), 100) : 0;
            $this->db->addLog('info', "账号测试成功 [{$accountLabel}] {$regionId} 实例 {$instanceCount} 台");
            $message = 'AK可用，ECS API已接通';
            if ($monitorWarning !== '') {
                $message .= '；' . $monitorWarning;
            } else {
                $message .= '，CDT 接口已接通';
            }

            return [
                'success' => true,
                'message' => $message,
                'monitorWarning' => $monitorWarning,
                'monitorStatus' => $monitorWarning !== '' ? 'warning' : 'ok',
                'monitorMessage' => $monitorWarning !== '' ? $monitorWarning : 'CDT 接口已接通，可获取账号出口流量。',
                'usageUsed' => $trafficUsed,
                'usageRemaining' => $trafficRemaining,
                'usagePercent' => $trafficPercent,
                'instanceCount' => $instanceCount
            ];
        } catch (ClientException $e) {
            $message = '鉴权失败，请检查AK ID和AK Secret是否正确，或确认是否具备ECS 权限';
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (ServerException $e) {
            $message = '阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage();
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (Exception $e) {
            $this->db->addLog('warning', "账号测试失败: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function previewEcsCreate($data)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        $preview = $this->aliyunService->buildEcsCreatePreview($account, $data, $this->detectClientPublicIp());
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
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        return [
            'success' => true,
            'data' => $this->aliyunService->getAvailableSystemDiskOptions($account, $data)
        ];
    }

    public function createEcsFromPreview($previewId, array $preview)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

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
            $result = $this->aliyunService->createManagedEcsFromPreview($account, $preview, $progress);
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
            $this->db->addLog('info', "一键创建 ECS成功 [{Helpers::getAccountLogLabel($account)}] {$result['instanceId']} {$preview['instanceType']} {$preview['regionId']} {$result['internetMaxBandwidthOut']}Mbps");
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
            $this->db->addLog('error', "一键创建 ECS 失败 [{Helpers::getAccountLogLabel($account)}]: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function syncAccountGroup($groupKey)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        // syncAccountGroups reconciles the full configured set, so use all groups here
        // and filter refresh work to the clicked group afterwards.
        $accountsBeforeSync = $this->configManager->getAccounts();
        $this->configManager->syncAccountGroups(true);
        $this->configManager->load();

        $threshold = (int) ($this->configManager->get('traffic_threshold', 95) ?? 95);
        $userInterval = (int) ($this->configManager->get('api_interval', 600) ?? 600);
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        $instanceCount = 0;

        foreach ($this->configManager->getAccounts() as $account) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            if ($accountGroupKey !== $groupKey || empty($account['instance_id'])) {
                continue;
            }

            $this->responseBuilder->buildInstanceSnapshot($account, $threshold, $userInterval, $billingEnabled, true, true);
            $instanceCount++;
        }

        if ($billingEnabled) {
            $this->responseBuilder->getAccountGroupBillingMetrics(true);
        }

        $this->configManager->load();
        $syncedAccounts = array_values(array_filter($this->configManager->getAccounts(), function ($account) use ($groupKey) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            return $accountGroupKey === $groupKey && !empty($account['instance_id']);
        }));
        $this->ddnsService->reconcileAfterSync($accountsBeforeSync, $this->configManager->getAccounts(), '账号同步');
        $this->db->addLog('info', "账号同步完成 [{$targetGroup['remark']}] {$targetGroup['regionId']} 实例 {$instanceCount} 台");

        $trafficIssue = $this->summarizeTrafficIssueForAccounts($syncedAccounts);
        $message = "已同步 {$instanceCount} 台实例，流量和消费情况已刷新";
        if ($trafficIssue !== '') {
            $message .= '；' . $trafficIssue;
        }

        return [
            'success' => true,
            'message' => $message,
            'instanceCount' => $instanceCount,
            'trafficIssue' => $trafficIssue
        ];
    }

    public function restoreScheduleAfterTrafficBlock($groupKey)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        $this->configManager->restoreScheduleAfterTrafficBlock($groupKey);
        $this->db->addLog('info', "已手动恢复定时开关机 [{$targetGroup['remark']}] {$targetGroup['regionId']}");

        return [
            'success' => true,
            'message' => '定时开关机已恢复。请确认本月流量未继续超过阈值，否则下一轮监控仍会触发保护。'
        ];
    }

    private function summarizeTrafficIssueForAccounts(array $accounts)
    {
        if (empty($accounts)) {
            return '';
        }

        $statuses = [];
        foreach ($accounts as $account) {
            $status = trim((string) ($account['traffic_api_status'] ?? 'ok'));
            if ($status !== '' && $status !== 'ok') {
                $statuses[$status] = true;
            }
        }

        if (empty($statuses)) {
            return '';
        }

        if (isset($statuses['auth_error'])) {
            return '部分账号 CDT 鉴权失败，请检查 AK 权限配置';
        }

        if (isset($statuses['timeout'])) {
            return '部分账号 CDT 请求超时，请稍后重试';
        }

        return '部分账号流量同步失败，请稍后重试';
    }

    public function getEcsCreateTask($taskId)
    {
        if ($this->initError) {
            return null;
        }

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
        $candidates = [];
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
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
        $externalIp = trim((string) $externalIp);
        if (filter_var($externalIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $externalIp;
        }

        return '';
    }

    public function controlInstanceAction($accountId, $action, $shutdownMode = 'KeepCharging', $waitForSync = true)
    {
        if ($this->initError) return false;
        return $this->instanceActionService->controlInstance($accountId, $action, $shutdownMode, $waitForSync, [$this, 'notifyStatusChangeIfNeeded']);
    }

    public function deleteInstanceAction($accountId, $forceStop = false)
    {
        if ($this->initError) return false;
        return $this->instanceActionService->deleteInstance($accountId);
    }

    public function replaceInstanceIpAction($accountId)
    {
        if ($this->initError) return ['success' => false, 'message' => $this->initError];
        return $this->instanceActionService->replaceInstanceIp($accountId);
    }

    public function getAllManagedInstances($sync = false)
    {
        if ($this->initError) return [];
        return $this->instanceActionService->getAllManagedInstances($sync, [$this->responseBuilder, 'buildInstanceSnapshot']);
    }

    // 供 controlInstanceAction 回调使用，主监控循环的完整版本在 MonitorService 中
    public function notifyStatusChangeIfNeeded($account, $fromStatus, $toStatus, $reason = '')
    {
        $fromStatus = (string) ($fromStatus ?: 'Unknown');
        $toStatus = (string) ($toStatus ?: 'Unknown');
        if ($fromStatus === $toStatus || !in_array($toStatus, ['Running', 'Stopped'], true)) return;
        if ($fromStatus === 'Unknown') return;
        $accountLabel = Helpers::getAccountLogLabel($account);
        $result = $this->notificationService->notifyInstanceStatusChanged($accountLabel, $account, $fromStatus, $toStatus, $reason);
        if ($result === true) {
            $this->db->addLog('info', "通知推送成功 [$accountLabel]");
        } elseif ($result !== false && $result !== true) {
            $this->db->addLog('warning', "通知推送异常/失败 [$accountLabel]: " . strip_tags($result));
        }
    }

    public function sendTestEmail($to)
    {
        return $this->notificationService->sendTestEmail($to);
    }

    public function sendTestTelegram($data)
    {
        return $this->notificationService->sendTestTelegram($data);
    }

    public function sendTestWebhook($data)
    {
        return $this->notificationService->sendTestWebhook($data);
    }

    public function renderTemplate()
    {
        if (!file_exists('template.html'))
            return "File not found";
        ob_start();
        include 'template.html';
        return ob_get_clean();
    }
}
