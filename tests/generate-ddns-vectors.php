<?php
/**
 * 生成 tests/ddns-name-vectors.json —— PHP 与 cf-worker 共用的 DDNS 算法验收向量。
 * 期望值全部由 PHP 实现产出,worker(vitest) 必须完全一致。
 * 用法:docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/generate-ddns-vectors.php
 */

require_once __DIR__ . '/../DdnsService.php';
require_once __DIR__ . '/../src/Helpers.php';

final class VectorGroupStub
{
    private array $groups;
    public function __construct(array $groups) { $this->groups = $groups; }
    public function getAccountGroups(): array { return $this->groups; }
}

function makeNameService(string $domain): DdnsService
{
    return new DdnsService(['ddns_domain' => $domain]);
}

// ---- 记录名向量 ----
$nameInputs = [
    ['cdf.mba', 'uk', 'uk', 'iZbp1xxxx', 'i-abc123', 1],
    ['cdf.mba', 'uk', 'uk', 'iZbp1xxxx', 'i-abc123', 2],
    ['cdf.mba', 'uk', 'uk', '', 'i-abc123', 2],
    ['cdf.mba', 'uk', 'uk', 'uk', 'i-abc123', 2],
    ['cdf.mba', '', '', 'web 1', 'i-abc123', 1],
    ['cdf.mba', '', '', '', 'i-abc123', 1],
    ['cdf.mba', '', '', '', 'abc123', 1],
    ['cdf.mba', '香港节点', '香港节点', 'iZbp1xxxx', 'i-abc123', 1],
    ['cdf.mba', '节点 node 1', '节点 node 1', '', 'i-abc123', 1],
    ['cdf.mba', 'UK Node', 'UK Node', '', 'i-abc123', 1],
    ['cdf.mba', 'café', 'café', '', 'i-abc123', 1],
    ['cdf.mba', 'Ünïcödé', 'Ünïcödé', '', 'i-abc123', 1],
    ['cdf.mba', 'ß', 'ß', '', 'i-abc123', 1],
    ['cdf.mba', '  a  b  ', '  a  b  ', '', 'i-abc123', 1],
    ['cdf.mba', '--x--', '--x--', '', 'i-abc123', 1],
    ['cdf.mba', '!!!', '!!!', '', 'i-abc123', 1],
    ['cdf.mba', str_repeat('a', 60), str_repeat('a', 60), '', 'i-abc123', 1],
    ['HTTPS://CDF.MBA/', 'uk', 'uk', '', 'i-abc123', 1],
    ['cdf.mba.', 'uk', 'uk', '', 'i-abc123', 1],
    // NBSP:PHP trim() 不剥它(JS 的 String.trim() 会),必须走 sha1 兜底而不是变成空串
    ["cdf.mba", "\u{00A0}", "\u{00A0}", '', 'i-abc123', 1],
    ["cdf.mba", "uk\u{00A0}", "uk\u{00A0}", '', 'i-abc123', 1],
    ['cdf.mba', '', '', '', '', 1],
];

$nameVectors = [];
foreach ($nameInputs as [$domain, $accountRemark, $remark, $instanceName, $instanceId, $count]) {
    $input = [
        'domain' => $domain,
        'account_remark' => $accountRemark,
        'remark' => $remark,
        'instance_name' => $instanceName,
        'instance_id' => $instanceId,
        'same_group_instance_count' => $count,
    ];
    try {
        $expected = makeNameService($domain)->buildRecordName([
            'account_remark' => $accountRemark,
            'remark' => $remark,
            'instance_name' => $instanceName,
            'instance_id' => $instanceId,
        ], $count);
        $nameVectors[] = ['input' => $input, 'expected' => $expected];
    } catch (\Exception $e) {
        $nameVectors[] = ['input' => $input, 'error' => $e->getMessage()];
    }
}

// ---- 域名归一化向量 ----
$domainVectors = [];
foreach (['cdf.mba', 'HTTPS://CDF.MBA/', 'http://cdf.mba/path', ' cdf.mba. ', '', '.', 'CDF.MBA:8080'] as $raw) {
    $ref = new ReflectionMethod(DdnsService::class, 'normalizeDomain');
    $ref->setAccessible(true);
    $domainVectors[] = ['input' => $raw, 'expected' => $ref->invoke(makeNameService('x.mba'), $raw)];
}

