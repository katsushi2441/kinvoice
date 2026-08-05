<?php
// kinvoice の台帳・認可・領収書PDFの動作確認。メールは送らない。
// 実行: php scripts/check_kinvoice.php

$tmp = sys_get_temp_dir() . '/kinvoice-check-' . getmypid();
@mkdir($tmp, 0700, true);
define('KINV_DATA_DIR', $tmp);

require_once __DIR__ . '/../public/kinvoice_pdf.php';   // 中で kinvoice_lib.php を読む

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/* ---- 税の内訳 ---- */
$t = kinv_tax_parts(110000, 10);
check('税込110,000の税抜', $t['net'], 100000);
check('税込110,000の消費税', $t['tax'], 10000);
check('内訳の合計が総額と一致', $t['net'] + $t['tax'], 110000);
$t8 = kinv_tax_parts(1080, 8);
check('8%の税抜', $t8['net'], 1000);
check('8%の消費税', $t8['tax'], 80);
// 端数が出ても合計は必ず総額に一致する（1円のズレを出さない）
$t9 = kinv_tax_parts(9999, 10);
check('端数ありでも合計一致', $t9['net'] + $t9['tax'], 9999);
check('税率0は全額が税抜', kinv_tax_parts(5000, 0)['tax'], 0);

/* ---- 発行 ---- */
check('最初は0件', count(kinv_all()), 0);
$res = kinv_create('株式会社アリス', 'Keiri@Alice.co.JP', 110000, 10, 'システム開発費', '2026-08-05');
check('発行できる', $res[0], true);
$a = $res[1];
check('番号の連番', substr($a['no'], -4), '0001');
check('メールは小文字で正規化', $a['email'], 'keiri@alice.co.jp');
check('トークンは32桁', strlen($a['token']), 32);
check('初期は未送信', $a['mail_ok'], false);

$b = kinv_create('株式会社ボブ', 'bob@bob.co.jp', 5500, 10, '保守費', '2026-08-05')[1];
check('連番が進む', substr($b['no'], -4), '0002');
check('トークンは重複しない', $a['token'] === $b['token'], false);

/* ---- トークンでの取得 ---- */
check('トークンで引ける', kinv_find_by_token($a['token'])['customer'], '株式会社アリス');
check('別のトークンでは別の領収書', kinv_find_by_token($b['token'])['customer'], '株式会社ボブ');
check('でたらめなトークンでは引けない', kinv_find_by_token('0'.str_repeat('a',31)), null);
check('空のトークンでは引けない', kinv_find_by_token(''), null);

/* ---- メールアドレス認証（ここが破れると他人の領収書が漏れる）---- */
$stored = kinv_norm_email(kinv_find_by_token($a['token'])['email']);
check('正しいアドレスは一致', hash_equals($stored, kinv_norm_email('keiri@alice.co.jp')), true);
check('大文字小文字を無視して一致', hash_equals($stored, kinv_norm_email('KEIRI@ALICE.CO.JP')), true);
check('前後の空白を無視して一致', hash_equals($stored, kinv_norm_email('  keiri@alice.co.jp ')), true);
check('別人のアドレスでは不一致', hash_equals($stored, kinv_norm_email('bob@bob.co.jp')), false);
check('部分一致では通らない', hash_equals($stored, kinv_norm_email('keiri@alice.co')), false);

/* ---- 失敗回数のロック ---- */
for ($i = 1; $i < KINV_MAX_FAIL; $i++) { kinv_mark_fail($a['id']); }
check('上限未満ではロックしない', kinv_find($a['id'])['fail'] >= KINV_MAX_FAIL, false);
$n = kinv_mark_fail($a['id']);
check('上限に達するとロック', $n >= KINV_MAX_FAIL, true);
kinv_unlock($a['id']);
check('解除できる', kinv_find($a['id'])['fail'], 0);

/* ---- ダウンロード記録 ---- */
kinv_mark_download($a['id']);
kinv_mark_download($a['id']);
check('ダウンロード回数を数える', count(kinv_find($a['id'])['downloads']), 2);
check('ボブ側は増えない', count(kinv_find($b['id'])['downloads']), 0);

/* ---- 送信結果の記録 ---- */
kinv_mark_sent($a['id'], true);
check('送信成功を記録', kinv_find($a['id'])['mail_ok'], true);
kinv_mark_sent($b['id'], false);
check('送信失敗も記録', kinv_find($b['id'])['mail_ok'], false);

/* ---- 履歴の並び ---- */
check('履歴は新しい順', kinv_recent()[0]['no'], $b['no']);

/* ---- 領収書PDF ---- */
$pdf = kinv_receipt_pdf(kinv_find($a['id']));
check('PDFのヘッダ', substr($pdf, 0, 8), '%PDF-1.4');
check('PDFの終端', substr($pdf, -5), '%%EOF');
check('中身がある', strlen($pdf) > 3000, true);
// ASCIIとCJKは別々に描画されるので、それぞれの断片で確認する
check('「領収書」が入っている', strpos($pdf, kinv_pdf_hex('領収書')) !== false, true);
check('宛名が入っている', strpos($pdf, kinv_pdf_hex('株式会社アリス')) !== false, true);
check('但し書きが入っている', strpos($pdf, kinv_pdf_hex('システム開発費')) !== false, true);
check('金額が入っている', strpos($pdf, '110,000') !== false, true);
check('発行元が入っている', strpos($pdf, kinv_pdf_hex('株式会社エクスブリッジ')) !== false, true);
check('印紙不要の注記', strpos($pdf, kinv_pdf_hex('収入印紙')) !== false, true);

@unlink(KINV_LEDGER);
@rmdir($tmp);
echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
