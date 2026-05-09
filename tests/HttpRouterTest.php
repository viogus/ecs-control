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
