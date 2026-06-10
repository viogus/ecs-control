<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'Database.php';
require_once 'ConfigManager.php';
require_once 'AliyunService.php';
require_once 'NotificationService.php';
require_once 'DdnsService.php';
require_once 'TelegramControlService.php';
require_once 'src/EcsProvisionService.php';
require_once 'src/EcsCreateService.php';
require_once 'src/BssService.php';
require_once 'src/ExportService.php';
require_once 'src/AdminSupportService.php';
require_once 'src/AccountGroupOperationService.php';
require_once 'src/AuthManager.php';
require_once 'src/InstanceStatus.php';
require_once 'src/AccountRefresher.php';


class AppContainer
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $ecsProvisionService;
    private $ecsCreateService;
    private $bssService;
    private $notificationService;
    private $ddnsService;
    private $responseBuilder;
    private $instanceActionService;
    private $exportService;
    private $adminSupportService;
    private $accountGroupOperationService;
    private $authManager;
    private $accountRefresher;
    private $initError = null;

    public function __construct()
    {
        try {
            $this->db = new Database();
            $this->configManager = new ConfigManager($this->db);
            $this->aliyunService = new AliyunService();
            $this->ecsProvisionService = new EcsProvisionService();
            $this->bssService = new BssService();
            $this->notificationService = new NotificationService();
            $this->ddnsService = new DdnsService($this->configManager->getAllSettings(), $this->db, $this->configManager);
            $this->ecsCreateService = new EcsCreateService(
                $this->db,
                $this->configManager,
                $this->ecsProvisionService,
                $this->ddnsService,
                $this->notificationService
            );
            $this->authManager = new AuthManager($this->db, $this->configManager);
            $this->accountRefresher = new AccountRefresher($this->db, $this->aliyunService, $this->configManager);
            $this->responseBuilder = new FrontendResponseBuilder(
                $this->configManager, $this->db, $this->aliyunService, $this->bssService,
                $this->accountRefresher
            );
            $this->accountGroupOperationService = new AccountGroupOperationService(
                $this->db,
                $this->configManager,
                $this->aliyunService,
                $this->responseBuilder,
                $this->ddnsService
            );
            $this->instanceActionService = new InstanceActionService(
                $this->aliyunService, $this->configManager, $this->db,
                $this->notificationService, $this->ddnsService, $this->bssService
            );
            $this->exportService = new ExportService($this->configManager);
            $this->adminSupportService = new AdminSupportService(
                $this->db, $this->configManager, $this->notificationService, __DIR__ . '/..'
            );

            // 注入配置到通知服务
            $this->notificationService->setConfig($this->configManager->getAllSettings());

        } catch (Exception $e) {
            $this->initError = $e->getMessage();
        }
    }

    public function getInitError(): ?string
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

    public function getAuthManager(): ?AuthManager
    {
        return $this->authManager;
    }

    public function getAccountRefresher(): AccountRefresher
    {
        return $this->accountRefresher;
    }

    public function getAliyunService(): AliyunService
    {
        return $this->aliyunService;
    }

    public function getEcsCreateService(): EcsCreateService
    {
        return $this->ecsCreateService;
    }

    public function getBssService(): BssService
    {
        return $this->bssService;
    }

    public function getNotificationService(): NotificationService
    {
        return $this->notificationService;
    }

    public function getDdnsService(): DdnsService
    {
        return $this->ddnsService;
    }

    public function getResponseBuilder(): FrontendResponseBuilder
    {
        return $this->responseBuilder;
    }

    public function getInstanceActionService(): InstanceActionService
    {
        return $this->instanceActionService;
    }

    public function getExportService(): ExportService
    {
        return $this->exportService;
    }

    public function getAdminSupportService(): AdminSupportService
    {
        return $this->adminSupportService;
    }

    public function getAccountGroupOperationService(): AccountGroupOperationService
    {
        return $this->accountGroupOperationService;
    }

    public function getMonitorService(): MonitorService
    {
        return new MonitorService(
            $this->db, $this->configManager, $this->aliyunService,
            $this->notificationService, $this->ddnsService, $this->bssService,
            $this->accountRefresher
        );
    }

    public function isInitialized(): bool
    {
        if ($this->initError)
            return false;
        return $this->configManager->isInitialized();
    }

    public function getPublicBrand(): array
    {
        if ($this->initError) {
            return ['logo_url' => ''];
        }

        return [
            'logo_url' => $this->configManager->get('app_logo_url', '')
        ];
    }

    public function renderTemplate(): string
    {
        if (!file_exists('template.html'))
            return "File not found";
        ob_start();
        include 'template.html';
        return ob_get_clean();
    }
}
