#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
ADDON_DIR="${PROJECT_ROOT}/dynamic-rate-addon"

bash "${ADDON_DIR}/scripts/init_db.sh" "${PROJECT_ROOT}"

if [[ ! -f "${ADDON_DIR}/sidecar/.env" ]]; then
  cp "${ADDON_DIR}/sidecar/.env.example" "${ADDON_DIR}/sidecar/.env"
  echo "[dynamic-rate] sidecar/.env created, please set SIDE_ACCESS_TOKEN before production"
fi

bash "${ADDON_DIR}/scripts/run_once.sh" "${PROJECT_ROOT}"
bash "${ADDON_DIR}/scripts/install_cron.sh" "${PROJECT_ROOT}"
bash "${ADDON_DIR}/sidecar/scripts/start_sidecar.sh"

echo "[dynamic-rate] setup completed"
echo "- admin: http://127.0.0.1:8092/<ADMIN_ENTRY>"
