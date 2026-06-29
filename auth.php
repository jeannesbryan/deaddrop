<?php
// ==========================================
// 🛡️ DEADDROP: VOLATILE AUTH SESSION HELPER (v11.1)
// ==========================================
// Goals:
// - short-lived server-side unlock session
// - no admin/master password in hidden form fields
// - CSRF-protected admin actions while unlocked
// - auto-lock when the server-side expiry is reached

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
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '1800');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_name('DEADDROPSESSID');
    session_start();
}

function deaddrop_session_ttl(?array $config = null): int {
    $ttl = 900; // default: 15 minutes
    if (is_array($config) && isset($config['session_ttl_seconds'])) {
        $ttl = (int)$config['session_ttl_seconds'];
    }

    // v11.1 policy: short admin windows only.
    if ($ttl < 600) $ttl = 600;     // minimum 10 minutes
    if ($ttl > 900) $ttl = 900;     // maximum 15 minutes
    return $ttl;
}

function deaddrop_lock(string $redirect = 'index.php'): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    session_destroy();
    header('Location: ' . $redirect);
    exit;
}

function deaddrop_clear_expired_unlock(): void {
    unset(
        $_SESSION['deaddrop_unlocked_until'],
        $_SESSION['deaddrop_master_key'],
        $_SESSION['deaddrop_auth_time'],
        $_SESSION['deaddrop_csrf_token']
    );
}

function deaddrop_is_unlocked(): bool {
    if (empty($_SESSION['deaddrop_unlocked_until']) || empty($_SESSION['deaddrop_master_key'])) {
        return false;
    }

    if ((int)$_SESSION['deaddrop_unlocked_until'] < time()) {
        deaddrop_clear_expired_unlock();
        return false;
    }

    return true;
}

function deaddrop_unlocked_remaining(): int {
    if (!deaddrop_is_unlocked()) return 0;
    return max(0, (int)$_SESSION['deaddrop_unlocked_until'] - time());
}

function deaddrop_master_key(): string {
    // The key is kept only inside the server-side session window.
    // It is never put into hidden inputs or URLs.
    return deaddrop_is_unlocked() ? (string)$_SESSION['deaddrop_master_key'] : '';
}

function deaddrop_unlock(string $password, string $admin_hash, ?string &$error = null, int $ttl_seconds = 900): bool {
    if (!password_verify($password, $admin_hash)) {
        $error = '[!] AUTHENTICATION FAILED: INVALID MASTER KEY.';
        return false;
    }

    if ($ttl_seconds < 600) $ttl_seconds = 600;
    if ($ttl_seconds > 900) $ttl_seconds = 900;

    session_regenerate_id(true);
    $_SESSION['deaddrop_unlocked_until'] = time() + $ttl_seconds;
    $_SESSION['deaddrop_auth_time'] = time();
    $_SESSION['deaddrop_master_key'] = $password;
    $_SESSION['deaddrop_csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

function deaddrop_refresh_unlock(int $ttl_seconds = 900): void {
    if (!deaddrop_is_unlocked()) return;

    if ($ttl_seconds < 600) $ttl_seconds = 600;
    if ($ttl_seconds > 900) $ttl_seconds = 900;
    $_SESSION['deaddrop_unlocked_until'] = time() + $ttl_seconds;
}

function deaddrop_csrf_token(): string {
    if (!deaddrop_is_unlocked()) return '';
    if (empty($_SESSION['deaddrop_csrf_token'])) {
        $_SESSION['deaddrop_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['deaddrop_csrf_token'];
}

function deaddrop_csrf_input(): string {
    $token = htmlspecialchars(deaddrop_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function deaddrop_verify_csrf(?string $token): bool {
    if (!deaddrop_is_unlocked()) return false;
    if (!is_string($token) || $token === '') return false;
    $session_token = (string)($_SESSION['deaddrop_csrf_token'] ?? '');
    return $session_token !== '' && hash_equals($session_token, $token);
}

function deaddrop_action_allowed(?string &$error = null): bool {
    if (!deaddrop_is_unlocked()) {
        $error = '[!] SESSION LOCKED OR EXPIRED. UNLOCK AGAIN.';
        return false;
    }

    if (!deaddrop_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = '[!] INVALID OR EXPIRED ACTION TOKEN.';
        return false;
    }

    return true;
}
?>
