<?php
// ==========================================
// 🏴‍☠️ DEADDROP: OUTBOX ATOMIC BROADCAST HELPER (v9.0)
// ==========================================

if (!function_exists('deaddrop_atomic_write_file')) {
    function deaddrop_atomic_write_file(string $path, string $contents, int $mode = 0644): void {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("Outbox directory is not writable: " . $dir);
        }

        $tmp = $dir . '/.' . basename($path) . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Could not create temporary outbox file.");
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException("Could not lock temporary outbox file.");
            }

            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $chunk = fwrite($fh, substr($contents, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new RuntimeException("Could not write complete outbox file.");
                }
                $written += $chunk;
            }

            fflush($fh);
            if (function_exists('fsync')) {
                fsync($fh);
            }

            flock($fh, LOCK_UN);
            fclose($fh);
            $fh = null;

            chmod($tmp, $mode);

            if (!rename($tmp, $path)) {
                throw new RuntimeException("Could not atomically publish outbox file.");
            }
        } catch (Throwable $e) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }
    }
}

if (!function_exists('deaddrop_prepare_outbox_post')) {
    function deaddrop_prepare_outbox_post(array $post): array {
        $content = (string)($post['content'] ?? '');

        // Split-ledger private drops keep plaintext locally before the marker.
        // Only ciphertext may leave through outbox.json.
        if (strpos($content, '[[SPLIT_LEDGER]]') !== false) {
            $ledger_parts = explode('[[SPLIT_LEDGER]]', $content, 2);
            $post['content'] = $ledger_parts[1] ?? '';
            $post['media_url'] = null;
        }

        return $post;
    }
}

if (!function_exists('rebuild_outbox')) {
    function rebuild_outbox(PDO $db, array $config): void {
        $now_utc = gmdate('Y-m-d\TH:i:s\Z');
        $max_outbox = isset($config['max_outbox']) ? (int)$config['max_outbox'] : 50;
        if ($max_outbox < 1) $max_outbox = 50;
        if ($max_outbox > 500) $max_outbox = 500;

        $stmt_out = $db->prepare("
            SELECT id, content, media_url, reply_to, status, expires_at, timestamp FROM (
                SELECT remote_id as id, content, media_url, reply_to, status, expires_at, created_at as timestamp
                FROM timeline WHERE is_local = 1
                UNION ALL
                SELECT remote_id as id, content, media_url, reply_to, status, expires_at, created_at as timestamp
                FROM inbox WHERE is_local = 1
            ) ORDER BY timestamp DESC LIMIT :limit
        ");
        $stmt_out->bindValue(':limit', $max_outbox, PDO::PARAM_INT);
        $stmt_out->execute();

        $my_posts = [];
        foreach ($stmt_out->fetchAll(PDO::FETCH_ASSOC) as $post) {
            $my_posts[] = deaddrop_prepare_outbox_post($post);
        }

        $nano_pub_feed = [
            'protocol'     => 'Nano-Pub',
            'author'       => $config['node_name'] ?? 'Unknown Node',
            'domain'       => $config['node_url'] ?? '',
            'public_key'   => $config['public_key'] ?? null,
            'pq_public'    => $config['pq_public'] ?? null,
            'last_updated' => $now_utc,
            'posts'        => $my_posts
        ];

        $json = json_encode($nano_pub_feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $outbox_path = $config['outbox_path'] ?? (__DIR__ . '/outbox.json');

        deaddrop_atomic_write_file($outbox_path, $json . "\n", 0644);
    }
}
?>
