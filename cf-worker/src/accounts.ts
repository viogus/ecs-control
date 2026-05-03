import type { Account, AccountGroup } from './types';
import { decrypt, encrypt } from './crypto';
import { getInstances } from './aliyun-api';
import { rowToAccount, getAccounts } from './db';

export function buildGroupKey(accessKeyId: string, regionId: string): string {
  const str = `${accessKeyId}|${regionId}`;
  let h = 0;
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) - h) + str.charCodeAt(i);
    h |= 0;
  }
  return Math.abs(h).toString(16).padStart(16, '0');
}

export async function getGroupsFromSettings(db: D1Database): Promise<AccountGroup[]> {
  const raw = await db.prepare("SELECT value FROM settings WHERE key = 'account_groups'")
    .first<{ value: string }>();
  if (!raw?.value) return [];
  try {
    const groups = JSON.parse(raw.value);
    if (!Array.isArray(groups)) return [];
    return groups.map((g: any) => ({
      groupKey: g.groupKey ?? buildGroupKey(g.AccessKeyId ?? '', g.regionId ?? ''),
      AccessKeyId: g.AccessKeyId ?? '',
      AccessKeySecret: g.AccessKeySecret ?? '',
      regionId: g.regionId ?? '',
      siteType: g.siteType ?? (g.regionId?.startsWith('cn-') && g.regionId !== 'cn-hongkong' ? 'china' : 'international'),
      maxTraffic: parseFloat(g.maxTraffic ?? 200),
      remark: g.remark ?? '',
      scheduleEnabled: !!(g.scheduleEnabled ?? false),
      scheduleStartEnabled: !!(g.scheduleStartEnabled ?? false),
      scheduleStopEnabled: !!(g.scheduleStopEnabled ?? false),
      startTime: g.startTime ?? '',
      stopTime: g.stopTime ?? '',
    }));
  } catch { return []; }
}

export function resolveNetworkMetadata(instance: Record<string, unknown>, existingRow?: Record<string, unknown> | null) {
  const eipId = String(instance.eipAllocationId ?? '');
  const eipAddr = String(instance.eipAddress ?? '');
  const existingMode = String(existingRow?.public_ip_mode ?? '');
  const existingManaged = Number(existingRow?.eip_managed ?? 0);
  const mode = eipId ? 'eip' : 'ecs_public_ip';
  return {
    public_ip_mode: mode,
    eip_allocation_id: eipId || String(existingRow?.eip_allocation_id ?? ''),
    eip_address: eipAddr || (mode === 'eip' ? String(instance.publicIp ?? '') : ''),
    eip_managed: existingManaged,
  };
}

export async function syncAccountGroups(
  db: D1Database, encKey: string, groups: AccountGroup[], onLog?: (type: string, msg: string) => void
): Promise<void> {
  const existing = await getAccounts(db);
  const existingByGroup: Record<string, Account[]> = {};
  for (const a of existing) {
    const gk = a.group_key || buildGroupKey(a.access_key_id, a.region_id);
    (existingByGroup[gk] ??= []).push(a);
  }

  for (const group of groups) {
    let instances;
    try {
      const fakeAccount: Account = {
        ...{} as Account, access_key_id: group.AccessKeyId,
        access_key_secret: group.AccessKeySecret, region_id: group.regionId,
      };
      instances = await getInstances(fakeAccount);
    } catch (e: any) {
      onLog?.('warning', `Instance sync failed [${group.AccessKeyId.substring(0, 7)}***] ${group.regionId}: ${e.message}`);
      // Update group base settings even on failure
      await db.prepare(`UPDATE accounts SET access_key_id=?, access_key_secret=?, region_id=?, max_traffic=?,
        schedule_enabled=?, schedule_start_enabled=?, schedule_stop_enabled=?, start_time=?, stop_time=?,
        site_type=?, group_key=? WHERE group_key=?`)
        .bind(group.AccessKeyId, await encrypt(group.AccessKeySecret, encKey),
          group.regionId, group.maxTraffic,
          group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
          group.startTime, group.stopTime, group.siteType, group.groupKey, group.groupKey).run();
      continue;
    }

    const existingForGroup = existingByGroup[group.groupKey] ?? [];
    const existingById: Record<string, Account> = {};
    for (const a of existingForGroup) existingById[a.instance_id] = a;

    const remoteIds = new Set<string>();
    const encSecret = await encrypt(group.AccessKeySecret, encKey);

    for (const inst of instances) {
      remoteIds.add(inst.instanceId);
      const existingRow = existingById[inst.instanceId] ?? null;
      const net = resolveNetworkMetadata(inst as any, existingRow as any);

      if (existingRow) {
        await db.prepare(`UPDATE accounts SET access_key_id=?,access_key_secret=?,region_id=?,instance_id=?,max_traffic=?,schedule_enabled=?,schedule_start_enabled=?,schedule_stop_enabled=?,start_time=?,stop_time=?,schedule_blocked_by_traffic=?,instance_status=?,remark=?,site_type=?,group_key=?,instance_name=?,instance_type=?,internet_max_bandwidth_out=?,public_ip=?,public_ip_mode=?,eip_allocation_id=?,eip_address=?,eip_managed=?,private_ip=?,cpu=?,memory=?,os_name=?,stopped_mode=? WHERE id=?`)
          .bind(group.AccessKeyId, encSecret, group.regionId, inst.instanceId, group.maxTraffic,
            group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
            group.startTime, group.stopTime, group.scheduleBlockedByTraffic ? 1 : 0,
            inst.status || (existingRow.instance_status || 'Unknown'),
            group.remark || inst.instanceName || inst.instanceId,
            group.siteType, group.groupKey,
            inst.instanceName || '', inst.instanceType || '',
            inst.internetMaxBandwidthOut, inst.publicIp || '',
            net.public_ip_mode, net.eip_allocation_id,
            net.eip_address, net.eip_managed,
            inst.privateIp || '', inst.cpu, inst.memory,
            inst.osName || '', inst.stoppedMode || '',
            existingRow.id).run();
      } else {
        await db.prepare(`INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_used,traffic_billing_month,instance_status,updated_at,last_keep_alive_at,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,private_ip,cpu,memory,os_name,stopped_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
          .bind(group.AccessKeyId, encSecret, group.regionId, inst.instanceId, group.maxTraffic,
            group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
            group.startTime, group.stopTime, group.scheduleBlockedByTraffic ? 1 : 0,
            new Date().toISOString().substring(0, 7), inst.status || 'Unknown',
            group.remark || inst.instanceName || inst.instanceId,
            group.siteType, group.groupKey,
            inst.instanceName || '', inst.instanceType || '',
            inst.internetMaxBandwidthOut, inst.publicIp || '',
            net.public_ip_mode, net.eip_allocation_id,
            net.eip_address, net.eip_managed,
            inst.privateIp || '', inst.cpu, inst.memory,
            inst.osName || '', inst.stoppedMode || '').run();
      }
    }

    // Remove instances no longer in remote
    for (const a of existingForGroup) {
      if (!remoteIds.has(a.instance_id)) {
        await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
      }
    }
  }

  // Remove groups no longer configured
  const configuredKeys = new Set(groups.map(g => g.groupKey));
  for (const a of existing) {
    const gk = a.group_key || buildGroupKey(a.access_key_id, a.region_id);
    if (!configuredKeys.has(gk)) {
      await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
    }
  }
}
