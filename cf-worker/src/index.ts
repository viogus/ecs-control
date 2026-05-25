import type { Env, JwtPayload } from './types';
import { verifyJwt, signJwt, verifyPassword, hashPassword, generateCsrfToken } from './auth';
import { getAccounts, getSetting, getSettings, saveSetting, getLogs, addLog, getAccountById } from './db';
import { runTrafficCheck } from './monitor';
import { runScheduleCheck } from './schedules';
import { syncDdns } from './ddns';
import { doControl, doDelete } from './instance-actions';
import { deleteInstance } from './aliyun-api';
import { decrypt, encrypt, isEncrypted } from './crypto';
import { buildPreview } from './ecs-create';
import { importFromDocker } from './migration';
import { renderHtml } from './frontend';
import { syncAccountGroups, getGroupsFromSettings } from './accounts';
import { sendEmail, sendWebhook } from './notification';
import { VUE_SOURCE } from './vue-source';
import type { MigrationExport } from './types';

async function jsonResponse(data: unknown, status = 200): Promise<Response> {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}

async function requireAuth(req: Request, env: Env): Promise<JwtPayload | null> {
  const auth = req.headers.get('Authorization') ?? '';
  const token = auth.replace(/^Bearer\s+/i, '');
  if (!token) return null;
  return verifyJwt(token, env.JWT_SECRET);
}

async function requireCsrf(req: Request, jwt: JwtPayload): Promise<boolean> {
  const hdr = req.headers.get('X-CSRF-Token') ?? '';
  return hdr === jwt.csrf_token;
}

const WRITE_ACTIONS = new Set([
  'save-config', 'upload-logo', 'refresh-account',
  'restore-schedule', 'control', 'delete', 'replace-ip',
  'preview-create', 'disk-options', 'create-ecs',
  'clear-logs', 'send-test-email', 'send-test-tg', 'send-test-wh',
  'export', 'import', 'schedule', 'save-account', 'add-account', 'remove-account',
]);

// === Route handlers (auth + body already processed) ===

type Handler = (env: Env, body: any, jwt: JwtPayload) => Promise<Response>;

async function handleStatus(env: Env): Promise<Response> {
  const accs = await getAccounts(env.DB);
  return jsonResponse({ data: accs.filter(a => a.instance_id).map(a => { const { access_key_secret: _, ...rest } = a as any; return rest; }), system_last_run: 0, sync_interval: 600 });
}

const MASKED_SETTINGS = new Set([
  'admin_password', 'notify_password', 'notify_tg_token', 'ddns_cf_token',
  'notify_tg_proxy_pass', 'monitor_key',
]);

async function handleConfig(env: Env, _body: any, jwt: JwtPayload): Promise<Response> {
  const settings = await env.DB.prepare('SELECT key,value FROM settings').all<{key:string;value:string}>();
  const cfg: Record<string,string> = {};
  for (const r of settings.results) {
    if (r.key === 'account_groups' && r.value) {
      try {
        const groups = JSON.parse(r.value);
        for (const g of groups) { if (g.AccessKeySecret) g.AccessKeySecret = '********'; }
        cfg[r.key] = JSON.stringify(groups);
      } catch { cfg[r.key] = '[]'; }
    } else if (MASKED_SETTINGS.has(r.key) && r.value) {
      cfg[r.key] = '********';
    } else {
      cfg[r.key] = r.value;
    }
  }
  return jsonResponse({ ...cfg, csrf_token: jwt.csrf_token });
}

async function handleSaveConfig(env: Env, body: any): Promise<Response> {
  for (const [k, v] of Object.entries(body)) {
    if (k === 'csrf_token') continue;
    await saveSetting(env.DB, k, String(v));
  }
  if (body.account_groups) {
    await saveSetting(env.DB, 'account_groups', JSON.stringify(body.account_groups));
  }
  const groups = await getGroupsFromSettings(env.DB);
  if (groups.length) {
    await syncAccountGroups(env.DB, env.ENCRYPTION_KEY, groups, (type, msg) => addLog(env.DB, type, msg));
  }
  return jsonResponse({ success: true });
}

async function handleControl(env: Env, body: any): Promise<Response> {
  const { accountId, action, shutdownMode } = body;
  const ok = await doControl(env.DB, env.ENCRYPTION_KEY, accountId, action, shutdownMode);
  return jsonResponse({ success: ok });
}

async function handleDelete(env: Env, body: any): Promise<Response> {
  const { accountId } = body;
  const ok = await doDelete(env.DB, accountId);
  return jsonResponse({ success: ok });
}

