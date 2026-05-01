# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ecs-control** is a lightweight web controller for managing Alibaba Cloud (阿里云) ECS instances. Core capabilities: CDT traffic monitoring with auto circuit-breaking, spot instance keepalive, scheduled start/stop, one-click ECS creation, cost analysis, and multi-channel notifications (Telegram, SMTP, Webhook).

## Tech Stack

- **Backend**: Native PHP 8.1+, no framework — keep it that way; do not introduce Laravel/Symfony/etc.
- **Database**: SQLite 3 in WAL mode, stored under `./data/`
- **Frontend**: Vue 3.x (SFC approach) + Vanilla CSS — no build step, no bundler
- **SDK**: Alibaba Cloud SDK for PHP V1
- **Runtime**: Docker (Nginx + PHP-FPM), port 80 inside container, mapped to 43210 externally

## Development Commands

```bash
# Build and start (first time or after code changes)
docker-compose up -d --build

# Start without rebuild
docker-compose up -d

# View logs
docker-compose logs -f ecs-controller

# Access container shell
docker exec -it ecs-control bash

# Run cron monitor manually inside container
docker exec ecs-control php /var/www/html/monitor.php
```

The `./data` directory is volume-mounted into `/var/www/html/data` — SQLite DB, encryption key, and brand logo live there. The Dockerfile applies a one-line patch to the Alibaba Cloud SDK (`Sign.php`) to fix a `microtime()` signature bug.

## Architecture

```
/
├── data/               # SQLite DB + runtime state (gitignored, volume-mounted)
├── api/                # PHP API endpoints (REST-style, no framework)
├── docker/
│   └── entrypoint.sh   # Container init; sets up cron for scheduled tasks
├── Dockerfile
└── template.html       # Main SPA shell (Vue 3 loaded via CDN)
```

Backend API endpoints are plain PHP files — each file handles a specific resource (instances, accounts, settings, logs, etc.). No routing layer; Nginx rewrites map URL paths to files.

Frontend is a single-page Vue 3 app served from `template.html`. Components are defined as Vue SFCs but compiled at runtime via the Vue 3 ESM browser build — no `npm`, no `node_modules`, no build output.

Scheduled/cron tasks (traffic checks, spot keepalive, DDNS sync) are managed by cron inside the container, configured in `entrypoint.sh`.

## Key Constraints

- **No PHP framework**: All routing, middleware, and ORM is hand-rolled. Keep new backend code consistent with existing patterns.
- **No frontend build toolchain**: Vue components must be compatible with in-browser compilation. Do not introduce TypeScript, JSX, or any syntax requiring a build step.
- **SQLite only**: Do not add MySQL/Redis/etc. WAL mode is already enabled; preserve it.
- **Aliyun SDK V1**: The PHP SDK uses `AlibabaCloud\Client` namespace. Do not upgrade to V2 without full audit.
- **Single container**: All services (web, cron, PHP) run in one Docker container.
