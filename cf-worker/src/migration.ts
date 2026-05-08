import type { MigrationExport } from './types';
import { encrypt, isEncrypted } from './crypto';
import { saveSetting } from './db';

export async function importFromDocker(db: D1Database, encKey: string, data: MigrationExport): Promise<void> {
  if (data.version !== 1) throw new Error(`Unsupported export version: ${data.version}`);

  const s = data.settings;
  const set = (k: string, v: string) => saveSetting(db, k, v);
  await set('admin_password', String(s.admin_password ?? ''));
  await set('traffic_threshold', String(s.traffic_threshold ?? 95));
  await set('shutdown_mode', String(s.shutdown_mode ?? 'KeepCharging'));
  await set('threshold_action', String(s.threshold_action ?? 'stop_and_notify'));
  await set('keep_alive', s.keep_alive ? '1' : '0');
  await set('monthly_auto_start', s.monthly_auto_start ? '1' : '0');
  await set('api_interval', String(s.api_interval ?? 600));
  await set('enable_billing', s.enable_billing ? '1' : '0');

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

  // Store account groups
  await set('account_groups', JSON.stringify(data.account_groups));

  // Clear existing accounts before re-import
  await db.prepare('DELETE FROM accounts').run();

  // Import accounts (with re-encryption of secrets)
  for (const acc of data.accounts) {
    const secret = acc.access_key_secret && !isEncrypted(String(acc.access_key_secret))
      ? await encrypt(String(acc.access_key_secret), encKey)
      : String(acc.access_key_secret ?? '');
    await db.prepare(`INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,instance_status,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,cpu,memory,os_name,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_billing_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind(
        acc.access_key_id, secret, acc.region_id, acc.instance_id || '',
        acc.max_traffic || 0, acc.instance_status || 'Unknown',
        acc.remark || '', acc.site_type || 'international', acc.group_key || '',
        acc.instance_name || '', acc.instance_type || '', acc.internet_max_bandwidth_out || 0,
        acc.public_ip || '', acc.public_ip_mode || 'ecs_public_ip',
        acc.eip_allocation_id || '', acc.eip_address || '', acc.eip_managed ? 1 : 0,
        acc.cpu || 0, acc.memory || 0, acc.os_name || '',
        acc.schedule_enabled ? 1 : 0, acc.schedule_start_enabled ? 1 : 0, acc.schedule_stop_enabled ? 1 : 0,
        acc.start_time || '', acc.stop_time || '',
        acc.schedule_blocked_by_traffic ? 1 : 0, new Date().toISOString().substring(0, 7)
      ).run();
  }
}
