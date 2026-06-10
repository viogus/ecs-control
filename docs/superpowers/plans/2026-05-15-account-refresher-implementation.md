# AccountRefresher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract shared traffic/status refresh pipeline from `MonitorService::handleAdaptiveHeartbeat` and `FrontendResponseBuilder::buildInstanceSnapshot` into a new `AccountRefresher` service.

**Architecture:** New `AccountRefresher` class (depends on `Database`, `AliyunService`, `ConfigManager`) with single `refresh(Account, int): RefreshResult` method. Callers handle their specific post-processing (auth recovery logging, health check, DTO building). `RefreshResult` is a simple value object carrying traffic, status, metadata, newUpdateTime, and flags.

**Tech Stack:** PHP 8.1+, no framework, SQLite, Composer classmap autoloading

---

### Task 1: Create AccountRefresher class

**Files:**
- Create: `src/AccountRefresher.php`

- [ ] **Step 1: Write the new file**

```php
<?php

declare(strict_types=1);

class RefreshResult
{
    public float $traffic;
    public string $status;
    public array $metadata;
    public int $newUpdateTime;
    public bool $authInvalid;
    public bool $trafficSuccess;

    public function __construct(
        float $traffic,
        string $status,
        array $metadata,
        int $newUpdateTime,
        bool $authInvalid,
        bool $trafficSuccess
    ) {
        $this->traffic = $traffic;
        $this->status = $status;
        $this->metadata = $metadata;
        $this->newUpdateTime = $newUpdateTime;
        $this->authInvalid = $authInvalid;
        $this->trafficSuccess = $trafficSuccess;
    }
}

class AccountRefresher
{
    private Database $db;
    private AliyunService $aliyunService;
    private ConfigManager $configManager;

    public function __construct(Database $db, AliyunService $aliyunService, ConfigManager $configManager)
    {
        $this->db = $db;
        $this->aliyunService = $aliyunService;
        $this->configManager = $configManager;
    }

    /**
     * Fetch fresh traffic + status from Alibaba Cloud and persist.
     * Callers apply their own post-processing (auth recovery, health check, DTO building).
     */
    public function refresh(Account $account, int $currentTime): RefreshResult
    {
        // 1. Fetch CDT traffic
        $trafficResult = Helpers::safeGetCdtTraffic($this->aliyunService, $account, $this->db);

        // 2. Fetch instance status
        $status = $this->safeGetInstanceStatus($account);

        // 3. Retry Unknown with 500ms delay
        if ($status === InstanceStatus::Unknown->value) {
            usleep(500000);
            $status = $this->safeGetInstanceStatus($account);
        }

        // 4. Build base metadata
        $metadata = [
            'traffic_api_status' => $trafficResult['status'] ?? 'ok',
            'traffic_api_message' => $trafficResult['message'] ?? '',
        ];

        // 5. Detect credential invalid
        $authInvalid = $this->isCredentialInvalidTrafficStatus($trafficResult['status'] ?? '');

        if ($authInvalid) {
            $metadata['protection_suspended'] = 1;
            $metadata['protection_suspend_reason'] = 'credential_invalid';
        }

        // 6. Handle traffic success/failure
        $trafficSuccess = !empty($trafficResult['success']);
        $newUpdateTime = $currentTime;

        if (!$trafficSuccess) {
            $traffic = $account->trafficUsed;
            $newUpdateTime = $account->updatedAt;
        } else {
            $traffic = (float) ($trafficResult['value'] ?? 0);
            $this->db->addHourlyStat($account->id, $traffic);
            $this->db->addDailyStat($account->id, $traffic);
        }

        // Safety: never persist a zero/negative update time
        if ($newUpdateTime <= 0) {
            $newUpdateTime = $currentTime;
        }

        // 7. Persist
        $this->configManager->updateAccountStatus($account->id, $traffic, $status, $newUpdateTime, $metadata);

        return new RefreshResult($traffic, $status, $metadata, $newUpdateTime, $authInvalid, $trafficSuccess);
    }

    private function safeGetInstanceStatus(Account $account): string
    {
        try {
            return $this->aliyunService->getInstanceStatus($account);
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例状态查询失败 [" . Helpers::getAccountLogLabel($account) . "]: " . strip_tags($e->getMessage()));
            return InstanceStatus::Unknown->value;
        }
    }

    private function isCredentialInvalidTrafficStatus($status): bool
    {
        return trim((string) $status) === 'auth_error';
    }
}
```

