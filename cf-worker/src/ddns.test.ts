import { describe, it, expect, vi, afterEach } from 'vitest';
import rawVectors from '../../tests/ddns-name-vectors.json';
import type { Account, AccountGroup } from './types';
import {
  ASCII_FOLD,
  buildRecordName,
  effectivePublicIp,
  getGroupCounts,
  getGroupKey,
  isPublicIPv4,
  normalizeDomain,
  phpTrim,
  resolveGroupRemark,
  slug,
  syncDdns,
  type RecordNameInput,
} from './ddns';

/**
 * 与 PHP DdnsService 的算法对齐验收。
 *
 * 向量文件 tests/ddns-name-vectors.json 由 PHP 实现生成(tests/generate-ddns-vectors.php),
 * tests/DdnsServiceTest.php 跑同一份文件 —— 任何一端改了算法而没同步另一端,两边测试之一必然失败。
 */

interface Vectors {
  nameVectors: { input: RecordNameInput & { domain: string; same_group_instance_count: number }; expected?: string; error?: string }[];
  domainVectors: { input: string; expected: string }[];
  remarkVectors: { account: Record<string, string>; groups: Record<string, string>[]; expected: string }[];
  groupCountVectors: { accounts: Record<string, unknown>[]; expected: Record<string, number> }[];
  ipSelectionVectors: { public_ip_mode: string; eip_address: string; public_ip: string; expected: string }[];
  ipVectors: { ip: string; public: boolean }[];
}

const vectors = rawVectors as unknown as Vectors;

function asAccount(row: Record<string, unknown>): Account {
  return row as unknown as Account;
}

describe('DDNS 记录名与 PHP 一致', () => {
  it('共用向量文件非空(防止生成脚本未运行)', () => {
    expect(vectors.nameVectors.length).toBeGreaterThan(15);
    expect(vectors.ipVectors.length).toBeGreaterThan(20);
  });

  for (const [index, vector] of vectors.nameVectors.entries()) {
    const label = `name #${index} ${JSON.stringify(vector.input)}`;
    if (vector.error) {
      it(`${label} 抛错`, async () => {
        await expect(buildRecordName(vector.input, vector.input.domain)).rejects.toThrow(vector.error);
      });
    } else {
      it(`${label} -> ${vector.expected}`, async () => {
        await expect(buildRecordName(vector.input, vector.input.domain)).resolves.toBe(vector.expected);
      });
    }
  }

  it('组内多实例追加实例名后缀,单实例不加', async () => {
    const base: RecordNameInput = { account_remark: 'uk', instance_name: 'iZbp1xxxx', instance_id: 'i-abc123' };
    await expect(buildRecordName({ ...base, same_group_instance_count: 1 }, 'cdf.mba')).resolves.toBe('uk.cdf.mba');
    await expect(buildRecordName({ ...base, same_group_instance_count: 2 }, 'cdf.mba')).resolves.toBe('uk-izbp1xxxx.cdf.mba');
  });

  it('无可用备注时抛错而不是生成空记录名', async () => {
    await expect(buildRecordName({}, 'cdf.mba')).rejects.toThrow('DDNS 记录名生成失败');
    await expect(buildRecordName({ account_remark: 'uk' }, '')).rejects.toThrow('请先填写 DDNS 根域名');
  });
});

