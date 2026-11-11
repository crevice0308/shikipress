<?php
/**
 * Regressions fixed in 1.1.8, on a real WordPress.
 * Dedicated disposable environment only: scripts/wp.sh eval-file tests/regression-integration.php
 * Every created post, term, user and option is removed again; settings are restored.
 */
if ( ! defined( 'SHIKI_TEST_ENV' ) || ! SHIKI_TEST_ENV || ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'Dedicated test WordPress required' ); }

/** The rows a browser would submit from the rendered slot forms: successful controls only. */
function shiki_regression_slot_rows( string $html ): array {
	$dom = new DOMDocument();
	@$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>' );
	$xpath = new DOMXPath( $dom );
	$rows = array();
	foreach ( $xpath->query( '//details[contains(@class,"shiki-ads-slot")]//*[self::input or self::select or self::textarea][@name]' ) as $control ) {
		if ( ! preg_match( '/^shikipress_settings\[ads_slots\]\[(\d+)\]\[([a-z_]+)\](\[\])?$/', $control->getAttribute( 'name' ), $match ) ) { continue; }
		$index = (int) $match[1];
		$field = $match[2];
		if ( 'input' === $control->nodeName ) {
			if ( 'checkbox' === $control->getAttribute( 'type' ) && ! $control->hasAttribute( 'checked' ) ) { continue; }
			$rows[ $index ][ $field ] = $control->getAttribute( 'value' );
		} elseif ( 'textarea' === $control->nodeName ) {
			$rows[ $index ][ $field ] = $control->textContent;
		} else {
			$selected = array();
			foreach ( $xpath->query( './/option[@selected]', $control ) as $option ) { $selected[] = $option->getAttribute( 'value' ); }
			if ( ! empty( $match[3] ) ) {
				if ( $selected ) { $rows[ $index ][ $field ] = $selected; }
			} else {
				$first = $xpath->query( './/option', $control )->item( 0 );
				$rows[ $index ][ $field ] = $selected ? $selected[0] : ( $first ? $first->getAttribute( 'value' ) : '' );
			}
		}
	}
	ksort( $rows );
	return array_values( $rows );
}

