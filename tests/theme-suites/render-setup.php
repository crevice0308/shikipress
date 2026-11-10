<?php
/**
 * Seeds a representative rendering scenario on the owned disposable theme-suite site
 * and writes the URL map for check-render.py. Invoked by run-ci.sh through WP-CLI.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SHIKI_TEST_ENV' ) || true !== SHIKI_TEST_ENV
	|| 'local' !== wp_get_environment_type() || '127.0.0.1' !== DB_HOST
	|| ! preg_match( '/^shikipress_suites_[0-9]+_[0-9]+$/D', DB_NAME )
	|| ! is_file( dirname( rtrim( ABSPATH, '/' ) ) . '/.owned-disposable-ci' )
	|| 'shikipress' !== get_stylesheet() || 'http://127.0.0.1:8080' !== home_url() ) {
	throw new RuntimeException( 'Requires the owned disposable theme-suite site.' );
}
$target = $args[0] ?? '';
if ( '' === $target || ! is_dir( dirname( $target ) ) ) { throw new RuntimeException( 'A writable private output path is required.' ); }
$ok = static function ( $value, $label ) {
	if ( is_wp_error( $value ) ) { throw new RuntimeException( $label . ': ' . $value->get_error_message() ); }
	if ( ! $value ) { throw new RuntimeException( $label . ' failed.' ); }
	return $value;
};
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

$fixtures = get_option( 'shiki_fixture_state' );
if ( ! is_array( $fixtures ) || 20 !== count( (array) ( $fixtures['post_ids'] ?? array() ) ) ) { throw new RuntimeException( 'scripts/fixtures.php 20 must run first.' ); }
$posts = array_map( 'intval', $fixtures['post_ids'] );
$pages = array_map( 'intval', $fixtures['page_ids'] );
$hidden = array_map( 'intval', $fixtures['hidden_ids'] );
// The automation suite deliberately deletes one mapped season category; the explicit setup restores the mapping.
shiki_setup_categories();
$seasons = shiki_season_terms();
if ( 4 !== count( $seasons ) ) { throw new RuntimeException( 'Season categories are not mapped.' ); }

// An author with one article, so the author archive and the author sitemap have content.
$author = $ok( wp_insert_user( array( 'user_login' => 'fixture-author', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'fixture-author@example.invalid', 'display_name' => 'TEST 著者', 'role' => 'author' ) ), 'author' );
$ok( wp_update_post( array( 'ID' => $posts[0], 'post_author' => $author ), true ), 'author assignment' );

// A local featured image attached to the first article.
$upload = wp_upload_bits( 'TEST-render-featured.png', null, file_get_contents( get_template_directory() . '/assets/social/autumn.png' ) );
if ( ! empty( $upload['error'] ) ) { throw new RuntimeException( $upload['error'] ); }
$attachment = $ok( wp_insert_attachment( array( 'post_title' => 'TEST render featured', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_excerpt' => 'TEST キャプション' ), $upload['file'], $posts[0], true ), 'attachment' );
wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );
update_post_meta( $attachment, '_wp_attachment_image_alt', 'TEST 代替テキスト' );
set_post_thumbnail( $posts[0], $attachment );

// Approved threaded comments.
$comment = $ok( wp_insert_comment( array( 'comment_post_ID' => $posts[0], 'comment_author' => 'TEST 読者', 'comment_author_email' => 'reader@example.invalid', 'comment_content' => 'TEST コメント本文', 'comment_approved' => 1 ) ), 'comment' );
$ok( wp_insert_comment( array( 'comment_post_ID' => $posts[0], 'comment_parent' => $comment, 'comment_author' => 'TEST 返信者', 'comment_author_email' => 'reply@example.invalid', 'comment_content' => 'TEST 返信本文', 'comment_approved' => 1 ) ), 'reply' );

// Classic (non-block) article with a second page.
$classic_body = "TEST クラシック本文の一段落目です。\n\n<h2>TEST クラシック見出しA</h2>\n\n二段落目です。<a href=\"" . esc_url( home_url( '/' ) ) . "\">ホーム</a>\n\n<h3>TEST 小見出し</h3>\n\n<ul>\n<li>項目1</li>\n<li>項目2</li>\n</ul>\n\n<h2>TEST クラシック見出しB</h2>\n\n<table><tr><th>列A</th><th>列B</th></tr><tr><td>1</td><td>2</td></tr></table>\n\n<blockquote>TEST 引用</blockquote>\n\n三段落目です。\n\n<!--nextpage-->\n\n<h2>TEST 二ページ目の見出しA</h2>\n\n二ページ目の本文です。\n\n<h2>TEST 二ページ目の見出しB</h2>\n\n二ページ目の続きです。";
$classic = $ok( wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'TEST クラシック記事', 'post_name' => 'test-classic-article', 'post_content' => $classic_body, 'post_category' => array( (int) $seasons['autumn'] ), 'tags_input' => array( 'TEST' ), 'meta_input' => array( '_shiki_fixture' => '1' ) ), true ), 'classic post' );

// A child page for ancestor breadcrumbs.
$child = $ok( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST 子ページ', 'post_name' => 'test-child', 'post_parent' => $pages['about'], 'post_content' => '<h2>TEST 子ページ見出しA</h2><p>TEST</p><h2>TEST 子ページ見出しB</h2><p>TEST</p>', 'meta_input' => array( '_shiki_fixture' => '1' ) ), true ), 'child page' );

// Menus mixing public, protected and external targets.
$menus = array();
foreach ( array( 'primary', 'footer' ) as $location ) {
	$menu = $ok( wp_create_nav_menu( 'TEST render ' . $location ), 'menu' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'TEST メニュー固定ページ', 'menu-item-object' => 'page', 'menu-item-object-id' => $pages['about'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) ), 'menu page' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'TEST メニュー季節', 'menu-item-object' => 'category', 'menu-item-object-id' => (int) $seasons['autumn'], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) ), 'menu category' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'TEST メニュー外部', 'menu-item-url' => 'https://example.org/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) ), 'menu external' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'TEST メニュー相対', 'menu-item-url' => '/test-contact/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) ), 'menu relative' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'SECRET_TEST_MENU_PROTECTED', 'menu-item-object' => 'post', 'menu-item-object-id' => $hidden['password'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) ), 'menu protected' );
	$ok( wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'SECRET_TEST_MENU_EXCLUDED', 'menu-item-url' => get_permalink( $hidden['page_excluded'] ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) ), 'menu excluded' );
	$menus[ $location ] = (int) $menu;
}
set_theme_mod( 'nav_menu_locations', $menus );

// Every ad position in local test mode, the optional view counter, a seasonal event and the SEO fields.
$slot = static function ( $id, $position ) {
	return array( 'id' => $id, 'name' => 'TEST ' . $id, 'service' => 'generic', 'state' => 'test', 'position' => $position, 'device' => 'all', 'post_types' => array( 'post', 'page' ), 'exclude_ids' => '', 'exclude_categories' => '', 'interval' => 2, 'limit' => 2, 'height' => 250, 'min_width' => 0, 'dummy' => 'ready', 'publisher' => '', 'ad_slot' => '', 'code' => '', 'vendors' => '', 'purposes' => '1,3,4', 'shared_loaders' => '' );
};
$input = array_merge( shiki_defaults(), (array) get_option( 'shikipress_settings', array() ), array(
	'ads_mode' => 'test', 'ads_limit' => 12,
	'ads_slots' => array( $slot( 'render-above', 'above' ), $slot( 'render-middle', 'middle' ), $slot( 'render-before-h2', 'before_h2' ), $slot( 'render-after-h2', 'after_h2' ), $slot( 'render-below', 'below' ), $slot( 'render-sidebar', 'sidebar' ), $slot( 'render-footer', 'footer' ) ),
	'view_count_enabled' => 1, 'view_count_consent_ack' => 1, 'view_count_consent_required' => 0, 'popular_days' => 30,
	'show_popular' => 1, 'toc_enabled' => 1,
	'season_events' => array( array( 'id' => 'render-all-year', 'label' => 'TEST 通年イベント', 'start' => '01-01', 'end' => '12-31', 'priority' => 50, 'accent' => '#805a44', 'enabled' => 1 ) ),
	'page_roles' => array( 'about' => $pages['about'], 'contact' => $pages['contact'], 'privacy' => $pages['privacy'], 'sitemap' => $pages['sitemap'] ),
	'seo_home_title' => 'TEST ホームのタイトル', 'seo_home_description' => 'TEST ホームの説明', 'seo_google_verification' => 'TEST-token_123', 'seo_sitemap_per_page' => 10,
) );
$settings = $input;
foreach ( array( 'design', 'automation', 'navigation', 'ads', 'seo' ) as $section ) { $settings = shiki_validate_settings( array_merge( $input, $settings ), $section, $settings ); }
if ( 7 !== count( $settings['ads_slots'] ) || 'test' !== $settings['ads_mode'] || empty( $settings['view_count_enabled'] ) || empty( $settings['season_events'][0]['enabled'] ) ) { throw new RuntimeException( 'Scenario settings were not stored as intended.' ); }
update_option( 'shikipress_settings', $settings, false );
update_post_meta( $posts[1], '_shiki_seo_title', 'TEST 個別SEOタイトル' );
update_post_meta( $posts[1], '_shiki_seo_description', 'TEST 個別の説明' );
update_post_meta( $posts[2], '_shiki_noindex', '1' );
update_post_meta( $posts[3], '_shiki_priority', 80 );
update_term_meta( (int) $seasons['autumn'], '_shiki_seo_description', 'TEST カテゴリーの説明' );

// Aggregate counters through the public endpoint, as a reader's browser would send them.
wp_set_current_user( 0 );
foreach ( array( $posts[0], $posts[0], $posts[4], $classic ) as $viewed ) {
	$request = new WP_REST_Request( 'POST', '/shikipress/v1/view' );
	$request->set_header( 'user-agent', 'Mozilla/5.0 Render Fixture' );
	$request->set_header( 'origin', home_url() );
	$request->set_body_params( array( 'post_id' => $viewed, 'consent' => 'not-required', 'cmp_id' => 0 ) );
	$response = rest_do_request( $request );
	if ( 200 !== $response->get_status() || empty( $response->get_data()['counted'] ) ) { throw new RuntimeException( 'View fixture was not counted.' ); }
}
wp_set_current_user( 1 );

update_option( 'blog_public', 1 );
update_option( 'posts_per_page', 5 );
update_option( 'thread_comments', 1 );
update_option( 'wp_attachment_pages_enabled', 1 ); // Render the attachment template instead of redirecting to the file.
shiki_invalidate_cache();
flush_rewrite_rules( false );

$tag = $ok( get_term_by( 'name', 'TEST', 'post_tag' ), 'tag' );
$first = get_post( $posts[0] );
$year = (int) get_the_date( 'Y', $first );
$month = (int) get_the_date( 'n', $first );
$public = array(
	'home' => home_url( '/' ),
	'home-paged' => home_url( '/page/2/' ),
	'stories' => shiki_stories_url(),
	'stories-paged' => shiki_stories_url( 2 ),
	'single-featured' => get_permalink( $posts[0] ),
	'single-seo-fields' => get_permalink( $posts[1] ),
	'single-noindex' => get_permalink( $posts[2] ),
	'single-long-title' => get_permalink( $posts[4] ),
	'single-classic' => get_permalink( $classic ),
	'single-classic-page2' => trailingslashit( get_permalink( $classic ) ) . '2/',
	'single-password' => get_permalink( $hidden['password'] ),
	'single-excluded' => get_permalink( $hidden['excluded'] ),
	'page-about' => get_permalink( $pages['about'] ),
	'page-child' => get_permalink( $child ),
	'page-sitemap' => get_permalink( $pages['sitemap'] ),
	'page-sitemap-2' => add_query_arg( 'shiki_map_posts', 2, get_permalink( $pages['sitemap'] ) ),
	'page-password' => get_permalink( $hidden['page_password'] ),
	'category' => get_term_link( (int) $seasons['autumn'], 'category' ),
	'category-paged' => trailingslashit( get_term_link( (int) $seasons['autumn'], 'category' ) ) . 'page/2/',
	'tag' => get_term_link( $tag ),
	'author' => get_author_posts_url( $author ),
	'date-year' => get_year_link( $year ),
	'date-month' => get_month_link( $year, $month ),
	'search' => home_url( '/?s=TEST' ),
	'search-paged' => home_url( '/page/2/?s=TEST' ),
	'search-empty' => home_url( '/?s=zzzz-no-such-word' ),
	'search-missing-category' => home_url( '/?s=TEST&cat=999999' ),
	'search-markup' => home_url( '/?s=' . rawurlencode( '"><script>alert(1)</script>' ) ),
	'not-found' => home_url( '/this-page-does-not-exist/' ),
	'attachment' => get_attachment_link( $attachment ),
	'embed' => get_post_embed_url( $posts[0] ),
	'feed' => get_feed_link(),
	'feed-comments' => get_feed_link( 'comments_rss2' ),
	'feed-category' => get_category_feed_link( (int) $seasons['autumn'] ),
	'feed-post-comments' => get_post_comments_feed_link( $posts[0] ),
	'sitemap-index' => home_url( '/wp-sitemap.xml' ),
	'sitemap-posts' => home_url( '/wp-sitemap-posts-post-1.xml' ),
	'sitemap-pages' => home_url( '/wp-sitemap-posts-page-1.xml' ),
	'sitemap-categories' => home_url( '/wp-sitemap-taxonomies-category-1.xml' ),
	'robots' => home_url( '/robots.txt' ),
	'rest-posts' => add_query_arg( 'per_page', 100, rest_url( 'wp/v2/posts' ) ),
	'rest-posts-or' => add_query_arg( array( 'per_page' => 100, 'tax_relation' => 'OR', 'categories' => (int) $seasons['autumn'] ), rest_url( 'wp/v2/posts' ) ),
	'rest-pages' => add_query_arg( 'per_page', 100, rest_url( 'wp/v2/pages' ) ),
	'rest-search' => add_query_arg( array( 'search' => 'TEST', 'per_page' => 100 ), rest_url( 'wp/v2/search' ) ),
	'rest-comments' => rest_url( 'wp/v2/comments' ),
	'rest-media' => rest_url( 'wp/v2/media' ),
	'rest-categories' => rest_url( 'wp/v2/categories' ),
);
foreach ( $public as $name => $url ) { if ( is_wp_error( $url ) || ! is_string( $url ) || '' === $url ) { throw new RuntimeException( 'No URL for ' . $name ); } }

$admin = array();
foreach ( array_keys( shiki_admin_tabs() ) as $tab ) { $admin[ 'admin-tab-' . $tab ] = add_query_arg( array( 'page' => 'shikipress', 'tab' => $tab ), admin_url( 'admin.php' ) ); }
$admin['admin-submenu-design'] = admin_url( 'admin.php?page=shikipress-design' );
$admin['admin-edit-post'] = admin_url( 'post.php?post=' . $posts[0] . '&action=edit' );
$admin['admin-edit-draft'] = admin_url( 'post.php?post=' . $hidden['draft'] . '&action=edit' );
$admin['admin-edit-page'] = admin_url( 'post.php?post=' . $pages['about'] . '&action=edit' );
$admin['admin-new-post'] = admin_url( 'post-new.php' );
$admin['admin-posts'] = admin_url( 'edit.php' );
$admin['admin-categories'] = admin_url( 'edit-tags.php?taxonomy=category' );
$admin['admin-category-edit'] = admin_url( 'term.php?taxonomy=category&tag_ID=' . (int) $seasons['autumn'] );
$admin['admin-tags'] = admin_url( 'edit-tags.php?taxonomy=post_tag' );
$admin['admin-themes'] = admin_url( 'themes.php' );
$admin['admin-widgets'] = admin_url( 'widgets.php' );
$admin['admin-menus'] = admin_url( 'nav-menus.php' );
$admin['admin-dashboard'] = admin_url( 'index.php' );

// A one-hour administrator session for the local sweep only; written outside the uploaded reports.
$expiration = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance( 1 )->create( $expiration );
$cookies = array(
	AUTH_COOKIE => wp_generate_auth_cookie( 1, $expiration, 'auth', $token ),
	LOGGED_IN_COOKIE => wp_generate_auth_cookie( 1, $expiration, 'logged_in', $token ),
);
// Administrator-only season preview on the public site; its nonce must belong to the same session.
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
$admin['preview-season'] = wp_nonce_url( add_query_arg( 'shiki_preview_season', 'winter', home_url( '/' ) ), 'shiki_season_preview' );

$written = file_put_contents( $target, wp_json_encode( array(
	'origin' => home_url(),
	'public' => $public,
	'admin' => $admin,
	'cookies' => $cookies,
	'expect' => array(
		'featured_title' => get_the_title( $posts[0] ),
		'classic_headings' => array( 'TEST クラシック見出しA', 'TEST クラシック見出しB' ),
		'author_post_title' => get_the_title( $posts[0] ),
		'child_parent_title' => get_the_title( $pages['about'] ),
		'event_label' => 'TEST 通年イベント',
	),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
if ( ! $written ) { throw new RuntimeException( 'Could not write the URL map.' ); }
chmod( $target, 0600 );
WP_CLI::success( 'Render scenario seeded: ' . count( $public ) . ' public and ' . count( $admin ) . ' administrator URLs.' );
