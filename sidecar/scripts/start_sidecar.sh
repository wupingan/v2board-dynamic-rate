#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${BASE_DIR}/.env"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "[dynamic-rate-sidecar] .env not found, copy from .env.example first"
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

HOST="${SIDE_HOST:-127.0.0.1}"
PORT="${SIDE_PORT:-8092}"

cd "${BASE_DIR}/src"
nohup php -S "${HOST}:${PORT}" index.php > /tmp/dynamic-rate-sidecar.log 2>&1 &

echo "[dynamic-rate-sidecar] started at ${HOST}:${PORT}"
