<?php
// N-31 — Hoppa'nın BACK_URL'i çapraz-site bir form POST'u: SameSite=Lax oturum
// çerezi bu istekte GELMEZ. bootstrap burada session_start() yaparsa PHP yeni
// bir oturum açıp `Set-Cookie: PHPSESSID=<yeni>` gönderiyor ve tarayıcıdaki
// gerçek oturumun üstüne yazıyordu → kullanıcı ödemeden sonra /login'e düşüyordu.
// Uç oturum kullanmıyor (sonuç sunucudan ProcessQuery ile alınır), o yüzden
// oturum hiç başlatılmaz; 303 ile gelen GET kullanıcının kendi çerezini taşır.
define('LUMANORIS_STATELESS_ENDPOINT', true);
require_once __DIR__ . '/../../src/autoload.php';
WalletController::hoppaReturn();
