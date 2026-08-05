<?php
/**
 * 管理者認証。2つのモードを持つ。
 *
 *   password（既定） — 設定に入れたパスワードハッシュで入る。
 *                      どのサーバーでも動く。導入したら普通はこちら。
 *   x               — X(Twitter)ログイン。auth_common.php が同じ場所に
 *                      必要で、OAuth を受ける側の準備も要る。自社運用向け。
 *
 * 設定を置かない状態では誰も入れない（KINV_ADMIN_PASSWORD_HASH も
 * KINV_ADMIN も空なら、常に false を返す）。
 */
require_once __DIR__ . '/kinvoice_lib.php';

function kinv_auth_mode() {
    $m = defined('KINV_AUTH') ? strtolower(KINV_AUTH) : 'password';
    return $m === 'x' ? 'x' : 'password';
}

/** 設置先のURL。設定が無ければリクエストから組み立てる（サブディレクトリ可）。 */
function kinv_base_url() {
    if (defined('KINV_BASE_URL') && KINV_BASE_URL !== '') { return rtrim(KINV_BASE_URL, '/'); }
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME'])
        ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';
    if ($dir === '/' || $dir === '.') { $dir = ''; }
    return $scheme . '://' . $host . $dir;
}

/* ---------------- パスワードモード ---------------- */

define('KINV_LOGIN_FAILS', KINV_DATA_DIR . '/login_fails.json');
define('KINV_LOGIN_MAX_FAIL', 8);
define('KINV_LOGIN_LOCK_SEC', 900);  // 15分

function kinv_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/** 総当たり対策。IPごとに失敗を数え、一定回数で一定時間止める。 */
function kinv_login_locked() {
    if (!file_exists(KINV_LOGIN_FAILS)) { return false; }
    $d = json_decode((string)@file_get_contents(KINV_LOGIN_FAILS), true);
    $ip = kinv_client_ip();
    if (!is_array($d) || !isset($d[$ip])) { return false; }
    $e = $d[$ip];
    return (int)$e['n'] >= KINV_LOGIN_MAX_FAIL && (time() - (int)$e['at']) < KINV_LOGIN_LOCK_SEC;
}

function kinv_login_record_fail() {
    if (!is_dir(KINV_DATA_DIR)) { @mkdir(KINV_DATA_DIR, 0705, true); }
    $fp = @fopen(KINV_LOGIN_FAILS, 'c+');
    if (!$fp) { return; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) { $d = array(); }
    $ip = kinv_client_ip();
    $now = time();
    // 古い記録は捨てる（ファイルが際限なく育たないように）
    foreach ($d as $k => $v) {
        if ($now - (int)$v['at'] > KINV_LOGIN_LOCK_SEC * 4) { unset($d[$k]); }
    }
    $n = (isset($d[$ip]) && $now - (int)$d[$ip]['at'] < KINV_LOGIN_LOCK_SEC) ? (int)$d[$ip]['n'] + 1 : 1;
    $d[$ip] = array('n' => $n, 'at' => $now);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($d));
    fflush($fp);
    @chmod(KINV_LOGIN_FAILS, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function kinv_login_clear_fail() {
    if (!file_exists(KINV_LOGIN_FAILS)) { return; }
    $d = json_decode((string)@file_get_contents(KINV_LOGIN_FAILS), true);
    if (!is_array($d)) { return; }
    unset($d[kinv_client_ip()]);
    @file_put_contents(KINV_LOGIN_FAILS, json_encode($d), LOCK_EX);
}

function kinv_password_hash_set() {
    return defined('KINV_ADMIN_PASSWORD_HASH') && KINV_ADMIN_PASSWORD_HASH !== '';
}

/** パスワードを照合してログインする。 */
function kinv_password_login($password) {
    if (!kinv_password_hash_set()) { return false; }
    if (kinv_login_locked()) { return false; }
    if (!password_verify((string)$password, KINV_ADMIN_PASSWORD_HASH)) {
        kinv_login_record_fail();
        // 総当たりの速度を落とす
        usleep(400000);
        return false;
    }
    kinv_login_clear_fail();
    session_regenerate_id(true);   // ログイン後にIDを変える（固定化対策）
    $_SESSION['kinv_admin'] = true;
    return true;
}

/* ---------------- 共通 ---------------- */

/** セッションを開始する。モードによって担当が違う。 */
function kinv_auth_start() {
    if (kinv_auth_mode() === 'x') {
        // auth_common.php 側がセッションを持つ
        if (!function_exists('url2ai_auth_bootstrap')) {
            if (file_exists(__DIR__ . '/config.php'))      { require_once __DIR__ . '/config.php'; }
            if (file_exists(__DIR__ . '/auth_common.php')) { require_once __DIR__ . '/auth_common.php'; }
        }
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('KINVSESSID');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params(0, '/', '', $secure, true);
        @session_start();
    }
}

/** いま管理者としてログインしているか。 */
function kinv_is_admin() {
    if (kinv_auth_mode() === 'x') {
        if (!function_exists('url2ai_auth_bootstrap')) { return false; }
        if (KINV_ADMIN === '') { return false; }
        $auth = url2ai_auth_bootstrap();
        if (empty($auth['logged_in'])) { return false; }
        $user = strtolower(ltrim(trim((string)$auth['session_user']), '@'));
        return $user !== '' && hash_equals(strtolower(KINV_ADMIN), $user);
    }
    return !empty($_SESSION['kinv_admin']) && kinv_password_hash_set();
}

function kinv_auth_logout() {
    if (kinv_auth_mode() === 'x') { return; }  // 画面側がリダイレクトを組む
    unset($_SESSION['kinv_admin']);
    @session_destroy();
}

/** 導入が終わっていない項目を返す。管理画面で案内するために使う。 */
function kinv_setup_missing() {
    $miss = kinv_config_missing();
    if (kinv_auth_mode() === 'x') {
        if (KINV_ADMIN === '') { $miss[] = 'KINV_ADMIN（Xアカウント）'; }
        if (!file_exists(__DIR__ . '/auth_common.php')) { $miss[] = 'auth_common.php（Xログイン用。同じ場所に必要）'; }
    } elseif (!kinv_password_hash_set()) {
        $miss[] = 'KINV_ADMIN_PASSWORD_HASH（管理パスワード。scripts/make_password_hash.php で作成）';
    }
    return array_values(array_unique($miss));
}
