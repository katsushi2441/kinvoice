# VERIFICATION

このファイルは、**AIが「できました」と言う前に、自分で確認するための手順**です。

初めての環境では、AIは「たぶん動いたはず」で報告しがちです。VWorkでは、**確認していないものを完了と呼びません**。
確認手段が環境に無いときは、無いと正直に書きます。

---

## 0. 最初に1回だけ：環境チェック

新しいPC・新しい案件では、**まず何が使えるかを実際に叩いて確かめます**。推測しません。

```bash
# 実行環境
python3 --version;  node --version;  php --version
# バージョン管理と取得
git --version;      curl --version | head -1
# ブラウザ検証(あれば強力)
which google-chrome chromium chromium-browser 2>/dev/null
python3 -c "import playwright; print('playwright OK')" 2>/dev/null
# 画像確認(スクリーンショットの加工に使う)
python3 -c "import PIL; print('Pillow OK')" 2>/dev/null
```

**結果を `SERVERS.md` の「実行コマンド」に記録します。**
以降の作業は、ここで「使える」と分かった手段だけで検証します。無いものを前提にしません。

---

## 1. 成果物タイプ別の検証（最低ライン）

### Webページ・HTML

| 順番 | やること | コマンド例 |
|---|---|---|
| 1 | 構文チェック（壊れたまま公開しない） | `php -l file.php` / `node --check file.js` |
| 2 | **画面を実際に見る**（最重要） | 下記「スクリーンショット確認」 |
| 3 | 公開後にURLが生きているか | `curl -s -o /dev/null -w "%{http_code}\n" https://…` |
| 4 | スマホ幅で崩れていないか | 下記「モバイル確認」 |

**スクリーンショット確認**（AIが自分の目で見るための手段。優先順に試す）

```bash
# A. Chrome headless（最も入っている可能性が高い）
google-chrome --headless=new --disable-gpu --hide-scrollbars \
  --window-size=1280,900 --screenshot=shot.png "https://example.com/page.html"

# B. Playwright（あれば、スマホ幅や要素の実測までできる）
python3 -c "
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={'width':390,'height':900})
    pg.goto('https://example.com/page.html', wait_until='networkidle')
    pg.screenshot(path='shot_mobile.png'); b.close()"
```

撮ったら**必ず画像を開いて中身を見ます**。「撮れた」は確認ではありません。
見るポイント：文字が隠れていないか／要素が重なっていないか／画像が出ているか／色が読めるか。

**モバイル確認**（横スクロール発生の判定は目視より数値が確実）

```bash
python3 -c "
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={'width':390,'height':900})
    pg.goto('https://example.com/page.html', wait_until='networkidle')
    print('scrollWidth:', pg.evaluate('document.documentElement.scrollWidth'))  # 390なら崩れ無し
    b.close()"
```

**ブラウザが無い環境なら**：`curl` でHTMLを取得し、意図した文言・タグが入っているかを確認します。
その場合は報告に「**画面の目視確認はできていません**（ブラウザ未導入）」と明記します。

```bash
curl -s https://example.com/page.html | grep -oE '<title>[^<]*|og:image" content="[^"]*"'
```

### スクリプト・データ処理

- 実行して**終了コードと出力を確認**する（`echo $?`）。
- 出力ファイルは**件数と中身のサンプルを見る**（`wc -l output/x.csv` ＋ 先頭数行）。
- 入力0件・想定外の値でも落ちないか、小さなデータで1回試す。

### 公開・アップロード

- アップロード成功メッセージだけで完了にしない。**公開URLを取得し直して200と中身を確認**する。
- 反映に時間がかかる場合（CDN等）は、その旨と再確認方法を書く。

### 外部への送信・投稿

- 送信APIが成功を返しても、**実際に相手側で見えるか**を確認する（投稿URL、受信ボックス、記録ファイル）。
- 確認できないものは「送信は成功したが、表示は未確認」と正直に書く。

---

## 2. 一括置換・自動編集の鉄則

複数ファイルの置換や自動編集は、**黙って失敗する**のが一番危険です。

- 置換は**件数を数え、想定と違えば失敗として報告**する（0件置換を成功にしない）。
- 変更前に対象ファイルをコピー（バックアップ）しておく。
- 変更後、**変更が実際に入ったかを再検索して確認**する。

```bash
before=$(grep -c "OLD" file); sed -i 's/OLD/NEW/g' file; after=$(grep -c "NEW" file)
echo "置換 $before → $after"   # 0件や想定外なら止めて報告する
```

---

## 3. コミット前チェック

```bash
git add -A
git diff --cached --name-only | grep -iE "\.env$|config\.(php|yml)$|secret|\.pem$" \
  && echo "!! 秘密が混入 !!" || echo "OK"
```

秘密ファイルが出たら**コミットせずに報告**します（`.gitignore` の見直し）。

---

## 4. 完了報告のかたち

「できました」だけでは完了になりません。次の3点を必ず添えます。

1. **何をしたか**（変更ファイル、実行コマンド）
2. **どう確認したか**（構文チェック／スクショ目視／HTTP 200／件数など、実際にやったこと）
3. **確認できていないこと**（あれば正直に。「未確認」と書くことは失敗ではありません）

悪い例：「修正しました。問題なく動くはずです。」
良い例：「`kfreqai.php` を修正 → `php -l` OK → デプロイ → 公開URL HTTP 200 → スマホ幅390pxでスクショ確認、崩れなし。メール送信部分は実送信していないため未確認です。」

---

## 5. やってはいけないこと

- 確認していないのに「動きます」「問題ありません」と言う。
- エラーや警告を報告から省く。
- スクリーンショットを撮っただけで、中身を見ずに完了とする。
- 環境に無いツールを「あるはず」と仮定して手順だけ書く。
- 本番データ・本番公開・外部送信を、事前確認なしで実行する。
