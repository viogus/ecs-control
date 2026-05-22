import type { Env, Account, TrafficResult } from './types';
import { getSettings, getSetting, updateAccountStatus, addLog } from './db';
import { getTraffic, getInstanceStatus, controlInstance, getInstanceBill } from './aliyun-api';

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
  const apiInterval = parseInt(await getSetting(env.DB, 'api_interval', '600'));

  const now = Math.floor(Date.now() / 1000);
  const cacheAge = now - account.updated_at;

  // Use cached values if within API interval (reduces CPU/Alibaba API calls)
  if (account.updated_at > 0 && cacheAge < apiInterval) {
    account.instance_status = account.instance_status; // already set from DB
    await env.DB.prepare('UPDATE accounts SET updated_at = ? WHERE id = ?').bind(now, account.id).run();
    return logs;
  }

  const traffic = await safeGetTraffic(account, env);
  const status = await safeGetStatus(account, env);

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
  account.instance_status = status;
  await updateAccountStatus(env.DB, account.id, usedTraffic, status, now, metadata);

  const usagePercent = account.max_traffic > 0 ? (usedTraffic / account.max_traffic * 100) : 0;
  const overThreshold = usagePercent >= threshold;
  const overLimit = account.max_traffic > 0 && usedTraffic >= account.max_traffic;

  // Clear schedule block when traffic is back within limits
  if (traffic.success && usagePercent < threshold && account.schedule_blocked_by_traffic) {
    account.schedule_blocked_by_traffic = 0;
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
        account.schedule_blocked_by_traffic = 1;
        await env.DB.prepare('UPDATE accounts SET schedule_blocked_by_traffic = 1 WHERE id = ?')
          .bind(account.id).run();
      } catch (e: any) {
        await addLog(env.DB, 'error', `Circuit break STOP failed [${label}]: ${e.message}`);
      }
    }
  }

  // Cost circuit breaker
  const costEnabled = await getSetting(env.DB, 'cost_threshold_enabled', '0') === '1';
  if (costEnabled && status === 'Running' && !account.protection_suspended) {
    const costThreshold = parseFloat(await getSetting(env.DB, 'cost_threshold', '0.48'));
    if (costThreshold > 0) {
      try {
        const bill = await getInstanceBill(account, new Date().toISOString().substring(0, 7));
        if (bill.TotalCost >= costThreshold) {
          await controlInstance(account, 'stop', shutdownMode);
          await addLog(env.DB, 'warning', `Cost circuit break: STOP [${label}] $${bill.TotalCost.toFixed(2)} >= $${costThreshold}`);
          logs.push(`[${label}] Cost break: STOP ($${bill.TotalCost.toFixed(2)})`);
          await updateAccountStatus(env.DB, account.id, usedTraffic, 'Stopping', now);
          account.schedule_blocked_by_traffic = 1;
          account.auto_start_blocked = 1;
          await env.DB.prepare('UPDATE accounts SET schedule_blocked_by_traffic = 1, auto_start_blocked = 1 WHERE id = ?')
            .bind(account.id).run();
        }
      } catch (e: any) {
        await addLog(env.DB, 'warning', `Cost check failed [${label}]: ${e.message}`);
      }
    }
  }

  return logs;
}
