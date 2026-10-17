> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# CAPTCHA連携の設定と確認

ShikiPress 1.0.2。CAPTCHAプラグインは任意で、テーマには同梱しません。Google/Cloudflareのキーは各プラグインで管理し、テーマの設定JSONからは書き出しません。フォームやコメントを公開する前に、実際の保護対象と失敗時の動作を確認してください。

## 対象構成

|構成|設定場所・フォームタグ|
|---|---|
|Contact Form 7標準Google reCAPTCHA v3|お問い合わせ→インテグレーション。v3のサイトキー・シークレットを設定。スコア判定の調整はCF7側で行う|
|Contact Form 7標準Cloudflare Turnstile|お問い合わせ→インテグレーション。Managedのキーを設定。既定では全CF7フォームへ追加される。配置・大きさは `[turnstile size:compact]` などで設定|
|ReCaptcha v2 for Contact Form 7|同プラグインでv2を選び、v2キーと `[recaptcha]` を設定。v3用キーと混同しない|
|Simple CAPTCHA with Cloudflare Turnstile|同プラグインでキーと保護対象を設定。CF7以外のコメント・ログイン等は個別に有効化する|

同じCF7フォームは一つの認証連携を基本にします。複数を使う場合は両方の認証、エラー、再送を確認してください。テーマはプラグインの選択・キー・免除設定を自動変更しません。標準TurnstileとSimple Turnstileは別の連携です。CF7の認証を設定してもWordPressのコメント・ログインが自動的に保護されるわけではありません。

## 表示・操作

Googleのバッジはモバイル下部ナビより上へ配置します。バッジの非表示は行いません。今回の320px以上の表示検証と、さらに狭い入れ子レイアウトは別です。狭いフォームでは各プラグインで公式のcompactサイズを選んでください。認証画面をCSSで縮小・切り取りしないでください。外部iframeの配色は提供元の設定に従い、テーマのダーク設定から内部を塗り替えません。

本番で320px程度の狭い画面、拡大表示、明暗表示、スマホの検索パネルを確認します。別の最適化プラグインで認証APIやCF7のスクリプトを遅延・結合した場合は再確認してください。

## 認証の確認

1. 対象ドメイン・HTTPS・サイトキーの種類を確認し、成功時に送信できることを確認。
2. 未認証・不正トークン・期限切れ・再利用を拒否することを確認。拒否後に入力を保って再認証・再送できることも確認。
3. 同じページに複数フォームを置く場合、各フォームと2回目の送信を確認。
4. APIの読み込み遮断・Siteverifyのタイムアウト時に、意図した拒否になるか確認。
5. キャッシュ・WAF・CDN・同意管理・ブラウザ拡張を本番と同じ構成にして再確認。

公開テストキーは実際のボット判定を行いません。本番キーへ差し替えてから運用し、公開テストキーや検証用MUプラグインを本番へ残さないでください。Google v3の実スコア・誤判定率、実メールの到達は別の確認です。

## Simple Turnstileの例外設定

IP免除リストは空の状態を基本にします。検証版1.44.0は転送ヘッダーからIPを取得するため、オリジンへ直接接続できる構成で送信者がヘッダーを偽装すると、登録済みIPとして認証を免除される条件をローカルで再現しています。免除を使う場合は、信頼するプロキシ以外からの接続を制限し、転送ヘッダーをサーバーで正しく処理してください。テーマのCSSやCF7用の補強では解消しません。

User-Agentは送信者が変更できます。User-Agentによる免除も認証省略の条件になります。障害時に送信を許可するfailover設定は、認証サービス障害時に未検証の送信を許可する運用です。診断画面に注意を表示しますが、設定を勝手に変更しません。

## 公式資料

- [CF7 reCAPTCHA](https://contactform7.com/recaptcha/)
- [CF7 Turnstile](https://contactform7.com/turnstile-integration/)
- [Googleテスト・バッジFAQ](https://developers.google.com/recaptcha/docs/faq)
- [Googleサーバー検証](https://developers.google.com/recaptcha/docs/verify)
- [Cloudflareテストキー](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
- [Cloudflareサーバー検証](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)

確認日2026-10-17。今回の実行結果と外部条件は `audit-report-1.0.2.md` を参照。
