# CF Worker 双轨部署 Implementation Plan

Spec: [cf-worker-migration-design.md](../specs/2026-05-03-cf-worker-migration-design.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add Cloudflare Workers deployment option alongside existing Docker/PHP version.

**Architecture:** Single Worker handles API routing + frontend HTML, backed by D1, KV, and 4 Cron Triggers. HMAC-SHA1 signing hand-rolled for Alibaba Cloud APIs. Docker version gets a single export endpoint for data migration.

**Tech Stack:** TypeScript, Cloudflare Workers, D1, KV, Web Crypto, bcryptjs, Vue 3 CDN

---

## File Map

```
cf-worker/                       ← NEW
├── package.json, tsconfig.json, wrangler.toml
├── db/schema.sql                ← D1 schema (11 tables)
├── src/
│   ├── index.ts                 ← fetch() + scheduled() entry
│   ├── types.ts                 ← All TS interfaces
│   ├── crypto.ts                ← AES-256-GCM encrypt/decrypt
│   ├── auth.ts                  ← bcrypt + JWT (HS256) + CSRF
│   ├── db.ts                    ← D1 helpers (getSettings, getAccounts, etc.)
│   ├── aliyun-sign.ts           ← HMAC-SHA1 + canonical query
│   ├── aliyun-api.ts            ← ECS/VPC/CDT/BSS/CMS wrappers
│   ├── accounts.ts              ← syncAccountGroups logic
│   ├── monitor.ts               ← traffic check + circuit breaker
│   ├── schedules.ts             ← schedule + keepalive
│   ├── ddns.ts                  ← Cloudflare DNS sync
│   ├── instance-actions.ts      ← start/stop/delete/replace-IP
│   ├── ecs-create.ts            ← ECS creation preview + execute
│   ├── notification.ts          ← Email + Webhook dispatch
│   ├── migration.ts             ← Import Docker export JSON
│   └── frontend.ts              ← HTML shell + Vue 3 app
├── test/index.test.ts

AliyunTrafficCheck.php           ← MODIFY: +exportForMigration()
index.php                        ← MODIFY: +?action=export route
```

---

### Task 1: Docker export endpoint

**Files:** Modify `AliyunTrafficCheck.php`, `index.php`

- [ ] **Step 1: Add `exportForMigration()` to AliyunTrafficCheck**

Append before the final `}`:

```php
public function exportForMigration()
{
    $settings = $this->configManager->getAllSettings();
    $accounts = $this->configManager->getAccounts();

    $decrypted = [];
    foreach ($accounts as $acc) {
        $s = $acc['access_key_secret'] ?? '';
        if (!empty($s)) $s = $this->configManager->decryptAccountSecret($s);
        $decrypted[] = [
            'access_key_id' => $acc['access_key_id'],
            'access_key_secret' => $s,
            'region_id' => $acc['region_id'],
            'instance_id' => $acc['instance_id'],
            'group_key' => $acc['group_key'],
            'max_traffic' => (float)($acc['max_traffic'] ?? 0),
            'instance_status' => $acc['instance_status'] ?? 'Unknown',
            'remark' => $acc['remark'] ?? '',
            'site_type' => $acc['site_type'] ?? 'international',
            'instance_name' => $acc['instance_name'] ?? '',
            'instance_type' => $acc['instance_type'] ?? '',
            'internet_max_bandwidth_out' => (int)($acc['internet_max_bandwidth_out'] ?? 0),
            'public_ip' => $acc['public_ip'] ?? '',
            'public_ip_mode' => $acc['public_ip_mode'] ?? 'ecs_public_ip',
            'eip_allocation_id' => $acc['eip_allocation_id'] ?? '',
            'eip_address' => $acc['eip_address'] ?? '',
            'eip_managed' => !empty($acc['eip_managed']),
            'cpu' => (int)($acc['cpu'] ?? 0),
            'memory' => (int)($acc['memory'] ?? 0),
            'os_name' => $acc['os_name'] ?? '',
            'schedule_enabled' => !empty($acc['schedule_enabled']),
            'start_time' => $acc['start_time'] ?? '',
            'stop_time' => $acc['stop_time'] ?? '',
            'schedule_blocked_by_traffic' => !empty($acc['schedule_blocked_by_traffic']),
        ];
    }

    $rawGroups = $settings['account_groups'] ?? '';
    $groups = [];
    if (!empty($rawGroups)) {
        $decoded = json_decode($rawGroups, true) ?: [];
        foreach ($decoded as $g) {
            $gs = $g['AccessKeySecret'] ?? '';
            if ($gs === '********') $gs = '';
            $groups[] = [
                'groupKey' => $g['groupKey'] ?? '',
                'AccessKeyId' => $g['AccessKeyId'] ?? '',
                'AccessKeySecret' => $gs,
                'regionId' => $g['regionId'] ?? '',
                'siteType' => $g['siteType'] ?? 'international',
                'maxTraffic' => (float)($g['maxTraffic'] ?? 200),
                'remark' => $g['remark'] ?? '',
                'scheduleEnabled' => !empty($g['scheduleEnabled']),
                'startTime' => $g['startTime'] ?? '',
                'stopTime' => $g['stopTime'] ?? '',
            ];
        }
    }

    return [
        'version' => 1,
        'exported_at' => date('Y-m-d H:i:s'),
        'settings' => [
            'admin_password' => $settings['admin_password'] ?? '',
            'traffic_threshold' => (int)($settings['traffic_threshold'] ?? 95),
            'shutdown_mode' => $settings['shutdown_mode'] ?? 'KeepCharging',
            'threshold_action' => $settings['threshold_action'] ?? 'stop_and_notify',
            'keep_alive' => ($settings['keep_alive'] ?? '0') === '1',
            'monthly_auto_start' => ($settings['monthly_auto_start'] ?? '0') === '1',
            'api_interval' => (int)($settings['api_interval'] ?? 600),
            'enable_billing' => ($settings['enable_billing'] ?? '0') === '1',
        ],
        'notification' => [
            'email_enabled' => ($settings['notify_email_enabled'] ?? '1') === '1',
            'email' => $settings['notify_email'] ?? '',
            'host' => $settings['notify_host'] ?? '',
            'port' => $settings['notify_port'] ?? '465',
            'username' => $settings['notify_username'] ?? '',
            'password' => $settings['notify_password'] ?? '',
            'secure' => $settings['notify_secure'] ?? 'ssl',
            'tg_enabled' => ($settings['notify_tg_enabled'] ?? '0') === '1',
            'tg_token' => $settings['notify_tg_token'] ?? '',
            'tg_chat_id' => $settings['notify_tg_chat_id'] ?? '',
            'wh_enabled' => ($settings['notify_wh_enabled'] ?? '0') === '1',
            'wh_url' => $settings['notify_wh_url'] ?? '',
            'wh_method' => $settings['notify_wh_method'] ?? 'GET',
            'wh_body' => $settings['notify_wh_body'] ?? '',
        ],
        'ddns' => [
            'enabled' => ($settings['ddns_enabled'] ?? '0') === '1',
            'domain' => $settings['ddns_domain'] ?? '',
            'cf_zone_id' => $settings['ddns_cf_zone_id'] ?? '',
            'cf_token' => $settings['ddns_cf_token'] ?? '',
            'cf_proxied' => ($settings['ddns_cf_proxied'] ?? '0') === '1',
        ],
        'accounts' => $decrypted,
        'account_groups' => $groups,
    ];
}
```

- [ ] **Step 2: Add export route to index.php**

Insert after the `require_csrf()` block (alongside other protected routes):

```php
if ($action === 'export') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode($app->exportForMigration(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
```

Add `'export'` to `$mutatingActions` array.

- [ ] **Step 3: Lint and commit**

```bash
docker exec ecs-control php -l /var/www/html/AliyunTrafficCheck.php
docker exec ecs-control php -l /var/www/html/index.php
git add AliyunTrafficCheck.php index.php
git commit -m "Add export endpoint for Docker-to-CF Worker data migration"
```

---

### Task 2: Project scaffold

**Files:** Create `cf-worker/package.json`, `cf-worker/tsconfig.json`, `cf-worker/wrangler.toml`

- [ ] **Step 1: package.json**

```json
{
  "name": "ecs-control-cf",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "scripts": {
    "deploy": "wrangler deploy",
    "dev": "wrangler dev",
    "db:init": "wrangler d1 execute ecs-control-db --file=db/schema.sql",
    "test": "vitest run"
  },
  "dependencies": { "bcryptjs": "^2.4.3" },
  "devDependencies": {
    "@cloudflare/workers-types": "^4.20250430.0",
    "@types/bcryptjs": "^2.4.6",
    "typescript": "^5.7.0",
    "vitest": "^3.1.0",
    "wrangler": "^4.14.0"
  }
}
```

- [ ] **Step 2: tsconfig.json**

```json
{
  "compilerOptions": {
    "target": "ES2022", "module": "ES2022",
    "moduleResolution": "bundler", "lib": ["ES2022"],
    "types": ["@cloudflare/workers-types"],
    "strict": true, "noEmit": true,
    "esModuleInterop": true, "skipLibCheck": true
  },
  "include": ["src/**/*.ts"]
}
```

- [ ] **Step 3: wrangler.toml**

```toml
name = "ecs-control"
main = "src/index.ts"
compatibility_date = "2026-05-03"

[[d1_databases]]
binding = "DB"
database_name = "ecs-control-db"
database_id = ""

[[kv_namespaces]]
binding = "KV"
id = ""

[triggers]
crons = ["* * * * *", "* * * * *", "*/10 * * * *", "5 3 * * *"]
```

- [ ] **Step 4: Install and commit**

```bash
cd cf-worker && npm install
git add cf-worker/
git commit -m "Scaffold CF Worker project with TypeScript, wrangler, bcryptjs"
```

---

### Task 3: D1 Schema

**Files:** Create `cf-worker/db/schema.sql`

- [ ] **Step 1: Write schema**

```sql
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);

CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_key_id TEXT NOT NULL DEFAULT '',
    access_key_secret TEXT NOT NULL DEFAULT '',
    region_id TEXT NOT NULL DEFAULT '',
    instance_id TEXT NOT NULL DEFAULT '',
    max_traffic REAL NOT NULL DEFAULT 0,
    traffic_used REAL NOT NULL DEFAULT 0,
    traffic_billing_month TEXT NOT NULL DEFAULT '',
    instance_status TEXT NOT NULL DEFAULT 'Unknown',
    health_status TEXT NOT NULL DEFAULT 'Unknown',
    stopped_mode TEXT NOT NULL DEFAULT '',
    updated_at INTEGER NOT NULL DEFAULT 0,
    last_keep_alive_at INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    auto_start_blocked INTEGER NOT NULL DEFAULT 0,
    schedule_enabled INTEGER NOT NULL DEFAULT 0,
    schedule_start_enabled INTEGER NOT NULL DEFAULT 0,
    schedule_stop_enabled INTEGER NOT NULL DEFAULT 0,
    start_time TEXT NOT NULL DEFAULT '',
    stop_time TEXT NOT NULL DEFAULT '',
    schedule_last_start_date TEXT NOT NULL DEFAULT '',
    schedule_last_stop_date TEXT NOT NULL DEFAULT '',
    schedule_blocked_by_traffic INTEGER NOT NULL DEFAULT 0,
    remark TEXT NOT NULL DEFAULT '',
    site_type TEXT NOT NULL DEFAULT 'international',
    group_key TEXT NOT NULL DEFAULT '',
    instance_name TEXT NOT NULL DEFAULT '',
    instance_type TEXT NOT NULL DEFAULT '',
    internet_max_bandwidth_out INTEGER NOT NULL DEFAULT 0,
    public_ip TEXT NOT NULL DEFAULT '',
    public_ip_mode TEXT NOT NULL DEFAULT 'ecs_public_ip',
    eip_allocation_id TEXT NOT NULL DEFAULT '',
    eip_address TEXT NOT NULL DEFAULT '',
    eip_managed INTEGER NOT NULL DEFAULT 0,
    private_ip TEXT NOT NULL DEFAULT '',
    cpu INTEGER NOT NULL DEFAULT 0,
    memory INTEGER NOT NULL DEFAULT 0,
    os_name TEXT NOT NULL DEFAULT '',
    traffic_api_status TEXT NOT NULL DEFAULT 'ok',
    traffic_api_message TEXT NOT NULL DEFAULT '',
    protection_suspended INTEGER NOT NULL DEFAULT 0,
    protection_suspend_reason TEXT NOT NULL DEFAULT '',
    protection_suspend_notified_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, message TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, attempt_time INTEGER NOT NULL);
CREATE TABLE traffic_hourly (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, traffic REAL NOT NULL, recorded_at INTEGER NOT NULL);
CREATE UNIQUE INDEX idx_th ON traffic_hourly (account_id, recorded_at);
CREATE TABLE traffic_daily (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, traffic REAL NOT NULL, recorded_at INTEGER NOT NULL);
CREATE UNIQUE INDEX idx_td ON traffic_daily (account_id, recorded_at);
CREATE TABLE billing_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, cache_type TEXT NOT NULL, billing_cycle TEXT NOT NULL DEFAULT '', data TEXT NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(account_id, cache_type, billing_cycle));
CREATE TABLE instance_traffic_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, instance_id TEXT NOT NULL, billing_month TEXT NOT NULL, traffic_bytes REAL NOT NULL DEFAULT 0, last_sample_ms INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL, UNIQUE(account_id, instance_id, billing_month));
CREATE TABLE ecs_create_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT UNIQUE NOT NULL, preview_id TEXT NOT NULL DEFAULT '', account_group_key TEXT NOT NULL, region_id TEXT NOT NULL, zone_id TEXT NOT NULL DEFAULT '', instance_type TEXT NOT NULL, image_id TEXT NOT NULL DEFAULT '', os_label TEXT NOT NULL DEFAULT '', instance_name TEXT NOT NULL DEFAULT '', vpc_id TEXT NOT NULL DEFAULT '', vswitch_id TEXT NOT NULL DEFAULT '', security_group_id TEXT NOT NULL DEFAULT '', internet_max_bandwidth_out INTEGER NOT NULL DEFAULT 0, system_disk_category TEXT NOT NULL DEFAULT '', system_disk_size INTEGER NOT NULL DEFAULT 0, instance_id TEXT NOT NULL DEFAULT '', public_ip TEXT NOT NULL DEFAULT '', public_ip_mode TEXT NOT NULL DEFAULT 'ecs_public_ip', eip_allocation_id TEXT NOT NULL DEFAULT '', eip_address TEXT NOT NULL DEFAULT '', eip_managed INTEGER NOT NULL DEFAULT 0, login_user TEXT NOT NULL DEFAULT '', login_password TEXT NOT NULL DEFAULT '', status TEXT NOT NULL, step TEXT NOT NULL DEFAULT '', error_message TEXT NOT NULL DEFAULT '', payload TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL);
CREATE TABLE telegram_bot_state (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE telegram_action_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, user_id TEXT NOT NULL, chat_id TEXT NOT NULL, action TEXT NOT NULL, account_id INTEGER NOT NULL, payload TEXT NOT NULL DEFAULT '', expires_at INTEGER NOT NULL, used_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);
```

- [ ] **Step 2: Commit**

```bash
git add cf-worker/db/schema.sql
git commit -m "Add D1 schema mirroring Docker SQLite (11 tables)"
```

---

### Task 4: Types

**Files:** Create `cf-worker/src/types.ts`

- [ ] **Step 1: Write all interfaces**

```typescript
// === Account row ===
export interface Account {
  id: number; access_key_id: string; access_key_secret: string;
  region_id: string; instance_id: string; max_traffic: number;
  traffic_used: number; traffic_billing_month: string;
  instance_status: string; health_status: string; stopped_mode: string;
  updated_at: number; last_keep_alive_at: number; is_deleted: number;
  auto_start_blocked: number;
  schedule_enabled: number; schedule_start_enabled: number; schedule_stop_enabled: number;
  start_time: string; stop_time: string;
  schedule_last_start_date: string; schedule_last_stop_date: string;
  schedule_blocked_by_traffic: number;
  remark: string; site_type: string; group_key: string;
  instance_name: string; instance_type: string;
  internet_max_bandwidth_out: number;
  public_ip: string; public_ip_mode: string;
  eip_allocation_id: string; eip_address: string; eip_managed: number;
  private_ip: string; cpu: number; memory: number; os_name: string;
  traffic_api_status: string; traffic_api_message: string;
  protection_suspended: number; protection_suspend_reason: string;
  protection_suspend_notified_at: number;
}

// === Account Group (from settings.account_groups JSON) ===
export interface AccountGroup {
  groupKey: string; AccessKeyId: string; AccessKeySecret: string;
  regionId: string; siteType: string; maxTraffic: number; remark: string;
  scheduleEnabled: boolean; scheduleStartEnabled: boolean; scheduleStopEnabled: boolean;
  startTime: string; stopTime: string; scheduleBlockedByTraffic?: boolean;
}

// === API ===
export interface TrafficResult {
  success: boolean; value: number | null; status: string; message: string;
}

export interface EcsInstance {
  instanceId: string; instanceName: string; status: string;
  regionId: string; regionName: string; instanceType: string;
  cpu: number; memory: number; internetMaxBandwidthOut: number;
  osName: string; publicIp: string; eipAllocationId: string;
  eipAddress: string; privateIp: string; stoppedMode: string; chargeType: string;
}

export interface EcsCreatePreview {
  account: { groupKey: string; label: string };
  regionId: string; zoneId: string; instanceType: string; instanceName: string;
  osKey: string; osLabel: string; imageId: string; imageSize: number;
  loginUser: string; loginPort: number;
  internetMaxBandwidthOut: number; publicIpMode: string;
  systemDisk: { category: string; size: number; min: number; max: number; unit: string };
  network: {
    vpc: { mode: string; name: string; cidr: string };
    vswitch: { mode: string; name: string; cidr: string };
    securityGroup: { mode: string; name: string; rules: string[] };
  };
  pricing: { available: boolean; currency: string; message: string };
  warnings: string[];
}

export interface InstanceSnapshot {
  id: number; accountId: number; groupKey: string;
  account: string; accountLabel: string;
  flow_total: number; flow_used: number; percentageOfUse: number;
  trafficStatus: string; trafficMessage: string;
  region: string; regionId: string; regionName: string;
  rate95: boolean; threshold: number;
  instanceStatus: string; status: string; healthStatus: string;
  stoppedMode: string; cpu: number; memory: number;
  lastUpdated: string; remark: string;
  instanceId: string; instanceName: string; instanceType: string;
  osName: string; internetMaxBandwidthOut: number;
  publicIp: string; publicIpMode: string;
  eipAllocationId: string; eipAddress: string; eipManaged: boolean;
  privateIp: string; maxTraffic: number; siteType: string;
  cost?: Record<string, unknown>;
  operationLocked?: boolean; operationLockedReason?: string;
}

// === JWT ===
export interface JwtPayload {
  role: 'admin'; csrf_token: string; iat: number; exp: number;
}

// === Env ===
export interface Env {
  DB: D1Database; KV: KVNamespace;
  ENCRYPTION_KEY: string; JWT_SECRET: string;
}

// === Migration ===
export interface MigrationExport {
  version: number; exported_at: string;
  settings: Record<string, unknown>;
  notification: Record<string, unknown>;
  ddns: Record<string, unknown>;
  accounts: Record<string, unknown>[];
  account_groups: Record<string, unknown>[];
}
```

- [ ] **Step 2: Commit**

```bash
git add cf-worker/src/types.ts
git commit -m "Add TypeScript types for accounts, API, env bindings, migration"
```

---

### Task 5: Crypto + Auth

**Files:** Create `cf-worker/src/crypto.ts`, `cf-worker/src/auth.ts`

- [ ] **Step 1: `crypto.ts`**

```typescript
function hexToBytes(hex: string): Uint8Array {
  const b = new Uint8Array(hex.length / 2);
  for (let i = 0; i < b.length; i++) b[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
  return b;
}

async function getKey(hex: string): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', hexToBytes(hex), { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

export async function encrypt(value: string, keyHex: string): Promise<string> {
  if (!value || !keyHex) return value;
  const key = await getKey(keyHex);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(value));
  const combined = new Uint8Array(iv.length + ct.byteLength);
  combined.set(iv); combined.set(new Uint8Array(ct), iv.length);
  return 'ENC2' + btoa(String.fromCharCode(...combined));
}

export async function decrypt(value: string, keyHex: string): Promise<string> {
  if (!value || value.length < 8 || !value.startsWith('ENC2') || !keyHex) return value;
  const key = await getKey(keyHex);
  const raw = Uint8Array.from(atob(value.slice(4)), c => c.charCodeAt(0));
  try {
    const dec = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: raw.slice(0, 12) }, key, raw.slice(12));
    return new TextDecoder().decode(dec);
  } catch { return value; }
}

export function isEncrypted(v: string): boolean { return v.length >= 8 && v.startsWith('ENC2'); }
```

- [ ] **Step 2: `auth.ts`**

```typescript
import bcrypt from 'bcryptjs';
import type { JwtPayload } from './types';

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let r = 0; for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}

export async function hashPassword(pw: string): Promise<string> { return bcrypt.hash(pw, 10); }

export async function verifyPassword(pw: string, hash: string): Promise<boolean> {
  if (!hash) return false;
  if (hash.startsWith('$2')) return bcrypt.compare(pw, hash);
  return timingSafeEqual(hash, pw);
}

function b64e(s: string): string { return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
function b64d(s: string): string {
  s = s.replace(/-/g, '+').replace(/_/g, '/');
  while (s.length % 4) s += '='; return atob(s);
}

async function hmacSha256(key: string, data: string): Promise<ArrayBuffer> {
  const e = new TextEncoder();
  const k = await crypto.subtle.importKey('raw', e.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return crypto.subtle.sign('HMAC', k, e.encode(data));
}

export async function signJwt(p: Omit<JwtPayload, 'iat' | 'exp'>, secret: string, ttl = 7 * 24 * 3600): Promise<string> {
  const now = Math.floor(Date.now() / 1000);
  const fp: JwtPayload = { ...p, iat: now, exp: now + ttl };
  const h = b64e(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  const b = b64e(JSON.stringify(fp));
  const s = b64e(btoa(String.fromCharCode(...new Uint8Array(await hmacSha256(secret, `${h}.${b}`)))));
  return `${h}.${b}.${s}`;
}

export async function verifyJwt(token: string, secret: string): Promise<JwtPayload | null> {
  try {
    const [h, b, s] = token.split('.');
    if (!h || !b || !s) return null;
    const exp = b64e(btoa(String.fromCharCode(...new Uint8Array(await hmacSha256(secret, `${h}.${b}`)))));
    if (!timingSafeEqual(s, exp)) return null;
    const p: JwtPayload = JSON.parse(b64d(b));
    if (p.exp < Math.floor(Date.now() / 1000)) return null;
    return p;
  } catch { return null; }
}

export function generateCsrfToken(): string {
  const b = new Uint8Array(32); crypto.getRandomValues(b);
  return Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
}
```

- [ ] **Step 3: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/crypto.ts cf-worker/src/auth.ts
git commit -m "Add AES-256-GCM crypto + bcrypt JWT auth"
```

---

### Task 6: Aliyun HMAC-SHA1 Signer

**Files:** Create `cf-worker/src/aliyun-sign.ts`

- [ ] **Step 1: Write signer**

```typescript
// Alibaba Cloud OpenAPI RPC HMAC-SHA1 signature (V1)

export interface SignParams {
  AccessKeyId: string;
  AccessKeySecret: string;
  endpoint: string;       // e.g., "ecs.cn-hangzhou.aliyuncs.com"
  action: string;          // e.g., "DescribeInstances"
  version: string;         // e.g., "2014-05-26"
  params?: Record<string, string | number>;
}

function encode(v: string): string {
  return encodeURIComponent(v)
    .replace(/\!/g, '%21').replace(/\'/g, '%27').replace(/\(/g, '%28')
    .replace(/\)/g, '%29').replace(/\*/g, '%2A').replace(/\+/g, '%20');
}

function timestamp(): string {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

export async function signAndCall(p: SignParams): Promise<Response> {
  const nonce = crypto.randomUUID();
  const ts = timestamp();

  const query: Record<string, string> = {
    AccessKeyId: p.AccessKeyId,
    Action: p.action,
    Version: p.version,
    Format: 'JSON',
    SignatureMethod: 'HMAC-SHA1',
    SignatureVersion: '1.0',
    SignatureNonce: nonce,
    Timestamp: ts,
  };

  if (p.params) {
    for (const [k, v] of Object.entries(p.params)) {
      if (v !== undefined && v !== '') query[k] = String(v);
    }
  }

  // Canonical query: sort by key, encode key=value, join with &
  const sortedKeys = Object.keys(query).sort();
  const canonQuery = sortedKeys.map(k => `${encode(k)}=${encode(query[k])}`).join('&');

  const stringToSign = `POST&${encode('/')}&${encode(canonQuery)}`;

  // HMAC-SHA1
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(p.AccessKeySecret + '&'),
    { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(stringToSign));
  const sigB64 = btoa(String.fromCharCode(...new Uint8Array(sig)));
  query.Signature = sigB64;

  const body = new URLSearchParams(
    Object.fromEntries(Object.entries(query).map(([k, v]) => [k, v]))
  ).toString();

  return fetch(`https://${p.endpoint}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'User-Agent': 'AlibabaCloud (Mac OS X; x86_64) PHP/8.1 SDK/1.8',
    },
    body,
  });
}

export async function signedRequest(p: SignParams): Promise<Record<string, unknown>> {
  const res = await signAndCall(p);
  const json = await res.json() as Record<string, unknown>;
  if (!res.ok) {
    const code = (json as any)?.Code ?? 'Unknown';
    const msg = (json as any)?.Message ?? res.statusText;
    throw new Error(`Aliyun ${p.action} error [${code}]: ${msg}`);
  }
  return json;
}
```

- [ ] **Step 2: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/aliyun-sign.ts
git commit -m "Add Alibaba Cloud RPC HMAC-SHA1 signer"
```

---

### Task 7: Aliyun API Wrappers

**Files:** Create `cf-worker/src/aliyun-api.ts`

- [ ] **Step 1: Write API wrappers for all 5 products**

```typescript
import { signedRequest } from './aliyun-sign';
import type { Account, EcsInstance } from './types';

function ak(account: { access_key_id: string; access_key_secret: string }) {
  return { AccessKeyId: account.access_key_id, AccessKeySecret: account.access_key_secret };
}

// === ECS ===
export async function getRegions(key: string, secret: string): Promise<{ regionId: string; localName: string }[]> {
  const r = await signedRequest({ ...ak({ access_key_id: key, access_key_secret: secret }), endpoint: 'ecs.cn-hangzhou.aliyuncs.com', action: 'DescribeRegions', version: '2014-05-26' });
  return ((r.Regions as any)?.Region ?? []).map((reg: any) => ({ regionId: reg.RegionId, localName: reg.LocalName ?? reg.RegionId }));
}

export async function getInstances(account: Account): Promise<EcsInstance[]> {
  const regions = await getRegions(account.access_key_id, account.access_key_secret);
  const target = regions.filter(r => r.regionId === account.region_id);
  const all: EcsInstance[] = [];

  for (const reg of (target.length ? target : regions)) {
    let page = 1;
    let total = 0;
    do {
      const r = await signedRequest({
        ...ak(account), endpoint: `ecs.${reg.regionId}.aliyuncs.com`,
        action: 'DescribeInstances', version: '2014-05-26',
        params: { RegionId: reg.regionId, PageSize: 100, PageNumber: page },
      });
      const items = (r.Instances as any)?.Instance ?? [];
      for (const inst of items) {
        all.push({
          instanceId: inst.InstanceId ?? '', instanceName: inst.InstanceName ?? '',
          status: inst.Status ?? 'Unknown', regionId: reg.regionId, regionName: reg.localName,
          instanceType: inst.InstanceType ?? '', cpu: inst.Cpu ?? 0, memory: inst.Memory ?? 0,
          internetMaxBandwidthOut: parseInt(inst.EipAddress?.Bandwidth ?? inst.InternetMaxBandwidthOut ?? 0),
          osName: inst.OSName ?? '',
          publicIp: inst.PublicIpAddress?.IpAddress?.[0] ?? inst.EipAddress?.IpAddress ?? '',
          eipAllocationId: inst.EipAddress?.AllocationId ?? '',
          eipAddress: inst.EipAddress?.IpAddress ?? '',
          privateIp: inst.VpcAttributes?.PrivateIpAddress?.IpAddress?.[0] ?? '',
          stoppedMode: inst.StoppedMode ?? '', chargeType: inst.InstanceChargeType ?? '',
        });
      }
      total = parseInt(String(r.TotalCount ?? items.length));
      page++;
    } while (total > 0 && (page - 1) * 100 < total);
  }
  return all;
}

export async function getInstanceStatus(account: Account): Promise<string> {
  if (!account.instance_id) return 'Unknown';
  const r = await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: 'DescribeInstanceStatus', version: '2014-05-26',
    params: { RegionId: account.region_id, 'InstanceId.1': account.instance_id },
  });
  const statuses = (r.InstanceStatuses as any)?.InstanceStatus ?? [];
  const match = statuses.find((s: any) => s.InstanceId === account.instance_id);
  return match?.Status ?? 'Unknown';
}

export async function controlInstance(account: Account, action: 'start' | 'stop', shutdownMode = 'KeepCharging'): Promise<void> {
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: action === 'stop' ? 'StopInstance' : 'StartInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, ...(action === 'stop' ? { StoppedMode: shutdownMode } : {}) },
  });
}

