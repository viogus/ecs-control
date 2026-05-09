<?php

class HttpRouter
{
    private $app;
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
        'logout',
        'export',
        'get_all_instances',
    ];

    public function __construct($app, ?string $baseDir = null)
    {
        $this->app = $app;
        $this->baseDir = $baseDir ?? dirname(__DIR__);
    }

    public function dispatch(string $action): void
    {
        if ($this->dispatchPublic($action)) {
            return;
        }

        if ($action !== 'view' && !$this->isLoggedIn()) {
            $this->json(['error' => '请先登录后再操作'], 403);
            return;
        }

        if (in_array($action, $this->mutatingActions, true) && !$this->requireCsrf()) {
            return;
        }

        if ($this->dispatchAuthenticated($action)) {
            return;
        }

        echo $this->app->renderTemplate();
    }

    public function json(array $payload, int $status = 200, int $flags = 0, bool $withCharset = false): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }

        header('Content-Type: application/json' . ($withCharset ? '; charset=utf-8' : ''));
        echo json_encode($payload, $flags);
    }

    public function readJsonBody(): array
    {
        $raw = $GLOBALS['HTTP_ROUTER_TEST_INPUT'] ?? file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    public function ensureCsrfToken(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function requireCsrf(): bool
    {
        $this->ensureCsrfToken();
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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
        if ($action === 'get_status') {
            $this->handleGetStatus();
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

        $data = $this->readJsonBody();
        try {
            if ($this->app->setup($data)) {
                $_SESSION['is_admin'] = true;
                $this->ensureCsrfToken();
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
        $data = $this->readJsonBody();
        try {
            if ($this->app->login($data['password'] ?? '')) {
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $this->ensureCsrfToken();
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

        if (!$this->isAdminStrict()) {
            $this->json(['error' => '请先登录后再操作'], 403, 0, true);
            return;
        }

        $this->json($this->app->getStatusForFrontend(true), 200, 0, true);
    }

    private function handleExport(): void
    {
        try {
            $this->json($this->app->exportForMigration(), 200, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleGetConfig(): void
    {
        $this->ensureCsrfToken();
        $config = $this->app->getConfigForFrontend();
        $config['csrf_token'] = $_SESSION['csrf_token'];
        $this->json($config);
    }

    private function handleSaveConfig(): void
    {
        $data = $this->readJsonBody();
        if ($this->app->updateConfig($data)) {
            $this->json(['success' => true]);
        } else {
            $this->json(['success' => false, 'message' => '保存失败']);
        }
    }

    private function handleUploadLogo(): void
    {
        $this->json($this->app->uploadLogo($_FILES['logo'] ?? []), 200, 0, true);
    }

    private function handleSendTestEmail(): void
    {
        $data = $this->readJsonBody();
        $result = $this->app->sendTestEmail($data['email'] ?? '');
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleSendTestTelegram(): void
    {
        $data = $this->readJsonBody();
        $result = $this->app->sendTestTelegram($data['telegram'] ?? []);
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleSendTestWebhook(): void
    {
        $data = $this->readJsonBody();
        $result = $this->app->sendTestWebhook($data['webhook'] ?? []);
        $this->json(['success' => $result === true, 'message' => $result]);
    }

    private function handleRefreshAccount(): void
    {
        $data = $this->readJsonBody();
        $id = $data['id'] ?? 0;
        $result = $this->app->refreshAccount($id);
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
        $data = $this->readJsonBody();

        try {
            $instances = $this->app->fetchInstances($data['accessKeyId'] ?? '', $data['accessKeySecret'] ?? '', $data['regionId'] ?? '');
            $this->json(['success' => true, 'data' => $instances], 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleTestAccount(): void
    {
        $data = $this->readJsonBody();

        try {
            $this->json($this->app->testAccountCredentials($data['account'] ?? []), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handleSyncAccountGroup(): void
    {
        $data = $this->readJsonBody();

        try {
            $this->json($this->app->syncAccountGroup($data['groupKey'] ?? ''), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handleRestoreScheduleBlock(): void
    {
        $data = $this->readJsonBody();

        try {
            $this->json($this->app->restoreScheduleAfterTrafficBlock($data['groupKey'] ?? ''), 200, 0, true);
        } catch (Exception $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400, 0, true);
        }
    }

    private function handlePreviewEcsCreate(): void
    {
        $data = $this->readJsonBody();

        try {
            $result = $this->app->previewEcsCreate($data);
            $_SESSION['ecs_create_previews'] = $_SESSION['ecs_create_previews'] ?? [];
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
        $data = $this->readJsonBody();

        try {
            $this->json($this->app->getEcsDiskOptions($data), 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleCreateEcs(): void
    {
        $data = $this->readJsonBody();
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

            $result = $this->app->createEcsFromPreview($previewId, $previewStore['summary']);
            unset($_SESSION['ecs_create_previews'][$previewId]);
            $this->json($result, 200, 0, true);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 400, 0, true);
        }
    }

    private function handleGetEcsCreateTask(): void
    {
        $taskId = $_GET['taskId'] ?? '';
        $task = $this->app->getEcsCreateTask($taskId);
        if (!$task) {
            $this->json(['success' => false, 'message' => '任务不存在'], 404, 0, true);
            return;
        }

        unset($task['login_password']);
        $this->json(['success' => true, 'data' => $task], 200, 0, true);
    }

    private function handleGetLogs(): void
    {
        $tab = $_GET['tab'] ?? 'action';
        $this->json(['data' => $this->app->getSystemLogs($tab)], 200, 0, true);
    }

    private function handleClearLogs(): void
    {
        $data = $this->readJsonBody();
        $tab = $data['tab'] ?? 'action';
        if ($this->app->clearSystemLogs($tab)) {
            $this->json(['success' => true]);
        } else {
            $this->json(['success' => false, 'message' => '清空失败']);
        }
    }

    private function handleGetHistory(): void
    {
        $id = $_GET['id'] ?? 0;
        $this->json(['data' => $this->app->getAccountHistory($id)], 200, 0, true);
    }

    private function handleLogout(): void
    {
        session_destroy();
        $this->json(['success' => true]);
    }

    private function handleGetAllInstances(): void
    {
        $input = $this->readJsonBody();
        $sync = ($input['sync'] ?? false) === true;
        $this->json(['data' => $this->app->getAllManagedInstances($sync)], 200, 0, true);
    }

    private function handleControlInstance(): void
    {
        $data = $this->readJsonBody();
        $accountId = $data['accountId'] ?? 0;
        $actionType = $data['action'] ?? '';
        $shutdownMode = $data['shutdownMode'] ?? 'KeepCharging';

        if (!in_array($actionType, ['start', 'stop'], true)) {
            $this->json(['success' => false, 'message' => '无效的操作类型'], 400);
            return;
        }

        $result = $this->app->controlInstanceAction($accountId, $actionType, $shutdownMode);
        $this->json(['success' => $result]);
    }

    private function handleDeleteInstance(): void
    {
        $data = $this->readJsonBody();
        $accountId = $data['accountId'] ?? 0;
        $forceStop = $data['forceStop'] ?? false;

        $result = $this->app->deleteInstanceAction($accountId, $forceStop);
        $this->json(['success' => $result]);
    }

    private function handleReplaceInstanceIp(): void
    {
        $data = $this->readJsonBody();
        $accountId = $data['accountId'] ?? 0;

        $this->json($this->app->replaceInstanceIpAction($accountId));
    }
}