$checks = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	$checks[] = array( 'test' => $label, 'pass' => (bool) $condition );
	if ( ! $condition ) { throw new RuntimeException( $label ); }
};
$old_settings = get_option( 'shikipress_settings', array() );
$old_user = get_current_user_id();
$old_reading = array( 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => get_option( 'page_on_front' ), 'page_for_posts' => get_option( 'page_for_posts' ) );
$old_query = array( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
$old_get = $_GET;
$posts = array();
$terms = array();
$users = array();
$lock = 'shiki_regression_lock_' . strtolower( wp_generate_password( 8, false ) );
$purges = 0;
$count_purges = static function () use ( &$purges ) { ++$purges; };
// Tags of this run only, so that removing them afterwards never touches a tag of the site.
$tag_names = array( 'TEST regression tag A ' . wp_generate_password( 6, false ), 'TEST regression tag B ' . wp_generate_password( 6, false ) );
$remember_tags = static function () use ( &$terms, $tag_names ) {
	foreach ( $tag_names as $name ) {
		$tag = get_term_by( 'name', $name, 'post_tag' );
		if ( $tag && ! in_array( array( (int) $tag->term_id, 'post_tag' ), $terms, true ) ) { $terms[] = array( (int) $tag->term_id, 'post_tag' ); }
	}
};
$errors = array();
$theme_root = trailingslashit( (string) realpath( get_template_directory() ) );
set_error_handler( static function ( $number, $message, $file, $line ) use ( &$errors, $theme_root ) {
	// Only problems raised from the theme's own files are the subject of this suite; suppressed ones are not problems.
	if ( ( error_reporting() & $number ) && str_starts_with( (string) realpath( $file ), $theme_root ) ) { $errors[] = $message . ' @ ' . basename( $file ) . ':' . $line; }
	return false;
} );
try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
	wp_set_current_user( $admin );
	$defaults = shiki_defaults();
	$base = array_merge( $defaults, array_intersect_key( (array) $old_settings, $defaults ) );

	// ---------------------------------------------------------------------------------------
	// 1. Public caches follow publication. Before 1.1.8 every draft save, each block editor
	//    autosave of the author's own draft and even opening the new-post screen emptied them.
	// ---------------------------------------------------------------------------------------
	add_action( 'shiki_cache_invalidated', $count_purges );
	$draft = wp_insert_post( array( 'post_title' => 'TEST regression draft', 'post_content' => 'TEST', 'post_status' => 'draft' ) );
	$posts[] = $draft;
	$assert( $draft && 0 === $purges, 'Creating a draft keeps the public caches' );
	wp_update_post( array( 'ID' => $draft, 'post_content' => 'TEST edited' ) );
	wp_set_post_categories( $draft, array( (int) get_option( 'default_category' ) ) );
	wp_set_post_tags( $draft, array( $tag_names[0] ) );
	$remember_tags();
	update_post_meta( $draft, '_shiki_priority', 50 );
	update_post_meta( $draft, '_shiki_noindex', '1' );
	$assert( 0 === $purges, 'Saving a draft, its terms and its editor fields keeps the public caches' );
	$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft . '/autosaves' );
	$request->set_param( 'title', 'TEST regression draft typed' );
	$request->set_param( 'content', 'TEST typed' );
	$response = rest_do_request( $request );
	clean_post_cache( $draft );
	$assert( 200 === $response->get_status() && 'TEST regression draft typed' === get_post_field( 'post_title', $draft ), 'The block editor autosave updates the own draft itself' );
	$assert( 0 === $purges, 'A block editor autosave of the own draft keeps the public caches' );
	require_once ABSPATH . 'wp-admin/includes/post.php';
	$auto = get_default_post_to_edit( 'post', true );
	$posts[] = $auto->ID;
	$assert( 'auto-draft' === get_post_status( $auto->ID ) && 0 === $purges, 'Opening the new-post screen keeps the public caches' );
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'pending' ) );
	wp_delete_post( $auto->ID, true );
	$assert( 0 === $purges, 'A status change outside publication and deleting an unpublished post keep the public caches' );
	$epoch = (int) get_option( 'shikipress_cache_epoch' );
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) );
	$assert( $purges > 0 && (int) get_option( 'shikipress_cache_epoch' ) > $epoch, 'Publishing invalidates the public caches' );
	$assert( in_array( $draft, array_map( 'intval', shiki_public_posts( array( 'post__in' => array( $draft ), 'fields' => 'ids' ) )->posts ), true ), 'The published post enters the public list' );
	$purges = 0;
	update_post_meta( $draft, '_shiki_priority', 60 );
	$assert( 1 === $purges, 'Public metadata of a published post invalidates once' );
	$purges = 0;
	wp_set_post_tags( $draft, $tag_names );
	$remember_tags();
	$assert( $purges > 0, 'Terms of a published post invalidate' );
	$purges = 0;
	wp_update_post( array( 'ID' => $draft, 'post_content' => 'TEST published edit' ) );
	$assert( $purges > 0, 'Updating a published post invalidates' );
	$purges = 0;
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'draft' ) );
	$assert( $purges > 0, 'Unpublishing invalidates although the post is no longer public afterwards' );
	$assert( array() === shiki_public_posts( array( 'post__in' => array( $draft ), 'fields' => 'ids' ) )->posts, 'The unpublished post leaves the public list' );
	wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) );
	$purges = 0;
	wp_delete_post( $draft, true );
	$assert( $purges > 0, 'Deleting a published post invalidates' );
	remove_action( 'shiki_cache_invalidated', $count_purges );

	// ---------------------------------------------------------------------------------------
	// 2. Advertising screen: the extra row. Before 1.1.8 every save stored it as another
	//    disabled slot, and after deleting an earlier slot its ID repeated an existing one,
	//    so a slot entered there was dropped without a message.
	// ---------------------------------------------------------------------------------------
	$slot = static function ( string $id, string $name ): array {
		return array_merge( shiki_ads_blank_slot( $id ), array( 'name' => $name, 'service' => 'generic', 'state' => 'test', 'vendors' => array() ) );
	};
	$with_slots = static function ( array $slots ) use ( $base ): array { return array_merge( $base, array( 'ads_mode' => 'test', 'ads_slots' => $slots ) ); };
	$screen = static function (): array { ob_start(); do_action( 'shiki_admin_tab_ads' ); return shiki_regression_slot_rows( (string) ob_get_clean() ); };
	$consecutive = array( $slot( 'slot-1', 'TEST regression A' ), $slot( 'slot-2', 'TEST regression B' ) );
	update_option( 'shikipress_settings', $with_slots( $consecutive ), false );
	$rows = $screen();
	$assert( 3 === count( $rows ) && shiki_validate_settings( array( 'ads_slots' => $rows ), 'ads' )['ads_slots'] == $consecutive, 'Saving the advertising screen unchanged stores no further slot' );
	// "slot-1" was deleted earlier: two saved slots remain.
	$saved_slots = array( $slot( 'slot-2', 'TEST regression B' ), $slot( 'slot-3', 'TEST regression C' ) );
	update_option( 'shikipress_settings', $with_slots( $saved_slots ), false );
	$rows = $screen();
	$ids = array_column( $rows, 'id' );
	$assert( 3 === count( $rows ) && array( 'slot-2', 'slot-3' ) === array_slice( $ids, 0, 2 ) && ! in_array( $ids[2], array( 'slot-2', 'slot-3' ), true ), 'The extra slot row gets an ID no saved slot uses' );
	$assert( '1' === ( $rows[2]['template'] ?? '' ) && ! isset( $rows[0]['template'] ) && ! isset( $rows[1]['template'] ), 'Only the extra slot row is marked as offered by the screen' );
	$stored = shiki_validate_settings( array( 'ads_slots' => $rows ), 'ads' )['ads_slots'];
	$assert( $stored == $saved_slots, 'Saving that screen unchanged keeps exactly the saved slots' );
	$filled = $rows;
	$filled[2]['name'] = 'TEST regression new';
	$stored = shiki_validate_settings( array( 'ads_slots' => $filled ), 'ads' )['ads_slots'];
	$assert( 3 === count( $stored ) && $ids[2] === $stored[2]['id'] && 'TEST regression new' === $stored[2]['name'] && array_slice( $stored, 0, 2 ) == $saved_slots, 'A slot entered in the extra row is stored, also after an earlier slot was deleted' );
	$changed = $rows;
	$changed[2]['state'] = 'test';
	$assert( 3 === count( shiki_validate_settings( array( 'ads_slots' => $changed ), 'ads' )['ads_slots'] ), 'Any change to the extra row stores it' );
	// A saved slot that happens to look like the offered row is the administrator's data.
	update_option( 'shikipress_settings', $with_slots( array( shiki_ads_blank_slot( 'slot-1' ) ) ), false );
	$rows = $screen();
	$stored = shiki_validate_settings( array( 'ads_slots' => $rows ), 'ads' )['ads_slots'];
	$assert( 2 === count( $rows ) && ! isset( $rows[0]['template'] ) && 1 === count( $stored ) && 'slot-1' === $stored[0]['id'], 'A saved slot is never dropped, whatever its values' );
	// Rows posted without the marker (imports, other callers) keep their previous handling.
	$assert( 1 === count( shiki_validate_settings( array( 'ads_slots' => array( shiki_ads_blank_slot( 'slot-9' ) ) ), 'ads' )['ads_slots'] ), 'A row without the screen marker is stored as before' );
	$full = array();
	for ( $number = 1; $number <= 24; ++$number ) { $full[] = $slot( 'slot-' . $number, 'TEST regression ' . $number ); }
	update_option( 'shikipress_settings', $with_slots( $full ), false );
	$rows = $screen();
	$assert( 24 === count( $rows ) && array() === array_filter( $rows, static fn( $row ) => isset( $row['template'] ) ), 'No extra row is offered once the 24 slot limit is reached' );
	update_option( 'shikipress_settings', $base, false );

	// ---------------------------------------------------------------------------------------
	// 3. Settings screens: saving without a change must not alter a value.
	// ---------------------------------------------------------------------------------------
	$design = shiki_validate_settings( array( 'hero_title' => "TEST one\r\ntwo", 'hero_description' => "TEST three\rfour", 'hero_kicker' => 'TEST kicker' ), 'design' );
	$assert( "TEST one\ntwo" === $design['hero_title'] && "TEST three\nfour" === $design['hero_description'] && 'TEST kicker' === $design['hero_kicker'], 'Text areas store the line break form of the defaults, not the browser CRLF' );
	$_GET['counts_deleted'] = '1';
	ob_start();
	do_action( 'shiki_admin_tab_automation' );
	$automation_screen = (string) ob_get_clean();
	unset( $_GET['counts_deleted'] );
	$assert( str_contains( $automation_screen, '保存済みの匿名集計を削除しました。' ), 'Deleting the aggregate counts is confirmed on the screen' );

	// ---------------------------------------------------------------------------------------
	// 4. Job locks: creation is insert-only. add_option() skips its existence check after a
	//    cached absence and then writes with an UPSERT, replacing a concurrent owner.
	// ---------------------------------------------------------------------------------------
	global $wpdb;
	$first = shiki_acquire_job_lock( $lock, 30 );
	$assert( is_string( $first ) && '' !== $first && false === shiki_acquire_job_lock( $lock, 30 ) && $first === ( get_option( $lock )['token'] ?? null ), 'A held job lock is not acquired a second time' );
	shiki_release_job_lock( $lock, $first );
	$assert( array() === get_option( $lock, array() ), 'The owner releases its job lock' ); // This read also caches the absence.
	$other = array( 'expires' => time() + 30, 'token' => 'other-request' );
	$wpdb->insert( $wpdb->options, array( 'option_name' => $lock, 'option_value' => maybe_serialize( $other ), 'autoload' => 'no' ) );
	$assert( false === shiki_acquire_job_lock( $lock, 30 ), 'A lock another request created after a cached absence is not acquired' );
	$assert( $other === get_option( $lock ), 'That lock is not overwritten and both option caches are refreshed' );
	shiki_release_job_lock( $lock, 'other-request' );
	$expired = array( 'expires' => time() - 5, 'token' => 'expired-owner' );
	$wpdb->insert( $wpdb->options, array( 'option_name' => $lock, 'option_value' => maybe_serialize( $expired ), 'autoload' => 'no' ) );
	wp_cache_delete( $lock, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$second = shiki_acquire_job_lock( $lock, 30 );
	$assert( is_string( $second ) && $second === ( get_option( $lock )['token'] ?? null ), 'An expired job lock is reclaimed' );
	shiki_release_job_lock( $lock, $second );

	// ---------------------------------------------------------------------------------------
	// 5. Canonical URL of the front page when a static page is the front page: core names a
	//    non-existent /N/ for /page/N/, while the template renders the same curated home.
	// ---------------------------------------------------------------------------------------
	$front = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'TEST regression front', 'post_content' => 'TEST' ) );
	$posts[] = $front;
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front );
	$query = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['wp_query'] = $query;
	$query->query( array( 'paged' => 2 ) );
	$assert( is_front_page() && is_singular() && 2 === (int) get_query_var( 'page' ), 'A static front page receives /page/2/ as its second page' );
	$assert( home_url( '/' ) === shiki_seo_canonical(), 'The canonical URL of a paged static front page is the home URL' );
	$query = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['wp_query'] = $query;
	$query->query( array() );
	$assert( is_front_page() && home_url( '/' ) === shiki_seo_canonical(), 'The canonical URL of the static front page is the home URL' );
	foreach ( $old_reading as $option => $value ) { update_option( $option, $value ); }
	list( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ) = $old_query;

	// ---------------------------------------------------------------------------------------
	// 6. "No indexable term" in the XML sitemap. An include list of only 0 is no filter at
	//    all before WordPress 6.9, which listed every category, hidden and noindex ones too.
	// ---------------------------------------------------------------------------------------
	$all_categories = array_map( 'intval', get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ) );
	$assert( count( $all_categories ) > 1, 'The fixtures provide several categories' );
	$assert( array() === get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids', 'include' => shiki_no_term_ids() ) ), 'The empty term list matches no term on this WordPress version' );
	update_option( 'shikipress_settings', array_merge( $base, array( 'category_exclude' => $all_categories ) ), false );
	$assert( array() === shiki_seo_public_term_ids( 'category' ), 'With every category hidden, no category is indexable' );
	$sitemap_args = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ), 'category' );
	$assert( array() === get_terms( $sitemap_args ), 'The category sitemap is then empty instead of listing every category' );
	update_option( 'shikipress_settings', $base, false );

	// ---------------------------------------------------------------------------------------
	// 7. Hidden categories are omitted from anonymous REST term lists as well.
	// ---------------------------------------------------------------------------------------
	$hidden = wp_insert_term( 'TEST_REGRESSION_HIDDENCAT_' . wp_generate_password( 6, false ), 'category' );
	$shown = wp_insert_term( 'TEST_REGRESSION_SHOWNCAT_' . wp_generate_password( 6, false ), 'category' );
	$terms[] = array( $hidden['term_id'], 'category' );
	$terms[] = array( $shown['term_id'], 'category' );
	update_option( 'shikipress_settings', array_merge( $base, array( 'category_exclude' => array( (int) $hidden['term_id'] ) ) ), false );
	$rest_ids = static function ( string $route, array $params ): array {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
		$response = rest_do_request( $request );
		return 200 === $response->get_status() ? array_map( 'intval', array_column( $response->get_data(), 'id' ) ) : array( -1 );
	};
	wp_set_current_user( 0 );
	$listed = $rest_ids( '/wp/v2/categories', array( 'per_page' => 100, 'hide_empty' => false ) );
	$assert( in_array( (int) $shown['term_id'], $listed, true ) && ! in_array( (int) $hidden['term_id'], $listed, true ), 'The anonymous REST category list omits a hidden category' );
	$assert( array() === $rest_ids( '/wp/v2/categories', array( 'include' => array( (int) $hidden['term_id'] ), 'hide_empty' => false ) ), 'Asking the list for a hidden category by ID returns nothing' );
	$assert( array( (int) $shown['term_id'] ) === $rest_ids( '/wp/v2/categories', array( 'include' => array( (int) $hidden['term_id'], (int) $shown['term_id'] ), 'hide_empty' => false ) ), 'A mixed ID list keeps the visible category only' );
	$found = $rest_ids( '/wp/v2/search', array( 'type' => 'term', 'subtype' => 'category', 'search' => 'TEST_REGRESSION_', 'per_page' => 100 ) );
	$assert( in_array( (int) $shown['term_id'], $found, true ) && ! in_array( (int) $hidden['term_id'], $found, true ), 'The anonymous REST term search omits a hidden category' );
	$editor = wp_insert_user( array( 'user_login' => 'shiki_regression_editor_' . strtolower( wp_generate_password( 6, false ) ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'editor' ) );
	$users[] = $editor;
	wp_set_current_user( $editor );
	$listed = $rest_ids( '/wp/v2/categories', array( 'per_page' => 100, 'hide_empty' => false ) );
	$assert( in_array( (int) $hidden['term_id'], $listed, true ), 'Editors still receive every category, as the editor needs' );
	wp_set_current_user( $admin );
	update_option( 'shikipress_settings', $base, false );

	// ---------------------------------------------------------------------------------------
	// 8. The list query and the card renderer agree on what "excluded" is: exactly the value 1.
	// ---------------------------------------------------------------------------------------
	$long = wp_insert_post( array( 'post_title' => 'TEST regression card', 'post_content' => str_repeat( '季節のしおりをめくる小さな楽しみ。', 300 ), 'post_status' => 'publish' ) );
	$posts[] = $long;
	$card = static function ( int $id ): string { ob_start(); shiki_card( $id ); return (string) ob_get_clean(); };
	$listed = static fn( int $id ): bool => in_array( $id, array_map( 'intval', shiki_public_posts( array( 'post__in' => array( $id ), 'fields' => 'ids' ) )->posts ), true );
	update_post_meta( $long, '_shiki_exclude', 'yes' );
	$assert( $listed( $long ) && '' !== $card( $long ), 'A post the list query returns always renders its card' );
	update_post_meta( $long, '_shiki_exclude', 1 );
	$assert( ! $listed( $long ) && '' === $card( $long ), 'An excluded post is neither listed nor rendered' );
	delete_post_meta( $long, '_shiki_exclude' );
	// Card excerpts stay bounded on any site language (wp_trim_words() counts spaced words by default).
	$excerpt = preg_match( '~<p class="card-excerpt">(.*?)</p>~s', $card( $long ), $match ) ? html_entity_decode( trim( $match[1] ), ENT_QUOTES, 'UTF-8' ) : null;
	$assert( is_string( $excerpt ) && '' !== $excerpt && mb_strlen( $excerpt ) <= 400, 'A card excerpt of unspaced text is bounded' );

	// ---------------------------------------------------------------------------------------
	// 9. A settings option of an unexpected shape (damaged, or written by other code) never
	//    stops a public page or a settings screen.
	// ---------------------------------------------------------------------------------------
	$damaged = array(
		'season_mode' => 'fixed', 'season_fixed' => array( 'x' ), 'timezone' => array( 'x' ), 'tabs' => 'x', 'page_roles' => 'x', 'page_exclude' => 'x', 'page_priority' => 7, 'category_exclude' => 'x',
		'season_terms' => 'x', 'featured_ids' => 'x', 'featured_mode' => array( 'manual' ), 'popular_days' => array( 7 ), 'view_count_enabled' => array( 1 ),
		'season_events' => array( 'x', 5, array( 'enabled' => 1, 'start' => array( 'a' ), 'end' => 5 ), array( 'enabled' => 1, 'start' => '01-01', 'end' => '12-31', 'label' => array( 'x' ), 'accent' => array( 'y' ), 'id' => array( 'z' ), 'priority' => array( 9 ) ) ),
		'ads_mode' => array( 'test' ), 'ads_limit' => array( 3 ), 'ads_publisher' => array( 'p' ), 'ads_cmp_id' => array( 1 ),
		'ads_slots' => array( 'x', 5, array( 'id' => array( 'y' ), 'name' => array( 'n' ), 'state' => 'test', 'position' => 'below', 'post_types' => 'post', 'shared_loaders' => 'x', 'vendors' => array( array( 1 ) ) ) ),
		'hero_kicker' => array( 'a' ), 'hero_title' => array( 'a' ), 'hero_description' => array( 'a' ), 'toc_enabled' => array(), 'show_popular' => array( 1 ),
		'seo_home_title' => array( 't' ), 'seo_home_description' => array( 'd' ), 'seo_google_verification' => array( 'g' ), 'seo_sitemap_per_page' => array( 5 ),
	);
	update_option( 'shikipress_settings', $damaged, false );
	$errors = array();
	$rendered = '';
	$thrown = null;
	try {
		foreach ( array_keys( $defaults ) as $key ) {
			$value = shiki_option( $key );
			if ( is_array( $defaults[ $key ] ) !== is_array( $value ) || ( ! is_array( $value ) && null !== $value && ! is_scalar( $value ) ) ) { throw new RuntimeException( 'shiki_option returned an unexpected shape for ' . $key ); }
		}
		shiki_season();
		shiki_timezone()->getName();
		shiki_active_event();
		shiki_season_context();
		shiki_season_terms();
		shiki_ads_named_slot( '' );
		shiki_ads_content_slots();
		shiki_ad_html( 'below' );
		shiki_public_pages();
		shiki_public_posts( array( 'posts_per_page' => 1 ) );
		shiki_visible_categories();
		shiki_select_posts( 'featured', array(), 3 );
		shiki_html_sitemap();
		ob_start();
		try {
			locate_template( 'front-page.php', true, false );
			// The settings screens run with a current screen, as in the real admin (core's page dropdown reads it).
			$had_screen = array_key_exists( 'current_screen', $GLOBALS );
			$old_screen = $GLOBALS['current_screen'] ?? null;
			set_current_screen( 'toplevel_page_shikipress' );
			try {
				foreach ( array( 'dashboard', 'design', 'automation', 'navigation', 'ads', 'seo', 'diagnostics', 'backup' ) as $tab ) { do_action( 'shiki_admin_tab_' . $tab ); }
			} finally {
				if ( $had_screen ) { $GLOBALS['current_screen'] = $old_screen; } else { unset( $GLOBALS['current_screen'] ); }
			}
		} finally { $rendered = (string) ob_get_clean(); }
		if ( array() !== shiki_ads_editor_slots() ) { throw new RuntimeException( 'Damaged slot rows reached the editor block choices' ); }
	} catch ( Throwable $problem ) { $thrown = get_class( $problem ) . ': ' . $problem->getMessage() . ' @ ' . basename( $problem->getFile() ) . ':' . $problem->getLine(); }
	update_option( 'shikipress_settings', $base, false );
	$assert( null === $thrown, 'A damaged settings option raises no error in templates and screens' . ( $thrown ? ' (' . $thrown . ')' : '' ) );
	$assert( array() === $errors, 'A damaged settings option raises no PHP warning in the theme' . ( $errors ? ' (' . implode( ' | ', array_slice( array_unique( $errors ), 0, 5 ) ) . ')' : '' ) );
	$assert( str_contains( $rendered, 'id="main-content"' ) && str_contains( $rendered, 'class="mobile-tabs"' ) && str_contains( $rendered, 'ホーム' ), 'The front page still renders completely with default labels' );
} catch ( Throwable $error ) {
	$checks[] = array( 'test' => 'exception', 'pass' => false, 'error' => $error->getMessage() );
} finally {
	restore_error_handler();
	remove_action( 'shiki_cache_invalidated', $count_purges );
	$_GET = $old_get;
	list( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ) = $old_query;
	foreach ( $old_reading as $option => $value ) { update_option( $option, $value ); }
	wp_set_current_user( $old_user ?: ( $admin ?? 0 ) );
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( $terms as $term ) { wp_delete_term( $term[0], $term[1] ); }
	if ( $users ) { require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); } }
	delete_option( $lock );
	update_option( 'shikipress_settings', $old_settings, false );
	shiki_invalidate_cache();
	wp_set_current_user( $old_user );
}
$directory = __DIR__ . '/results';
wp_mkdir_p( $directory );
file_put_contents( $directory . '/regression-integration.json', wp_json_encode( array( 'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ), 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
foreach ( $checks as $check ) { WP_CLI::log( ( $check['pass'] ? 'PASS ' : 'FAIL ' ) . $check['test'] . ( isset( $check['error'] ) ? ' ' . $check['error'] : '' ) ); }
if ( array_filter( $checks, static fn( $check ) => ! $check['pass'] ) ) { WP_CLI::error( 'Regression integration failed' ); }
echo 'REGRESSION_TOTAL ' . count( $checks ) . PHP_EOL;