export async function deleteInstance(account: Account): Promise<void> {
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: 'DeleteInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, Force: true },
  });
}

// === CDT ===
export async function getTraffic(account: Account): Promise<number> {
  const r = await signedRequest({
    ...ak(account), endpoint: 'cdt.aliyuncs.com',
    action: 'ListCdtInternetTraffic', version: '2021-08-13',
  });
  const details = (r.TrafficDetails as any[]) ?? [];
  const isOverseas = !account.region_id.startsWith('cn-') || account.region_id === 'cn-hongkong';
  let total = 0;
  for (const d of details) {
    const reg = d.BusinessRegionId ?? '';
    const overseas = !reg.startsWith('cn-') || reg === 'cn-hongkong';
    if (overseas === isOverseas) total += (d.Traffic ?? 0);
  }
  return total / (1024 * 1024 * 1024);
}

// === BSS ===
export async function getAccountBalance(account: Account): Promise<{ AvailableAmount: string; Currency: string }> {
  const bssRegion = account.site_type === 'international' ? 'ap-southeast-1' : 'cn-hangzhou';
  const r = await signedRequest({
    ...ak(account), endpoint: `business.${bssRegion}.aliyuncs.com`,
    action: 'QueryAccountBalance', version: '2017-12-14',
  });
  return { AvailableAmount: (r.Data as any)?.AvailableAmount ?? '0', Currency: (r.Data as any)?.Currency ?? 'CNY' };
}

