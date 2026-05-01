# Security Audit Report — ecs-control

**Date**: 2026-05-02
**Scope**: Full codebase review of commit at time of audit
**Methodology**: Manual review of all PHP source, Docker configuration, Nginx configuration, and frontend template

---

## Summary

The ecs-control application demonstrates a solid security baseline with several well-implemented protections: CSRF tokens with constant-time comparison, bcrypt password hashing with legacy upgrade path, Sodium-based encryption of Alibaba Cloud AccessKey secrets, login rate limiting, proper session cookie attributes, and Nginx-level blocking of sensitive paths.

However, the single most significant finding is the **absence of TLS/HTTPS**. All traffic — including admin passwords, cloud credentials, SMTP passwords, Telegram bot tokens, and API keys — traverses the network in plaintext. In a deployed scenario behind a reverse proxy this may be mitigated, but the application itself has no awareness or enforcement.

---

## Findings by Severity

### Critical

#### C-1: No TLS/HTTPS — all credentials transmitted in plaintext

**Location**: `index.php:3`, `docker/nginx.conf:1`, `Dockerfile`

The application listens on plain HTTP port 80 inside the container (mapped to 43210 externally). The session cookie `secure` flag is set conditionally based on `$_SERVER['HTTPS']`, which will never be set since Nginx terminates HTTP directly. All sensitive data — admin login password, Alibaba Cloud AK secrets, SMTP credentials, Telegram bot tokens, Cloudflare API tokens — is transmitted unencrypted between the browser and the server.

```php
// index.php:3 — secure flag depends on HTTPS, which is never set
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
```

**Recommendation**: Either terminate TLS at Nginx (add SSL certificate configuration) or deploy behind a TLS-terminating reverse proxy (e.g., Traefik, Caddy, nginx-proxy). Force `session.cookie_secure` to 1 unconditionally once TLS is in place.

---

#### C-2: Notification secrets stored as plaintext in SQLite

**Location**: `ConfigManager.php:586-627`, `NotificationService.php:359-443`

Unlike Alibaba Cloud AccessKey secrets (which are encrypted with Sodium secretbox), SMTP passwords, Telegram bot tokens, Telegram proxy credentials, Cloudflare API tokens, and webhook URLs are stored as plaintext in the `settings` table of the SQLite database. An attacker who gains read access to the database file obtains all notification channel credentials.

**Recommendation**: Apply the same Sodium encryption pattern used for AK secrets to all stored credentials: SMTP password, Telegram bot token (and proxy credentials), and Cloudflare API token. The `********` placeholder pattern used in the config UI is cosmetic only and does not protect the stored value.

---

#### C-3: SQLite database readable if Nginx data/ block is misconfigured

**Location**: `Database.php:11`, `docker/nginx.conf:47-50`

The database file is stored at `./data/data.sqlite`. The data directory is protected from direct web access by an Nginx `location ^~ /data/` block that returns 403. The `.htaccess` file written by `Database::secureEnvironment()` (line 46) is ineffective under Nginx. If the Nginx configuration is removed or altered without understanding this dependency, the database — containing all encrypted AK secrets, notification credentials, and the admin password hash — would be directly downloadable as a static file.

**Recommendation**: Move the database file outside the web root entirely (e.g., `/var/www/data/` with a symlink or absolute path) rather than relying solely on the Nginx location block. Document this as a hard requirement in the Nginx config. Alternatively, add an additional `location ~ \.sqlite$` block as defense-in-depth.

---

### High

#### H-1: IP spoofing via X-Forwarded-For in login rate limiting

**Location**: `AliyunTrafficCheck.php:92-95`

The login rate limiter trusts `HTTP_X_FORWARDED_FOR` to determine the client IP for attack tracking. An attacker can set this header to arbitrary values, bypassing the 5-attempts-per-15-minutes lockout by rotating the spoofed IP. They could also use a victim's real IP to lock them out (denial of service).

```php
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($ips[0]);  // attacker-controlled
}
```

**Recommendation**: Trust `X-Forwarded-For` only when the application is behind a known trusted reverse proxy. In a Docker deployment with direct Nginx-to-PHP-FPM communication, use `REMOTE_ADDR` exclusively. If a reverse proxy is expected, validate that the request came from a trusted upstream IP before trusting the header.

---

#### H-2: SSRF via user-configured webhook URL

