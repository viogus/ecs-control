import type { Env, Account, TrafficResult } from './types';
import { getSettings, getSetting, saveSetting, updateAccountStatus, addLog } from './db';
import { getTraffic, getInstanceStatus, controlInstance, getInstanceBill } from './aliyun-api';
import { sendEmail, sendWebhook } from './notification';

// ==== CDT 错误分类 ====
// 对齐 PHP Helpers::isCredentialInvalidError / isPermissionError / isNetworkError：
// 网络抖动绝不能判成鉴权失效，否则会误暂停自动停机保护。

/** 解析 signedRequest 抛出的 "Aliyun <action> error [CODE]: <msg>"；无 CODE ⇒ 请求未到达服务端 */
export function parseApiError(message: string): { code: string; message: string } {
  const match = message.match(/\[([^\]]+)\]/);
  if (!match) return { code: '', message };
  return { code: match[1].trim(), message: message.replace(/\[[^\]]+\]\s*/, ' ').trim() };
}

const CREDENTIAL_ERROR_CODES = ['invalidaccesskeyid.notfound', 'invalidaccesskeyid', 'signaturedoesnotmatch',
  'incompletesignature', 'forbidden.accesskeydisabled', 'invalidsecuritytoken.expired',
  'invalidsecuritytoken.malformed', 'missingsecuritytoken'];

export function isCredentialError(code: string, message: string): boolean {
  const c = code.toLowerCase().trim();
  if (c && CREDENTIAL_ERROR_CODES.includes(c)) return true;
  const m = message.toLowerCase();
  return m.includes('access key is not found')
    || m.includes('access key id does not exist')
    || m.includes('signature does not match')
    || m.includes('incomplete signature')
    || m.includes('accesskeydisabled');
}

const PERMISSION_ERROR_CODES = ['nopermission', 'accessdenied', 'unauthorized', 'invalidpermission', 'permissiondenied'];

export function isPermissionError(code: string, message: string): boolean {
  const c = code.toLowerCase().trim();
  if (c) {
    if (c.startsWith('forbidden')) return true;
    if (PERMISSION_ERROR_CODES.includes(c)) return true;
  }
  const m = message.toLowerCase();
  return m.includes('no permission') || m.includes('not authorized')
    || m.includes('access denied') || m.includes('forbidden');
}

/** 无服务端错误码 ⇒ 本地/传输层问题（连接失败、超时、DNS、fetch 中断等） */
export function isNetworkError(code: string, message: string): boolean {
  if (code) return false;
  if (/invalid JSON response/i.test(message)) return false; // 响应解析异常属同步错误
  return true;
}

async function safeGetTraffic(account: Account, env: Env): Promise<TrafficResult> {
  const label = account.remark || account.instance_id || account.instance_name;
  try {
    const v = await getTraffic(account);
    return { success: true, value: v, status: 'ok', message: '' };
  } catch (e: any) {
    const { code, message } = parseApiError(String(e?.message ?? ''));
    if (isCredentialError(code, message)) {
      await addLog(env.DB, 'error', `CDT 流量查询失败 [${label}]: AK 已失效`);
      return { success: false, value: null, status: 'auth_error', message: '账号 AK 已失效', code };
    }
    if (isPermissionError(code, message)) {
      await addLog(env.DB, 'error', `CDT 流量查询缺少权限 [${label}]: ${code} - ${message}`);
      return { success: false, value: null, status: 'permission_denied', message: '缺少 CDT 权限，请检查 RAM 授权', code };
    }
    if (isNetworkError(code, message)) {
      await addLog(env.DB, 'warning', `CDT 流量查询网络异常 [${label}]: ${message || '连接失败'}，将自动重试`);
      return { success: false, value: null, status: 'timeout', message: 'CDT 网络连接异常', code };
    }
    await addLog(env.DB, 'warning', `CDT 流量查询接口异常 [${label}]: ${message || code || '未知错误'}`);
    return { success: false, value: null, status: 'sync_error', message: 'CDT 接口异常', code };
  }
}

async function safeGetStatus(account: Account, env: Env): Promise<string> {
  try { return await getInstanceStatus(account); }
  catch (e: any) {
    await addLog(env.DB, 'error', `Status query failed [${account.instance_id} / ${account.region_id}]: ${e.message}`);
    return 'Unknown';
  }
}

// ==== 持续失败告警（对齐 PHP MonitorService::notifyCdtUnavailable）====

/** 熔断豁免阈值：CDT 持续失败超过此时长后基于陈旧数据熔断会误停，改为跳过并告警 */
const CDT_EXEMPT_SECONDS = 900;
/** 告警推送失败后的重试窗口 */
const CDT_NOTIFY_RETRY_SECONDS = 1800;

/** 告警去重键后缀：CDT 按 AK 聚合查询，同一 AK 的多实例共用一次告警 */
async function cdtNotifyKeySuffix(account: Account): Promise<string> {
  const ak = (account.access_key_id || '').toLowerCase();
  if (!ak) return `acct-${account.id}`;
  const digest = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(ak));
  return 'ak-' + Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('').substring(0, 16);
}