- [ ] **Step 2: Verify file compiles**

Run: `docker exec ecs-control php -l src/AccountRefresher.php`
Expected: `No syntax errors detected in src/AccountRefresher.php`

- [ ] **Step 3: Commit**

```bash
git add src/AccountRefresher.php
git commit -m "feat: add AccountRefresher service to extract shared refresh logic

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

### Task 2: Wire AccountRefresher into AppContainer

**Files:**
- Modify: `src/AppContainer.php`

- [ ] **Step 1: Add require and property**

Add the require at the top of AppContainer.php (after the existing `require_once` block, before the class definition):

```php
require_once 'src/AccountRefresher.php';
```

Add property in the class body (after `private $authManager`):

```php
private $accountRefresher;
```

- [ ] **Step 2: Create AccountRefresher in constructor**

Add after `$this->authManager = new AuthManager(...)` block (around line 75):

```php
$this->accountRefresher = new AccountRefresher($this->db, $this->aliyunService, $this->configManager);
```

- [ ] **Step 3: Add getter**

Add after `getAuthManager()`:

```php
public function getAccountRefresher(): AccountRefresher
{
    return $this->accountRefresher;
}
```

- [ ] **Step 4: Verify syntax**

Run: `docker exec ecs-control php -l src/AppContainer.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add src/AppContainer.php
git commit -m "feat: wire AccountRefresher into AppContainer

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

### Task 3: Refactor MonitorService to use AccountRefresher

**Files:**
- Modify: `src/MonitorService.php`

- [ ] **Step 1: Add AccountRefresher dependency to constructor**

Replace the constructor (lines 17-24) with:

```php
private $accountRefresher;

public function __construct($db, $configManager, $aliyunService, $notificationService, $ddnsService, $bssService = null, $accountRefresher = null)
{
    $this->db = $db;
    $this->configManager = $configManager;
    $this->aliyunService = $aliyunService;
    $this->notificationService = $notificationService;
    $this->ddnsService = $ddnsService;
    $this->bssService = $bssService;
    $this->accountRefresher = $accountRefresher;
}
```

- [ ] **Step 2: Replace handleAdaptiveHeartbeat body**

Replace lines 274-348 (the entire method body after the signature) with:

```php
private function handleAdaptiveHeartbeat($account, int $currentTime, int $userInterval, array &$s): void
{
    $lastUpdate = $account->updatedAt ?? 0;
    $cachedStatus = InstanceStatus::tryFrom($account->instanceStatus ?? InstanceStatus::Unknown->value) ?? InstanceStatus::Unknown;
    $isTransientState = $cachedStatus->isTransient();
    $currentInterval = $isTransientState ? 60 : $userInterval;

    $shouldCheckApi = ($currentTime - $lastUpdate) > $currentInterval;
    if (date('i') === '00') {
        $shouldCheckApi = true;
    }

    if ($shouldCheckApi) {
        $result = $this->accountRefresher->refresh($account, $currentTime);

        $s['traffic'] = $result->traffic;
        $s['status'] = $result->status;
        $s['apiStatusLog'] = $result->trafficSuccess ? '已更新' : '流量接口异常';

        // Caller-specific: auth recovery / suspended flag management
        if ($result->authInvalid) {
            $this->configManager->updateAccountStatus(
                $account->id, $result->traffic, $result->status, $result->newUpdateTime,
                ['protection_suspend_notified_at' => $s['protectionSuspendNotifiedAt']]
            );
            $s['protectionSuspended'] = true;
            $s['protectionSuspendReason'] = 'credential_invalid';
        } elseif ($s['protectionSuspended'] && $s['protectionSuspendReason'] === 'credential_invalid') {
            $this->configManager->updateAccountStatus(
                $account->id, $result->traffic, $result->status, $result->newUpdateTime,
                ['protection_suspended' => 0, 'protection_suspend_reason' => '', 'protection_suspend_notified_at' => 0]
            );
            $s['protectionSuspended'] = false;
            $s['protectionSuspendReason'] = '';
            $s['protectionSuspendNotifiedAt'] = 0;
            $this->db->addLog('info', "账号鉴权已恢复，自动停机保护已重新启用 [{$s['accountLabel']}]");
        }

        // Status logging
        $statusEnum = InstanceStatus::tryFrom($result->status) ?? InstanceStatus::Unknown;
        if ($statusEnum === InstanceStatus::Unknown) {
            $s['apiStatusLog'] .= '(状态Unknown)';
        } else {
            $s['apiStatusLog'] .= $statusEnum->isTransient() ? ' [过渡态]' : ' [稳定态]';
        }

        $this->notifyStatusChangeIfNeeded($account, $cachedStatus->value, $result->status, '系统同步检测到实例状态变化。');
    } else {
        $s['traffic'] = $account->trafficUsed;
        $s['status'] = $account->instanceStatus;
        $timeLeft = $currentInterval - ($currentTime - $lastUpdate);
        $s['apiStatusLog'] = "缓存({$timeLeft}s)";
    }
}
```