async function handleLogs(env: Env, body: any): Promise<Response> {
  const { tab } = body || {};
  const types = tab === 'heartbeat' ? ['heartbeat'] : ['info', 'warning', 'error'];
  const logs = await getLogs(env.DB, types, 20);
  return jsonResponse({ data: logs });
}

async function handleClearLogs(env: Env, body: any): Promise<Response> {
  const { tab } = body || {};
  const types = tab === 'heartbeat' ? ['heartbeat'] : ['info', 'warning', 'error'];
  for (const t of types) await env.DB.prepare('DELETE FROM logs WHERE type = ?').bind(t).run();
  return jsonResponse({ success: true });
}

async function handleRefreshAccount(env: Env, body: any): Promise<Response> {
  const { groupKey } = body;
  const groups = await getGroupsFromSettings(env.DB);
  const group = groups.find(g => g.groupKey === groupKey);
  if (!group) return jsonResponse({ success: false, message: 'Group not found' });
  await syncAccountGroups(env.DB, env.ENCRYPTION_KEY, [group], (type, msg) => addLog(env.DB, type, msg));
  return jsonResponse({ success: true });
}

async function handleRestoreSchedule(env: Env, body: any): Promise<Response> {
  const { accountId } = body;
  await env.DB.prepare('UPDATE accounts SET auto_start_blocked = 0 WHERE id = ?').bind(accountId).run();
  return jsonResponse({ success: true });
}

async function handleReplaceIp(): Promise<Response> {
  return jsonResponse({ success: false, message: 'CF Worker 版不支持更换公网 IP，请使用 Docker 版' });
}

async function handlePreviewCreate(env: Env, body: any): Promise<Response> {
  const { groupKey, regionId, instanceType, osKey, publicIpMode } = body;
  const groups = await getGroupsFromSettings(env.DB);
  const group = groups.find(g => g.groupKey === groupKey);
  if (!group) return jsonResponse({ success: false, message: 'Group not found' });
  const account: any = { ...group, access_key_secret: group.AccessKeySecret };
  try {
    const preview = await buildPreview(account, regionId || group.regionId, instanceType, osKey, publicIpMode);
    return jsonResponse({ success: true, data: preview });
  } catch (e: any) {
    return jsonResponse({ success: false, message: e.message });
  }
}

async function handleDiskOptions(): Promise<Response> {
  return jsonResponse({ success: false, message: 'CF Worker 版不支持磁盘选项查询，请使用 Docker 版' });
}

async function handleCreateEcs(): Promise<Response> {
  return jsonResponse({ success: false, message: 'CF Worker 版不支持创建 ECS，请使用 Docker 版' });
}

async function handleSendTestEmail(env: Env): Promise<Response> {
  const ok = await sendEmail(env.DB, 'ECS Control 测试邮件', '这是一封测试邮件，来自 CF Worker 部署。');
  return jsonResponse({ success: ok, message: ok ? '测试邮件已发送' : '发送失败，请检查邮箱配置' });
}

async function handleSendTestTg(): Promise<Response> {
  return jsonResponse({ success: false, message: 'CF Worker 版不支持 Telegram 通知，请使用 Docker 版' });
}

async function handleSendTestWh(env: Env): Promise<Response> {
  const ok = await sendWebhook(env.DB, 'ECS Control 测试 Webhook');
  return jsonResponse({ success: ok, message: ok ? 'Webhook 测试已发送' : '发送失败，请检查 Webhook 配置' });
}

