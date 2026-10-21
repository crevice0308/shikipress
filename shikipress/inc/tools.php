<?php
/** Administrator diagnostics, bounded link checks and private settings migration. */
defined( 'ABSPATH' ) || exit;

add_action( 'shiki_admin_tab_diagnostics', 'shiki_tools_diagnostics_page' );
add_action( 'shiki_admin_tab_backup', 'shiki_tools_backup_page' );
foreach ( array( 'scan', 'external', 'export', 'preview', 'apply', 'restore' ) as $shiki_tools_action ) {
	add_action( 'admin_post_shiki_tools_' . $shiki_tools_action, 'shiki_tools_action_' . $shiki_tools_action );
}
unset( $shiki_tools_action );

/** Capabilities and nonce are independent requirements. Never register nopriv handlers. */
function shiki_tools_authorize( $action, $nonce = null ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', 'この操作には管理者権限が必要です。' );
	}
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'shiki_tools_' . $action ) ) {
		return new WP_Error( 'nonce', '操作の有効期限が切れています。画面を再読み込みしてください。' );
	}
	return true;
}
function shiki_tools_guard( $action ) {
	$result = shiki_tools_authorize( $action, isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : null );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
	}
}
function shiki_tools_form( $action ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="shiki_tools_' . esc_attr( $action ) . '">';
	wp_nonce_field( 'shiki_tools_' . $action );
}
function shiki_tools_redirect( $tab, $notice ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'shikipress', 'tab' => $tab, 'shiki_notice' => $notice ), admin_url( 'admin.php' ) ) );
	exit;
}
function shiki_tools_notice() {
	$messages = array( 'scanned' => '内部リンクを最大40記事分検査しました。', 'external' => '外部キューを処理しました。', 'rate' => '外部検査は60秒以上空けて実行してください。', 'locked' => '他の検査が実行中です。', 'preview' => '設定を検証しました。プレビューを確認して適用対象を選んでください。', 'applied' => '選択した設定を適用しました。広告・実行コード・任意の閲覧集計はテスト・無効状態です。', 'restored' => '適用前の設定を復元しました。広告・実行コード・任意の閲覧集計はテスト・無効状態です。' );
	$key = isset( $_GET['shiki_notice'] ) ? sanitize_key( wp_unslash( $_GET['shiki_notice'] ) ) : '';
	if ( isset( $messages[ $key ] ) ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
	}
}

