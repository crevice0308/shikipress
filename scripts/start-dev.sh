#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
[[ -f "$SHIKI_WP_ROOT/wp-config.php" ]] || { printf 'Run setup-dev.sh first.\n' >&2; exit 1; }
shiki_start_database
if [[ -f "$SHIKI_DEV_STATE/http.pid" ]] && kill -0 "$(cat "$SHIKI_DEV_STATE/http.pid")" 2>/dev/null; then
    printf 'Already running: %s\n' "$SHIKI_DEV_URL"; exit 0
fi
# The router handles WordPress pretty URLs. Bind only to loopback.
nohup php -S "127.0.0.1:$SHIKI_DEV_PORT" -t "$SHIKI_WP_ROOT" "$SHIKI_PROJECT_ROOT/scripts/router.php" >"$SHIKI_DEV_STATE/http.log" 2>&1 &
printf '%s\n' "$!" >"$SHIKI_DEV_STATE/http.pid"
for _ in $(seq 1 100); do
    if curl -fsS -o /dev/null "$SHIKI_DEV_URL/"; then printf 'Running: %s\n' "$SHIKI_DEV_URL"; exit 0; fi
    sleep 0.1
done
printf 'HTTP server failed; inspect %s/http.log\n' "$SHIKI_DEV_STATE" >&2; exit 1
