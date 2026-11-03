<?php
/** Isolated synthetic WordPress stubs; no HTTP, database, or site access. */
const ABSPATH='/';
class WP_Error { public function __construct(public $code, public $message='',public $data=null){} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$x){} function add_filter(...$x){} function apply_filters($tag,$value,...$x){return $value;}
$GLOBALS['caps']=['manage_options'=>true,'unfiltered_html'=>true];
function current_user_can($cap){return !empty($GLOBALS['caps'][$cap]);}
function wp_verify_nonce($nonce,$action){return $nonce === 'nonce:'.$action;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower($x));}
function is_email($x){return filter_var($x,FILTER_VALIDATE_EMAIL);}
function shiki_defaults(){return ['timezone'=>'Asia/Tokyo','enabled'=>false,'count'=>1,'tabs'=>['home'=>'Home'],'ads_slots'=>[],'ads_cmp_code'=>'','api_key'=>'','season_events'=>[['id'=>'spring','enabled'=>true]]];}
require defined('SHIKI_TOOLS_FILE') ? SHIKI_TOOLS_FILE : __DIR__ . '/../../shikipress/inc/tools.php';
$n=0; function ok($x,$name){global $n; ++$n;if(!$x)throw new RuntimeException('FAIL '.$name);}
foreach(['scan','external','export','preview','apply','restore'] as $action){
 ok(shiki_tools_authorize($action,'nonce:shiki_tools_'.$action)===true,'valid '.$action);
 ok(is_wp_error(shiki_tools_authorize($action,'nonce:shiki_tools_other')),'wrong nonce '.$action);
 $GLOBALS['caps']['manage_options']=false;
 ok(is_wp_error(shiki_tools_authorize($action,'nonce:shiki_tools_'.$action)),'capability required '.$action);
 $GLOBALS['caps']['manage_options']=true;
}
function payload($settings,$kind='settings'){return json_encode(['format'=>'shikipress-settings','schema_version'=>1,'theme_version'=>'1.1.4','kind'=>$kind,'settings'=>$settings]);}
ok(!is_wp_error(shiki_backup_parse(payload(['timezone'=>'Asia/Tokyo']))),'valid ordinary settings');
foreach([['unknown'=>'x'],['api_key'=>'secret'],['enabled'=>'true'],['count'=>[]],['timezone'=>['a']],['tabs'=>['bad'=>'x']],['timezone'=>'<script>alert(1)</script>'],['timezone'=>'javascript:alert(1)'],['timezone'=>'x@example.com'],['ads_slots'=>[]]] as $data){ok(is_wp_error(shiki_backup_parse(payload($data))),'malformed private/executable ordinary '.json_encode($data));}
ok(is_wp_error(shiki_backup_parse(str_repeat(' ',524289))),'oversize');ok(is_wp_error(shiki_backup_parse('{')),'bad json');
$GLOBALS['caps']['unfiltered_html']=false;ok(is_wp_error(shiki_backup_parse(payload(['ads_cmp_code'=>'<script>1</script>'],'executable-ad-code'))),'code capability');
$GLOBALS['caps']['unfiltered_html']=true;ok(!is_wp_error(shiki_backup_parse(payload(['ads_cmp_code'=>'<script>1</script>'],'executable-ad-code'))),'explicit code capability');
$disarmed=shiki_backup_disarm(['ads_mode'=>'production','ads_live_approved'=>true,'ads_auto'=>true,'ads_slots'=>[['trusted'=>true]],'view_count_enabled'=>true,'view_count_consent_required'=>false]);
ok($disarmed['ads_mode']==='test' && !$disarmed['ads_auto'] && !$disarmed['ads_live_approved'] && !$disarmed['ads_slots'][0]['trusted'] && !$disarmed['view_count_enabled'] && $disarmed['view_count_consent_required'],'disarm execution');
foreach(['127.0.0.1','10.1.2.3','169.254.169.254','192.168.1.1','100.64.0.1','224.1.2.3','::1','::ffff:127.0.0.1','fe80::1','fc00::1','2001:db8::1','2002:7f00:1::','3fff::1'] as $ip)ok(!shiki_link_public_ip($ip),'private reserved '.$ip);
foreach(['8.8.8.8','93.184.216.34','2606:4700:4700::1111'] as $ip)ok(shiki_link_public_ip($ip),'public '.$ip);
foreach(['http://127.0.0.1/','http://169.254.169.254/','http://[::1]/','http://2130706433/','http://127.1/','http://localhost/','http://metadata.google.internal/','file:///etc/passwd','gopher://8.8.8.8/','https://user:pass@public.example/','https://public.example:8080/','https://public.example./','https://public.example/\\foo'] as $url)ok(is_wp_error(shiki_link_safe_target($url)),'unsafe URL '.$url);
echo json_encode(['status'=>'PASS','checks'=>$n,'php'=>PHP_VERSION]);
