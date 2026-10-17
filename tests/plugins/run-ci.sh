#!/usr/bin/env bash
# Additive smoke phase. Run only after tests/upgrade/run-ci.sh assert-php.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
state="${SHIKI_CI_STATE:-${RUNNER_TEMP:-/tmp}/shikipress-retention-ci}"
[[ "${CI:-}" == true && -n "${RUNNER_TEMP:-}" ]] || { echo 'Requires CI=true and RUNNER_TEMP'; exit 1; }
state="$(realpath -e "$state")"
tmp="$(realpath -e "$RUNNER_TEMP")"
[[ "$state" == "$tmp/"* && -f "$state/.owned-disposable-ci" ]] || { echo 'Requires an owned disposable site under RUNNER_TEMP'; exit 1; }
grep -Fxq 'Disposable ShikiPress regression database; never production' "$state/.owned-disposable-ci"
wp_root="$state/wordpress"
reports="$state/reports/plugins"
export SHIKI_CI_STATE="$state"
export SHIKI_PLUGIN_SMOKE_MANIFEST="$root/tests/plugins/plugins.tsv"
wp_ci() { wp --path="$wp_root" --allow-root "$@"; }
[[ "$(wp_ci config get DB_HOST --type=constant)" == 127.0.0.1 ]] || { echo 'Only local MySQL is allowed'; exit 1; }
db="$(wp_ci config get DB_NAME --type=constant)"
[[ "$db" =~ ^shikipress_ci_[0-9]+_[0-9]+$ ]] || { echo 'Not a disposable CI database'; exit 1; }
[[ ! -e "$reports" ]] || { echo 'Refusing to overwrite plugin evidence; start a fresh retention job'; exit 1; }
mkdir -p "$reports"
# None of the downloaded third-party plugins runs during installation/checksum retrieval.
[[ "$(wp_ci --skip-plugins --skip-themes option get active_plugins --format=json)" == '[]' ]] || { echo 'Expected no pre-existing active plugins'; exit 1; }
mu="$wp_root/wp-content/mu-plugins/shiki-ci-plugin-safety.php"
[[ ! -e "$mu" ]] || { echo 'Safety MU already exists; use a fresh retention job'; exit 1; }
while IFS=$'\t' read -r slug version file; do
  wp_ci --skip-plugins --skip-themes plugin install "$slug" --version="$version"
  wp_ci --skip-plugins --skip-themes plugin verify-checksums "$slug" --version="$version" --strict | tee "$reports/checksums-$slug.txt"
done < "$root/tests/plugins/plugins.tsv"
mkdir -p "$(dirname "$mu")"
cp "$root/tests/plugins/safety.php" "$mu"
# Keep runtime guards installed, including after failure. The entire site is disposable.
assert_phase() { wp_ci eval-file "$root/tests/plugins/smoke.php" "$1" "${2:-all}" | tee "$reports/$1-${2:-all}.json"; }
assert_retained() { wp_ci eval-file "$root/tests/upgrade/retention.php" "plugins-$1" | tee "$reports/retention-$1.json"; }
assert_phase seed
assert_retained baseline
php -d memory_limit=512M -S 127.0.0.1:9450 -t "$wp_root" "$root/tests/plugins/router.php" > "$reports/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT
for attempt in {1..30}; do
  kill -0 "$server_pid" 2>/dev/null || { cat "$reports/server.log"; exit 1; }
  if curl --silent --fail --max-time 2 http://127.0.0.1:9450/ > /dev/null; then break; fi
  sleep 1
done
render() { python3 "$root/tests/plugins/check-http.py" "$reports" "$1"; }
render baseline
while IFS=$'\t' read -r slug version file; do
  wp_ci plugin activate "$slug"
  assert_phase seed-plugin "$slug"
  assert_phase active "$slug" # Fresh bootstrap after activation/fixture seeding.
  assert_retained "$slug-active"
  render "$slug"
  wp_ci plugin deactivate "$slug"
  assert_phase inactive "$slug"
  assert_retained "$slug-inactive"
  wp_ci plugin activate "$slug"
  assert_phase reactivated "$slug"
  wp_ci plugin deactivate "$slug"
done < "$root/tests/plugins/plugins.tsv"
# One plugin per responsibility; no competing SEO/cache/form/security/backup plugin.
mapfile -t slugs < <(cut -f1 "$root/tests/plugins/plugins.tsv")
wp_ci plugin activate "${slugs[@]}"
assert_phase combined
assert_retained combined
render combined
wp_ci plugin deactivate "${slugs[@]}"
assert_phase final
assert_retained final
render final
if [[ -f "$wp_root/wp-content/debug.log" ]]; then
  cp "$wp_root/wp-content/debug.log" "$reports/debug.log"
  if grep -E 'PHP (Fatal error|Parse error)|(Warning|Deprecated).*[/]shikipress[/]' "$reports/debug.log"; then exit 1; fi
fi
