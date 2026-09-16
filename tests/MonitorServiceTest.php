<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/AccountSyncService.php';
require_once __DIR__ . '/../src/MonitorService.php';
require_once __DIR__ . '/../src/Account.php';
require_once __DIR__ . '/../src/InstanceStatus.php';

// Helpers::logNotificationResult() 的类型约束是 Database,故 fake 继承真实类并覆盖构造(不建真实连接)
final class FakeMonitorDb extends Database
{
    public array $logs = [];
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
        // getGroupTrafficUsed() 会按分组聚合 accounts.traffic_used
        $this->pdo->exec("CREATE TABLE accounts (
            id INTEGER PRIMARY KEY,
            group_key TEXT,
            access_key_id TEXT,
            region_id TEXT,
            traffic_billing_month TEXT,
            traffic_used REAL DEFAULT 0
        )");
    }

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function putSetting(string $key, string $value): void
    {
        $this->pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$key, $value]);
    }

    public function deleteSetting(string $key): void
    {
        $this->pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([$key]);
    }

    public function setting(string $key): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        return (string) $stmt->fetchColumn();
    }

    public function logsOfType(string $type): array
    {
        $out = [];
        foreach ($this->logs as $log) {
            if ($log['type'] === $type) $out[] = $log['message'];
        }
        return $out;
    }
}

final class FakeMonitorConfig
{
    public array $settings = [
        'cost_threshold_enabled' => '1',
        'cost_threshold' => '0.48',
    ];

    public function get($key, $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    // ---- 定时开关机相关调用的桩(供断言) ----
    public array $executionStates = [];
    public array $autoStartBlocked = [];
    public array $restoredGroups = [];

    public function updateScheduleExecutionState($id, $type, $date): void
    {
        $this->executionStates[] = ['id' => $id, 'type' => $type, 'date' => $date];
    }

    public function updateAutoStartBlocked($id, $blocked): void
    {
        $this->autoStartBlocked[] = ['id' => $id, 'blocked' => $blocked];
    }

    public function restoreScheduleAfterTrafficBlock($groupKey): bool
    {
        $this->restoredGroups[] = $groupKey;
        return true;
    }

    public function updateAccountStatus($id, $traffic, $status, $updatedAt, $metadata = []): bool
    {
        return true;
    }

    public function updateLastKeepAlive($id, $time): void
    {
    }
}

final class FakeMonitorAliyun
{
    public int $controlCalls = 0;

    public function controlInstance($account, $action, $shutdownMode = 'KeepCharging'): bool
    {
        $this->controlCalls++;
        return true;
    }
}

final class FakeMonitorNotification
{
    public int $scheduleNotifications = 0;
    public int $cdtUnavailableNotifications = 0;
    public array $cdtUnavailableArgs = [];
    /** 模拟通知渠道结果:true=成功,字符串=渠道报错 */
    public mixed $cdtResult = true;

    public function notifySchedule($title, $account, $message)
    {
        $this->scheduleNotifications++;
        return true;
    }

    public function notifyCdtTrafficUnavailable($account, int $failedMinutes, string $lastStatus = '', string $lastMessage = '')
    {
        $this->cdtUnavailableNotifications++;
        $this->cdtUnavailableArgs[] = [
            'minutes' => $failedMinutes,
            'status' => $lastStatus,
            'message' => $lastMessage,
        ];
        return $this->cdtResult;
    }
}

final class FakeMonitorDdns
{
    public int $syncCalls = 0;

    public function syncForAccounts(array $accounts, string $reason): void
    {
        $this->syncCalls++;
    }
}

final class FakeMonitorBss
{
    public int $billCalls = 0;

