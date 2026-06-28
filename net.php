<?php
// ==========================================
// 🛡️ DEADDROP: NETWORK POLICY HELPERS (Task 7)
// ==========================================
// Production default: only Tor v3 .onion peers are accepted.
// Set $config['allow_local_peers'] = true in db.php only for local lab/dev testing.

function deaddrop_allow_local_peers(array $config): bool {
    return !empty($config['allow_local_peers']);
}

function deaddrop_url_host(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function deaddrop_is_onion_v3_host(string $host): bool {
    return preg_match('/^[a-z2-7]{56}\.onion$/i', strtolower($host)) === 1;
}

function deaddrop_is_local_host(string $host): bool {
    $host = strtolower(trim($host, '[]'));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function deaddrop_normalize_peer_url(string $input): ?string {
    $input = trim(strip_tags($input));
    $input = preg_replace('/[\x00-\x1F\x7F]/', '', $input);
    if ($input === '') return null;

    if (!preg_match('#^https?://#i', $input)) {
        $input = 'http://' . $input;
    }

    $parts = parse_url($input);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    // Userinfo in URLs can hide the real host and confuse visual inspection.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = $parts['path'] ?? '';
    $path = '/' . trim($path, '/');
    if ($path === '/') $path = '';

    if (!preg_match('#/deaddrop$#i', $path)) {
        $path .= '/deaddrop';
    }

    return rtrim($scheme . '://' . $host . $port . $path, '/');
}

function deaddrop_validate_peer_url(string $url, array $config, ?string &$error = null): bool {
    $error = null;

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        $error = 'Malformed peer URL.';
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        $error = 'Only http:// or https:// peer URLs are accepted.';
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        $error = 'Peer URLs must not contain username/password userinfo.';
        return false;
    }

    $host = strtolower($parts['host']);
    if (deaddrop_is_onion_v3_host($host)) {
        return true;
    }

    if (deaddrop_is_local_host($host)) {
        if (deaddrop_allow_local_peers($config)) return true;
        $error = 'Localhost peers are disabled in production. Set allow_local_peers=true only for local lab testing.';
        return false;
    }

    if (preg_match('/\.onion$/i', $host)) {
        $error = 'Only Tor v3 onion hosts are accepted: 56 chars of a-z2-7 followed by .onion.';
    } else {
        $error = 'Only Tor v3 .onion peers are accepted in production.';
    }

    return false;
}

function deaddrop_normalize_and_validate_peer_url(string $input, array $config, ?string &$error = null): ?string {
    $url = deaddrop_normalize_peer_url($input);
    if ($url === null) {
        $error = 'Malformed peer URL.';
        return null;
    }

    if (!deaddrop_validate_peer_url($url, $config, $error)) {
        return null;
    }

    return $url;
}

function deaddrop_should_use_tor_proxy(string $url): bool {
    return deaddrop_is_onion_v3_host(deaddrop_url_host($url));
}

function deaddrop_same_peer_url(string $a, string $b): bool {
    $na = deaddrop_normalize_peer_url($a);
    $nb = deaddrop_normalize_peer_url($b);
    return $na !== null && $nb !== null && hash_equals($na, $nb);
}
?>
