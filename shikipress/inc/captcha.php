<?php
/** Narrow adapters for the verified CF7 CAPTCHA integrations; no transport changes. */
defined('ABSPATH') || exit;

/** A loaded class alone is insufficient: the known verifier must be installed. */
function shiki_captcha_cf7_service_active($class, $callback) {
    if (!defined('WPCF7_VERSION') || !class_exists($class) || !is_callable(array($class, 'get_instance')) || false === has_filter('wpcf7_spam', $callback)) {
        return false;
    }
    $service = $class::get_instance();
    return is_object($service) && is_callable(array($service, 'is_active')) && (bool) $service->is_active();
}

function shiki_captcha_recaptcha_mode() {
    if (shiki_captcha_cf7_service_active('IQFix_ReCaptcha', 'iqfix_wpcf7_recaptcha_check_with_google')) { return 'v2'; }
    if (shiki_captcha_cf7_service_active('WPCF7_RECAPTCHA', 'wpcf7_recaptcha_verify_response')) { return 'v3'; }
    return '';
}

function shiki_captcha_native_turnstile_active() {
    return shiki_captcha_cf7_service_active('WPCF7_Turnstile', 'wpcf7_turnstile_verify_response');
}

/** Provider success is a JSON boolean. v3 additionally requires a numeric score. */
function shiki_captcha_response_valid($response, $mode) {
    if (!in_array($mode, array('v2', 'v3', 'turnstile'), true) || !is_array($response) || ($response['success'] ?? null) !== true) {
        return false;
    }
    if ($mode === 'v3') {
        $score = $response['score'] ?? null;
        return (is_int($score) || is_float($score)) && is_finite((float) $score) && $score >= 0 && $score <= 1;
    }
    return true;
}

/** Preserve an upstream rejection, including a valid response below its threshold. */
function shiki_captcha_recaptcha_result($is_human, $response) {
    $mode = shiki_captcha_recaptcha_mode();
    if ($mode === '' || !$is_human) { return $is_human; }
    return $is_human === true && shiki_captcha_response_valid($response, $mode);
}
add_filter('wpcf7_recaptcha_verify_response', 'shiki_captcha_recaptcha_result', PHP_INT_MAX, 2);

function shiki_captcha_token_valid($token, $mode) {
    if (!is_string($token) || trim($token) === '') { return false; }
    // Turnstile documents a 2048-character token limit; no guessed Google limit.
    return $mode !== 'turnstile' || strlen($token) <= 2048;
}

function shiki_captcha_reject($submission) {
    if ($submission instanceof WPCF7_Submission) {
        $submission->add_spam_log(array('agent' => 'shikipress-captcha', 'reason' => 'The CAPTCHA verification response has an invalid format.'));
    }
    return true;
}

function shiki_captcha_v2_protects_submission($submission) {
    if (!($submission instanceof WPCF7_Submission)) { return false; }
    $form = $submission->get_contact_form();
    return $form instanceof WPCF7_ContactForm && (bool) $form->scan_form_tags(array('type' => 'recaptcha'));
}

/** Reject malformed posted types before the known verifiers send them upstream. */
function shiki_captcha_token_guard($spam, $submission) {
    if ($spam) { return $spam; }
    $mode = shiki_captcha_recaptcha_mode();
    if ($mode === 'v3' && !shiki_captcha_token_valid($_POST['_wpcf7_recaptcha_response'] ?? null, $mode)) {
        return shiki_captcha_reject($submission);
    }
    // v2 protects only forms containing its form-tag. Its own verifier handles a
    // missing token; a present non-string is invalid regardless of form layout.
    if ($mode === 'v2' && shiki_captcha_v2_protects_submission($submission) && array_key_exists('g-recaptcha-response', $_POST) && !shiki_captcha_token_valid($_POST['g-recaptcha-response'], $mode)) {
        return shiki_captcha_reject($submission);
    }
    if (shiki_captcha_native_turnstile_active() && !shiki_captcha_token_valid($_POST['_wpcf7_turnstile_response'] ?? null, 'turnstile')) {
        return shiki_captcha_reject($submission);
    }
    return $spam;
}
add_filter('wpcf7_spam', 'shiki_captcha_token_guard', 8, 2);

/** Native CF7 stores the verified response in the submission's public pocket. */
function shiki_captcha_turnstile_result($spam, $submission) {
    if ($spam || !shiki_captcha_native_turnstile_active() || !($submission instanceof WPCF7_Submission)) { return $spam; }
    $verification = $submission->pull('turnstile');
    if (!is_array($verification) || !shiki_captcha_response_valid($verification['response'] ?? null, 'turnstile')) {
        return shiki_captcha_reject($submission);
    }
    return $spam;
}
add_filter('wpcf7_spam', 'shiki_captcha_turnstile_result', PHP_INT_MAX, 2);

/** Only the known Simple Turnstile CF7 validator participates in this adapter. */
function shiki_captcha_simple_cf7_active() {
    return function_exists('cfturnstile_cf7_verify_recaptcha') && false !== has_filter('wpcf7_validate', 'cfturnstile_cf7_verify_recaptcha');
}

/** Request-local observation; it is reset for every CF7 validation pass. */
function shiki_captcha_simple_cf7_observation($operation = 'read', $malformed = false) {
    static $invalid = false;
    if ($operation === 'reset') { $invalid = false; }
    elseif ($operation === 'observe') { $invalid = $invalid || $malformed; }
    return $invalid;
}

