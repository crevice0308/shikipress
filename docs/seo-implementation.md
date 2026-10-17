> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# SEO・サイトマップ実装メモ

対象要件: 14、17、関連する13・20・22。実装: `shikipress/inc/seo.php`、`shikipress/assets/social/*.png`。確認日: 2026-10-17。

## headと各URL

WordPressの `title-tag` と `pre_get_document_title`、`wp_robots` を使い、タイトルとrobotsタグはコアが一度だけ出力する。コアの `rel_canonical` のみを外し、テーマが投稿・固定ページ・カテゴリー・タグ・ホーム・著者・日付・投稿タイプアーカイブのcanonicalを出力する。投稿内 `<!--nextpage-->` とコメント分割は `wp_get_canonical_url()`、アーカイブはコア生成のベースURLとページ番号を使う。404はcanonicalを出さず、検索結果は検索語と分割を保持したcanonicalを出す。UTMなど任意リクエストパラメーターを複写しない。全記事一覧の `shiki_stories=1` ルートは専用URLヘルパーを使い、分割ごとにcanonical・記事一覧タイトル・パンくずを持つ。

投稿・固定ページにはSEOタイトル、説明、メディアIDによる共有画像、noindexの編集欄を置く。カテゴリー／タグもタイトル・説明・noindexを設定できる。保存時はnonceと投稿編集権限またはカテゴリー管理権限を確認し、自動保存・リビジョンを除外する。フロントの値は文脈別にエスケープする。

説明文は個別設定、抜粋／現在の分割本文、サイト説明の順。ホーム説明はホーム設定を優先する。動的ブロック・ショートコードを実行して説明を作らない。保護・非公開コンテンツから本文・画像・記事構造化データを作らない。

共有画像は個別指定のメディア画像、アイキャッチ、同梱の四季PNGの順。JPEG/PNG/WebP/GIF以外はPNGへ戻す。四季画像は1200×630の独自制作で、サイト訪問時に外部画像取得はない。SNS側の再クロール／画像取得は公開後の外部条件でありローカル検証とは別。

JSON-LDはWebSite、WebPage／CollectionPage、画面に表示するパンくずと同じBreadcrumbList、公開記事のBlogPostingを出力。公開日・更新日は実際のWordPress値。記事のimageは実際のアイキャッチがある場合だけ。著者は初期状態で出さず、同じ著者表記を画面に実装する場合のみ `shiki_seo_show_author` で有効にする。評価・FAQ・監修者・組織・連絡先を捏造しない。JSONにはHEXエンコードを併用しscript終端文字を安全に扱う。

Yoast、Rank Math、All in One SEO、SEOPressがある場合、テーマのhead・title・robots出力は停止する。1.0.1ではYoast SEO 28.7に限り、テーマのnoindex/一覧除外をより厳しい方向でrobots/XMLへ引き継ぐ。タイトル/説明/共有画像はYoast側で設定する。他のSEOプラグインとのnoindex同期は未実検証のため管理者が確認する。未知のSEO実装との共存は `shiki_seo_managed` フィルターでテーマ側を停止できる。初期構成は追加プラグイン不要。

## 公開ポリシー

- サイト全体 `blog_public=0`、検索、404、プレビュー、添付ページ、保護・未公開・除外コンテンツはnoindex。
- 個別 `_shiki_noindex=1` はnoindex。タグ・著者・日付アーカイブの全体noindexも設定可能。著者／日付の初期値は有効、タグの初期値は無効。
- `_shiki_exclude=1`、`page_exclude`、`category_exclude`、保護・未公開・個別noindexはHTML／XMLサイトマップへ掲載しない。noindex自体は閲覧権限を変更しない。
- `blog_public=0` のときコアXMLサイトマップを無効化する。HTMLは読者案内として公開済み情報を引き続き表示する。noindex／サイトマップ停止はアクセス制限の代替ではない。

## サイトマップ

XMLはWordPressコアのprovider／queryフィルターを使う。別XMLエンドポイントを作らず、投稿・固定ページ・カテゴリー・タグを対象とする。noindex著者のproviderは無効、著者を有効にする場合もインデックス対象投稿の著者だけ。カテゴリー／タグも実際のインデックス対象公開記事を持つtermだけを対象にする。XMLとmax pages計算は同じクエリー条件を使う。管理画面に `get_sitemap_url('index')` の実URLを選択・コピーできる読み取り専用欄と確認リンクを表示する。

読者向けHTMLは固定ページに `[shiki_sitemap]` または `[shiki_html_sitemap]` を置く。設定上のterm IDと対応する四季カテゴリー、記事、固定ページに分ける。記事と固定ページは10〜100件で独立してページ分割し、それぞれ `shiki_map_posts`／`shiki_map_pages` を使う。分割URLは独立したcanonicalを持つ。空セクションは出さない。HTMLサイトマップ作成だけでGoogle登録したようには表示しない。

Google所有権確認はトークンのみを保存・出力し、外部通信を行わない。Search Console作成・所有権確認操作・サイトマップ送信は実行していない。公開サイトでのGoogle/SNSの実クロール、Search Console、エックスサーバーのキャッシュは利用者の本番条件による確認項目。

## テスト

`php -l shikipress/inc/seo.php`、`php -l tests/seo-integration.php` は成功。

実WordPress統合テスト: `scripts/wp.sh eval-file tests/seo-integration.php`。2026-10-17、WordPress 7.1.3／PHP 8.3.6／MariaDB 10.11.14で50アサーション成功（最終実行exit 0、警告なし）。実行環境の分離に合わせ `SHIKI_DEV_STATE=/workspace/scratch/28f95e205fde/dev-state bash shikipress-project/scripts/dev-job.sh bash scripts/wp.sh eval-file tests/seo-integration.php` をscratchの作業ディレクトリーから実行した。専用ローカルDBでTEST投稿／termを作り、finallyで削除・設定を復元する。他の操作テストと同時には走らせない。検証対象は一意なheadタグ、JSON-LD、各公開状態・noindexのHTML／XML除外、サイト全体の非公開設定、カテゴリー、分割canonical、nonce・低権限拒否、PNG共有画像、記事／ページのHTML分割、全記事一覧ルートの分割canonical・タイトル・パンくず。実行結果: `Success: SEO integration: 50 assertions passed.`。最初の実行でテストハーネスの「非公開設定で初期化済みのコアproviderを取得する」手順とsave_post引数数を修正し、最終再実行で成功した。テーマhead・サイトマップの不具合は本テストでは検出されなかった。

## 参照した公式仕様

- https://developer.wordpress.org/reference/hooks/pre_get_document_title/
- https://developer.wordpress.org/reference/hooks/wp_robots/
- https://developer.wordpress.org/reference/functions/wp_get_canonical_url/
- https://developer.wordpress.org/reference/hooks/wp_sitemaps_posts_query_args/
- https://developer.wordpress.org/reference/classes/wp_sitemaps_posts/get_url_list/
- https://developer.wordpress.org/reference/hooks/wp_sitemaps_taxonomies_query_args/

四季SNS画像の著作条件: ShikiPressの独自制作画像。テーマのGPL-3.0-or-laterに従って利用可能。描画時の文字はシステムのDejaVu Sansをラスタライズしたもの。フォント本体を配布しない。参照画像、外部画像の転載・直リンクはない。