export async function getBillOverview(account: Account, billingCycle: string): Promise<{ TotalCost: number }> {
  const bssRegion = account.site_type === 'international' ? 'ap-southeast-1' : 'cn-hangzhou';
  const r = await signedRequest({
    ...ak(account), endpoint: `business.${bssRegion}.aliyuncs.com`,
    action: 'QueryBillOverview', version: '2017-12-14',
    params: { BillingCycle: billingCycle },
  });
  const items = (r.Data as any)?.Items?.Item ?? [];
  let cost = 0;
  for (const item of items) cost += parseFloat(item.PretaxAmount ?? 0);
  return { TotalCost: Math.round(cost * 100) / 100 };
}

// === CMS (CloudMonitor) ===
export async function getInstanceOutboundBytes(account: Account, startMs: number, endMs: number): Promise<number> {
  let total = 0;
  let cursor = startMs;
  const period = 60;
  const chunkMs = 24 * 60 * 60 * 1000;
  while (cursor < endMs) {
    const chunkEnd = Math.min(cursor + chunkMs, endMs);
    let nextToken: string | undefined;
    do {
      const params: Record<string, string | number> = {
        Namespace: 'acs_ecs_dashboard', MetricName: 'InternetOutRate',
        Period: period, StartTime: cursor, EndTime: chunkEnd,
        Dimensions: JSON.stringify([{ instanceId: account.instance_id }]),
        Length: 1440,
      };
      if (nextToken) params.NextToken = nextToken;
      const r = await signedRequest({
        ...ak(account), endpoint: 'metrics.aliyuncs.com',
        action: 'DescribeMetricList', version: '2019-01-01', params,
      });
      const dps = typeof r.Datapoints === 'string' ? JSON.parse(r.Datapoints as string) : (r.Datapoints ?? []);
      for (const p of (dps as any[])) {
        const ts = parseInt(p.timestamp ?? 0);
        if (ts <= startMs || ts > endMs) continue;
        const rate = Math.max(0, parseFloat(p.Average ?? p.Maximum ?? 0));
        total += (rate * period) / 8;
      }
      nextToken = r.NextToken as string | undefined;
    } while (nextToken);
    cursor = chunkEnd;
  }
  return total;
}
```

- [ ] **Step 2: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/aliyun-api.ts
git commit -m "Add Alibaba Cloud API wrappers (ECS/VPC/CDT/BSS/CMS)"
```

