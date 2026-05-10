<?php

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class AccountGroupOperationService
{
    private $db;
    private $configManager;
    private $aliyunService;
    private $responseBuilder;
    private $ddnsService;

    public function __construct($db, $configManager, $aliyunService, $responseBuilder, $ddnsService)
    {
        $this->db = $db;
        $this->configManager = $configManager;
        $this->aliyunService = $aliyunService;
        $this->responseBuilder = $responseBuilder;
        $this->ddnsService = $ddnsService;
    }

    public function fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')
    {
        if (empty($accessKeyId) || empty($accessKeySecret)) {
            throw new Exception('请先填写AK ID和AK Secret');
        }

        try {
            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret, $regionId ?: null);
            $maskedKey = substr($accessKeyId, 0, 7) . '***';
            $this->db->addLog('info', "实例列表获取成功 [{$maskedKey}] 共 " . count($instances) . " 台");
            return $instances;
        } catch (ClientException $e) {
            $this->db->addLog('warning', "实例列表获取失败: 鉴权错误");
            throw new Exception('阿里云鉴权失败，请检查AK权限或密钥是否正确');
        } catch (ServerException $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . $e->getErrorCode() . " - " . strip_tags($e->getErrorMessage()));
            throw new Exception('阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage());
        } catch (\Exception $e) {
            $this->db->addLog('warning', "实例列表获取失败: " . strip_tags($e->getMessage()));
            throw new Exception('实例列表获取失败: 网络或系统错误');
        }
    }

    public function testAccountCredentials($account)
    {
        $accessKeyId = trim((string) ($account['AccessKeyId'] ?? ''));
        $accessKeySecret = trim((string) ($account['AccessKeySecret'] ?? ''));
        $regionId = trim((string) ($account['regionId'] ?? ''));
        $maxTraffic = (float) ($account['maxTraffic'] ?? 0);
        $accountLabel = trim((string) ($account['remark'] ?? '')) ?: (substr($accessKeyId, 0, 7) . '***');

        if ($accessKeyId === '' || $accessKeySecret === '' || $regionId === '') {
            throw new Exception('请先填写完整的AK、区域和账号流量');
        }

        if ($accessKeySecret === '********') {
            $accessKeySecret = $this->resolveSecretFromDatabase($accessKeyId, $regionId, $account['groupKey'] ?? '');
        }

        try {
            $regions = $this->aliyunService->getRegions($accessKeyId, $accessKeySecret);
            $regionIds = array_column($regions, 'regionId');
            if (!in_array($regionId, $regionIds, true)) {
                throw new Exception('当前AK无法访问所选区域，请检查权限范围');
            }

            $instances = $this->aliyunService->getInstances($accessKeyId, $accessKeySecret);
            $regionInstances = array_values(array_filter($instances, function ($instance) use ($regionId) {
                return ($instance['regionId'] ?? '') === $regionId;
            }));
            $instanceCount = count($regionInstances);

            $monitorWarning = '';
            try {
                $this->aliyunService->getTraffic($accessKeyId, $accessKeySecret, $regionId);
            } catch (\Exception $e) {
                $monitorWarning = 'CDT 流量查询未通过：' . strip_tags($e->getMessage());
                $this->db->addLog('warning', "账号 CDT 探测异常 [{$accountLabel}]: {$monitorWarning}");
            }

            $trafficUsed = (float) ($account['usageUsed'] ?? 0);
            $trafficRemaining = max(round($maxTraffic - $trafficUsed, 2), 0);
            $trafficPercent = $maxTraffic > 0 ? min(round(($trafficUsed / $maxTraffic) * 100, 2), 100) : 0;
            $this->db->addLog('info', "账号测试成功 [{$accountLabel}] {$regionId} 实例 {$instanceCount} 台");
            $message = 'AK可用，ECS API已接通';
            if ($monitorWarning !== '') {
                $message .= '；' . $monitorWarning;
            } else {
                $message .= '，CDT 接口已接通';
            }

            return [
                'success' => true,
                'message' => $message,
                'monitorWarning' => $monitorWarning,
                'monitorStatus' => $monitorWarning !== '' ? 'warning' : 'ok',
                'monitorMessage' => $monitorWarning !== '' ? $monitorWarning : 'CDT 接口已接通，可获取账号出口流量。',
                'usageUsed' => $trafficUsed,
                'usageRemaining' => $trafficRemaining,
                'usagePercent' => $trafficPercent,
                'instanceCount' => $instanceCount
            ];
        } catch (ClientException $e) {
            $message = '鉴权失败，请检查AK ID和AK Secret是否正确，或确认是否具备ECS 权限';
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (ServerException $e) {
            $message = '阿里云接口错误 [' . $e->getErrorCode() . ']: ' . $e->getErrorMessage();
            $this->db->addLog('warning', "账号测试失败: {$message}");
            throw new Exception($message);
        } catch (Exception $e) {
            $this->db->addLog('warning', "账号测试失败: " . strip_tags($e->getMessage()));
            throw $e;
        }
    }

    public function syncAccountGroup($groupKey): array
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        $accountsBeforeSync = $this->configManager->getAccounts();
        // syncAccountGroups reconciles the full configured set, so use all groups here
        // and filter refresh work to the clicked group afterwards.
        $this->configManager->syncAccountGroups(true);
        $this->configManager->load();

        $threshold = (int) ($this->configManager->get('traffic_threshold', 95) ?: 95);
        $userInterval = (int) ($this->configManager->get('api_interval', 600) ?: 600);
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        $instanceCount = 0;

        foreach ($this->configManager->getAccounts() as $account) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            if ($accountGroupKey !== $groupKey || empty($account['instance_id'])) {
                continue;
            }

            $this->responseBuilder->buildInstanceSnapshot($account, ['threshold' => $threshold, 'userInterval' => $userInterval, 'billingEnabled' => $billingEnabled, 'includeSensitive' => true, 'forceRefresh' => true]);
            $instanceCount++;
        }

        if ($billingEnabled) {
            $this->responseBuilder->getAccountGroupBillingMetrics(true);
        }

        $this->configManager->load();
        $syncedAccounts = array_values(array_filter($this->configManager->getAccounts(), function ($account) use ($groupKey) {
            $accountGroupKey = $account['group_key'] ?: substr(sha1($account['access_key_id'] . '|' . $account['region_id']), 0, 16);
            return $accountGroupKey === $groupKey && !empty($account['instance_id']);
        }));
        $this->ddnsService->reconcileAfterSync($accountsBeforeSync, $this->configManager->getAccounts(), '账号同步');
        $this->db->addLog('info', "账号同步完成 [{$targetGroup['remark']}] {$targetGroup['regionId']} 实例 {$instanceCount} 台");

        $trafficIssue = $this->summarizeTrafficIssueForAccounts($syncedAccounts);
        $message = "已同步 {$instanceCount} 台实例，流量和消费情况已刷新";
        if ($trafficIssue !== '') {
            $message .= '；' . $trafficIssue;
        }

        return [
            'success' => true,
            'message' => $message,
            'instanceCount' => $instanceCount,
            'trafficIssue' => $trafficIssue
        ];
    }

    public function restoreScheduleAfterTrafficBlock($groupKey)
    {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '') {
            throw new Exception('缺少账号组标识');
        }

        $groups = $this->configManager->getAccountGroups();
        $targetGroup = null;
        foreach ($groups as $group) {
            if (($group['groupKey'] ?? '') === $groupKey) {
                $targetGroup = $group;
                break;
            }
        }

        if (!$targetGroup) {
            throw new Exception('账号组不存在，请刷新页面后重试');
        }

        $this->configManager->restoreScheduleAfterTrafficBlock($groupKey);
        $this->db->addLog('info', "已手动恢复定时开关机 [{$targetGroup['remark']}] {$targetGroup['regionId']}");

        return [
            'success' => true,
            'message' => '定时开关机已恢复。请确认本月流量未继续超过阈值，否则下一轮监控仍会触发保护。'
        ];
    }

    private function resolveSecretFromDatabase($accessKeyId, $regionId, $groupKey = '')
    {
        $pdo = $this->db->getPdo();
        $groupKey = trim((string) $groupKey);

        if ($groupKey !== '') {
            $stmt = $pdo->prepare("SELECT access_key_secret FROM accounts WHERE group_key = ? LIMIT 1");
            $stmt->execute([$groupKey]);
            $row = $stmt->fetch();

            if ($row && !empty($row['access_key_secret'])) {
                $secret = $this->configManager->decryptAccountSecret($row['access_key_secret']);
                if (!empty($secret)) {
                    return $secret;
                }
            }
        }

        $stmt = $pdo->prepare("SELECT access_key_secret FROM accounts WHERE access_key_id = ? AND region_id = ? LIMIT 1");
        $stmt->execute([$accessKeyId, $regionId]);
        $row = $stmt->fetch();

        if ($row && !empty($row['access_key_secret'])) {
            $secret = $this->configManager->decryptAccountSecret($row['access_key_secret']);
            if (!empty($secret)) {
                return $secret;
            }
        }

        foreach ($this->configManager->getAccountGroups() as $group) {
            if (
                (
                    ($groupKey !== '' && ($group['groupKey'] ?? '') === $groupKey)
                    || (($group['AccessKeyId'] ?? '') === $accessKeyId && ($group['regionId'] ?? '') === $regionId)
                )
                && !empty($group['AccessKeySecret'])
                && $group['AccessKeySecret'] !== '********'
            ) {
                return $group['AccessKeySecret'];
            }
        }

        throw new Exception('无法读取该账号的AK Secret，请重新输入后保存');
    }

    private function summarizeTrafficIssueForAccounts(array $accounts)
    {
        if (empty($accounts)) {
            return '';
        }

        $statuses = [];
        foreach ($accounts as $account) {
            $status = trim((string) ($account['traffic_api_status'] ?? 'ok'));
            if ($status !== '' && $status !== 'ok') {
                $statuses[$status] = true;
            }
        }

        if (empty($statuses)) {
            return '';
        }

        if (isset($statuses['auth_error'])) {
            return '部分账号 CDT 鉴权失败，请检查 AK 权限配置';
        }

        if (isset($statuses['timeout'])) {
            return '部分账号 CDT 请求超时，请稍后重试';
        }

        return '部分账号流量同步失败，请稍后重试';
    }
}
