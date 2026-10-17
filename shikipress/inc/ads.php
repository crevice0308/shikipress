<?php
/** Advertising manager. No third-party request is made by the default configuration. */
defined('ABSPATH') || exit;

function shiki_ads_defaults(): array {
    return [
        'ads_mode' => 'test', 'ads_live_approved' => false, 'ads_auto' => false,
        'ads_publisher' => '', 'ads_adsense_import' => '', 'ads_slots' => [], 'ads_limit' => 5,
        'ads_cmp_enabled' => false, 'ads_cmp_id' => 0, 'ads_cmp_code' => '',
        'ads_cmp_trusted' => false, 'ads_external_reviewed' => false,
    ];
}
add_filter('shiki_defaults', function ($defaults) { return array_merge($defaults, shiki_ads_defaults()); });
add_filter('shiki_setting_sections', function ($sections) {
    foreach (shiki_ads_defaults() as $key => $value) { $sections[$key] = 'ads'; }
    return $sections;
});
add_filter('shiki_backup_executable_keys', function ($keys) { return array_unique(array_merge($keys, ['ads_slots', 'ads_cmp_code'])); });
function shiki_ads_reset_import(array $settings): array {
    $settings['ads_mode'] = 'test'; $settings['ads_live_approved'] = false; $settings['ads_auto'] = false;
    $settings['ads_cmp_enabled'] = false; $settings['ads_cmp_trusted'] = false; $settings['ads_external_reviewed'] = false;
    if (isset($settings['ads_slots']) && is_array($settings['ads_slots'])) {
        foreach ($settings['ads_slots'] as &$slot) { if (is_array($slot)) { $slot['trusted'] = false; } }
        unset($slot);
    }
    return $settings;
}
add_filter('shiki_disarm_import_settings', 'shiki_ads_reset_import');

function shiki_ads_services(): array {
    return ['adsense' => 'Google AdSense（配信型）', 'admax' => '忍者AdMax（配信型）', 'a8' => 'A8.net（成果報酬型）', 'moshimo' => 'もしもアフィリエイト（成果報酬型）', 'afb' => 'afb（成果報酬型）', 'valuecommerce' => 'バリューコマース（成果報酬型）', 'generic' => '汎用公式広告タグ'];
}
function shiki_ads_positions(): array {
    return ['above' => '記事上', 'before_h2' => 'H2の前（最上位ブロック境界のみ）', 'after_h2' => 'H2の後（最上位ブロック境界のみ）', 'middle' => '本文中（段落・ブロックの後）', 'below' => '記事下', 'sidebar' => 'サイドバー', 'footer' => 'フッター', 'manual' => '広告ブロック専用'];
}
function shiki_ads_ids($value): array {
    if (!is_array($value)) { $value = is_scalar($value) ? preg_split('/[\s,]+/', (string) $value) : []; }
    return array_values(array_unique(array_filter(array_map('absint', array_filter($value, 'is_scalar')))));
}
function shiki_ads_choice($value, array $choices, string $fallback): string {
    return is_string($value) && isset($choices[$value]) ? $value : $fallback;
}
function shiki_ads_pub($value): string {
    if (!is_scalar($value)) { return ''; }
    $value = trim((string) $value);
    return preg_match('/^ca-pub-[0-9]{16}$/D', $value) ? $value : '';
}
/** Import supported official responsive display code; never execute arbitrary imported code. */
function shiki_ads_parse_adsense(string $code): array {
    $out = ['publisher' => '', 'ad_slot' => ''];
    if (preg_match('/\bdata-ad-client\s*=\s*[\x22\x27](ca-pub-[0-9]{16})[\x22\x27]/i', $code, $m)) { $out['publisher'] = $m[1]; }
    elseif (preg_match('/\bclient=(ca-pub-[0-9]{16})(?:[&\x22\x27\s]|$)/i', $code, $m)) { $out['publisher'] = $m[1]; }
    if (preg_match('/\bdata-ad-slot\s*=\s*[\x22\x27]([0-9]{1,20})[\x22\x27]/i', $code, $m)) { $out['ad_slot'] = $m[1]; }
    return $out;
}

