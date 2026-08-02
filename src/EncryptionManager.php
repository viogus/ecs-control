<?php

class EncryptionManager
{
    /**
     * Load or create the encryption key stored under data/.secret_encryption.key.
     * Throws if existing key is corrupted (prevents silent data loss).
     *
     * 优先使用环境变量 APP_ENC_KEY(64 位 hex = 32 字节):密钥与数据库分离存储,
     * 避免 data 目录被整体泄露时加密形同虚设。未设置时回退到 data 目录密钥文件。
     */
    public static function loadKey(string $keyDir = null): string
    {
        $envKeyBin = null;
        $envKey = getenv('APP_ENC_KEY');
        if (is_string($envKey) && strlen($envKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 && ctype_xdigit($envKey)) {
            $envKeyBin = hex2bin($envKey);
        }

        $keyDir = $keyDir ?: __DIR__ . '/../data';
        $keyFile = $keyDir . '/.secret_encryption.key';

        if (!is_dir($keyDir)) {
            @mkdir($keyDir, 0755, true);
        }

        if (file_exists($keyFile)) {
            $key = file_get_contents($keyFile);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                // 已有文件密钥时以文件为准:若环境变量与文件不一致,忽略环境变量并告警,
                // 避免切换密钥导致已加密数据(账号 Secret 等)无法解密
                if ($envKeyBin !== null && !hash_equals($envKeyBin, $key)) {
                    error_log('WARNING: APP_ENC_KEY 与现有密钥文件不一致，已忽略环境变量并使用文件密钥。若需迁移请先备份并重新加密数据。');
                }
                return $key;
            }
            $actual = is_string($key) ? strlen($key) : 'false';
            throw new Exception(
                "加密密钥文件 {$keyFile} 异常（期望 " . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . " 字节，实际 {$actual}）。" .
                "请从备份恢复密钥文件后重试，或删除该文件重新初始化（将丢失所有已加密的 AK Secret）。"
            );
        }

        // 全新部署:优先使用环境变量密钥,同时落盘密钥文件(0600),
        // 防止环境变量丢失/更换导致已加密数据永久不可解;文件存在后以文件为准
        if ($envKeyBin !== null) {
            $written = @file_put_contents($keyFile, $envKeyBin, LOCK_EX);
            if ($written === false) {
                error_log("WARNING: 无法写入加密密钥文件 {$keyFile},数据仅依赖 APP_ENC_KEY,环境变量丢失将导致已加密数据不可解");
            } else {
                @chmod($keyFile, 0600);
            }
            return $envKeyBin;
        }

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        file_put_contents($keyFile, $key, LOCK_EX);
        @chmod($keyFile, 0600);
        return $key;
    }

    public static function encrypt(string $value, string $key): string
    {
        if (!function_exists('sodium_crypto_secretbox') || empty($value)) {
            return $value;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($value, $nonce, $key);
        return 'ENC1' . base64_encode($nonce . $encrypted);
    }

    public static function decrypt(string $value, string $key): string
    {
        if (!function_exists('sodium_crypto_secretbox') || empty($value) || strlen($value) < 8 || substr($value, 0, 4) !== 'ENC1') {
            return $value;
        }
        $raw = base64_decode(substr($value, 4));
        if ($raw === false) {
            return $value;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        return $decrypted !== false ? $decrypted : $value;
    }

    public static function isEncrypted(string $value): bool
    {
        return strlen($value) >= 8 && substr($value, 0, 4) === 'ENC1';
    }
}
