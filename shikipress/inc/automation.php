<?php
/** Seasonal automation, suggestions and bounded, optional aggregate statistics. */
if (!defined('ABSPATH')) { exit; }

function shiki_seasons(): array {
    return ['spring' => '春', 'summer' => '夏', 'autumn' => '秋', 'winter' => '冬'];
}
add_filter('shiki_defaults', function (array $defaults): array {
    return array_merge($defaults, [
        'season_terms' => [], 'classification_mode' => 'suggest', 'slug_mode' => 'suggest',
        'deepl_enabled' => false, 'deepl_plan' => 'free', 'featured_mode' => 'auto', 'featured_ids' => [],
        'view_count_enabled' => false, 'popular_days' => 30, 'view_count_consent_required' => true, 'view_count_consent_ack' => false,
        'season_events' => [
            ['id' => 'new-year', 'label' => '新年のしおり', 'start' => '01-01', 'end' => '01-07', 'priority' => 20, 'accent' => '#a94a44', 'enabled' => false],
            ['id' => 'christmas', 'label' => 'クリスマスのしおり', 'start' => '12-15', 'end' => '12-25', 'priority' => 10, 'accent' => '#43684f', 'enabled' => false],
        ],
    ]);
});
function shiki_auto_ids($value, int $limit = 100): array {
    if (!is_array($value)) { $value = preg_split('/[\s,]+/', (string) $value); }
    return array_slice(array_values(array_unique(array_filter(array_map('absint', array_filter($value, 'is_scalar'))))), 0, $limit);
}
function shiki_valid_month_day(string $value): bool {
    return (bool) preg_match('/^(\d{2})-(\d{2})$/', $value, $matches) && checkdate((int) $matches[1], (int) $matches[2], 2000);
}
add_filter('shiki_sanitize_settings', function (array $out, array $input, string $section): array {
    if ($section === 'design') {
        $out['season_mode'] = in_array($input['season_mode'] ?? '', ['auto', 'fixed'], true) ? $input['season_mode'] : 'auto';
        $fixed = is_string($input['season_fixed'] ?? null) ? sanitize_key($input['season_fixed']) : '';
        $out['season_fixed'] = isset(shiki_seasons()[$fixed]) ? $fixed : 'autumn';
        $timezone = sanitize_text_field($input['timezone'] ?? 'Asia/Tokyo');
        $out['timezone'] = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Tokyo';
    }
    if ($section !== 'automation') { return $out; }
    foreach (['classification_mode' => ['suggest', 'auto'], 'slug_mode' => ['suggest', 'auto'], 'featured_mode' => ['auto', 'manual', 'hybrid'], 'deepl_plan' => ['free', 'pro']] as $key => $allowed) {
        $out[$key] = in_array($input[$key] ?? '', $allowed, true) ? $input[$key] : $allowed[0];
    }
    foreach (['deepl_enabled', 'view_count_enabled'] as $key) { $out[$key] = !empty($input[$key]); }
    $out['view_count_consent_ack'] = !empty($input['view_count_consent_ack']);
    $out['view_count_consent_required'] = !empty($input['view_count_consent_required']) || !$out['view_count_consent_ack'];
    $out['popular_days'] = in_array((int) ($input['popular_days'] ?? 30), [7, 30], true) ? (int) ($input['popular_days'] ?? 30) : 30;
    $out['featured_ids'] = shiki_auto_ids($input['featured_ids'] ?? []);
    $out['season_terms'] = [];
    foreach (shiki_seasons() as $season => $label) {
        $candidate_id = $input['season_terms'][$season] ?? 0; $id = is_scalar($candidate_id) ? absint($candidate_id) : 0;
        if ($id && term_exists($id, 'category')) { $out['season_terms'][$season] = $id; }
    }
    $out['season_events'] = [];
    foreach (array_slice((array) ($input['season_events'] ?? []), 0, 12) as $i => $event) {
        if (!is_array($event)) { continue; }
        $start = sanitize_text_field($event['start'] ?? ''); $end = sanitize_text_field($event['end'] ?? '');
        if (!shiki_valid_month_day($start) || !shiki_valid_month_day($end)) { continue; }
        $label = sanitize_text_field($event['label'] ?? '');
        if ($label === '') { continue; }
        $out['season_events'][] = ['id' => sanitize_key(is_string($event['id'] ?? null) ? $event['id'] : ('event-' . $i)), 'label' => mb_substr($label, 0, 60), 'start' => $start, 'end' => $end, 'priority' => min(100, max(0, (int) ($event['priority'] ?? 0))), 'accent' => sanitize_hex_color(is_string($event['accent'] ?? null) ? $event['accent'] : '') ?: '#805a44', 'enabled' => !empty($event['enabled'])];
    }
    return $out;
}, 10, 3);
function shiki_timezone(): DateTimeZone {
    try { return new DateTimeZone(shiki_option('timezone', 'Asia/Tokyo')); }
    catch (Exception $exception) { return new DateTimeZone('Asia/Tokyo'); }
}
function shiki_is_season_preview(): bool {
    return !is_admin() && current_user_can('manage_options') && isset($_GET['shiki_preview_season'], $_GET['_wpnonce']) && isset(shiki_seasons()[sanitize_key(wp_unslash($_GET['shiki_preview_season']))]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'shiki_season_preview');
}
add_action('template_redirect', function (): void {
    if (shiki_is_season_preview()) {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
    }
});
function shiki_season(?DateTimeImmutable $date = null): string {
    if ($date === null && shiki_is_season_preview()) { return sanitize_key(wp_unslash($_GET['shiki_preview_season'])); }
    if ($date === null && shiki_option('season_mode') === 'fixed') { return array_key_exists(shiki_option('season_fixed'), shiki_seasons()) ? shiki_option('season_fixed') : 'autumn'; }
    $date = ($date ?? new DateTimeImmutable('now', shiki_timezone()))->setTimezone(shiki_timezone());
    $month = (int) $date->format('n');
    return $month >= 3 && $month <= 5 ? 'spring' : ($month >= 6 && $month <= 8 ? 'summer' : ($month >= 9 && $month <= 11 ? 'autumn' : 'winter'));
}
function shiki_active_event(?DateTimeImmutable $date = null): array {
    $today = ($date ?? new DateTimeImmutable('now', shiki_timezone()))->setTimezone(shiki_timezone())->format('m-d');
    $events = [];
    foreach ((array) shiki_option('season_events', []) as $event) {
        if (empty($event['enabled']) || !shiki_valid_month_day($event['start'] ?? '') || !shiki_valid_month_day($event['end'] ?? '')) { continue; }
        $start = $event['start']; $end = $event['end'];
        if (($start <= $end && $today >= $start && $today <= $end) || ($start > $end && ($today >= $start || $today <= $end))) { $events[] = $event; }
    }
    usort($events, static fn($a, $b) => ((int) $b['priority'] <=> (int) $a['priority']) ?: strcmp($a['id'], $b['id']));
    return $events[0] ?? [];
}
function shiki_season_context(): array {
    $season = shiki_season();
    return ['season' => $season, 'label' => shiki_seasons()[$season], 'event' => shiki_active_event(), 'timezone' => shiki_timezone()->getName(), 'preview' => shiki_is_season_preview()];
}
function shiki_season_terms(): array {
    $terms = [];
    foreach ((array) shiki_option('season_terms', []) as $season => $id) {
        if (isset(shiki_seasons()[$season]) && term_exists((int) $id, 'category')) { $terms[$season] = (int) $id; }
    }
    return $terms;
}
/** Explicit, idempotent setup. A conflicting slug/name always requires a mapping. */
function shiki_setup_categories(): array {
    $settings = get_option('shikipress_settings', []); $mapping = shiki_season_terms(); $report = [];
    foreach (shiki_seasons() as $season => $label) {
        if (!empty($mapping[$season])) { $report[$season] = '既存の対応付けを維持'; continue; }
        $name = $label . 'の暮らし'; $by_slug = get_term_by('slug', $season, 'category'); $by_name = get_term_by('name', $name, 'category');
        if ($by_slug || $by_name) {
            if ($by_slug && $by_name && $by_slug->term_id === $by_name->term_id) { $mapping[$season] = (int) $by_slug->term_id; $report[$season] = '同じ名前・スラッグのカテゴリーに対応付け'; }
            else { $report[$season] = '名称またはスラッグが衝突。下の対応付けで管理者が選択してください'; }
            continue;
        }
        $term = wp_insert_term($name, 'category', ['slug' => $season]);
        if (is_wp_error($term)) { $report[$season] = '作成できませんでした。カテゴリー一覧で確認してください'; }
        else { $mapping[$season] = (int) $term['term_id']; $report[$season] = '作成しました'; }
    }
    $settings['season_terms'] = $mapping; update_option('shikipress_settings', $settings); shiki_invalidate_cache();
    return $report;
}
function shiki_classifier_keywords(): array {
    return [
        'spring' => ['春の', '春休み', '桜', '花見', '新生活', '入学', 'ひな祭り', '花粉', 'spring'],
        'summer' => ['夏の', '夏休み', '梅雨', '熱中症', '暑さ', '冷房', 'エアコン', 'カビ対策', '日焼け', 'プール', 'summer'],
        'autumn' => ['秋の', '紅葉', '秋雨', '十五夜', 'ハロウィン', '衣替え', 'autumn', 'fall'],
        'winter' => ['冬の', '冬休み', '結露', '暖房', '防寒', '雪', '正月', 'クリスマス', '乾燥対策', 'winter'],
    ];
}
function shiki_classify_post($post): array {
    $post = get_post($post); if (!$post) { return ['candidates' => [], 'reasons' => [], 'scores' => [], 'notice' => '記事がありません']; }
    $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
    $fields = ['タイトル' => [$post->post_title, 3], 'タグ' => [is_wp_error($tags) ? '' : implode(' ', $tags), 3], '本文' => [mb_substr(wp_strip_all_tags($post->post_content), 0, 40000), 1]];
    $scores = []; $reasons = [];
    foreach (shiki_classifier_keywords() as $season => $words) {
        $scores[$season] = 0; $reasons[$season] = [];
        foreach ($fields as $field => [$text, $weight]) {
            foreach ($words as $word) {
                $matched = preg_match('/^[a-z]+$/i', $word) ? preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text) : mb_stripos($text, $word) !== false;
                if ($matched) { $scores[$season] += $weight; $reasons[$season][] = $field . '「' . $word . '」'; }
            }
        }
    }
    $candidates = array_keys(array_filter($scores, static fn($score) => $score >= 3));
    $all_year = (bool) preg_match('/通年|一年中|年中|all.year/i', $post->post_title);
    if ($all_year) { $candidates = []; }
    return apply_filters('shiki_classification_result', ['candidates' => $candidates, 'scores' => $scores, 'reasons' => $reasons, 'notice' => $all_year ? '通年という明示があるため四季を自動適用しません。' : ($candidates ? '一致語によるルール点数です。正解率・確率ではありません。' : '季節を確定できません。カテゴリーを手動で選択できます。')], $post);
}
function shiki_clean_english_slug(string $text): string {
    $text = strtolower(remove_accents(wp_strip_all_tags($text)));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text); $text = trim($text, '-');
    if (strlen($text) > 70) { $text = rtrim(substr($text, 0, 70), '-'); }
    if ($text === '' || preg_match('/^\d+$/', $text)) { return ''; }
    if (in_array($text, ['feed', 'feeds', 'rss', 'rss2', 'rdf', 'atom', 'embed', 'page', 'attachment', 'author', 'category', 'tag', 'search', 'wp-admin', 'wp-json', 'wp-content', 'trackback'], true)) { $text .= '-guide'; }
    return $text;
}
function shiki_slug_candidate(string $title): array {
    $title = trim(wp_strip_all_tags($title));
    $phrases = [
        '冬の結露を防ぐ方法' => 'prevent-window-condensation', '秋の大掃除のコツ' => 'autumn-cleaning-tips',
        '梅雨のカビ対策' => 'rainy-season-mold-prevention', '春の暮らし' => 'spring', '夏の暮らし' => 'summer', '秋の暮らし' => 'autumn', '冬の暮らし' => 'winter',
        'お問い合わせ' => 'contact', 'プライバシーポリシー' => 'privacy-policy', '運営者情報' => 'about', 'サイトについて' => 'about-site', '免責事項' => 'disclaimer',
    ];
    $phrases = apply_filters('shiki_slug_dictionary', $phrases);
    if (isset($phrases[$title])) { return ['slug' => shiki_clean_english_slug($phrases[$title]), 'source' => '辞書の登録済み語句', 'complete' => true]; }
    if (preg_match('/^[\x20-\x7E]+$/', $title)) { return ['slug' => shiki_clean_english_slug($title), 'source' => '英語・英数字の整形（翻訳なし）', 'complete' => true]; }
    $dictionary = ['春' => 'spring', '夏' => 'summer', '秋' => 'autumn', '冬' => 'winter', '梅雨' => 'rainy-season', '結露' => 'window-condensation', 'カビ' => 'mold', '大掃除' => 'cleaning', '掃除' => 'cleaning', '衣替え' => 'wardrobe-change', '花粉' => 'pollen', '新生活' => 'new-life', '防寒' => 'cold-protection', '熱中症' => 'heatstroke', '対策' => 'prevention', '方法' => 'guide', 'コツ' => 'tips', '暮らし' => 'living', '節約' => 'saving', '料理' => 'cooking', '収納' => 'storage', '準備' => 'preparation'];
    uksort($dictionary, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $remaining = $title;
    foreach ($dictionary as $word => $english) { $remaining = str_replace($word, ' ' . $english . ' ', $remaining); }
    $remaining = preg_replace('/[のをにでとやはがもへ、。！？!?:：・「」『』（）()\s]+/u', ' ', $remaining);
    if (preg_match('/[^\x20-\x7E]/', $remaining)) { return ['slug' => '', 'source' => '辞書の範囲外。英語候補を手入力、または許可したDeepL翻訳を利用してください。', 'complete' => false]; }
    return ['slug' => shiki_clean_english_slug($remaining), 'source' => '限定語彙の辞書・ルール候補。意味を確認してください。', 'complete' => true];
}
function shiki_slug_editable($post): bool {
    $post = get_post($post);
    return $post && (!$post->post_name || get_post_meta($post->ID, '_shiki_new_theme_draft', true)) && in_array($post->post_type, ['post', 'page'], true) && in_array($post->post_status, ['draft', 'auto-draft', 'pending'], true) && !get_post_meta($post->ID, '_shiki_ever_published', true);
}
function shiki_adopt_slug(int $post_id, string $candidate, bool $explicit = false) {
    $post = get_post($post_id);
    if (!$post || !shiki_slug_editable($post)) { return new WP_Error('protected', '公開済み・公開履歴のあるURLは自動化の対象外です。'); }
    if (get_post_meta($post_id, '_shiki_slug_lock', true) && !$explicit) { return new WP_Error('locked', '手動固定中です。'); }
    $owned = (string) get_post_meta($post_id, '_shiki_auto_slug', true);
    if (!$explicit && $post->post_name && $post->post_name !== $owned) { return new WP_Error('manual', '手動指定済みURLを維持しました。'); }
    $slug = shiki_clean_english_slug($candidate);
    if ($slug === '') { return new WP_Error('empty', '有効な英語スラッグを入力してください。'); }
    // Force uniqueness even while a draft, then let core repeat the check on publication.
    $slug = wp_unique_post_slug($slug, $post_id, 'publish', $post->post_type, $post->post_parent);
    static $busy = false;
    if ($busy) { return new WP_Error('busy', '保存中です。'); }
    $busy = true; $result = wp_update_post(['ID' => $post_id, 'post_name' => $slug], true); $busy = false;
    if (is_wp_error($result)) { return $result; }
    update_post_meta($post_id, '_shiki_auto_slug', $slug);
    return $slug;
}
add_action('transition_post_status', function ($new, $old, $post): void {
    if ($new === 'publish' || $old === 'publish') { update_post_meta($post->ID, '_shiki_ever_published', 1); }
}, 10, 3);
add_action('save_post', function (int $post_id, WP_Post $post, bool $update): void {
    if (!$update && !wp_is_post_revision($post_id) && in_array($post->post_status, ['auto-draft', 'draft'], true) && in_array($post->post_type, ['post', 'page'], true)) { update_post_meta($post_id, '_shiki_new_theme_draft', 1); }
}, 5, 3);
add_action('save_post', function (int $post_id, WP_Post $post, bool $update): void {
    static $busy = false;
    if ($busy || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !in_array($post->post_type, ['post', 'page'], true) || $post->post_status === 'auto-draft') { return; }
    if (!get_post_meta($post_id, '_shiki_draft_seen', true)) {
        update_post_meta($post_id, '_shiki_draft_seen', 1);
        if ($post->post_status !== 'draft' || !get_post_meta($post_id, '_shiki_new_theme_draft', true)) { return; }
        $busy = true;
        if ($post->post_type === 'post' && shiki_option('classification_mode') === 'auto' && !get_post_meta($post_id, '_shiki_classification_lock', true) && empty($_POST['shiki_classification_lock'])) {
            $result = shiki_classify_post($post); $mapping = shiki_season_terms(); $terms = [];
            foreach ($result['candidates'] as $season) { if (!empty($mapping[$season])) { $terms[] = $mapping[$season]; } }
            $existing = wp_get_post_categories($post_id); $default = (int) get_option('default_category');
            if ($terms && (!$existing || $existing === [$default])) { wp_set_post_categories($post_id, $terms); update_post_meta($post_id, '_shiki_classification_applied', 'auto'); }
        }
        if (shiki_option('slug_mode') === 'auto' && shiki_slug_editable($post) && !get_post_meta($post_id, '_shiki_slug_lock', true) && empty($_POST['shiki_slug_lock'])) {
            $candidate = shiki_slug_candidate($post->post_title); if ($candidate['slug']) { shiki_adopt_slug($post_id, $candidate['slug']); }
        }
        $busy = false;
    }
}, 20, 3);
add_action('add_meta_boxes', function (): void {
    foreach (['post', 'page'] as $type) { add_meta_box('shiki-automation', '季節のしおり・記事自動化', 'shiki_automation_metabox', $type, 'normal', 'default'); }
});
function shiki_automation_metabox(WP_Post $post): void {
    if (!current_user_can('edit_post', $post->ID)) { return; }
    wp_nonce_field('shiki_editor_' . $post->ID, 'shiki_editor_nonce');
    $candidate = shiki_slug_candidate($post->post_title); $classification = shiki_classify_post($post); $mapping = shiki_season_terms();
    echo '<p>辞書方式は季節・暮らしの限定語彙に対応します。未知の文章は自動翻訳せず候補なしと表示します。</p>';
    if ($post->post_type === 'post') {
        echo '<p><strong>カテゴリー候補</strong> ' . esc_html($classification['notice']) . '</p>';
        foreach (shiki_seasons() as $season => $label) {
            $selected = in_array($season, $classification['candidates'], true);
            echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="shiki_classification[]" value="' . esc_attr($season) . '" ' . checked($selected, true, false) . disabled(empty($mapping[$season]), true, false) . '> ' . esc_html($label) . ' — ' . esc_html(implode('、', $classification['reasons'][$season])) . ' (' . (int) $classification['scores'][$season] . '点)</label>';
        }
        echo '<p><label><input type="checkbox" name="shiki_apply_classification" value="1"> 保存時に選択した季節を追加する（既存カテゴリーを維持）</label></p>';
        echo '<p><label><input type="checkbox" name="shiki_classification_lock" value="1" ' . checked((bool) get_post_meta($post->ID, '_shiki_classification_lock', true), true, false) . '> 分類を手動ロック</label></p>';
        echo '<p><label>特集の優先度（0〜100） <input type="number" min="0" max="100" name="shiki_priority" value="' . (int) get_post_meta($post->ID, '_shiki_priority', true) . '"></label></p>';
    }
    echo '<hr><p><strong>英語スラッグ候補</strong> <span id="shiki-slug-source">' . esc_html($candidate['source']) . '</span></p>';
    echo '<p><label for="shiki-slug-candidate">候補を編集</label> <input class="widefat" id="shiki-slug-candidate" name="shiki_slug_candidate" maxlength="100" value="' . esc_attr($candidate['slug']) . '"></p>';
    if (shiki_slug_editable($post)) {
        echo '<p><label><input type="checkbox" name="shiki_apply_slug" value="1"> 保存時にこの候補を採用（明示した下書きURLの変更）</label></p>';
        echo '<button type="button" class="button shiki-regenerate" data-post="' . (int) $post->ID . '">辞書から再生成</button> ';
        if (shiki_option('deepl_enabled')) {
            echo '<p><label><input id="shiki-deepl-consent" type="checkbox"> 現在保存済みのタイトルだけをDeepLへ送ることを承認します。契約により料金が発生します。</label></p>';
            echo '<button type="button" class="button shiki-translate" data-post="' . (int) $post->ID . '">DeepL候補を作成</button>';
        }
    } else { echo '<p>公開済み・公開履歴のある記事のURLは、この機能では変更しません。</p>'; }
    echo '<p><label><input type="checkbox" name="shiki_slug_lock" value="1" ' . checked((bool) get_post_meta($post->ID, '_shiki_slug_lock', true), true, false) . '> スラッグを手動固定（自動適用を停止）</label></p><p id="shiki-automation-status" role="status" aria-live="polite"></p>';
}
add_action('save_post', function (int $post_id, WP_Post $post): void {
    static $busy = false;
    if ($busy || empty($_POST['shiki_editor_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shiki_editor_nonce'])), 'shiki_editor_' . $post_id) || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
    $busy = true;
    update_post_meta($post_id, '_shiki_classification_lock', !empty($_POST['shiki_classification_lock']) ? 1 : 0);
    update_post_meta($post_id, '_shiki_slug_lock', !empty($_POST['shiki_slug_lock']) ? 1 : 0);
    update_post_meta($post_id, '_shiki_priority', min(100, max(0, (int) ($_POST['shiki_priority'] ?? 0))));
    if ($post->post_type === 'post' && !empty($_POST['shiki_apply_classification'])) {
        $mapping = shiki_season_terms(); $terms = [];
        foreach ((array) ($_POST['shiki_classification'] ?? []) as $season) { if (is_string($season) && isset($mapping[$season])) { $terms[] = $mapping[$season]; } }
        if ($terms) { wp_set_post_categories($post_id, array_unique(array_merge(wp_get_post_categories($post_id), $terms))); update_post_meta($post_id, '_shiki_classification_applied', 'manual'); }
    }
    if (!empty($_POST['shiki_apply_slug']) && isset($_POST['shiki_slug_candidate'])) { shiki_adopt_slug($post_id, sanitize_text_field(wp_unslash($_POST['shiki_slug_candidate'])), true); }
    shiki_invalidate_cache(); $busy = false;
}, 30, 2);
add_action('admin_enqueue_scripts', function ($hook): void {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) { return; }
    wp_enqueue_script('shiki-automation', get_template_directory_uri() . '/assets/automation.js', [], SHIKI_VERSION, true);
    wp_localize_script('shiki-automation', 'shikiAutomation', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('shiki_automation_ajax')]);
});
function shiki_automation_ajax_auth(): WP_Post {
    check_ajax_referer('shiki_automation_ajax', 'nonce'); $post = get_post(absint($_POST['post_id'] ?? 0));
    if (!$post || !current_user_can('edit_post', $post->ID)) { wp_send_json_error(['message' => '操作権限がありません。'], 403); }
    return $post;
}
add_action('wp_ajax_shiki_slug_suggest', function (): void {
    $post = shiki_automation_ajax_auth(); wp_send_json_success(shiki_slug_candidate($post->post_title));
});
function shiki_delete_job_lock_value(string $key, array $value): void {
    global $wpdb;
    // Compare-and-delete prevents a stale worker from releasing a newer worker's lock.
    $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => maybe_serialize($value)], ['%s', '%s']);
    wp_cache_delete($key, 'options');
}
function shiki_acquire_job_lock(string $key, int $seconds): string|false {
    $existing = get_option($key, []);
    if (is_array($existing) && $existing && (int) ($existing['expires'] ?? 0) < time()) { shiki_delete_job_lock_value($key, $existing); }
    $token = wp_generate_password(24, false, false);
    return add_option($key, ['expires' => time() + $seconds, 'token' => $token], '', false) ? $token : false;
}
function shiki_release_job_lock(string $key, string $token): void {
    $existing = get_option($key, []);
    if (is_array($existing) && isset($existing['token']) && hash_equals((string) $existing['token'], $token)) { shiki_delete_job_lock_value($key, $existing); }
}
/** All network calls are off the save/render path, queued only after an explicit per-title consent. */
function shiki_deepl_request(string $title) {
    if (!shiki_option('deepl_enabled') || !defined('SHIKIPRESS_DEEPL_KEY') || !SHIKIPRESS_DEEPL_KEY) { return new WP_Error('disabled', 'DeepLを有効化し、サーバー側にAPIキーを設定してください。'); }
    $url = shiki_option('deepl_plan') === 'pro' ? 'https://api.deepl.com/v2/translate' : 'https://api-free.deepl.com/v2/translate';
    $response = wp_safe_remote_post($url, ['timeout' => 5, 'redirection' => 0, 'headers' => ['Authorization' => 'DeepL-Auth-Key ' . SHIKIPRESS_DEEPL_KEY, 'Content-Type' => 'application/json'], 'body' => wp_json_encode(['text' => [mb_substr(wp_strip_all_tags($title), 0, 500)], 'source_lang' => 'JA', 'target_lang' => 'EN-US']), 'limit_response_size' => 16384]);
    if (is_wp_error($response)) { return new WP_Error('network', '通信に失敗しました。', ['retry' => true]); }
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) { return new WP_Error('http_' . $status, 'DeepL応答コード ' . $status, ['retry' => $status === 429 || $status >= 500]); }
    $json = json_decode(wp_remote_retrieve_body($response), true); $translation = $json['translations'][0]['text'] ?? '';
    if (!is_string($translation)) { return new WP_Error('invalid_response', '翻訳応答の形式を確認できませんでした。'); }
    $slug = shiki_clean_english_slug($translation);
    return $slug !== '' ? $slug : new WP_Error('invalid_response', '有効な英語候補を取得できませんでした。');
}
add_action('wp_ajax_shiki_translate_title', function (): void {
    $post = shiki_automation_ajax_auth();
    if (!shiki_slug_editable($post) || empty($_POST['consent']) || !shiki_option('deepl_enabled') || !defined('SHIKIPRESS_DEEPL_KEY') || !SHIKIPRESS_DEEPL_KEY) { wp_send_json_error(['message' => '下書き・API設定・タイトル送信の承認を確認してください。'], 400); }
    $existing = get_post_meta($post->ID, '_shiki_translation', true);
    $active_lock = get_option('shiki_translation_lock_' . $post->ID, []);
    $scheduled = is_array($existing) && !empty($existing['token']) && wp_next_scheduled('shiki_translate_job', [$post->ID, $existing['token']]);
    $working = is_array($active_lock) && (int) ($active_lock['expires'] ?? 0) > time();
    if (is_array($existing) && in_array($existing['state'] ?? '', ['queued', 'working'], true) && ($existing['title_hash'] ?? '') === hash('sha256', $post->post_title) && ($scheduled || $working) && (int) ($existing['created'] ?? 0) > time() - 15 * MINUTE_IN_SECONDS) { wp_send_json_success(['state' => 'queued', 'message' => 'すでに処理待ちです。']); }
    $token = wp_generate_password(20, false, false);
    update_post_meta($post->ID, '_shiki_translation', ['state' => 'queued', 'title_hash' => hash('sha256', $post->post_title), 'token' => $token, 'attempts' => 0, 'created' => time()]);
    wp_schedule_single_event(time() + 1, 'shiki_translate_job', [$post->ID, $token]);
    wp_send_json_success(['state' => 'queued', 'message' => '保存済みタイトルの候補を処理待ちにしました。WP-Cronが実行すると反映されます。']);
});
function shiki_translation_budget_available(): bool {
    static $started = null, $count = 0;
    if ($started === null) { $started = microtime(true); }
    if ($count >= 3 || microtime(true) - $started >= 12) { return false; }
    $count++; return true;
}
add_action('shiki_translate_job', function (int $post_id, string $token): void {
    $job = get_post_meta($post_id, '_shiki_translation', true); $post = get_post($post_id);
    if (!$post || !is_array($job) || ($job['state'] ?? '') !== 'queued' || (int) ($job['attempts'] ?? 0) >= 3 || !hash_equals((string) ($job['token'] ?? ''), $token) || !shiki_slug_editable($post) || hash('sha256', $post->post_title) !== ($job['title_hash'] ?? '') || !shiki_option('deepl_enabled')) { return; }
    if (!shiki_translation_budget_available()) { wp_schedule_single_event(time() + 60, 'shiki_translate_job', [$post_id, $token]); return; }
    $lock = 'shiki_translation_lock_' . $post_id;
    $lock_token = shiki_acquire_job_lock($lock, 30); if (!$lock_token) { return; }
    $job['state'] = 'working'; $job['attempts'] = (int) $job['attempts'] + 1; update_post_meta($post_id, '_shiki_translation', $job);
    $result = shiki_deepl_request($post->post_title);
    if (is_wp_error($result)) {
        $retry = $result->get_error_data(); $job['message'] = $result->get_error_message();
        if (!empty($retry['retry']) && $job['attempts'] < 3) { $job['state'] = 'queued'; wp_schedule_single_event(time() + 60 * (2 ** ($job['attempts'] - 1)), 'shiki_translate_job', [$post_id, $token]); }
        else { $job['state'] = 'error'; }
    } else { $job['state'] = 'done'; $job['slug'] = $result; $job['message'] = '候補を作成しました。意味を確認して採用してください。'; }
    update_post_meta($post_id, '_shiki_translation', $job); shiki_release_job_lock($lock, $lock_token);
}, 10, 2);
add_action('wp_ajax_shiki_translation_status', function (): void {
    $post = shiki_automation_ajax_auth(); $job = get_post_meta($post->ID, '_shiki_translation', true);
    if (is_array($job) && hash('sha256', $post->post_title) !== ($job['title_hash'] ?? '')) { wp_send_json_success(['state' => 'error', 'slug' => '', 'message' => 'タイトルが変更されたため旧候補を破棄しました。再生成してください。']); }
    if (is_array($job) && in_array($job['state'] ?? '', ['queued', 'working'], true)) {
        $scheduled = !empty($job['token']) && wp_next_scheduled('shiki_translate_job', [$post->ID, $job['token']]); $lock = get_option('shiki_translation_lock_' . $post->ID, []);
        if (!$scheduled && (!is_array($lock) || (int) ($lock['expires'] ?? 0) < time())) { $job['state'] = 'error'; $job['message'] = 'ジョブが解除または中断されました。送信内容を確認して再生成してください。'; update_post_meta($post->ID, '_shiki_translation', $job); }
    }
    wp_send_json_success(['state' => $job['state'] ?? 'none', 'slug' => $job['slug'] ?? '', 'message' => $job['message'] ?? '処理待ちです。WP-Cronの実行を待ってください。']);
});

