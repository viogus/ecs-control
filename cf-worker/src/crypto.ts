function hexToBytes(hex: string): Uint8Array {
  const b = new Uint8Array(hex.length / 2);
  for (let i = 0; i < b.length; i++) b[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
  return b;
}

async function getKey(hex: string): Promise<CryptoKey> {
  if (hex.length !== 64) throw new Error('ENCRYPTION_KEY must be 64 hex chars (32 bytes)');
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
  } catch { throw new Error('Decryption failed: wrong key or corrupted data'); }
}

export function isEncrypted(v: string): boolean { return v.length >= 8 && v.startsWith('ENC2'); }
