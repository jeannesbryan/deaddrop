<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NANO-PUB CORE ENGINE (v6.0 - Quantum Vault Blueprint)
// ==========================================

// ⚙️ 1. NODE CONFIGURATION
// Keep this webroot file non-secret. Put real production values in /etc/deaddrop/config.php.
$default_config = [
    'node_name'   => 'YOUR_NODE_NAME',
    'node_url'    => 'http://your-onion-address.onion/deaddrop',
    'admin_hash'  => 'YOUR_ADMIN_PASSWORD_HASH',
    'max_outbox'  => 50,

    // v11: public outbox schema metadata.
    // DeadDrop v11+ emits schema v2 and skips schema-less legacy feeds.
    'outbox_schema_version' => 2,

    // v11.1: short-lived server-side admin unlock session (10-15 minutes).
    'session_ttl_seconds' => 900,

    // Sensitive SQLite storage outside webroot
    'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',

    // v13.1: local encrypted private-media cache outside webroot.
    // Public encrypted DM blobs are still exported under media/private/ as ciphertext-only .ddm files.
    'private_media_path' => '/var/lib/deaddrop/private-media',

    // v13.2: paranoid inbox defaults.
    // Incoming private drops remain ciphertext-at-rest; outgoing private drops do not keep plaintext unless requested.
    'paranoid_inbox' => true,
    'save_private_plaintext_copy' => false,

    // Sensitive backup storage outside webroot
    'backup_path' => '/var/backups/deaddrop',
    'backup_retention' => 7,
    'backup_include_config' => true,

    // v11.4: encrypted backup export.
    // Put only the public age recipient here; keep the identity/private key offline.
    'backup_encryption' => true,
    'backup_age_recipient' => 'age1REPLACE_WITH_YOUR_PUBLIC_RECIPIENT',

    // 🛡️ NETWORK POLICY
    // Production default: reject localhost/127.0.0.1 peers. Set true only in local lab/dev.
    'allow_local_peers' => false,

    // 📡 TELEGRAM BRIDGE CONFIGURATION (Optional)
    'tg_on'       => false,
    'tg_token'    => 'YOUR_TELEGRAM_BOT_TOKEN',
    'tg_chat'     => 'YOUR_TELEGRAM_CHAT_ID'
];

$config_path = getenv('DEADDROP_CONFIG') ?: '/etc/deaddrop/config.php';
$config = $default_config;

if (is_readable($config_path)) {
    $local_config = require $config_path;
    if (is_array($local_config)) {
        $config = array_replace($config, $local_config);
    }
}

$config['config_path'] = $config_path;

