<?php
/**
 * Regressions fixed in 1.1.9, on a real WordPress.
 * Dedicated disposable environment only: scripts/wp.sh eval-file tests/regression-119-integration.php
 * Every created post, term, comment and option is removed again; settings and reading options are restored.
 */
if ( ! defined( 'SHIKI_TEST_ENV' ) || ! SHIKI_TEST_ENV || ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'Dedicated test WordPress required' ); }

$checks = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	$checks[] = array( 'test' => $label, 'pass' => (bool) $condition );
	if ( ! $condition ) { throw new RuntimeException( $label ); }
};
$old_settings = get_option( 'shikipress_settings', array() );
$old_user = get_current_user_id();
$old_options = array();
foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'blogname', 'blog_public' ) as $option ) { $old_options[ $option ] = get_option( $option ); }
$old_query = array( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
$old_post = $GLOBALS['post'] ?? null;
$old_globals = array( $_GET, $_POST, $_REQUEST, $GLOBALS['pagenow'] ?? null );
$old_views_schema = get_option( 'shikipress_views_schema' );
$posts = array();
$terms = array();
$filters = array();
$add_filter = static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$filters ) { add_filter( $hook, $callback, $priority, $args ); $filters[] = array( $hook, $callback, $priority ); };
$errors = array();
$theme_root = trailingslashit( (string) realpath( get_template_directory() ) );
set_error_handler( static function ( $number, $message, $file, $line ) use ( &$errors, $theme_root ) {
	if ( ( error_reporting() & $number ) && str_starts_with( (string) realpath( $file ), $theme_root ) ) { $errors[] = $message . ' @ ' . basename( $file ) . ':' . $line; }
	return false;
} );
/** A main query, as a request for these query variables would build it. */
$main_query = static function ( array $vars ) {
	$query = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['wp_query'] = $query;
	$query->query( $vars );
	return $query;
};
$make = static function ( array $args ) use ( &$posts ) {
	$id = wp_insert_post( $args + array( 'post_status' => 'publish', 'post_type' => 'post' ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$posts[] = $id;
	return $id;
};
try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
	wp_set_current_user( $admin );
	$defaults = shiki_defaults();
	$base = array_merge( $defaults, array_intersect_key( (array) $old_settings, $defaults ) );
	$no_unfiltered = static function ( $caps, $cap ) { return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps; };

	// 1. options.php reads option_page from $_REQUEST. With option_page only in the query string, the
	//    sanitizer took the internal path and stored raw input, including executable CMP code, for an
	//    administrator without unfiltered_html (a multisite site administrator, DISALLOW_UNFILTERED_HTML).
	update_option( 'shikipress_settings', $base, false );
	add_filter( 'map_meta_cap', $no_unfiltered, 10, 2 );
	try {
		$payload = array( 'ads_mode' => 'production', 'ads_live_approved' => '1', 'ads_external_reviewed' => '1', 'ads_cmp_enabled' => '1', 'ads_cmp_trusted' => '1', 'ads_cmp_code' => '<script>/*TEST-REGRESSION-119*/</script>', 'tabs' => 'broken' );
		$GLOBALS['pagenow'] = 'options.php';
		$_GET = array( 'option_page' => 'shikipress', 'action' => 'update' );
		$_POST = array( 'shikipress_settings' => $payload );
		$_REQUEST = array_merge( $_GET, $_POST );
		$result = shiki_sanitize_settings( $payload );
		$assert( ! str_contains( (string) wp_json_encode( $result ), 'TEST-REGRESSION-119' ) && is_array( $result['tabs'] ?? null ), 'option_page in the query string cannot skip validation on options.php' );
		$_POST['option_page'] = 'shikipress';
		$_POST['shikipress_section'] = 'ads';
		$_REQUEST = array_merge( $_GET, $_POST );
		$result = shiki_sanitize_settings( $payload );
		$assert( '' === $result['ads_cmp_code'] && false === $result['ads_cmp_trusted'] && is_array( $result['tabs'] ), 'The ordinary settings form still validates the section without unfiltered_html' );
		$_GET = array(); $_POST = array( 'option_page' => 'options', 'shikipress_settings' => $payload ); $_REQUEST = $_POST;
		$assert( shiki_sanitize_settings( $payload ) == get_option( 'shikipress_settings' ), 'The generic options screen never writes the theme settings' );
		$_GET = array(); $_POST = array( 'shikipress_settings' => $payload ); $_REQUEST = $_POST;
		$assert( shiki_sanitize_settings( $payload ) == get_option( 'shikipress_settings' ), 'A request to options.php without a settings group is not an internal call' );
		$GLOBALS['pagenow'] = 'admin-post.php'; $_POST = array(); $_REQUEST = array();
		$internal = shiki_sanitize_settings( array( 'tabs' => $base['tabs'], 'unknown_key' => 1 ) );
		$assert( array( 'tabs' => $base['tabs'] ) === $internal, 'Internal update_option calls keep their existing path' );
	} finally {
		remove_filter( 'map_meta_cap', $no_unfiltered, 10 );
		list( $_GET, $_POST, $_REQUEST ) = array( $old_globals[0], $old_globals[1], $old_globals[2] );
		if ( null === $old_globals[3] ) { unset( $GLOBALS['pagenow'] ); } else { $GLOBALS['pagenow'] = $old_globals[3]; }
	}

	// 2. The first save after activation stores a value equal to the defaults. With a registered default,
	//    update_option() handed it to add_option(), which fires no update_option_shikipress_settings.
	$fired = 0;
	$count = static function () use ( &$fired ) { ++$fired; };
	delete_option( 'shikipress_settings' );
	add_option( 'shikipress_settings', shiki_defaults(), '', false );
	shiki_register_settings();
	add_action( 'shiki_cache_invalidated', $count );
	try {
		update_option( 'shikipress_settings', array_merge( shiki_defaults(), array( 'category_exclude' => array( 987654 ) ) ), false );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'shikipress_settings' ) );
		$assert( $fired >= 1, 'The first settings change after activation invalidates the caches' );
		$assert( in_array( $autoload, array( 'no', 'off' ), true ), 'The settings option stays out of autoload' );
		$fired = 0;
		delete_option( 'shikipress_settings' );
		update_option( 'shikipress_settings', $base, false );
		$assert( $fired >= 1, 'Creating a missing settings option invalidates the caches' );
	} finally {
		remove_action( 'shiki_cache_invalidated', $count );
		unregister_setting( 'shikipress', 'shikipress_settings' );
		update_option( 'shikipress_settings', $base, false );
	}

	// 3. A stored value of another shape no longer stops a save, an import or a restore.
	$thrown = null;
	try {
		shiki_validate_settings( array( 'tabs' => array( 'home' => 'TEST' ) ), 'navigation', array_merge( $base, array( 'tabs' => 'broken' ) ) );
		update_option( 'shikipress_settings_before_import', array( 'saved_at' => time(), 'settings' => array_merge( $base, array( 'tabs' => 7 ) ) ), false );
		shiki_backup_restore_snapshot();
	} catch ( Throwable $problem ) { $thrown = get_class( $problem ) . ': ' . $problem->getMessage(); }
	delete_option( 'shikipress_settings_before_import' );
	update_option( 'shikipress_settings', $base, false );
	$assert( null === $thrown, 'A damaged tabs value cannot break saving, importing or restoring' . ( $thrown ? ' (' . $thrown . ')' : '' ) );
	update_option( 'shikipress_settings', array_merge( $base, array( 'ads_mode' => null ) ), false );
	$assert( 'test' === shiki_option( 'ads_mode' ), 'A stored null falls back to the documented default' );
	update_option( 'shikipress_settings', $base, false );

	// 4. IDs typed with a Japanese input method.
	$assert( array( 12, 15, 18, 20 ) === shiki_ids( '12、15，１８　20' ) && array( 12, 3 ) === shiki_auto_ids( array( '１２', 3 ) ) && array( 4, 5 ) === shiki_ads_ids( '4、5' ), 'Full-width digits and Japanese separators are understood' );

	// 5. Core's Latest Comments block and Recent Comments widget follow the public scope.
	$hidden = $make( array( 'post_title' => 'TEST 119 excluded with comment', 'meta_input' => array( '_shiki_exclude' => '1' ) ) );
	$visible = $make( array( 'post_title' => 'TEST 119 public with comment' ) );
	$hidden_comment = wp_insert_comment( array( 'comment_post_ID' => $hidden, 'comment_content' => 'TEST 119 hidden comment', 'comment_approved' => 1 ) );
	$visible_comment = wp_insert_comment( array( 'comment_post_ID' => $visible, 'comment_content' => 'TEST 119 visible comment', 'comment_approved' => 1 ) );
	$listed = wp_list_pluck( get_comments( apply_filters( 'widget_comments_args', array( 'number' => 100, 'status' => 'approve', 'post_status' => 'publish' ), array() ) ), 'comment_ID' );
	$assert( in_array( (string) $visible_comment, $listed, true ) && ! in_array( (string) $hidden_comment, $listed, true ), 'Latest comments never list comments of excluded posts' );

	// 6. A protected body cannot be tested with a search term by a signed-in user.
	$protected = $make( array( 'post_title' => 'TEST 119 protected', 'post_content' => 'TEST119SECRETWORD body', 'post_password' => 'test-only' ) );
	$match = $main_query( array( 'p' => $protected, 's' => 'TEST119SECRETWORD' ) )->posts;
	$miss = $main_query( array( 'p' => $protected, 's' => 'TEST119NOTPRESENT' ) )->posts;
	$assert( array() === $match && array() === $miss, 'A search term on a protected post answers the same whether it matches or not' );

	// 7. Card excerpts and descriptions are built like core's automatic excerpt.
	$hidden_block = '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>TEST119HIDDENBLOCK</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>TEST119SHOWNBLOCK</p><!-- /wp:paragraph -->';
	$card_post = $make( array( 'post_title' => 'TEST 119 hidden block', 'post_content' => $hidden_block ) );
	ob_start(); shiki_card( $card_post ); $card = (string) ob_get_clean();
	$supports_hidden = version_compare( get_bloginfo( 'version' ), '6.9', '>=' );
	$assert( str_contains( $card, 'TEST119SHOWNBLOCK' ) && ( ! $supports_hidden || ! str_contains( $card, 'TEST119HIDDENBLOCK' ) ), 'A hidden block never appears in a card excerpt' );
	$main_query( array( 'p' => $card_post ) );
	$description = shiki_seo_description();
	$assert( str_contains( $description, 'TEST119SHOWNBLOCK' ) && ( ! $supports_hidden || ! str_contains( $description, 'TEST119HIDDENBLOCK' ) ), 'A hidden block never appears in the automatic description' );

	// 8. Table of contents: text shown as written, noscript and unclosed headings as a browser parses them.
	$toc = shiki_toc( '<h2>&amp;copy; と &amp;lt;br&amp;gt;</h2><noscript><h2>TEST NOSCRIPT</h2></noscript><h2>A<h3>B</h3></h2><h2>C</h3><p>本文</p>' );
	$assert( str_contains( $toc, '>&amp;copy; と &amp;lt;br&amp;gt;</a>' ), 'TOC text keeps character references as the heading shows them' );
	$assert( ! str_contains( $toc, 'TEST NOSCRIPT</a>' ) && str_contains( $toc, '>A</a>' ) && str_contains( $toc, '>B</a>' ) && str_contains( $toc, '>C</a>' ) && ! str_contains( $toc, 'C 本文' ), 'TOC skips noscript headings and ends headings where a browser does' );

	// 9. Menus keep the way home when the front and posts pages are hidden from page lists.
	$front = $make( array( 'post_type' => 'page', 'post_title' => 'TEST 119 front' ) );
	$blog = $make( array( 'post_type' => 'page', 'post_title' => 'TEST 119 blog' ) );
	$other = $make( array( 'post_type' => 'page', 'post_title' => 'TEST 119 other hidden' ) );
	update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $front ); update_option( 'page_for_posts', $blog );
	update_option( 'shikipress_settings', array_merge( $base, array( 'page_exclude' => array( $front, $blog, $other ) ) ), false );
	$item = static fn( $id, $object, $url, $type = 'post_type' ) => (object) array( 'ID' => $id + 70000, 'object_id' => $id, 'object' => $object, 'url' => $url, 'type' => $type );
	$menu = array_values( apply_filters( 'wp_nav_menu_objects', array( $item( 0, 'custom', home_url( '/' ), 'custom' ), $item( $blog, 'page', get_permalink( $blog ) ), $item( 0, 'custom', '/?shiki_stories=1', 'custom' ), $item( $other, 'custom', '?page_id=' . $other, 'custom' ) ) ) );
	$assert( 3 === count( $menu ) && 0 === (int) $menu[0]->object_id && $blog === (int) $menu[1]->object_id, 'Home, posts page and story list stay in menus; other hidden pages do not' );

	// 10. SEO: the posts page, searches limited to a term, ?m= dates, site names, locales and sitemap slices.
	update_post_meta( $blog, '_shiki_seo_title', 'TEST 119 posts page title' );
	update_post_meta( $blog, '_shiki_noindex', '1' );
	$main_query( array( 'page_id' => $blog ) );
	$assert( is_home() && shiki_seo_noindex() && str_contains( (string) apply_filters( 'pre_get_document_title', '' ), 'TEST 119 posts page title' ), 'The posts page honours its own noindex and SEO title' );
	update_option( 'show_on_front', 'posts' ); update_option( 'page_on_front', 0 ); update_option( 'page_for_posts', 0 );
	update_option( 'shikipress_settings', $base, false );
	$term = wp_insert_term( 'TEST 119 term ' . wp_generate_password( 6, false ), 'category' );
	if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
	$terms[] = (int) $term['term_id'];
	update_term_meta( $term['term_id'], '_shiki_seo_title', 'TEST 119 term title' );
	$main_query( array( 's' => 'TEST', 'cat' => $term['term_id'] ) );
	$crumbs = shiki_breadcrumb_items();
	$assert( ! str_contains( (string) apply_filters( 'pre_get_document_title', '' ), 'TEST 119 term title' ) && str_contains( end( $crumbs )['name'], '検索結果' ), 'A search limited to a category is titled and described as a search' );
	$main_query( array( 'm' => '20240115' ) );
	$day = shiki_seo_canonical();
	$main_query( array( 'm' => '202401' ) );
	$month = shiki_seo_canonical();
	$assert( $day === get_day_link( 2024, 1, 15 ) && $month === get_month_link( 2024, 1 ), '?m= date archives name their own date, not today' );
	update_option( 'blogname', 'TEST 暮らし&季節' );
	$main_query( array() );
	$graph = shiki_seo_graph( home_url( '/' ), 'TEST', '', array( 'url' => '', 'width' => 0, 'height' => 0, 'alt' => '' ) );
	$assert( 'TEST 暮らし&季節' === ( $graph[0]['name'] ?? '' ), 'Structured data names the site in plain text' );
	update_option( 'blogname', $old_options['blogname'] );
	$locale = static fn() => 'ja';
	add_filter( 'locale', $locale, 99 );
	ob_start(); shiki_seo_head(); $head = (string) ob_get_clean();
	remove_filter( 'locale', $locale, 99 );
	$assert( str_contains( $head, '<meta property="og:locale" content="ja_JP">' ), 'Open Graph names the Japanese locale ja_JP' );
	$map = $make( array( 'post_type' => 'page', 'post_title' => 'TEST 119 map', 'post_content' => '[shiki_sitemap]' ) );
	$_GET['shiki_map_posts'] = '99999';
	$main_query( array( 'page_id' => $map ) );
	$slice = shiki_html_sitemap_page( 'post' );
	$canonical = shiki_seo_canonical();
	$html = shiki_html_sitemap();
	unset( $_GET['shiki_map_posts'] );
	$assert( $slice < 99999 && ! str_contains( $canonical, '99999' ) && str_contains( $html, '<h2>' . esc_html__( '記事', 'shikipress' ) . '</h2>' ), 'A reader-sitemap slice beyond the last one shows the last one and never names itself' );

	// 11. Advertising: renamed slots keep their code, invalid IDs keep their row, offered IDs are new.
	add_filter( 'map_meta_cap', $no_unfiltered, 10, 2 );
	try {
		$trusted = array_merge( shiki_ads_blank_slot( 'slot-1' ), array( 'name' => 'TEST 119 trusted', 'service' => 'generic', 'state' => 'test', 'code' => '<div>TEST 119 approved code</div>', 'trusted' => true, 'vendors' => array() ) );
		update_option( 'shikipress_settings', array_merge( $base, array( 'ads_slots' => array( $trusted ) ) ), false );
		$renamed = shiki_validate_settings( array( 'ads_slots' => array( array_merge( $trusted, array( 'id' => 'top-banner', 'original_id' => 'slot-1', 'code' => '', 'trusted' => '' ) ) ) ), 'ads' )['ads_slots'];
		$assert( 1 === count( $renamed ) && 'top-banner' === $renamed[0]['id'] && '<div>TEST 119 approved code</div>' === $renamed[0]['code'] && true === $renamed[0]['trusted'], 'Renaming a slot without unfiltered_html keeps its approved code' );
		$kept = shiki_validate_settings( array( 'ads_slots' => array( array_merge( $trusted, array( 'id' => '記事上', 'original_id' => 'slot-1' ) ), array_merge( shiki_ads_blank_slot( 'slot-2' ), array( 'id' => '', 'name' => 'TEST 119 unnamed' ) ) ) ), 'ads' )['ads_slots'];
		$assert( 2 === count( $kept ) && 'slot-1' === $kept[0]['id'] && '<div>TEST 119 approved code</div>' === $kept[0]['code'] && 'TEST 119 unnamed' === $kept[1]['name'] && 'slot-1' !== $kept[1]['id'], 'A slot with an empty, invalid or repeated ID is kept under another ID' );
	} finally { remove_filter( 'map_meta_cap', $no_unfiltered, 10 ); }
	$offered = array_merge( shiki_ads_blank_slot( 'slot-2' ), array( 'template' => '1', 'offered_id' => 'slot-2', 'id' => 'sidebar-pr' ) );
	$assert( 1 === count( shiki_validate_settings( array( 'ads_slots' => array( $offered ) ), 'ads' )['ads_slots'] ), 'Typing only an ID into the extra row stores the slot' );
	$make( array( 'post_title' => 'TEST 119 block', 'post_content' => '<!-- wp:shikipress/ad-slot {"slot":"slot-2"} /-->' ) );
	update_option( 'shikipress_settings', array_merge( $base, array( 'ads_slots' => array( $trusted ) ) ), false );
	ob_start(); do_action( 'shiki_admin_tab_ads' ); $screen = (string) ob_get_clean();
	$assert( ! str_contains( $screen, 'value="slot-2"' ), 'An ID still named by an ad block is not offered for a new slot' );
	update_option( 'show_on_front', 'page' ); update_option( 'page_for_posts', $blog );
	$main_query( array( 'page_id' => $blog ) );
	$footer_slot = array_merge( shiki_ads_blank_slot( 'slot-9' ), array( 'state' => 'test', 'position' => 'footer', 'exclude_ids' => array( $blog ) ) );
	$assert( false === shiki_ads_page_allowed( $footer_slot ), 'A slot excludes the posts page by its ID' );
	update_option( 'show_on_front', 'posts' ); update_option( 'page_for_posts', 0 );
	// Another filter returning a non-string must not stop the article.
	update_option( 'shikipress_settings', array_merge( $base, array( 'ads_mode' => 'test', 'ads_slots' => array( array_merge( shiki_ads_blank_slot( 'slot-3' ), array( 'state' => 'test', 'position' => 'middle', 'interval' => 1 ) ) ) ) ), false );
	$article = $make( array( 'post_title' => 'TEST 119 classic', 'post_content' => "<p>one</p>\n\n<p>two</p>" ) );
	$main_query( array( 'p' => $article ) );
	$thrown = null;
	$add_filter( 'the_content', '__return_null', 9 );
	try { while ( have_posts() ) { the_post(); apply_filters( 'the_content', get_the_content() ); } } catch ( Throwable $problem ) { $thrown = get_class( $problem ) . ': ' . $problem->getMessage(); }
	remove_filter( 'the_content', '__return_null', 9 );
	wp_reset_postdata();
	$assert( null === $thrown, 'A non-string from an earlier content filter reaches no typed theme function' . ( $thrown ? ' (' . $thrown . ')' : '' ) );
	update_option( 'shikipress_settings', $base, false );

	// 12. Compatibility adapters: a short call to the CAPTCHA hook and one page-cache purge per request.
	$thrown = null;
	try { shiki_captcha_simple_cf7_observe( null, null ); shiki_captcha_simple_cf7_observe( null ); } catch ( Throwable $problem ) { $thrown = $problem->getMessage(); }
	$assert( null === $thrown, 'The CAPTCHA observation hook accepts fewer arguments' );
	remove_action( 'shutdown', 'shiki_compat_purge_page_caches', 0 );
	shiki_invalidate_cache(); shiki_invalidate_cache(); shiki_invalidate_cache();
	global $wp_filter;
	$scheduled = isset( $wp_filter['shutdown'] ) ? count( array_filter( $wp_filter['shutdown']->callbacks[0] ?? array(), static fn( $callback ) => 'shiki_compat_purge_page_caches' === $callback['function'] ) ) : 0;
	$assert( 1 === $scheduled, 'Several invalidations in one request purge the page caches once, at the end' );

	// 13. Automation: menu items are not published content, and expired counts are removed completely.
	$menu_item = wp_insert_post( array( 'post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_title' => 'TEST 119 menu item' ) );
	$posts[] = $menu_item;
	$assert( '' === get_post_meta( $menu_item, '_shiki_ever_published', true ), 'Publishing a menu item writes no publication marker' );
	shiki_install_views_table();
	global $wpdb;
	$table = shiki_views_table();
	$old_day = '2001-01-01';
	$rows = array();
	for ( $index = 1; $index <= 2500; ++$index ) { $rows[] = $wpdb->prepare( '(%d, %s, 1)', 900000000 + $index, $old_day ); }
	foreach ( array_chunk( $rows, 500 ) as $chunk ) { $wpdb->query( "INSERT INTO $table (post_id, view_day, views) VALUES " . implode( ',', $chunk ) ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'shiki_daily_lock' );
	do_action( 'shiki_daily_maintenance' );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE view_day = %s", $old_day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE post_id > %d", 900000000 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$assert( 0 === $left, 'The daily maintenance removes every count older than 90 days, not 1000 a day' );
	$_GET['counts_deleted'] = '0';
	ob_start(); do_action( 'shiki_admin_tab_automation' ); $notice = (string) ob_get_clean();
	unset( $_GET['counts_deleted'] );
	$assert( str_contains( $notice, '匿名集計を削除できませんでした' ) && ! str_contains( $notice, '保存済みの匿名集計を削除しました。' ), 'A failed deletion of counts is reported as a failure' );

	// 13b. Explicit "apply on save" choices apply once: the block editor resends the meta box with every save.
	$winter = wp_insert_term( 'TEST 119 winter ' . wp_generate_password( 6, false ), 'category' );
	$terms[] = (int) $winter['term_id'];
	update_option( 'shikipress_settings', array_merge( $base, array( 'season_terms' => array( 'winter' => (int) $winter['term_id'] ) ) ), false );
	$draft = $make( array( 'post_status' => 'draft', 'post_title' => 'TEST 119 apply once' ) );
	$send = static function () use ( $draft ) {
		$_POST = array( 'shiki_editor_nonce' => wp_create_nonce( 'shiki_editor_' . $draft ), 'shiki_apply_classification' => '1', 'shiki_classification' => array( 'winter' ), 'shiki_apply_token' => 'TEST119TOKEN' );
		do_action( 'save_post', $draft, get_post( $draft ), true );
		$_POST = array();
	};
	$send();
	$applied = in_array( (int) $winter['term_id'], wp_get_post_categories( $draft ), true );
	wp_set_post_categories( $draft, array( (int) get_option( 'default_category' ) ) );
	$send();
	$assert( $applied && ! in_array( (int) $winter['term_id'], wp_get_post_categories( $draft ), true ), 'A resent meta box applies a season once and keeps the author\'s later change' );
	update_option( 'shikipress_settings', $base, false );
	// DeepL is billed to the site: a contributor cannot send titles, and new requests are capped per day.
	$contributor = wp_insert_user( array( 'user_login' => 'test119-' . strtolower( wp_generate_password( 6, false ) ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'contributor', 'user_email' => 'test119-' . strtolower( wp_generate_password( 6, false ) ) . '@example.invalid' ) );
	if ( is_wp_error( $contributor ) ) { throw new RuntimeException( $contributor->get_error_message() ); }
	wp_set_current_user( $contributor );
	$own = $make( array( 'post_status' => 'draft', 'post_title' => 'TEST 119 contributor draft', 'post_author' => $contributor ) );
	$contributor_allowed = shiki_deepl_allowed( get_post( $own ) );
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $contributor );
	$usage_key = 'shikipress_deepl_usage_' . gmdate( 'Ymd' );
	$usage = get_option( $usage_key, null );
	$one = static fn() => 1;
	add_filter( 'shiki_deepl_daily_limit', $one );
	delete_option( $usage_key );
	$first = shiki_deepl_reserve_request(); $second = shiki_deepl_reserve_request();
	remove_filter( 'shiki_deepl_daily_limit', $one );
	if ( null === $usage ) { delete_option( $usage_key ); } else { update_option( $usage_key, $usage, false ); }
	$assert( ! $contributor_allowed && shiki_deepl_allowed( get_post( $own ) ) && $first && ! $second, 'Only users who may publish send titles to DeepL, within a daily limit' );

	// 14. Bounded work per request.
	$category = wp_insert_term( 'TEST 119 related ' . wp_generate_password( 6, false ), 'category' );
	$terms[] = (int) $category['term_id'];
	$related = array();
	for ( $index = 0; $index < 30; ++$index ) { $related[] = $make( array( 'post_title' => 'TEST 119 related ' . $index, 'post_category' => array( $category['term_id'] ) ) ); }
	shiki_invalidate_cache();
	$before = $wpdb->num_queries;
	shiki_select_posts( 'related', array(), 3, $related[0] );
	$related_queries = $wpdb->num_queries - $before;
	$assert( $related_queries < 25, 'Related posts do not query the terms of each candidate (' . $related_queries . ' queries for 29 candidates)' );
	shiki_public_pages();
	$before = $wpdb->num_queries;
	shiki_public_pages(); shiki_public_pages();
	$assert( $wpdb->num_queries === $before, 'The public page list is read once per request' );
	shiki_seo_public_term_ids( 'category' );
	$before = $wpdb->num_queries;
	shiki_seo_public_term_ids( 'category' );
	$assert( $wpdb->num_queries - $before <= 1, 'Sitemap term lists are kept for the current cache generation' );

	// 15. Link checks continue after the last checked post instead of an offset that shifts.
	update_option( 'shikipress_link_state', array( 'offset' => 0, 'last_id' => PHP_INT_MAX - 1, 'complete' => false, 'results' => array(), 'queue' => array(), 'dropped' => 0 ), false );
	$state = shiki_links_scan_batch();
	$assert( $state['complete'] && PHP_INT_MAX - 1 === $state['last_id'], 'A link check resumes after its last checked post' );
	delete_option( 'shikipress_link_state' );

	// 16. Found by the review of this release.
	$purged = 0; $count_purge = static function () use ( &$purged ) { ++$purged; };
	$trash_draft = $make( array( 'post_status' => 'draft', 'post_title' => 'TEST 119 draft to trash' ) );
	add_action( 'shiki_cache_invalidated', $count_purge );
	wp_trash_post( $trash_draft ); wp_untrash_post( $trash_draft );
	remove_action( 'shiki_cache_invalidated', $count_purge );
	$assert( 0 === $purged, 'Moving a draft to the trash and back invalidates nothing' );
	$events = shiki_validate_settings( array( 'season_events' => array( array( 'id' => 'test-119-a', 'label' => 'TEST 119 A', 'start' => '12-5', 'end' => '１２-２５' ), array( 'id' => 'test-119-b', 'label' => 'TEST 119 B', 'start' => '1/2', 'end' => '01.15' ) ) ), 'automation' )['season_events'];
	$assert( 2 === count( $events ) && '12-05' === $events[0]['start'] && '12-25' === $events[0]['end'] && '01-02' === $events[1]['start'] && '01-15' === $events[1]['end'], 'Event dates typed as 12-5, １２-２５, 1/2 or 01.15 are understood' );
	update_option( 'shikipress_settings', array_merge( $base, array( 'season_events' => $events ) ), false );
	$kept_events = shiki_validate_settings( array( 'season_events' => array( array( 'id' => 'test-119-a', 'label' => 'TEST 119 A', 'start' => 'invalid', 'end' => '12-25' ) ) ), 'automation' )['season_events'];
	$assert( 1 === count( $kept_events ) && '12-05' === $kept_events[0]['start'], 'An event date that cannot be read keeps the saved event' );
	update_option( 'shikipress_settings', $base, false );
	$zones = array( shiki_validate_settings( array( 'timezone' => 'europe/london' ), 'design' )['timezone'], shiki_validate_settings( array( 'timezone' => 'No/Such_Zone' ), 'design', array_merge( $base, array( 'timezone' => 'Europe/Paris' ) ) )['timezone'] );
	$assert( array( 'Europe/London', 'Europe/Paris' ) === $zones, 'Time zones are matched without case and an unknown one keeps the saved zone' );
	$title_post = $make( array( 'post_status' => 'draft', 'post_title' => '今年中に済ませたい冬の大掃除' ) );
	$assert( empty( shiki_classify_post( get_post( $title_post ) )['all_year'] ?? false ) && ! str_contains( shiki_classify_post( get_post( $title_post ) )['notice'], '通年' ), '"今年中" does not mark an article as all-year' );
	$parent = wp_insert_term( 'TEST 119 winter parent ' . wp_generate_password( 6, false ), 'category' );
	$terms[] = (int) $parent['term_id'];
	$child = wp_insert_term( 'TEST 119 winter child ' . wp_generate_password( 6, false ), 'category', array( 'parent' => (int) $parent['term_id'] ) );
	$terms[] = (int) $child['term_id'];
	$child_post = $make( array( 'post_title' => 'TEST 119 child season post', 'post_category' => array( (int) $child['term_id'] ) ) );
	update_option( 'shikipress_settings', array_merge( $base, array( 'season_mode' => 'fixed', 'season_fixed' => 'winter', 'season_terms' => array( 'winter' => (int) $parent['term_id'] ), 'featured_mode' => 'auto' ) ), false );
	$featured = wp_list_pluck( shiki_select_posts( 'featured', array(), 12 ), 'ID' );
	$assert( in_array( $child_post, $featured, true ), 'A season mapped to a parent category features posts of its child categories' );
	// A new post published without a draft save still gets its one-time automatic season.
	update_option( 'shikipress_settings', array_merge( $base, array( 'classification_mode' => 'auto', 'season_terms' => array( 'winter' => (int) $parent['term_id'] ) ) ), false );
	$direct = $make( array( 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ) );
	wp_update_post( array( 'ID' => $direct, 'post_status' => 'publish', 'post_title' => '冬の結露を防ぐ窓の暖かい暮らし', 'post_content' => '冬の寒い朝、雪と結露の対策。' ) );
	$assert( in_array( (int) $parent['term_id'], wp_get_post_categories( $direct ), true ), 'A new post published without a draft save gets its automatic season' );
	update_option( 'shikipress_settings', $base, false );
	shiki_schedule_automation();
	$assert( false === wp_get_schedule( 'shiki_season_tick' ) && false !== wp_next_scheduled( 'shiki_season_tick' ) && false === wp_get_schedule( 'shiki_daily_maintenance' ), 'Periodic steps are single events renewed by the active theme' );
	$dns = static fn() => array( '93.184.215.14' );
	add_filter( 'shiki_link_dns_records', $dns );
	$normalized = shiki_link_safe_target( 'HTTP://Example.COM/path?x=1' );
	remove_filter( 'shiki_link_dns_records', $dns );
	$assert( is_array( $normalized ) && 'http://example.com/path?x=1' === $normalized['url'], 'A link is checked in the form WordPress sends it, so the DNS pin always applies' );
	$saved_one = array_merge( shiki_ads_blank_slot( 'slot-1' ), array( 'name' => 'TEST 119 one', 'service' => 'generic', 'state' => 'test', 'vendors' => array() ) );
	update_option( 'shikipress_settings', array_merge( $base, array( 'ads_slots' => array( $saved_one ) ) ), false );
	$template = array_merge( shiki_ads_blank_slot( 'slot-2' ), array( 'template' => '1', 'offered_id' => 'slot-2' ) );
	$renamed_rows = shiki_validate_settings( array( 'ads_slots' => array( array_merge( $saved_one, array( 'id' => 'slot-2', 'original_id' => 'slot-1' ) ), $template ) ), 'ads' )['ads_slots'];
	$assert( 1 === count( $renamed_rows ) && 'slot-2' === $renamed_rows[0]['id'], 'An untouched extra row stays unsaved when another slot takes its offered ID' );
	$japanese = shiki_validate_settings( array( 'ads_slots' => array( $saved_one, array_merge( $template, array( 'id' => '記事下', 'name' => 'TEST 119 new' ) ) ) ), 'ads' )['ads_slots'];
	$assert( 2 === count( $japanese ) && 'slot-2' === $japanese[1]['id'], 'An extra row with an unusable ID takes the offered ID' );
	update_option( 'shikipress_settings', $base, false );
	$details = $make( array( 'post_title' => 'TEST 119 details', 'post_content' => '<!-- wp:details --><details class="wp-block-details"><summary>Q</summary><!-- wp:paragraph --><p>TEST119DETAILSTEXT</p><!-- /wp:paragraph --></details><!-- /wp:details -->' ) );
	ob_start(); shiki_card( $details ); $details_card = (string) ob_get_clean();
	$assert( str_contains( $details_card, 'TEST119DETAILSTEXT' ), 'Text kept inside a details block still makes the card excerpt' );
	$unclosed = shiki_toc( '<div><h2>B</div><p>TEST119PARAGRAPH</p><h2>C' . str_repeat( 'x', 300 ) );
	$assert( ! str_contains( $unclosed, 'TEST119PARAGRAPH</a>' ) && str_contains( $unclosed, '>B</a>' ) && str_contains( $unclosed, '…</a>' ), 'TOC headings end with their container and stay short when unclosed' );
	$locked_blog = $make( array( 'post_type' => 'page', 'post_title' => 'TEST 119 protected blog', 'post_password' => 'test-only' ) );
	update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $front ); update_option( 'page_for_posts', $locked_blog );
	// The disposable CI site discourages search engines (every page is noindex); this check needs a public site.
	update_option( 'blog_public', '1' );
	$main_query( array( 'page_id' => $locked_blog ) );
	$listed = is_home() && ! shiki_seo_noindex();
	update_option( 'blog_public', $old_options['blog_public'] );
	$assert( $listed, 'A password on the page assigned to the posts list does not de-index the list' );
	update_option( 'show_on_front', 'posts' ); update_option( 'page_on_front', 0 ); update_option( 'page_for_posts', 0 );

	$assert( array() === $errors, 'No PHP warning from the theme in these regressions' . ( $errors ? ' (' . implode( ' | ', array_slice( array_unique( $errors ), 0, 5 ) ) . ')' : '' ) );
} catch ( Throwable $error ) {
	$checks[] = array( 'test' => 'exception', 'pass' => false, 'error' => $error->getMessage() . ' @ ' . basename( $error->getFile() ) . ':' . $error->getLine() );
} finally {
	restore_error_handler();
	foreach ( $filters as $filter ) { remove_filter( $filter[0], $filter[1], $filter[2] ); }
	list( $_GET, $_POST, $_REQUEST ) = array( $old_globals[0], $old_globals[1], $old_globals[2] );
	if ( null === $old_globals[3] ) { unset( $GLOBALS['pagenow'] ); } else { $GLOBALS['pagenow'] = $old_globals[3]; }
	list( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ) = $old_query;
	$GLOBALS['post'] = $old_post;
	foreach ( $old_options as $option => $value ) { update_option( $option, $value ); }
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( $terms as $id ) { wp_delete_term( $id, 'category' ); }
	if ( false === $old_views_schema ) { global $wpdb; $wpdb->query( 'DROP TABLE IF EXISTS ' . shiki_views_table() ); delete_option( 'shikipress_views_schema' ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'shikipress_link_state' );
	update_option( 'shikipress_settings', $old_settings, false );
	remove_action( 'shutdown', 'shiki_compat_purge_page_caches', 0 );
	shiki_invalidate_cache();
	wp_set_current_user( $old_user );
}
$directory = __DIR__ . '/results';
wp_mkdir_p( $directory );
file_put_contents( $directory . '/regression-119-integration.json', wp_json_encode( array( 'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ), 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
foreach ( $checks as $check ) { WP_CLI::log( ( $check['pass'] ? 'PASS ' : 'FAIL ' ) . $check['test'] . ( isset( $check['error'] ) ? ' ' . $check['error'] : '' ) ); }
if ( array_filter( $checks, static fn( $check ) => ! $check['pass'] ) ) { WP_CLI::error( 'Regression 1.1.9 integration failed' ); }
echo 'REGRESSION_119_TOTAL ' . count( $checks ) . PHP_EOL;
