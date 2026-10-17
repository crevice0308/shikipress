#!/usr/bin/env bash
# Only creates a new, uniquely named disposable local WordPress database.
set -euo pipefail
phase="${1:-full}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
state="${SHIKI_CI_STATE:-${RUNNER_TEMP:-/tmp}/shikipress-retention-ci}"
wp_root="$state/wordpress"
reports="$state/reports"
wp_version="${SHIKI_WP_VERSION:-6.6}"
wp_target="${SHIKI_WP_TARGET:-7.1.3}"
export SHIKI_CI_STATE="$state"
wp_ci() { wp --path="$wp_root" --allow-root "$@"; }
assert_retained() { wp_ci eval-file "$root/tests/upgrade/retention.php" "$1" | tee "$reports/$1.json"; }
if [[ "$phase" == assert-php ]]; then
  [[ -f "$state/.owned-disposable-ci" ]] || { echo 'Missing disposable site marker';exit 1; }
  assert_retained "php-upgrade-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  if [[ -f "$wp_root/wp-content/debug.log" ]] && grep -E 'PHP (Fatal error|Parse error)|(Warning|Deprecated).*[/]shikipress[/]' "$wp_root/wp-content/debug.log"; then exit 1; fi
  exit 0
fi
[[ "$phase" == full ]] || exit 1
[[ "${CI:-}" == true && ! -e "$state" ]] || { echo 'Refusing: requires CI=true and an unused temporary state directory';exit 1; }
[[ "${SHIKI_DB_HOST:-127.0.0.1}" == 127.0.0.1 ]] || { echo 'Only local disposable MySQL is allowed';exit 1; }
mkdir -p "$wp_root" "$reports"
printf 'Disposable ShikiPress regression database; never production\n' > "$state/.owned-disposable-ci"
db="shikipress_ci_$(date +%s)_${RANDOM}"
[[ "$db" =~ ^shikipress_ci_[0-9]+_[0-9]+$ ]] || exit 1
wp_ci core download --version="$wp_version" --skip-content
wp_ci config create --dbname="$db" --dbhost=127.0.0.1 --dbuser=root --dbpass="${SHIKI_DB_PASS:-root}" --skip-check --extra-php <<'PHP'
define('SHIKI_TEST_ENV', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', 'api.wordpress.org,downloads.wordpress.org,wordpress.org');
PHP
wp_ci db create
wp_ci core install --url=http://127.0.0.1:9450 --title='Disposable update test' --admin_user=fixture-admin --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" --admin_email=fixture@example.invalid --skip-email
# Inert local fallback avoids downloading or introducing a second external theme.
mkdir -p "$wp_root/wp-content/themes/ci-fallback"
printf '/*\nTheme Name: CI fallback\nVersion: 1.0\n*/\n' > "$wp_root/wp-content/themes/ci-fallback/style.css"
printf '<?php echo "Disposable fallback";\n' > "$wp_root/wp-content/themes/ci-fallback/index.php"
previous="$(cat "$root/tests/upgrade/baseline-commit.txt")"
[[ "$previous" =~ ^[0-9a-f]{40}$ ]] || { echo 'A verified baseline Git commit is required';exit 1; }
git -C "$root" cat-file -e "$previous:shikipress/functions.php"
git -C "$root" archive --format=zip --output="$state/previous-theme.zip" "$previous" shikipress
python3 "$root/tests/upgrade/verify-package.py" "$state/previous-theme.zip" 1.0.2
python3 "$root/tests/upgrade/verify-package.py" --build "$root/shikipress" "$state/current-theme.zip"
wp_ci theme install "$state/previous-theme.zip" --activate
# Force the deferred switch hook through a fresh bootstrap before snapshotting.
wp_ci eval 'if(get_stylesheet()!=="shikipress"){throw new RuntimeException("Theme activation failed");}'
assert_retained seed
wp_ci theme activate ci-fallback
wp_ci eval 'echo get_stylesheet();'
wp_ci theme activate shikipress
wp_ci eval 'echo get_stylesheet();'
assert_retained theme-reactivation
# Use the actual WordPress upgrader force-replacement path; never silently substitute extraction.
wp_ci theme install "$state/previous-theme.zip" --force --activate
assert_retained same-version-reinstall
wp_ci theme install "$state/current-theme.zip" --force --activate
assert_retained actual-theme-upgrade
python3 "$root/tests/upgrade/verify-package.py" "$state/current-theme.zip" --installed "$wp_root/wp-content/themes"
wp_ci core update --version="$wp_target" --force
wp_ci core update-db
assert_retained wordpress-core-upgrade
wp_ci core verify-checksums
php -v | head -1
wp_ci core version
wp_ci eval 'echo "Theme ".SHIKI_VERSION." / DB ".$GLOBALS["wpdb"]->db_version()."\n";'
# A marker and report path are retained for the next PHP version on this exact DB/filesystem.
if [[ -f "$wp_root/wp-content/debug.log" ]]; then
  cat "$wp_root/wp-content/debug.log"
  if grep -E 'PHP (Fatal error|Parse error)|(Warning|Deprecated).*[/]shikipress[/]' "$wp_root/wp-content/debug.log"; then exit 1; fi
fi
