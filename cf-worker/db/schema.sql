CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_key_id TEXT NOT NULL DEFAULT '',
    access_key_secret TEXT NOT NULL DEFAULT '',
    region_id TEXT NOT NULL DEFAULT '',
    instance_id TEXT NOT NULL DEFAULT '',
    max_traffic REAL NOT NULL DEFAULT 0,
    traffic_used REAL NOT NULL DEFAULT 0,
    traffic_billing_month TEXT NOT NULL DEFAULT '',
    instance_status TEXT NOT NULL DEFAULT 'Unknown',
    health_status TEXT NOT NULL DEFAULT 'Unknown',
    stopped_mode TEXT NOT NULL DEFAULT '',
    updated_at INTEGER NOT NULL DEFAULT 0,
    last_keep_alive_at INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    auto_start_blocked INTEGER NOT NULL DEFAULT 0,
    schedule_enabled INTEGER NOT NULL DEFAULT 0,
    schedule_start_enabled INTEGER NOT NULL DEFAULT 0,
    schedule_stop_enabled INTEGER NOT NULL DEFAULT 0,
    start_time TEXT NOT NULL DEFAULT '',
    stop_time TEXT NOT NULL DEFAULT '',
    schedule_last_start_date TEXT NOT NULL DEFAULT '',
    schedule_last_stop_date TEXT NOT NULL DEFAULT '',
    schedule_blocked_by_traffic INTEGER NOT NULL DEFAULT 0,
    remark TEXT NOT NULL DEFAULT '',
    site_type TEXT NOT NULL DEFAULT 'international',
    group_key TEXT NOT NULL DEFAULT '',
    instance_name TEXT NOT NULL DEFAULT '',
    instance_type TEXT NOT NULL DEFAULT '',
    internet_max_bandwidth_out INTEGER NOT NULL DEFAULT 0,
    public_ip TEXT NOT NULL DEFAULT '',
    public_ip_mode TEXT NOT NULL DEFAULT 'ecs_public_ip',
    eip_allocation_id TEXT NOT NULL DEFAULT '',
    eip_address TEXT NOT NULL DEFAULT '',
    eip_managed INTEGER NOT NULL DEFAULT 0,
    private_ip TEXT NOT NULL DEFAULT '',
    cpu INTEGER NOT NULL DEFAULT 0,
    memory INTEGER NOT NULL DEFAULT 0,
    os_name TEXT NOT NULL DEFAULT '',
    traffic_api_status TEXT NOT NULL DEFAULT 'ok',
    traffic_api_message TEXT NOT NULL DEFAULT '',
    protection_suspended INTEGER NOT NULL DEFAULT 0,
    protection_suspend_reason TEXT NOT NULL DEFAULT '',
    protection_suspend_notified_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, message TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, attempt_time INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS traffic_hourly (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, traffic REAL NOT NULL, recorded_at INTEGER NOT NULL);
CREATE UNIQUE INDEX idx_th ON traffic_hourly (account_id, recorded_at);
CREATE TABLE IF NOT EXISTS traffic_daily (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, traffic REAL NOT NULL, recorded_at INTEGER NOT NULL);
CREATE UNIQUE INDEX idx_td ON traffic_daily (account_id, recorded_at);
CREATE TABLE IF NOT EXISTS billing_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, cache_type TEXT NOT NULL, billing_cycle TEXT NOT NULL DEFAULT '', data TEXT NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(account_id, cache_type, billing_cycle));
CREATE TABLE IF NOT EXISTS instance_traffic_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, instance_id TEXT NOT NULL, billing_month TEXT NOT NULL, traffic_bytes REAL NOT NULL DEFAULT 0, last_sample_ms INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL, UNIQUE(account_id, instance_id, billing_month));
CREATE TABLE IF NOT EXISTS ecs_create_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT UNIQUE NOT NULL, preview_id TEXT NOT NULL DEFAULT '', account_group_key TEXT NOT NULL, region_id TEXT NOT NULL, zone_id TEXT NOT NULL DEFAULT '', instance_type TEXT NOT NULL, image_id TEXT NOT NULL DEFAULT '', os_label TEXT NOT NULL DEFAULT '', instance_name TEXT NOT NULL DEFAULT '', vpc_id TEXT NOT NULL DEFAULT '', vswitch_id TEXT NOT NULL DEFAULT '', security_group_id TEXT NOT NULL DEFAULT '', internet_max_bandwidth_out INTEGER NOT NULL DEFAULT 0, system_disk_category TEXT NOT NULL DEFAULT '', system_disk_size INTEGER NOT NULL DEFAULT 0, instance_id TEXT NOT NULL DEFAULT '', public_ip TEXT NOT NULL DEFAULT '', public_ip_mode TEXT NOT NULL DEFAULT 'ecs_public_ip', eip_allocation_id TEXT NOT NULL DEFAULT '', eip_address TEXT NOT NULL DEFAULT '', eip_managed INTEGER NOT NULL DEFAULT 0, login_user TEXT NOT NULL DEFAULT '', login_password TEXT NOT NULL DEFAULT '', status TEXT NOT NULL, step TEXT NOT NULL DEFAULT '', error_message TEXT NOT NULL DEFAULT '', payload TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS telegram_bot_state (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS telegram_action_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, user_id TEXT NOT NULL, chat_id TEXT NOT NULL, action TEXT NOT NULL, account_id INTEGER NOT NULL, payload TEXT NOT NULL DEFAULT '', expires_at INTEGER NOT NULL, used_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
