<?php
/**
 * 領収書の登録・送信（管理者専用）。
 *
 * 金額・顧客名・発行日・メールアドレスを入れると、領収書PDFを作り、
 * ダウンロードURLをメールで送る。PDFはメールに添付せず、推測困難な
 * URL＋メールアドレス確認の先に置く（誤送信で第三者に届いても開けない）。
 *
 * 認証は kinvoice_auth.php（既定はパスワード。設定でXログインにもできる）。
 * 発行元・製品名・設置先URLはすべて kinvoice_config.php で決める。
 * このファイルに自社の情報を書かないこと。
 */
require_once __DIR__ . '/kinvoice_auth.php';
require_once __DIR__ . '/kinvoice_pdf.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE = basename(__FILE__);
kinv_auth_start();

$login_error = '';

if (isset($_GET['logout'])) {
    if (kinv_auth_mode() === 'x' && function_exists('url2ai_auth_logout_url')) {
        header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE)); exit;
    }
    kinv_auth_logout();
    header('Location: ' . $THIS_FILE); exit;
}
if (isset($_GET['login']) && kinv_auth_mode() === 'x' && function_exists('url2ai_auth_login_url')) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE)); exit;
}
if (kinv_auth_mode() === 'password' && isset($_POST['do_login'])) {
    if (kinv_login_locked()) {
        $login_error = '試行回数が多いため、しばらく時間をおいてからお試しください。';
    } elseif (!kinv_password_login(isset($_POST['password']) ? $_POST['password'] : '')) {
        $login_error = 'パスワードが違います。';
    } else {
        header('Location: ' . $THIS_FILE); exit;
    }
}

$is_admin = kinv_is_admin();
$missing  = kinv_setup_missing();
kinv_demo_cleanup();   // デモの台帳が育ち続けないように

if (empty($_SESSION['kinv_csrf'])) { $_SESSION['kinv_csrf'] = kinv_random_hex(24); }
$csrf = (string)$_SESSION['kinv_csrf'];

function kinv_dl_url($token) {
    return kinv_base_url() . '/kinvoice_dl.php?t=' . rawurlencode($token);
}

/* ---- 管理者が自分の控えとしてPDFを見る ---- */
if ($is_admin && isset($_GET['pdf'])) {
    $r = kinv_find((string)$_GET['pdf']);
    if (!$r) { http_response_code(404); exit('領収書が見つかりません'); }
    $pdf = kinv_receipt_pdf($r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $r['no'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, max-age=0');
    echo $pdf;
    exit;
}

$error = '';
$notice = '';

/* ---- 登録して送信 ---- */
if ($is_admin && isset($_POST['issue'])) {
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!$sent || !hash_equals($csrf, $sent)) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } else {
        $customer = trim((string)(isset($_POST['customer']) ? $_POST['customer'] : ''));
        $email    = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
        $total    = (int)(isset($_POST['total']) ? $_POST['total'] : 0);
        $rate     = (int)(isset($_POST['tax_rate']) ? $_POST['tax_rate'] : 10);
        $note     = trim((string)(isset($_POST['note']) ? $_POST['note'] : ''));
        $issued   = trim((string)(isset($_POST['issued_on']) ? $_POST['issued_on'] : ''));

        if ($customer === '' || $email === '' || $total <= 0) {
            $error = '顧客名・メールアドレス・金額は必須です。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'メールアドレスの形式が正しくありません。';
        } elseif (mb_strlen($customer, 'UTF-8') > 100 || mb_strlen($note, 'UTF-8') > 60) {
            $error = '入力が長すぎます。';
        } elseif ($total > 100000000) {
            $error = '金額が大きすぎます。';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued) || !strtotime($issued)) {
            $error = '発行日の形式が正しくありません。';
        } elseif (!in_array($rate, array(0, 8, 10), true)) {
            $error = '税率は 0 / 8 / 10 のいずれかです。';
        } else {
            $res = kinv_create($customer, $email, $total, $rate, $note, $issued);
            if (empty($res[0])) {
                $error = isset($res[1]) ? $res[1] : '登録できませんでした。';
            } else {
                $r = $res[1];
                $ok = kinv_send_mail($r, kinv_dl_url($r['token']));
                kinv_mark_sent($r['id'], $ok);
                header('Location: ' . $THIS_FILE . '?done=' . rawurlencode($r['id']) . ($ok ? '' : '&mailng=1'));
                exit;
            }
        }
    }
}

