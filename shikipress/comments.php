<?php
if (!defined('ABSPATH') || post_password_required()) { return; }
$shiki_commenter = wp_get_current_commenter();
$shiki_required = (bool) get_option('require_name_email');
$shiki_required_mark = $shiki_required ? ' <span class="required" aria-hidden="true">*</span>' : '';
$shiki_required_attr = $shiki_required ? ' required' : '';
$shiki_fields = [
    'author' => '<p class="comment-form-author"><label for="author">お名前' . $shiki_required_mark . '</label><input id="author" name="author" type="text" value="' . esc_attr($shiki_commenter['comment_author']) . '" autocomplete="name" maxlength="245"' . $shiki_required_attr . '></p>',
    'email' => '<p class="comment-form-email"><label for="email">メールアドレス' . $shiki_required_mark . '</label><input id="email" name="email" type="email" value="' . esc_attr($shiki_commenter['comment_author_email']) . '" autocomplete="email" maxlength="100"' . $shiki_required_attr . '></p>',
    'url' => '<p class="comment-form-url"><label for="url">ウェブサイト（任意）</label><input id="url" name="url" type="url" value="' . esc_attr($shiki_commenter['comment_author_url']) . '" autocomplete="url" maxlength="200"></p>',
];
if (get_option('show_comments_cookies_opt_in')) {
    $shiki_fields['cookies'] = '<p class="comment-form-cookies-consent"><input id="wp-comment-cookies-consent" name="wp-comment-cookies-consent" type="checkbox" value="yes"' . checked(!empty($shiki_commenter['comment_author_email']), true, false) . '><label for="wp-comment-cookies-consent">次回のコメントのために、このブラウザーに名前・メールアドレス・サイトを保存する</label></p>';
}
?>
<section class="comments-area" aria-label="コメント">
<?php if (have_comments()) { ?><h2 id="comments-title">コメント</h2><ol class="comment-list"><?php wp_list_comments(['style' => 'ol', 'short_ping' => true, 'avatar_size' => 0, 'reply_text' => '返信する']); ?></ol><?php the_comments_pagination(['prev_text' => '前のコメント', 'next_text' => '次のコメント']); }
if (!comments_open() && get_comments_number()) { ?><p>コメントの受付は終了しました。</p><?php }
$shiki_comment_defaults = [
    'title_reply' => 'コメントを残す',
    'title_reply_to' => '%sさんへ返信',
    'cancel_reply_link' => '返信をやめる',
    'label_submit' => 'コメントを送信する',
    'comment_notes_before' => '<p class="comment-notes">メールアドレスは公開されません。<span class="required">*</span> は必須項目です。</p>',
    'comment_field' => '<p class="comment-form-comment"><label for="comment">コメント <span class="required" aria-hidden="true">*</span></label><textarea id="comment" name="comment" cols="45" rows="8" maxlength="65525" required></textarea></p>',
    'must_log_in' => '<p class="must-log-in">コメントするには <a href="' . esc_url(wp_login_url(get_permalink())) . '">ログイン</a> してください。</p>',
    'logged_in_as' => '<p class="logged-in-as">ログイン中です。<a href="' . esc_url(wp_logout_url(get_permalink())) . '">ログアウト</a></p>',
];
// Supply theme defaults before ordinary plugin filters. Passing these as
// comment_form() arguments would overwrite CAPTCHA fields and plugin edits.
$shiki_comment_fields_filter = static function ($fields) use ($shiki_fields) {
    foreach ($shiki_fields as $key => $markup) {
        if (array_key_exists($key, $fields)) { $fields[$key] = $markup; }
    }
    return $fields;
};
$shiki_comment_defaults_filter = static function ($defaults) use ($shiki_comment_defaults) {
    return array_merge($defaults, $shiki_comment_defaults);
};
add_filter('comment_form_default_fields', $shiki_comment_fields_filter, 5);
add_filter('comment_form_defaults', $shiki_comment_defaults_filter, 5);
try {
    comment_form();
} finally {
    remove_filter('comment_form_default_fields', $shiki_comment_fields_filter, 5);
    remove_filter('comment_form_defaults', $shiki_comment_defaults_filter, 5);
} ?>
</section>
