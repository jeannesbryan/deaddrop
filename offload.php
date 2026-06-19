<?php
// ==========================================
// 🏴‍☠️ DEADDROP: COLD STORAGE OFFLOADER (LOCAL)
// ==========================================

// CAN ONLY BE EXECUTED VIA CLI / TERMINAL
if (php_sapi_name() !== 'cli') {
    die("[!] Access Denied. Must be executed via Terminal/Cron.\n");
}

require_once __DIR__ . '/db.php';

echo "[>] Initiating Local Offload Protocol...\n";

// 1. Define retention threshold (30 Days ago)
$threshold_date = gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
echo "[>] Scanning for data older than: $threshold_date\n";

try {
    // 2. Extract obsolete data from the database
    $stmt = $db->prepare("SELECT * FROM timeline WHERE created_at < :threshold");
    $stmt->execute([':threshold' => $threshold_date]);
    $old_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($old_posts)) {
        die("[+] No obsolete data found. eMMC memory is secure.\n");
    }

    $post_count = count($old_posts);
    echo "[!] Found $post_count obsolete signals. Starting local archiving process...\n";

    // 3. Create Temporary Staging Directory
    $staging_dir = __DIR__ . '/data/staging_' . time();
    if (!is_dir($staging_dir)) mkdir($staging_dir, 0777, true);

    // Save extracted text/JSON payload
    file_put_contents($staging_dir . '/archive_data.json', json_encode($old_posts, JSON_PRETTY_PRINT));

    // Gather associated media files
    $media_dir = __DIR__ . '/media/';
    $files_to_delete = [];
    $ids_to_delete = [];

    foreach ($old_posts as $post) {
        $ids_to_delete[] = $post['id'];
        
        if (!empty($post['media_url'])) {
            $filename = basename($post['media_url']);
            $local_path = $media_dir . $filename;
            
            if (file_exists($local_path)) {
                // Copy to staging directory
                copy($local_path, $staging_dir . '/' . $filename);
                // Mark for deletion later
                $files_to_delete[] = $local_path;
            }
        }
    }

    // 4. Setup Backup Directory & Compress into .tar.gz archive
    $backup_dir = __DIR__ . '/backup';
    if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);

    $archive_name = 'deaddrop_cold_' . date('Ymd_His') . '.tar.gz';
    $archive_path = $backup_dir . '/' . $archive_name;
    
    echo "[>] Compressing data into $archive_name...\n";
    
    // Utilizing native Linux shell command for memory efficiency
    exec("tar -czf " . escapeshellarg($archive_path) . " -C " . escapeshellarg($staging_dir) . " .", $output, $return_var);

    // 5. VERIFY ARCHIVE & INITIATE PURGE
    if ($return_var === 0 && file_exists($archive_path)) {
        echo "[+] Local archive successfully created. Initiating local purge...\n";

        // A. Purge media files from eMMC
        foreach ($files_to_delete as $file) {
            @unlink($file);
        }

        // B. Purge rows from SQLite database
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
        $del_stmt = $db->prepare("DELETE FROM timeline WHERE id IN ($placeholders)");
        $del_stmt->execute($ids_to_delete);

        // C. THE SECRET SAUCE: SQLite Vacuum
        // Deleting rows doesn't shrink the SQLite file size, VACUUM does.
        echo "[>] Executing database VACUUM...\n";
        $db->exec("VACUUM;");

        echo "[+] PURGE COMPLETE. eMMC MEMORY RESTORED.\n";
        echo "[*] Data securely stored in: /backup/$archive_name\n";
    } else {
        echo "[!] Archiving failed. Local data retained to prevent data loss.\n";
    }

    // 6. Clean up staging files (Trash)
    array_map('unlink', glob("$staging_dir/*.*"));
    rmdir($staging_dir);

} catch (Exception $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
}

echo "============================================\n";
?>