<?php
/** Installed as a must-use plugin only in the owned disposable CI site. */
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || wp_get_environment_type() !== 'local'
    || DB_HOST !== '127.0.0.1' || !preg_match('/^shikipress_ci_[0-9]+_[0-9]+$/D', DB_NAME)
    || !is_file(dirname(rtrim(ABSPATH, '/')).'/.owned-disposable-ci')
    || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
    throw new RuntimeException('Plugin smoke safety requires the owned local disposable CI site.');
}
define('SHIKI_PLUGIN_SMOKE_SAFETY', true);
// No allowlist in runtime: this also blocks loopback requests and update checks.
add_filter('pre_http_request', static function () {
    $GLOBALS['shiki_smoke_http_blocks'] = 1 + ($GLOBALS['shiki_smoke_http_blocks'] ?? 0);
    return new WP_Error('shiki_smoke_http_blocked', 'HTTP is disabled during disposable plugin smoke tests.');
}, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', static function () {
    $GLOBALS['shiki_smoke_mail_blocks'] = 1 + ($GLOBALS['shiki_smoke_mail_blocks'] ?? 0);
    return true; // Report delivery only to the synthetic caller; no transport executes.
}, PHP_INT_MAX);
add_filter('automatic_updater_disabled', '__return_true');
// Public synthetic markers prove the theme actually calls all three standard hooks.
add_action('wp_head', static function () { echo "\n<!-- shiki-ci-wp-head -->\n"; }, PHP_INT_MAX);
add_action('wp_body_open', static function () { echo "\n<!-- shiki-ci-body-open -->\n"; }, PHP_INT_MAX);
add_action('wp_footer', static function () { echo "\n<!-- shiki-ci-wp-footer -->\n"; }, PHP_INT_MAX);
add_action('send_headers', static function () { header('X-Shiki-CI-Smoke: disposable'); });
