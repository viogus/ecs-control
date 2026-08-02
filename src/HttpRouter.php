<?php

declare(strict_types=1);

class HttpRouter
{
    private $app;
    private HttpRequest $request;
    private string $baseDir;

    /** @var string[] */
    private array $mutatingActions = [
        'save_config',
        'upload_logo',
        'send_test_email',
        'send_test_telegram',
        'send_test_webhook',
        'refresh_account',
        'fetch_instances',
        'test_account',
        'sync_account_group',
        'restore_schedule_block',
        'preview_ecs_create',
        'get_ecs_disk_options',
        'create_ecs',
        'clear_logs',
        'control_instance',
        'delete_instance',
        'replace_instance_ip',
        'allocate_ipv6',
        'release_ipv6',
        'logout',
        'export',
    ];

    public function __construct($app, HttpRequest $request, ?string $baseDir = null)
    {
        $this->app = $app;
        $this->request = $request;
        $this->baseDir = $baseDir ?? dirname(__DIR__);
    }

    public function dispatch(): void
    {
        $action = $this->request->getAction();

        if ($this->dispatchPublic($action)) {
            return;
        }

        if ($action !== 'view' && !$this->isLoggedIn()) {
            $this->json(['error' => '请先登录后再操作'], 403);
            return;
        }

        if (in_array($action, $this->mutatingActions, true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->logSecurityEvent('CSRF: method not POST', $action);
                $this->json(['error' => 'Method not allowed'], 405);
                return;
            }
            if (!$this->requireCsrf()) {
                $this->logSecurityEvent('CSRF: token mismatch', $action);
                return;
            }
        }

        try {
            if ($this->dispatchAuthenticated($action)) {
                return;
            }
        } catch (\Throwable $e) {
            // 统一兜底:未捕获异常返回 JSON 错误而非 500 空白页,并记录日志
            $this->app->getDb()->addLog('error', "请求处理异常 [{$action}]: " . strip_tags($e->getMessage()));
            $this->json(['error' => '服务器内部错误，请查看日志'], 500);
            return;
        }

        echo $this->app->renderTemplate();
    }

    /**
     * 安全事件(CSRF 拒绝等)记录到 PHP 错误日志与数据库日志表,
     * 数据库不可用时静默降级,不阻断请求。
     */
    private function logSecurityEvent(string $message, string $action): void
    {
        $detail = sprintf('%s [action=%s ip=%s]', $message, $action, $this->request->getClientIp());
        error_log($detail);
        try {
            $db = $this->app->getDb();
            if ($db) {
                $db->addLog('warning', $detail);
            }
        } catch (\Throwable $e) {
            // 数据库不可用时仅保留 PHP 错误日志
        }
    }

