<?php

class Helpers
{
    public static function getAccountLogLabel(array $account): string
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
}