---

### Task 8: D1 Access Layer

**Files:** Create `cf-worker/src/db.ts`

- [ ] **Step 1: Write db helpers**

```typescript
import type { Account } from './types';

export function rowToAccount(row: Record<string, unknown>): Account {
  return {
    id: Number(row.id ?? 0),
    access_key_id: String(row.access_key_id ?? ''),
    access_key_secret: String(row.access_key_secret ?? ''),
    region_id: String(row.region_id ?? ''),
    instance_id: String(row.instance_id ?? ''),
    max_traffic: Number(row.max_traffic ?? 0),
    traffic_used: Number(row.traffic_used ?? 0),
    traffic_billing_month: String(row.traffic_billing_month ?? ''),
    instance_status: String(row.instance_status ?? 'Unknown'),
    health_status: String(row.health_status ?? 'Unknown'),
    stopped_mode: String(row.stopped_mode ?? ''),
    updated_at: Number(row.updated_at ?? 0),
    last_keep_alive_at: Number(row.last_keep_alive_at ?? 0),
    is_deleted: Number(row.is_deleted ?? 0),
    auto_start_blocked: Number(row.auto_start_blocked ?? 0),
    schedule_enabled: Number(row.schedule_enabled ?? 0),
    schedule_start_enabled: Number(row.schedule_start_enabled ?? 0),
    schedule_stop_enabled: Number(row.schedule_stop_enabled ?? 0),
    start_time: String(row.start_time ?? ''),
    stop_time: String(row.stop_time ?? ''),
    schedule_last_start_date: String(row.schedule_last_start_date ?? ''),
    schedule_last_stop_date: String(row.schedule_last_stop_date ?? ''),
    schedule_blocked_by_traffic: Number(row.schedule_blocked_by_traffic ?? 0),
    remark: String(row.remark ?? ''),
    site_type: String(row.site_type ?? 'international'),
    group_key: String(row.group_key ?? ''),
    instance_name: String(row.instance_name ?? ''),
    instance_type: String(row.instance_type ?? ''),
    internet_max_bandwidth_out: Number(row.internet_max_bandwidth_out ?? 0),
    public_ip: String(row.public_ip ?? ''),
    public_ip_mode: String(row.public_ip_mode ?? 'ecs_public_ip'),
    eip_allocation_id: String(row.eip_allocation_id ?? ''),
    eip_address: String(row.eip_address ?? ''),
    eip_managed: Number(row.eip_managed ?? 0),
    private_ip: String(row.private_ip ?? ''),
    cpu: Number(row.cpu ?? 0),
    memory: Number(row.memory ?? 0),
    os_name: String(row.os_name ?? ''),
    traffic_api_status: String(row.traffic_api_status ?? 'ok'),
    traffic_api_message: String(row.traffic_api_message ?? ''),
    protection_suspended: Number(row.protection_suspended ?? 0),
    protection_suspend_reason: String(row.protection_suspend_reason ?? ''),
    protection_suspend_notified_at: Number(row.protection_suspend_notified_at ?? 0),
  };
}

export function getSettings(db: D1Database): Promise<Record<string, string>> {
  return db.prepare('SELECT key, value FROM settings').all<{ key: string; value: string }>()
    .then(r => Object.fromEntries(r.results.map(row => [row.key, row.value])));
}

export function getSetting(db: D1Database, key: string, def = ''): Promise<string> {
  return db.prepare('SELECT value FROM settings WHERE key = ?').bind(key)
    .first<{ value: string }>().then(r => r?.value ?? def);
}

export function saveSetting(db: D1Database, key: string, value: string): Promise<D1Result> {
  return db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').bind(key, value).run();
}

export function getAccounts(db: D1Database): Promise<Account[]> {
  return db.prepare('SELECT * FROM accounts WHERE is_deleted = 0 ORDER BY region_id, remark, id')
    .all<Record<string, unknown>>().then(r => r.results.map(rowToAccount));
}

export function getAccountById(db: D1Database, id: number): Promise<Account | null> {
  return db.prepare('SELECT * FROM accounts WHERE id = ?').bind(id)
    .first<Record<string, unknown>>().then(r => r ? rowToAccount(r) : null);
}

export function addLog(db: D1Database, type: string, message: string): Promise<D1Result> {
  return db.prepare('INSERT INTO logs (type, message, created_at) VALUES (?, ?, ?)')
    .bind(type, message, Math.floor(Date.now() / 1000)).run();
}

export function getLogs(db: D1Database, types: string[], limit = 20): Promise<Record<string, unknown>[]> {
  const ph = types.map(() => '?').join(',');
  return db.prepare(`SELECT * FROM logs WHERE type IN (${ph}) ORDER BY id DESC LIMIT ?`)
    .bind(...types, limit).all<Record<string, unknown>>().then(r => r.results);
}

export async function updateAccountStatus(
  db: D1Database, id: number, traffic: number, status: string, updatedAt: number, meta: Record<string, unknown> = {}
): Promise<void> {
  let sql = 'UPDATE accounts SET traffic_used = ?, traffic_billing_month = ?, instance_status = ?, updated_at = ?';
  const params: unknown[] = [traffic, new Date().toISOString().substring(0, 7), status, updatedAt];
  if (meta.health_status !== undefined) { sql += ', health_status = ?'; params.push(meta.health_status); }
  if (meta.traffic_api_status !== undefined) { sql += ', traffic_api_status = ?'; params.push(meta.traffic_api_status); }
  if (meta.traffic_api_message !== undefined) { sql += ', traffic_api_message = ?'; params.push(meta.traffic_api_message); }
  if (meta.protection_suspended !== undefined) { sql += ', protection_suspended = ?'; params.push(meta.protection_suspended); }
  if (meta.protection_suspend_reason !== undefined) { sql += ', protection_suspend_reason = ?'; params.push(meta.protection_suspend_reason); }
  if (meta.protection_suspend_notified_at !== undefined) { sql += ', protection_suspend_notified_at = ?'; params.push(meta.protection_suspend_notified_at); }
  sql += ' WHERE id = ?'; params.push(id);
  await db.prepare(sql).bind(...params).run();
}
```

