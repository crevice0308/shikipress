<?php
/** Synthetic public plugin types exercise core's actual sitemap providers. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || DB_NAME !== 'shikipress_audit') { WP_CLI::error('Dedicated audit required'); }
$checks=[]; $ids=[]; $term=0;
$check=static function($name,$pass) use (&$checks) {$checks[]=['name'=>$name,'pass'=>(bool)$pass];};
register_post_type('shiki_audit_book',['public'=>true,'has_archive'=>true,'rewrite'=>false,'label'=>'TEST books']);
register_taxonomy('shiki_audit_topic','shiki_audit_book',['public'=>true,'rewrite'=>false,'label'=>'TEST topics']);
try {
    foreach (['publish','private','password','noindex'] as $kind) {
        $id=wp_insert_post(['post_type'=>'shiki_audit_book','post_title'=>'TEST sitemap '.$kind,'post_status'=>$kind==='private'?'private':'publish','post_password'=>$kind==='password'?'test-only':'']);
        $ids[$kind]=$id;
        if ($kind==='noindex') { update_post_meta($id,'_shiki_noindex','1'); }
    }
    $created=wp_insert_term('TEST plugin topic '.wp_generate_password(6,false),'shiki_audit_topic');$term=(int)$created['term_id'];wp_set_object_terms($ids['publish'],[$term],'shiki_audit_topic');
    $provider=new WP_Sitemaps_Posts();$types=$provider->get_object_subtypes();
    $check('Core sitemap retains public plugin post type',isset($types['shiki_audit_book']));
    $urls=array_column($provider->get_url_list(1,'shiki_audit_book'),'loc');
    $check('Core sitemap includes public plugin content',in_array(get_permalink($ids['publish']),$urls,true));
    foreach (['private','password','noindex'] as $kind) { $check('Core sitemap omits '.$kind.' plugin content',!in_array(get_permalink($ids[$kind]),$urls,true)); }
    $taxes=new WP_Sitemaps_Taxonomies();$check('Core sitemap retains public plugin taxonomy',isset($taxes->get_object_subtypes()['shiki_audit_topic']));
    $check('Core sitemap includes populated public plugin term',in_array(get_term_link($term,'shiki_audit_topic'),array_column($taxes->get_url_list(1,'shiki_audit_topic'),'loc'),true));
    $epoch=(int)get_option('shikipress_cache_epoch');update_term_meta($term,'_shiki_noindex','1');
    $check('Independent term-meta update invalidates public cache',(int)get_option('shikipress_cache_epoch')>$epoch);
    $check('Yoast adapter omits term noindex',false===apply_filters('wpseo_sitemap_entry',['loc'=>'test'],'term',get_term($term,'shiki_audit_topic')));
    $check('Yoast XML URL displayed accurately',str_contains(shiki_xml_sitemap_url(),'sitemap_index.xml'));
} finally {
    foreach($ids as$id) {wp_delete_post($id,true);}if($term){wp_delete_term($term,'shiki_audit_topic');}
    unregister_taxonomy('shiki_audit_topic');unregister_post_type('shiki_audit_book');
}
file_put_contents(__DIR__.'/results/plugin-public-types.json',wp_json_encode($checks,JSON_PRETTY_PRINT));echo wp_json_encode($checks,JSON_PRETTY_PRINT).PHP_EOL;
if(array_filter($checks,fn($r)=>!$r['pass'])){WP_CLI::error('Plugin public types regression');}