add_filter('shiki_sanitize_settings', function ($out, $input, $section) {
    if ('ads' !== $section || !current_user_can('manage_options')) { return $out; }
    $out['ads_mode'] = shiki_ads_choice($input['ads_mode'] ?? 'test', array_fill_keys(['off', 'test', 'production'], true), 'test');
    $out['ads_live_approved'] = !empty($input['ads_live_approved']);
    $out['ads_auto'] = !empty($input['ads_auto']);
    $import = shiki_ads_parse_adsense(is_string($input['ads_adsense_import'] ?? null) ? $input['ads_adsense_import'] : '');
    $out['ads_publisher'] = shiki_ads_pub($input['ads_publisher'] ?? '') ?: $import['publisher'];
    $out['ads_adsense_import'] = ''; // Only IDs are retained; never retain or execute pasted global loader code.
    $out['ads_limit'] = max(1, min(12, absint($input['ads_limit'] ?? 5)));
    $out['ads_cmp_id'] = max(0, min(65535, absint($input['ads_cmp_id'] ?? 0)));
    $out['ads_external_reviewed'] = !empty($input['ads_external_reviewed']);
    $can_code = current_user_can('unfiltered_html');
    $cmp_code = isset($input['ads_cmp_code']) && is_string($input['ads_cmp_code']) ? substr($input['ads_cmp_code'], 0, 100000) : '';
    if ($can_code) {
        $out['ads_cmp_code'] = $cmp_code;
        $out['ads_cmp_trusted'] = !empty($input['ads_cmp_trusted']) && '' !== trim($cmp_code);
    } else {
        $out['ads_cmp_code'] = shiki_option('ads_cmp_code', '');
        $out['ads_cmp_trusted'] = (bool) shiki_option('ads_cmp_trusted', false);
    }
    $out['ads_cmp_enabled'] = !empty($input['ads_cmp_enabled']) && !empty($out['ads_cmp_trusted']);
    $previous = shiki_option('ads_slots', []); $slots = [];
    foreach (array_values(array_slice(is_array($input['ads_slots'] ?? null) ? $input['ads_slots'] : [], 0, 24)) as $index => $row) {
        if (!is_array($row) || !empty($row['delete'])) { continue; }
        $id = sanitize_key($row['id'] ?? ('slot-' . ($index + 1)));
        if ('' === $id || isset($slots[$id])) { continue; }
        $service = shiki_ads_choice($row['service'] ?? '', shiki_ads_services(), 'generic');
        $old = []; foreach ((array) $previous as $previous_row) { if (($previous_row['id'] ?? '') === $id) { $old = $previous_row; break; } }
        $code = $can_code && is_string($row['code'] ?? null) ? substr($row['code'], 0, 100000) : ($old['code'] ?? '');
        $parsed = 'adsense' === $service ? shiki_ads_parse_adsense($code) : [];
        $publisher = shiki_ads_pub($row['publisher'] ?? '') ?: ($parsed['publisher'] ?? '');
        $ad_slot_input = is_scalar($row['ad_slot'] ?? '') ? (string) ($row['ad_slot'] ?? '') : '';
        $ad_slot = preg_match('/^[0-9]{1,20}$/D', $ad_slot_input) ? $ad_slot_input : ($parsed['ad_slot'] ?? '');
        $loaders = [];
        $loader_input = $row['shared_loaders'] ?? [];
        foreach (is_array($loader_input) ? $loader_input : preg_split('/\s+/', (string) $loader_input) as $url) {
            if (!is_string($url)) { continue; }
            if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL)) { $loaders[] = esc_url_raw($url, ['https']); }
        }
        $slots[$id] = [
            'id' => $id, 'name' => sanitize_text_field($row['name'] ?? $id),
            'service' => $service, 'state' => shiki_ads_choice($row['state'] ?? '', array_fill_keys(['off','test','production'], true), 'test'),
            'position' => shiki_ads_choice($row['position'] ?? '', shiki_ads_positions(), 'manual'),
            'device' => shiki_ads_choice($row['device'] ?? '', array_fill_keys(['all','mobile','desktop'], true), 'all'),
            'post_types' => array_values(array_intersect(['post','page'], (array) ($row['post_types'] ?? []))),
            'exclude_ids' => shiki_ads_ids($row['exclude_ids'] ?? []), 'exclude_categories' => shiki_ads_ids($row['exclude_categories'] ?? []),
            'interval' => max(1, min(20, absint($row['interval'] ?? 3))), 'limit' => max(1, min(6, absint($row['limit'] ?? 1))),
            'height' => max(80, min(600, absint($row['height'] ?? 250))),
            'dummy' => shiki_ads_choice($row['dummy'] ?? '', array_fill_keys(['ready','delay','no-fill','failure'], true), 'ready'),
            'publisher' => $publisher, 'ad_slot' => $ad_slot, 'code' => $code,
            'trusted' => $can_code ? !empty($row['trusted']) : !empty($old['trusted']),
            'vendors' => shiki_ads_ids($row['vendors'] ?? ('adsense' === $service ? [755] : [])),
            'purposes' => shiki_ads_ids($row['purposes'] ?? [1,3,4]), 'shared_loaders' => array_values(array_unique($loaders)),
        ];
    }
    $out['ads_slots'] = array_values($slots);
    if (!$out['ads_live_approved']) { $out['ads_mode'] = 'production' === $out['ads_mode'] ? 'test' : $out['ads_mode']; }
    return $out;
}, 10, 3);

