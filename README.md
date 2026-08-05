# kinvoice — Kurage Send Invoice

**領収書をPDFで発行し、ダウンロードURLをお客様へメールで送る。** 管理者専用。

- 管理画面: `/kinvoice.php`（`KINV_ADMIN` に設定したXアカウントのみ）
- 顧客用  : `/kinvoice_dl.php?t=<32桁トークン>`

## 使い方

金額（税込）・顧客名・発行日・メールアドレス・但し書きを入れて発行すると、
領収書PDFを作り、**ダウンロードURLをメールで送ります**。登録エリアの下に
送信履歴の一覧があり、再送・PDF確認・ロック解除ができます。

## PDFをメールに添付しない理由

添付にすると、宛先を間違えた時点で領収書が相手の手元に残ります。
本システムは**推測困難なURL（32桁トークン）＋宛先メールアドレスの確認**の
2つが揃わないと開けない場所にPDFを置きます。誤送信しても中身は開けません。

認証前の画面には、顧客名も金額も領収書番号も出しません（トークンだけが
漏れたときに、誰宛のいくらの領収書かが分かってしまうため）。
メールアドレスの入力を10回間違えるとロックし、管理画面から解除します。

## 構成

heteml の素のPHPだけで動きます。**常駐プロセスもDBもポートも使いません。**

| ファイル | 役割 |
|---|---|
| `public/kinvoice.php` | 管理画面（発行フォーム＋送信履歴一覧） |
| `public/kinvoice_dl.php` | 顧客用ダウンロードページ |
| `public/kinvoice_lib.php` | 台帳・税計算・メール送信 |
| `public/kinvoice_pdf.php` | 領収書PDF生成（外部ライブラリ非依存） |

- 台帳は `public/kinvoice_data/receipts.json` に flock 付き。`.htaccess` で直接アクセスを塞ぐ
- 認証は `auth_common.php`（kurage.exbridge.jp に既設のものを使う。OAuth は aiknowledgecms が担当）
- メールは `mail()` + base64（`exbridge_jp/contact.php` と同方式。heteml で実績あり）
- PDFの描画部品は `kappstore/public/kapp_invoice.php` と共通（あちらは請求書）

## 領収書としての体裁

- 宛名・金額（税込）・但し書き・発行日・発行元・税率ごとの内訳
- **電子交付のため収入印紙は不要**である旨を印字
- 適格請求書発行事業者の登録番号は、`KINV_INVOICE_REG_NO` を設定したときだけ印字。
  **未取得のまま架空の番号を入れないこと**（未設定なら「記載しておりません」と明記される）

## 開発

```bash
php scripts/check_kinvoice.php   # 台帳・認証・税計算・PDF の確認（41項目）
bash scripts/deploy.sh           # kurage.exbridge.jp へ公開
```

金額の内訳は端数が出ても**合計が必ず税込総額に一致**します（税抜を切り捨て、
差額を消費税に寄せる）。ここがズレると領収書として使えません。

## ライセンス

MIT License.
