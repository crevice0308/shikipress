> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# 公式資料と採用判断

確認日: 2026-10-17（Asia/Tokyo）。ソフトウェアの実行バージョンはtest-report.mdで照合します。以下は確認した公式ページです。外部アカウント・本番環境への接続成功を意味しません。

|対象|公式資料|採用/確認内容|
|---|---|---|
|WordPress安定版|https://wordpress.org/download/|7.1.3の配布表示と実際のlatest.tar.gz内version.phpを照合|
|推奨環境|https://wordpress.org/about/requirements/|PHP8.3+、MySQL8.0+/MariaDB10.11+、HTTPS|
|PHPサポート|https://www.php.net/supported-versions.php|8.2/8.3/8.4/8.5のサポート表。利用可能な8.3で実行検証|
|エックスサーバーPHP|https://www.xserver.ne.jp/support/faq/service_php_version.php|PHP8.5までの選択肢を確認。実契約サーバーのパネル確認は別途必要|
|エックスサーバーDB|https://www.xserver.ne.jp/manual/man_db_spec.php|MySQL/MariaDB、新規サーバーMariaDB10.11の案内|
|エックスサーバーPHP切替|https://www.xserver.ne.jp/manual/man_program_php_ver.php|サーバーパネルで切替、対象環境と高速化設定を実際に確認|
|アクセス制限|https://www.xserver.ne.jp/manual/man_server_limit.php|パネルで設置先のBASIC認証を設定。noindexだけで保護しない|
|Settings API|https://developer.wordpress.org/plugins/settings/settings-api/|options.php、manage_options、登録済みサニタイズ|
|nonce|https://developer.wordpress.org/plugins/security/nonces/|CSRF対策。nonceを権限チェックの代用にしない|
|一意スラッグ|https://developer.wordpress.org/reference/functions/wp_unique_post_slug/|下書き時の一意候補確認と公開時のコア再チェック|
|XMLサイトマップ|https://developer.wordpress.org/reference/functions/wp_sitemaps_get_server/|コアproviderのフィルタで公開/保護/noindexを整合|
|安全なHTTP|https://developer.wordpress.org/reference/functions/wp_safe_remote_get/|安全な外部リクエスト、独自DNS検証/固定も追加|
|Cron|https://developer.wordpress.org/plugins/cron/|アクセス起動による遅れ、明示サーバーCronの別手順|
|URL移行|https://developer.wordpress.org/cli/commands/search-replace/|シリアライズ対応、対象表限定、dry-run、GUIDを除外|
|Core Web Vitals|https://web.dev/articles/vitals?hl=ja|LCP/INP/CLS。実利用者INPとラボTBTを区別|

DeepLの送信形式・認証・HTTPエラーは `automation-implementation.md`、広告7サービスとGoogle認定CMP/TCFの出典は `ads-guide.md` に個別記載しています。広告タグの取得・審査・アカウントごとの本番コードは運営者が確認します。

## 追加監査 2026-10-17（日本時間）

- Contact Form 7 6.2.2: https://contactform7.com/2026/10/11/contact-form-7-622/ / https://wordpress.org/plugins/contact-form-7/
- Yoast SEO 28.7: https://developer.yoast.com/changelog/yoast-seo/28.7/ / https://wordpress.org/plugins/wordpress-seo/
- WP Super Cache 3.1.4: https://wordpress.org/plugins/wp-super-cache/
- TablePress 3.4.1: https://wordpress.org/plugins/tablepress/ / https://tablepress.org/documentation/

公式APIのプラグイン情報と実際に導入したソースで版/要件/フックを照合。プラグイン全実装の独立した脆弱性監査や認証付きCVEデータベースの全件照合を行ったという意味ではありません。

## CAPTCHA追加監査

- [CF7 Turnstile連携](https://contactform7.com/turnstile-integration/)、[reCAPTCHA](https://contactform7.com/recaptcha/)
- [Cloudflareテストキー](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)、[Widget設定・寸法](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/)、[Siteverify](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- [Googleテストキー・バッジFAQ](https://developers.google.com/recaptcha/docs/faq)、[表示](https://developers.google.com/recaptcha/docs/display)、[Siteverify](https://developers.google.com/recaptcha/docs/verify)
- [WordPress comment_formフックと処理順序](https://developer.wordpress.org/reference/functions/comment_form/)
- [Simple CAPTCHA with Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/)、[ReCaptcha v2 for Contact Form 7](https://wordpress.org/plugins/wpcf7-recaptcha/)

公式配布パッケージの版・要件・SHA256はtests/results/captcha-plugin-packages.json。正常・異常応答の実行範囲はaudit-report-1.0.2.mdで区別する。
