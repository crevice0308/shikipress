#!/usr/bin/env bash
# Source only. Private state defaults outside this source tree and must never ship.
set -euo pipefail
SHIKI_PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHIKI_DEV_STATE="${SHIKI_DEV_STATE:-${XDG_STATE_HOME:-$HOME/.local/state}/shikipress-dev}"
SHIKI_DEV_PORT="${SHIKI_DEV_PORT:-8080}"
SHIKI_WP_ROOT="$SHIKI_DEV_STATE/wordpress"
SHIKI_DB_PORT="${SHIKI_DB_PORT:-3307}"
SHIKI_DB_NAME="${SHIKI_DB_NAME:-shikipress_dev}"
SHIKI_DEV_URL="http://127.0.0.1:$SHIKI_DEV_PORT"
mkdir -p "$SHIKI_DEV_STATE"
chmod 700 "$SHIKI_DEV_STATE"
shiki_wp() { php "$SHIKI_DEV_STATE/wp-cli.phar" --allow-root --path="$SHIKI_WP_ROOT" "$@"; }
shiki_db() { mariadb --no-defaults --protocol=tcp --host=127.0.0.1 --port="$SHIKI_DB_PORT" --user=root "$@"; }
shiki_start_database() {
    if mariadb-admin --no-defaults --protocol=tcp --host=127.0.0.1 --port="$SHIKI_DB_PORT" --user=root ping >/dev/null 2>&1; then return; fi
    if [[ ! -d "$SHIKI_DEV_STATE/db/mysql" ]]; then
        mariadb-install-db --no-defaults --datadir="$SHIKI_DEV_STATE/db" --auth-root-authentication-method=normal --skip-test-db --user="$(id -un)" >"$SHIKI_DEV_STATE/db-init.log" 2>&1
    fi
    nohup mariadbd --no-defaults --datadir="$SHIKI_DEV_STATE/db" --socket= --bind-address=127.0.0.1 --port="$SHIKI_DB_PORT" --pid-file="$SHIKI_DEV_STATE/mariadb.pid" --user="$(id -un)" --log-error="$SHIKI_DEV_STATE/mariadb.log" >"$SHIKI_DEV_STATE/mariadb-console.log" 2>&1 &
    for _ in $(seq 1 100); do
        if mariadb-admin --no-defaults --protocol=tcp --host=127.0.0.1 --port="$SHIKI_DB_PORT" --user=root ping >/dev/null 2>&1; then return; fi
        sleep 0.1
    done
    printf 'MariaDB failed; inspect %s/mariadb.log\n' "$SHIKI_DEV_STATE" >&2; return 1
}
