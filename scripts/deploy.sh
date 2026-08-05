#!/usr/bin/env bash
# Kurage Send Invoice の公開デプロイ。
#   管理画面 : https://kurage.exbridge.jp/kinvoice.php     （KINV_ADMIN のみ）
#   顧客用   : https://kurage.exbridge.jp/kinvoice_dl.php?t=<token>
#
# 常駐プロセスもDBもポートも使わない。auth_common.php と config.php は
# kurage.exbridge.jp に既設のものをそのまま使うので、ここでは上げない。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/kurage_exbridge_jp"

upload() {
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"
  echo "deployed: ${2}"
}

upload public/kinvoice.php     kinvoice.php
upload public/kinvoice_dl.php  kinvoice_dl.php
upload public/kinvoice_auth.php kinvoice_auth.php
upload public/kinvoice_lib.php kinvoice_lib.php
upload public/kinvoice_pdf.php kinvoice_pdf.php
[[ -f public/kinvoice_config.php ]] && upload public/kinvoice_config.php kinvoice_config.php
# 台帳(顧客名・メール・金額が入る)をWebから直接読ませない
upload public/kinvoice_data/.htaccess kinvoice_data/.htaccess

echo
echo "published: https://kurage.exbridge.jp/kinvoice.php"
