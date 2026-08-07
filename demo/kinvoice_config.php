<?php
// デモ用の設定。proto.exbridge.jp/kinvoice/ に置く。
// 本番(kurage.exbridge.jp)とは別インスタンス・別台帳。

// メールを実際に送らない。公開デモに送信フォームを置くと、誰でも任意の
// アドレス宛に送れる踏み台になり、ドメインの送信評価が落ちる。
define('KINV_DEMO', true);

define('KINV_APP_TITLE', '領収書の発行・送信（デモ）');
define('KINV_NAME', 'デモ商事株式会社');
define('KINV_MAIL_FROM', 'demo@example.co.jp');
define('KINV_INVOICE_REG_NO', 'T0000000000000');
define('KINV_ZIP',  '〒100-0001');
define('KINV_ADDR', '東京都千代田区サンプル1-2-3');

// デモのパスワードは商品ページに公開する（ログイン画面も商品の一部なので隠さない）
define('KINV_ADMIN_PASSWORD_HASH', '$2y$10$AlTUJyaGtCpdMQXAlpwSLO0Q6Ml60Eigzs8gtTDaGYfqRiEdCOvoy');

/* ------------------------------------------------------------------
 * デモサイトのアクセス解析
 *
 * これは **デモ用の設定ファイル** にだけ書く。配布物の
 * kinvoice_config.php.example には値を入れない。
 * 入れてしまうと、購入されたお客様のサイトの訪問者が当社の解析へ
 * 送られてしまう。定数が未定義なら画面は何も出力しない。
 * ---------------------------------------------------------------- */
define('KINV_HEAD_EXTRA', <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
HTML
);
