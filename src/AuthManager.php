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

        $passwordValid = $this->verifyPassword($password);

        if ($passwordValid) {
            // 明文密码升级为 bcrypt 哈希
            if (!$this->isPasswordHashed($adminPass)) {
                $this->configManager->upgradePasswordHash($password);
            }
            $this->db->clearLoginAttempts($ip);
            $this->db->addLog('info', "管理员登录成功 [地址: {$ip}]");
            return true;
        }

        $this->db->recordLoginAttempt($ip);
        $this->db->addLog('warning', "管理员登录失败 [地址: {$ip}]");
        // 指数退避:失败次数越多延迟越长(上限 3 秒),同时清理过期记录
        $this->db->deleteExpiredLoginAttempts(900);
        $failures = $this->db->getRecentFailedAttempts($ip, 900);
        usleep(min((int) pow(2, min($failures, 5)) * 100000, 3000000));
        return false;
    }

    /**
     * 校验管理员密码(不记录失败次数、不触发锁定)。
     * 用于已登录会话内的敏感操作二次认证。
     */
    public function verifyPassword(string $password): bool
    {
        $adminPass = $this->getAdminPassword();
        if ($adminPass === '') {
            return false;
        }

        $valid = false;
        if ($this->isPasswordHashed($adminPass)) {
            $valid = password_verify($password, $adminPass);
        } else {
            $valid = hash_equals($adminPass, $password);
        }

        // 固定延迟:防止对二次认证接口(如明文导出)做高速爆破
        usleep(300000);
        return $valid;
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