describe('slug 转写与 PHP 一致', () => {
  it('折叠表非空且与 PHP 同构(逐字符核对在 PHP 测试里做)', () => {
    expect(ASCII_FOLD.length).toBeGreaterThan(500);
    expect(ASCII_FOLD).toContain('é:e');
    expect(ASCII_FOLD).toContain('ß:ss');
    expect(ASCII_FOLD).toContain('Œ:oe');
  });

  it('去变音符、大小写、连字符归并', async () => {
    await expect(slug('  UK Node  ')).resolves.toBe('uk-node');
    await expect(slug('café')).resolves.toBe('cafe');
    await expect(slug('Ünïcödé')).resolves.toBe('unicode');
    await expect(slug('--x--')).resolves.toBe('x');
    await expect(slug('')).resolves.toBe('');
  });

  it('非 ASCII 字符走 sha1 兜底(与 PHP sha1(原始值) 前 8 位一致)', async () => {
    await expect(slug('香港节点')).resolves.toBe('c5295bea');
  });

  it('phpTrim 只剥 PHP trim() 覆盖的字节,不剥 NBSP', () => {
    expect(phpTrim('\u00a0a\u00a0')).toBe('\u00a0a\u00a0');
    expect(phpTrim(' \t a \n ')).toBe('a');
  });
});

describe('根域名归一化与 PHP 一致', () => {
  for (const [index, vector] of vectors.domainVectors.entries()) {
    it(`domain #${index} ${JSON.stringify(vector.input)}`, () => {
      expect(normalizeDomain(vector.input)).toBe(vector.expected);
    });
  }
});

describe('账号组备注解析与 PHP 一致', () => {
  for (const [index, vector] of vectors.remarkVectors.entries()) {
    it(`group remark #${index}`, () => {
      expect(resolveGroupRemark(asAccount(vector.account), vector.groups as unknown as AccountGroup[])).toBe(vector.expected);
    });
  }
});

describe('账号组键与组内计数与 PHP 一致', () => {
  it('组键缺失时回退 AK|region', () => {
    expect(getGroupKey(asAccount({ group_key: 'g1', access_key_id: 'LTAI', region_id: 'cn-hongkong' }))).toBe('g1');
    expect(getGroupKey(asAccount({ group_key: '', access_key_id: 'LTAI', region_id: 'cn-hongkong' }))).toBe('LTAI|cn-hongkong');
  });

  for (const [index, vector] of vectors.groupCountVectors.entries()) {
    it(`group counts #${index}`, () => {
      expect(getGroupCounts(vector.accounts.map(asAccount))).toEqual(vector.expected);
    });
  }
});

describe('生效公网 IP 选择与 PHP 一致', () => {
  for (const [index, vector] of vectors.ipSelectionVectors.entries()) {
    it(`public ip #${index}`, () => {
      expect(effectivePublicIp(asAccount(vector))).toBe(vector.expected);
    });
  }
});

describe('公网 IPv4 判定与 PHP filter_var 一致', () => {
  for (const [index, vector] of vectors.ipVectors.entries()) {
    it(`ip #${index} ${JSON.stringify(vector.ip)}`, () => {
      expect(isPublicIPv4(vector.ip)).toBe(vector.public);
    });
  }

  it('私有/保留地址一律拒绝,EIP 不合法时回退 ECS 公网 IP', () => {
    expect(isPublicIPv4('8.208.77.147')).toBe(true);
    expect(isPublicIPv4('10.0.0.1')).toBe(false);
    expect(isPublicIPv4('172.16.5.5')).toBe(false);
    expect(isPublicIPv4('172.32.5.5')).toBe(true);
    expect(isPublicIPv4('192.168.1.1')).toBe(false);
    expect(isPublicIPv4('169.254.1.1')).toBe(false);
    expect(isPublicIPv4('240.0.0.1')).toBe(false);
    expect(
      effectivePublicIp(asAccount({ public_ip_mode: 'eip', eip_address: '10.0.0.5', public_ip: '9.9.9.9' }))
    ).toBe('9.9.9.9');
  });
});

// ---- syncDdns 行为:与 PHP syncForAccounts/syncARecord 同序、同跳过条件 ----

