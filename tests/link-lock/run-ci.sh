#!/usr/bin/env bash
# Actual concurrent lock ownership on the existing owned disposable MySQL site.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
[[ "${CI:-}" == true && -n "${RUNNER_TEMP:-}" ]] || { echo 'Requires CI and RUNNER_TEMP'; exit 1; }
state="$(realpath -e "${SHIKI_CI_STATE:-$RUNNER_TEMP/shikipress-retention-ci}")"
tmp="$(realpath -e "$RUNNER_TEMP")"
[[ "$state" == "$tmp/"* && -f "$state/.owned-disposable-ci" ]] || { echo 'Requires owned disposable retention site'; exit 1; }
grep -Fxq 'Disposable ShikiPress regression database; never production' "$state/.owned-disposable-ci"
wp_ci() { wp --path="$state/wordpress" --allow-root --context=cli "$@"; }
[[ "$(wp_ci config get DB_HOST --type=constant)" == 127.0.0.1 ]] || exit 1
db="$(wp_ci config get DB_NAME --type=constant)"; [[ "$db" =~ ^shikipress_ci_[0-9]+_[0-9]+$ ]] || exit 1
reports="$state/reports/link-lock"
[[ ! -e "$reports" ]] || { echo 'Refusing to overwrite fixture reports'; exit 1; }
export SHIKI_CI_STATE="$state" SHIKI_LINK_LOCK_FIXTURE=1
python3 "$root/tests/link-lock/run-integration.py" --wp-path "$state/wordpress" --reports "$reports"
wp_ci eval-file "$root/tests/upgrade/retention.php" link-lock | tee "$reports/retention.json"
