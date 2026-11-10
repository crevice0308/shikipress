<?php
/**
 * Isolated synthetic WordPress stubs; no HTTP, database, or site access.
 * Other code may fire save_post with fewer or loosely typed arguments. The theme's
 * handlers must tolerate that, and revision saves must not purge public caches.
 */
error_reporting(E_ALL);
set_error_handler(static function ($number, $message, $file, $line) { throw new ErrorException($message, 0, $number, $file, $line); });
const ABSPATH = '/';
define('SHIKI_VERSION', '0.0.0'); define('SHIKI_DIR', '/synthetic'); define('SHIKI_URL', '/synthetic');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600);
class WP_Post { public $ID = 0; public $post_type = 'post'; public $post_status = 'draft'; public $post_title = 'Synthetic'; public $post_name = ''; public $post_parent = 0; public $post_password = ''; public $post_content = ''; }
class WP_Error {}
$hooks = $posts = $meta = array(); $options = array('shikipress_cache_epoch' => 1);
function add_action($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$hook][] = array($callback, $accepted); }
function add_filter($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$hook][] = array($callback, $accepted); }
function add_shortcode(...$unused) {}
function __($text, $domain = '') { return $text; }
function apply_filters($tag, $value, ...$unused) { return $value; }
// Same argument handling as WP_Hook: callbacks get at most what the caller supplied.
function do_action($hook, ...$args) {
    if (!$args) { $args = array(''); }
    foreach ($GLOBALS['hooks'][$hook] ?? array() as list($callback, $accepted)) {
        call_user_func_array($callback, $accepted >= count($args) ? $args : array_slice($args, 0, $accepted));
    }
}
function absint($value) { return abs((int) $value); }
// Like core, an empty ID resolves to the global post; the theme must not rely on that.
function get_post($post = null) {
    if ($post instanceof WP_Post) { return $post; }
    if (empty($post) && isset($GLOBALS['post'])) { return $GLOBALS['post']; }
    return $GLOBALS['posts'][(int) $post] ?? null;
}
function wp_is_post_revision($id) { $post = $GLOBALS['posts'][(int) $id] ?? null; return $post && 'revision' === $post->post_type ? (int) $post->post_parent : false; }
function wp_is_post_autosave($id) { return false; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = (string) $value; return true; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$unused) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_transient($key) { return false; }
function set_transient(...$unused) { return true; }
function delete_transient($key) { return true; }
function wp_cache_set_comments_last_changed() {}
function sanitize_text_field($value) { return is_scalar($value) ? (string) $value : ''; }
function wp_unslash($value) { return $value; }
function wp_verify_nonce(...$unused) { return false; }
function current_user_can(...$unused) { return true; }
$theme = defined('SHIKI_THEME_DIR') ? SHIKI_THEME_DIR : __DIR__ . '/../../shikipress';
foreach (array('core', 'automation', 'seo') as $module) { require $theme . '/inc/' . $module . '.php'; }
$passed = 0;
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL ' . $label); } ++$GLOBALS['passed']; }
$draft = new WP_Post(); $draft->ID = 10; $posts[10] = $draft;
$revision = new WP_Post(); $revision->ID = 11; $revision->post_type = 'revision'; $revision->post_parent = 10; $posts[11] = $revision;
$GLOBALS['post'] = $draft;
check(shiki_saved_post(10) === $draft && shiki_saved_post('10', null) === $draft && shiki_saved_post('x', $draft) === $draft, 'resolves the saved post by ID or object');
check(null === shiki_saved_post(0) && null === shiki_saved_post('') && null === shiki_saved_post(array(10)) && null === shiki_saved_post(999) && null === shiki_saved_post(), 'unknown input never resolves to the global post');
$epoch = static function () { return (int) $GLOBALS['options']['shikipress_cache_epoch']; };
$loose = array(array(10), array('10', null), array(10, $draft), array(0), array(), array(array(10)), array(999), array(10, $draft, true), array(10, 'not-a-post', true), array(10, null, null));
foreach ($loose as $index => $args) {
    $before = $epoch();
    do_action('save_post', ...$args);
    check($epoch() === $before + 1, 'loose save_post call ' . $index . ' is handled and invalidates once');
}
check(!isset($meta[10]['_shiki_new_theme_draft']), 'a missing or non-boolean $update never marks a new theme draft');
do_action('save_post', 10, $draft, false);
check('1' === ($meta[10]['_shiki_new_theme_draft'] ?? ''), "core's explicit new-post save still marks the theme draft");
$before = $epoch();
do_action('save_post', 11, $revision, true);
do_action('save_post', 11);
check($epoch() === $before, 'revision and autosave saves keep the public caches');
do_action('transition_post_status', 'publish', 'draft', null);
check(!isset($meta[10]['_shiki_ever_published']), 'a status transition without a post is ignored');
do_action('transition_post_status', 'publish', 'draft', $draft);
check('1' === ($meta[10]['_shiki_ever_published'] ?? ''), 'publication history is still recorded');
echo json_encode(array('status' => 'PASS', 'checks' => $passed, 'php' => PHP_VERSION)), "\n";
