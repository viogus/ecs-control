import type { Account, AccountGroup } from './types';
import { getSetting, getSettingPlain, addLog } from './db';
import { getGroupsFromSettings } from './accounts';

/**
 * DDNS 记录名算法 —— 必须与 PHP `DdnsService` 逐字节一致。
 *
 * 背景:PHP 容器与 cf-worker 可能同时管理同一个 Cloudflare Zone。只要两边对同一账号
 * 算出的记录名(或选出的公网 IP)不同,就会每 10 分钟互相覆盖一次,日志里表现为
 * "每轮都在同步同一条记录"。因此本文件的语义与 PHP 严格对齐,并由双端共用向量
 * `tests/ddns-name-vectors.json` 验收(PHP 与 vitest 都会跑)。
 *
 * 改动本文件任一算法时,必须同步修改 DdnsService.php 的对应方法。
 */

/**
 * 非 ASCII 字母 -> ASCII 折叠表("源字符:目标",逗号分隔)。
 * 与 PHP `DdnsService::ASCII_FOLD` 必须逐字符一致(测试会直接读 PHP 源文件核对)。
 * 刻意不用 iconv/NFD:musl 与 glibc 的 //TRANSLIT 结果不同(é -> "'" vs "e")。
 */
export const ASCII_FOLD =
  'ª:a,º:o,À:a,Á:a,Â:a,Ã:a,Ä:a,Å:a,Æ:ae,Ç:c,È:e,É:e,Ê:e,Ë:e,Ì:i,Í:i,Î:i,Ï:i,Ð:d,Ñ:n,Ò:o,Ó:o,Ô:o,Õ:o,Ö:o,Ø:o,Ù:u,Ú:u,Û:u,Ü:u,Ý:y,Þ:th,ß:ss,à:a,á:a,â:a,ã:a,ä:a,å:a,æ:ae,ç:c,è:e,é:e,ê:e,ë:e,ì:i,í:i,î:i,ï:i,ð:d,ñ:n,ò:o,ó:o,ô:o,õ:o,ö:o,ø:o,ù:u,ú:u,û:u,ü:u,ý:y,þ:th,ÿ:y,Ā:a,ā:a,Ă:a,ă:a,Ą:a,ą:a,Ć:c,ć:c,Ĉ:c,ĉ:c,Ċ:c,ċ:c,Č:c,č:c,Ď:d,ď:d,Đ:d,đ:d,Ē:e,ē:e,Ĕ:e,ĕ:e,Ė:e,ė:e,Ę:e,ę:e,Ě:e,ě:e,Ĝ:g,ĝ:g,Ğ:g,ğ:g,Ġ:g,ġ:g,Ģ:g,ģ:g,Ĥ:h,ĥ:h,Ħ:h,ħ:h,Ĩ:i,ĩ:i,Ī:i,ī:i,Ĭ:i,ĭ:i,Į:i,į:i,İ:i,ı:i,Ĳ:ij,ĳ:ij,Ĵ:j,ĵ:j,Ķ:k,ķ:k,ĸ:k,Ĺ:l,ĺ:l,Ļ:l,ļ:l,Ľ:l,ľ:l,Ŀ:l,ŀ:l,Ł:l,ł:l,Ń:n,ń:n,Ņ:n,ņ:n,Ň:n,ň:n,ŉ:n,Ŋ:n,ŋ:n,Ō:o,ō:o,Ŏ:o,ŏ:o,Ő:o,ő:o,Œ:oe,œ:oe,Ŕ:r,ŕ:r,Ŗ:r,ŗ:r,Ř:r,ř:r,Ś:s,ś:s,Ŝ:s,ŝ:s,Ş:s,ş:s,Š:s,š:s,ſ:s,Ţ:t,ţ:t,Ť:t,ť:t,Ŧ:t,ŧ:t,Ũ:u,ũ:u,Ū:u,ū:u,Ŭ:u,ŭ:u,Ů:u,ů:u,Ű:u,ű:u,Ų:u,ų:u,Ŵ:w,ŵ:w,Ŷ:y,ŷ:y,Ÿ:y,Ź:z,ź:z,Ż:z,ż:z,Ž:z,ž:z';

const FOLD: Record<string, string> = (() => {
  const map: Record<string, string> = {};
  for (const pair of ASCII_FOLD.split(',')) {
    const idx = pair.indexOf(':');
    if (idx > 0) map[pair.slice(0, idx)] = pair.slice(idx + 1);
  }
  return map;
})();

/** PHP trim() 只剥这些字节,不能用 JS 的 Unicode trim() */
export function phpTrim(value: unknown): string {
  return String(value ?? '').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}

