<?php
/** Search metadata, reader sitemap and core XML sitemap policy. */
if (!defined('ABSPATH')) { exit; }

add_filter('shiki_defaults', function ($defaults) {
    return array_merge($defaults, [
        'seo_home_title' => '', 'seo_home_description' => '', 'seo_google_verification' => '',
        'seo_noindex_tags' => false, 'seo_noindex_authors' => true, 'seo_noindex_dates' => true,
        'seo_sitemap_per_page' => 50,
    ]);
});
add_filter('shiki_setting_sections', function ($sections) {
    foreach (['seo_home_title','seo_home_description','seo_google_verification','seo_noindex_tags','seo_noindex_authors','seo_noindex_dates','seo_sitemap_per_page'] as $key) { $sections[$key] = 'seo'; }
    return $sections;
});
add_filter('shiki_sanitize_settings', function ($out, $input, $section) {
    if ('seo' !== $section) { return $out; }
    $out['seo_home_title'] = shiki_seo_limit(sanitize_text_field($input['seo_home_title'] ?? ''), 160);
    $out['seo_home_description'] = shiki_seo_limit(sanitize_textarea_field($input['seo_home_description'] ?? ''), 320);
    // Accept the token only, never a pasted HTML element or arbitrary markup.
    $out['seo_google_verification'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['seo_google_verification'] ?? ''));
    $out['seo_google_verification'] = substr($out['seo_google_verification'], 0, 200);
    foreach (['seo_noindex_tags','seo_noindex_authors','seo_noindex_dates'] as $key) { $out[$key] = !empty($input[$key]); }
    $out['seo_sitemap_per_page'] = max(10, min(100, absint($input['seo_sitemap_per_page'] ?? 50)));
    return $out;
}, 10, 3);

function shiki_seo_limit($text, $length) {
    return function_exists('mb_substr') ? mb_substr((string) $text, 0, $length) : substr((string) $text, 0, $length);
}
function shiki_seo_text($text, $length = 160) {
    $text = strip_shortcodes((string) $text);
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return shiki_seo_limit(trim(preg_replace('/\s+/u', ' ', $text)), $length);
}
/** Known SEO plugins own metadata if installed; the default needs no plugin. */
function shiki_seo_managed() {
    $plugin = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');
    return (bool) apply_filters('shiki_seo_managed', !$plugin);
}
function shiki_seo_is_public_post($post, $indexable = false) {
    $post = get_post($post);
    if (!$post || 'publish' !== $post->post_status || '' !== $post->post_password || '1' === (string) get_post_meta($post->ID, '_shiki_exclude', true)) { return false; }
    if ('page' === $post->post_type && in_array((int) $post->ID, array_map('absint', (array) shiki_option('page_exclude', [])), true)) { return false; }
    $hidden_categories = array_map('absint', (array) shiki_option('category_exclude', []));
    if ('post' === $post->post_type && $hidden_categories && has_category($hidden_categories, $post)) { return false; }
    return !$indexable || '1' !== (string) get_post_meta($post->ID, '_shiki_noindex', true);
}
function shiki_seo_noindex() {
    if ('1' !== (string) get_option('blog_public') || is_search() || is_404() || is_preview() || is_attachment()) { return true; }
    if (is_singular() && !shiki_seo_is_public_post(get_queried_object_id(), true)) { return true; }
    if ((is_category() || is_tag() || is_tax()) && '1' === (string) get_term_meta(get_queried_object_id(), '_shiki_noindex', true)) { return true; }
    if (is_category() && in_array((int) get_queried_object_id(), array_map('absint', (array) shiki_option('category_exclude', [])), true)) { return true; }
    return (is_tag() && shiki_option('seo_noindex_tags')) || (is_author() && shiki_option('seo_noindex_authors')) || (is_date() && shiki_option('seo_noindex_dates'));
}
add_filter('wp_robots', function ($robots) {
    if (!shiki_seo_managed()) { return $robots; }
    if (shiki_seo_noindex()) { $robots['noindex'] = true; unset($robots['index']); }
    if (is_search() || is_404() || is_preview() || (is_singular() && !shiki_seo_is_public_post(get_queried_object_id()))) { $robots['nofollow'] = true; unset($robots['follow']); }
    return $robots;
}, 99);

