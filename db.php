<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NANO-PUB CORE ENGINE
// ==========================================

// ⚙️ 1. NODE CONFIGURATION (For Open Source Release)
$config = [
    'node_name'   => 'YOUR_NODE_NAME_HERE', // Change this to your desired node name
    'node_url'    => 'http://your_v3_onion_address_here.onion/deaddrop', // Your Tor Hidden Service URL
    'admin_hash'  => 'YOUR_BCRYPT_HASH_HERE', // Generate using password_hash('your_password', PASSWORD_BCRYPT)
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
        private_key TEXT
    )");

    // PHASE 2: Safe Migrations for existing DB
    try { $db->exec("ALTER TABLE timeline ADD COLUMN status TEXT DEFAULT 'active'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE timeline ADD COLUMN expires_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE inbox ADD COLUMN status TEXT DEFAULT 'active'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE inbox ADD COLUMN expires_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

    // 🧬 GENERATE LIBSODIUM KEYPAIR IF NOT EXISTS
    $stmt = $db->query("SELECT public_key, private_key FROM node_identity WHERE id = 1");
    $keys = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$keys) {
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
    die("<h3 style='color:red; font-family:monospace;'>[ DEADDROP CORE ERROR ]<br>Database failure.</h3>");
}

function generate_local_id() {
    return 'dd_' . bin2hex(random_bytes(6)) . '_' . time();
}
?>