// 🛡️ 2. DATABASE INITIALIZATION & WAL MODE ARMOR
umask(0077);
try {
    $db_dir = dirname($config['db_path']);
    if (!is_dir($db_dir)) {
        mkdir($db_dir, 0700, true);
    }
    @chmod($db_dir, 0700);

    $db = new PDO("sqlite:" . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5); 

    // SQLite Performance, Concurrency & ANTI-FORENSICS Tuning
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->exec("PRAGMA busy_timeout = 3000;");
    
    // 🔥 DATA VAPORIZATION PROTOCOL
    // Forces SQLite to overwrite deleted content with zeros on the physical disk
    $db->exec("PRAGMA secure_delete = FAST;");

    // 🏗️ 3. PURE ARCHITECTURE
    $db->exec("CREATE TABLE IF NOT EXISTS timeline (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remote_id TEXT UNIQUE,        
        author_name TEXT,              
        author_host TEXT,              
        content TEXT,                  
        media_url TEXT,                
        is_local INTEGER DEFAULT 0,    
        reply_to TEXT,
        status TEXT DEFAULT 'active',
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS inbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remote_id TEXT UNIQUE,        
        author_name TEXT,              
        author_host TEXT,              
        content TEXT,                  
        media_url TEXT,                
        is_local INTEGER DEFAULT 0,    
        reply_to TEXT,
        status TEXT DEFAULT 'active',
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS following (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        onion_url TEXT UNIQUE,
        alias TEXT,
        public_key TEXT DEFAULT NULL,
        pq_public TEXT DEFAULT NULL,
        trust_status TEXT DEFAULT 'unverified',
        signing_public_key TEXT DEFAULT NULL,
        pending_public_key TEXT DEFAULT NULL,
        pending_pq_public TEXT DEFAULT NULL,
        pending_signing_public_key TEXT DEFAULT NULL,
        key_changed_at DATETIME DEFAULT NULL,
        trust_updated_at DATETIME DEFAULT NULL,
        moderation_status TEXT DEFAULT 'active',
        remote_media_policy TEXT DEFAULT 'allow',
        moderation_updated_at DATETIME DEFAULT NULL,
        is_mutual INTEGER DEFAULT 0,
        last_pulled DATETIME
    )");

    $following_columns = [];
    foreach ($db->query("PRAGMA table_info(following)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $following_columns[$column['name']] = true;
    }

    $following_migrations = [
        'trust_status' => "ALTER TABLE following ADD COLUMN trust_status TEXT DEFAULT 'unverified'",
        'signing_public_key' => "ALTER TABLE following ADD COLUMN signing_public_key TEXT DEFAULT NULL",
        'pending_public_key' => "ALTER TABLE following ADD COLUMN pending_public_key TEXT DEFAULT NULL",
        'pending_pq_public' => "ALTER TABLE following ADD COLUMN pending_pq_public TEXT DEFAULT NULL",
        'pending_signing_public_key' => "ALTER TABLE following ADD COLUMN pending_signing_public_key TEXT DEFAULT NULL",
        'key_changed_at' => "ALTER TABLE following ADD COLUMN key_changed_at DATETIME DEFAULT NULL",
        'trust_updated_at' => "ALTER TABLE following ADD COLUMN trust_updated_at DATETIME DEFAULT NULL",
        'moderation_status' => "ALTER TABLE following ADD COLUMN moderation_status TEXT DEFAULT 'active'",
        'remote_media_policy' => "ALTER TABLE following ADD COLUMN remote_media_policy TEXT DEFAULT 'allow'",
        'moderation_updated_at' => "ALTER TABLE following ADD COLUMN moderation_updated_at DATETIME DEFAULT NULL",
    ];

    foreach ($following_migrations as $column => $sql) {
        if (empty($following_columns[$column])) {
            $db->exec($sql);
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS ping_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_url TEXT UNIQUE,
        status TEXT DEFAULT 'pending',
        is_known INTEGER DEFAULT 0,
        reviewed_at DATETIME DEFAULT NULL,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $ping_columns = [];
    foreach ($db->query("PRAGMA table_info(ping_queue)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $ping_columns[$column['name']] = true;
    }

    $ping_migrations = [
        'status' => "ALTER TABLE ping_queue ADD COLUMN status TEXT DEFAULT 'pending'",
        'is_known' => "ALTER TABLE ping_queue ADD COLUMN is_known INTEGER DEFAULT 0",
        'reviewed_at' => "ALTER TABLE ping_queue ADD COLUMN reviewed_at DATETIME DEFAULT NULL",
    ];

    foreach ($ping_migrations as $column => $sql) {
        if (empty($ping_columns[$column])) {
            $db->exec($sql);
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS node_identity (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        public_key TEXT,
        private_key TEXT,
        pq_public TEXT DEFAULT NULL,
        pq_private TEXT DEFAULT NULL,
        signing_public_key TEXT DEFAULT NULL,
        signing_private_key TEXT DEFAULT NULL
    )");

    $identity_columns = [];
    foreach ($db->query("PRAGMA table_info(node_identity)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $identity_columns[$column['name']] = true;
    }

    $identity_migrations = [
        'signing_public_key' => "ALTER TABLE node_identity ADD COLUMN signing_public_key TEXT DEFAULT NULL",
        'signing_private_key' => "ALTER TABLE node_identity ADD COLUMN signing_private_key TEXT DEFAULT NULL",
    ];

    foreach ($identity_migrations as $column => $sql) {
        if (empty($identity_columns[$column])) {
            $db->exec($sql);
        }
    }

    // 🧬 4. LAYER-1 CRYPTOGRAPHIC KEYPAIR IGNITION
    $stmt = $db->query("SELECT public_key, private_key, pq_public, pq_private, signing_public_key, signing_private_key FROM node_identity WHERE id = 1");
    $keys = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keys) {
        // Generate Classical ECDH Base Keys (X25519)
        $keypair = sodium_crypto_box_keypair();
        $public_key = base64_encode(sodium_crypto_box_publickey($keypair));
        $private_key = base64_encode(sodium_crypto_box_secretkey($keypair));

        // v12.2: Ed25519 signing keys for public outbox post signatures.
        $signing_keypair = sodium_crypto_sign_keypair();
        $signing_public_key = base64_encode(sodium_crypto_sign_publickey($signing_keypair));
        $signing_private_key = base64_encode(sodium_crypto_sign_secretkey($signing_keypair));
        
        // Quantum Mockup Keys injected via external CLI Keygen
        $stmt_insert = $db->prepare("
            INSERT INTO node_identity (id, public_key, private_key, pq_public, pq_private, signing_public_key, signing_private_key)
            VALUES (1, :pub, :priv, NULL, NULL, :sign_pub, :sign_priv)
        ");
        $stmt_insert->execute([
            ':pub' => $public_key,
            ':priv' => $private_key,
            ':sign_pub' => $signing_public_key,
            ':sign_priv' => $signing_private_key,
        ]);
        
        $config['public_key'] = $public_key;
        $config['private_key'] = $private_key;
        $config['pq_public'] = null;
        $config['pq_private'] = null;
        $config['signing_public_key'] = $signing_public_key;
        $config['signing_private_key'] = $signing_private_key;
    } else {
        if (empty($keys['signing_public_key']) || empty($keys['signing_private_key'])) {
            $signing_keypair = sodium_crypto_sign_keypair();
            $keys['signing_public_key'] = base64_encode(sodium_crypto_sign_publickey($signing_keypair));
            $keys['signing_private_key'] = base64_encode(sodium_crypto_sign_secretkey($signing_keypair));

            $stmt_sign = $db->prepare("
                UPDATE node_identity
                SET signing_public_key = :sign_pub,
                    signing_private_key = :sign_priv
                WHERE id = 1
            ");
            $stmt_sign->execute([
                ':sign_pub' => $keys['signing_public_key'],
                ':sign_priv' => $keys['signing_private_key'],
            ]);
        }

        $config['public_key'] = $keys['public_key'];
        $config['private_key'] = $keys['private_key'];
        $config['pq_public'] = $keys['pq_public'] ?? null;
        $config['pq_private'] = $keys['pq_private'] ?? null;
        $config['signing_public_key'] = $keys['signing_public_key'] ?? null;
        $config['signing_private_key'] = $keys['signing_private_key'] ?? null;
    }

} catch (PDOException $e) {
    die("<h3 style='color:#ff0055; font-family:monospace;'>[ DEADDROP CORE ERROR ]<br>Fatal Database Initialization Failure.</h3>");
}

function generate_local_id() {
    return 'dd_' . bin2hex(random_bytes(6)) . '_' . time();
}

function deaddrop_private_media_path(array $config): string {
    $path = trim((string)($config['private_media_path'] ?? ''));
    if ($path === '') {
        $path = dirname((string)$config['db_path']) . '/private-media';
    }

    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    @chmod($path, 0700);

    return rtrim($path, '/');
}

function deaddrop_public_private_media_path(): string {
    $path = __DIR__ . '/media/private';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    @chmod($path, 0755);

    return $path;
}

function deaddrop_private_media_allowed_mimes(): array {
    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
}

function deaddrop_private_media_marker(array $manifest): string {
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return "\n[::DEADDROP_PRIVATE_MEDIA_V1::]" . base64_encode($json);
}

function deaddrop_extract_private_media_manifest(string $content): array {
    $marker = "\n[::DEADDROP_PRIVATE_MEDIA_V1::]";
    $pos = strpos($content, $marker);
    if ($pos === false) {
        return [$content, null];
    }

    $visible = substr($content, 0, $pos);
    $encoded = trim(substr($content, $pos + strlen($marker)));
    $encoded = strtok($encoded, "\r\n");
    $decoded = base64_decode((string)$encoded, true);
    if ($decoded === false) {
        return [$visible, null];
    }

    $manifest = json_decode($decoded, true);
    if (!is_array($manifest) || (($manifest['type'] ?? '') !== 'deaddrop-private-media')) {
        return [$visible, null];
    }

    return [$visible, $manifest];
}

function deaddrop_private_media_local_file(array $config, array $manifest): ?string {
    $stored_name = basename((string)($manifest['stored_name'] ?? ''));
    if ($stored_name === '' || !preg_match('/^[a-f0-9]{64}\.ddm$/', $stored_name)) {
        return null;
    }

    $private_file = deaddrop_private_media_path($config) . '/' . $stored_name;
    if (is_file($private_file)) {
        return $private_file;
    }

    $public_file = deaddrop_public_private_media_path() . '/' . $stored_name;
    if (is_file($public_file)) {
        return $public_file;
    }

    return null;
}

function deaddrop_private_media_decrypt_file(string $path, array $manifest) {
    $key = base64_decode((string)($manifest['key'] ?? ''), true);
    $nonce = base64_decode((string)($manifest['nonce'] ?? ''), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
        return false;
    }
    if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
        return false;
    }

    $ciphertext = file_get_contents($path);
    if ($ciphertext === false) {
        return false;
    }

    if (!empty($manifest['cipher_sha256']) && !hash_equals((string)$manifest['cipher_sha256'], hash('sha256', $ciphertext))) {
        return false;
    }

    return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
}

function deaddrop_private_media_shred(array $config, ?string $media_url): void {
    if (empty($media_url) || strpos($media_url, 'DDM:') !== 0) {
        return;
    }

    $stored_name = basename(substr($media_url, 4));
    if ($stored_name === '' || !preg_match('/^[a-f0-9]{64}\.ddm$/', $stored_name)) {
        return;
    }

    foreach ([deaddrop_private_media_path($config), deaddrop_public_private_media_path()] as $dir) {
        $file = $dir . '/' . $stored_name;
        if (is_file($file)) {
            exec('shred -u -z -n 3 ' . escapeshellarg($file));
        }
    }
}
?>