    public function getInstanceBill($key, $secret, $instanceId, $billingCycle, $siteType = 'china'): array
    {
        $this->billCalls++;
        throw new Exception('BSS unavailable');
    }
}

function assert_same_monitor($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function invoke_monitor_keep_alive(MonitorService $service, Account $account, int $currentTime, bool $keepAlive, array &$state): void
{
    $method = new ReflectionMethod(MonitorService::class, 'handleKeepAlive');
    $method->setAccessible(true);
    $method->invokeArgs($service, [$account, $currentTime, $keepAlive, &$state]);
}

function invoke_monitor_cost_breaker(MonitorService $service, Account $account, int $currentTime, string $shutdownMode, array &$state): bool
{
    $method = new ReflectionMethod(MonitorService::class, 'handleCostCircuitBreaker');
    $method->setAccessible(true);
    return $method->invokeArgs($service, [$account, $currentTime, $shutdownMode, &$state]);
}

function invoke_monitor_traffic_breaker(MonitorService $service, Account $account, int $currentTime, array &$state): bool
{
    $method = new ReflectionMethod(MonitorService::class, 'handleTrafficCircuitBreaker');
    $method->setAccessible(true);
    return $method->invokeArgs($service, [$account, $currentTime, 95, 'KeepCharging', 'stop_and_notify', &$state, 600]);
}

function invoke_monitor_scheduled_ops(MonitorService $service, Account $account, int $currentTime, string $shutdownMode, array &$state): void
{
    $method = new ReflectionMethod(MonitorService::class, 'handleScheduledOps');
    $method->setAccessible(true);
    $method->invokeArgs($service, [$account, $currentTime, $shutdownMode, &$state]);
}

function test_keep_alive_skips_when_schedule_is_blocked_by_protection(): void
{
    $db = new FakeMonitorDb();
    $aliyun = new FakeMonitorAliyun();
    $notification = new FakeMonitorNotification();
    $ddns = new FakeMonitorDdns();
    $service = new MonitorService($db, new FakeMonitorConfig(), $aliyun, $notification, $ddns);

    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKID1234567890',
        'region_id' => 'eu-central-1',
        'instance_id' => 'i-1',
        'instance_status' => 'Stopped',
        'auto_start_blocked' => 0,
    ]);
    $state = [
        'accountLabel' => 'prod',
        'status' => 'Stopped',
        'traffic' => 0.0,
        'actions' => [],
        'apiStatusLog' => '',
        'requiresTrafficProtection' => false,
        'scheduleBlockedByTraffic' => true,
    ];

    invoke_monitor_keep_alive($service, $account, time(), true, $state);

    assert_same_monitor(0, $aliyun->controlCalls, 'keep-alive should not start a blocked account');
    assert_same_monitor([], $state['actions'], 'keep-alive should not add actions when blocked');
    assert_same_monitor(0, $notification->scheduleNotifications, 'keep-alive should not notify when blocked');
    assert_same_monitor(0, $ddns->syncCalls, 'keep-alive should not sync DDNS when blocked');
}

test_keep_alive_skips_when_schedule_is_blocked_by_protection();

function test_cost_query_failure_is_cooled_down_for_five_minutes(): void
{
    $db = new FakeMonitorDb();
    $bss = new FakeMonitorBss();
    $service = new MonitorService(
        $db,
        new FakeMonitorConfig(),
        new FakeMonitorAliyun(),
        new FakeMonitorNotification(),
        new FakeMonitorDdns(),
        $bss
    );
    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKID1234567890',
        'access_key_secret' => 'secret',
        'instance_id' => 'i-1',
        'site_type' => 'international',
    ]);
    $state = [
        'accountLabel' => 'prod',
        'status' => 'Running',
        'traffic' => 0.0,
        'actions' => [],
        'apiStatusLog' => '',
        'protectionSuspended' => false,
    ];

    $firstResult = invoke_monitor_cost_breaker($service, $account, 1000, 'KeepCharging', $state);
    $secondResult = invoke_monitor_cost_breaker($service, $account, 1100, 'KeepCharging', $state);

    assert_same_monitor(false, $firstResult, 'failed cost query should not block instance');
    assert_same_monitor(false, $secondResult, 'cooled down cost query should not block instance');
    assert_same_monitor(1, $bss->billCalls, 'BSS should not be retried during cooldown window');
    assert_same_monitor(1, count($db->logs), 'cooldown should suppress repetitive warning logs');
    assert_same_monitor('warning', $db->logs[0]['type'], 'first cost query failure should still be logged');
}

test_cost_query_failure_is_cooled_down_for_five_minutes();

// ---- CDT 持续失败：熔断被跳过时必须告警一次(否则保护静默失效) ----

