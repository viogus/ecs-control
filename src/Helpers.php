<?php

class Helpers
{
    public static function getAccountLogLabel($account): string
    {
        $remark = trim((string) ($account['remark'] ?? ''));
        if ($remark !== '') return $remark;
        $name = trim((string) ($account['instance_name'] ?? ''));
        if ($name !== '') return $name;
        $id = trim((string) ($account['instance_id'] ?? ''));
        if ($id !== '') return $id;
        return substr((string) ($account['access_key_id'] ?? ''), 0, 7) . '***';
    }

    public static function logNotificationResult(Database $db, $result, string $key): void
    {
        if ($result === true) {
            $db->addLog('info', "通知推送成功 [$key]");
        } elseif ($result !== false && $result !== true) {
            $db->addLog('warning', "通知推送异常/失败 [$key]: " . strip_tags($result));
        }
    }

    public static function isCredentialInvalidError(string $code, string $message = ''): bool
    {
        $normalizedCode = strtolower(trim($code));
        $normalizedMessage = strtolower(trim($message));
        if ($normalizedCode === '') return false;

        $codes = ['invalidaccesskeyid.notfound', 'invalidaccesskeyid', 'signaturedoesnotmatch',
            'incompletesignature', 'forbidden.accesskeydisabled', 'invalidsecuritytoken.expired',
            'invalidsecuritytoken.malformed', 'missingsecuritytoken'];
        if (in_array($normalizedCode, $codes, true)) return true;
        if ($normalizedMessage === '') return false;

        return strpos($normalizedMessage, 'access key is not found') !== false
            || strpos($normalizedMessage, 'access key id does not exist') !== false
            || strpos($normalizedMessage, 'signature does not match') !== false
            || strpos($normalizedMessage, 'incomplete signature') !== false
            || strpos($normalizedMessage, 'accesskeydisabled') !== false;
    }

    /**
     * SDK 客户端层（ClientException）错误码全集见
     * vendor/alibabacloud/client/src/Exception/ClientException.php，其中只有下面两个表示
     * 「请求未到达服务端」：连接失败/socket 读写超时、主机名无法解析。
     */
    private const NETWORK_ERROR_CODES = ['sdk.serverunreachable', 'sdk.hostnotfound'];

    /** 消息兜底特征：仅在 errorCode 缺失或非 SDK 码时使用（如 Guzzle/cURL 直接抛出的异常）。 */
    private const NETWORK_ERROR_HINTS = ['timed out', 'timeout', 'unreachable', 'no route to host',
        'could not resolve', 'name or service not known', 'connection refused', 'connection reset',
        'curl error', 'ssl handshake', 'network is down'];

    /**
     * 判断异常是否属于网络/传输层问题（端点不可达、连接超时、DNS 失败等）。
     * 这类错误的请求根本没到达服务端、不携带任何鉴权结论，不能据此判定 AK 失效或权限不足。
     */
    public static function isNetworkError(string $code, string $message = ''): bool
    {
        $normalizedCode = strtolower(trim($code));
        if ($normalizedCode !== '') {
            if (in_array($normalizedCode, self::NETWORK_ERROR_CODES, true)) return true;
            // 其余 SDK.* 是 SDK 的本地判定结果（参数/区域/解析错误），不再靠消息猜
            if (strpos($normalizedCode, 'sdk.') === 0) return false;
        }

        $normalizedMessage = strtolower(strip_tags(trim($message)));
        if ($normalizedMessage === '') return false;
        foreach (self::NETWORK_ERROR_HINTS as $needle) {
            if (strpos($normalizedMessage, $needle) !== false) return true;
        }

        return false;
    }

    /**
     * 判断服务端返回的是否为权限类错误（RAM 未授权 / 策略拒绝）。
     * 与「AK 已失效」区分：AK 有效但缺少 CDT 权限时流量数据同样取不到，但处置方式不同。
     */
    public static function isPermissionError(string $code, string $message = ''): bool
    {
        $normalizedCode = strtolower(trim($code));
        if ($normalizedCode !== '') {
            if (strpos($normalizedCode, 'forbidden') === 0) return true;
            if (in_array($normalizedCode, ['nopermission', 'accessdenied', 'unauthorized',
                'invalidpermission', 'permissiondenied'], true)) return true;
        }

        $normalizedMessage = strtolower(strip_tags(trim($message)));
        if ($normalizedMessage === '') return false;

        return strpos($normalizedMessage, 'no permission') !== false
            || strpos($normalizedMessage, 'not authorized') !== false
            || strpos($normalizedMessage, 'access denied') !== false
            || strpos($normalizedMessage, 'forbidden') !== false;
    }

