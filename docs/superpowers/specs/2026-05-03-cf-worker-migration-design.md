# ecs-control 双轨部署设计：Docker + Cloudflare Workers

**日期**: 2026-05-03
**状态**: 已确认

## 目标

ecs-control 同时支持两种部署方式，用户按需选择：

- **方案一：Docker**（当前已有）— 自部署容器，PHP/SQLite 全内置，零外部依赖
- **方案二：Cloudflare Workers**（新增）— Serverless 部署，Worker + D1 替代容器，零运维

两者功能对齐，用户可按自身偏好和基础设施决定用哪一种。Docker 版本继续维护，不废弃。

## 两套方案对比

| 维度 | Docker | CF Workers |
|------|--------|------------|
| 部署 | `docker-compose up -d` | `wrangler deploy` |
| 运维 | 需要一台机器（ECS/VPS） | 零运维 |
| 数据库 | 本地 SQLite 文件 | D1（Cloudflare 托管 SQLite） |
| 定时任务 | dcron 每分钟跑 | Cron Triggers 拆 4 个 |
| 文件存储 | 本地 ./data 目录 | KV（Logo）+ D1（数据） |
| 会话 | PHP Session | JWT 无状态 |
| 加密 | libsodium XSalsa20 | Web Crypto AES-256-GCM |
| 通知 | PHPMailer SMTP | MailChannels / fetch |
| 费用 | 服务器成本 | Worker 免费额度（10万请求/天 + D1 5GB） |
| Telegram | 支持 | 暂不支持（后续独立补） |

## 方案一：Docker（保留）

当前系统结构不变。唯一新增：`?action=export` 导出端点，为想切换到 CF Workers 的用户提供数据导出桥梁。

## 方案二：Cloudflare Workers 架构

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

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /api/check-init | 检查初始化状态 |
| POST | /api/login | 密码登录，返回 JWT + CSRF token |
| POST | /api/setup | 首次初始化（支持粘贴 Docker 导出的 JSON） |
| GET | /api/brand-logo | Logo 图片（从 KV 读取） |

### 受保护接口（需 JWT + CSRF）

Worker API 路径与 Docker `?action=xxx` 一一对应，功能等价：

| 方法 | 路径 | 功能 |
|------|------|------|
| POST | /api/status | 实例状态快照 |
| POST | /api/config | 配置读取 |
| POST | /api/save-config | 配置保存 + 同步 |
| POST | /api/refresh-account | 刷新单个账号 |
| POST | /api/sync-group | 同步账号组 |
| POST | /api/restore-schedule | 恢复定时开关机 |
| POST | /api/fetch-instances | 获取实例列表 |
| POST | /api/test-account | 测试账号凭证 |
| POST | /api/preview-create | ECS 创建预检 |
| POST | /api/disk-options | 获取磁盘选项 |
| POST | /api/create-ecs | 创建 ECS |
| POST | /api/get-task | 查询创建任务 |
| POST | /api/control | 开关机控制 |
| POST | /api/delete | 删除实例 |
| POST | /api/replace-ip | 更换 EIP |
| POST | /api/logs | 系统日志 |
| POST | /api/clear-logs | 清空日志 |
| POST | /api/history | 流量历史 |
| POST | /api/all-instances | 全部实例 |
| POST | /api/upload-logo | 上传 Logo |
| POST | /api/send-test-email | 测试邮件 |
| POST | /api/send-test-tg | 测试 Telegram |
| POST | /api/send-test-wh | 测试 Webhook |

### 前端

| GET | / | 管理面板 HTML shell + CDN Vue 3 |

## 认证

- 单密码登录，密码被 Bcrypt 后存 D1 settings 表
- 登录成功后签发 JWT，payload 含 `{ role: "admin", csrf_token: "<hex32>" }`，exp 7 天
- JWT 签名密钥存储于 Worker Secret 环境变量，不在 D1 中
- 写操作 API 额外校验 `X-CSRF-Token` header 与 JWT 内嵌 csrf_token 一致
- CSRF token 在整个 JWT 有效期内不变，无需单独刷新

## 数据库 (D1)

Schema 与 Docker 版 SQLite 对齐，共约 11 张表：

