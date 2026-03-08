#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
ENV_FILE="${PROJECT_ROOT}/.env"
SQL_FILE="${PROJECT_ROOT}/dynamic-rate-addon/sql/001_create_dynamic_rate_rule.sql"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "[dynamic-rate] .env not found: ${ENV_FILE}"
  exit 1
fi

DB_HOST="$(grep -E '^DB_HOST=' "${ENV_FILE}" | head -n1 | cut -d= -f2-)"
DB_PORT="$(grep -E '^DB_PORT=' "${ENV_FILE}" | head -n1 | cut -d= -f2-)"
DB_DATABASE="$(grep -E '^DB_DATABASE=' "${ENV_FILE}" | head -n1 | cut -d= -f2-)"
DB_USERNAME="$(grep -E '^DB_USERNAME=' "${ENV_FILE}" | head -n1 | cut -d= -f2-)"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "${ENV_FILE}" | head -n1 | cut -d= -f2-)"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

if [[ -z "${DB_DATABASE}" || -z "${DB_USERNAME}" ]]; then
  echo "[dynamic-rate] DB config missing"
  exit 1
fi

mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" < "${SQL_FILE}"

echo "[dynamic-rate] table initialized"