    /**
     * 「CDT 持续失败」告警的去重键后缀。
     * CDT 按 AK 聚合查询(见 AliyunService::getTraffic 的 trafficCache),同一 AK 的多台实例必然
     * 同时失败,因此按 AK 汇总去重,避免一个分组刷出 N 条重复告警;AK 缺失时退回账号 id。
     */
    public static function cdtNotifyKeySuffix($account): string
    {
        $accessKeyId = trim((string) ($account['access_key_id'] ?? ''));
        if ($accessKeyId !== '') {
            return 'ak-' . substr(md5(strtolower($accessKeyId)), 0, 16);
        }

        return 'acct-' . (string) ($account['id'] ?? 'unknown');
    }

    public static function safeGetCdtTraffic(AliyunService $aliyunService, $account, ?Database $db = null): array
    {
        $label = self::getAccountLogLabel($account);
        try {
            $value = $aliyunService->getTraffic(
                $account['access_key_id'], $account['access_key_secret'], $account['region_id']
            );
            return ['success' => true, 'value' => $value, 'status' => 'ok', 'message' => ''];
        } catch (\AlibabaCloud\Client\Exception\ClientException $e) {
            $code = trim((string) $e->getErrorCode());
            $detail = trim($code . ($e->getMessage() !== '' ? ' - ' . strip_tags($e->getMessage()) : ''));
            if (self::isCredentialInvalidError($code, $e->getMessage())) {
                if ($db) $db->addLog('error', "CDT 流量查询失败 [{$label}]: AK 已失效");
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            // SDK 客户端异常（典型如 SDK.ServerUnreachable）是本地/传输层问题：请求未到服务端，
            // 不能等同于「AK 失效」或「缺少 CDT 权限」。否则网络抖动每轮都会误暂停自动停机保护，
            // 恢复后再刷一条「鉴权已恢复」，日志里成对出现。
            if (self::isNetworkError($code, $e->getMessage())) {
                if ($db) $db->addLog('warning', "CDT 流量查询网络异常 [{$label}]: " . ($detail ?: '连接失败') . "，将自动重试");
                return ['success' => false, 'value' => null, 'status' => 'timeout', 'message' => 'CDT 网络连接异常'];
            }
            if ($db) $db->addLog('warning', "CDT 流量查询接口异常 [{$label}]: " . ($detail ?: '未知错误'));
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 接口异常'];
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = trim((string) $e->getErrorCode());
            $message = strip_tags($e->getErrorMessage());
            if (self::isCredentialInvalidError($code, $e->getErrorMessage())) {
                if ($db) $db->addLog('error', "CDT 流量查询失败 [{$label}]: {$code} - {$message}");
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            // AK 有效但 RAM 未授权：数据同样取不到，用独立状态让前端/汇总提示「缺少权限」而不是「鉴权失败」
            if (self::isPermissionError($code, $message)) {
                if ($db) $db->addLog('error', "CDT 流量查询缺少权限 [{$label}]: {$code} - {$message}");
                return ['success' => false, 'value' => null, 'status' => 'permission_denied', 'message' => '缺少 CDT 权限，请检查 RAM 授权'];
            }
            if ($db) $db->addLog('error', "CDT 流量查询失败 [{$label}]: {$code} - {$message}");
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 接口异常'];
        } catch (\Exception $e) {
            $isNetwork = self::isNetworkError('', $e->getMessage());
            if ($db) {
                $db->addLog($isNetwork ? 'warning' : 'error', $isNetwork
                    ? "CDT 流量查询网络异常 [{$label}]: " . strip_tags($e->getMessage()) . "，将自动重试"
                    : "CDT 流量查询失败 [{$label}]: " . strip_tags($e->getMessage()));
            }
            return $isNetwork
                ? ['success' => false, 'value' => null, 'status' => 'timeout', 'message' => 'CDT 请求超时']
                : ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 流量同步失败'];
        }
    }
}
