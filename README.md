# ShikiPress Ultimate

四季の暮らしを表現する日本語向け WordPress ハイブリッドテーマ。独立テーマで、必須プラグイン・外部フォントはありません。

## インストール

配布済みのインストール用ZIPは[最新の正式Release](https://github.com/crevice0308/shikipress/releases/latest)から取得できます。以下はソースから生成する場合の手順です。

1. `python3 scripts/package.py dist` でインストール用 ZIP を生成します。
2. WordPress 管理画面の「外観 → テーマ → 新しいテーマを追加 → テーマのアップロード」から `dist/shikipress-1.1.3.zip` を選びます。
3. 既存サイトへの適用前にデータベースと wp-content 全体をバックアップし、ステージングで確認してください。

GitHub の「Download ZIP」は開発資料も含むソース一式です。そのまま WordPress にアップロードせず、上記のテーマ ZIP を使ってください。1.1.0からはGitHubの正式Releaseを確認し、WordPress標準の管理画面から更新できます。1.1.0の更新検出・更新操作は利用でき、1.1.1では管理画面の「詳細」を通常のGitHub Releaseリンクに変更しています。1.1.2ではShikiPressダッシュボードに標準の自動更新ON/OFFを追加しています。管理者が有効にすると、以後の正式版をWordPressがバックグラウンドでインストールします。詳細は[管理画面でのテーマ更新](docs/theme-updates.md)を参照してください。

## 動作条件と検証範囲

- テーマの宣言下限: WordPress 6.6、PHP 8.2
- 推奨 PHP とサーバー条件は [WordPress 公式要件](https://wordpress.org/about/requirements/) を確認してください。
- [実行結果・未検証事項](docs/verification.md) を参照してください。CI の対象設定は合格結果や将来の互換性保証を意味しません。
- 本番サイト、実機スマートフォン、利用者の広告/CMPアカウントを使った配信は確認していません。

[代表的なプラグイン7種類の併用検査](docs/plugin-compatibility.md)も参照してください。テーマとプラグインで必要なWordPress/PHP版が異なる場合があります。

## 主な機能

- 四季の配色・植物イラスト、ライト/ダーク、レスポンシブ表示
- 記事目次、関連記事、固定ページ案内、SEO出力
- 公開URL・手動分類を尊重する分類/スラッグ支援
- 広告枠、ローカルダミー広告、外部CMP連携
- 設定書き出し/取り込みと復旧補助

広告・閲覧集計・DeepLなど外部通信を伴う任意機能は設定と同意条件を確認して利用してください。実広告コードやAPIキーはリポジトリへコミットしないでください。

## 更新とデータ保全

投稿・固定ページ・メディア・設定は WordPress のデータベースおよび uploads に保存されます。テーマのファイル更新とデータ削除は別の操作です。ただし、プラグインやDB移行、サーバー障害を含むすべての更新を保証するものではありません。

[更新と復旧](docs/update-and-recovery.md)、[広告設定](docs/ads-guide.md)、[構成](ARCHITECTURE.md) を参照してください。納品由来の資料に記載された旧試験結果は今回の再検証とは区別しています。過去の生ログ・画面写真・ローカル環境情報は公開ソースから除外しています。

## 開発・テスト

開発スクリプトと統合テストは使い捨てのローカル環境専用です。本番DBや既存の実データへ向けて実行しないでください。通常の開発環境では PHP/MySQL系DB/WP-CLI が必要です。ブラウザテストには Playwright が必要です。

- `python3 scripts/package.py dist`: テーマZIPを生成
- `find shikipress -name '*.php' -print0 | xargs -0 -n1 php -l`: PHP構文チェック
- `node --check shikipress/assets/frontend.js`: JavaScript構文チェック

- `npm ci --ignore-scripts && npx playwright install --with-deps chromium && npm run test:layout`: 外部広告へ接続しない画面回帰テスト

[データ保全テスト](tests/upgrade/README.md)、[画面回帰テスト](tests/layout/README.md)、[検証記録](docs/verification.md) を参照してください。通常のテストは読み取り専用権限で実行します。Release専用ジョブだけに書き込み権限を限定しています。テーマの安定版番号を上げてmainへ反映し、同一コミットの互換性CIと画面回帰CIが成功すると、ZIP・SHA256SUMS・更新メタデータを生成・検証して正式Releaseを自動公開します。公開済みの版・タグ・資産は上書きしません。

## ライセンス

原作の GPL-3.0-or-later を維持しています。[LICENSE](LICENSE)、[THIRD-PARTY.md](THIRD-PARTY.md) を参照してください。
