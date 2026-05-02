<?php

class SchemaManager
{
    public static function init(\PDO $pdo): void
    {
        self::initSchema($pdo);
    }

    private static function initSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            access_key_id TEXT,
            access_key_secret TEXT,
            region_id TEXT,
            instance_id TEXT,
            max_traffic REAL,
            schedule_enabled INTEGER DEFAULT 0,
            start_time TEXT,
            stop_time TEXT,
            traffic_used REAL DEFAULT 0,
            instance_status TEXT DEFAULT 'Unknown',
            updated_at INTEGER DEFAULT 0,
            last_keep_alive_at INTEGER DEFAULT 0,
            is_deleted INTEGER DEFAULT 0
        )");

        try {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN is_deleted INTEGER DEFAULT 0");
        } catch (PDOException $e) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, message TEXT, created_at INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, attempt_time INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS traffic_hourly (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, traffic REAL, recorded_at INTEGER)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_traffic_hourly_unique ON traffic_hourly (account_id, recorded_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS traffic_daily (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, traffic REAL, recorded_at INTEGER)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_traffic_daily_unique ON traffic_daily (account_id, recorded_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, cache_type TEXT NOT NULL, billing_cycle TEXT DEFAULT '', data TEXT NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(account_id, cache_type, billing_cycle))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS instance_traffic_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, instance_id TEXT NOT NULL, billing_month TEXT NOT NULL, traffic_bytes REAL DEFAULT 0, last_sample_ms INTEGER DEFAULT 0, updated_at INTEGER NOT NULL, UNIQUE(account_id, instance_id, billing_month))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ecs_create_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT UNIQUE NOT NULL, preview_id TEXT DEFAULT '', account_group_key TEXT NOT NULL, region_id TEXT NOT NULL, zone_id TEXT DEFAULT '', instance_type TEXT NOT NULL, image_id TEXT DEFAULT '', os_label TEXT DEFAULT '', instance_name TEXT DEFAULT '', vpc_id TEXT DEFAULT '', vswitch_id TEXT DEFAULT '', security_group_id TEXT DEFAULT '', internet_max_bandwidth_out INTEGER DEFAULT 0, system_disk_category TEXT DEFAULT '', system_disk_size INTEGER DEFAULT 0, instance_id TEXT DEFAULT '', public_ip TEXT DEFAULT '', public_ip_mode TEXT DEFAULT 'ecs_public_ip', eip_allocation_id TEXT DEFAULT '', eip_address TEXT DEFAULT '', eip_managed INTEGER DEFAULT 0, login_user TEXT DEFAULT '', login_password TEXT DEFAULT '', status TEXT NOT NULL, step TEXT DEFAULT '', error_message TEXT DEFAULT '', payload TEXT DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_state (key TEXT PRIMARY KEY, value TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_action_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, user_id TEXT NOT NULL, chat_id TEXT NOT NULL, action TEXT NOT NULL, account_id INTEGER NOT NULL, payload TEXT DEFAULT '', expires_at INTEGER NOT NULL, used_at INTEGER DEFAULT 0, created_at INTEGER NOT NULL)");

        self::ensureColumn($pdo, 'accounts', 'traffic_used', 'REAL DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'traffic_billing_month', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'instance_status', "TEXT DEFAULT 'Unknown'");
        self::ensureColumn($pdo, 'accounts', 'updated_at', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'last_keep_alive_at', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'auto_start_blocked', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'schedule_start_enabled', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'schedule_stop_enabled', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'schedule_last_start_date', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'schedule_last_stop_date', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'schedule_blocked_by_traffic', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'remark', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'site_type', "TEXT DEFAULT 'international'");
        self::ensureColumn($pdo, 'accounts', 'group_key', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'instance_name', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'instance_type', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'internet_max_bandwidth_out', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'public_ip', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'public_ip_mode', "TEXT DEFAULT 'ecs_public_ip'");
        self::ensureColumn($pdo, 'accounts', 'eip_allocation_id', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'eip_address', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'eip_managed', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'private_ip', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'cpu', "INTEGER DEFAULT 0");
        self::ensureColumn($pdo, 'accounts', 'memory', "INTEGER DEFAULT 0");
        self::ensureColumn($pdo, 'accounts', 'os_name', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'stopped_mode', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'health_status', "TEXT DEFAULT 'Unknown'");
        self::ensureColumn($pdo, 'accounts', 'traffic_api_status', "TEXT DEFAULT 'ok'");
        self::ensureColumn($pdo, 'accounts', 'traffic_api_message', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'protection_suspended', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'accounts', 'protection_suspend_reason', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'accounts', 'protection_suspend_notified_at', 'INTEGER DEFAULT 0');
        self::ensureColumn($pdo, 'ecs_create_tasks', 'public_ip_mode', "TEXT DEFAULT 'ecs_public_ip'");
        self::ensureColumn($pdo, 'ecs_create_tasks', 'eip_allocation_id', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'ecs_create_tasks', 'eip_address', "TEXT DEFAULT ''");
        self::ensureColumn($pdo, 'ecs_create_tasks', 'eip_managed', 'INTEGER DEFAULT 0');

        self::migrateStatsToAccountId($pdo);
    }

    private static function ensureColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $pdo->query("SELECT $column FROM $table LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }

    private static function migrateStatsToAccountId(\PDO $pdo): void
    {
        $needsMigration = false;
        try {
            $pdo->query("SELECT access_key_id FROM traffic_hourly LIMIT 1");
            $needsMigration = true;
        } catch (Exception $e) {}

        if (!$needsMigration) return;

        try {
            $pdo->beginTransaction();
            $pdo->exec("CREATE TABLE traffic_hourly_new (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, traffic REAL, recorded_at INTEGER)");
            $pdo->exec("INSERT INTO traffic_hourly_new (account_id, traffic, recorded_at) SELECT a.id, t.traffic, t.recorded_at FROM traffic_hourly t JOIN accounts a ON t.access_key_id = a.access_key_id GROUP BY a.id, t.recorded_at");
            $pdo->exec("DROP TABLE traffic_hourly");
            $pdo->exec("ALTER TABLE traffic_hourly_new RENAME TO traffic_hourly");
            $pdo->exec("CREATE UNIQUE INDEX idx_traffic_hourly_unique ON traffic_hourly (account_id, recorded_at)");
            $pdo->exec("CREATE TABLE traffic_daily_new (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, traffic REAL, recorded_at INTEGER)");
            $pdo->exec("INSERT INTO traffic_daily_new (account_id, traffic, recorded_at) SELECT a.id, t.traffic, t.recorded_at FROM traffic_daily t JOIN accounts a ON t.access_key_id = a.access_key_id GROUP BY a.id, t.recorded_at");
            $pdo->exec("DROP TABLE traffic_daily");
            $pdo->exec("ALTER TABLE traffic_daily_new RENAME TO traffic_daily");
            $pdo->exec("CREATE UNIQUE INDEX idx_traffic_daily_unique ON traffic_daily (account_id, recorded_at)");
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
