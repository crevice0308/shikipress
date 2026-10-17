<?php
/** Native WordPress/MySQL smoke assertions, invoked by WP-CLI eval-file. */
if (!defined('SHIKI_PLUGIN_SMOKE_SAFETY') || !SHIKI_PLUGIN_SMOKE_SAFETY
    || !defined('WP_CLI') || !WP_CLI || !($GLOBALS['wpdb']->dbh instanceof mysqli)
    || get_stylesheet() !== 'shikipress' || home_url() !== 'http://127.0.0.1:9450') {
    throw new RuntimeException('Requires protected native-MySQL disposable ShikiPress CI.');
}
require_once ABSPATH.'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$phase = $args[0] ?? '';
$slug = $args[1] ?? 'all';
$manifest = [];
foreach (file(getenv('SHIKI_PLUGIN_SMOKE_MANIFEST'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$name, $version, $file] = explode("\t", $line);
    $manifest[$name] = ['version'=>$version, 'file'=>$file];
}
if (!in_array($phase, ['seed','seed-plugin','active','inactive','reactivated','combined','final'], true)
    || ($slug !== 'all' && !isset($manifest[$slug]))) { throw new RuntimeException('Unknown smoke phase/plugin.'); }
function shiki_plugin_ok($value) {
    if (is_wp_error($value)) { throw new RuntimeException($value->get_error_message()); }
    if (!$value) { throw new RuntimeException('Fixture creation failed.'); }
    return $value;
}
function shiki_plugin_post($title, $body, $type = 'page', $category = 0) {
    $post = ['post_title'=>$title, 'post_content'=>$body,
        'post_type'=>$type, 'post_status'=>'publish', 'post_author'=>1];
    if ($type === 'post') {
        if (!$category || !term_exists($category, 'category')) { throw new RuntimeException('An isolated plugin fixture category is required.'); }
        // Assign on insertion: default-category counts are part of the retained WP_Term snapshots.
        $post['post_category'] = [(int)$category];
    }
    return shiki_plugin_ok(wp_insert_post($post, true));
}
function shiki_plugin_content_hash($ids) {
    $data = [];
    foreach ($ids as $id) {
        $post = get_post($id, ARRAY_A);
        if (!is_array($post)) { throw new RuntimeException('Missing plugin fixture post: '.$id); }
        $data[$id] = [$post, get_post_meta($id)];
    }
    return hash('sha256', wp_json_encode($data));
}
function shiki_plugin_tablepress_option($name) {
    $raw = get_option($name);
    if (!is_string($raw)) { throw new RuntimeException('Missing TablePress JSON option: '.$name); }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new RuntimeException('Invalid TablePress option shape: '.$name); }
    return $value;
}
function shiki_plugin_settings($slug, $fixture) {
    switch ($slug) {
        case 'google-site-kit':
            $value = get_option('googlesitekit_admin_bar_menu_enabled', null);
            if ($value === null) { throw new RuntimeException('Missing Site Kit fixture setting.'); }
            return ['admin_bar_menu_enabled'=>(bool)$value];
        case 'contact-form-7': return ['form_content_and_meta'=>shiki_plugin_content_hash([$fixture['form']])];
        case 'wordpress-seo':
            $o = get_option('wpseo_titles', []);
            if (!is_array($o) || !isset($o['title-post'], $o['metadesc-post'])) { throw new RuntimeException('Missing Yoast fixture settings.'); }
            return ['title-post'=>$o['title-post'], 'metadesc-post'=>$o['metadesc-post']];
        case 'wp-super-cache':
            $text = is_file(WP_CONTENT_DIR.'/wp-cache-config.php') ? file_get_contents(WP_CONTENT_DIR.'/wp-cache-config.php') : '';
            preg_match('/^\s*\$cache_max_time\s*=\s*[\x27\x22]?([0-9]+)/m', $text, $m);
            if (!isset($m[1])) { throw new RuntimeException('Missing Super Cache fixture setting.'); }
            return ['cache_max_time'=>(int)$m[1]];
        case 'tablepress':
            // TablePress persists its option arrays as JSON strings, including while inactive.
            $o = shiki_plugin_tablepress_option('tablepress_plugin_options');
            $tables = shiki_plugin_tablepress_option('tablepress_tables');
            if (!isset($o['custom_css'], $o['use_custom_css'], $tables['table_post'][$fixture['table']])
                || !is_string($o['custom_css']) || !is_bool($o['use_custom_css'])
                || !is_int($tables['table_post'][$fixture['table']])) { throw new RuntimeException('Incomplete TablePress fixture settings.'); }
            return ['custom_css'=>$o['custom_css'], 'use_custom_css'=>$o['use_custom_css'],
                'table_post'=>$tables['table_post'][$fixture['table']],
                'table_content_and_meta'=>shiki_plugin_content_hash([$fixture['table_post']])];
        case 'wordfence':
            global $wpdb;
            $table = $fixture['config_table'] ?? '';
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)
                || !in_array($table, $wpdb->get_col('SHOW TABLES'), true)) {
                throw new RuntimeException('Missing or case-mismatched Wordfence fixture table.');
            }
            $value = $wpdb->get_var($wpdb->prepare('SELECT val FROM `'.$table.'` WHERE name = %s', 'deleteTablesOnDeact'));
            if ($value !== '0') { throw new RuntimeException('Wordfence keep-tables setting must be present and equal to 0.'); }
            return ['deleteTablesOnDeact'=>$value];
        case 'updraftplus':
            $value = get_option('updraft_retain', null);
            if ($value === null || !ctype_digit((string)$value)) { throw new RuntimeException('Missing UpdraftPlus fixture setting.'); }
            return ['retained_local_backups'=>(int)$value];
    }
    throw new RuntimeException('Unknown plugin settings.');
}
$f = get_option('shiki_plugin_smoke_fixture');
if ($phase === 'seed') {
    if ($f) { throw new RuntimeException('Refusing to replace existing smoke fixtures.'); }
    $category = shiki_plugin_ok(wp_insert_term('CI plugin smoke fixtures', 'category', ['slug'=>'ci-plugin-smoke-fixtures']));
    $category = (int)$category['term_id'];
    $base = shiki_plugin_post('CI plugin smoke article', '<h2>Plugin smoke heading</h2><p>SHIKI_PLUGIN_SMOKE_BODY</p><h2>Retained section</h2><p>Synthetic local content.</p>', 'post', $category);
    update_post_meta($base, '_shiki_seo_description', 'ShikiPress synthetic plugin smoke description.');
    $sitemap = shiki_plugin_post('CI plugin reader sitemap', '[shiki_sitemap]');
    // Do not alter original theme settings/snapshot. Populate a genuine second slice.
    $per_page = max(10, min(100, absint(shiki_option('seo_sitemap_per_page', 50))));
    $posts = [$base, $sitemap];
    for ($i = 0; $i < $per_page + 2; $i++) {
        $posts[] = shiki_plugin_post('CI plugin sitemap entry '.$i, '<p>Synthetic pagination fixture '.$i.'</p>', 'post', $category);
    }
    $f = ['posts'=>$posts, 'category'=>$category, 'urls'=>['article'=>get_permalink($base), 'sitemap'=>get_permalink($sitemap)],
        'plugins'=>[], 'base_hash'=>shiki_plugin_content_hash($posts), 'sitemap_page_size'=>$per_page];
    update_option('shiki_plugin_smoke_fixture', $f, false);
}
if (!is_array($f)) { throw new RuntimeException('Missing plugin smoke fixtures.'); }
if ($phase === 'seed-plugin') {
    if (isset($f['plugins'][$slug])) { throw new RuntimeException('Plugin fixture already exists.'); }
    $p = ['posts'=>[]];
    switch ($slug) {
        case 'google-site-kit': update_option('googlesitekit_admin_bar_menu_enabled', false); break;
        case 'contact-form-7':
            $form = WPCF7_ContactForm::get_template(['locale'=>'en_US', 'title'=>'CI local-only form']);
            $props = $form->get_properties();
            $props['form'] = '<label>Name[text* your-name]</label><label>Email[email* your-email]</label><label>Message[textarea* your-message]</label>[submit "Local test"]';
            $props['mail']['recipient'] = 'fixture@example.invalid';
            $props['mail']['sender'] = 'CI fixture <fixture@example.invalid>';
            $props['mail']['body'] = '[your-name] [your-email] [your-message]';
            $form->set_properties($props);
            $form->save();
            $p['form'] = shiki_plugin_ok($form->id());
            $p['posts'][] = $p['form'];
            $form = WPCF7_ContactForm::get_instance($p['form']);
            $page = shiki_plugin_post('CI plugin contact form', '<p>SHIKI_PLUGIN_SMOKE_BODY</p>[contact-form-7 id="'.$form->hash().'" title="CI local-only form"]');
            $p['posts'][] = $page;
            $f['urls']['contact-form-7'] = get_permalink($page);
            break;
        case 'wordpress-seo':
            $o = get_option('wpseo_titles', []);
            $o['title-post'] = '%%title%% %%sep%% %%sitename%%';
            $o['metadesc-post'] = 'CI synthetic Yoast description.';
            update_option('wpseo_titles', $o);
            break;
        case 'wp-super-cache':
            // Keep caching disabled; exercise a real persisted setting, not cache performance.
            wp_cache_setting('cache_enabled', false);
            wp_cache_setting('cache_max_time', 1733);
            break;
        case 'tablepress':
            TablePress::$model_options->update(['use_custom_css'=>true, 'custom_css'=>'.tablepress { border-spacing: 0; }']);
            $table = TablePress::$model_table->get_table_template();
            $table['name'] = 'CI plugin smoke table';
            $table['data'] = [['Season','Value'], ['CI_TABLE_ROW_ALPHA','17'], ['CI_TABLE_ROW_BETA','29']];
            $table['visibility'] = ['rows'=>[1,1,1], 'columns'=>[1,1]];
            $table['options']['use_datatables'] = true;
            $p['table'] = shiki_plugin_ok(TablePress::$model_table->add($table));
            $tables = shiki_plugin_tablepress_option('tablepress_tables');
            $p['table_post'] = shiki_plugin_ok($tables['table_post'][$p['table']] ?? null);
            $page = shiki_plugin_post('CI plugin table', '<p>SHIKI_PLUGIN_SMOKE_BODY</p>[table id='.$p['table'].' /]');
            $p['posts'] = [$p['table_post'], $page];
            $f['urls']['tablepress'] = get_permalink($page);
            break;
        case 'wordfence':
            $p['config_table'] = wfDB::networkTable('wfConfig');
            wfConfig::set('deleteTablesOnDeact', '0');
            break;
        case 'updraftplus': update_option('updraft_retain', 3); break;
    }
    $p['settings'] = shiki_plugin_settings($slug, $p);
    $expected_settings = [
        'google-site-kit'=>['admin_bar_menu_enabled'=>false],
        'wordpress-seo'=>['title-post'=>'%%title%% %%sep%% %%sitename%%', 'metadesc-post'=>'CI synthetic Yoast description.'],
        'wp-super-cache'=>['cache_max_time'=>1733],
        'wordfence'=>['deleteTablesOnDeact'=>'0'],
        'updraftplus'=>['retained_local_backups'=>3],
    ];
    if (isset($expected_settings[$slug]) && $p['settings'] !== $expected_settings[$slug]) { throw new RuntimeException('Plugin fixture settings were not persisted: '.$slug); }
    if ($slug === 'tablepress' && ($p['settings']['custom_css'] !== '.tablepress { border-spacing: 0; }'
        || $p['settings']['use_custom_css'] !== true)) { throw new RuntimeException('TablePress fixture settings were not persisted.'); }
    $p['content_hash'] = shiki_plugin_content_hash($p['posts']);
    $f['plugins'][$slug] = $p;
    update_option('shiki_plugin_smoke_fixture', $f, false);
}
$checks = [];
$versions = [];
$expected_active = in_array($phase, ['seed-plugin','active','reactivated'], true) ? [$manifest[$slug]['file']] : ($phase === 'combined' ? array_column($manifest, 'file') : []);
$active = array_values((array)get_option('active_plugins', []));
sort($active); sort($expected_active);
$checks['exact_active_plugins'] = $active === $expected_active;
$checks['base_content_and_meta_retained'] = hash_equals($f['base_hash'], shiki_plugin_content_hash($f['posts']));
foreach ($manifest as $name=>$entry) {
    $data = get_plugin_data(WP_PLUGIN_DIR.'/'.$entry['file'], false, false);
    $versions[$name] = $data['Version'];
    $checks['pinned_version_'.$name] = $data['Version'] === $entry['version'];
}
foreach ($f['plugins'] as $name=>$p) {
    $checks['settings_retained_'.$name] = wp_json_encode($p['settings']) === wp_json_encode(shiki_plugin_settings($name, $p));
    $checks['content_retained_'.$name] = hash_equals($p['content_hash'], shiki_plugin_content_hash($p['posts']));
}
// These calls must terminate in the MU filters, never a transport.
$http_before = $GLOBALS['shiki_smoke_http_blocks'] ?? 0;
$response = wp_remote_get('https://example.invalid/shiki-smoke-safety-probe');
$checks['http_blocked'] = is_wp_error($response) && $response->get_error_code() === 'shiki_smoke_http_blocked'
    && ($GLOBALS['shiki_smoke_http_blocks'] ?? 0) === $http_before + 1;
