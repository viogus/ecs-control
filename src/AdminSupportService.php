<?php

class AdminSupportService
{
    private $db;
    private $configManager;
    private $notificationService;
    private string $baseDir;

    public function __construct($db, $configManager, $notificationService, ?string $baseDir = null)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->notificationService = $notificationService;
        $this->baseDir = $baseDir ?? dirname(__DIR__);
    }

    public function uploadLogo(array $file): array
    {
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

        $dir = $this->baseDir . '/data';
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

    public function getSystemLogs($tab = 'action'): array
    {
        if ($tab === 'heartbeat') {
            $types = ['heartbeat'];
        } else {
            $types = ['info', 'warning'];
        }

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

    public function clearSystemLogs($tab = 'action')
    {
        if ($tab === 'heartbeat') {
            $result = $this->db->clearLogsByTypes(['heartbeat']);
        } else {
            $result = $this->db->clearLogsByTypes(['info', 'warning', 'error']);
        }

        if ($result) {
            $this->db->reorderLogsIds();
        }

        return $result;
    }

    public function getAccountHistory($id): array
    {
        $account = $this->configManager->getAccountById($id);
        if (!$account) {
            return ['error' => 'Account not found'];
        }

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
}

