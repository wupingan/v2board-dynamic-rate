#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-/www/wwwroot/your-project}"
PHP_BIN="${PHP_BIN:-php}"

cd "${PROJECT_ROOT}/dynamic-rate-addon"
"${PHP_BIN}" worker/apply_dynamic_rate.php "${PROJECT_ROOT}"
