<?php
/** Isolated real-function lock regressions; no HTTP or WordPress installation. */
define('ABSPATH', '/');
class WP_Error { public function __construct(public $code, public $message = '') {} public function get_error_code() { return $this->code; } }
function add_action(...$unused) {} function add_filter(...$unused) {}
function is_wp_error($value) { return $value instanceof WP_Error; }
$options = []; $cache = []; $after_read = null; $before_delete = null; $before_insert = null; $sequence = 0; $passed = 0; $insert_result = 1; $cache_deletes = [];
function get_option($key, $default = []) {
    $value = $GLOBALS['cache'][$key] ?? ($GLOBALS['options'][$key] ?? $default);
    $GLOBALS['cache'][$key] = $value;
    if ($GLOBALS['after_read']) { $callback = $GLOBALS['after_read']; $GLOBALS['after_read'] = null; $callback(); }
    return $value;
}
function add_option($key, $value, ...$unused) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    // Core checks absence before SQL, but its subsequent UPSERT can replace a new owner.
    if ($GLOBALS['before_insert']) { $callback = $GLOBALS['before_insert']; $GLOBALS['before_insert'] = null; $callback(); }
    $GLOBALS['options'][$key] = $value; unset($GLOBALS['cache'][$key]); return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key], $GLOBALS['cache'][$key]); return true; }
function wp_cache_delete($key, $group) { $GLOBALS['cache_deletes'][] = [$key, $group]; unset($GLOBALS['cache'][$key]); }
function maybe_serialize($value) { return serialize($value); }
function wp_generate_uuid4() { return 'synthetic-owner-' . ++$GLOBALS['sequence']; }
$wpdb = new class {
    public $options = 'wp_options';
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function query($prepared) {
        [$sql, $args] = $prepared;
        if ($sql !== 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, %s)'
            || count($args) !== 3 || $args[0] !== 'shikipress_link_lock' || $args[2] !== 'no') {
            throw new RuntimeException('Unexpected or unprepared lock insertion.');
        }
        if ($GLOBALS['before_insert']) { $callback = $GLOBALS['before_insert']; $GLOBALS['before_insert'] = null; $callback(); }
        if ($GLOBALS['insert_result'] !== 1) { return $GLOBALS['insert_result']; }
        if (isset($GLOBALS['options'][$args[0]])) { return 0; }
        $GLOBALS['options'][$args[0]] = unserialize($args[1]); return 1;
    }
    public function delete($table, $where, ...$unused) {
        if ($GLOBALS['before_delete']) { $callback = $GLOBALS['before_delete']; $GLOBALS['before_delete'] = null; $callback(); }
        $key = $where['option_name'];
        if (isset($GLOBALS['options'][$key]) && serialize($GLOBALS['options'][$key]) === $where['option_value']) { unset($GLOBALS['options'][$key]); return 1; }
        return 0;
    }
};
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL ' . $label); } ++$GLOBALS['passed']; }
function reset_fixture($value = null) {
    $GLOBALS['options'] = $value === null ? [] : ['shikipress_link_lock'=>$value];
    $GLOBALS['cache'] = []; $GLOBALS['after_read'] = $GLOBALS['before_delete'] = $GLOBALS['before_insert'] = null;
    $GLOBALS['insert_result'] = 1; $GLOBALS['cache_deletes'] = [];
}
$source = defined('SHIKI_LOCK_SOURCE') ? SHIKI_LOCK_SOURCE : dirname(__DIR__, 2) . '/shikipress';
require $source . '/inc/automation.php';
require $source . '/inc/tools.php';
$key = 'shikipress_link_lock';
reset_fixture(); $token = shiki_links_acquire_lock();
check(is_string($token) && $options[$key]['token'] === $token, 'free lock acquired');
check($options[$key]['time'] >= time()-1, 'same ninety-second lease representation');
check(in_array([$key,'options'],$cache_deletes,true) && in_array(['notoptions','options'],$cache_deletes,true), 'successful insert clears positive and negative caches');
$busy = shiki_links_acquire_lock(); check(is_wp_error($busy) && $busy->get_error_code() === 'locked', 'fresh lock respected');
shiki_links_release_lock('wrong-owner'); check(isset($options[$key]), 'other owner cannot release');
shiki_links_release_lock($token); check(!isset($options[$key]), 'owner releases unchanged lock');
shiki_links_release_lock($token); check(!isset($options[$key]), 'repeated release harmless');
foreach ([0, false, 2] as $result) {
    reset_fixture(); $insert_result = $result; $token = shiki_links_acquire_lock();
    check(is_wp_error($token) && $token->get_error_code() === 'locked' && !isset($options[$key]), 'only exactly one inserted row grants ownership: '.var_export($result,true));
    check(in_array([$key,'options'],$cache_deletes,true) && in_array(['notoptions','options'],$cache_deletes,true), 'failed insert clears both caches');
}
reset_fixture(); $cache['notoptions'] = [$key=>true];
$new = ['time'=>time(),'token'=>'concurrent-owner'];
$before_insert = static function() use($key,$new) { $GLOBALS['options'][$key] = $new; };
$token = shiki_links_acquire_lock();
check(is_wp_error($token) && $token->get_error_code() === 'locked' && $options[$key] === $new, 'absent-lock contention cannot overwrite owner');
check(!isset($cache[$key]) && !isset($cache['notoptions']), 'contended insert clears stale negative caches');
reset_fixture(['time'=>time()-120,'token'=>'expired']); $token=shiki_links_acquire_lock();
check(is_string($token) && $options[$key]['token']===$token, 'expired lock reclaimed');
foreach (['after_read','before_delete'] as $point) {
    $old=['time'=>time()-120,'token'=>'expired']; $new=['time'=>time(),'token'=>'concurrent-owner'];
    reset_fixture($old); $GLOBALS[$point]=static function() use($key,$new) { $GLOBALS['options'][$key]=$new; };
    $token=shiki_links_acquire_lock();
    check(is_wp_error($token) && $token->get_error_code()==='locked', $point.' replacement blocks stale acquisition');
    check($options[$key]===$new, $point.' replacement retained');
    check(!isset($cache[$key]) || $cache[$key]===$new, $point.' stale request cache cleared');
    reset_fixture($old); $GLOBALS[$point]=static function() use($key,$new) { $GLOBALS['options'][$key]=$new; };
    shiki_links_release_lock('expired');
    check($options[$key]===$new, $point.' stale owner release retains new owner');
}
// Even the same token with a refreshed lease is a different observed option value.
$old=['time'=>time()-120,'token'=>'same']; $new=['time'=>time(),'token'=>'same'];
reset_fixture($old); $after_read=static function() use($key,$new) { $GLOBALS['options'][$key]=$new; };
shiki_links_release_lock('same'); check($options[$key]===$new, 'full value comparison preserves refreshed lease');
// Existing rate guard still returns without leaving a lock or sending any requests.
reset_fixture(); $options['shikipress_link_last_run']=time();
$result=shiki_links_run_queue(); check(is_wp_error($result) && $result->get_error_code()==='rate', 'rate limit preserved');
check(!isset($options[$key]), 'rate return releases its lock');
echo json_encode(['php'=>PHP_VERSION,'passed'=>$passed,'status'=>'PASS']) . "\n";
