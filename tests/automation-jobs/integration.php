<?php
/** Real WordPress metadata/Cron regression, only in a marked disposable local site. */
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || !defined('ABSPATH') || wp_get_environment_type() !== 'local'
    || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true || !function_exists('shiki_acquire_job_lock')) {
    throw new RuntimeException('Requires a marked disposable local ShikiPress installation.');
}
if (defined('SHIKIPRESS_DEEPL_KEY')) { throw new RuntimeException('Refusing an environment with an existing translation key.'); }
define('SHIKIPRESS_DEEPL_KEY', 'synthetic-test-no-network');
$scenario = $shiki_job_case ?? ($args[0] ?? 'stale-success-title');
if (!in_array($scenario, array('success', 'failure', 'retry', 'stale-success-title', 'stale-failure-title', 'stale-retry-title', 'stale-success-token', 'stale-retry-token', 'replace-before-claim', 'busy-lock', 'busy-lock-exhausted', 'status-stale-title', 'status-stale-token'), true)) { throw new RuntimeException('Unknown fixture scenario'); }
$checks = 0; $http_calls = 0; $id = 0; $lock = ''; $replacement = null;
$check = static function ($condition, $message) use (&$checks) { if (!$condition) { throw new RuntimeException($message); } ++$checks; };
$settings = get_option('shikipress_settings', array());
$job = array('state' => 'queued', 'title_hash' => hash('sha256', 'Original title'), 'token' => 'original-fixture', 'attempts' => 0, 'created' => time());
$replace = static function ($same_title) use (&$id, &$replacement, $job) {
    if (!$same_title) { wp_update_post(array('ID' => $id, 'post_title' => 'Replacement title')); }
    $replacement = $job; $replacement['token'] = 'replacement-fixture';
    $replacement['title_hash'] = hash('sha256', get_post($id)->post_title);
    update_post_meta($id, '_shiki_translation', $replacement);
    wp_schedule_single_event(time() + 1, 'shiki_translate_job', array($id, 'replacement-fixture'));
};
$claim_interleave = static function ($name) use (&$lock, $scenario, $replace) {
    if ($scenario === 'replace-before-claim' && $name === $lock) { $replace(true); }
};
$mock = static function ($pre, $http_args, $url) use (&$http_calls, $scenario, $replace) {
    if ($url !== 'https://api-free.deepl.com/v2/translate') { return new WP_Error('fixture-network-blocked', 'No real HTTP in this fixture.'); }
    ++$http_calls;
    if (str_starts_with($scenario, 'stale-') && $http_calls === 1) { $replace(str_ends_with($scenario, '-token')); }
    $status = str_contains($scenario, 'failure') ? 403 : (str_contains($scenario, 'retry') ? 503 : 200);
    return array('response' => array('code' => $status), 'headers' => array(), 'body' => '{"translations":[{"text":"Original English title"}]}', 'cookies' => array());
};
$status_interleave = null; $json_die = null;
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_action('added_option', $claim_interleave);
try {
    wp_set_current_user(1);
    update_option('shikipress_settings', array_merge($settings, array('deepl_enabled' => true, 'deepl_plan' => 'free', 'classification_mode' => 'suggest', 'slug_mode' => 'suggest')));
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Original title', 'meta_input' => array('_shiki_translation_fixture' => 1)), true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $lock = 'shiki_translation_lock_' . $id;
    $check(shiki_slug_editable($id), 'Synthetic draft must be eligible');
    if ($scenario === 'busy-lock-exhausted') { $job['lock_waits'] = 3; }
    update_post_meta($id, '_shiki_translation', $job);
    // Prime WordPress's post-meta cache before interleaving state replacement.
    $check(get_post_meta($id, '_shiki_translation', true) === $job, 'Initial metadata cached');
    if (str_starts_with($scenario, 'busy-lock')) { add_option($lock, array('expires' => time() + 30, 'token' => 'other-fixture'), '', false); }
    if (str_starts_with($scenario, 'status-')) {
        // Emulate another process's DB writes without refreshing this request's
        // already-primed post/meta caches; the production failed-CAS path must do that.
        $status_interleave = static function ($check_value, $object_id, $key, $value) use (&$id, &$replacement, $scenario, $job) {
            if ($object_id !== $id || $key !== '_shiki_translation' || ($value['state'] ?? '') !== 'error') { return $check_value; }
            global $wpdb;
            $replacement = $job; $replacement['token'] = 'replacement-fixture';
            if ($scenario === 'status-stale-title') {
                $replacement['title_hash'] = hash('sha256', 'Replacement title');
                $wpdb->update($wpdb->posts, array('post_title' => 'Replacement title'), array('ID' => $id));
            }
            $wpdb->update($wpdb->postmeta, array('meta_value' => maybe_serialize($replacement)), array('post_id' => $id, 'meta_key' => '_shiki_translation'));
            return $check_value;
        };
        add_filter('update_post_metadata', $status_interleave, 10, 4);
        if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
        $json_die = static fn() => static function () { throw new RuntimeException('fixture-json-complete'); };
        add_filter('wp_die_ajax_handler', $json_die);
        $_POST['post_id'] = $id; $_POST['nonce'] = wp_create_nonce('shiki_automation_ajax'); $_REQUEST['nonce'] = $_POST['nonce'];
        ob_start();
        try { do_action('wp_ajax_shiki_translation_status'); }
        catch (RuntimeException $error) { if ($error->getMessage() !== 'fixture-json-complete') { throw $error; } }
        finally { $json = json_decode(ob_get_clean(), true); }
        $check(($json['success'] ?? false) && ($json['data']['state'] ?? '') === 'queued', 'Polling returns replacement and refreshed title');
        $check(get_post_meta($id, '_shiki_translation', true) === $replacement, 'Polling cannot overwrite newer job');
        $check($http_calls === 0, 'Status polling sends no HTTP');
    } else { do_action('shiki_translate_job', $id, 'original-fixture'); }
    $actual = get_post_meta($id, '_shiki_translation', true);
    if (str_starts_with($scenario, 'status-')) {
        $check(get_post($id)->post_title === ($scenario === 'status-stale-title' ? 'Replacement title' : 'Original title'), 'Post cache refreshed after failed CAS');
    } elseif (str_starts_with($scenario, 'stale-') || $scenario === 'replace-before-claim') {
        $check($actual === $replacement, 'Atomic transition must preserve replacement, including same-title replacement');
        $check(!wp_next_scheduled('shiki_translate_job', array($id, 'original-fixture')), 'Stale worker must not schedule retry');
        $check($http_calls === ($scenario === 'replace-before-claim' ? 0 : 1), 'Only owned claim may send HTTP');
        $check(!get_option($lock), 'Owned lock released');
        remove_action('added_option', $claim_interleave);
        wp_clear_scheduled_hook('shiki_translate_job', array($id, 'replacement-fixture'));
        do_action('shiki_translate_job', $id, 'replacement-fixture');
        $latest = get_post_meta($id, '_shiki_translation', true);
        $check($latest['token'] === 'replacement-fixture' && $latest['attempts'] === 1 && $latest['state'] !== 'working', 'Replacement remains runnable');
    } elseif (str_starts_with($scenario, 'busy-lock')) {
        $check($http_calls === 0 && get_option($lock)['token'] === 'other-fixture', 'Busy worker cannot send or release another lock');
        $check($actual['attempts'] === 0, 'Busy deferral does not count as HTTP attempt');
        if ($scenario === 'busy-lock-exhausted') {
            $check($actual['state'] === 'error' && !wp_next_scheduled('shiki_translate_job', array($id, 'original-fixture')), 'Contention retries bounded');
        } else {
            $next = wp_next_scheduled('shiki_translate_job', array($id, 'original-fixture'));
            $check($actual['state'] === 'queued' && $actual['lock_waits'] === 1 && $next >= time() + 29, 'Consumed event rescheduled');
            wp_clear_scheduled_hook('shiki_translate_job', array($id, 'original-fixture')); delete_option($lock);
            do_action('shiki_translate_job', $id, 'original-fixture');
            $check(get_post_meta($id, '_shiki_translation', true)['state'] === 'done' && $http_calls === 1, 'Deferred request recovers');
        }
    } else {
        $expected = array('success' => 'done', 'failure' => 'error', 'retry' => 'queued');
        $check($actual['state'] === $expected[$scenario] && $actual['attempts'] === 1 && $http_calls === 1, 'Normal request result preserved');
        $check(!get_option($lock), 'Normal request releases lock');
        $next = wp_next_scheduled('shiki_translate_job', array($id, 'original-fixture'));
        $check($scenario === 'retry' ? $next >= time() + 59 : !$next, 'Only retry response schedules backoff');
    }
} finally {
    remove_filter('pre_http_request', $mock, PHP_INT_MAX); remove_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
    remove_action('added_option', $claim_interleave);
    if ($status_interleave) { remove_filter('update_post_metadata', $status_interleave, 10); }
    if ($json_die) { remove_filter('wp_die_ajax_handler', $json_die); }
    if ($id && !is_wp_error($id)) {
        foreach (array('original-fixture', 'replacement-fixture') as $token) { wp_clear_scheduled_hook('shiki_translate_job', array($id, $token)); }
        delete_option('shiki_translation_lock_' . $id); wp_delete_post($id, true);
    }
    update_option('shikipress_settings', $settings);
}
echo wp_json_encode(array('php' => PHP_VERSION, 'wp' => get_bloginfo('version'), 'scenario' => $scenario, 'checks' => $checks, 'status' => 'PASS')) . "\n";
