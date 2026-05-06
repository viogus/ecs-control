import type { Env, Account } from './types';
import { getSetting, addLog } from './db';
import { controlInstance } from './aliyun-api';

export async function runScheduleCheck(env: Env, account: Account): Promise<string[]> {
  const logs: string[] = [];
  const keepAlive = (await getSetting(env.DB, 'keep_alive', '0')) === '1';
  const monthlyAutoStart = (await getSetting(env.DB, 'monthly_auto_start', '0')) === '1';
  const shutdownMode = await getSetting(env.DB, 'shutdown_mode', 'KeepCharging');
  const now = new Date();
  const label = account.remark || account.instance_id || account.instance_name;
  const status = account.instance_status;

  const scheduleBlocked = !!account.schedule_blocked_by_traffic;
  const scheduleActive = !!account.schedule_enabled && !scheduleBlocked;

  function timeToMin(hm: string): number { const [h, m] = hm.split(':').map(Number); return h * 60 + m; }

  function shouldRun(now: Date, targetTime: string, lastDate: string): boolean {
    if (!/^\d{2}:\d{2}$/.test(targetTime)) return false;
    const today = now.toISOString().substring(0, 10);
    if (lastDate === today) return false;
    const targetMin = timeToMin(targetTime);
    const currentMin = now.getHours() * 60 + now.getMinutes();
    return Math.abs(currentMin - targetMin) <= 5;
  }

  // Scheduled stop
  if (scheduleActive && account.schedule_stop_enabled && shouldRun(now, account.stop_time, account.schedule_last_stop_date)) {
    if (status === 'Running') {
      try {
        await controlInstance(account, 'stop', shutdownMode);
        await addLog(env.DB, 'info', `Scheduled STOP [${label}] ${account.stop_time}`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_stop_date=?, auto_start_blocked=1 WHERE id=?')
          .bind('Stopping', now.toISOString().substring(0, 10), account.id).run();
        logs.push(`[${label}] Scheduled STOP`);
      } catch (e: any) { await addLog(env.DB, 'error', `Scheduled STOP failed [${label}]: ${e.message}`); }
    } else {
      await env.DB.prepare('UPDATE accounts SET schedule_last_stop_date=? WHERE id=?')
        .bind(now.toISOString().substring(0, 10), account.id).run();
    }
  }

  // Scheduled start
  if (scheduleActive && account.schedule_start_enabled && shouldRun(now, account.start_time, account.schedule_last_start_date)) {
    if (status === 'Stopped') {
      try {
        await controlInstance(account, 'start');
        await addLog(env.DB, 'info', `Scheduled START [${label}] ${account.start_time}`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_start_date=?, auto_start_blocked=0 WHERE id=?')
          .bind('Starting', now.toISOString().substring(0, 10), account.id).run();
        logs.push(`[${label}] Scheduled START`);
      } catch (e: any) { await addLog(env.DB, 'error', `Scheduled START failed [${label}]: ${e.message}`); }
    } else {
      await env.DB.prepare('UPDATE accounts SET schedule_last_start_date=? WHERE id=?')
        .bind(now.toISOString().substring(0, 10), account.id).run();
    }
  }

  // Monthly auto-start (day 1)
  if (monthlyAutoStart && now.getDate() === 1 && !scheduleBlocked && !account.auto_start_blocked) {
    if (status === 'Stopped') {
      try {
        await controlInstance(account, 'start');
        await addLog(env.DB, 'info', `Monthly auto-start [${label}]`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, last_keep_alive_at=? WHERE id=?')
          .bind('Starting', Math.floor(Date.now() / 1000), account.id).run();
        logs.push(`[${label}] Monthly auto-start`);
      } catch (e: any) { await addLog(env.DB, 'error', `Monthly auto-start failed [${label}]: ${e.message}`); }
    }
  }

  // Keepalive
  if (keepAlive && !account.auto_start_blocked && !account.schedule_blocked_by_traffic) {
    if (status === 'Stopped') {
      try {
        await controlInstance(account, 'start');
        await addLog(env.DB, 'info', `Keepalive START [${label}]`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, last_keep_alive_at=? WHERE id=?')
          .bind('Starting', Math.floor(Date.now() / 1000), account.id).run();
        logs.push(`[${label}] Keepalive START`);
      } catch (e: any) { await addLog(env.DB, 'error', `Keepalive START failed [${label}]: ${e.message}`); }
    }
  }

  return logs;
}