- `settings` — 键值配置
- `accounts` — 账号信息（~30 列）
- `logs` — 系统日志
- `login_attempts` — 登录尝试记录
- `traffic_hourly` / `traffic_daily` — 流量统计
- `billing_cache` — 账单缓存
- `instance_traffic_usage` — 实例粒度流量
- `ecs_create_tasks` — ECS 创建任务记录
- `telegram_action_tokens` / `telegram_bot_state` — 暂预留

**两套方案的数据完全独立**，各自有各自的 SQLite/D1 文件，不共享。

## 加密

CF Workers 使用与 Docker 不同的加密体系：

| | Docker | CF Workers |
|------|--------|------------|
| 算法 | libsodium XSalsa20-Poly1305 | Web Crypto AES-256-GCM |
| 前缀 | ENC1 | ENC2 |
| 密钥来源 | `data/.secret_encryption.key` 文件 | Worker Secret 环境变量 |

两个体系的密文互不兼容。同一时刻用户只跑一套方案，不存在跨系统解密的需求。从 Docker 切换到 CF Workers 时通过导出 JSON 桥接（明文解密 → 重新加密）。

## Cron Triggers（仅 CF Workers）

4 个独立 trigger：

| Trigger | Schedule | 职责 |
|---------|----------|------|
| cron-traffic | `* * * * *` | CDT 流量查询 → 熔断判断 → 停机/告警 |
| cron-schedule | `* * * * *` | 定时开关机 + 月初自动开机 + 保活 |
| cron-ddns | `*/10 * * * *` | DDNS A 记录同步 |
| cron-cleanup | `5 3 * * *` | 日志清理 + VACUUM + 账单缓存刷新 + 实例释放队列 |

## 阿里云 API 签名

- 纯手写 HMAC-SHA1 签名实现，无外部依赖
- 覆盖：ECS、VPC、CDT、BSS、CMS 共 5 个产品 ~25 个接口
- 使用 RPC 签名方式，User-Agent → AlibabaCloud SDK V1 格式

## 通知服务

| 通道 | Docker | CF Workers |
|------|--------|------------|
| Email | PHPMailer SMTP | MailChannels（免费 1000 封/天） |
| Webhook | curl | `fetch()` |
| Telegram | Bot API 长轮询 | 暂不支持 |

## DDNS

- `fetch()` + Cloudflare DNS REST API
- 子域名生成：中文备注直接 sha1 前 8 位，不再尝试 iconv 音译
- Token 存 D1，读内存直接用

## 前端（CF Workers 版）

- 单文件 HTML + CDN Vue 3，Worker 直接返回字符串
- 组件保持内联风格
- 密码字段：留空 = 不改，输入 = 更新（不再用 `********` 占位）
- `brand-logo` 存 KV，Worker 返回 base64

## 从 Docker 切换到 CF Workers 的流程

两套方案独立部署，新用户可直接用 CF Workers 开始。已在使用 Docker 的用户如需切换：

1. 访问 Docker 部署的 `?action=export`（需管理员登录 + CSRF）
2. PHP 解密所有 AK Secret → 输出明文 JSON（含 settings、accounts、version=1）
3. 复制 JSON 内容
4. 打开 CF Workers 部署的控制台→ 粘贴 JSON 到初始化表单
5. Worker 用 ENCRYPTION_KEY 加密 → 写入 D1
6. 初始化完成

切换后两套系统独立运行，不互相通信。旧 Docker 实例可下线。

## 暂不实现（CF Workers 版）

- Telegram 控制（telegram_worker.php + TelegramControlService）
- `readfile()` 输出 Logo → 改为 KV 存储 base64
- `flock()` 文件锁 → 等 Telegram 接入时用 D1 或 DO 实现并发控制

## 兼容性注意事项

- D1 不支持 `IF NOT EXISTS` 建表语法，迁移脚本需独立管理
- Worker 无文件系统，所有持久化走 D1 或 KV
- Worker 单次 CPU 时间有限制（免费 10ms，付费 30s），需注意阿里云 API 超时
- Worker 体积限制 1MB，避免引入重量级 npm 依赖
- 两套方案的 API 路径格式不同（`?action=xxx` vs `/api/xxx`），前端需根据实际部署调整请求路径
