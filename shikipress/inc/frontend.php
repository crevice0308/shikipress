<?php
/** Presentation and accessible reading interface. */
if (!defined('ABSPATH')) { exit; }

add_filter('shiki_defaults', function ($defaults) {
    return array_merge($defaults, [
        'hero_kicker' => '季節を感じて、暮らしを楽しむ。',
        'hero_title' => "季節と暮らす、\nささやかな喜び。",
        'hero_description' => '移りゆく四季に、小さな発見を。日々を心地よくする暮らしのヒントをお届けします。',
        'toc_enabled' => 1,
        'show_popular' => 0,
    ]);
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('shiki-style', get_theme_file_uri('/assets/style.css'), [], SHIKI_VERSION);
    wp_enqueue_script('shiki-frontend', get_theme_file_uri('/assets/frontend.js'), [], SHIKI_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    if (is_singular() && comments_open() && get_option('thread_comments')) { wp_enqueue_script('comment-reply'); }
});
add_action('wp_head', function () {
    // This stores only a reader's display preference, never an identifier.
    echo '<script id="shiki-display-init">try{var t=localStorage.getItem("shiki-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;}catch(e){}</script>' . "\n";
}, 1);
add_filter('body_class', function ($classes) {
    $context = shiki_frontend_context();
    $classes[] = 'season-' . sanitize_html_class($context['season']);
    if (!empty($context['event'])) { $classes[] = 'has-season-event'; }
    return $classes;
});

function shiki_frontend_context() {
    $context = function_exists('shiki_season_context') ? shiki_season_context() : ['season' => 'autumn', 'label' => '秋', 'event' => []];
    $labels = ['spring' => '春', 'summer' => '夏', 'autumn' => '秋', 'winter' => '冬'];
    if (!isset($labels[$context['season'] ?? ''])) { $context['season'] = 'autumn'; }
    $context['label'] = $labels[$context['season']];
    return $context;
}

function shiki_icon($name) {
    $paths = [
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'pages' => '<path d="M6 3h9l5 5v13H6Z"/><path d="M14 3v6h6M3 7v14M10 13h6M10 17h6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'close' => '<path d="m5 5 14 14M19 5 5 19"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 1v2m0 18v2M1 12h2m18 0h2M4 4l1.5 1.5m13 13L20 20M4 20l1.5-1.5m13-13L20 4"/>',
        'leaf' => '<path d="M20 3c1 11-4 18-12 16C0 13 8 4 20 3Z"/><path d="M5 22 17 7"/>',
        'up' => '<path d="m6 14 6-6 6 6"/>',
    ];
    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['leaf']) . '</svg>';
}

function shiki_visible_categories() {
    $key = 'shiki_visible_terms';
    $signature = (int) get_option('shikipress_cache_epoch', 1) . ':' . md5(wp_json_encode(shiki_option('category_exclude', [])));
    $cached = get_transient($key);
    if (is_array($cached) && ($cached['signature'] ?? '') === $signature) { return $cached['rows']; }
    $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => true, 'orderby' => 'name']);
    $visible = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, array_map('absint', (array) shiki_option('category_exclude', [])), true)) { continue; }
            $query = shiki_public_posts(['posts_per_page' => 1, 'fields' => 'ids', 'cat' => $term->term_id, 'no_found_rows' => false]);
            if ($query->found_posts > 0) { $visible[] = ['term' => $term, 'count' => (int) $query->found_posts]; }
        }
    }
    set_transient($key, ['signature' => $signature, 'rows' => $visible], 12 * HOUR_IN_SECONDS);
    return $visible;
}

function shiki_post_season($post_id) {
    if (function_exists('shiki_season_terms')) {
        $ids = wp_get_post_categories($post_id);
        foreach (shiki_season_terms() as $season => $term_id) {
            if (in_array((int) $term_id, array_map('intval', $ids), true)) { return $season; }
        }
    }
    return shiki_frontend_context()['season'];
}

/** Match the card grid so browsers can select an appropriately sized local image. */
function shiki_card_image_sizes(int $columns = 3): string {
    if (1 === $columns) {
        return '(max-width: 360px) calc(100vw - 32px), (max-width: 767px) calc(100vw - 40px), 580px';
    }
    $mobile = '(max-width: 360px) calc((100vw - 44px) / 2), (max-width: 767px) calc((100vw - 55px) / 2), (max-width: 900px) calc((100vw - 96px) / 2), ';
    if (2 === $columns) {
        return $mobile . '(max-width: 1100px) calc((100vw - 96px) / 2), (max-width: 1352px) calc((100vw - 142px) / 2), 605px';
    }
    return $mobile . '(max-width: 1100px) calc((100vw - 120px) / 3), (max-width: 1352px) calc((100vw - 172px) / 3), 394px';
}

