<?php
/** Runs only against the dedicated clean installation created by verify-package.sh. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || DB_NAME !== 'shikipress_clean') { WP_CLI::error('Not clean verification DB.'); }
$phase = $args[0] ?? 'baseline';
$expected_version = getenv('SHIKI_PACKAGE_EXPECTED_VERSION');
if (!is_string($expected_version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $expected_version)) { WP_CLI::error('Validated package release version is required.'); }
$results = array();
$assert = static function ($name, $condition) use (&$results) { $results[] = array('name' => $name, 'pass' => (bool) $condition); if (!$condition) { WP_CLI::warning('FAIL ' . $name); } };
$assert('delivery theme active', get_stylesheet() === 'shikipress');
$assert('no active production plugins', get_option('active_plugins', array()) === array());
$assert('no source symlink', !is_link(get_theme_root() . '/shikipress'));
$assert('all modules available', function_exists('shiki_select_posts') && function_exists('shiki_ad_html') && function_exists('shiki_breadcrumbs') && function_exists('shiki_toc'));
$assert('PHP bootstrap matches expected release ' . $expected_version, defined('SHIKI_VERSION') && SHIKI_VERSION === $expected_version);
$assert('stored version current', get_option('shikipress_version') === $expected_version);
$assert('cache epoch retained as positive integer', (int) get_option('shikipress_cache_epoch') > 0);
if ($phase !== 'simulated-update') { $assert('theme header matches expected release ' . $expected_version, wp_get_theme()->get('Version') === $expected_version); }
$category_identity = static function ($ids) {
    $identity = array();
    foreach ($ids as $season => $id) {
        $term = get_term($id, 'category');
        $identity[$season] = $term && !is_wp_error($term) ? array('term_id' => (int) $term->term_id, 'slug' => $term->slug, 'name' => $term->name, 'parent' => (int) $term->parent) : null;
    }
    return $identity;
};
if ($phase === 'baseline') {
    $assert('release optional adapter present', function_exists('shiki_xml_sitemap_url'));
    $assert('fresh zero public posts', shiki_public_posts(array('posts_per_page' => 1))->found_posts === 0);
} elseif ($phase === 'snapshot') {
    $settings = get_option('shikipress_settings', array());
    $settings['season_mode'] = 'fixed'; $settings['season_fixed'] = 'summer';
    update_option('shikipress_settings', $settings);
    $fixture = get_option('shiki_fixture_state');
    $snapshot = array(
        'settings' => get_option('shikipress_settings'),
        'post_ids' => $fixture['post_ids'],
        'slugs' => array_map(static function ($id) { return get_post_field('post_name', $id); }, $fixture['post_ids']),
        'urls' => array_map('get_permalink', $fixture['post_ids']),
        'permalink_structure' => get_option('permalink_structure'),
        'terms' => shiki_season_terms(),
        'category_identity' => $category_identity(shiki_season_terms()),
    );
    update_option('shiki_package_snapshot', $snapshot);
    $assert('twenty real fixture posts', count($snapshot['post_ids']) === 20);
    $assert('four mapped categories', count(array_filter($snapshot['terms'])) === 4);
} else {
    $snapshot = get_option('shiki_package_snapshot');
    if (!is_array($snapshot) || count($snapshot['post_ids'] ?? array()) !== 20) { WP_CLI::error('Missing twenty-post preservation snapshot.'); }
    $assert($phase . ' preserves settings', $snapshot['settings'] === get_option('shikipress_settings'));
    $assert($phase . ' preserves category mapping', $snapshot['terms'] === shiki_season_terms());
    $assert($phase . ' preserves category IDs and identities', $snapshot['category_identity'] === $category_identity(shiki_season_terms()));
    $actual_slugs = array_map(static function ($id) { return get_post_field('post_name', $id); }, $snapshot['post_ids']);
    $assert($phase . ' preserves article slugs', $snapshot['slugs'] === $actual_slugs);
    $assert($phase . ' preserves twenty complete article URLs', $snapshot['urls'] === array_map('get_permalink', $snapshot['post_ids']));
    $assert($phase . ' preserves permalink structure', $snapshot['permalink_structure'] === get_option('permalink_structure'));
    $fixture = get_option('shiki_fixture_state');
    $assert($phase . ' preserves article IDs', is_array($fixture) && $snapshot['post_ids'] === ($fixture['post_ids'] ?? array()));
    $assert($phase . ' retains every original published article', count(array_filter($snapshot['post_ids'], static function ($id) { return get_post_status($id) === 'publish' && get_post_type($id) === 'post'; })) === 20);
    $assert($phase . ' public article count20', (int) shiki_public_posts(array('posts_per_page' => 1))->found_posts === 20);
    if ($phase === 'simulated-update') { $assert('simulated installed header changed', wp_get_theme()->get('Version') === getenv('SHIKI_PACKAGE_SIMULATED_VERSION')); }
    if ($phase === 'rollback') {
        $assert('rollback restored actual release files', wp_get_theme()->get('Version') === SHIKI_VERSION);
        $assert('rollback removed simulated defect marker', !str_contains(file_get_contents(get_stylesheet_directory() . '/style.css'), 'SHIKI_SIMULATED_DEFECT'));
    }
}
$file = dirname(__DIR__) . '/tests/results/package-' . sanitize_key($phase) . '.json';
file_put_contents($file, wp_json_encode(array('phase' => $phase, 'expected_release' => $expected_version, 'wordpress' => get_bloginfo('version'), 'results' => $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
foreach ($results as $row) { if (!$row['pass']) { WP_CLI::error('Package verification assertions failed.'); } }
WP_CLI::success('Package ' . $phase . ': ' . count($results) . ' assertions.');
