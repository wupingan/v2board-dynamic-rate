#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8092}"
TOKEN="${2:-change_me}"

echo "[1/4] health"
curl -fsS "${BASE_URL}/health" | cat

echo "\n[2/4] upsert"
curl -fsS -X POST "${BASE_URL}/api/rules/upsert" \
  -H "Content-Type: application/json" \
  -H "X-Access-Token: ${TOKEN}" \
  -d '{"server_type":"vmess","server_id":1,"enabled":0,"base_rate":1,"timezone":"Asia/Shanghai","rules_json":[]}' | cat

echo "\n[3/4] get"
curl -fsS "${BASE_URL}/api/rules?server_type=vmess&server_id=1" \
  -H "X-Access-Token: ${TOKEN}" | cat

echo "\n[4/4] list"
curl -fsS "${BASE_URL}/api/rules/list" \
  -H "X-Access-Token: ${TOKEN}" | head -c 400 | cat

echo "\n[dynamic-rate] smoke done"
