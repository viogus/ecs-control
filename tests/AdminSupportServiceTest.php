<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/AdminSupportService.php';

final class FakeSupportDb
{
    public array $logs = [];
    public array $hourlyStats = [];
    public array $dailyStats = [];
    public array $clearedTypes = [];
    public bool $reordered = false;

    public function getLogsByTypes(array $types, $limit): array
    {
        return array_slice(array_values(array_filter($this->logs, function ($log) use ($types) {
            return in_array($log['type'], $types, true);
        })), 0, $limit);
    }

    public function clearLogsByTypes(array $types): bool
    {
        $this->clearedTypes[] = $types;
        return true;
    }

    public function reorderLogsIds(): bool
    {
        $this->reordered = true;
        return true;
    }

    public function getHourlyStats($accountId): array
    {
        return $this->hourlyStats[$accountId] ?? [];
    }

    public function getDailyStats($accountId): array
    {
        return $this->dailyStats[$accountId] ?? [];
    }
}

final class FakeSupportConfig
{
    public array $accounts = [];
    public array $accountsById = [];
    public array $logoUrls = [];

    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function getAccountById($id)
    {
        return $this->accountsById[$id] ?? null;
    }

    public function updateAppLogoUrl($url): void
    {
        $this->logoUrls[] = $url;
    }
}

final class FakeSupportNotification
{
    public array $emailCalls = [];
    public array $telegramCalls = [];
    public array $webhookCalls = [];

    public function sendTestEmail($to)
    {
        $this->emailCalls[] = $to;
        return true;
    }

    public function sendTestTelegram($data)
    {
        $this->telegramCalls[] = $data;
        return 'telegram-result';
    }

    public function sendTestWebhook($data)
    {
        $this->webhookCalls[] = $data;
        return 'webhook-result';
    }
}

function assert_same_support($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function create_support_service(
    FakeSupportDb $db = null,
    FakeSupportConfig $config = null,
    FakeSupportNotification $notification = null
): AdminSupportService {
    return new AdminSupportService(
        $db ?? new FakeSupportDb(),
        $config ?? new FakeSupportConfig(),
        $notification ?? new FakeSupportNotification(),
        dirname(__DIR__)
    );
}

function test_get_system_logs_masks_access_key_labels_and_formats_time(): void
{
    $db = new FakeSupportDb();
    $db->logs = [
        [
            'type' => 'info',
            'message' => 'started [AKID1234567890] and AKID1234567890',
            'created_at' => 1700000000,
        ],
        [
            'type' => 'error',
            'message' => 'ignored error',
            'created_at' => 1700000001,
        ],
    ];

    $config = new FakeSupportConfig();
    $config->accounts = [
        [
            'name' => 'primary',
            'access_key_id' => 'AKID1234567890',
            'region_id' => 'cn-hangzhou',
        ],
    ];

    $logs = create_support_service($db, $config)->getSystemLogs('action');

    // action 页口径与 clearSystemLogs 一致:包含 info/warning/error 三种级别
    assert_same_support(2, count($logs), 'action logs should include info/warning/error');
    assert_same_support('started [AKID123***] and AKID123***', $logs[0]['message'], 'access key should be replaced with account label');
    assert_same_support('2023-11-14 22:13:20', $logs[0]['time_str'], 'log should include formatted timestamp');
    assert_same_support('ignored error', $logs[1]['message'], 'error logs should be visible in action tab');
}

function test_clear_system_logs_reorders_ids_after_success(): void
{
    $db = new FakeSupportDb();

    $result = create_support_service($db)->clearSystemLogs('heartbeat');

    assert_same_support(true, $result, 'clearSystemLogs should return database result');
    assert_same_support([['heartbeat']], $db->clearedTypes, 'heartbeat tab should clear heartbeat logs only');
    assert_same_support(true, $db->reordered, 'successful clear should reorder log IDs');
}

function test_get_account_history_returns_chart_series(): void
{
    $db = new FakeSupportDb();
    $db->hourlyStats = [
        7 => [
            ['recorded_at' => 1700000000, 'traffic' => 1.23456],
        ],
    ];
    $db->dailyStats = [
        7 => [
            ['recorded_at' => 1700000000, 'traffic' => 9.87654],
        ],
    ];

    $config = new FakeSupportConfig();
    $config->accountsById = [7 => ['id' => 7]];

    $history = create_support_service($db, $config)->getAccountHistory(7);

    assert_same_support([
        'history_24h' => [
            [
                'time' => '22:00',
                'full_time' => '2023-11-14 22:13',
                'value' => 1.235,
            ],
        ],
        'history_30d' => [
            [
                'date' => '2023-11-14',
                'value' => 9.877,
            ],
        ],
    ], $history, 'history should preserve chart payload shape');
}

function test_notification_tests_delegate_to_notification_service(): void
{
    $notification = new FakeSupportNotification();
    $service = create_support_service(null, null, $notification);

    assert_same_support(true, $service->sendTestEmail('ops@example.com'), 'email test should return notification result');
    assert_same_support('telegram-result', $service->sendTestTelegram(['bot_token' => 't']), 'telegram test should return notification result');
    assert_same_support('webhook-result', $service->sendTestWebhook(['url' => 'https://example.com']), 'webhook test should return notification result');
    assert_same_support(['ops@example.com'], $notification->emailCalls, 'email recipient should be forwarded');
    assert_same_support([['bot_token' => 't']], $notification->telegramCalls, 'telegram payload should be forwarded');
    assert_same_support([['url' => 'https://example.com']], $notification->webhookCalls, 'webhook payload should be forwarded');
}

test_get_system_logs_masks_access_key_labels_and_formats_time();
test_clear_system_logs_reorders_ids_after_success();
test_get_account_history_returns_chart_series();
test_notification_tests_delegate_to_notification_service();

echo "AdminSupportService tests passed\n";
