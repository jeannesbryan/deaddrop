<?php
// ==========================================
// 🏴‍☠️ DEADDROP: LOCAL SECRET CONFIG
// Location: /etc/deaddrop/config.php
// Owner: root:www-data | Permission: 0640
// ==========================================

return [
    'node_name'   => 'YOUR_NODE_NAME',
    'node_url'    => 'http://your-onion-address.onion/deaddrop',
    'admin_hash'  => 'YOUR_ADMIN_PASSWORD_HASH',
    'max_outbox'  => 50,

    // Sensitive database storage outside webroot
    'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',

    // Sensitive backup storage outside webroot
    'backup_path' => '/var/backups/deaddrop',
    'backup_retention' => 7,
    'backup_include_config' => true,

    // 🛡️ NETWORK POLICY
    // Production default: reject localhost/127.0.0.1 peers.
    // Set true only for local lab/dev testing.
    'allow_local_peers' => false,

    // 📡 TELEGRAM BRIDGE CONFIGURATION (Optional)
    'tg_on'       => false,
    'tg_token'    => 'YOUR_TELEGRAM_BOT_TOKEN',
    'tg_chat'     => 'YOUR_TELEGRAM_CHAT_ID'
];
