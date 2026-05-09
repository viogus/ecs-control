# ECS Create Service Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract ECS creation orchestration from `AliyunTrafficCheck` into `EcsCreateService` without changing API behavior.

**Architecture:** `AliyunTrafficCheck` remains the facade used by `HttpRouter` and keeps existing public method names plus initialization-error guards. A new global `EcsCreateService` owns ECS create preview, disk option lookup, create-task orchestration, task lookup, account-group resolution, and client-IP detection. Dependency-free PHP tests use fake dependencies to verify side effects without calling Alibaba Cloud.

**Tech Stack:** PHP 8.1+, native classes with no namespace, Composer classmap/autoload pattern, Docker PHP 8.2 CLI for verification, no PHPUnit.

---

## File Structure

- Create: `tests/EcsCreateServiceTest.php`
  - Standalone PHP test runner with fake DB/config/provision/DDNS/notification dependencies.
  - Tests the extracted service directly and avoids real cloud/network calls by passing public IP candidates through `$_SERVER`.
- Create: `src/EcsCreateService.php`
  - Global class, no namespace.
  - Owns ECS creation workflow currently embedded in `AliyunTrafficCheck`.
- Modify: `AliyunTrafficCheck.php`
  - Add `require_once 'src/EcsCreateService.php';`.
  - Add `$ecsCreateService` property and construct it.
  - Keep public facade methods and init-error behavior; delegate normal behavior to `EcsCreateService`.

---

### Task 1: Add ECS Create Service Regression Tests

**Files:**
- Create: `tests/EcsCreateServiceTest.php`

- [ ] **Step 1: Write the failing service tests**

Create `tests/EcsCreateServiceTest.php` with this content:

