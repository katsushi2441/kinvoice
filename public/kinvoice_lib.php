<?php
/**
 * Kurage Send Invoice — 領収書の台帳とメール送信。
 *
 * heteml にDBを置かない方針（kgeo/karchitect/kappstore と同じ）。
 * JSONを flock で直列化する。更新は必ず kinv_update() を通すこと。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

if (!defined('KINV_DATA_DIR')) { define('KINV_DATA_DIR', __DIR__ . '/kinvoice_data'); }
define('KINV_LEDGER', KINV_DATA_DIR . '/receipts.json');

// 使えるのはこの人だけ。領収書は会社名義の発行物なので、他人に触らせない。
if (!defined('KINV_ADMIN')) { define('KINV_ADMIN', 'xb_bittensor'); }

// メール認証の総当たり対策。これを超えたら管理者が解除するまで開かない。
define('KINV_MAX_FAIL', 10);

/**
 * 発行元。
 *
 * 領収書に住所は法定の記載事項ではない（適格請求書の記載事項に住所は含まれず、
 * 登録番号があれば国税庁の公表サイトで事業者を特定できる）。慣行に合わせて
 * 1行だけ入れ、建物名は省いて部屋番号のみとする。電話・FAXは載せない。
 *
 * メールは送信元(From)にも使う。heteml から送るので、SPFに heteml が
 * 入っているドメインでなければ受信側に捨てられる。
 *   exbridge.jp   v=spf1 include:_spf.heteml.jp  → 送れる
 *   exdirect.net  v=spf1 redirect=_spf.ocnk.net  → 送れない（2026-08-05に実際に不達）
 */
function kinv_issuer() {
    return array(
        'name'  => '株式会社エクスブリッジ',
        'zip'   => '〒467-0853',
        'addr'  => '愛知県名古屋市瑞穂区内浜町34-9-305',
        'mail'  => defined('KINV_MAIL_FROM') ? KINV_MAIL_FROM : 'info@exbridge.jp',
        // 適格請求書発行事業者の登録番号。空なら領収書に印字しない
        // （存在しない番号を書かないため）。
        'invoice_no' => defined('KINV_INVOICE_REG_NO') ? KINV_INVOICE_REG_NO : 'T4180001056508',
    );
}

function kinv_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** random_bytes は PHP 7 以降。5.x でも動くよう退避経路を持つ。 */
function kinv_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    $out = '';
    for ($i = 0; $i < $bytes * 2; $i++) { $out .= dechex(mt_rand(0, 15)); }
    return $out;
}

