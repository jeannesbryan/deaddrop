<?php
// ==========================================
// DEADDROP: HEALTH CHECK CLI (v11)
// ==========================================
// Usage:
//   sudo -u www-data php /var/www/html/deaddrop/health.php
//   php health.php --json
//
// This script is intentionally CLI-only. It performs read-oriented deployment
// checks for PHP extensions, config/storage paths, SQLite, Tor SOCKS, outbox
// schema v2+, encrypted backup readiness, and local Nginx blocking of sensitive helper files.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die("[!] Access denied. health.php is CLI only.\n");
}

umask(0077);

$want_json = in_array('--json', $argv ?? [], true);
$results = [];
$started_at = gmdate('Y-m-d\TH:i:s\Z');

function dd_health_record(string $status, string $check, string $detail = ''): void {
    global $results;
    $allowed = ['OK', 'WARN', 'FAIL', 'SKIP'];
    if (!in_array($status, $allowed, true)) $status = 'WARN';
    $results[] = [
        'status' => $status,
        'check' => $check,
        'detail' => $detail,
    ];
}

function dd_health_ok(string $check, string $detail = ''): void { dd_health_record('OK', $check, $detail); }
function dd_health_warn(string $check, string $detail = ''): void { dd_health_record('WARN', $check, $detail); }
function dd_health_fail(string $check, string $detail = ''): void { dd_health_record('FAIL', $check, $detail); }
function dd_health_skip(string $check, string $detail = ''): void { dd_health_record('SKIP', $check, $detail); }

function dd_health_command_exists(string $command): bool {
    $safe = escapeshellarg($command);
    $out = [];
    $code = 1;
    @exec("command -v $safe 2>/dev/null", $out, $code);
    return $code === 0 && !empty($out);
}

function dd_health_bytes_perms(string $path): string {
    $perms = @fileperms($path);
    if ($perms === false) return 'unknown';
    return substr(sprintf('%o', $perms), -4);
}

function dd_health_is_inside(string $child, string $parent): bool {
    $child_real = realpath($child);
    $parent_real = realpath($parent);
    if ($child_real === false || $parent_real === false) return false;
    $parent_real = rtrim($parent_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strncmp($child_real . (is_dir($child_real) ? DIRECTORY_SEPARATOR : ''), $parent_real, strlen($parent_real)) === 0;
}

function dd_health_http_status(string $url, int $timeout = 4): ?int {
    if (!function_exists('curl_init')) return null;

    $ch = curl_init($url);
    if ($ch === false) return null;

    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'DeadDropHealth/11.4');

    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 || $status <= 0) return null;
    return (int)$status;
}

function dd_health_onion_v3_host(string $host): bool {
    return preg_match('/^[a-z2-7]{56}\.onion$/i', strtolower($host)) === 1;
}

function dd_health_load_config(string $app_root): array {
    $default_config = [
        'node_name'   => 'YOUR_NODE_NAME',
        'node_url'    => 'http://your-onion-address.onion/deaddrop',
        'admin_hash'  => 'YOUR_ADMIN_PASSWORD_HASH',
        'max_outbox'  => 50,
        'outbox_schema_version' => 2,
        'session_ttl_seconds' => 900,
        'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',
        'backup_path' => '/var/backups/deaddrop',
        'backup_retention' => 7,
        'backup_include_config' => true,
        'backup_encryption' => true,
        'backup_age_recipient' => 'age1REPLACE_WITH_YOUR_PUBLIC_RECIPIENT',
        'allow_local_peers' => false,
        'tg_on'       => false,
        'tg_token'    => 'YOUR_TELEGRAM_BOT_TOKEN',
        'tg_chat'     => 'YOUR_TELEGRAM_CHAT_ID',
    ];

    $config_path = getenv('DEADDROP_CONFIG') ?: '/etc/deaddrop/config.php';
    $config = $default_config;

    if (is_readable($config_path)) {
        $local_config = require $config_path;
        if (is_array($local_config)) {
            $config = array_replace($config, $local_config);
        } else {
            dd_health_fail('Config format', "$config_path did not return an array.");
        }
    } else {
        dd_health_warn('Config file', "$config_path is not readable; using built-in defaults for checks.");
    }

    $config['config_path'] = $config_path;
    $config['app_root'] = $app_root;
    return $config;
}

