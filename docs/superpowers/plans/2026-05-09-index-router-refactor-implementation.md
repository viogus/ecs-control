# index.php Router Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move action dispatch, authentication gates, and CSRF enforcement out of `index.php` into a small `HttpRouter` class while preserving the existing `index.php?action=xxx` contract.

**Architecture:** `index.php` remains the single web entry point and process bootstrap. A new global `HttpRouter` class in `src/HttpRouter.php` owns action dispatch and delegates business behavior to `AliyunTrafficCheck`. A dependency-free PHP test script exercises router behavior with a fake app object so no live Alibaba Cloud credentials are needed.

**Tech Stack:** PHP 8.1+, native sessions/superglobals, Composer classmap autoloading, no framework, no PHPUnit dependency.

---

## File Structure

- Create: `tests/HttpRouterTest.php`
  - Standalone PHP test runner.
  - Defines a fake app with the public methods used by representative router actions.
  - Calls `HttpRouter::dispatch()` directly under controlled `$_SESSION`, `$_SERVER`, `$_GET`, and `$_POST` state.
- Create: `src/HttpRouter.php`
  - Global class, no namespace.
  - Contains action sets, CSRF helpers, JSON body reader, response helpers, and per-action handlers moved from `index.php`.
  - Does not create `AliyunTrafficCheck`; it receives the app instance.
- Modify: `index.php`
  - Keep process setup only.
  - Require `AliyunTrafficCheck.php` and `src/HttpRouter.php`.
  - Instantiate `AliyunTrafficCheck` and `HttpRouter`.
  - Dispatch `$_GET['action'] ?? 'view'`.

---

### Task 1: Add Router Regression Tests

**Files:**
- Create: `tests/HttpRouterTest.php`

- [ ] **Step 1: Write the failing test runner**

Create `tests/HttpRouterTest.php` with this content:

