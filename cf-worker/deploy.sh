#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
WRANGLER="npx wrangler"
D1_NAME="ecs-control-db"

# ── colors ──
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}✓${NC} $1"; }
warn()  { echo -e "${YELLOW}⚠${NC} $1"; }
err()   { echo -e "${RED}✗${NC} $1"; }

check_auth() {
  if ! $WRANGLER whoami &>/dev/null; then
    err "wrangler 未登录，请先运行: npx wrangler login"
    exit 1
  fi
}

# ── init: create D1 database and write id to wrangler.toml ──
cmd_init() {
  check_auth
  if $WRANGLER d1 list 2>/dev/null | grep -q "$D1_NAME"; then
    info "D1 数据库 '$D1_NAME' 已存在"
    # fetch existing uuid and write to toml
    local uuid
    uuid=$($WRANGLER d1 list --format json 2>/dev/null | python3 -c "
import sys, json
dbs = json.load(sys.stdin)
for db in dbs:
    if db.get('name') == '$D1_NAME':
        print(db.get('uuid', ''))
    ")
    if [ -n "$uuid" ]; then
      sed -i '' "s/database_id = \"\"/database_id = \"$uuid\"/" wrangler.toml
      info "database_id 已写入 wrangler.toml"
    fi
  else
    info "创建 D1 数据库 '$D1_NAME' ..."
    local output
    output=$($WRANGLER d1 create "$D1_NAME" 2>&1)
    local uuid
    uuid=$(echo "$output" | grep -o 'database_id: [a-f0-9-]*' | cut -d' ' -f2)
    if [ -z "$uuid" ]; then
      uuid=$(echo "$output" | python3 -c "
import sys, json
for line in sys.stdin:
    try:
        d = json.loads(line)
        if d.get('success'):
            print(d['result']['uuid'])
            break
    except: pass
    " 2>/dev/null || true)
    fi
    if [ -z "$uuid" ]; then
      err "无法从 wrangler 输出中提取 database_id"
      echo "$output"
      exit 1
    fi
    sed -i '' "s/database_id = \"\"/database_id = \"$uuid\"/" wrangler.toml
    info "database_id 已写入 wrangler.toml"
  fi
}

# ── secrets: generate and set ENCRYPTION_KEY + JWT_SECRET ──
cmd_secrets() {
  check_auth
  local secrets_file=".secrets"
  if [ -f "$secrets_file" ]; then
    warn ".secrets 文件已存在，跳过密钥生成"
    warn "如需重新生成，请删除 $secrets_file 后重试"
    return
  fi

  info "生成密钥 ..."
  local key1 key2
  key1=$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")
  key2=$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")
  printf '%s\n%s\n' "$key1" "$key2" > "$secrets_file"
  chmod 600 "$secrets_file"
  info "密钥已写入 $secrets_file"

  # Check if secrets already set on Cloudflare
  local existing
  existing=$($WRANGLER secret list --format json 2>/dev/null | python3 -c "
import sys, json
try:
    secrets = json.load(sys.stdin)
    existing = {s['name'] for s in secrets}
    if 'ENCRYPTION_KEY' in existing and 'JWT_SECRET' in existing:
        print('exists')
except: pass
    " 2>/dev/null || true)

  if [ "$existing" = "exists" ]; then
    warn "Cloudflare 上已有 ENCRYPTION_KEY 和 JWT_SECRET，跳过写入"
    warn "如需覆盖，先运行: npx wrangler secret delete ENCRYPTION_KEY && npx wrangler secret delete JWT_SECRET"
    return
  fi

  info "写入 ENCRYPTION_KEY 到 Cloudflare ..."
  echo "$key1" | $WRANGLER secret put ENCRYPTION_KEY 2>&1 | tail -1
  info "写入 JWT_SECRET 到 Cloudflare ..."
  echo "$key2" | $WRANGLER secret put JWT_SECRET 2>&1 | tail -1
}

# ── deploy: wrangler deploy ──
cmd_deploy() {
  check_auth
  # Ensure database_id is set before deploy
  local db_id
  db_id=$(grep 'database_id' wrangler.toml | grep -o '"[^"]*"' | head -1 | tr -d '"')
  if [ -z "$db_id" ]; then
    warn "database_id 为空，请先运行: $0 init"
    exit 1
  fi
  info "部署 Worker ..."
  $WRANGLER deploy
}

# ── schema: apply D1 schema ──
cmd_schema() {
  check_auth
  info "导入数据库结构到 $D1_NAME ..."
  $WRANGLER d1 execute "$D1_NAME" --file=db/schema.sql
}

# ── all: full pipeline ──
cmd_all() {
  cmd_init
  cmd_secrets
  cmd_deploy
  cmd_schema
}

# ── help ──
cmd_help() {
  cat <<EOF
用法: $0 <command>

命令:
  init     创建 D1 数据库并将 database_id 写入 wrangler.toml
  secrets  生成 ENCRYPTION_KEY / JWT_SECRET 并写入 Cloudflare
  deploy   部署 Worker 代码
  schema   导入数据库结构到 D1
  all      依次执行 init → secrets → deploy → schema
EOF
}

case "${1:-help}" in
  init)    cmd_init ;;
  secrets) cmd_secrets ;;
  deploy)  cmd_deploy ;;
  schema)  cmd_schema ;;
  all)     cmd_all ;;
  help|*)  cmd_help ;;
esac
