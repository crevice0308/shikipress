<?php
/** Optional plugin adapters; no plugin is required for the standalone theme. */
defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', static function () {
    if (defined('WPCF7_VERSION') || class_exists('TablePress') || function_exists('cfturnstile_field_show')) {
        wp_enqueue_style('shiki-plugin-compat', SHIKI_URL . '/assets/plugin-compat.css', array('shiki-style'), SHIKI_VERSION);
    }
    if (function_exists('shiki_captcha_native_turnstile_active') && shiki_captcha_native_turnstile_active()) {
        wp_enqueue_script('shiki-captcha-compat', SHIKI_URL . '/assets/captcha-compat.js', array('cloudflare-turnstile'), SHIKI_VERSION, true);
    }
}, 30);

// Keep the stricter theme publication policy when Yoast owns head metadata.
add_filter('wpseo_robots_array', static function ($robots) {
    if (shiki_seo_noindex()) { $robots['index'] = 'noindex'; }
    if (is_search() || is_404() || is_preview() || (is_singular() && !shiki_seo_is_public_post(get_queried_object_id()))) { $robots['follow'] = 'nofollow'; }
    return $robots;
}, 99);
add_filter('wpseo_sitemap_entry', static function ($entry, $type, $object) {
    if ('1' !== (string) get_option('blog_public')) { return false; }
    if ($type === 'post' && !shiki_seo_is_public_post($object, true)) { return false; }
    if ($type === 'term' && $object instanceof WP_Term) {
        if (get_term_meta($object->term_id, '_shiki_noindex', true) === '1') { return false; }
        if ($object->taxonomy === 'post_tag' && shiki_option('seo_noindex_tags')) { return false; }
        if ($object->taxonomy === 'category' && in_array((int) $object->term_id, shiki_ids(shiki_option('category_exclude', array())), true)) { return false; }
        // Yoast counts protected, excluded and noindex posts as well; a term needs a post the core sitemap would list.
        if (in_array($object->taxonomy, array('category', 'post_tag'), true) && !in_array((int) $object->term_id, shiki_seo_public_term_ids($object->taxonomy), true)) { return false; }
    }
    if ($type === 'user' && shiki_option('seo_noindex_authors')) { return false; }
    if ($type === 'user' && $object instanceof WP_User && !in_array((int) $object->ID, shiki_seo_public_author_ids(), true)) { return false; }
    return $entry;
}, 20, 3);
// Yoast serves XML during pre_get_posts, before template_redirect. Honor the
// same private-site sitemap policy as core, without changing plugin settings.
add_action('pre_get_posts', static function ($query) {
    if (defined('WPSEO_VERSION') && '1' !== (string) get_option('blog_public') && $query->is_main_query() && $query->get('sitemap')) {
        status_header(404);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        exit;
    }
}, 0);
add_filter('wpseo_sitemap_exclude_author', static function ($users) { return shiki_option('seo_noindex_authors') ? array() : $users; });
// A tag sitemap whose every entry is removed would stay listed in Yoast's index.
add_filter('wpseo_sitemap_exclude_taxonomy', static function ($excluded, $taxonomy) { return $excluded || ('post_tag' === $taxonomy && shiki_option('seo_noindex_tags')); }, 20, 2);
// Yoast lists the static front page first in the page sitemap without passing it through wpseo_sitemap_entry.
add_filter('wpseo_sitemap_post_type_first_links', static function ($links, $post_type = '') {
    $front = (int) get_option('page_on_front');
    if ('page' !== $post_type || 'page' !== get_option('show_on_front') || !$front || shiki_seo_is_public_post($front, true) || !is_array($links)) { return $links; }
    $home = untrailingslashit(home_url('/'));
    return array_values(array_filter($links, static function ($link) use ($home) { return !is_array($link) || untrailingslashit((string) ($link['loc'] ?? '')) !== $home; }));
}, 20, 2);
add_filter('wpseo_canonical', static function ($url) {
    if (!is_404() && !is_preview() && get_query_var('shiki_stories')) { return shiki_seo_canonical(); }
    if (is_singular() && (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_sitemap') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_html_sitemap'))) { return shiki_seo_canonical(); }
    return $url;
}, 20);
// Reader-sitemap slices are distinct URLs. Keep Yoast's presentation and schema
// context aligned with the theme canonical instead of changing only the link tag.
add_filter('wpseo_frontend_presentation', static function ($presentation, $context) {
    if (is_404() || is_preview() || is_feed() || !is_singular()) { return $presentation; }
    $content = (string) get_post_field('post_content', get_queried_object_id());
    if (!has_shortcode($content, 'shiki_sitemap') && !has_shortcode($content, 'shiki_html_sitemap')) { return $presentation; }
    if (shiki_html_sitemap_page('post') < 2 && shiki_html_sitemap_page('page') < 2) { return $presentation; }
    $url = shiki_seo_canonical();
    if (!$url) { return $presentation; }
    $presentation->canonical = $url;
    $presentation->open_graph_url = $url;
    $presentation->permalink = $url;
    $context->canonical = $url;
    $context->permalink = $url;
    $context->main_schema_id = $url;
    return $presentation;
}, 20, 2);
add_filter('wpseo_title', static function ($title) {
    if (!is_404() && get_query_var('shiki_stories')) {
        $page = max(1, (int) get_query_var('paged'));
        return '記事一覧' . ($page > 1 ? ' — ' . $page . 'ページ目' : '') . ' | ' . get_bloginfo('name');
    }
    return $title;
}, 20);