```php
<?php

require_once __DIR__ . '/../src/HttpRouter.php';

final class FakeRouterApp
{
    public array $calls = [];
    public bool $initialized = false;
    public ?string $initError = null;

    public function getInitError(): ?string
    {
        $this->calls[] = ['getInitError'];
        return $this->initError;
    }

    public function isInitialized(): bool
    {
        $this->calls[] = ['isInitialized'];
        return $this->initialized;
    }

    public function getPublicBrand()
    {
        $this->calls[] = ['getPublicBrand'];
        return ['name' => 'ECS Control'];
    }

    public function getConfigForFrontend(): array
    {
        $this->calls[] = ['getConfigForFrontend'];
        return ['site_name' => 'demo'];
    }

    public function controlInstanceAction($accountId, $action, $shutdownMode = 'KeepCharging', $waitForSync = true)
    {
        $this->calls[] = ['controlInstanceAction', $accountId, $action, $shutdownMode, $waitForSync];
        return true;
    }

    public function renderTemplate(): string
    {
        $this->calls[] = ['renderTemplate'];
        return '<html>view</html>';
    }
}

function reset_router_state(): void
{
    $_SESSION = [];
    $_SERVER = [];
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    http_response_code(200);
}

function dispatch_router(string $action, FakeRouterApp $app): string
{
    $router = new HttpRouter($app, dirname(__DIR__));
    ob_start();
    $router->dispatch($action);
    return ob_get_clean();
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function test_protected_action_requires_login(): void
{
    reset_router_state();
    $app = new FakeRouterApp();

    $output = dispatch_router('get_config', $app);

    assert_same(403, http_response_code(), 'protected action should return HTTP 403');
    assert_same('{"error":"请先登录后再操作"}', $output, 'protected action should keep the existing auth error JSON');
    assert_same([], $app->calls, 'protected action should not call the app when unauthenticated');
}

function test_get_config_adds_csrf_token_for_logged_in_session(): void
{
    reset_router_state();
    $_SESSION['is_admin'] = true;

    $app = new FakeRouterApp();
    $output = dispatch_router('get_config', $app);
    $payload = json_decode($output, true);

    assert_same(200, http_response_code(), 'get_config should return HTTP 200');
    assert_same('demo', $payload['site_name'] ?? null, 'get_config should include frontend config');
    assert_true(!empty($payload['csrf_token']), 'get_config should include a CSRF token');
    assert_same($payload['csrf_token'], $_SESSION['csrf_token'] ?? null, 'get_config should store token in session');
}

function test_mutating_action_requires_valid_csrf_before_calling_app(): void
{
    reset_router_state();
    $_SESSION['is_admin'] = true;
    $_SESSION['csrf_token'] = 'known-token';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';

    $app = new FakeRouterApp();
    $output = dispatch_router('control_instance', $app);

    assert_same(403, http_response_code(), 'invalid CSRF should return HTTP 403');
    assert_same('{"error":"CSRF 验证失败，请刷新页面后重试"}', $output, 'invalid CSRF should keep the existing CSRF error JSON');
    assert_same([], $app->calls, 'invalid CSRF should stop before calling mutating app methods');
}

function test_control_instance_invalid_action_keeps_bad_request_response(): void
{
    reset_router_state();
    $_SESSION['is_admin'] = true;
    $_SESSION['csrf_token'] = 'known-token';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'known-token';
    $GLOBALS['HTTP_ROUTER_TEST_INPUT'] = json_encode([
        'accountId' => 10,
        'action' => 'reboot',
        'shutdownMode' => 'KeepCharging',
    ]);

    $app = new FakeRouterApp();
    $output = dispatch_router('control_instance', $app);
    unset($GLOBALS['HTTP_ROUTER_TEST_INPUT']);

    assert_same(400, http_response_code(), 'invalid instance action should return HTTP 400');
    assert_same('{"success":false,"message":"无效的操作类型"}', $output, 'invalid instance action should keep existing JSON');
    assert_same([], $app->calls, 'invalid instance action should not call the app');
}

function test_unknown_action_renders_template(): void
{
    reset_router_state();
    $app = new FakeRouterApp();

    $output = dispatch_router('unknown_action', $app);

    assert_same(200, http_response_code(), 'unknown action should fall through with HTTP 200');
    assert_same('<html>view</html>', $output, 'unknown action should render the app template');
    assert_same([['renderTemplate']], $app->calls, 'unknown action should call renderTemplate');
}

test_protected_action_requires_login();
test_get_config_adds_csrf_token_for_logged_in_session();
test_mutating_action_requires_valid_csrf_before_calling_app();
test_control_instance_invalid_action_keeps_bad_request_response();
test_unknown_action_renders_template();

echo "HttpRouter tests passed\n";
```

- [ ] **Step 2: Run the test to verify RED**

Run:

```bash
php tests/HttpRouterTest.php
```

Expected: FAIL with a fatal error that `src/HttpRouter.php` cannot be opened or `Class "HttpRouter" not found`.

- [ ] **Step 3: Commit the failing test**

Run:

```bash
git add tests/HttpRouterTest.php
git commit -m "test: add router dispatch regression tests"
```

Expected: commit succeeds with only `tests/HttpRouterTest.php` staged.

---

### Task 2: Implement HttpRouter

**Files:**
- Create: `src/HttpRouter.php`
- Test: `tests/HttpRouterTest.php`

- [ ] **Step 1: Add the router class**

Create `src/HttpRouter.php` with a global `HttpRouter` class. The implementation should move the existing action blocks from `index.php` into methods on the class.

The class structure must be:

