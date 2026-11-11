#!/usr/bin/env bash
# Runs the theme's own real-WordPress integration suites on a new, uniquely named
# disposable local database. Never point this at an existing site.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
state="${SHIKI_SUITES_STATE:-${RUNNER_TEMP:-/tmp}/shikipress-theme-suites}"
wp_root="$state/wordpress"
reports="$state/reports"
wp_version="${SHIKI_WP_VERSION:-6.6}"
[[ "${CI:-}" == true && -n "${RUNNER_TEMP:-}" && ! -e "$state" ]] || { echo 'Refusing: requires CI=true, RUNNER_TEMP and an unused temporary state directory'; exit 1; }
[[ "${SHIKI_DB_HOST:-127.0.0.1}" == 127.0.0.1 ]] || { echo 'Only local disposable MySQL is allowed'; exit 1; }
[[ "$wp_version" == latest || "$wp_version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { echo 'Unexpected WordPress version selector'; exit 1; }
wp_ci() { wp --path="$wp_root" --allow-root --context=cli "$@"; }
mkdir -p "$wp_root" "$reports"
printf 'Disposable ShikiPress theme-suite database; never production\n' > "$state/.owned-disposable-ci"
db="shikipress_suites_$(date +%s)_${RANDOM}"
[[ "$db" =~ ^shikipress_suites_[0-9]+_[0-9]+$ ]] || exit 1
if [[ "$wp_version" == latest ]]; then wp_ci core download --skip-content; else wp_ci core download --version="$wp_version" --skip-content; fi
wp_ci config create --dbname="$db" --dbhost=127.0.0.1 --dbuser=root --dbpass="${SHIKI_DB_PASS:-root}" --skip-check --extra-php <<'PHP'
define('SHIKI_TEST_ENV', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
PHP
wp_ci db create
# Same loopback origin, title and reading settings as scripts/setup-dev.sh, which the suites were written against.
wp_ci core install --url=http://127.0.0.1:8080 --title='季節のしおり' --admin_user=fixture-admin --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" --admin_email=fixture@example.invalid --skip-email
mkdir -p "$wp_root/wp-content/mu-plugins"
cat > "$wp_root/wp-content/mu-plugins/shiki-ci-suites-safety.php" <<'PHP'
<?php
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || wp_get_environment_type() !== 'local'
    || DB_HOST !== '127.0.0.1' || !preg_match('/^shikipress_suites_[0-9]+_[0-9]+$/D', DB_NAME)
    || !is_file(dirname(rtrim(ABSPATH, '/')) . '/.owned-disposable-ci')
    || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
    throw new RuntimeException('Theme suites require the owned disposable local CI installation.');
}
// The suites supply their own synthetic HTTP responses; every other request stays offline.
add_filter('pre_http_request', static fn($pre) => false !== $pre ? $pre : new WP_Error('shiki_ci_network_blocked', 'External HTTP is disabled in disposable theme suites.'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
PHP
wp_ci post delete 1 2 --force
wp_ci option update blogdescription '四季の暮らしを、心地よく。'
wp_ci option update timezone_string Asia/Tokyo
wp_ci option update blog_public 0
wp_ci rewrite structure '/%postname%/'
# Install the package exactly as a site receives it, never a symlink to the source tree.
python3 "$root/tests/upgrade/verify-package.py" --build "$root/shikipress" "$state/current-theme.zip"
wp_ci theme install "$state/current-theme.zip" --activate
python3 "$root/tests/upgrade/verify-package.py" "$state/current-theme.zip" --installed "$wp_root/wp-content/themes"
wp_ci eval 'if (get_stylesheet() !== "shikipress" || !defined("SHIKI_VERSION")) { throw new RuntimeException("Theme activation failed"); }'
wp_ci eval-file "$root/scripts/fixtures.php" 20
# The suites write their own result files next to the tests; that directory is ignored by Git.
mkdir -p "$root/tests/results"
for suite in core frontend seo ads public-scope automation tools lifecycle media regression; do
  echo "::group::$suite integration suite"
  wp_ci eval-file "$root/tests/$suite-integration.php" | tee "$reports/$suite.txt"
  echo "::endgroup::"
done
# Server-rendered sweep: every template, feed, sitemap, public REST list and theme admin screen over loopback HTTP.
private="$state/private"
mkdir -m 700 "$private"
wp_ci eval-file "$root/tests/theme-suites/render-setup.php" "$private/render.json"
php -d memory_limit=512M -S 127.0.0.1:8080 -t "$wp_root" "$root/tests/plugins/router.php" > "$reports/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT
for attempt in {1..30}; do
  kill -0 "$server_pid" 2>/dev/null || { cat "$reports/server.log"; exit 1; }
  if curl --silent --fail --max-time 5 http://127.0.0.1:8080/ > /dev/null; then break; fi
  sleep 1
done
echo "::group::render sweep"
sweep=0
python3 "$root/tests/theme-suites/check-render.py" "$private/render.json" "$reports" || sweep=$?
echo "::endgroup::"
rm -f "$private/render.json" # The one-hour administrator session of the disposable site is not kept.
[[ "$sweep" == 0 ]] || { echo 'Render sweep failed'; tail -n 40 "$reports/server.log"; exit 1; }
php -v | head -1
wp_ci core version
wp_ci eval 'echo "Theme ".SHIKI_VERSION." / DB ".$GLOBALS["wpdb"]->db_version()."\n";'
if [[ -f "$wp_root/wp-content/debug.log" ]]; then
  cp "$wp_root/wp-content/debug.log" "$reports/debug.log"
  if grep -E 'PHP (Fatal error|Parse error)|(Warning|Deprecated).*[/]shikipress[/]' "$reports/debug.log"; then exit 1; fi
fi
