#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
CRON_LINE="* * * * * cd ${PROJECT_ROOT}/dynamic-rate-addon && ${PHP_BIN} worker/apply_dynamic_rate.php ${PROJECT_ROOT} >> /tmp/xiao-dynamic-rate.log 2>&1"

( crontab -l 2>/dev/null | grep -v "dynamic-rate-addon/worker/apply_dynamic_rate.php"; echo "$CRON_LINE" ) | crontab -

echo "[dynamic-rate] cron installed"
