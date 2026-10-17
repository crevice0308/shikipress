<?php
/** Run inside the isolated development WordPress via WP-CLI eval-file. */
if (!defined('WP_CLI') || !WP_CLI) { exit("WP-CLI required\n"); }
if (!defined('SHIKI_TEST_ENV') || true !== SHIKI_TEST_ENV) { WP_CLI::error('SHIKI_TEST_ENV=true is required for advertising mutation tests.'); }
$saved_settings = get_option('shikipress_settings', []);
$saved_user = get_current_user_id();
$created = []; $results = [];
$assert = function ($condition, $label) use (&$results) {
    $results[] = ['name' => $label, 'pass' => (bool) $condition];
    if (!$condition) { throw new RuntimeException($label); }
};
$slot = ['id'=>'ads-test-slot','name'=>'TEST枠','service'=>'a8','state'=>'test','position'=>'below','device'=>'all','post_types'=>['post'],'exclude_ids'=>[],'exclude_categories'=>[],'interval'=>2,'limit'=>3,'height'=>250,'dummy'=>'ready','publisher'=>'','ad_slot'=>'','code'=>'<a href="https://never-request.invalid/test"><img src="https://never-request.invalid/pixel.gif"></a><script>window.__SHIKI_TEST_CODE_EXECUTED=true;</script>','trusted'=>true,'vendors'=>[123],'purposes'=>[1,3,4],'shared_loaders'=>[]];
try {
    $admins = get_users(['role'=>'administrator','number'=>1]); wp_set_current_user($admins[0]->ID);
    $assert('test' === shiki_ads_defaults()['ads_mode'] && [] === shiki_ads_defaults()['ads_slots'], 'safe defaults: test and no registered slots');
    $parsed = shiki_ads_parse_adsense('<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456"></script><ins class="adsbygoogle" data-ad-client="ca-pub-1234567890123456" data-ad-slot="1234567890"></ins>');
    $assert('ca-pub-1234567890123456' === $parsed['publisher'] && '1234567890' === $parsed['ad_slot'], 'official display code import extracts IDs only');
    $assert('' === shiki_ads_pub('ca-pub-123<script>'), 'publisher format rejects markup');
    $imported=shiki_validate_settings(['ads_adsense_import'=>'<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456"></script>'],'ads');
    $assert('ca-pub-1234567890123456'===$imported['ads_publisher']&&''===$imported['ads_adsense_import'],'global official loader import stores only the validated publisher ID');
    $settings = array_merge(shiki_defaults(), ['ads_mode'=>'test','ads_slots'=>[$slot]]); update_option('shikipress_settings', $settings, false);
    $id = wp_insert_post(['post_title'=>'TEST advertising boundary','post_content'=>'<p>TEST</p>','post_status'=>'publish','post_type'=>'post']); $created[] = $id;
    global $wp_query, $wp_the_query, $post;
    $wp_query = new WP_Query(['p'=>$id]); $wp_the_query = $wp_query; $wp_query->the_post();
    $html = shiki_ads_render_slot($slot, false);
    $assert(str_contains($html, 'TEST 広告枠') && !str_contains($html, 'never-request') && !str_contains($html, '__SHIKI_TEST'), 'test HTML contains no production tracking URLs or code');
    preg_match('/data-shiki-ad="([^"]+)"/', $html, $match); $payload = json_decode(base64_decode(html_entity_decode($match[1])), true);
    $assert('test' === $payload['mode'] && !isset($payload['code']) && !isset($payload['publisher']), 'test payload excludes raw scripts and publisher IDs');
    $excluded = $slot; $excluded['exclude_ids'] = [$id]; $assert('' === shiki_ads_render_slot($excluded, false), 'individual page exclusion');
    $category = wp_insert_term('TEST ads category ' . wp_generate_password(5, false), 'category'); wp_set_post_categories($id, [$category['term_id']]);
    $excluded = $slot; $excluded['exclude_categories'] = [$category['term_id']]; $assert('' === shiki_ads_render_slot($excluded, false), 'category exclusion');
    $assert('' !== shiki_ads_render_slot($slot, false), 'empty category exclusions do not exclude categorized posts');
    $excluded = $slot; $excluded['post_types'] = ['page']; $assert('' === shiki_ads_render_slot($excluded, false), 'post type exclusion');
    wp_update_post(['ID'=>$id,'post_password'=>'TEST-PASSWORD']); clean_post_cache($id); $wp_query = new WP_Query(['p'=>$id]); $wp_the_query=$wp_query; $wp_query->the_post();
    $assert('' === shiki_ads_render_slot($slot, false), 'password protected content has no ad output');
    wp_update_post(['ID'=>$id,'post_password'=>'']); $wp_query = new WP_Query(['p'=>$id]); $wp_the_query=$wp_query; $wp_query->the_post();
    $settings['ads_mode']='off'; update_option('shikipress_settings',$settings,false); $assert('' === shiki_ads_render_slot($slot,false),'global off emits no advertising markup');
    $settings['ads_mode']='test'; $slot['position']='middle'; $slot['id']='boundary-middle'; $slot['limit']=2; $settings['ads_slots']=[$slot]; update_option('shikipress_settings',$settings,false);
    $content = '<!-- wp:paragraph --><p>first <a href="#TEST">link</a></p><!-- /wp:paragraph --><!-- wp:paragraph --><p>second</p><!-- /wp:paragraph --><!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">nested</h2><!-- /wp:heading --><!-- wp:paragraph --><p>nested paragraph</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>third</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>fourth</p><!-- /wp:paragraph -->';
    $rendered=apply_filters('the_content',$content); $assert(2 === substr_count($rendered,'data-shiki-ad='),'in-content interval and per-slot limit');
    $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$rendered); $xpath=new DOMXPath($dom); $assert(0 === $xpath->query('//a//aside | //p//aside | //div[contains(@class,"wp-block-group")]//aside')->length,'ads never inserted inside link, paragraph, or nested block group');
    $slot['id']='h2-only'; $slot['position']='before_h2'; $slot['interval']=1; $settings['ads_slots']=[$slot]; update_option('shikipress_settings',$settings,false);
    $rendered=apply_filters('the_content',$content.'<!-- wp:heading --><h2 class="wp-block-heading">outer</h2><!-- /wp:heading -->'); $assert(1 === substr_count($rendered,'data-shiki-ad='),'nested headings skipped, top-level heading eligible');
    $registered=WP_Block_Type_Registry::get_instance()->get_registered('shikipress/ad-slot'); $assert($registered && is_callable($registered->render_callback),'real dynamic Gutenberg slot block is registered');
    $restored=shiki_ads_reset_import(['ads_mode'=>'production','ads_live_approved'=>true,'ads_auto'=>true,'ads_cmp_enabled'=>true,'ads_cmp_trusted'=>true,'ads_external_reviewed'=>true,'ads_slots'=>[$slot]]);
    $assert('test' === $restored['ads_mode'] && !$restored['ads_live_approved'] && !$restored['ads_auto'] && !$restored['ads_cmp_enabled'] && !$restored['ads_cmp_trusted'] && !$restored['ads_slots'][0]['trusted'],'imports and restores disarm all external execution');
    $slot['shared_loaders']=['https://never-request.invalid/shared.js'];
    $input=['ads_mode'=>'production','ads_live_approved'=>false,'ads_slots'=>[$slot],'ads_cmp_code'=>'<script>TEST</script>'];
    $clean=shiki_validate_settings($input,'ads'); $assert('test' === $clean['ads_mode'] && $clean['ads_slots'][0]['shared_loaders']===$slot['shared_loaders'],'sanitizer requires production acknowledgement and accepts normalized loader arrays');
    $malformed=$input; $malformed['ads_slots']=['unexpected-key'=>array_merge($slot,['publisher'=>['bad'],'ad_slot'=>['bad'],'shared_loaders'=>[['bad']],'vendors'=>[['bad'],123]])];
    $normalized=shiki_validate_settings($malformed,'ads');$assert([]===$normalized['ads_slots'][0]['shared_loaders']&&[123]===$normalized['ads_slots'][0]['vendors'],'malformed import rows normalize without executing code or throwing');
    $deny=function($caps){$caps['unfiltered_html']=false;return $caps;}; add_filter('user_has_cap',$deny);
    $existing_cmp=shiki_option('ads_cmp_code',''); $existing_slot=$settings['ads_slots'][0]; $input['ads_cmp_code']='<script>ATTACK</script>'; $input['ads_cmp_trusted']=true; $input['ads_slots'][0]['code']='<script>ATTACK</script>'; $input['ads_slots'][0]['trusted']=true;
    $clean=shiki_validate_settings($input,'ads'); remove_filter('user_has_cap',$deny);
    $assert($clean['ads_cmp_code']===$existing_cmp && $clean['ads_slots'][0]['code']===$existing_slot['code'],'manage_options without unfiltered_html cannot replace executable tags');
    $settings['ads_slots'][0]['code']='</textarea><script data-test-xss="1">window.TEST_XSS=true;</script>'; update_option('shikipress_settings',$settings,false);
    ob_start();do_action('shiki_admin_tab_ads');$admin_html=ob_get_clean();
    $assert(str_contains($admin_html,'&lt;/textarea&gt;&lt;script')&&!str_contains($admin_html,'</textarea><script data-test-xss'),'executable code is escaped as textarea text in admin');
    wp_set_current_user(0); ob_start();do_action('shiki_admin_tab_ads');$low_html=ob_get_clean();
    $assert('' === $low_html && shiki_validate_settings(['ads_mode'=>'production'],'ads')['ads_mode']===$settings['ads_mode'],'unauthenticated users cannot access or mutate manager'); wp_set_current_user($admins[0]->ID);
    $before=is_file(ABSPATH.'ads.txt')?hash_file('sha256',ABSPATH.'ads.txt'):null; $diag=shiki_ads_txt_diagnostic(); $after=is_file(ABSPATH.'ads.txt')?hash_file('sha256',ABSPATH.'ads.txt'):null;
    $assert($before===$after && isset($diag['status'],$diag['message']),'ads.txt diagnostics are read-only');
    wp_delete_term($category['term_id'],'category');
} catch (Throwable $error) { $results[]=['name'=>'exception','pass'=>false,'error'=>$error->getMessage()]; }
finally {
    wp_set_current_user($admins[0]->ID ?? $saved_user); update_option('shikipress_settings',$saved_settings,false);
    foreach($created as $id){wp_delete_post($id,true);} wp_set_current_user($saved_user);
}
$dir=dirname(__FILE__).'/results'; wp_mkdir_p($dir); file_put_contents($dir.'/ads-integration.json',wp_json_encode(['environment'=>['wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'type'=>wp_get_environment_type()],'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
foreach($results as $result){WP_CLI::log(($result['pass']?'PASS ':'FAIL ').$result['name'].(isset($result['error'])?' '.$result['error']:''));}
if(array_filter($results,fn($result)=>!$result['pass'])){WP_CLI::error('Advertising integration tests failed');}
WP_CLI::success('Advertising integration assertions: '.count($results));
