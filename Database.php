<?php

class Database
{
    private $pdo;
    private $dbFile;

    public function __construct($dbFile = null)
    {
        // 默认路径修改为 /data/ 子目录
        $this->dbFile = $dbFile ?: __DIR__ . '/data/data.sqlite';

        // 环境安全检查
        $this->secureEnvironment();

        $this->connect();
        SchemaManager::init($this->pdo);
    }

    private function secureEnvironment()
    {
        $dir = dirname($this->dbFile);
        $oldFile = __DIR__ . '/data.sqlite';

        // 1. 自动创建目录
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $this->throwPermissionError($dir);
            }
        }

        // 2. 自动迁移旧数据
        if (file_exists($oldFile) && !file_exists($this->dbFile)) {
            if (!@rename($oldFile, $this->dbFile)) {
                if (@copy($oldFile, $this->dbFile)) {
                    @unlink($oldFile);
                } else {
                    throw new Exception("安全迁移失败：无法移动旧数据库。请检查目录权限。");
                }
            }
        }

        // 3. 部署 .htaccess
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all");
        }

        // 4. 部署 index.html
        $indexHtml = $dir . '/index.html';
        if (!file_exists($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }
    }

    private function connect()
    {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbFile);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // 开启 WAL 模式，提高并发读写性能
            $this->pdo->exec('PRAGMA journal_mode = WAL;');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'unable to open database file') !== false) {
                $this->throwPermissionError(dirname($this->dbFile));
            }
            throw new Exception("Database Error: " . $e->getMessage());
        }
    }

    private function throwPermissionError($dir)
    {
        $user = get_current_user();
        throw new Exception("权限不足：Web用户 ({$user}) 无法读写 {$dir}。<br>请修复权限：<code>chown -R {$user}:{$user} " . __DIR__ . "</code>");
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function addLog($type, $message)
    {
        $stmt = $this->pdo->prepare("INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$type, $message, time()]);
    }

    public function getLogs($limit = 100)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLogsByTypes(array $types, $limit = 20)
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = "SELECT * FROM logs WHERE type IN ($placeholders) ORDER BY id DESC LIMIT ?";

        $params = $types;
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearLogsByTypes(array $types)
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM logs WHERE type IN ($placeholders)");
        return $stmt->execute($types);
    }

    /**
     * 优化后的日志清理逻辑
     * @param int $defaultDays 默认保留天数（用于重要日志）
     * @param int $heartbeatDays 心跳日志保留天数（建议设置较短，如3天）
     */
    public function pruneLogs($defaultDays = 30, $heartbeatDays = 3)
    {
        $now = time();

        // 1. 清理过期心跳日志 (Heartbeat) - 激进清理
        $stmt = $this->pdo->prepare("DELETE FROM logs WHERE type = 'heartbeat' AND created_at < ?");
        $stmt->execute([$now - ($heartbeatDays * 86400)]);

        // 2. 清理其他过期日志 (Info, Warning, Error) - 保守清理
        $stmt = $this->pdo->prepare("DELETE FROM logs WHERE type != 'heartbeat' AND created_at < ?");
        $stmt->execute([$now - ($defaultDays * 86400)]);
    }

    /**
     * 重置 Logs 表的自增 ID 并重新排序
     * 这是一个较重的操作，但能保证 ID 连续
     */
    public function reorderLogsIds()
    {
        try {
            $this->pdo->beginTransaction();

            // 1. 检查是否有数据
            $count = $this->pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();

            if ($count == 0) {
                // 如果没数据，直接重置序号为 0
                $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='logs'");
                $this->pdo->exec("DELETE FROM logs"); // 确保空
            } else {
                // 2. 使用临时表重排数据
                // 创建临时表保存现有数据，按时间正序排列
                $this->pdo->exec("CREATE TEMPORARY TABLE logs_temp AS SELECT type, message, created_at FROM logs ORDER BY created_at ASC");

                // 清空原表
                $this->pdo->exec("DELETE FROM logs");

                // 重置自增序列
                $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='logs'");

                // 将数据插回原表，ID 会自动从 1 开始重新生成
                $this->pdo->exec("INSERT INTO logs (type, message, created_at) SELECT type, message, created_at FROM logs_temp");

                // 删除临时表
                $this->pdo->exec("DROP TABLE logs_temp");
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // 记录错误到错误日志文件（如果有），或者忽略，因为这不是关键业务
            return false;
        }
    }

    /**
     * 整理数据库碎片 (VACUUM)
     * 释放已删除数据占用的磁盘空间
     */
    public function vacuum()
    {
        $this->pdo->exec("VACUUM");
    }

    // --- 登录频率限制相关方法 ---

    public function recordLoginAttempt($ip)
    {
        $stmt = $this->pdo->prepare("INSERT INTO login_attempts (ip, attempt_time) VALUES (?, ?)");
        $stmt->execute([$ip, time()]);
    }

    public function getRecentFailedAttempts($ip, $windowSeconds = 900)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempt_time > ?");
        $stmt->execute([$ip, time() - $windowSeconds]);
        return (int) $stmt->fetchColumn();
    }

    public function clearLoginAttempts($ip)
    {
        $stmt = $this->pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
    }

    // --- 流量记录逻辑 ---

    public function addHourlyStat($accountId, $traffic)
    {
        $hourTimestamp = floor(time() / 3600) * 3600;
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO traffic_hourly (account_id, traffic, recorded_at) VALUES (?, ?, ?)");
        $stmt->execute([$accountId, $traffic, $hourTimestamp]);
    }

    public function addDailyStat($accountId, $traffic)
    {
        $dayTimestamp = strtotime(date('Y-m-d 00:00:00'));
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO traffic_daily (account_id, traffic, recorded_at) VALUES (?, ?, ?)");
        $stmt->execute([$accountId, $traffic, $dayTimestamp]);
    }

    public function getHourlyStats($accountId)
    {
        $stmt = $this->pdo->prepare("SELECT traffic, recorded_at FROM traffic_hourly WHERE account_id = ? ORDER BY recorded_at DESC LIMIT 25");
        $stmt->execute([$accountId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($data);
    }

    public function getDailyStats($accountId)
    {
        $stmt = $this->pdo->prepare("SELECT traffic, recorded_at FROM traffic_daily WHERE account_id = ? ORDER BY recorded_at DESC LIMIT 31");
        $stmt->execute([$accountId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($data);
    }

    public function pruneStats()
    {
        $hourLimit = time() - (48 * 3600);
        $stmt = $this->pdo->prepare("DELETE FROM traffic_hourly WHERE recorded_at < ?");
        $stmt->execute([$hourLimit]);

        $dayLimit = time() - (60 * 86400);
        $stmt = $this->pdo->prepare("DELETE FROM traffic_daily WHERE recorded_at < ?");
        $stmt->execute([$dayLimit]);

        $monthLimit = date('Y-m', strtotime('-4 months'));
        $stmt = $this->pdo->prepare("DELETE FROM instance_traffic_usage WHERE billing_month < ?");
        $stmt->execute([$monthLimit]);
    }

    public function getInstanceTrafficUsage($accountId, $instanceId, $billingMonth)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM instance_traffic_usage WHERE account_id = ? AND instance_id = ? AND billing_month = ? LIMIT 1");
        $stmt->execute([$accountId, $instanceId, $billingMonth]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsertInstanceTrafficUsage($accountId, $instanceId, $billingMonth, $trafficBytes, $lastSampleMs)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instance_traffic_usage (account_id, instance_id, billing_month, traffic_bytes, last_sample_ms, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(account_id, instance_id, billing_month)
            DO UPDATE SET
                traffic_bytes = excluded.traffic_bytes,
                last_sample_ms = excluded.last_sample_ms,
                updated_at = excluded.updated_at
        ");

        return $stmt->execute([
            $accountId,
            $instanceId,
            $billingMonth,
            max(0, (float) $trafficBytes),
            max(0, (int) $lastSampleMs),
            time()
        ]);
    }

    // --- 账单缓存相关方法 ---

    /**
     * 写入/更新账单缓存
     */
    public function setBillingCache($accountId, $cacheType, $billingCycle, $data)
    {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO billing_cache (account_id, cache_type, billing_cycle, data, updated_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$accountId, $cacheType, $billingCycle, json_encode($data), time()]);
    }

    /**
     * 读取账单缓存 (含过期判断)
     * @param int $maxAge 最大缓存时间(秒)，默认6小时
     * @return array|null 缓存数据或 null(已过期/不存在)
     */
    public function getBillingCache($accountId, $cacheType, $billingCycle, $maxAge = 21600)
    {
        $stmt = $this->pdo->prepare("SELECT data, updated_at FROM billing_cache WHERE account_id = ? AND cache_type = ? AND billing_cycle = ? LIMIT 1");
        $stmt->execute([$accountId, $cacheType, $billingCycle]);
        $row = $stmt->fetch();

        if (!$row) return null;
        if ((time() - $row['updated_at']) > $maxAge) return null;

        return json_decode($row['data'], true);
    }

    /**
     * 清理超过90天的历史账单缓存
     */
    public function pruneBillingCache()
    {
        $limit = time() - (90 * 86400);
        $stmt = $this->pdo->prepare("DELETE FROM billing_cache WHERE updated_at < ?");
        $stmt->execute([$limit]);
    }

    public function createEcsCreateTask($taskId, $previewId, $groupKey, $regionId, $instanceType, $payload)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ecs_create_tasks (
                task_id, preview_id, account_group_key, region_id, instance_type, status, step, payload, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $now = time();
        $stmt->execute([
            $taskId,
            $previewId,
            $groupKey,
            $regionId,
            $instanceType,
            'running',
            '初始化创建任务',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $now,
            $now
        ]);
    }

    public function updateEcsCreateTask($taskId, array $fields)
    {
        if (empty($fields)) {
            return false;
        }

        $fields['updated_at'] = time();
        $allowed = [
            'zone_id', 'image_id', 'os_label', 'instance_name', 'vpc_id', 'vswitch_id',
            'security_group_id', 'internet_max_bandwidth_out', 'system_disk_category',
            'system_disk_size', 'instance_id', 'public_ip', 'login_user', 'login_password',
            'public_ip_mode', 'eip_allocation_id', 'eip_address', 'eip_managed',
            'status', 'step', 'error_message', 'payload', 'updated_at'
        ];

        $sets = [];
        $values = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "$key = ?";
            $values[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
        }

        if (empty($sets)) {
            return false;
        }

        $values[] = $taskId;
        $stmt = $this->pdo->prepare("UPDATE ecs_create_tasks SET " . implode(', ', $sets) . " WHERE task_id = ?");
        return $stmt->execute($values);
    }

    public function getEcsCreateTask($taskId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ecs_create_tasks WHERE task_id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
