<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE DOOR (Ping Endpoint)
// ==========================================
require_once 'db.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("[!] Access denied. Passive mode.");
}

$source_url = trim(strip_tags($_POST['source_url'] ?? ''));

if (empty($source_url)) {
    http_response_code(400);
    die("[!] ERROR: Empty payload.");
}

// Extreme Validation: Only accept Darknet entities (.onion) or localhost
$parsed_url = parse_url($source_url);
$host_domain = $parsed_url['host'] ?? '';

if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
    http_response_code(403);
    die("[!] PROTOCOL REJECTED: Clearnet networks are not allowed.");
}

try {
    // Insert into the queue basket (ping_queue)
    $stmt = $db->prepare("INSERT INTO ping_queue (source_url) VALUES (:url)");
    $stmt->execute([':url' => rtrim($source_url, '/')]);
    
    // Return HTTP 202 (Accepted) lightning fast
    http_response_code(202);
    echo "[+] ACCEPTED: Ping entered the Worker queue.";
} catch (PDOException $e) {
    // Ignore if duplicate (using SQLite unique constraint if any)
    http_response_code(202);
    echo "[+] ACCEPTED: Ping already in queue.";
}
?>