- [ ] **Step 3: Remove dead private methods**

Delete these three methods (they are now in AccountRefresher):
- `isCredentialInvalidTrafficStatus()` (lines 132-135)
- `safeGetTraffic()` (lines 137-140)
- `safeGetInstanceStatus()` (lines 196-204)

Verify none of these are called elsewhere in the file:
```bash
grep -n "safeGetTraffic\|safeGetInstanceStatus\|isCredentialInvalidTrafficStatus" src/MonitorService.php
```
Expected: only the deleted definitions appear (no other call sites).

- [ ] **Step 4: Update AppContainer::getMonitorService()**

In `src/AppContainer.php`, update `getMonitorService()` to pass the AccountRefresher:

```php
public function getMonitorService(): MonitorService
{
    return new MonitorService(
        $this->db, $this->configManager, $this->aliyunService,
        $this->notificationService, $this->ddnsService, $this->bssService,
        $this->accountRefresher
    );
}
```

- [ ] **Step 5: Verify syntax**

Run:
```bash
docker exec ecs-control php -l src/MonitorService.php
docker exec ecs-control php -l src/AppContainer.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add src/MonitorService.php src/AppContainer.php
git commit -m "refactor: delegate traffic/status refresh in MonitorService to AccountRefresher

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

### Task 4: Refactor FrontendResponseBuilder to use AccountRefresher

**Files:**
- Modify: `src/FrontendResponseBuilder.php`

- [ ] **Step 1: Add AccountRefresher dependency to constructor**

Replace the constructor (lines 12-22) with:

```php
private AccountRefresher $accountRefresher;

