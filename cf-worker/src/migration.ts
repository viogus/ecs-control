import type { MigrationExport } from './types';
import { decrypt, encrypt, isEncrypted } from './crypto';
import { encryptGroupSecrets } from './accounts';
import { saveSetting } from './db';

export interface ImportOptions { skipPassword?: boolean; skipDefaults?: boolean; }

export async function importFromDocker(db: D1Database, encKey: string, data: MigrationExport, opts: ImportOptions = {}): Promise<void> {
  if (data.version !== 1) throw new Error(`Unsupported export version: ${data.version}`);

  // Ensure import_id column exists (added in 0.9.3)
  try { await db.prepare("ALTER TABLE accounts ADD COLUMN import_id TEXT NOT NULL DEFAULT ''").run(); } catch { /* already exists */ }

  const importId = Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);

  // Snapshot current settings for rollback
  let settingsSnapshot: Record<string, string> | null = null;
  try {
    const rows = await db.prepare('SELECT key, value FROM settings').all<{key:string;value:string}>();
    settingsSnapshot = {};
    for (const r of rows.results) settingsSnapshot[r.key] = r.value;
  } catch { /* empty DB is fine */ }

  const writtenKeys = new Set<string>();

  // Phase 1: tag old accounts (keep visible), insert new ones hidden (is_deleted=4)
  const newImportId = importId + '_new';
  await db.prepare("UPDATE accounts SET import_id = ? WHERE is_deleted = 0").bind(importId).run();

  try {
    const insertSql = `INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,instance_status,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,cpu,memory,os_name,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_billing_month,is_deleted,import_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,4,?)`;
    for (const acc of data.accounts) {
      const secret = acc.access_key_secret && !isEncrypted(String(acc.access_key_secret))
        ? await encrypt(String(acc.access_key_secret), encKey)
        : String(acc.access_key_secret ?? '');
      await db.prepare(insertSql).bind(
        acc.access_key_id, secret, acc.region_id, acc.instance_id || '',
        acc.max_traffic || 0, acc.instance_status || 'Unknown',
        acc.remark || '', acc.site_type || 'international', acc.group_key || '',
        acc.instance_name || '', acc.instance_type || '', acc.internet_max_bandwidth_out || 0,
        acc.public_ip || '', acc.public_ip_mode || 'ecs_public_ip',
        acc.eip_allocation_id || '', acc.eip_address || '', acc.eip_managed ? 1 : 0,
        acc.cpu || 0, acc.memory || 0, acc.os_name || '',
        acc.schedule_enabled ? 1 : 0, acc.schedule_start_enabled ? 1 : 0, acc.schedule_stop_enabled ? 1 : 0,
        acc.start_time || '', acc.stop_time || '',
        acc.schedule_blocked_by_traffic ? 1 : 0, new Date().toISOString().substring(0, 7),
        newImportId
      ).run();
    }
  } catch (e) {
    await rollback(db, importId, newImportId, settingsSnapshot, writtenKeys);
    throw e;
  }

  // Phase 2: write all settings (accounts not yet visible)
  try {
    await writeSettings(db, data, opts, writtenKeys);
  } catch (e) {
    await rollback(db, importId, newImportId, settingsSnapshot, writtenKeys);
    throw e;
  }

  // Phase 3: promote new accounts (4→0), then delete old ones
  try {
    await db.prepare("UPDATE accounts SET is_deleted = 0 WHERE import_id = ? AND is_deleted = 4").bind(newImportId).run();
  } catch (e) {
    await rollback(db, importId, newImportId, settingsSnapshot, writtenKeys);
    throw e;
  }

  // Delete old accounts — if this fails, rollback new accounts to avoid duplicates
  try {
    await db.prepare("DELETE FROM accounts WHERE import_id = ? AND is_deleted = 0").bind(importId).run();
  } catch (e) {
    // Restore old settings, clear old markers, and delete new accounts
    await rollback(db, importId, newImportId, settingsSnapshot, writtenKeys);
    throw e;
  }
  // Old accounts deleted — remaining cleanup can't delete new accounts
  await db.prepare("UPDATE accounts SET import_id = '' WHERE import_id = ?").bind(newImportId).run();
}