/** Do not expose machine paths, keys or server environment values in diagnostics. */
function shiki_tools_diagnostics() {
	$rows = array();
	$add = static function ( $name, $state, $detail ) use ( &$rows ) { $rows[] = compact( 'name', 'state', 'detail' ); };
	$add( 'PHP', version_compare( PHP_VERSION, '8.2', '>=' ) ? 'ok' : 'error', PHP_VERSION . '（必要: 8.2以上）' );
	$add( 'WordPress', version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ? 'ok' : 'error', get_bloginfo( 'version' ) . '（必要: 6.6以上）' );
	foreach ( array( 'json', 'mbstring', 'curl', 'openssl', 'dom' ) as $ext ) {
		$add( 'PHP拡張: ' . $ext, extension_loaded( $ext ) ? 'ok' : ( 'mbstring' === $ext ? 'error' : 'notice' ), extension_loaded( $ext ) ? '利用可能' : '未導入。関連機能の利用可否を確認してください。' );
	}
	$cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$add( 'Cron', $cron ? 'unconfirmed' : 'ok', $cron ? 'WordPress Cronは無効。サーバー側Cronの実行状況はこの画面から確認できません。' : 'WordPress Cron有効。実際の実行成功を保証するものではありません。' );
	$due = 0;
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		if ( (int) $timestamp < time() - 3600 ) { $due += count( $hooks ); }
	}
	$add( '定期処理の遅延', $due ? 'notice' : 'ok', $due ? $due . '件のフックが1時間以上遅延。アクセス頻度・サーバーCronをご確認ください。' : '1時間以上遅延したフックは検出していません。' );
	$public = (bool) get_option( 'blog_public' );
	$add( '検索エンジン公開設定', $public ? 'ok' : 'notice', $public ? '検索エンジンへの表示を許可しています。インデックス登録は別途確認が必要です。' : '検索エンジンへの表示を抑制しています。テストサイトではこの設定を維持できます。' );
	$sitemaps = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server()->sitemaps_enabled() : false;
	$add( 'XMLサイトマップ', $sitemaps ? 'ok' : 'unconfirmed', $sitemaps ? ( function_exists( 'shiki_xml_sitemap_url' ) ? shiki_xml_sitemap_url() : home_url( '/wp-sitemap.xml' ) ) . '（設定状態のみ。HTTP到達性は未検査）' : 'コアサイトマップは無効。SEOプラグインによる提供状況は要確認。' );
	$add( 'パーマリンク', get_option( 'permalink_structure' ) ? 'ok' : 'notice', get_option( 'permalink_structure' ) ? 'カスタム構造を設定済み。リライトルールのHTTP動作は別途確認してください。' : '基本形式を使用中。投稿リンク自体は基本形式でも利用できます。' );
	$add( 'キャッシュ', 'unconfirmed', 'オブジェクトキャッシュ: ' . ( wp_using_ext_object_cache() ? '外部あり' : '標準' ) . ' / ページキャッシュ定数: ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? '有効' : '無効' ) . '。サーバー・CDN側キャッシュと期限は未確認。' );
	$settings = array_merge( shiki_defaults(), (array) get_option( 'shikipress_settings', array() ) );
	$roles = isset( $settings['page_roles'] ) ? (array) $settings['page_roles'] : array();
	foreach ( array( 'operator' => '運営者情報', 'about' => 'このサイトについて', 'privacy' => 'プライバシーポリシー', 'contact' => 'お問い合わせ', 'disclaimer' => '免責事項', 'sitemap' => 'サイト案内' ) as $role => $label ) {
		$id = isset( $roles[ $role ] ) ? absint( $roles[ $role ] ) : 0;
		$post = $id ? get_post( $id ) : null;
		$valid = $post && 'page' === $post->post_type && 'publish' === $post->post_status && ! $post->post_password;
		$add( $label, $valid ? 'ok' : 'notice', $valid ? '公開固定ページを設定済み。本文内容の適切さは運営者が確認してください。' : '対応する公開固定ページが未設定です。ナビゲーションで割り当ててください。' );
	}
	$add( 'ロゴ', has_custom_logo() ? 'ok' : 'notice', has_custom_logo() ? '設定済み' : '未設定。サイト名のテキストを使用します。' );
	if ( function_exists( 'shiki_season_terms' ) ) {
		$terms = shiki_season_terms();
		$add( '四季カテゴリー', count( array_filter( (array) $terms ) ) === 4 ? 'ok' : 'notice', count( array_filter( (array) $terms ) ) . '/4季節が対応付け済み。作成は記事自動化画面から明示的に実行します。' );
	}
	$add( '広告モード', isset( $settings['ads_mode'] ) && 'production' === $settings['ads_mode'] ? 'notice' : 'ok', '現在: ' . sanitize_key( isset( $settings['ads_mode'] ) ? $settings['ads_mode'] : '未設定' ) . '。広告配信・同意取得・審査の成否はこの画面では判定しません。' );
	$seen = array(); $duplicates = 0;
	foreach ( isset( $settings['ads_slots'] ) ? (array) $settings['ads_slots'] : array() as $slot ) {
		if ( ! is_array( $slot ) ) { continue; }
		$code = isset( $slot['code'] ) ? trim( (string) $slot['code'] ) : '';
		if ( $code ) { $hash = hash( 'sha256', $code ); if ( isset( $seen[ $hash ] ) ) { ++$duplicates; } $seen[ $hash ] = true; }
	}
	$add( '広告タグ重複', $duplicates ? 'notice' : 'unconfirmed', $duplicates ? $duplicates . '件の完全一致タグ重複を検出。意図した配置か確認してください。' : 'テーマ設定内の完全一致重複は未検出。プラグイン・外部自動広告・実配信HTMLは別途確認が必要です。' );
	$active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	$seo_plugins = array_filter( $active, static function ( $path ) { return (bool) preg_match( '/wordpress-seo|all-in-one-seo|seo-by-rank-math|the-seo-framework/i', $path ); } );
	$add( 'SEOの重複候補', $seo_plugins ? 'notice' : 'unconfirmed', $seo_plugins ? 'SEOプラグインを検出。テーマSEO出力の停止設定と実際のhead出力を確認してください。' : '既知のSEOプラグインは未検出。他プラグインの出力は未確認。' );
	return apply_filters( 'shiki_diagnostics', $rows );
}
function shiki_tools_diagnostics_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	shiki_tools_notice();
	echo '<h2>自己診断</h2><p>検出できる設定状態を表示します。全不具合・脆弱性を検出する監査ではありません。秘密情報は表示しません。</p><div class="shiki-diagnostics">';
	$labels = array( 'ok' => '正常', 'notice' => '注意', 'error' => 'エラー', 'unconfirmed' => '未確認' );
	foreach ( shiki_tools_diagnostics() as $row ) {
		echo '<div class="shiki-diagnostic"><h3>' . esc_html( $row['name'] ) . '</h3><p><strong>' . esc_html( isset( $labels[ $row['state'] ] ) ? $labels[ $row['state'] ] : '未確認' ) . '</strong> — ' . esc_html( $row['detail'] ) . '</p></div>';
	}
	echo '</div><h2>リンク検査</h2><p>公開済み・パスワードなしの記事と固定ページを40件ずつ検査します。内部リンクはDBとローカルファイルで照合し、外部へのHTTP要求を送りません。独自ルーターなど判断できないURLは「要確認」です。</p>';
	$state = shiki_links_state();
	shiki_tools_form( 'scan' );
	echo '<label><input type="checkbox" name="reset" value="1">最初から再検査（既存結果・外部キューをクリア）</label><p>';
	submit_button( '内部リンクを次の40件検査', 'secondary', 'submit', false ); echo '</p></form>';
	echo '<p>確認済み記事: ' . esc_html( $state['offset'] ) . ' / 検査: ' . ( $state['complete'] ? '一巡完了' : '続きあり' ) . ' / 外部キュー: ' . esc_html( count( $state['queue'] ) ) . '件（上限100件・超過 ' . esc_html( $state['dropped'] ) . '件）</p>';
	echo '<p>外部検査は管理者が下のボタンを押した時だけ実行します。1回最大2URL、同時接続1、60秒間隔、HTTP待ち時間4秒、リダイレクト最大2回、レスポンス1KBまで。403・429・タイムアウトは要確認として最大1回再試行します。</p>';
	shiki_tools_form( 'external' ); submit_button( '外部キューを2件処理', 'secondary' ); echo '</form>';
	$all = array_merge( $state['results'], $state['queue'] );
	if ( $all ) {
		echo '<ul class="shiki-link-results">';
		foreach ( array_slice( $all, -200 ) as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : 'queued';
			$names = array( 'missing' => '見つからない', 'unresolved' => '要確認（未解決）', 'unsafe' => '取得拒否（安全対策）', 'ok' => '到達確認', 'queued' => '待機中', 'uncertain' => '要確認', 'broken' => 'HTTP 404/410', 'redirect' => 'リダイレクト要確認' );
			echo '<li><strong>' . esc_html( isset( $names[ $status ] ) ? $names[ $status ] : $status ) . '</strong> <code style="overflow-wrap:anywhere">' . esc_html( shiki_link_display_url( $item['url'] ) ) . '</code>';
			if ( ! empty( $item['detail'] ) ) { echo ' — ' . esc_html( $item['detail'] ); }
			foreach ( array_slice( isset( $item['sources'] ) ? $item['sources'] : array(), 0, 5 ) as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) { echo ' <a href="' . esc_url( get_edit_post_link( $id ) ) . '">記事 #' . esc_html( $id ) . 'を編集</a>'; }
			}
			echo '</li>';
		}
		echo '</ul><p>結果は最大200件を表示します。保存は内部結果1,000件・外部URL100件までです。</p>';
	}
}

