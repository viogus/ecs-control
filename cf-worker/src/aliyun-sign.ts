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
  const res = await signAndCall(p);
  let json: Record<string, unknown>;
  try { json = await res.json() as Record<string, unknown>; }
  catch { json = { Code: `HTTP ${res.status}`, Message: await res.text().then(t => t.substring(0, 100)) } as any; }
  if (!res.ok) {
    const code = (json as any)?.Code ?? 'Unknown';
    const msg = (json as any)?.Message ?? res.statusText;
    throw new Error(`Aliyun ${p.action} error [${code}]: ${msg}`);
  }
  return json;
}
