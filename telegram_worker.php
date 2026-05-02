<?php
// Telegram 控制常驻轮询进程。主监控 cron 继续负责流量和实例巡检。

require_once 'AliyunTrafficCheck.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "仅支持 CLI 运行。";
    exit(1);
}

$app = new AliyunTrafficCheck();
$db = $app->getDb();
$configManager = $app->getConfigManager();

$service = new TelegramControlService($db, $configManager, $app);

while (true) {
    try {
        $processed = $service->processUpdatesWithTimeout(20);
        if ($processed === 0) {
            sleep(30); // 未配置或无新消息时低频等待
        }
    } catch (\Throwable $e) {
        $db->addLog('error', 'Telegram 控制常驻进程异常: ' . strip_tags($e->getMessage()));
        sleep(5);
    }
}