public function __construct(
    ConfigManager $configManager,
    Database $db,
    AliyunService $aliyunService,
    BssService $bssService,
    AccountRefresher $accountRefresher
) {
    $this->configManager = $configManager;
    $this->db = $db;
    $this->aliyunService = $aliyunService;
    $this->bssService = $bssService;
    $this->accountRefresher = $accountRefresher;
}
```

- [ ] **Step 2: Replace the refresh block in buildInstanceSnapshot**

Replace lines 170-228 (the `$currentTime = time()` through the end of the refresh `if/else` block) with:

```php
        $currentTime = time();
        $lastUpdate = (int) ($account['updated_at'] ?? 0);
        $cachedStatus = $account['instance_status'] ?? 'Unknown';
        $trafficApiStatus = $account['traffic_api_status'] ?? 'ok';
        $trafficApiMessage = $account['traffic_api_message'] ?? '';

        $isTransientState = in_array($cachedStatus, [InstanceStatus::Starting->value, InstanceStatus::Stopping->value, InstanceStatus::Pending->value, InstanceStatus::Unknown->value], true);
        $checkInterval = $isTransientState ? 60 : $userInterval;

        if ($forceRefresh || ($currentTime - $lastUpdate) > $checkInterval) {
            $result = $this->accountRefresher->refresh($account, $currentTime);

            $traffic = $result->traffic;
            $status = $result->status;
            $trafficApiStatus = $result->metadata['traffic_api_status'];
            $trafficApiMessage = $result->metadata['traffic_api_message'];
            $lastUpdate = $result->newUpdateTime;

            // Caller-specific: hard-reset protection_suspended when not auth_invalid
            if (!$result->authInvalid) {
                $this->configManager->updateAccountStatus(
                    $account['id'], $traffic, $status, $result->newUpdateTime,
                    ['protection_suspended' => 0, 'protection_suspend_reason' => '', 'protection_suspend_notified_at' => 0]
                );
            }

            // Caller-specific: health check on Running instances
            if ($status === InstanceStatus::Running->value && ($account['health_status'] ?? '') !== 'OK') {
                $full = $this->safeGetInstanceFullStatus($account);
                if ($full) {
                    $this->configManager->updateAccountStatus(
                        $account['id'], $traffic, $status, $result->newUpdateTime,
                        ['health_status' => $full['healthStatus']]
                    );
                }
            }
        } else {
            $traffic = (float) ($account['traffic_used'] ?? 0);
            $status = $cachedStatus;
        }
```

Note: This replaces the old refresh block while preserving lines 229-281 (the snapshot DTO building).

- [ ] **Step 3: Remove dead private methods**

Delete these three methods (now in AccountRefresher):
- `safeGetTraffic()` (lines 289-292)
- `safeGetInstanceStatus()` (lines 294-298)
- `isCredentialInvalidTrafficStatus()` (lines 306-309)

Verify no other call sites:
```bash
grep -n "safeGetTraffic\|safeGetInstanceStatus\|isCredentialInvalidTrafficStatus" src/FrontendResponseBuilder.php
```
Expected: only the deleted definitions appear. `safeGetInstanceFullStatus()` (line 300) is a different method and must stay.

- [ ] **Step 4: Update AppContainer to pass AccountRefresher to FrontendResponseBuilder**

In `src/AppContainer.php`, update the FrontendResponseBuilder construction (around line 57):

```php
$this->responseBuilder = new FrontendResponseBuilder(
    $this->configManager, $this->db, $this->aliyunService, $this->bssService,
    $this->accountRefresher
);
```

- [ ] **Step 5: Verify syntax**

Run:
```bash
docker exec ecs-control php -l src/FrontendResponseBuilder.php
docker exec ecs-control php -l src/AppContainer.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add src/FrontendResponseBuilder.php src/AppContainer.php
git commit -m "refactor: delegate traffic/status refresh in FrontendResponseBuilder to AccountRefresher

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

### Task 5: Run tests and verify

**Files:**
- Test: `tests/HttpRouterTest.php`
- Test: `tests/MonitorServiceTest.php`

- [ ] **Step 1: Run all tests**

```bash
docker exec ecs-control php /var/www/html/tests/HttpRouterTest.php
docker exec ecs-control php /var/www/html/tests/MonitorServiceTest.php
```
Expected: Both output `HttpRouter tests passed` and `MonitorService tests passed`.

- [ ] **Step 2: Quick integration smoke test**

```bash
docker exec ecs-control php -r "
require 'vendor/autoload.php';
require 'src/AppContainer.php';
\$app = new AppContainer();
\$refresher = \$app->getAccountRefresher();
echo 'AccountRefresher created: ' . get_class(\$refresher) . PHP_EOL;
\$monitor = \$app->getMonitorService();
echo 'MonitorService created' . PHP_EOL;
\$builder = \$app->getResponseBuilder();
echo 'FrontendResponseBuilder created' . PHP_EOL;
echo 'All services wired OK' . PHP_EOL;
"
```
Expected: Output confirms all three services created without errors.

- [ ] **Step 3: Final commit (if any fixes needed)**

Only if tests or smoke test required changes:
```bash
git add -A
git commit -m "fix: test adjustments after AccountRefresher extraction

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```
