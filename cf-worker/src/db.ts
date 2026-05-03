import type { Account } from './types';

export function rowToAccount(row: Record<string, unknown>): Account {
  return {
    id: Number(row.id ?? 0),
    access_key_id: String(row.access_key_id ?? ''),
    access_key_secret: String(row.access_key_secret ?? ''),
    region_id: String(row.region_id ?? ''),
    instance_id: String(row.instance_id ?? ''),
    max_traffic: Number(row.max_traffic ?? 0),
    traffic_used: Number(row.traffic_used ?? 0),
    traffic_billing_month: String(row.traffic_billing_month ?? ''),
    instance_status: String(row.instance_status ?? 'Unknown'),
    health_status: String(row.health_status ?? 'Unknown'),
    stopped_mode: String(row.stopped_mode ?? ''),
    updated_at: Number(row.updated_at ?? 0),
    last_keep_alive_at: Number(row.last_keep_alive_at ?? 0),
    is_deleted: Number(row.is_deleted ?? 0),
    auto_start_blocked: Number(row.auto_start_blocked ?? 0),
    schedule_enabled: Number(row.schedule_enabled ?? 0),
    schedule_start_enabled: Number(row.schedule_start_enabled ?? 0),
    schedule_stop_enabled: Number(row.schedule_stop_enabled ?? 0),
    start_time: String(row.start_time ?? ''),
    stop_time: String(row.stop_time ?? ''),
    schedule_last_start_date: String(row.schedule_last_start_date ?? ''),
    schedule_last_stop_date: String(row.schedule_last_stop_date ?? ''),
    schedule_blocked_by_traffic: Number(row.schedule_blocked_by_traffic ?? 0),
    remark: String(row.remark ?? ''),
    site_type: String(row.site_type ?? 'international'),
    group_key: String(row.group_key ?? ''),
    instance_name: String(row.instance_name ?? ''),
    instance_type: String(row.instance_type ?? ''),
    internet_max_bandwidth_out: Number(row.internet_max_bandwidth_out ?? 0),
    public_ip: String(row.public_ip ?? ''),
    public_ip_mode: String(row.public_ip_mode ?? 'ecs_public_ip'),
    eip_allocation_id: String(row.eip_allocation_id ?? ''),
    eip_address: String(row.eip_address ?? ''),
    eip_managed: Number(row.eip_managed ?? 0),
    private_ip: String(row.private_ip ?? ''),
    cpu: Number(row.cpu ?? 0),
    memory: Number(row.memory ?? 0),
    os_name: String(row.os_name ?? ''),
    traffic_api_status: String(row.traffic_api_status ?? 'ok'),
    traffic_api_message: String(row.traffic_api_message ?? ''),
    protection_suspended: Number(row.protection_suspended ?? 0),
    protection_suspend_reason: String(row.protection_suspend_reason ?? ''),
    protection_suspend_notified_at: Number(row.protection_suspend_notified_at ?? 0),
  };
}

export function getSettings(db: D1Database): Promise<Record<string, string>> {
  return db.prepare('SELECT key, value FROM settings').all<{ key: string; value: string }>()
    .then(r => Object.fromEntries(r.results.map(row => [row.key, row.value])));
}

export function getSetting(db: D1Database, key: string, def = ''): Promise<string> {
  return db.prepare('SELECT value FROM settings WHERE key = ?').bind(key)
    .first<{ value: string }>().then(r => r?.value ?? def);
}

export function saveSetting(db: D1Database, key: string, value: string): Promise<D1Result> {
  return db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').bind(key, value).run();
}

export function getAccounts(db: D1Database): Promise<Account[]> {
  return db.prepare('SELECT * FROM accounts WHERE is_deleted = 0 ORDER BY region_id, remark, id')
    .all<Record<string, unknown>>().then(r => r.results.map(rowToAccount));
}

export function getAccountById(db: D1Database, id: number): Promise<Account | null> {
  return db.prepare('SELECT * FROM accounts WHERE id = ?').bind(id)
    .first<Record<string, unknown>>().then(r => r ? rowToAccount(r) : null);
}

export function addLog(db: D1Database, type: string, message: string): Promise<D1Result> {
  return db.prepare('INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)')
    .bind(type, message, Math.floor(Date.now() / 1000)).run();
}

export function getLogs(db: D1Database, types: string[], limit = 20): Promise<Record<string, unknown>[]> {
  const ph = types.map(() => '?').join(',');
  return db.prepare(`SELECT * FROM logs WHERE type IN (${ph}) ORDER BY id DESC LIMIT ?`)
    .bind(...types, limit).all<Record<string, unknown>>().then(r => r.results);
}

export async function updateAccountStatus(
  db: D1Database, id: number, traffic: number, status: string, updatedAt: number, meta: Record<string, unknown> = {}
): Promise<void> {
  let sql = 'UPDATE accounts SET traffic_used = ?, traffic_billing_month = ?, instance_status = ?, updated_at = ?';
  const params: unknown[] = [traffic, new Date().toISOString().substring(0, 7), status, updatedAt];
  if (meta.health_status !== undefined) { sql += ', health_status = ?'; params.push(meta.health_status); }
  if (meta.traffic_api_status !== undefined) { sql += ', traffic_api_status = ?'; params.push(meta.traffic_api_status); }
  if (meta.traffic_api_message !== undefined) { sql += ', traffic_api_message = ?'; params.push(meta.traffic_api_message); }
  if (meta.protection_suspended !== undefined) { sql += ', protection_suspended = ?'; params.push(meta.protection_suspended); }
  if (meta.protection_suspend_reason !== undefined) { sql += ', protection_suspend_reason = ?'; params.push(meta.protection_suspend_reason); }
  if (meta.protection_suspend_notified_at !== undefined) { sql += ', protection_suspend_notified_at = ?'; params.push(meta.protection_suspend_notified_at); }
  sql += ' WHERE id = ?'; params.push(id);
  await db.prepare(sql).bind(...params).run();
}