$mail_before = $GLOBALS['shiki_smoke_mail_blocks'] ?? 0;
$checks['mail_intercepted'] = wp_mail('fixture@example.invalid', 'Disposable smoke probe', 'Synthetic data only.') === true
    && ($GLOBALS['shiki_smoke_mail_blocks'] ?? 0) === $mail_before + 1;
$checks['theme_seo_ownership'] = shiki_seo_managed() === !is_plugin_active('wordpress-seo/wp-seo.php');
$checks['native_mysqli'] = $GLOBALS['wpdb']->dbh instanceof mysqli;
$checks['no_database_error'] = $GLOBALS['wpdb']->last_error === '';
$report = ['phase'=>$phase, 'plugin'=>$slug, 'wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION,
    'database_driver'=>'mysqli', 'database_version'=>$GLOBALS['wpdb']->db_version(), 'theme'=>SHIKI_VERSION,
    'plugin_versions'=>$versions, 'active_plugins'=>$active, 'sitemap_page_size'=>$f['sitemap_page_size'],
    'urls'=>$f['urls'], 'checks'=>$checks, 'pass'=>!in_array(false, $checks, true)];
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
if (!$report['pass']) { throw new RuntimeException('Plugin smoke assertion failed: '.implode(', ', array_keys(array_filter($checks, static fn($ok)=>!$ok)))); }