```php
<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/EcsCreateService.php';

final class FakeEcsCreateDb
{
    public array $logs = [];
    public array $tasks = [];
    public array $taskUpdates = [];

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }

    public function createEcsCreateTask($taskId, $previewId, $groupKey, $regionId, $instanceType, $payload): void
    {
        $this->tasks[$taskId] = [
            'taskId' => $taskId,
            'previewId' => $previewId,
            'groupKey' => $groupKey,
            'regionId' => $regionId,
            'instanceType' => $instanceType,
            'payload' => $payload,
        ];
    }

    public function updateEcsCreateTask($taskId, array $fields): void
    {
        $this->taskUpdates[] = ['taskId' => $taskId, 'fields' => $fields];
    }

    public function getEcsCreateTask($taskId): ?array
    {
        return $this->tasks[$taskId] ?? null;
    }
}

final class FakeEcsCreateConfig
{
    public array $groups = [];
    public array $accounts = [];
    public array $createdAccountsByInstanceId = [];
    public array $networkUpdates = [];
    public int $blockCurrentlyStoppedInstancesCalls = 0;
    public array $syncAccountGroupsCalls = [];
    public int $loadCalls = 0;

    public function getAccountGroups(): array
    {
        return $this->groups;
    }

    public function blockCurrentlyStoppedInstances(): void
    {
        $this->blockCurrentlyStoppedInstancesCalls++;
    }

    public function syncAccountGroups($force = false, $groups = null): void
    {
        $this->syncAccountGroupsCalls[] = [$force, $groups];
    }

    public function load(): void
    {
        $this->loadCalls++;
    }

    public function getAccountByInstanceId($instanceId)
    {
        return $this->createdAccountsByInstanceId[$instanceId] ?? null;
    }

    public function updateAccountNetworkMetadata($id, array $metadata): void
    {
        $this->networkUpdates[] = ['id' => $id, 'metadata' => $metadata];
    }

    public function getAccounts(): array
    {
        return $this->accounts;
    }
}

final class FakeEcsProvision
{
    public array $previewCalls = [];
    public array $diskCalls = [];
    public array $createCalls = [];
    public array $previewResult = [];
    public array $diskResult = [];
    public array $createResult = [];
    public ?Exception $createException = null;

    public function buildEcsCreatePreview($account, array $request, $clientIp = '')
    {
        $this->previewCalls[] = ['account' => $account, 'request' => $request, 'clientIp' => $clientIp];
        return $this->previewResult;
    }

    public function getAvailableSystemDiskOptions($account, array $request)
    {
        $this->diskCalls[] = ['account' => $account, 'request' => $request];
        return $this->diskResult;
    }

    public function createManagedEcsFromPreview($account, array $preview, callable $progress = null)
    {
        $this->createCalls[] = ['account' => $account, 'preview' => $preview];
        if ($progress) {
            $progress('准备 VPC');
        }
        if ($this->createException) {
            throw $this->createException;
        }
        return $this->createResult;
    }
}

final class FakeEcsCreateDdns
{
    public array $syncCalls = [];

    public function syncForAccounts(array $accounts, string $reason): void
    {
        $this->syncCalls[] = ['accounts' => $accounts, 'reason' => $reason];
    }
}

final class FakeEcsCreateNotification
{
    public array $createdCalls = [];
    public $result = true;

    public function notifyEcsCreated($label, $result, $preview)
    {
        $this->createdCalls[] = ['label' => $label, 'result' => $result, 'preview' => $preview];
        return $this->result;
    }
}

function assert_same_ecs_create($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_true_ecs_create(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assert_throws_ecs_create(callable $fn, string $message, string $assertMessage): void
{
    try {
        $fn();
    } catch (Exception $e) {
        assert_same_ecs_create($message, $e->getMessage(), $assertMessage);
        return;
    }

    fwrite(STDERR, $assertMessage . PHP_EOL);
    fwrite(STDERR, 'Expected exception: ' . $message . PHP_EOL);
    exit(1);
}

function create_ecs_create_service(
    FakeEcsCreateDb $db = null,
    FakeEcsCreateConfig $config = null,
    FakeEcsProvision $provision = null,
    FakeEcsCreateDdns $ddns = null,
    FakeEcsCreateNotification $notification = null
): EcsCreateService {
    return new EcsCreateService(
        $db ?? new FakeEcsCreateDb(),
        $config ?? fake_ecs_create_config(),
        $provision ?? fake_ecs_provision(),
        $ddns ?? new FakeEcsCreateDdns(),
        $notification ?? new FakeEcsCreateNotification()
    );
}

function fake_ecs_create_config(): FakeEcsCreateConfig
{
    $config = new FakeEcsCreateConfig();
    $config->groups = [[
        'groupKey' => 'group-1',
        'AccessKeyId' => 'AKID1234567890',
        'AccessKeySecret' => 'secret',
        'regionId' => 'cn-hangzhou',
        'remark' => 'prod',
        'siteType' => 'china',
        'maxTraffic' => 300,
    ]];
    $config->accounts = [['id' => 99, 'instance_id' => 'i-new']];
    $config->createdAccountsByInstanceId = ['i-new' => ['id' => 99]];
    return $config;
}

function fake_ecs_provision(): FakeEcsProvision
{
    $provision = new FakeEcsProvision();
    $provision->previewResult = fake_preview();
    $provision->diskResult = ['options' => [['value' => 'cloud_essd_entry']]];
    $provision->createResult = fake_create_result();
    return $provision;
}

function fake_preview(): array
{
    return [
        'account' => ['groupKey' => 'group-1', 'label' => 'prod'],
        'regionId' => 'cn-shanghai',
        'zoneId' => 'cn-shanghai-a',
        'instanceType' => 'ecs.e-c4m1.large',
        'instanceName' => 'launch-test',
        'imageId' => 'm-123',
        'osLabel' => 'Debian 12',
        'pricing' => ['available' => false],
        'warnings' => ['warn'],
    ];
}

function fake_create_result(): array
{
    return [
        'instanceId' => 'i-new',
        'publicIp' => '203.0.113.8',
        'publicIpMode' => 'eip',
        'eipAllocationId' => 'eip-1',
        'eipAddress' => '203.0.113.8',
        'eipManaged' => true,
        'internetMaxBandwidthOut' => 100,
        'systemDiskCategory' => 'cloud_essd_entry',
        'systemDiskSize' => 40,
        'vpcId' => 'vpc-1',
        'vswitchId' => 'vsw-1',
        'securityGroupId' => 'sg-1',
        'loginUser' => 'root',
    ];
}

function test_preview_success_returns_payload_and_logs(): void
{
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.10'];
    $db = new FakeEcsCreateDb();
    $config = fake_ecs_create_config();
    $provision = fake_ecs_provision();
    $service = create_ecs_create_service($db, $config, $provision);

    $result = $service->previewEcsCreate([
        'accountGroupKey' => 'group-1',
        'regionId' => 'cn-shanghai',
    ]);

    assert_same_ecs_create(true, $result['success'], 'preview should return success true');
    assert_true_ecs_create(str_starts_with($result['previewId'], 'preview_'), 'preview ID should keep prefix');
    assert_same_ecs_create(32, strlen($result['previewId']), 'preview ID should include 12 random bytes as hex');
    assert_same_ecs_create(fake_preview(), $result['summary'], 'preview summary should come from provision service');
    assert_same_ecs_create(['available' => false], $result['pricing'], 'pricing should be exposed directly');
    assert_same_ecs_create(['warn'], $result['warnings'], 'warnings should be exposed directly');
    assert_same_ecs_create('203.0.113.10', $provision->previewCalls[0]['clientIp'], 'public client IP should be forwarded to preview builder');
    assert_same_ecs_create('cn-shanghai', $provision->previewCalls[0]['account']['region_id'], 'requested region should override group region');
    assert_same_ecs_create('ECS 创建预检完成 [prod] cn-shanghai ecs.e-c4m1.large', $db->logs[0]['message'], 'preview success log should keep shape');
}

function test_preview_requires_account_group(): void
{
    $service = create_ecs_create_service();

    assert_throws_ecs_create(
        fn() => $service->previewEcsCreate(['accountGroupKey' => '']),
        '请选择用于创建 ECS 的账号',
        'preview should reject missing account group'
    );
}

function test_disk_options_wraps_provider_result(): void
{
    $config = fake_ecs_create_config();
    $provision = fake_ecs_provision();
    $service = create_ecs_create_service(null, $config, $provision);

    $result = $service->getEcsDiskOptions([
        'accountGroupKey' => 'group-1',
        'regionId' => 'cn-shanghai',
        'instanceType' => 'ecs.e-c4m1.large',
    ]);

    assert_same_ecs_create(true, $result['success'], 'disk options should return success true');
    assert_same_ecs_create($provision->diskResult, $result['data'], 'disk option data should come from provision service');
    assert_same_ecs_create('cn-shanghai', $provision->diskCalls[0]['account']['region_id'], 'disk options should resolve requested region');
}

function test_create_success_records_side_effects_and_returns_task(): void
{
    $db = new FakeEcsCreateDb();
    $config = fake_ecs_create_config();
    $provision = fake_ecs_provision();
    $ddns = new FakeEcsCreateDdns();
    $notification = new FakeEcsCreateNotification();
    $service = create_ecs_create_service($db, $config, $provision, $ddns, $notification);

    $result = $service->createEcsFromPreview('preview_abc', fake_preview());
    $taskId = $result['taskId'];

    assert_same_ecs_create(true, $result['success'], 'create should return success true');
    assert_true_ecs_create(str_starts_with($taskId, 'ecs_'), 'task ID should keep ecs prefix');
    assert_same_ecs_create(24, strlen($taskId), 'task ID should include 10 random bytes as hex');
    assert_same_ecs_create(fake_create_result(), $result['data'], 'create result should be returned unchanged');
    assert_same_ecs_create(1, $config->blockCurrentlyStoppedInstancesCalls, 'create should block currently stopped instances before provisioning');
    assert_same_ecs_create('preview_abc', $db->tasks[$taskId]['previewId'], 'task should store preview ID');
    assert_same_ecs_create('group-1', $db->tasks[$taskId]['groupKey'], 'task should store group key');
    assert_same_ecs_create(['step' => '准备 VPC'], $db->taskUpdates[0]['fields'], 'progress callback should update task step');
    assert_same_ecs_create('success', end($db->taskUpdates)['fields']['status'], 'final task update should mark success');
    assert_same_ecs_create([['id' => 99, 'metadata' => [
        'public_ip' => '203.0.113.8',
        'public_ip_mode' => 'eip',
        'eip_allocation_id' => 'eip-1',
        'eip_address' => '203.0.113.8',
        'eip_managed' => 1,
        'internet_max_bandwidth_out' => 100,
    ]]], $config->networkUpdates, 'EIP metadata should be updated for created account');
    assert_same_ecs_create([[true, null]], $config->syncAccountGroupsCalls, 'account groups should sync after create');
    assert_same_ecs_create(1, $config->loadCalls, 'config should reload after account sync');
    assert_same_ecs_create('ECS 创建后', $ddns->syncCalls[0]['reason'], 'DDNS sync reason should be preserved');
    assert_same_ecs_create('prod', $notification->createdCalls[0]['label'], 'notification label should use account log label');
    assert_same_ecs_create('通知推送成功 [prod]', end($db->logs)['message'], 'notification result should be logged');
}

function test_create_failure_marks_task_failed_and_rethrows(): void
{
    $db = new FakeEcsCreateDb();
    $provision = fake_ecs_provision();
    $provision->createException = new Exception('<b>boom</b>');
    $service = create_ecs_create_service($db, fake_ecs_create_config(), $provision);

    assert_throws_ecs_create(
        fn() => $service->createEcsFromPreview('preview_abc', fake_preview()),
        '<b>boom</b>',
        'create failure should rethrow original exception'
    );

    $failedUpdate = end($db->taskUpdates);
    assert_same_ecs_create('failed', $failedUpdate['fields']['status'], 'failed create should mark task failed');
    assert_same_ecs_create('创建失败', $failedUpdate['fields']['step'], 'failed create should store failed step');
    assert_same_ecs_create('boom', $failedUpdate['fields']['error_message'], 'failed create should strip tags for task error');
    assert_same_ecs_create('error', end($db->logs)['type'], 'failed create should write error log');
    assert_same_ecs_create('一键创建 ECS 失败 [prod]: boom', end($db->logs)['message'], 'failed create log should keep message shape');
}

function test_get_ecs_create_task_delegates_to_database(): void
{
    $db = new FakeEcsCreateDb();
    $db->tasks['ecs_123'] = ['task_id' => 'ecs_123', 'status' => 'success'];
    $service = create_ecs_create_service($db);

    assert_same_ecs_create(['task_id' => 'ecs_123', 'status' => 'success'], $service->getEcsCreateTask('ecs_123'), 'task lookup should delegate to DB');
}

test_preview_success_returns_payload_and_logs();
test_preview_requires_account_group();
test_disk_options_wraps_provider_result();
test_create_success_records_side_effects_and_returns_task();
test_create_failure_marks_task_failed_and_rethrows();
test_get_ecs_create_task_delegates_to_database();

echo "EcsCreateService tests passed\n";
```

