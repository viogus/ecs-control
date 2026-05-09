# Account Group Operation Service Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract account-group operation orchestration from `AliyunTrafficCheck` into `AccountGroupOperationService` without changing API behavior.

**Architecture:** `AliyunTrafficCheck` remains the facade used by `HttpRouter` and keeps existing public method names plus initialization-error guards. A new global `AccountGroupOperationService` owns instance fetching, account credential testing, account-group sync, schedule-block restore, masked-secret resolution, and traffic issue summarization. Dependency-free PHP tests use fake dependencies to verify side effects without calling Alibaba Cloud.

**Tech Stack:** PHP 8.1+, native classes with no namespace, Composer classmap/autoload pattern, Docker PHP 8.2 CLI for verification, no PHPUnit.

---

## File Structure

- Create: `tests/AccountGroupOperationServiceTest.php`
  - Standalone PHP test runner with fake DB/config/Aliyun/response-builder/DDNS dependencies.
  - Tests the extracted service directly and avoids real Alibaba Cloud calls.
- Create: `src/AccountGroupOperationService.php`
  - Global class, no namespace.
  - Owns account-group operation workflow currently embedded in `AliyunTrafficCheck`.
- Modify: `AliyunTrafficCheck.php`
  - Add `require_once 'src/AccountGroupOperationService.php';`.
  - Add `$accountGroupOperationService` property and construct it.
  - Keep public facade methods and init-error behavior; delegate normal behavior to `AccountGroupOperationService`.

---

### Task 1: Add Account Group Operation Regression Tests

**Files:**
- Create: `tests/AccountGroupOperationServiceTest.php`

- [ ] **Step 1: Write the failing service tests**

Create `tests/AccountGroupOperationServiceTest.php` with this content:

```php
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
```