async function writeSettings(db: D1Database, data: MigrationExport, opts: ImportOptions, writtenKeys: Set<string>): Promise<void> {
  const s = data.settings;
  const set = (k: string, v: string) => { writtenKeys.add(k); return saveSetting(db, k, v); };

  if (!opts.skipPassword) await set('admin_password', String(s.admin_password ?? ''));
  if (!opts.skipDefaults) await set('traffic_threshold', String(s.traffic_threshold ?? 95));
  await set('shutdown_mode', String(s.shutdown_mode ?? 'KeepCharging'));
  await set('threshold_action', String(s.threshold_action ?? 'stop_and_notify'));
  await set('keep_alive', (String(s.keep_alive) === '1' || s.keep_alive === true) ? '1' : '0');
  await set('monthly_auto_start', (String(s.monthly_auto_start) === '1' || s.monthly_auto_start === true) ? '1' : '0');
  await set('api_interval', String(s.api_interval ?? 600));
  await set('enable_billing', (String(s.enable_billing) === '1' || s.enable_billing === true) ? '1' : '0');
  await set('cost_threshold', String(s.cost_threshold ?? '0.48'));
  await set('cost_threshold_enabled', (String(s.cost_threshold_enabled) === '1' || s.cost_threshold_enabled === true) ? '1' : '0');

  const n = data.notification;
  await set('notify_email_enabled', n.email_enabled ? '1' : '0');
  await set('notify_email', String(n.email ?? ''));
  await set('notify_host', String(n.host ?? ''));
  await set('notify_port', String(n.port ?? '465'));
  await set('notify_username', String(n.username ?? ''));
  if (n.password && n.password !== '********') await set('notify_password', String(n.password));
  await set('notify_secure', String(n.secure ?? 'ssl'));
  await set('notify_tg_enabled', n.tg_enabled ? '1' : '0');
  if (n.tg_token && n.tg_token !== '********') await set('notify_tg_token', String(n.tg_token));
  await set('notify_tg_chat_id', String(n.tg_chat_id ?? ''));
  await set('notify_wh_enabled', n.wh_enabled ? '1' : '0');
  await set('notify_wh_url', String(n.wh_url ?? ''));
  await set('notify_wh_method', String(n.wh_method ?? 'GET'));

  const d = data.ddns;
  await set('ddns_enabled', d.enabled ? '1' : '0');
  await set('ddns_domain', String(d.domain ?? ''));
  await set('ddns_cf_zone_id', String(d.cf_zone_id ?? ''));
  if (d.cf_token && d.cf_token !== '********') await set('ddns_cf_token', String(d.cf_token));
  await set('ddns_cf_proxied', d.cf_proxied ? '1' : '0');

  // Re-encrypt account_groups secrets with the current encryption key
  // (handles cross-key migration: Docker-encrypted -> CF Worker key)
  const importedGroups = data.account_groups || [];
  if (importedGroups.length > 0) {
    const withPlaintext = await Promise.all(importedGroups.map(async (g: any) => {
      let secret = String(g.AccessKeySecret ?? '');
      if (secret && secret !== '********' && isEncrypted(secret)) {
        // Try to decrypt (for cross-key re-encryption)
        try { secret = await decrypt(secret, encKey); } catch { secret = ''; }
      }
      return { ...g, AccessKeySecret: secret };
    }));
    const encrypted = await encryptGroupSecrets(withPlaintext, encKey);
    await set('account_groups', JSON.stringify(encrypted));
  }
}
async function rollback(db: D1Database, importId: string, newImportId: string, snapshot: Record<string, string> | null, writtenKeys: Set<string>): Promise<void> {
  // Restore settings
  if (snapshot) {
    for (const k of writtenKeys) {
      if (snapshot[k] !== undefined) {
        await saveSetting(db, k, snapshot[k]);
      } else {
        await db.prepare('DELETE FROM settings WHERE key = ?').bind(k).run();
      }
    }
  }
  // Delete new staged accounts, restore old accounts
  await db.prepare("DELETE FROM accounts WHERE import_id = ?").bind(newImportId).run();
  await db.prepare("UPDATE accounts SET import_id = '' WHERE import_id = ?").bind(importId).run();
}