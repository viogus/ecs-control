<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';
require_once __DIR__ . '/../AliyunService.php';
require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/AccountSyncService.php';
require_once __DIR__ . '/../src/AccountRefresher.php';
require_once __DIR__ . '/../src/Account.php';
require_once __DIR__ . '/../src/InstanceStatus.php';

final class FakeRefresherDb extends Database
{
    public array $logs = [];
    public array $hourlyStats = [];
    public array $dailyStats = [];
    private PDO $pdo;

    public function __construct()
    {
        // 内存 SQLite:支持 settings 表写入(CDT 失败标记等)
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function addHourlyStat($accountId, $traffic): void
    {
        $this->hourlyStats[] = ['id' => $accountId, 'traffic' => $traffic];
    }

    public function addDailyStat($accountId, $traffic): void
    {
        $this->dailyStats[] = ['id' => $accountId, 'traffic' => $traffic];
    }

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }
}

final class FakeRefresherAliyun extends AliyunService
{
    public float $trafficValue = 12.5;
    public string $status = 'Running';
    public int $statusCalls = 0;
    public ?\Exception $trafficException = null;
    public ?\Exception $statusException = null;
    public bool $transientUnknown = false;

    public function getTraffic($key, $secret, $regionId)
    {
        if ($this->trafficException) {
            throw $this->trafficException;
        }
        return $this->trafficValue;
    }

    public function getInstanceStatus($account): string
    {
        $this->statusCalls++;
        if ($this->statusException) {
            throw $this->statusException;
        }
        if ($this->transientUnknown && $this->statusCalls === 1) {
            return InstanceStatus::Unknown->value;
        }
        return $this->status;
    }
}

final class FakeRefresherConfig extends ConfigManager
{
    public array $statusUpdates = [];

    public function __construct()
    {
        // skip parent — no DB needed
    }

    // 参数类型必须与父类 ConfigManager::updateAccountStatus 兼容(PHP 8.1 不允许子类收窄参数类型)
    public function updateAccountStatus($id, $traffic, $status, $updatedAt, $metadata = []): bool
    {
        $this->statusUpdates[] = [
            'id' => $id, 'traffic' => $traffic, 'status' => $status,
            'updatedAt' => $updatedAt, 'metadata' => $metadata
        ];
        return true;
    }
}

function assert_refresher($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, '  Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

// ---- Test 1: Successful refresh ----

function test_successful_refresh_records_stats(): void
{
    $db = new FakeRefresherDb();
    $aliyun = new FakeRefresherAliyun();
    $aliyun->trafficValue = 8.2;
    $aliyun->status = 'Running';
    $config = new FakeRefresherConfig();

    $refresher = new AccountRefresher($db, $aliyun, $config);

    $account = Account::fromDbRow([
        'id' => 42,
        'access_key_id' => 'AKIDtest',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'instance_id' => 'i-test',
        'traffic_used' => 5.0,
        'updated_at' => 1000,
    ]);

    $result = $refresher->refresh($account, 2000);

    assert_refresher(8.2, $result->traffic, 'traffic should be the fetched value');
    assert_refresher('Running', $result->status, 'status should be Running');
    assert_refresher('ok', $result->metadata['traffic_api_status'], 'traffic_api_status should be ok');
    assert_refresher('', $result->metadata['traffic_api_message'], 'traffic_api_message should be empty');
    assert_refresher(2000, $result->newUpdateTime, 'updateTime should be currentTime');
    assert_refresher(false, $result->authInvalid, 'authInvalid should be false on success');
    assert_refresher(true, $result->trafficSuccess, 'trafficSuccess should be true');

    assert_refresher(1, count($db->hourlyStats), 'hourly stat should be recorded');
    assert_refresher(42, $db->hourlyStats[0]['id'], 'hourly stat account id');
    assert_refresher(8.2, $db->hourlyStats[0]['traffic'], 'hourly stat traffic value');
    assert_refresher(1, count($db->dailyStats), 'daily stat should be recorded');

    assert_refresher(1, count($config->statusUpdates), 'updateAccountStatus should be called once');
    assert_refresher(42, $config->statusUpdates[0]['id'], 'status update id');
    assert_refresher(8.2, $config->statusUpdates[0]['traffic'], 'status update traffic');
    assert_refresher('Running', $config->statusUpdates[0]['status'], 'status update status');
    assert_refresher(2000, $config->statusUpdates[0]['updatedAt'], 'status update time');
}

test_successful_refresh_records_stats();

// ---- Test 2: Traffic failure preserves old values ----

function test_traffic_failure_preserves_old_values(): void
{
    $db = new FakeRefresherDb();
    $aliyun = new FakeRefresherAliyun();
    $aliyun->trafficException = new \Exception('cURL error: Operation timed out');
    $aliyun->status = 'Running';
    $config = new FakeRefresherConfig();

    $refresher = new AccountRefresher($db, $aliyun, $config);

    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKIDtest',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'instance_id' => 'i-test',
        'traffic_used' => 3.7,
        'updated_at' => 500,
    ]);

    $result = $refresher->refresh($account, 1000);

    assert_refresher(3.7, $result->traffic, 'traffic should preserve old value on failure');
    assert_refresher('Running', $result->status, 'status should still be fetched');
    assert_refresher(false, $result->trafficSuccess, 'trafficSuccess should be false');
    // 失败冷却语义:失败时更新时间推进到 currentTime-300(5 分钟),
    // 使 shouldCheckApi 在冷却期内不再触发,避免每分钟全量重试触发阿里云限流
    assert_refresher(700, $result->newUpdateTime, 'updateTime should advance to failure cooldown when traffic fails');
    assert_refresher('timeout', $result->metadata['traffic_api_status'], 'status should reflect network error');
    assert_refresher(false, $result->authInvalid, 'timeout is not auth_error');

    assert_refresher(0, count($db->hourlyStats), 'no hourly stat on traffic failure');
    assert_refresher(0, count($db->dailyStats), 'no daily stat on traffic failure');
}