/** Canonical pagination uses core permalink functions, with no tracking parameters. */
function shiki_seo_paged_url($base, $number) {
    $number = max(1, absint($number));
    if ($number < 2) { return $base; }
    global $wp_rewrite;
    if (!get_option('permalink_structure') || str_contains($base, '?')) { return add_query_arg('paged', $number, $base); }
    return trailingslashit($base) . user_trailingslashit(($wp_rewrite->pagination_base ?? 'page') . '/' . $number, 'paged');
}
function shiki_seo_canonical() {
    if (is_404() || is_preview()) { return ''; }
    if (get_query_var('shiki_stories') && function_exists('shiki_stories_url')) { return shiki_stories_url(max(1, (int) get_query_var('paged'))); }
    if (is_singular()) {
        $url = wp_get_canonical_url(get_queried_object_id());
        if (!$url) { return ''; }
        // Each reader-sitemap slice is a distinct page and keeps its own canonical.
        if (has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_sitemap') || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'shiki_html_sitemap')) {
            foreach (['shiki_map_posts','shiki_map_pages'] as $key) { if (isset($_GET[$key]) && absint($_GET[$key]) > 1) { $url = add_query_arg($key, absint($_GET[$key]), $url); } }
        }
        return $url;
    }
    $base = '';
    if (is_front_page()) { $base = home_url('/'); }
    elseif (is_home()) { $id = (int) get_option('page_for_posts'); $base = $id ? get_permalink($id) : home_url('/'); }
    elseif (is_search()) { $base = get_search_link(get_search_query(false)); }
    elseif (is_category() || is_tag() || is_tax()) { $base = get_term_link(get_queried_object()); }
    elseif (is_author()) { $base = get_author_posts_url(get_queried_object_id()); }
    elseif (is_day()) { $base = get_day_link((int) get_query_var('year'), (int) get_query_var('monthnum'), (int) get_query_var('day')); }
    elseif (is_month()) { $base = get_month_link((int) get_query_var('year'), (int) get_query_var('monthnum')); }
    elseif (is_year()) { $base = get_year_link((int) get_query_var('year')); }
    elseif (is_post_type_archive()) { $base = get_post_type_archive_link(get_query_var('post_type')); }
    if (is_wp_error($base) || !$base) { return ''; }
    return shiki_seo_paged_url($base, max(1, (int) get_query_var('paged')));
}
add_filter('pre_get_document_title', function ($title) {
    if (!shiki_seo_managed() || $title || is_404()) { return $title; }
    $custom = '';
    if (get_query_var('shiki_stories')) { $custom = __('記事一覧', 'shikipress'); }
    if (is_singular() && shiki_seo_is_public_post(get_queried_object_id())) { $custom = get_post_meta(get_queried_object_id(), '_shiki_seo_title', true); }
    if (is_category() || is_tag() || is_tax()) { $custom = get_term_meta(get_queried_object_id(), '_shiki_seo_title', true); }
    if (is_front_page() && shiki_option('seo_home_title')) { $custom = shiki_option('seo_home_title'); }
    if (!$custom) { return ''; }
    $page = max((int) get_query_var('paged'), (int) get_query_var('page'), 1);
    return esc_html(shiki_seo_text($custom, 160) . ($page > 1 ? ' — ' . sprintf(__('%dページ目', 'shikipress'), $page) : '') . ' | ' . get_bloginfo('name'));
}, 20);
function shiki_seo_description() {
    if (is_front_page() && shiki_option('seo_home_description')) { return shiki_seo_text(shiki_option('seo_home_description')); }
    if (is_404()) { return __('お探しのページが見つかりません。サイト内検索やホームから記事をご覧ください。', 'shikipress'); }
    if (is_search()) { return sprintf(__('「%s」のサイト内検索結果です。', 'shikipress'), shiki_seo_text(get_search_query(false), 80)); }
    if (is_singular()) {
        $post = get_queried_object();
        if (shiki_seo_is_public_post($post)) {
            $custom = get_post_meta($post->ID, '_shiki_seo_description', true);
            if ($custom) { return shiki_seo_text($custom); }
            $content = $post->post_excerpt ?: $post->post_content;
            // Do not expand dynamic blocks or shortcodes into metadata.
            $content = preg_split('/<!--nextpage-->/', $content);
            $page = max(1, (int) get_query_var('page'));
            $description = shiki_seo_text($content[$page - 1] ?? $content[0]);
            if ($description) { return $description; }
        }
    }
    if (is_category() || is_tag() || is_tax()) {
        $custom = get_term_meta(get_queried_object_id(), '_shiki_seo_description', true);
        $description = $custom ?: term_description(get_queried_object_id());
        if ($description) { return shiki_seo_text($description); }
    }
    if (is_front_page() && shiki_option('seo_home_description')) { return shiki_seo_text(shiki_option('seo_home_description')); }
    return shiki_seo_text(get_bloginfo('description'));
}
/** Individual image > featured image > included 1200x630 seasonal PNG. */
function shiki_seo_image() {
    $id = 0;
    if (is_singular() && shiki_seo_is_public_post(get_queried_object_id())) {
        $id = absint(get_post_meta(get_queried_object_id(), '_shiki_og_image', true));
        if (!$id || !wp_attachment_is_image($id)) { $id = get_post_thumbnail_id(get_queried_object_id()); }
    }
    if ($id) {
        $src = wp_get_attachment_image_src($id, 'full');
        // Widely supported crawler formats; do not claim SVG fallback support.
        if ($src && in_array(get_post_mime_type($id), ['image/jpeg','image/png','image/webp','image/gif'], true)) {
            return ['url'=>$src[0], 'width'=>(int) $src[1], 'height'=>(int) $src[2], 'alt'=>shiki_seo_text(get_post_meta($id, '_wp_attachment_image_alt', true), 160)];
        }
    }
    $season = function_exists('shiki_season') ? shiki_season() : 'autumn';
    if (!in_array($season, ['spring','summer','autumn','winter'], true)) { $season = 'autumn'; }
    return ['url'=>get_theme_file_uri('assets/social/' . $season . '.png'), 'width'=>1200, 'height'=>630, 'alt'=>get_bloginfo('name')];
}
function shiki_seo_head() {
    if (!shiki_seo_managed() || is_feed()) { return; }
    $title = shiki_seo_text(wp_get_document_title(), 250);
    $description = shiki_seo_description();
    $canonical = shiki_seo_canonical();
    $image = shiki_seo_image();
    $article = is_singular('post') && shiki_seo_is_public_post(get_queried_object_id());
    echo "\n<!-- ShikiPress metadata -->\n";
    if ($description) { echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n"; }
    if ($canonical) { echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n"; }
    foreach (['og:locale'=>get_locale(), 'og:type'=>$article ? 'article' : 'website', 'og:site_name'=>get_bloginfo('name'), 'og:title'=>$title, 'og:description'=>$description, 'og:url'=>$canonical, 'og:image'=>$image['url'], 'og:image:width'=>$image['width'], 'og:image:height'=>$image['height'], 'og:image:alt'=>$image['alt']] as $key=>$value) {
        if ('' !== (string) $value) { echo '<meta property="' . esc_attr($key) . '" content="' . esc_attr($value) . '">' . "\n"; }
    }
    foreach (['twitter:card'=>'summary_large_image','twitter:title'=>$title,'twitter:description'=>$description,'twitter:image'=>$image['url'],'twitter:image:alt'=>$image['alt']] as $key=>$value) {
        if ('' !== (string) $value) { echo '<meta name="' . esc_attr($key) . '" content="' . esc_attr($value) . '">' . "\n"; }
    }
    if ($article) {
        echo '<meta property="article:published_time" content="' . esc_attr(get_the_date(DATE_W3C, get_queried_object_id())) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date(DATE_W3C, get_queried_object_id())) . '">' . "\n";
    }
    $token = shiki_option('seo_google_verification');
    if ($token && preg_match('/^[A-Za-z0-9_-]{1,200}$/', $token)) { echo '<meta name="google-site-verification" content="' . esc_attr($token) . '">' . "\n"; }
    $graph = shiki_seo_graph($canonical, $title, $description, $image);
    if ($graph) { echo '<script type="application/ld+json">' . wp_json_encode(['@context'=>'https://schema.org','@graph'=>$graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>' . "\n"; }
}
add_action('after_setup_theme', function () { if (shiki_seo_managed()) { remove_action('wp_head', 'rel_canonical'); } }, 30);
add_action('wp_head', 'shiki_seo_head', 8);

/** URLs/names here are also rendered by shiki_breadcrumbs(). */
function shiki_breadcrumb_items() {
    $items = [['name'=>__('ホーム', 'shikipress'), 'url'=>home_url('/')]];
    if (get_query_var('shiki_stories')) {
        $page = max(1, (int) get_query_var('paged'));
        $items[] = ['name'=>__('記事一覧', 'shikipress') . ($page > 1 ? ' — ' . sprintf(__('%dページ目', 'shikipress'), $page) : ''), 'url'=>shiki_seo_canonical()];
        return $items;
    }
    if (is_front_page()) { return $items; }
    if (is_singular()) {
        $post = get_queried_object();
        if ($post instanceof WP_Post && 'page' === $post->post_type) {
            foreach (array_reverse(get_post_ancestors($post)) as $parent) {
                if (shiki_seo_is_public_post($parent)) { $items[] = ['name'=>get_the_title($parent),'url'=>get_permalink($parent)]; }
            }
        } elseif ($post instanceof WP_Post && 'post' === $post->post_type && shiki_seo_is_public_post($post)) {
            $terms = get_the_category($post->ID);
            if ($terms) {
                usort($terms, function ($a, $b) { return $a->term_id <=> $b->term_id; });
                $term = $terms[0];
                foreach (array_reverse(get_ancestors($term->term_id, 'category', 'taxonomy')) as $parent) {
                    $parent_term = get_term($parent, 'category');
                    if (!$parent_term || is_wp_error($parent_term)) { continue; }
                    $link = get_term_link($parent_term);
                    if (!is_wp_error($link)) { $items[] = ['name'=>$parent_term->name, 'url'=>$link]; }
                }
                $link = get_term_link($term);
                if (!is_wp_error($link)) { $items[] = ['name'=>$term->name, 'url'=>$link]; }
            }
        }
        $items[] = ['name'=>get_the_title(get_queried_object_id()), 'url'=>shiki_seo_canonical()];
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        foreach (array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) as $parent) {
            $p = get_term($parent, $term->taxonomy);
            if (!$p || is_wp_error($p)) { continue; }
            $link = get_term_link($p);
            if (!is_wp_error($link)) { $items[] = ['name'=>$p->name,'url'=>$link]; }
        }
        $items[] = ['name'=>$term->name,'url'=>shiki_seo_canonical()];
    } elseif (is_search()) { $items[] = ['name'=>sprintf(__('「%s」の検索結果', 'shikipress'), get_search_query(false)), 'url'=>shiki_seo_canonical()]; }
    elseif (is_404()) { $items[] = ['name'=>__('ページが見つかりません', 'shikipress'), 'url'=>'']; }
    else { $items[] = ['name'=>is_home() ? __('記事一覧', 'shikipress') : wp_strip_all_tags(get_the_archive_title()), 'url'=>shiki_seo_canonical()]; }
    $page = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
    if ($page > 1) { $items[count($items)-1]['name'] .= ' — ' . sprintf(__('%dページ目', 'shikipress'), $page); }
    return $items;
}
function shiki_breadcrumbs() {
    $items = shiki_breadcrumb_items();
    if (count($items) < 2) { return; }
    echo '<nav class="shiki-breadcrumbs" aria-label="' . esc_attr__('パンくず', 'shikipress') . '"><ol>';
    foreach ($items as $i=>$item) {
        echo '<li>';
        if ($i < count($items)-1 && $item['url']) { echo '<a href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a>'; }
        else { echo '<span aria-current="page">' . esc_html($item['name']) . '</span>'; }
        echo '</li>';
    }
    echo '</ol></nav>';
}
function shiki_seo_graph($url, $title, $description, $image) {
    if (!$url || is_search() || is_404() || is_preview() || (is_singular() && !shiki_seo_is_public_post(get_queried_object_id()))) { return []; }
    $home = home_url('/');
    $graph = [['@type'=>'WebSite','@id'=>$home . '#website','url'=>$home,'name'=>get_bloginfo('name'),'inLanguage'=>get_bloginfo('language')]];
    $page = ['@type'=>(is_archive() || is_home()) ? 'CollectionPage' : 'WebPage','@id'=>$url . '#webpage','url'=>$url,'name'=>$title,'isPartOf'=>['@id'=>$home . '#website'],'inLanguage'=>get_bloginfo('language')];
    if ($description) { $page['description'] = $description; }
    $items = shiki_breadcrumb_items();
    if (count($items) > 1) {
        $crumb = ['@type'=>'BreadcrumbList','@id'=>$url . '#breadcrumb','itemListElement'=>[]];
        foreach ($items as $index=>$item) { if ($item['url']) { $crumb['itemListElement'][] = ['@type'=>'ListItem','position'=>$index+1,'name'=>shiki_seo_text($item['name'], 250),'item'=>$item['url']]; } }
        $graph[] = $crumb; $page['breadcrumb'] = ['@id'=>$url . '#breadcrumb'];
    }
    $graph[] = $page;
    if (is_singular('post')) {
        $post = get_queried_object();
        // The author is added only when templates actually display the same attribution.
        $article = ['@type'=>'BlogPosting','@id'=>$url . '#article','mainEntityOfPage'=>['@id'=>$url . '#webpage'],'headline'=>shiki_seo_text(get_the_title($post), 250),'datePublished'=>get_the_date(DATE_W3C, $post),'dateModified'=>get_the_modified_date(DATE_W3C, $post),'inLanguage'=>get_bloginfo('language')];
        $thumbnail = get_the_post_thumbnail_url($post, 'full');
        if ($thumbnail) { $article['image'] = [$thumbnail]; }
        if (apply_filters('shiki_seo_show_author', false, $post)) { $name = get_the_author_meta('display_name', $post->post_author); if ($name) { $article['author'] = ['@type'=>'Person','name'=>$name]; } }
        $graph[] = $article;
    }
    return $graph;
}

/** Same exclusion predicate is composed into core XML and reader-map queries. */
function shiki_seo_map_args($args, $post_type = 'post') {
    $args['post_status'] = 'publish'; $args['has_password'] = false;
    $meta = ['relation'=>'AND'];
    if (!empty($args['meta_query'])) { $meta[] = $args['meta_query']; }
    foreach (['_shiki_exclude','_shiki_noindex'] as $key) { $meta[] = ['relation'=>'OR', ['key'=>$key,'compare'=>'NOT EXISTS'], ['key'=>$key,'value'=>'1','compare'=>'!=']]; }
    $args['meta_query'] = $meta;
    if ('post' === $post_type) { $args['category__not_in'] = array_values(array_unique(array_merge((array) ($args['category__not_in'] ?? []), array_map('absint', (array) shiki_option('category_exclude', []))))); }
    if ('page' === $post_type) { $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), array_map('absint', (array) shiki_option('page_exclude', []))))); }
    return $args;
}
add_filter('wp_sitemaps_posts_query_args', 'shiki_seo_map_args', 20, 2);
add_filter('wp_sitemaps_enabled', function ($enabled) { return $enabled && '1' === (string) get_option('blog_public'); });
// Keep public post types registered by plugins in the native core sitemap.
add_filter('wp_sitemaps_taxonomies', function ($types) { if (shiki_option('seo_noindex_tags')) { unset($types['post_tag']); } return $types; });
add_filter('wp_sitemaps_add_provider', function ($provider, $name) { return 'users' === $name && shiki_option('seo_noindex_authors') ? false : $provider; }, 20, 2);
// Exclude author sitemap regardless when private; public-author query excludes protected and noindex posts.
add_filter('wp_sitemaps_users_query_args', function ($args) {
    $args['has_published_posts'] = ['post'];
    $args['include'] = shiki_seo_public_author_ids() ?: [0];
    return $args;
});
function shiki_seo_public_author_ids() {
    global $wpdb;
    $ids = shiki_seo_indexable_post_ids();
    if (!$ids) { return []; }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE ID IN ($placeholders)", ...$ids)));
}
/** One indexed, request-cached query supports both maps, never protected titles. */
function shiki_seo_indexable_post_ids() {
    static $cache = [];
    $signature = (string) get_option('shikipress_cache_epoch', 1) . ':' . md5(wp_json_encode(shiki_option('category_exclude', [])));
    if (!isset($cache[$signature])) {
        $query = new WP_Query(shiki_seo_map_args(['post_type'=>'post','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,'orderby'=>'ID','order'=>'ASC'], 'post'));
        $cache[$signature] = array_map('absint', $query->posts);
    }
    return $cache[$signature];
}
function shiki_seo_public_term_ids($taxonomy) {
    $ids = shiki_seo_indexable_post_ids();
    if (!$ids) { return []; }
    $terms = wp_get_object_terms($ids, $taxonomy, ['fields'=>'ids']);
    if (is_wp_error($terms)) { return []; }
    $hidden = 'category' === $taxonomy ? array_map('absint', (array) shiki_option('category_exclude', [])) : [];
    return array_values(array_filter(array_map('absint', $terms), function ($id) use ($hidden) { return !in_array($id, $hidden, true) && '1' !== (string) get_term_meta($id, '_shiki_noindex', true); }));
}
add_filter('wp_sitemaps_taxonomies_query_args', function ($args, $taxonomy) { if (in_array($taxonomy, ['category', 'post_tag'], true)) { $args['include'] = shiki_seo_public_term_ids($taxonomy) ?: [0]; } return $args; }, 20, 2);

function shiki_html_sitemap($attributes = []) {
    $per_page = max(10, min(100, absint(shiki_option('seo_sitemap_per_page', 50))));
    $html = '<div class="shiki-html-sitemap">';
    $terms = function_exists('shiki_season_terms') ? shiki_season_terms() : [];
    $public_terms = shiki_seo_public_term_ids('category');
    $season_links = '';
    foreach (['spring','summer','autumn','winter'] as $season) {
        $id = absint($terms[$season] ?? 0);
        if (!$id || !in_array($id, $public_terms, true)) { continue; }
        $term = get_term($id, 'category'); $link = get_term_link($id, 'category');
        if (!is_wp_error($term) && !is_wp_error($link)) { $season_links .= '<li><a href="' . esc_url($link) . '">' . esc_html($term->name) . '</a></li>'; }
    }
    if ($season_links) { $html .= '<section aria-label="' . esc_attr__('四季カテゴリー', 'shikipress') . '"><h2>' . esc_html__('四季カテゴリー', 'shikipress') . '</h2><ul>' . $season_links . '</ul></section>'; }
    foreach (['post'=>['shiki_map_posts',__('記事', 'shikipress')], 'page'=>['shiki_map_pages',__('固定ページ', 'shikipress')]] as $type=>$definition) {
        [$key, $label] = $definition;
        $requested = isset($_GET[$key]) ? max(1, min(100000, absint($_GET[$key]))) : 1;
        $args = shiki_seo_map_args(['post_type'=>$type,'posts_per_page'=>$per_page,'paged'=>$requested,'orderby'=>$type === 'page' ? ['menu_order'=>'ASC','ID'=>'ASC'] : ['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true], $type);
        $query = new WP_Query($args);
        if (!$query->posts && 0 === (int) $query->found_posts) { continue; }
        $html .= '<section aria-label="' . esc_attr($label) . '"><h2>' . esc_html($label) . '</h2><ul>';
        foreach ($query->posts as $post) { $html .= '<li><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></li>'; }
        $html .= '</ul>';
        if ($query->max_num_pages > 1) {
            $base = get_permalink(get_queried_object_id()) ?: home_url('/');
            $other = 'post' === $type ? 'shiki_map_pages' : 'shiki_map_posts';
            if (isset($_GET[$other]) && absint($_GET[$other]) > 1) { $base = add_query_arg($other, absint($_GET[$other]), $base); }
            $base = add_query_arg($key, '%#%', $base);
            $links = paginate_links(['base'=>str_replace('%25%23%25','%#%', esc_url_raw($base)), 'format'=>'','current'=>$requested,'total'=>$query->max_num_pages,'type'=>'list','prev_text'=>__('前へ', 'shikipress'),'next_text'=>__('次へ', 'shikipress')]);
            $html .= '<nav aria-label="' . esc_attr($label . __('のページ', 'shikipress')) . '">' . $links . '</nav>';
        }
        $html .= '</section>';
    }
    return $html . '</div>';
}
add_shortcode('shiki_sitemap', 'shiki_html_sitemap');
add_shortcode('shiki_html_sitemap', 'shiki_html_sitemap');

add_action('add_meta_boxes', function () { foreach (['post','page'] as $type) { add_meta_box('shiki-seo', __('ShikiPress SEO・SNS', 'shikipress'), 'shiki_seo_metabox', $type, 'normal', 'default'); } });
function shiki_seo_metabox($post) {
    wp_nonce_field('shiki_save_seo', 'shiki_seo_nonce');
    foreach (['_shiki_seo_title'=>__('SEOタイトル（空欄で記事タイトル）', 'shikipress'), '_shiki_seo_description'=>__('メタディスクリプション（空欄で抜粋・本文）', 'shikipress'), '_shiki_og_image'=>__('共有画像のメディアID（空欄でアイキャッチ・季節画像）', 'shikipress')] as $key=>$label) {
        echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label><br><input class="widefat" type="' . ('_shiki_og_image' === $key ? 'number' : 'text') . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr(get_post_meta($post->ID, $key, true)) . '"></p>';
    }
    echo '<p><label><input type="checkbox" name="_shiki_noindex" value="1" ' . checked('1', get_post_meta($post->ID, '_shiki_noindex', true), false) . '> ' . esc_html__('検索への掲載を抑止（noindex・サイトマップから除外）', 'shikipress') . '</label></p>';
    echo '<p class="description">' . esc_html__('noindexはアクセス制限ではありません。非公開情報にはWordPressの非公開設定とサーバーのアクセス制限を使用してください。', 'shikipress') . '</p>';
}
add_action('save_post', function ($id, $post) {
    if (!in_array($post->post_type, ['post','page'], true) || !isset($_POST['shiki_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shiki_seo_nonce'])), 'shiki_save_seo') || !current_user_can('edit_post', $id) || wp_is_post_revision($id) || wp_is_post_autosave($id)) { return; }
    foreach (['_shiki_seo_title'=>160,'_shiki_seo_description'=>320] as $key=>$length) { update_post_meta($id, $key, shiki_seo_limit(sanitize_text_field(wp_unslash($_POST[$key] ?? '')), $length)); }
    $image = absint($_POST['_shiki_og_image'] ?? 0);
    update_post_meta($id, '_shiki_og_image', $image && wp_attachment_is_image($image) && current_user_can('edit_post', $image) ? $image : 0);
    update_post_meta($id, '_shiki_noindex', empty($_POST['_shiki_noindex']) ? '0' : '1');
    if (function_exists('shiki_invalidate_cache')) { shiki_invalidate_cache(); }
}, 20, 2);
function shiki_seo_term_fields($term = null) {
    $id = $term instanceof WP_Term ? $term->term_id : 0;
    $editing = $id > 0;
    wp_nonce_field('shiki_term_seo', 'shiki_term_seo_nonce');
    foreach (['_shiki_seo_title'=>__('SEOタイトル', 'shikipress'), '_shiki_seo_description'=>__('メタディスクリプション', 'shikipress')] as $key=>$label) {
        if ($editing) { echo '<tr class="form-field"><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'; }
        else { echo '<div class="form-field"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>'; }
        echo '<input type="text" name="' . esc_attr($key) . '" id="' . esc_attr($key) . '" value="' . esc_attr($id ? get_term_meta($id, $key, true) : '') . '">';
        echo $editing ? '</td></tr>' : '</div>';
    }
    echo $editing ? '<tr class="form-field"><th>ShikiPress</th><td>' : '<div class="form-field">';
    echo '<label><input type="checkbox" name="_shiki_noindex" value="1" ' . checked('1', $id ? get_term_meta($id, '_shiki_noindex', true) : '', false) . '> ' . esc_html__('このアーカイブをnoindex・サイトマップから除外', 'shikipress') . '</label>';
    echo $editing ? '</td></tr>' : '</div>';
}
function shiki_seo_save_term($id) {
    if (!current_user_can('manage_categories') || !isset($_POST['shiki_term_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shiki_term_seo_nonce'])), 'shiki_term_seo')) { return; }
    foreach (['_shiki_seo_title'=>160,'_shiki_seo_description'=>320] as $key=>$length) { update_term_meta($id, $key, shiki_seo_limit(sanitize_text_field(wp_unslash($_POST[$key] ?? '')), $length)); }
    update_term_meta($id, '_shiki_noindex', empty($_POST['_shiki_noindex']) ? '0' : '1');
    if (function_exists('shiki_invalidate_cache')) { shiki_invalidate_cache(); }
}
foreach (['category','post_tag'] as $taxonomy) {
    add_action($taxonomy . '_add_form_fields', 'shiki_seo_term_fields');
    add_action($taxonomy . '_edit_form_fields', 'shiki_seo_term_fields');
    add_action('created_' . $taxonomy, 'shiki_seo_save_term');
    add_action('edited_' . $taxonomy, 'shiki_seo_save_term');
}
add_action('shiki_admin_tab_seo', function () {
    if (!current_user_can('manage_options')) { return; }
    echo '<h2>' . esc_html__('SEO・サイトマップ', 'shikipress') . '</h2>';
    echo '<p>' . esc_html__('SEOタイトル・説明・noindexは投稿／固定ページ／カテゴリー／タグの編集画面でも設定できます。', 'shikipress') . '</p>';
    if (!shiki_seo_managed()) { echo '<div class="notice notice-info inline"><p>' . esc_html__('SEOプラグインを検出したため、テーマのheadメタ情報出力を停止しています。noindexは利用中プラグイン側でも確認してください。', 'shikipress') . '</p></div>'; }
    shiki_settings_form_start('seo');
    echo '<table class="form-table"><tbody>';
    foreach (['seo_home_title'=>__('ホームのSEOタイトル', 'shikipress'),'seo_home_description'=>__('ホームの説明', 'shikipress'),'seo_google_verification'=>__('Google所有権確認トークン', 'shikipress')] as $key=>$label) {
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" type="text" id="' . esc_attr($key) . '" name="shikipress_settings[' . esc_attr($key) . ']" value="' . esc_attr(shiki_option($key)) . '">';
        if ('seo_google_verification' === $key) { echo '<p class="description">' . esc_html__('content属性内のトークンだけを入力。プロパティ作成・登録・送信は行いません。', 'shikipress') . '</p>'; }
        echo '</td></tr>';
    }
    echo '</tbody></table><details><summary>' . esc_html__('詳細設定', 'shikipress') . '</summary><p>';
    foreach (['seo_noindex_tags'=>__('タグをnoindex', 'shikipress'),'seo_noindex_authors'=>__('著者アーカイブをnoindex', 'shikipress'),'seo_noindex_dates'=>__('日付アーカイブをnoindex', 'shikipress')] as $key=>$label) { echo '<label><input type="checkbox" name="shikipress_settings[' . esc_attr($key) . ']" value="1" ' . checked(true, shiki_option($key), false) . '> ' . esc_html($label) . '</label><br>'; }
    echo '</p><label>' . esc_html__('HTMLサイトマップの1ページ件数（10〜100）', 'shikipress') . ' <input type="number" min="10" max="100" name="shikipress_settings[seo_sitemap_per_page]" value="' . esc_attr(shiki_option('seo_sitemap_per_page')) . '"></label></details>';
    shiki_settings_form_end();
    $url = function_exists('shiki_xml_sitemap_url') ? shiki_xml_sitemap_url() : get_sitemap_url('index');
    echo '<h3>' . esc_html__('XMLサイトマップ（利用中の生成元）', 'shikipress') . '</h3><p><input class="large-text code" readonly aria-label="' . esc_attr__('XMLサイトマップURL・選択してコピー', 'shikipress') . '" value="' . esc_attr($url) . '"></p>';
    echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('サイトマップを確認', 'shikipress') . '</a></p>';
    if ('1' !== (string) get_option('blog_public')) { echo '<p>' . esc_html__('現在はWordPressの「検索エンジンがサイトをインデックスしない」設定が有効なためXML配信は停止しています。これはアクセス制限ではありません。', 'shikipress') . '</p>'; }
    echo '<p>' . esc_html__('読者向けHTMLサイトマップは固定ページに [shiki_sitemap] を置いて利用します。記事と固定ページは別々にページ分割し、保護・非公開・除外・noindexを掲載しません。', 'shikipress') . '</p>';
});
