<?php

class EncryptionManager
{
    /**
     * Load or create the encryption key stored under data/.secret_encryption.key.
     * Throws if existing key is corrupted (prevents silent data loss).
     */
    public static function loadKey(string $keyDir = null): string
    {
        $keyDir = $keyDir ?: __DIR__ . '/../data';
        $keyFile = $keyDir . '/.secret_encryption.key';

        if (!is_dir($keyDir)) {
            @mkdir($keyDir, 0755, true);
        }

        if (file_exists($keyFile)) {
            $key = file_get_contents($keyFile);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
            $actual = is_string($key) ? strlen($key) : 'false';
            throw new Exception(
                "加密密钥文件 {$keyFile} 异常（期望 " . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . " 字节，实际 {$actual}）。" .
                "请从备份恢复密钥文件后重试，或删除该文件重新初始化（将丢失所有已加密的 AK Secret）。"
            );
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
