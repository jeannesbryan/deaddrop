<?php
// ==========================================
// 🏴‍☠️ DEADDROP: ENCRYPTED BACKUP EXPORT (v11.4)
// ==========================================
// CLI-only exporter for cron/manual backups.
// Output is encrypted with age by default: deaddrop_backup_YYYYmmdd_HHMMSS.tar.gz.age

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("[!] Access Denied. Backup export is CLI only.\n");
}

require_once 'db.php';

umask(0077);

function dd_backup_line(string $message = ''): void {
    echo $message . "\n";
}

function dd_backup_fail(string $message, int $code = 1): void {
    fwrite(STDERR, "[!] " . $message . "\n");
    exit($code);
}

function dd_backup_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) dd_backup_rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function dd_backup_mkdir(string $dir, int $mode = 0700): void {
    if (!is_dir($dir) && !mkdir($dir, $mode, true)) {
        throw new RuntimeException("Could not create directory: $dir");
    }
    @chmod($dir, $mode);
}

function dd_backup_copy_file(string $source, string $destination): bool {
    if (!is_file($source)) return false;
    dd_backup_mkdir(dirname($destination));
    if (!copy($source, $destination)) return false;
    @chmod($destination, 0600);
    return true;
}

function dd_backup_copy_dir(string $source, string $destination): int {
    if (!is_dir($source)) return 0;
    dd_backup_mkdir($destination);

    $copied = 0;
    $items = scandir($source);
    if ($items === false) return 0;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($src) && !is_link($src)) {
            $copied += dd_backup_copy_dir($src, $dst);
        } elseif (is_file($src)) {
            if (dd_backup_copy_file($src, $dst)) $copied++;
        }
    }
    return $copied;
}