**Location**: `NotificationService.php:446-569`, `AliyunService.php` (via Alibaba Cloud SDK)

The webhook notification feature makes cURL requests to a user-configurable URL with no validation. While this requires admin access to configure, a compromised admin session could point the webhook at internal services (e.g., the Docker host's metadata endpoint at `169.254.169.254`, or internal network services accessible from the container). Additionally, the user-configurable `proxy_url` for Telegram (line 404 in NotificationService) could redirect Telegram API calls through an attacker-controlled proxy, enabling man-in-the-middle interception of bot tokens and messages.

**Recommendation**: Maintain a blocklist of disallowed webhook/proxy destinations: private IP ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), link-local addresses (169.254.0.0/16), and loopback (127.0.0.0/8). Validate before making outbound requests. For the proxy URL, require HTTPS and validate the hostname.

---

#### H-3: No Content Security Policy — CDN supply chain risk

**Location**: `template.html:8-13`, `docker/nginx.conf`

The application loads Vue 3, Google Fonts, and a compiled Tailwind CSS file from CDNs without any Content Security Policy header. The Nginx config includes `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` but omits CSP. A compromised CDN could inject malicious JavaScript into the admin panel, gaining full control over ECS instances and cloud credentials.

**Recommendation**: Add a `Content-Security-Policy` header in the Nginx configuration that restricts script sources to trusted origins. Consider self-hosting the Tailwind CSS and using Subresource Integrity (SRI) hashes for CDN-loaded scripts. At minimum:

```
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:;" always;
```

---

### Medium

#### M-1: Email template HTML injection

**Location**: `NotificationService.php:319-357`

The `renderEmailTemplate()` method directly interpolates `$item['value']` into HTML without escaping. While most values are internally generated, some notification types include user-provided or API-derived data (instance names, remarks, IP addresses, error messages from Alibaba Cloud). If an attacker can control any of these fields (e.g., by setting a malicious instance name in Alibaba Cloud), they could inject HTML or execute JavaScript in the admin's email client.

```php
// line 333 — unescaped value:
<td ...>{$item['value']}</td>
```

**Recommendation**: Apply `htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8')` to all dynamic values in the email template.

---

#### M-2: Database schema migration uses dynamic SQL interpolation

**Location**: `Database.php:247-254`

The `ensureColumn()` method constructs SQL by interpolating `$table` and `$column` directly into the query string. While these values are hardcoded constants in practice, the pattern itself is dangerous — if a caller were to ever pass user-controlled input, it would create an SQL injection vector.

```php
// line 252 — table and column interpolated directly:
$this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
```

The same pattern exists in `ConfigManager::updateScheduleExecutionState()` at line 1006:
```php
$stmt = $this->db->prepare("UPDATE accounts SET {$column} = ? WHERE id = ?");
```

**Recommendation**: While these are low-risk given the hardcoded callers, refactor to use a whitelist approach. For `ensureColumn`, validate `$table` and `$column` against known schema values. For the schedule state update, hardcode both columns in the SQL and select via a parameter instead of interpolating the column name.

---

#### M-3: Encryption key file permissions could be stronger

**Location**: `ConfigManager.php:19-47`

The encryption key directory is created with `0755` permissions and the key file with `0600`. While `0600` is appropriate, the directory's `0755` permissions allow other system users to list the directory contents. More critically, on first run, if the `data/` directory already exists with world-readable permissions, the key file inherits the directory's default permissions (the `@chmod` at line 45 suppresses errors and may silently fail).

**Recommendation**: Use `0700` for the data directory and verify the `chmod` call succeeded. Consider using a dedicated secrets directory with stricter permissions.

---

#### M-4: Monitor key visible in admin UI and loggable via URL

**Location**: `AliyunTrafficCheck.php:68-77`, `index.php`

The monitor key, which authorizes web-based cron triggering of the full monitoring loop, is auto-generated and displayed in the admin settings page. This key is used as a Bearer token in `monitor.php`. If the admin UI is viewed over HTTP (see C-1) or the key appears in browser history/referrer headers, it could leak. Additionally, the key is passed as a header value, which is less likely to appear in server logs than a query parameter — this is a positive design choice.

**Recommendation**: Consider adding an option to regenerate the monitor key. Document that this key should be treated as a secret. If feasible, restrict monitor.php web access to internal IPs only in the Nginx config.

---

#### M-5: No file type validation beyond MIME for logo upload