function shiki_links_state() {
	return array_merge( array( 'offset' => 0, 'complete' => false, 'results' => array(), 'queue' => array(), 'dropped' => 0 ), (array) get_option( 'shikipress_link_state', array() ) );
}
function shiki_link_display_url( $url ) {
	$url = preg_replace( '~^(https?://)[^/@]+@~i', '$1[redacted]@', $url );
	return preg_replace( '/([?&](?:api[_-]?key|key|token|secret|password|access_token|auth)=)[^&#]*/i', '$1[redacted]', $url );
}
function shiki_link_absolute( $url, $base ) {
	$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $url || '#' === $url[0] || preg_match( '/^(?:mailto|tel|data|javascript|sms):/i', $url ) ) { return ''; }
	$url = WP_Http::make_absolute_url( $url, $base );
	$parts = wp_parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) { return ''; }
	return preg_replace( '/#.*$/s', '', $url );
}
/** Returns a local resolution state, not an unauthenticated fetch endpoint. */
function shiki_link_internal_status( $url ) {
	$home = wp_parse_url( home_url( '/' ) ); $parts = wp_parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) { return 'external'; }
	$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home['scheme'] ? 443 : 80 );
	$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ? 443 : 80 );
	if ( $port !== $home_port ) { return 'unresolved'; }
	$path = rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '/' );
	$base_path = rtrim( isset( $home['path'] ) ? $home['path'] : '', '/' );
	if ( false !== strpos( $path, "\0" ) || preg_match( '~(?:^|/)\.\.(?:/|$)~', $path ) ) { return 'unresolved'; }
	if ( '' !== $base_path && 0 !== strpos( trailingslashit( $path ), trailingslashit( $base_path ) ) ) { return 'unresolved'; }
	$relative = trim( substr( $path, strlen( $base_path ) ), '/' );
	$query = array(); parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
	if ( isset( $query['s'] ) || isset( $query['rest_route'] ) || isset( $query['feed'] ) || preg_match( '~^(?:wp-admin|wp-json|wp-login\.php)(?:/|$)~', $relative ) ) { return 'unresolved'; }
	$id = url_to_postid( $url );
	if ( $id ) { return 'publish' === get_post_status( $id ) ? 'ok' : 'missing'; }
	if ( isset( $query['p'] ) || isset( $query['page_id'] ) || isset( $query['attachment_id'] ) ) { return 'missing'; }
	if ( '' === $relative ) { return 'ok'; }
	if ( in_array( $relative, array( 'wp-sitemap.xml', 'robots.txt', 'feed', 'sitemap.xml' ), true ) ) { return 'unresolved'; }
	$page = get_page_by_path( $relative, OBJECT, array( 'page', 'post' ) );
	if ( $page ) { return 'publish' === $page->post_status ? 'ok' : 'missing'; }
	foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
		$term = get_term_by( 'slug', basename( $relative ), $taxonomy );
		if ( $term ) { $term_url = get_term_link( $term ); if ( ! is_wp_error( $term_url ) && untrailingslashit( wp_parse_url( $term_url, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) { return 'ok'; } }
	}
	if ( preg_match( '~^(?:author/|\d{4}(?:/|$)|page/\d+)~', $relative ) ) { return 'unresolved'; }
	$uploads = wp_upload_dir( null, false );
	$upload_path = rawurldecode( wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
	if ( $upload_path && 0 === strpos( $path, trailingslashit( $upload_path ) ) ) {
		$file = $uploads['basedir'] . '/' . substr( $path, strlen( trailingslashit( $upload_path ) ) );
		return is_file( $file ) ? 'ok' : 'missing';
	}
	if ( preg_match( '~^(?:wp-content|wp-includes)/~', $relative ) ) {
		return is_file( ABSPATH . $relative ) ? 'ok' : 'unresolved';
	}
	return 'unresolved';
}
function shiki_links_scan_batch( $reset = false ) {
	$state = $reset ? array( 'offset' => 0, 'complete' => false, 'results' => array(), 'queue' => array(), 'dropped' => 0 ) : shiki_links_state();
	$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 40, 'offset' => $state['offset'], 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ) );
	foreach ( $posts as $post ) {
		$processor = new WP_HTML_Tag_Processor( $post->post_content ); $seen = array(); $link_count = 0;
		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) && $link_count < 500 ) {
			$href = $processor->get_attribute( 'href' ); if ( ! is_string( $href ) ) { continue; }
			$url = shiki_link_absolute( $href, get_permalink( $post ) );
			if ( ! $url || strlen( $url ) > 2048 || isset( $seen[ $url ] ) ) { continue; }
			$seen[ $url ] = true; ++$link_count;
			$status = shiki_link_internal_status( $url );
			if ( 'external' === $status ) {
				$key = hash( 'sha256', $url );
				if ( isset( $state['queue'][ $key ] ) ) { $state['queue'][ $key ]['sources'] = array_slice( array_unique( array_merge( $state['queue'][ $key ]['sources'], array( $post->ID ) ) ), 0, 20 ); }
				elseif ( count( $state['queue'] ) < 100 ) { $state['queue'][ $key ] = array( 'url' => $url, 'sources' => array( $post->ID ), 'status' => 'queued', 'attempts' => 0, 'next' => 0 ); }
				else { ++$state['dropped']; }
			} elseif ( 'ok' !== $status && count( $state['results'] ) < 1000 ) { $state['results'][] = array( 'url' => $url, 'sources' => array( $post->ID ), 'status' => $status ); }
		}
	}
	$state['offset'] += count( $posts ); $state['complete'] = count( $posts ) < 40;
	update_option( 'shikipress_link_state', $state, false );
	return $state;
}
function shiki_tools_action_scan() {
	shiki_tools_guard( 'scan' );
	$lock = shiki_links_acquire_lock();
	if ( is_wp_error( $lock ) ) { shiki_tools_redirect( 'diagnostics', 'locked' ); }
	try { shiki_links_scan_batch( ! empty( $_POST['reset'] ) ); } finally { shiki_links_release_lock( $lock ); }
	shiki_tools_redirect( 'diagnostics', 'scanned' );
}

