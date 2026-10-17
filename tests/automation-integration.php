<?php
/** Run only in the dedicated local fixture site: wp eval-file tests/automation-integration.php */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI only.'); }
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV) { throw new RuntimeException('Requires SHIKI_TEST_ENV=true in the dedicated test wp-config.'); }
global $results, $post_ids;
$settings_before = get_option('shikipress_settings', []);
$post_ids = []; $term_ids = []; $results = []; $scheduled = []; $renamed_terms = [];
function shiki_auto_test_assert(bool $condition, string $label): void { global $results; $results[] = ['test' => $label, 'passed' => $condition]; if (!$condition) { throw new RuntimeException($label); } }
function shiki_auto_test_post(array $args): int { global $post_ids; $id = wp_insert_post(array_merge(['post_title' => 'TEST automation', 'post_status' => 'draft', 'meta_input' => ['_shiki_automation_test' => 1]], $args), true); if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $post_ids[] = $id; return $id; }
try {
    wp_set_current_user(1);
    foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_shiki_automation_test', 'meta_value' => 1]) as $leftover) { wp_delete_post($leftover->ID, true); }
    update_option('shikipress_settings', array_merge(shiki_defaults(), $settings_before, ['season_mode' => 'auto', 'timezone' => 'Asia/Tokyo', 'classification_mode' => 'suggest', 'slug_mode' => 'suggest']));
    foreach (['2026-02-28T14:59:59Z' => 'winter', '2026-02-28T15:00:00Z' => 'spring', '2026-05-31T15:00:00Z' => 'summer', '2026-08-31T15:00:00Z' => 'autumn', '2026-11-30T15:00:00Z' => 'winter', '2028-02-29T14:59:59Z' => 'winter'] as $date => $expected) { shiki_auto_test_assert(shiki_season(new DateTimeImmutable($date)) === $expected, 'Tokyo boundary ' . $date); }
    $settings = get_option('shikipress_settings'); $settings['season_mode'] = 'fixed'; $settings['season_fixed'] = 'summer'; update_option('shikipress_settings', $settings);
    shiki_auto_test_assert(shiki_season() === 'summer', 'manual season fixed');
    $settings['season_mode'] = 'auto'; $settings['season_events'] = [['id' => 'year-cross', 'enabled' => true, 'start' => '12-20', 'end' => '01-10', 'label' => 'TEST year', 'priority' => 1], ['id' => 'priority', 'enabled' => true, 'start' => '01-01', 'end' => '01-02', 'label' => 'TEST priority', 'priority' => 2]]; update_option('shikipress_settings', $settings);
    shiki_auto_test_assert(shiki_active_event(new DateTimeImmutable('2027-01-01T00:00:00+09:00'))['id'] === 'priority', 'event overlap priority');
    shiki_auto_test_assert(shiki_active_event(new DateTimeImmutable('2026-12-31T00:00:00+09:00'))['id'] === 'year-cross', 'event across year');
    shiki_auto_test_assert(shiki_active_event(new DateTimeImmutable('2027-01-11T00:00:00+09:00')) === [], 'event automatic return');
    $sanitized = shiki_validate_settings(['season_events' => [['label' => '<script>x</script>', 'start' => '02-31', 'end' => '03-01', 'enabled' => 1]]], 'automation');
    shiki_auto_test_assert($sanitized['season_events'] === [], 'invalid calendar rule rejected');
    $malformed = shiki_validate_settings(['season_fixed' => ['array'], 'timezone' => ['array']], 'design'); shiki_auto_test_assert($malformed['season_fixed'] === 'autumn' && $malformed['timezone'] === 'Asia/Tokyo', 'nested malformed design values safe');
    $malformed = shiki_validate_settings(['season_events' => [['id' => ['array'], 'label' => 'TEST', 'start' => '01-01', 'end' => '01-02', 'accent' => ['array']]], 'view_count_consent_required' => false], 'automation'); shiki_auto_test_assert($malformed['season_events'][0]['accent'] === '#805a44' && $malformed['view_count_consent_required'], 'nested malformed event and missing consent acknowledgement safe');
    $before_count = wp_count_terms(['taxonomy' => 'category', 'hide_empty' => false]); shiki_setup_categories(); $count_once = wp_count_terms(['taxonomy' => 'category', 'hide_empty' => false]); shiki_setup_categories();
    shiki_auto_test_assert(wp_count_terms(['taxonomy' => 'category', 'hide_empty' => false]) === $count_once && $count_once >= $before_count, 'category setup idempotency');
    $spring_existing = get_term_by('slug', 'spring', 'category');
    if ($spring_existing && $spring_existing->name === '春の暮らし') {
        $settings_after_setup = get_option('shikipress_settings'); $renamed_terms[$spring_existing->term_id] = $spring_existing->name;
        wp_update_term($spring_existing->term_id, 'category', ['name' => 'TEST conflicting existing spring']);
        $conflict_settings = $settings_after_setup; unset($conflict_settings['season_terms']['spring']); update_option('shikipress_settings', $conflict_settings);
        $conflict_report = shiki_setup_categories();
        shiki_auto_test_assert(!isset(shiki_season_terms()['spring']) && get_term($spring_existing->term_id)->name === 'TEST conflicting existing spring' && str_contains($conflict_report['spring'], '衝突'), 'existing category collision requires mapping and never renames');
        wp_update_term($spring_existing->term_id, 'category', ['name' => $spring_existing->name]); unset($renamed_terms[$spring_existing->term_id]); update_option('shikipress_settings', $settings_after_setup);
    }
    $mapping = [];
    foreach (shiki_seasons() as $season => $label) { $term = wp_insert_term('TEST automation ' . $season . ' ' . wp_generate_password(6, false), 'category'); if (is_wp_error($term)) { throw new RuntimeException('term create'); } $term_ids[] = $term['term_id']; $mapping[$season] = $term['term_id']; }
    $settings = get_option('shikipress_settings'); $settings['season_terms'] = $mapping; $settings['featured_mode'] = 'auto'; $settings['view_count_enabled'] = false; update_option('shikipress_settings', $settings);
    $multi = shiki_auto_test_post(['post_title' => 'TEST 春の暮らしと冬の結露']); $result = shiki_classify_post($multi);
    shiki_auto_test_assert(in_array('spring', $result['candidates'], true) && in_array('winter', $result['candidates'], true), 'multi-season reasons');
    $all_year = shiki_auto_test_post(['post_title' => 'TEST 一年中の春の暮らし']); shiki_auto_test_assert(shiki_classify_post($all_year)['candidates'] === [], 'all-year no forced category');
    $unknown = shiki_auto_test_post(['post_title' => 'TEST 本の選び方']); shiki_auto_test_assert(shiki_classify_post($unknown)['candidates'] === [], 'unknown no forced category');
    $settings['classification_mode'] = 'auto'; $settings['slug_mode'] = 'auto'; update_option('shikipress_settings', $settings);
    wp_update_post(['ID' => $unknown, 'post_title' => '冬の結露を防ぐ方法']); shiki_auto_test_assert(!in_array($mapping['winter'], wp_get_post_categories($unknown), true) && !get_post($unknown)->post_name, 'existing draft untouched after automation enabled');
    $new = shiki_auto_test_post(['post_title' => '冬の結露を防ぐ方法']); shiki_auto_test_assert(in_array($mapping['winter'], wp_get_post_categories($new), true), 'new draft auto category'); shiki_auto_test_assert(get_post($new)->post_name === 'prevent-window-condensation', 'new draft auto English slug');
    $manual = shiki_auto_test_post(['post_title' => '冬の結露を防ぐ方法', 'post_name' => 'test-manual-url']); shiki_auto_test_assert(get_post($manual)->post_name === 'test-manual-url', 'manual draft slug preserved');
    $locked = shiki_auto_test_post(['post_title' => '冬の結露を防ぐ方法', 'meta_input' => ['_shiki_classification_lock' => 1, '_shiki_slug_lock' => 1, '_shiki_automation_test' => 1]]); shiki_auto_test_assert(!in_array($mapping['winter'], wp_get_post_categories($locked), true), 'classification lock preserves choice'); shiki_auto_test_assert(get_post($locked)->post_name !== 'prevent-window-condensation', 'slug lock preserved');
    shiki_auto_test_assert(shiki_slug_candidate('冬の結露を防ぐ方法')['slug'] === 'prevent-window-condensation', 'dictionary known phrase');
    shiki_auto_test_assert(shiki_slug_candidate('未知の文章を生成しました')['slug'] === '', 'dictionary unknown remains unknown');
    shiki_auto_test_assert(shiki_slug_candidate('秋の大掃除のコツ')['slug'] === 'autumn-cleaning-tips', 'dictionary autumn phrase');
    shiki_auto_test_assert(shiki_clean_english_slug('FEED') === 'feed-guide', 'reserved word');
    shiki_auto_test_assert(strlen(shiki_clean_english_slug(str_repeat('hello ', 50))) <= 70, 'slug length bound');
    $same = shiki_auto_test_post(['post_title' => '冬の結露を防ぐ方法']); $adopted = shiki_adopt_slug($same, 'prevent-window-condensation', true); shiki_auto_test_assert($adopted !== 'prevent-window-condensation', 'draft candidate uniqueness');
    wp_update_post(['ID' => $new, 'post_status' => 'publish']); $public_slug = get_post($new)->post_name; wp_update_post(['ID' => $new, 'post_title' => 'TEST altered']); shiki_auto_test_assert(get_post($new)->post_name === $public_slug && is_wp_error(shiki_adopt_slug($new, 'not-allowed', true)), 'published URL protected');
    wp_update_post(['ID' => $new, 'post_status' => 'draft']); shiki_auto_test_assert(!shiki_slug_editable($new), 'previously published URL protected on draft');
    $settings = get_option('shikipress_settings'); $settings['classification_mode'] = 'suggest'; $settings['slug_mode'] = 'suggest'; $settings['season_mode'] = 'fixed'; $settings['season_fixed'] = 'spring'; update_option('shikipress_settings', $settings);
    $visible = shiki_auto_test_post(['post_title' => 'TEST 春の公開記事', 'post_status' => 'publish', 'post_category' => [$mapping['spring']]]);
    $related = shiki_auto_test_post(['post_title' => 'TEST 春の関連記事', 'post_status' => 'publish', 'post_category' => [$mapping['spring']]]);
    $private = shiki_auto_test_post(['post_title' => 'TEST 春の非公開', 'post_status' => 'private', 'post_category' => [$mapping['spring']]]);
    $password = shiki_auto_test_post(['post_title' => 'TEST 春の保護', 'post_status' => 'publish', 'post_password' => 'test-only', 'post_category' => [$mapping['spring']]]);
    $future = shiki_auto_test_post(['post_title' => 'TEST 予約', 'post_status' => 'future', 'post_date' => '2035-01-01 12:00:00', 'post_category' => [$mapping['spring']]]);
    $excluded = shiki_auto_test_post(['post_title' => 'TEST 春の除外', 'post_status' => 'publish', 'post_category' => [$mapping['spring']], 'meta_input' => ['_shiki_exclude' => 1, '_shiki_automation_test' => 1]]);
    $featured = wp_list_pluck(shiki_select_posts('featured', [], 12), 'ID');
    shiki_auto_test_assert(in_array($visible, $featured, true) && !array_intersect([$private, $password, $future, $excluded], $featured), 'selection visibility');
    $related_ids = wp_list_pluck(shiki_select_posts('related', [], 3, $visible), 'ID'); shiki_auto_test_assert(in_array($related, $related_ids, true) && !in_array($visible, $related_ids, true), 'related current post excluded');
    $settings['featured_mode'] = 'manual'; $settings['featured_ids'] = [$private, $visible, $password]; update_option('shikipress_settings', $settings); shiki_auto_test_assert(wp_list_pluck(shiki_select_posts('featured', [], 3), 'ID') === [$visible], 'manual selection protection'); shiki_auto_test_assert(shiki_select_posts('featured', [$visible], 3) === [], 'manual inclusion respects cross-section exclusions');
    shiki_auto_test_assert(shiki_select_posts('popular') === [], 'no invented popularity');
    $settings['deepl_enabled'] = true; $settings['deepl_plan'] = 'free'; update_option('shikipress_settings', $settings);
    if (!defined('SHIKIPRESS_DEEPL_KEY')) { define('SHIKIPRESS_DEEPL_KEY', 'mock-fixture-key'); }
    $captured = []; $mock = static function ($pre, $args, $url) use (&$captured) { $captured = ['url' => $url, 'args' => $args]; return ['headers' => [], 'response' => ['code' => 200, 'message' => 'OK'], 'body' => '{"translations":[{"text":"Prevent window condensation"}]}', 'cookies' => []]; };
    add_filter('pre_http_request', $mock, 10, 3); $translated = shiki_deepl_request('冬の結露を防ぐ方法'); remove_filter('pre_http_request', $mock, 10);
    shiki_auto_test_assert($translated === 'prevent-window-condensation' && $captured['url'] === 'https://api-free.deepl.com/v2/translate' && $captured['args']['timeout'] === 5 && $captured['args']['redirection'] === 0, 'DeepL mocked official request');
    $payload = json_decode($captured['args']['body'], true); shiki_auto_test_assert($payload === ['text' => ['冬の結露を防ぐ方法'], 'source_lang' => 'JA', 'target_lang' => 'EN-US'], 'DeepL title-only payload');
    $failure = static fn() => ['headers' => [], 'response' => ['code' => 429, 'message' => 'Rate limited'], 'body' => '{}', 'cookies' => []]; add_filter('pre_http_request', $failure, 10, 3);
    $token = 'automation-test-token'; $scheduled[] = [$same, $token]; update_post_meta($same, '_shiki_translation', ['state' => 'queued', 'title_hash' => hash('sha256', get_post($same)->post_title), 'token' => $token, 'attempts' => 0, 'created' => time()]);
    for ($attempt = 0; $attempt < 3; $attempt++) { do_action('shiki_translate_job', $same, $token); }
    remove_filter('pre_http_request', $failure, 10); $job = get_post_meta($same, '_shiki_translation', true); shiki_auto_test_assert($job['state'] === 'error' && $job['attempts'] === 3, 'DeepL retry limit'); do_action('shiki_translate_job', $same, $token); shiki_auto_test_assert(get_post_meta($same, '_shiki_translation', true)['attempts'] === 3, 'completed exhausted job cannot issue another request');
    $settings['view_count_enabled'] = true; $settings['view_count_consent_required'] = true; update_option('shikipress_settings', $settings); wp_set_current_user(0); $blocked_request = new WP_REST_Request('POST', '/shikipress/v1/view'); $blocked_request->set_param('post_id', $visible); $blocked_request->set_header('user-agent', 'Mozilla/5.0 Test Browser'); shiki_auto_test_assert(rest_do_request($blocked_request)->get_data()['counted'] === false, 'server measurement gate without CMP confirmation'); wp_set_current_user(1);
    $settings['view_count_consent_required'] = false; $settings['view_count_consent_ack'] = true; update_option('shikipress_settings', $settings); shiki_install_views_table(); wp_set_current_user(0);
    $request = new WP_REST_Request('POST', '/shikipress/v1/view'); $request->set_param('post_id', $visible); $request->set_header('user-agent', 'Mozilla/5.0 Test Browser'); $request->set_header('origin', home_url()); $response = rest_do_request($request); shiki_auto_test_assert($response->get_status() === 200 && $response->get_data()['counted'], 'opt-in anonymous view aggregation');
    delete_transient('shiki_popular_30'); shiki_auto_test_assert((shiki_popular_counts()[$visible] ?? 0) >= 1, 'popular from measured aggregate');
    $request->set_header('origin', 'https://unrelated.example'); shiki_auto_test_assert(rest_do_request($request)->get_status() === 403, 'view cross-origin rejected');
    wp_set_current_user(1);
    shiki_schedule_automation(); $first_cron = wp_next_scheduled('shiki_season_tick'); shiki_schedule_automation(); shiki_auto_test_assert($first_cron === wp_next_scheduled('shiki_season_tick'), 'cron does not multiply');
    wp_delete_term($mapping['winter'], 'category'); shiki_schedule_automation(); shiki_auto_test_assert(!isset(shiki_season_terms()['winter']) && !term_exists($mapping['winter'], 'category'), 'deleted mapped category not silently recreated');
    $owner_token = shiki_acquire_job_lock('shiki_test_lock', 5); shiki_auto_test_assert($owner_token && !shiki_acquire_job_lock('shiki_test_lock', 5), 'atomic job lock'); shiki_release_job_lock('shiki_test_lock', 'wrong-owner'); shiki_auto_test_assert(!shiki_acquire_job_lock('shiki_test_lock', 5), 'foreign owner cannot release lock'); shiki_release_job_lock('shiki_test_lock', $owner_token); shiki_auto_test_assert((bool) shiki_acquire_job_lock('shiki_test_lock', 5), 'owner releases lock'); delete_option('shiki_test_lock');
} catch (Throwable $error) { $results[] = ['error' => $error->getMessage()]; }
finally {
    wp_set_current_user(1);
    foreach ($scheduled as $args) { wp_clear_scheduled_hook('shiki_translate_job', $args); }
    global $wpdb; if (get_option('shikipress_views_schema') === '1' && $post_ids) { $table = shiki_views_table(); $ids = implode(',', array_map('absint', $post_ids)); $wpdb->query("DELETE FROM $table WHERE post_id IN ($ids)"); }
    foreach ($post_ids as $id) { wp_delete_post($id, true); }
    foreach ($term_ids as $id) { wp_delete_term($id, 'category'); }
    foreach ($renamed_terms as $id => $name) { wp_update_term($id, 'category', ['name' => $name]); }
    update_option('shikipress_settings', $settings_before); shiki_invalidate_cache();
}
WP_CLI::log(wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
foreach ($results as $result) { if (isset($result['error']) || (isset($result['passed']) && !$result['passed'])) { WP_CLI::error('Automation integration failed.'); } }
WP_CLI::success(count($results) . ' automation checks passed. External DeepL calls were mocked.');
