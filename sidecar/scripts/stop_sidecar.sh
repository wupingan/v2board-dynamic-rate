#!/usr/bin/env bash
set -euo pipefail

pkill -f "dynamic-rate-addon/sidecar/src/index.php" || true
echo "[dynamic-rate-sidecar] stopped"
