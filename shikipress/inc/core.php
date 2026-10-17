<?php
/** Shared WordPress integration and settings. */
defined( 'ABSPATH' ) || exit;

function shiki_defaults() {
    return apply_filters( 'shiki_defaults', array(
        'season_mode' => 'auto', 'season_fixed' => 'autumn', 'timezone' => 'Asia/Tokyo',
        'page_exclude' => array(), 'page_priority' => array(), 'category_exclude' => array(),
        'page_roles' => array(),
        'tabs' => array( 'home' => 'ホーム', 'categories' => 'カテゴリー', 'pages' => '固定ページ', 'search' => '検索' ),
    ) );
}

function shiki_option( $key, $default = null ) {
    $options = get_option( 'shikipress_settings', array() );
    $options = is_array( $options ) ? $options : array();
    $defaults = shiki_defaults();
    return array_key_exists( $key, $options ) ? $options[ $key ] : ( $defaults[ $key ] ?? $default );
}

function shiki_setup() {
    load_theme_textdomain( 'shikipress', SHIKI_DIR . '/languages' );
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'custom-logo', array( 'height' => 100, 'width' => 320, 'flex-height' => true, 'flex-width' => true ) );
    add_theme_support( 'automatic-feed-links' );
    add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
    add_theme_support( 'editor-styles' );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'align-wide' );
    add_image_size( 'shiki-card', 760, 520, true );
    add_image_size( 'shiki-card-small', 380, 260, true );
    add_image_size( 'shiki-hero', 1440, 900, true );
    register_nav_menus( array( 'primary' => __( '上部メニュー', 'shikipress' ), 'footer' => __( 'フッターメニュー', 'shikipress' ) ) );
    register_sidebar( array( 'name' => __( '記事サイドバー', 'shikipress' ), 'id' => 'shiki-sidebar', 'before_widget' => '<section class="shiki-widget">', 'after_widget' => '</section>', 'before_title' => '<h2>', 'after_title' => '</h2>' ) );
}
add_action( 'after_setup_theme', 'shiki_setup' );

// Native OS emoji keep the default reader experience free of external fallback assets.
add_action( 'init', static function() {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
    add_filter( 'emoji_svg_url', '__return_false' );
} );

function shiki_activate() {
    add_option( 'shikipress_settings', shiki_defaults(), '', false );
    add_option( 'shikipress_cache_epoch', 1, '', false );
    update_option( 'shikipress_version', SHIKI_VERSION, false );
    shiki_invalidate_cache();
}
add_action( 'after_switch_theme', 'shiki_activate' );

// Replacing an active ZIP does not trigger after_switch_theme.
add_action( 'init', static function () {
    if ( get_option( 'shikipress_version' ) !== SHIKI_VERSION ) {
        update_option( 'shikipress_version', SHIKI_VERSION, false );
        shiki_invalidate_cache();
    }
}, 99 );

function shiki_invalidate_cache() {
    $epoch = (int) get_option( 'shikipress_cache_epoch', 1 );
    update_option( 'shikipress_cache_epoch', $epoch + 1, false );
    do_action( 'shiki_cache_invalidated' );
}
add_action( 'save_post', 'shiki_invalidate_cache', 90, 0 );
add_action( 'deleted_post', 'shiki_invalidate_cache', 10, 0 );
add_action( 'set_object_terms', 'shiki_invalidate_cache', 10, 0 );
add_action( 'edited_term', 'shiki_invalidate_cache', 10, 0 );
add_action( 'delete_term', 'shiki_invalidate_cache', 10, 0 );
add_action( 'update_option_shikipress_settings', 'shiki_invalidate_cache', 10, 0 );
add_action( 'update_option_blog_public', 'shiki_invalidate_cache', 10, 0 );