/** Stable selection, with a bounded pool and an explicit no-unrelated-fill rule. */
function shiki_select_posts(string $context, array $exclude = [], int $limit = 3, int $post_id = 0): array {
    if (!in_array($context, ['featured', 'related', 'popular'], true)) { return []; }
    $limit = max(1, min(12, $limit)); $exclude = shiki_auto_ids(array_merge($exclude, $post_id ? [$post_id] : []), 1000); sort($exclude);
    $season = shiki_season(); $epoch = (int) get_option('shikipress_cache_epoch', 0);
    $cache_key = 'shiki_sel_' . md5(wp_json_encode([$context, $exclude, $limit, $post_id, $season, $epoch, shiki_option('popular_days'), gmdate('Y-m-d')]));
    if (!shiki_is_season_preview()) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            if (!$cached) { return []; }
            $query = shiki_public_posts(['post__in' => $cached, 'orderby' => 'post__in', 'posts_per_page' => count($cached)]);
            return $query->posts;
        }
    }
    $manual = array_values(array_diff(shiki_auto_ids(shiki_option('featured_ids', [])), $exclude)); $mode = shiki_option('featured_mode', 'auto'); $mapping = shiki_season_terms();
    $current_categories = $post_id ? wp_get_post_categories($post_id) : []; $current_tags = $post_id ? wp_get_post_tags($post_id, ['fields' => 'ids']) : [];
    if (is_wp_error($current_tags)) { $current_tags = []; }
    $args = ['posts_per_page' => 500, 'post__not_in' => $exclude, 'orderby' => ['modified' => 'DESC', 'ID' => 'DESC']];
    if ($context === 'featured' && $mode === 'manual') {
        if (!$manual) { return []; } $args['post__in'] = $manual;
    } elseif ($context === 'featured') {
        // A hybrid may pin a published article of a different season by explicit administrator choice.
        $branches = [];
        if (!empty($mapping[$season])) { $branches[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => [$mapping[$season]]]; }
        if ($mode !== 'hybrid' || !$manual) {
            if (!$branches) { return []; } $args['tax_query'] = $branches;
        }
    } elseif ($context === 'related') {
        $tax = ['relation' => 'OR'];
        if ($current_categories) { $tax[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => $current_categories]; }
        if ($current_tags) { $tax[] = ['taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $current_tags]; }
        if (count($tax) === 1) { return []; } $args['tax_query'] = $tax;
    } elseif ($context === 'popular') {
        if (!shiki_option('view_count_enabled')) { return []; }
        $counts = shiki_popular_counts(); if (!$counts) { return []; } $args['post__in'] = array_keys($counts);
    }
    $query = shiki_public_posts($args); $pool = $query->posts;
    // Include explicitly pinned IDs even when they are older than the automatic 500-candidate window.
    if ($context === 'featured' && $mode === 'hybrid' && $manual) {
        $pinned = shiki_public_posts(['post__in' => $manual, 'post__not_in' => $exclude, 'posts_per_page' => count($manual)])->posts;
        $by_id = []; foreach (array_merge($pinned, $pool) as $candidate) { $by_id[$candidate->ID] = $candidate; } $pool = array_values($by_id);
    }
    $counts = $counts ?? (shiki_option('view_count_enabled') ? shiki_popular_counts() : []);
    $scored = [];
    foreach ($pool as $candidate) {
        if (in_array($candidate->ID, $exclude, true)) { continue; }
        $categories = wp_get_post_categories($candidate->ID); $tags = wp_get_post_tags($candidate->ID, ['fields' => 'ids']); if (is_wp_error($tags)) { $tags = []; }
        $season_match = !empty($mapping[$season]) && in_array($mapping[$season], $categories, true);
        $manual_index = array_search($candidate->ID, $manual, true);
        $shared_tags = count(array_intersect($tags, $current_tags)); $shared_categories = count(array_intersect($categories, $current_categories));
        if ($context === 'featured' && !$season_match && !($mode !== 'auto' && $manual_index !== false)) { continue; }
        if ($context === 'related' && !$shared_tags && !$shared_categories) { continue; }
        $score = $context === 'popular' ? (int) ($counts[$candidate->ID] ?? 0) : (($manual_index !== false && $context === 'featured' && $mode !== 'auto' ? (count($manual) - $manual_index) * 1000000 : 0) + max(0, min(100, (int) get_post_meta($candidate->ID, '_shiki_priority', true))) * 100 + ($season_match ? 40 : 0) + min(100, $shared_tags) * 20 + min(100, $shared_categories) * 10 + min(9, (int) ($counts[$candidate->ID] ?? 0)));
        if ($context === 'popular' && $score < 1) { continue; }
        $scored[] = ['post' => $candidate, 'score' => $score];
    }
    usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($b['post']->post_modified_gmt, $a['post']->post_modified_gmt) ?: strcmp($b['post']->post_date_gmt, $a['post']->post_date_gmt) ?: ($b['post']->ID <=> $a['post']->ID));
    $posts = array_map(static fn($item) => $item['post'], array_slice($scored, 0, $limit));
    if (!shiki_is_season_preview()) {
        set_transient($cache_key, array_map(static fn($post) => $post->ID, $posts), 15 * MINUTE_IN_SECONDS);
        $keys = (array) get_option('shikipress_selection_keys', []);
        $keys = array_values(array_diff($keys, [$cache_key])); $keys[] = $cache_key;
        while (count($keys) > 200) { delete_transient(array_shift($keys)); }
        update_option('shikipress_selection_keys', $keys, false);
    }
    return $posts;
}
function shiki_views_table(): string { global $wpdb; return $wpdb->prefix . 'shikipress_views'; }
function shiki_install_views_table(): void {
    if (get_option('shikipress_views_schema') === '1') { return; }
    global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = shiki_views_table(); $collation = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (post_id bigint(20) unsigned NOT NULL, view_day date NOT NULL, views bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (post_id,view_day), KEY view_day (view_day)) $collation;");
    update_option('shikipress_views_schema', '1', false);
}
function shiki_popular_counts(): array {
    if (!shiki_option('view_count_enabled') || get_option('shikipress_views_schema') !== '1') { return []; }
    $days = (int) shiki_option('popular_days', 30); $key = 'shiki_popular_' . $days;
    $cached = get_transient($key); if (is_array($cached)) { return $cached; }
    global $wpdb; $table = shiki_views_table();
    $start = (new DateTimeImmutable('now', shiki_timezone()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, SUM(views) AS total FROM $table WHERE view_day >= %s GROUP BY post_id ORDER BY total DESC, post_id DESC LIMIT 500", $start)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table prefix only.
    $counts = []; foreach ($rows as $row) { if ($row->total > 0) { $counts[(int) $row->post_id] = (int) $row->total; } }
    set_transient($key, $counts, 15 * MINUTE_IN_SECONDS); return $counts;
}
add_action('rest_api_init', function (): void {
    register_rest_route('shikipress/v1', '/view', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $request) {
        if (!shiki_option('view_count_enabled') || (is_user_logged_in() && current_user_can('edit_posts'))) { return new WP_REST_Response(['counted' => false], 200); }
        if (shiki_option('view_count_consent_required', true) && ($request->get_param('consent') !== 'tcf-confirmed' || (int) $request->get_param('cmp_id') !== (int) shiki_option('ads_cmp_id', 0) || !(int) shiki_option('ads_cmp_id', 0))) { return new WP_REST_Response(['counted' => false], 200); }
        $origin = $request->get_header('origin');
        if ($origin && wp_parse_url($origin, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) { return new WP_Error('origin', '送信元を確認できません。', ['status' => 403]); }
        $agent = (string) $request->get_header('user_agent');
        if (!$agent || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview/i', $agent)) { return new WP_REST_Response(['counted' => false], 200); }
        $post = get_post(absint($request->get_param('post_id')));
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || $post->post_password || get_post_meta($post->ID, '_shiki_exclude', true)) { return new WP_Error('post', '対象外の記事です。', ['status' => 400]); }
        // A single atomic counter update; no visitor ID, cookie, IP, user agent or raw event log is stored.
        shiki_install_views_table(); global $wpdb; $table = shiki_views_table(); $day = (new DateTimeImmutable('now', shiki_timezone()))->format('Y-m-d');
        $wpdb->query($wpdb->prepare("INSERT INTO $table (post_id, view_day, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views=views+1", $post->ID, $day)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table prefix only.
        return new WP_REST_Response(['counted' => true], 200);
    }, 'args' => ['post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint']]]);
});
add_action('wp_enqueue_scripts', function (): void {
    if (!shiki_option('view_count_enabled') || !is_singular('post') || (is_user_logged_in() && current_user_can('edit_posts')) || post_password_required() || get_post_meta(get_queried_object_id(), '_shiki_exclude', true)) { return; }
    wp_enqueue_script('shiki-views', get_template_directory_uri() . '/assets/views.js', [], SHIKI_VERSION, true);
    wp_localize_script('shiki-views', 'shikiViews', ['endpoint' => rest_url('shikipress/v1/view'), 'postId' => get_queried_object_id(), 'consentRequired' => (bool) shiki_option('view_count_consent_required', true), 'cmpId' => absint(shiki_option('ads_cmp_id', 0))]);
});
add_action('shiki_daily_maintenance', function (): void {
    $lock = 'shiki_daily_lock'; $lock_token = shiki_acquire_job_lock($lock, 60); if (!$lock_token) { return; }
    if (get_option('shikipress_views_schema') === '1') {
        global $wpdb; $table = shiki_views_table(); $cutoff = (new DateTimeImmutable('now', shiki_timezone()))->modify('-90 days')->format('Y-m-d');
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE view_day < %s LIMIT 1000", $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    delete_transient('shiki_popular_7'); delete_transient('shiki_popular_30'); shiki_release_job_lock($lock, $lock_token);
});
add_action('shiki_season_tick', function (): void {
    $context = shiki_season_context(); $fingerprint = $context['season'] . ':' . ($context['event']['id'] ?? '') . ':' . (new DateTimeImmutable('now', shiki_timezone()))->format('Y-m-d');
    if (get_option('shikipress_season_fingerprint') !== $fingerprint) { update_option('shikipress_season_fingerprint', $fingerprint, false); shiki_invalidate_cache(); }
});
function shiki_schedule_automation(): void {
    if (!wp_next_scheduled('shiki_season_tick')) { wp_schedule_event(time() + 60, 'hourly', 'shiki_season_tick'); }
    if (!wp_next_scheduled('shiki_daily_maintenance')) { wp_schedule_event(time() + 120, 'daily', 'shiki_daily_maintenance'); }
    if (shiki_option('view_count_enabled')) { shiki_install_views_table(); }
}
add_action('init', 'shiki_schedule_automation');
add_action('switch_theme', function (): void {
    foreach (['shiki_season_tick', 'shiki_daily_maintenance', 'shiki_translate_job'] as $hook) { wp_unschedule_hook($hook); }
});
foreach (['trashed_post', 'untrashed_post', 'created_category'] as $hook) { add_action($hook, 'shiki_invalidate_cache', 99, 0); }
add_action('shiki_cache_invalidated', function (): void {
    foreach ((array) get_option('shikipress_selection_keys', []) as $key) { delete_transient($key); }
    delete_option('shikipress_selection_keys'); delete_transient('shiki_popular_7'); delete_transient('shiki_popular_30');
});
add_filter('shiki_setting_sections', function (array $sections): array {
    foreach (['season_terms', 'classification_mode', 'slug_mode', 'deepl_enabled', 'deepl_plan', 'featured_mode', 'featured_ids', 'view_count_enabled', 'popular_days', 'view_count_consent_required', 'view_count_consent_ack', 'season_events'] as $key) { $sections[$key] = 'automation'; }
    foreach (['season_mode', 'season_fixed', 'timezone'] as $key) { $sections[$key] = 'design'; }
    return $sections;
});
add_action('admin_post_shiki_category_setup', function (): void {
    if (!current_user_can('manage_categories') || !current_user_can('manage_options')) { wp_die('操作権限がありません。', '', ['response' => 403]); }
    check_admin_referer('shiki_category_setup');
    $report = shiki_setup_categories(); set_transient('shiki_setup_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=shikipress&tab=automation')); exit;
});
add_action('admin_post_shiki_delete_counts', function (): void {
    if (!current_user_can('manage_options')) { wp_die('操作権限がありません。', '', ['response' => 403]); }
    check_admin_referer('shiki_delete_counts'); global $wpdb; $table = shiki_views_table();
    if (get_option('shikipress_views_schema') === '1') { $wpdb->query("TRUNCATE TABLE $table"); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table prefix.
    shiki_invalidate_cache(); wp_safe_redirect(admin_url('admin.php?page=shikipress&tab=automation&counts_deleted=1')); exit;
});
add_action('shiki_admin_tab_automation', 'shiki_automation_admin');
function shiki_automation_admin(): void {
    if (!current_user_can('manage_options')) { return; }
    $report_key = 'shiki_setup_report_' . get_current_user_id(); $report = get_transient($report_key);
    if ($report) { echo '<div class="notice notice-info"><p>'; foreach ($report as $season => $message) { echo esc_html(shiki_seasons()[$season] . '：' . $message) . '<br>'; } echo '</p></div>'; delete_transient($report_key); }
    echo '<h2>基本設定</h2><p>四季カテゴリーは、管理者がここで実行したときだけ作成します。現在の名前・スラッグを変更せず、衝突するカテゴリーは対応付けを選択します。</p><table class="widefat striped"><thead><tr><th>対象</th><th>実行予定</th></tr></thead><tbody>';
    $mapping = shiki_season_terms();
    foreach (shiki_seasons() as $season => $label) {
        $existing = get_term_by('slug', $season, 'category'); $named = get_term_by('name', $label . 'の暮らし', 'category');
        $message = isset($mapping[$season]) ? '現在のterm ID ' . $mapping[$season] . 'を維持' : (($existing || $named) ? '既存カテゴリーあり。衝突時は作成せず管理者が対応を選択' : $label . 'の暮らし / ' . $season . ' を新規作成');
        echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($message) . '</td></tr>';
    }
    echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="shiki_category_setup">'; wp_nonce_field('shiki_category_setup'); submit_button('上記を確認して四季カテゴリーをセットアップ', 'secondary'); echo '</form>';
    shiki_settings_form_start('automation');
    echo '<h3>既存カテゴリーとの対応付け</h3><p>削除されたカテゴリーは自動復活しません。未対応の季節は特集の対象外になります。</p>';
    foreach (shiki_seasons() as $season => $label) {
        echo '<p><label>' . esc_html($label) . ' ';
        wp_dropdown_categories(['name' => 'shikipress_settings[season_terms][' . $season . ']', 'id' => 'shiki-season-term-' . $season, 'selected' => $mapping[$season] ?? 0, 'show_option_none' => '未対応', 'option_none_value' => 0, 'hide_empty' => false]); echo '</label></p>';
    }
    foreach (['classification_mode' => ['カテゴリー分類', ['suggest' => '候補提示のみ', 'auto' => '条件を満たす新規下書きだけ自動適用']], 'slug_mode' => ['英語スラッグ', ['suggest' => '候補提示のみ', 'auto' => 'URL未指定の新規下書きだけ自動適用']], 'featured_mode' => ['季節特集', ['auto' => '自動（現在の季節）', 'manual' => '手動指定のみ', 'hybrid' => '手動優先＋現在の季節']]] as $key => [$label, $choices]) {
        echo '<p><label>' . esc_html($label) . ' <select name="shikipress_settings[' . esc_attr($key) . ']">';
        foreach ($choices as $value => $text) { echo '<option value="' . esc_attr($value) . '" ' . selected(shiki_option($key), $value, false) . '>' . esc_html($text) . '</option>'; } echo '</select></label></p>';
    }
    echo '<p><label>手動特集の投稿ID（カンマ区切り・左から優先） <input class="regular-text" name="shikipress_settings[featured_ids]" value="' . esc_attr(implode(',', shiki_option('featured_ids', []))) . '"></label></p>';
    echo '<details><summary><strong>詳細設定：期間限定イベント</strong></summary><p>開始・終了はMM-DD。開始より終了が前なら年またぎです。両端の日付を含み、同時該当時は優先度が高いもの、同点はID順。最大12件。通常は無効です。</p>';
    $events = (array) shiki_option('season_events', []); if (count($events) < 12) { $events[] = ['id' => 'custom-' . (count($events) + 1), 'label' => '', 'start' => '01-01', 'end' => '01-01', 'priority' => 0, 'accent' => '#805a44', 'enabled' => false]; }
    foreach ($events as $i => $event) {
        $name = 'shikipress_settings[season_events][' . $i . ']';
        echo '<fieldset style="border:1px solid #ccd0d4;padding:12px;margin:12px 0"><legend>イベント ' . (int) ($i + 1) . '</legend><input type="hidden" name="' . esc_attr($name . '[id]') . '" value="' . esc_attr($event['id']) . '">';
        echo '<p><label><input type="checkbox" name="' . esc_attr($name . '[enabled]') . '" value="1" ' . checked(!empty($event['enabled']), true, false) . '> 有効</label></p>';
        foreach (['label' => '表示名（空欄で削除）', 'start' => '開始 MM-DD', 'end' => '終了 MM-DD', 'priority' => '優先度 0〜100', 'accent' => 'アクセント色 #RRGGBB'] as $key => $label) { echo '<p><label>' . esc_html($label) . ' <input name="' . esc_attr($name . '[' . $key . ']') . '" value="' . esc_attr($event[$key]) . '" size="' . ($key === 'label' ? '24' : '10') . '"></label></p>'; } echo '</fieldset>';
    }
    echo '</details><details><summary><strong>詳細設定：任意のDeepL翻訳</strong></summary><p>送信先はFree: https://api-free.deepl.com/v2/translate / Pro: https://api.deepl.com/v2/translate。本文・タグは送信せず、編集画面で明示承認した保存済みタイトル（最大500文字）のみ送信します。契約により料金が発生します。</p>';
    echo '<p><label><input type="checkbox" name="shikipress_settings[deepl_enabled]" value="1" ' . checked((bool) shiki_option('deepl_enabled'), true, false) . '> DeepL候補作成ボタンを有効にする</label></p><p><label>契約 <select name="shikipress_settings[deepl_plan]"><option value="free" ' . selected(shiki_option('deepl_plan'), 'free', false) . '>API Free</option><option value="pro" ' . selected(shiki_option('deepl_plan'), 'pro', false) . '>API Pro</option></select></label></p>';
    echo '<p>APIキー：' . (defined('SHIKIPRESS_DEEPL_KEY') && SHIKIPRESS_DEEPL_KEY ? '設定済み（非表示）' : '未設定') . '。wp-config.phpで定数 <code>SHIKIPRESS_DEEPL_KEY</code> を設定してください。キーはこのフォーム・バックアップへ保存しません。</p><p>保存・閲覧中に翻訳しません。WP-Cronで1件5秒、最大3試行、429/5xx/通信失敗だけ60秒・120秒後に再試行します。実API接続は契約キーを設定後に確認してください。</p></details>';
    echo '<details><summary><strong>詳細設定：任意の人気記事計測</strong></summary><p><label><input type="checkbox" name="shikipress_settings[view_count_enabled]" value="1" ' . checked((bool) shiki_option('view_count_enabled'), true, false) . '> 匿名の表示イベント集計を有効にする（初期無効）</label></p><p><label>集計期間 <select name="shikipress_settings[popular_days]"><option value="7" ' . selected(shiki_option('popular_days'), 7, false) . '>過去7日</option><option value="30" ' . selected(shiki_option('popular_days'), 30, false) . '>過去30日</option></select></label></p><p>投稿ID・日本時間の日付・件数だけを保存し、90日より古い行を毎日最大1000行削除します。IP・Cookie・訪問者IDを記録しません。表示後5秒のイベントを集計し、既知ボット・編集権限者を除外します。リロード・未判定ボット等は含み、厳密なユニーク訪問者数ではありません。1回のイベントにつき軽い原子的カウンター更新、ランキングは15分キャッシュです。</p></details>';
    echo '<details><summary><strong>詳細設定：集計の同意確認</strong></summary><p><label><input type="checkbox" name="shikipress_settings[view_count_consent_required]" value="1" ' . checked((bool) shiki_option('view_count_consent_required', true), true, false) . '> 有効なTCF CMPの同意確認ができるまで表示イベントを送信しない（標準）</label></p><p>広告画面で指定したCMP IDと一致する、loaded状態の公式TCF APIだけを確認します。GDPR適用時はpublisherの目的8・9の同意が必要です。CMP不在・拒否・状態不明では記録しません。CMPは承認済み連携またはサイト既存の仕組みで読み込んでください。</p><p><label><input type="checkbox" name="shikipress_settings[view_count_consent_ack]" value="1" ' . checked((bool) shiki_option('view_count_consent_ack', false), true, false) . '> 識別子を保存しない自サイトの匿名集計について、対象地域・運用上の同意要件を確認しました。上の確認を外す運用を明示的に選ぶ場合のみチェックします。</label></p></details>';
    shiki_settings_form_end();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="shiki_delete_counts">'; wp_nonce_field('shiki_delete_counts'); submit_button('保存済みの匿名集計をすべて削除', 'delete'); echo '</form>';
    echo '<p>選定順：手動の順番 → 記事の優先度 → 季節一致 → 共通タグ・カテゴリー → 更新日 → 公開日 → ID。自動候補は新しい最大500件、関連記事は同じカテゴリーまたはタグの記事だけです。人気記事は集計値があるものだけを表示します。</p><p>季節の基準：' . esc_html(shiki_timezone()->getName()) . '。WP-Cronはアクセスがないと遅れます。共有ページキャッシュの季節境界はサーバー側でも更新してください。</p>';
}

/** Category candidates appear only in the create form; existing term URLs are never rewritten. */
add_action('category_add_form_fields', function (): void {
    if (!current_user_can('manage_categories')) { return; }
    echo '<div class="form-field"><label for="shiki-new-category-candidate">英語スラッグ候補（新規カテゴリー）</label><input id="shiki-new-category-candidate" type="text" maxlength="100"><p>生成 → 内容を編集・確認 → 採用で標準スラッグ欄に入力します。カテゴリーの追加は最後に管理者が実行します。既存カテゴリーのURLは変更しません。</p><button type="button" class="button" id="shiki-category-generate">辞書から生成・再生成</button> <button type="button" class="button" id="shiki-category-adopt">候補を採用</button><p id="shiki-category-status" role="status" aria-live="polite"></p></div>';
});
add_action('admin_enqueue_scripts', function ($hook): void {
    $screen = get_current_screen();
    if ($hook !== 'edit-tags.php' || !$screen || $screen->taxonomy !== 'category') { return; }
    wp_enqueue_script('shiki-category-slug', get_template_directory_uri() . '/assets/category-slug.js', [], SHIKI_VERSION, true);
    wp_localize_script('shiki-category-slug', 'shikiCategory', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('shiki_category_slug')]);
});
add_action('wp_ajax_shiki_category_slug', function (): void {
    check_ajax_referer('shiki_category_slug', 'nonce');
    if (!current_user_can('manage_categories')) { wp_send_json_error(['message' => '操作権限がありません。'], 403); }
    $name = mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 200);
    wp_send_json_success(shiki_slug_candidate($name));
});
