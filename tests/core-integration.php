<?php
/** Run only in guarded isolated development WP: scripts/wp.sh eval-file tests/core-integration.php */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Dedicated test WordPress required'); }
$GLOBALS['results']=[];$results=&$GLOBALS['results'];$ids=[];$old_settings=get_option('shikipress_settings',[]);$old_user=get_current_user_id();
function shiki_core_assert($condition,$label){global $results;$results[]=['test'=>$label,'pass'=>(bool)$condition];if(!$condition){throw new RuntimeException($label);}}
try{
    $admin=get_users(['role'=>'administrator','number'=>1]);wp_set_current_user($admin[0]->ID);
    $states=['publish','draft','private','future'];$pages=[];
    foreach($states as $state){$args=['post_type'=>'page','post_status'=>$state,'post_title'=>'TEST core '.$state,'post_content'=>'TEST'];if($state==='future'){$args['post_date']=gmdate('Y-m-d H:i:s',time()+86400);$args['post_date_gmt']=$args['post_date'];}$id=wp_insert_post($args,true);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$ids[]=$id;$pages[$state]=$id;}
    $protected=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'TEST core password','post_password'=>'test-only-password']);$ids[]=$protected;
    $excluded=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'TEST core excluded','meta_input'=>['_shiki_exclude'=>1]]);$ids[]=$excluded;
    $visible=array_map(fn($p)=>$p->ID,shiki_public_pages());
    shiki_core_assert(in_array($pages['publish'],$visible,true),'Public page listed');
    foreach([$pages['draft'],$pages['private'],$pages['future'],$protected,$excluded] as $hidden){shiki_core_assert(!in_array($hidden,$visible,true),'Nonpublic/protected/excluded page omitted '.$hidden);}
    $post=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST core public post']);$ids[]=$post;
    $locked=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST core protected post','post_password'=>'test-only-password']);$ids[]=$locked;
    $excludedpost=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST core excluded post','meta_input'=>['_shiki_exclude'=>1]]);$ids[]=$excludedpost;
    $query=shiki_public_posts(['post__in'=>[$post,$locked,$excludedpost],'posts_per_page'=>10,'fields'=>'ids']);
    shiki_core_assert($query->posts===[$post],'Public post query omits password and excluded posts');
    $sanitized=shiki_validate_settings(['tabs'=>['home'=>'<script>x</script>トップ','categories'=>'','pages'=>'Pages','search'=>'Find'],'page_priority'=>[$pages['publish'],$pages['publish']],'page_exclude'=>(string)$pages['publish'],'page_roles'=>['about'=>$post,'contact'=>$pages['publish']],'unknown'=>'injected','ads_mode'=>'production'],'navigation',$old_settings);
    shiki_core_assert(!isset($sanitized['unknown']),'Unknown settings rejected');
    shiki_core_assert($sanitized['ads_mode']===($old_settings['ads_mode']??'test'),'Cross-section advertisement mutation refused');
    shiki_core_assert(!isset($sanitized['page_roles']['about'])&&$sanitized['page_roles']['contact']===$pages['publish'],'Page roles accept page IDs only');
    shiki_core_assert(strpos($sanitized['tabs']['home'],'<')===false,'Tab text strips HTML');
    shiki_core_assert(count($sanitized['page_priority'])===1,'Page priority deduplicated');
    $epoch=(int)get_option('shikipress_cache_epoch');shiki_invalidate_cache();shiki_core_assert((int)get_option('shikipress_cache_epoch')>$epoch,'Cache epoch advances');
    $subscriber=wp_insert_user(['user_login'=>'shiki_core_sub_'.wp_generate_password(6,false),'user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
    wp_set_current_user($subscriber);$_POST['option_page']='shikipress';$_POST['shikipress_section']='navigation';
    shiki_core_assert(shiki_sanitize_settings(['page_exclude'=>[]])===get_option('shikipress_settings',[]),'Low privilege settings save rejected');
    wp_set_current_user($admin[0]->ID);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($subscriber);
}catch(Throwable $error){$results[]=['error'=>$error->getMessage(),'pass'=>false];}
finally{unset($_POST['option_page'],$_POST['shikipress_section']);foreach($ids as $id){wp_delete_post($id,true);}wp_set_current_user($old_user);}
echo wp_json_encode($results,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
foreach($results as $result){if(empty($result['pass'])){WP_CLI::error('Core integration failed');}}
WP_CLI::success('Core integration passed: '.count($results));