async function notifyCdtUnavailable(
  env: Env, account: Account, failureAt: number, suffix: string, lastStatus: string, lastMessage: string
): Promise<void> {
  const notifiedKey = `cdt_failure_notified_at_${suffix}`;
  const attemptKey = `cdt_failure_notify_attempt_at_${suffix}`;
  const now = Math.floor(Date.now() / 1000);
  const label = account.remark || account.instance_id || account.instance_name;

  if (await getSetting(env.DB, notifiedKey, '')) return; // 已成功告警过
  const lastAttempt = parseInt((await getSetting(env.DB, attemptKey, '0')) || '0', 10);
  if (lastAttempt > 0 && now - lastAttempt < CDT_NOTIFY_RETRY_SECONDS) return; // 窗口内不重复推送

  const failedMinutes = Math.max(1, Math.round((now - failureAt) / 60));
  const statusText = lastStatus + (lastMessage ? `（${lastMessage}）` : '');
  const lines = [
    `【ECS 服务器管家】流量数据中断 - 自动停机保护暂停`,
    `账号: ${label}`,
    `实例编号: ${account.instance_id || '-'}`,
    `区域: ${account.region_id || '-'}`,
    `中断时长: ${failedMinutes} 分钟`,
    `最后错误: ${statusText || '未知'}`,
    `影响: 数据中断期间不会触发流量熔断，可能继续超量计费`,
    `处理建议: 检查 AK 的 CDT 权限与网络连通性；数据恢复后保护会自动重新启用。`,
  ];
  const body = lines.join('\n');

  const mailOk = await sendEmail(env.DB, '流量数据中断 - 自动停机保护暂停', body);
  const whOk = await sendWebhook(env.DB, body, env.ENCRYPTION_KEY);
  const delivered = mailOk && whOk;

  await addLog(env.DB, delivered ? 'warning' : 'error',
    `CDT 流量数据持续中断 ${failedMinutes} 分钟，自动停机保护暂时失效 [${label}] 最后错误:${statusText}`
    + (delivered ? '' : `（告警推送失败，${Math.round(CDT_NOTIFY_RETRY_SECONDS / 60)} 分钟后重试）`));

  // 只有推送成功才写成功标记；失败仅记尝试时间，窗口期满后自动重试
  await saveSetting(env.DB, attemptKey, String(now));
  if (delivered) await saveSetting(env.DB, notifiedKey, String(now));
}

export async function runTrafficCheck(env: Env, account: Account, preloaded?: Record<string, string>): Promise<string[]> {
  const cfg = async (k: string, d = '') => preloaded ? (preloaded[k] ?? d) : await getSetting(env.DB, k, d);
  const logs: string[] = [];
  const threshold = parseInt(await cfg('traffic_threshold', '95'));
  const shutdownMode = await cfg('shutdown_mode', 'KeepCharging');
  const thresholdAction = await cfg('threshold_action', 'stop_and_notify');
  const label = account.remark || account.instance_id || account.instance_name;
  const apiInterval = parseInt(await cfg('api_interval', '600'));

  const now = Math.floor(Date.now() / 1000);
  const cacheAge = now - account.updated_at;

  // Cache hit: skip full API check, refresh status only if transient
  if (account.updated_at > 0 && cacheAge < apiInterval) {
    if (account.instance_status === 'Starting' || account.instance_status === 'Stopping') {
      const freshStatus = await safeGetStatus(account, env);
      account.instance_status = freshStatus;
      await env.DB.prepare('UPDATE accounts SET instance_status = ? WHERE id = ?')
        .bind(freshStatus, account.id).run();
      // Don't update updated_at — keep old value so transient re-checks every minute
      // until it settles, at which point updated_at expires naturally for full check
    }
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
    if (traffic.success) {
      await addLog(env.DB, 'info', `账号鉴权已恢复，自动停机保护已重新启用 [${label}]`);
    }
  }

  // ==== 失败冷却标记 + 持续失败告警（对齐 PHP cdt_failure_at_* / notifyCdtUnavailable）====
  const failureKey = `cdt_failure_at_${account.id}`;
  const notifySuffix = await cdtNotifyKeySuffix(account);
  const notifiedKey = `cdt_failure_notified_at_${notifySuffix}`;
  const attemptKey = `cdt_failure_notify_attempt_at_${notifySuffix}`;

  let failureAt = 0;
  if (traffic.success) {
    // 恢复成功：清除失败与告警标记，下次中断可重新告警
    await env.DB.prepare('DELETE FROM settings WHERE key IN (?, ?, ?)')
      .bind(failureKey, notifiedKey, attemptKey).run();
  } else {
    failureAt = parseInt((await getSetting(env.DB, failureKey, '0')) || '0', 10);
    if (!failureAt) {
      failureAt = now;
      await saveSetting(env.DB, failureKey, String(now));
    }
  }
  const outageSeconds = failureAt > 0 ? now - failureAt : 0;
  // 数据持续失败超过豁免窗口：基于陈旧数据熔断可能误停，跳过本轮并告警一次
  const breakerSuppressed = !traffic.success && outageSeconds > CDT_EXEMPT_SECONDS;
  if (breakerSuppressed) {
    await notifyCdtUnavailable(env, account, failureAt, notifySuffix, traffic.status, traffic.message);
    logs.push(`[${label}] 流量数据持续中断 ${Math.round(outageSeconds / 60)} 分钟，跳过熔断`);
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

  if (!breakerSuppressed && (overThreshold || overLimit) && thresholdAction === 'stop_and_notify' && !account.protection_suspended) {
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
  const costEnabled = (await cfg('cost_threshold_enabled', '0')) === '1';
  if (costEnabled && status === 'Running' && !account.protection_suspended) {
    const costThreshold = parseFloat(await cfg('cost_threshold', '0.48'));
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
