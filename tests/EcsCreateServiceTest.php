<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../src/EcsCreateService.php';

final class FakeEcsCreateDb extends Database
{
    public array $logs = [];
    public array $tasks = [];
    public array $taskUpdates = [];

    public function __construct()
    {
    }

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
    $_SERVER = ['REMOTE_ADDR' => '8.8.8.8'];
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
    assert_same_ecs_create('8.8.8.8', $provision->previewCalls[0]['clientIp'], 'public client IP should be forwarded to preview builder');
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