- [ ] **Step 2: Run the test to verify RED**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AccountGroupOperationServiceTest.php
```

Expected: FAIL with a fatal error that `src/AccountGroupOperationService.php` cannot be opened or `Class "AccountGroupOperationService" not found`.

- [ ] **Step 3: Commit the failing test**

Run:

```bash
git add tests/AccountGroupOperationServiceTest.php
git commit -m "test: add account group operation regression tests"
```

Expected: commit succeeds with only `tests/AccountGroupOperationServiceTest.php` staged.

---

### Task 2: Implement AccountGroupOperationService

**Files:**
- Create: `src/AccountGroupOperationService.php`
- Test: `tests/AccountGroupOperationServiceTest.php`

- [ ] **Step 1: Create the service class**

Create `src/AccountGroupOperationService.php` and move the account-group operation workflow from `AliyunTrafficCheck.php` into it. Use this class outline and fill method bodies from the current `AliyunTrafficCheck` implementation:

```php
<?php

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class AccountGroupOperationService
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $responseBuilder;
    private $ddnsService;

    public function __construct($db, $configManager, $aliyunService, $responseBuilder, $ddnsService)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->aliyunService = $aliyunService;
        $this->responseBuilder = $responseBuilder;
        $this->ddnsService = $ddnsService;
    }

    public function fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')
    {
        if (empty($accessKeyId) || empty($accessKeySecret)) {
            throw new Exception('请先填写AK ID和AK Secret');
        }

        try {
            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret, $regionId ?: null);
            $maskedKey = substr($accessKeyId, 0, 7) . '***';
            $this->db->addLog('info', "实例列表获取成功 [{$maskedKey}] 共 " . count($instances) . " 台");
            return $instances;
        } catch (ClientException $e) {
            $this->db->addLog('warning', "实例列表获取失败: 鉴权错误");
            throw new Exception('阿里云鉴权失败，请检查AK权限或密钥是否正确');
        } catch (ServerException $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . $e->getErrorCode() . " - " . strip_tags($e->getErrorMessage()));
            throw new Exception('阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage());
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . strip_tags($e->getMessage()));
            throw new Exception('实例列表获取失败: 网络或系统错误');
        }
    }

    public function testAccountCredentials($account)
    {
        $accessKeyId = trim((string) ($account['AccessKeyId'] ?? ''));
        $accessKeySecret = trim((string) ($account['AccessKeySecret'] ?? ''));
        $regionId = trim((string) ($account['regionId'] ?? ''));
        $maxTraffic = (float) ($account['maxTraffic'] ?? 0);
        $accountLabel = trim((string) ($account['remark'] ?? '')) ?: (substr($accessKeyId, 0, 7) . '***');

        if ($accessKeyId === '' || $accessKeySecret === '' || $regionId === '') {
            throw new Exception('请先填写完整的AK、区域和账号流量');
        }

        if ($accessKeySecret === '********') {
            $accessKeySecret = $this->resolveSecretFromDatabase($accessKeyId, $regionId, $account['groupKey'] ?? '');
        }

        try {
            $regions = $this->aliyunService->getRegions($accessKeyId, $accessKeySecret);
            $regionIds = array_column($regions, 'regionId');
            if (!in_array($regionId, $regionIds, true)) {
                throw new Exception('当前AK无法访问所选区域，请检查权限范围');
            }

            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret);
            $regionInstances = array_values(array_filter($instances, function ($instance) use ($regionId) {
                return ($instance['regionId'] ?? '') === $regionId;
            }));
            $instanceCount = count($regionInstances);

            $monitorWarning = '';
            try {
                $this->aliyunService->getTraffic($accessKeyId, $accessKeySecret, $regionId);
            } catch (\Exception $e) {
                $monitorWarning = 'CDT 流量查询未通过：' . strip_tags($e->getMessage());
                $this->db->addLog('warning', "账号 CDT 探测异常 [{$accountLabel}]: {$monitorWarning}");
            }

            $trafficUsed = (float) ($account['usageUsed'] ?? 0);
            $trafficRemaining = max(round($maxTraffic - $trafficUsed, 2), 0);
            $trafficPercent = $maxTraffic > 0 ? min(round(($trafficUsed / $maxTraffic) * 100, 2), 100) : 0;
            $this->db->addLog('info', "账号测试成功 [{$accountLabel}] {$regionId} 实例 {$instanceCount} 台");
            $message = 'AK可用，ECS API已接通';
            if ($monitorWarning !== '') {
                $message .= '；' . $monitorWarning;
            } else {
                $message .= '，CDT 接口已接通';
            }

            return [
                'success' => true,
                'message' => $message,
                'monitorWarning' => $monitorWarning,
                'monitorStatus' => $monitorWarning !== '' ? 'warning' : 'ok',
                'monitorMessage' => $monitorWarning !== '' ? $monitorWarning : 'CDT 接口已接通，可获取账号出口流量。',
                'usageUsed' => $trafficUsed,
                'usageRemaining' => $trafficRemaining,
                'usagePercent' => $trafficPercent,
                'instanceCount' => $instanceCount
            ];
        } catch (ClientException $e) {
            $message = '鉴权失败，请检查AK ID和AK Secret是否正确，或确认是否具备ECS 权限';
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (ServerException $e) {
            $message = '阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage();
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (Exception $e) {
            $this->db->addLog('warning', "账号测试失败: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function syncAccountGroup($groupKey): array
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        $accountsBeforeSync = $this->configManager->getAccounts();
        $this->configManager->syncAccountGroups(true);
        $this->configManager->load();

        $threshold = (int) ($this->configManager->get('traffic_threshold', 95) ?: 95);
        $userInterval = (int) ($this->configManager->get('api_interval', 600) ?: 600);
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        $instanceCount = 0;

        foreach ($this->configManager->getAccounts() as $account) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            if ($accountGroupKey !== $groupKey || empty($account['instance_id'])) {
                continue;
            }

            $this->responseBuilder->buildInstanceSnapshot($account, ['threshold' => $threshold, 'userInterval' => $userInterval, 'billingEnabled' => $billingEnabled, 'includeSensitive' => true, 'forceRefresh' => true]);
            $instanceCount++;
        }

        if ($billingEnabled) {
            $this->responseBuilder->getAccountGroupBillingMetrics(true);
        }

        $this->configManager->load();
        $syncedAccounts = array_values(array_filter($this->configManager->getAccounts(), function ($account) use ($groupKey) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            return $accountGroupKey === $groupKey && !empty($account['instance_id']);
        }));
        $this->ddnsService->reconcileAfterSync($accountsBeforeSync, $this->configManager->getAccounts(), '账号同步');
        $this->db->addLog('info', "账号同步完成 [{$targetGroup['remark']}] {$targetGroup['regionId']} 实例 {$instanceCount} 台");

        $trafficIssue = $this->summarizeTrafficIssueForAccounts($syncedAccounts);
        $message = "已同步 {$instanceCount} 台实例，流量和消费情况已刷新";
        if ($trafficIssue !== '') {
            $message .= '；' . $trafficIssue;
        }

        return [
            'success' => true,
            'message' => $message,
            'instanceCount' => $instanceCount,
            'trafficIssue' => $trafficIssue
        ];
    }

    public function restoreScheduleAfterTrafficBlock($groupKey)
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        $this->configManager->restoreScheduleAfterTrafficBlock($groupKey);
        $this->db->addLog('info', "已手动恢复定时开关机 [{$targetGroup['remark']}] {$targetGroup['regionId']}");

        return [
            'success' => true,
            'message' => '定时开关机已恢复。请确认本月流量未继续超过阈值，否则下一轮监控仍会触发保护。'
        ];
    }

    private function resolveSecretFromDatabase($accessKeyId, $regionId, $groupKey = '')
    {
        $groups = $this->configManager->getAccountGroups();
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey && !empty($group['AccessKeySecret'])) {
                return $group['AccessKeySecret'];
            }
            if (($group['AccessKeyId'] ?? '') === $accessKeyId && ($group['regionId'] ?? '') === $regionId && !empty($group['AccessKeySecret'])) {
                return $group['AccessKeySecret'];
            }
        }

        foreach ($this->configManager->getAccounts() as $account) {
            if (($account['access_key_id'] ?? '') === $accessKeyId && ($account['region_id'] ?? '') === $regionId && !empty($account['access_key_secret'])) {
                return $this->configManager->decryptAccountSecret($account['access_key_secret']);
            }
        }

        throw new Exception('请重新输入 AK Secret 后再测试账号');
    }

    private function summarizeTrafficIssueForAccounts(array $accounts)
    {
        if (empty($accounts)) {
            return '';
        }

        $statuses = [];
        foreach ($accounts as $account) {
            $status = trim((string) ($account['traffic_api_status'] ?? 'ok'));
            if ($status !== '' && $status !== 'ok') {
                $statuses[$status] = true;
            }
        }

        if (empty($statuses)) {
            return '';
        }

        if (isset($statuses['auth_error'])) {
            return '部分账号 CDT 鉴权失败，请检查 AK 权限配置';
        }

        if (isset($statuses['timeout'])) {
            return '部分账号 CDT 请求超时，请稍后重试';
        }

        return '部分账号流量同步失败，请稍后重试';
    }
}
```

- [ ] **Step 2: Run the service test to verify GREEN**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AccountGroupOperationServiceTest.php
```

