import type { Account } from './types';
import { addLog, getAccountById } from './db';
import { controlInstance, deleteInstance, replaceManagedEip } from './aliyun-api';
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

export interface ReplaceIpResult {
  success: boolean; message?: string; label?: string; oldIp?: string; newIp?: string;
}

/**
 * 更换系统托管 EIP（对齐 PHP InstanceActionService::replaceInstanceIp）：
 * 先校验托管门禁，成功后才更新网络元数据；DDNS 同步与通知由调用方编排。
 */
export async function doReplaceIp(db: D1Database, encKey: string, accountId: number): Promise<ReplaceIpResult> {
  const acc = await getAccountById(db, accountId);
  if (!acc) return { success: false, message: '实例不存在' };
  if (acc.public_ip_mode !== 'eip' || !acc.eip_managed || !acc.eip_allocation_id) {
    return { success: false, message: '当前实例不是系统托管 EIP，无法更换公网 IP' };
  }
  const label = acc.remark || acc.instance_name || acc.instance_id;
  const oldIp = acc.eip_address || acc.public_ip || '';
  const secret = isEncrypted(acc.access_key_secret) ? await decrypt(acc.access_key_secret, encKey) : acc.access_key_secret;
  try {
    const eip = await replaceManagedEip({ ...acc, access_key_secret: secret });
    await db.prepare(`UPDATE accounts SET public_ip=?, public_ip_mode='eip', eip_allocation_id=?, eip_address=?, eip_managed=1 WHERE id=?`)
      .bind(eip.ipAddress, eip.allocationId, eip.ipAddress, accountId).run();
    await addLog(db, 'info', `EIP 已更换 [${label}] ${acc.instance_id} ${oldIp} -> ${eip.ipAddress}`);
    return { success: true, label, oldIp, newIp: eip.ipAddress };
  } catch (e: any) {
    await addLog(db, 'error', `EIP 更换失败 [${label}]: ${e.message}`);
    return { success: false, message: e.message, label, oldIp };
  }
}
