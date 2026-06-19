<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NANO-PUB CORE ENGINE
// ==========================================

// ⚙️ 1. NODE CONFIGURATION (For Open Source Release)
$config = [
    'node_name'   => 'Anonymous Node', // [!] CHANGE THIS: Your Darknet Alias
    'node_url'    => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.onion', // [!] CHANGE THIS: Your actual .onion URL
    
    // [!] CHANGE THIS: Use BCRYPT HASH, NEVER use plain text!
    // To generate a new hash, run this command in your Debian/Armbian terminal:
    // php -r "echo password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT) . PHP_EOL;"
    'admin_hash'  => '$2y$10$YourGeneratedBcryptHashGoesHere...', 
    
    'max_outbox'  => 50,
    'db_path'     => __DIR__ . '/data/deaddrop.sqlite'
];

// 🛡️ 2. DATABASE INITIALIZATION & WAL MODE
try {
    if (!is_dir(dirname($config['db_path']))) {
        mkdir(dirname($config['db_path']), 0777, true);
    }

    $db = new PDO("sqlite:" . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5); 

    // Performance optimization for low-end hardware (STB / Raspberry Pi)
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->exec("PRAGMA busy_timeout = 3000;");

    // 🏗️ 3. AUTO MIGRATION 
    $db->exec("CREATE TABLE IF NOT EXISTS timeline (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remote_id TEXT UNIQUE,        
        author_name TEXT,             
        author_host TEXT,             
        content TEXT,                 
        media_url TEXT,               
        is_local INTEGER DEFAULT 0,   
        reply_to TEXT,                
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS following (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        onion_url TEXT UNIQUE,
        alias TEXT,
        last_pulled DATETIME
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ping_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_url TEXT UNIQUE,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 🔐 PHASE 1 E2EE: Create Identity Table for Libsodium Keys
    $db->exec("CREATE TABLE IF NOT EXISTS node_identity (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        public_key TEXT,
        private_key TEXT
    )");

    // Add public_key column to following table safely
    try {
        $db->exec("ALTER TABLE following ADD COLUMN public_key TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // 🧬 GENERATE LIBSODIUM KEYPAIR IF NOT EXISTS
    $stmt = $db->query("SELECT public_key, private_key FROM node_identity WHERE id = 1");
    $keys = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keys) {
        // Create new sealed boxes
        $keypair = sodium_crypto_box_keypair();
        $public_key = base64_encode(sodium_crypto_box_publickey($keypair));
        $private_key = base64_encode(sodium_crypto_box_secretkey($keypair));
        
        $stmt_insert = $db->prepare("INSERT INTO node_identity (id, public_key, private_key) VALUES (1, :pub, :priv)");
        $stmt_insert->execute([':pub' => $public_key, ':priv' => $private_key]);
        
        $config['public_key'] = $public_key;
        $config['private_key'] = $private_key;
    } else {
        $config['public_key'] = $keys['public_key'];
        $config['private_key'] = $keys['private_key'];
    }

} catch (PDOException $e) {
    die("<h3 style='color:red; font-family:monospace;'>[ DEADDROP CORE ERROR ]<br>Database failure: " . $e->getMessage() . "</h3>");
}

function generate_local_id() {
    return 'dd_' . bin2hex(random_bytes(6)) . '_' . time();
}
?>