/** Reject reserved, private, mapped-private and special-use IPs. */
function shiki_link_public_ip( $ip ) {
	$ip = trim( $ip, '[]' );
	if ( 0 === stripos( $ip, '::ffff:' ) ) { $ip = substr( $ip, 7 ); }
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
	if ( false !== strpos( $ip, ':' ) ) {
		$binary = inet_pton( $ip );
		// Global unicast only. Excludes local, multicast, translation and transition ranges.
		return false !== $binary && ( ord( $binary[0] ) & 0xe0 ) === 0x20 && !( ord( $binary[0] ) === 0x20 && ord( $binary[1] ) === 0x01 && ord( $binary[2] ) < 0x02 ) && 0 !== stripos( $ip, '2001:db8:' ) && 0 !== stripos( $ip, '2002:' ) && !( ord( $binary[0] ) === 0x3f && ( ord( $binary[1] ) & 0xf0 ) === 0xf0 );
	}
	$long = (int) sprintf( '%u', ip2long( $ip ) );
	foreach ( array( array( '224.0.0.0', 4 ), array( '192.88.99.0', 24 ), array( '100.64.0.0', 10 ), array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ), array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ), array( '203.0.113.0', 24 ) ) as $range ) {
		$mask = ( 0xffffffff << ( 32 - $range[1] ) ) & 0xffffffff;
		if ( ( $long & $mask ) === ( (int) sprintf( '%u', ip2long( $range[0] ) ) & $mask ) ) { return false; }
	}
	return true;
}
function shiki_link_safe_target( $url ) {
	$parts = wp_parse_url( $url );
	if ( ! $parts || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return new WP_Error( 'unsafe', 'HTTP(S)の認証情報を含まないURLのみ検査できます。' ); }
	$host = strtolower( trim( $parts['host'], '[]' ) );
	if ( str_ends_with( $host, '.' ) ) { return new WP_Error( 'unsafe', '末尾ドットの特殊ホスト表記を拒否しました。' ); }
	$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
	if ( ! in_array( $port, array( 80, 443 ), true ) || ! $host || preg_match( '/(?:^|\.)(?:localhost|local|internal|test|invalid|onion)$/i', $host ) || ! preg_match( '/^[a-z0-9.:-]+$/i', $host ) ) { return new WP_Error( 'unsafe', 'ローカルホスト・特殊ホスト・標準以外のポートを拒否しました。' ); }
	$ips = array();
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) { $ips[] = $host; }
	elseif ( preg_match( '/^[0-9.]+$/', $host ) || false !== strpos( $host, ':' ) || false === strpos( $host, '.' ) ) { return new WP_Error( 'unsafe', '特殊なIP表記を拒否しました。' ); }
	else {
		$ips = apply_filters( 'shiki_link_dns_records', array(), $host );
		if ( ! $ips && function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // DNS only; no content fetch.
			foreach ( (array) $records as $record ) { if ( isset( $record['ip'] ) ) { $ips[] = $record['ip']; } if ( isset( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; } }
		}
	}
	if ( ! $ips ) { return new WP_Error( 'dns', 'DNSを確認できませんでした。リンク切れとは断定しません。' ); }
	foreach ( $ips as $ip ) { if ( ! is_string( $ip ) || ! shiki_link_public_ip( $ip ) ) { return new WP_Error( 'unsafe', 'プライベート・予約済み・メタデータ等のアドレスを拒否しました。' ); } }
	return array( 'url' => $url, 'host' => $host, 'port' => $port, 'ips' => array_values( array_unique( $ips ) ) );
}
/** Validate every hop and pin cURL DNS to the previously checked public IP. */
function shiki_link_fetch( $url ) {
	for ( $hop = 0; $hop <= 2; ++$hop ) {
		$target = shiki_link_safe_target( $url );
		if ( is_wp_error( $target ) ) { return array( 'status' => 'unsafe' === $target->get_error_code() ? 'unsafe' : 'uncertain', 'detail' => $target->get_error_message() ); }
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) { return array( 'status' => 'uncertain', 'detail' => 'DNS固定に必要なcURLが未導入のためHTTP取得を見送りました。' ); }
		$force_transport = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( $url ) {
			if ( $request_url === $url ) { $options['transport'] = 'WpOrg\\Requests\\Transport\\Curl'; }
		};
		$pin = static function ( $handle, $args, $request_url ) use ( $url, $target ) {
			if ( $request_url !== $url ) { return; }
			$ip = $target['ips'][0]; $resolve_ip = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $target['host'] . ':' . $target['port'] . ':' . $resolve_ip ) );
			curl_setopt( $handle, CURLOPT_PROXY, '' );
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 3 );
		};
		add_action( 'requests-requests.before_request', $force_transport, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		try {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 4, 'redirection' => 0, 'limit_response_size' => 1024, 'headers' => array( 'Range' => 'bytes=0-1023', 'Accept' => 'text/html,*/*;q=0.1' ), 'user-agent' => 'ShikiPress-LinkCheck/' . ( defined( 'SHIKI_VERSION' ) ? SHIKI_VERSION : '1.0.0' ), 'cookies' => array(), 'sslverify' => true ) );
		} finally {
			remove_action( 'requests-requests.before_request', $force_transport, PHP_INT_MAX ); remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
		}
		if ( is_wp_error( $response ) ) { return array( 'status' => 'uncertain', 'detail' => '取得失敗・タイムアウト等。リンク切れとは断定しません。' ); }
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! is_string( $location ) || ! $location || $hop >= 2 ) { return array( 'status' => 'redirect', 'detail' => 'リダイレクト先不明または上限2回。' ); }
			$url = WP_Http::make_absolute_url( $location, $url ); continue;
		}
		if ( in_array( $code, array( 404, 410 ), true ) ) { return array( 'status' => 'broken', 'detail' => 'HTTP ' . $code ); }
		if ( $code >= 200 && $code < 300 ) { return array( 'status' => 'ok', 'detail' => 'HTTP ' . $code ); }
		return array( 'status' => 'uncertain', 'detail' => 'HTTP ' . $code . '。アクセス制限・混雑等の可能性があるため要確認。' );
	}
	return array( 'status' => 'redirect', 'detail' => 'リダイレクト上限。' );
}
/** Delete only the observed lock value; another request may replace it after our read. */
function shiki_links_acquire_lock() {
	global $wpdb;
	$now = time(); $lock = (array) get_option( 'shikipress_link_lock', array() );
	if ( ! empty( $lock['time'] ) && $lock['time'] < $now - 90 ) { shiki_delete_job_lock_value( 'shikipress_link_lock', $lock ); }
	$token = wp_generate_uuid4();
	// add_option() can upsert a concurrent owner's value after its existence check.
	$inserted = $wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
		'shikipress_link_lock', maybe_serialize( array( 'time' => $now, 'token' => $token ) ), 'no'
	) );
	wp_cache_delete( 'shikipress_link_lock', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( 1 !== $inserted ) { return new WP_Error( 'locked', '検査が実行中です。' ); }
	return $token;
}
function shiki_links_release_lock( $token ) {
	$current = (array) get_option( 'shikipress_link_lock', array() );
	if ( isset( $current['token'] ) && $current['token'] === $token ) { shiki_delete_job_lock_value( 'shikipress_link_lock', $current ); }
}
function shiki_links_run_queue() {
	$now = time(); $token = shiki_links_acquire_lock();
	if ( is_wp_error( $token ) ) { return $token; }
	try {
		if ( (int) get_option( 'shikipress_link_last_run', 0 ) > $now - 60 ) { return new WP_Error( 'rate', '60秒以上空けて実行してください。' ); }
		update_option( 'shikipress_link_last_run', $now, false );
		$state = shiki_links_state(); $count = 0;
		foreach ( $state['queue'] as &$item ) {
			if ( $count >= 2 ) { break; }
			if ( ! in_array( $item['status'], array( 'queued', 'uncertain' ), true ) || $item['attempts'] >= 2 || $item['next'] > $now ) { continue; }
			++$count; ++$item['attempts']; $item = array_merge( $item, shiki_link_fetch( $item['url'] ) ); $item['next'] = time() + 300;
		}
		unset( $item ); update_option( 'shikipress_link_state', $state, false );
		return $state;
	} finally { shiki_links_release_lock( $token ); }
}
function shiki_tools_action_external() {
	shiki_tools_guard( 'external' ); $result = shiki_links_run_queue();
	shiki_tools_redirect( 'diagnostics', is_wp_error( $result ) ? $result->get_error_code() : 'external' );
}

