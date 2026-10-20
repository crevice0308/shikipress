<?php
/** Isolated interleaving tests. No WordPress database, real credentials, or HTTP. */
$cases = array('success', 'failure', 'retry-first', 'retry-second', 'retry-exhausted', 'stale-success-title', 'stale-failure-title', 'stale-retry-title', 'stale-success-token', 'stale-failure-token', 'stale-retry-token', 'replace-before-claim-title', 'replace-before-claim-token', 'busy-lock', 'busy-lock-last-deferral', 'busy-lock-exhausted', 'busy-lock-recovery', 'replace-before-deferral', 'duplicate-inflight', 'exception-release', 'status-stale-title', 'status-stale-token', 'status-stopped', 'status-scheduled', 'status-working', 'status-title-mismatch');
$scenario = defined('SHIKI_JOB_CASE') ? SHIKI_JOB_CASE : ($argv[1] ?? '--list');
if ($scenario === '--list') { echo implode("\n", $cases) . "\n"; return; }
if (!in_array($scenario, $cases, true)) { throw new RuntimeException('Unknown scenario'); }
define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('SHIKIPRESS_DEEPL_KEY', 'inert-test-no-network');
class WP_Post { public $ID = 1; public $post_title = 'Original title'; public $post_name = ''; public $post_type = 'post'; public $post_status = 'draft'; }
class WP_Error {
    public function __construct(private $code, private $message = '', private $data = null) {}
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
class FixtureJsonResponse extends RuntimeException { public function __construct(public $data) { parent::__construct('Synthetic JSON response'); } }
$hooks = $options = $events = array(); $post = new WP_Post(); $http_calls = 0; $passed = 0; $meta_invalidations = 0;
$after_lock = $before_http = $before_cas = null; $response_kind = 'success';
function fixture_job($token = 'original', $title = 'Original title') { return array('state' => 'queued', 'title_hash' => hash('sha256', $title), 'token' => $token, 'attempts' => 0, 'created' => time()); }
$meta = array('_shiki_translation' => fixture_job());
function add_action($hook, $callback, ...$unused) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter(...$unused) {}
function get_post($id) { return clone ($id instanceof WP_Post ? $id : ($GLOBALS['read_cache_post'] ?? $GLOBALS['post'])); }
function get_post_meta($id, $key, $single) { return $GLOBALS['read_cache_meta'][$key] ?? ($GLOBALS['meta'][$key] ?? ''); }
function update_post_meta($id, $key, $value, $previous = '') {
    if ($GLOBALS['before_cas']) { $callback = $GLOBALS['before_cas']; $GLOBALS['before_cas'] = null; $callback(); }
    // Model update_metadata's serialized previous-value WHERE predicate and cache invalidation.
    if ($previous !== '' && serialize($GLOBALS['meta'][$key] ?? null) !== serialize($previous)) { return false; }
    if (($GLOBALS['meta'][$key] ?? null) === $value) { return false; }
    $GLOBALS['meta'][$key] = $value; ++$GLOBALS['meta_invalidations']; return true;
}
function shiki_option($key, ...$unused) { return $key === 'deepl_enabled' ? true : 'free'; }
function get_option($key, $default = array()) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$unused) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key] = $value;
    if ($GLOBALS['after_lock']) { $callback = $GLOBALS['after_lock']; $GLOBALS['after_lock'] = null; $callback(); }
    return true;
}
function wp_generate_password(...$unused) { return 'owned-lock'; }
function maybe_serialize($value) { return serialize($value); }
function wp_cache_delete($key, $group) {
    if ($group === 'post_meta') { unset($GLOBALS['read_cache_meta']); }
    if ($group === 'posts') { unset($GLOBALS['read_cache_post']); }
}
$wpdb = new class {
    public $options = 'wp_options';
    public function delete($table, $where, ...$unused) {
        $key = $where['option_name'];
        if (serialize($GLOBALS['options'][$key] ?? null) === $where['option_value']) { unset($GLOBALS['options'][$key]); }
    }
};
function wp_json_encode($value) { return json_encode($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function remove_accents($value) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_schedule_single_event($when, $hook, $args) { $GLOBALS['events'][] = array('delay' => $when - time(), 'hook' => $hook, 'args' => $args); return true; }
function wp_next_scheduled($hook, $args) { foreach ($GLOBALS['events'] as $event) { if ($event['hook'] === $hook && $event['args'] === $args) { return time() + $event['delay']; } } return false; }
function check_ajax_referer(...$unused) {}
function absint($value) { return abs((int) $value); }
function current_user_can(...$unused) { return true; }
function wp_send_json_success($data) { throw new FixtureJsonResponse($data); }
function wp_send_json_error(...$unused) { throw new RuntimeException('Unexpected JSON authorization failure'); }
function wp_safe_remote_post($url, $args) {
    ++$GLOBALS['http_calls'];
    if ($GLOBALS['before_http']) { $callback = $GLOBALS['before_http']; $GLOBALS['before_http'] = null; $callback(); }
    if ($GLOBALS['response_kind'] === 'throw') { throw new RuntimeException('Synthetic interruption'); }
    return array('code' => match ($GLOBALS['response_kind']) { 'failure' => 403, 'retry' => 503, default => 200 }, 'body' => '{"translations":[{"text":"Original English title"}]}');
}
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL ' . $GLOBALS['scenario'] . ': ' . $label); } ++$GLOBALS['passed']; }
function replacement($same_title = false) {
    $GLOBALS['post']->post_title = $same_title ? 'Original title' : 'New title';
    $GLOBALS['replacement'] = fixture_job('replacement', $GLOBALS['post']->post_title);
    $GLOBALS['meta']['_shiki_translation'] = $GLOBALS['replacement'];
}
require defined('SHIKI_AUTOMATION_FILE') ? SHIKI_AUTOMATION_FILE : dirname(__DIR__, 2) . '/shikipress/inc/automation.php';
$run = $hooks['shiki_translate_job'][0]; $lock = 'shiki_translation_lock_1';
if (str_starts_with($scenario, 'status-')) {
    $_POST['post_id'] = 1;
    if (str_starts_with($scenario, 'status-stale-')) {
        $before_cas = static function () use ($scenario) {
            // Another request writes DB state, leaving this request's already-read caches stale.
            $GLOBALS['read_cache_meta'] = $GLOBALS['meta']; $GLOBALS['read_cache_post'] = clone $GLOBALS['post'];
            replacement(str_ends_with($scenario, '-token'));
        };
    }
    if ($scenario === 'status-scheduled') { wp_schedule_single_event(time() + 30, 'shiki_translate_job', array(1, 'original')); }
    if ($scenario === 'status-working') { $meta['_shiki_translation']['state'] = 'working'; $options[$lock] = array('expires' => time() + 30, 'token' => 'other-worker'); }
    if ($scenario === 'status-title-mismatch') { $post->post_title = 'Unapproved new title'; }
    try { $hooks['wp_ajax_shiki_translation_status'][0](); check(false, 'expected JSON result'); }
    catch (FixtureJsonResponse $json) { $data = $json->data; }
    if (str_starts_with($scenario, 'status-stale-')) {
        check($meta['_shiki_translation'] === $replacement, 'stale poll does not cancel replacement');
        check($data['state'] === 'queued' && $data['slug'] === '', 'poll returns latest job and refreshed title');
        check(!isset($GLOBALS['read_cache_meta']) && !isset($GLOBALS['read_cache_post']), 'failed CAS explicitly refreshes request-local caches');
    } elseif ($scenario === 'status-title-mismatch') {
        check($data['state'] === 'error' && $meta['_shiki_translation']['state'] === 'queued', 'title mismatch still refuses old candidate without mutating job');
    } else {
        $expected = $scenario === 'status-stopped' ? 'error' : ($scenario === 'status-working' ? 'working' : 'queued');
        check($meta['_shiki_translation']['state'] === $expected && $data['state'] === $expected, 'normal poll status preserved');
    }
    check($http_calls === 0, 'poll never sends HTTP');
} elseif (str_starts_with($scenario, 'stale-')) {
    $response_kind = explode('-', $scenario)[1];
    $before_http = static function () use ($scenario) { replacement(str_ends_with($scenario, '-token')); };
    $run(1, 'original');
    check($meta['_shiki_translation'] === $replacement, 'old response cannot overwrite replacement');
    check(!$events, 'old response cannot schedule retry');
    check(!isset($options[$lock]), 'old worker releases its lock');
    check($http_calls === 1, 'only original request ran');
    $response_kind = 'success'; $run(1, 'replacement');
    check($meta['_shiki_translation']['state'] === 'done' && $meta['_shiki_translation']['token'] === 'replacement' && $http_calls === 2, 'replacement still runs');
} elseif (str_starts_with($scenario, 'replace-before-claim')) {
    $after_lock = static function () use ($scenario) { replacement(str_ends_with($scenario, '-token')); };
    $run(1, 'original');
    check($meta['_shiki_translation'] === $replacement, 'stale queued read cannot claim replacement');
    check($http_calls === 0 && !$events && !isset($options[$lock]), 'no old request/retry and lock released');
} elseif (str_starts_with($scenario, 'busy-lock') || $scenario === 'replace-before-deferral') {
    $options[$lock] = array('expires' => time() + 30, 'token' => 'other-worker');
    if ($scenario === 'busy-lock-last-deferral') { $meta['_shiki_translation']['lock_waits'] = 2; }
    if ($scenario === 'busy-lock-exhausted') { $meta['_shiki_translation']['lock_waits'] = 3; }
    if ($scenario === 'replace-before-deferral') { $before_cas = static function () { replacement(true); }; }
    $run(1, 'original');
    check($http_calls === 0 && $options[$lock]['token'] === 'other-worker', 'busy lock is respected');
    check($meta['_shiki_translation']['attempts'] === 0, 'deferral does not spend HTTP attempts');
    if ($scenario === 'replace-before-deferral') {
        check($meta['_shiki_translation'] === $replacement && !$events, 'stale deferral cannot replace or reschedule new job');
    } elseif ($scenario === 'busy-lock-exhausted') {
        check($meta['_shiki_translation']['state'] === 'error' && !$events, 'lock retries are bounded');
    } else {
        check($meta['_shiki_translation']['state'] === 'queued' && count($events) === 1 && $events[0]['args'] === array(1, 'original') && $events[0]['delay'] >= 29 && $events[0]['delay'] <= 30, 'consumed event rescheduled after lock expiry');
        if ($scenario === 'busy-lock-recovery') {
            unset($options[$lock]); $events = array(); $run(1, 'original');
            check($http_calls === 1 && $meta['_shiki_translation']['state'] === 'done' && !$events, 'deferred job completes after lock release');
        }
    }
} elseif ($scenario === 'duplicate-inflight') {
    $after_lock = static function () { $GLOBALS['meta']['_shiki_translation']['state'] = 'working'; $GLOBALS['meta']['_shiki_translation']['attempts'] = 1; };
    $run(1, 'original');
    check($http_calls === 0 && $meta['_shiki_translation']['state'] === 'working' && !$events, 'duplicate stale queued job cannot send twice');
    check(!isset($options[$lock]), 'duplicate claim releases owned lock');
} elseif ($scenario === 'exception-release') {
    $response_kind = 'throw';
    try { $run(1, 'original'); check(false, 'expected synthetic exception'); } catch (RuntimeException $e) { check($e->getMessage() === 'Synthetic interruption', 'exception preserved'); }
    check(!isset($options[$lock]), 'owned lock released on exception');
} else {
    $response_kind = str_starts_with($scenario, 'retry-') ? 'retry' : $scenario;
    if ($scenario === 'retry-second') { $meta['_shiki_translation']['attempts'] = 1; }
    if ($scenario === 'retry-exhausted') { $meta['_shiki_translation']['attempts'] = 2; }
    $run(1, 'original'); $job = $meta['_shiki_translation'];
    check($http_calls === 1 && !isset($options[$lock]), 'one request and owned lock released');
    check($meta_invalidations === 2, 'claim and finish invalidate metadata through normal API');
    $state = $scenario === 'success' ? 'done' : (in_array($scenario, array('retry-first', 'retry-second'), true) ? 'queued' : 'error');
    check($job['state'] === $state, 'expected result state');
    if ($state === 'queued') {
        $delay = $scenario === 'retry-first' ? 60 : 120;
        check(count($events) === 1 && $events[0]['args'] === array(1, 'original') && $events[0]['delay'] >= $delay - 1 && $events[0]['delay'] <= $delay, 'original bounded backoff preserved');
    } else { check(!$events, 'terminal state has no retry'); }
}
echo json_encode(array('php' => PHP_VERSION, 'scenario' => $scenario, 'passed' => $passed, 'status' => 'PASS')) . "\n";
