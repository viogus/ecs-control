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

    public static function safeGetCdtTraffic(AliyunService $aliyunService, $account, ?Database $db = null): array
    {
        try {
            $value = $aliyunService->getTraffic(
                $account['access_key_id'], $account['access_key_secret'], $account['region_id']
            );
            return ['success' => true, 'value' => $value, 'status' => 'ok', 'message' => ''];
        } catch (\AlibabaCloud\Client\Exception\ClientException $e) {
            $code = trim((string) $e->getErrorCode());
            if (self::isCredentialInvalidError($code, $e->getMessage())) {
                if ($db) $db->addLog('error', "CDT 流量查询失败 [" . self::getAccountLogLabel($account) . "]: AK 已失效");
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            if ($db) $db->addLog('error', "CDT 流量查询配置错误 [" . self::getAccountLogLabel($account) . "]: " . ($code ?: "鉴权失败") . "，请确认 AK 拥有 CDT 权限");
            return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => 'CDT 权限不足'];
        } catch (\AlibabaCloud\Client\Exception\ServerException $e) {
            $code = trim((string) $e->getErrorCode());
            if (self::isCredentialInvalidError($code, $e->getErrorMessage())) {
                if ($db) $db->addLog('error', "CDT 流量查询失败 [" . self::getAccountLogLabel($account) . "]: {$code} - " . $e->getErrorMessage());
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            if ($db) $db->addLog('error', "CDT 流量查询失败 [" . self::getAccountLogLabel($account) . "]: " . $e->getErrorCode() . " - " . $e->getErrorMessage());
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 接口异常'];
        } catch (\Exception $e) {
            if ($db) {
                if (str_contains($e->getMessage(), 'cURL error')) {
                    $db->addLog('error', "CDT 流量查询失败 [" . self::getAccountLogLabel($account) . "]: 网络连接超时");
                    return ['success' => false, 'value' => null, 'status' => 'timeout', 'message' => 'CDT 请求超时'];
                }
                $db->addLog('error', "CDT 流量查询失败 [" . self::getAccountLogLabel($account) . "]: " . strip_tags($e->getMessage()));
            }
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 流量同步失败'];
        }
    }
}
