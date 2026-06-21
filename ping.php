<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE DOOR (Ping Endpoint w/ Auto-Scaling PoW)
// ==========================================
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("[!] Access denied. Passive mode.");
}

$source_url = trim(strip_tags($_POST['source_url'] ?? ''));
$timestamp  = (int)($_POST['timestamp'] ?? 0);
$nonce      = trim(strip_tags($_POST['nonce'] ?? ''));

if (empty($source_url) || empty($timestamp) || $nonce === '') {
    http_response_code(400);
    die("[!] ERROR: Missing Proof-of-Work payload.");
}

$current_time = time();
if (abs($current_time - $timestamp) > 300) {
    http_response_code(403);
    die("[!] REJECTED: Timestamp expired or node clocks are out of sync.");
}

$parsed_url = parse_url($source_url);
$host_domain = $parsed_url['host'] ?? '';

if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
    http_response_code(403);
    die("[!] PROTOCOL REJECTED: Only external Darknet (.onion) networks are allowed.");
}

// 📊 MENGHITUNG ANTREAN (Auto-Scaling Defense Logic)
$queue_count = $db->query("SELECT COUNT(*) FROM ping_queue")->fetchColumn();

if ($queue_count > 150) {
    $difficulty = '000000'; // ANTI-DDOS NUKE: ~16 juta komputasi
} elseif ($queue_count > 50) {
    $difficulty = '00000';  // HIGH SHIELD: ~1 juta komputasi
} else {
    $difficulty = '0000';   // NORMAL: ~65 ribu komputasi
}

// 🛡️ HASHCASH VERIFICATION DENGAN KESULITAN DINAMIS
$data_to_hash = rtrim($source_url, '/') . $timestamp . $nonce;
$hash = hash('sha256', $data_to_hash);

if (substr($hash, 0, strlen($difficulty)) !== $difficulty) {
    http_response_code(403);
    die("[!] REJECTED: Proof-of-Work invalid. You failed the Level $difficulty puzzle.");
}

// 🛑 BATAS MUTLAK SERVER
if ($queue_count > 200) {
    http_response_code(429);
    die("[!] QUEUE FULL: Node radar is currently overwhelmed. Try again later.");
}

try {
    $stmt = $db->prepare("INSERT INTO ping_queue (source_url) VALUES (:url)");
    $stmt->execute([':url' => rtrim($source_url, '/')]);
    
    // 📡 TELEGRAM BRIDGE PING TRIGGER
    if ($config['tg_on'] && !empty($config['tg_token']) && !empty($config['tg_chat'])) {
        $msg_tg = "📡 RADAR NODE: Mendapat ketukan masuk (Ping) baru dari:\n" . rtrim($source_url, '/');
        $url_tg = "https://api.telegram.org/bot" . $config['tg_token'] . "/sendMessage";
        
        $chTg = curl_init($url_tg);
        curl_setopt($chTg, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chTg, CURLOPT_POST, true);
        curl_setopt($chTg, CURLOPT_POSTFIELDS, ['chat_id' => $config['tg_chat'], 'text' => $msg_tg]);
        curl_setopt($chTg, CURLOPT_TIMEOUT, 5);
        curl_exec($chTg);
        curl_close($chTg);
    }
    
    http_response_code(202);
    echo "[+] ACCEPTED: Valid PoW verified (Difficulty: $difficulty). Ping entered the Worker queue.";
} catch (PDOException $e) {
    http_response_code(202);
    echo "[+] ACCEPTED: Ping is already queued for processing.";
}
?>