<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/MonitorService.php';

final class FakeMonitorDb
{
    public array $logs = [];

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }
}

final class FakeMonitorConfig
{
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

echo "MonitorService tests passed\n";
