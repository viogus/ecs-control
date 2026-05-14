<?php
// 此文件用于 Cron 任务
// 输出简洁的文本日志

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
}

// 输出简洁日志
echo "--- ECS 服务器管理 开始检测: " . date('Y-m-d H:i:s') . " ---\n";
echo $app->getMonitorService()->run();
$app->getInstanceActionService()->processPendingReleases(function($label, $account) use ($app) {
    $notifyResult = $app->getNotificationService()->notifyInstanceReleased(
        $label, $account, '用户前端提交指令后，后台成功执行安全彻底销毁。'
    );
    if ($notifyResult === true) {
        $app->getDb()->addLog('info', "通知推送成功 [$label]");
    } elseif ($notifyResult !== false && $notifyResult !== true) {
        $app->getDb()->addLog('warning', "通知推送异常/失败 [$label]: " . strip_tags($notifyResult));
    }
});
try {
    $service = new TelegramControlService($app->getDb(), $app->getConfigManager(), $app);
    $service->processUpdates();
} catch (\Exception $e) {
    $app->getDb()->addLog('error', 'Telegram 控制处理失败: ' . strip_tags($e->getMessage()));
}
echo "\n--- 检测结束 ---\n";