function shiki_card($item, $heading_level = 3, $columns = 3) {
    $item = get_post($item);
    if (!$item || $item->post_status !== 'publish' || $item->post_password !== '' || get_post_meta($item->ID, '_shiki_exclude', true)) { return; }
    if (function_exists('shiki_seo_is_public_post') && !shiki_seo_is_public_post($item)) { return; }
    $season = shiki_post_season($item->ID);
    $categories = get_the_category($item->ID);
    $title = get_the_title($item);
    $title = $title !== '' ? $title : '無題の記事';
    $heading_tag = $heading_level === 2 ? 'h2' : 'h3';
    ?>
    <article class="post-card season-<?php echo esc_attr($season); ?>">
        <a class="card-image" href="<?php echo esc_url(get_permalink($item)); ?>" tabindex="-1" aria-hidden="true">
            <?php if (has_post_thumbnail($item)) {
                echo get_the_post_thumbnail($item, 'shiki-card', ['loading' => 'lazy', 'decoding' => 'async', 'sizes' => shiki_card_image_sizes((int) $columns), 'alt' => '']);
            } else { ?>
                <img src="<?php echo esc_url(get_theme_file_uri('/assets/season-' . $season . '.svg')); ?>" alt="" width="760" height="520" loading="lazy" decoding="async">
            <?php } ?>
            <span class="card-image-arrow"><?php echo shiki_icon('arrow'); ?></span>
        </a>
        <div class="card-content">
            <div class="card-meta"><?php if ($categories) { ?><span class="category-label"><?php echo esc_html($categories[0]->name); ?></span><span aria-hidden="true">·</span><?php } ?><time datetime="<?php echo esc_attr(get_the_date('c', $item)); ?>"><?php echo esc_html(get_the_date('Y.m.d', $item)); ?></time></div>
            <?php echo '<' . $heading_tag . ' class="card-title"><a href="' . esc_url(get_permalink($item)) . '">' . esc_html($title) . '</a></' . $heading_tag . '>'; ?>
            <p class="card-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($item->post_excerpt ?: strip_shortcodes($item->post_content)), 46, '…')); ?></p>
        </div>
    </article>
    <?php
}

function shiki_render_cards($posts) {
    if (!$posts) { return; }
    echo '<div class="post-grid count-' . esc_attr(min(3, count($posts))) . '">';
    foreach ($posts as $item) { shiki_card($item, 3, min(3, count($posts))); }
    echo '</div>';
}

function shiki_section_title($eyebrow, $title, $description = '') {
    echo '<div class="section-heading"><div><p class="eyebrow">' . esc_html($eyebrow) . '</p><h2>' . esc_html($title) . '</h2></div>';
    if ($description) { echo '<p class="section-description">' . esc_html($description) . '</p>'; }
    echo '</div>';
}

function shiki_season_links($layout = 'tiles') {
    $map = function_exists('shiki_season_terms') ? shiki_season_terms() : [];
    $seasons = ['spring' => ['春', 'SPRING', '芽吹きの季節を、軽やかに。'], 'summer' => ['夏', 'SUMMER', '涼やかな日々の工夫。'], 'autumn' => ['秋', 'AUTUMN', '深まる季節を、心豊かに。'], 'winter' => ['冬', 'WINTER', 'あたたかな時間を紡ぐ。']];
    $visible = shiki_visible_categories();
    $rows = [];
    foreach ($visible as $row) { $rows[(int) $row['term']->term_id] = $row; }
    $has_any = false;
    foreach ($map as $term_id) { if (isset($rows[(int) $term_id])) { $has_any = true; break; } }
    if (!$has_any) { return; }
    echo '<div class="season-links ' . esc_attr($layout) . '">';
    foreach ($seasons as $season => $copy) {
        $row = $rows[(int) ($map[$season] ?? 0)] ?? null;
        if (!$row) { continue; }
        $url = get_term_link($row['term']);
        if (is_wp_error($url)) { continue; }
        echo '<a class="season-tile season-' . esc_attr($season) . '" href="' . esc_url($url) . '"><span class="season-kanji" aria-hidden="true">' . esc_html($copy[0]) . '</span><span><span class="eyebrow">' . esc_html($copy[1]) . '</span><strong>' . esc_html($row['term']->name) . '</strong><span class="season-description">' . esc_html($copy[2]) . '</span></span><span class="season-count">' . esc_html($row['count']) . ' articles ' . shiki_icon('arrow') . '</span></a>';
    }
    echo '</div>';
}

