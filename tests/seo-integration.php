<?php
/** Run: scripts/wp.sh eval-file tests/seo-integration.php (dedicated dev DB only). */
if (!defined('ABSPATH') || !defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Dedicated test WordPress required'); }
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
$GLOBALS['shiki_seo_test_assertions'] = 0;
function shiki_seo_test_assert($ok, $message) {
    ++$GLOBALS['shiki_seo_test_assertions'];
    if (!$ok) { throw new RuntimeException('SEO assertion failed: ' . $message); }
}
$created = [];
$created_terms = [];
$original_settings = get_option('shikipress_settings', []);
$original_public = get_option('blog_public');
$original_permalink = get_option('permalink_structure');
$original_show = get_option('show_on_front');
$old_user = get_current_user_id();
wp_set_current_user(1);
try {
    update_option('blog_public', 1);
    update_option('show_on_front', 'posts');
    update_option('shikipress_settings', array_merge($original_settings, ['page_exclude'=>[], 'category_exclude'=>[], 'seo_noindex_tags'=>false, 'seo_sitemap_per_page'=>10]));
    $term = wp_insert_term('TEST SEO isolated season ' . wp_generate_password(8, false), 'category');
    $term_id = (int) $term['term_id']; $created_terms[] = $term_id;
    $make = function ($title, $status = 'publish', $password = '', $type = 'post') use (&$created, $term_id) {
        $id = wp_insert_post(['post_type'=>$type,'post_title'=>'TEST SEO ' . $title,'post_status'=>$status,'post_password'=>$password,'post_content'=>'<h2>TEST SEO visible heading</h2><p>Public first page description.</p><!--nextpage--><p>Second-page-only description.</p>'], true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $created[] = $id;
        if ('post' === $type) { wp_set_post_categories($id, [$term_id]); }
        return $id;
    };
    $public = $make('public');
    $draft = $make('draft', 'draft');
    $private = $make('private', 'private');
    $future = wp_insert_post(['post_title'=>'TEST SEO scheduled secret','post_status'=>'future','post_date'=>gmdate('Y-m-d H:i:s', time()+86400),'post_date_gmt'=>gmdate('Y-m-d H:i:s', time()+86400),'post_content'=>'SECRET FUTURE CONTENT']); $created[]=$future;
    $protected = $make('protected', 'publish', 'test-protected');
    $excluded = $make('excluded'); update_post_meta($excluded, '_shiki_exclude', '1');
    $noindex = $make('noindex'); update_post_meta($noindex, '_shiki_noindex', '1');
    $page = $make('public page', 'publish', '', 'page');
    $excluded_page = $make('excluded page', 'publish', '', 'page');
    update_option('shikipress_settings', array_merge(get_option('shikipress_settings'), ['page_exclude'=>[$excluded_page]]));
    shiki_invalidate_cache();
    $post_query = new WP_Query(shiki_seo_map_args(['post_type'=>'post','posts_per_page'=>-1,'fields'=>'ids'], 'post'));
    shiki_seo_test_assert(in_array($public, $post_query->posts, true), 'public post listed');
    foreach ([$draft,$private,$future,$protected,$excluded,$noindex] as $hidden) { shiki_seo_test_assert(!in_array($hidden, $post_query->posts, true), 'hidden post absent: ' . $hidden); }
    $page_query = new WP_Query(shiki_seo_map_args(['post_type'=>'page','posts_per_page'=>-1,'fields'=>'ids'], 'page'));
    shiki_seo_test_assert(in_array($page, $page_query->posts, true) && !in_array($excluded_page, $page_query->posts, true), 'page exclusions honored');
    // CLI boots with the original private-site setting; use the native provider directly after the test opts in.
    $native_posts = new WP_Sitemaps_Posts();
    $xml_posts = $native_posts->get_url_list(1, 'post');
    $xml_locations = array_column($xml_posts, 'loc');
    shiki_seo_test_assert(in_array(get_permalink($public), $xml_locations, true), 'core XML contains published post');
    foreach ([$draft,$private,$future,$protected,$excluded,$noindex] as $hidden) { shiki_seo_test_assert(!in_array(get_permalink($hidden), $xml_locations, true), 'core XML excludes hidden: ' . $hidden); }
    shiki_seo_test_assert(in_array($term_id, shiki_seo_public_term_ids('category'), true), 'public category included');
    update_term_meta($term_id, '_shiki_noindex', '1');
    shiki_seo_test_assert(!in_array($term_id, shiki_seo_public_term_ids('category'), true), 'term noindex excluded');
    update_term_meta($term_id, '_shiki_noindex', '0');
    update_post_meta($public, '_shiki_seo_title', 'TEST SEO <script>bad</script> custom');
    update_post_meta($public, '_shiki_seo_description', 'A real description with "quotes" & detail.');
    query_posts(['p'=>$public, 'post_type'=>'post']);
    ob_start(); wp_head(); $head=ob_get_clean();
    shiki_seo_test_assert(1 === substr_count($head, 'rel="canonical"'), 'one canonical');
    shiki_seo_test_assert(1 === substr_count($head, '<title>'), 'one title');
    shiki_seo_test_assert(1 === substr_count($head, 'name="description"'), 'one description');
    shiki_seo_test_assert(1 === substr_count($head, 'property="og:title"'), 'one OG title');
    shiki_seo_test_assert(!str_contains($head, '<script>bad</script>'), 'custom title safely escaped');
    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $head, $matches);
    $schema=json_decode($matches[1] ?? '', true);
    shiki_seo_test_assert(is_array($schema) && isset($schema['@graph']), 'valid JSON-LD');
    $types=array_column($schema['@graph'], '@type');
    shiki_seo_test_assert(in_array('BlogPosting', $types, true), 'real post article schema');
    $robots = apply_filters('wp_robots', []);
    shiki_seo_test_assert(empty($robots['noindex']), 'public post indexable');
    query_posts(['p'=>$noindex, 'post_type'=>'post']);
    shiki_seo_test_assert(!empty(apply_filters('wp_robots', [])['noindex']), 'per-post noindex output');
    query_posts(['p'=>$protected, 'post_type'=>'post']);
    shiki_seo_test_assert(shiki_seo_noindex(), 'password protected always noindex');
    shiki_seo_test_assert(!str_contains(shiki_seo_description(), 'Public first page'), 'protected excerpt not disclosed');
    update_option('permalink_structure', '/%postname%/');
    query_posts(['p'=>$public, 'post_type'=>'post', 'page'=>2]);
    shiki_seo_test_assert(str_ends_with(shiki_seo_canonical(), '/2/'), 'pretty multipage canonical is page two');
    delete_post_meta($public, '_shiki_seo_description');
    shiki_seo_test_assert(str_contains(shiki_seo_description(), 'Second-page-only'), 'multipage description matches slice');
    update_option('permalink_structure', '');
    shiki_seo_test_assert(str_contains(shiki_seo_canonical(), 'page=2'), 'plain multipage canonical is page two');
    shiki_seo_test_assert(str_contains(shiki_seo_paged_url(home_url('/?cat=' . $term_id), 3), 'paged=3'), 'plain archive pagination canonical');
    update_option('permalink_structure', '/%postname%/');
    shiki_seo_test_assert(str_ends_with(shiki_seo_paged_url(get_category_link($term_id), 3), '/page/3/'), 'pretty archive pagination canonical');
    query_posts(['s'=>'TEST SEO']);
    shiki_seo_test_assert(shiki_seo_noindex(), 'search noindex');
    query_posts(['error'=>'404']);
    shiki_seo_test_assert(shiki_seo_noindex() && '' === shiki_seo_canonical(), '404 noindex and no canonical');
    update_option('blog_public', 0);
    shiki_seo_test_assert(!apply_filters('wp_sitemaps_enabled', true), 'private site disables XML');
    shiki_seo_test_assert(shiki_seo_noindex(), 'private site noindex');
    update_option('blog_public', 1);
    $html=shiki_html_sitemap();
    foreach ([$protected,$excluded,$noindex,$excluded_page] as $hidden) { shiki_seo_test_assert(!str_contains($html, esc_html(get_the_title($hidden))), 'HTML map excludes hidden ' . $hidden); }
    // Reader map really paginates both collections; query variables remain independent.
    for ($i=0; $i<12; $i++) { $make('pagination post ' . $i); $make('pagination page ' . $i, 'publish', '', 'page'); }
    shiki_invalidate_cache();
    $_GET=[]; $first_map=shiki_html_sitemap();
    shiki_seo_test_assert(str_contains($first_map, 'shiki_map_posts=2') && str_contains($first_map, 'shiki_map_pages=2'), 'HTML post/page pagination links');
    $_GET=['shiki_map_posts'=>2,'shiki_map_pages'=>2]; $second_map=shiki_html_sitemap();
    shiki_seo_test_assert(str_contains($second_map, 'aria-current="page"') && $first_map !== $second_map, 'HTML second slices rendered');
    $_GET=[];
    if (function_exists('shiki_stories_url')) {
        query_posts(['shiki_stories'=>1,'paged'=>2,'posts_per_page'=>10]);
        shiki_seo_test_assert(shiki_seo_canonical() === shiki_stories_url(2), 'all-stories second page canonical');
        shiki_seo_test_assert(str_contains(shiki_seo_text(wp_get_document_title()), '記事一覧') && str_contains(shiki_seo_text(wp_get_document_title()), '2ページ目'), 'all-stories page title');
        $crumbs = shiki_breadcrumb_items();
        shiki_seo_test_assert(str_contains(end($crumbs)['name'], '記事一覧') && str_contains(end($crumbs)['name'], '2ページ目'), 'all-stories breadcrumbs');
    }
    $current = get_option('shikipress_settings');
    $safe = apply_filters('shiki_sanitize_settings', $current, ['seo_google_verification'=>'<script>alert(1)</script>','seo_sitemap_per_page'=>10000], 'seo');
    shiki_seo_test_assert(!str_contains($safe['seo_google_verification'], '<') && 100 === $safe['seo_sitemap_per_page'], 'settings token and bound sanitation');
    $image=shiki_seo_image();
    shiki_seo_test_assert(1200 === $image['width'] && 630 === $image['height'] && str_ends_with($image['url'], '.png'), 'crawler compatible fallback');
    $denied = $make('nonce deny');
    $_POST=['_shiki_noindex'=>'1'];
    do_action('save_post', $denied, get_post($denied), true);
    shiki_seo_test_assert('' === get_post_meta($denied, '_shiki_noindex', true), 'missing nonce rejected');
    $_POST=['shiki_seo_nonce'=>wp_create_nonce('shiki_save_seo'),'_shiki_noindex'=>'1'];
    wp_set_current_user(0); do_action('save_post', $denied, get_post($denied), true);
    shiki_seo_test_assert('' === get_post_meta($denied, '_shiki_noindex', true), 'low permission rejected');
    wp_set_current_user(1); $_POST=[];
    WP_CLI::success('SEO integration: ' . $GLOBALS['shiki_seo_test_assertions'] . ' assertions passed.');
} finally {
    $_POST=[]; $_GET=[];
    wp_reset_query();
    foreach ($created as $id) { wp_delete_post($id, true); }
    foreach ($created_terms as $id) { wp_delete_term($id, 'category'); }
    update_option('shikipress_settings', $original_settings);
    update_option('blog_public', $original_public);
    update_option('permalink_structure', $original_permalink);
    update_option('show_on_front', $original_show);
    wp_set_current_user($old_user);
    shiki_invalidate_cache();
}
