<?php

class TelegramApi
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * 常驻进程配置热更新:token/代理等修改后无需重启即可生效。
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * 简单 SSRF 校验:解析主机并检查解析出的 IP 是否为公网地址。
     */
    private function isExternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null) {
            return false;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (empty($records)) {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return false;
            }
            $records = [['type' => 'A', 'ip' => $ip]];
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false;
            }
        }

        return true;
    }

    public function call(string $method, array $payload = []): array
    {
        $token = trim((string) ($this->settings['notify_tg_token'] ?? ''));
        $proxyType = $this->settings['notify_tg_proxy_type'] ?? 'none';
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        if ($proxyType === 'custom' && !empty($this->settings['notify_tg_proxy_url'])) {
            // SSRF 防护:拒绝自定义代理指向内网/保留地址
            if (!$this->isExternalUrl($this->settings['notify_tg_proxy_url'])) {
                return ['ok' => false, 'description' => '自定义代理地址指向内网地址，已拒绝请求'];
            }
            $url = rtrim($this->settings['notify_tg_proxy_url'], '/') . "/bot{$token}/{$method}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($proxyType === 'socks5') {
            $proxyIp = trim((string) ($this->settings['notify_tg_proxy_ip'] ?? ''));
            $proxyPort = trim((string) ($this->settings['notify_tg_proxy_port'] ?? ''));
            if ($proxyIp !== '' && $proxyPort !== '') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                curl_setopt($ch, CURLOPT_PROXY, "{$proxyIp}:{$proxyPort}");
                $proxyUser = $this->settings['notify_tg_proxy_user'] ?? '';
                $proxyPass = $this->settings['notify_tg_proxy_pass'] ?? '';
                if ($proxyUser !== '' || $proxyPass !== '') {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxyUser}:{$proxyPass}");
                }
            }
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) return ['ok' => false, 'description' => '网络请求错误: ' . $error];
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) return ['ok' => false, 'description' => "接口返回异常 {$httpCode}: " . (string) $raw];
        return $decoded;
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        return $this->call('sendMessage', $payload);
    }

    public function editMessage(string $chatId, int $messageId, string $text, ?array $keyboard = null): array
    {
        if ($messageId <= 0) return $this->sendMessage($chatId, $text, $keyboard);
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        $response = $this->call('editMessageText', $payload);
        if (!$response['ok']) return $this->sendMessage($chatId, $text, $keyboard);
        return $response;
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        if ($callbackId === '') return;
        $payload = ['callback_query_id' => $callbackId];
        if ($text !== '') $payload['text'] = $text;
        $this->call('answerCallbackQuery', $payload);
    }
}
