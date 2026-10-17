<?php
/** Standalone focused tests. Native WordPress integration is in integration.php. */
if ( ! defined( 'SHIKI_TEST_FIXTURES' ) ) {
    define( 'SHIKI_TEST_FIXTURES', $argv[1] );
    define( 'SHIKI_UPDATER_FILE', dirname( __DIR__, 2 ) . '/shikipress/inc/updater.php' );
}
define( 'ABSPATH', sys_get_temp_dir() . '/shiki-updater-unit-' . getmypid() . '/' );
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/file.php', '<?php' );
define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error { public $code; public function __construct( $code, $message = '' ) { $this->code = $code; } }
class Theme_Upgrader {}
function __( $s, $domain = '' ) { return $s; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_filter( ...$x ) {}
function add_action( ...$x ) {}
function apply_filters( $name, $value ) { return $value; }
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function get_template() { return $GLOBALS['active_template'] ?? 'shikipress'; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function wp_salt( $x ) { return 'local-test-only-fixture'; }
function get_site_transient( $x ) { return $GLOBALS['cache'][ $x ] ?? false; }
function set_site_transient( $x, $v, $ttl = 0 ) { $GLOBALS['cache'][ $x ] = $v; $GLOBALS['ttls'][ $x ] = $ttl; }
function wp_parse_url( $x ) { return parse_url( $x ); }
function wp_remote_retrieve_response_code( $x ) { return $x['response']['code']; }
function wp_remote_retrieve_body( $x ) { return $x['body']; }
function wp_remote_retrieve_header( $x, $h ) { return $x['headers'][ $h ] ?? ''; }
function wp_tempnam( $x ) { return tempnam( ABSPATH, 'download-' ); }
function wp_delete_file( $x ) { unlink( $x ); }
function is_wp_version_compatible( $x ) { return version_compare( '7.1.3', $x, '>=' ); }
function is_php_version_compatible( $x ) { return version_compare( PHP_VERSION, $x, '>=' ); }
function wp_get_theme( $x ) { return new class { function get( $key ) { return $GLOBALS['installed'][ $key ]; } }; }
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['calls'][] = array( $url, $args );
    $r = $GLOBALS['http'][ $url ] ?? new WP_Error( 'unexpected_request' );
    if ( ! is_wp_error( $r ) && ! empty( $args['stream'] ) ) {
        file_put_contents( $args['filename'], $r['body'] );
        $r['body'] = '';
    }
    return $r;
}
require SHIKI_UPDATER_FILE;
$passed = 0;
function check( $truth, $label ) { global $passed; if ( ! $truth ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$passed; }
function response( $body, $code = 200, $headers = array() ) { return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => $body ); }
function seed( $archive = 'valid', $changes = array(), $release_changes = array() ) {
    $base = ShikiPress_Updater::URI;
    $zip = file_get_contents( SHIKI_TEST_FIXTURES . '/' . $archive . '.zip' );
    $meta = array_merge( array( 'schema' => 1, 'theme' => 'shikipress', 'version' => '2.0.0', 'requires' => '6.6', 'requires_php' => '8.2', 'tested' => '7.1.3', 'package' => $base . '/releases/download/v2.0.0/shikipress-2.0.0.zip', 'sha256' => hash( 'sha256', $zip ), 'size' => strlen( $zip ) ), $changes );
    $manifest = json_encode( $meta );
    $meta_url = $base . '/releases/download/v2.0.0/shikipress-update.json';
    $zip_url = $base . '/releases/download/v2.0.0/shikipress-2.0.0.zip';
    $release = array_merge( array( 'tag_name' => 'v2.0.0', 'draft' => false, 'prerelease' => false, 'html_url' => $base . '/releases/tag/v2.0.0', 'assets' => array(
        array( 'name' => 'shikipress-update.json', 'state' => 'uploaded', 'browser_download_url' => $meta_url, 'size' => strlen( $manifest ), 'digest' => 'sha256:' . hash( 'sha256', $manifest ) ),
        array( 'name' => 'shikipress-2.0.0.zip', 'state' => 'uploaded', 'browser_download_url' => $zip_url, 'size' => strlen( $zip ), 'digest' => 'sha256:' . hash( 'sha256', $zip ) ),
    ) ), $release_changes );
    $GLOBALS['cache'] = $GLOBALS['calls'] = array();
    $GLOBALS['http'] = array( ShikiPress_Updater::API => response( json_encode( $release ) ), $meta_url => response( $manifest ), $zip_url => response( $zip ) );
    $GLOBALS['installed'] = array( 'Version' => '1.1.0', 'UpdateURI' => $base );
    return $meta;
}
$data = seed();
$valid = ShikiPress_Updater::release();
check( ! is_wp_error( $valid ) && '2.0.0' === $valid['version'], 'valid release metadata' );
check( 21600 === $GLOBALS['ttls'][ ShikiPress_Updater::CACHE ], 'six-hour success cache' );
check( 2 === count( $GLOBALS['calls'] ), 'API and metadata only' );
ShikiPress_Updater::release(); check( 2 === count( $GLOBALS['calls'] ), 'success cache avoids HTTP' );
$GLOBALS['cache'][ ShikiPress_Updater::CACHE ]['data']['sha256'] = str_repeat( '0', 64 );
ShikiPress_Updater::release(); check( 4 === count( $GLOBALS['calls'] ), 'tampered signed cache is discarded' );
$headers = array( 'Version' => '1.1.0', 'UpdateURI' => ShikiPress_Updater::URI );
$offer = ShikiPress_Updater::filter_update( false, $headers, 'shikipress', array() );
check( '2.0.0' === $offer['version'] && false === $offer['autoupdate'] && '6.6' === $offer['requires'], 'native response fields' );
check( 'original' === ShikiPress_Updater::filter_update( 'original', $headers, 'other', array() ), 'other theme unchanged' );
check( 'original' === ShikiPress_Updater::filter_update( 'original', array( 'UpdateURI' => 'https://github.com/other/theme' ), 'shikipress', array() ), 'other repository unchanged' );
foreach ( array( '2.0.0', '3.0.0' ) as $current ) { $headers['Version'] = $current; check( false === ShikiPress_Updater::filter_update( false, $headers, 'shikipress', array() ), 'no same-version or downgrade offer' ); }
foreach ( $GLOBALS['calls'] as list( $url, $args ) ) {
    check( 'ShikiPress-Updater/1' === $args['user-agent'] && array() === $args['cookies'] && ! isset( $args['body'] ), 'no site identifiers or cookies' );
    check( true === $args['sslverify'] && 0 === $args['redirection'] && $args['timeout'] <= 5 && $args['limit_response_size'] <= 524289, 'bounded safe JSON transport' );
}
$metadata_bad = array( array( 'schema' => '1' ), array( 'theme' => 'other' ), array( 'version' => '2.0.0-rc.1' ), array( 'version' => '02.0.0' ), array( 'package' => 'https://evil.example/zip' ), array( 'size' => 1 ), array( 'sha256' => str_repeat( '0', 64 ) ), array( 'requires' => '6.6<script>' ), array( 'requires_php' => '7.4' ), array( 'tested' => '5.0' ) );
foreach ( $metadata_bad as $bad ) { seed( 'valid', $bad ); check( is_wp_error( ShikiPress_Updater::release() ), 'bad metadata ' . json_encode( $bad ) ); }
foreach ( array( array( 'draft' => true ), array( 'prerelease' => true ), array( 'tag_name' => 'v2.0.0-rc.1' ), array( 'tag_name' => '2.0.0' ), array( 'html_url' => 'https://github.com/other/repo/releases/tag/v2.0.0' ) ) as $bad ) { seed( 'valid', array(), $bad ); check( is_wp_error( ShikiPress_Updater::release() ), 'bad release ' . json_encode( $bad ) ); }
seed(); $GLOBALS['http'][ ShikiPress_Updater::API ] = response( 'Rate limited', 403 );
check( is_wp_error( ShikiPress_Updater::release() ) && 900 === $GLOBALS['ttls'][ ShikiPress_Updater::CACHE ], 'error cache fifteen minutes' );
ShikiPress_Updater::release(); check( 1 === count( $GLOBALS['calls'] ), 'error cache avoids repeated HTTP' );
seed(); $GLOBALS['http'][ ShikiPress_Updater::API ] = response( str_repeat( 'x', 524289 ) ); check( is_wp_error( ShikiPress_Updater::release() ), 'oversized API' );
seed(); $GLOBALS['http'][ ShikiPress_Updater::API ] = response( '{broken' ); check( is_wp_error( ShikiPress_Updater::release() ), 'malformed API JSON' );
$meta_url = ShikiPress_Updater::URI . '/releases/download/v2.0.0/shikipress-update.json';
foreach ( array( 'https://evil.example/a', 'http://release-assets.githubusercontent.com/a', 'https://github.com/other/repo/a', 'https://release-assets.githubusercontent.com:444/a', 'https://user:pass@release-assets.githubusercontent.com/a', 'https://release-assets.githubusercontent.com.evil.example/a', '/relative' ) as $location ) {
    seed(); $GLOBALS['http'][ $meta_url ] = response( '', 302, array( 'location' => $location ) ); check( is_wp_error( ShikiPress_Updater::release() ) && 2 === count( $GLOBALS['calls'] ), 'unsafe redirect blocked before request: ' . $location );
}
seed(); $cdn = 'https://release-assets.githubusercontent.com/asset?signature=fixture'; $GLOBALS['http'][ $cdn ] = $GLOBALS['http'][ $meta_url ]; $GLOBALS['http'][ $meta_url ] = response( '', 302, array( 'location' => $cdn ) ); check( ! is_wp_error( ShikiPress_Updater::release() ), 'official asset redirect accepted' );
$normal = array( 'theme' => 'shikipress', 'type' => 'theme', 'action' => 'update' );
foreach ( array( $normal, array( 'theme' => 'shikipress' ) ) as $extra ) {
    $data = seed(); $path = ShikiPress_Updater::pre_download( false, $data['package'], new Theme_Upgrader(), $extra );
    check( is_string( $path ) && is_file( $path ), 'verified package returned for single/bulk' ); unlink( $path );
}
$data = seed(); check( 'keep' === ShikiPress_Updater::pre_download( 'keep', 'file.zip', new Theme_Upgrader(), array( 'action' => 'install', 'type' => 'theme' ) ), 'manual ZIP import untouched' );
check( 'keep' === ShikiPress_Updater::pre_download( 'keep', $data['package'], new stdClass(), $normal ), 'other upgrader untouched' );
check( 'keep' === ShikiPress_Updater::pre_download( 'keep', $data['package'], new Theme_Upgrader(), array( 'theme' => 'other' ) ), 'other theme untouched' );
check( is_wp_error( ShikiPress_Updater::pre_download( false, 'https://evil.example/zip', new Theme_Upgrader(), $normal ) ), 'wrong package fails closed' );
$data = seed(); $GLOBALS['installed']['Version'] = '3.0.0'; check( is_wp_error( ShikiPress_Updater::pre_download( false, $data['package'], new Theme_Upgrader(), $normal ) ), 'install cannot downgrade' );
foreach ( array( array( 'requires' => '99.0', 'tested' => '99.0' ), array( 'requires_php' => '99.0' ) ) as $future ) { $data = seed( 'valid', $future ); check( is_wp_error( ShikiPress_Updater::pre_download( false, $data['package'], new Theme_Upgrader(), $normal ) ) && 2 === count( $GLOBALS['calls'] ), 'incompatible runtime blocks ZIP fetch' ); }
$data = seed(); $GLOBALS['http'][ $data['package'] ]['body'] .= 'tampered'; check( is_wp_error( ShikiPress_Updater::pre_download( false, $data['package'], new Theme_Upgrader(), $normal ) ) && array() === glob( ABSPATH . 'download-*' ), 'corrupt ZIP rejected and temp removed' );
foreach ( glob( SHIKI_TEST_FIXTURES . '/*.zip' ) as $file ) {
    $name = basename( $file, '.zip' ); $data = seed( $name ); $result = ShikiPress_Updater::validate_archive( $file, $data );
    check( str_starts_with( $name, 'valid' ) ? true === $result : is_wp_error( $result ), 'archive fixture ' . $name );
}
$data = seed(); $GLOBALS['multisite'] = true;
check( false === ShikiPress_Updater::filter_update( array( 'package' => 'old' ), array( 'Version' => '1.1.0', 'UpdateURI' => ShikiPress_Updater::URI ), 'shikipress', array() ) && 0 === count( $GLOBALS['calls'] ), 'multisite never advertises a theme-only update' );
check( is_wp_error( ShikiPress_Updater::pre_download( false, $data['package'], new Theme_Upgrader(), $normal ) ) && 0 === count( $GLOBALS['calls'] ), 'multisite native update fails closed without HTTP' );
check( 'keep' === ShikiPress_Updater::pre_download( 'keep', 'local.zip', new Theme_Upgrader(), array( 'action' => 'install', 'type' => 'theme' ) ), 'multisite manual imports unchanged' );
$GLOBALS['multisite'] = false;
$GLOBALS['active_template'] = 'other';
check( false === ShikiPress_Updater::filter_update( array( 'package' => 'old' ), array( 'Version' => '1.1.0', 'UpdateURI' => ShikiPress_Updater::URI ), 'shikipress', array() ), 'inactive provider cannot advertise a stale offer in a switch request' );
unset( $GLOBALS['active_template'] );
$other_theme = new class { function get_template() { return 'other'; } };
$child_theme = new class { function get_template() { return 'shikipress'; } };
$GLOBALS['cache']['update_themes'] = (object) array( 'response' => array( 'shikipress' => array( 'package' => 'fixture' ), 'other' => array( 'package' => 'other' ) ), 'no_update' => array( 'shikipress' => array(), 'other' => array() ), 'checked' => array( 'shikipress' => '1.1.0' ) );
ShikiPress_Updater::switched_theme( 'Child', $child_theme );
check( isset( $GLOBALS['cache']['update_themes']->response['shikipress'] ), 'parent-child switch retains verified provider offer' );
ShikiPress_Updater::switched_theme( 'Other', $other_theme );
check( ! isset( $GLOBALS['cache']['update_themes']->response['shikipress'] ) && ! isset( $GLOBALS['cache']['update_themes']->no_update['shikipress'] ), 'switch away removes stale ShikiPress offers' );
check( isset( $GLOBALS['cache']['update_themes']->response['other'] ) && isset( $GLOBALS['cache']['update_themes']->no_update['other'] ) && '1.1.0' === $GLOBALS['cache']['update_themes']->checked['shikipress'], 'switch cleanup preserves unrelated themes and checked versions' );
echo json_encode( array( 'php' => PHP_VERSION, 'passed' => $passed, 'status' => 'PASS' ) ) . "\n";