    public function json(array $payload, int $status = 200, int $flags = 0, bool $withCharset = false): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }

        header('Content-Type: application/json' . ($withCharset ? '; charset=utf-8' : ''));
        echo json_encode($payload, $flags);
    }

    public function ensureCsrfToken(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function rotateCsrfToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public function requireCsrf(): bool
    {
        $this->ensureCsrfToken();
        $header = $this->request->getHeader('X-CSRF-TOKEN', '');
        if (empty($header) || !hash_equals($_SESSION['csrf_token'], $header)) {
            $this->json(['error' => 'CSRF 验证失败，请刷新页面后重试'], 403);
            return false;
        }

        return true;
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['is_admin']);
    }

    private function isAdminStrict(): bool
    {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }

    private function dispatchPublic(string $action): bool
    {
        if ($action === 'check_init') {
            $this->handleCheckInit();
            return true;
        }
        if ($action === 'setup') {
            $this->handleSetup();
            return true;
        }
        if ($action === 'login') {
            $this->handleLogin();
            return true;
        }
        if ($action === 'check_login') {
            $this->handleCheckLogin();
            return true;
        }
        if ($action === 'brand_logo') {
            $this->handleBrandLogo();
            return true;
        }

        return false;
    }

    private function dispatchAuthenticated(string $action): bool
    {
        if ($action === 'export') {
            $this->handleExport();
            return true;
        }
        if ($action === 'get_config') {
            $this->handleGetConfig();
            return true;
        }
        if ($action === 'save_config') {
            $this->handleSaveConfig();
            return true;
        }
        if ($action === 'upload_logo') {
            $this->handleUploadLogo();
            return true;
        }
        if ($action === 'send_test_email') {
            $this->handleSendTestEmail();
            return true;
        }
        if ($action === 'send_test_telegram') {
            $this->handleSendTestTelegram();
            return true;
        }
        if ($action === 'send_test_webhook') {
            $this->handleSendTestWebhook();
            return true;
        }
        if ($action === 'refresh_account') {
            $this->handleRefreshAccount();
            return true;
        }
        if ($action === 'fetch_instances') {
            // 预留 action:已被 test_account 取代,前端未调用,保留以兼容旧客户端/测试
            $this->handleFetchInstances();
            return true;
        }
        if ($action === 'test_account') {
            $this->handleTestAccount();
            return true;
        }
        if ($action === 'sync_account_group') {
            $this->handleSyncAccountGroup();
            return true;
        }
        if ($action === 'restore_schedule_block') {
            $this->handleRestoreScheduleBlock();
            return true;
        }
        if ($action === 'preview_ecs_create') {
            $this->handlePreviewEcsCreate();
            return true;
        }
        if ($action === 'get_ecs_disk_options') {
            $this->handleGetEcsDiskOptions();
            return true;
        }
        if ($action === 'create_ecs') {
            $this->handleCreateEcs();
            return true;
        }
        if ($action === 'get_ecs_create_task') {
            // 预留 action:ECS 创建当前为同步流程,前端直接消费创建返回;保留供异步任务轮询扩展
            $this->handleGetEcsCreateTask();
            return true;
        }
        if ($action === 'get_logs') {
            $this->handleGetLogs();
            return true;
        }
        if ($action === 'clear_logs') {
            $this->handleClearLogs();
            return true;
        }
        if ($action === 'get_history') {
            // 预留 action:前端暂无入口,保留供账号历史用量图表扩展
            $this->handleGetHistory();
            return true;
        }
        if ($action === 'logout') {
            $this->handleLogout();
            return true;
        }
        if ($action === 'get_all_instances') {
            $this->handleGetAllInstances();
            return true;
        }
        if ($action === 'control_instance') {
            $this->handleControlInstance();
            return true;
        }
        if ($action === 'delete_instance') {
            $this->handleDeleteInstance();
            return true;
        }
        if ($action === 'replace_instance_ip') {
            $this->handleReplaceInstanceIp();
            return true;
        }
        if ($action === 'allocate_ipv6') {
            $this->handleAllocateIpv6();
            return true;
        }
        if ($action === 'release_ipv6') {
            $this->handleReleaseIpv6();
            return true;
        }
        if ($action === 'get_status') {
            $this->handleGetStatus();
            return true;
        }

        return false;
    }

    private function handleCheckInit(): void
    {
        $initError = $this->app->getInitError();
        if ($initError) {
            $this->json(['initialized' => false, 'error' => $initError]);
            return;
        }

        $this->json([
            'initialized' => $this->app->isInitialized(),
            'brand' => $this->app->getPublicBrand(),
        ]);
    }

    private function handleSetup(): void
    {
        if ($this->app->isInitialized()) {
            $this->json(['success' => false, 'message' => '系统已完成初始化'], 403);
            return;
        }

        $data = $this->request->getJsonBody();
        try {
            // 一次性安装令牌校验,防止任意访客抢占初始化
            $expected = $this->app->getConfigManager()->getSetupToken();
            if ($expected === '' || !hash_equals($expected, (string) ($data['setup_token'] ?? ''))) {
                $this->json(['success' => false, 'message' => '安装令牌错误，请查看容器启动日志中的 SETUP_TOKEN'], 403);
                return;
            }
            if ($this->app->getAuthManager()->setup($data)) {
                $this->app->getConfigManager()->clearSetupToken();
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $this->rotateCsrfToken();
                $this->json(['success' => true]);
            } else {
                $this->json(['success' => false, 'message' => '初始化失败']);
            }
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function handleLogin(): void
    {
        $data = $this->request->getJsonBody();
        try {
            if ($this->app->getAuthManager()->login($data['password'] ?? '', $this->request->getClientIp())) {
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $this->rotateCsrfToken();
                $this->json(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
            } else {
                $this->json(['success' => false, 'message' => '密码错误']);
            }
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function handleCheckLogin(): void
    {
        $isLoggedIn = $this->isAdminStrict();
        $response = ['logged_in' => $isLoggedIn];
        if ($isLoggedIn) {
            $this->ensureCsrfToken();
            $response['csrf_token'] = $_SESSION['csrf_token'];
        }

        $this->json($response);
    }

    private function handleBrandLogo(): void
    {
        $dir = $this->baseDir . '/data';
        $files = array_merge(
            glob($dir . '/brand-logo.png') ?: [],
            glob($dir . '/brand-logo.jpg') ?: [],
            glob($dir . '/brand-logo.webp') ?: []
        );
        $file = $files[0] ?? '';
        if ($file === '' || !is_file($file)) {
            http_response_code(404);
            return;
        }

        $mimeMap = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
    }

    private function handleGetStatus(): void
    {
        $initError = $this->app->getInitError();
        if ($initError) {
            $this->json(['error' => $initError], 200, 0, true);
            return;
        }

        // 显式 sync=true 视为写操作:强制刷新实例数据,需 POST + CSRF;
        // 普通 GET 仅返回节流缓存的只读状态,避免 GET 触发写副作用
        $input = $this->request->getJsonBody();
        $forceSync = ($input['sync'] ?? false) === true || $this->request->getQueryParam('sync') === '1';
        if ($forceSync) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->json(['error' => 'Method not allowed'], 405, 0, true);
                return;
            }
            if (!$this->requireCsrf()) {
                return;
            }
        }

        $this->json($this->app->getResponseBuilder()->getStatusForFrontend(true, $forceSync), 200, 0, true);
    }

    private function handleExport(): void
    {
        try {
            $body = $this->request->getJsonBody();
            // 默认脱敏导出;完整备份(full=1)必须显式声明且通过管理员密码二次认证
            $wantFull = $this->request->getQueryParam('full') === '1'
                || ($body['full'] ?? false) === true;

            if ($wantFull) {
                $password = (string) ($body['password'] ?? '');
                if ($password === '' || !$this->app->getAuthManager()->verifyPassword($password)) {
                    $this->json(['success' => false, 'message' => '完整备份需要验证管理员密码'], 403, 0, true);
                    return;
                }
            }

            $this->json($this->app->getExportService()->export(!$wantFull), 200, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleGetConfig(): void
    {
        $this->ensureCsrfToken();
        $config = $this->app->getResponseBuilder()->getConfigForFrontend();
        $config['csrf_token'] = $_SESSION['csrf_token'];
        $this->json($config);
    }

    private function handleSaveConfig(): void
    {
        $data = $this->request->getJsonBody();
        try {
            $success = $this->app->getConfigManager()->updateConfig($data);
            if ($success) {
                $this->app->getNotificationService()->setConfig($this->app->getConfigManager()->getAllSettings());
                $this->app->getDdnsService()->setConfig($this->app->getConfigManager()->getAllSettings());
                $this->json(['success' => true]);
            } else {
                $this->json(['success' => false, 'message' => '保存失败']);
            }
        } catch (Exception $e) {
            $this->app->getDb()->addLog('error', '保存配置异常: ' . strip_tags($e->getMessage()));
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function handleUploadLogo(): void
    {
        $this->json($this->app->getAdminSupportService()->uploadLogo($this->request->getFile('logo') ?? []), 200, 0, true);
    }

    private function handleSendTestEmail(): void
    {
        $data = $this->request->getJsonBody();
        $result = $this->app->getAdminSupportService()->sendTestEmail($data['email'] ?? '');
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleSendTestTelegram(): void
    {
        $data = $this->request->getJsonBody();
        $result = $this->app->getAdminSupportService()->sendTestTelegram($data['telegram'] ?? []);
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleSendTestWebhook(): void
    {
        $data = $this->request->getJsonBody();
        $result = $this->app->getAdminSupportService()->sendTestWebhook($data['webhook'] ?? []);
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleRefreshAccount(): void
    {
        $data = $this->request->getJsonBody();
        $id = $data['id'] ?? 0;
        $result = $this->app->getInstanceActionService()->refreshAccount($id);
        if ($result === false) {
            $this->json(['success' => false, 'message' => '刷新失败']);
        } elseif (is_array($result)) {
            $this->json($result);
        } else {
            $this->json(['success' => true]);
        }
    }

    private function handleFetchInstances(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $instances = $this->app->getAccountGroupOperationService()->fetchInstances($data['accessKeyId'] ?? '', $data['accessKeySecret'] ?? '', $data['regionId'] ?? '');
            $this->json(['success' => true, 'data' => $instances], 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleTestAccount(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $this->json($this->app->getAccountGroupOperationService()->testAccountCredentials($data['account'] ?? []), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handleSyncAccountGroup(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $this->json($this->app->getAccountGroupOperationService()->syncAccountGroup($data['groupKey'] ?? ''), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handleRestoreScheduleBlock(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $this->json($this->app->getAccountGroupOperationService()->restoreScheduleAfterTrafficBlock($data['groupKey'] ?? ''), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handlePreviewEcsCreate(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $result = $this->app->getEcsCreateService()->previewEcsCreate($data);
            $_SESSION['ecs_create_previews'] = $_SESSION['ecs_create_previews'] ?? [];
            // 只保留 15 分钟内有效的预检,防止反复预检导致 session 膨胀
            foreach ($_SESSION['ecs_create_previews'] as $pid => $store) {
                if ((time() - ($store['created_at'] ?? 0)) > 900) {
                    unset($_SESSION['ecs_create_previews'][$pid]);
                }
            }
            $_SESSION['ecs_create_previews'][$result['previewId']] = [
                'summary' => $result['summary'],
                'created_at' => time(),
            ];
            $this->json($result, 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleGetEcsDiskOptions(): void
    {
        $data = $this->request->getJsonBody();

        try {
            $this->json($this->app->getEcsCreateService()->getEcsDiskOptions($data), 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleCreateEcs(): void
    {
        $data = $this->request->getJsonBody();
        $previewId = $data['previewId'] ?? '';
        $confirmed = !empty($data['confirmed']);

        try {
            if (!$confirmed) {
                throw new Exception('请先确认配置清单和费用提示');
            }
            $previewStore = $_SESSION['ecs_create_previews'][$previewId] ?? null;
            if (!$previewStore || (time() - ($previewStore['created_at'] ?? 0)) > 900) {
                throw new Exception('配置清单已过期，请重新预检');
            }

            $result = $this->app->getEcsCreateService()->createEcsFromPreview($previewId, $previewStore['summary']);
            unset($_SESSION['ecs_create_previews'][$previewId]);
            $this->json($result, 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleGetEcsCreateTask(): void
    {
        $taskId = $this->request->getQueryParam('taskId', '');
        $task = $this->app->getEcsCreateService()->getEcsCreateTask($taskId);
        if (!$task) {
            $this->json(['success' => false, 'message' => '任务不存在'], 404, 0, true);
            return;
        }

        $allowed = ['task_id', 'preview_id', 'account_group_key', 'region_id', 'zone_id',
            'instance_type', 'image_id', 'os_label', 'instance_name', 'vpc_id', 'vswitch_id',
            'security_group_id', 'internet_max_bandwidth_out', 'system_disk_category',
            'system_disk_size', 'instance_id', 'public_ip', 'public_ip_mode',
            'eip_allocation_id', 'eip_address', 'eip_managed', 'login_user',
            'status', 'step', 'error_message', 'payload', 'created_at', 'updated_at'];
        $safe = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $task)) {
                $safe[$col] = $task[$col];
            }
        }
        $this->json(['success' => true, 'data' => $safe], 200, 0, true);
    }

    private function handleGetLogs(): void
    {
        $tab = $this->request->getQueryParam('tab', 'action');
        $this->json(['data' => $this->app->getAdminSupportService()->getSystemLogs($tab)], 200, 0, true);
    }

    private function handleClearLogs(): void
    {
        $data = $this->request->getJsonBody();
        $tab = $data['tab'] ?? 'action';
        if ($this->app->getAdminSupportService()->clearSystemLogs($tab)) {
            $this->json(['success' => true]);
        } else {
            $this->json(['success' => false, 'message' => '清空失败']);
        }
    }

    private function handleGetHistory(): void
    {
        $id = $this->request->getQueryParam('id', 0);
        $this->json(['data' => $this->app->getAdminSupportService()->getAccountHistory($id)], 200, 0, true);
    }

    private function handleLogout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->json(['success' => true]);
    }

    private function handleGetAllInstances(): void
    {
        $input = $this->request->getJsonBody();
        $sync = ($input['sync'] ?? false) === true;

        if ($sync) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->json(['error' => 'Method not allowed'], 405);
                return;
            }
            if (!$this->requireCsrf()) {
                return;
            }
        }

        $this->json(['data' => $this->app->getInstanceActionService()->getAllManagedInstances($sync, [$this->app->getResponseBuilder(), 'buildInstanceSnapshot'])], 200, 0, true);
    }

    private function handleControlInstance(): void
    {
        $data = $this->request->getJsonBody();
        $accountId = $data['accountId'] ?? 0;
        $actionType = $data['action'] ?? '';
        $shutdownMode = $data['shutdownMode'] ?? 'KeepCharging';

        $action = in_array($actionType, ['start', 'stop'], true) ? $actionType : null;
        if ($action === null) {
            $this->json(['success' => false, 'message' => '无效的操作类型'], 400);
            return;
        }

        // 停机方式白名单校验:防止非法值透传阿里云,且 StopCharging(停机即停止计费)由服务端显式允许
        $allowedShutdownModes = ['KeepCharging', 'StopCharging'];
        if (!in_array($shutdownMode, $allowedShutdownModes, true)) {
            $shutdownMode = 'KeepCharging';
        }

        $result = $this->app->getInstanceActionService()->controlInstance($accountId, $action, $shutdownMode, true, function($account, $fromStatus, $toStatus, $reason = '') {
            $fromStatus = InstanceStatus::tryFrom((string) ($fromStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;
            $toStatus = InstanceStatus::tryFrom((string) ($toStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;
            if ($fromStatus === $toStatus || !in_array($toStatus, [InstanceStatus::Running, InstanceStatus::Stopped], true)) return;
            if ($fromStatus === InstanceStatus::Unknown) return;
            $accountLabel = Helpers::getAccountLogLabel($account);
            $res = $this->app->getNotificationService()->notifyInstanceStatusChanged($accountLabel, $account, $fromStatus->value, $toStatus->value, $reason);
            Helpers::logNotificationResult($this->app->getDb(), $res, $accountLabel);
        });
        $this->json(['success' => $result]);
    }

    private function handleDeleteInstance(): void
    {
        $data = $this->request->getJsonBody();
        $accountId = $data['accountId'] ?? 0;
        $forceStop = $data['forceStop'] ?? false;

        $result = $this->app->getInstanceActionService()->deleteInstance($accountId, $forceStop);
        $this->json(['success' => $result]);
    }

    private function handleReplaceInstanceIp(): void
    {
        $data = $this->request->getJsonBody();
        $accountId = $data['accountId'] ?? 0;

        $this->json($this->app->getInstanceActionService()->replaceInstanceIp($accountId));
    }

    private function handleAllocateIpv6(): void
    {
        $data = $this->request->getJsonBody();
        $accountId = $data['accountId'] ?? 0;
        // 带宽按量计费(PayByTraffic,1~1000 Mbps);前端暂未提供输入,使用默认 5 Mbps
        $bandwidth = max(1, min(1000, (int) ($data['bandwidth'] ?? 5)));

        $this->json($this->app->getInstanceActionService()->allocateIpv6($accountId, $bandwidth));
    }

    private function handleReleaseIpv6(): void
    {
        $data = $this->request->getJsonBody();
        $accountId = $data['accountId'] ?? 0;

        $this->json($this->app->getInstanceActionService()->releaseIpv6($accountId));
    }
}
