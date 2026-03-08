#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
ADDON_DIR="${PROJECT_ROOT}/dynamic-rate-addon"
SIDE_ENV="${ADDON_DIR}/sidecar/.env"

if [[ ! -d "${ADDON_DIR}" ]]; then
  echo "[dynamic-rate] addon dir not found: ${ADDON_DIR}"
  exit 1
fi

rand_hex() {
  local n="$1"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "$n"
  else
    php -r "echo bin2hex(random_bytes(${n}));"
  fi
}

set_kv() {
  local key="$1"
  local val="$2"
  if grep -qE "^${key}=" "$SIDE_ENV"; then
    sed -i "s#^${key}=.*#${key}=${val}#" "$SIDE_ENV"
  else
    echo "${key}=${val}" >> "$SIDE_ENV"
  fi
}

echo "[1/8] init db"
bash "${ADDON_DIR}/scripts/init_db.sh" "$PROJECT_ROOT"

echo "[2/8] alter precision to 2 decimals"
ENV_FILE="${PROJECT_ROOT}/.env"
DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "${ADDON_DIR}/sql/002_alter_dynamic_rate_rule_precision.sql"

echo "[3/8] ensure sidecar env"
if [[ ! -f "$SIDE_ENV" ]]; then
  cp "${ADDON_DIR}/sidecar/.env.example" "$SIDE_ENV"
fi

SIDE_TOKEN="$(grep -E '^SIDE_ACCESS_TOKEN=' "$SIDE_ENV" | head -n1 | cut -d= -f2-)"
ADMIN_ENTRY="$(grep -E '^ADMIN_ENTRY=' "$SIDE_ENV" | head -n1 | cut -d= -f2-)"
ADMIN_USERNAME="$(grep -E '^ADMIN_USERNAME=' "$SIDE_ENV" | head -n1 | cut -d= -f2-)"
ADMIN_PASSWORD="$(grep -E '^ADMIN_PASSWORD=' "$SIDE_ENV" | head -n1 | cut -d= -f2-)"

if [[ -z "$SIDE_TOKEN" || "$SIDE_TOKEN" == "change_me" ]]; then
  SIDE_TOKEN="$(rand_hex 24)"
  set_kv "SIDE_ACCESS_TOKEN" "$SIDE_TOKEN"
fi

if [[ -z "$ADMIN_ENTRY" || "$ADMIN_ENTRY" == "dr-change-me" ]]; then
  ADMIN_ENTRY="dr-$(rand_hex 8)"
  set_kv "ADMIN_ENTRY" "$ADMIN_ENTRY"
fi

if [[ -z "$ADMIN_USERNAME" ]]; then
  ADMIN_USERNAME="admin"
  set_kv "ADMIN_USERNAME" "$ADMIN_USERNAME"
fi

if [[ -z "$ADMIN_PASSWORD" || "$ADMIN_PASSWORD" == "change_me" ]]; then
  ADMIN_PASSWORD="$(rand_hex 12)"
  set_kv "ADMIN_PASSWORD" "$ADMIN_PASSWORD"
fi

set_kv "PROJECT_ROOT" "$PROJECT_ROOT"
set_kv "SIDE_HOST" "127.0.0.1"
set_kv "SIDE_PORT" "8092"

echo "[4/8] normalize historical rule precision"
bash "${ADDON_DIR}/scripts/normalize_precision.sh" "$PROJECT_ROOT"

echo "[5/8] run worker once"
bash "${ADDON_DIR}/scripts/run_once.sh" "$PROJECT_ROOT"

echo "[6/8] install cron"
bash "${ADDON_DIR}/scripts/install_cron.sh" "$PROJECT_ROOT"

echo "[7/8] restart sidecar"
bash "${ADDON_DIR}/sidecar/scripts/stop_sidecar.sh" || true
bash "${ADDON_DIR}/sidecar/scripts/start_sidecar.sh"

echo "[8/8] smoke check"
bash "${ADDON_DIR}/scripts/smoke_check.sh" "http://127.0.0.1:8092" "$SIDE_TOKEN"

echo ""
echo "[dynamic-rate] one-click done"
echo "Admin URL : http://127.0.0.1:8092/${ADMIN_ENTRY}"
echo "Admin User: ${ADMIN_USERNAME}"
echo "Admin Pass: ${ADMIN_PASSWORD}"
echo "Side Token: ${SIDE_TOKEN}"
