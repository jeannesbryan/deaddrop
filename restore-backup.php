<?php
// ==========================================
// 🏴‍☠️ DEADDROP: ENCRYPTED BACKUP RESTORE (v11.4)
// ==========================================
// Usage:
//   sudo php restore-backup.php /var/backups/deaddrop/deaddrop_backup_YYYYmmdd_HHMMSS.tar.gz.age /path/to/age_identity.txt --dry-run
//   sudo php restore-backup.php /var/backups/deaddrop/deaddrop_backup_YYYYmmdd_HHMMSS.tar.gz.age /path/to/age_identity.txt --yes

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("[!] Access Denied. Backup restore is CLI only.\n");
}

require_once 'db.php';

umask(0077);

function dd_restore_line(string $message = ''): void {
    echo $message . "\n";
}

function dd_restore_fail(string $message, int $code = 1): void {
    fwrite(STDERR, "[!] " . $message . "\n");
    exit($code);
}

function dd_restore_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) dd_restore_rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function dd_restore_mkdir(string $dir, int $mode = 0700): void {
    if (!is_dir($dir) && !mkdir($dir, $mode, true)) {
        throw new RuntimeException("Could not create directory: $dir");
    }
    @chmod($dir, $mode);
}

function dd_restore_copy_file(string $source, string $destination, int $mode = 0600): bool {
    if (!is_file($source)) return false;
    dd_restore_mkdir(dirname($destination));
    if (!copy($source, $destination)) return false;
    @chmod($destination, $mode);
    return true;
}

function dd_restore_copy_dir(string $source, string $destination, int $file_mode = 0644): int {
    if (!is_dir($source)) return 0;
    dd_restore_mkdir($destination, 0755);
    $copied = 0;
    $items = scandir($source);
    if ($items === false) return 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src) && !is_link($src)) {
            $copied += dd_restore_copy_dir($src, $dst, $file_mode);
        } elseif (is_file($src)) {
            if (dd_restore_copy_file($src, $dst, $file_mode)) $copied++;
        }
    }
    return $copied;
}

function dd_restore_command_exists(string $cmd): bool {
    $out = [];
    $code = 127;
    @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

function dd_restore_run(string $command, ?array &$output = null): void {
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    $output = $lines;
    if ($code !== 0) {
        throw new RuntimeException("Command failed ($code): " . $command . "\n" . implode("\n", $lines));
    }
}

function dd_restore_prompt_confirm(): void {
    fwrite(STDOUT, "\nType RESTORE to overwrite local DeadDrop data: ");
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'RESTORE') {
        dd_restore_fail('Restore aborted by operator.', 2);
    }
}

$args = array_values(array_filter(array_slice($argv, 1), static fn($v) => $v !== ''));
$dry_run = in_array('--dry-run', $args, true);
$yes = in_array('--yes', $args, true);
$args = array_values(array_filter($args, static fn($v) => !in_array($v, ['--dry-run', '--yes'], true)));

$archive_path = $args[0] ?? '';
$identity_path = $args[1] ?? (getenv('DEADDROP_AGE_IDENTITY') ?: '');

if ($archive_path === '' || in_array($archive_path, ['-h', '--help'], true)) {
    dd_restore_line("Usage:");
    dd_restore_line("  sudo php restore-backup.php <backup.tar.gz.age> <age_identity_file> --dry-run");
    dd_restore_line("  sudo php restore-backup.php <backup.tar.gz.age> <age_identity_file> --yes");
    dd_restore_line("");
    dd_restore_line("You may also set DEADDROP_AGE_IDENTITY=/path/to/identity.txt.");
    exit(0);
}

if (!is_file($archive_path)) dd_restore_fail("Backup archive not found: $archive_path");
if (!is_readable($archive_path)) dd_restore_fail("Backup archive is not readable: $archive_path");
if ($identity_path === '') dd_restore_fail("Missing age identity file. Pass it as argument 2 or set DEADDROP_AGE_IDENTITY.");
if (!is_file($identity_path) || !is_readable($identity_path)) dd_restore_fail("Age identity file is not readable: $identity_path");

if (!function_exists('exec')) dd_restore_fail("PHP exec() is disabled. v11.4 restore requires tar and age CLI execution from PHP CLI.");
if (!dd_restore_command_exists('age')) dd_restore_fail("Missing required command: age");
if (!dd_restore_command_exists('tar')) dd_restore_fail("Missing required command: tar");

$app_root = __DIR__;
$db_path = (string)($config['db_path'] ?? '/var/lib/deaddrop/deaddrop.sqlite');
$config_path = (string)($config['config_path'] ?? '/etc/deaddrop/config.php');
$backup_dir = rtrim((string)($config['backup_path'] ?? '/var/backups/deaddrop'), '/');

$restore_root = sys_get_temp_dir() . '/deaddrop_restore_' . bin2hex(random_bytes(8));
$decrypted_archive = $restore_root . '/backup.tar.gz';
$extract_dir = $restore_root . '/extract';

