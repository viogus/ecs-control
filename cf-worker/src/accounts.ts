import type { Account, AccountGroup } from './types';
import { decrypt, encrypt, isEncrypted } from './crypto';
import { getInstances } from './aliyun-api';
import { rowToAccount, getAccounts, addLog } from './db';

export async function buildGroupKey(accessKeyId: string, regionId: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(`${accessKeyId}|${regionId}`));
  return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('').substring(0, 16);
}

export async function mergeMaskedAccountGroupSecrets(groups: unknown, existingRaw?: string | null, encKey?: string): Promise<Record<string, unknown>[]> {
  const normalizedGroups = Array.isArray(groups) ? groups.map(group => ({ ...(group as Record<string, unknown>) })) : [];
  if (!existingRaw) {
    return normalizedGroups;
  }

  try {
    const existingGroups = JSON.parse(existingRaw);
    if (!Array.isArray(existingGroups)) {
      return normalizedGroups;
    }

    const existingByKey: Record<string, Record<string, unknown>> = {};
    for (const group of existingGroups as Record<string, unknown>[]) {
      const groupKey = String(group.groupKey ?? '');
      const derivedKey = groupKey || await buildGroupKey(String(group.AccessKeyId ?? ''), String(group.regionId ?? ''));
      existingByKey[derivedKey] = group;
    }

    for (const group of normalizedGroups) {
      if ((group.AccessKeySecret ?? '') === '********') {
        const groupKey = String(group.groupKey ?? '');
        const derivedKey = groupKey || await buildGroupKey(String(group.AccessKeyId ?? ''), String(group.regionId ?? ''));
        const existingSecret = existingByKey[derivedKey]?.AccessKeySecret ?? '********';
        // Try to decrypt with current key — if it fails, leave as ********
        // so the user is forced to re-enter the secret
        if (existingSecret !== '********' && encKey && isEncrypted(existingSecret)) {
          try {
            await decrypt(existingSecret, encKey);
          } catch {
            // Can't decrypt — existing secret uses a different key
            group.AccessKeySecret = '********';
            continue;
          }
        }
        group.AccessKeySecret = existingSecret;
      }
    }
  } catch {
    // Keep the submitted groups as-is if stored JSON is corrupt.
  }

  return normalizedGroups;
}

/**
 * Encrypt plaintext AccessKeySecret in account groups before persisting to settings.
 * Mirrors PHP ConfigManager::encryptGroupSecrets().
 * Skips already-encrypted values (checked by crypto.isEncrypted) to avoid double-encryption
 * on partial updates (e.g. schedule block toggles via updateStoredAccountGroupScheduleBlock).
 */
export async function encryptGroupSecrets(groups: Record<string, unknown>[], encKey: string): Promise<Record<string, unknown>[]> {
    for (const g of groups) {
        const s = String(g.AccessKeySecret ?? '');
        if (s && s !== '********') {
            if (isEncrypted(s)) {
                // Try to decrypt with current key first (handles cross-key re-encryption)
                try {
                    const plaintext = await decrypt(s, encKey);
                    g.AccessKeySecret = await encrypt(plaintext, encKey);
                } catch {
                    // Decryption failed (wrong key) — leave as-is
                }
            } else {
                // Plaintext — encrypt with current key
                g.AccessKeySecret = await encrypt(s, encKey);
            }
        }
    }
    return groups;
}


export async function getGroupsFromSettings(db: D1Database, encKey?: string): Promise<AccountGroup[]> {
  const raw = await db.prepare("SELECT value FROM settings WHERE key = 'account_groups'")
    .first<{ value: string }>();
  if (!raw?.value) return [];
  try {
    const groups = JSON.parse(raw.value);
    if (!Array.isArray(groups)) return [];
    return Promise.all(groups.map(async (g: any) => {
      let secret: string = g.AccessKeySecret ?? '';
      if (encKey && isEncrypted(secret)) {
        secret = await decrypt(secret, encKey);
      }
      return {
        groupKey: g.groupKey ?? await buildGroupKey(g.AccessKeyId ?? '', g.regionId ?? ''),
        AccessKeyId: g.AccessKeyId ?? '',
        AccessKeySecret: secret,
        regionId: g.regionId ?? '',
        siteType: g.siteType ?? (g.regionId?.startsWith('cn-') && g.regionId !== 'cn-hongkong' ? 'china' : 'international'),
        maxTraffic: parseFloat(g.maxTraffic ?? 200),
        remark: g.remark ?? '',
        scheduleEnabled: !!(g.scheduleEnabled ?? false),
        scheduleStartEnabled: !!(g.scheduleStartEnabled ?? false),
        scheduleStopEnabled: !!(g.scheduleStopEnabled ?? false),
        startTime: g.startTime ?? '',
        stopTime: g.stopTime ?? '',
        scheduleBlockedByTraffic: !!(g.scheduleBlockedByTraffic ?? false),
      };
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
    const gk = a.group_key || await buildGroupKey(a.access_key_id, a.region_id);
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

    // Remove instances no longer in remote (only if API returned data — empty response = API failure)
    if (remoteIds.size > 0) {
    for (const a of existingForGroup) {
      if (!remoteIds.has(a.instance_id)) {
        onLog?.('warning', `Removing instance not found in remote [${a.instance_id}] (group: ${group.groupKey})`);
        await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
      }
    }
    }
  }

  // Remove groups no longer configured
  const configuredKeys = new Set(groups.map(g => g.groupKey));
  for (const a of existing) {
    const gk = a.group_key || await buildGroupKey(a.access_key_id, a.region_id);
    if (!configuredKeys.has(gk)) {
      onLog?.('warning', `Removing orphaned group instance [${a.instance_id}] (group removed from config)`);
      await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
    }
  }
}
