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
function is_object_in_taxonomy($type, $taxonomy) { return in_array($type, array('post', 'page'), true) && in_array($taxonomy, array('category', 'post_tag'), true); }
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
$published = new WP_Post(); $published->ID = 12; $published->post_status = 'publish'; $posts[12] = $published;
$attachment = new WP_Post(); $attachment->ID = 13; $attachment->post_type = 'attachment'; $attachment->post_status = 'inherit'; $posts[13] = $attachment;
$embed = new WP_Post(); $embed->ID = 14; $embed->post_type = 'oembed_cache'; $embed->post_status = 'publish'; $posts[14] = $embed;
// Loose calls never raise an error. A draft changes nothing public, so its saves (the block editor
// autosaves the author's own draft itself about once a minute) keep the caches.
foreach (array(array(10), array('10', null), array(10, $draft), array(10, $draft, true), array(10, 'not-a-post', true), array(10, null, null)) as $index => $args) {
    $before = $epoch();
    do_action('save_post', ...$args);
    check($epoch() === $before, 'loose save_post call ' . $index . ' for a draft is handled and keeps the public caches');
}
// The same calls for a published post invalidate once each.
foreach (array(array(12), array('12', null), array(12, $published), array(12, $published, true), array(12, 'not-a-post', true), array(12, null, null)) as $index => $args) {
    $before = $epoch();
    do_action('save_post', ...$args);
    check($epoch() === $before + 1, 'loose save_post call ' . $index . ' for a published post invalidates once');
}
// A caller that cannot name its post keeps the full invalidation.
foreach (array(array(0), array(), array(array(10)), array(999)) as $index => $args) {
    $before = $epoch();
    do_action('save_post', ...$args);
    check($epoch() === $before + 1, 'unresolvable save_post call ' . $index . ' is handled and invalidates once');
}
check(!isset($meta[10]['_shiki_new_theme_draft']), 'a missing or non-boolean $update never marks a new theme draft');
do_action('save_post', 10, $draft, false);
check('1' === ($meta[10]['_shiki_new_theme_draft'] ?? ''), "core's explicit new-post save still marks the theme draft");
$before = $epoch();
do_action('save_post', 11, $revision, true);
do_action('save_post', 11);
do_action('save_post', 14, $embed, false);
check($epoch() === $before, 'revision, autosave and oEmbed cache saves keep the public caches');
// Entering or leaving publication changes public lists; other status changes do not.
$before = $epoch();
do_action('transition_post_status', 'pending', 'draft', $draft);
do_action('transition_post_status', 'draft', 'auto-draft', $draft);
do_action('transition_post_status', 'publish', 'publish', $embed);
check($epoch() === $before, 'status changes outside publication keep the public caches');
do_action('transition_post_status', 'publish', 'draft', null);
check(!isset($meta[10]['_shiki_ever_published']), 'a status transition without a post is ignored by the publication history');
check($epoch() === $before + 1, 'a publication transition without a post still invalidates');
do_action('transition_post_status', 'publish', 'draft', $draft);
check('1' === ($meta[10]['_shiki_ever_published'] ?? ''), 'publication history is still recorded');
check($epoch() === $before + 2, 'publishing invalidates');
do_action('transition_post_status', 'draft', 'publish', $draft);
check($epoch() === $before + 3, 'unpublishing invalidates although the post is no longer public');
do_action('transition_post_status', 'publish', 'publish', $published);
check($epoch() === $before + 3, 'an update that stays published is left to save_post, which invalidates it once');
// Deleting: only the object core passes can tell what was deleted.
$before = $epoch();
do_action('deleted_post', 10, $draft);
do_action('deleted_post', 11, $revision);
check($epoch() === $before, 'deleting a draft or a revision keeps the public caches');
do_action('deleted_post', 12, $published);
do_action('deleted_post', 13, $attachment);
do_action('deleted_post', 999);
check($epoch() === $before + 3, 'deleting a published post, an uploaded file or an unknown post invalidates');
// Independent metadata updates (REST, plugins) follow the same rule, for watched keys only.
$before = $epoch();
do_action('updated_post_meta', 1, 10, '_shiki_priority');
do_action('added_post_meta', 1, 10, '_thumbnail_id');
do_action('updated_post_meta', 1, 12, '_shiki_translation');
check($epoch() === $before, 'metadata of a draft and unrelated keys keep the public caches');
do_action('updated_post_meta', 1, 12, '_shiki_priority');
do_action('deleted_post_meta', array(1), 12, '_thumbnail_id');
do_action('deleted_post_meta', array(1), 0, '_thumbnail_id');
check($epoch() === $before + 3, 'public metadata of a published post, or of all posts at once, invalidates');
// Core sets the terms of a post on every save, including each draft autosave.
$before = $epoch();
do_action('set_object_terms', 10, array(), array(), 'category');
check($epoch() === $before, 'terms of a draft keep the public caches');
do_action('set_object_terms', 12, array(), array(), 'category');
do_action('set_object_terms', 999, array(), array(), 'category');
do_action('set_object_terms', 10, array(), array(), 'link_category');
check($epoch() === $before + 3, 'terms of a published post, or of anything that is not clearly a draft post, invalidate');
echo json_encode(array('status' => 'PASS', 'checks' => $passed, 'php' => PHP_VERSION)), "\n";
