#!/usr/bin/env bash
# ダウンロード販売用のパッケージを作る。
#
#   出力: outputs/kinvoice-vwork-<日付>.zip
#
# GitHub には動くコードだけを置いている（.gitignore）。このzipには、それに加えて
# 教材（docs/）・AI向け手順書（AGENTS.md / INSTALL.md）・VWork一式を入れる。
# **この差分が価格の根拠**なので、中身を減らさないこと。
#
# 顧客データと実行時設定は入れない（public/kinvoice_data/ と kinvoice_config.php）。
set -euo pipefail
cd "$(dirname "$0")/.."

STAMP="$(date +%Y%m%d)"
NAME="kinvoice-vwork-${STAMP}"
OUT="outputs/${NAME}"

rm -rf "$OUT" "outputs/${NAME}.zip"
mkdir -p "$OUT"

# --- 動くシステム（設定と台帳は除く） ---
mkdir -p "$OUT/public/kinvoice_data"
for f in kinvoice.php kinvoice_dl.php kinvoice_auth.php kinvoice_lib.php kinvoice_pdf.php \
         kinvoice_config.php.example; do
  cp "public/$f" "$OUT/public/$f"
done
cp public/kinvoice_data/.htaccess "$OUT/public/kinvoice_data/.htaccess"

# --- 有償パッケージにだけ入るもの ---
cp AGENTS.md INSTALL.md SUPPORT.md README.md LICENSE "$OUT/"
cp -r docs "$OUT/docs"
cp -r vwork "$OUT/vwork"

# --- 道具 ---
mkdir -p "$OUT/scripts"
cp scripts/make_password_hash.php scripts/check_kinvoice.php scripts/check_demo.php "$OUT/scripts/"

# --- 入っていてはいけないものが混ざっていないか確かめる ---
ng=0
for bad in "kinvoice_config.php" "receipts.json" "login_fails.json" ".env"; do
  if find "$OUT" -name "$bad" | grep -q .; then
    echo "NG: $bad が混ざっています" >&2; ng=1
  fi
done
# 実設定に入っている値が漏れていないか（社名・登録番号など）
if grep -rl "T4180001056508" "$OUT" >/dev/null 2>&1; then
  echo "NG: 自社の登録番号が混ざっています" >&2; ng=1
fi
[[ $ng -eq 0 ]] || { echo "中止しました" >&2; exit 1; }

( cd outputs && zip -qr "${NAME}.zip" "$NAME" )
rm -rf "$OUT"

echo "できました: outputs/${NAME}.zip"
unzip -l "outputs/${NAME}.zip" | tail -n +4 | head -30