- [ ] **Step 2: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/db.ts
git commit -m "Add D1 query helpers (settings, accounts, logs, status)"
```

---

### Task 9: Account Sync

**Files:** Create `cf-worker/src/accounts.ts`

- [ ] **Step 1: Write account group sync**

```typescript
import type { Account, AccountGroup } from './types';
import { decrypt, encrypt } from './crypto';
import { getInstances } from './aliyun-api';
import { rowToAccount, getAccounts } from './db';

export function buildGroupKey(accessKeyId: string, regionId: string): string {
  const hash = new Uint8Array(16);
  crypto.getRandomValues(hash); // placeholder — use SHA-1 for determinism
  // For now, use a simple hash:
  const str = `${accessKeyId}|${regionId}`;
  let h = 0;
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) - h) + str.charCodeAt(i);
    h |= 0;
  }
  return Math.abs(h).toString(16).padStart(16, '0');
}

export async function getGroupsFromSettings(db: D1Database): Promise<AccountGroup[]> {
  const raw = await db.prepare("SELECT value FROM settings WHERE key = 'account_groups'")
    .first<{ value: string }>();
  if (!raw?.value) return [];
  try {
    const groups = JSON.parse(raw.value);
    if (!Array.isArray(groups)) return [];
    return groups.map((g: any) => ({
      groupKey: g.groupKey ?? buildGroupKey(g.AccessKeyId ?? '', g.regionId ?? ''),
      AccessKeyId: g.AccessKeyId ?? '',
      AccessKeySecret: g.AccessKeySecret ?? '',
      regionId: g.regionId ?? '',
      siteType: g.siteType ?? (g.regionId?.startsWith('cn-') && g.regionId !== 'cn-hongkong' ? 'china' : 'international'),
      maxTraffic: parseFloat(g.maxTraffic ?? 200),
      remark: g.remark ?? '',
      scheduleEnabled: !!(g.scheduleEnabled ?? false),
      scheduleStartEnabled: !!(g.scheduleStartEnabled ?? false),
      scheduleStopEnabled: !!(g.scheduleStopEnabled ?? false),
      startTime: g.startTime ?? '',
      stopTime: g.stopTime ?? '',
    }));
  } catch { return []; }
}

export function resolveNetworkMetadata(instance: Record<string, unknown>, existingRow?: Record<string, unknown> | null) {
  const eipId = String(instance.eipAllocationId ?? '');
  const eipAddr = String(instance.eipAddress ?? '');
  const existingMode = String(existingRow?.public_ip_mode ?? '');
  const existingManaged = Number(existingRow?.eip_managed ?? 0);
  const mode = eipId ? 'eip' : 'ecs_public_ip';
  return {
    public_ip_mode: mode,
    eip_allocation_id: eipId || String(existingRow?.eip_allocation_id ?? ''),
    eip_address: eipAddr || (mode === 'eip' ? String(instance.publicIp ?? '') : ''),
    eip_managed: existingManaged,
  };
}

