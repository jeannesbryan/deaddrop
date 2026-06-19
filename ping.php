<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE DOOR (Ping Endpoint)
// ==========================================
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("[!] Access denied. Passive mode.");
}

$source_url = trim(strip_tags($_POST['source_url'] ?? ''));

if (empty($source_url)) {
    http_response_code(400);
    die("[!] ERROR: Empty payload.");
}

// VALIDASI EKSTREM: HANYA IZINKAN .ONION (Localhost DIBLOKIR)
$parsed_url = parse_url($source_url);
$host_domain = $parsed_url['host'] ?? '';

if (!preg_match('/\.onion$/i', $host_domain)) {
    http_response_code(403);
    die("[!] PROTOCOL REJECTED: Only external Darknet (.onion) networks are allowed.");
}

try {
    // PROTEKSI FLOODING: Cek kapasitas antrean agar SQLite tidak jebol
    $queue_count = $db->query("SELECT COUNT(*) FROM ping_queue")->fetchColumn();
    if ($queue_count > 200) {
        http_response_code(429);
        die("[!] QUEUE FULL: Node radar is currently overwhelmed. Try again later.");
    }

    $stmt = $db->prepare("INSERT INTO ping_queue (source_url) VALUES (:url)");
    $stmt->execute([':url' => rtrim($source_url, '/')]);
    
    http_response_code(202);
    echo "[+] ACCEPTED: Ping entered the Worker queue.";
} catch (PDOException $e) {
    http_response_code(202);
    echo "[+] ACCEPTED: Ping already in queue.";
}
?>