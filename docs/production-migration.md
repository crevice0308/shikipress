> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# 独自ドメインへの本番移行

ドメイン購入、DNS変更、外部公開、実広告、Search Console登録、AdSense申請は自動実行していません。実際の変更前に運営者が対象・作業内容を確認します。テスト環境とは別に本番を用意し、テストサイトのアクセス制限を保ちます。

## 移行前

- 本番用の四季各5記事、合計20記事の内容・画像権利・運営情報・問い合わせ先・広告表示・プライバシーを確認。TEST記事・架空の体験談を本番記事として移行しない。
- 20記事は運営上の区切りであり、Googleの申請条件や合格保証ではありません。
- WordPressファイル・DB・画像・設定の全体バックアップを取得し、復元手順を実際に確認。設定JSONだけでは記事や画像は戻りません。
- 新ドメインとSSLの準備、対象DB/パス/ユーザー、home/siteurl、パーマリンク、アクセス制限の適用範囲を確認。
- 検証用APIキー・広告タグ・TESTデータ・デバッグログを除外。

## URLの移行

WordPressの設定画面でURLを書き換えるだけでは、記事中画像・内部リンク・シリアライズ設定は移りません。WP-CLIが使える場合、実際のテーブル接頭辞を確認し対象テーブルを限定してdry-runします。以下のドメイン/テーブルは説明用で、実値に置き換えてください。

```sh
wp db export /公開領域外/pre-migration.sql
wp search-replace 'https://old.example.test' 'https://new.example.test' wp_options wp_posts wp_postmeta --skip-columns=guid --precise --dry-run
# 対象と件数を確認してから同じ範囲で実施
wp search-replace 'https://old.example.test' 'https://new.example.test' wp_options wp_posts wp_postmeta --skip-columns=guid --precise
```

実際に他テーブルへ旧URLが保存されていれば、そのテーブルだけ追加。すべてのDBへ無差別に実行しません。GUIDは原則変更しません。マルチサイトは別途専用の計画が必要で、本納品では検証していません。バックアップやログを公開ディレクトリへ置かないでください。

## 公開前

1. DNS/SSL、HTTPSの混在、home/siteurl、記事/画像/内部リンクを確認。
2. canonical/OGPのドメインとページ番号、XML/HTMLサイトマップ、検索/404、カテゴリーと下部タブを確認。
3. 重要固定ページを公開し、役割IDを本番のIDへ対応付け直す。設定JSONのページ/term IDは環境間で一致するとは限りません。
4. 全キャッシュを更新。公開前のプレビューが一般訪問者へ出ないことを確認。
5. 本番の対象URLを明示してから、アクセス制限とnoindexを解除。テストサイトを解除しない。
6. ログアウト/別端末で公開範囲を再確認。noindexだけでは非公開になりません。Search Console未登録でも公開サイトが発見されることがあります。
7. 20記事の準備を確認した運営者がSearch Consoleのプロパティ作成・所有権確認・サイトマップ送信を手動実施。テーマは安全な確認トークン入力とサイトマップURLの案内のみ提供します。
8. AdSense等は各社で別途登録・審査・配置・CMP条件を確認。実広告の有効化には広告管理画面の本番確認と本番環境設定が必要。下部アンカー広告はサービス側で無効を初期推奨。配信確認はテストサイトのダミー確認と区別。

## 公開後

低頻度でリンク検査、予約公開、キャッシュ更新、設定バックアップを確認。Search Console/実利用者のCore Web Vitalsはデータ蓄積後に確認します。ローカルLighthouseのTBTをINPと呼び替えません。障害時は更新前ファイルとDBの対応する組へ戻し、公開範囲・広告テスト状態・URLを再確認します。

公式資料: https://developer.wordpress.org/cli/commands/search-replace/
