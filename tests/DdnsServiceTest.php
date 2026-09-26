<?php
/**
 * DdnsService 记录名/公网 IP 算法测试。
 *
 * 验收向量来自 tests/ddns-name-vectors.json(PHP 与 cf-worker 共用,由
 * tests/generate-ddns-vectors.php 生成),cf-worker/src/ddns.test.ts 跑同一份向量。
 * 任何一侧改了算法而没同步另一侧,这两个测试中的一个必然失败。
 */

require_once __DIR__ . '/../DdnsService.php';

final class DdnsVectorConfigStub
{
    private array $groups;

    public function __construct(array $groups)
    {
        $this->groups = $groups;
    }

    public function getAccountGroups(): array
    {
        return $this->groups;
    }
}

function ddns_assert($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, '  Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "  ok - {$label}\n";
}

function ddns_fail(string $label): void
{
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

function ddns_service(string $domain = 'cdf.mba', $configManager = null): DdnsService
{
    return new DdnsService(['ddns_domain' => $domain], null, $configManager);
}

$vectorFile = __DIR__ . '/ddns-name-vectors.json';
$vectors = json_decode((string) file_get_contents($vectorFile), true);
if (!is_array($vectors)) {
    ddns_fail("无法解析 {$vectorFile},请先运行 tests/generate-ddns-vectors.php");
}

// ---- 1. 记录名(PHP 与 worker 必须逐字节一致)----
foreach ($vectors['nameVectors'] as $index => $vector) {
    $input = $vector['input'];
    $service = ddns_service($input['domain']);
    $label = "name #{$index} " . json_encode($input, JSON_UNESCAPED_UNICODE);
    try {
        $actual = $service->buildRecordName([
            'account_remark' => $input['account_remark'],
            'remark' => $input['remark'],
            'instance_name' => $input['instance_name'],
            'instance_id' => $input['instance_id'],
        ], $input['same_group_instance_count']);
        ddns_assert($vector['expected'] ?? null, $actual, $label);
    } catch (\Exception $e) {
        ddns_assert($vector['error'] ?? null, $e->getMessage(), $label . ' (异常)');
    }
}

// ---- 2. 根域名归一化 ----
$normalize = new ReflectionMethod(DdnsService::class, 'normalizeDomain');
$normalize->setAccessible(true);
$normalizeService = ddns_service();
foreach ($vectors['domainVectors'] as $index => $vector) {
    ddns_assert(
        $vector['expected'],
        $normalize->invoke($normalizeService, $vector['input']),
        "domain #{$index} " . json_encode($vector['input'])
    );
}

// ---- 3. 账号组备注解析(优先组备注,回退实例备注)----
foreach ($vectors['remarkVectors'] as $index => $vector) {
    $service = ddns_service('cdf.mba', new DdnsVectorConfigStub($vector['groups']));
    ddns_assert(
        $vector['expected'],
        $service->resolveGroupRemark($vector['account']),
        "group remark #{$index} " . json_encode($vector['account'])
    );
}

// ---- 4. 组内实例计数 ----
foreach ($vectors['groupCountVectors'] as $index => $vector) {
    ddns_assert(
        $vector['expected'],
        ddns_service()->getGroupCounts($vector['accounts']),
        "group counts #{$index}"
    );
}

// ---- 5. 生效公网 IP 选择 ----
foreach ($vectors['ipSelectionVectors'] as $index => $vector) {
    $account = [
        'public_ip_mode' => $vector['public_ip_mode'],
        'eip_address' => $vector['eip_address'],
        'public_ip' => $vector['public_ip'],
    ];
    ddns_assert(
        $vector['expected'],
        ddns_service()->getEffectivePublicIp($account),
        "public ip #{$index} " . json_encode($account)
    );
}

// ---- 6. 公网 IPv4 判定向量本身必须与 PHP filter_var 一致 ----
foreach ($vectors['ipVectors'] as $index => $vector) {
    $actual = filter_var(
        $vector['ip'],
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
    ddns_assert($vector['public'], $actual, "ip #{$index} " . json_encode($vector['ip']));
}

// ---- 7. slug 不再依赖 iconv(musl 生产镜像与 glibc CI 的 //TRANSLIT 结果不同)----
$source = (string) file_get_contents(__DIR__ . '/../DdnsService.php');
ddns_assert(false, str_contains($source, '@iconv('), 'slug 不应再调用 iconv');
ddns_assert(false, str_contains($source, "function_exists('iconv')"), 'slug 不应再探测 iconv 扩展');

// ---- 8. ASCII 折叠表必须与 cf-worker/src/ddns.ts 完全一致 ----
$phpFold = (new ReflectionClass(DdnsService::class))->getConstant('ASCII_FOLD');
$tsSource = (string) file_get_contents(__DIR__ . '/../cf-worker/src/ddns.ts');
if (!preg_match("/export const ASCII_FOLD\s*=\s*'([^']*)'/", $tsSource, $matches)) {
    ddns_fail('无法从 cf-worker/src/ddns.ts 解析 ASCII_FOLD');
}
ddns_assert($phpFold, $matches[1], 'ASCII_FOLD 两端逐字符一致');

echo "DdnsService tests passed\n";
