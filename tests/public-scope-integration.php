<?php
/** Dedicated test environment only. Tests anonymous REST and global comment feeds. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || !defined('WP_CLI')) {throw new RuntimeException('Dedicated environment required');}
$GLOBALS['shiki_scope_checks']=0;
function shiki_scope_assert($ok,$label){++$GLOBALS['shiki_scope_checks'];if(!$ok){throw new RuntimeException($label);}echo 'PASS '.$label.PHP_EOL;}
$old_settings=get_option('shikipress_settings',[]);$old_user=get_current_user_id();$ids=[];$comments=[];
try{
 wp_set_current_user(0);
 $make=function($type,$label,$password='',$exclude=false)use(&$ids){$id=wp_insert_post(['post_type'=>$type,'post_status'=>'publish','post_title'=>'TEST_SCOPE_'.strtoupper($label),'post_content'=>'TEST content','post_password'=>$password]);$ids[]=$id;if($exclude){update_post_meta($id,'_shiki_exclude',1);}return $id;};
 $public=$make('post','visible');$protected=$make('post','protected','test-password');$hidden=$make('post','excluded','',true);
 $page=$make('page','page');$pageprotected=$make('page','protectedpage','test-password');$pagehidden=$make('page','hiddenpage');
 update_option('shikipress_settings',array_merge($old_settings,['page_exclude'=>[$pagehidden]]));
 foreach([$public,$protected,$hidden,$page,$pageprotected,$pagehidden]as$id){$comments[$id]=wp_insert_comment(['comment_post_ID'=>$id,'comment_content'=>'TEST_SCOPE_COMMENT_'.$id,'comment_author'=>'TEST','comment_approved'=>1]);}
 foreach(['/wp/v2/posts'=>[$public,[$protected,$hidden]],'/wp/v2/pages'=>[$page,[$pageprotected,$pagehidden]]]as$route=>$expected){$request=new WP_REST_Request('GET',$route);$request->set_param('search','TEST_SCOPE_');$request->set_param('per_page',100);$response=rest_do_request($request);shiki_scope_assert($response->get_status()===200,$route.' status');$found=array_column($response->get_data(),'id');shiki_scope_assert(in_array($expected[0],$found,true),$route.' visible');foreach($expected[1]as$id){shiki_scope_assert(!in_array($id,$found,true),$route.' hidden omitted '.$id);}}
 $request=new WP_REST_Request('GET','/wp/v2/search');$request->set_param('search','TEST_SCOPE_');$request->set_param('per_page',100);$found=array_column(rest_do_request($request)->get_data(),'id');shiki_scope_assert(in_array($public,$found,true)&&in_array($page,$found,true),'REST search public visible');foreach([$protected,$hidden,$pageprotected,$pagehidden]as$id){shiki_scope_assert(!in_array($id,$found,true),'REST search hidden omitted '.$id);}
 $request=new WP_REST_Request('GET','/wp/v2/comments');$request->set_param('search','TEST_SCOPE_COMMENT_');$request->set_param('per_page',100);$response=rest_do_request($request);shiki_scope_assert($response->get_status()===200,'REST comments status');$found=array_column($response->get_data(),'id');shiki_scope_assert(in_array($comments[$public],$found,true),'REST public comment visible');foreach([$protected,$hidden,$pageprotected,$pagehidden]as$id){shiki_scope_assert(!in_array($comments[$id],$found,true),'REST hidden comment omitted '.$id);}
 $query=new WP_Query(['feed'=>'rss2','withcomments'=>1,'comments_per_page'=>100]);$found=wp_list_pluck((array)$query->comments,'comment_ID');$found=array_map('intval',$found);shiki_scope_assert(in_array($comments[$public],$found,true),'Global comments feed public visible');foreach([$protected,$hidden,$pageprotected,$pagehidden]as$id){shiki_scope_assert(!in_array($comments[$id],$found,true),'Global comment feed hidden omitted '.$id);}
 echo 'PUBLIC_SCOPE_TOTAL '.$GLOBALS['shiki_scope_checks'].PHP_EOL;
}finally{foreach($comments as$id){wp_delete_comment($id,true);}foreach($ids as$id){wp_delete_post($id,true);}update_option('shikipress_settings',$old_settings);wp_set_current_user($old_user);}
