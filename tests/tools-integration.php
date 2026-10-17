<?php
/** Real WordPress tests; no external HTTP. Dedicated development database only. */
if (!defined('ABSPATH') || !defined('WP_CLI')) { exit(1); }
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true) { WP_CLI::error('Tools tests require the dedicated SHIKI_TEST_ENV=true environment.'); }
$GLOBALS['tools_assertions'] = 0;
function shiki_tools_test($ok, $label) { global $tools_assertions; ++$tools_assertions; if (!$ok) { throw new RuntimeException('Tools assertion failed: ' . $label); } }
$tools_options = [];
foreach (['shikipress_settings','shikipress_settings_before_import','shikipress_link_state','shikipress_link_lock','shikipress_link_last_run','permalink_structure'] as $name) { $tools_options[$name] = get_option($name, null); }
$tools_old_user = get_current_user_id(); $tools_created = []; $tools_users=[]; $tools_preview_before=get_transient('shikipress_import_1');
$tools_dns = static function($ips, $host) { if ($host === 'check.example.com') { return ['93.184.216.34']; } if ($host === 'mixed.example.com') { return ['93.184.216.34','127.0.0.1']; } return $ips; };
$tools_http_calls = [];
$tools_response = static function($code, $headers = []) { return ['headers'=>$headers,'body'=>'test','response'=>['code'=>$code,'message'=>'test'],'cookies'=>[]]; };
$tools_http = static function($pre, $args, $url) use (&$tools_http_calls, $tools_response) {
    $tools_http_calls[] = ['url'=>$url,'args'=>$args];
    if (str_contains($url,'/private-redirect')) { return $tools_response(302, ['location'=>'http://169.254.169.254/latest/meta-data/']); }
    if (str_contains($url,'/loop')) { return $tools_response(302, ['location'=>'/loop']); }
    if (str_contains($url,'/timeout')) { return new WP_Error('http_request_failed','timeout'); }
    foreach ([403,429,404,410,500] as $code) { if (str_contains($url,'/status-'.$code)) { return $tools_response($code); } }
    return $tools_response(200);
};
try {
    wp_set_current_user(1);
    shiki_tools_test(function_exists('shiki_tools_diagnostics'), 'theme module loaded');
    $nonce = wp_create_nonce('shiki_tools_export');
    shiki_tools_test(true === shiki_tools_authorize('export',$nonce), 'admin valid nonce accepted');
    shiki_tools_test(is_wp_error(shiki_tools_authorize('export','invalid')), 'nonce required');
    shiki_tools_test(is_wp_error(shiki_tools_authorize('scan',$nonce)), 'nonce scoped to operation');
    $uid = wp_insert_user(['user_login'=>'shiki-tools-' . wp_generate_password(10,false),'user_pass'=>wp_generate_password(32),'user_email'=>'tools-'.wp_generate_password(8,false).'@example.invalid','role'=>'subscriber']);
    if (is_wp_error($uid)) { throw new RuntimeException('fixture user failed'); } $tools_users[]=$uid;
    wp_set_current_user($uid); $subscriber_nonce=wp_create_nonce('shiki_tools_export');
    shiki_tools_test(is_wp_error(shiki_tools_authorize('export',$subscriber_nonce)), 'nonce cannot replace administrator permission');
    foreach (['scan','external','export','preview','apply','restore'] as $action) { shiki_tools_test(!has_action('admin_post_nopriv_shiki_tools_'.$action),'no unauthenticated action '.$action); }
    wp_set_current_user(0); shiki_tools_test(is_wp_error(shiki_tools_authorize('export',$nonce)), 'anonymous forbidden'); wp_set_current_user(1);
    $old_post = $_POST;
    $die_intercept = static function() { return static function($message, $title, $args) { throw new RuntimeException('EXPECTED_GUARD_' . ($args['response'] ?? 0)); }; };
    add_filter('wp_die_handler', $die_intercept, PHP_INT_MAX);
    try {
        foreach (['scan','external','export','preview','apply','restore'] as $action) {
            shiki_tools_test((bool)has_action('admin_post_shiki_tools_'.$action),'privileged handler registered '.$action);
            $_POST=['_wpnonce'=>'invalid'];
            try { do_action('admin_post_shiki_tools_'.$action); shiki_tools_test(false,'invalid nonce blocked action '.$action); }
            catch (RuntimeException $e) { shiki_tools_test($e->getMessage()==='EXPECTED_GUARD_403','action nonce failure is 403 '.$action); }
            wp_set_current_user($uid); $_POST=['_wpnonce'=>wp_create_nonce('shiki_tools_'.$action)];
            try { do_action('admin_post_shiki_tools_'.$action); shiki_tools_test(false,'subscriber blocked action '.$action); }
            catch (RuntimeException $e) { shiki_tools_test($e->getMessage()==='EXPECTED_GUARD_403','action capability failure is 403 '.$action); }
            wp_set_current_user(1);
        }
    } finally { remove_filter('wp_die_handler',$die_intercept,PHP_INT_MAX); $_POST=$old_post; wp_set_current_user(1); }


    foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','0.0.0.0','100.64.0.1','198.18.0.1','192.0.2.1','224.0.0.1','240.0.0.1','192.88.99.1','::1','::','::ffff:127.0.0.1','fe80::1','fc00::1','ff02::1','2001:db8::1','2001::1','2001:20::1','2002:7f00:1::','3fff::1'] as $ip) { shiki_tools_test(!shiki_link_public_ip($ip),'special IP rejected '.$ip); }
    foreach (['93.184.216.34','8.8.8.8','2606:4700:4700::1111','2001:4860:4860::8888'] as $ip) { shiki_tools_test(shiki_link_public_ip($ip),'global IP accepted '.$ip); }
    add_filter('shiki_link_dns_records',$tools_dns,10,2); add_filter('pre_http_request',$tools_http,10,3);
    foreach (['file:///etc/passwd','gopher://127.0.0.1/','http://127.0.0.1/','http://169.254.169.254/','http://[::1]/','http://user:pass@check.example.com/','http://check.example.com:8080/','http://localhost/','http://metadata.google.internal/','http://127.1/','http://2130706433/','http://0177.0.0.1/','http://check.example.com./','http://mixed.example.com/'] as $url) {
        $before=count($tools_http_calls); $result=shiki_link_fetch($url); shiki_tools_test($result['status']==='unsafe','unsafe target denied '.$url); shiki_tools_test(count($tools_http_calls)===$before,'unsafe target never fetched '.$url);
    }
    shiki_tools_test(!is_wp_error(shiki_link_safe_target('https://check.example.com/ok')),'all DNS answers public');
    $result=shiki_link_fetch('https://check.example.com/private-redirect'); shiki_tools_test($result['status']==='unsafe','private redirect denied');
    shiki_tools_test(count($tools_http_calls)===1,'private redirect never fetched');
    foreach ([200=>'ok',403=>'uncertain',429=>'uncertain',404=>'broken',410=>'broken',500=>'uncertain'] as $code=>$expected) {
        $result=shiki_link_fetch('https://check.example.com/status-'.$code); shiki_tools_test($result['status']===$expected,'HTTP classification '.$code);
    }
    shiki_tools_test(shiki_link_fetch('https://check.example.com/timeout')['status']==='uncertain','timeout not broken');
    $before=count($tools_http_calls); shiki_tools_test(shiki_link_fetch('https://check.example.com/loop')['status']==='redirect','redirect loops bounded'); shiki_tools_test(count($tools_http_calls)-$before===3,'maximum three hop requests');
    foreach ($tools_http_calls as $call) { shiki_tools_test($call['args']['timeout']===4 && $call['args']['redirection']===0 && $call['args']['limit_response_size']===1024,'HTTP request budgets'); }

    update_option('permalink_structure','');
    $public=wp_insert_post(['post_title'=>'TEST TOOLS public','post_status'=>'publish']); $tools_created[]=$public;
    $draft=wp_insert_post(['post_title'=>'TEST TOOLS draft','post_status'=>'draft']); $tools_created[]=$draft;
    shiki_tools_test(shiki_link_internal_status(get_permalink($public))==='ok','local post resolved without HTTP');
    shiki_tools_test(shiki_link_internal_status(home_url('/?p='.$draft))==='missing','unpublished target not publicly available');
    shiki_tools_test(shiki_link_internal_status(home_url('/?p=2147483646'))==='missing','missing local post ID detected');
    shiki_tools_test(shiki_link_internal_status(home_url('/?s=test'))==='unresolved','search route not falsely broken');
    shiki_tools_test(shiki_link_internal_status(home_url('/custom-plugin-endpoint'))==='unresolved','unknown custom router remains uncertain');
    shiki_tools_test(shiki_link_absolute('#anchor',home_url('/'))==='','fragment skipped');
    shiki_tools_test(!str_contains(shiki_link_display_url('https://user:example-pass@check.example.com/?api_key=example-secret'),'example-pass') && !str_contains(shiki_link_display_url('https://check.example.com/?api_key=example-secret'),'example-secret'),'link result masks credential-like URL parts');
    shiki_tools_test(shiki_link_absolute('mailto:user@example.com',home_url('/'))==='','non-http skipped');
    $source=wp_insert_post(['post_title'=>'TEST TOOLS source','post_status'=>'publish','post_content'=>'<a href="'.esc_url(home_url('/?p=2147483646')).'">broken</a><a href="https://check.example.com/status-403">external</a><a href="https://check.example.com/status-404">external2</a><a href="https://check.example.com/status-200">external3</a>']); $tools_created[]=$source;
    $all=get_posts(['post_type'=>['post','page'],'post_status'=>'publish','has_password'=>false,'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids']); $offset=array_search($source,$all,true);
    update_option('shikipress_link_state',['offset'=>$offset,'queue'=>[],'results'=>[],'dropped'=>0,'complete'=>false]);
    $before=count($tools_http_calls); $state=shiki_links_scan_batch(); shiki_tools_test(count($tools_http_calls)===$before,'internal scan makes no HTTP');
    shiki_tools_test(count($state['queue'])===3,'external queue collected unique URLs');
    shiki_tools_test(count(array_filter($state['results'],static fn($x)=>$x['status']==='missing' && in_array($source,$x['sources'],true)))===1,'source edit ID retained');
    delete_option('shikipress_link_last_run'); delete_option('shikipress_link_lock');
    $token=shiki_links_acquire_lock(); shiki_tools_test(!is_wp_error($token),'single runner acquired'); shiki_tools_test(is_wp_error(shiki_links_acquire_lock()),'second runner refused'); shiki_links_release_lock('wrong-token'); shiki_tools_test(is_wp_error(shiki_links_acquire_lock()),'unowned lock cannot release'); shiki_links_release_lock($token);
    $before=count($tools_http_calls); $state=shiki_links_run_queue(); shiki_tools_test(!is_wp_error($state) && count($tools_http_calls)-$before===2,'external batch limited to two serial URLs');
    shiki_tools_test(shiki_links_run_queue()->get_error_code()==='rate','external 60s interval enforced');
    shiki_tools_test(!get_option('shikipress_link_lock'),'runner lock released');
    foreach($state['queue'] as $item){ if($item['attempts']){ shiki_tools_test($item['attempts']===1 && $item['next']>time()+250,'retry interval and attempts recorded'); }}

    $baseline=array_merge(shiki_defaults(),['season_mode'=>'auto','view_count_enabled'=>true,'view_count_consent_required'=>false,'view_count_consent_ack'=>true,'ads_mode'=>'production','ads_live_approved'=>true,'ads_auto'=>true,'ads_publisher'=>'ca-pub-1234567890123456','ads_cmp_code'=>'<script>window.cmpExample=true;</script>','ads_cmp_enabled'=>true,'ads_cmp_trusted'=>true,'ads_slots'=>[['id'=>'test','name'=>'Test','service'=>'generic','state'=>'production','code'=>'<script src="https://check.example.com/example.js"></script>','trusted'=>true,'shared_loaders'=>[]]],'tabs'=>['home'=>'現在','categories'=>'カテゴリー','pages'=>'固定ページ','search'=>'検索']]);
    update_option('shikipress_settings',$baseline); $payload=shiki_backup_payload();
    shiki_tools_test($payload['schema_version']===1 && $payload['format']==='shikipress-settings','versioned envelope');
    foreach(['ads_slots','ads_cmp_code','ads_publisher'] as $key){ shiki_tools_test(!isset($payload['settings'][$key]),'normal export excludes '.$key); }
    shiki_tools_test(!str_contains(wp_json_encode($payload),'<script'),'no executable tags in normal export');
    shiki_tools_test(array_key_exists('seo_home_description',$payload['settings']),'SEO description is ordinary text, not a script key');
    shiki_tools_test(!is_wp_error(shiki_backup_parse(wp_json_encode($payload))),'normal JSON roundtrip valid');
    $code=shiki_backup_payload('executable-ad-code'); shiki_tools_test(isset($code['settings']['ads_slots'],$code['settings']['ads_cmp_code']),'explicit executable bundle separated');
    wp_set_current_user($uid); shiki_tools_test(is_wp_error(shiki_backup_parse(wp_json_encode($code))),'code import requires unfiltered_html'); wp_set_current_user(1);
    $make_payload=static fn($settings,$kind='settings')=>['format'=>'shikipress-settings','schema_version'=>1,'theme_version'=>'1.0.0','kind'=>$kind,'settings'=>$settings];
    foreach ([['unknown'=>'value'],['deepl_enabled'=>'true'],['ads_slots'=>[]],['timezone'=>['bad']],['tabs'=>['home'=>'test','arbitrary'=>'bad']],['timezone'=>'user@example.com']] as $settings) { shiki_tools_test(is_wp_error(shiki_backup_parse(wp_json_encode($make_payload($settings)))),'invalid setting rejected '.array_key_first($settings)); }
    shiki_tools_test(is_wp_error(shiki_backup_parse('{broken')),'malformed JSON denied');
    shiki_tools_test(is_wp_error(shiki_backup_parse(str_repeat(' ',524289))),'oversized JSON denied');
    $bad=$make_payload(['timezone'=>'Asia/Tokyo']);$bad['schema_version']=2;shiki_tools_test(is_wp_error(shiki_backup_parse(wp_json_encode($bad))),'unknown schema denied');
    $events=shiki_defaults()['season_events'];$events[]=$events[0]; shiki_tools_test(!is_wp_error(shiki_backup_parse(wp_json_encode($make_payload(['season_events'=>$events])))),'additional event rows validate against row schema');
    $data=shiki_backup_parse(wp_json_encode($make_payload(['season_mode'=>'fixed','tabs'=>['home'=>'移行後','categories'=>'カテゴリー','pages'=>'固定ページ','search'=>'検索']])));
    shiki_tools_test(!is_wp_error($data),'valid scoped import');
    $changed_data=$data; $changed_data['settings']['season_mode']='auto'; shiki_tools_test(shiki_backup_preview_hash($data)!==shiki_backup_preview_hash($changed_data),'different previews receive different signed identities');
    set_transient('shikipress_import_1',$changed_data,900); $old_post=$_POST;
    $_POST=['_wpnonce'=>wp_create_nonce('shiki_tools_apply'),'ack_apply'=>1,'preview_hash'=>shiki_backup_preview_hash($data),'sections'=>['navigation']];
    add_filter('wp_die_handler',$die_intercept,PHP_INT_MAX); $before_stale=get_option('shikipress_settings');
    try { do_action('admin_post_shiki_tools_apply'); shiki_tools_test(false,'stale preview must stop'); }
    catch(RuntimeException $e){ shiki_tools_test($e->getMessage()==='EXPECTED_GUARD_409','stale preview rejected after another tab changes payload'); }
    finally { remove_filter('wp_die_handler',$die_intercept,PHP_INT_MAX); $_POST=$old_post; }
    shiki_tools_test(get_option('shikipress_settings')===$before_stale,'stale preview does not mutate settings'); delete_transient('shikipress_import_1');

    shiki_tools_test(is_wp_error(shiki_backup_apply_data($data,[])),'empty target selection rejected');
    shiki_tools_test(is_wp_error(shiki_backup_apply_data($data,['unknown'])),'unknown section rejected');
    shiki_tools_test(shiki_backup_apply_data($data,['navigation'])===true,'selected section apply');
    $out=get_option('shikipress_settings'); shiki_tools_test($out['tabs']['home']==='移行後','selected navigation imported'); shiki_tools_test($out['season_mode']==='auto','unselected design retained');
    shiki_tools_test($out['ads_mode']==='test' && !$out['ads_live_approved'] && !$out['ads_auto'] && !$out['ads_cmp_enabled'] && !$out['ads_cmp_trusted'] && !$out['ads_slots'][0]['trusted'],'all existing ad execution disarmed after import');
    shiki_tools_test(!$out['view_count_enabled'] && $out['view_count_consent_required'] && !$out['view_count_consent_ack'],'optional aggregate measurement disabled and consent reset after import');
    $snapshot=get_option('shikipress_settings_before_import'); shiki_tools_test($snapshot['settings']['tabs']['home']==='現在','pre-apply private snapshot retained');
    shiki_tools_test(shiki_backup_restore_snapshot()===true,'snapshot restoration');$out=get_option('shikipress_settings'); shiki_tools_test($out['tabs']['home']==='現在' && $out['ads_mode']==='test' && !$out['ads_cmp_trusted'],'restore returns settings but leaves code disarmed');
    shiki_tools_test(shiki_backup_apply_data(shiki_backup_parse(wp_json_encode($code)),['ads'])===true,'explicit ad bundle imports through ad sanitizer'); $out=get_option('shikipress_settings'); shiki_tools_test(!$out['ads_slots'][0]['trusted'] && $out['ads_mode']==='test','executable import never trusted or live');
    $diagnostics=shiki_tools_diagnostics(); shiki_tools_test(count($diagnostics)>=15,'diagnostic observations available');
    foreach($diagnostics as $row){shiki_tools_test(in_array($row['state'],['ok','notice','error','unconfirmed'],true),'diagnostic severity explicit');}
    ob_start(); shiki_tools_backup_page(); $html=ob_get_clean(); shiki_tools_test(str_contains($html,'通常設定JSON') && str_contains($html,'512KB'),'backup UI rendered'); shiki_tools_test(!str_contains($html,'<script src="https://check.example.com/example.js"'),'backup preview does not execute raw code');
    WP_CLI::success('Tools: '.$GLOBALS['tools_assertions'].' assertions passed; external HTTP mocked, settings restored.');
} finally {
    remove_filter('pre_http_request',$tools_http,10);remove_filter('shiki_link_dns_records',$tools_dns,10);
    wp_set_current_user(1);
    foreach($tools_options as $name=>$value){ if($value===null){delete_option($name);}else{update_option($name,$value,false);} }
    foreach($tools_created as $id){wp_delete_post($id,true);}if(!function_exists('wp_delete_user')){require_once ABSPATH.'wp-admin/includes/user.php';}foreach($tools_users as $id){wp_delete_user($id);}
    if($tools_preview_before===false){delete_transient('shikipress_import_1');}else{set_transient('shikipress_import_1',$tools_preview_before,900);}
    wp_set_current_user($tools_old_user);
}
