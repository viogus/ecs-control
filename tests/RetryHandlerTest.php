<?php

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/RetryHandler.php';

use AlibabaCloud\Client\Exception\ClientException;

function assert_retry_php($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, '  Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "  ok - {$label}\n";
}

$networkError = new ClientException('Unable to connect server: timed out', 'SDK.ServerUnreachable');

// ---- 1. 默认不重试传输层错误（保护非幂等写操作）----

$calls = 0;
try {
    RetryHandler::execute(function () use (&$calls, $networkError) {
        $calls++;
        throw $networkError;
    }, 'controlInstance');
    assert_retry_php(false, true, '默认配置下网络错误应抛出异常');
} catch (ClientException $e) {
    assert_retry_php('SDK.ServerUnreachable', $e->getErrorCode(), '默认不重试:异常原样抛出');
}
assert_retry_php(1, $calls, '默认 networkRetries=0 时只尝试一次');

// ---- 2. 幂等读操作显式开启后追加一次尝试 ----

$calls = 0;
$result = RetryHandler::execute(function () use (&$calls, $networkError) {
    $calls++;
    if ($calls === 1) {
        throw $networkError;
    }
    return 'traffic-ok';
}, 'getTraffic', 3, 1);
assert_retry_php('traffic-ok', $result, '首次网络失败后重试成功');
assert_retry_php(2, $calls, '网络错误只追加一次尝试');

// ---- 3. 非网络类客户端错误即使开启也不重试 ----

$calls = 0;
try {
    RetryHandler::execute(function () use (&$calls) {
        $calls++;
        throw new ClientException('Speicified endpoint or uri is not valid', 'SDK.InvalidRegionId');
    }, 'getTraffic', 3, 1);
    assert_retry_php(false, true, '非网络错误应抛出异常');
} catch (ClientException $e) {
    assert_retry_php('SDK.InvalidRegionId', $e->getErrorCode(), '非网络错误不重试:原样抛出');
}
assert_retry_php(1, $calls, '非网络错误只尝试一次');

echo "RetryHandler tests passed\n";
