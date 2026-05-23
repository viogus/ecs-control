import type { Env, Account } from './types';
import { getSetting, addLog } from './db';
import { controlInstance } from './aliyun-api';

function applyTzOffset(utcDate: Date, offsetHours: number): { day: number; hours: number; minutes: number; isoDate: string } {
  const ms = utcDate.getTime() + offsetHours * 3600000;
  const d = new Date(ms);
  return {
    day: d.getUTCDate(),
    hours: d.getUTCHours(),
    minutes: d.getUTCMinutes(),
    isoDate: d.toISOString().substring(0, 10),
  };
}

export async function runScheduleCheck(env: Env, account: Account): Promise<string[]> {
  const logs: string[] = [];
  const keepAlive = (await getSetting(env.DB, 'keep_alive', '0')) === '1';
  const monthlyAutoStart = (await getSetting(env.DB, 'monthly_auto_start', '0')) === '1';
  const shutdownMode = await getSetting(env.DB, 'shutdown_mode', 'KeepCharging');
  const rawOffset = parseInt(await getSetting(env.DB, 'tz_offset_hours', '8'));
  const tzOffset = isNaN(rawOffset) ? 8 : rawOffset;
  const now = new Date();
  const local = applyTzOffset(now, tzOffset);
  const label = account.remark || account.instance_id || account.instance_name;
  const status = account.instance_status;

  const scheduleBlocked = !!account.schedule_blocked_by_traffic;
  const scheduleActive = !!account.schedule_enabled && !scheduleBlocked;

  function timeToMin(hm: string): number { const [h, m] = hm.split(':').map(Number); return h * 60 + m; }

  function shouldRun(targetTime: string, lastDate: string): boolean {
    if (!/^\d{2}:\d{2}$/.test(targetTime)) return false;
    if (lastDate === local.isoDate) return false;
    const targetMin = timeToMin(targetTime);
    const currentMin = local.hours * 60 + local.minutes;
    return Math.abs(currentMin - targetMin) <= 5;
  }

  // Safety net: block auto-start actions when local time is between scheduled stop and start
  function inStopWindow(): boolean {
    if (!scheduleActive) return false;
    if (!account.schedule_stop_enabled || !account.schedule_start_enabled) return false;
    if (!/^\d{2}:\d{2}$/.test(account.stop_time) || !/^\d{2}:\d{2}$/.test(account.start_time)) return false;
    const stopMin = timeToMin(account.stop_time);
    const startMin = timeToMin(account.start_time);
    const cur = local.hours * 60 + local.minutes;
    if (stopMin < startMin) return cur >= stopMin && cur < startMin;
    return cur >= stopMin || cur < startMin; // overnight stop window
  }

  const stopWindow = inStopWindow();

  // Scheduled stop
  if (scheduleActive && account.schedule_stop_enabled && shouldRun(account.stop_time, account.schedule_last_stop_date)) {
    if (status === 'Running') {
      try {
        await controlInstance(account, 'stop', shutdownMode);
        account.auto_start_blocked = 1;  // Prevent keepalive from overriding schedule
        await addLog(env.DB, 'info', `Scheduled STOP [${label}] ${account.stop_time}`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_stop_date=?, auto_start_blocked=1 WHERE id=?')
          .bind('Stopping', local.isoDate, account.id).run();
        logs.push(`[${label}] Scheduled STOP`);
      } catch (e: any) {
        // IncorrectInstanceStatus = instance already transitioning, mark schedule serviced
        if (e.message?.includes('IncorrectInstanceStatus')) {
          account.auto_start_blocked = 1;
          await env.DB.prepare('UPDATE accounts SET schedule_last_stop_date=?, auto_start_blocked=1 WHERE id=?')
            .bind(local.isoDate, account.id).run();
          await addLog(env.DB, 'warning', `Scheduled STOP skipped [${label}]: already ${account.instance_status}`);
        } else {
          await addLog(env.DB, 'error', `Scheduled STOP failed [${label}]: ${e.message}`);
        }
      }
    } else {
      account.auto_start_blocked = 1;
      await env.DB.prepare('UPDATE accounts SET schedule_last_stop_date=?, auto_start_blocked=1 WHERE id=?')
        .bind(local.isoDate, account.id).run();
    }
  }

  // Scheduled start
  if (scheduleActive && account.schedule_start_enabled && shouldRun(account.start_time, account.schedule_last_start_date)) {
    if (status === 'Stopped') {
      try {
        await controlInstance(account, 'start');
        account.auto_start_blocked = 0;
        await addLog(env.DB, 'info', `Scheduled START [${label}] ${account.start_time}`);
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_start_date=?, auto_start_blocked=0 WHERE id=?')
          .bind('Starting', local.isoDate, account.id).run();
        logs.push(`[${label}] Scheduled START`);
      } catch (e: any) {
        if (e.message?.includes('IncorrectInstanceStatus')) {
          account.auto_start_blocked = 0;
          await env.DB.prepare('UPDATE accounts SET schedule_last_start_date=?, auto_start_blocked=0 WHERE id=?')
            .bind(local.isoDate, account.id).run();
          await addLog(env.DB, 'warning', `Scheduled START skipped [${label}]: already ${account.instance_status}`);
        } else {
          await addLog(env.DB, 'error', `Scheduled START failed [${label}]: ${e.message}`);
        }
      }
    } else {
      account.auto_start_blocked = 0;
      await env.DB.prepare('UPDATE accounts SET schedule_last_start_date=?, auto_start_blocked=0 WHERE id=?')
        .bind(local.isoDate, account.id).run();
    }
  }

  // Monthly auto-start (day 1 in local time)
  if (monthlyAutoStart && local.day === 1 && !scheduleBlocked && !account.auto_start_blocked && !stopWindow) {
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
  if (keepAlive && !account.auto_start_blocked && !account.schedule_blocked_by_traffic && !stopWindow) {
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