/** 只折叠 ASCII 之外的表内字符(等价 PHP strtr) */
function foldAscii(value: string): string {
  let out = '';
  for (const ch of value) out += FOLD[ch] ?? ch;
  return out;
}

/** 等价 PHP strtolower:只动 A-Z,绝不像 JS toLowerCase 那样处理 U+212A 之类的字符 */
function asciiLower(value: string): string {
  let out = '';
  for (const ch of value) {
    const code = ch.codePointAt(0) as number;
    out += code >= 65 && code <= 90 ? String.fromCharCode(code + 32) : ch;
  }
  return out;
}

async function sha1Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * 等价 PHP `DdnsService::slug()`:
 * trim -> 折叠表 -> 转小写 -> 非 [a-z0-9] 归并为 '-' -> 去首尾 '-' ->
 * 空则取 sha1(原始值,已 trim) 前 8 位 -> 截断 48 字符。
 */
export async function slug(value: unknown): Promise<string> {
  const original = phpTrim(value);
  if (original === '') return '';

  let out = asciiLower(foldAscii(original)).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  if (out === '') out = (await sha1Hex(original)).slice(0, 8);
  return out.slice(0, 48);
}

/** 等价 PHP `DdnsService::normalizeDomain()` */
export function normalizeDomain(domain: unknown): string {
  let value = asciiLower(phpTrim(domain));
  value = value.replace(/^https?:\/\//, '');
  value = value.split('/')[0] ?? '';
  return value.replace(/^\.+|\.+$/g, '');
}

export interface RecordNameInput {
  account_remark?: string | null;
  remark?: string | null;
  instance_name?: string | null;
  instance_id?: string | null;
  same_group_instance_count?: number;
}

/**
 * 等价 PHP `DdnsService::buildRecordName()`。
 * 组内多实例时追加实例名后缀,避免同组实例争抢同一条记录。
 */
export async function buildRecordName(account: RecordNameInput, domain: string): Promise<string> {
  const root = normalizeDomain(domain);
  if (root === '') throw new Error('请先填写 DDNS 根域名');

  // PHP 的 ?? 只在键不存在/为 null 时回退:空字符串仍会被采用
  let accountSlug = await slug(account.account_remark ?? account.remark ?? '');
  const instanceSlug = await slug(account.instance_name ?? '');
  const shortId = await slug(String(account.instance_id ?? '').replace(/^i-/, ''));

  if (accountSlug === '') accountSlug = instanceSlug || shortId;
  if (accountSlug === '') throw new Error('DDNS 记录名生成失败，请检查账号备注或实例名称');

  let subdomain = accountSlug;
  if (Number(account.same_group_instance_count ?? 1) > 1) {
    const suffix = instanceSlug || shortId;
    if (suffix !== '' && suffix !== accountSlug) subdomain += '-' + suffix;
  }

  return `${subdomain.replace(/^-+|-+$/g, '')}.${root}`;
}

/** 等价 PHP `DdnsService::getGroupKey()`(注意 PHP 的 ?: 把 "0" 也当假值) */
export function getGroupKey(account: Account): string {
  const groupKey = String(account.group_key ?? '');
  if (groupKey !== '' && groupKey !== '0') return groupKey;
  return `${account.access_key_id ?? ''}|${account.region_id ?? ''}`;
}

/** 等价 PHP `DdnsService::getGroupCounts()` */
export function getGroupCounts(accounts: Account[]): Record<string, number> {
  const counts: Record<string, number> = {};
  for (const account of accounts) {
    if (!account.instance_id) continue;
    const key = getGroupKey(account);
    counts[key] = (counts[key] ?? 0) + 1;
  }
  return counts;
}

/** 等价 PHP `DdnsService::resolveGroupRemark()`:优先账号组备注,回退实例备注 */
export function resolveGroupRemark(account: Account, groups: AccountGroup[]): string {
  const groupKey = phpTrim(account.group_key);
  if (groupKey !== '') {
    for (const group of groups) {
      if (String(group.groupKey ?? '') === groupKey) return phpTrim(group.remark);
    }
  }
  return phpTrim(account.remark);
}

/** 等价 PHP `DdnsService::buildRecordNameForAccount()` */
export async function recordNameForAccount(
  account: Account,
  groups: AccountGroup[],
  groupCounts: Record<string, number>,
  domain: string
): Promise<string> {
  return buildRecordName(
    {
      account_remark: resolveGroupRemark(account, groups),
      remark: account.remark ?? '',
      instance_name: account.instance_name ?? '',
      instance_id: account.instance_id ?? '',
      same_group_instance_count: groupCounts[getGroupKey(account)] ?? 1,
    },
    domain
  );
}

/**
 * 等价 PHP `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)`。
 * 已用 PHP 8.2 逐项核对:拒绝 0/8、10/8、127/8、169.254/16、172.16/12、192.168/16、240/4,
 * 以及前导零/带空格/少于四段等格式;100.64/10、224/4 等 PHP 视为合法。
 */
export function isPublicIPv4(ip: unknown): boolean {
  const match = /^(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})$/.exec(String(ip ?? ''));
  if (!match) return false;

  const octets = match.slice(1).map(Number);
  if (octets.some(o => o > 255)) return false;

  const [a, b] = octets;
  if (a === 0 || a === 10 || a === 127 || a >= 240) return false;
  if (a === 169 && b === 254) return false;
  if (a === 172 && b >= 16 && b <= 31) return false;
  if (a === 192 && b === 168) return false;
  return true;
}

