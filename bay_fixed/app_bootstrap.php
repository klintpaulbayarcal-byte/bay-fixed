<?php
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $sameSite = $isHttps ? 'None' : 'Lax';

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=' . $sameSite, '', $isHttps, true);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_start();
}
