// Alibaba Cloud OpenAPI RPC HMAC-SHA1 signature (V1)

export interface SignParams {
  AccessKeyId: string;
  AccessKeySecret: string;
  endpoint: string;       // e.g., "ecs.cn-hangzhou.aliyuncs.com"
  action: string;          // e.g., "DescribeInstances"
  version: string;         // e.g., "2014-05-26"
  params?: Record<string, string | number>;
}

// Mirrors PHP SDK percentEncode: urlencode → replace('+','%20') → replace('*','%2A') → preg_replace('%7E','~')
function encode(v: string): string {
  let r = encodeURIComponent(v);
  // encodeURIComponent does not encode these; PHP urlencode does
  r = r.replace(/\!/g, '%21').replace(/\'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29');
  // encodeURIComponent does not encode *; PHP urlencode encodes as %2A
  r = r.replace(/\*/g, '%2A');
  // encodeURIComponent encodes space as %20 (same as PHP after +→%20 replacement) ✓
  // PHP keeps ~ unencoded; encodeURIComponent also keeps ~ unencoded ✓
  return r;
}

function timestamp(): string {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

// Module-level key cache: importKey is CPU-expensive, reuse across API calls
const keyCache = new Map<string, CryptoKey>();

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

  // HMAC-SHA1 with cached key
  const enc = new TextEncoder();
  const keyMaterial = p.AccessKeySecret + '&';
  let key = keyCache.get(keyMaterial);
  if (!key) {
    key = await crypto.subtle.importKey('raw', enc.encode(keyMaterial),
      { name: 'HMAC', hash: 'SHA-1' }, false, ['sign']);
    keyCache.set(keyMaterial, key);
  }
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(stringToSign));
  const sigB64 = btoa(String.fromCharCode(...new Uint8Array(sig)));
  query.Signature = sigB64;

  const body = Object.entries(query)
    .map(([k, v]) => `${encode(k)}=${encode(v)}`)
    .join('&');

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
  let lastErr: Error | null = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    if (attempt > 0) await new Promise(r => setTimeout(r, 1000 * attempt));
    try {
      const res = await signAndCall(p);
      const text = await res.text();
      if (res.ok) {
        try { return JSON.parse(text); }
        catch { throw new Error(`Aliyun ${p.action}: invalid JSON response`); }
      }
      // 5xx = transient, retry; 4xx = permanent, throw
      if (res.status >= 500) { lastErr = new Error(`Aliyun ${p.action}: HTTP ${res.status}`); continue; }
      let json: Record<string, unknown> = {};
      try { json = JSON.parse(text); } catch { /* non-JSON error body */ }
      const code = (json as any)?.Code ?? 'Unknown';
      const msg = (json as any)?.Message ?? text.substring(0, 100);
      throw new Error(`Aliyun ${p.action} error [${code}]: ${msg}`);
    } catch (e: any) {
      if (e.message?.includes('Aliyun ')) throw e; // permanent error, don't retry
      lastErr = e;
      // network/fetch errors are transient, retry
    }
  }
  throw lastErr || new Error(`Aliyun ${p.action}: request failed`);
}
