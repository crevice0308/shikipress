#!/usr/bin/env bash
# Native DB/Cron checks on the existing owned disposable retention site only.
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
reports="$state/reports/translation-jobs"; [[ ! -e "$reports" ]] || { echo 'Refusing to overwrite fixture reports'; exit 1; }
mkdir -p "$reports"
for scenario in success failure retry stale-success-title stale-failure-title stale-retry-title stale-success-token stale-retry-token replace-before-claim busy-lock busy-lock-exhausted status-stale-title status-stale-token; do
  wp_ci eval-file "$root/tests/automation-jobs/integration.php" "$scenario" | tee "$reports/$scenario.json"
done
export SHIKI_CI_STATE="$state"
wp_ci eval-file "$root/tests/upgrade/retention.php" translation-jobs | tee "$reports/retention.json"
