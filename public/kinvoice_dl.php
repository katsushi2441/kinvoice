<?php
/**
 * Kurage Send Invoice — 領収書のダウンロードページ（お客様用）。
 *
 * URLのトークン(32桁)＋宛先メールアドレスの2つが揃わないと開かない。
 * ログインは不要。お客様にアカウントを作らせないための作り。
 *
 * 認証前は領収書の中身（顧客名・金額）を一切出さない。トークンだけが
 * 漏れた場合に、誰宛のいくらの領収書かが分かってしまうため。
 */
require_once __DIR__ . '/kinvoice_lib.php';
require_once __DIR__ . '/kinvoice_pdf.php';
date_default_timezone_set('Asia/Tokyo');

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$r = kinv_find_by_token($token);

$error = '';
$locked = false;

if ($r) {
    $locked = (int)(isset($r['fail']) ? $r['fail'] : 0) >= KINV_MAX_FAIL;

    if (!$locked && isset($_POST['email'])) {
        $input = kinv_norm_email($_POST['email']);
        if ($input !== '' && hash_equals(kinv_norm_email($r['email']), $input)) {
            kinv_mark_download($r['id']);
            $pdf = kinv_receipt_pdf($r);
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $r['no'] . '.pdf"');
            header('Content-Length: ' . strlen($pdf));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
            echo $pdf;
            exit;
        }
        $n = kinv_mark_fail($r['id']);
        $locked = $n >= KINV_MAX_FAIL;
        // 「そのアドレスは登録されていません」とは言わない（総当たりの手がかりになる）
        $error = $locked
            ? 'ご入力の回数が上限に達しました。お手数ですが発行元へご連絡ください。'
            : 'メールアドレスが確認できませんでした。領収書をお送りしたメールの宛先をご入力ください。';
    }
}

$issuer = kinv_issuer();
function h($v) { return kinv_h($v); }
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>領収書のダウンロード | <?php echo h($issuer['name']); ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{
  --abyss:#12202f; --abyss-soft:#55697a; --foam:#f5fbfb; --panel:#e7f3f2; --panel-line:#cde5e2;
  --teal:#12a99f; --teal-deep:#0a726b; --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
  --shadow:0 14px 40px rgba(10,40,45,.10);
}
@media(prefers-color-scheme:dark){:root{
  --abyss:#eaf3f3; --abyss-soft:#9fb3ba; --foam:#0c1720; --panel:#12242a; --panel-line:#1f3a3f;
  --teal:#2bd4c6; --teal-deep:#1c9e93; --gold:#f2c766; --gold-bg:#241b08; --gold-line:#4c3c17;
  --shadow:0 14px 40px rgba(0,0,0,.38);
}}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--abyss);background:var(--foam);line-height:1.9;min-height:100vh;
  display:flex;align-items:center;justify-content:center;padding:28px 18px;
  font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif}
a{color:var(--teal-deep);text-decoration:none}
h1{font-family:"Zen Maru Gothic",sans-serif;font-size:clamp(19px,4vw,24px);font-weight:900;margin-bottom:8px}
.box{max-width:460px;width:100%;background:var(--panel);border:1.5px solid var(--panel-line);
  border-radius:20px;padding:32px 28px;box-shadow:var(--shadow)}
.ico{width:52px;height:52px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);margin-bottom:16px}
.ico img{width:100%;height:100%;object-fit:cover;object-position:50% 12%;display:block}
.lead{font-size:13.5px;color:var(--abyss-soft);margin-bottom:20px}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
input[type=email]{width:100%;font:inherit;font-size:16px;color:inherit;background:var(--foam);
  border:1.5px solid var(--panel-line);border-radius:10px;padding:12px 13px}
input[type=email]:focus{outline:2px solid var(--teal);border-color:var(--teal)}
.btn{width:100%;border:0;border-radius:999px;padding:13px 24px;font-weight:900;font-size:15px;
  cursor:pointer;margin-top:16px;font-family:inherit;
  background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;
  box-shadow:0 10px 24px rgba(18,169,159,.28)}
.err{background:#fdecea;border:1.5px solid #f5c6c2;color:#a3261b;border-radius:10px;
  padding:11px 13px;font-size:13px;margin-bottom:16px}
@media(prefers-color-scheme:dark){.err{background:#2a1512;border-color:#5c2a24;color:#ff9d92}}
.note{font-size:11.5px;color:var(--abyss-soft);margin-top:18px;border-top:1px solid var(--panel-line);padding-top:14px}
</style>
</head>
<body>
<div class="box">
  <div class="ico"><img src="images/kurage-ecosystem-avatar.png" alt="Kurage"></div>

<?php if (!$r): ?>
  <h1>ページが見つかりません</h1>
  <p class="lead">お手数ですが、メールに記載されたURLをもう一度ご確認ください。
    URLが途中で改行されていると開けないことがあります。</p>

<?php elseif ($locked): ?>
  <h1>ダウンロードを停止しています</h1>
  <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
  <p class="lead">メールアドレスのご入力が規定回数に達したため、一時的に停止しました。
    お手数ですが、下記までご連絡ください。</p>

<?php else: ?>
  <h1>領収書のダウンロード</h1>
  <p class="lead">ご本人さま確認のため、<b>領収書をお送りしたメールの宛先</b>をご入力ください。</p>

  <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>

  <form method="post">
    <label for="email">メールアドレス</label>
    <input type="email" id="email" name="email" required autocomplete="email"
           placeholder="example@your-company.co.jp" autofocus>
    <button type="submit" class="btn">領収書をダウンロード</button>
  </form>
<?php endif; ?>

  <p class="note">
    <?php echo h($issuer['name']); ?><br>
    <?php if ($issuer['invoice_no'] !== ''): ?>登録番号 <?php echo h($issuer['invoice_no']); ?><br><?php endif; ?>
    <?php echo h($issuer['zip'] . ' ' . $issuer['addr']); ?><br>
    <a href="mailto:<?php echo h($issuer['mail']); ?>"><?php echo h($issuer['mail']); ?></a>
  </p>
</div>
</body>
</html>
