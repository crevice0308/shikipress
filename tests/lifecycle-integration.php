<?php
/** Actual core future publication and revision/autosave lifecycle, dedicated environment only. */
if(!defined('SHIKI_TEST_ENV')||!SHIKI_TEST_ENV||!defined('WP_CLI')){throw new RuntimeException('Dedicated test WordPress required');}
$created=[];$settings=get_option('shikipress_settings',[]);$old_user=get_current_user_id();$checks=[];
$assert=function($ok,$name)use(&$checks){$checks[]=['test'=>$name,'pass'=>(bool)$ok];if(!$ok){throw new RuntimeException($name);}};
try{
 wp_set_current_user(1);update_option('shikipress_settings',array_merge($settings,['season_mode'=>'fixed','season_fixed'=>'autumn']));
 $map=shiki_season_terms();$future=wp_insert_post(['post_title'=>'TEST lifecycle scheduled autumn','post_status'=>'future','post_date'=>wp_date('Y-m-d H:i:s',time()+3600),'post_date_gmt'=>gmdate('Y-m-d H:i:s',time()+3600),'post_name'=>'test-lifecycle-scheduled','post_content'=>'TEST scheduled content','post_category'=>array_values(array_filter([$map['autumn']??0]))]);$created[]=$future;
 $assert(get_post_status($future)==='future','Core creates real future post');
 $assert(!in_array($future,wp_list_pluck(shiki_select_posts('featured',[],20),'ID'),true),'Scheduled post hidden before time');
 $before=(int)get_option('shikipress_cache_epoch');
 // Advance this dedicated fixture's due time without running wp_insert_post's immediate publish conversion.
 global $wpdb;$wpdb->update($wpdb->posts,['post_date_gmt'=>gmdate('Y-m-d H:i:s',time()-10),'post_date'=>wp_date('Y-m-d H:i:s',time()-10)],['ID'=>$future],['%s','%s'],['%d']);clean_post_cache($future);
 check_and_publish_future_post($future);
 $assert(get_post_status($future)==='publish','Core scheduled callback publishes due post');
 $assert((int)get_option('shikipress_cache_epoch')>$before,'Scheduled publication invalidates theme cache');
 $assert(in_array($future,shiki_public_posts(['post__in'=>[$future],'fields'=>'ids'])->posts,true),'Published scheduled post enters public list');
 $assert(in_array($future,wp_list_pluck(shiki_select_posts('featured',[],500),'ID'),true),'Published scheduled post enters seasonal selection');
 $args=shiki_seo_map_args(['post_type'=>'post','post__in'=>[$future],'fields'=>'ids'],'post');$assert(in_array($future,(new WP_Query($args))->posts,true),'Published scheduled post enters core XML provider policy');
 $post=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST lifecycle original winter','post_name'=>'test-manual-preserved-slug','post_content'=>'TEST original content']);$created[]=$post;
 update_post_meta($post,'_shiki_classification_lock',1);update_post_meta($post,'_shiki_slug_lock',1);
 $terms=wp_get_post_categories($post);$slug=get_post_field('post_name',$post);wp_save_post_revision($post);
 wp_update_post(['ID'=>$post,'post_title'=>'TEST lifecycle revised spring','post_content'=>'TEST revised content']);
 $revisions=wp_get_post_revisions($post,['order'=>'ASC']);$assert(count($revisions)>=1,'Core stores actual revision');
 $restore=reset($revisions);wp_restore_post_revision($restore->ID);
 $assert(get_post_field('post_name',$post)===$slug,'Revision restore preserves published URL');
 $assert(wp_get_post_categories($post)===$terms,'Revision restore preserves manual classification');
 require_once ABSPATH.'wp-admin/includes/post.php';$autosave=wp_create_post_autosave(['post_ID'=>$post,'post_type'=>'post','post_title'=>'TEST autosaved autumn','post_content'=>'TEST autosaved']);
 $assert(!is_wp_error($autosave)&&is_numeric($autosave),'Core creates autosave revision');
 $assert(get_post_field('post_name',$post)===$slug,'Autosave preserves URL');
 $assert(wp_get_post_categories($post)===$terms,'Autosave preserves manual classification');
 echo wp_json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;echo 'LIFECYCLE_TOTAL '.count($checks).PHP_EOL;
}finally{foreach($created as$id){wp_delete_post($id,true);}update_option('shikipress_settings',$settings);wp_set_current_user($old_user);}
