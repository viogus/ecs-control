# CF Worker 自动化部署脚本设计

> **For agentic workers:** Implementation plan follows via writing-plans skill.

**Goal:** 一条命令完成 CF Worker 的初始化与部署，最小化手动操作。

**Scope:** Cloudflare Workers 的部署流程自动化，不涉及 PHP Docker 版本。

---

## 当前手动步骤

目前部署需 5 步手动操作：
1. `npx wrangler d1 create ecs-control-db` → 复制 database_id 到 wrangler.toml
2. `npx wrangler secret put ENCRYPTION_KEY` → 手动粘贴密钥
3. `npx wrangler secret put JWT_SECRET` → 手动粘贴密钥
4. `npx wrangler d1 execute ecs-control-db --file=db/schema.sql`
5. `npx wrangler deploy`

## 自动化方案

一个 `cf-worker/deploy.sh` shell 脚本，支持幂等运行（可反复执行）。

### 文件变更

| 文件 | 操作 | 说明 |
|------|------|------|
| `cf-worker/deploy.sh` | 新建 | 自动化部署脚本 |
| `cf-worker/.secrets` | 新建（运行时） | 本地持久化生成的密钥，不提交到 git |
| `cf-worker/.gitignore` | 追加 `.secrets` | 防止密钥泄露 |
| `cf-worker/wrangler.toml` | 移除 `[[kv_namespaces]]` | 代码中未使用 KV，减少配置步骤 |
| `cf-worker/db/schema.sql` | `CREATE TABLE` → `CREATE TABLE IF NOT EXISTS` | 幂等执行 schema |

### 脚本流程

```
deploy.sh  [init|deploy|all]
```

**init 阶段**（首次运行 `deploy.sh all` 时触发，也可单独 `deploy.sh init`）：
1. `wrangler d1 list` 检查 `ecs-control-db` 是否存在
2. 不存在则 `wrangler d1 create` → 解析 JSON 提取 `database_id`
3. `sed` 写入 `wrangler.toml` 的 `database_id = "xxx"`
4. `wrangler d1 execute ecs-control-db --file=db/schema.sql`

**密钥阶段**（首次运行 `deploy.sh all` 时触发，也可单独 `deploy.sh secrets`）：
1. 检查 `.secrets` 文件是否存在
2. 不存在则用 `node -e` 生成两个 64 字符 hex 密钥写入 `.secrets`
3. `wrangler secret put ENCRYPTION_KEY < .secrets`（提取第一行）
4. `wrangler secret put JWT_SECRET < .secrets`（提取第二行）

**部署阶段**（每次运行都会执行，也可单独 `deploy.sh deploy`）：
1. `wrangler deploy`

### 幂等性保证

- schema.sql 使用 `IF NOT EXISTS`，重复执行不报错
- secrets 检查 `.secrets` 文件存在性，已存在则跳过生成和写入 Cloudflare
- init 检查 D1 数据库是否已创建，已存在则跳过
- 所有子命令可独立运行：`deploy.sh init`、`deploy.sh secrets`、`deploy.sh deploy`

### 边界情况

- **D1 已创建但 wrangler.toml 未更新**：脚本从 `wrangler d1 list` 重新查询已存在 DB 的 uuid，写入 wrangler.toml
- **环境已有 secrets 但本地 `.secrets` 丢失**：检查 `.secrets` 不存在时重新生成。但新密钥与 Cloudflare 上现有的不同。方案：提示用户选择，默认跳过（不覆盖已有 secrets）
- **wrangler 未认证**：脚本检查 `wrangler whoami`，未登录时提示用户先 `npx wrangler login`
