<?php

require_once __DIR__ . '/../src/AccountGroupOperationService.php';

final class FakeAccountGroupDb
{
    public array $logs = [];

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }
}

final class FakeAccountGroupConfig
{
    public array $groups = [];
    public array $accounts = [];
    public array $settings = [
        'traffic_threshold' => 95,
        'api_interval' => 600,
        'enable_billing' => '1',
    ];
    public array $syncCalls = [];
    public int $loadCalls = 0;
    public array $restoreCalls = [];

    public function getAccountGroups(): array
    {
        return $this->groups;
    }

    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function syncAccountGroups($force = false, $groups = null): void
    {
        $this->syncCalls[] = [$force, $groups];
    }

    public function load(): void
    {
        $this->loadCalls++;
    }

    public function get($key, $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function restoreScheduleAfterTrafficBlock($groupKey): void
    {
        $this->restoreCalls[] = $groupKey;
    }
}

final class FakeAccountGroupAliyun
{
    public array $instances = [];
    public array $regions = [];
    public ?Exception $trafficException = null;
    public array $getInstancesCalls = [];
    public array $getRegionsCalls = [];
    public array $getTrafficCalls = [];

    public function getInstances($key, $secret, $targetRegionId = null)
    {
        $this->getInstancesCalls[] = [$key, $secret, $targetRegionId];
        return $this->instances;
    }

    public function getRegions($key, $secret)
    {
        $this->getRegionsCalls[] = [$key, $secret];
        return $this->regions;
    }

    public function getTraffic($key, $secret, $regionId)
    {
        $this->getTrafficCalls[] = [$key, $secret, $regionId];
        if ($this->trafficException) {
            throw $this->trafficException;
        }

        return 12.5;
    }
}

final class FakeAccountGroupResponseBuilder
{
    public array $snapshotCalls = [];
    public int $billingRefreshCalls = 0;

    public function buildInstanceSnapshot($account, array $options = []): array
    {
        $this->snapshotCalls[] = ['account' => $account, 'options' => $options];
        return ['id' => $account['id'] ?? 0];
    }

    public function getAccountGroupBillingMetrics(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->billingRefreshCalls++;
        }

        return [];
    }
}

final class FakeAccountGroupDdns
{
    public array $reconcileCalls = [];

    public function reconcileAfterSync(array $before, array $after, string $reason): void
    {
        $this->reconcileCalls[] = ['before' => $before, 'after' => $after, 'reason' => $reason];
    }
}