function shiki_ads_environment_allowed(): bool {
    return 'production' === wp_get_environment_type() && (bool) shiki_option('ads_live_approved', false) && (bool) shiki_option('ads_external_reviewed', false);
}
function shiki_ads_page_allowed(array $slot): bool {
    if (is_admin() || is_feed() || is_search() || is_404() || (function_exists('is_customize_preview') && is_customize_preview())) { return false; }
    if (is_singular()) {
        $post = get_queried_object();
        if (!$post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password || post_password_required($post)) { return false; }
        if ('1' === (string) get_post_meta($post->ID, '_shiki_exclude', true)) { return false; }
        if (!in_array($post->post_type, (array) ($slot['post_types'] ?? ['post']), true) || in_array($post->ID, (array) ($slot['exclude_ids'] ?? []), true)) { return false; }
        if (!empty($slot['exclude_categories']) && has_category((array) $slot['exclude_categories'], $post)) { return false; }
    } elseif (is_front_page() || is_home()) {
        $public = shiki_public_posts(['posts_per_page' => 1, 'fields' => 'ids']); if (!$public->have_posts()) { return false; }
    } else { return false; }
    return true;
}
function shiki_ads_render_slot(array $slot, bool $count = true): string {
    static $counts = []; static $total = 0; static $instance = 0;
    $mode = shiki_option('ads_mode', 'test');
    if ('off' === $mode || 'off' === ($slot['state'] ?? 'off') || !shiki_ads_page_allowed($slot)) { return ''; }
    $id = (string) ($slot['id'] ?? '');
    if ($count && (($counts[$id] ?? 0) >= ($slot['limit'] ?? 1) || $total >= shiki_option('ads_limit', 5))) { return ''; }
    if ($count) { $counts[$id] = ($counts[$id] ?? 0) + 1; $total++; }
    $production = 'production' === $mode && 'production' === ($slot['state'] ?? '') && shiki_ads_environment_allowed();
    $payload = ['service' => $slot['service'] ?? 'generic', 'device' => $slot['device'] ?? 'all', 'mode' => $production ? 'production' : 'test', 'dummy' => $slot['dummy'] ?? 'ready'];
    if ($production) {
        $payload += ['publisher' => ($slot['publisher'] ?? '') ?: shiki_option('ads_publisher', ''), 'adSlot' => $slot['ad_slot'] ?? '', 'vendors' => $slot['vendors'] ?? [], 'purposes' => $slot['purposes'] ?? [1,3,4], 'sharedLoaders' => $slot['shared_loaders'] ?? []];
        if ('adsense' !== $payload['service']) {
            if (empty($slot['trusted'])) { return ''; }
            $payload['code'] = $slot['code'] ?? '';
        }
    }
    $instance++; wp_enqueue_style('shiki-ads'); wp_enqueue_script('shiki-ads');
    return '<aside class="shiki-ad" id="shiki-ad-' . $instance . '" aria-label="広告" data-shiki-ad="' . esc_attr(base64_encode(wp_json_encode($payload))) . '" style="--shiki-ad-height:' . absint($slot['height'] ?? 250) . 'px"><span class="shiki-ad__label">広告 / PR</span><div class="shiki-ad__body"><span class="shiki-ad__status">' . ($production ? '広告を準備中' : 'TEST 広告枠') . '</span></div></aside>';
}
function shiki_ad_html(string $position): string {
    $html = '';
    foreach ((array) shiki_option('ads_slots', []) as $slot) {
        if (is_array($slot) && ($slot['position'] ?? '') === $position) { $html .= shiki_ads_render_slot($slot); }
    }
    return $html;
}
function shiki_ad(string $position): void { echo shiki_ad_html($position); /* HTML escaped at source. */ }
function shiki_ads_named_slot(string $id): string {
    foreach ((array) shiki_option('ads_slots', []) as $slot) { if (($slot['id'] ?? '') === $id) { return shiki_ads_render_slot($slot); } }
    return '';
}