/* ---- 再送 / ロック解除 ---- */
if ($is_admin && isset($_POST['resend'])) {
    if (hash_equals($csrf, (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
        $r = kinv_find((string)$_POST['resend']);
        if ($r) {
            $ok = kinv_send_mail($r, kinv_dl_url($r['token']));
            kinv_mark_sent($r['id'], $ok);
            $notice = $ok ? ($r['no'] . ' を再送しました。') : ($r['no'] . ' の再送に失敗しました。');
            if (!$ok) { $error = $notice; $notice = ''; }
        }
    }
}
if ($is_admin && isset($_POST['unlock'])) {
    if (hash_equals($csrf, (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
        $res = kinv_unlock((string)$_POST['unlock']);
        $notice = !empty($res[0]) ? 'ロックを解除しました。' : '';
    }
}

$done = ($is_admin && isset($_GET['done'])) ? kinv_find((string)$_GET['done']) : null;
if ($done && isset($_GET['mailng'])) { $error = 'メールの送信に失敗しました。下の一覧から再送してください。'; }
elseif ($done) { $notice = $done['no'] . ' を発行し、' . $done['email'] . ' へ送信しました。'; }

$list = $is_admin ? kinv_recent() : array();
$issuer_name = kinv_issuer()['name'];
function h($v) { return kinv_h($v); }
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h(kinv_app_title()); ?></title>
<meta name="description" content="領収書PDFを発行し、ダウンロードURLをお客様へメールで送る管理ツールです。">
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
body{color:var(--abyss);background:var(--foam);line-height:1.9;
  font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif}
a{color:var(--teal-deep);text-decoration:none}
a:hover{text-decoration:underline}
h1,h2,h3{font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif}
.wrap{max-width:960px;margin:0 auto;padding:0 22px}
header.site{border-bottom:1px solid var(--panel-line);background:var(--panel)}
header.site .wrap{display:flex;align-items:center;gap:12px;padding:12px 22px;flex-wrap:wrap}
.hbrand{display:flex;gap:11px;align-items:center;color:inherit}
.hbrand:hover{text-decoration:none}
.hbrand .ico{width:38px;height:38px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex:none}
.hbrand .ico img{width:100%;height:100%;object-fit:cover;object-position:50% 12%;display:block}
.hbrand strong{font-size:14.5px;font-weight:900;display:block;line-height:1.25}
.hbrand span{font-size:11px;color:var(--abyss-soft)}
.hnav{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.chip{font-size:12px;font-weight:700;color:var(--abyss-soft);border:1px solid var(--panel-line);
  border-radius:999px;padding:4px 12px;background:var(--foam)}
a.chip:hover{text-decoration:none;border-color:var(--teal);color:var(--teal-deep)}
.btn{border:0;border-radius:999px;padding:11px 24px;font-weight:900;font-size:14px;cursor:pointer;
  display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--teal),var(--teal-deep));
  color:#fff;box-shadow:0 10px 24px rgba(18,169,159,.28);font-family:inherit}
.btn:hover{color:#fff;text-decoration:none}
.btn.mini{padding:5px 13px;font-size:12px;box-shadow:none}
.btn.ghost{background:transparent;color:var(--abyss-soft);border:1.5px solid var(--panel-line);box-shadow:none}
section{padding:28px 0}
h1{font-size:clamp(21px,3.4vw,28px);font-weight:900;margin-bottom:8px}
h2{font-size:18px;font-weight:900;margin-bottom:10px}
.lead{font-size:14px;color:var(--abyss-soft);margin-bottom:18px}
.card{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:18px;
  padding:24px;box-shadow:var(--shadow);margin-bottom:18px}
.card.plain{background:var(--foam)}
label{display:block;font-size:13px;font-weight:700;margin:14px 0 5px}
input[type=text],input[type=email],input[type=number],input[type=date],select{width:100%;font:inherit;font-size:15px;
  color:inherit;background:var(--foam);border:1.5px solid var(--panel-line);border-radius:10px;padding:10px 12px}
input:focus,select:focus{outline:2px solid var(--teal);border-color:var(--teal)}
.hint{font-size:11.5px;color:var(--abyss-soft);margin-top:4px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0 18px}
.err{background:#fdecea;border:1.5px solid #f5c6c2;color:#a3261b;border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:16px}
@media(prefers-color-scheme:dark){.err{background:#2a1512;border-color:#5c2a24;color:#ff9d92}}
.ok{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:12px;
  padding:13px 16px;font-size:13.5px;margin-bottom:16px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--panel-line);padding:12px 2px}
.row:first-child{border-top:0}
.row .grow{flex:1;min-width:210px}
.tag{font-size:11px;font-weight:800;border-radius:999px;padding:2px 10px;border:1.5px solid var(--panel-line);color:var(--abyss-soft)}
.tag.sent{border-color:var(--teal);color:var(--teal-deep)}
.tag.ng{border-color:#d98a83;color:#b2473f}
.tag.lock{border-color:var(--gold-line);color:var(--gold)}
.urlbox{font-size:11px;color:var(--abyss-soft);word-break:break-all;background:var(--foam);
  border:1px solid var(--panel-line);border-radius:8px;padding:5px 9px;margin-top:5px}
.demo{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:12px;
  padding:12px 16px;font-size:13px;margin:18px 0 0}
.empty-note{text-align:center;color:var(--abyss-soft);font-size:14px;padding:40px 20px}
footer.site{text-align:center;color:var(--abyss-soft);font-size:12.5px;padding:32px 20px 44px;
  border-top:1px solid var(--panel-line);margin-top:16px}
</style>
</head>
<body>

<header class="site"><div class="wrap">
  <a class="hbrand" href="<?php echo h($THIS_FILE); ?>">
<?php if (kinv_logo() !== ''): ?>
    <span class="ico"><img src="<?php echo h(kinv_logo()); ?>" alt=""></span>
<?php endif; ?>
    <div><strong><?php echo h(kinv_app_title()); ?></strong>
      <span><?php echo h($issuer_name !== '' ? $issuer_name : '発行元 未設定'); ?></span></div>
  </a>
  <nav class="hnav">
<?php if ($is_admin): ?>
    <a class="chip" href="?logout=1">ログアウト</a>
<?php elseif (kinv_auth_mode() === 'x'): ?>
    <a class="chip" href="?login=1">𝕏 でログイン</a>
<?php endif; ?>
  </nav>
</div></header>

<main class="wrap">

<?php if (kinv_is_demo()): ?>
  <p class="demo">これは<b>デモ</b>です。自由に発行して試せます。<b>メールは実際には送信されません。</b>入力した内容は24時間で消えます。</p>
<?php endif; ?>

<?php if (!$is_admin): ?>
  <section>
    <h1><?php echo h(kinv_app_title()); ?></h1>

    <?php if ($missing): ?>
      <div class="card plain">
        <h2>初期設定が終わっていません</h2>
        <p style="font-size:14px">同じディレクトリの <code>kinvoice_config.php</code> に、次の項目を設定してください。
          <code>kinvoice_config.php.example</code> をコピーして作れます。</p>
        <ul style="font-size:14px;padding-left:22px;margin-top:10px">
          <?php foreach ($missing as $m): ?><li><code><?php echo h($m); ?></code></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (kinv_auth_mode() === 'x'): ?>
      <p class="lead">この画面は管理者専用です。𝕏 でログインしてください。</p>
      <p><a class="btn" href="?login=1">𝕏 でログイン</a></p>
    <?php elseif (kinv_password_hash_set()): ?>
      <p class="lead">管理パスワードを入力してください。</p>
      <?php if ($login_error !== ''): ?><p class="err"><?php echo h($login_error); ?></p><?php endif; ?>
      <form method="post" class="card" style="max-width:420px">
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" required autofocus autocomplete="current-password"
               style="width:100%;font:inherit;font-size:16px;color:inherit;background:var(--foam);
                      border:1.5px solid var(--panel-line);border-radius:10px;padding:10px 12px">
        <button type="submit" name="do_login" value="1" class="btn" style="margin-top:16px">ログイン</button>
      </form>
    <?php endif; ?>
  </section>

<?php else: ?>
  <?php if ($missing): ?>
    <p class="err" style="margin-top:22px">設定が未完了です：<?php echo h(implode(' / ', $missing)); ?></p>
  <?php endif; ?>
  <section>
    <h1>領収書を発行する</h1>
    <p class="lead">金額・顧客名・発行日・メールアドレスを入れると、領収書PDFを作り、
      <b>ダウンロードURLをメールで送ります。</b>PDFはメールに添付しません。</p>

    <?php if ($notice !== ''): ?><p class="ok"><?php echo h($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>

    <?php if ($done): ?>
    <div class="card plain">
      <h2><?php echo h($done['no']); ?> のダウンロードURL</h2>
      <div class="urlbox"><?php echo h(kinv_dl_url($done['token'])); ?></div>
      <p class="hint">お客様には <b><?php echo h($done['email']); ?></b> の入力を求めます。
        <?php if (kinv_is_demo()): ?>
          <b>このURLを開いて、上のアドレスを入力してみてください。</b>
        <?php else: ?>
          メールが届かない場合は、このURLを直接お伝えください。
        <?php endif; ?></p>
      <p style="margin-top:10px">
        <a class="btn" href="<?php echo h(kinv_dl_url($done['token'])); ?>" target="_blank" rel="noopener">ダウンロードページを開く</a>
        <a class="btn mini ghost" href="?pdf=<?php echo h($done['id']); ?>" target="_blank" rel="noopener"
           style="margin-left:8px">PDFを確認</a></p>
    </div>
    <?php if (kinv_is_demo()): ?>
    <div class="card plain">
      <h2>お客様に届くメール</h2>
      <p class="hint" style="margin-bottom:10px">デモでは実際には送信しません。本番ではこの内容が届きます。</p>
      <div style="font-size:12px;color:var(--abyss-soft);border:1px solid var(--panel-line);
                  border-radius:10px;padding:8px 12px;margin-bottom:8px">
        件名: <?php echo h(kinv_mail_subject($done)); ?></div>
      <pre style="font-size:12.5px;line-height:1.8;white-space:pre-wrap;word-break:break-all;
                  background:var(--foam);border:1px solid var(--panel-line);border-radius:10px;
                  padding:14px 16px;font-family:ui-monospace,Menlo,Consolas,monospace"><?php
        echo h(kinv_mail_body($done, kinv_dl_url($done['token']))); ?></pre>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
      <div class="grid2">
        <div>
          <label for="customer">顧客名（宛名）<span style="color:#c0392b">*</span></label>
          <input type="text" id="customer" name="customer" maxlength="100" required
                 placeholder="例：株式会社テスト商事"
                 value="<?php echo h(isset($_POST['customer']) ? $_POST['customer'] : ''); ?>">
          <p class="hint">「様」は自動で付きます。</p>
        </div>
        <div>
          <label for="email">メールアドレス<span style="color:#c0392b">*</span></label>
          <input type="email" id="email" name="email" maxlength="200" required
                 placeholder="例：keiri@example.co.jp"
                 value="<?php echo h(isset($_POST['email']) ? $_POST['email'] : ''); ?>">
          <p class="hint">送信先です。ダウンロード時の本人確認にも使います。</p>
        </div>
        <div>
          <label for="total">金額（税込・円）<span style="color:#c0392b">*</span></label>
          <input type="number" id="total" name="total" min="1" max="100000000" step="1" required
                 placeholder="110000"
                 value="<?php echo h(isset($_POST['total']) ? $_POST['total'] : ''); ?>">
          <p class="hint">領収書は税込総額で記載します。内訳は自動計算します。</p>
        </div>
        <div>
          <label for="tax_rate">消費税率</label>
          <select id="tax_rate" name="tax_rate">
            <option value="10" selected>10%</option>
            <option value="8">8%（軽減税率）</option>
            <option value="0">対象外</option>
          </select>
        </div>
        <div>
          <label for="issued_on">発行日<span style="color:#c0392b">*</span></label>
          <input type="date" id="issued_on" name="issued_on" required
                 value="<?php echo h(isset($_POST['issued_on']) ? $_POST['issued_on'] : date('Y-m-d')); ?>">
        </div>
        <div>
          <label for="note">但し書き</label>
          <input type="text" id="note" name="note" maxlength="60" placeholder="空欄なら「お品代」"
                 value="<?php echo h(isset($_POST['note']) ? $_POST['note'] : ''); ?>">
          <p class="hint">「但し　◯◯　として」と印字されます。空欄のままなら「お品代」になります。</p>
        </div>
      </div>
      <button type="submit" name="issue" value="1" class="btn" style="margin-top:20px">
        発行してメールで送る</button>
    </form>
  </section>

  <section>
    <h2>領収書 送信履歴</h2>
    <?php if (!$list): ?>
      <p class="empty-note">まだ発行した領収書はありません。</p>
    <?php else: ?>
    <div class="card">
      <?php foreach ($list as $r):
        $locked = (int)(isset($r['fail']) ? $r['fail'] : 0) >= KINV_MAX_FAIL;
        $dl = isset($r['downloads']) ? count($r['downloads']) : 0; ?>
      <div class="row">
        <div class="grow">
          <b><?php echo h($r['no']); ?></b>　<?php echo h($r['customer']); ?> 様<br>
          <span style="color:var(--abyss-soft);font-size:12.5px">
            <?php echo h($r['issued_on']); ?> ·
            <?php echo number_format((int)$r['total']); ?>円（税込<?php echo (int)$r['tax_rate']; ?>%） ·
            <?php echo h($r['email']); ?> ·
            DL <?php echo $dl; ?>回
            <?php if ($dl > 0): ?>（最終 <?php echo date('n/j H:i', (int)end($r['downloads'])); ?>）<?php endif; ?>
          </span>
          <div class="urlbox"><?php echo h(kinv_dl_url($r['token'])); ?></div>
        </div>
        <?php if ($locked): ?><span class="tag lock">ロック中</span><?php endif; ?>
        <span class="tag <?php echo !empty($r['mail_ok']) ? 'sent' : 'ng'; ?>">
          <?php echo !empty($r['mail_ok']) ? '送信済' : '送信失敗'; ?></span>
        <a class="chip" href="?pdf=<?php echo h($r['id']); ?>" target="_blank" rel="noopener">PDF</a>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <button type="submit" name="resend" value="<?php echo h($r['id']); ?>"
                  class="btn mini ghost">再送</button>
        </form>
        <?php if ($locked): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <button type="submit" name="unlock" value="<?php echo h($r['id']); ?>"
                  class="btn mini">解除</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

</main>

<footer class="site"><div class="wrap">
  <?php echo h(kinv_app_title()); ?><?php echo $issuer_name !== '' ? ' — ' . h($issuer_name) : ''; ?>
</div></footer>
</body>
</html>