function assert_same_account_group($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_throws_account_group(callable $fn, string $message, string $assertMessage): void
{
    try {
        $fn();
    } catch (Exception $e) {
        assert_same_account_group($message, $e->getMessage(), $assertMessage);
        return;
    }

    fwrite(STDERR, $assertMessage . PHP_EOL);
    fwrite(STDERR, 'Expected exception: ' . $message . PHP_EOL);
    exit(1);
}

function fake_account_group_config(): FakeAccountGroupConfig
{
    $config = new FakeAccountGroupConfig();
    $config->groups = [[
        'groupKey' => 'group-1',
        'AccessKeyId' => 'AKID1234567890',
        'AccessKeySecret' => 'stored-secret',
        'regionId' => 'cn-hangzhou',
        'remark' => 'prod',
    ]];
    $config->accounts = [
        [
            'id' => 1,
            'group_key' => 'group-1',
            'access_key_id' => 'AKID1234567890',
            'access_key_secret' => 'stored-secret',
            'region_id' => 'cn-hangzhou',
            'instance_id' => 'i-1',
            'traffic_api_status' => 'ok',
        ],
        [
            'id' => 2,
            'group_key' => 'other-group',
            'access_key_id' => 'AKID9999999999',
            'region_id' => 'cn-hangzhou',
            'instance_id' => 'i-2',
            'traffic_api_status' => 'ok',
        ],
    ];
    return $config;
}

function fake_account_group_aliyun(): FakeAccountGroupAliyun
{
    $aliyun = new FakeAccountGroupAliyun();
    $aliyun->regions = [
        ['regionId' => 'cn-hangzhou'],
        ['regionId' => 'cn-shanghai'],
    ];
    $aliyun->instances = [
        ['instanceId' => 'i-1', 'regionId' => 'cn-hangzhou'],
        ['instanceId' => 'i-2', 'regionId' => 'cn-shanghai'],
    ];
    return $aliyun;
}

function create_account_group_service(
    FakeAccountGroupDb $db = null,
    FakeAccountGroupConfig $config = null,
    FakeAccountGroupAliyun $aliyun = null,
    FakeAccountGroupResponseBuilder $responseBuilder = null,
    FakeAccountGroupDdns $ddns = null
): AccountGroupOperationService {
    return new AccountGroupOperationService(
        $db ?? new FakeAccountGroupDb(),
        $config ?? fake_account_group_config(),
        $aliyun ?? fake_account_group_aliyun(),
        $responseBuilder ?? new FakeAccountGroupResponseBuilder(),
        $ddns ?? new FakeAccountGroupDdns()
    );
}

function test_fetch_instances_success_logs_and_passes_null_region_when_empty(): void
{
    $db = new FakeAccountGroupDb();
    $aliyun = fake_account_group_aliyun();
    $service = create_account_group_service($db, null, $aliyun);

    $instances = $service->fetchInstances('AKID1234567890', 'secret', '');

    assert_same_account_group($aliyun->instances, $instances, 'fetchInstances should return provider instances');
    assert_same_account_group(['AKID1234567890', 'secret', null], $aliyun->getInstancesCalls[0], 'empty region should be passed as null');
    assert_same_account_group('实例列表获取成功 [AKID123***] 共 2 台', $db->logs[0]['message'], 'fetch success log should keep message shape');
}

function test_fetch_instances_requires_credentials(): void
{
    $service = create_account_group_service();

    assert_throws_account_group(
        fn() => $service->fetchInstances('', 'secret', 'cn-hangzhou'),
        '请先填写AK ID和AK Secret',
        'fetchInstances should reject missing AK ID'
    );
}

function test_account_credentials_success_uses_masked_secret_and_returns_payload(): void
{
    $db = new FakeAccountGroupDb();
    $config = fake_account_group_config();
    $aliyun = fake_account_group_aliyun();
    $service = create_account_group_service($db, $config, $aliyun);

    $result = $service->testAccountCredentials([
        'AccessKeyId' => 'AKID1234567890',
        'AccessKeySecret' => '********',
        'regionId' => 'cn-hangzhou',
        'maxTraffic' => 100,
        'usageUsed' => 40,
        'remark' => 'prod',
        'groupKey' => 'group-1',
    ]);

    assert_same_account_group(true, $result['success'], 'account test should return success true');
    assert_same_account_group('AK可用，ECS API已接通，CDT 接口已接通', $result['message'], 'success message should include CDT success');
    assert_same_account_group('', $result['monitorWarning'], 'success should have no monitor warning');
    assert_same_account_group('ok', $result['monitorStatus'], 'success monitor status should be ok');
    assert_same_account_group(40.0, $result['usageUsed'], 'usage used should be preserved');
    assert_same_account_group(60.0, $result['usageRemaining'], 'usage remaining should be calculated');
    assert_same_account_group(40.0, $result['usagePercent'], 'usage percent should be calculated');
    assert_same_account_group(1, $result['instanceCount'], 'instance count should count selected region only');
    assert_same_account_group(['AKID1234567890', 'stored-secret'], $aliyun->getRegionsCalls[0], 'masked secret should resolve from stored account group');
    assert_same_account_group('账号测试成功 [prod] cn-hangzhou 实例 1 台', $db->logs[0]['message'], 'success log should keep shape');
}

function test_account_credentials_returns_cdt_warning_payload(): void
{
    $db = new FakeAccountGroupDb();
    $aliyun = fake_account_group_aliyun();
    $aliyun->trafficException = new Exception('CDT denied');
    $service = create_account_group_service($db, fake_account_group_config(), $aliyun);

    $result = $service->testAccountCredentials([
        'AccessKeyId' => 'AKID1234567890',
        'AccessKeySecret' => 'stored-secret',
        'regionId' => 'cn-hangzhou',
        'maxTraffic' => 100,
        'remark' => 'prod',
    ]);

    assert_same_account_group('warning', $result['monitorStatus'], 'CDT failure should return warning status');
    assert_same_account_group('CDT 流量查询未通过：CDT denied', $result['monitorWarning'], 'CDT warning should include stripped message');
    assert_same_account_group('AK可用，ECS API已接通；CDT 流量查询未通过：CDT denied', $result['message'], 'main message should include CDT warning');
    assert_same_account_group('warning', $db->logs[0]['type'], 'CDT warning should be logged');
}

function test_sync_account_group_success_refreshes_matching_instances_billing_and_ddns(): void
{
    $db = new FakeAccountGroupDb();
    $config = fake_account_group_config();
    $responseBuilder = new FakeAccountGroupResponseBuilder();
    $ddns = new FakeAccountGroupDdns();
    $service = create_account_group_service($db, $config, null, $responseBuilder, $ddns);

    $result = $service->syncAccountGroup('group-1');

    assert_same_account_group(true, $result['success'], 'sync should return success true');
    assert_same_account_group('已同步 1 台实例，流量和消费情况已刷新', $result['message'], 'sync message should keep shape');
    assert_same_account_group(1, $result['instanceCount'], 'sync should count matching instances only');
    assert_same_account_group('', $result['trafficIssue'], 'sync should omit traffic issue when all ok');
    assert_same_account_group([[true, null]], $config->syncCalls, 'sync should reconcile full configured set');
    assert_same_account_group(2, $config->loadCalls, 'sync should reload before and after refresh');
    assert_same_account_group(1, count($responseBuilder->snapshotCalls), 'sync should refresh matching group instances only');
    assert_same_account_group('i-1', $responseBuilder->snapshotCalls[0]['account']['instance_id'], 'sync should refresh selected group account');
    assert_same_account_group(1, $responseBuilder->billingRefreshCalls, 'billing metrics should refresh when enabled');
    assert_same_account_group('账号同步', $ddns->reconcileCalls[0]['reason'], 'DDNS reconcile reason should be preserved');
    assert_same_account_group('账号同步完成 [prod] cn-hangzhou 实例 1 台', $db->logs[0]['message'], 'sync success log should keep shape');
}

function test_sync_account_group_reports_traffic_issues(): void
{
    $config = fake_account_group_config();
    $config->accounts[0]['traffic_api_status'] = 'timeout';
    $service = create_account_group_service(null, $config);

    $result = $service->syncAccountGroup('group-1');

    assert_same_account_group('部分账号 CDT 请求超时，请稍后重试', $result['trafficIssue'], 'timeout traffic issue should be summarized');
    assert_same_account_group('已同步 1 台实例，流量和消费情况已刷新；部分账号 CDT 请求超时，请稍后重试', $result['message'], 'sync message should append traffic issue');
}

function test_restore_schedule_block_success(): void
{
    $db = new FakeAccountGroupDb();
    $config = fake_account_group_config();
    $service = create_account_group_service($db, $config);

    $result = $service->restoreScheduleAfterTrafficBlock('group-1');

    assert_same_account_group(['group-1'], $config->restoreCalls, 'restore should delegate to config manager');
    assert_same_account_group(true, $result['success'], 'restore should return success true');
    assert_same_account_group('定时开关机已恢复。请确认本月流量未继续超过阈值，否则下一轮监控仍会触发保护。', $result['message'], 'restore message should keep shape');
    assert_same_account_group('已手动恢复定时开关机 [prod] cn-hangzhou', $db->logs[0]['message'], 'restore log should keep shape');
}

function test_unknown_account_group_throws_current_message(): void
{
    $service = create_account_group_service();

    assert_throws_account_group(
        fn() => $service->syncAccountGroup('missing-group'),
        '账号组不存在，请刷新页面后重试',
        'unknown account group should throw current message'
    );
}

test_fetch_instances_success_logs_and_passes_null_region_when_empty();
test_fetch_instances_requires_credentials();
test_account_credentials_success_uses_masked_secret_and_returns_payload();
test_account_credentials_returns_cdt_warning_payload();
test_sync_account_group_success_refreshes_matching_instances_billing_and_ddns();
test_sync_account_group_reports_traffic_issues();
test_restore_schedule_block_success();
test_unknown_account_group_throws_current_message();

echo "AccountGroupOperationService tests passed\n";