/** Insert only between top-level blocks; classic HTML is parsed before insertion. */
add_filter('the_content', function ($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query() || is_admin() || is_feed() || is_preview()) { return $content; }
    $slots = array_filter((array) shiki_option('ads_slots', []), function ($slot) { return is_array($slot) && in_array($slot['position'] ?? '', ['middle','before_h2','after_h2'], true); });
    $body = $content;
    if ($slots && has_blocks($content)) {
        $parts = parse_blocks($content); $body = ''; $paragraphs = 0; $headings = 0;
        foreach ($parts as $part) {
            $h2 = 'core/heading' === $part['blockName'] && 2 === (int) ($part['attrs']['level'] ?? 2);
            if ($h2) { $headings++; }
            foreach ($slots as $slot) {
                if ($h2 && 'before_h2' === $slot['position'] && 0 === $headings % $slot['interval']) { $body .= shiki_ads_render_slot($slot); }
            }
            $body .= serialize_block($part);
            if (in_array($part['blockName'], ['core/paragraph','core/image','core/table'], true)) { $paragraphs++; }
            foreach ($slots as $slot) {
                if (($h2 && 'after_h2' === $slot['position'] && 0 === $headings % $slot['interval']) || ('middle' === $slot['position'] && in_array($part['blockName'], ['core/paragraph','core/image','core/table'], true) && 0 === $paragraphs % $slot['interval'])) { $body .= shiki_ads_render_slot($slot); }
            }
        }
    } elseif ($slots && class_exists('DOMDocument') && !str_contains($content, '<!-- wp:')) {
        $doc = new DOMDocument(); $prior = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div id="shiki-ad-content-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prior);
        $root = $doc->getElementById('shiki-ad-content-root');
        if ($loaded && $root) {
            $body = ''; $paragraphs = 0; $headings = 0;
            foreach ($root->childNodes as $node) {
                $h2 = $node instanceof DOMElement && 'h2' === strtolower($node->tagName); $para = $node instanceof DOMElement && 'p' === strtolower($node->tagName);
                if ($h2) { $headings++; }
                foreach ($slots as $slot) { if ($h2 && 'before_h2' === $slot['position'] && 0 === $headings % $slot['interval']) { $body .= shiki_ads_render_slot($slot); } }
                $body .= $doc->saveHTML($node);
                if ($para) { $paragraphs++; }
                foreach ($slots as $slot) { if (($h2 && 'after_h2' === $slot['position'] && 0 === $headings % $slot['interval']) || ($para && 'middle' === $slot['position'] && 0 === $paragraphs % $slot['interval'])) { $body .= shiki_ads_render_slot($slot); } }
            }
        }
    }
    return shiki_ad_html('above') . $body . shiki_ad_html('below');
}, 8);