function shiki_page_links($class = '') {
    $pages = shiki_public_pages();
    if (!$pages) { echo '<p class="muted">公開された固定ページはまだありません。</p>'; return; }
    $list = function ($slice) use ($class) {
        echo '<ul class="page-links ' . esc_attr($class) . '">';
        foreach ($slice as $page) { echo '<li><a href="' . esc_url(get_permalink($page)) . '">' . esc_html(get_the_title($page) ?: '無題のページ') . shiki_icon('arrow') . '</a></li>'; }
        echo '</ul>';
    };
    echo '<div class="page-link-groups">';
    $list(array_slice($pages, 0, 10));
    if (count($pages) > 10) {
        echo '<details class="additional-pages"><summary>その他の固定ページ（' . esc_html(number_format_i18n(count($pages) - 10)) . '件）</summary>';
        $list(array_slice($pages, 10));
        echo '</details>';
    }
    echo '</div>';
}

/** Build a TOC and stable unique anchors, retaining existing unique author IDs. */
function shiki_toc($content) {
    if (!shiki_option('toc_enabled', 1)) { return $content; }
    $counts = [];
    $tags = new WP_HTML_Tag_Processor($content);
    while ($tags->next_tag()) {
        $id = $tags->get_attribute('id');
        if (is_string($id)) { $counts[$id] = ($counts[$id] ?? 0) + 1; }
    }
    $used = array_fill_keys(array_keys($counts), true);
    $headings = [];
    $index = 0;
    $active = null;
    $template_depth = 0;
    $processor = new WP_HTML_Tag_Processor($content);
    // Tokenize actual HTML; headings embedded in JSON/script/style are raw text,
    // and inert template headings are not part of the reader's document.
    while ($processor->next_token()) {
        $type = $processor->get_token_type();
        $tag = $processor->get_tag();
        if ($type === '#tag' && $tag === 'TEMPLATE') {
            $template_depth = max(0, $template_depth + ($processor->is_tag_closer() ? -1 : 1));
            continue;
        }
        if ($template_depth > 0) { continue; }
        if ($type === '#tag' && in_array($tag, ['H2', 'H3'], true)) {
            if ($processor->is_tag_closer()) {
                if ($active !== null && $active['level'] === (int) substr($tag, 1)) {
                    $active['text'] = trim(preg_replace('/\s+/u', ' ', $active['text']));
                    if ($active['text'] !== '') { $headings[] = $active; }
                    $active = null;
                }
                continue;
            }
            $index++;
            $existing = $processor->get_attribute('id');
            $id = is_string($existing) ? $existing : '';
            if ($id === '' || ($counts[$id] ?? 0) > 1) {
                $id = 'shiki-heading-' . $index;
                while (isset($used[$id])) { $id .= '-x'; }
                $processor->set_attribute('id', $id);
                $used[$id] = true;
            }
            $active = ['level' => (int) substr($tag, 1), 'id' => $id, 'text' => ''];
        } elseif ($active !== null && $type === '#text') {
            $active['text'] .= $processor->get_modifiable_text();
        } elseif ($active !== null && $type === '#tag' && $tag === 'BR') {
            $active['text'] .= ' ';
        }
    }
    $content = $processor->get_updated_html();
    if (count($headings) < 2) { return $content; }
    $toc = '<details class="article-toc" open><summary><span>この記事の目次</span><span class="toc-toggle">開く・閉じる</span></summary><ol>';
    foreach ($headings as $heading) { $toc .= '<li class="toc-level-' . $heading['level'] . '"><a href="#' . esc_attr($heading['id']) . '">' . esc_html($heading['text']) . '</a></li>'; }
    return $toc . '</ol></details>' . $content;
}
add_filter('the_content', function ($content) {
    if (!is_admin() && is_singular() && in_the_loop() && is_main_query() && !post_password_required()) { return shiki_toc($content); }
    return $content;
}, 12);

