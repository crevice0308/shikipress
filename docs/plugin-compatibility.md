# プラグイン併用検査（2026-10-17）

「有名なプラグインすべて」の動作保証ではなく、下記の代表的な7種類を範囲を分けて検査しました。公式WordPress.org配布物を使い、本番サイト、Googleアカウント、実広告、実メール、外部バックアップ先へは接続していません。

## 対象と確認範囲

| プラグイン | 検査版 | 実行した主な検査 | 範囲外 |
| --- | --- | --- | --- |
| [Site Kit by Google](https://wordpress.org/plugins/google-site-kit/) | 1.189.0 | 未接続状態での有効化・画面、実AdSenseタグ描画クラスの合成ID出力、オフラインローダー重複/同意保留 | OAuth、実Analytics/Search Console/AdSenseデータ、審査 |
| [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) | 6.2.2 | フォームのスマホ/PC表示、必須入力・AJAX応答（実メールは遮断） | 実メール到達、実CAPTCHA/外部スパム判定 |
| [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) | 28.7 | canonical/説明/schemaの重複、noindex、公開範囲、XMLサイトマップ、読者用サイトマップのページ送り | 検索順位、Google側の反映、Premium |
| [WP Super Cache](https://wordpress.org/plugins/wp-super-cache/) | 3.1.4 | 実キャッシュファイル/キャッシュヒット、テーマ設定・公開方針変更時の消去 | ホスティング/CDNキャッシュ、Apache rewriteモード |
| [TablePress](https://wordpress.org/plugins/tablepress/) | 3.4.1 | 検索/ソート/ページ送り、25/500行、320〜1920px、目次・広告枠・配色 | Premium、実機ブラウザ、本番性能 |
| [Wordfence Security](https://wordpress.org/plugins/wordfence/) | 9.0.2 | 未登録状態での有効化/無効化、記事保持、基本画面、フォーム併用 | 登録、脅威フィード、スキャン品質、WAF最適化/OS設定 |
| [UpdraftPlus](https://wordpress.org/plugins/updraftplus/) | 1.26.11 | 有効化/無効化、記事保持、基本画面、フォーム併用 | 完全バックアップ/復元、外部ストレージ認証/転送 |

最新版情報は検査日に[公式プラグイン情報API](https://codex.wordpress.org/WordPress.org_API)と各公式配布ページで照合しました。Site Kit/Wordfenceは各500万以上、UpdraftPlusは400万以上の有効インストール表示を参考に代表例へ選定しました。人気は互換性や安全性の保証ではありません。

Contact Form 7 6.2.2自身の要件はWordPress7.1以上/PHP8.3以上です。テーマがPHP8.2をサポートしていても、このCF7版との組合せをPHP8.2で使えるという意味ではありません。

## 構成と測定条件

- ローカル実行: WordPress7.1.3/PHP8.3.33/SQLite-WASM、Playground CLI3.1.58。
- ブラウザ: Chromium155.0.8059.84、Playwright1.62.1、PC/モバイルの画面幅をエミュレーション。
- 独立構成: 各プラグイン単独、Yoast+WP Super Cache、Site Kit+CF7+Wordfence+UpdraftPlus。競合するSEO/キャッシュ製品を同時に有効化した検査ではありません。
- 各単独有効化時にテスト記事の本文保持、320/390/768/1440pxでHTTP200・横はみ出しなし・JS例外なしを確認（中央4種）。
- Site Kit接続後の挙動は、実プラグインのタグ描画クラスと合成ID/ローカル応答を使う検査です。実アカウント接続の証明ではありません。
- WP Super Cacheのローカル検査は、PlaygroundがCLIフロントコントローラを使う差を検査側で補正しています。プラグイン/テーマ本体はそのために変更していません。

## 修正対象

### Site KitのAdSenseローダー

1.0.3はURL全体で共有スクリプトを比較していました。Site Kitは同じパブリッシャーのURLに`host`引数を付けるため、テーマ側もAdSenseを使うと2本読み込む条件がありました。公式ホスト/パス/パブリッシャーIDで照合して共有するよう1.0.4で修正しました。

CMPが`type="text/plain"`または`data-src`で保留したタグは実行せず、広告枠は再試行可能な同意待ちにします。プラグインのタグ・属性・アカウント設定は変更しません。複数の仕組みで自動広告を管理するより、管理元を1つに決めて設定を確認する方が安全です。Site Kit自身が出すタグの同意管理は、テーマの設定だけでは制御できません。

### Yoastの読者用サイトマップ

1.0.3では読者用サイトマップの2ページ目のcanonicalだけがページ送りを反映し、YoastのOpen Graph URLとWebPage schemaが1ページ目を指す条件を再現しました。該当するテーマのショートコード付きページに限り、URL表現を1.0.4で一致させました。

## TablePressの結果と性能上の注意

未変更1.0.3に対して192/192判定が合格。検索・ソート・ページ送り、読み取りモード、目次、合成広告、配色を確認。ブラウザ例外/HTTPエラー/外部要求0、PHPデバッグログは空でした。

390×844px、CPU4倍低速化、広告なし、ローカルHTTP、各3回の中央値では、500行TablePressのLCP2,572ms、長時間タスクのブロック時間合計752msに対し、同じ静的表は2,456ms/397msでした。25行ではTablePress2,084ms/340ms、静的表2,224ms/228ms。大量行の追加処理負荷は残ります。LighthouseのTBT/スコアや実ユーザーINPではなく、過去のLighthouse課題が解消したとは扱いません。大量データは行数/列数/ページ分割を実サイトで再評価してください。

## 運用前に

使うプラグインの版と要件を合わせ、実際の設定・フォーム・広告・キャッシュを含むステージングで確認してください。DBとwp-content全体の復元可能なバックアップを取り、特にバックアップ/セキュリティ製品はそれぞれの復元・防御設定を別途検証してください。

修正後のYoast/WP Super Cache統合検査は65/65、追加のURL/schema参照検査は11/11合格。Site Kit共有ローダー/CMPの18判定と、既存画面回帰99判定が合格しています。

CF7+Site Kit+Wordfence+UpdraftPlusの組合せでは、4画面幅でフォーム1個・HTTP200・横はみ出しなし、必須項目3件の拒否/メール呼出し0、正常AJAX応答/メールスタブ1回、失敗スタブのエラー表示を確認しました。JavaScript例外/外部ブラウザ要求は0。送信成功はローカルスタブ応答で、メール到達の確認ではありません。自動入力の検査はCF7の非同期フィールド検証完了を待って実行しています。