test_traffic_failure_preserves_old_values();

// ---- Test 3: Unknown status retries once ----

function test_unknown_status_retries_once(): void
{
    $db = new FakeRefresherDb();
    $aliyun = new FakeRefresherAliyun();
    $aliyun->transientUnknown = true;
    $aliyun->status = 'Running';
    $config = new FakeRefresherConfig();

    $refresher = new AccountRefresher($db, $aliyun, $config);

    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKIDtest',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'instance_id' => 'i-test',
        'traffic_used' => 0,
        'updated_at' => 100,
    ]);

    $result = $refresher->refresh($account, 500);

    assert_refresher(2, $aliyun->statusCalls, 'status should be called twice with retry');
    assert_refresher('Running', $result->status, 'status should resolve to Running after retry');
}

test_unknown_status_retries_once();

// ---- Test 4: auth_error sets authInvalid ----

function test_auth_error_sets_invalid_flag(): void
{
    $db = new FakeRefresherDb();
    $aliyun = new FakeRefresherAliyun();
    $aliyun->trafficException = new \AlibabaCloud\Client\Exception\ClientException(
        'AccessKeyId is not found',
        'InvalidAccessKeyId.NotFound'
    );
    $aliyun->status = 'Running';
    $config = new FakeRefresherConfig();

    $refresher = new AccountRefresher($db, $aliyun, $config);

    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKID-dead',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'instance_id' => 'i-test',
        'traffic_used' => 2.0,
        'updated_at' => 1000,
    ]);

    $result = $refresher->refresh($account, 2000);

    assert_refresher(true, $result->authInvalid, 'authInvalid should be true for InvalidAccessKeyId');
    assert_refresher('auth_error', $result->metadata['traffic_api_status'], 'status should be auth_error');
    assert_refresher(1, $config->statusUpdates[0]['metadata']['protection_suspended'], 'protection_suspended should be 1');
    assert_refresher('credential_invalid', $config->statusUpdates[0]['metadata']['protection_suspend_reason'], 'reason should be credential_invalid');
}

test_auth_error_sets_invalid_flag();

// ---- Test 5: Status Unknown + traffic failure = old update time ----

function test_unknown_and_traffic_failure_keeps_old_time(): void
{
    $db = new FakeRefresherDb();
    $aliyun = new FakeRefresherAliyun();
    $aliyun->statusException = new \Exception('ECS API unreachable');
    $aliyun->trafficException = new \Exception('CDT API unreachable');
    $config = new FakeRefresherConfig();

    $refresher = new AccountRefresher($db, $aliyun, $config);

    $account = Account::fromDbRow([
        'id' => 1,
        'access_key_id' => 'AKIDtest',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'instance_id' => 'i-test',
        'traffic_used' => 1.0,
        'updated_at' => 300,
    ]);

    $result = $refresher->refresh($account, 1000);

    assert_refresher(1.0, $result->traffic, 'should keep old traffic on double failure');
    assert_refresher('Unknown', $result->status, 'should be Unknown on status failure');
    assert_refresher(300, $result->newUpdateTime, 'should keep old update time on double failure');
    assert_refresher(false, $result->trafficSuccess, 'trafficSuccess false');
    assert_refresher(false, $result->authInvalid, 'exception is not auth error');
}

test_unknown_and_traffic_failure_keeps_old_time();

echo "AccountRefresher tests passed\n";
