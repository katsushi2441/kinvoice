<?php
require_once __DIR__ . '/kinvoice_lib.php';
/**
 * 領収書PDFの生成。外部ライブラリを使わない。
 *
 * heteml（共有サーバー）にはPDF拡張もComposerも無い。TCPDF等を持ち込むと
 * 数十MBのvendorを抱えるため、必要な機能だけを直接書いている。
 * 描画部品は kappstore/public/kapp_invoice.php と共通（そちらは請求書）。
 *
 * heteml（共有サーバー）にはPDF拡張もComposerも無い。TCPDF等を持ち込むと
 * 数十MBのvendorを抱えることになるため、必要な機能だけを直接書いている。
 *
 * 日本語は Adobe-Japan1 の CID フォント（KozMinPro-Regular-Acro）を
 * UniJIS-UCS2-H 符号化で参照する。フォントファイルは埋め込まず、閲覧側の
 * 代替フォントで表示される。これが共有サーバーで日本語PDFを出す一番軽い方法。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

if (!defined('KINV_INVOICE_INTERNAL')) { define('KINV_INVOICE_INTERNAL', true); }

/** UTF-8 を UTF-16BE のhex文字列にする（UniJIS-UCS2-H が要求する形）。 */
function kinv_pdf_hex($text) {
    $utf16 = mb_convert_encoding((string)$text, 'UTF-16BE', 'UTF-8');
    return strtoupper(bin2hex($utf16));
}

/** Helvetica の字幅（AFM標準・1000分率）。ASCIIの送り幅を出すのに使う。 */
function kinv_pdf_helvetica_widths() {
    static $w = null;
    if ($w !== null) { return $w; }
    $widths = array(
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    );
    $w = array();
    foreach ($widths as $i => $width) { $w[32 + $i] = $width; }
    return $w;
}

/** 文字列を ASCII の並びと非ASCII（日本語）の並びに切り分ける。 */
function kinv_pdf_runs($text) {
    $runs = array();
    $len = mb_strlen($text, 'UTF-8');
    $buf = ''; $ascii = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        $is_ascii = (strlen($ch) === 1 && ord($ch) >= 32 && ord($ch) <= 126);
        if ($ascii === null) { $ascii = $is_ascii; }
        if ($is_ascii !== $ascii) {
            $runs[] = array($ascii, $buf);
            $buf = ''; $ascii = $is_ascii;
        }
        $buf .= $ch;
    }
    if ($buf !== '') { $runs[] = array($ascii, $buf); }
    return $runs;
}

/**
 * テキスト描画。$size はpt、$x/$y はページ左下原点。
 *
 * ASCIIは Helvetica(F2)、日本語は CIDフォント(F1) で描く。CIDフォントは
 * ASCIIのグリフを持たないため、全部F1で描くと英数字が豆腐になる（実測）。
 * 送り幅を自前で積んで、runをつなげて配置する。
 */
function kinv_pdf_text($x, $y, $size, $text) {
    $out = "BT\n";
    $cursor = $x;
    $widths = kinv_pdf_helvetica_widths();
    foreach (kinv_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($chunk === '') { continue; }
        if ($is_ascii) {
            $out .= sprintf("/F2 %s Tf 1 0 0 1 %s %s Tm (%s) Tj\n",
                $size, round($cursor, 2), $y, kinv_pdf_escape($chunk));
            $advance = 0;
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $advance += isset($widths[$code]) ? $widths[$code] : 556;
            }
            $cursor += $advance * $size / 1000;
        } else {
            $out .= sprintf("/F1 %s Tf 1 0 0 1 %s %s Tm <%s> Tj\n",
                $size, round($cursor, 2), $y, kinv_pdf_hex($chunk));
            // 全角は1em。半角カナ等の例外はこの請求書では使わない。
            $cursor += mb_strlen($chunk, 'UTF-8') * $size;
        }
    }
    return $out . "ET\n";
}

/** Helvetica用。PDF文字列リテラルのエスケープ。 */
function kinv_pdf_escape($text) {
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
}

function kinv_pdf_line($x1, $y1, $x2, $y2, $width = 0.5) {
    return sprintf("%s w %s %s m %s %s l S\n", $width, $x1, $y1, $x2, $y2);
}

function kinv_pdf_rect_fill($x, $y, $w, $h, $gray = 0.94) {
    return sprintf("%s g %s %s %s %s re f 0 g\n", $gray, $x, $y, $w, $h);
}

/**
 * 請求書PDFのバイト列を返す。
 *
 * @param array $order 注文（id, invoice_no, billing_name, contact, created_at, amount, tax, total, method）
 */

/**
 * 領収書PDFのバイト列を返す。
 *
 * @param array $r 領収書（no, customer, total, tax_rate, note, issued_on）
 */