/** 等价 PHP `DdnsService::getEffectivePublicIp()`:EIP 必须是公网 IPv4,否则回退 ECS 公网 IP */
export function effectivePublicIp(account: Account): string {
  if (String(account.public_ip_mode ?? '') === 'eip') {
    const eip = phpTrim(account.eip_address);
    if (eip !== '' && isPublicIPv4(eip)) return eip;
  }
  return phpTrim(account.public_ip);
}

/** 等价 PHP `Helpers::getAccountLogLabel()` */
export function accountLogLabel(account: Account): string {
  const remark = phpTrim(account.remark);
  if (remark !== '') return remark;
  const name = phpTrim(account.instance_name);
  if (name !== '') return name;
  const id = phpTrim(account.instance_id);
  if (id !== '') return id;
  return String(account.access_key_id ?? '').slice(0, 7) + '***';
}

function cfErrors(json: any): string {
  const errors = Array.isArray(json?.errors) ? json.errors : [];
  if (errors.length === 0) return '未知错误';
  return errors.map((e: any) => e?.message ?? JSON.stringify(e)).join('；');
}

export async function syncDdns(db: D1Database, accounts: Account[], encKey: string): Promise<void> {
  const enabled = await getSetting(db, 'ddns_enabled', '0') === '1';
  const provider = await getSetting(db, 'ddns_provider', 'cloudflare');
  const domain = await getSetting(db, 'ddns_domain', '');
  const token = await getSettingPlain(db, 'ddns_cf_token', encKey);
  const zoneId = await getSetting(db, 'ddns_cf_zone_id', '');
  const proxied = await getSetting(db, 'ddns_cf_proxied', '0') === '1';
  // 与 PHP DdnsService::isEnabled() 对齐
  if (!enabled || provider !== 'cloudflare' || !domain || !token) return;

  const groups = await getGroupsFromSettings(db);
  const groupCounts = getGroupCounts(accounts);

  for (const account of accounts) {
    if (!account.instance_id) continue;
    const ip = effectivePublicIp(account);
    if (ip === '') continue;
    const label = accountLogLabel(account);
    try {
      // 与 PHP 同序:先生成记录名(可抛出配置/命名错误),再校验 IP
      const recordName = await recordNameForAccount(account, groups, groupCounts, domain);
      if (!isPublicIPv4(ip)) {
        await addLog(db, 'warning', `DDNS sync failed [${label}]: 公网 IP 为空或不是公网 IPv4`);
        continue;
      }

      const listRes = await fetch(
        `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records?type=A&name=${encodeURIComponent(recordName)}`,
        { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' } }
      );
      const list = await listRes.json().catch(() => ({})) as any;
      if (!list.success) throw new Error(`Cloudflare 查询记录失败: ${cfErrors(list)}`);
      const existing = list.result?.[0];

      if (existing && existing.content === ip) continue; // unchanged

      const method = existing ? 'PUT' : 'POST';
      const path = existing ? `/dns_records/${existing.id}` : '/dns_records';
      const body = JSON.stringify({ type: 'A', name: recordName, content: ip, ttl: 1, proxied, comment: 'Managed by ECS Control worker' });

      const res = await fetch(`https://api.cloudflare.com/client/v4/zones/${zoneId}${path}`, {
        method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body,
      });
      const json = await res.json().catch(() => ({})) as any;
      if (!json.success) throw new Error(`Cloudflare ${existing ? '更新' : '创建'}记录失败: ${cfErrors(json)}`);

      // 带上写入前的旧值:出现"每轮都在同步"时,它直接指出对方把记录改成了什么
      await addLog(db, 'info', `DDNS ${existing ? 'updated' : 'created'}: ${recordName} -> ${ip}` +
        (existing ? ` (from ${existing.content})` : ''));
    } catch (e: any) {
      await addLog(db, 'warning', `DDNS sync failed [${label}]: ${e?.message ?? e}`);
    }
  }
}
