# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ecs-control** is a lightweight web controller for managing Alibaba Cloud (阿里云) ECS instances. Core capabilities: CDT traffic monitoring with auto circuit-breaking, spot instance keepalive, scheduled start/stop, one-click ECS creation, cost analysis, and multi-channel notifications (Telegram, SMTP, Webhook).

## Tech Stack

- **Backend**: Native PHP 8.1+, no framework — do not introduce Laravel/Symfony/etc.
- **Database**: SQLite 3 in WAL mode, stored under `./data/`
- **Frontend**: Vue 3.x (SFC approach) + Vanilla CSS — no build step, no bundler
- **SDK**: Alibaba Cloud SDK for PHP V1 (`AlibabaCloud\Client` namespace)
- **Runtime**: Docker (Nginx + PHP-FPM), default port 43210 configurable via `PORT` env var
- **Autoloading**: Composer classmap (`composer.json`) — after adding new files under `src/`, the Docker build's `composer dump-autoload -o` picks them up

## Development Commands

```bash
# Build and start
docker-compose up -d --build

# Start without rebuild (only if no new files added to src/)
docker-compose up -d

# Run cron monitor manually
docker exec ecs-control php /var/www/html/monitor.php

# View cron output
docker exec ecs-control cat /var/log/cron-monitor.log

# Custom port
PORT=8080 docker-compose up -d --build
```

The `./data` directory is volume-mounted into `/var/www/html/data`. The Dockerfile patches the Alibaba Cloud SDK's `Sign.php` to fix a `microtime()` signature bug (line 13).

## Architecture

### Request Flow

```
index.php?action=xxx  →  HttpRouter
                            ├── public interface (login, setup, check_init — no auth)
                            ├── auth gate (session check)
                            ├── CSRF gate (X-CSRF-Token header on mutating endpoints)
                            └── delegates to AppContainer services
```

There is no `api/` directory. All routing is via `index.php?action=xxx` with a flat if/else chain. Nginx rewrites everything to `index.php`.

### Service Layer (src/)

| Class | Role |
|---|---|
| `AppContainer` | Dependency Injection container, central bootstrap, exposes service getters |
| `HttpRouter` | Entry point, router, session/auth, action dispatch |
| `MonitorService` | Cron monitoring loop: traffic check, circuit breaker, schedules, keepalive, DDNS |
| `InstanceActionService` (563 lines) | Instance CRUD: start/stop/delete/replace-IP/refresh/release queue, IPv6 allocate/release |
| `FrontendResponseBuilder` (445 lines) | DTO building: instance snapshots, config/status for frontend, billing metrics |
| `Account` (179 lines) | Immutable value object wrapping DB row (not yet wired into consumers) |

### Root-Level Services

| Class | Role |
|---|---|
| `AliyunService` (1152 lines) | Alibaba Cloud SDK wrapper: ECS, VPC, EIP, IPv6 gateway, BSS, CDT, CloudMonitor APIs |
| `ConfigManager` (1097 lines) | Settings CRUD, account groups, encryption (sodium), schema migration |
| `Database` (629 lines) | SQLite connection, schema init, migrations, log/stats queries |
| `NotificationService` (486 lines) | Email (PHPMailer), Telegram Bot API, Webhook dispatcher |
| `DdnsService` (404 lines) | Cloudflare DNS API + orchestration (sync, reconcile, group-aware naming) |
| `TelegramControlService` (804 lines) | Telegram bot message handling, action tokens, inline keyboards |

### Cron & Background Processes

- **crond** (Alpine dcron): `/etc/crontabs/root` runs `monitor.php` every minute. Must `cd /var/www/html` first (dcron CWD is `/var/www`). dcron skips nologin users, so use root crontab.
- **telegram_worker.php**: Long-polling loop with `sleep(30)` when idle. Started as background process in `entrypoint.sh`.

### Security & Reliability Mechanisms

- **monitor 互斥锁**: `monitor.php` acquires a non-blocking flock on `data/monitor.lock`; if the previous run is still in progress the round is skipped (prevents duplicate stop/notify/DDNS).
- **Setup 安装令牌**: before initialization, `ConfigManager::getSetupToken()` generates a one-time `SETUP_TOKEN` printed in container logs (`docker logs ... | grep SETUP_TOKEN`); `handleSetup` validates it to prevent hijacking.
- **敏感配置加密**: `ConfigManager::SENSITIVE_SETTING_KEYS` (notify_password, notify_tg_token, notify_tg_proxy_pass, ddns_cf_token, notify_wh_url, monitor_key) are sodium-encrypted at rest; `get()`/`getAllSettings()` auto-decrypt. Encryption key: `data/.secret_encryption.key` file, or `APP_ENC_KEY` env (64-hex) — file key wins when both exist (backward compatible).
- **导出保护**: `action=export` defaults to redacted output; full backup requires `full=1` + admin password re-auth.
- **告警去重**: `MonitorService::notifyTrafficWarningWithCooldown` dedupes traffic alerts per account per billing month (1h cooldown); `AccountRefresher` writes failure cooldown keys (`cdt_failure_at_*`) so failed CDT queries back off 5 min.
- **IPv6**: no IPv6 EIP on Alibaba Cloud — public IPv6 goes through the VPC IPv6 gateway path (`AliyunService::enableVpcIpv6` → `enableVSwitchIpv6` → `ensureIpv6Gateway` → `assignIpv6Address` → `allocateIpv6InternetBandwidth`, PayByTraffic). `InstanceActionService::allocateIpv6/releaseIpv6` orchestrate with an atomic in-flight lock (`ipv6_allocating_{id}` in settings, 10 min expiry) and cloud-side idempotent recovery.

### Frontend

Single `template.html` — Vue 3 loaded via CDN (`static/vue.global.prod.js`). Components compiled in-browser (no build step). CSRF token stored in `csrfToken` ref, sent as `X-CSRF-Token` header on all POST requests.

## Key Constraints

- **No PHP framework**: All routing, middleware, ORM is hand-rolled
- **No frontend build toolchain**: Vue components must work with in-browser compilation. No TypeScript, JSX, or bundler
- **SQLite only**: No MySQL/Redis. WAL mode enabled
- **Aliyun SDK V1**: Do not upgrade to V2 without full audit
- **Single container**: Web + cron + PHP all in one Docker container
- **No new namespaces**: Current classes are global; maintain classmap autoloading pattern
- **CSRF required**: All mutating endpoints need `X-CSRF-Token` header; list is in `$mutatingActions` array in src/HttpRouter.php (plus sync=true branches of get_status/get_all_instances)