add_filter('wp_nav_menu_objects', function ($items) {
    $public_page_ids = array_map(function ($page) { return $page->ID; }, shiki_public_pages());
    $visible_category_ids = array_map(function ($row) { return (int) $row['term']->term_id; }, shiki_visible_categories());
    $public_post = function ($id) use ($public_page_ids) {
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== '' || (string) get_post_meta($post->ID, '_shiki_exclude', true) === '1') { return false; }
        if ($post->post_type === 'page') { return in_array((int) $post->ID, $public_page_ids, true); }
        $excluded = array_map('absint', (array) shiki_option('category_exclude', []));
        return $post->post_type !== 'post' || !$excluded || !has_category($excluded, $post);
    };
    return array_filter($items, function ($item) use ($visible_category_ids, $public_post) {
        if ($item->object === 'page' || $item->object === 'post') { return $public_post($item->object_id); }
        if ($item->object === 'category') { return in_array((int) $item->object_id, $visible_category_ids, true); }
        if ($item->type === 'custom') {
            $target_host = wp_parse_url($item->url, PHP_URL_HOST);
            $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if (!$target_host || $target_host === $site_host) {
                $url = !$target_host && !str_starts_with($item->url, '#') ? home_url('/' . ltrim($item->url, '/')) : $item->url;
                $post_id = url_to_postid($url);
                if ($post_id && !$public_post($post_id)) { return false; }
                $query_args = [];
                parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query_args);
                if (isset($query_args['cat']) && !in_array(absint($query_args['cat']), $visible_category_ids, true)) { return false; }
                $all_terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
                if (!is_wp_error($all_terms)) { foreach ($all_terms as $term) {
                    $term_url = get_term_link($term);
                    if (!is_wp_error($term_url) && untrailingslashit(preg_replace('/[?#].*$/', '', $url)) === untrailingslashit($term_url) && !in_array((int) $term->term_id, $visible_category_ids, true)) { return false; }
                } }
            }
        }
        return true;
    });
});