try {
    dd_restore_line("============================================");
    dd_restore_line("   DEADDROP ENCRYPTED BACKUP RESTORE v11.4");
    dd_restore_line("   TIME: " . gmdate('Y-m-d H:i:s') . " UTC");
    dd_restore_line("============================================\n");

    dd_restore_mkdir($restore_root);
    dd_restore_mkdir($extract_dir);

    dd_restore_line("[>] Decrypting archive with age identity...");
    dd_restore_run('age -d -i ' . escapeshellarg($identity_path) . ' -o ' . escapeshellarg($decrypted_archive) . ' ' . escapeshellarg($archive_path));
    @chmod($decrypted_archive, 0600);

    dd_restore_line("[>] Extracting backup archive into isolated staging area...");
    dd_restore_run('tar -xzf ' . escapeshellarg($decrypted_archive) . ' -C ' . escapeshellarg($extract_dir));

    $manifest_path = $extract_dir . '/manifest.json';
    $manifest = [];
    if (is_file($manifest_path)) {
        $decoded = json_decode((string)file_get_contents($manifest_path), true);
        if (is_array($decoded)) $manifest = $decoded;
    }

    dd_restore_line("[>] Backup manifest:");
    dd_restore_line("    format: " . (string)($manifest['format'] ?? 'unknown'));
    dd_restore_line("    version: " . (string)($manifest['version'] ?? 'unknown'));
    dd_restore_line("    created_at: " . (string)($manifest['created_at'] ?? 'unknown'));
    dd_restore_line("    node_url: " . (string)($manifest['node_url'] ?? 'unknown'));

    $planned = [];
    if (is_file($extract_dir . '/data/deaddrop.sqlite')) $planned[] = "SQLite database -> $db_path";
    if (is_dir($extract_dir . '/media')) $planned[] = "media directory -> $app_root/media";
    if (is_file($extract_dir . '/outbox.json')) $planned[] = "outbox.json -> $app_root/outbox.json";
    if (is_file($extract_dir . '/config/config.php')) $planned[] = "config.php -> $config_path";

    if (empty($planned)) {
        throw new RuntimeException('Backup archive does not contain recognized DeadDrop targets.');
    }

    dd_restore_line("\n[>] Restore plan:");
    foreach ($planned as $line) dd_restore_line("    - " . $line);

    if ($dry_run) {
        dd_restore_line("\n[+] DRY RUN COMPLETE. No local files were modified.");
        exit(0);
    }

    if (!$yes) dd_restore_prompt_confirm();

    // Safety copy of current live files into private backup dir before overwriting.
    $safety_dir = $backup_dir . '/restore_safety_' . gmdate('Ymd_His');
    dd_restore_mkdir($safety_dir);
    if (is_file($db_path)) {
        dd_restore_copy_file($db_path, $safety_dir . '/current/deaddrop.sqlite');
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($db_path . $suffix)) dd_restore_copy_file($db_path . $suffix, $safety_dir . '/current/deaddrop.sqlite' . $suffix);
        }
    }
    if (is_file($config_path)) dd_restore_copy_file($config_path, $safety_dir . '/current/config.php');
    if (is_file($app_root . '/outbox.json')) dd_restore_copy_file($app_root . '/outbox.json', $safety_dir . '/current/outbox.json');
    @chmod($safety_dir, 0700);
    dd_restore_line("[>] Safety copy created: $safety_dir");

    // Restore database.
    if (is_file($extract_dir . '/data/deaddrop.sqlite')) {
        dd_restore_mkdir(dirname($db_path));
        dd_restore_copy_file($extract_dir . '/data/deaddrop.sqlite', $db_path, 0600);
        foreach (['-wal', '-shm'] as $suffix) {
            $src = $extract_dir . '/data/deaddrop.sqlite' . $suffix;
            if (is_file($src)) dd_restore_copy_file($src, $db_path . $suffix, 0600);
            elseif (is_file($db_path . $suffix)) @unlink($db_path . $suffix);
        }
        dd_restore_line("[+] Restored SQLite database.");
    }

    // Restore public media additively. It overwrites matching filenames but does not delete extra local files.
    if (is_dir($extract_dir . '/media')) {
        $media_count = dd_restore_copy_dir($extract_dir . '/media', $app_root . '/media', 0644);
        dd_restore_line("[+] Restored media files: $media_count");
    }

    // Restore outbox.
    if (is_file($extract_dir . '/outbox.json')) {
        dd_restore_copy_file($extract_dir . '/outbox.json', $app_root . '/outbox.json', 0644);
        dd_restore_line("[+] Restored outbox.json.");
    }

    // Restore config if permissions allow.
    if (is_file($extract_dir . '/config/config.php')) {
        if (is_writable($config_path) || (!file_exists($config_path) && is_writable(dirname($config_path)))) {
            dd_restore_copy_file($extract_dir . '/config/config.php', $config_path, 0640);
            dd_restore_line("[+] Restored config.php.");
        } else {
            dd_restore_line("[!] Config restore skipped: $config_path is not writable by this process.");
            dd_restore_line("    Manual restore source: " . $extract_dir . '/config/config.php');
        }
    }

    dd_restore_line("\n[+] RESTORE COMPLETE.");
    dd_restore_line("[*] Recommended next commands:");
    dd_restore_line("    sudo chown -R www-data:www-data " . escapeshellarg(dirname($db_path)) . " " . escapeshellarg($app_root . '/media'));
    dd_restore_line("    sudo chown root:www-data " . escapeshellarg($config_path));
    dd_restore_line("    sudo chmod 0640 " . escapeshellarg($config_path));
    dd_restore_line("    sudo systemctl restart php8.2-fpm nginx");
} catch (Throwable $e) {
    dd_restore_fail('RESTORE ERROR: ' . $e->getMessage());
} finally {
    if (is_file($decrypted_archive)) @unlink($decrypted_archive);
    dd_restore_rrmdir($restore_root);
}
