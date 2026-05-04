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
  const rawSig = new Uint8Array(await hmacSha256(secret, `${h}.${b}`));
  const s = b64e(String.fromCharCode(...rawSig));
  return `${h}.${b}.${s}`;
}

export async function verifyJwt(token: string, secret: string): Promise<JwtPayload | null> {
  try {
    const [h, b, s] = token.split('.');
    if (!h || !b || !s) return null;
    const rawSig = new Uint8Array(await hmacSha256(secret, `${h}.${b}`));
    const exp = b64e(String.fromCharCode(...rawSig));
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
