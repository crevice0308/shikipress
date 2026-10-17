#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
if [[ -f "$SHIKI_DEV_STATE/http.pid" ]]; then
    server_pid="$(cat "$SHIKI_DEV_STATE/http.pid")"
    if kill -0 "$server_pid" 2>/dev/null && tr '\0' ' ' <"/proc/$server_pid/cmdline" | rg -F -- "127.0.0.1:$SHIKI_DEV_PORT" >/dev/null; then kill "$server_pid"; fi
    rm -f "$SHIKI_DEV_STATE/http.pid"
fi
if mariadb-admin --no-defaults --protocol=tcp --host=127.0.0.1 --port="$SHIKI_DB_PORT" --user=root ping >/dev/null 2>&1; then mariadb-admin --no-defaults --protocol=tcp --host=127.0.0.1 --port="$SHIKI_DB_PORT" --user=root shutdown; fi
printf 'Stopped local development services.\n'