async function handleExport(env: Env): Promise<Response> {
  const settingsRows = await env.DB.prepare('SELECT key,value FROM settings').all<{key:string;value:string}>();
  const settingsData: Record<string, string> = {};
  for (const r of settingsRows.results) settingsData[r.key] = r.value;
  const rawAccounts = await env.DB.prepare('SELECT * FROM accounts WHERE is_deleted = 0').all<Record<string, unknown>>();
  const groupJson = settingsData['account_groups'] || '[]';

  const decryptFailures: string[] = [];
  const accounts = await Promise.all(rawAccounts.results.map(async a => {
    const label = (a.instance_id || a.remark || a.access_key_id) as string;
    let secret = String(a.access_key_secret ?? '');
    if (isEncrypted(secret)) {
      try { secret = await decrypt(secret, env.ENCRYPTION_KEY); } catch { secret = ''; decryptFailures.push(label); }
    }
    return {
      access_key_id: String(a.access_key_id ?? ''), access_key_secret: secret,
      region_id: String(a.region_id ?? ''), instance_id: String(a.instance_id ?? ''),
      max_traffic: Number(a.max_traffic ?? 0), instance_status: String(a.instance_status ?? ''),
      remark: String(a.remark ?? ''), site_type: String(a.site_type ?? ''), group_key: String(a.group_key ?? ''),
      instance_name: String(a.instance_name ?? ''), instance_type: String(a.instance_type ?? ''),
      internet_max_bandwidth_out: Number(a.internet_max_bandwidth_out ?? 0),
      public_ip: String(a.public_ip ?? ''), public_ip_mode: String(a.public_ip_mode ?? ''),
      eip_allocation_id: String(a.eip_allocation_id ?? ''), eip_address: String(a.eip_address ?? ''),
      eip_managed: Number(a.eip_managed ?? 0), cpu: Number(a.cpu ?? 0), memory: Number(a.memory ?? 0),
      os_name: String(a.os_name ?? ''), schedule_enabled: Number(a.schedule_enabled ?? 0),
      schedule_start_enabled: Number(a.schedule_start_enabled ?? 0), schedule_stop_enabled: Number(a.schedule_stop_enabled ?? 0),
      start_time: String(a.start_time ?? ''), stop_time: String(a.stop_time ?? ''),
      schedule_blocked_by_traffic: Number(a.schedule_blocked_by_traffic ?? 0),
    };
  }));

  if (decryptFailures.length > 0) {
    return jsonResponse({
      success: false,
      message: `密钥解密失败: ${decryptFailures.join(', ')}`,
      warnings: [`以下账号密钥解密失败，备份中对应密钥为空: ${decryptFailures.join(', ')}`],
    }, 400);
  }

  return jsonResponse({ success: true, data: {
    settings: {
      admin_password: '', traffic_threshold: settingsData['traffic_threshold'] || '95',
      shutdown_mode: settingsData['shutdown_mode'] || 'KeepCharging',
      threshold_action: settingsData['threshold_action'] || 'stop_and_notify',
      keep_alive: settingsData['keep_alive'] === '1',
      monthly_auto_start: settingsData['monthly_auto_start'] === '1',
      api_interval: settingsData['api_interval'] || '600',
      enable_billing: settingsData['enable_billing'] === '1',
      cost_threshold: settingsData['cost_threshold'] || '0.48',
      cost_threshold_enabled: settingsData['cost_threshold_enabled'] === '1',
    },
    version: 1, exported_at: new Date().toISOString(),
    notification: {
      email_enabled: settingsData['notify_email_enabled'] === '1', email: settingsData['notify_email'] || '',
      host: settingsData['notify_host'] || '', port: settingsData['notify_port'] || '465',
      username: settingsData['notify_username'] || '', password: settingsData['notify_password'] || '',
      secure: settingsData['notify_secure'] || 'ssl', tg_enabled: settingsData['notify_tg_enabled'] === '1',
      tg_token: settingsData['notify_tg_token'] || '', tg_chat_id: settingsData['notify_tg_chat_id'] || '',
      wh_enabled: settingsData['notify_wh_enabled'] === '1', wh_url: settingsData['notify_wh_url'] || '',
      wh_method: settingsData['notify_wh_method'] || 'GET',
    },
    ddns: {
      enabled: settingsData['ddns_enabled'] === '1', domain: settingsData['ddns_domain'] || '',
      cf_zone_id: settingsData['ddns_cf_zone_id'] || '', cf_token: settingsData['ddns_cf_token'] || '',
      cf_proxied: settingsData['ddns_cf_proxied'] === '1',
    },
    accounts,
    account_groups: JSON.parse(groupJson),
  }});
}

async function handleSchedule(env: Env, body: any): Promise<Response> {
  const { accountId, scheduleEnabled, scheduleStartEnabled, scheduleStopEnabled, startTime, stopTime } = body;
  await env.DB.prepare('UPDATE accounts SET schedule_enabled=?, schedule_start_enabled=?, schedule_stop_enabled=?, start_time=?, stop_time=? WHERE id=?')
    .bind(scheduleEnabled ? 1 : 0, scheduleStartEnabled ? 1 : 0, scheduleStopEnabled ? 1 : 0, String(startTime || ''), String(stopTime || ''), accountId).run();
  return jsonResponse({ success: true });
}

