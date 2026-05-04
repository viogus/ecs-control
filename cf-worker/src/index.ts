import type { Env, JwtPayload } from './types';
import { verifyJwt, signJwt, verifyPassword, hashPassword, generateCsrfToken } from './auth';
import { getAccounts, getSetting, saveSetting, getLogs, addLog, getAccountById } from './db';
import { runTrafficCheck } from './monitor';
import { runScheduleCheck } from './schedules';
import { syncDdns } from './ddns';
import { doControl, doDelete } from './instance-actions';
import { deleteInstance } from './aliyun-api';
import { decrypt, isEncrypted } from './crypto';
import { buildPreview } from './ecs-create';
import { importFromDocker } from './migration';
import { renderHtml } from './frontend';
import { syncAccountGroups, getGroupsFromSettings } from './accounts';

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
  'save-config', 'upload-logo', 'refresh-account', 'sync-group',
  'restore-schedule', 'control', 'delete', 'replace-ip',
  'preview-create', 'disk-options', 'create-ecs',
  'clear-logs', 'send-test-email', 'send-test-tg', 'send-test-wh',
  'export',
]);

export default {
  async fetch(req: Request, env: Env): Promise<Response> {
    const url = new URL(req.url);
    const path = url.pathname;

    // Frontend
    if (req.method === 'GET' && path === '/') {
      const csrf = generateCsrfToken();
      return new Response(renderHtml(csrf), {
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
      });
    }

    // Public endpoints
    if (path === '/api/check-init' && req.method === 'POST') {
      const pwd = await getSetting(env.DB, 'admin_password', '');
      return jsonResponse({ initialized: !!pwd, brand: { logo_url: '' } });
    }

    if (path === '/api/login' && req.method === 'POST') {
      const { password } = await req.json() as any;
      const hash = await getSetting(env.DB, 'admin_password', '');
      if (!hash) return jsonResponse({ success: false, message: '未初始化' }, 403);
      const valid = await verifyPassword(password, hash);
      if (!valid) return jsonResponse({ success: false, message: '密码错误' });
      const csrf = generateCsrfToken();
      const token = await signJwt({ role: 'admin', csrf_token: csrf }, env.JWT_SECRET);
      return jsonResponse({ success: true, token, csrf_token: csrf });
    }

    if (path === '/api/setup' && req.method === 'POST') {
      const { password, migration } = await req.json() as any;
      const existingPwd = await getSetting(env.DB, 'admin_password', '');
      if (existingPwd) return jsonResponse({ success: false, message: '已初始化' }, 403);
      const hashed = await hashPassword(password);
      await saveSetting(env.DB, 'admin_password', hashed);
      await saveSetting(env.DB, 'traffic_threshold', '95');
      if (migration) {
        try { await importFromDocker(env.DB, env.ENCRYPTION_KEY, migration); }
        catch (e: any) { return jsonResponse({ success: false, message: `迁移数据导入失败: ${e.message}` }); }
      }
      const csrf = generateCsrfToken();
      const token = await signJwt({ role: 'admin', csrf_token: csrf }, env.JWT_SECRET);
      return jsonResponse({ success: true, token, csrf_token: csrf });
    }

    // Auth gate for all other /api/*
    const jwt = await requireAuth(req, env);
    if (!jwt) return jsonResponse({ error: '请先登录' }, 403);

    if (WRITE_ACTIONS.has(path.replace('/api/', ''))) {
      if (!await requireCsrf(req, jwt)) return jsonResponse({ error: 'CSRF 验证失败' }, 403);
    }

    // === API Routes ===
    try {
      const body: any = req.method === 'POST' ? await req.json().catch(() => ({})) : {};

      if (path === '/api/status' && req.method === 'POST') {
        const accs = await getAccounts(env.DB);
        return jsonResponse({ data: accs.filter(a => a.instance_id), system_last_run: 0, sync_interval: 600 });
      }

      if (path === '/api/config' && req.method === 'POST') {
        const settings = await env.DB.prepare('SELECT key,value FROM settings').all<{key:string;value:string}>();
        const cfg: Record<string,string> = {};
        for (const r of settings.results) cfg[r.key] = r.value;
        return jsonResponse({ ...cfg, csrf_token: jwt.csrf_token });
      }

      if (path === '/api/save-config' && req.method === 'POST') {
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

      if (path === '/api/control' && req.method === 'POST') {
        const { accountId, action, shutdownMode } = body;
        const ok = await doControl(env.DB, accountId, action, shutdownMode);
        return jsonResponse({ success: ok });
      }

      if (path === '/api/delete' && req.method === 'POST') {
        const { accountId } = body;
        const ok = await doDelete(env.DB, accountId);
        return jsonResponse({ success: ok });
      }

      if (path === '/api/logs' && req.method === 'POST') {
        const { tab } = body as any;
        const types = tab === 'heartbeat' ? ['heartbeat'] : ['info', 'warning'];
        const logs = await getLogs(env.DB, types, 20);
        return jsonResponse({ data: logs });
      }

      if (path === '/api/clear-logs' && req.method === 'POST') {
        const { tab } = body as any;
        const types = tab === 'heartbeat' ? ['heartbeat'] : ['info', 'warning', 'error'];
        for (const t of types) await env.DB.prepare('DELETE FROM logs WHERE type = ?').bind(t).run();
        return jsonResponse({ success: true });
      }

      return jsonResponse({ error: 'Not found' }, 404);
    } catch (e: any) {
      return jsonResponse({ success: false, message: e.message }, 400);
    }
  },

  // Cron Triggers (FIXED: 3 triggers, traffic+schedule combined in * * * * *)
  async scheduled(event: ScheduledEvent, env: Env, ctx: ExecutionContext): Promise<void> {
    const cron = event.cron;
    const accounts = await getAccounts(env.DB).then(a => a.filter(a => a.instance_id && !a.is_deleted));

    if (cron === '* * * * *') {
      for (const acc of accounts) {
        ctx.waitUntil((async () => {
          const trafficLogs = await runTrafficCheck(env, acc);
          const scheduleLogs = await runScheduleCheck(env, acc);
          await addLog(env.DB, 'heartbeat',
            `[${acc.remark || acc.instance_id}] ${acc.instance_status} | ` +
            `Traffic: ${trafficLogs.length ? trafficLogs.join(',') : 'OK'} | ` +
            `Schedule: ${scheduleLogs.length ? scheduleLogs.join(',') : 'none'}`
          );
        })());
      }
    }

    if (cron === '*/10 * * * *') {
      await syncDdns(env.DB, accounts);
    }

    if (cron === '5 3 * * *') {
      const cutoff30 = Math.floor(Date.now() / 1000) - 30 * 86400;
      const cutoff3 = Math.floor(Date.now() / 1000) - 3 * 86400;
      await env.DB.prepare('DELETE FROM logs WHERE created_at < ? AND type != ?').bind(cutoff30, 'heartbeat').run();
      await env.DB.prepare("DELETE FROM logs WHERE type = 'heartbeat' AND created_at < ?").bind(cutoff3).run();

      // Process pending releases
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
          await addLog(env.DB, 'error', `Cleanup release failed [${acc.instance_id}]: ${e.message}`);
        }
      }
    }
  },
};