/** Export only known settings, with sensitive/executable fields classified separately. */
function shiki_backup_sections() {
	$map = array();
	foreach ( shiki_defaults() as $key => $value ) {
		$map[ $key ] = preg_match( '/^(?:seo_|ogp_|canonical|breadcrumbs)/', $key ) ? 'seo' : ( preg_match( '/^(?:ads_|ad_)/', $key ) ? 'ads' : ( preg_match( '/^(?:season_|auto_|slug_|popular_|related_|featured_)/', $key ) ? 'automation' : ( preg_match( '/^(?:page_|tabs|nav_)/', $key ) ? 'navigation' : 'design' ) ) );
	}
	return function_exists( 'shiki_setting_sections' ) ? array_merge( $map, shiki_setting_sections() ) : apply_filters( 'shiki_setting_sections', $map );
}
function shiki_backup_sensitive_keys() {
	$keys = array();
	foreach ( shiki_defaults() as $key => $value ) { if ( preg_match( '/(?:api[_-]?key|password|secret|token|email|phone|address|owner|contact_name|author_name|publisher)/i', $key ) ) { $keys[] = $key; } }
	return array_unique( apply_filters( 'shiki_backup_sensitive_keys', $keys ) );
}
function shiki_backup_executable_keys() {
	$keys = array();
	foreach ( shiki_defaults() as $key => $value ) { if ( ( is_string( $value ) || is_array( $value ) ) && preg_match( '/(?:^|_)(?:code|script|html|custom_tag|ad_tag|ads_slots)(?:_|$)/i', $key ) ) { $keys[] = $key; } }
	return array_unique( apply_filters( 'shiki_backup_executable_keys', $keys ) );
}
function shiki_backup_has_private_value( $value ) {
	if ( is_array( $value ) ) { foreach ( $value as $key => $child ) { if ( is_string( $key ) && preg_match( '/(?:password|secret|token|api[_-]?key|email|phone|address)/i', $key ) ) { return true; } if ( shiki_backup_has_private_value( $child ) ) { return true; } } return false; }
	return is_string( $value ) && ( is_email( $value ) || (bool) preg_match( '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|[?&](?:key|token|secret|api_key|password)=)/i', $value ) );
}
function shiki_backup_has_executable_value( $value ) {
	if ( is_array( $value ) ) { foreach ( $value as $child ) { if ( shiki_backup_has_executable_value( $child ) ) { return true; } } return false; }
	return is_string( $value ) && (bool) preg_match( '/<\s*(?:script|iframe|object|embed)|\bon\w+\s*=|javascript\s*:|<\?php/i', $value );
}
function shiki_backup_payload( $kind = 'settings' ) {
	$defaults = shiki_defaults(); $settings = array_intersect_key( (array) get_option( 'shikipress_settings', array() ), $defaults );
	$settings = array_merge( $defaults, $settings ); $sensitive = shiki_backup_sensitive_keys(); $code = shiki_backup_executable_keys();
	foreach ( $settings as $key => $value ) {
		$is_code = in_array( $key, $code, true );
		if ( in_array( $key, $sensitive, true ) || shiki_backup_has_private_value( $value ) || ( 'executable-ad-code' === $kind ? ! $is_code : $is_code || shiki_backup_has_executable_value( $value ) ) ) { unset( $settings[ $key ] ); }
	}
	return array( 'format' => 'shikipress-settings', 'schema_version' => 1, 'theme_version' => defined( 'SHIKI_VERSION' ) ? SHIKI_VERSION : '1.0.0', 'kind' => $kind, 'exported_at' => gmdate( 'c' ), 'settings' => $settings );
}
function shiki_backup_value_matches( $value, $default, $depth = 0 ) {
	if ( $depth > 5 ) { return false; }
	if ( is_bool( $default ) ) { return is_bool( $value ); }
	if ( is_int( $default ) ) { return is_int( $value ); }
	if ( is_float( $default ) ) { return is_int( $value ) || is_float( $value ); }
	if ( is_string( $default ) ) { return is_string( $value ) && strlen( $value ) <= 131072; }
	if ( is_array( $default ) ) {
		if ( ! is_array( $value ) || count( $value ) > 500 ) { return false; }
		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 100 ) ) { return false; }
			if ( $default && array_is_list( $default ) ) { if ( ! is_int( $key ) || ! shiki_backup_value_matches( $child, $default[0], $depth + 1 ) ) { return false; } }
			elseif ( isset( $default[ $key ] ) ) { if ( ! shiki_backup_value_matches( $child, $default[ $key ], $depth + 1 ) ) { return false; } }
			elseif ( $default ) { return false; }
			elseif ( is_array( $child ) ) { if ( ! shiki_backup_value_matches( $child, array(), $depth + 1 ) ) { return false; } }
			elseif ( ! is_scalar( $child ) || ( is_string( $child ) && strlen( $child ) > 131072 ) ) { return false; }
		}
		return true;
	}
	return null === $default && null === $value;
}
function shiki_backup_parse( $json ) {
	if ( ! is_string( $json ) || strlen( $json ) > 524288 ) { return new WP_Error( 'size', 'JSONは512KB以内にしてください。' ); }
	$data = json_decode( $json, true, 12 );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || ( $data['format'] ?? '' ) !== 'shikipress-settings' || ( $data['schema_version'] ?? 0 ) !== 1 || ! is_string( $data['theme_version'] ?? null ) || ! in_array( $data['kind'] ?? '', array( 'settings', 'executable-ad-code' ), true ) || ! is_array( $data['settings'] ?? null ) ) { return new WP_Error( 'schema', 'ShikiPress schema_version=1の設定JSONではありません。' ); }
	if ( 'executable-ad-code' === $data['kind'] && ! current_user_can( 'unfiltered_html' ) ) { return new WP_Error( 'code_privilege', '広告コードの移行にはunfiltered_html権限も必要です。' ); }
	$defaults = shiki_defaults(); $sensitive = shiki_backup_sensitive_keys(); $code = shiki_backup_executable_keys();
	foreach ( $data['settings'] as $key => $value ) {
		if ( ! is_string( $key ) || ! array_key_exists( $key, $defaults ) || in_array( $key, $sensitive, true ) || shiki_backup_has_private_value( $value ) ) { return new WP_Error( 'unknown', '未知・秘密情報・個人情報を含む設定キーを拒否しました。' ); }
		$is_code = in_array( $key, $code, true );
		if ( ( 'settings' === $data['kind'] && ( $is_code || shiki_backup_has_executable_value( $value ) ) ) || ( 'executable-ad-code' === $data['kind'] && ! $is_code ) ) { return new WP_Error( 'code_scope', '通常設定と実行可能な広告コードは別々のJSONで移行してください。' ); }
		if ( ! shiki_backup_value_matches( $value, $defaults[ $key ] ) ) { return new WP_Error( 'type', '設定の値の型・配列構造・サイズが正しくありません: ' . sanitize_key( $key ) ); }
	}
	if ( ! $data['settings'] ) { return new WP_Error( 'empty', '移行できる設定がありません。' ); }
	return $data;
}
function shiki_backup_disarm( $settings ) {
	foreach ( array( 'view_count_enabled' => false, 'view_count_consent_required' => true, 'view_count_consent_ack' => false ) as $key => $value ) { if ( array_key_exists( $key, $settings ) ) { $settings[ $key ] = $value; } }
	// No imported state is allowed to start advertisements or custom script execution.
	foreach ( $settings as $key => $value ) {
		if ( preg_match( '/^(?:ads_|ad_)/', $key ) && preg_match( '/(?:enabled|approved|active|auto|trusted)/', $key ) && is_bool( $value ) ) { $settings[ $key ] = false; }
	}
	if ( array_key_exists( 'ads_mode', $settings ) ) { $settings['ads_mode'] = 'test'; }
	if ( isset( $settings['ads_slots'] ) && is_array( $settings['ads_slots'] ) ) {
		foreach ( $settings['ads_slots'] as &$slot ) { if ( is_array( $slot ) ) { $slot['trusted'] = false; if ( array_key_exists( 'enabled', $slot ) ) { $slot['enabled'] = false; } } } unset( $slot );
	}
	return apply_filters( 'shiki_disarm_import_settings', $settings );
}
function shiki_backup_apply_data( $data, $sections ) {
	$map = shiki_backup_sections(); $allowed = array_unique( array_values( $map ) );
	if ( ! is_array( $sections ) || ! $sections || array_diff( $sections, $allowed ) ) { return new WP_Error( 'sections', '適用対象を1つ以上選んでください。' ); }
	$current = array_merge( shiki_defaults(), (array) get_option( 'shikipress_settings', array() ) ); $out = $current;
	foreach ( $sections as $section ) {
		$input = $out; $changed = array();
		foreach ( $data['settings'] as $key => $value ) { if ( isset( $map[ $key ] ) && $map[ $key ] === $section ) { $input[ $key ] = $value; $changed[] = $key; } }
		if ( ! $changed ) { continue; }
		$validated = function_exists( 'shiki_validate_settings' ) ? shiki_validate_settings( $input, $section, $out ) : apply_filters( 'shiki_sanitize_settings', $out, $input, $section );
		// Section sanitizers cannot alter unchecked sections during this import.
		foreach ( $map as $key => $owner ) { if ( $owner === $section && array_key_exists( $key, $validated ) ) { $out[ $key ] = $validated[ $key ]; } }
	}
	$out = shiki_backup_disarm( $out );
	update_option( 'shikipress_settings_before_import', array( 'saved_at' => time(), 'settings' => $current ), false );
	update_option( 'shikipress_settings', $out, false );
	if ( function_exists( 'shiki_invalidate_cache' ) ) { shiki_invalidate_cache(); }
	return true;
}
function shiki_tools_action_export() {
	shiki_tools_guard( 'export' );
	$kind = isset( $_POST['kind'] ) && 'executable-ad-code' === $_POST['kind'] ? 'executable-ad-code' : 'settings';
	if ( 'executable-ad-code' === $kind && ( ! current_user_can( 'unfiltered_html' ) || empty( $_POST['ack_code'] ) ) ) { wp_die( '広告コードの明示的な確認と権限が必要です。', '', array( 'response' => 403 ) ); }
	nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="shikipress-' . $kind . '-' . gmdate( 'Ymd-His' ) . '.json"' ); header( 'X-Content-Type-Options: nosniff' );
	echo wp_json_encode( shiki_backup_payload( $kind ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); exit;
}
function shiki_tools_action_preview() {
	shiki_tools_guard( 'preview' );
	$data = shiki_backup_parse( isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '' );
	if ( is_wp_error( $data ) ) { wp_die( esc_html( $data->get_error_message() ), '設定JSONの検証エラー', array( 'response' => 400, 'back_link' => true ) ); }
	set_transient( 'shikipress_import_' . get_current_user_id(), $data, 15 * MINUTE_IN_SECONDS );
	shiki_tools_redirect( 'backup', 'preview' );
}
function shiki_backup_preview_hash( $data ) {
	return hash_hmac( 'sha256', wp_json_encode( $data ), wp_salt( 'nonce' ) );
}
function shiki_tools_action_apply() {
	shiki_tools_guard( 'apply' );
	$key = 'shikipress_import_' . get_current_user_id(); $data = get_transient( $key );
	if ( ! $data || empty( $_POST['ack_apply'] ) ) { wp_die( 'プレビューが失効したか、適用確認がありません。', '', array( 'response' => 400 ) ); }
	$shown_hash = isset( $_POST['preview_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_hash'] ) ) : '';
	if ( ! hash_equals( shiki_backup_preview_hash( $data ), $shown_hash ) ) { wp_die( '別のタブでプレビューが更新されました。現在のプレビューを確認してください。', '', array( 'response' => 409 ) ); }
	// Revalidate at application time, including changes of user privileges or theme schema.
	$data = shiki_backup_parse( wp_json_encode( $data ) );
	if ( is_wp_error( $data ) ) { wp_die( esc_html( $data->get_error_message() ), '', array( 'response' => 400 ) ); }
	$sections = isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['sections'] ) ) : array();
	$result = shiki_backup_apply_data( $data, $sections );
	if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
	delete_transient( $key ); shiki_tools_redirect( 'backup', 'applied' );
}
function shiki_backup_restore_snapshot() {
	$snapshot = get_option( 'shikipress_settings_before_import', array() );
	if ( empty( $snapshot['settings'] ) || ! is_array( $snapshot['settings'] ) ) { return new WP_Error( 'snapshot', '復元元の設定がありません。' ); }
	$restored = array_merge( shiki_defaults(), array_intersect_key( $snapshot['settings'], shiki_defaults() ) );
	if ( function_exists( 'shiki_validate_settings' ) ) { foreach ( array_unique( array_values( shiki_backup_sections() ) ) as $section ) { $restored = shiki_validate_settings( $restored, $section, $restored ); } }
	update_option( 'shikipress_settings', shiki_backup_disarm( $restored ), false );
	if ( function_exists( 'shiki_invalidate_cache' ) ) { shiki_invalidate_cache(); }
	return true;
}
function shiki_tools_action_restore() {
	shiki_tools_guard( 'restore' );
	if ( empty( $_POST['ack_restore'] ) ) { wp_die( '復元の確認がありません。', '', array( 'response' => 400 ) ); }
	$result = shiki_backup_restore_snapshot();
	if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
	shiki_tools_redirect( 'backup', 'restored' );
}
function shiki_tools_backup_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	shiki_tools_notice();
	echo '<h2>設定バックアップ</h2><p>テーマ設定だけをバージョン付きJSONで移行します。記事・画像・メニュー・カテゴリー・WordPress全体のDBバックアップは含みません。通常の書き出しではAPIキー・認証情報・個人情報候補・実行コードを除外します。</p>';
	shiki_tools_form( 'export' ); echo '<input type="hidden" name="kind" value="settings">'; submit_button( '通常設定JSONをダウンロード', 'secondary' ); echo '</form>';
	if ( current_user_can( 'unfiltered_html' ) ) {
		echo '<details><summary>広告コードを別ファイルで移行</summary><p>広告スロット定義とCMPコードは実行可能な情報を含みます。安全に保管し、受け取り元で内容を確認してください。個人情報・認証情報候補を含む項目はこの経路でも除外します。インポートでは広告を有効化しません。</p>';
		shiki_tools_form( 'export' ); echo '<input type="hidden" name="kind" value="executable-ad-code"><label><input type="checkbox" name="ack_code" value="1" required>実行可能な広告コードを別途書き出すことを確認しました</label>'; submit_button( '広告コードJSONをダウンロード', 'secondary' ); echo '</form></details>';
	}
	echo '<h3>JSONを検証してプレビュー</h3><p>512KB以内のJSONを貼り付けてください。この段階では設定を変更しません。外部から受け取った広告コードは必ず内容を確認してください。</p>';
	shiki_tools_form( 'preview' ); echo '<label for="shiki-import-json">設定JSON</label><textarea id="shiki-import-json" name="json" rows="10" style="width:100%;max-width:900px" required spellcheck="false"></textarea>'; submit_button( '検証・プレビュー' ); echo '</form>';
	$data = get_transient( 'shikipress_import_' . get_current_user_id() );
	if ( is_array( $data ) && isset( $data['settings'] ) ) {
		$map = shiki_backup_sections(); $groups = array();
		foreach ( $data['settings'] as $key => $value ) { if ( isset( $map[ $key ] ) ) { $groups[ $map[ $key ] ][ $key ] = $value; } }
		echo '<h3>適用プレビュー</h3><p>形式: ' . esc_html( $data['kind'] ) . ' / 元テーマ版: ' . esc_html( $data['theme_version'] ) . '。プレビューは15分間有効。広告・CMP・自動広告・タグ信頼状態・任意の閲覧集計は適用時と復元時に無効またはテストへ戻します。閲覧集計の同意要件も初期状態へ戻します。固定ページなどのIDは移行先サイトに存在するかご確認ください。</p>';
		shiki_tools_form( 'apply' ); echo '<input type="hidden" name="preview_hash" value="' . esc_attr( shiki_backup_preview_hash( $data ) ) . '">'; $labels = array( 'design' => 'デザイン', 'automation' => '記事自動化', 'navigation' => 'ナビゲーション', 'ads' => '広告', 'seo' => 'SEO' );
		$current = array_merge( shiki_defaults(), (array) get_option( 'shikipress_settings', array() ) );
		foreach ( $groups as $section => $values ) {
			echo '<fieldset style="margin:16px 0;padding:12px;border:1px solid #ccd0d4"><legend><label><input type="checkbox" name="sections[]" value="' . esc_attr( $section ) . '"> ' . esc_html( $labels[ $section ] ?? $section ) . 'を適用</label></legend><ul>';
			foreach ( $values as $key => $value ) {
				$changed = ( $current[ $key ] ?? null ) !== $value;
				echo '<li><strong>' . esc_html( $key ) . '</strong> ' . ( $changed ? '変更あり' : '同じ値' ) . '<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:160px;overflow:auto">' . esc_html( wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre></li>';
			}
			echo '</ul></fieldset>';
		}
		echo '<label><input type="checkbox" name="ack_apply" value="1" required>選択した設定の変更と適用前スナップショットの保存を確認しました</label>'; submit_button( '選択した設定を適用' ); echo '</form>';
	}
	$snapshot = get_option( 'shikipress_settings_before_import', array() );
	if ( ! empty( $snapshot['settings'] ) ) {
		echo '<h3>適用前の設定へ復元</h3><p>保存日時: ' . esc_html( wp_date( 'Y-m-d H:i:s', $snapshot['saved_at'] ) ) . '。直近のインポート直前の設定を1世代保持します。記事や画像は変更しません。</p>';
		shiki_tools_form( 'restore' ); echo '<label><input type="checkbox" name="ack_restore" value="1" required>現在のテーマ設定を適用前に戻すことを確認しました</label>'; submit_button( '適用前の設定を復元', 'secondary' ); echo '</form>';
	}
}
