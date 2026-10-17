<?php
/** Native WordPress updater regression fixture. Never use on a real website. */
if (!defined('SHIKI_TEST_ENV') || SHIKI_TEST_ENV !== true || !defined('ABSPATH')
    || wp_get_environment_type() !== 'local' || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
    throw new RuntimeException('A marked disposable local WordPress installation is required.');
}
// A normal WP-CLI bootstrap is sufficient. Load the real admin/upgrader APIs
// directly; WP-CLI's synthetic admin context can fail during menu setup.
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);
if (!current_user_can('update_themes') || !class_exists('ShikiPress_Updater')) {
    throw new RuntimeException('The administrator and installed ShikiPress updater must be loaded.');
}
$phase = $shiki_updater_phase ?? ($args[0] ?? 'security');
// WP-CLI eval-file executes inside a method; helpers need this explicit binding.
global $fixture_dir;
$fixture_dir = defined('SHIKI_UPDATER_FIXTURE_DIR') ? SHIKI_UPDATER_FIXTURE_DIR : rtrim((string)getenv('SHIKI_CI_STATE'), '/') . '/updater-fixtures';
if ($fixture_dir === '/updater-fixtures') {
    throw new RuntimeException('An explicit disposable fixture directory is required.');
}
const SHIKI_CI_BASE_VERSION = '1.1.1';
const SHIKI_CI_UPDATE_VERSION = '1.1.2';
const SHIKI_CI_UPDATE_API = 'https://api.github.com/repos/crevice0308/shikipress/releases/latest';
const SHIKI_CI_UPDATE_REPO = 'https://github.com/crevice0308/shikipress';
const SHIKI_CI_UPDATE_CACHE = 'shikipress_github_release_v1';
$GLOBALS['shiki_updater_checks'] = [];
$GLOBALS['shiki_updater_requests'] = [];
$GLOBALS['shiki_updater_mode'] = 'valid';
$GLOBALS['shiki_updater_install_calls'] = 0;
$GLOBALS['shiki_updater_upgrade_attempts'] = [];
function shiki_updater_assert($condition, $label) {
    $GLOBALS['shiki_updater_checks'][$label] = (bool)$condition;
    if (!$condition) { throw new RuntimeException('Updater assertion failed: ' . $label); }
}
function shiki_updater_files($directory) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) { $files[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($files);
    return $files;
}
function shiki_updater_state($write = null) {
    global $fixture_dir;
    $file = $fixture_dir . '/state.json';
    if ($write !== null) {
        shiki_updater_assert(file_put_contents($file, wp_json_encode($write, JSON_PRETTY_PRINT)) !== false, 'write_disposable_fixture_state');
    }
    $state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
    shiki_updater_assert(is_array($state), 'disposable_fixture_state_exists');
    return $state;
}
function shiki_updater_fixture_zip($name, $kind = 'valid') {
    global $fixture_dir;
    $zip = new ZipArchive();
    shiki_updater_assert($zip->open($fixture_dir . '/' . $name . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'create_' . $name);
    $theme = get_theme_root('shikipress') . '/shikipress';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $relative = substr($file->getPathname(), strlen($theme) + 1);
        $bytes = file_get_contents($file->getPathname());
        if ($kind !== 'baseline') {
            if ($relative === 'style.css') {
                $bytes = preg_replace('/^Version:\s*' . preg_quote(SHIKI_CI_BASE_VERSION, '/') . '\s*$/m', 'Version: ' . SHIKI_CI_UPDATE_VERSION, $bytes, 1, $count);
                shiki_updater_assert($count === 1, 'rewrite_only_disposable_header_' . $name);
                if ($kind === 'different-theme') { $bytes = str_replace('Text Domain: shikipress', 'Text Domain: unrelated-theme', $bytes); }
                if ($kind === 'requirements-mismatch') { $bytes = str_replace('Requires PHP: 8.2', 'Requires PHP: 99.0', $bytes); }
            }
            if ($relative === 'functions.php') {
                $bytes = preg_replace("/(define\(\s*['\"]SHIKI_VERSION['\"]\s*,\s*['\"])" . preg_quote(SHIKI_CI_BASE_VERSION, '/') . "(['\"]\s*\)) /x", '${1}' . SHIKI_CI_UPDATE_VERSION . '${2}', $bytes, 1, $count);
                shiki_updater_assert($count === 1, 'rewrite_only_disposable_runtime_' . $name);
            }
        }
        $root = $kind === 'wrong-root' ? 'unrelated-theme' : 'shikipress';
        shiki_updater_assert($zip->addFromString($root . '/' . $relative, $bytes), 'zip_add_' . $name);
    }
    if ($kind !== 'baseline') { $zip->addFromString(($kind === 'wrong-root' ? 'unrelated-theme' : 'shikipress') . '/ci-synthetic-update.txt', 'Disposable synthetic ' . SHIKI_CI_UPDATE_VERSION . ' fixture; never publish.'); }
    if ($kind === 'path-traversal') { $zip->addFromString('shikipress/../../ci-updater-escaped.txt', 'Must never be extracted.'); }
    // Use a fixed ordinary DEFLATE level for reproducible disposable fixtures.
    // Production validation remains unchanged by this test builder.
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        shiki_updater_assert($zip->setCompressionIndex($index, ZipArchive::CM_DEFLATE, 6), 'canonical_deflate_' . $name);
    }
    shiki_updater_assert($zip->close(), 'close_' . $name);
}
function shiki_updater_payload($mode) {
    global $fixture_dir;
    $kind = in_array($mode, ['wrong-root', 'path-traversal', 'different-theme', 'requirements-mismatch'], true) ? $mode : 'valid';
    $zip = $fixture_dir . '/' . $kind . '.zip';
    $version = SHIKI_CI_UPDATE_VERSION;
    $package = SHIKI_CI_UPDATE_REPO . '/releases/download/v' . $version . '/shikipress-' . $version . '.zip';
    $metadata_url = SHIKI_CI_UPDATE_REPO . '/releases/download/v' . $version . '/shikipress-update.json';
    $sha256 = hash_file('sha256', $zip);
    $metadata = ['schema'=>1, 'theme'=>'shikipress', 'version'=>$version, 'requires'=>'6.6', 'requires_php'=>'8.2', 'tested'=>'7.1.3', 'package'=>$package, 'sha256'=>$sha256, 'size'=>filesize($zip)];
    if ($mode === 'low-requirements') { $metadata['requires'] = '1.0'; $metadata['requires_php'] = '5.0'; }
    if ($mode === 'incompatible-wp') { $metadata['requires'] = '99.0'; $metadata['tested'] = '99.0'; }
    if ($mode === 'incompatible-php') { $metadata['requires_php'] = '99.0'; }
    if ($mode === 'different-metadata-theme') { $metadata['theme'] = 'unrelated-theme'; }
    if ($mode === 'wrong-schema') { $metadata['schema'] = 2; }
    if ($mode === 'hash-bad') { $metadata['sha256'] = str_repeat('0', 64); $sha256 = $metadata['sha256']; }
    if ($mode === 'external-package') { $metadata['package'] = 'https://example.invalid/shikipress.zip'; }
    $body = $mode === 'malformed-json' ? '{not json' : wp_json_encode($metadata);
    $release = [
        'tag_name'=>'v' . $version, 'draft'=>false, 'prerelease'=>false,
        'html_url'=>SHIKI_CI_UPDATE_REPO . '/releases/tag/v' . $version,
        'assets'=>[
            ['name'=>'shikipress-update.json', 'state'=>'uploaded', 'size'=>strlen($body), 'digest'=>'sha256:' . hash('sha256', $body), 'browser_download_url'=>$metadata_url],
            ['name'=>'shikipress-' . $version . '.zip', 'state'=>'uploaded', 'size'=>filesize($zip), 'digest'=>'sha256:' . $sha256, 'browser_download_url'=>$package],
        ],
    ];
    if ($mode === 'wrong-tag') { $release['tag_name'] = 'v1.1.3'; }
    if ($mode === 'prerelease') { $release['prerelease'] = true; }
    if ($mode === 'draft') { $release['draft'] = true; }
    return ['release'=>wp_json_encode($release), 'metadata'=>$body, 'metadata_url'=>$metadata_url, 'package'=>$package, 'zip'=>$zip];
}
function shiki_updater_response($body, $code = 200) {
    return ['headers'=>[], 'body'=>$body, 'response'=>['code'=>$code, 'message'=>$code === 200 ? 'OK' : 'Synthetic error'], 'cookies'=>[], 'filename'=>null];
}
function shiki_updater_http($reply, $request, $url) {
    $GLOBALS['shiki_updater_requests'][] = ['url'=>$url, 'stream'=>!empty($request['stream'])];
    if (in_array($url, ['https://api.wordpress.org/themes/update-check/1.1/', 'http://api.wordpress.org/themes/update-check/1.1/'], true)) {
        return shiki_updater_response(wp_json_encode(['themes'=>[], 'no_update'=>[], 'translations'=>[]]));
    }
    if (str_starts_with($url, 'https://api.github.com/') || str_starts_with($url, SHIKI_CI_UPDATE_REPO . '/releases/download/')) {
        shiki_updater_assert(($request['user-agent'] ?? null) === 'ShikiPress-Updater/1' && empty($request['cookies']) && empty($request['body']), 'github_requests_do_not_send_site_identity_or_cookies');
        shiki_updater_assert(($request['redirection'] ?? null) === 0 && !empty($request['sslverify']), 'github_requests_verify_tls_and_handle_redirects_explicitly');
    }
    $mode = $GLOBALS['shiki_updater_mode'];
    if ($url === SHIKI_CI_UPDATE_API && $mode === 'network-failure') {
        return new WP_Error('shiki_ci_http_failure', 'Synthetic offline fixture.');
    }
    $payload = shiki_updater_payload($mode);
    if ($url === SHIKI_CI_UPDATE_API) { return shiki_updater_response($payload['release']); }
    if ($url === $payload['metadata_url']) { return shiki_updater_response($payload['metadata']); }
    if ($url === $payload['package']) {
        shiki_updater_assert(!empty($request['stream']) && !empty($request['filename']), 'package_uses_streaming');
        shiki_updater_assert(copy($payload['zip'], $request['filename']), 'copy_only_synthetic_zip_to_stream');
        $response = shiki_updater_response('');
        $response['filename'] = $request['filename'];
        $response['headers'] = ['content-length'=>(string)filesize($payload['zip'])];
        return $response;
    }
    return new WP_Error('shiki_ci_external_http_blocked', 'All non-fixture HTTP is disabled.');
}
// These callbacks never permit a real HTTP or mail transport, including core callbacks.
add_filter('pre_http_request', 'shiki_updater_http', PHP_INT_MAX, 3);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MAX);
add_filter('upgrader_pre_install', static function ($response) {
    ++$GLOBALS['shiki_updater_install_calls'];
    return $response;
}, PHP_INT_MAX);
function shiki_updater_detect($mode, $clear_release = true) {
    $GLOBALS['shiki_updater_mode'] = $mode;
    if ($clear_release) { delete_site_transient(SHIKI_CI_UPDATE_CACHE); delete_transient(SHIKI_CI_UPDATE_CACHE); }
    delete_site_transient('update_themes');
    wp_clean_themes_cache(false);
    wp_update_themes();
    return get_site_transient('update_themes');
}
function shiki_updater_anchors($html) {
    $processor = new WP_HTML_Tag_Processor($html);
    $anchors = [];
    while ($processor->next_tag('A')) {
        $anchors[] = ['href'=>$processor->get_attribute('href'), 'class'=>$processor->get_attribute('class'), 'id'=>$processor->get_attribute('id'), 'target'=>$processor->get_attribute('target'), 'rel'=>$processor->get_attribute('rel')];
    }
    return $anchors;
}
function shiki_updater_request_count($url) {
    return count(array_filter($GLOBALS['shiki_updater_requests'], static fn($request) => $request['url'] === $url));
}
// Preserve core's bulk skin behavior, except flushing the test reporter's JSON buffer.
class Shiki_CI_Bulk_Theme_Upgrader_Skin extends Bulk_Theme_Upgrader_Skin {
    public function flush_output() {}
}
function shiki_updater_upgrade($bulk) {
    $skin = $bulk ? new Shiki_CI_Bulk_Theme_Upgrader_Skin() : new WP_Ajax_Upgrader_Skin();
    $upgrader = new Theme_Upgrader($skin);
    // The bulk skin may flush its own nested output buffer. Capture the entire render.
    ob_start();
    try {
        $result = $bulk ? $upgrader->bulk_upgrade(['shikipress']) : $upgrader->upgrade('shikipress');
    } finally { ob_end_clean(); }
    $result = $bulk && is_array($result) ? ($result['shikipress'] ?? false) : $result;
    // Core's single upgrade() may return null for a pre-download error; the real
    // Ajax skin preserves that WP_Error, just as wp_ajax_update_theme() expects.
    $errors = !$bulk ? $skin->get_errors() : null;
    $GLOBALS['shiki_updater_upgrade_attempts'][] = ['path'=>$bulk ? 'bulk' : 'single', 'fixture'=>$GLOBALS['shiki_updater_mode'], 'native_result_type'=>get_debug_type($result), 'error_codes'=>is_wp_error($result) ? $result->get_error_codes() : ($errors ? $errors->get_error_codes() : [])];
    return !$bulk && !$result && $errors && $errors->has_errors() ? $errors : $result;
}
function shiki_updater_base_intact($state, $label) {
    shiki_updater_assert(shiki_updater_files(get_theme_root('shikipress') . '/shikipress') === $state['baseline_files'], $label . '_installed_bytes_unchanged');
    shiki_updater_assert(wp_get_theme('shikipress')->get('Version') === SHIKI_CI_BASE_VERSION, $label . '_version_unchanged');
}
try {
    if ($phase === 'setup') {
        shiki_updater_assert(SHIKI_VERSION === SHIKI_CI_BASE_VERSION && wp_get_theme('shikipress')->get('Version') === SHIKI_CI_BASE_VERSION, 'genuine_current_release_installed');
        shiki_updater_assert(!file_exists($fixture_dir), 'unused_fixture_directory');
        shiki_updater_assert(wp_mkdir_p($fixture_dir), 'create_fixture_directory');
        shiki_updater_assert(!file_exists(get_theme_root() . '/shikipress-ci-child'), 'unused_child_theme_slug');
        $state = ['baseline_files'=>shiki_updater_files(get_theme_root('shikipress') . '/shikipress'), 'auto_update_themes'=>get_site_option('auto_update_themes', null)];
        foreach (['baseline', 'valid', 'wrong-root', 'path-traversal', 'different-theme', 'requirements-mismatch'] as $kind) { shiki_updater_fixture_zip($kind, $kind); }
        shiki_updater_state($state);
    } elseif ($phase === 'ui-offer') {
        shiki_updater_state();
        shiki_updater_detect('valid');
    } elseif ($phase === 'security') {
        $state = shiki_updater_state();
        $offer = shiki_updater_detect('valid');
        shiki_updater_assert(($offer->response['shikipress']['new_version'] ?? null) === SHIKI_CI_UPDATE_VERSION, 'native_update_themes_detects_synthetic_stable');
        shiki_updater_assert(($offer->response['shikipress']['package'] ?? null) === shiki_updater_payload('valid')['package'], 'native_offer_exact_official_package');
        shiki_updater_assert(empty($offer->response['shikipress']['autoupdate']), 'update_offer_does_not_opt_into_automatic_updates');
        $prepared_http_before = count($GLOBALS['shiki_updater_requests']);
        $native_html = get_theme_update_available(wp_get_theme('shikipress'));
        $prepared = wp_prepare_themes_for_js(['shikipress'=>wp_get_theme('shikipress')]);
        $prepared = array_values(array_filter($prepared, static fn($theme) => ($theme['id'] ?? null) === 'shikipress'));
        shiki_updater_assert(count($prepared) === 1 && $prepared[0]['hasUpdate'], 'native_prepared_theme_has_verified_update');
        $prepared_anchors = shiki_updater_anchors($prepared[0]['update']);
        $details_url = SHIKI_CI_UPDATE_REPO . '/releases/tag/v' . SHIKI_CI_UPDATE_VERSION;
        $details = array_values(array_filter($prepared_anchors, static fn($anchor) => $anchor['href'] === $details_url));
        shiki_updater_assert(count($details) === 1 && !str_contains((string)$details[0]['class'], 'thickbox') && !str_contains((string)$details[0]['class'], 'open-plugin-details-modal'), 'native_details_link_is_direct_without_modal_classes');
        shiki_updater_assert(!str_contains($prepared[0]['update'], 'TB_iframe'), 'native_details_link_has_no_iframe_query');
        $native_actions = array_values(array_filter(shiki_updater_anchors($native_html), static fn($anchor) => str_contains((string)$anchor['href'], 'action=upgrade-theme')));
        $prepared_actions = array_values(array_filter($prepared_anchors, static fn($anchor) => str_contains((string)$anchor['href'], 'action=upgrade-theme')));
        shiki_updater_assert(count($native_actions) === 1 && str_contains($native_actions[0]['href'], '_wpnonce=') && $native_actions === $prepared_actions, 'native_update_action_and_nonce_unchanged');
        preg_match_all('~<a\b[^>]*>.*?</a>~is', $native_html, $native_tags);
        preg_match_all('~<a\b[^>]*>.*?</a>~is', $prepared[0]['update'], $prepared_tags);
        $native_tags = array_values(array_filter($native_tags[0], static fn($tag) => str_contains($tag, 'action=upgrade-theme')));
        $prepared_tags = array_values(array_filter($prepared_tags[0], static fn($tag) => str_contains($tag, 'action=upgrade-theme')));
        shiki_updater_assert(count($native_tags) === 1 && $native_tags === $prepared_tags, 'native_update_anchor_bytes_unchanged');
        $other_prepared = ['ci-unrelated'=>['id'=>'ci-unrelated', 'hasUpdate'=>true, 'update'=>$native_html]];
        shiki_updater_assert(apply_filters('wp_prepare_themes_for_js', $other_prepared) === $other_prepared, 'native_prepared_unrelated_theme_unchanged');
        shiki_updater_assert(count($GLOBALS['shiki_updater_requests']) === $prepared_http_before, 'native_details_link_filter_makes_no_http_requests');


        $before = shiki_updater_request_count(SHIKI_CI_UPDATE_API);
        shiki_updater_detect('valid', false);
        shiki_updater_assert(shiki_updater_request_count(SHIKI_CI_UPDATE_API) === $before, 'verified_metadata_cache_avoids_repeated_api_reads');
        foreach (['wrong-tag', 'prerelease', 'draft', 'malformed-json', 'wrong-schema', 'low-requirements', 'different-metadata-theme', 'external-package', 'network-failure'] as $mode) {
            $offer = shiki_updater_detect($mode);
            shiki_updater_assert(empty($offer->response['shikipress']), $mode . '_not_offered_by_core');
            shiki_updater_base_intact($state, $mode);
        }
        $GLOBALS['shiki_updater_mode'] = 'valid';
        shiki_updater_assert(!is_wp_error(ShikiPress_Updater::release(true)), 'explicit_fresh_retry_recovers_after_network_failure');
        foreach (['incompatible-wp', 'incompatible-php'] as $mode) {
            foreach ([false, true] as $bulk) {
                $offer = shiki_updater_detect($mode);
                shiki_updater_assert(isset($offer->response['shikipress']), $mode . '_native_requirements_visible');
                $before = $GLOBALS['shiki_updater_install_calls'];
                $packages = shiki_updater_request_count(shiki_updater_payload('valid')['package']);
                $result = shiki_updater_upgrade($bulk);
                $label = ($bulk ? 'bulk_' : 'single_') . $mode;
                shiki_updater_assert(is_wp_error($result), $label . '_core_reports_incompatibility_error');
                shiki_updater_assert($GLOBALS['shiki_updater_install_calls'] === $before, $label . '_rejected_before_install');
                shiki_updater_assert(shiki_updater_request_count(shiki_updater_payload('valid')['package']) === $packages, $label . '_rejected_before_zip_download');
                shiki_updater_base_intact($state, $label);
            }
        }
        foreach ([false, true] as $bulk) {
            $path = $bulk ? 'bulk' : 'single';
            foreach (['wrong-tag', 'prerelease', 'draft', 'malformed-json', 'wrong-schema', 'low-requirements', 'different-metadata-theme', 'external-package', 'network-failure', 'hash-bad', 'wrong-root', 'path-traversal', 'different-theme', 'requirements-mismatch'] as $mode) {
                $offer = shiki_updater_detect('valid');
                shiki_updater_assert(isset($offer->response['shikipress']), $path . '_' . $mode . '_initial_valid_offer');
                $GLOBALS['shiki_updater_mode'] = $mode; // The release is re-fetched at installation time.
                $before = $GLOBALS['shiki_updater_install_calls'];
                $api_before = shiki_updater_request_count(SHIKI_CI_UPDATE_API);
                $result = shiki_updater_upgrade($bulk);
                shiki_updater_assert(is_wp_error($result), $path . '_' . $mode . '_core_reports_error');
                $archive_errors = ['hash-bad'=>'shikipress_package_integrity', 'wrong-root'=>'shikipress_archive_path', 'path-traversal'=>'shikipress_archive_path', 'different-theme'=>'shikipress_archive_headers', 'requirements-mismatch'=>'shikipress_archive_headers'];
                if (isset($archive_errors[$mode])) { shiki_updater_assert(in_array($archive_errors[$mode], $result->get_error_codes(), true), $path . '_' . $mode . '_specific_validation_error'); }
                shiki_updater_assert($GLOBALS['shiki_updater_install_calls'] === $before, $path . '_' . $mode . '_rejected_before_install');
                shiki_updater_assert(shiki_updater_request_count(SHIKI_CI_UPDATE_API) > $api_before, $path . '_' . $mode . '_fresh_release_verification');
                wp_clean_themes_cache(false);
                shiki_updater_base_intact($state, $path . '_' . $mode);
                shiki_updater_assert(!file_exists(WP_CONTENT_DIR . '/ci-updater-escaped.txt') && !file_exists(get_theme_root() . '/ci-updater-escaped.txt'), $path . '_no_path_escape');
            }
        }
        $offer = shiki_updater_detect('valid');
        $offer->response['ci-unrelated'] = ['theme'=>'ci-unrelated', 'new_version'=>'9.9.9', 'package'=>'https://example.invalid/unrelated.zip'];
        $offer->no_update['shikipress'] = ['theme'=>'shikipress'];
        $offer->no_update['ci-unrelated'] = ['theme'=>'ci-unrelated'];
        set_site_transient('update_themes', $offer);
        shiki_updater_assert(wp_get_theme('ci-fallback')->exists(), 'disposable_fallback_available');
        switch_theme('ci-fallback');
        $switched = get_site_transient('update_themes');
        shiki_updater_assert(empty($switched->response['shikipress']) && empty($switched->no_update['shikipress']), 'switch_away_removes_shikipress_stale_offer');
        shiki_updater_assert(($switched->response['ci-unrelated'] ?? null) === $offer->response['ci-unrelated'] && ($switched->no_update['ci-unrelated'] ?? null) === $offer->no_update['ci-unrelated'], 'switch_away_preserves_unrelated_offers');
        switch_theme('shikipress');
        shiki_updater_assert(get_site_option('auto_update_themes', null) === $state['auto_update_themes'], 'automatic_update_preferences_unchanged');
    } elseif ($phase === 'single' || $phase === 'bulk') {
        $state = shiki_updater_state();
        shiki_updater_base_intact($state, $phase . '_starts_at_real_release');
        $offer = shiki_updater_detect('valid');
        shiki_updater_assert(($offer->response['shikipress']['new_version'] ?? null) === SHIKI_CI_UPDATE_VERSION, $phase . '_native_update_offer');
        if ($phase === 'bulk') {
            shiki_updater_assert(get_stylesheet() === 'shikipress-ci-child' && get_template() === 'shikipress', 'child_active_parent_correct');
            shiki_updater_assert(empty($offer->response['shikipress-ci-child']), 'parent_offer_is_not_mapped_to_child');
            shiki_updater_assert(get_theme_mod('ci_child_customization') === ['keep'=>'exact child option'], 'child_customization_survived_activation');
            $state['child_mods'] = get_option('theme_mods_shikipress-ci-child');
            shiki_updater_state($state);
        }
        if ($phase === 'bulk') {
            add_filter('unzip_file_use_ziparchive', '__return_false', PHP_INT_MAX);
            shiki_updater_assert(apply_filters('unzip_file_use_ziparchive', true) === false, 'bulk_exercises_real_pclzip_fallback');
        }
        $result = shiki_updater_upgrade($phase === 'bulk');
        shiki_updater_assert(!is_wp_error($result) && !empty($result), $phase . '_core_upgrade_completed');
        wp_clean_themes_cache(false);
        shiki_updater_assert(wp_get_theme('shikipress')->get('Version') === SHIKI_CI_UPDATE_VERSION, $phase . '_installed_synthetic_version');
        shiki_updater_assert(is_file(get_theme_root('shikipress') . '/shikipress/ci-synthetic-update.txt'), $phase . '_synthetic_package_installed');
    } elseif ($phase === 'verify-single' || $phase === 'verify-bulk') {
        $state = shiki_updater_state();
        shiki_updater_assert(SHIKI_VERSION === SHIKI_CI_UPDATE_VERSION, $phase . '_fresh_runtime_version');
        shiki_updater_assert(wp_get_theme('shikipress')->get('Version') === SHIKI_CI_UPDATE_VERSION, $phase . '_fresh_header_version');
        shiki_updater_assert(get_option('shikipress_version') === SHIKI_CI_UPDATE_VERSION, $phase . '_stored_runtime_version');
        shiki_updater_assert(get_site_option('auto_update_themes', null) === $state['auto_update_themes'], $phase . '_automatic_update_preferences_unchanged');
        if ($phase === 'verify-bulk') {
            shiki_updater_assert(get_stylesheet() === 'shikipress-ci-child' && get_template() === 'shikipress', 'child_remains_active_after_parent_update');
            shiki_updater_assert(shiki_updater_files(get_theme_root() . '/shikipress-ci-child') === $state['child_files'], 'custom_child_files_unchanged');
            shiki_updater_assert(get_option('theme_mods_shikipress-ci-child') === $state['child_mods'], 'custom_child_settings_unchanged');
        }
    } elseif ($phase === 'child-setup') {
        $state = shiki_updater_state();
        $child = get_theme_root() . '/shikipress-ci-child';
        shiki_updater_assert(wp_mkdir_p($child), 'create_disposable_child');
        file_put_contents($child . '/style.css', "/*\nTheme Name: Disposable ShikiPress child\nTemplate: shikipress\nVersion: 0.0.1\nUpdate URI: https://github.com/crevice0308/shikipress\n*/\n.ci-child-custom { color: #123456; }\n");
        file_put_contents($child . '/functions.php', "<?php\n// A custom child theme file that must survive parent updates.\n");
        file_put_contents($child . '/custom-child.txt', 'Keep this exact child customization.');
        wp_clean_themes_cache(false);
        $offer = shiki_updater_detect('valid');
        shiki_updater_assert(isset($offer->response['shikipress']), 'parent_offer_exists_before_child_switch');
        switch_theme('shikipress-ci-child');
        $switched = get_site_transient('update_themes');
        shiki_updater_assert(($switched->response['shikipress'] ?? null) === $offer->response['shikipress'], 'parent_to_child_switch_preserves_parent_offer');
        set_theme_mod('ci_child_customization', ['keep'=>'exact child option']);
        $state['child_files'] = shiki_updater_files($child);
        $state['child_mods'] = get_option('theme_mods_shikipress-ci-child');
        shiki_updater_state($state);
    } elseif ($phase === 'child-deactivate') {
        switch_theme('shikipress');
        shiki_updater_assert(get_stylesheet() === 'shikipress', 'parent_reactivated_after_child_test');
    } elseif ($phase === 'child-cleanup') {
        // A fresh parent bootstrap is essential: deleting child mods in the old
        // child request would invoke core's stale site-logo deletion callback.
        shiki_updater_assert(get_stylesheet() === 'shikipress', 'parent_bootstrapped_before_child_cleanup');
        $deleted = delete_theme('shikipress-ci-child');
        shiki_updater_assert(!is_wp_error($deleted) && $deleted, 'remove_only_disposable_child_theme');
        delete_option('theme_mods_shikipress-ci-child');
    } elseif ($phase === 'verify-restored') {
        $state = shiki_updater_state();
        shiki_updater_base_intact($state, 'restored');
        shiki_updater_assert(SHIKI_VERSION === SHIKI_CI_BASE_VERSION && get_option('shikipress_version') === SHIKI_CI_BASE_VERSION, 'real_release_runtime_restored');
        shiki_updater_assert(get_stylesheet() === 'shikipress', 'real_parent_theme_active');
        shiki_updater_assert(get_site_option('auto_update_themes', null) === $state['auto_update_themes'], 'original_automatic_update_preferences_restored');
    } else { throw new RuntimeException('Unknown updater phase.'); }
    $error = null;
} catch (Throwable $exception) { $error = $exception->getMessage(); }
$report = ['phase'=>$phase, 'wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION, 'database_driver'=>get_class($GLOBALS['wpdb']), 'theme_runtime'=>SHIKI_VERSION, 'updater_sha256'=>hash_file('sha256', get_theme_root('shikipress') . '/shikipress/inc/updater.php'), 'synthetic_version'=>SHIKI_CI_UPDATE_VERSION, 'checks'=>$GLOBALS['shiki_updater_checks'], 'http_requests'=>$GLOBALS['shiki_updater_requests'], 'core_upgrade_attempts'=>$GLOBALS['shiki_updater_upgrade_attempts'], 'pass'=>$error === null];
if ($error !== null) { $report['error'] = $error; }
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($error !== null) { throw new RuntimeException($error); }