async function handleSaveAccount(env: Env, body: any): Promise<Response> {
  const { id, access_key_id, access_key_secret, instance_id, region_id, remark, schedule_enabled, schedule_start_enabled, schedule_stop_enabled, start_time, stop_time } = body;
  if (!id) return jsonResponse({ success: false, message: '缺少账号 ID' });
  let secret = String(access_key_secret || '');
  if (secret && secret !== '********') {
    secret = await encrypt(secret, env.ENCRYPTION_KEY);
  } else { secret = ''; }
  const setSql = secret
    ? 'access_key_id=?, access_key_secret=?, instance_id=?, region_id=?, remark=?, schedule_enabled=?, schedule_start_enabled=?, schedule_stop_enabled=?, start_time=?, stop_time=?'
    : 'access_key_id=?, instance_id=?, region_id=?, remark=?, schedule_enabled=?, schedule_start_enabled=?, schedule_stop_enabled=?, start_time=?, stop_time=?';
  const params = secret
    ? [String(access_key_id || ''), secret, String(instance_id || ''), String(region_id || ''), String(remark || ''), schedule_enabled ? 1 : 0, schedule_start_enabled ? 1 : 0, schedule_stop_enabled ? 1 : 0, String(start_time || ''), String(stop_time || ''), id]
    : [String(access_key_id || ''), String(instance_id || ''), String(region_id || ''), String(remark || ''), schedule_enabled ? 1 : 0, schedule_start_enabled ? 1 : 0, schedule_stop_enabled ? 1 : 0, String(start_time || ''), String(stop_time || ''), id];
  await env.DB.prepare(`UPDATE accounts SET ${setSql} WHERE id=?`).bind(...params).run();
  return jsonResponse({ success: true });
}

async function handleAddAccount(env: Env, body: any): Promise<Response> {
  const { access_key_id, access_key_secret, region_id, instance_id, remark, schedule_enabled, schedule_start_enabled, schedule_stop_enabled, start_time, stop_time } = body;
  if (!access_key_id) return jsonResponse({ success: false, message: '缺少 AccessKey ID' });
  const secret = String(access_key_secret || '') ? await encrypt(String(access_key_secret), env.ENCRYPTION_KEY) : '';
  await env.DB.prepare(`INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,remark,site_type,group_key,instance_name,instance_type,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,traffic_billing_month,instance_status) VALUES (?,?,?,?,200,?,?,?,?,?,?,?,?,?,?,?,'Unknown')`)
    .bind(String(access_key_id), secret, String(region_id || ''), String(instance_id || ''), String(remark || ''), region_id&&region_id.startsWith('cn-')&&region_id!=='cn-hongkong'?'china':'international', '', '', '', schedule_enabled?1:0, schedule_start_enabled?1:0, schedule_stop_enabled?1:0, String(start_time||''), String(stop_time||''), new Date().toISOString().substring(0,7)).run();
  return jsonResponse({ success: true });
}

async function handleRemoveAccount(env: Env, body: any): Promise<Response> {
  const { id } = body;
  if (!id) return jsonResponse({ success: false, message: '缺少账号 ID' });
  await env.DB.prepare('DELETE FROM accounts WHERE id = ?').bind(id).run();
  return jsonResponse({ success: true });
}

async function handleUploadLogo(): Promise<Response> {
  return jsonResponse({ success: false, message: 'CF Worker 版不支持 Logo 上传，请使用 Docker 版' });
}

async function handleImport(env: Env, body: any): Promise<Response> {
  const { migration } = body;
  if (!migration) return jsonResponse({ success: false, message: '缺少迁移数据' });
  try {
    await importFromDocker(env.DB, env.ENCRYPTION_KEY, migration, { skipPassword: true });
    return jsonResponse({ success: true, message: '数据导入成功' });
  } catch (e: any) {
    return jsonResponse({ success: false, message: '导入失败: ' + e.message });
  }
}

const API_ROUTES: Record<string, Handler> = {
  'status': handleStatus, 'config': handleConfig, 'save-config': handleSaveConfig,
  'control': handleControl, 'delete': handleDelete, 'logs': handleLogs, 'clear-logs': handleClearLogs,
  'refresh-account': handleRefreshAccount, 'restore-schedule': handleRestoreSchedule,
  'replace-ip': handleReplaceIp, 'preview-create': handlePreviewCreate, 'disk-options': handleDiskOptions,
  'create-ecs': handleCreateEcs, 'send-test-email': handleSendTestEmail, 'send-test-tg': handleSendTestTg,
  'send-test-wh': handleSendTestWh, 'export': handleExport, 'schedule': handleSchedule,
  'save-account': handleSaveAccount, 'add-account': handleAddAccount, 'remove-account': handleRemoveAccount,
  'upload-logo': handleUploadLogo, 'import': handleImport,
};

