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

    // Sensitive SQLite storage outside webroot
    'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',

    // Sensitive backup storage outside webroot
    'backup_path' => '/var/backups/deaddrop',
    'backup_retention' => 7,
    'backup_include_config' => true,

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
        is_mutual INTEGER DEFAULT 0,
        last_pulled DATETIME
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ping_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_url TEXT UNIQUE,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS node_identity (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        public_key TEXT,
        private_key TEXT,
        pq_public TEXT DEFAULT NULL,
        pq_private TEXT DEFAULT NULL
    )");

    // 🧬 4. LAYER-1 CRYPTOGRAPHIC KEYPAIR IGNITION
    $stmt = $db->query("SELECT public_key, private_key, pq_public, pq_private FROM node_identity WHERE id = 1");
    $keys = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keys) {
        // Generate Classical ECDH Base Keys (X25519)
        $keypair = sodium_crypto_box_keypair();
        $public_key = base64_encode(sodium_crypto_box_publickey($keypair));
        $private_key = base64_encode(sodium_crypto_box_secretkey($keypair));
        
        // Quantum Mockup Keys injected via external CLI Keygen
        $stmt_insert = $db->prepare("INSERT INTO node_identity (id, public_key, private_key, pq_public, pq_private) VALUES (1, :pub, :priv, NULL, NULL)");
        $stmt_insert->execute([':pub' => $public_key, ':priv' => $private_key]);
        
        $config['public_key'] = $public_key;
        $config['private_key'] = $private_key;
        $config['pq_public'] = null;
        $config['pq_private'] = null;
    } else {
        $config['public_key'] = $keys['public_key'];
        $config['private_key'] = $keys['private_key'];
        $config['pq_public'] = $keys['pq_public'] ?? null;
        $config['pq_private'] = $keys['pq_private'] ?? null;
    }

} catch (PDOException $e) {
    die("<h3 style='color:#ff0055; font-family:monospace;'>[ DEADDROP CORE ERROR ]<br>Fatal Database Initialization Failure.</h3>");
}

function generate_local_id() {
    return 'dd_' . bin2hex(random_bytes(6)) . '_' . time();
}
?>