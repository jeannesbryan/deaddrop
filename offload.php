<?php
// ==========================================
// 🏴‍☠️ DEADDROP: ROTATIONAL AUTO-BACKUP (v9.0 - Off-Webroot Vault)
// ==========================================

// EXECUTION CONTEXT STRICTLY CLI
if (php_sapi_name() !== 'cli') {
    die("[!] Access Denied. Protocol strictly requires Terminal/Cron execution.\n");
}

require_once 'db.php';

umask(0077);

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function copy_file_safe(string $source, string $destination): bool {
    if (!is_file($source)) return false;
    $dir = dirname($destination);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (!copy($source, $destination)) return false;
    @chmod($destination, 0600);
    return true;
}

function copy_dir_safe(string $source, string $destination): int {
    if (!is_dir($source)) return 0;
    if (!is_dir($destination)) mkdir($destination, 0700, true);

    $copied = 0;
    $items = scandir($source);
    if ($items === false) return 0;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($src) && !is_link($src)) {
            $copied += copy_dir_safe($src, $dst);
        } elseif (is_file($src)) {
            if (copy_file_safe($src, $dst)) $copied++;
        }
    }
    return $copied;
}

function add_target(array &$targets, string $label, bool $added): void {
    if ($added) $targets[] = $label;
}

echo "============================================\n";
echo "   DEADDROP AUTO-BACKUP PROTOCOL\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

// 👶 OBAT TIDUR ACAK (Cron Jitter 1 - 10 Menit)
$jitter = random_int(1, 600);
echo "[*] OpSec Jitter Engaged: Archiver sleeping for $jitter seconds to obfuscate cron signature...\n";
sleep($jitter);

$backup_dir = $config['backup_path'] ?? '/var/backups/deaddrop';
$retention = (int)($config['backup_retention'] ?? 7);
if ($retention < 1) $retention = 7;

if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0700, true);
}
@chmod($backup_dir, 0700);

$archive_name = 'deaddrop_backup_' . gmdate('Ymd_His') . '.tar.gz';
$archive_path = rtrim($backup_dir, '/') . '/' . $archive_name;

$staging_root = sys_get_temp_dir() . '/deaddrop_backup_' . bin2hex(random_bytes(8));
if (!mkdir($staging_root, 0700, true)) {
    die("[!] CRITICAL ERROR: Failed to create temporary staging directory.\n");
}

$targets = [];
try {
    // 1. SQLite database and WAL/SHM sidecars from off-webroot db_path
    $db_path = $config['db_path'] ?? null;
    if ($db_path && is_file($db_path)) {
        add_target($targets, 'data/deaddrop.sqlite', copy_file_safe($db_path, $staging_root . '/data/deaddrop.sqlite'));
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($db_path . $suffix)) {
                add_target($targets, 'data/deaddrop.sqlite' . $suffix, copy_file_safe($db_path . $suffix, $staging_root . '/data/deaddrop.sqlite' . $suffix));
            }
        }
    }

    // 2. Public media remains in webroot for serving, but backup copy is stored off-webroot
    if (is_dir(__DIR__ . '/media')) {
        $media_count = copy_dir_safe(__DIR__ . '/media', $staging_root . '/media');
        if ($media_count > 0) $targets[] = "media ($media_count files)";
    }

    // 3. Public outbox
    add_target($targets, 'outbox.json', copy_file_safe(__DIR__ . '/outbox.json', $staging_root . '/outbox.json'));

    // 4. External secret config, optional but useful for disaster recovery
    $include_config = (bool)($config['backup_include_config'] ?? true);
    $config_path = $config['config_path'] ?? '/etc/deaddrop/config.php';
    if ($include_config && is_file($config_path)) {
        add_target($targets, 'config/config.php', copy_file_safe($config_path, $staging_root . '/config/config.php'));
    }

    if (empty($targets)) {
        throw new RuntimeException('No backup targets found.');
    }

    chdir($staging_root);
    $tar_command = 'tar -czf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($staging_root) . ' .';

    echo "[>] Compressing private backup targets: " . implode(', ', $targets) . "...\n";
    exec($tar_command, $output, $return_var);

    if ($return_var === 0 && is_file($archive_path)) {
        @chmod($archive_path, 0600);
        echo "[+] BACKUP COMPLETE. Sovereign node data wrapped outside webroot.\n";
        echo "[*] Destination: $archive_path\n\n";

        $backups = glob(rtrim($backup_dir, '/') . '/deaddrop_backup_*.tar.gz') ?: [];
        if (count($backups) > $retention) {
            rsort($backups);
            $to_delete = array_slice($backups, $retention);
            echo "[>] Retention Protocol Engaged. Sweeping redundant archives...\n";
            foreach ($to_delete as $old_backup) {
                @unlink($old_backup);
                echo "    [-] Pruned: " . basename($old_backup) . "\n";
            }
            echo "[+] Strict $retention-backup retention successfully enforced.\n";
        }
    } else {
        throw new RuntimeException('Compression engine failed to wrap target files.');
    }
} catch (Throwable $e) {
    echo "[!] CRITICAL ERROR: " . $e->getMessage() . "\n";
} finally {
    rrmdir($staging_root);
}

echo "\n============================================\n";
echo "   BACKUP CYCLE COMPLETE\n";
echo "============================================\n";
?>
