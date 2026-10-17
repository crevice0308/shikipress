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
    }
    if ($type === 'user' && shiki_option('seo_noindex_authors')) { return false; }
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
add_filter('wpseo_canonical', static function ($url) {
    if (!is_404() && !is_preview() && get_query_var('shiki_stories')) { return shiki_seo_canonical(); }
    if (is_singular() && (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_sitemap') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_html_sitemap'))) { return shiki_seo_canonical(); }
    return $url;
}, 20);
add_filter('wpseo_title', static function ($title) {
    if (!is_404() && get_query_var('shiki_stories')) {
        $page = max(1, (int) get_query_var('paged'));
        return '記事一覧' . ($page > 1 ? ' — ' . $page . 'ページ目' : '') . ' | ' . get_bloginfo('name');
    }
    return $title;
}, 20);

function shiki_xml_sitemap_url() {
    if (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps_Router')) { return WPSEO_Sitemaps_Router::get_base_url('sitemap_index.xml'); }
    return function_exists('get_sitemap_url') ? get_sitemap_url('index') : home_url('/wp-sitemap.xml');
}

// The known page-cache API must also see changes to theme options and season rules.
// It does not purge hosting/CDN caches; those retain their own documented procedure.
add_action('shiki_cache_invalidated', static function () {
    static $running = false;
    if ($running || !did_action('init')) { return; }
    $running = true;
    try {
        if (class_exists('WPSEO_Sitemaps_Cache') && is_callable(array('WPSEO_Sitemaps_Cache', 'clear'))) { WPSEO_Sitemaps_Cache::clear(); }
        if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
    }
    finally { $running = false; }
}, 30);
