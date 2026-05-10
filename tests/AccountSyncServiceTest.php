<?php

final class AliyunService
{
    public array $calls = [];

    public function getInstances($key, $secret, $targetRegionId = null): array
    {
        $this->calls[] = [$key, $secret, $targetRegionId];
        return [];
    }
}

require_once __DIR__ . '/../src/SchemaManager.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../src/AccountSyncService.php';

function assert_same_account_sync($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function create_account_sync_test_db(): Database
{
    $dbFile = tempnam(sys_get_temp_dir(), 'ecs-control-account-sync-');
    if ($dbFile === false) {
        throw new Exception('Unable to create temporary database');
    }
    unlink($dbFile);

    return new Database($dbFile);
}

function test_sync_preserves_existing_rows_when_remote_response_is_empty(): void
{
    $db = create_account_sync_test_db();
    $pdo = $db->getPdo();
    $now = time();

    $pdo->prepare("INSERT INTO accounts (access_key_id, access_key_secret, region_id, instance_id, max_traffic, instance_status, updated_at, remark, site_type, group_key, instance_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute(['AKID1234567890', 'secret', 'eu-central-1', 'i-existing', 100, 'Running', $now, 'prod', 'international', 'group-1', 'existing-node']);

    $groups = [[
        'groupKey' => 'group-1',
        'AccessKeyId' => 'AKID1234567890',
        'AccessKeySecret' => 'secret',
        'regionId' => 'eu-central-1',
        'maxTraffic' => 100,
        'remark' => 'prod',
        'siteType' => 'international',
        'scheduleEnabled' => false,
        'scheduleStartEnabled' => false,
        'scheduleStopEnabled' => false,
        'scheduleBlockedByTraffic' => false,
    ]];

    $service = new AccountSyncService($db, [], str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service->syncAccountGroups($groups);

    $remainingRows = $pdo->query("SELECT id, instance_id, group_key FROM accounts ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    assert_same_account_sync(1, count($remainingRows), 'empty remote response should not delete existing group rows');
    assert_same_account_sync('i-existing', $remainingRows[0]['instance_id'], 'existing row should remain when remote response is empty');
    assert_same_account_sync('group-1', $remainingRows[0]['group_key'], 'existing group key should remain');
}

test_sync_preserves_existing_rows_when_remote_response_is_empty();

echo "AccountSyncService tests passed\n";
