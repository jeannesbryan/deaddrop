<?php
// ==========================================
// 🏴‍☠️ DEADDROP: ROTATIONAL AUTO-BACKUP (v5.0)
// ==========================================

// CAN ONLY BE EXECUTED VIA CLI / TERMINAL
if (php_sapi_name() !== 'cli') {
    die("[!] Access Denied. Must be executed via Terminal/Cron.\n");
}

echo "============================================\n";
echo "   DEADDROP AUTO-BACKUP PROTOCOL\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

// 1. Siapkan direktori backup
$backup_dir = __DIR__ . '/backup';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);

$archive_name = 'deaddrop_backup_' . date('Ymd_His') . '.tar.gz';
$archive_path = $backup_dir . '/' . $archive_name;

// 2. Daftar target absolut yang akan dibungkus
$targets = [];
if (is_dir(__DIR__ . '/data')) $targets[] = 'data';
if (is_dir(__DIR__ . '/media')) $targets[] = 'media';
if (file_exists(__DIR__ . '/outbox.json')) $targets[] = 'outbox.json';
if (file_exists(__DIR__ . '/db.php')) $targets[] = 'db.php';

if (empty($targets)) {
    die("[!] CRITICAL ERROR: No data found to backup.\n");
}

// Berpindah ke direktori root deaddrop agar struktur di dalam tar rapi (relatif path)
chdir(__DIR__);

// Menyusun perintah kompresi native Linux
$target_list = implode(' ', array_map('escapeshellarg', $targets));
$tar_command = "tar -czf " . escapeshellarg($archive_path) . " " . $target_list;

echo "[>] Compressing core assets: " . implode(', ', $targets) . "...\n";

// 3. Eksekusi Kompresi
exec($tar_command, $output, $return_var);

// 4. Verifikasi dan Pembersihan Backup Lama (Diet eMMC)
if ($return_var === 0 && file_exists($archive_path)) {
    echo "[+] BACKUP COMPLETE. Sovereign node data is securely archived.\n";
    echo "[*] Stored in: /backup/$archive_name\n\n";

    // ROTASI 7 HARI: Hitung jumlah fail tar.gz di folder backup
    $backups = glob($backup_dir . '/deaddrop_backup_*.tar.gz');
    
    if (count($backups) > 7) {
        // Urutkan dari yang paling baru ke yang paling usang
        rsort($backups); 
        
        // Ambil elemen sisanya (hari ke-8 dan seterusnya) untuk dimusnahkan
        $to_delete = array_slice($backups, 7); 
        
        echo "[>] eMMC Diet Protocol Engaged. Sweeping old archives...\n";
        foreach ($to_delete as $old_backup) {
            @unlink($old_backup);
            echo "    [-] Pruned: " . basename($old_backup) . "\n";
        }
        echo "[+] Strict 7-Day retention enforced.\n";
    }
} else {
    echo "[!] CRITICAL ERROR: Auto-backup failed to compress files.\n";
}

echo "\n============================================\n";
echo "   BACKUP CYCLE COMPLETE\n";
echo "============================================\n";
?>