function shiki_design_admin() {
    $context = shiki_frontend_context();
    shiki_settings_form_start('design');
    ?><h2>基本設定</h2><p>ロゴは <a href="<?php echo esc_url(admin_url('customize.php?autofocus[control]=custom_logo')); ?>">カスタマイザーのサイト基本情報</a> から変更できます。画像未設定時はサイト名と四季のオリジナルイラストを表示します。</p>
    <table class="form-table" role="presentation">
        <tr><th scope="row"><label for="shiki-season-mode">季節の切り替え</label></th><td><select id="shiki-season-mode" name="shikipress_settings[season_mode]"><option value="auto" <?php selected(shiki_option('season_mode'), 'auto'); ?>>自動</option><option value="fixed" <?php selected(shiki_option('season_mode'), 'fixed'); ?>>手動固定</option></select></td></tr>
        <tr><th scope="row"><label for="shiki-season-fixed">固定する季節</label></th><td><select id="shiki-season-fixed" name="shikipress_settings[season_fixed]"><?php foreach (['spring' => '春', 'summer' => '夏', 'autumn' => '秋', 'winter' => '冬'] as $value => $label) { ?><option value="<?php echo esc_attr($value); ?>" <?php selected(shiki_option('season_fixed'), $value); ?>><?php echo esc_html($label); ?></option><?php } ?></select></td></tr>
        <tr><th scope="row"><label for="shiki-timezone">季節の判定基準</label></th><td><input id="shiki-timezone" name="shikipress_settings[timezone]" value="<?php echo esc_attr(shiki_option('timezone', 'Asia/Tokyo')); ?>" class="regular-text"><p class="description">例: Asia/Tokyo。WordPress全体のタイムゾーンは変更しません。現在の判定: <?php echo esc_html($context['label']); ?></p></td></tr>
        <tr><th scope="row"><label for="shiki-hero-kicker">メインビジュアルの小見出し</label></th><td><input class="large-text" id="shiki-hero-kicker" name="shikipress_settings[hero_kicker]" value="<?php echo esc_attr(shiki_option('hero_kicker')); ?>"></td></tr>
        <tr><th scope="row"><label for="shiki-hero-title">メインタイトル</label></th><td><textarea class="large-text" rows="2" id="shiki-hero-title" name="shikipress_settings[hero_title]"><?php echo esc_textarea(shiki_option('hero_title')); ?></textarea></td></tr>
        <tr><th scope="row"><label for="shiki-hero-description">紹介文</label></th><td><textarea class="large-text" rows="3" id="shiki-hero-description" name="shikipress_settings[hero_description]"><?php echo esc_textarea(shiki_option('hero_description')); ?></textarea></td></tr>
    </table>
    <details><summary>詳細設定</summary><p><label><input type="checkbox" name="shikipress_settings[toc_enabled]" value="1" <?php checked(shiki_option('toc_enabled'), 1); ?>> H2・H3から目次を自動生成する</label></p><p><label><input type="checkbox" name="shikipress_settings[show_popular]" value="1" <?php checked(shiki_option('show_popular'), 1); ?>> 実測のある人気記事をトップに表示する</label></p><p>人気記事の集計は「記事自動化」で別に有効化します。記録のない記事は表示しません。イベント日付ルールも「記事自動化」で設定できます。</p></details>
    <?php shiki_settings_form_end(); ?>
    <h2>管理者だけの季節プレビュー</h2><p>公開設定を保存せず表示を確認できます。プレビューは認証済み管理者と有効なnonceに限定され、キャッシュを抑止します。</p><p><?php foreach (['spring' => '春', 'summer' => '夏', 'autumn' => '秋', 'winter' => '冬'] as $season => $label) { $url = wp_nonce_url(add_query_arg('shiki_preview_season', $season, home_url('/')), 'shiki_season_preview'); echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">' . esc_html($label) . 'を確認</a> '; } ?></p><?php
}
add_action('shiki_admin_tab_design', 'shiki_design_admin');
add_filter('shiki_sanitize_settings', function ($out, $input, $section) {
    if ($section !== 'design') { return $out; }
    foreach (['hero_kicker', 'hero_title', 'hero_description'] as $key) { if (isset($input[$key])) { $out[$key] = sanitize_textarea_field($input[$key]); } }
    $out['toc_enabled'] = empty($input['toc_enabled']) ? 0 : 1;
    $out['show_popular'] = empty($input['show_popular']) ? 0 : 1;
    return $out;
}, 10, 3);

add_filter('shiki_setting_sections', function ($sections) {
    foreach (['hero_kicker', 'hero_title', 'hero_description', 'toc_enabled', 'show_popular'] as $key) { $sections[$key] = 'design'; }
    return $sections;
});

/** All posts remain reachable even when the front page is the automatic journal. */
function shiki_stories_url($page = 1) {
    $posts_page = (int) get_option('page_for_posts');
    $base = home_url('/');
    $valid_page = $posts_page && get_option('show_on_front') === 'page' && in_array($posts_page, array_map(function ($item) { return $item->ID; }, shiki_public_pages()), true);
    $base = $valid_page ? get_permalink($posts_page) : add_query_arg('shiki_stories', 1, $base);
    return $page > 1 ? add_query_arg('paged', absint($page), $base) : $base;
}
add_filter('query_vars', function ($vars) { $vars[] = 'shiki_stories'; return $vars; });
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || !$query->get('shiki_stories')) { return; }
    foreach (['p', 'page_id', 'attachment_id'] as $key) { $query->set($key, 0); }
    foreach (['name', 'pagename', 'attachment'] as $key) { $query->set($key, ''); }
    $query->set('post_type', 'post');
    $query->set('posts_per_page', max(1, (int) get_option('posts_per_page', 10)));
    $query->set('ignore_sticky_posts', true);
    $query->set('orderby', ['date' => 'DESC', 'ID' => 'DESC']);
    $query->set('paged', max(1, (int) $query->get('paged'), (int) $query->get('page')));
    $query->set('page', 0);
    $query->is_page = false;
    $query->is_single = false;
    $query->is_singular = false;
    $query->is_home = false;
    $query->is_archive = true;
    $query->is_search = false;
    $query->is_attachment = false;
    $query->is_404 = false;
    $query->queried_object = null;
    $query->queried_object_id = 0;
}, 5);
add_filter('template_include', function ($template) { return get_query_var('shiki_stories') && !is_404() ? get_theme_file_path('/index.php') : $template; }, 99);
add_filter('redirect_canonical', function ($redirect) { return get_query_var('shiki_stories') ? false : $redirect; });
add_action('after_setup_theme', function () { add_editor_style('assets/editor.css'); }, 20);
