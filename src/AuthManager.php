<?php

declare(strict_types=1);

class AuthManager
{
    private Database $db;
    private ConfigManager $configManager;

    public function __construct(Database $db, ConfigManager $configManager)
    {
        $this->db = $db;
        $this->configManager = $configManager;
    }

    public function getAdminPassword(): string
    {
        return (string) $this->configManager->get('admin_password', '');
    }

    public function getMonitorKey(): string
    {
        $key = $this->configManager->get('monitor_key', '');
        if (empty($key)) {
            $key = bin2hex(random_bytes(32));
            $this->configManager->saveMonitorKey($key);
        }
        return $key;
    }

    public function login(string $password, string $ip): bool
    {
        $attempts = $this->db->getRecentFailedAttempts($ip, 900);
        if ($attempts >= 5) {
            $this->db->addLog('warning', "登录被锁定: 地址 {$ip} 尝试次数过多");
            throw new Exception("错误次数过多，请 15 分钟后再试。");
        }

        $adminPass = $this->getAdminPassword();
        if (empty($adminPass)) {
            return false;
        }

        $passwordValid = false;

        if ($this->isPasswordHashed($adminPass)) {
            $passwordValid = password_verify($password, $adminPass);
        } else {
            $passwordValid = hash_equals($adminPass, $password);
            if ($passwordValid) {
                $this->configManager->upgradePasswordHash($password);
            }
        }

        if ($passwordValid) {
            $this->db->clearLoginAttempts($ip);
            $this->db->addLog('info', "管理员登录成功 [地址: {$ip}]");
            return true;
        }

        $this->db->recordLoginAttempt($ip);
        $this->db->addLog('warning', "管理员登录失败 [地址: {$ip}]");
        return false;
    }

    private function isPasswordHashed(string $password): bool
    {
        return preg_match('/^\$2[aby]?\$/', $password) === 1 || preg_match('/^\$argon2[aid]\$/', $password) === 1;
    }

    public function setup(array $data): bool
    {
        if ($this->configManager->isInitialized()) {
            return false;
        }
        return $this->configManager->updateConfig($data);
    }
}
