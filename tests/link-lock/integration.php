<?php
/** Actual independent-request link-lock checks; never load on a real site. */
if (!defined('WP_CLI') || !WP_CLI || !defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true
    || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true || wp_get_environment_type() !== 'local'
    || getenv('SHIKI_LINK_LOCK_FIXTURE') !== '1' || !function_exists('shiki_links_acquire_lock')) {
    throw new RuntimeException('Requires an explicitly enabled, owned disposable local CLI site.');
}
$phase = $args[0] ?? ''; $scenario = $args[1] ?? ''; $directory = realpath($args[2] ?? '');
$state = realpath(getenv('SHIKI_CI_STATE') ?: '');
if (!$state || realpath(ABSPATH) !== $state . '/wordpress'
    || !is_file($state . '/.owned-disposable-ci')
    || trim(file_get_contents($state . '/.owned-disposable-ci')) !== 'Disposable ShikiPress regression database; never production'
    || !$directory || !str_starts_with($directory . '/', $state . '/reports/link-lock/')
    || !is_file($directory . '/.fixture-run')
    || trim(file_get_contents($directory . '/.fixture-run')) !== 'ShikiPress link-lock integration fixture'
    || !in_array($scenario, ['lifecycle', 'stale-reclaim', 'owner-release', 'absent-acquire'], true)
    || !in_array($phase, ['lifecycle', 'seed', 'a', 'b', 'verify', 'cleanup'], true)) {
    throw new RuntimeException('Invalid fixture site, phase, scenario, or owned report directory.');
}
$network_calls = $mail_calls = 0;
add_filter('pre_http_request', static function () use (&$network_calls) { ++$network_calls; return new WP_Error('fixture-network-blocked', 'No HTTP in link-lock fixture.'); }, PHP_INT_MAX);
add_filter('pre_wp_mail', static function () use (&$mail_calls) { ++$mail_calls; return false; }, PHP_INT_MAX);
$key = 'shikipress_link_lock'; $checks = [];
$check = static function ($condition, $label) use (&$checks) { $checks[$label] = (bool)$condition; if (!$condition) { throw new RuntimeException($label); } };
$write = static function ($name, $value) use ($directory) { file_put_contents($directory . '/' . $name . '.json', wp_json_encode($value, JSON_PRETTY_PRINT)); };
$read = static function ($name) use ($directory) { $value = json_decode(file_get_contents($directory . '/' . $name . '.json'), true); if (!is_array($value)) { throw new RuntimeException('Invalid barrier data: ' . $name); } return $value; };
$wait = static function ($name) use ($directory) { $deadline = microtime(true) + 20; while (!is_file($directory . '/' . $name)) { if (microtime(true) > $deadline) { throw new RuntimeException('Bounded barrier timed out: ' . $name); } usleep(100000); } };
$fresh = static function () use ($key) { wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options'); return get_option($key, []); };
$hash = static fn($value) => is_string($value) && $value !== '' ? hash('sha256', $value) : null;
$report = ['phase'=>$phase, 'scenario'=>$scenario, 'php'=>PHP_VERSION, 'wordpress'=>get_bloginfo('version'), 'theme'=>SHIKI_VERSION, 'pid'=>getmypid(), 'opcache_enabled'=>function_exists('opcache_get_status') && (bool)opcache_get_status(false)];
if ($phase === 'lifecycle') {
    $check(!get_option($key), 'no_existing_lock');
    $token = shiki_links_acquire_lock();
    $check(is_string($token), 'fresh_lock_acquired');
    $write('owned-lifecycle', ['token'=>$token]);
    $original = $fresh(); $busy = shiki_links_acquire_lock();
    $check(is_wp_error($busy) && $busy->get_error_code() === 'locked' && $fresh() === $original, 'busy_acquisition_preserves_owner');
    shiki_links_release_lock('not-the-fixture-owner');
    $check($fresh() === $original, 'foreign_release_preserves_owner');
    shiki_links_release_lock($token);
    $check(!$fresh(), 'owner_releases_lock');
    $expired = ['time'=>time()-120, 'token'=>'expired-' . wp_generate_uuid4()];
    $write('seed-private', $expired); $check(add_option($key, $expired, '', false), 'expired_fixture_created');
    $next = shiki_links_acquire_lock();
    $check(is_string($next) && $next !== $expired['token'], 'expired_lock_reclaimed');
    $write('owned-a-private', ['token'=>$next]);
    shiki_links_release_lock($expired['token']);
    $check(($fresh()['token'] ?? '') === $next, 'stale_owner_cannot_release_successor');
    shiki_links_release_lock($next); $check(!$fresh(), 'successor_releases_lock');
} elseif ($phase === 'seed') {
    $check(!get_option($key), 'no_existing_lock');
    $expired = ['time'=>time()-120, 'token'=>'expired-' . wp_generate_uuid4()];
    $write('seed-private', $expired);
    if ($scenario !== 'absent-acquire') { $check(add_option($key, $expired, '', false), 'expired_fixture_created'); }
    $report['time'] = microtime(true);
} elseif ($phase === 'a') {
    $expired = $read('seed-private');
    // The hook only pauses a real read. All lock changes are made by actual theme functions.
    $pause = static function ($value) use ($expired, $write, $wait) {
        static $paused = false;
        if (!$paused) {
            $paused = true;
            if ($value !== $expired) { throw new RuntimeException('A did not read the actual seeded expired lock.'); }
            $write('a-read', ['pid'=>getmypid(), 'time'=>microtime(true), 'value_hash'=>hash('sha256', serialize($value))]);
            $wait('resume-a');
        }
        return $value;
    };
    $pause_insert = static function ($sql) use ($write, $wait, $key) {
        global $wpdb; static $paused = false;
        if (!$paused && preg_match('/^\s*INSERT(?:\s+IGNORE)?\s+INTO\s+/i', $sql) && str_contains($sql, $wpdb->options) && str_contains($sql, "'" . $key . "'")) {
            $paused = true;
            $write('a-read', ['pid'=>getmypid(), 'time'=>microtime(true), 'point'=>'before actual option INSERT SQL', 'query_sha256'=>hash('sha256',$sql), 'upsert'=>str_contains(strtoupper($sql),'ON DUPLICATE KEY UPDATE')]);
            $wait('resume-a');
        }
        return $sql;
    };
    if ($scenario === 'absent-acquire') { add_filter('query', $pause_insert, 10); }
    else { add_filter('option_shikipress_link_lock', $pause, 10); }
    if (in_array($scenario, ['stale-reclaim', 'absent-acquire'], true)) {
        $result = shiki_links_acquire_lock();
        $report['acquired'] = is_string($result);
        $report['error'] = is_wp_error($result) ? $result->get_error_code() : null;
        $report['token_hash'] = is_string($result) ? $hash($result) : null;
        if (is_string($result)) { $write('owned-a-private', ['token'=>$result]); }
    } else {
        shiki_links_release_lock($expired['token']);
        $report['release_returned'] = true;
    }
    remove_filter('option_shikipress_link_lock', $pause, 10); remove_filter('query', $pause_insert, 10);
    $current = $fresh();
    $report['stored_token_hash'] = $hash($current['token'] ?? null);
    $report['time'] = microtime(true); $write('a-observation', $report);
} elseif ($phase === 'b') {
    $token = shiki_links_acquire_lock(); $check(is_string($token), 'b_acquired_actual_lock');
    $write('owned-b-private', ['token'=>$token]); $held = $fresh();
    $write('b-acquired', ['pid'=>getmypid(), 'time'=>microtime(true), 'token_hash'=>$hash($token), 'stored_token_hash'=>$hash($held['token'] ?? null), 'expires_at'=>$held['time'] + 90]);
    $wait('finish-b');
    $current = $fresh();
    $report['token_hash'] = $hash($token); $report['stored_token_hash'] = $hash($current['token'] ?? null);
    $report['fresh_lock_preserved'] = ($current['token'] ?? null) === $token;
    shiki_links_release_lock($token);
    $report['no_lock_after_own_release'] = !$fresh();
    $report['time'] = microtime(true); $write('b-observation', $report);
} elseif ($phase === 'verify') {
    $a = $read('a-observation'); $b = $read('b-observation'); $first = $read('a-read'); $held = $read('b-acquired');
    $checks = [
        'independent_processes'=>$a['pid'] !== $b['pid'],
        'a_pauses_then_b_acquires_then_a_resumes'=>$first['time'] < $held['time'] && $held['time'] < $a['time'],
        'b_lock_was_fresh'=>$a['time'] < $held['expires_at'],
        'a_preserved_fresh_b_lock'=>$a['stored_token_hash'] === $held['token_hash'],
        'b_observed_its_lock_preserved'=>$b['fresh_lock_preserved'],
        'b_released_own_lock'=>$b['no_lock_after_own_release'],
    ];
    if (in_array($scenario, ['stale-reclaim', 'absent-acquire'], true)) { $checks['contender_did_not_become_second_owner'] = !$a['acquired'] && $a['error'] === 'locked'; }
    else { $checks['stale_release_returned_without_deleting_successor'] = $a['release_returned'] && $a['stored_token_hash'] === $held['token_hash']; }
    // Emit failed observations too, so a negative control proves what happened.
} elseif ($phase === 'cleanup') {
    $owned = [];
    foreach (['seed-private', 'owned-lifecycle', 'owned-a-private', 'owned-b-private'] as $name) {
        if (is_file($directory . '/' . $name . '.json')) { $value = $read($name); if (isset($value['token'])) { $owned[] = $value['token']; } }
    }
    $current = $fresh();
    if (isset($current['token']) && in_array($current['token'], $owned, true)) { shiki_links_release_lock($current['token']); }
    $report['foreign_lock_left_untouched'] = isset($current['token']) && !in_array($current['token'], $owned, true);
    $check(!$fresh(), 'no_fixture_lock_remains');
}
$checks['zero_http_calls'] = $network_calls === 0; $checks['zero_mail_calls'] = $mail_calls === 0;
$report['checks'] = $checks; $report['assertions'] = count($checks); $report['pass'] = !in_array(false, $checks, true);
echo wp_json_encode($report, JSON_PRETTY_PRINT) . "\n";
