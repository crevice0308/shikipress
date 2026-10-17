# WordPress管理画面からのテーマ更新

ShikiPress 1.1.0から、通常の単一サイトWordPressでは、公開GitHubリポジトリの正式ReleaseをWordPress標準のテーマ更新へ接続します。追加プラグイン、GitHubログイン、アクセストークンは不要です。

## 最初の1回

1. DBとwp-content全体の復元可能なバックアップを取ります。
2. [正式Release](https://github.com/crevice0308/shikipress/releases/latest)のインストール用 `shikipress-1.1.0.zip` を入手します。GitHubのSource code ZIPは使いません。
3. 1.0.4以前を使っている場合は、WordPressの「外観 → テーマ → 新しいテーマを追加 → テーマのアップロード」からZIPを選び、現在のテーマを置き換えます。旧版には更新検出コードがないため、この1回はZIPが必要です。
4. ShikiPressまたはShikiPressを親にした子テーマを有効にします。

以後、新しい正式版が公開されると「ダッシュボード → 更新」や「外観 → テーマ」に更新が表示され、WordPress標準の「今すぐ更新」を使えます。公開直後はキャッシュのため通知まで時間がかかることがあります。API障害やレート制限中は時間を置いて確認してください。

## 対象と制限

- 配布元は `crevice0308/shikipress` の公開GitHub Releaseに固定。Draft、Pre-release、開発ブランチ、同じ版、古い版は自動検出対象外です。
- マルチサイトではこの管理画面更新機能を無効にしています。ネットワーク共通の更新情報とテーマ読込範囲が異なるためです。従来どおり公式ZIPで更新してください。
- 標準フォルダ名は `wp-content/themes/shikipress` です。フォルダ名を変更した配置の自動更新は対象外です。
- テーマ内のコードなので、別の無関係なテーマを有効にしている間はShikiPressの更新検出・検証処理は読み込まれません。ShikiPressまたはその子テーマを有効にして確認してください。通常のテーマ切替時にはShikiPressだけの古い更新候補を消しますが、テーマを読み込まないWP-CLIの `--skip-themes`、直接DB変更、並行要求を含むあらゆる経路を制御するものではありません。これらの経路での検証は保証していません。
- WordPress/PHPの必要版を満たさないパッケージはインストールしません。サーバーのPHPやWordPress本体を勝手に更新する機能ではありません。
- 無人更新を有効化する設定変更は行いません。標準の手動更新が基本です。管理者が別途WordPressで有効にした自動更新設定は、その管理者の選択として扱われます。
- WordPressの権限・nonce・ファイルシステム権限に従います。ホスティング環境によってはWordPress標準のファイルアクセス確認が必要です。

## データとカスタマイズ

テーマ更新はテーマフォルダ内のファイルを置き換えます。投稿、固定ページ、メディア、設定の保存先を削除する処理は追加していません。ただし、障害・プラグイン・すべての将来版を含む無条件のデータ保全保証ではありません。実サイトの設定を含むステージング確認とバックアップを継続してください。

親テーマ内のPHP/CSSを直接編集した変更は更新で上書きされます。独自の変更は子テーマやWordPressの追加CSSへ分離してください。子テーマ自身のファイルは親テーマ更新の置き換え対象にしません。

## 通信・検証

更新確認時にGitHub APIと公開ReleaseのメタデータへHTTPSで接続します。GitHub/CDNには接続元サーバーのIPアドレスと通常の通信情報が伝わります。サイトURL、管理者メール、記事、APIキー、GitHub認証情報を更新要求に追加しません。

正式Releaseの版、ZIP名、URL、サイズ、SHA-256と互換性メタデータを一致させ、インストール前にZIP本体、構造、テーマヘッダーを検査します。配布先・版・ハッシュ・構造が不一致なら更新を中止し、未検証ZIPをWordPressへ渡しません。

SHA-256は破損やメタデータとの不一致を検出する仕組みで、独立した署名ではありません。GitHubの配布アカウント自体が侵害された場合の完全な防御を意味しません。

## 開発上の根拠

- [WordPress Update URIとホスト別更新フック](https://developer.wordpress.org/reference/hooks/update_themes_hostname/)
- [パッケージダウンロード前の検査フック](https://developer.wordpress.org/reference/hooks/upgrader_pre_download/)
- [WordPressの子テーマ](https://developer.wordpress.org/themes/advanced-topics/child-themes/)
- [GitHub Releases API](https://docs.github.com/en/rest/releases/releases#get-the-latest-release)

実行した検査と未検証事項は[検証記録](verification.md)に記載します。

## Releaseを作る側の要件

更新対応版のReleaseには、インストール用ZIP、SHA256SUMS.txt、shikipress-update.jsonの3資産が必要です。Release専用ワークフローは検証済みZIPのstyle.cssから版と必要WordPress/PHPを読み、実ZIPのサイズ・SHA-256とレビュー済みの検証対象WordPress版をメタデータへ記録します。すべての資産を再取得して一致を確認した後に公開します。

将来の版を追加するときも、Release許可リストの版・ハッシュ・ファイル数・互換性情報をレビューし、同じコミットの必須CIを通してください。既存のタグや公開済み資産は上書きしません。メタデータのない旧Releaseは履歴として残せますが、更新クライアントへの配布候補にはなりません。プレリリースを正式更新として配ることはしません。
