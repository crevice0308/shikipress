<?php
/** Native Yoast providers, beyond head-only conflict detection. */
if(!defined('SHIKI_TEST_ENV')||!SHIKI_TEST_ENV||DB_NAME!=='shikipress_audit'){WP_CLI::error('Dedicated audit required');}
$ids=get_option('shiki_plugin_audit_fixtures');$checks=[];$provider=new WPSEO_Post_Type_Sitemap_Provider();$links=$provider->get_sitemap_links('page',1000,1);$loc=array_column($links,'loc');
foreach(['noindex','excluded']as$type){$checks[]=['name'=>'Yoast XML excludes ShikiPress '.$type,'pass'=>!in_array(get_permalink($ids[$type]),$loc,true)];}
$checks[]=['name'=>'Yoast XML includes real contact page','pass'=>in_array(get_permalink($ids['contact']),$loc,true)];
file_put_contents(__DIR__.'/results/plugin-seo-scope-'.sanitize_key($args[0]??'after').'.json',wp_json_encode($checks,JSON_PRETTY_PRINT));echo wp_json_encode($checks,JSON_PRETTY_PRINT).PHP_EOL;
if(array_filter($checks,fn($r)=>!$r['pass']))WP_CLI::error('Yoast sitemap scope mismatch');
