<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AliyunService.php';
require_once __DIR__ . '/../src/Helpers.php';

use AlibabaCloud\Client\Exception\ClientException;

final class FakeCdtDb extends Database
{
    public array $logs = [];

    public function __construct()
    {
        // 不建立真实连接：仅收集日志
    }

    public function addLog($type, $message): void
    {
        $this->logs[] = ['type' => $type, 'message' => $message];
    }

    public function logsOfType(string $type): array
    {
        $out = [];
        foreach ($this->logs as $log) {
            if ($log['type'] === $type) $out[] = $log['message'];
        }
        return $out;
    }

    public function allLogText(): string
    {
        $out = '';
        foreach ($this->logs as $log) $out .= $log['message'] . "\n";
        return $out;
    }
}

final class FakeCdtAliyun extends AliyunService
{
    public ?\Throwable $trafficException = null;
    public float $trafficValue = 3.25;

    public function getTraffic($key, $secret, $regionId)
    {
        if ($this->trafficException) {
            throw $this->trafficException;
        }
        return $this->trafficValue;
    }
}

function assert_cdt(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "  ok - {$label}\n";
}

function cdt_account(): array
{
    return [
        'access_key_id' => 'AKIDtest',
        'access_key_secret' => 'secret',
        'region_id' => 'cn-hangzhou',
        'remark' => 'hk2',
    ];
}

function classify_cdt(?\Throwable $exception): array
{
    $db = new FakeCdtDb();
    $aliyun = new FakeCdtAliyun();
    $aliyun->trafficException = $exception;
    $result = Helpers::safeGetCdtTraffic($aliyun, cdt_account(), $db);
    return ['result' => $result, 'db' => $db];
}

// ---- 0. 前提自检：SDK 异常构造顺序与网络识别 ----

$probe = new ClientException('Unable to connect server: timed out', 'SDK.ServerUnreachable');
assert_cdt($probe->getErrorCode() === 'SDK.ServerUnreachable', 'ClientException(message, errorCode) 构造顺序符合预期');
assert_cdt(Helpers::isNetworkError('SDK.ServerUnreachable', $probe->getMessage()), 'SDK.ServerUnreachable 被识别为网络错误');
assert_cdt(!Helpers::isNetworkError('InvalidAccessKeyId.NotFound', 'AccessKeyId is not found'), '凭证错误不落入网络错误判定');

// ---- 1. SDK.ServerUnreachable：网络抖动不得判定为鉴权/权限问题 ----

$hit = classify_cdt(new ClientException('Unable to connect server: timed out', 'SDK.ServerUnreachable'));
assert_cdt($hit['result']['success'] === false, 'SDK.ServerUnreachable: success=false');
assert_cdt($hit['result']['status'] === 'timeout', "SDK.ServerUnreachable 归类为 timeout（实际 {$hit['result']['status']}）");
assert_cdt($hit['result']['status'] !== 'auth_error', 'SDK.ServerUnreachable 不再归类为 auth_error（否则会暂停自动停机保护）');
assert_cdt(strpos($hit['db']->allLogText(), '请确认 AK 拥有 CDT 权限') === false, '不再误报「请确认 AK 拥有 CDT 权限」');
assert_cdt(count($hit['db']->logsOfType('warning')) === 1, '网络错误记一条 warning');
assert_cdt(count($hit['db']->logsOfType('error')) === 0, '网络错误不再记 error');

// ---- 2. SDK.Timeout 等同网络错误 ----

$hit = classify_cdt(new ClientException('SDK timeout', 'SDK.Timeout'));
assert_cdt($hit['result']['status'] === 'timeout', 'SDK.Timeout 归类为 timeout');

// ---- 3. 真正的 AK 失效仍判 auth_error ----

$hit = classify_cdt(new ClientException('AccessKeyId is not found', 'InvalidAccessKeyId.NotFound'));
assert_cdt($hit['result']['status'] === 'auth_error', 'InvalidAccessKeyId.NotFound 仍归类为 auth_error');
assert_cdt(count($hit['db']->logsOfType('error')) === 1, 'AK 失效记一条 error');

// ---- 4. 非网络、非凭证的 SDK 客户端错误（如端点配置错误）不再判 auth_error ----

$hit = classify_cdt(new ClientException('Speicified endpoint or uri is not valid', 'SDK.InvalidRegionId'));
assert_cdt($hit['result']['status'] === 'sync_error', "端点配置错误归类为 sync_error（实际 {$hit['result']['status']}）");
assert_cdt(strpos($hit['db']->allLogText(), '请确认 AK 拥有 CDT 权限') === false, '端点配置错误不与 AK 权限混淆');

// ---- 5. 普通 Exception 中的 cURL 网络错误归类 timeout ----

$hit = classify_cdt(new \Exception('cURL error 28: Operation timed out after 20000 milliseconds'));
assert_cdt($hit['result']['status'] === 'timeout', 'cURL error 归类为 timeout');
assert_cdt(count($hit['db']->logsOfType('warning')) === 1, 'cURL 错误记 warning');

// ---- 6. 响应结构异常仍为 sync_error（不涉及鉴权）----

$hit = classify_cdt(new \Exception('API 响应缺少 TrafficDetails 字段'));
assert_cdt($hit['result']['status'] === 'sync_error', '响应缺字段归类为 sync_error');
assert_cdt($hit['result']['status'] !== 'auth_error', '响应缺字段不归类为 auth_error');

// ---- 7. 成功路径不受影响 ----

$hit = classify_cdt(null);
assert_cdt($hit['result']['success'] === true, '成功路径 success=true');
assert_cdt($hit['result']['status'] === 'ok', '成功路径 status=ok');
assert_cdt((float) $hit['result']['value'] === 3.25, '成功路径返回流量值');

echo "Helpers (CDT 错误分类) tests passed\n";