- [ ] **Step 2: Run the test to verify RED**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
```

Expected: FAIL with a fatal error that `src/EcsCreateService.php` cannot be opened or `Class "EcsCreateService" not found`.

- [ ] **Step 3: Commit the failing test**

Run:

```bash
git add tests/EcsCreateServiceTest.php
git commit -m "test: add ecs create service regression tests"
```

Expected: commit succeeds with only `tests/EcsCreateServiceTest.php` staged.

---

### Task 2: Implement EcsCreateService

**Files:**
- Create: `src/EcsCreateService.php`
- Test: `tests/EcsCreateServiceTest.php`

- [ ] **Step 1: Create the service class**

Create `src/EcsCreateService.php` and move the ECS creation workflow from `AliyunTrafficCheck.php` into it. Use this exact class outline and fill method bodies from the current `AliyunTrafficCheck` implementation:

```php
<?php

class EcsCreateService
{
    private $db;
    private $configManager;
    private $ecsProvisionService;
    private $ddnsService;
    private $notificationService;

    public function __construct($db, $configManager, $ecsProvisionService, $ddnsService, $notificationService)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->ecsProvisionService = $ecsProvisionService;
        $this->ddnsService = $ddnsService;
        $this->notificationService = $notificationService;
    }

    public function previewEcsCreate($data): array
    {
        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        $preview = $this->ecsProvisionService->buildEcsCreatePreview($account, $data, $this->detectClientPublicIp());
        $previewId = 'preview_' . bin2hex(random_bytes(12));

        $this->db->addLog('info', "ECS 创建预检完成 [{$preview['account']['label']}] {$preview['regionId']} {$preview['instanceType']}");

        return [
            'success' => true,
            'previewId' => $previewId,
            'summary' => $preview,
            'pricing' => $preview['pricing'],
            'warnings' => $preview['warnings']
        ];
    }

    public function getEcsDiskOptions($data)
    {
        $groupKey = trim((string) ($data['accountGroupKey'] ?? ''));
        if ($groupKey === '') {
            throw new Exception('请选择用于创建 ECS 的账号');
        }

        $account = $this->resolveAccountGroupForCreate($groupKey, $data['regionId'] ?? '');
        return [
            'success' => true,
            'data' => $this->ecsProvisionService->getAvailableSystemDiskOptions($account, $data)
        ];
    }

    public function createEcsFromPreview($previewId, array $preview): array
    {
        if (empty($preview['account']['groupKey'])) {
            throw new Exception('创建预检已失效，请重新预检');
        }

        $groupKey = $preview['account']['groupKey'];
        $account = $this->resolveAccountGroupForCreate($groupKey, $preview['regionId'] ?? '');
        $taskId = 'ecs_' . bin2hex(random_bytes(10));

        $this->configManager->blockCurrentlyStoppedInstances();

        $this->db->createEcsCreateTask(
            $taskId,
            $previewId,
            $groupKey,
            $preview['regionId'],
            $preview['instanceType'],
            $preview
        );

        $progress = function ($step) use ($taskId) {
            $this->db->updateEcsCreateTask($taskId, ['step' => $step]);
        };

        try {
            $result = $this->ecsProvisionService->createManagedEcsFromPreview($account, $preview, $progress);
            $this->db->updateEcsCreateTask($taskId, [
                'zone_id' => $preview['zoneId'] ?? '',
                'image_id' => $preview['imageId'] ?? '',
                'os_label' => $preview['osLabel'] ?? '',
                'instance_name' => $preview['instanceName'] ?? '',
                'vpc_id' => $result['vpcId'] ?? '',
                'vswitch_id' => $result['vswitchId'] ?? '',
                'security_group_id' => $result['securityGroupId'] ?? '',
                'internet_max_bandwidth_out' => $result['internetMaxBandwidthOut'] ?? 0,
                'system_disk_category' => $result['systemDiskCategory'] ?? '',
                'system_disk_size' => $result['systemDiskSize'] ?? 0,
                'instance_id' => $result['instanceId'] ?? '',
                'public_ip' => $result['publicIp'] ?? '',
                'public_ip_mode' => $result['publicIpMode'] ?? 'ecs_public_ip',
                'eip_allocation_id' => $result['eipAllocationId'] ?? '',
                'eip_address' => $result['eipAddress'] ?? '',
                'eip_managed' => !empty($result['eipManaged']) ? 1 : 0,
                'login_user' => $result['loginUser'] ?? '',
                'login_password' => '',
                'status' => 'success',
                'step' => '创建完成'
            ]);

            $this->configManager->syncAccountGroups(true);
            $this->configManager->load();
            $createdAccount = $this->configManager->getAccountByInstanceId($result['instanceId'] ?? '');
            if ($createdAccount && (($result['publicIpMode'] ?? '') === 'eip')) {
                $this->configManager->updateAccountNetworkMetadata($createdAccount['id'], [
                    'public_ip' => $result['publicIp'] ?? '',
                    'public_ip_mode' => 'eip',
                    'eip_allocation_id' => $result['eipAllocationId'] ?? '',
                    'eip_address' => $result['eipAddress'] ?? '',
                    'eip_managed' => 1,
                    'internet_max_bandwidth_out' => $result['internetMaxBandwidthOut'] ?? 0
                ]);
            }
            $this->ddnsService->syncForAccounts($this->configManager->getAccounts(), "ECS 创建后");
            $createLabel = Helpers::getAccountLogLabel($account);
            $this->db->addLog('info', "一键创建 ECS成功 [{$createLabel}] {$result['instanceId']} {$preview['instanceType']} {$preview['regionId']} {$result['internetMaxBandwidthOut']}Mbps");
            $notifyResult = $this->notificationService->notifyEcsCreated(Helpers::getAccountLogLabel($account), $result, $preview);
            Helpers::logNotificationResult($this->db, $notifyResult, Helpers::getAccountLogLabel($account));

            return [
                'success' => true,
                'taskId' => $taskId,
                'data' => $result
            ];
        } catch (Exception $e) {
            $this->db->updateEcsCreateTask($taskId, [
                'status' => 'failed',
                'step' => '创建失败',
                'error_message' => strip_tags($e->getMessage())
            ]);
            $failLabel = Helpers::getAccountLogLabel($account);
            $this->db->addLog('error', "一键创建 ECS 失败 [{$failLabel}]: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function getEcsCreateTask($taskId): ?array
    {
        return $this->db->getEcsCreateTask($taskId);
    }

    private function resolveAccountGroupForCreate($groupKey, $regionId = '')
    {
        $groups = $this->configManager->getAccountGroups();
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') !== $groupKey) {
                continue;
            }

            $resolvedRegion = trim((string) $regionId) ?: ($group['regionId'] ?? '');
            return [
                'id' => 0,
                'access_key_id' => $group['AccessKeyId'],
                'access_key_secret' => $group['AccessKeySecret'],
                'region_id' => $resolvedRegion,
                'group_key' => $group['groupKey'],
                'remark' => $group['remark'] ?? '',
                'site_type' => $group['siteType'] ?? 'international',
                'max_traffic' => (float) ($group['maxTraffic'] ?? 200),
                'instance_id' => '',
                'instance_name' => ''
            ];
        }

        throw new Exception('未找到对应账号，请先在账号管理中保存账号');
    }

    private function detectClientPublicIp()
    {
        $fromCf = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

        $candidates = [];
        if ($fromCf && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        foreach (['HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates[] = trim((string) $_SERVER[$key]);
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $item) {
                $candidates[] = trim($item);
            }
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        $context = stream_context_create(['http' => ['timeout' => 3]]);
        $externalIp = @file_get_contents('https://api.ipify.org', false, $context);
        if ($externalIp === false) return '';
        $externalIp = trim((string) $externalIp);
        if (filter_var($externalIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $externalIp;
        }

        return '';
    }
}
```

- [ ] **Step 2: Run the service test to verify GREEN**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
```

Expected:

```text
EcsCreateService tests passed
```

- [ ] **Step 3: Run syntax check for the new service**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/EcsCreateService.php
```

Expected:

```text
No syntax errors detected in src/EcsCreateService.php
```

- [ ] **Step 4: Commit the service implementation**

Run:

```bash
git add src/EcsCreateService.php tests/EcsCreateServiceTest.php
git commit -m "refactor: add ecs create service"
```

Expected: commit succeeds with the service and any necessary test fixes staged.

---

### Task 3: Delegate AliyunTrafficCheck ECS Create Methods

**Files:**
- Modify: `AliyunTrafficCheck.php`
- Verify: `src/EcsCreateService.php`
- Test: `tests/EcsCreateServiceTest.php`

- [ ] **Step 1: Add the service dependency to AliyunTrafficCheck**

In `AliyunTrafficCheck.php`, add:

```php
require_once 'src/EcsCreateService.php';
```

Add a private property:

```php
private $ecsCreateService;
```

After `$this->ecsProvisionService = new EcsProvisionService();` and after the other dependencies used by the service exist, construct:

```php
$this->ecsCreateService = new EcsCreateService(
    $this->db,
    $this->configManager,
    $this->ecsProvisionService,
    $this->ddnsService,
    $this->notificationService
);
```

- [ ] **Step 2: Replace ECS create method bodies with facade delegations**

In `AliyunTrafficCheck.php`, replace the four public methods with:

```php
public function previewEcsCreate($data): array
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->ecsCreateService->previewEcsCreate($data);
}

public function getEcsDiskOptions($data)
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->ecsCreateService->getEcsDiskOptions($data);
}

public function createEcsFromPreview($previewId, array $preview): array
{
    if ($this->initError) {
        throw new Exception($this->initError);
    }

    return $this->ecsCreateService->createEcsFromPreview($previewId, $preview);
}

public function getEcsCreateTask($taskId): ?array
{
    if ($this->initError) {
        return null;
    }

    return $this->ecsCreateService->getEcsCreateTask($taskId);
}
```

Remove the private methods from `AliyunTrafficCheck.php`:

```php
private function resolveAccountGroupForCreate($groupKey, $regionId = '')
private function detectClientPublicIp()
```

- [ ] **Step 3: Run regression and syntax checks**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/EcsCreateService.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/EcsCreateServiceTest.php
```

Expected:

```text
EcsCreateService tests passed
HttpRouter tests passed
AdminSupportService tests passed
No syntax errors detected in AliyunTrafficCheck.php
No syntax errors detected in src/EcsCreateService.php
No syntax errors detected in tests/EcsCreateServiceTest.php
```

- [ ] **Step 4: Confirm AliyunTrafficCheck no longer owns ECS create helpers**

Run:

```bash
rg -n "resolveAccountGroupForCreate|detectClientPublicIp|buildEcsCreatePreview|createManagedEcsFromPreview|createEcsCreateTask" AliyunTrafficCheck.php
```

Expected: no output and exit code 1.

- [ ] **Step 5: Commit the facade delegation**

Run:

```bash
git add AliyunTrafficCheck.php src/EcsCreateService.php tests/EcsCreateServiceTest.php
git commit -m "refactor: delegate ecs create workflow"
```

Expected: commit succeeds with `AliyunTrafficCheck.php` and any necessary service/test adjustments staged.

---

### Task 4: Final Verification

**Files:**
- Verify: `AliyunTrafficCheck.php`
- Verify: `src/EcsCreateService.php`
- Verify: `tests/EcsCreateServiceTest.php`

- [ ] **Step 1: Run all verification commands fresh**

Run:

```bash
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/EcsCreateService.php
docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/EcsCreateServiceTest.php
```

Expected:

```text
EcsCreateService tests passed
HttpRouter tests passed
AdminSupportService tests passed
No syntax errors detected in AliyunTrafficCheck.php
No syntax errors detected in src/EcsCreateService.php
No syntax errors detected in tests/EcsCreateServiceTest.php
```

- [ ] **Step 2: Review final diff scope**

Run:

```bash
git diff --stat HEAD~2..HEAD
git diff --name-status HEAD~2..HEAD
wc -l AliyunTrafficCheck.php src/EcsCreateService.php tests/EcsCreateServiceTest.php
```

Expected:

- New `src/EcsCreateService.php`.
- New `tests/EcsCreateServiceTest.php`.
- `AliyunTrafficCheck.php` shorter and delegating ECS create workflow.
- No changes to `HttpRouter`, frontend, database schema, `EcsProvisionService`, or `AliyunService`.

- [ ] **Step 3: Report final status**

In the final response, include:

- Files changed.
- Verification commands run and pass/fail status.
- Commit SHAs created.
- Any residual test gaps, especially that the service tests use fake dependencies rather than live Alibaba Cloud calls.

