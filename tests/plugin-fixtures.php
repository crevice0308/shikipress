<?php
/** Dedicated local compatibility data, no real mail or third-party services. */
if(!defined('SHIKI_TEST_ENV')||!SHIKI_TEST_ENV||DB_NAME!=='shikipress_audit'){WP_CLI::error('Dedicated audit environment required');}
wp_set_current_user(1);
$prior=get_option('shiki_plugin_audit_fixtures',[]);foreach($prior['posts']??[]as$id){wp_delete_post($id,true);}if(!empty($prior['form'])){wp_delete_post($prior['form'],true);}if(!empty($prior['table'])&&class_exists('TablePress')){TablePress::$model_table->delete($prior['table']);}
$form=WPCF7_ContactForm::get_template(['locale'=>'ja','title'=>'TEST ShikiPress plugin audit']);
$props=$form->get_properties();$props['form']=<<<'FORM'
<label>お名前（必須）[text* your-name autocomplete:name]</label>
<label>メールアドレス（必須）[email* your-email autocomplete:email]</label>
<label>お問い合わせの種類[select subject "暮らしの記事について" "技術について" "その他"]</label>
<fieldset><legend>ご希望の季節</legend>[checkbox seasons use_label_element "春" "夏" "秋" "冬"]</fieldset>
<fieldset><legend>ご返信の希望</legend>[radio reply use_label_element default:1 "希望する" "希望しない"]</fieldset>
<label>お問い合わせ内容（必須）[textarea* your-message]</label>
[acceptance privacy]個人情報の取り扱いを確認しました[/acceptance]
[submit "確認して送信する"]
FORM;
$props['mail']['recipient']='audit@example.invalid';$props['mail']['sender']='ShikiPress TEST <wordpress@example.invalid>';$props['mail']['subject']='TEST local audit';$props['mail']['body']='[your-name] [your-email] [your-message]';$form->set_properties($props);$form->save();$form=WPCF7_ContactForm::get_instance($form->id());
$table=TablePress::$model_table->get_table_template();$table['name']='TEST 四季比較表';$table['description']='TEST only';$table['data']=[['季節','温度','湿度','衣類','収納','掃除','注意点','備考']];for($n=1;$n<=25;$n++){$table['data'][]=["TEST $n",'20℃','60%','長い日本語のセル内容を確認','風通しの良い場所','週一回','TEST注意点','TEST備考'];}$table['visibility']=['rows'=>array_fill(0,count($table['data']),1),'columns'=>array_fill(0,8,1)];$table['options']['datatables_scrollx']=true;$table['options']['datatables_locale']='ja';$table['options']['use_datatables']=true;$tid=TablePress::$model_table->add($table);if(is_wp_error($tid)){WP_CLI::error($tid->get_error_message());}
$posts=[];$create=function($slug,$title,$body)use(&$posts){$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'TEST '.$title,'post_name'=>$slug,'post_content'=>$body,'meta_input'=>['_shiki_plugin_audit'=>1]]);$posts[]=$id;return$id;};
$contact=$create('test-plugin-contact','お問い合わせプラグイン','<h2 id="form-title">お問い合わせ</h2><p>TEST：実メールは送信しません。</p>[contact-form-7 id="'.$form->id().'" title="TEST"]<h2>送信について</h2><p>検証専用です。</p>');
$block=$create('test-plugin-contact-block','ブロックフォーム','<!-- wp:heading --><h2>ブロックのお問い合わせ</h2><!-- /wp:heading --><!-- wp:contact-form-7/contact-form-selector {"id":'.$form->id().',"hash":"'.$form->hash().'","title":"TEST"} --><div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="'.$form->hash().'" title="TEST"]</div><!-- /wp:contact-form-7/contact-form-selector --><!-- wp:heading --><h2>補足</h2><!-- /wp:heading -->');
$tablepage=$create('test-plugin-table','表プラグイン','<h2>四季の比較</h2>[table id='.$tid.' /]<h2>表の説明</h2><p>TEST only</p>');
$noindex=$create('test-plugin-noindex','掲載抑止テスト','TEST noindex body');update_post_meta($noindex,'_shiki_noindex',1);
$excluded=$create('test-plugin-excluded','一覧除外テスト','TEST excluded body');update_post_meta($excluded,'_shiki_exclude',1);
update_option('blog_public',1);update_option('shiki_audit_mail_calls',0);update_option('shiki_audit_mail_outcome','success');
$state=['posts'=>$posts,'form'=>$form->id(),'table'=>$tid,'contact'=>$contact,'block'=>$block,'table_page'=>$tablepage,'noindex'=>$noindex,'excluded'=>$excluded];update_option('shiki_plugin_audit_fixtures',$state,false);echo wp_json_encode($state,JSON_PRETTY_PRINT).PHP_EOL;
