<?php
// Telegram 控制常驻轮询进程。主监控 cron 继续负责流量和实例巡检。

date_default_timezone_set('Asia/Shanghai');

require_once 'src/AppContainer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "仅支持 CLI 运行。";
    exit(1);
}

$app = new AppContainer();
$db = $app->getDb();
$configManager = $app->getConfigManager();

$service = new TelegramControlService($db, $configManager, $app);

$shutdown = false;
$handleSignal = function (int $signo) use (&$shutdown) {
    $shutdown = true;
};
pcntl_signal(SIGTERM, $handleSignal);
pcntl_signal(SIGINT, $handleSignal);
// 立即投递信号,避免长轮询期间 SIGTERM/SIGINT 无法及时响应导致进程难优雅退出
pcntl_async_signals(true);

while (!$shutdown) {
    try {
        $processed = $service->processUpdatesWithTimeout(20);
        if ($processed === 0) {
            sleep(5); // 未配置或无新消息时低频等待
        }
    } catch (\Throwable $e) {
        $db->addLog('error', 'Telegram 控制常驻进程异常: ' . strip_tags($e->getMessage()));
        sleep(5);
    }
}