function cdt_failure_account(int $id = 1, string $accessKeyId = 'AKID1234567890'): Account
{
    return Account::fromDbRow([
        'id' => $id,
        'access_key_id' => $accessKeyId,
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hongkong',
        'instance_id' => 'i-' . $id,
        'traffic_used' => 120.0,
        'traffic_api_status' => 'timeout',
        'traffic_api_message' => 'CDT 网络连接异常',
    ]);
}

function traffic_breaker_state(): array
{
    return [
        'accountLabel' => 'hk2',
        'status' => 'Running',
        'traffic' => 120.0,
        'actions' => [],
        'apiStatusLog' => '',
        'protectionSuspended' => false,
        'protectionSuspendReason' => '',
    ];
}

function test_persistent_cdt_failure_notifies_once_and_skips_breaker(): void
{
    $db = new FakeMonitorDb();
    $notification = new FakeMonitorNotification();
    $service = new MonitorService(
        $db,
        new FakeMonitorConfig(),
        new FakeMonitorAliyun(),
        $notification,
        new FakeMonitorDdns()
    );
    $account = cdt_failure_account();
    $notifySuffix = Helpers::cdtNotifyKeySuffix($account);

    // 距离首次失败 1000 秒(> 900 秒豁免阈值)
    $db->putSetting('cdt_failure_at_1', '1000');

    $state = traffic_breaker_state();
    $result = invoke_monitor_traffic_breaker($service, $account, 2000, $state);

    assert_same_monitor(false, $result, 'persistent CDT failure should skip the breaker');
    assert_same_monitor(1, $notification->cdtUnavailableNotifications, 'protection suspension should be notified once');
    assert_same_monitor(17, $notification->cdtUnavailableArgs[0]['minutes'], 'notification should carry the outage duration');
    assert_same_monitor('timeout', $notification->cdtUnavailableArgs[0]['status'], 'notification should carry the last API status');
    assert_same_monitor(1, count($state['actions']), 'state should record the traffic outage action');
    assert_same_monitor(true, strpos($state['apiStatusLog'], '跳过熔断') !== false, 'log should explain the skipped breaker');
    assert_same_monitor('2000', $db->setting('cdt_failure_notified_at_' . $notifySuffix), 'successful push writes the AK-scoped marker');
    // 通知成功会额外记一条 info,中断本身必须是 warning
    $warningLogs = $db->logsOfType('warning');
    assert_same_monitor(2, count($db->logs), 'notification result log plus outage warning are expected');
    assert_same_monitor(1, count($warningLogs), 'outage should produce exactly one warning log');
    assert_same_monitor(true, strpos($warningLogs[0], '自动停机保护暂时失效') !== false, 'outage log should state the protection gap');

    // 下一轮仍在中断:不得重复推送
    $secondState = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $account, 2060, $secondState);
    assert_same_monitor(1, $notification->cdtUnavailableNotifications, 'repeated rounds must not re-notify');
    assert_same_monitor(true, strpos($secondState['apiStatusLog'], '已告警') !== false, 'repeated rounds should mention the existing alert');

    // CDT 恢复(AccountRefresher 清除标记)后再次中断:应重新告警
    $db->deleteSetting('cdt_failure_at_1');
    $db->deleteSetting('cdt_failure_notified_at_' . $notifySuffix);
    $db->deleteSetting('cdt_failure_notify_attempt_at_' . $notifySuffix);
    $db->putSetting('cdt_failure_at_1', '3000');

    $thirdState = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $account, 4000, $thirdState);
    assert_same_monitor(2, $notification->cdtUnavailableNotifications, 'a new outage after recovery should notify again');
}

test_persistent_cdt_failure_notifies_once_and_skips_breaker();

function test_same_ak_instances_share_one_cdt_alert(): void
{
    $db = new FakeMonitorDb();
    $notification = new FakeMonitorNotification();
    $service = new MonitorService(
        $db,
        new FakeMonitorConfig(),
        new FakeMonitorAliyun(),
        $notification,
        new FakeMonitorDdns()
    );

    // 同一 AK 的两台实例:CDT 按 AK 聚合查询,必然同时失败,应只告警一次
    $accountA = cdt_failure_account(1, 'AKIDSHARED0001');
    $accountB = cdt_failure_account(2, 'AKIDSHARED0001');
    $db->putSetting('cdt_failure_at_1', '1000');
    $db->putSetting('cdt_failure_at_2', '1000');

    $stateA = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $accountA, 2000, $stateA);
    $stateB = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $accountB, 2010, $stateB);

    assert_same_monitor(1, $notification->cdtUnavailableNotifications, 'same-AK instances must share one alert');
    assert_same_monitor(true, strpos($stateA['apiStatusLog'], '跳过熔断') !== false, 'first instance should still skip the breaker');
    assert_same_monitor(true, strpos($stateB['apiStatusLog'], '已告警') !== false, 'second instance should reuse the existing alert');
}

