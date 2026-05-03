# Cloudflare Workers 迁移设计

**日期**: 2026-05-03
**状态**: 已确认

## 目标

将 ecs-control 从 Docker/PHP/SQLite 迁移到 Cloudflare Workers/D1/JS，实现 Serverless 零运维部署。

## 架构

```
┌─────────────────────────────────────────┐
│              Cloudflare                 │
│                                         │
│  ┌──────────┐  ┌──────────┐  ┌───────┐ │
│  │  Worker  │  │Cron: flow │  │  D1   │ │
│  │  主控     │  │Cron: ip   │  │ SQLite│ │
│  │  api +   │  │Cron: ddns │  │  兼容  │ │
│  │  frontend│  │Cron: alive│  │       │ │
│  └──────────┘  └──────────┘  └───────┘ │
│                                         │
│  ┌──────────┐                           │
│  │ Worker   │  JWT 密钥、Logo 存 KV      │
│  │ KV/Env   │  ENCRYPTION_KEY 存 Secret│
│  └──────────┘                           │
└─────────────────────────────────────────┘
       │                  │
       ▼                  ▼
  ┌─────────┐    ┌──────────────┐
  │ 阿里云   │    │  Cloudflare  │
  │ ECS/CDT │    │  DNS API     │
  │ BSS/CMS │    │  (DDNS)      │
  └─────────┘    └──────────────┘
```

## Worker 路由

### 公开接口（无需登录）

| 方法 | 路径 | 对应原 action | 说明 |
|------|------|-------------|------|
| POST | /api/check-init | check_init | 检查初始化状态 |
| POST | /api/login | login | 密码登录，返回 JWT + CSRF token |
| POST | /api/setup | setup | 首次初始化（含迁移 JSON 导入） |
| GET | /api/brand-logo | brand_logo | Logo 图片 |

### 受保护接口（需 JWT）

| 方法 | 路径 | 对应原 action |
|------|------|-------------|
| POST | /api/status | get_status |
| POST | /api/config | get_config |
| POST | /api/save-config | save_config |
| POST | /api/logout | logout |
| POST | /api/refresh-account | refresh_account |
| POST | /api/sync-group | sync_account_group |
| POST | /api/restore-schedule | restore_schedule_block |
| POST | /api/fetch-instances | fetch_instances |
| POST | /api/test-account | test_account |
| POST | /api/preview-create | preview_ecs_create |
| POST | /api/disk-options | get_ecs_disk_options |
| POST | /api/create-ecs | create_ecs |
| POST | /api/get-task | get_ecs_create_task |
| POST | /api/control | control_instance |
| POST | /api/delete | delete_instance |
| POST | /api/replace-ip | replace_instance_ip |
| POST | /api/logs | get_logs |
| POST | /api/clear-logs | clear_logs |
| POST | /api/history | get_history |
| POST | /api/all-instances | get_all_instances |
| POST | /api/upload-logo | upload_logo |
| POST | /api/send-test-email | send_test_email |
| POST | /api/send-test-tg | send_test_telegram |
| POST | /api/send-test-wh | send_test_webhook |

### 前端页面

| GET | / | 管理面板 HTML shell + CDN Vue 3 |

## 认证

- 单密码登录，密码被 Bcrypt 后存 D1 settings 表
- 登录成功后签发 JWT，payload 含 `{ role: "admin", csrf_token: "<hex32>" }`，exp 7 天
- JWT 签名密钥存储于 Worker Secret 环境变量，不在 D1 中
- 写操作 API 额外校验 `X-CSRF-Token` header 与 JWT 内嵌 csrf_token 一致
- CSRF token 在整个 JWT 有效期内不变，无需单独刷新

## 数据库 (D1)

Schema 与原 SQLite 保持一致，共 10 张表：

- `settings` — 键值配置
- `accounts` — 账号信息（~30 列）
- `logs` — 系统日志
- `login_attempts` — 登录尝试记录
- `traffic_hourly` / `traffic_daily` — 流量统计
- `billing_cache` — 账单缓存
- `instance_traffic_usage` — 实例粒度流量
- `ecs_create_tasks` — ECS 创建任务记录
- `telegram_action_tokens` — Telegram 操作令牌（初期保留，暂不用）

## 加密

- 加密算法：Web Crypto AES-256-GCM
- 密文格式：`ENC2` + base64(iv + ciphertext)
- 加密密钥：从 Worker Secret `ENCRYPTION_KEY` 读取（256-bit hex）
- 老 PHP sodium 加密格式（ENC1）不兼容，走迁移流程转换

## Cron Triggers

| Trigger | Schedule | 职责 |
|---------|----------|------|
| cron-traffic | `* * * * *` | CDT 流量查询 → 熔断判断 → 停机/告警 |
| cron-schedule | `* * * * *` | 定时开关机 + 月初自动开机 + 保活 |
| cron-ddns | `*/10 * * * *` | DDNS A 记录同步 |
| cron-cleanup | `5 3 * * *` | 日志清理 + VACUUM + 账单缓存刷新 + 实例释放队列处理 |

## 阿里云 API 签名

- 纯手写 HMAC-SHA1 签名实现，无外部依赖
- 覆盖：ECS、VPC、CDT、BSS、CMS 共 5 个产品 ~25 个接口
- 使用 RPC 签名方式，User-Agent → AlibabaCloud SDK V1 格式

## 通知服务

- Email：通过 MailChannels（免费 1000 封/天），或外部 SMTP API 中转
- Webhook：`fetch()` 发送，与原 PHP 逻辑等价
- Telegram：暂不迁移，待后续独立部署

## DDNS

- `fetch()` + Cloudflare DNS REST API
- 子域名生成：中文备注直接 sha1 前 8 位，不再尝试 iconv 音译
- Token 存 D1，读内存直接用

## 前端

- 单文件 HTML + CDN Vue 3
- Worker 返回 HTML 字符串
- 组件保持内联风格
- 密码字段不再用 `********` 占位：留空 = 不改，输入 = 更新
- `brand-logo` 存 KV 或 Worker 返回 base64

## 迁移流程

1. 老系统（PHP）新增 `?action=export` 接口，需管理员 + CSRF 验证
2. `/api/export` 解密所有 AK Secret 后返回 JSON（含 settings、accounts、version=1）
3. 用户在新系统初始化页面粘贴 JSON
4. Worker 收到后：验证 JSON → 用 ENCRYPTION_KEY 重新 AES-256-GCM 加密 AK Secret → 写入 D1
5. 初始化完成，旧系统下线

## 暂不迁移

- Telegram 控制（telegram_worker.php + TelegramControlService）
- 后续可考虑独立 Worker + Webhook 模式，或轻量 VPS 上保留长轮询

## 兼容性注意事项

- 老 PHP sodium 加密数据不直接可读，需通过导出 JSON 桥接
- D1 不支持 `IF NOT EXISTS` 语法，建表语句需独立于数据迁移
- Worker 无文件系统，所有数据通过 D1/KV/Env 存储
- Logo 上传改为 KV 存储
- 文件锁（flock）逻辑在 Worker 中不可用（fopen 不存在），Telegram 并发锁到时再说
- `readfile()` 等价物 → Worker 从 KV 读取后返回 Response body
