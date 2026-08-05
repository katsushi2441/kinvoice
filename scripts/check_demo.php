<?php
// デモモードの確認。とくに「メールを実際に送らないこと」を固定する。
// 実行: php scripts/check_demo.php
//
// 定数は1プロセスで切り替えられないので、通常モードのテスト
// (check_kinvoice.php) とは別プロセスで走らせる。

$tmp = sys_get_temp_dir() . '/kinvoice-demo-check-' . getmypid();
@mkdir($tmp, 0700, true);
define('KINV_DATA_DIR', $tmp);
define('KINV_CONFIG_FILE', '');
define('KINV_DEMO', true);
define('KINV_NAME', 'デモ商事株式会社');
define('KINV_MAIL_FROM', 'demo@example.test');

require_once __DIR__ . '/../public/kinvoice_pdf.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

check('デモモードとして認識される', kinv_is_demo(), true);

/* ---- メールを実際に送らないこと（ここが一番大事）----
 * 公開デモで実送信すると、誰でも任意のアドレス宛に送れる踏み台になり、
 * ドメインの送信評価が落ちて本物の領収書まで届かなくなる。 */
$r = kinv_create('デモ顧客', 'demo-customer@example.test', 11000, 10, 'デモ', date('Y-m-d'))[1];
// mail() を呼ぶと PHP の警告やMTA接続が起きうる。デモでは手前で返るはず。
$before = error_get_last();
$sent = kinv_send_mail($r, 'https://example.test/kinvoice_dl.php?t=' . $r['token']);
$after = error_get_last();
check('送信は成功扱いで返る', $sent, true);
check('mail() に到達していない（新しいエラーが出ていない）', $before === $after, true);

/* ---- 画面に出すための本文が作れること ---- */
$body = kinv_mail_body($r, 'https://example.test/dl?t=x');
check('本文に宛名が入る', strpos($body, 'デモ顧客 様') !== false, true);
check('本文にダウンロードURLが入る', strpos($body, 'https://example.test/dl?t=x') !== false, true);
check('本文に発行元が入る', strpos($body, 'デモ商事株式会社') !== false, true);
check('件名が作れる', strpos(kinv_mail_subject($r), $r['no']) !== false, true);

/* ---- 台帳が育ち続けないこと ---- */
for ($i = 0; $i < 5; $i++) {
    kinv_create('連番' . $i, 'x' . $i . '@example.test', 1100, 10, '', date('Y-m-d'));
}
check('いま6件', count(kinv_all()), 6);

// 古い記録に見せかけて、掃除されることを確かめる
kinv_update(function (&$data) {
    foreach ($data['receipts'] as $i => $x) {
        if ($i < 3) { $data['receipts'][$i]['created_at'] = time() - 90000; }  // 25時間前
    }
    return array(true, '');
});
kinv_demo_cleanup();
check('24時間より古いものが消える', count(kinv_all()), 3);

// 上限
for ($i = 0; $i < 10; $i++) {
    kinv_create('多数' . $i, 'y' . $i . '@example.test', 1100, 10, '', date('Y-m-d'));
}
kinv_demo_cleanup(86400, 5);
check('上限を超えたら古い順に捨てる', count(kinv_all()), 5);

/* ---- 通常モードでは掃除しないこと（本番の台帳を消さない）---- */
// KINV_DEMO は定数なのでこのプロセスでは戻せない。別プロセスの
// check_kinvoice.php 側で「デモではない」ことを確認している。

@unlink(KINV_LEDGER);
@rmdir($tmp);
echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
