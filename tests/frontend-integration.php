<?php
/** Dedicated isolated WordPress only. Restores every created fixture. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Dedicated test environment required'); }
$GLOBALS['results'] = []; $results = &$GLOBALS['results']; $created = []; $term_ids = []; $old_settings = get_option('shikipress_settings', []); $old_query = $GLOBALS['wp_query']; $old_main = $GLOBALS['wp_the_query']; $old_reading = get_option('show_on_front'); $old_front = get_option('page_on_front');
function shiki_front_assert($condition, $label) { global $results; $results[] = ['test' => $label, 'pass' => (bool) $condition]; if (!$condition) { throw new RuntimeException($label); } }
function shiki_front_menu_item($id, $object, $url, $type = 'post_type') { return (object) ['ID' => $id + 50000, 'object_id' => $id, 'object' => $object, 'url' => $url, 'type' => $type]; }
try {
    update_option('shikipress_settings', array_merge($old_settings, ['toc_enabled' => 1]));
    $markup = '<div id="shiki-heading-1"></div><h2 id=custom>見出しA</h2><h3 id="kept">小見出し</h3><h2 id="">空</h2><h2 id="dupe">同名</h2><h3 id=dupe>同名</h3><h2 data-id="data-only">別見出し</h2><h2>同名</h2>';
    $toc = shiki_toc($markup);
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $toc); $xpath = new DOMXPath($dom);
    $all_ids = []; foreach ($xpath->query('//*[@id]') as $node) { $all_ids[] = $node->getAttribute('id'); }
    shiki_front_assert(count($all_ids) === count(array_unique($all_ids)), 'TOC author/generated IDs all unique including repeated and empty attributes');
    shiki_front_assert(str_contains($toc, '<h2 id=custom>') && str_contains($toc, '<h3 id="kept">'), 'TOC preserves existing quoted and unquoted unique author IDs');
    shiki_front_assert(str_contains($toc, 'data-id="data-only"') && str_contains($toc, 'href="#shiki-heading-6"'), 'data-id is not confused with id');
    foreach ($xpath->query('//details//a') as $link) { $target = substr($link->getAttribute('href'), 1); $found = $xpath->query('//*[@id="' . $target . '"]'); shiki_front_assert($found->length === 1, 'TOC link resolves once: ' . $target); }
    $quoted = shiki_toc('<p>literal id=stable is prose</p><h2 id=stable title="a>b">Quoted attribute</h2><h3>Next</h3>');
    shiki_front_assert(str_contains($quoted, '<h2 id=stable title="a>b">') && str_contains($quoted, 'href="#stable"'), 'TOC preserves greater-than quoted attributes and ignores ID-like prose');
    $attribute_ids = shiki_toc('<h2 title="literal id=fake" id=real>Heading</h2><h3>Next</h3>');
    shiki_front_assert(str_contains($attribute_ids,'href="#real"') && !str_contains($attribute_ids,'href="#fake"'), 'Core HTML parser ignores ID-looking strings inside other attributes');
    $private = wp_insert_post(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'TEST frontend private']); $created[] = $private;
    $password = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST frontend password', 'post_password' => 'test-only']); $created[] = $password;
    $public = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST frontend public']); $created[] = $public;
    $excluded = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST frontend excluded']); $created[] = $excluded;
    $term = wp_insert_term('TEST frontend hidden category', 'category'); if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); } $term_id = (int) $term['term_id']; $term_ids[] = $term_id;
    $hidden_post = wp_insert_post(['post_status' => 'publish', 'post_title' => 'TEST hidden category post', 'post_category' => [$term_id]]); $created[] = $hidden_post;
    update_option('shikipress_settings', array_merge($old_settings, ['toc_enabled' => 1, 'page_exclude' => [$excluded], 'category_exclude' => [$term_id]]));
    $menus = [shiki_front_menu_item($public, 'page', get_permalink($public)), shiki_front_menu_item($private, 'page', get_permalink($private)), shiki_front_menu_item($password, 'custom', get_permalink($password), 'custom'), shiki_front_menu_item($excluded, 'custom', '?page_id=' . $excluded, 'custom'), shiki_front_menu_item($term_id, 'category', get_term_link($term_id)), shiki_front_menu_item($term_id, 'custom', '?cat=' . $term_id, 'custom'), shiki_front_menu_item($hidden_post, 'post', get_permalink($hidden_post)), shiki_front_menu_item(0, 'custom', 'https://example.org/', 'custom')];
    $filtered = array_values(apply_filters('wp_nav_menu_objects', $menus));
    shiki_front_assert(count($filtered) === 2 && (int) $filtered[0]->object_id === $public && $filtered[1]->url === 'https://example.org/', 'Menu filters native/custom/relative protected pages and excluded categories; preserves public and external');
    $terms = shiki_visible_categories(); shiki_front_assert(!in_array($term_id, array_map(fn($row) => (int) $row['term']->term_id, $terms), true), 'Excluded category absent from navigation counts');
    global $wpdb; for ($i=0; $i<4; $i++) { shiki_invalidate_cache(); shiki_visible_categories(); }
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '_transient_shiki_visible_terms'");
    shiki_front_assert($count === 1 && is_array(get_transient('shiki_visible_terms')['rows']), 'Category cache uses one fixed bounded transient across epochs');
    $story_ids = [];
    for ($i=0;$i<12;$i++) { $id=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST frontend story '.$i]); $created[]=$id; $story_ids[]=$id; }
    $page_size = max(1, (int) get_option('posts_per_page', 10));
    $seen=[];
    foreach (range(1, (int) ceil(12 / $page_size)) as $page) {
        $query = new WP_Query(); $GLOBALS['wp_the_query'] = $query; $GLOBALS['wp_query'] = $query;
        $query->query(['shiki_stories' => 1, 'paged' => $page, 'post__in' => $story_ids]);
        shiki_front_assert($query->is_archive() && !$query->is_singular() && !$query->is_front_page(), 'Stories route is distinct archive page ' . $page);
        $seen = array_merge($seen, wp_list_pluck($query->posts, 'ID'));
    }
    sort($seen); sort($story_ids); shiki_front_assert($seen === $story_ids, 'All stories reachable through real pagination with no duplicates');
    update_option('show_on_front', 'posts'); shiki_front_assert(str_contains(shiki_stories_url(2), 'shiki_stories=1') && str_contains(shiki_stories_url(2), 'paged=2'), 'All-story fallback URL keeps route and pagination');
    update_option('show_on_front','page'); update_option('page_on_front',$public);
    $query=new WP_Query();$GLOBALS['wp_the_query']=$query;$GLOBALS['wp_query']=$query;$query->query(['shiki_stories'=>1,'page_id'=>$public,'post__in'=>$story_ids]);
    shiki_front_assert(!$query->is_singular() && $query->get('page_id')===0 && $query->post_count>0, 'All-story route clears static homepage constraints');
    shiki_front_assert(isset(shiki_setting_sections()['hero_title']) && shiki_setting_sections()['hero_title']==='design', 'Design fields exported/restored under design section');
    for($i=0;$i<11;$i++) { $created[]=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'TEST frontend extra page '.$i]); }
    ob_start();shiki_page_links();$page_links=ob_get_clean();shiki_front_assert(str_contains($page_links,'additional-pages') && str_contains($page_links,'その他の固定ページ'), 'Many public pages use native accessible details disclosure');
    shiki_front_assert(!str_contains($page_links,'TEST frontend private') && !str_contains($page_links,'TEST frontend password') && !str_contains($page_links,'TEST frontend excluded'), 'Expanded page groups keep all private/password/excluded pages out');
    $hidden_settings=shiki_validate_settings(['hero_title'=>'<script>x</script>見出し','toc_enabled'=>1],'design',$old_settings); shiki_front_assert(!str_contains($hidden_settings['hero_title'],'<'), 'Hero title sanitizes markup');
} catch (Throwable $error) { $results[]=['error'=>$error->getMessage(),'pass'=>false]; }
finally { update_option('shikipress_settings',$old_settings);update_option('show_on_front',$old_reading);update_option('page_on_front',$old_front); foreach($created as $id){wp_delete_post($id,true);}foreach($term_ids as $id){wp_delete_term($id,'category');}$GLOBALS['wp_query']=$old_query;$GLOBALS['wp_the_query']=$old_main;shiki_invalidate_cache(); }
file_put_contents(__DIR__.'/results/frontend-integration.json',wp_json_encode($results, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo wp_json_encode($results, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
foreach($results as $row){if(empty($row['pass'])){WP_CLI::error('Frontend integration failed');}}
WP_CLI::success('Frontend integration passed: '.count($results));
