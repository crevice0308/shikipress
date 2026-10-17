<?php
/** Read-only selection timings after fixtures; local environment only. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || wp_get_environment_type() !== 'local') { WP_CLI::error('Requires isolated local test environment.'); }
$count = (int) ($args[0] ?? 20);
$actual = (int) shiki_public_posts(array('posts_per_page' => 1, 'fields' => 'ids'))->found_posts;
if ($actual !== $count) { WP_CLI::error('Public article count mismatch.'); }
$rows = array();
global $wpdb;
for ($repeat = 1; $repeat <= 3; ++$repeat) {
    shiki_invalidate_cache();
    foreach (array('cold', 'warm') as $cache) {
        $query_start = $wpdb->num_queries;
        $time_start = hrtime(true);
        $posts = shiki_select_posts('featured', array(), 3);
        $rows[] = array('repeat' => $repeat, 'cache' => $cache, 'milliseconds' => round((hrtime(true) - $time_start) / 1000000, 3), 'database_queries' => $wpdb->num_queries - $query_start, 'selected_count' => count($posts));
    }
}
$path = dirname(__DIR__) . '/tests/results/scale-performance.json';
$all = file_exists($path) ? json_decode(file_get_contents($path), true) : array('conditions' => 'WP-CLI PHP8.3.6/MariaDB10.11.14 loopback. Cold=theme cache epoch invalidated; warm=immediate same-argument call. Object/process state may remain warm. Three pairs per count. Not an end-user latency or Lighthouse score.', 'counts' => array());
$all['counts'][(string) $count] = array('public_articles' => $actual, 'peak_memory_bytes' => memory_get_peak_usage(true), 'samples' => $rows);
file_put_contents($path, wp_json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
WP_CLI::success('Scale measurement count' . $count . ', six samples saved.');
