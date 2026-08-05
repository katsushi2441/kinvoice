# SERVERS

このファイルは、案件で使うPC、サーバー、API、外部サービス、環境変数の場所をまとめるファイルです。

パスワードやAPIキーはここに直接書かず、`.env` の場所と変数名だけを書きます。

## ローカル環境

```text
OS:
作業フォルダ:
VS Code:
Codex:
Git:
Python:
Node.js:
```

## フォルダ構成

```text
data/      入力データ。Excel、CSV、URL一覧、サンプルファイル
src/       スクリプトや小さなWebツール
output/    生成結果。CSV、Markdown、HTML、ログ
docs/      補足資料
AGENTS.md
WORK_PROTOCOL.md
RULES.md
VERIFICATION.md
SERVERS.md
```

## 環境変数

```text
.env:
使用する変数:
```

`.env` はGit管理しません。

必要に応じて、値を空欄にした `.env.sample` を作ります。

```text
SMTP_HOST=
SMTP_PORT=
API_BASE_URL=
API_TOKEN=
FTP_HOST=
FTP_USER=
FTP_PASSWORD=
```

実値は `.env` またはサーバ環境変数に置きます。

## 設定ファイル

サイト名、公開先、ジョブ種別、目標件数など、秘密ではない設定項目が増えたら `config.yml.sample` を作ります。

```yaml
site:
  name:
  base_url:

jobs:
  daily_target:
  interval_seconds:
  queue_name:

publish:
  enabled:
  destination:
```

実際の `config.yml` はGit管理しません。

## 外部サービス

```text
サービス名:
用途:
URL:
認証情報の場所:
注意点:
```

## サーバー・FTP・公開先

```text
公開URL:
FTP/SSH/API:
接続情報の場所:
ローカルパス:
リモートパス:
デプロイ手順:
```

## 実行コマンド

実際に動いたコマンドをここに整理します（`VERIFICATION.md` の環境チェック結果もここに記録します）。

```bash

```

## ジョブ・キュー・常駐サービス

長時間処理や繰り返し実行がある場合に書きます。

```text
ジョブ管理:
キュー:
常駐サービス名:
起動/停止/再起動:
管理画面:
ログ:
1日の目標件数:
実行間隔:
重複防止:
失敗時の確認方法:
```

## 注意点

- 本番データを直接上書きしない
- 接続情報は `.env` に置く
- 初めて行った作業手順はこのファイルに追記する
- サーバー反映が必要な作業は、反映先と確認URLを書く