class FakeStatement {
  private args: unknown[] = [];
  constructor(private db: FakeDb, private sql: string) {}
  bind(...args: unknown[]): FakeStatement { this.args = args; return this; }
  private settingKey(): string {
    if (this.args.length > 0) return String(this.args[0]);
    const match = /key\s*=\s*'([^']+)'/.exec(this.sql);
    return match ? match[1] : '';
  }
  async first<T>(): Promise<T | null> {
    if (/FROM settings/i.test(this.sql)) {
      const value = this.db.settings[this.settingKey()];
      return (value === undefined ? null : { value }) as T;
    }
    return null;
  }
  async all<T>(): Promise<{ results: T[] }> { return { results: [] }; }
  async run(): Promise<unknown> {
    this.db.statements.push({ sql: this.sql, args: this.args });
    return {};
  }
}

class FakeDb {
  statements: { sql: string; args: unknown[] }[] = [];
  constructor(public settings: Record<string, string>) {}
  prepare(sql: string): FakeStatement { return new FakeStatement(this, sql); }
  logs(): string[] {
    return this.statements
      .filter(s => /INSERT INTO logs/i.test(s.sql))
      .map(s => String(s.args[1] ?? ''));
  }
}

function ddnsSettings(extra: Record<string, string> = {}): Record<string, string> {
  return {
    ddns_enabled: '1',
    ddns_domain: 'cdf.mba',
    ddns_cf_token: 'plain-token',
    ddns_cf_zone_id: 'zone-1',
    ddns_cf_proxied: '0',
    // 账号组备注与实例备注不同,用于验证记录名取的是组备注(与 PHP resolveGroupRemark 一致)
    account_groups: JSON.stringify([{ groupKey: 'g1', remark: 'UK Main' }]),
    ...extra,
  };
}

function ddnsAccount(overrides: Partial<Account> = {}): Account {
  return {
    id: 1, access_key_id: 'LTAI', region_id: 'cn-hongkong', instance_id: 'i-abc123',
    public_ip: '8.208.77.147', public_ip_mode: 'ecs_public_ip', eip_address: '',
    remark: 'uk', instance_name: 'iZbp1xxxx', group_key: 'g1',
    ...overrides,
  } as unknown as Account;
}

/** 记录所有 Cloudflare 调用,并返回固定的列表/写入结果 */
function stubCloudflare(options: { list?: unknown[]; listSuccess?: boolean; writeSuccess?: boolean } = {}) {
  const calls: { url: string; method: string; body: any }[] = [];
  const fetchMock = vi.fn(async (url: any, init: any = {}) => {
    const method = String(init.method ?? 'GET').toUpperCase();
    const body = init.body ? JSON.parse(String(init.body)) : null;
    calls.push({ url: String(url), method, body });
    const payload = method === 'GET'
      ? { success: options.listSuccess ?? true, result: options.list ?? [], errors: options.listSuccess === false ? [{ message: 'boom' }] : [] }
      : { success: options.writeSuccess ?? true, result: {}, errors: options.writeSuccess === false ? [{ message: 'write-boom' }] : [] };
    return { json: async () => payload } as unknown as Response;
  });
  vi.stubGlobal('fetch', fetchMock);
  return calls;
}

afterEach(() => vi.unstubAllGlobals());