function kinv_update($callback) {
    if (!is_dir(KINV_DATA_DIR) && !@mkdir(KINV_DATA_DIR, 0705, true)) {
        return array(false, '台帳ディレクトリを作成できません');
    }
    $fp = @fopen(KINV_LEDGER, 'c+');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    rewind($fp);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data)) { $data = array(); }
    if (!isset($data['receipts']) || !is_array($data['receipts'])) { $data['receipts'] = array(); }
    if (!isset($data['seq'])) { $data['seq'] = 0; }
    $result = $callback($data);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    @chmod(KINV_LEDGER, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function kinv_all() {
    if (!file_exists(KINV_LEDGER)) { return array(); }
    $fp = @fopen(KINV_LEDGER, 'r');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode((string)$raw, true);
    return (is_array($d) && isset($d['receipts']) && is_array($d['receipts'])) ? $d['receipts'] : array();
}

/** 新しい順。管理画面の履歴一覧はこれを使う。 */
function kinv_recent() {
    $list = kinv_all();
    return array_reverse($list);
}

/** ダウンロードURLのトークンで引く。見つからなければ null。 */
function kinv_find_by_token($token) {
    $token = (string)$token;
    if ($token === '') { return null; }
    foreach (kinv_all() as $r) {
        // トークンは秘密なので、比較も定数時間で行う
        if (hash_equals((string)$r['token'], $token)) { return $r; }
    }
    return null;
}

function kinv_find($id) {
    foreach (kinv_all() as $r) { if ($r['id'] === $id) { return $r; } }
    return null;
}

function kinv_norm_email($email) { return strtolower(trim((string)$email)); }

/**
 * 税込金額から内訳を出す。領収書は税込総額を書くのが実務なので、入力も税込。
 * 端数は切り捨て（内訳の合計が総額を超えないように）。
 */
function kinv_tax_parts($total, $rate) {
    $total = (int)$total;
    $rate = (int)$rate;
    if ($rate <= 0) { return array('total' => $total, 'net' => $total, 'tax' => 0, 'rate' => 0); }
    $net = (int)floor($total * 100 / (100 + $rate));
    return array('total' => $total, 'net' => $net, 'tax' => $total - $net, 'rate' => $rate);
}

/** 領収書を登録する。番号とダウンロードトークンはここで採番する。 */
function kinv_create($customer, $email, $total, $rate, $note, $issued_on) {
    return kinv_update(function (&$data) use ($customer, $email, $total, $rate, $note, $issued_on) {
        $data['seq'] = (int)$data['seq'] + 1;
        $r = array(
            'id'        => kinv_random_hex(8),
            // 推測されると他人の領収書が開くので、ここは16バイト(32桁)使う
            'token'     => kinv_random_hex(16),
            'no'        => 'RCP-' . date('Ymd', strtotime($issued_on)) . '-' . sprintf('%04d', $data['seq']),
            'customer'  => $customer,
            'email'     => kinv_norm_email($email),
            'total'     => (int)$total,
            'tax_rate'  => (int)$rate,
            'note'      => $note,
            'issued_on' => $issued_on,
            'created_at'=> time(),
            'sent_at'   => 0,
            'mail_ok'   => false,
            'downloads' => array(),
            'fail'      => 0,
        );
        $data['receipts'][] = $r;
        return array(true, $r);
    });
}

function kinv_mark_sent($id, $ok) {
    return kinv_update(function (&$data) use ($id, $ok) {
        foreach ($data['receipts'] as $i => $r) {
            if ($r['id'] === $id) {
                $data['receipts'][$i]['sent_at'] = time();
                $data['receipts'][$i]['mail_ok'] = (bool)$ok;
                return array(true, '');
            }
        }
        return array(false, '領収書が見つかりません');
    });
}

function kinv_mark_download($id) {
    return kinv_update(function (&$data) use ($id) {
        foreach ($data['receipts'] as $i => $r) {
            if ($r['id'] === $id) {
                if (!isset($data['receipts'][$i]['downloads'])) { $data['receipts'][$i]['downloads'] = array(); }
                $data['receipts'][$i]['downloads'][] = time();
                $data['receipts'][$i]['fail'] = 0;  // 正解したら失敗数を戻す
                return array(true, '');
            }
        }
        return array(false, '');
    });
}

/** メール認証に失敗した回数を数える。戻り値は加算後の回数。 */
function kinv_mark_fail($id) {
    $res = kinv_update(function (&$data) use ($id) {
        foreach ($data['receipts'] as $i => $r) {
            if ($r['id'] === $id) {
                $n = (int)(isset($r['fail']) ? $r['fail'] : 0) + 1;
                $data['receipts'][$i]['fail'] = $n;
                return array(true, $n);
            }
        }
        return array(false, 0);
    });
    return isset($res[1]) ? (int)$res[1] : 0;
}

function kinv_unlock($id) {
    return kinv_update(function (&$data) use ($id) {
        foreach ($data['receipts'] as $i => $r) {
            if ($r['id'] === $id) { $data['receipts'][$i]['fail'] = 0; return array(true, '解除しました'); }
        }
        return array(false, '領収書が見つかりません');
    });
}

/* ---------------- メール ---------------- */

/**
 * 領収書のダウンロード案内を送る。
 * heteml で実績のある mail() + base64（exbridge_jp/contact.php と同方式）。
 */
function kinv_send_mail($r, $download_url) {
    $issuer = kinv_issuer();
    $from = defined('KINV_MAIL_FROM') ? KINV_MAIL_FROM : $issuer['mail'];
    $subject = '【' . $issuer['name'] . '】領収書 ' . $r['no'] . ' のご案内';

    $body = $r['customer'] . " 様\n\n"
          . "いつもお世話になっております。" . $issuer['name'] . "です。\n"
          . "領収書を発行いたしましたので、下記よりダウンロードしてください。\n\n"
          . "──────────────────────────\n"
          . "  領収書番号 : " . $r['no'] . "\n"
          . "  発行日     : " . $r['issued_on'] . "\n"
          . "  金額       : " . number_format((int)$r['total']) . " 円（税込）\n"
          . "  但し書き   : " . $r['note'] . "\n"
          . "──────────────────────────\n\n"
          . "▼ ダウンロードはこちら\n"
          . $download_url . "\n\n"
          . "このページを開くと、メールアドレスの確認を求められます。\n"
          . "本メールの宛先（" . $r['email'] . "）をご入力ください。\n\n"
          . "※このURLはお客様専用です。第三者へ転送しないようお願いいたします。\n"
          . "※電子的に交付する領収書のため、収入印紙は不要です。\n\n"
          . "──────────────────────────\n"
          . $issuer['name'] . "\n"
          . ($issuer['invoice_no'] !== '' ? '登録番号 ' . $issuer['invoice_no'] . "\n" : '')
          . $issuer['zip'] . ' ' . $issuer['addr'] . "\n"
          . $issuer['mail'] . "\n";

    $headers = implode("\r\n", array(
        'From: ' . $issuer['name'] . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage Send Invoice',
    ));
    return @mail(
        $r['email'],
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        chunk_split(base64_encode($body)),
        $headers
    );
}
