> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# 広告実装記録

対象要件：15〜16、19の外部コード移行、20の管理権限、24の広告関連検証。

ファイル：`shikipress/inc/ads.php`、`assets/ads.js`、`assets/ads.css`、`assets/ad-editor.js`。契約：`shiki_ad($position)`はecho、`shiki_ad_html($position)`はHTMLを返す。本文フィルターは優先度8（WordPress標準do_blocksより前）。フロントテンプレートからsidebar/footerを呼ぶ。

安全な初期状態はtest・空の広告枠です。テストHTMLには本番コード・URL・IDを含めず、出力ペイロードにはダミーのサービス/端末/動作だけを含めます。本番コードはbase64の文字列として出力し、端末選択・本番承認・正規TCF応答の確認後にだけHTMLを復元します。base64は秘密の保管やサニタイズの代替ではなく、ブラウザの先行読み込みを防ぐための不活性化です。

AdSense標準アダプターはレスポンシブ・ディスプレイのID形式を検証し、公式の共有ローダーと枠ごとのins/pushを生成。その他6種は公式タグを管理者が許可するアダプターで、素材・URL・文言を保持します。共通ローダーURLだけを指定して重複排除し、正当な枠ごとの初期化は残します。同期document.writeタグは拒否してエラー状態にします。

認定CMP自体は作りません。TCF `addEventListener`、Google `googlefc.callbackQueue/CONSENT_API_READY`を使用。必要同意を確認できない場合はfail closed。認定IDは現行リストで運営者が確認して登録します。自動で認定を検証する仕組みではありません。TCF以外のGPP/Additional Consentを独自判定する機能はありません。アカウント固有タグ、実広告、実CMP、実スマホの外部確認は未検証です。正式な制約・運用は`ads-guide.md`。

通常バックアップの実行コードキー：`ads_slots`、`ads_cmp_code`。`shiki_ads_reset_import`は`shiki_disarm_import_settings`に登録され、モードtest、許可false、自動広告false、CMP無効、各枠trusted=falseへ戻す。

テスト：`tests/ads-integration.php`（WP-CLI実サイト）と`tests/ads-browser.mjs`（Playwright、外部通信の遮断/計数、モックTCFと合成タグ）。WordPress 7.1.3 / PHP 8.3.6で23 assertions PASS、Chrome headless-shell 155.0.8059.84で19 assertions PASS。広告会社・CMP・アフィリエイトへの実リクエストは0件。`tests/results/ads-integration.json`と`tests/results/ads-browser.json`を参照。実際の本番配信・本番認定CMPを検証した結果ではありません。

再実行例（ソースルートをカレントディレクトリとして、開発状態の場所を合わせる）：

```sh
SHIKI_DEV_STATE=/workspace/scratch/28f95e205fde/dev-state bash scripts/dev-job.sh bash scripts/wp.sh eval-file tests/ads-integration.php
SHIKI_DEV_STATE=/workspace/scratch/28f95e205fde/dev-state bash scripts/dev-job.sh env SHIKI_CHROMIUM=/tmp/shiki-headless/chrome-headless-shell-linux64/chrome-headless-shell node tests/ads-browser.mjs
```

本環境のUnixソケット制約によりChromeは`--single-process --no-zygote --no-sandbox`の専用検証起動を使用。1つのBrowserContextを保持し、ページごとに新しいコンテキストを作らない。これは配布テーマの実行要件ではありません。通常の開発環境では対応するPlaywright Chromiumまたは`SHIKI_CHROME_BIN`で指定したChromeを使用できます。