// REST and plugins may update these fields after save_post has already fired.
function shiki_public_meta_changed( $meta_id, $object_id, $meta_key ) {
    if ( in_array( $meta_key, array( '_shiki_exclude', '_shiki_noindex', '_shiki_priority', '_shiki_seo_title', '_shiki_seo_description', '_shiki_og_image', '_thumbnail_id' ), true ) ) { shiki_invalidate_cache(); }
}
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $shiki_meta_hook ) {
    add_action( $shiki_meta_hook, 'shiki_public_meta_changed', 20, 3 );
}
unset( $shiki_meta_hook );

// Term metadata can be saved independently of edited_term as well.
foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $shiki_meta_hook ) {
    add_action( $shiki_meta_hook, static function ( $meta_id, $term_id, $meta_key ) {
        if ( in_array( $meta_key, array( '_shiki_noindex', '_shiki_seo_title', '_shiki_seo_description' ), true ) ) { shiki_invalidate_cache(); }
    }, 20, 3 );
}
unset( $shiki_meta_hook );


function shiki_visibility_meta_query() {
    return array( 'relation' => 'OR', array( 'key' => '_shiki_exclude', 'compare' => 'NOT EXISTS' ), array( 'key' => '_shiki_exclude', 'value' => '1', 'compare' => '!=' ) );
}

function shiki_public_posts( $args = array() ) {
    $meta = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
    $args = wp_parse_args( $args, array( 'post_type' => 'post', 'posts_per_page' => 6, 'ignore_sticky_posts' => true, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) ) );
    $args['post_status'] = 'publish';
    $args['has_password'] = false;
    $args['suppress_filters'] = false;
    $args['meta_query'] = empty( $meta ) ? array( shiki_visibility_meta_query() ) : array( 'relation' => 'AND', $meta, shiki_visibility_meta_query() );
    $hidden = array_map( 'absint', (array) shiki_option( 'category_exclude', array() ) );
    if ( 'post' === $args['post_type'] && $hidden ) {
        $args['category__not_in'] = array_values( array_unique( array_merge( $hidden, (array) ( $args['category__not_in'] ?? array() ) ) ) );
    }
    return new WP_Query( $args );
}

function shiki_public_pages() {
    $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ), 'suppress_filters' => false, 'exclude' => array_map( 'absint', (array) shiki_option( 'page_exclude', array() ) ), 'meta_query' => array( shiki_visibility_meta_query() ) ) );
    $priority = array_values( array_map( 'absint', (array) shiki_option( 'page_priority', array() ) ) );
    if ( $priority ) {
        $positions = array_flip( $priority );
        usort( $pages, static function( $a, $b ) use ( $positions ) {
            $rank = ( $positions[ $a->ID ] ?? PHP_INT_MAX ) <=> ( $positions[ $b->ID ] ?? PHP_INT_MAX );
            return $rank ?: ( $a->menu_order <=> $b->menu_order ) ?: strcmp( $a->post_title, $b->post_title );
        } );
    }
    return $pages;
}

function shiki_public_query_scope( $query ) {
    if ( is_admin() || ! $query->is_main_query() || $query->is_singular() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }
    if ( $query->is_search() || $query->is_archive() || $query->is_home() || $query->is_feed() ) {
        $query->set( 'has_password', false );
        $query->set( 'post_status', 'publish' );
        $meta = (array) $query->get( 'meta_query' );
        $meta[] = shiki_visibility_meta_query();
        $query->set( 'meta_query', $meta );
        $excluded = array_map( 'absint', (array) shiki_option( 'page_exclude', array() ) );
        if ( $excluded ) {
            $query->set( 'post__not_in', array_unique( array_merge( (array) $query->get( 'post__not_in' ), $excluded ) ) );
        }
        $categories = array_map( 'absint', (array) shiki_option( 'category_exclude', array() ) );
        if ( $categories ) {
            $query->set( 'category__not_in', array_unique( array_merge( (array) $query->get( 'category__not_in' ), $categories ) ) );
        }
    }
}
add_action( 'pre_get_posts', 'shiki_public_query_scope' );

