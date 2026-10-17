import {runCLI} from '@wp-playground/cli';
import fs from 'node:fs';import path from 'node:path';import {execFile} from 'node:child_process';import {promisify} from 'node:util';
const root=path.dirname(new URL(import.meta.url).pathname),label=process.env.RUN_LABEL||'wordpress';
for(const dir of ['results','screenshots'])fs.mkdirSync(path.join(root,dir),{recursive:true});
const source=process.env.THEME_DIR||path.resolve(root,'../../shikipress');
const theme=path.join(root,'wp-theme-'+label);fs.cpSync(source,theme,{recursive:true});
fs.cpSync(path.join(root,'image-fixtures'),path.join(theme,'test-fixtures'),{recursive:true});
const server=await runCLI({command:'server',php:'8.3',wp:'7.1.3',port:9415,workers:1,login:false,mount:[{hostPath:theme,vfsPath:'/wordpress/wp-content/themes/shikipress'}],blueprint:{steps:[{step:'activateTheme',themeFolderName:'shikipress'}]},'define-bool':{WP_DEBUG:true,WP_DEBUG_LOG:true,WP_DEBUG_DISPLAY:false,DISABLE_WP_CRON:true,SHIKI_TEST_ENV:true}});
try{
const r=await server.playground.run({code:`<?php require '/wordpress/wp-load.php';
$upload=wp_upload_dir(); $dir=$upload['path']; foreach(['full','card','small','hero'] as $size){copy(get_template_directory().'/test-fixtures/image-'.$size.'.jpg',$dir.'/layout-'.$size.'.jpg');}
$id=wp_insert_attachment(['post_title'=>'Local fixture image','post_mime_type'=>'image/jpeg','post_status'=>'inherit'],$dir.'/layout-full.jpg');
wp_update_attachment_metadata($id,['width'=>2000,'height'=>1400,'file'=>ltrim($upload['subdir'].'/layout-full.jpg','/'),'sizes'=>['shiki-card'=>['file'=>'layout-card.jpg','width'=>760,'height'=>520,'mime-type'=>'image/jpeg'],'shiki-card-small'=>['file'=>'layout-small.jpg','width'=>380,'height'=>260,'mime-type'=>'image/jpeg'],'shiki-hero'=>['file'=>'layout-hero.jpg','width'=>1440,'height'=>900,'mime-type'=>'image/jpeg']]]);
$ids=[];for($i=0;$i<5;$i++){$post=wp_insert_post(['post_status'=>'publish','post_type'=>'post','post_title'=>'PCとスマートフォンの読みやすさを確かめる記事 '.($i+1),'post_content'=>'<!-- wp:paragraph --><p>季節を感じる暮らしのしおり。この記事は更新テスト専用のローカルデータです。</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">余白と読みやすさ</h2><!-- /wp:heading --><!-- wp:paragraph --><p>'.str_repeat('文字や写真、広告枠の配置を確認します。',10).'</p><!-- /wp:paragraph --><table><tr><th>見出し</th><th>長い見出し</th><th>確認事項</th><th>結果</th><th>補足</th></tr><tr><td>季節</td><td>ローカル専用</td><td>安全なテスト</td><td>測定中</td><td>スマートフォン対応</td></tr></table>']);set_post_thumbnail($post,$id);$ids[]=$post;}
$page=wp_insert_post(['post_status'=>'publish','post_type'=>'page','post_title'=>'固定ページの確認','post_content'=>'<h2>固定ページ</h2><p>保存した本文は変更しません。</p>']);
$options=get_option('shikipress_settings',[]);$options['ads_mode']='test';$options['ads_live_approved']=false;$options['ads_limit']=8;$options['ads_slots']=[];foreach(['above'=>'delay','below'=>'no-fill','sidebar'=>'failure','footer'=>'ready'] as $position=>$dummy){$options['ads_slots'][]=['id'=>'local-'.$position,'name'=>'Local fixture','service'=>'generic','state'=>'test','position'=>$position,'device'=>'all','post_types'=>['post','page'],'limit'=>1,'height'=>250,'interval'=>3,'dummy'=>$dummy];}update_option('shikipress_settings',$options);
echo json_encode(['php'=>PHP_VERSION,'wp'=>get_bloginfo('version'),'posts'=>$ids,'page'=>$page,'image'=>$id,'themeVersion'=>SHIKI_VERSION]);`});
const data=JSON.parse(r.text);fs.writeFileSync(path.join(root,'results',label+'-runtime.json'),JSON.stringify(data,null,2));
const {stdout,stderr}=await promisify(execFile)(process.execPath,[path.join(root,'wordpress-smoke.cjs')],{env:{...process.env,RUN_LABEL:label,WP_BASE:'http://127.0.0.1:9415',WP_PATHS:JSON.stringify(['/','/?p='+data.posts[0],'/?page_id='+data.page,'/?s=local','/?p=999999'])},timeout:180000,maxBuffer:2000000});console.log(stdout);if(stderr)console.error(stderr);
}catch(error){if(error.stdout)console.error(error.stdout);throw error;}finally{await server[Symbol.asyncDispose]();}
