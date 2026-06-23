<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NANO-PUB CORE ENGINE (v6.0 - Quantum Vault Blueprint)
// ==========================================

// ⚙️ 1. NODE CONFIGURATION
$config = [
    'node_name'   => 'YOUR_NODE_NAME',
    'node_url'    => 'http://your-onion-address.onion/deaddrop', 
    'admin_hash'  => 'YOUR_ADMIN_PASSWORD_HASH', 
    'max_outbox'  => 50,
    'db_path'     => __DIR__ . '/data/deaddrop.sqlite',
    
    // 📡 TELEGRAM BRIDGE CONFIGURATION (Optional)
    'tg_on'       => false, // Change to true to enable 
    'tg_token'    => 'YOUR_TELEGRAM_BOT_TOKEN',
    'tg_chat'     => 'YOUR_TELEGRAM_CHAT_ID'
];

// 🛡️ 2. DATABASE INITIALIZATION & WAL MODE ARMOR
try {
    if (!is_dir(dirname($config['db_path']))) {
        mkdir(dirname($config['db_path']), 0777, true);
    }

    $db = new PDO("sqlite:" . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5); 

    // SQLite Performance & Concurrency Tuning
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->exec("PRAGMA busy_timeout = 3000;");

    // 🏗️ 3. PURE V6.0 ARCHITECTURE (Legacy Alter Tables Eradicated)
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