/** Public REST lists follow the same scope; authenticated editor requests retain core access. */
function shiki_rest_public_scope( $args, $request ) {
    if ( 'edit' === $request->get_param( 'context' ) && current_user_can( 'edit_posts' ) ) { return $args; }
    $args['post_status'] = 'publish';
    $args['has_password'] = false;
    $args['meta_query'] = array( 'relation' => 'AND', (array) ( $args['meta_query'] ?? array() ), shiki_visibility_meta_query() );
    $args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), shiki_ids( shiki_option( 'page_exclude', array() ) ) ) ) );
    $args['category__not_in'] = array_values( array_unique( array_merge( (array) ( $args['category__not_in'] ?? array() ), shiki_ids( shiki_option( 'category_exclude', array() ) ) ) ) );
    return $args;
}
add_filter( 'rest_post_query', 'shiki_rest_public_scope', 20, 2 );
add_filter( 'rest_page_query', 'shiki_rest_public_scope', 20, 2 );
add_filter( 'rest_post_search_query', 'shiki_rest_public_scope', 20, 2 );

/** A prepared fixed-policy EXISTS clause also protects core's separate comments-feed SQL. */
function shiki_public_comment_post_sql() {
    global $wpdb;
    $sql = "EXISTS (SELECT 1 FROM {$wpdb->posts} shiki_cp WHERE shiki_cp.ID = {$wpdb->comments}.comment_post_ID AND shiki_cp.post_status = 'publish' AND shiki_cp.post_password = '' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} shiki_cm WHERE shiki_cm.post_id = shiki_cp.ID AND shiki_cm.meta_key = '_shiki_exclude' AND shiki_cm.meta_value = '1')";
    $ids = shiki_ids( shiki_option( 'page_exclude', array() ) );
    if ( $ids ) { $sql .= $wpdb->prepare( ' AND shiki_cp.ID NOT IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ); }
    $terms = shiki_ids( shiki_option( 'category_exclude', array() ) );
    if ( $terms ) {
        $sql .= $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} shiki_cr INNER JOIN {$wpdb->term_taxonomy} shiki_ct ON shiki_cr.term_taxonomy_id = shiki_ct.term_taxonomy_id WHERE shiki_cr.object_id = shiki_cp.ID AND shiki_ct.taxonomy = 'category' AND shiki_ct.term_id IN (" . implode( ',', array_fill( 0, count( $terms ), '%d' ) ) . '))', $terms );
    }
    return $sql . ')';
}
add_filter( 'comment_feed_where', static function( $where ) { return $where . ' AND ' . shiki_public_comment_post_sql(); } );
add_filter( 'rest_comment_query', static function( $args, $request ) {
    if ( 'edit' !== $request->get_param( 'context' ) || ! current_user_can( 'moderate_comments' ) ) { $args['shiki_public_scope'] = true; }
    return $args;
}, 20, 2 );
add_filter( 'comments_clauses', static function( $clauses, $query ) {
    if ( ! empty( $query->query_vars['shiki_public_scope'] ) ) { $clauses['where'] .= ' AND ' . shiki_public_comment_post_sql(); }
    return $clauses;
}, 20, 2 );

function shiki_setting_sections() {
    return apply_filters( 'shiki_setting_sections', array( 'season_mode' => 'design', 'season_fixed' => 'design', 'timezone' => 'design', 'tabs' => 'navigation', 'page_roles' => 'navigation', 'page_exclude' => 'navigation', 'page_priority' => 'navigation', 'category_exclude' => 'navigation' ) );
}

function shiki_ids( $value ) {
    if ( is_string( $value ) ) {
        $value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
    }
    return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
}

function shiki_validate_settings( $input, $section, $existing = null ) {
    $defaults = shiki_defaults();
    $current = is_array( $existing ) ? $existing : get_option( 'shikipress_settings', array() );
    $out = array_merge( $defaults, is_array( $current ) ? $current : array() );
    if ( ! is_array( $input ) ) { return $out; }
    $input = array_intersect_key( $input, $defaults );
    foreach ( $input as $key => $value ) {
        if ( ! is_array( $defaults[ $key ] ) && ! is_scalar( $value ) && null !== $value ) { $input[ $key ] = $defaults[ $key ]; }
        elseif ( is_array( $defaults[ $key ] ) && ! is_array( $value ) && ! in_array( $key, array( 'page_exclude', 'page_priority', 'category_exclude', 'featured_ids' ), true ) ) { $input[ $key ] = $defaults[ $key ]; }
    }

    if ( 'navigation' === $section ) {
        foreach ( array( 'page_exclude', 'page_priority', 'category_exclude' ) as $key ) { $out[ $key ] = shiki_ids( $input[ $key ] ?? array() ); }
        foreach ( array( 'home', 'categories', 'pages', 'search' ) as $key ) {
            $value = sanitize_text_field( $input['tabs'][ $key ] ?? $defaults['tabs'][ $key ] );
            $out['tabs'][ $key ] = mb_substr( $value ?: $defaults['tabs'][ $key ], 0, 12 );
        }
        $out['page_roles'] = array();
        foreach ( array( 'privacy', 'contact', 'operator', 'about', 'disclaimer', 'sitemap' ) as $role ) {
            $id = absint( $input['page_roles'][ $role ] ?? 0 );
            if ( $id && 'page' === get_post_type( $id ) ) { $out['page_roles'][ $role ] = $id; }
        }
    }
    return apply_filters( 'shiki_sanitize_settings', $out, $input, $section );
}

function shiki_sanitize_settings( $input ) {
    if ( ! current_user_can( 'manage_options' ) ) { return get_option( 'shikipress_settings', array() ); }
    // Registered sanitizers also run for internal update_option calls. Each such
    // action validates its own nonce and payload before reaching this boundary.
    if ( 'shikipress' !== ( $_POST['option_page'] ?? '' ) ) {
        return is_array( $input ) ? array_intersect_key( $input, shiki_defaults() ) : get_option( 'shikipress_settings', array() );
    }
    $section = sanitize_key( wp_unslash( $_POST['shikipress_section'] ?? '' ) );
    if ( ! in_array( $section, array( 'dashboard', 'design', 'automation', 'navigation', 'ads', 'seo', 'diagnostics', 'backup' ), true ) ) {
        return get_option( 'shikipress_settings', array() );
    }
    return shiki_validate_settings( $input, $section );
}

function shiki_register_settings() {
    register_setting( 'shikipress', 'shikipress_settings', array( 'type' => 'array', 'sanitize_callback' => 'shiki_sanitize_settings', 'show_in_rest' => false, 'default' => shiki_defaults() ) );
}
add_action( 'admin_init', 'shiki_register_settings' );

function shiki_settings_form_start( $section ) {
    echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="shiki-settings-form">';
    settings_fields( 'shikipress' );
    echo '<input type="hidden" name="shikipress_section" value="' . esc_attr( $section ) . '">';
}
function shiki_settings_form_end() { submit_button( __( '設定を保存', 'shikipress' ) ); echo '</form>'; }

function shiki_admin_tabs() {
    return array( 'dashboard' => 'ダッシュボード', 'design' => 'デザイン', 'automation' => '記事自動化', 'navigation' => 'ナビゲーション', 'ads' => '広告', 'seo' => 'SEO', 'diagnostics' => '診断', 'backup' => '設定バックアップ' );
}
function shiki_admin_menu() {
    add_menu_page( 'ShikiPress', 'ShikiPress', 'manage_options', 'shikipress', 'shiki_admin_page', 'dashicons-palmtree', 59 );
    foreach ( shiki_admin_tabs() as $tab => $label ) {
        if ( 'dashboard' === $tab ) { continue; }
        add_submenu_page( 'shikipress', $label, $label, 'manage_options', 'shikipress-' . $tab, static function() use ( $tab ) { shiki_admin_page( $tab ); } );
    }
}
add_action( 'admin_menu', 'shiki_admin_menu' );
function shiki_admin_url( $tab = 'dashboard' ) { return add_query_arg( array( 'page' => 'shikipress', 'tab' => $tab ), admin_url( 'admin.php' ) ); }
function shiki_admin_page( $requested = '' ) {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'この設定へのアクセス権限がありません。', 'shikipress' ), '', array( 'response' => 403 ) ); }
    $tabs = shiki_admin_tabs();
    $tab = $requested ?: sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) );
    if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'dashboard'; }
    echo '<div class="wrap shiki-admin"><div class="shiki-admin-heading"><span class="shiki-admin-mark">栞</span><div><p>SHIKIPRESS ULTIMATE · ' . esc_html( SHIKI_VERSION ) . '</p><h1>' . esc_html( $tabs[ $tab ] ) . '</h1></div></div><nav class="shiki-admin-nav" aria-label="ShikiPress設定">';
    foreach ( $tabs as $key => $label ) { echo '<a class="' . ( $tab === $key ? 'is-current' : '' ) . '" href="' . esc_url( shiki_admin_url( $key ) ) . '"' . ( $tab === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
    echo '</nav>';
    settings_errors();
    do_action( 'shiki_admin_tab_' . $tab );
    echo '</div>';
}
function shiki_admin_assets( $hook ) {
    if ( false === strpos( $hook, 'shikipress' ) ) { return; }
    wp_enqueue_style( 'shiki-admin', SHIKI_URL . '/assets/admin.css', array(), SHIKI_VERSION );
}
add_action( 'admin_enqueue_scripts', 'shiki_admin_assets' );

function shiki_dashboard_screen() {
    $count = wp_count_posts();
    $number = (int) $count->publish;
    echo '<div class="shiki-admin-card"><h2>季節のしおりを、ひとつずつ。</h2><p>テーマは高級感、操作はシンプル。初期設定は次の順に確認できます。</p><ol class="shiki-setup-steps">';
    foreach ( array( 'automation' => '四季カテゴリーを確認・対応付けする（実行前に変更内容を確認）', 'design' => 'ロゴ・配色・季節の切り替えを確認する', 'navigation' => '重要な固定ページを対応付ける', 'ads' => '広告がテスト状態であることを確認する', 'diagnostics' => '公開設定と自己診断を確認する' ) as $tab => $label ) {
        echo '<li><a href="' . esc_url( shiki_admin_url( $tab ) ) . '">' . esc_html( $label ) . '</a></li>';
    }
    echo '</ol></div><div class="shiki-admin-card"><h2>運営の目安</h2><p>公開記事数：<strong>' . esc_html( (string) $number ) . '</strong>件。四季各5記事・合計20記事の準備後に、内容を確認してSearch Consoleへ手動登録してください。TEST記事は完成記事数に含めません。</p><p>20記事は運営上の区切りです。テーマから自動登録・申請は行いません。</p><p><a class="button" href="' . esc_url( home_url( '/' ) ) . '">サイトを表示</a> <a class="button" href="' . esc_url( admin_url( 'post-new.php' ) ) . '">記事を書く</a></p></div>';
    echo '<details class="shiki-admin-card"><summary>重要固定ページの下書きを準備</summary><p>変更予定：未作成の役割について、確認用の下書き6件を作成してIDを対応付けます。既存の対応ページは変更しません。個人情報や架空の運営者は入力しません。</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="shiki_prepare_pages">';
    wp_nonce_field( 'shiki_prepare_pages' );
    submit_button( '下書きを準備する', 'secondary' );
    echo '</form></details>';
}
add_action( 'shiki_admin_tab_dashboard', 'shiki_dashboard_screen' );

function shiki_prepare_pages() {
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_pages' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'shiki_prepare_pages' );
    $settings = get_option( 'shikipress_settings', array() );
    $roles = (array) ( $settings['page_roles'] ?? array() );
    $templates = array(
        'privacy' => array( 'プライバシーポリシー', '個人情報の取り扱い、広告・同意管理、Cookie、問い合わせ先を、実際の運用に合わせて記入してください。外部サービスの導入後はその条件を確認してください。' ),
        'contact' => array( 'お問い合わせ', '実際に利用できる連絡方法を記入してください。この下書きにはフォームや送信先は設定されていません。' ),
        'operator' => array( '運営者情報', '公開する運営者名と連絡先などを、本人の確認後に記入してください。' ),
        'about' => array( 'このサイトについて', '季節のしおりの目的、扱うテーマ、情報の更新方針を記入してください。' ),
        'disclaimer' => array( '免責事項', '掲載内容、外部リンク、広告について実際の運用に合わせて記入し、内容を確認してください。' ),
        'sitemap' => array( 'サイトマップ', '[shiki_sitemap]' ),
    );
    foreach ( $templates as $role => $template ) {
        if ( ! empty( $roles[ $role ] ) && get_post( $roles[ $role ] ) ) { continue; }
        $previous = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_shiki_page_role', 'meta_value' => $role ) );
        if ( $previous ) { $roles[ $role ] = $previous[0]->ID; continue; }
        $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $template[0], 'post_content' => $template[1], 'meta_input' => array( '_shiki_page_role' => $role ) ), true );
        if ( ! is_wp_error( $id ) ) { $roles[ $role ] = $id; }
    }
    $settings['page_roles'] = $roles;
    update_option( 'shikipress_settings', $settings, false );
    wp_safe_redirect( shiki_admin_url( 'navigation' ) ); exit;
}
add_action( 'admin_post_shiki_prepare_pages', 'shiki_prepare_pages' );