export async function syncAccountGroups(
  db: D1Database, encKey: string, groups: AccountGroup[], onLog?: (type: string, msg: string) => void
): Promise<void> {
  const existing = await getAccounts(db);
  const existingByGroup: Record<string, Account[]> = {};
  for (const a of existing) {
    const gk = a.group_key || buildGroupKey(a.access_key_id, a.region_id);
    (existingByGroup[gk] ??= []).push(a);
  }

  for (const group of groups) {
    let instances;
    try {
      const fakeAccount: Account = {
        ...{} as Account, access_key_id: group.AccessKeyId,
        access_key_secret: group.AccessKeySecret, region_id: group.regionId,
      };
      instances = await getInstances(fakeAccount);
    } catch (e: any) {
      onLog?.('warning', `Instance sync failed [${group.AccessKeyId.substring(0, 7)}***] ${group.regionId}: ${e.message}`);
      // Update group base settings even on failure
      await db.prepare(`UPDATE accounts SET access_key_id=?, access_key_secret=?, region_id=?, max_traffic=?,
        schedule_enabled=?, schedule_start_enabled=?, schedule_stop_enabled=?, start_time=?, stop_time=?,
        site_type=?, group_key=? WHERE group_key=?`)
        .bind(group.AccessKeyId, await encrypt(group.AccessKeySecret, encKey),
          group.regionId, group.maxTraffic,
          group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
          group.startTime, group.stopTime, group.siteType, group.groupKey, group.groupKey).run();
      continue;
    }

    const existingForGroup = existingByGroup[group.groupKey] ?? [];
    const existingById: Record<string, Account> = {};
    for (const a of existingForGroup) existingById[a.instance_id] = a;

    const remoteIds = new Set<string>();
    const encSecret = await encrypt(group.AccessKeySecret, encKey);

    for (const inst of instances) {
      remoteIds.add(inst.instanceId);
      const existingRow = existingById[inst.instanceId] ?? null;
      const net = resolveNetworkMetadata(inst, existingRow as any);

      if (existingRow) {
        await db.prepare(`UPDATE accounts SET access_key_id=?,access_key_secret=?,region_id=?,instance_id=?,max_traffic=?,schedule_enabled=?,schedule_start_enabled=?,schedule_stop_enabled=?,start_time=?,stop_time=?,schedule_blocked_by_traffic=?,instance_status=?,remark=?,site_type=?,group_key=?,instance_name=?,instance_type=?,internet_max_bandwidth_out=?,public_ip=?,public_ip_mode=?,eip_allocation_id=?,eip_address=?,eip_managed=?,private_ip=?,cpu=?,memory=?,os_name=?,stopped_mode=? WHERE id=?`)
          .bind(group.AccessKeyId, encSecret, group.regionId, inst.instanceId, group.maxTraffic,
            group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
            group.startTime, group.stopTime, group.scheduleBlockedByTraffic ? 1 : 0,
            inst.status || (existingRow.instance_status || 'Unknown'),
            group.remark || inst.instanceName || inst.instanceId,
            group.siteType, group.groupKey,
            inst.instanceName || '', inst.instanceType || '',
            inst.internetMaxBandwidthOut, inst.publicIp || '',
            net.public_ip_mode, net.eip_allocation_id,
            net.eip_address, net.eip_managed,
            inst.privateIp || '', inst.cpu, inst.memory,
            inst.osName || '', inst.stoppedMode || '',
            existingRow.id).run();
      } else {
        await db.prepare(`INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,schedule_enabled,schedule_start_enabled,schedule_stop_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_used,traffic_billing_month,instance_status,updated_at,last_keep_alive_at,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,private_ip,cpu,memory,os_name,stopped_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
          .bind(group.AccessKeyId, encSecret, group.regionId, inst.instanceId, group.maxTraffic,
            group.scheduleEnabled ? 1 : 0, group.scheduleStartEnabled ? 1 : 0, group.scheduleStopEnabled ? 1 : 0,
            group.startTime, group.stopTime, group.scheduleBlockedByTraffic ? 1 : 0,
            new Date().toISOString().substring(0, 7), inst.status || 'Unknown',
            group.remark || inst.instanceName || inst.instanceId,
            group.siteType, group.groupKey,
            inst.instanceName || '', inst.instanceType || '',
            inst.internetMaxBandwidthOut, inst.publicIp || '',
            net.public_ip_mode, net.eip_allocation_id,
            net.eip_address, net.eip_managed,
            inst.privateIp || '', inst.cpu, inst.memory,
            inst.osName || '', inst.stoppedMode || '').run();
      }
    }

    // Remove instances no longer in remote
    for (const a of existingForGroup) {
      if (!remoteIds.has(a.instance_id)) {
        await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
      }
    }
  }

  // Remove groups no longer configured
  const configuredKeys = new Set(groups.map(g => g.groupKey));
  for (const a of existing) {
    const gk = a.group_key || buildGroupKey(a.access_key_id, a.region_id);
    if (!configuredKeys.has(gk)) {
      await db.prepare('DELETE FROM accounts WHERE id = ?').bind(a.id).run();
    }
  }
}
```

- [ ] **Step 2: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/accounts.ts
git commit -m "Add account group sync with EIP metadata preservation"
```

---

### Task 10: Monitor + Schedules + DDNS + Instance Actions

**Files:** Create `cf-worker/src/monitor.ts`, `cf-worker/src/schedules.ts`, `cf-worker/src/ddns.ts`, `cf-worker/src/instance-actions.ts`

- [ ] **Step 1: `monitor.ts` — traffic check + circuit breaker**

```typescript
import type { Env, Account, TrafficResult } from './types';
import { getAccounts, getSetting, updateAccountStatus, addLog } from './db';
import { getTraffic, getInstanceStatus, controlInstance } from './aliyun-api';

async function safeGetTraffic(account: Account, env: Env): Promise<TrafficResult> {
  try {
    const v = await getTraffic(account);
    return { success: true, value: v, status: 'ok', message: '' };
  } catch (e: any) {
    const msg = e.message ?? '';
    if (msg.includes('InvalidAccessKeyId') || msg.includes('SignatureDoesNotMatch')) {
      await addLog(env.DB, 'error', `CDT auth error [${account.remark || account.instance_id}]: AK invalid`);
      return { success: false, value: null, status: 'auth_error', message: 'AK invalid' };
    }
    if (msg.includes('timeout') || msg.includes('cURL')) {
      return { success: false, value: null, status: 'timeout', message: 'CDT timeout' };
    }
    return { success: false, value: null, status: 'sync_error', message: 'CDT sync failed' };
  }
}

async function safeGetStatus(account: Account): Promise<string> {
  try { return await getInstanceStatus(account); }
  catch { return 'Unknown'; }
}

export async function runTrafficCheck(env: Env, account: Account): Promise<string[]> {
  const logs: string[] = [];
  const threshold = parseInt(await getSetting(env.DB, 'traffic_threshold', '95'));
  const shutdownMode = await getSetting(env.DB, 'shutdown_mode', 'KeepCharging');
  const thresholdAction = await getSetting(env.DB, 'threshold_action', 'stop_and_notify');
  const label = account.remark || account.instance_id || account.instance_name;

  const traffic = await safeGetTraffic(account, env);
  const status = await safeGetStatus(account);

  const now = Math.floor(Date.now() / 1000);
  const metadata: Record<string, unknown> = {
    traffic_api_status: traffic.status,
    traffic_api_message: traffic.message,
  };

  if (traffic.status === 'auth_error') {
    metadata.protection_suspended = 1;
    metadata.protection_suspend_reason = 'credential_invalid';
  }

  const usedTraffic = traffic.success ? (traffic.value ?? 0) : (account.traffic_used);
  await updateAccountStatus(env.DB, account.id, usedTraffic, status, now, metadata);

  const usagePercent = account.max_traffic > 0 ? (usedTraffic / account.max_traffic * 100) : 0;
  const overThreshold = usagePercent >= threshold;
  const overLimit = account.max_traffic > 0 && usedTraffic >= account.max_traffic;

  if ((overThreshold || overLimit) && thresholdAction === 'stop_and_notify' && !account.protection_suspended) {
    if (status === 'Running') {
      try {
        await controlInstance(account, 'stop', shutdownMode);
        await addLog(env.DB, 'warning', `Traffic circuit break: STOP [${label}] ${usagePercent.toFixed(1)}%`);
        logs.push(`[${label}] Circuit break: STOP`);
        await updateAccountStatus(env.DB, account.id, usedTraffic, 'Stopping', now);
        await env.DB.prepare('UPDATE accounts SET schedule_blocked_by_traffic = 1 WHERE group_key = ?')
          .bind(account.group_key).run();
      } catch (e: any) {
        await addLog(env.DB, 'error', `Circuit break STOP failed [${label}]: ${e.message}`);
      }
    }
  }

  return logs;
}
```

- [ ] **Step 2: `schedules.ts` — schedule + keepalive**

```typescript
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
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_stop_date=? WHERE id=?')
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
        await env.DB.prepare('UPDATE accounts SET instance_status=?, schedule_last_start_date=? WHERE id=?')
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
```

- [ ] **Step 3: `ddns.ts` — Cloudflare DNS sync**

```typescript
import type { Account } from './types';
import { getSetting, addLog } from './db';

export async function syncDdns(db: D1Database, accounts: Account[]): Promise<void> {
  const enabled = await getSetting(db, 'ddns_enabled', '0') === '1';
  if (!enabled) return;
  const domain = await getSetting(db, 'ddns_domain', '');
  const token = await getSetting(db, 'ddns_cf_token', '');
  const zoneId = await getSetting(db, 'ddns_cf_zone_id', '');
  const proxied = await getSetting(db, 'ddns_cf_proxied', '0') === '1';
  if (!domain || !token) return;

  const groupCounts: Record<string, number> = {};
  for (const a of accounts) {
    if (!a.instance_id || !a.public_ip) continue;
    groupCounts[a.group_key] = (groupCounts[a.group_key] ?? 0) + 1;
  }

  for (const a of accounts) {
    if (!a.instance_id) continue;
    const ip = a.public_ip_mode === 'eip' ? (a.eip_address || a.public_ip) : a.public_ip;
    if (!ip) continue;
    try {
      const slug = a.instance_name || a.remark || a.instance_id.replace('i-', '');
      const sanitized = slug.replace(/[^a-zA-Z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').substring(0, 48) || a.group_key;
      const recordName = `${sanitized}.${domain}`.toLowerCase();

      const listRes = await fetch(
        `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records?type=A&name=${encodeURIComponent(recordName)}`,
        { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' } }
      );
      const list = await listRes.json() as any;
      const existing = list.result?.[0];

      if (existing && existing.content === ip) continue; // unchanged

      const method = existing ? 'PUT' : 'POST';
      const path = existing ? `/dns_records/${existing.id}` : '/dns_records';
      const body = JSON.stringify({ type: 'A', name: recordName, content: ip, ttl: 1, proxied });

      const res = await fetch(`https://api.cloudflare.com/client/v4/zones/${zoneId}${path}`, {
        method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body,
      });
      const json = await res.json() as any;
      if (json.success) {
        await addLog(db, 'info', `DDNS ${existing ? 'updated' : 'created'}: ${recordName} -> ${ip}`);
      }
    } catch (e: any) {
      await addLog(db, 'warning', `DDNS sync failed [${a.remark || a.instance_id}]: ${e.message}`);
    }
  }
}
```

- [ ] **Step 4: `instance-actions.ts`**

```typescript
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
```

- [ ] **Step 5: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/monitor.ts cf-worker/src/schedules.ts cf-worker/src/ddns.ts cf-worker/src/instance-actions.ts
git commit -m "Add monitor, schedules, DDNS, and instance action services"
```

---

### Task 11: ECS Create + Notification + Migration + Frontend

**Files:** Create `cf-worker/src/ecs-create.ts`, `cf-worker/src/notification.ts`, `cf-worker/src/migration.ts`, `cf-worker/src/frontend.ts`

- [ ] **Step 1: `ecs-create.ts`**

```typescript
import { signedRequest } from './aliyun-sign';
import type { Account, EcsCreatePreview } from './types';

const TAG_KEY = 'ecs-controller-managed';
const TAG_VAL = 'true';

export async function buildPreview(account: Account, regionId: string, instanceType = 'ecs.e-c4m1.large', osKey = 'debian_12', publicIpMode: 'ecs_public_ip' | 'eip' = 'ecs_public_ip'): Promise<EcsCreatePreview> {
  const a = { access_key_id: account.access_key_id, access_key_secret: account.access_key_secret };

  // Get zones
  const zonesR = await signedRequest({ ...a, endpoint: `ecs.${regionId}.aliyuncs.com`, action: 'DescribeZones', version: '2014-05-26', params: { RegionId: regionId, InstanceType: instanceType } });
  const zones = (zonesR.Zones as any)?.Zone ?? [];
  const zoneId = zones[0]?.ZoneId ?? '';
  if (!zoneId) throw new Error(`No available zone for ${instanceType} in ${regionId}`);

  // Get images (Debian 12)
  const imgR = await signedRequest({ ...a, endpoint: `ecs.${regionId}.aliyuncs.com`, action: 'DescribeImages', version: '2014-05-26', params: { RegionId: regionId, ImageOwnerAlias: 'system', OSType: 'linux', Platform: 'Debian', Status: 'Available', PageSize: 100 } });
  const images = (imgR.Images as any)?.Image ?? [];
  images.sort((a: any, b: any) => String(b.CreationTime ?? '').localeCompare(String(a.CreationTime ?? '')));
  const image = images.find((i: any) => String(i.ImageName ?? '').toLowerCase().includes('debian 12')) ?? images[0];
  if (!image) throw new Error('No Debian 12 image found');

  const diskSize = 20;
  const bandwidth = 200;

  return {
    account: { groupKey: account.group_key, label: account.remark || account.instance_name || '' },
    regionId, zoneId, instanceType,
    instanceName: `launch-${new Date().toISOString().replace(/[-:T]/g, '').substring(0, 12)}`,
    osKey, osLabel: 'Debian 12', imageId: image.ImageId, imageSize: image.Size ?? 0,
    loginUser: 'root', loginPort: 22,
    internetMaxBandwidthOut: bandwidth, publicIpMode,
    systemDisk: { category: 'cloud_essd_entry', size: diskSize, min: 20, max: 2048, unit: 'GiB' },
    network: {
      vpc: { mode: 'auto', name: `ecs-ctrl-vpc-${regionId}`, cidr: '172.31.0.0/16' },
      vswitch: { mode: 'auto', name: `ecs-ctrl-vsw-${zoneId}`, cidr: `172.31.1.0/24` },
      securityGroup: { mode: 'auto', name: `ecs-ctrl-sg-${regionId}`, rules: ['0.0.0.0/0'] },
    },
    pricing: { available: false, currency: 'CNY', message: 'Pay-as-you-go billing. Final charges per Alibaba Cloud bill.' },
    warnings: ['Security group allows all inbound. Restrict in production.'],
  };
}
```

- [ ] **Step 2: `notification.ts`**

```typescript
import { getSetting } from './db';

export async function sendEmail(db: D1Database, subject: string, body: string): Promise<boolean> {
  const enabled = await getSetting(db, 'notify_email_enabled', '1') === '1';
  if (!enabled) return true;
  // MailChannels integration
  try {
    const res = await fetch('https://api.mailchannels.net/tx/v1/send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        personalizations: [{ to: [{ email: await getSetting(db, 'notify_email', '') }] }],
        from: { email: 'ecs-control@mailchannels.net', name: 'ECS Controller' },
        subject,
        content: [{ type: 'text/plain', value: body }],
      }),
    });
    return res.ok;
  } catch { return false; }
}

export async function sendWebhook(db: D1Database, text: string): Promise<boolean> {
  const enabled = await getSetting(db, 'notify_wh_enabled', '0') === '1';
  if (!enabled) return true;
  const url = await getSetting(db, 'notify_wh_url', '');
  const method = await getSetting(db, 'notify_wh_method', 'GET');
  if (!url) return true;
  try {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: method !== 'GET' ? JSON.stringify({ text, time: new Date().toISOString() }) : undefined });
    return res.ok;
  } catch { return false; }
}
```

- [ ] **Step 3: `migration.ts`**

```typescript
import type { MigrationExport } from './types';
import { encrypt } from './crypto';
import { saveSetting } from './db';

export async function importFromDocker(db: D1Database, encKey: string, data: MigrationExport): Promise<void> {
  if (data.version !== 1) throw new Error(`Unsupported export version: ${data.version}`);

  const s = data.settings;
  // Store settings
  const set = (k: string, v: string) => saveSetting(db, k, v);
  await set('admin_password', String(s.admin_password ?? ''));
  await set('traffic_threshold', String(s.traffic_threshold ?? 95));
  await set('shutdown_mode', String(s.shutdown_mode ?? 'KeepCharging'));
  await set('threshold_action', String(s.threshold_action ?? 'stop_and_notify'));
  await set('keep_alive', s.keep_alive ? '1' : '0');
  await set('monthly_auto_start', s.monthly_auto_start ? '1' : '0');
  await set('api_interval', String(s.api_interval ?? 600));
  await set('enable_billing', s.enable_billing ? '1' : '0');

  const n = data.notification;
  await set('notify_email_enabled', n.email_enabled ? '1' : '0');
  await set('notify_email', String(n.email ?? ''));
  await set('notify_host', String(n.host ?? ''));
  await set('notify_port', String(n.port ?? '465'));
  await set('notify_username', String(n.username ?? ''));
  if (n.password) await set('notify_password', String(n.password));
  await set('notify_secure', String(n.secure ?? 'ssl'));
  await set('notify_tg_enabled', n.tg_enabled ? '1' : '0');
  if (n.tg_token) await set('notify_tg_token', String(n.tg_token));
  await set('notify_tg_chat_id', String(n.tg_chat_id ?? ''));
  await set('notify_wh_enabled', n.wh_enabled ? '1' : '0');
  await set('notify_wh_url', String(n.wh_url ?? ''));
  await set('notify_wh_method', String(n.wh_method ?? 'GET'));

  const d = data.ddns;
  await set('ddns_enabled', d.enabled ? '1' : '0');
  await set('ddns_domain', String(d.domain ?? ''));
  await set('ddns_cf_zone_id', String(d.cf_zone_id ?? ''));
  if (d.cf_token) await set('ddns_cf_token', String(d.cf_token));
  await set('ddns_cf_proxied', d.cf_proxied ? '1' : '0');

  // Store account groups
  await set('account_groups', JSON.stringify(data.account_groups));

  // Import accounts (with re-encryption of secrets)
  for (const acc of data.accounts) {
    const secret = acc.access_key_secret ? await encrypt(String(acc.access_key_secret), encKey) : '';
    await db.prepare(`INSERT INTO accounts (access_key_id,access_key_secret,region_id,instance_id,max_traffic,instance_status,remark,site_type,group_key,instance_name,instance_type,internet_max_bandwidth_out,public_ip,public_ip_mode,eip_allocation_id,eip_address,eip_managed,cpu,memory,os_name,schedule_enabled,start_time,stop_time,schedule_blocked_by_traffic,traffic_billing_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind(
        acc.access_key_id, secret, acc.region_id, acc.instance_id || '',
        acc.max_traffic || 0, acc.instance_status || 'Unknown',
        acc.remark || '', acc.site_type || 'international', acc.group_key || '',
        acc.instance_name || '', acc.instance_type || '', acc.internet_max_bandwidth_out || 0,
        acc.public_ip || '', acc.public_ip_mode || 'ecs_public_ip',
        acc.eip_allocation_id || '', acc.eip_address || '', acc.eip_managed ? 1 : 0,
        acc.cpu || 0, acc.memory || 0, acc.os_name || '',
        acc.schedule_enabled ? 1 : 0, acc.start_time || '', acc.stop_time || '',
        acc.schedule_blocked_by_traffic ? 1 : 0, new Date().toISOString().substring(0, 7)
      ).run();
  }
}
```

- [ ] **Step 4: `frontend.ts`**

```typescript
export function renderHtml(csrfToken: string): string {
  return `<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ECS 服务器管家</title>
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,sans-serif;background:#f5f5f7;color:#1d1d1f}
.container{max-width:960px;margin:0 auto;padding:20px}
.card{background:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:24px;margin-bottom:16px}
.btn{padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-size:14px}
.btn-primary{background:#007aff;color:#fff}
.btn-danger{background:#ff3b30;color:#fff}
input,select{padding:8px 12px;border:1px solid #d2d2d7;border-radius:8px;font-size:14px;width:100%}
label{font-size:13px;color:#86868b;display:block;margin-bottom:4px}
.form-group{margin-bottom:12px}
.status-badge{padding:2px 8px;border-radius:12px;font-size:12px}
.status-Running{background:#34c759;color:#fff}
.status-Stopped{background:#ff3b30;color:#fff}
.status-Stopping,.status-Starting{background:#ff9500;color:#fff}
</style>
</head>
<body>
<div id="app">
<div class="container">
  <!-- Login -->
  <div v-if="!loggedIn" class="card">
    <h1>ECS 服务器管家</h1>
    <div v-if="!initialized">
      <div class="form-group"><label>管理员密码</label><input v-model="setupPassword" type="password"></div>
      <div class="form-group"><label>迁移数据 (可选，粘贴 Docker export JSON)</label><textarea v-model="migrationJson" rows="4"></textarea></div>
      <button class="btn btn-primary" @click="doSetup">初始化</button>
      <p v-if="initMsg" style="margin-top:8px;color:#ff3b30">{{ initMsg }}</p>
    </div>
    <div v-else>
      <div class="form-group"><label>密码</label><input v-model="loginPassword" type="password" @keyup.enter="doLogin"></div>
      <button class="btn btn-primary" @click="doLogin">登录</button>
      <p v-if="loginMsg" style="margin-top:8px;color:#ff3b30">{{ loginMsg }}</p>
    </div>
  </div>
  <!-- Loading / placeholder for full SPA -->
  <div v-else>
    <p>已登录，管理面板加载中...</p>
  </div>
</div>
</div>
<script>
const { createApp } = Vue;
createApp({
  data() { return {
    loggedIn: false, initialized: false,
    loginPassword: '', setupPassword: '', migrationJson: '',
    loginMsg: '', initMsg: '',
    token: '', csrfToken: '${csrfToken}'
  };},
  async mounted() {
    try {
      const r = await fetch('/api/check-init', {method:'POST'});
      const d = await r.json();
      this.initialized = d.initialized;
    } catch(e) { this.initMsg = '无法连接到服务器'; }
  },
  methods: {
    async doLogin() {
      try {
        const r = await fetch('/api/login', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:this.loginPassword})});
        const d = await r.json();
        if (d.success) { this.token = d.token; this.csrfToken = d.csrf_token; this.loggedIn = true; }
        else this.loginMsg = d.message || '密码错误';
      } catch(e) { this.loginMsg = '登录请求失败'; }
    },
    async doSetup() {
      try {
        const body = { password: this.setupPassword };
        if (this.migrationJson.trim()) {
          try { body.migration = JSON.parse(this.migrationJson); }
          catch(e) { this.initMsg = 'JSON 格式错误'; return; }
        }
        const r = await fetch('/api/setup', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.success) { this.initialized = true; this.initMsg = ''; }
        else this.initMsg = d.message || '初始化失败';
      } catch(e) { this.initMsg = '初始化请求失败'; }
    }
  }
}).mount('#app');
</script>
</body></html>`;
}
```

- [ ] **Step 5: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/ecs-create.ts cf-worker/src/notification.ts cf-worker/src/migration.ts cf-worker/src/frontend.ts
git commit -m "Add ECS create, notification, migration, and frontend services"
```

---

### Task 12: Worker Entry Point + API Router

**Files:** Create `cf-worker/src/index.ts`

- [ ] **Step 1: Write `index.ts`**

```typescript
import type { Env, JwtPayload } from './types';
import { verifyJwt, signJwt, verifyPassword, hashPassword, generateCsrfToken } from './auth';
import { getAccounts, getSetting, saveSetting, getLogs, addLog, getAccountById } from './db';
import { runTrafficCheck } from './monitor';
import { runScheduleCheck } from './schedules';
import { syncDdns } from './ddns';
import { doControl, doDelete } from './instance-actions';
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
        try {
          await importFromDocker(env.DB, env.ENCRYPTION_KEY, migration);
        } catch (e: any) {
          return jsonResponse({ success: false, message: `迁移数据导入失败: ${e.message}` });
        }
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
      const body = req.method === 'POST' ? await req.json().catch(() => ({})) : {};

      if (path === '/api/status' && req.method === 'POST') {
        const accs = await getAccounts(env.DB);
        // Return simplified status (full implementation would call buildInstanceSnapshot)
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

  // Cron Triggers
  async scheduled(event: ScheduledEvent, env: Env, ctx: ExecutionContext): Promise<void> {
    const cron = event.cron;
    const accounts = await getAccounts(env.DB).then(accs => accs.filter(a => a.instance_id && !a.is_deleted));

    if (cron === '* * * * *') {
      // First minute trigger: traffic check
      // Second minute trigger: schedule check
      // We distinguish by checking the second — first cron in wrangler.toml list gets the first match
      // Actually, both crons match the same pattern. We'll use a simple round-robin approach.
    }

    // Distinguish crons by their position in the triggers list
    const now = new Date();

    // cron-traffic: every minute
    for (const acc of accounts) {
      ctx.waitUntil((async () => {
        await runTrafficCheck(env, acc);
      })());
    }

    // cron-schedule: every minute (different trigger, same pattern)
    // We combine traffic + schedule into a single handler since they share the pattern
    // The second "* * * * *" is for schedule
    // For simplicity, we run both in each minute handler but stagger with a flag
    // Better: use a state key to distinguish

    // Simplified: traffic runs on odd minutes, schedule on even minutes
    const minute = now.getMinutes();
    const second = now.getSeconds();

    for (const acc of accounts) {
      ctx.waitUntil((async () => {
        if (minute % 2 === 0) {
          await runTrafficCheck(env, acc);
        } else {
          await runScheduleCheck(env, acc);
        }
        await addLog(env.DB, 'heartbeat', `[${acc.remark || acc.instance_id}] heartbeat ${acc.instance_status}`);
      })());
    }

    // cron-ddns: every 10 minutes
    if (cron === '*/10 * * * *') {
      await syncDdns(env.DB, accounts);
      await saveSetting(env.DB, 'last_ddns_sync', String(Math.floor(Date.now() / 1000)));
    }

    // cron-cleanup: daily at 3:05 AM
    if (cron === '5 3 * * *') {
      // Clean logs older than 30 days
      const cutoff = Math.floor(Date.now() / 1000) - 30 * 86400;
      await env.DB.prepare('DELETE FROM logs WHERE created_at < ?').bind(cutoff).run();
      // Clean heartbeat logs older than 3 days
      const hbCutoff = Math.floor(Date.now() / 1000) - 3 * 86400;
      await env.DB.prepare("DELETE FROM logs WHERE type = 'heartbeat' AND created_at < ?").bind(hbCutoff).run();
      // Process pending releases
      const pending = await env.DB.prepare('SELECT * FROM accounts WHERE is_deleted = 1').all();
      for (const acc of pending.results as any[]) {
        try {
          await env.DB.prepare("UPDATE accounts SET is_deleted = 2, instance_status = 'Released' WHERE id = ?").bind(acc.id).run();
        } catch {}
      }
    }
  },
};
```

This `scheduled()` handler has a problem: two crons with `* * * * *` can't be distinguished by `event.cron`. The fix is to use different cron patterns:

- cron-traffic: `* * * * *`
- cron-schedule: `* * * * *` (offset by 30 seconds is impossible in CF)

The real fix: **combine traffic + schedule into a single cron handler** that runs both sequentially, since they're independent.

- [ ] **Step 2: Fix wrangler.toml cron triggers**

Update `cf-worker/wrangler.toml` cron section:

```toml
[triggers]
crons = ["* * * * *", "*/10 * * * *", "5 3 * * *"]
```

Three triggers:
1. `* * * * *` — traffic check + schedule + keepalive (combined)
2. `*/10 * * * *` — DDNS sync
3. `5 3 * * *` — cleanup + pending releases

- [ ] **Step 3: Update `scheduled()` handler**

```typescript
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
      await env.DB.prepare("UPDATE accounts SET is_deleted = 2, instance_status = 'Released' WHERE id = ?").bind(acc.id).run();
    }
  }
}
```

- [ ] **Step 4: Commit**

```bash
cd cf-worker && npx tsc --noEmit
git add cf-worker/src/index.ts cf-worker/wrangler.toml
git commit -m "Add Worker entry point with fetch routing and scheduled handlers"
```

---

### Task 13: Deployment & E2E Validation

- [ ] **Step 1: Create D1 database**

```bash
cd cf-worker
npx wrangler d1 create ecs-control-db
# Note the database_id from output, update wrangler.toml
```

- [ ] **Step 2: Create KV namespace**

```bash
npx wrangler kv:namespace create KV
# Note the id from output, update wrangler.toml
```

- [ ] **Step 3: Set secrets**

```bash
# Generate random 256-bit hex key for encryption
ENCRYPTION_KEY=$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")
JWT_SECRET=$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")

npx wrangler secret put ENCRYPTION_KEY
# Paste: $ENCRYPTION_KEY

npx wrangler secret put JWT_SECRET
# Paste: $JWT_SECRET
```

- [ ] **Step 4: Apply D1 schema**

```bash
npx wrangler d1 execute ecs-control-db --file=db/schema.sql
```

- [ ] **Step 5: Deploy**

```bash
npx wrangler deploy
```

- [ ] **Step 6: Verification checklist**

```bash
# 1. Check frontend loads
curl -s https://ecs-control.<worker-subdomain>.workers.dev/ | head -5

# 2. Check init
curl -s -X POST https://ecs-control.<worker-subdomain>.workers.dev/api/check-init | jq .

# 3. Setup (fresh) or login (with exported data)
curl -s -X POST https://ecs-control.<worker-subdomain>.workers.dev/api/setup \
  -H 'Content-Type: application/json' \
  -d '{"password":"test123","migration":<exported-json>}' | jq .
```

- [ ] **Step 7: Commit final config**

```bash
git add cf-worker/wrangler.toml
git commit -m "Finalize wrangler config with D1 and KV IDs"
```

---

## Test Plan

- Docker endpoint: hit `?action=export` → verify JSON structure, all secrets decrypted
- Worker init: `POST /api/setup` without migration → verify account_groups accepts manual config
- Worker import: `POST /api/setup` with migration JSON → verify accounts populated with ENC2 secrets
- Worker auth: `POST /api/login` → verify JWT + CSRF returned, `POST /api/status` with Bearer → verify 200
- Worker CSRF: `POST /api/control` without X-CSRF-Token → verify 403
- Aliyun sign: manual test with real AK → verify ECS DescribeRegions returns valid JSON
- Cron: deploy, wait for cron trigger, verify heartbeat logs in D1
- DDNS: configure CF token + domain → verify A record created/updated
