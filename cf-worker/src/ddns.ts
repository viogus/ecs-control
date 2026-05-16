import type { Account } from './types';
import { getSetting, addLog } from './db';

export async function syncDdns(db: D1Database, accounts: Account[]): Promise<void> {
  const enabled = await getSetting(db, 'ddns_enabled', '0') === '1';
  if (!enabled) return;
  const domain = await getSetting(db, 'ddns_domain', '');
  const token = await getSetting(db, 'ddns_cf_token', '');
  const zoneId = await getSetting(db, 'ddns_cf_zone_id', '');
  const proxied = await getSetting(db, 'ddns_cf_proxied', '0') === '1';
  if (!domain || !token) return;

  for (const a of accounts) {
    if (!a.instance_id) continue;
    const ip = a.public_ip_mode === 'eip' ? (a.eip_address || a.public_ip) : a.public_ip;
    if (!ip) continue;
    try {
      const slug = a.remark || a.instance_name || a.instance_id.replace('i-', '');
      const sanitized = slug.replace(/[^a-zA-Z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').substring(0, 48) || a.group_key;
      const recordName = `${sanitized}.${domain}`.toLowerCase();

      const listRes = await fetch(
        `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records?type=A&name=${encodeURIComponent(recordName)}`,
        { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' } }
      );
      const list = await listRes.json() as any;
      const existing = list.result?.[0];

      if (existing && existing.content === ip) continue; // unchanged

      const method = existing ? 'PUT' : 'POST';
      const path = existing ? `/dns_records/${existing.id}` : '/dns_records';
      const body = JSON.stringify({ type: 'A', name: recordName, content: ip, ttl: 1, proxied, comment: 'Managed by ECS Control worker' });

      const res = await fetch(`https://api.cloudflare.com/client/v4/zones/${zoneId}${path}`, {
        method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body,
      });
      const json = await res.json() as any;
      if (json.success) {
        await addLog(db, 'info', `DDNS ${existing ? 'updated' : 'created'}: ${recordName} -> ${ip}`);
      }
    } catch (e: any) {
      await addLog(db, 'warning', `DDNS sync failed [${a.remark || a.instance_id}]: ${e.message}`);
    }
  }
}