function kinv_receipt_pdf($r) {
    $issuer = kinv_issuer();
    $t      = kinv_tax_parts($r['total'], $r['tax_rate']);
    $no     = isset($r['no']) ? $r['no'] : '';
    $to     = isset($r['customer']) ? $r['customer'] : '';
    $note   = isset($r['note']) && $r['note'] !== '' ? $r['note'] : 'お品代';
    $issued = isset($r['issued_on']) ? date('Y年n月j日', strtotime($r['issued_on'])) : date('Y年n月j日');

    // A4 = 595.28 x 841.89pt。左右マージン 57pt。
    $L = 57; $R = 538; $c = '';

    $c .= kinv_pdf_text($L, 780, 24, '領収書');
    $c .= kinv_pdf_line($L, 771, $L + 86, 771, 1.2);
    $c .= kinv_pdf_text(400, 782, 9, 'No. ' . $no);
    $c .= kinv_pdf_text(400, 768, 9, '発行日: ' . $issued);

    // 宛名
    $c .= kinv_pdf_text($L, 726, 15, $to . ' 様');
    $c .= kinv_pdf_line($L, 718, 340, 718, 0.8);

    // 発行元
    $c .= kinv_pdf_text(360, 726, 11, $issuer['name']);
    $c .= kinv_pdf_text(360, 712, 7.5, $issuer['zip'] . ' ' . $issuer['addr1']);
    $c .= kinv_pdf_text(360, 701, 7.5, $issuer['addr2']);
    $c .= kinv_pdf_text(360, 690, 7.5, $issuer['tel']);
    $c .= kinv_pdf_text(360, 679, 7.5, $issuer['mail']);
    if ($issuer['invoice_no'] !== '') {
        $c .= kinv_pdf_text(360, 668, 7.5, '登録番号 ' . $issuer['invoice_no']);
    }

    // 金額（領収書の主役）
    $c .= kinv_pdf_rect_fill($L, 606, 340, 46);
    $c .= kinv_pdf_text($L + 12, 622, 13, '金額');
    $c .= kinv_pdf_text($L + 70, 618, 22, '￥' . number_format($t['total']) . '-');

    // 但し書き
    $c .= kinv_pdf_text($L, 578, 10.5, '但し　' . $note . '　として');
    $c .= kinv_pdf_line($L, 570, $R, 570, 0.5);
    $c .= kinv_pdf_text($L, 548, 10.5, '上記正に領収いたしました。');

    // 内訳
    $y = 500;
    $c .= kinv_pdf_rect_fill($L, $y, $R - $L, 22, 0.90);
    $c .= kinv_pdf_text($L + 8,   $y + 7, 9, '内訳');
    $c .= kinv_pdf_text($L + 330, $y + 7, 9, '税抜金額');
    $c .= kinv_pdf_text($L + 430, $y + 7, 9, '消費税額');

    $ry = $y - 26;
    $label = $t['rate'] > 0 ? ($t['rate'] . '%対象') : '税対象外';
    $c .= kinv_pdf_text($L + 8,   $ry + 7, 9.5, $label);
    $c .= kinv_pdf_text($L + 330, $ry + 7, 9.5, '￥' . number_format($t['net']));
    $c .= kinv_pdf_text($L + 430, $ry + 7, 9.5, '￥' . number_format($t['tax']));
    $c .= kinv_pdf_line($L, $ry, $R, $ry, 0.5);

    $sy = $ry - 26;
    $c .= kinv_pdf_text($L + 330, $sy, 10, '合計（税込）');
    $c .= kinv_pdf_text($L + 430, $sy, 10, '￥' . number_format($t['total']));
    $c .= kinv_pdf_line($L + 325, $sy - 7, $R, $sy - 7, 0.5);

    // 注記
    $c .= kinv_pdf_text($L, $sy - 46, 8.5,
        '・本領収書は電子的に交付しているため、収入印紙の貼付は不要です。');
    if ($issuer['invoice_no'] === '') {
        $c .= kinv_pdf_text($L, $sy - 60, 8.5,
            '・適格請求書発行事業者の登録番号は記載しておりません。');
    }

    // 脚注
    $c .= kinv_pdf_line($L, 70, $R, 70, 0.5);
    $c .= kinv_pdf_text($L, 56, 8, $issuer['name'] . '　' . $issuer['zip'] . ' '
        . $issuer['addr1'] . ' ' . $issuer['addr2']);

    return kinv_pdf_document($c);
}

/** オブジェクトを組み立ててPDFのバイト列にする。 */
function kinv_pdf_document($content) {
    $objects = array();
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] "
                . "/Resources << /Font << /F1 5 0 R /F2 8 0 R >> >> /Contents 4 0 R >>";
    $objects[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    // Adobe-Japan1 の CID フォント。フォントファイルは埋め込まない。
    $objects[5] = "<< /Type /Font /Subtype /Type0 /BaseFont /KozMinPro-Regular-Acro "
                . "/Encoding /UniJIS-UCS2-H /DescendantFonts [6 0 R] >>";
    $objects[6] = "<< /Type /Font /Subtype /CIDFontType0 /BaseFont /KozMinPro-Regular-Acro "
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 2 >> "
                . "/FontDescriptor 7 0 R /DW 1000 /W [1 [250] 231 632 500] >>";
    $objects[7] = "<< /Type /FontDescriptor /FontName /KozMinPro-Regular-Acro /Flags 6 "
                . "/FontBBox [-437 -340 1147 1317] /ItalicAngle 0 /Ascent 1317 /Descent -349 "
                . "/CapHeight 742 /StemV 80 >>";
    // 英数字用。PDFの標準14フォントなので埋め込み不要。
    $objects[8] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array();
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
