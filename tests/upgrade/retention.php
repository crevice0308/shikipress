<?php
/** Disposable WordPress regression fixture; NEVER load on a real website. */
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || !defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('A marked, disposable local WordPress installation is required.');
}
$phase = $shiki_retention_phase ?? ($args[0] ?? 'assert');
// Compare private permalinks under one stable author context in every phase.
wp_set_current_user(1);
function shiki_ci_ok($value) {
    if (is_wp_error($value)) { throw new RuntimeException($value->get_error_message()); }
    if (!$value) { throw new RuntimeException('Fixture creation returned an empty result.'); }
    return $value;
}
function shiki_ci_snapshot($fixture) {
    global $wpdb;
    $posts = $meta = $terms = $urls = [];
    foreach ($fixture['posts'] as $id) {
        $posts[$id] = get_post($id, ARRAY_A);
        $meta[$id] = get_post_meta($id);
        $terms[$id] = wp_get_object_terms($id, ['category','post_tag'], ['orderby'=>'term_id','fields'=>'all']);
        $urls[$id] = get_permalink($id);
    }
    $term_ids = array_map('intval', $fixture['terms']);
    $category_rows = [];
    foreach ($term_ids as $id) { $category_rows[$id] = (array)get_term($id); }
    $files = [];
    foreach ($fixture['media'] as $id) { $file = get_attached_file($id); $files[$id] = ['path'=>$file,'sha256'=>is_file($file)?hash_file('sha256',$file):null,'metadata'=>wp_get_attachment_metadata($id)]; }
    $comments = [];
    foreach ($fixture['comments'] as $id) { $comments[$id] = (array)get_comment($id); }
    // Populate WordPress's lazy custom-CSS lookup before reading theme mods.
    wp_get_custom_css_post('shikipress');
    $options = [];
    foreach (['shikipress_settings','theme_mods_shikipress','permalink_structure','blogname','blogdescription','timezone_string','sidebars_widgets','widget_text','sticky_posts','show_on_front','page_on_front','page_for_posts'] as $key) { $options[$key] = get_option($key); }
    // Core records a timestamped sidebar backup on theme switches. Check actual placements separately.
    unset($options['theme_mods_shikipress']['sidebars_widgets']);
    unset($options['sidebars_widgets']['wp_inactive_widgets']);
    return ['posts'=>$posts,'postmeta'=>$meta,'term_relationships'=>$terms,'category_identity'=>$category_rows,'urls'=>$urls,'media'=>$files,'comments'=>$comments,'options'=>$options];
}
if (str_starts_with($phase, 'seed')) {
    if (get_option('shiki_ci_retention_fixture')) { throw new RuntimeException('Fixture already exists; refusing to alter it.'); }
    if (get_stylesheet() !== 'shikipress') { throw new RuntimeException('Activate shikipress before seeding.'); }
    wp_set_current_user(1);
    update_option('blogname', 'ShikiPress update retention');
    update_option('blogdescription', 'Synthetic disposable integration fixtures');
    update_option('timezone_string', 'Asia/Tokyo');
    update_option('permalink_structure', '/%postname%/');
    $settings = get_option('shikipress_settings', []);
    $settings['season_mode']='fixed'; $settings['season_fixed']='summer';
    $settings['classification_mode']='manual'; $settings['slug_mode']='manual';
    $settings['ci_custom_setting']='preserve-unknown-setting';
    update_option('shikipress_settings', $settings);
    shiki_setup_categories();
    $category = shiki_ci_ok(wp_insert_term('CI hand-made category','category',['slug'=>'ci-manual-category']));
    $tag = shiki_ci_ok(wp_insert_term('CI hand-made tag','post_tag',['slug'=>'ci-manual-tag']));
    $fixture=['posts'=>[],'media'=>[],'comments'=>[],'terms'=>array_merge(array_values(shiki_season_terms()),[$category['term_id'],$tag['term_id']])];
    for ($i=1;$i<=20;$i++) {
        $id=shiki_ci_ok(wp_insert_post(['post_title'=>'更新後も残る記事 '.$i,'post_name'=>'ci-retain-'.$i,'post_status'=>'publish','post_content'=>'<!-- wp:paragraph --><p>本文 '.$i.' を完全に保持する。</p><!-- /wp:paragraph --><h2>小見出し</h2><p>Retained article body.</p>','post_excerpt'=>'保存する抜粋 '.$i,'post_author'=>1],true));
        $fixture['posts'][]=$id;
        wp_set_post_categories($id,[$category['term_id'],array_values(shiki_season_terms())[$i%4]]);
        wp_set_post_tags($id,[$tag['term_id']]);
        update_post_meta($id,'_shiki_classification_lock','1'); update_post_meta($id,'_shiki_slug_lock','1');
        update_post_meta($id,'_shiki_seo_title','SEO title '.$i);update_post_meta($id,'_shiki_seo_description','SEO description '.$i);
        update_post_meta($id,'ci_custom_array',['number'=>$i,'text'=>'日本語','nested'=>['preserve'=>true]]);
    }
    foreach (['draft','private','future','trash'] as $status) {
        $post=['post_title'=>'CI '.$status,'post_name'=>'ci-'.$status,'post_status'=>$status,'post_content'=>'Keep '.$status,'post_author'=>1];
        if($status==='future'){$post['post_date']='2035-01-01 10:00:00';$post['post_date_gmt']='2035-01-01 01:00:00';}
        $fixture['posts'][]=shiki_ci_ok(wp_insert_post($post,true));
    }
    $fixture['posts'][]=shiki_ci_ok(wp_insert_post(['post_type'=>'page','post_title'=>'固定ページ','post_name'=>'ci-page','post_status'=>'publish','post_content'=>'Retained page content'],true));
    $fixture['posts'][]=shiki_ci_ok(wp_insert_post(['post_title'=>'Password protected','post_name'=>'ci-password','post_status'=>'publish','post_password'=>'fixture-only-password','post_content'=>'Synthetic protected content'],true));
    $first=$fixture['posts'][0];
    wp_save_post_revision($first);
    wp_update_post(['ID'=>$first,'post_content'=>'<h2>更新済み本文</h2><p>Latest content must survive.</p>']);
    foreach(wp_get_post_revisions($first) as $revision){$fixture['posts'][]=$revision->ID;}
    $upload=wp_upload_bits('ci-retention.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1sAAAAASUVORK5CYII='));
    if($upload['error']){throw new RuntimeException($upload['error']);}
    $attachment=shiki_ci_ok(wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>'Retention image','post_content'=>'Media description','post_excerpt'=>'Media caption','post_status'=>'inherit'],$upload['file'],$first,true));
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
    update_post_meta($attachment,'_wp_attachment_image_alt','保持する代替テキスト');
    set_post_thumbnail($first,$attachment); set_theme_mod('custom_logo',$attachment);
    $fixture['posts'][]=$attachment;$fixture['media'][]=$attachment;
    $fixture['comments'][]=shiki_ci_ok(wp_insert_comment(['comment_post_ID'=>$first,'comment_author'=>'Fixture Reader','comment_author_email'=>'fixture@example.invalid','comment_content'=>'Keep this synthetic comment','comment_approved'=>1]));
    $menu=shiki_ci_ok(wp_create_nav_menu('CI navigation'));
    $item=shiki_ci_ok(wp_update_nav_menu_item($menu,0,['menu-item-object-id'=>$first,'menu-item-object'=>'post','menu-item-type'=>'post_type','menu-item-status'=>'publish']));
    set_theme_mod('nav_menu_locations',['primary'=>$menu,'footer'=>$menu]);$fixture['posts'][]=$item;$fixture['terms'][]=$menu;
    update_option('widget_text',[2=>['title'=>'Retained widget','text'=>'Retained widget text','filter'=>false],'_multiwidget'=>1]);
    update_option('sidebars_widgets',['wp_inactive_widgets'=>[],'shiki-sidebar'=>['text-2'],'array_version'=>3]);
    stick_post($first);
    $custom_css=shiki_ci_ok(wp_update_custom_css_post('.ci-retained { color: #123456; }',['stylesheet'=>'shikipress']));
    $fixture['posts'][]=$custom_css->ID;
    sort($fixture['posts']); $fixture['posts']=array_values(array_unique($fixture['posts']));
    update_option('shiki_ci_retention_fixture',$fixture,false);
    update_option('shiki_ci_retention_snapshot',shiki_ci_snapshot($fixture),false);
}
$fixture=get_option('shiki_ci_retention_fixture');
$expected=get_option('shiki_ci_retention_snapshot');
if(!is_array($fixture)||!is_array($expected)){throw new RuntimeException('Missing baseline fixture.');}
$actual=shiki_ci_snapshot($fixture);$checks=[];
foreach($expected as $key=>$value){$checks[$key]=wp_json_encode($value)===wp_json_encode($actual[$key]);}
$checks['active_theme']=get_stylesheet()==='shikipress';
$checks['runtime_header_version']=SHIKI_VERSION===wp_get_theme()->get('Version');
$checks['stored_theme_version']=get_option('shikipress_version')===SHIKI_VERSION;
$checks['media_file_exists']=!in_array(null,array_column($actual['media'],'sha256'),true);
$checks['twenty_published_articles']=count(array_filter($fixture['posts'],static fn($id)=>get_post_status($id)==='publish'&&get_post_type($id)==='post'&&str_starts_with(get_post_field('post_name',$id),'ci-retain-')))===20;
$report=['phase'=>$phase,'wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'database_driver'=>get_class($GLOBALS['wpdb']),'theme'=>SHIKI_VERSION,'fixture_posts'=>count($fixture['posts']),'checks'=>$checks,'pass'=>!in_array(false,$checks,true)];
echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
if(!$report['pass']){ $diff=[]; foreach($checks as $key=>$ok){if(!$ok&&isset($expected[$key])){$diff[$key]=['expected'=>$expected[$key],'actual'=>$actual[$key]];}} file_put_contents(ABSPATH.'ci-retention-diff.json',wp_json_encode($diff,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); throw new RuntimeException('Retention mismatch: '.implode(', ',array_keys(array_filter($checks,static fn($ok)=>!$ok))));}
