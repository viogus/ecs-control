import type { Account } from './types';
import { addLog, getAccountById } from './db';
import { controlInstance, deleteInstance } from './aliyun-api';

export async function doControl(db: D1Database, accountId: number, action: 'start' | 'stop', shutdownMode = 'KeepCharging'): Promise<boolean> {
  const acc = await getAccountById(db, accountId);
  if (!acc) return false;
  try {
    await controlInstance(acc, action, shutdownMode);
    await addLog(db, 'info', `Instance ${action} OK [${acc.remark || acc.instance_id}]`);
    await db.prepare('UPDATE accounts SET instance_status=?, updated_at=? WHERE id=?')
      .bind(action === 'stop' ? 'Stopping' : 'Starting', Math.floor(Date.now() / 1000), accountId).run();
    return true;
  } catch (e: any) {
    await addLog(db, 'error', `Instance ${action} failed [${acc.remark || acc.instance_id}]: ${e.message}`);
    return false;
  }
}

export async function doDelete(db: D1Database, accountId: number): Promise<boolean> {
  const acc = await getAccountById(db, accountId);
  if (!acc) return false;
  await addLog(db, 'warning', `Release submitted: mark soft-deleted [${acc.remark || acc.instance_id}]`);
  await db.prepare('UPDATE accounts SET is_deleted = 1 WHERE id = ?').bind(accountId).run();
  return true;
}