// ---- 账号组备注解析向量 ----
$remarkVectors = [];
$remarkInputs = [
    [['remark' => 'uk', 'group_key' => 'g1'], [['groupKey' => 'g1', 'remark' => 'UK Main']]],
    [['remark' => 'uk', 'group_key' => 'g9'], [['groupKey' => 'g1', 'remark' => 'UK Main']]],
    [['remark' => 'uk', 'group_key' => ''], [['groupKey' => 'g1', 'remark' => 'UK Main']]],
    [['remark' => 'fallback', 'group_key' => 'g1'], [['groupKey' => 'g1', 'remark' => '  ']]],
    [['remark' => '', 'group_key' => 'g1'], []],
    [['remark' => ' uk ', 'group_key' => ' g1 '], [['groupKey' => 'g1', 'remark' => ' UK Main ']]],
];
foreach ($remarkInputs as [$account, $groups]) {
    $service = new DdnsService(['ddns_domain' => 'cdf.mba'], null, new VectorGroupStub($groups));
    $remarkVectors[] = [
        'account' => $account,
        'groups' => $groups,
        'expected' => $service->resolveGroupRemark($account),
    ];
}

// ---- 组内实例计数向量 ----
$countAccounts = [
    ['group_key' => 'g1', 'instance_id' => 'i-1'],
    ['group_key' => 'g1', 'instance_id' => 'i-2'],
    ['group_key' => 'g2', 'instance_id' => ''],
    ['group_key' => '', 'access_key_id' => 'LTAI', 'region_id' => 'cn-hongkong', 'instance_id' => 'i-3'],
];
$countService = new DdnsService(['ddns_domain' => 'cdf.mba']);
$groupCountVectors = [[
    'accounts' => $countAccounts,
    'expected' => $countService->getGroupCounts($countAccounts),
]];

// ---- 生效公网 IP 选择向量 ----
$ipSelectionInputs = [
    ['eip', '8.208.77.147', '10.0.0.5'],
    ['eip', '10.0.0.5', '9.9.9.9'],
    ['eip', '', '9.9.9.9'],
    ['eip', ' 8.208.77.147 ', ''],
    ['ecs_public_ip', '8.208.77.147', '9.9.9.9'],
    ['eip', '192.0.2.1', ''],
    ['eip', 'not-an-ip', '9.9.9.9'],
    ['', '', ' 9.9.9.9 '],
    ['eip', '2001:db8::1', '9.9.9.9'],
];
$ipSelectionVectors = [];
foreach ($ipSelectionInputs as [$mode, $eip, $publicIp]) {
    $account = ['public_ip_mode' => $mode, 'eip_address' => $eip, 'public_ip' => $publicIp];
    $ipSelectionVectors[] = [
        'public_ip_mode' => $mode,
        'eip_address' => $eip,
        'public_ip' => $publicIp,
        'expected' => (new DdnsService(['ddns_domain' => 'cdf.mba']))->getEffectivePublicIp($account),
    ];
}

// ---- 公网 IPv4 判定向量(直接取自 PHP filter_var) ----
$ipList = [
    '8.208.77.147', '10.0.0.1', '172.16.5.5', '172.32.5.5', '192.168.1.1', '169.254.1.1',
    '127.0.0.1', '0.0.0.0', '100.64.0.1', '192.0.2.1', '198.51.100.7', '203.0.113.9',
    '240.0.0.1', '255.255.255.255', '224.0.0.1', '198.18.0.1', '192.88.99.1', '1.2.3',
    '8.208.77.147 ', '999.1.1.1', '::1', '8.208.77.147/24', '', '01.2.3.4', '1.2.3.04',
    "1.2.3.4\n", '1.2.3.4.', '1.2.3.4.5', '+1.2.3.4', '172.15.0.1', '172.32.0.1', '11.0.0.1',
];
$ipVectors = [];
foreach ($ipList as $ip) {
    $ipVectors[] = [
        'ip' => $ip,
        'public' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
    ];
}

$payload = [
    '_comment' => 'PHP 与 cf-worker 共用的 DDNS 算法验收向量。期望值由 PHP 实现生成(tests/generate-ddns-vectors.php)，两端测试都必须通过；修改算法后需重新生成。',
    'nameVectors' => $nameVectors,
    'domainVectors' => $domainVectors,
    'remarkVectors' => $remarkVectors,
    'groupCountVectors' => $groupCountVectors,
    'ipSelectionVectors' => $ipSelectionVectors,
    'ipVectors' => $ipVectors,
];

file_put_contents(
    __DIR__ . '/ddns-name-vectors.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
echo "vectors written: " . count($nameVectors) . " name, " . count($ipVectors) . " ip\n";
