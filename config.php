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

    // v11: public outbox schema metadata.
    // DeadDrop v11+ requires schema v2 or newer.
    'outbox_schema_version' => 2,

    // v11.1: short-lived server-side admin unlock session (10-15 minutes).
    'session_ttl_seconds' => 900,

    // Sensitive database storage outside webroot
    'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',

    // Sensitive backup storage outside webroot
    'backup_path' => '/var/backups/deaddrop',
    'backup_retention' => 7,
    'backup_include_config' => true,

    // v11.4: encrypted backup export.
    // Generate an age recipient on your admin workstation:
    //   age-keygen -o deaddrop-backup.agekey
    // Put only the public recipient here. Keep the identity/private key offline.
    'backup_encryption' => true,
    'backup_age_recipient' => 'age1REPLACE_WITH_YOUR_PUBLIC_RECIPIENT',

    // 🛡️ NETWORK POLICY
    // Production default: reject localhost/127.0.0.1 peers.
    // Set true only for local lab/dev testing.
    'allow_local_peers' => false,

    // 📡 TELEGRAM BRIDGE CONFIGURATION (Optional)
    'tg_on'       => false,
    'tg_token'    => 'YOUR_TELEGRAM_BOT_TOKEN',
    'tg_chat'     => 'YOUR_TELEGRAM_CHAT_ID'
];
