<?php

class ExportService
{
    private ConfigManager $configManager;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    public function export(bool $redact = false): array
    {
        $settings = $this->configManager->getAllSettings();
        $accounts = $this->configManager->getAccounts();
        $mask = $redact ? '********' : null;

        $decrypted = [];
        foreach ($accounts as $acc) {
            $decrypted[] = [
                'access_key_id' => $acc['access_key_id'],
                'access_key_secret' => $mask ?? $acc['access_key_secret'] ?? '',
                'region_id' => $acc['region_id'],
                'instance_id' => $acc['instance_id'],
                'group_key' => $acc['group_key'],
                'max_traffic' => (float)($acc['max_traffic'] ?? 0),
                'instance_status' => $acc['instance_status'] ?? 'Unknown',
                'remark' => $acc['remark'] ?? '',
                'site_type' => $acc['site_type'] ?? 'international',
                'instance_name' => $acc['instance_name'] ?? '',
                'instance_type' => $acc['instance_type'] ?? '',
                'internet_max_bandwidth_out' => (int)($acc['internet_max_bandwidth_out'] ?? 0),
                'public_ip' => $acc['public_ip'] ?? '',
                'public_ip_mode' => $acc['public_ip_mode'] ?? 'ecs_public_ip',
                'eip_allocation_id' => $acc['eip_allocation_id'] ?? '',
                'eip_address' => $acc['eip_address'] ?? '',
                'eip_managed' => !empty($acc['eip_managed']),
                'cpu' => (int)($acc['cpu'] ?? 0),
                'memory' => (int)($acc['memory'] ?? 0),
                'os_name' => $acc['os_name'] ?? '',
                'schedule_enabled' => !empty($acc['schedule_enabled']),
                'schedule_start_enabled' => !empty($acc['schedule_start_enabled']),
                'schedule_stop_enabled' => !empty($acc['schedule_stop_enabled']),
                'start_time' => $acc['start_time'] ?? '',
                'stop_time' => $acc['stop_time'] ?? '',
                'schedule_blocked_by_traffic' => !empty($acc['schedule_blocked_by_traffic']),
            ];
        }

        $rawGroups = $settings['account_groups'] ?? '';
        $groups = [];
        if (!empty($rawGroups)) {
            $decoded = json_decode($rawGroups, true) ?: [];
            foreach ($decoded as $g) {
                $gs = $g['AccessKeySecret'] ?? '';
                if ($gs === '********') $gs = '';
                if ($mask !== null) $gs = $mask;
                $groups[] = [
                    'groupKey' => $g['groupKey'] ?? '',
                    'AccessKeyId' => $g['AccessKeyId'] ?? '',
                    'AccessKeySecret' => $gs,
                    'regionId' => $g['regionId'] ?? '',
                    'siteType' => $g['siteType'] ?? 'international',
                    'maxTraffic' => (float)($g['maxTraffic'] ?? 200),
                    'remark' => $g['remark'] ?? '',
                    'scheduleEnabled' => !empty($g['scheduleEnabled']),
                    'scheduleStartEnabled' => !empty($g['scheduleStartEnabled']),
                    'scheduleStopEnabled' => !empty($g['scheduleStopEnabled']),
                    'startTime' => $g['startTime'] ?? '',
                    'stopTime' => $g['stopTime'] ?? '',
                ];
            }
        }

        return [
            'version' => 1,
            'exported_at' => date('Y-m-d H:i:s'),
            'settings' => [
                'admin_password' => $mask ?? $settings['admin_password'] ?? '',
                'traffic_threshold' => (int)($settings['traffic_threshold'] ?? 95),
                'shutdown_mode' => $settings['shutdown_mode'] ?? 'KeepCharging',
                'threshold_action' => $settings['threshold_action'] ?? 'stop_and_notify',
                'keep_alive' => ($settings['keep_alive'] ?? '0') === '1',
                'monthly_auto_start' => ($settings['monthly_auto_start'] ?? '0') === '1',
                'api_interval' => (int)($settings['api_interval'] ?? 600),
                'enable_billing' => ($settings['enable_billing'] ?? '0') === '1',
                'cost_threshold' => (float)($settings['cost_threshold'] ?? 0.48),
                'cost_threshold_enabled' => ($settings['cost_threshold_enabled'] ?? '0') === '1',
            ],
            'notification' => [
                'email_enabled' => ($settings['notify_email_enabled'] ?? '1') === '1',
                'email' => $settings['notify_email'] ?? '',
                'host' => $settings['notify_host'] ?? '',
                'port' => $settings['notify_port'] ?? '465',
                'username' => $settings['notify_username'] ?? '',
                'password' => $mask ?? $settings['notify_password'] ?? '',
                'secure' => $settings['notify_secure'] ?? 'ssl',
                'tg_enabled' => ($settings['notify_tg_enabled'] ?? '0') === '1',
                'tg_token' => $mask ?? $settings['notify_tg_token'] ?? '',
                'tg_chat_id' => $settings['notify_tg_chat_id'] ?? '',
                'wh_enabled' => ($settings['notify_wh_enabled'] ?? '0') === '1',
                'wh_url' => $settings['notify_wh_url'] ?? '',
                'wh_method' => $settings['notify_wh_method'] ?? 'GET',
                'wh_body' => $settings['notify_wh_body'] ?? '',
            ],
            'ddns' => [
                'enabled' => ($settings['ddns_enabled'] ?? '0') === '1',
                'domain' => $settings['ddns_domain'] ?? '',
                'cf_zone_id' => $settings['ddns_cf_zone_id'] ?? '',
                'cf_token' => $mask ?? $settings['ddns_cf_token'] ?? '',
                'cf_proxied' => ($settings['ddns_cf_proxied'] ?? '0') === '1',
            ],
            'accounts' => $decrypted,
            'account_groups' => $groups,
        ];
    }
}
