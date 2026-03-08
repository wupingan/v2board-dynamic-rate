#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
ADDON_DIR="${PROJECT_ROOT}/dynamic-rate-addon"
DROP_TABLE="${2:-}"

if [[ ! -d "${ADDON_DIR}" ]]; then
  echo "[dynamic-rate] addon dir not found: ${ADDON_DIR}"
  exit 1
fi

echo "[1/4] stop sidecar"
bash "${ADDON_DIR}/sidecar/scripts/stop_sidecar.sh" || true

echo "[2/4] remove cron"
( crontab -l 2>/dev/null | grep -v "dynamic-rate-addon/worker/apply_dynamic_rate.php" ) | crontab - || true

echo "[3/4] restore node rate to base_rate"
php "${ADDON_DIR}/worker/restore_base_rate.php" "${PROJECT_ROOT}"

echo "[4/4] optional drop table"
if [[ "$DROP_TABLE" == "--drop-table" ]]; then
  ENV_FILE="${PROJECT_ROOT}/.env"
  DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
  DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
  DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
  DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
  DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="${DB_PORT:-3306}"

  mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "DROP TABLE IF EXISTS v2_dynamic_rate_rule;"
  echo "[dynamic-rate] table dropped"
else
  echo "[dynamic-rate] keep table (use --drop-table to drop)"
fi

echo "[dynamic-rate] uninstall completed"
