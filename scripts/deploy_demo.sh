#!/usr/bin/env bash
# デモサイトを公開する。
#   https://proto.exbridge.jp/kinvoice/
#
# 本番(kurage.exbridge.jp/kinvoice.php)とは別インスタンス。台帳も設定も別。
# デモ設定は KINV_DEMO=true なので、メールは実際には送信されない。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/proto_exbridge_jp/kinvoice"

upload() {
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"
  echo "deployed: ${2}"
}

upload public/kinvoice.php      kinvoice.php
upload public/kinvoice_dl.php   kinvoice_dl.php
upload public/kinvoice_auth.php kinvoice_auth.php
upload public/kinvoice_lib.php  kinvoice_lib.php
upload public/kinvoice_pdf.php  kinvoice_pdf.php
upload demo/kinvoice_config.php kinvoice_config.php
upload public/kinvoice_data/.htaccess kinvoice_data/.htaccess
upload demo/.htaccess           .htaccess
upload demo/index.php           index.php

echo
echo "published: https://proto.exbridge.jp/kinvoice/"