describe('syncDdns 与 PHP 同步行为一致', () => {
  it('IP 未变化时只查一次列表,不写 Cloudflare 也不记日志', async () => {
    const calls = stubCloudflare({ list: [{ id: 'rec-1', content: '8.208.77.147' }] });
    const db = new FakeDb(ddnsSettings());

    await syncDdns(db as unknown as D1Database, [ddnsAccount()], 'enc');

    expect(calls).toHaveLength(1);
    expect(calls[0].method).toBe('GET');
    expect(db.logs()).toEqual([]);
  });

  it('记录名取账号组备注,IP 变化时 PUT 并记一条 updated', async () => {
    const calls = stubCloudflare({ list: [{ id: 'rec-1', content: '1.1.1.1' }] });
    const db = new FakeDb(ddnsSettings());

    await syncDdns(db as unknown as D1Database, [ddnsAccount()], 'enc');

    expect(calls.map(c => c.method)).toEqual(['GET', 'PUT']);
    expect(calls[1].url).toContain('/dns_records/rec-1');
    expect(calls[1].body).toMatchObject({ type: 'A', name: 'uk-main.cdf.mba', content: '8.208.77.147', ttl: 1, proxied: false });
    expect(db.logs()).toEqual(['DDNS updated: uk-main.cdf.mba -> 8.208.77.147']);
  });

  it('记录不存在时创建,API 失败时记 warning(不再静默)', async () => {
    const createCalls = stubCloudflare({ list: [] });
    const createDb = new FakeDb(ddnsSettings());
    await syncDdns(createDb as unknown as D1Database, [ddnsAccount()], 'enc');
    expect(createCalls.map(c => c.method)).toEqual(['GET', 'POST']);
    expect(createDb.logs()).toEqual(['DDNS created: uk-main.cdf.mba -> 8.208.77.147']);

    stubCloudflare({ list: [{ id: 'rec-1', content: '1.1.1.1' }], writeSuccess: false });
    const failDb = new FakeDb(ddnsSettings());
    await syncDdns(failDb as unknown as D1Database, [ddnsAccount()], 'enc');
    expect(failDb.logs()).toHaveLength(1);
    expect(failDb.logs()[0]).toContain('DDNS sync failed [uk]');
    expect(failDb.logs()[0]).toContain('write-boom');

    stubCloudflare({ listSuccess: false });
    const listFailDb = new FakeDb(ddnsSettings());
    await syncDdns(listFailDb as unknown as D1Database, [ddnsAccount()], 'enc');
    expect(listFailDb.logs()[0]).toContain('DDNS sync failed [uk]');
    expect(listFailDb.logs()[0]).toContain('boom');
  });

  it('同组多实例按实例名区分记录名(与 PHP buildRecordNameForAccount 一致)', async () => {
    const calls = stubCloudflare({ list: [{ id: 'rec-1', content: '8.208.77.147' }] });
    const db = new FakeDb(ddnsSettings());
    const accounts = [
      ddnsAccount({ id: 1, instance_id: 'i-aaa111', instance_name: 'web-1' }),
      ddnsAccount({ id: 2, instance_id: 'i-bbb222', instance_name: 'web-2', public_ip: '9.9.9.9' }),
    ];

    await syncDdns(db as unknown as D1Database, accounts, 'enc');

    const listNames = calls
      .filter(c => c.method === 'GET')
      .map(c => decodeURIComponent(new URL(c.url).searchParams.get('name') ?? ''));
    expect(listNames).toEqual(['uk-main-web-1.cdf.mba', 'uk-main-web-2.cdf.mba']);
    expect(db.logs()).toEqual(['DDNS updated: uk-main-web-2.cdf.mba -> 9.9.9.9']);
  });

  it('未启用或没有公网 IP 时不触碰 Cloudflare', async () => {
    const disabled = stubCloudflare({ list: [] });
    await syncDdns(new FakeDb(ddnsSettings({ ddns_enabled: '0' })) as unknown as D1Database, [ddnsAccount()], 'enc');
    expect(disabled).toHaveLength(0);

    const noIp = stubCloudflare({ list: [] });
    await syncDdns(new FakeDb(ddnsSettings()) as unknown as D1Database, [ddnsAccount({ public_ip: '' })], 'enc');
    expect(noIp).toHaveLength(0);
  });

  it('非公网 IP 记 warning 而不是写进 DNS(与 PHP syncARecord 校验一致)', async () => {
    const calls = stubCloudflare({ list: [] });
    const db = new FakeDb(ddnsSettings());
    await syncDdns(db as unknown as D1Database, [ddnsAccount({ public_ip: '10.0.0.5' })], 'enc');

    expect(calls).toHaveLength(0);
    expect(db.logs()).toHaveLength(1);
    expect(db.logs()[0]).toContain('DDNS sync failed [uk]');
  });
});