```php
<?php

class HttpRouter
{
    private $app;
    private string $baseDir;

    private array $mutatingActions = [
        'save_config', 'upload_logo', 'send_test_email', 'send_test_telegram',
        'send_test_webhook', 'refresh_account', 'fetch_instances', 'test_account',
        'sync_account_group', 'restore_schedule_block', 'preview_ecs_create',
        'get_ecs_disk_options', 'create_ecs', 'clear_logs',
        'control_instance', 'delete_instance', 'replace_instance_ip', 'logout', 'export',
        'get_all_instances',
    ];

    public function __construct($app, ?string $baseDir = null)
    {
        $this->app = $app;
        $this->baseDir = $baseDir ?: dirname(__DIR__);
    }

    public function dispatch(string $action): void
    {
        if ($this->dispatchPublicAction($action)) {
            return;
        }

        if ($action !== 'view' && !$this->isLoggedIn()) {
            $this->json(['error' => '请先登录后再操作'], 403);
            return;
        }

        if (in_array($action, $this->mutatingActions, true) && !$this->requireCsrf()) {
            return;
        }

        $this->dispatchAuthenticatedAction($action);
    }

    private function dispatchPublicAction(string $action): bool
    {
        switch ($action) {
            case 'check_init':
                $this->handleCheckInit();
                return true;
            case 'setup':
                $this->handleSetup();
                return true;
            case 'login':
                $this->handleLogin();
                return true;
            case 'check_login':
                $this->handleCheckLogin();
                return true;
            case 'brand_logo':
                $this->handleBrandLogo();
                return true;
            case 'get_status':
                $this->handleGetStatus();
                return true;
        }

        return false;
    }

    private function dispatchAuthenticatedAction(string $action): void
    {
        switch ($action) {
            case 'export':
                $this->handleExport();
                return;
            case 'get_config':
                $this->handleGetConfig();
                return;
            case 'save_config':
                $this->handleSaveConfig();
                return;
            case 'upload_logo':
                $this->handleUploadLogo();
                return;
            case 'send_test_email':
                $this->handleSendTestEmail();
                return;
            case 'send_test_telegram':
                $this->handleSendTestTelegram();
                return;
            case 'send_test_webhook':
                $this->handleSendTestWebhook();
                return;
            case 'refresh_account':
                $this->handleRefreshAccount();
                return;
            case 'fetch_instances':
                $this->handleFetchInstances();
                return;
            case 'test_account':
                $this->handleTestAccount();
                return;
            case 'sync_account_group':
                $this->handleSyncAccountGroup();
                return;
            case 'restore_schedule_block':
                $this->handleRestoreScheduleBlock();
                return;
            case 'preview_ecs_create':
                $this->handlePreviewEcsCreate();
                return;
            case 'get_ecs_disk_options':
                $this->handleGetEcsDiskOptions();
                return;
            case 'create_ecs':
                $this->handleCreateEcs();
                return;
            case 'get_ecs_create_task':
                $this->handleGetEcsCreateTask();
                return;
            case 'get_logs':
                $this->handleGetLogs();
                return;
            case 'clear_logs':
                $this->handleClearLogs();
                return;
            case 'get_history':
                $this->handleGetHistory();
                return;
            case 'logout':
                $this->handleLogout();
                return;
            case 'get_all_instances':
                $this->handleGetAllInstances();
                return;
            case 'control_instance':
                $this->handleControlInstance();
                return;
            case 'delete_instance':
                $this->handleDeleteInstance();
                return;
            case 'replace_instance_ip':
                $this->handleReplaceInstanceIp();
                return;
        }

        echo $this->app->renderTemplate();
    }

    private function json(array $payload, int $status = 200, int $flags = 0, bool $withCharset = false): void
    {
        http_response_code($status);
        header('Content-Type: application/json' . ($withCharset ? '; charset=utf-8' : ''));
        echo json_encode($payload, $flags);
    }

    private function readJsonBody(): array
    {
        if (isset($GLOBALS['HTTP_ROUTER_TEST_INPUT'])) {
            $raw = (string) $GLOBALS['HTTP_ROUTER_TEST_INPUT'];
        } else {
            $raw = file_get_contents('php://input');
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function ensureCsrfToken(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function requireCsrf(): bool
    {
        $this->ensureCsrfToken();
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($header) || !hash_equals($_SESSION['csrf_token'], $header)) {
            $this->json(['error' => 'CSRF 验证失败，请刷新页面后重试'], 403);
            return false;
        }

        return true;
    }

    private function isLoggedIn(): bool
    {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }
}
```

