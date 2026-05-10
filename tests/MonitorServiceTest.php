<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/MonitorService.php';

final class FakeMonitorDb
{
    public array $logs = [];
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    }

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
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

    public function notifySchedule($title, $account, $message)
    {
        $this->scheduleNotifications++;
        return true;
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

function invoke_monitor_keep_alive(MonitorService $service, array $account, int $currentTime, bool $keepAlive, array &$state): void
{
    $method = new ReflectionMethod(MonitorService::class, 'handleKeepAlive');
    $method->setAccessible(true);
    $method->invokeArgs($service, [$account, $currentTime, $keepAlive, &$state]);
}

function invoke_monitor_cost_breaker(MonitorService $service, array $account, int $currentTime, string $shutdownMode, array &$state): bool
{
    $method = new ReflectionMethod(MonitorService::class, 'handleCostCircuitBreaker');
    $method->setAccessible(true);
    return $method->invokeArgs($service, [$account, $currentTime, $shutdownMode, &$state]);
}

function test_keep_alive_skips_when_schedule_is_blocked_by_protection(): void
{
    $db = new FakeMonitorDb();
    $aliyun = new FakeMonitorAliyun();
    $notification = new FakeMonitorNotification();
    $ddns = new FakeMonitorDdns();
    $service = new MonitorService($db, new FakeMonitorConfig(), $aliyun, $notification, $ddns);

    $account = [
        'id' => 1,
        'access_key_id' => 'AKID1234567890',
        'region_id' => 'eu-central-1',
        'instance_id' => 'i-1',
        'instance_status' => 'Stopped',
        'auto_start_blocked' => 0,
    ];
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
    $account = [
        'id' => 1,
        'access_key_id' => 'AKID1234567890',
        'access_key_secret' => 'secret',
        'instance_id' => 'i-1',
        'site_type' => 'international',
    ];
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

echo "MonitorService tests passed\n";
