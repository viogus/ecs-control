<?php
// 此文件用于 Cron 任务
// 输出简洁的文本日志

date_default_timezone_set('Asia/Shanghai');

require_once 'src/AppContainer.php';

header('Content-Type: text/plain; charset=utf-8');

$app = new AppContainer();

// CLI 模式直接运行，Web 模式使用 Bearer Token 鉴权
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $authHeader = $matches[1];
    }

    $monitorKey = $app->getAuthManager() ? $app->getAuthManager()->getMonitorKey() : '';
    if (empty($monitorKey) || !hash_equals($monitorKey, $authHeader)) {
        http_response_code(403);
        echo "访问被拒绝，请使用有效的监控密钥。";
        exit;
    }

    // Rate limiting: max 1 request per 10 seconds per token
    $pdo = $app->getDb()->getPdo();
    $rateKey = 'monitor_rate_' . substr(hash('sha256', $authHeader), 0, 16);
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
    $stmt->execute([$rateKey]);
    $lastRun = (int) $stmt->fetchColumn();
    if ($lastRun > 0 && (time() - $lastRun) < 10) {
        http_response_code(429);
        echo "请求过于频繁，请稍后再试。";
        exit;
    }
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
        ->execute([$rateKey, (string) time()]);
    // 清理历史 monitor_rate_* 键,防止 settings 表无限膨胀
    $pdo->prepare("DELETE FROM settings WHERE key LIKE 'monitor_rate_%' AND key <> ?")
        ->execute([$rateKey]);
}

// 输出简洁日志
echo "--- ECS 服务器管理 开始检测: " . date('Y-m-d H:i:s') . " ---\n";

// 互斥锁:防止上一轮未结束(多账号 API 重试/SMTP 阻塞等)时 cron 并发执行,
// 避免重复停机、重复通知、重复 DDNS 同步。
$lockFile = __DIR__ . '/data/monitor.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "上一轮检测尚未结束，跳过本轮。\n";
    exit(0);
}
register_shutdown_function(function () use ($lockFp) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
});

echo $app->getMonitorService()->run();
$app->getInstanceActionService()->processPendingReleases(function($label, $account) use ($app) {
    $notifyResult = $app->getNotificationService()->notifyInstanceReleased(
        $label, $account, '用户前端提交指令后，后台成功执行安全彻底销毁。'
    );
    Helpers::logNotificationResult($app->getDb(), $notifyResult, $label);
});
try {
    $service = new TelegramControlService($app->getDb(), $app->getConfigManager(), $app);
    $service->processUpdates();
} catch (\Exception $e) {
    $app->getDb()->addLog('error', 'Telegram 控制处理失败: ' . strip_tags($e->getMessage()));
}
echo "\n--- 检测结束 ---\n";
