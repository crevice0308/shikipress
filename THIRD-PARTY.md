# ライセンス・素材一覧

## 納品テーマ

- ShikiPressのPHP/JS/CSS/theme.json/テンプレート: この成果物で新規作成。GPLv3以降。LICENSEへ全文同梱。
- 四季SVG (`assets/season-{spring,summer,autumn,winter}.svg`): この成果物で座標とパスを記述したオリジナル植物・花器イラスト。GPLv3以降。写真転載・第三者画像への直リンクなし。
- 季節別SNS PNG (`assets/social/*.png`): この成果物でローカル描画したオリジナル1200x630画像。GPLv3以降。イメージ生成モデルで描いたUIの完成予想図ではありません。
- アイコン: inc/frontend.php内のオリジナルSVGパス、GPLv3以降。
- フォント: 同梱・外部配信なし。利用端末の日本語システムフォントを使用。検証環境はNoto CJK（SIL Open Font License、Ubuntu提供、テーマへ再配布していません）。
- screenshot.pngとscreenshots/: 実際にローカルWordPressをブラウザで描画した画像。TESTの投稿を使用。個人の認証情報やAPIキーは含みません。
- 既存テーマ・子テーマ・外部CSS/JSライブラリのコピーは含みません。

## 開発のみ（テーマZIPには非同梱）

WordPress本体（GPLv2以降）、WP-CLI（MIT）、PHP（PHP License）、MariaDB（GPL）、Playwright（Apache-2.0）、Lighthouse（Apache-2.0）、Chromium/Chrome headless shell（Chromiumプロジェクトと各依存ライセンス）、Firefox（MPL等）、WebKit（WebKit/依存ライセンス）を検証用に利用。ブラウザ・WordPress・DB・開発依存そのものはソースZIPにも含めません。利用バージョンと実際の実行可否はtest-report.mdを参照。

Google AdSense/AdMax/A8/もしも/afb/バリューコマース/DeepLは外部サービスです。各社の商標・広告素材・配布コードの権利は各社/権利者に帰属します。納品にアカウント固有の実広告コード・キーは含みません。運営者が正式なコードを取得し、その条件に従って利用してください。

## 1.0.1の併用検証のみで使用したソフトウェア

Contact Form 7 6.2.2、Yoast SEO 28.7、WP Super Cache 3.1.4、TablePress 3.4.1はWordPress.org公式配布物を検証環境だけへ導入しました。プラグイン本体・ZIP・翻訳・依存アセットはテーマZIP/ソースZIPへ同梱していません。`assets/plugin-compat.css` と `inc/compat.php` はShikiPress側の独自実装です。配布元・版・確認結果はaudit-report-1.0.1.mdを参照。

## 1.0.2のCAPTCHA検証

ReCaptcha v2 for Contact Form 7 1.5.0、Simple CAPTCHA with Cloudflare Turnstile 1.44.0も公式配布ZIPをローカル検証環境だけに導入。プラグイン本体は同梱しません。Google reCAPTCHA / Cloudflare Turnstileの公開テストキーとテスト用API応答を使い分け、実行範囲はaudit-report-1.0.2.mdへ記載。新しい互換CSS/JS/PHPはShikiPress独自実装です。API契約テスト用の代替ウィジェットは第三者の実認証画面ではありません。