Then add private `handle...()` methods by moving the body of each existing `if ($action === '...')` block from `index.php` into the matching method. Keep the exact payloads, status codes, session writes, file lookup logic, and JSON flags from the current `index.php`.

- [ ] **Step 2: Run the router tests to verify GREEN**

Run:

```bash
php tests/HttpRouterTest.php
```

Expected: PASS with exactly:

```text
HttpRouter tests passed
```

- [ ] **Step 3: Run PHP syntax check on the new file**

Run:

```bash
php -l src/HttpRouter.php
```

Expected:

```text
No syntax errors detected in src/HttpRouter.php
```

- [ ] **Step 4: Commit the router implementation**

Run:

```bash
git add src/HttpRouter.php tests/HttpRouterTest.php
git commit -m "refactor: add http router for action dispatch"
```

Expected: commit succeeds with `src/HttpRouter.php` and any necessary test adjustment staged.

---

### Task 3: Shrink index.php To Bootstrap

**Files:**
- Modify: `index.php`
- Test: `tests/HttpRouterTest.php`

- [ ] **Step 1: Replace index.php dispatch logic**

Replace the contents of `index.php` with:

```php
<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_start();

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'AliyunTrafficCheck.php';
require_once 'src/HttpRouter.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$app = new AliyunTrafficCheck();
$router = new HttpRouter($app, __DIR__);
$router->dispatch($_GET['action'] ?? 'view');
```

- [ ] **Step 2: Run the router tests**

Run:

```bash
php tests/HttpRouterTest.php
```

Expected: PASS with exactly:

```text
HttpRouter tests passed
```

- [ ] **Step 3: Run PHP syntax checks**

Run:

```bash
php -l index.php
php -l src/HttpRouter.php
```

Expected:

```text
No syntax errors detected in index.php
No syntax errors detected in src/HttpRouter.php
```

- [ ] **Step 4: Verify all existing action strings remain handled**

Run:

```bash
for action in check_init setup login check_login brand_logo get_status export get_config save_config upload_logo send_test_email send_test_telegram send_test_webhook refresh_account fetch_instances test_account sync_account_group restore_schedule_block preview_ecs_create get_ecs_disk_options create_ecs get_ecs_create_task get_logs clear_logs get_history logout get_all_instances control_instance delete_instance replace_instance_ip; do rg -q "case '$action'" src/HttpRouter.php || { echo "missing $action"; exit 1; }; done
```

Expected: no output and exit code 0.

- [ ] **Step 5: Commit the bootstrap reduction**

Run:

```bash
git add index.php src/HttpRouter.php tests/HttpRouterTest.php
git commit -m "refactor: reduce index to web bootstrap"
```

Expected: commit succeeds with the final bootstrap change.

---

### Task 4: Final Verification

**Files:**
- Verify: `index.php`
- Verify: `src/HttpRouter.php`
- Verify: `tests/HttpRouterTest.php`

- [ ] **Step 1: Run all local verification commands**

Run:

```bash
php tests/HttpRouterTest.php
php -l index.php
php -l src/HttpRouter.php
php -l tests/HttpRouterTest.php
```

Expected:

```text
HttpRouter tests passed
No syntax errors detected in index.php
No syntax errors detected in src/HttpRouter.php
No syntax errors detected in tests/HttpRouterTest.php
```

- [ ] **Step 2: Review final diff**

Run:

```bash
git diff --stat HEAD~3..HEAD
git diff HEAD~3..HEAD -- index.php src/HttpRouter.php tests/HttpRouterTest.php
```

Expected:

- `index.php` contains only bootstrap setup and router dispatch.
- `src/HttpRouter.php` contains the moved action handling logic.
- `tests/HttpRouterTest.php` contains dependency-free router regression tests.
- No frontend files, database files, or business service files changed.

- [ ] **Step 3: Report verification evidence**

In the final response, include:

- Files changed.
- Commands run.
- Whether each command passed.
- Any verification not run, such as Docker smoke tests, with the reason.

