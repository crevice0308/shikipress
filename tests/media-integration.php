<?php
/** Test real WP responsive-image generation and custom-logo rendering in isolated WP. */
if (!defined('SHIKI_TEST_ENV') || !SHIKI_TEST_ENV || !defined('WP_CLI')) {throw new RuntimeException('Dedicated test WordPress required');}
$original_logo=get_theme_mod('custom_logo');$original_user=get_current_user_id();$attachment=0;$post=0;
try{
 wp_set_current_user(1);require_once ABSPATH.'wp-admin/includes/image.php';
 $upload=wp_upload_bits('TEST-shiki-image-'.wp_generate_password(8,false).'.png',null,file_get_contents(get_template_directory().'/assets/social/autumn.png'));
 if($upload['error']){throw new RuntimeException($upload['error']);}
 $attachment=wp_insert_attachment(['post_title'=>'TEST original Shiki art','post_mime_type'=>'image/png','post_status'=>'inherit'],$upload['file']);
 update_post_meta($attachment,'_wp_attachment_image_alt','TEST original seasonal illustration');
 wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
 $post=wp_insert_post(['post_title'=>'TEST media with image','post_status'=>'publish','post_content'=>'TEST']);set_post_thumbnail($post,$attachment);
 ob_start();shiki_card(get_post($post));$card=ob_get_clean();
 $checks=[
  ['test'=>'Real WordPress responsive srcset generated','pass'=>str_contains($card,'srcset=')],
  ['test'=>'Card image dimensions generated','pass'=>str_contains($card,'width="760"')&&str_contains($card,'height="520"')],
  ['test'=>'Nonhero card lazy loading','pass'=>str_contains($card,'loading="lazy"')],
 ];
 set_theme_mod('custom_logo',$attachment);$logo=get_custom_logo();
 $checks[]=['test'=>'Custom logo uses actual attachment','pass'=>str_contains($logo,'custom-logo')&&str_contains($logo,'TEST original seasonal illustration')];
 $checks[]=['test'=>'Custom logo links to home','pass'=>str_contains($logo,esc_url(home_url('/')))];
 $metadata=wp_get_attachment_metadata($attachment);$checks[]=['test'=>'WP generated thumbnail variants','pass'=>!empty($metadata['sizes']['shiki-card'])];
 echo wp_json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
 foreach($checks as$check){if(!$check['pass']){throw new RuntimeException($check['test']);}}
 echo 'MEDIA_TOTAL '.count($checks).PHP_EOL;
}finally{if($original_logo){set_theme_mod('custom_logo',$original_logo);}else{remove_theme_mod('custom_logo');}if($post){wp_delete_post($post,true);}if($attachment){wp_delete_attachment($attachment,true);}wp_set_current_user($original_user);}