Expected:

```text
AccountGroupOperationService tests passed
```

- [ ] **Step 3: Run syntax check for the new service**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/AccountGroupOperationService.php
```

Expected:

```text
No syntax errors detected in src/AccountGroupOperationService.php
```

- [ ] **Step 4: Commit the service implementation**

Run:

```bash
git add src/AccountGroupOperationService.php tests/AccountGroupOperationServiceTest.php
git commit -m "refactor: add account group operation service"
```

Expected: commit succeeds with the service and any necessary test adjustment staged.

---

### Task 3: Delegate AliyunTrafficCheck Account Group Methods

**Files:**
- Modify: `AliyunTrafficCheck.php`
- Verify: `src/AccountGroupOperationService.php`
- Test: `tests/AccountGroupOperationServiceTest.php`

- [ ] **Step 1: Add the service dependency to AliyunTrafficCheck**

In `AliyunTrafficCheck.php`, add:

```php
require_once 'src/AccountGroupOperationService.php';
```

Add a private property:

```php
private $accountGroupOperationService;
```

After `$this->responseBuilder` and `$this->ddnsService` are constructed, construct:

```php
$this->accountGroupOperationService = new AccountGroupOperationService(
    $this->db,
    $this->configManager,
    $this->aliyunService,
    $this->responseBuilder,
    $this->ddnsService
);
```

- [ ] **Step 2: Replace account-group method bodies with facade delegations**

In `AliyunTrafficCheck.php`, replace these public methods with:

```php
public function fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->accountGroupOperationService->fetchInstances($accessKeyId, $accessKeySecret, $regionId);
}

