<?php
// ==========================================
// 🛡️ DEADDROP: VOLATILE AUTH SESSION HELPER
// ==========================================

// Keep browser/proxy from caching unlocked pages or reflected secrets.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$session_path = '/run/deaddrop-sessions';
if (is_dir($session_path) && is_writable($session_path)) {
    session_save_path($session_path);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    session_name('DEADDROPSESSID');
    session_start();
}

function deaddrop_lock(string $redirect = 'index.php'): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
    header('Location: ' . $redirect);
    exit;
}

function deaddrop_is_unlocked(): bool {
    return !empty($_SESSION['deaddrop_unlocked_until'])
        && $_SESSION['deaddrop_unlocked_until'] >= time()
        && !empty($_SESSION['deaddrop_master_key']);
}

function deaddrop_master_key(): string {
    return deaddrop_is_unlocked() ? (string)$_SESSION['deaddrop_master_key'] : '';
}

function deaddrop_unlock(string $password, string $admin_hash, ?string &$error = null, int $ttl_seconds = 900): bool {
    if (!password_verify($password, $admin_hash)) {
        $error = '[!] AUTHENTICATION FAILED: INVALID MASTER KEY.';
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['deaddrop_unlocked_until'] = time() + $ttl_seconds;
    $_SESSION['deaddrop_master_key'] = $password;
    return true;
}

function deaddrop_refresh_unlock(int $ttl_seconds = 900): void {
    if (deaddrop_is_unlocked()) {
        $_SESSION['deaddrop_unlocked_until'] = time() + $ttl_seconds;
    }
}
?>
