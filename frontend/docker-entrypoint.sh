#!/bin/sh
set -eu

ENV_FILE="/usr/share/nginx/html/env.js"
TMP_FILE="${ENV_FILE}.tmp"

ENV_JSON=$(jq -n \
  --arg api "${VITE_API_URL:-}" \
  --arg fingerprint "${VITE_FINGERPRINT_ENCRYPTION_KEY:-}" \
  --arg metrics "${VITE_METRICS_URL:-}" \
  '{
    VITE_API_URL: $api,
    VITE_FINGERPRINT_ENCRYPTION_KEY: $fingerprint,
    VITE_METRICS_URL: $metrics
  }'
)

printf 'window.__ENV__ = %s;\n' "$ENV_JSON" > "$TMP_FILE"
mv "$TMP_FILE" "$ENV_FILE"

exec "$@"