test_same_ak_instances_share_one_cdt_alert();

function test_cdt_alert_retries_after_notify_failure(): void
{
    $db = new FakeMonitorDb();
    $notification = new FakeMonitorNotification();
    $notification->cdtResult = '邮件通知: SMTP connect failed';
    $service = new MonitorService(
        $db,
        new FakeMonitorConfig(),
        new FakeMonitorAliyun(),
        $notification,
        new FakeMonitorDdns()
    );
    $account = cdt_failure_account();
    $notifySuffix = Helpers::cdtNotifyKeySuffix($account);
    $db->putSetting('cdt_failure_at_1', '1000');

    $first = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $account, 2000, $first);

    assert_same_monitor(1, $notification->cdtUnavailableNotifications, 'failed push still counts as an attempt');
    assert_same_monitor('', $db->setting('cdt_failure_notified_at_' . $notifySuffix), 'failed push must NOT write the success marker');
    assert_same_monitor('2000', $db->setting('cdt_failure_notify_attempt_at_' . $notifySuffix), 'failed push records the attempt time');
    $errorLogs = $db->logsOfType('error');
    assert_same_monitor(1, count($errorLogs), 'failed push should log exactly one error');
    assert_same_monitor(true, strpos($errorLogs[0], '分钟后重试') !== false, 'failed push log should announce the retry window');

    // 仍在重试窗口内:不再尝试
    $second = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $account, 2060, $second);
    assert_same_monitor(1, $notification->cdtUnavailableNotifications, 'retry window should suppress immediate retries');
    assert_same_monitor(true, strpos($second['apiStatusLog'], '待重试') !== false, 'suppressed retry should be visible in the status log');

    // 窗口期满(1800s)后重试成功 → 写成功标记
    $notification->cdtResult = true;
    $third = traffic_breaker_state();
    invoke_monitor_traffic_breaker($service, $account, 4000, $third);
    assert_same_monitor(2, $notification->cdtUnavailableNotifications, 'alert should be retried after the window');
    assert_same_monitor('4000', $db->setting('cdt_failure_notified_at_' . $notifySuffix), 'successful retry writes the success marker');
}

test_cdt_alert_retries_after_notify_failure();

function test_short_cdt_failure_keeps_normal_breaker_path(): void
{
    $db = new FakeMonitorDb();
    $notification = new FakeMonitorNotification();
    $service = new MonitorService(
        $db,
        new FakeMonitorConfig(),
        new FakeMonitorAliyun(),
        $notification,
        new FakeMonitorDdns()
    );
    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKID1234567890',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hongkong',
        'instance_id' => 'i-1',
        'traffic_used' => 10.0,
        'max_traffic' => 1000.0,
        'updated_at' => 1990,
    ]);

    // 首次失败仅 100 秒:未达豁免阈值,应继续走正常熔断逻辑(此处未超阈值 → 不触发保护)
    $db->putSetting('cdt_failure_at_1', '1990');
    $state = traffic_breaker_state();
    $result = invoke_monitor_traffic_breaker($service, $account, 2000, $state);

    assert_same_monitor(false, $result, 'short outage should not skip the breaker');
    assert_same_monitor(0, $notification->cdtUnavailableNotifications, 'short outage should not notify');
    assert_same_monitor([], $state['actions'], 'short outage should not record actions');
}

test_short_cdt_failure_keeps_normal_breaker_path();

// ---- 定时开关机 × 保活 的冲突修复 ----