function shiki_navigation_screen() {
    shiki_settings_form_start( 'navigation' );
    echo '<div class="shiki-admin-card"><h2>基本設定：スマホ下部の4タブ</h2><p>ホームはトップページへ移動し、他の3つは一覧・検索パネルを開きます。</p>';
    foreach ( (array) shiki_option( 'tabs' ) as $key => $label ) {
        echo '<p><label>' . esc_html( $key ) . ' <input type="text" maxlength="12" name="shikipress_settings[tabs][' . esc_attr( $key ) . ']" value="' . esc_attr( $label ) . '"></label></p>';
    }
    echo '</div><div class="shiki-admin-card"><h2>重要固定ページの対応付け</h2><p>公開済み・パスワードなし・除外なしのページだけを公開画面に表示します。選択しても下書きは公開されません。</p>';
    $roles = (array) shiki_option( 'page_roles', array() );
    foreach ( array( 'privacy' => 'プライバシーポリシー', 'contact' => 'お問い合わせ', 'operator' => '運営者情報', 'about' => 'このサイトについて', 'disclaimer' => '免責事項', 'sitemap' => 'サイトマップ' ) as $role => $label ) {
        echo '<p><label>' . esc_html( $label ) . '<br>';
        wp_dropdown_pages( array( 'name' => 'shikipress_settings[page_roles][' . $role . ']', 'selected' => absint( $roles[ $role ] ?? 0 ), 'show_option_none' => '未設定', 'option_none_value' => 0, 'post_status' => array( 'publish', 'draft', 'private', 'pending' ), 'echo' => true ) );
        echo '</label></p>';
    }
    echo '</div><details class="shiki-admin-card"><summary>詳細設定：表示順と除外</summary><p>ページIDは固定ページ編集URLのpost=の値です。カンマ区切りで入力します。</p>';
    foreach ( array( 'page_priority' => '優先表示するページID（入力順）', 'page_exclude' => '一覧から除外するページID', 'category_exclude' => '一覧から除外するカテゴリーID' ) as $key => $label ) {
        echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="text" inputmode="numeric" name="shikipress_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( implode( ',', (array) shiki_option( $key, array() ) ) ) . '"></label></p>';
    }
    echo '<p>それ以外のページはWordPressの並び順（menu_order）、タイトル順で表示します。固定ページの表示順は編集画面の「ページ属性」から変更できます。</p></details>';
    shiki_settings_form_end();
}
add_action( 'shiki_admin_tab_navigation', 'shiki_navigation_screen' );
