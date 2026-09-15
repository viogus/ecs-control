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
     * 判断 SDK 异常是否属于网络/传输层问题（端点不可达、连接超时、DNS 失败、SSL 异常等）。
     * 这类错误的请求根本没到达服务端、不携带任何鉴权结论，不能据此判定 AK 失效或权限不足。
     */
    public static function isNetworkError(string $code, string $message = ''): bool
    {
        $haystack = strtolower(trim($code) . ' ' . strip_tags(trim($message)));
        if (trim($haystack) === '') return false;

        $needles = ['serverunreachable', 'unreachable', 'timeout', 'timed out', 'timedout',
            'cannot connect', 'could not connect', 'connection refused', 'connection reset',
            'curl error', 'could not resolve', 'name or service not known', 'ssl', 'network'];
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) return true;
        }

        return false;
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
            if (self::isCredentialInvalidError($code, $e->getErrorMessage())) {
                if ($db) $db->addLog('error', "CDT 流量查询失败 [{$label}]: {$code} - " . $e->getErrorMessage());
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            if ($db) $db->addLog('error', "CDT 流量查询失败 [{$label}]: " . $e->getErrorCode() . " - " . $e->getErrorMessage());
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