add_action('wp_enqueue_scripts', function () {
    wp_register_style('shiki-ads', get_template_directory_uri() . '/assets/ads.css', [], SHIKI_VERSION);
    wp_register_script('shiki-ads', get_template_directory_uri() . '/assets/ads.js', [], SHIKI_VERSION, true);
    $live = 'production' === shiki_option('ads_mode', 'test') && shiki_ads_environment_allowed() && shiki_ads_page_allowed(['post_types' => ['post','page']]);
    $cfg = ['live' => $live, 'cmpId' => absint(shiki_option('ads_cmp_id', 0)), 'auto' => $live && (bool) shiki_option('ads_auto', false), 'publisher' => $live ? shiki_option('ads_publisher', '') : '', 'cmpCode' => ''];
    if ($live && shiki_option('ads_cmp_enabled', false) && shiki_option('ads_cmp_trusted', false)) { $cfg['cmpCode'] = base64_encode((string) shiki_option('ads_cmp_code', '')); }
    wp_add_inline_script('shiki-ads', 'window.shikiAdsConfig=' . wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    if ($live && (shiki_option('ads_auto', false) || '' !== $cfg['cmpCode'])) { wp_enqueue_script('shiki-ads'); }
    if ('off' !== shiki_option('ads_mode', 'test')) {
        foreach ((array) shiki_option('ads_slots', []) as $slot) {
            if (is_array($slot) && 'off' !== ($slot['state'] ?? 'off') && shiki_ads_page_allowed($slot)) { wp_enqueue_style('shiki-ads'); wp_enqueue_script('shiki-ads'); break; }
        }
    }
});

add_action('init', function () {
    wp_register_script('shiki-ad-editor', get_template_directory_uri() . '/assets/ad-editor.js', ['wp-blocks','wp-element','wp-components','wp-block-editor'], SHIKI_VERSION, true);
    register_block_type('shikipress/ad-slot', ['api_version' => 3, 'editor_script' => 'shiki-ad-editor', 'attributes' => ['slot' => ['type' => 'string', 'default' => '']], 'render_callback' => function ($attrs) { return shiki_ads_named_slot(sanitize_key($attrs['slot'] ?? '')); }]);
});
add_action('enqueue_block_editor_assets', function () {
    $slots = []; foreach ((array) shiki_option('ads_slots', []) as $slot) { $slots[] = ['value' => $slot['id'], 'label' => $slot['name']]; }
    wp_add_inline_script('shiki-ad-editor', 'window.shikiAdSlots=' . wp_json_encode($slots, JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
});

/** Local read only diagnostics: never writes or overwrites ads.txt and never fetches arbitrary URLs. */
function shiki_ads_txt_diagnostic(): array {
    $path = ABSPATH . 'ads.txt';
    if (!is_file($path)) { return ['status' => 'unverified', 'message' => 'WordPress設置先にads.txtがありません。ドメイン直下が別の場合はエックスサーバーで確認してください。']; }
    if (!is_readable($path) || filesize($path) > 1048576) { return ['status' => 'unverified', 'message' => 'ads.txtを読み取れないか、1MBの確認上限を超えています。']; }
    $body = file_get_contents($path); $pub = str_replace('ca-', '', shiki_option('ads_publisher', ''));
    return ['status' => '' !== $pub && false !== stripos($body, 'google.com, ' . $pub) ? 'detected' : 'review', 'message' => '' !== $pub && preg_match('/^\s*google\.com\s*,\s*' . preg_quote($pub, '/') . '\s*,/mi', $body) ? '設定したGoogle販売者IDの行を検出（認証・ドメイン到達性は別確認）。既存ファイルは変更していません。' : '既存ads.txtを検出。販売者IDと公式発行行を手動で照合してください。既存ファイルは変更していません。'];
}

function shiki_ads_admin_select(string $name, string $current, array $choices): void {
    echo '<select name="' . esc_attr($name) . '">';
    foreach ($choices as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($current, $key, false) . '>' . esc_html($label) . '</option>'; }
    echo '</select>';
}
function shiki_ads_admin_field(string $label, string $name, $value, string $type = 'text'): void {
    echo '<label class="shiki-ads-field">' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_array($value) ? implode(',', $value) : (string) $value) . '"></label>';
}
function shiki_ads_admin_check(string $label, string $name, bool $checked): void {
    echo '<label class="shiki-ads-check"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
}
add_action('shiki_admin_tab_ads', function () {
    if (!current_user_can('manage_options')) { return; }
    echo '<style>.shiki-ads-field{display:grid;gap:5px;margin:12px 0;max-width:660px}.shiki-ads-field input,.shiki-ads-field textarea{width:100%;max-width:100%}.shiki-ads-check{display:block;margin:12px 0}.shiki-ads-slot{border:1px solid #ccd0d4;padding:16px;margin:16px 0}.shiki-ads-slot summary{cursor:pointer;font-weight:600}.shiki-ads-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px}.shiki-ads-slot textarea{font-family:monospace;min-height:140px;max-width:100%;width:100%}</style>';
    echo '<h2>広告マネージャー</h2><p>初期状態はテスト。ダミーは広告・計測・アフィリエイト通信を行いません。本番にはサービス契約、公式タグ、配信条件、認定CMPの設定と確認が必要です。</p>';
    echo '<p>下部4タブを優先するため、アンカー・オーバーレイ広告はサービス側の公式設定で無効を推奨します。テーマは広告iframeの移動や非表示を行いません。</p>';
    shiki_settings_form_start('ads');
    echo '<h3>基本設定</h3><label>全体の状態 '; shiki_ads_admin_select('shikipress_settings[ads_mode]', shiki_option('ads_mode', 'test'), ['off'=>'無効','test'=>'テスト（通信なし）','production'=>'本番（下記の承認・条件が必要）']); echo '</label>';
    shiki_ads_admin_field('1ページの広告上限（1〜12）', 'shikipress_settings[ads_limit]', shiki_option('ads_limit', 5), 'number');
    shiki_ads_admin_field('AdSenseパブリッシャーID（ca-pub-16桁）', 'shikipress_settings[ads_publisher]', shiki_option('ads_publisher', ''));
    echo '<label class="shiki-ads-field">AdSense公式コードからパブリッシャーIDを取り込む（任意・ID欄が空の場合）<textarea name="shikipress_settings[ads_adsense_import]" rows="3" placeholder="AdSenseが発行した公式コードを貼り付け"></textarea></label><p>ここではIDだけを取り込み、貼り付けコード自体は保存・実行しません。手動ユニットは下の枠別コード欄へ登録してください。</p>';
    shiki_ads_admin_check('本番広告・外部通信を許可する（開発/ステージング環境では無効）', 'shikipress_settings[ads_live_approved]', (bool) shiki_option('ads_live_approved'));
    shiki_ads_admin_check('配信地域・各社規約・必要な同意と計測を確認した', 'shikipress_settings[ads_external_reviewed]', (bool) shiki_option('ads_external_reviewed'));
    shiki_ads_admin_check('AdSense自動広告用の読み込みを許可（配信配置はAdSense管理画面で設定）', 'shikipress_settings[ads_auto]', (bool) shiki_option('ads_auto'));
    echo '<p>手動枠と自動広告は同じ公式ローダーを共用します。手動枠を使う場合もAdSense側で自動広告・アンカーの設定を確認してください。</p>';
    echo '<details><summary>詳細設定：認定CMPとの連携</summary><p>Googleの「プライバシーとメッセージ」または認定TCF CMPが発行した専用CMPタグを登録します。広告タグをCMP欄へ入れないでください。テーマは独自の同意ボタンを生成しません。__tcfapiの有効な応答、登録CMP ID、目的・ベンダー同意を確認できるまで広告を保留します。米国州法などTCF以外の要件は配信地域に合うCMP側で設定し、上の確認を行ってください。</p>';
    shiki_ads_admin_field('認定リストで確認したTCF CMP ID', 'shikipress_settings[ads_cmp_id]', shiki_option('ads_cmp_id', 0), 'number');
    if (current_user_can('unfiltered_html')) {
        echo '<label class="shiki-ads-field">認定CMP公式コード（文字列として保存）<textarea name="shikipress_settings[ads_cmp_code]" rows="6">' . esc_textarea(shiki_option('ads_cmp_code', '')) . '</textarea></label>';
        shiki_ads_admin_check('この公式CMPコードの外部実行を許可する', 'shikipress_settings[ads_cmp_trusted]', (bool) shiki_option('ads_cmp_trusted'));
    } else { echo '<p>コード編集にはunfiltered_html権限が必要です（マルチサイトでは通常スーパー管理者）。</p>'; }
    shiki_ads_admin_check('本番時にCMP専用コードを読み込む', 'shikipress_settings[ads_cmp_enabled]', (bool) shiki_option('ads_cmp_enabled'));
    echo '<p><a href="https://support.google.com/adsense/answer/13554116?hl=ja" target="_blank" rel="noopener noreferrer">Google認定CMP・地域要件</a> / <a href="https://developers.google.com/funding-choices/fc-api-docs?hl=ja" target="_blank" rel="noopener noreferrer">Google公式API</a></p></details><h3>広告枠</h3>';
    $slots = (array) shiki_option('ads_slots', []);
    $slots[] = ['id' => 'slot-' . (count($slots) + 1), 'name' => '新しい広告枠', 'service' => 'adsense', 'state' => 'off', 'position' => 'below', 'device' => 'all', 'post_types' => ['post'], 'interval' => 3, 'limit' => 1, 'height' => 250, 'vendors' => [755], 'purposes' => [1,3,4]];
    foreach ($slots as $index => $slot) {
        $prefix = 'shikipress_settings[ads_slots][' . $index . ']';
        echo '<details class="shiki-ads-slot"><summary>' . esc_html($slot['name'] ?? $slot['id']) . '</summary><div class="shiki-ads-grid">';
        shiki_ads_admin_field('枠ID（半角英数・ハイフン）', $prefix . '[id]', $slot['id']);
        shiki_ads_admin_field('管理名', $prefix . '[name]', $slot['name'] ?? '');
        echo '<label>サービス '; shiki_ads_admin_select($prefix . '[service]', $slot['service'] ?? 'adsense', shiki_ads_services()); echo '</label>';
        echo '<label>状態 '; shiki_ads_admin_select($prefix . '[state]', $slot['state'] ?? 'off', ['off'=>'無効','test'=>'テスト','production'=>'本番']); echo '</label>';
        echo '<label>配置 '; shiki_ads_admin_select($prefix . '[position]', $slot['position'] ?? 'below', shiki_ads_positions()); echo '</label>';
        echo '<label>端末 '; shiki_ads_admin_select($prefix . '[device]', $slot['device'] ?? 'all', ['all'=>'全端末','mobile'=>'767px以下','desktop'=>'768px以上']); echo '</label>';
        echo '<label>ダミー動作 '; shiki_ads_admin_select($prefix . '[dummy]', $slot['dummy'] ?? 'ready', ['ready'=>'通常','delay'=>'2秒遅延','no-fill'=>'未配信','failure'=>'読み込み失敗']); echo '</label>';
        shiki_ads_admin_field('挿入間隔（段落/H2数）', $prefix . '[interval]', $slot['interval'] ?? 3, 'number');
        shiki_ads_admin_field('同一ページ内の枠上限', $prefix . '[limit]', $slot['limit'] ?? 1, 'number');
        shiki_ads_admin_field('確保する高さ（80〜600px）', $prefix . '[height]', $slot['height'] ?? 250, 'number');
        shiki_ads_admin_field('除外ページ/投稿ID（カンマ区切り）', $prefix . '[exclude_ids]', $slot['exclude_ids'] ?? []);
        shiki_ads_admin_field('除外カテゴリーID（カンマ区切り）', $prefix . '[exclude_categories]', $slot['exclude_categories'] ?? []);
        shiki_ads_admin_field('AdSense枠別パブリッシャーID（省略可）', $prefix . '[publisher]', $slot['publisher'] ?? '');
        shiki_ads_admin_field('AdSense手動ユニットID', $prefix . '[ad_slot]', $slot['ad_slot'] ?? '');
        shiki_ads_admin_field('TCFベンダーID（Google=755。他社はCMPで確認）', $prefix . '[vendors]', $slot['vendors'] ?? []);
        shiki_ads_admin_field('必要なTCF同意目的ID', $prefix . '[purposes]', $slot['purposes'] ?? [1,3,4]);
        echo '</div>';
        // Explicit values avoid ambiguous checkbox values for a list of post types.
        echo '<label class="shiki-ads-field">投稿種別<select multiple name="' . esc_attr($prefix . '[post_types][]') . '"><option value="post"' . selected(in_array('post', $slot['post_types'] ?? ['post'], true), true, false) . '>投稿</option><option value="page"' . selected(in_array('page', $slot['post_types'] ?? [], true), true, false) . '>固定ページ</option></select></label>';
        if (current_user_can('unfiltered_html')) {
            echo '<label class="shiki-ads-field">公式コード（AdSenseはレスポンシブ・ディスプレイのIDのみ取り込み。他社は公式HTML/非同期タグ）<textarea name="' . esc_attr($prefix . '[code]') . '" rows="6">' . esc_textarea($slot['code'] ?? '') . '</textarea></label>';
            shiki_ads_admin_check('この枠の外部HTML・JavaScript実行を許可（AdSense以外）', $prefix . '[trusted]', !empty($slot['trusted']));
        }
        shiki_ads_admin_field('重複を防ぐ共通ローダーの完全HTTPS URL（空白区切り・公式仕様で確認）', $prefix . '[shared_loaders]', implode(' ', $slot['shared_loaders'] ?? []));
        shiki_ads_admin_check('この枠を削除', $prefix . '[delete]', false);
        echo '<p>枠ごとの外部scriptは削除しません。共通ローダーだけ指定してください。document.write依存の同期タグは未対応です。コードは管理画面では実行されません。</p></details>';
    }
    shiki_settings_form_end();
    $diag = shiki_ads_txt_diagnostic(); echo '<h3>ads.txtの確認（読み取りのみ）</h3><p>' . esc_html($diag['message']) . '</p><p>必要な場合は各社から発行された行を既存ads.txtへ追記し、ドメイン直下で確認します。テーマはファイルを作成・上書きしません。</p>';
});
