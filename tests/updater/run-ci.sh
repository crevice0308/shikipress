#!/usr/bin/env bash
# Additive native MySQL updater phase; run after assert-php and before plugin smoke.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
state="${SHIKI_CI_STATE:-${RUNNER_TEMP:-/tmp}/shikipress-retention-ci}"
[[ "${CI:-}" == true && -n "${RUNNER_TEMP:-}" ]] || { echo 'Requires CI=true and RUNNER_TEMP'; exit 1; }
state="$(realpath -e "$state")"
tmp="$(realpath -e "$RUNNER_TEMP")"
[[ "$state" == "$tmp/"* && -f "$state/.owned-disposable-ci" ]] || { echo 'Requires the owned disposable retention site under RUNNER_TEMP'; exit 1; }
grep -Fxq 'Disposable ShikiPress regression database; never production' "$state/.owned-disposable-ci"
wp_root="$state/wordpress"
reports="$state/reports/updater"
export SHIKI_CI_STATE="$state"
wp_ci() { wp --path="$wp_root" --allow-root --context=admin "$@"; }
[[ "$(wp_ci config get DB_HOST --type=constant)" == 127.0.0.1 ]] || { echo 'Only local MySQL is allowed'; exit 1; }
db="$(wp_ci config get DB_NAME --type=constant)"
[[ "$db" =~ ^shikipress_ci_[0-9]+_[0-9]+$ ]] || { echo 'Not a disposable CI database'; exit 1; }
[[ ! -e "$reports" && ! -e "$state/updater-fixtures" ]] || { echo 'Refusing to overwrite updater evidence'; exit 1; }
[[ "$(wp_ci --skip-plugins --skip-themes option get active_plugins --format=json)" == '[]' ]] || { echo 'Expected no pre-existing active plugins'; exit 1; }
[[ ! -e "$wp_root/wp-content/mu-plugins/shiki-ci-plugin-safety.php" ]] || { echo 'Run updater tests before the plugin smoke phase'; exit 1; }
mkdir -p "$reports" "$wp_root/wp-content/mu-plugins"
mu="$wp_root/wp-content/mu-plugins/shiki-ci-updater-safety.php"
[[ ! -e "$mu" ]] || { echo 'Updater safety guard already exists'; exit 1; }
cat > "$mu" <<'PHP'
<?php
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || wp_get_environment_type() !== 'local'
    || DB_HOST !== '127.0.0.1' || !preg_match('/^shikipress_ci_[0-9]+_[0-9]+$/D', DB_NAME)
    || !is_file(dirname(rtrim(ABSPATH, '/')) . '/.owned-disposable-ci')
    || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
    throw new RuntimeException('Updater safety requires the owned disposable local CI installation.');
}
add_filter('pre_http_request', static fn() => new WP_Error('shiki_ci_network_blocked', 'External HTTP disabled in disposable updater tests.'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MAX);
PHP
mu_sha256="$(sha256sum "$mu" | cut -d' ' -f1)"
assert_retained() { wp_ci eval-file "$root/tests/upgrade/retention.php" "updater-$1" | tee "$reports/retention-$1.json"; }
assert_phase() { wp_ci eval-file "$root/tests/updater/integration.php" "$1" | tee "$reports/${2:-$1}.json"; }
# Bootstrap the feature exactly as an existing 1.0.4 installation must: manual ZIP.
# This immutable historical commit contains the genuine release, not a fabricated header edit.
previous=39cefcf2adf81110e6f0a068ee75bec36f48cdf3
git -C "$root" cat-file -e "$previous:shikipress/functions.php"
git -C "$root" archive --format=zip --output="$state/updater-bootstrap-1.0.4.zip" "$previous" shikipress
python3 "$root/tests/upgrade/verify-package.py" "$state/updater-bootstrap-1.0.4.zip" 1.0.4
python3 "$root/tests/upgrade/verify-package.py" --build "$root/shikipress" "$state/updater-real-1.1.0.zip"
wp_ci theme install "$state/updater-bootstrap-1.0.4.zip" --force --activate
wp_ci eval 'if (SHIKI_VERSION !== "1.0.4") { throw new RuntimeException("Historical 1.0.4 bootstrap failed"); }'
wp_ci theme install "$state/updater-real-1.1.0.zip" --force --activate
assert_retained manual-bootstrap-1.0.4-to-1.1.0
assert_phase setup
assert_phase security
assert_retained rejected-packages
assert_phase single
assert_phase verify-single
assert_retained single-synthetic-update
wp_ci theme install "$state/updater-real-1.1.0.zip" --force --activate
assert_phase verify-restored restored-after-single
assert_retained single-restored
assert_phase child-setup
# A fresh bootstrap runs the child's activation lifecycle before checking its parent.
assert_phase bulk
assert_phase verify-bulk
assert_phase child-deactivate
assert_phase child-cleanup
wp_ci theme install "$state/updater-real-1.1.0.zip" --force --activate
assert_phase verify-restored restored-after-bulk
assert_retained bulk-restored
python3 "$root/tests/upgrade/verify-package.py" "$state/updater-real-1.1.0.zip" --installed "$wp_root/wp-content/themes"
if [[ -f "$wp_root/wp-content/debug.log" ]]; then
  cp "$wp_root/wp-content/debug.log" "$reports/debug.log"
  if grep -E 'PHP (Fatal error|Parse error)|(Warning|Deprecated).*[/]shikipress[/]' "$reports/debug.log"; then exit 1; fi
fi
# Leave the whole disposable environment intact; retain runtime safety guards on failure.
# Remove only our guard after success so the subsequent official-plugin download phase works.
[[ -f "$state/.owned-disposable-ci" && "$(sha256sum "$mu" | cut -d' ' -f1)" == "$mu_sha256" ]] || { echo 'Updater safety guard ownership changed; refusing removal'; exit 1; }
rm "$mu"
