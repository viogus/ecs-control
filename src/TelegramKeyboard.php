<?php

declare(strict_types=1);

class TelegramKeyboard
{
    public static function mainMenu(): array
    {
        return ['inline_keyboard' => [
            [['text' => '📊 账号概览', 'callback_data' => 'm:traffic'], ['text' => '🖥️ 实例列表', 'callback_data' => 'm:list:1']],
            [['text' => '🔄 刷新数据', 'callback_data' => 'm:refreshall'], ['text' => '📘 帮助说明', 'callback_data' => 'm:help']]
        ]];
    }

    public static function traffic(): array
    {
        return ['inline_keyboard' => [
            [['text' => '🔄 刷新流量', 'callback_data' => 'm:traffic'], ['text' => '🖥️ 查看实例', 'callback_data' => 'm:list:1']],
            [['text' => '🏠 返回主菜单', 'callback_data' => 'm:home']]
        ]];
    }

    public static function mainMenuText(): string
    {
        return "🛡️ ECS 服务器管家\n\n请选择要执行的操作：";
    }

    public static function helpText(): string
    {
        return "📘 使用说明\n\n配置 Telegram 通知后，当前 Bot 默认支持远程控制。\n\n"
            . "可用功能：\n📊 查看账号概览\n🖥️ 查看实例列表和详情\n"
            . "🚀 对已停止实例一键开机\n🗑️ 二次确认后释放实例\n\n"
            . "⚠️ 释放实例会进入后台安全队列。";
    }

    public static function statusIcon(string $status): string
    {
        return match ($status) {
            InstanceStatus::Running->value => '🟢', InstanceStatus::Starting->value => '🟡', InstanceStatus::Stopping->value => '🟠',
            InstanceStatus::Stopped->value => '🔴', InstanceStatus::Pending->value => '🟡', InstanceStatus::Releasing->value => '🗑️',
            InstanceStatus::Released->value => '⚫', default => '⚪'
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            InstanceStatus::Running->value => '运行中', InstanceStatus::Starting->value => '启动中', InstanceStatus::Stopping->value => '停机中',
            InstanceStatus::Stopped->value => '已停止', InstanceStatus::Pending->value => '创建中', InstanceStatus::Releasing->value => '释放中',
            InstanceStatus::Released->value => '已释放', default => ($status ?: '未知')
        };
    }

    public static function trafficStatusIcon(string $status): string
    {
        if (str_contains($status, '超量') || str_contains($status, '异常')) return '🔴';
        if (str_contains($status, '接近')) return '🟠';
        return '🟢';
    }

    private const REGION_NAMES = [
        'cn-hongkong' => '中国香港', 'ap-southeast-1' => '新加坡', 'ap-northeast-1' => '日本（东京）',
        'ap-northeast-2' => '韩国（首尔）',
        'us-west-1' => '美国（硅谷）', 'us-east-1' => '美国（弗吉尼亚）',
        'eu-central-1' => '德国（法兰克福）', 'eu-west-1' => '英国（伦敦）',
        'ap-southeast-2' => '澳大利亚（悉尼）', 'ap-southeast-3' => '马来西亚（吉隆坡）',
        'ap-southeast-5' => '印度尼西亚（雅加达）', 'ap-southeast-6' => '菲律宾（马尼拉）',
        'ap-southeast-7' => '泰国（曼谷）',
        'me-east-1' => '阿联酋（迪拜）',
        'cn-hangzhou' => '华东1（杭州）', 'cn-shanghai' => '华东2（上海）',
        'cn-qingdao' => '华北1（青岛）', 'cn-beijing' => '华北2（北京）', 'cn-zhangjiakou' => '华北3（张家口）',
        'cn-huhehaote' => '华北5（呼和浩特）', 'cn-wulanchabu' => '华北6（乌兰察布）',
        'cn-shenzhen' => '华南1（深圳）', 'cn-heyuan' => '华南2（河源）', 'cn-guangzhou' => '华南3（广州）',
        'cn-chengdu' => '西南1（成都）',
    ];

    public static function regionName(string $regionId): string
    {
        return self::REGION_NAMES[$regionId] ?? ($regionId ?: '-');
    }

    public static function formatTraffic(float $value): string
    {
        if ($value <= 0) return '0 MB';
        if ($value < 1) return round($value * 1024, 2) . ' MB';
        return round($value, 2) . ' GB';
    }

    public static function shortButtonText(string $text, int $maxLen = 28): string
    {
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $maxLen) {
            return mb_substr($text, 0, $maxLen - 3, 'UTF-8') . '...';
        }
        return $text;
    }
}
