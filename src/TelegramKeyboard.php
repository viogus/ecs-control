<?php

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
            'Running' => '🟢', 'Starting' => '🟡', 'Stopping' => '🟠',
            'Stopped' => '🔴', 'Pending' => '🟡', 'Releasing' => '🗑️',
            'Released' => '⚫', default => '⚪'
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'Running' => '运行中', 'Starting' => '启动中', 'Stopping' => '停机中',
            'Stopped' => '已停止', 'Pending' => '创建中', 'Releasing' => '释放中',
            'Released' => '已释放', default => ($status ?: '未知')
        };
    }

    public static function trafficStatusIcon(string $status): string
    {
        if (str_contains($status, '超量') || str_contains($status, '异常')) return '🔴';
        if (str_contains($status, '接近')) return '🟠';
        return '🟢';
    }

    public static function regionName(string $regionId): string
    {
        return match ($regionId) {
            'cn-hongkong' => '中国香港', 'ap-southeast-1' => '新加坡', 'ap-northeast-1' => '日本（东京）',
            'us-west-1' => '美国（硅谷）', 'us-east-1' => '美国（弗吉尼亚）',
            'cn-hangzhou' => '华东 1（杭州）', 'cn-shanghai' => '华东 2（上海）',
            'cn-beijing' => '华北 2（北京）', 'cn-shenzhen' => '华南 1（深圳）',
            default => ($regionId ?: '-')
        };
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
