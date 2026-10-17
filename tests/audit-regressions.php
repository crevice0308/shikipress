<?php
/** v1.0.1 targeted adversarial/regression checks, dedicated local audit only. */
if(!defined('SHIKI_TEST_ENV')||!SHIKI_TEST_ENV||DB_NAME!=='shikipress_audit'||!defined('WP_CLI')){throw new RuntimeException('Dedicated audit database required');}
$checks=[];$ids=[];$before=get_option('shikipress_settings',[]);$olduser=get_current_user_id();
$assert=function($name,$ok,$detail=null)use(&$checks){$checks[]=['name'=>$name,'pass'=>(bool)$ok,'detail'=>$detail];};
try{
 wp_set_current_user(1);$settings=array_merge($before,['season_mode'=>'fixed','season_fixed'=>'autumn','featured_mode'=>'auto']);update_option('shikipress_settings',$settings);
 $payload='<script type="application/json">{"html":"<h2>JSON_ONLY</h2>","safe":"yes"}</script>';
 $html='<h2 id="real">REAL_ONE</h2><p>text</p>'.$payload.'<h3>REAL_TWO</h3>';$out=shiki_toc($html);
 $assert('TOC leaves script JSON bytes unchanged',str_contains($out,$payload));
 $assert('TOC does not list script-only heading',!preg_match('~<a[^>]*>JSON_ONLY</a>~',$out));
 $template='<template id="plugin-template"><h2>TEMPLATE_ONLY</h2></template>';$out=shiki_toc('<h2>REAL_ONE</h2>'.$template.'<h3>REAL_TWO</h3>');
 $assert('TOC preserves inert plugin template',str_contains($out,$template));
 $assert('TOC excludes inert template heading',!preg_match('~<a[^>]*>TEMPLATE_ONLY</a>~',$out));
 $map=shiki_season_terms();$id=wp_insert_post(['post_status'=>'publish','post_title'=>'TEST audit priority','post_content'=>'TEST','post_date'=>'2020-01-01 12:00:00','post_category'=>[$map['autumn']]]);$ids[]=$id;
 shiki_select_posts('featured',[],3);$epoch=(int)get_option('shikipress_cache_epoch');update_post_meta($id,'_shiki_priority',100);
 $assert('Post-meta priority updates invalidate selection cache',(int)get_option('shikipress_cache_epoch')>$epoch);
 $selection=shiki_select_posts('featured',[],3);$assert('Priority changes reflected without a second save',$selection&&$selection[0]->ID===$id,array_map(fn($p)=>$p->ID,$selection));
 $epoch=(int)get_option('shikipress_cache_epoch');update_post_meta($id,'_shiki_exclude',1);$assert('Post-meta exclusion immediately invalidates navigation cache',(int)get_option('shikipress_cache_epoch')>$epoch);
 $epoch=(int)get_option('shikipress_cache_epoch');update_post_meta($id,'_shiki_translation',['state'=>'done']);$assert('Private job-meta changes avoid unnecessary public cache purge',(int)get_option('shikipress_cache_epoch')===$epoch);
 $malformed=[['section'=>'seo','input'=>['seo_google_verification'=>['not-a-token']]],['section'=>'navigation','input'=>['tabs'=>'not-an-array','page_roles'=>['contact'=>['x']]]],['section'=>'automation','input'=>['featured_ids'=>[['bad']],'season_terms'=>['spring'=>['bad']]]]];
 foreach($malformed as$case){$errors=[];set_error_handler(function($severity,$message)use(&$errors){$errors[]=$message;return true;});try{$out=shiki_validate_settings($case['input'],$case['section']);$ok=is_array($out)&&!$errors;}catch(Throwable$e){$errors[]=$e->getMessage();$ok=false;}finally{restore_error_handler();}$assert('Malformed '.$case['section'].' settings fail safely',$ok,$errors);}
}finally{foreach($ids as$id){wp_delete_post($id,true);}update_option('shikipress_settings',$before);wp_set_current_user($olduser);}
file_put_contents(__DIR__.'/results/audit-regressions-' . sanitize_key($args[0] ?? 'after') . '.json',wp_json_encode(['checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo wp_json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
if(array_filter($checks,fn($r)=>!$r['pass'])){WP_CLI::error('Audit regressions found');}WP_CLI::success('All audit regressions pass');