function scheduled_account(array $overrides = []): Account
{
    return Account::fromDbRow(array_merge([
        'id' => 1, 'access_key_id' => 'AKIDSCHED00001', 'access_key_secret' => 'secret',
        'region_id' => 'cn-hongkong', 'instance_id' => 'i-sched',
        'instance_status' => 'Running',
        'schedule_enabled' => 1, 'schedule_start_enabled' => 1, 'schedule_stop_enabled' => 1,
        'start_time' => '08:00', 'stop_time' => '23:00',
    ], $overrides));
}

function scheduled_state(array $overrides = []): array
{
    return array_merge([
        'accountLabel' => 'hk2', 'status' => 'Running', 'traffic' => 0.0, 'actions' => [],
        'apiStatusLog' => '', 'requiresTrafficProtection' => false, 'scheduleBlockedByTraffic' => false,
        'protectionSuspended' => false, 'protectionSuspendReason' => '', 'protectionSuspendNotifiedAt' => 0,
        'accountGroupKey' => 'gk1',
    ], $overrides);
}

function test_keep_alive_skips_inside_scheduled_stop_window(): void
{
    $db = new FakeMonitorDb();
    $aliyun = new FakeMonitorAliyun();
    $service = new MonitorService($db, new FakeMonitorConfig(), $aliyun, new FakeMonitorNotification(), new FakeMonitorDdns());
    $account = scheduled_account(['instance_status' => 'Stopped', 'auto_start_blocked' => 0]);

    // 23:00 停机、08:00 开机 → 23:30 处于停机时段内，不得保活拉起
    $inside = scheduled_state(['status' => 'Stopped']);
    invoke_monitor_keep_alive($service, $account, strtotime('2026-09-16 23:30:00'), true, $inside);
    assert_same_monitor(0, $aliyun->controlCalls, 'keep-alive must not start the instance inside the stop window');
    assert_same_monitor(true, strpos($inside['apiStatusLog'], '停机时段') !== false, 'status log should explain the skip');

    // 12:00 不在停机时段，保活照常
    $outside = scheduled_state(['status' => 'Stopped']);
    invoke_monitor_keep_alive($service, $account, strtotime('2026-09-16 12:00:00'), true, $outside);
    assert_same_monitor(1, $aliyun->controlCalls, 'keep-alive should still start outside the stop window');
}

test_keep_alive_skips_inside_scheduled_stop_window();

function test_scheduled_stop_does_not_consume_window_in_transient_state(): void
{
    $db = new FakeMonitorDb();
    $aliyun = new FakeMonitorAliyun();
    $config = new FakeMonitorConfig();
    $service = new MonitorService($db, $config, $aliyun, new FakeMonitorNotification(), new FakeMonitorDdns());

    // 到点 23:00 时实例还在 Starting（例如刚被保活拉起）：不得标记当天已执行，须留待宽限窗口内重试
    $account = scheduled_account(['instance_status' => 'Starting']);
    $state = scheduled_state(['status' => 'Starting']);
    invoke_monitor_scheduled_ops($service, $account, strtotime('2026-09-16 23:00:10'), 'KeepCharging', $state);

    assert_same_monitor(0, count($config->executionStates), 'transient state must not consume the day window');
    assert_same_monitor(true, strpos($state['apiStatusLog'], '待重试') !== false, 'status log should mention the retry');
    assert_same_monitor(0, $aliyun->controlCalls, 'no stop call while in transient state');
}

test_scheduled_stop_does_not_consume_window_in_transient_state();

function test_traffic_recovery_auto_restores_schedule_block(): void
{
    $db = new FakeMonitorDb();
    $config = new FakeMonitorConfig();
    $service = new MonitorService($db, $config, new FakeMonitorAliyun(), new FakeMonitorNotification(), new FakeMonitorDdns());

    $account = scheduled_account(['max_traffic' => 1000.0, 'traffic_used' => 10.0, 'updated_at' => time()]);
    $state = scheduled_state(['scheduleBlockedByTraffic' => true]);

    invoke_monitor_traffic_breaker($service, $account, time(), $state);

    assert_same_monitor(1, count($config->restoredGroups), 'traffic recovery should clear the schedule block automatically');
    assert_same_monitor('gk1', $config->restoredGroups[0], 'restore should target the account group');
    assert_same_monitor(false, $state['scheduleBlockedByTraffic'], 'this round should no longer be treated as blocked');
}

test_traffic_recovery_auto_restores_schedule_block();

echo "MonitorService tests passed\n";
