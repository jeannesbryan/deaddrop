<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE DOOR (v8.0 - Airgapped Gatekeeper)
// ==========================================
require_once 'db.php';
require_once 'net.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("[!] Access denied. Passive mode.");
}

$source_url_raw = trim(strip_tags($_POST['source_url'] ?? ''));
$timestamp  = (int)($_POST['timestamp'] ?? 0);
$nonce      = trim(strip_tags($_POST['nonce'] ?? ''));

if (empty($source_url_raw) || empty($timestamp) || $nonce === '') {
    http_response_code(400);
    die("[!] ERROR: Missing Proof-of-Work payload.");
}

$current_time = time();
if (abs($current_time - $timestamp) > 300) {
    http_response_code(403);
    die("[!] REJECTED: Timestamp expired or node clocks are out of sync.");
}

$policy_error = null;
$source_url = deaddrop_normalize_and_validate_peer_url($source_url_raw, $config, $policy_error);
if ($source_url === null) {
    http_response_code(403);
    die("[!] PROTOCOL REJECTED: " . $policy_error);
}
$host_domain = deaddrop_url_host($source_url);

$peer_status = null;
$peer_known = 0;
$stmt_peer = $db->prepare("SELECT moderation_status FROM following WHERE onion_url = :url LIMIT 1");
$stmt_peer->execute([':url' => $source_url]);
$peer_row = $stmt_peer->fetch(PDO::FETCH_ASSOC);
if ($peer_row) {
    $peer_known = 1;
    $peer_status = $peer_row['moderation_status'] ?? 'active';
}

if ($peer_status === 'blocked') {
    http_response_code(403);
    die("[!] REJECTED: This peer is blocked by node policy.");
}

// 📊 AUTO-SCALING DEFENSE QUEUE METRICS
$queue_count = $db->query("SELECT COUNT(*) FROM ping_queue")->fetchColumn();

if ($queue_count > 150) {
    $difficulty = '000000'; // ANTI-DDOS NUKE: ~16 million computations
} elseif ($queue_count > 50) {
    $difficulty = '00000';  // HIGH SHIELD: ~1 million computations
} else {
    $difficulty = '0000';   // NORMAL: ~65k computations
}

// 🛡️ DYNAMIC HASHCASH VERIFICATION
$data_to_hash = rtrim($source_url, '/') . $timestamp . $nonce;
$hash = hash('sha256', $data_to_hash);

if (substr($hash, 0, strlen($difficulty)) !== $difficulty) {
    http_response_code(403);
    die("[!] REJECTED: Proof-of-Work invalid. You failed the Level $difficulty puzzle.");
}

// 🛑 ABSOLUTE SERVER CAP
if ($queue_count > 200) {
    http_response_code(429);
    die("[!] QUEUE FULL: Node radar is currently overwhelmed. Try again later.");
}

try {
    $queue_status = 'pending';
    if ($peer_status === 'active') {
        $queue_status = 'trusted';
    } elseif ($peer_status === 'quarantined') {
        $queue_status = 'quarantined';
    }

    $stmt = $db->prepare("INSERT OR IGNORE INTO ping_queue (source_url, status, is_known) VALUES (:url, :status, :known)");
    $stmt->execute([':url' => $source_url, ':status' => $queue_status, ':known' => $peer_known]);

    $stmt_update = $db->prepare("
        UPDATE ping_queue
        SET status = :status,
            is_known = :known,
            received_at = CURRENT_TIMESTAMP
        WHERE source_url = :url
    ");
    $stmt_update->execute([':url' => $source_url, ':status' => $queue_status, ':known' => $peer_known]);
    
    // 📡 AIRGAPPED TELEGRAM BRIDGE (Routed strictly via Tor SOCKS5 Proxy)
    if ($config['tg_on'] && !empty($config['tg_token']) && !empty($config['tg_chat'])) {
        $msg_tg = "📡 RADAR INTRUSION: Valid ping/knock received from:\n" . $source_url;
        $url_tg = "https://api.telegram.org/bot" . $config['tg_token'] . "/sendMessage";
        
        $chTg = curl_init($url_tg);
        curl_setopt($chTg, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chTg, CURLOPT_POST, true);
        curl_setopt($chTg, CURLOPT_POSTFIELDS, ['chat_id' => $config['tg_chat'], 'text' => $msg_tg]);
        curl_setopt($chTg, CURLOPT_TIMEOUT, 15);
        
        // 🧤 SARUNG TANGAN GAIB
        curl_setopt($chTg, CURLOPT_PROXY, "127.0.0.1:9050");
        curl_setopt($chTg, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        
        curl_exec($chTg);
        curl_close($chTg);
    }
    
    http_response_code(202);
    if ($queue_status === 'trusted') {
        echo "[+] ACCEPTED: Valid PoW verified (Difficulty: $difficulty). Known peer entered the Worker queue.";
    } elseif ($queue_status === 'quarantined') {
        echo "[+] ACCEPTED: Valid PoW verified (Difficulty: $difficulty). Peer is quarantined pending admin review.";
    } else {
        echo "[+] ACCEPTED: Valid PoW verified (Difficulty: $difficulty). Unknown peer is pending Radar review.";
    }
} catch (PDOException $e) {
    http_response_code(202);
    echo "[+] ACCEPTED: Ping is already queued for review.";
}
?>
