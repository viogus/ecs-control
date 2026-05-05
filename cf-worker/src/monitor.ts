import type { Env, Account, TrafficResult } from './types';
import { getSettings, getSetting, updateAccountStatus, addLog } from './db';
import { getTraffic, getInstanceStatus, controlInstance } from './aliyun-api';

function isCredentialError(msg: string): boolean {
  const normalized = msg.toLowerCase();
  const codes = ['invalidaccesskeyid.notfound', 'invalidaccesskeyid', 'signaturedoesnotmatch',
    'incompletesignature', 'forbidden.accesskeydisabled', 'invalidsecuritytoken.expired',
    'invalidsecuritytoken.malformed', 'missingsecuritytoken'];
  const match = normalized.match(/\[([^\]]+)\]/);
  const code = match ? match[1].toLowerCase().trim() : '';
  if (code && codes.includes(code)) return true;
  const message = normalized.replace(/\[[^\]]+\]\s*/, '');
  return message.includes('access key is not found')
    || message.includes('access key id does not exist')
    || message.includes('signature does not match')
    || message.includes('incomplete signature')
    || message.includes('accesskeydisabled');
}

async function safeGetTraffic(account: Account, env: Env): Promise<TrafficResult> {
  try {
    const v = await getTraffic(account);
    return { success: true, value: v, status: 'ok', message: '' };
  } catch (e: any) {
    const msg = e.message ?? '';
    if (msg.includes('timeout') || msg.includes('cURL')) {
      return { success: false, value: null, status: 'timeout', message: 'CDT timeout' };
    }
    if (isCredentialError(msg)) {
      await addLog(env.DB, 'error', `CDT auth error [${account.remark || account.instance_id}]: AK invalid`);
      return { success: false, value: null, status: 'auth_error', message: 'AK invalid' };
    }
    return { success: false, value: null, status: 'sync_error', message: 'CDT sync failed' };
  }
}

async function safeGetStatus(account: Account, env: Env): Promise<string> {
  try { return await getInstanceStatus(account); }
  catch (e: any) {
    await addLog(env.DB, 'error', `Status query failed [${account.instance_id} / ${account.region_id}]: ${e.message}`);
    return 'Unknown';
  }
}

export async function runTrafficCheck(env: Env, account: Account): Promise<string[]> {
  const logs: string[] = [];
  const threshold = parseInt(await getSetting(env.DB, 'traffic_threshold', '95'));
  const shutdownMode = await getSetting(env.DB, 'shutdown_mode', 'KeepCharging');
  const thresholdAction = await getSetting(env.DB, 'threshold_action', 'stop_and_notify');
  const label = account.remark || account.instance_id || account.instance_name;

  const traffic = await safeGetTraffic(account, env);
  const status = await safeGetStatus(account, env);

  const now = Math.floor(Date.now() / 1000);
  const metadata: Record<string, unknown> = {
    traffic_api_status: traffic.status,
    traffic_api_message: traffic.message,
  };

  if (traffic.status === 'auth_error') {
    metadata.protection_suspended = 1;
    metadata.protection_suspend_reason = 'credential_invalid';
  } else if (account.protection_suspended && account.protection_suspend_reason === 'credential_invalid') {
    metadata.protection_suspended = 0;
    metadata.protection_suspend_reason = '';
    metadata.protection_suspend_notified_at = 0;
  }

  const usedTraffic = traffic.success ? (traffic.value ?? 0) : (account.traffic_used);
  await updateAccountStatus(env.DB, account.id, usedTraffic, status, now, metadata);

  const usagePercent = account.max_traffic > 0 ? (usedTraffic / account.max_traffic * 100) : 0;
  const overThreshold = usagePercent >= threshold;
  const overLimit = account.max_traffic > 0 && usedTraffic >= account.max_traffic;

  // Clear schedule block when traffic is back within limits
  if (traffic.success && usagePercent < threshold && account.schedule_blocked_by_traffic) {
    await env.DB.prepare('UPDATE accounts SET schedule_blocked_by_traffic = 0 WHERE id = ?')
      .bind(account.id).run();
  }

  if ((overThreshold || overLimit) && thresholdAction === 'stop_and_notify' && !account.protection_suspended) {
    if (status === 'Running') {
      try {
        await controlInstance(account, 'stop', shutdownMode);
        await addLog(env.DB, 'warning', `Traffic circuit break: STOP [${label}] ${usagePercent.toFixed(1)}%`);
        logs.push(`[${label}] Circuit break: STOP`);
        await updateAccountStatus(env.DB, account.id, usedTraffic, 'Stopping', now);
        await env.DB.prepare('UPDATE accounts SET schedule_blocked_by_traffic = 1 WHERE id = ?')
          .bind(account.id).run();
      } catch (e: any) {
        await addLog(env.DB, 'error', `Circuit break STOP failed [${label}]: ${e.message}`);
      }
    }
  }

  return logs;
}
