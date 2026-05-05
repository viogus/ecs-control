import type { Account } from './types';
import { addLog, getAccountById } from './db';
import { controlInstance, deleteInstance } from './aliyun-api';
import { decrypt, isEncrypted } from './crypto';

export async function doControl(db: D1Database, encKey: string, accountId: number, action: 'start' | 'stop', shutdownMode = 'KeepCharging'): Promise<boolean> {
  const acc = await getAccountById(db, accountId);
  if (!acc) return false;
  // Mark intent BEFORE API call — prevents keepalive restart even if API fails
  await db.prepare('UPDATE accounts SET instance_status=?, updated_at=?, auto_start_blocked=? WHERE id=?')
    .bind(action === 'stop' ? 'Stopping' : 'Starting', Math.floor(Date.now() / 1000), action === 'stop' ? 1 : 0, accountId).run();

  try {
    const secret = isEncrypted(acc.access_key_secret) ? await decrypt(acc.access_key_secret, encKey) : acc.access_key_secret;
    await controlInstance({ ...acc, access_key_secret: secret }, action, shutdownMode);
    await addLog(db, 'info', `Instance ${action} OK [${acc.remark || acc.instance_id}]`);
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