// === Main worker ===

export default {
  async fetch(req: Request, env: Env): Promise<Response> {
    try {
    const url = new URL(req.url);
    const path = url.pathname;

    // Static assets
    if (req.method === 'GET' && path === '/vue.js') {
      return new Response(VUE_SOURCE, {
        headers: { 'Content-Type': 'application/javascript; charset=utf-8', 'Cache-Control': 'public, max-age=31536000' },
      });
    }

    // Frontend
    if (req.method === 'GET' && path === '/') {
      const csrf = generateCsrfToken();
      return new Response(renderHtml(csrf), {
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
      });
    }

    // Public endpoints
    if (path === '/api/health' && req.method === 'GET') {
      return jsonResponse({ ok: true });
    }

    if (path === '/api/check-init' && req.method === 'POST') {
      const pwd = await getSetting(env.DB, 'admin_password', '');
      return jsonResponse({ initialized: !!pwd });
    }

    if (path === '/api/login' && req.method === 'POST') {
      const { password } = await req.json() as any;
      const hash = await getSetting(env.DB, 'admin_password', '');
      if (!hash) return jsonResponse({ success: false, message: '未初始化' }, 403);
      const ip = req.headers.get('CF-Connecting-IP') ?? 'unknown';
      const cutoff = Math.floor(Date.now() / 1000) - 900;
      const recentAttempts = await env.DB.prepare('SELECT COUNT(*) as cnt FROM login_attempts WHERE ip = ? AND attempt_time > ?').bind(ip, cutoff).first<{cnt:number}>();
      if ((recentAttempts?.cnt ?? 0) >= 10) return jsonResponse({ success: false, message: '登录尝试过于频繁，请15分钟后重试' }, 429);
      const valid = await verifyPassword(password, hash);
      if (!valid) {
        await env.DB.prepare('INSERT INTO login_attempts (ip, attempt_time) VALUES (?, ?)').bind(ip, Math.floor(Date.now() / 1000)).run();
        return jsonResponse({ success: false, message: '密码错误' });
      }
      await env.DB.prepare('DELETE FROM login_attempts WHERE ip = ?').bind(ip).run();
      const csrf = generateCsrfToken();
      const token = await signJwt({ role: 'admin', csrf_token: csrf }, env.JWT_SECRET);
      return jsonResponse({ success: true, token, csrf_token: csrf });
    }

    if (path === '/api/setup' && req.method === 'POST') {
      const { password, migration, tz_offset_hours } = await req.json() as any;
      const existingPwd = await getSetting(env.DB, 'admin_password', '');
      if (existingPwd) return jsonResponse({ success: false, message: '已初始化' }, 403);
      if (!password || password.length < 8) return jsonResponse({ success: false, message: '密码至少需要8个字符' });
      if (password.length > 72) return jsonResponse({ success: false, message: '密码最多72个字符' });
      // Import first — if it fails, the instance stays uninitialized so the user can retry
      if (migration) {
        try { await importFromDocker(env.DB, env.ENCRYPTION_KEY, migration, { skipPassword: true }); }
        catch (e: any) { return jsonResponse({ success: false, message: `迁移数据导入失败: ${e.message}` }, 400); }
      }
      // Save user's chosen password AFTER import
      const hashed = await hashPassword(password);
      await saveSetting(env.DB, 'admin_password', hashed);
      if (!migration) {
        await saveSetting(env.DB, 'traffic_threshold', '95');
        await saveSetting(env.DB, 'tz_offset_hours', tz_offset_hours != null ? String(tz_offset_hours) : '8');
      }
      const csrf = generateCsrfToken();
      const token = await signJwt({ role: 'admin', csrf_token: csrf }, env.JWT_SECRET);
      return jsonResponse({ success: true, token, csrf_token: csrf });
    }

    // Auth gate
    const jwt = await requireAuth(req, env);
    if (!jwt) return jsonResponse({ error: '请先登录' }, 403);

    const routeKey = path.replace('/api/', '');
    if (WRITE_ACTIONS.has(routeKey)) {
      if (!await requireCsrf(req, jwt)) return jsonResponse({ error: 'CSRF 验证失败' }, 403);
    }

    // Route dispatch — all auth-gated APIs require POST
    if (req.method !== 'POST') {
      return jsonResponse({ error: 'Method not allowed' }, 405);
    }
    const handler = API_ROUTES[routeKey];
    if (handler) {
      try {
        const body: any = await req.json().catch(() => ({}));
        return await handler(env, body, jwt);
      } catch (e: any) {
        return jsonResponse({ success: false, message: e.message }, 400);
      }
    }

    return jsonResponse({ error: 'Not found' }, 404);
    } catch (e: any) { return jsonResponse({ error: 'Internal server error' }, 500); }
  },

  // Cron Triggers
  async scheduled(event: ScheduledEvent, env: Env, ctx: ExecutionContext): Promise<void> {
    const cron = event.cron;

    async function decryptAccount(acc: any): Promise<any> {
      if (isEncrypted(acc.access_key_secret)) {
        return { ...acc, access_key_secret: await decrypt(acc.access_key_secret, env.ENCRYPTION_KEY) };
      }
      return acc;
    }

    let accounts: any[] = [];
    try { accounts = await getAccounts(env.DB).then(a => a.filter(a => a.instance_id && !a.is_deleted)); }
    catch (e: any) { return; }

    if (cron === '* * * * *') {
      // Pre-load all settings once (saves 8+ individual getSetting queries per account)
      const settings = await getSettings(env.DB).catch(() => ({} as Record<string, string>));
      for (const acc of accounts) {
        let decrypted: any;
        try { decrypted = await decryptAccount(acc); }
        catch (e: any) {
          try { await addLog(env.DB, 'error', `Decrypt failed [${acc.remark || acc.instance_id}]: ${e.message}`); } catch {}
          continue;
        }
        ctx.waitUntil((async () => {
          try {
            const trafficLogs = await runTrafficCheck(env, decrypted, settings);
            const scheduleLogs = await runScheduleCheck(env, decrypted, settings);
            await addLog(env.DB, 'heartbeat',
              `[${acc.remark || acc.instance_id}] ${acc.instance_status} | ` +
              `Traffic: ${trafficLogs.length ? trafficLogs.join(',') : 'OK'} | ` +
              `Schedule: ${scheduleLogs.length ? scheduleLogs.join(',') : 'none'}`
            );
          } catch (e: any) {
            try { await addLog(env.DB, 'error', `Cron check failed [${acc.remark || acc.instance_id}]: ${e.message}`); } catch {}
          }
        })());
      }
    }

    if (cron === '*/10 * * * *') {
      ctx.waitUntil((async () => {
        try { await syncDdns(env.DB, accounts); }
        catch (e: any) { try { await addLog(env.DB, 'error', `DDNS cron failed: ${e.message}`); } catch {} }
      })());
    }

    if (cron === '5 3 * * *') {
      ctx.waitUntil((async () => {
        try {
          const cutoff30 = Math.floor(Date.now() / 1000) - 30 * 86400;
          const cutoff3 = Math.floor(Date.now() / 1000) - 3 * 86400;
          await env.DB.prepare('DELETE FROM logs WHERE created_at < ? AND type != ?').bind(cutoff30, 'heartbeat').run();
          await env.DB.prepare("DELETE FROM logs WHERE type = 'heartbeat' AND created_at < ?").bind(cutoff3).run();
          await env.DB.prepare('DELETE FROM login_attempts WHERE attempt_time < ?').bind(cutoff30).run();

          const pending = await env.DB.prepare('SELECT * FROM accounts WHERE is_deleted = 1').all();
          for (const acc of pending.results as any[]) {
            const secret = String(acc.access_key_secret ?? '');
            const plainSecret = isEncrypted(secret) ? await decrypt(secret, env.ENCRYPTION_KEY) : secret;
            const fakeAcc = { ...acc, access_key_secret: plainSecret } as any;
            try {
              if (acc.instance_id) await deleteInstance(fakeAcc);
              await env.DB.prepare("UPDATE accounts SET is_deleted = 2, instance_status = 'Released' WHERE id = ?").bind(acc.id).run();
              await addLog(env.DB, 'warning', `Instance released by cleanup [${acc.instance_id}]`);
            } catch (e: any) {
              try { await addLog(env.DB, 'error', `Cleanup release failed [${acc.instance_id}]: ${e.message}`); } catch {}
            }
          }
        } catch (e: any) {
          try { await addLog(env.DB, 'error', `Daily cleanup failed: ${e.message}`); } catch {}
        }
      })());
    }
  },
};
