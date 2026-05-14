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
require_once 'src/InstanceAction.php';

class AliyunTrafficCheck
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
            $this->responseBuilder = new FrontendResponseBuilder(
                $this->configManager, $this->db, $this->aliyunService, $this->bssService
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
                $this->db, $this->configManager, $this->notificationService, __DIR__
            );
            $this->authManager = new AuthManager($this->db, $this->configManager);

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

    public function isInitialized(): bool
    {
        if ($this->initError)
            return false;
        return $this->configManager->isInitialized();
    }



    public function getMonitorKey()
    {
        if (!$this->authManager) return '';
        return $this->authManager->getMonitorKey();
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

    public function login($password, string $ip = '0.0.0.0'): bool
    {
        if (!$this->authManager) return false;
        return $this->authManager->login((string)$password, $ip);
    }

    public function setup($data): bool
    {
        if ($this->initError)
            throw new Exception($this->initError);
        if (!$this->authManager) return false;
        return $this->authManager->setup($data);
    }

    public function updateConfig($data): bool
    {
        $success = $this->configManager->updateConfig($data);
        if ($success) {
            $this->notificationService->setConfig($this->configManager->getAllSettings());
        }
        return $success;
    }

    public function uploadLogo(array $file): array
    {
        if ($this->initError) {
            return ['success' => false, 'message' => $this->initError];
        }

        return $this->adminSupportService->uploadLogo($file);
    }

    public function getConfigForFrontend(): array
    {
        if ($this->initError) return [];
        return $this->responseBuilder->getConfigForFrontend();
    }
    // --- 修改：支持按 Tab 获取日志 ---
    public function getSystemLogs($tab = 'action'): array
    {
        if ($this->initError)
            return [];

        return $this->adminSupportService->getSystemLogs($tab);
    }

    // --- 新增：清空日志并重排 ID ---
    public function clearSystemLogs($tab = 'action')
    {
        if ($this->initError)
            return false;

        return $this->adminSupportService->clearSystemLogs($tab);
    }

    public function getAccountHistory($id): array
    {
        if ($this->initError)
            return [];

        return $this->adminSupportService->getAccountHistory($id);
    }

    // --- 核心监控逻辑 ---

    public function monitor(): string
    {
        if ($this->initError) return "错误: " . $this->initError;
        $monitor = new MonitorService($this->db, $this->configManager, $this->aliyunService, $this->notificationService, $this->ddnsService, $this->bssService);
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

    public function getStatusForFrontend($includeSensitive = false): array
    {
        if ($this->initError)
            return ['error' => $this->initError];
        return $this->responseBuilder->getStatusForFrontend($includeSensitive);
    }

    public function refreshAccount($id): array|bool
    {
        if ($this->initError) return false;
        return $this->instanceActionService->refreshAccount($id);
    }

    public function fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->accountGroupOperationService->fetchInstances($accessKeyId, $accessKeySecret, $regionId);
    }

    public function testAccountCredentials($account)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->accountGroupOperationService->testAccountCredentials($account);
    }

    public function previewEcsCreate($data): array
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->ecsCreateService->previewEcsCreate($data);
    }

    public function getEcsDiskOptions($data)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->ecsCreateService->getEcsDiskOptions($data);
    }

    public function createEcsFromPreview($previewId, array $preview): array
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->ecsCreateService->createEcsFromPreview($previewId, $preview);
    }

    public function syncAccountGroup($groupKey): array
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->accountGroupOperationService->syncAccountGroup($groupKey);
    }

    public function restoreScheduleAfterTrafficBlock($groupKey)
    {
        if ($this->initError) {
            throw new Exception($this->initError);
        }

        return $this->accountGroupOperationService->restoreScheduleAfterTrafficBlock($groupKey);
    }

    public function getEcsCreateTask($taskId): ?array
    {
        if ($this->initError) {
            return null;
        }

        return $this->ecsCreateService->getEcsCreateTask($taskId);
    }

    public function controlInstanceAction($accountId, InstanceAction $action, $shutdownMode = 'KeepCharging', $waitForSync = true)
    {
        if ($this->initError) return false;
        return $this->instanceActionService->controlInstance($accountId, $action, $shutdownMode, $waitForSync, [$this, 'notifyStatusChangeIfNeeded']);
    }

    public function deleteInstanceAction($accountId, $forceStop = false)
    {
        if ($this->initError) return false;
        return $this->instanceActionService->deleteInstance($accountId, $forceStop);
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
        $fromStatus = InstanceStatus::tryFrom((string) ($fromStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;
        $toStatus = InstanceStatus::tryFrom((string) ($toStatus ?: 'Unknown')) ?? InstanceStatus::Unknown;
        if ($fromStatus === $toStatus || !in_array($toStatus, [InstanceStatus::Running, InstanceStatus::Stopped], true)) return;
        if ($fromStatus === InstanceStatus::Unknown) return;
        $accountLabel = Helpers::getAccountLogLabel($account);
        $result = $this->notificationService->notifyInstanceStatusChanged($accountLabel, $account, $fromStatus->value, $toStatus->value, $reason);
        if ($result === true) {
            $this->db->addLog('info', "通知推送成功 [$accountLabel]");
        } elseif ($result !== false && $result !== true) {
            $this->db->addLog('warning', "通知推送异常/失败 [$accountLabel]: " . strip_tags($result));
        }
    }

    public function sendTestEmail($to)
    {
        return $this->adminSupportService->sendTestEmail($to);
    }

    public function sendTestTelegram($data)
    {
        return $this->adminSupportService->sendTestTelegram($data);
    }

    public function sendTestWebhook($data)
    {
        return $this->adminSupportService->sendTestWebhook($data);
    }

    public function renderTemplate(): string
    {
        if (!file_exists('template.html'))
            return "File not found";
        ob_start();
        include 'template.html';
        return ob_get_clean();
    }

    public function exportForMigration(): array
    {
        return $this->exportService->export();
    }
}