function dd_health_check_sqlite(array $config): void {
    $db_path = (string)($config['db_path'] ?? '');
    if ($db_path === '') {
        dd_health_fail('SQLite path', 'db_path is empty.');
        return;
    }

    $db_dir = dirname($db_path);
    if (!is_dir($db_dir)) {
        dd_health_fail('SQLite directory', "$db_dir does not exist.");
        return;
    }

    if (!is_writable($db_dir)) {
        dd_health_fail('SQLite directory writable', "$db_dir is not writable by the current user.");
    } else {
        dd_health_ok('SQLite directory writable', $db_dir);
    }

    if (!is_file($db_path)) {
        dd_health_warn('SQLite database file', "$db_path does not exist yet. Open the app or run a CLI script once to initialize it.");
        return;
    }

    if (!is_readable($db_path)) {
        dd_health_fail('SQLite database readable', "$db_path is not readable by the current user.");
        return;
    }

    if (!is_writable($db_path)) {
        dd_health_fail('SQLite database writable', "$db_path is not writable by the current user.");
        return;
    }

    if (!extension_loaded('pdo_sqlite')) {
        dd_health_fail('SQLite runtime', 'pdo_sqlite extension is missing; cannot inspect database.');
        return;
    }

    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);

        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower($integrity) === 'ok') {
            dd_health_ok('SQLite integrity_check', 'ok');
        } else {
            dd_health_fail('SQLite integrity_check', $integrity);
        }

        $journal = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
        if (strtolower($journal) === 'wal') {
            dd_health_ok('SQLite journal_mode', 'WAL');
        } else {
            dd_health_warn('SQLite journal_mode', "Current mode: $journal; expected WAL after normal db.php initialization.");
        }

        $tables = ['timeline', 'inbox', 'following', 'ping_queue', 'node_identity'];
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        foreach ($tables as $table) {
            $stmt->execute([':name' => $table]);
            if ($stmt->fetchColumn() === $table) {
                dd_health_ok('SQLite table', $table);
            } else {
                dd_health_fail('SQLite table missing', $table);
            }
        }

        $following_columns = [];
        foreach ($pdo->query("PRAGMA table_info(following)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $following_columns[$column['name']] = true;
        }

        foreach (['trust_status', 'signing_public_key', 'pending_public_key', 'pending_pq_public', 'pending_signing_public_key', 'key_changed_at', 'trust_updated_at', 'moderation_status', 'remote_media_policy', 'moderation_updated_at'] as $column) {
            if (!empty($following_columns[$column])) {
                dd_health_ok('Peer trust column', 'following.' . $column);
            } else {
                dd_health_fail('Peer policy column missing', 'following.' . $column . ' is required by v12 peer trust/moderation.');
            }
        }

        if (!empty($following_columns['trust_status'])) {
            $pending_changes = (int)$pdo->query("SELECT COUNT(*) FROM following WHERE trust_status = 'key_changed'")->fetchColumn();
            if ($pending_changes > 0) {
                dd_health_warn('Peer key changes pending', $pending_changes . ' peer(s) require Radar approval.');
            } else {
                dd_health_ok('Peer key changes pending', 'none');
            }
        }

        if (!empty($following_columns['moderation_status'])) {
            $blocked_peers = (int)$pdo->query("SELECT COUNT(*) FROM following WHERE moderation_status = 'blocked'")->fetchColumn();
            $quarantined_peers = (int)$pdo->query("SELECT COUNT(*) FROM following WHERE moderation_status = 'quarantined'")->fetchColumn();
            if ($blocked_peers > 0 || $quarantined_peers > 0) {
                dd_health_warn('Peer moderation state', "blocked=$blocked_peers quarantined=$quarantined_peers");
            } else {
                dd_health_ok('Peer moderation state', 'no blocked/quarantined peers');
            }
        }

        $ping_columns = [];
        foreach ($pdo->query("PRAGMA table_info(ping_queue)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $ping_columns[$column['name']] = true;
        }

        foreach (['status', 'is_known', 'reviewed_at'] as $column) {
            if (!empty($ping_columns[$column])) {
                dd_health_ok('Ping queue column', 'ping_queue.' . $column);
            } else {
                dd_health_fail('Ping queue column missing', 'ping_queue.' . $column . ' is required by v12.3 ping quarantine.');
            }
        }

        if (!empty($ping_columns['status'])) {
            $pending_pings = (int)$pdo->query("SELECT COUNT(*) FROM ping_queue WHERE status IN ('pending', 'quarantined')")->fetchColumn();
            if ($pending_pings > 0) {
                dd_health_warn('Ping queue review pending', $pending_pings . ' ping(s) require Radar review.');
            } else {
                dd_health_ok('Ping queue review pending', 'none');
            }
        }

        $identity_columns = [];
        foreach ($pdo->query("PRAGMA table_info(node_identity)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $identity_columns[$column['name']] = true;
        }

        foreach (['signing_public_key', 'signing_private_key'] as $column) {
            if (!empty($identity_columns[$column])) {
                dd_health_ok('Node identity column', 'node_identity.' . $column);
            } else {
                dd_health_fail('Node identity column missing', 'node_identity.' . $column . ' is required by v12.2 signed posts. Load any app page once to run db.php migration.');
            }
        }

        $identity_select = 'public_key, private_key';
        if (!empty($identity_columns['signing_public_key']) && !empty($identity_columns['signing_private_key'])) {
            $identity_select .= ', signing_public_key, signing_private_key';
        }

        $identity = $pdo->query("SELECT $identity_select FROM node_identity WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$identity) {
            dd_health_fail('Node identity', 'node_identity row id=1 is missing.');
        } else {
            $pub = base64_decode((string)($identity['public_key'] ?? ''), true);
            $priv = base64_decode((string)($identity['private_key'] ?? ''), true);
            $pub_ok = is_string($pub) && strlen($pub) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
            $priv_ok = is_string($priv) && strlen($priv) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES;
            if ($pub_ok && $priv_ok) {
                dd_health_ok('Node identity keys', 'X25519 public/private key sizes are valid.');
            } else {
                dd_health_fail('Node identity keys', 'Invalid or corrupted X25519 key size.');
            }

            $sign_pub = base64_decode((string)($identity['signing_public_key'] ?? ''), true);
            $sign_priv = base64_decode((string)($identity['signing_private_key'] ?? ''), true);
            $sign_pub_ok = is_string($sign_pub) && strlen($sign_pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
            $sign_priv_ok = is_string($sign_priv) && strlen($sign_priv) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
            if ($sign_pub_ok && $sign_priv_ok) {
                dd_health_ok('Node signing keys', 'Ed25519 public/private key sizes are valid.');
            } else {
                dd_health_fail('Node signing keys', 'Missing or invalid Ed25519 signing keypair.');
            }
        }
    } catch (Throwable $e) {
        dd_health_fail('SQLite inspection', $e->getMessage());
    }
}

function dd_health_check_outbox(array $config): void {
    $outbox_path = (string)($config['outbox_path'] ?? (__DIR__ . '/outbox.json'));
    $outbox_dir = dirname($outbox_path);

    if (!is_dir($outbox_dir)) {
        dd_health_fail('Outbox directory', "$outbox_dir does not exist.");
        return;
    }

    if (is_writable($outbox_dir)) {
        dd_health_ok('Outbox directory writable', $outbox_dir);
    } else {
        dd_health_fail('Outbox directory writable', "$outbox_dir is not writable by the current user.");
    }

    if (!is_file($outbox_path)) {
        dd_health_warn('outbox.json', "$outbox_path does not exist yet. Publish or run rebuild_outbox once.");
        return;
    }

    $raw = file_get_contents($outbox_path);
    if ($raw === false) {
        dd_health_fail('outbox.json readable', "$outbox_path could not be read.");
        return;
    }

    $feed = json_decode($raw, true);
    if (!is_array($feed)) {
        dd_health_fail('outbox.json JSON', 'Invalid JSON.');
        return;
    }

    if (!array_key_exists('schema_version', $feed)) {
        dd_health_fail('outbox schema_version', 'Missing schema_version. DeadDrop v11+ requires schema_version >= 2.');
    } else {
        $schema_version = filter_var($feed['schema_version'], FILTER_VALIDATE_INT);
        if ($schema_version !== false && $schema_version >= 2) {
            dd_health_ok('outbox schema_version', (string)$schema_version);
        } else {
            dd_health_fail('outbox schema_version', 'Legacy or invalid schema_version. DeadDrop v11+ requires schema_version >= 2.');
        }
    }

    if (($feed['protocol'] ?? '') === 'Nano-Pub') {
        dd_health_ok('outbox protocol', 'Nano-Pub');
    } else {
        dd_health_warn('outbox protocol', 'Missing protocol=Nano-Pub.');
    }

    if (isset($feed['node']) && is_array($feed['node']) && isset($feed['node']['capabilities'])) {
        dd_health_ok('outbox capabilities', 'node.capabilities present.');
    } else {
        dd_health_fail('outbox capabilities', 'node.capabilities missing; rebuild outbox with the v11 exporter.');
    }

    $signed_posts_capability = $feed['node']['capabilities']['signed_posts'] ?? $feed['capabilities']['signed_posts'] ?? null;
    if ($signed_posts_capability === true || $signed_posts_capability === 1 || $signed_posts_capability === '1') {
        dd_health_ok('outbox signed_posts capability', 'enabled');
    } else {
        dd_health_fail('outbox signed_posts capability', 'signed_posts is not enabled; rebuild outbox after v12.2.');
    }

    if (isset($feed['node']['signing_public_key'])) {
        $sign_pub = base64_decode((string)$feed['node']['signing_public_key'], true);
        if (is_string($sign_pub) && strlen($sign_pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            dd_health_ok('outbox signing public key', 'node.signing_public_key present.');
        } else {
            dd_health_fail('outbox signing public key', 'node.signing_public_key is invalid.');
        }
    } else {
        dd_health_fail('outbox signing public key', 'node.signing_public_key missing; rebuild outbox after v12.2.');
    }

    if (array_key_exists('posts', $feed) && is_array($feed['posts'])) {
        dd_health_ok('outbox posts array', count($feed['posts']) . ' post(s) exported.');
        $unsigned = 0;
        foreach ($feed['posts'] as $post) {
            if (!is_array($post) || empty($post['post_signature']) || (($post['signature_algorithm'] ?? '') !== 'ed25519')) {
                $unsigned++;
            }
        }
        if ($unsigned === 0) {
            dd_health_ok('outbox post signatures', 'all exported posts are signed.');
        } else {
            dd_health_fail('outbox post signatures', $unsigned . ' exported post(s) are unsigned or missing ed25519 metadata.');
        }
    } else {
        dd_health_fail('outbox posts array', 'posts is missing or not an array.');
    }
}

function dd_health_check_http_blocks(array $config): void {
    if (!function_exists('curl_init')) {
        dd_health_skip('Nginx helper block HTTP test', 'PHP curl extension is missing.');
        return;
    }

    $node_path = parse_url((string)($config['node_url'] ?? ''), PHP_URL_PATH);
    if (!is_string($node_path) || $node_path === '' || $node_path === '/') {
        $node_path = '/deaddrop';
    }
    $node_path = '/' . trim($node_path, '/');
    $base = 'http://127.0.0.1' . $node_path;

    $sensitive = [
        'auth.php',
        'db.php',
        'net.php',
        'outbox.php',
        'worker.php',
        'offload.php',
        'health.php',
        'keygen.php',
        'password-generator.php',
        'config.php',
    ];

    $any_response = false;
    foreach ($sensitive as $file) {
        $status = dd_health_http_status($base . '/' . $file);
        if ($status === null) {
            continue;
        }
        $any_response = true;
        if ($status === 200) {
            dd_health_fail('Nginx helper block', "$file returned HTTP 200. Block direct browser access.");
        } else {
            dd_health_ok('Nginx helper block', "$file returned HTTP $status.");
        }
    }

    if (!$any_response) {
        dd_health_warn('Nginx helper block HTTP test', "No local HTTP response from $base. Is Nginx running on 127.0.0.1:80?");
    }
}

function dd_health_check_tor_socks(string $host = '127.0.0.1', int $port = 9050): void {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
    if (is_resource($fp)) {
        fclose($fp);
        dd_health_ok('Tor SOCKS', "$host:$port is reachable.");
    } else {
        dd_health_fail('Tor SOCKS', "$host:$port is not reachable ($errstr). Is tor running?");
    }
}

$app_root = __DIR__;
$config = dd_health_load_config($app_root);

// Basic runtime and identity.
dd_health_ok('Execution context', 'CLI as uid=' . (function_exists('posix_geteuid') ? (string)posix_geteuid() : 'unknown'));

if (version_compare(PHP_VERSION, '8.2.0', '>=')) {
    dd_health_ok('PHP version', PHP_VERSION);
} else {
    dd_health_fail('PHP version', PHP_VERSION . ' detected; DeadDrop v11 expects PHP 8.2+.');
}

$required_extensions = ['sodium', 'pdo', 'pdo_sqlite', 'curl', 'mbstring', 'json', 'session', 'filter'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        dd_health_ok('PHP extension', $ext);
    } else {
        dd_health_fail('PHP extension missing', $ext);
    }
}

$optional_extensions = ['exif', 'fileinfo'];
foreach ($optional_extensions as $ext) {
    if (extension_loaded($ext)) {
        dd_health_ok('PHP optional extension', $ext);
    } else {
        dd_health_warn('PHP optional extension', "$ext is missing; optional media inspection features may be limited.");
    }
}

$commands = ['php', 'flock'];
foreach ($commands as $cmd) {
    if (dd_health_command_exists($cmd)) dd_health_ok('System command', $cmd);
    else dd_health_fail('System command missing', $cmd);
}

$optional_commands = ['sqlite3', 'curl', 'tor', 'exiftool'];
foreach ($optional_commands as $cmd) {
    if (dd_health_command_exists($cmd)) {
        dd_health_ok('Optional command', $cmd);
    } else {
        dd_health_warn('Optional command missing', "$cmd (optional or deployment-dependent.)");
    }
}

$backup_encryption = (bool)($config['backup_encryption'] ?? true);
$age_recipient = trim((string)($config['backup_age_recipient'] ?? ''));
$age_placeholder = $age_recipient === ''
    || stripos($age_recipient, 'REPLACE_WITH') !== false
    || stripos($age_recipient, 'YOUR_PUBLIC') !== false;

if ($backup_encryption) {
    if (dd_health_command_exists('age')) dd_health_ok('Encrypted backup command', 'age');
    else dd_health_fail('Encrypted backup command missing', 'age is required by v11.4 backup_encryption=true. Install: sudo apt install age');

    if (dd_health_command_exists('tar')) dd_health_ok('Backup archive command', 'tar');
    else dd_health_fail('Backup archive command missing', 'tar is required for v11.4 backups.');

    if ($age_placeholder) {
        dd_health_fail('Backup age recipient', 'backup_age_recipient is empty or still a placeholder. Generate an age keypair and place only the public recipient in config.php.');
    } elseif (preg_match('/^age1[ac-hj-np-z02-9]+$/', $age_recipient) === 1) {
        dd_health_ok('Backup age recipient', substr($age_recipient, 0, 12) . '…');
    } else {
        dd_health_fail('Backup age recipient', 'Recipient does not look like an age public recipient.');
    }
} else {
    dd_health_warn('Encrypted backup', 'backup_encryption=false. v11.4 recommends encrypted age backups.');
}

// Config checks.
$config_path = (string)($config['config_path'] ?? '/etc/deaddrop/config.php');
if (is_readable($config_path)) {
    dd_health_ok('Config readable', $config_path);
    $perms = dd_health_bytes_perms($config_path);
    $mode = @fileperms($config_path);
    if ($mode !== false && ($mode & 0004)) {
        dd_health_fail('Config permissions', "$config_path is world-readable ($perms). Recommended: 0640 root:www-data.");
    } else {
        dd_health_ok('Config permissions', "$config_path mode $perms.");
    }
} else {
    dd_health_warn('Config readable', "$config_path is not readable. Production should use /etc/deaddrop/config.php.");
}

if (dd_health_is_inside(dirname($config_path), $app_root)) {
    dd_health_fail('Config outside webroot', "$config_path appears to be inside the app webroot.");
} else {
    dd_health_ok('Config outside webroot', $config_path);
}

$node_url = (string)($config['node_url'] ?? '');
$node_host = parse_url($node_url, PHP_URL_HOST);
if (is_string($node_host) && dd_health_onion_v3_host($node_host)) {
    dd_health_ok('Node URL onion v3', $node_host);
} elseif (!empty($config['allow_local_peers'])) {
    dd_health_warn('Node URL onion v3', 'allow_local_peers=true; localhost/lab mode may be active. Do not use this on production onion nodes.');
} else {
    dd_health_fail('Node URL onion v3', 'node_url is not a valid Tor v3 onion URL.');
}

$ttl = (int)($config['session_ttl_seconds'] ?? 900);
if ($ttl >= 600 && $ttl <= 900) {
    dd_health_ok('Admin session TTL', $ttl . ' seconds.');
} else {
    dd_health_fail('Admin session TTL', $ttl . ' seconds; v11.1 policy requires 600-900 seconds.');
}

$outbox_schema = (int)($config['outbox_schema_version'] ?? 1);
if ($outbox_schema >= 2) {
    dd_health_ok('Configured outbox schema', (string)$outbox_schema);
} else {
    dd_health_fail('Configured outbox schema', 'DeadDrop v11+ requires outbox_schema_version >= 2.');
}

// Storage path checks.
$webroot = realpath($app_root) ?: $app_root;
foreach (['db_path', 'backup_path'] as $key) {
    $path = (string)($config[$key] ?? '');
    if ($path === '') {
        dd_health_fail($key, 'empty path');
        continue;
    }

    $check_path = is_dir($path) ? $path : dirname($path);
    if (dd_health_is_inside($check_path, $webroot)) {
        dd_health_fail($key . ' outside webroot', "$path appears to be inside $webroot.");
    } else {
        dd_health_ok($key . ' outside webroot', $path);
    }
}

$media_dir = $app_root . '/media';
if (is_dir($media_dir)) {
    if (is_writable($media_dir)) dd_health_ok('Public media directory writable', $media_dir);
    else dd_health_fail('Public media directory writable', "$media_dir is not writable by current user.");
} else {
    dd_health_warn('Public media directory', "$media_dir does not exist. Public media uploads may fail.");
}

$session_dir = '/run/deaddrop-sessions';
if (is_dir($session_dir)) {
    if (is_writable($session_dir)) dd_health_ok('Session tmpfs directory writable', $session_dir);
    else dd_health_fail('Session tmpfs directory writable', "$session_dir is not writable by current user.");
} else {
    dd_health_warn('Session tmpfs directory', "$session_dir does not exist. v11.1 will fall back to PHP default sessions.");
}

$webroot_files = ['db.php', 'auth.php', 'net.php', 'outbox.php', 'worker.php', 'offload.php', 'restore-backup.php', 'health.php'];
foreach ($webroot_files as $file) {
    $path = $app_root . '/' . $file;
    if (is_file($path)) dd_health_ok('App helper exists', $file);
    else dd_health_warn('App helper missing', "$file not found in $app_root.");
}

dd_health_check_sqlite($config);
dd_health_check_outbox($config);
dd_health_check_tor_socks();
dd_health_check_http_blocks($config);

$counts = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0, 'SKIP' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
}

if ($want_json) {
    echo json_encode([
        'tool' => 'DeadDrop Health Check',
        'version' => '11',
        'started_at' => $started_at,
        'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'summary' => $counts,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "============================================\n";
    echo "   DEADDROP HEALTH CHECK (v11)\n";
    echo "   TIME: $started_at\n";
    echo "============================================\n\n";

    foreach ($results as $r) {
        $label = str_pad('[' . $r['status'] . ']', 8);
        echo $label . $r['check'];
        if ($r['detail'] !== '') echo ' // ' . $r['detail'];
        echo "\n";
    }

    echo "\n============================================\n";
    echo "SUMMARY: OK={$counts['OK']} WARN={$counts['WARN']} FAIL={$counts['FAIL']} SKIP={$counts['SKIP']}\n";
    if ($counts['FAIL'] > 0) {
        echo "RESULT: FAIL - fix failing checks before treating this node as healthy.\n";
    } elseif ($counts['WARN'] > 0) {
        echo "RESULT: WARN - node is usable, but review warnings.\n";
    } else {
        echo "RESULT: OK - deployment checks passed.\n";
    }
    echo "============================================\n";
}

exit($counts['FAIL'] > 0 ? 1 : 0);
