<?php
// ==========================================
// 🏴‍☠️ DEADDROP: SECURE KEY GENERATOR (v7.0 - OpSec CLI)
// ==========================================

// Cegah akses dari Browser Web!
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("<!DOCTYPE html><html lang='en'><body style='background:#110818; color:#ff0055; font-family:monospace; padding:50px; text-align:center;'><h2>[!] OPSEC VIOLATION</h2><p>Master Key generation is strictly restricted to Terminal CLI.</p></body></html>");
}

echo "\n============================================\n";
echo "   DEADDROP MASTER KEY GENERATOR\n";
echo "============================================\n\n";

echo "Enter your new raw Master Key: ";

// Menangkap input langsung dari terminal
$handle = fopen("php://stdin", "r");
$raw_password = trim(fgets($handle));
fclose($handle);

if (empty($raw_password)) {
    die("\n[!] ERROR: Master Key cannot be empty. Aborting.\n\n");
}

// Generate Bcrypt Hash
$hash_result = password_hash($raw_password, PASSWORD_DEFAULT);

echo "\n[+] ENCRYPTION SUCCESSFUL\n";
echo "Copy the green string below and inject it into the 'admin_hash' parameter inside your db.php file:\n\n";

// Menggunakan kode warna ANSI agar hash berwarna hijau di terminal
echo "\033[1;32m" . $hash_result . "\033[0m\n\n";

echo "[!] WARNING: Delete 'password-generator.php' from the server after use to maintain absolute OpSec!\n\n";
?>