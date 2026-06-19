<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE DOOR (Ping Endpoint w/ Proof-of-Work)
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
    die("[!] ERROR: Missing Proof-of-Work payload. Update your node to v3.");
}

// ⏱️ Prevent Replay Attacks: Timestamp must be within the last 5 minutes
$current_time = time();
if (abs($current_time - $timestamp) > 300) {
    http_response_code(403);
    die("[!] REJECTED: Timestamp expired or node clocks are out of sync.");
}

// Extreme Validation: Only allow .onion (and localhost for testing)
$parsed_url = parse_url($source_url);
$host_domain = $parsed_url['host'] ?? '';

if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
    http_response_code(403);
    die("[!] PROTOCOL REJECTED: Only external Darknet (.onion) networks are allowed.");
}

// 🛡️ PHASE 3: HASHCASH VERIFICATION
// Target difficulty: 4 leading zeros (approx. 65,536 computational tries)
$difficulty = '0000';
$data_to_hash = rtrim($source_url, '/') . $timestamp . $nonce;
$hash = hash('sha256', $data_to_hash);

if (substr($hash, 0, strlen($difficulty)) !== $difficulty) {
    http_response_code(403);
    die("[!] REJECTED: Proof-of-Work invalid. Cryptographic puzzle failed.");
}

try {
    // Flood Protection: SQLite queue limit
    $queue_count = $db->query("SELECT COUNT(*) FROM ping_queue")->fetchColumn();
    if ($queue_count > 200) {
        http_response_code(429);
        die("[!] QUEUE FULL: Node radar is currently overwhelmed. Try again later.");
    }

    $stmt = $db->prepare("INSERT INTO ping_queue (source_url) VALUES (:url)");
    $stmt->execute([':url' => rtrim($source_url, '/')]);
    
    http_response_code(202);
    echo "[+] ACCEPTED: Valid PoW verified. Ping entered the Worker queue.";
} catch (PDOException $e) {
    http_response_code(202);
    echo "[+] ACCEPTED: Ping is already queued for processing.";
}
?>