/** Yoast's XML sitemaps are a feature of their own; the router class is loaded whether or not it is on. */
function shiki_compat_yoast_sitemaps_enabled() {
    if (class_exists('WPSEO_Options') && is_callable(array('WPSEO_Options', 'get'))) { return (bool) WPSEO_Options::get('enable_xml_sitemap', true); }
    $options = get_option('wpseo', array());
    return !is_array($options) || !array_key_exists('enable_xml_sitemap', $options) || (bool) $options['enable_xml_sitemap'];
}
function shiki_xml_sitemap_url() {
    if (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps_Router') && shiki_compat_yoast_sitemaps_enabled()) { return WPSEO_Sitemaps_Router::get_base_url('sitemap_index.xml'); }
    return function_exists('get_sitemap_url') ? get_sitemap_url('index') : home_url('/wp-sitemap.xml');
}

// The known page-cache API must also see changes to theme options and season rules.
// It does not purge hosting/CDN caches; those retain their own documented procedure.
// One save fires several invalidations (terms, metadata, the post itself); the page caches are
// emptied once, after all of them, at the end of the request.
function shiki_compat_purge_sites($site = null) {
    static $sites = array();
    if (null !== $site) { $sites[(int) $site] = true; return $sites; }
    $pending = $sites; $sites = array();
    return $pending;
}
function shiki_compat_purge_page_caches() {
    if (class_exists('WPSEO_Sitemaps_Cache') && is_callable(array('WPSEO_Sitemaps_Cache', 'clear'))) { WPSEO_Sitemaps_Cache::clear(); }
    if (!function_exists('wp_cache_clear_cache')) { shiki_compat_purge_sites(); return; }
    // On a network, only the sites whose content changed (also inside switch_to_blog()); 0 would empty every site.
    foreach (array_keys(shiki_compat_purge_sites()) as $site) { wp_cache_clear_cache(is_multisite() ? $site : 0); }
}
add_action('shiki_cache_invalidated', static function () {
    if (!did_action('init')) { return; }
    shiki_compat_purge_sites(is_multisite() ? get_current_blog_id() : 0);
    // During shutdown a deferred purge may already have run; purge at once instead.
    if (doing_action('shutdown')) { shiki_compat_purge_page_caches(); return; }
    if (false === has_action('shutdown', 'shiki_compat_purge_page_caches')) { add_action('shutdown', 'shiki_compat_purge_page_caches', 0); }
}, 30);
