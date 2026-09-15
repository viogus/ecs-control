import { getSetting, getSettingPlain } from './db';

export async function sendEmail(db: D1Database, subject: string, body: string): Promise<boolean> {
  const enabled = await getSetting(db, 'notify_email_enabled', '1') === '1';
  if (!enabled) return true;
  const toEmail = await getSetting(db, 'notify_email', '');
  if (!toEmail) return true;
  const fromEmail = toEmail;
  try {
    const res = await fetch('https://api.mailchannels.net/tx/v1/send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        personalizations: [{ to: [{ email: toEmail }] }],
        from: { email: fromEmail, name: 'ECS Control' },
        subject,
        content: [{ type: 'text/plain', value: body }],
      }),
    });
    return res.ok;
  } catch { return false; }
}

/** notify_wh_url 为加密存储，需 encKey 解密后才能请求。 */
export async function sendWebhook(db: D1Database, text: string, encKey: string): Promise<boolean> {
  const enabled = await getSetting(db, 'notify_wh_enabled', '0') === '1';
  if (!enabled) return true;
  const url = await getSettingPlain(db, 'notify_wh_url', encKey);
  const method = await getSetting(db, 'notify_wh_method', 'GET');
  if (!url) return true;
  try {
    const res = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: method !== 'GET' ? JSON.stringify({ text, time: new Date().toISOString() }) : undefined });
    return res.ok;
  } catch { return false; }
}
