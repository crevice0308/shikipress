<?php
/** wp eval-file scripts/fixtures.php <0|1|5|20|100|500> -- development only. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || wp_get_environment_type() !== 'local' || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('127.0.0.1', 'localhost'), true)) {
    WP_CLI::error('Fixtures require the isolated loopback local development installation.');
}
$count = isset($args[0]) ? (int) $args[0] : 20;
if (!in_array($count, array(0, 1, 5, 20, 100, 500), true)) {
    WP_CLI::error('Supported fixture counts: 0, 1, 5, 20, 100, 500.');
}
$unmarked = get_posts(array('post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1, 'meta_query' => array(array('key' => '_shiki_fixture', 'compare' => 'NOT EXISTS'))));
if ($unmarked) {
    WP_CLI::error('Unmarked posts exist. Refusing to touch unrelated content; create a fresh dedicated installation.');
}
foreach (get_posts(array('post_type' => array('post', 'page'), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_shiki_fixture', 'meta_value' => '1', 'fields' => 'ids')) as $id) {
    wp_delete_post($id, true);
}
if (function_exists('shiki_setup_categories')) {
    shiki_setup_categories();
}
$terms = function_exists('shiki_season_terms') ? shiki_season_terms() : array();
$labels = array('spring' => '春の暮らし', 'summer' => '夏の暮らし', 'autumn' => '秋の暮らし', 'winter' => '冬の暮らし');
foreach ($labels as $season => $label) {
    if (empty($terms[$season])) {
        $term = term_exists($season, 'category');
        if (!$term) { $term = wp_insert_term($label, 'category', array('slug' => $season)); }
        if (is_wp_error($term)) { WP_CLI::error($term->get_error_message()); }
        $terms[$season] = (int) $term['term_id'];
    }
}
$settings = get_option('shikipress_settings', array());
$settings['season_terms'] = $terms;
update_option('shikipress_settings', $settings);
$titles = array(
    'spring' => array('桜の季節に整える、春の部屋', '春の衣替えを心地よく', '花粉の日も快適に暮らす', '新生活の小さな習慣', '春の台所と旬の野菜'),
    'summer' => array('夏の朝を涼しく過ごす', '梅雨のカビ対策', '暑い日の水分補給', '夏の窓辺と日差し', '新緑を楽しむ暮らし'),
    'autumn' => array('秋の大掃除のコツ', '紅葉を楽しむ小さな散歩', '秋の夜長と読書時間', '衣替えと収納の見直し', '秋の旬を食卓へ'),
    'winter' => array('冬の結露を防ぐ方法', '冬の暖房と乾燥対策', '年末の暮らしを整える', '雪の日の室内時間', '冬の温かい台所'),
);
$seasons = array_keys($labels);
$ids = array();
$table = '<figure class="wp-block-table"><table><thead><tr><th>TEST 長い項目名</th><th>内容</th><th>補足</th></tr></thead><tbody><tr><td>' . str_repeat('横長の検証データ ', 25) . '</td><td>セル</td><td>テスト用</td></tr></tbody></table></figure>';
wp_defer_term_counting(true);
for ($i = 0; $i < $count; ++$i) {
    $season = $seasons[$i % 4];
    $title = $titles[$season][(int) floor($i / 4) % 5];
    if ($i === 4) { $title .= ' 🌸🍃 ' . str_repeat('長い記事タイトルで表示を確認します ', 5); }
    if ($i === 3) { $title = '冬'; }
    $body = '<!-- wp:paragraph --><p>TEST ONLY — 表示と機能を確認するための架空の検証用記事です。本番記事として使用しないでください。</p><!-- /wp:paragraph -->';
    $body .= '<!-- wp:heading --><h2 class="wp-block-heading">暮らしを整える</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . esc_html($title) . '。四季に合わせた余白と読みやすさを確かめます。</p><!-- /wp:paragraph -->';
    $body .= '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">小さな習慣</h3><!-- /wp:heading --><p>春夏秋冬のテスト用の文章です。</p><h2>暮らしを整える</h2><h3 id="existing-anchor">既存の見出しID</h3><blockquote><p>TEST 引用ブロックの表示を確認します。</p><cite>検証用テキスト</cite></blockquote><ul><li>テスト項目1</li><li>テスト項目2</li></ul><pre class="wp-block-code"><code>' . str_repeat('sample_code_long_line ', 20) . '</code></pre>';
    if ($i === 0) { $body .= $table; }
    $body .= '<p><a href="' . esc_url(home_url('/')) . '">ホームへ戻る</a></p>';
    $date = gmdate('Y-m-d H:i:s', time() - ($count - $i) * HOUR_IN_SECONDS);
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'TEST ' . $title . ' ' . ($i + 1), 'post_name' => 'test-' . $season . '-' . ($i + 1), 'post_content' => $body, 'post_excerpt' => $i % 3 === 0 ? '' : 'TEST 四季の暮らしに寄り添う、検証用の記事です。', 'post_date_gmt' => $date, 'post_date' => get_date_from_gmt($date), 'post_category' => array((int) $terms[$season]), 'meta_input' => array('_shiki_fixture' => '1')), true);
    if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
    wp_set_post_tags($id, array('TEST', $labels[$season]));
    $ids[] = $id;
}
wp_defer_term_counting(false);
$hidden = array();
foreach (array('draft', 'private', 'future', 'password', 'excluded') as $kind) {
    $values = array('post_type' => 'post', 'post_title' => 'SECRET_TEST_' . strtoupper($kind), 'post_name' => 'secret-test-' . $kind, 'post_content' => '<p>SECRET_TEST_BODY_' . strtoupper($kind) . '</p>', 'post_status' => in_array($kind, array('password', 'excluded'), true) ? 'publish' : $kind, 'meta_input' => array('_shiki_fixture' => '1'));
    if ($kind === 'future') { $values['post_date'] = date('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS); $values['post_date_gmt'] = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS); }
    if ($kind === 'password') { $values['post_password'] = 'fixture-protected'; }
    if ($kind === 'excluded') { $values['meta_input']['_shiki_exclude'] = 1; }
    $hidden[$kind] = wp_insert_post($values);
}
$pages = array();
foreach (array('about' => 'このサイトについて', 'contact' => 'お問い合わせ', 'privacy' => 'プライバシーポリシー', 'disclosure' => '広告・運営方針', 'sitemap' => 'サイトマップ') as $slug => $label) {
    $pages[$slug] = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST ' . $label, 'post_name' => 'test-' . $slug, 'post_content' => $slug === 'sitemap' ? '[shiki_sitemap]' : '<h2>' . esc_html($label) . '</h2><p>TEST ONLY — 公開前に実際の内容を作成してください。</p>', 'meta_input' => array('_shiki_fixture' => '1')));
}
foreach (array('draft', 'private', 'password', 'excluded') as $kind) {
    $page = array('post_type' => 'page', 'post_title' => 'SECRET_TEST_PAGE_' . strtoupper($kind), 'post_name' => 'secret-test-page-' . $kind, 'post_content' => '<p>SECRET_TEST_PAGE_BODY</p>', 'post_status' => in_array($kind, array('password', 'excluded'), true) ? 'publish' : $kind, 'meta_input' => array('_shiki_fixture' => '1'));
    if ($kind === 'password') { $page['post_password'] = 'fixture-protected'; }
    if ($kind === 'excluded') { $page['meta_input']['_shiki_exclude'] = 1; }
    $hidden['page_' . $kind] = wp_insert_post($page);
}
if (function_exists('shiki_invalidate_cache')) { shiki_invalidate_cache(); }
update_option('shiki_fixture_state', array('count' => $count, 'post_ids' => $ids, 'hidden_ids' => $hidden, 'page_ids' => $pages, 'created_at_utc' => gmdate('c')));
WP_CLI::success('Created ' . $count . ' public TEST articles plus hidden coverage fixtures. No unmarked content was deleted.');
