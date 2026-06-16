<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NANO-PUB CORE ENGINE (v1.0)
// ==========================================

// ⚙️ 1. NODE CONFIGURATION (For Open Source Release)
$config = [
    'node_name'   => 'Your Node Name', // e.g., 'Anonymous' or 'My Server'
    'node_url'    => 'http://your-onion-address.onion', // Your public URL
    
    // Use BCRYPT HASH, NEVER use plain text!
    // To generate a new hash, run this command in your Debian/Armbian terminal:
    // php -r "echo password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT) . PHP_EOL;"
    'admin_hash'  => 'REPLACE_WITH_YOUR_GENERATED_HASH', 
    
    'max_outbox'  => 50,
    'db_path'     => __DIR__ . '/data/deaddrop.sqlite'
];

// 🛡️ 2. DATABASE INITIALIZATION & WAL MODE
try {
    // Ensure the data/ directory exists
    if (!is_dir(dirname($config['db_path']))) {
        mkdir(dirname($config['db_path']), 0777, true);
    }

    $db = new PDO("sqlite:" . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 5); 

    // Performance optimization for low-end hardware (STB / Raspberry Pi)
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->exec("PRAGMA busy_timeout = 3000;"); // Anti-database locked feature

    // 🏗️ 3. AUTO MIGRATION (Create tables if they don't exist)
    // TIMELINE Table: Stores your local posts and pulled external posts
    $db->exec("CREATE TABLE IF NOT EXISTS timeline (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remote_id TEXT UNIQUE,        -- Original ID from remote node (NULL if local post)
        author_name TEXT,             -- Author's name
        author_host TEXT,             -- Origin URL of the post (useful for avatars/profile links)
        content TEXT,                 -- Message content
        media_url TEXT,               -- Attached media link (if any)
        is_local INTEGER DEFAULT 0,   -- 1 = Your post, 0 = External post
        reply_to TEXT,                -- ID of the replied post (if any)
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // FOLLOWING Table: List of peers pulled by the Cron Job
    $db->exec("CREATE TABLE IF NOT EXISTS following (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        onion_url TEXT UNIQUE,
        alias TEXT,
        last_pulled DATETIME
    )");

    // PING_QUEUE Table: Temporary queue for incoming external comments/pings
    $db->exec("CREATE TABLE IF NOT EXISTS ping_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_url TEXT UNIQUE,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

} catch (PDOException $e) {
    die("<h3 style='color:red; font-family:monospace;'>[ DEADDROP CORE ERROR ]<br>Failed to access database. Ensure the /data directory has write permissions (chmod 777).<br>Error: " . $e->getMessage() . "</h3>");
}

// Internal Helper Function
function generate_local_id() {
    return 'dd_' . bin2hex(random_bytes(6)) . '_' . time();
}
?>