function dd_backup_command_exists(string $cmd): bool {
    $out = [];
    $code = 127;
    @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

function dd_backup_run(string $command, ?array &$output = null): void {
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    $output = $lines;
    if ($code !== 0) {
        throw new RuntimeException("Command failed ($code): " . $command . "\n" . implode("\n", $lines));
    }
}

function dd_backup_is_placeholder_recipient(string $recipient): bool {
    $recipient = trim($recipient);
    return $recipient === ''
        || stripos($recipient, 'REPLACE_WITH') !== false
        || stripos($recipient, 'YOUR_PUBLIC') !== false;
}

function dd_backup_add_target(array &$targets, string $label, bool $added): void {
    if ($added) $targets[] = $label;
}

$no_jitter = in_array('--no-jitter', $argv, true);
$allow_plaintext = in_array('--allow-plaintext', $argv, true);

$backup_dir = rtrim((string)($config['backup_path'] ?? '/var/backups/deaddrop'), '/');
$retention = max(1, (int)($config['backup_retention'] ?? 7));
$encrypt_backups = (bool)($config['backup_encryption'] ?? true);
$age_recipient = trim((string)($config['backup_age_recipient'] ?? ''));
$config_path = (string)($config['config_path'] ?? '/etc/deaddrop/config.php');
$include_config = (bool)($config['backup_include_config'] ?? true);

if (!$encrypt_backups && !$allow_plaintext) {
    dd_backup_fail("backup_encryption=false, but plaintext backup export is blocked by default. Re-run with --allow-plaintext only for emergency local recovery.");
}

if ($encrypt_backups) {
    if (!function_exists('exec')) {
        dd_backup_fail("PHP exec() is disabled. v11.4 encrypted backup requires tar and age CLI execution from PHP CLI.");
    }
    if (!dd_backup_command_exists('tar')) dd_backup_fail("Missing required command: tar");
    if (!dd_backup_command_exists('age')) dd_backup_fail("Missing required command: age. Install with: sudo apt install age");
    if (dd_backup_is_placeholder_recipient($age_recipient)) {
        dd_backup_fail("backup_age_recipient is not configured. Generate an age keypair and put only the public recipient in /etc/deaddrop/config.php.");
    }
} else {
    if (!dd_backup_command_exists('tar')) dd_backup_fail("Missing required command: tar");
}

dd_backup_mkdir($backup_dir);

$timestamp = gmdate('Ymd_His');
$archive_base = 'deaddrop_backup_' . $timestamp . '.tar.gz';
$plain_archive_path = $backup_dir . '/' . $archive_base;
$encrypted_archive_path = $plain_archive_path . '.age';

$staging_root = sys_get_temp_dir() . '/deaddrop_backup_stage_' . bin2hex(random_bytes(8));
$temp_archive = sys_get_temp_dir() . '/' . $archive_base . '.' . bin2hex(random_bytes(4));

try {
    dd_backup_line("============================================");
    dd_backup_line("   DEADDROP ENCRYPTED BACKUP EXPORT v11.4");
    dd_backup_line("   TIME: " . gmdate('Y-m-d H:i:s') . " UTC");
    dd_backup_line("============================================\n");

    if (!$no_jitter) {
        $jitter = random_int(1, 600);
        dd_backup_line("[*] Cron jitter: sleeping for $jitter seconds...");
        sleep($jitter);
    }

    dd_backup_mkdir($staging_root);
    $targets = [];

    // Ask SQLite to checkpoint WAL so the copied database is as self-contained as possible.
    try {
        $db->exec('PRAGMA wal_checkpoint(FULL);');
    } catch (Throwable $e) {
        dd_backup_line("[!] WAL checkpoint warning: " . $e->getMessage());
    }

    // 1. SQLite database and sidecars.
    $db_path = (string)($config['db_path'] ?? '');
    if ($db_path !== '' && is_file($db_path)) {
        dd_backup_add_target($targets, 'data/deaddrop.sqlite', dd_backup_copy_file($db_path, $staging_root . '/data/deaddrop.sqlite'));
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($db_path . $suffix)) {
                dd_backup_add_target($targets, 'data/deaddrop.sqlite' . $suffix, dd_backup_copy_file($db_path . $suffix, $staging_root . '/data/deaddrop.sqlite' . $suffix));
            }
        }
    }

    // 2. Public media directory. Private DM media should remain disabled until v13 encrypted media.
    $media_dir = __DIR__ . '/media';
    if (is_dir($media_dir)) {
        $media_count = dd_backup_copy_dir($media_dir, $staging_root . '/media');
        if ($media_count > 0) $targets[] = "media ($media_count files)";
    }

    // 3. Public static outbox.
    dd_backup_add_target($targets, 'outbox.json', dd_backup_copy_file(__DIR__ . '/outbox.json', $staging_root . '/outbox.json'));

    // 4. External secret config, optional. It is encrypted in this archive, but keep the age identity offline.
    if ($include_config && is_file($config_path)) {
        dd_backup_add_target($targets, 'config/config.php', dd_backup_copy_file($config_path, $staging_root . '/config/config.php'));
    }

    // 5. Manifest for restore verification.
    $manifest = [
        'format' => 'deaddrop-backup',
        'version' => 1,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_version' => '11.4',
        'node_name' => (string)($config['node_name'] ?? ''),
        'node_url' => (string)($config['node_url'] ?? ''),
        'encrypted' => $encrypt_backups,
        'targets' => $targets,
    ];
    file_put_contents($staging_root . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @chmod($staging_root . '/manifest.json', 0600);
    $targets[] = 'manifest.json';

    if (empty($targets)) {
        throw new RuntimeException('No backup targets found.');
    }

    dd_backup_line("[>] Staged targets: " . implode(', ', $targets));
    dd_backup_line("[>] Creating tar.gz archive...");
    dd_backup_run('tar -czf ' . escapeshellarg($temp_archive) . ' -C ' . escapeshellarg($staging_root) . ' .');
    @chmod($temp_archive, 0600);

    if ($encrypt_backups) {
        dd_backup_line("[>] Encrypting archive with age recipient...");
        dd_backup_run('age -r ' . escapeshellarg($age_recipient) . ' -o ' . escapeshellarg($encrypted_archive_path) . ' ' . escapeshellarg($temp_archive));
        @chmod($encrypted_archive_path, 0600);
        @unlink($temp_archive);
        dd_backup_line("[+] ENCRYPTED BACKUP COMPLETE");
        dd_backup_line("[*] Destination: $encrypted_archive_path");
    } else {
        if (!rename($temp_archive, $plain_archive_path)) {
            throw new RuntimeException('Could not move plaintext archive into backup directory.');
        }
        @chmod($plain_archive_path, 0600);
        dd_backup_line("[!] PLAINTEXT BACKUP COMPLETE — use only for emergency local recovery.");
        dd_backup_line("[*] Destination: $plain_archive_path");
    }

    // Retention. Prefer encrypted v11.4 archives, but also prune old plaintext archives created by emergency mode.
    $patterns = [$backup_dir . '/deaddrop_backup_*.tar.gz.age'];
    if (!$encrypt_backups) $patterns[] = $backup_dir . '/deaddrop_backup_*.tar.gz';

    foreach ($patterns as $pattern) {
        $backups = glob($pattern) ?: [];
        if (count($backups) <= $retention) continue;
        rsort($backups);
        $to_delete = array_slice($backups, $retention);
        dd_backup_line("[>] Retention: pruning old backups for pattern " . basename($pattern));
        foreach ($to_delete as $old_backup) {
            @unlink($old_backup);
            dd_backup_line("    [-] Pruned: " . basename($old_backup));
        }
    }

    dd_backup_line("\n============================================");
    dd_backup_line("   BACKUP CYCLE COMPLETE");
    dd_backup_line("============================================");
} catch (Throwable $e) {
    if (is_file($temp_archive)) @unlink($temp_archive);
    if (is_file($encrypted_archive_path) && filesize($encrypted_archive_path) === 0) @unlink($encrypted_archive_path);
    dd_backup_fail("CRITICAL ERROR: " . $e->getMessage());
} finally {
    dd_backup_rrmdir($staging_root);
}