**Location**: `AliyunTrafficCheck.php:217-268`

The logo upload handler validates the file using `finfo(FILEINFO_MIME_TYPE)` which checks the actual file content rather than the extension — this is good. However, uploaded files are stored with predictable names in a web-accessible location (served via `readfile()` in `index.php`). While the MIME check prevents executable PHP from being uploaded, a polyglot file (valid image containing embedded data) would pass validation. This is low risk since the file is only served as an image and never `include`d.

**Recommendation**: Re-encode the uploaded image server-side (e.g., with GD or Imagick) to strip any embedded data. Serve static assets through Nginx directly rather than PHP's `readfile()`.

---

### Positive Findings (Well-Implemented Security Controls)

The following security controls are implemented correctly and should be maintained:

1. **CSRF Protection** (`index.php:20-34`): Proper token generation via `random_bytes(32)`, constant-time comparison with `hash_equals()`, and enforcement on all mutating actions. The token is sent as a custom `X-CSRF-Token` header which avoids it appearing in server logs.

2. **Session Security** (`index.php:2-7`): HttpOnly cookies, SameSite=Lax, strict mode, use-only-cookies — all correctly configured.

3. **Password Hashing** (`ConfigManager.php:297-300`, `AliyunTrafficCheck.php:107-116`): Uses bcrypt via `password_hash()` with automatic upgrade from legacy plaintext comparison.

4. **AK Secret Encryption** (`ConfigManager.php:49-72`): Alibaba Cloud AccessKey secrets are encrypted with Sodium secretbox (authenticated encryption) using a randomly generated key. The `ENC1` prefix allows versioning. Correct use of random nonce per encryption.

5. **Login Rate Limiting** (`AliyunTrafficCheck.php:91-99`, `Database.php:430-447`): Failed login attempts are tracked per IP with a 15-minute window and 5-attempt limit. Successful login clears attempts.

6. **Prepared Statements** (throughout): The codebase consistently uses PDO prepared statements with bound parameters for user-supplied values, preventing SQL injection.

7. **Nginx Security Headers** (`docker/nginx.conf:12-14`): `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin` are set.

8. **Nginx Path Protection** (`docker/nginx.conf:41-61`): Blocks access to `.ht*` files, `.git`, `composer.json/lock`, and the entire `/data/` directory.

9. **Error Display Disabled** (`index.php:10`): `display_errors` is off in production, preventing information leakage through PHP error messages.

10. **Docker Multi-Stage Build** (`Dockerfile`): Separates build-time dependencies from the runtime image, reducing attack surface.

11. **Telegram Access Control** (`TelegramControlService.php:225-239`): Validates chat ID matches the configured target and checks user IDs against an allowed list. Group chat IDs (starting with `-`) are blocked unless users are explicitly whitelisted.

---

## Risk Matrix

| Finding | Likelihood | Impact | Risk Level |
|---------|-----------|--------|------------|
| C-1: No TLS/HTTPS | High | Critical | **Critical** |
| C-2: Plaintext notification secrets | Medium | Critical | **Critical** |
| C-3: DB accessible without Nginx block | Low | Critical | **Critical** |
| H-1: IP spoofing in rate limiter | Medium | High | **High** |
| H-2: Webhook/proxy SSRF | Low | High | **High** |
| H-3: No CSP — CDN risk | Low | High | **High** |
| M-1: Email HTML injection | Low | Medium | **Medium** |
| M-2: Dynamic SQL in migrations | Very Low | Medium | **Low** |
| M-3: Key file permissions | Low | Medium | **Medium** |
| M-4: Monitor key in UI | Low | Low | **Low** |
| M-5: Logo upload hardening | Very Low | Low | **Low** |

---

## Remediation Priority

**Immediate** (address before public exposure):
1. Deploy TLS termination (C-1)
2. Encrypt notification credentials in database (C-2)

**Short-term** (address within next development cycle):
3. Harden IP extraction from X-Forwarded-For (H-1)
4. Add outbound URL validation for webhooks/proxies (H-2)
5. Add Content Security Policy header (H-3)
6. Escape values in email templates (M-1)

**Long-term** (address during maintenance):
7. Move SQLite database outside web root (C-3)
8. Whitelist column names in dynamic SQL (M-2)
9. Tighten data directory permissions (M-3)
10. Add key regeneration capability (M-4)
