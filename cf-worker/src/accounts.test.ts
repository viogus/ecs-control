import { describe, it, expect, vi, beforeEach } from 'vitest';

// accounts.ts 只从 aliyun-api 引入 getInstances；整体 mock 掉避免真发请求。
vi.mock('./aliyun-api', () => ({ getInstances: vi.fn() }));

import { getInstances } from './aliyun-api';
import { refreshAccountMetadata, refreshAllAccountsMetadata } from './accounts';
import type { Account } from './types';

/** 只记录写语句的极简 D1 桩 */
class FakeDb {
  statements: { sql: string; args: unknown[] }[] = [];
  prepare(sql: string): any {
    const db = this;
    const stmt: any = {
      args: [] as unknown[],
      bind(...args: unknown[]) { stmt.args = args; return stmt; },
      async run() { db.statements.push({ sql, args: stmt.args }); return {}; },
      async first() { return null; },
      async all() { return { results: [] }; },
    };
    return stmt;
  }
  logs(): string[] {
    return this.statements.filter(s => /INSERT INTO logs/i.test(s.sql)).map(s => String(s.args[1] ?? ''));
  }
  updates(): { sql: string; args: unknown[] }[] {
    return this.statements.filter(s => !/INSERT INTO logs/i.test(s.sql));
  }
}

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 1, access_key_id: 'LTAI', access_key_secret: 'plain-secret', region_id: 'cn-hongkong',
    instance_id: 'i-abc123', remark: 'uk', instance_name: 'iZbp1xxxx',
    public_ip: '8.208.8.54', public_ip_mode: 'ecs_public_ip', eip_address: '', eip_allocation_id: '',
    eip_managed: 0, internet_max_bandwidth_out: 1,
    ...overrides,
  } as unknown as Account;
}

function remoteInstance(overrides: Record<string, unknown> = {}) {
  return {
    instanceId: 'i-abc123', instanceName: 'iZbp1xxxx', status: 'Running',
    publicIp: '8.208.77.147', eipAllocationId: '', eipAddress: '',
    internetMaxBandwidthOut: 100, ...overrides,
  } as any;
}

beforeEach(() => {
  vi.mocked(getInstances).mockReset();
});

describe('refreshAccountMetadata', () => {
  it('公网 IP 变了 → 写库并记一条 info(带 旧值 -> 新值)', async () => {
    vi.mocked(getInstances).mockResolvedValue([remoteInstance()]);
    const db = new FakeDb();

    await expect(refreshAccountMetadata(db as unknown as D1Database, 'enc', account(),
      (type, msg) => db.prepare('INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)').bind(type, msg, 0).run()))
      .resolves.toBe(true);

    const update = db.updates().find(u => /UPDATE accounts/i.test(u.sql));
    expect(update).toBeDefined();
    expect(update!.args[0]).toBe('8.208.77.147');
    expect(update!.args[5]).toBe(100);
    expect(db.logs()).toEqual(['实例网络元数据已刷新 [uk]: public_ip 8.208.8.54 -> 8.208.77.147']);
  });

  it('IP 没变 → 仍写库(幂等)但不记日志', async () => {
    vi.mocked(getInstances).mockResolvedValue([remoteInstance({ publicIp: '8.208.8.54' })]);
    const db = new FakeDb();

    await refreshAccountMetadata(db as unknown as D1Database, 'enc', account(),
      (type, msg) => db.prepare('INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)').bind(type, msg, 0).run());

    expect(db.updates()).toHaveLength(1);
    expect(db.logs()).toEqual([]);
  });

  it('远端查不到该实例 → 不写库、不报错', async () => {
    vi.mocked(getInstances).mockResolvedValue([remoteInstance({ instanceId: 'i-other' })]);
    const db = new FakeDb();
    const logs: string[] = [];

    await expect(refreshAccountMetadata(db as unknown as D1Database, 'enc', account(),
      (_t, m) => { logs.push(m); })).resolves.toBe(false);

    expect(db.updates()).toHaveLength(0);
    expect(logs).toEqual([]);
  });

  it('阿里云接口失败 → 记 warning,不抛异常', async () => {
    vi.mocked(getInstances).mockRejectedValue(new Error('InvalidAccessKeyId.NotFound'));
    const db = new FakeDb();
    const logs: string[] = [];

    await expect(refreshAccountMetadata(db as unknown as D1Database, 'enc', account(),
      (_t, m) => { logs.push(m); })).resolves.toBe(false);

    expect(db.updates()).toHaveLength(0);
    expect(logs).toHaveLength(1);
    expect(logs[0]).toContain('实例元数据刷新失败 [uk]');
    expect(logs[0]).toContain('InvalidAccessKeyId');
  });

  it('EIP 被重绑时同步修正 eip_address / public_ip_mode', async () => {
    vi.mocked(getInstances).mockResolvedValue([remoteInstance({
      publicIp: '8.208.77.147', eipAllocationId: 'eip-xyz', eipAddress: '8.208.77.147',
    })]);
    const db = new FakeDb();

    await refreshAccountMetadata(db as unknown as D1Database, 'enc', account(),
      (type, msg) => db.prepare('INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)').bind(type, msg, 0).run());

    const update = db.updates().find(u => /UPDATE accounts/i.test(u.sql))!;
    expect(update.args[1]).toBe('eip');
    expect(update.args[3]).toBe('8.208.77.147');
    expect(db.logs()[0]).toContain('eip_address  -> 8.208.77.147');
  });
});

describe('refreshAllAccountsMetadata', () => {
  it('逐个刷新,跳过没有 instance_id 的行,单个失败不影响其余', async () => {
    vi.mocked(getInstances)
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce([remoteInstance({ instanceId: 'i-2', publicIp: '9.9.9.9' })]);
    const db = new FakeDb();
    const logs: string[] = [];

    await refreshAllAccountsMetadata(db as unknown as D1Database, 'enc', [
      account({ id: 1 }),
      account({ id: 2, instance_id: 'i-2' }),
      account({ id: 3, instance_id: '' }),
    ], (_t, m) => { logs.push(m); });

    expect(vi.mocked(getInstances)).toHaveBeenCalledTimes(2);
    expect(db.updates().filter(u => /UPDATE accounts/i.test(u.sql))).toHaveLength(1);
    expect(logs.some(l => l.includes('实例元数据刷新失败 [uk]'))).toBe(true);
    expect(logs.some(l => l.includes('public_ip 8.208.8.54 -> 9.9.9.9'))).toBe(true);
  });
});