function shiki_captcha_simple_cf7_reset($result) {
    shiki_captcha_simple_cf7_observation('reset');
    return $result;
}
add_filter('wpcf7_validate', 'shiki_captcha_simple_cf7_reset', 1, 1);

/** Observe the plugin's normal verification event, without changing its result. */
function shiki_captcha_simple_cf7_observe($response, $results, $form_action) {
    if ($form_action !== 'contact-form-7' || !shiki_captcha_simple_cf7_active()) { return; }
    $properties = is_object($response) ? get_object_vars($response) : array();
    $malformed = !array_key_exists('success', $properties) || !is_bool($properties['success']) || !is_array($results) || !array_key_exists('success', $results) || !is_bool($results['success']);
    if (!$malformed && $results['success'] === true && $properties['success'] !== true) { $malformed = true; }
    shiki_captcha_simple_cf7_observation('observe', $malformed);
}
add_action('cfturnstile_after_check', 'shiki_captcha_simple_cf7_observe', PHP_INT_MAX, 3);

function shiki_captcha_simple_cf7_result($result) {
    if (shiki_captcha_simple_cf7_active() && shiki_captcha_simple_cf7_observation() && $result instanceof WPCF7_Validation) {
        $result->invalidate(array('type' => 'captcha', 'name' => 'cf-turnstile'), '認証結果を確認できませんでした。ページを再読み込みして、もう一度お試しください。');
    }
    // Whitelist/custom skips and configured failover allow paths emit no normal
    // event. With no malformed observation, their explicit policy is preserved.
    return $result;
}
add_filter('wpcf7_validate', 'shiki_captcha_simple_cf7_result', PHP_INT_MAX, 1);

/** Configuration state only: never fetch providers or reveal keys/exemption values. */
function shiki_captcha_diagnostics($rows) {
    $cf7 = defined('WPCF7_VERSION');
    $simple = function_exists('cfturnstile_whitelisted');
    if (!$cf7 && !$simple) { return $rows; }
    $add = static function ($name, $state, $detail) use (&$rows) { $rows[] = compact('name', 'state', 'detail'); };
    $recaptcha = shiki_captcha_recaptcha_mode();
    $native_turnstile = shiki_captcha_native_turnstile_active();
    if ($cf7) {
        $configured = $recaptcha !== '' || $native_turnstile;
        $add('CAPTCHA: Contact Form 7', $configured ? 'unconfirmed' : 'notice', $configured ? '既知の標準連携を設定済み。実ドメインでの認証・拒否・期限切れ・再送は別途確認してください。' : '既知の標準連携は未設定、または別プラグインが担当しています。対象フォームの保護設定を確認してください。');
    }
    if ($simple) {
        $key = get_option('cfturnstile_key');
        $secret = get_option('cfturnstile_secret');
        $configured = is_string($key) && trim($key) !== '' && is_string($secret) && trim($secret) !== '';
        $add('CAPTCHA: Simple Cloudflare Turnstile', $configured ? 'unconfirmed' : 'notice', $configured ? 'キーの設定を検出。保護対象・実際の認証成功・各例外の適切さは別途確認が必要です。' : 'キーが未設定、または設定形式を確認する必要があります。値はこの画面に表示しません。');
        $ip = get_option('cfturnstile_whitelist_ips');
        $agent = get_option('cfturnstile_whitelist_agents');
        $has_ip = !is_string($ip) ? !empty($ip) : trim($ip) !== '';
        $has_agent = !is_string($agent) ? !empty($agent) : trim($agent) !== '';
        $add('CAPTCHA: IPによる免除', $has_ip ? 'notice' : 'ok', $has_ip ? 'IP免除が設定されています。転送ヘッダーを送信者が偽装できない構成が必要です。直接オリジンへの接続制限と信頼するプロキシの設定を確認してください。' : 'IP免除の登録は検出していません。');
        $add('CAPTCHA: User-Agentによる免除', $has_agent ? 'notice' : 'ok', $has_agent ? 'User-Agent免除が設定されています。User-Agentは送信者が変更でき、この条件に一致すると認証が省略されます。' : 'User-Agent免除の登録は検出していません。');
        $failover = (bool) get_option('cfturnstile_failover');
        $fallback = get_option('cfturnstile_failsafe_type', 'allow');
        $allow = $failover && $fallback === 'allow';
        $add('CAPTCHA: 障害時の処理', $allow ? 'notice' : 'unconfirmed', $allow ? '提供元に到達できない場合に送信を許可する設定です。迷惑送信対策と可用性の運用方針を確認してください。' : '障害時の送信許可は検出していません。代替認証やネットワーク障害時の動作は別途確認してください。');
    }
    $simple_all = $simple && !empty(get_option('cfturnstile_cf7_all'));
    if ($cf7 && (int) ($recaptcha !== '') + (int) $native_turnstile + (int) $simple_all > 1) {
        $add('CAPTCHA: フォームの複数連携', 'notice', '同じCF7フォームに複数の認証連携が設定されている可能性があります。意図した構成か、各認証の完了・再送を確認してください。');
    }
    return $rows;
}
add_filter('shiki_diagnostics', 'shiki_captcha_diagnostics');
