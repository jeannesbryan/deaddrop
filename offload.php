<?php
// ==========================================
// 🏴‍☠️ DEADDROP: ROTATIONAL AUTO-BACKUP (v8.0 - Stealth Jitter Archiver)
// ==========================================

// EXECUTION CONTEXT STRICTLY CLI
if (php_sapi_name() !== 'cli') {
    die("[!] Access Denied. Protocol strictly requires Terminal/Cron execution.\n");
}

echo "============================================\n";
echo "   DEADDROP AUTO-BACKUP PROTOCOL\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

// 👶 OBAT TIDUR ACAK (Cron Jitter 1 - 10 Menit)
$jitter = random_int(1, 600);
echo "[*] OpSec Jitter Engaged: Archiver sleeping for $jitter seconds to obfuscate cron signature...\n";
sleep($jitter);

// 1. INITIALIZE ARCHIVE DIRECTORY
$backup_dir = __DIR__ . '/backup';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);

$archive_name = 'deaddrop_backup_' . date('Ymd_His') . '.tar.gz';
$archive_path = $backup_dir . '/' . $archive_name;

// 2. DEFINE ABSOLUTE RETENTION TARGETS
$targets = [];
if (is_dir(__DIR__ . '/data')) $targets[] = 'data';
if (is_dir(__DIR__ . '/media')) $targets[] = 'media';
if (file_exists(__DIR__ . '/outbox.json')) $targets[] = 'outbox.json';
if (file_exists(__DIR__ . '/db.php')) $targets[] = 'db.php';

if (empty($targets)) {
    die("[!] CRITICAL ERROR: Target directories missing. Aborting backup.\n");
}

// Re-route execution context for relative tar paths
chdir(__DIR__);

// Assemble native Linux tarball command
$target_list = implode(' ', array_map('escapeshellarg', $targets));
$tar_command = "tar -czf " . escapeshellarg($archive_path) . " " . $target_list;

echo "[>] Compressing core structural assets: " . implode(', ', $targets) . "...\n";

// 3. EXECUTE COMPRESSION
exec($tar_command, $output, $return_var);

// 4. VERIFY ARCHIVE AND ENFORCE HARDWARE RETENTION LIMITS
if ($return_var === 0 && file_exists($archive_path)) {
    echo "[+] BACKUP COMPLETE. Sovereign node data securely wrapped.\n";
    echo "[*] Destination: /backup/$archive_name\n\n";

    // 7-DAY RETENTION POLICY: Scan existing archives
    $backups = glob($backup_dir . '/deaddrop_backup_*.tar.gz');
    
    if (count($backups) > 7) {
        rsort($backups); // Order: Newest to Oldest
        $to_delete = array_slice($backups, 7); // Isolate outdated archives
        
        echo "[>] eMMC Diet Protocol Engaged. Sweeping redundant archives...\n";
        foreach ($to_delete as $old_backup) {
            @unlink($old_backup);
            echo "    [-] Pruned: " . basename($old_backup) . "\n";
        }
        echo "[+] Strict 7-Day retention successfully enforced.\n";
    }
} else {
    echo "[!] CRITICAL ERROR: Compression engine failed to wrap target files.\n";
}

echo "\n============================================\n";
echo "   BACKUP CYCLE COMPLETE\n";
echo "============================================\n";
?>