public function testAccountCredentials($account)
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->accountGroupOperationService->testAccountCredentials($account);
}

public function syncAccountGroup($groupKey): array
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->accountGroupOperationService->syncAccountGroup($groupKey);
}

public function restoreScheduleAfterTrafficBlock($groupKey)
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->accountGroupOperationService->restoreScheduleAfterTrafficBlock($groupKey);
}
```

Remove these private helpers from `AliyunTrafficCheck.php`:

```php
private function resolveSecretFromDatabase($accessKeyId, $regionId, $groupKey = '')
private function summarizeTrafficIssueForAccounts(array $accounts)
```

- [ ] **Step 3: Run regression and syntax checks**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AccountGroupOperationServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/AccountGroupOperationService.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/AccountGroupOperationServiceTest.php
```

Expected:

```text
AccountGroupOperationService tests passed
EcsCreateService tests passed
HttpRouter tests passed
AdminSupportService tests passed
No syntax errors detected in AliyunTrafficCheck.php
No syntax errors detected in src/AccountGroupOperationService.php
No syntax errors detected in tests/AccountGroupOperationServiceTest.php
```

- [ ] **Step 4: Confirm AliyunTrafficCheck no longer owns account-group operation helpers**

Run:

```bash
rg -n "resolveSecretFromDatabase|summarizeTrafficIssueForAccounts|getRegions|getTraffic\\(|syncAccountGroups\\(true\\)|reconcileAfterSync|restoreScheduleAfterTrafficBlock\\(" AliyunTrafficCheck.php
```

Expected: no output and exit code 1, except `restoreScheduleAfterTrafficBlock` may appear in the facade method declaration and delegate call. If it appears only in those facade lines, this check passes.

- [ ] **Step 5: Commit the facade delegation**

Run:

```bash
git add AliyunTrafficCheck.php src/AccountGroupOperationService.php tests/AccountGroupOperationServiceTest.php
git commit -m "refactor: delegate account group operations"
```

Expected: commit succeeds with `AliyunTrafficCheck.php` and any necessary service/test adjustments staged.

---

### Task 4: Final Verification

**Files:**
- Verify: `AliyunTrafficCheck.php`
- Verify: `src/AccountGroupOperationService.php`
- Verify: `tests/AccountGroupOperationServiceTest.php`

- [ ] **Step 1: Run all verification commands fresh**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AccountGroupOperationServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/AccountGroupOperationService.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/AccountGroupOperationServiceTest.php
```

Expected:

```text
AccountGroupOperationService tests passed
EcsCreateService tests passed
HttpRouter tests passed
AdminSupportService tests passed
No syntax errors detected in AliyunTrafficCheck.php
No syntax errors detected in src/AccountGroupOperationService.php
No syntax errors detected in tests/AccountGroupOperationServiceTest.php
```

- [ ] **Step 2: Review final diff scope**

Run:

```bash
git diff --stat HEAD~2..HEAD
git diff --name-status HEAD~2..HEAD
wc -l AliyunTrafficCheck.php src/AccountGroupOperationService.php tests/AccountGroupOperationServiceTest.php
```

Expected:

- New `src/AccountGroupOperationService.php`.
- New `tests/AccountGroupOperationServiceTest.php`.
- `AliyunTrafficCheck.php` shorter and delegating account-group operation workflow.
- No changes to `HttpRouter`, frontend, database schema, `AccountSyncService`, `InstanceActionService`, or `AliyunService`.

- [ ] **Step 3: Report final status**

In the final response, include:

- Files changed.
- Verification commands run and pass/fail status.
- Commit SHAs created.
- Any residual test gaps, especially that tests use fake dependencies rather than live Alibaba Cloud calls.
