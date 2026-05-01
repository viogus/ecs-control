<?php

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class TrafficChecker
{
    private AliyunService $aliyunService;
    private Database $db;

    public function __construct(AliyunService $aliyunService, Database $db)
    {
        $this->aliyunService = $aliyunService;
        $this->db = $db;
    }

    public function safeGetTraffic(array $account): array
    {
        try {
            $value = $this->aliyunService->getTraffic(
                $account['access_key_id'],
                $account['access_key_secret'],
                $account['region_id']
            );
            return ['success' => true, 'value' => $value, 'status' => 'ok', 'message' => ''];
        } catch (ClientException $e) {
            $code = trim((string) $e->getErrorCode());
            if ($this->isCredentialInvalidError($code, $e->getMessage())) {
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => 'CDT 权限不足'];
        } catch (ServerException $e) {
            $code = trim((string) $e->getErrorCode());
            if ($this->isCredentialInvalidError($code, $e->getErrorMessage())) {
                return ['success' => false, 'value' => null, 'status' => 'auth_error', 'message' => '账号 AK 已失效'];
            }
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 接口异常'];
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'cURL error') !== false) {
                return ['success' => false, 'value' => null, 'status' => 'timeout', 'message' => 'CDT 请求超时'];
            }
            return ['success' => false, 'value' => null, 'status' => 'sync_error', 'message' => 'CDT 流量同步失败'];
        }
    }

    public function safeGetInstanceStatus(array $account): string
    {
        try {
            return $this->aliyunService->getInstanceStatus($account);
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    public function getGroupTrafficUsed(Database $db, array $account): float
    {
        $pdo = $db->getPdo();
        $groupKey = trim((string) ($account['group_key'] ?? ''));
        $billingMonth = date('Y-m');

        if ($groupKey !== '') {
            $stmt = $pdo->prepare("SELECT traffic_used FROM accounts WHERE group_key = ? AND traffic_billing_month = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$groupKey, $billingMonth]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT traffic_used FROM accounts WHERE access_key_id = ? AND region_id = ? AND traffic_billing_month = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$account['access_key_id'] ?? '', $account['region_id'] ?? '', $billingMonth]);
        return (float) $stmt->fetchColumn();
    }

    private function isCredentialInvalidError($code, $message = ''): bool
    {
        $normalizedCode = strtolower(trim((string) $code));
        $normalizedMessage = strtolower(trim((string) $message));

        $credentialErrorCodes = [
            'invalidaccesskeyid.notfound', 'invalidaccesskeyid', 'signaturedoesnotmatch',
            'incompletesignature', 'forbidden.accesskeydisabled', 'invalidsecuritytoken.expired',
            'invalidsecuritytoken.malformed', 'missingsecuritytoken'
        ];
        if (in_array($normalizedCode, $credentialErrorCodes, true)) {
            return true;
        }
        if ($normalizedMessage === '') {
            return false;
        }
        return strpos($normalizedMessage, 'access key is not found') !== false
            || strpos($normalizedMessage, 'access key id does not exist') !== false
            || strpos($normalizedMessage, 'signature does not match') !== false
            || strpos($normalizedMessage, 'incomplete signature') !== false
            || strpos($normalizedMessage, 'accesskeydisabled') !== false;
    }
}
