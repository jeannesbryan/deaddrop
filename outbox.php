<?php
// ==========================================
// DEADDROP: OUTBOX ATOMIC BROADCAST HELPER (v11 - Schema v2)
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

if (!function_exists('deaddrop_outbox_schema_version')) {
    function deaddrop_outbox_schema_version(array $config): int {
        // v11 exporter intentionally emits schema v2 only.
        return 2;
    }
}

if (!function_exists('deaddrop_outbox_capabilities')) {
    function deaddrop_outbox_capabilities(array $config): array {
        // Public capability advertisement. Keep false entries visible so future workers can
        // distinguish "unsupported" from "unknown/not advertised".
        return [
            'e2ee' => true,
            'private_drops' => true,
            'public_media' => true,
            'private_media' => true,
            'ttl' => true,
            'burner' => true,
            'tombstone' => true,
            'pow_knock' => true,
            'atomic_outbox' => true,
            'server_side_unlock_session' => true,
            'signed_posts' => true,
            'encrypted_media' => true,
            'paranoid_inbox' => true,
            'pq_placeholder' => !empty($config['pq_public'])
        ];
    }
}

if (!function_exists('deaddrop_post_signing_payload')) {
    function deaddrop_post_signing_payload(array $post, string $node_url): string {
        $payload = [
            'node_url' => rtrim($node_url, '/'),
            'id' => (string)($post['id'] ?? ''),
            'content' => (string)($post['content'] ?? ''),
            'media_url' => $post['media_url'] ?? null,
            'reply_to' => $post['reply_to'] ?? null,
            'status' => (string)($post['status'] ?? 'active'),
            'expires_at' => $post['expires_at'] ?? null,
            'timestamp' => (string)($post['timestamp'] ?? ''),
            'schema_version' => (int)($post['schema_version'] ?? 2),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('deaddrop_sign_outbox_post')) {
    function deaddrop_sign_outbox_post(array $post, array $config): array {
        $signing_private = base64_decode((string)($config['signing_private_key'] ?? ''), true);
        if ($signing_private === false || strlen($signing_private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Node signing private key is missing or invalid.');
        }

        $node_url = rtrim((string)($config['node_url'] ?? ''), '/');
        $payload = deaddrop_post_signing_payload($post, $node_url);
        $signature = sodium_crypto_sign_detached($payload, $signing_private);

        $post['signature_algorithm'] = 'ed25519';
        $post['post_signature'] = base64_encode($signature);

        return $post;
    }
}

if (!function_exists('deaddrop_prepare_outbox_post')) {
    function deaddrop_prepare_outbox_post(array $post, array $config): array {
        $content = (string)($post['content'] ?? '');

        // Split-ledger private drops keep plaintext locally before the marker.
        // Only ciphertext may leave through outbox.json.
        if (strpos($content, '[[SPLIT_LEDGER]]') !== false) {
            $ledger_parts = explode('[[SPLIT_LEDGER]]', $content, 2);
            $post['content'] = $ledger_parts[1] ?? '';
            $post['media_url'] = null;
        } elseif (strpos($content, 'HYBRID:') === 0 || strpos($content, 'HYBRID-BURNER:') === 0) {
            $post['media_url'] = null;
        }

        // Post-level marker keeps future migrations explicit.
        $post['schema_version'] = 2;

        return deaddrop_sign_outbox_post($post, $config);
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
            $my_posts[] = deaddrop_prepare_outbox_post($post, $config);
        }

        $schema_version = deaddrop_outbox_schema_version($config);
        $capabilities = deaddrop_outbox_capabilities($config);
        $node_url = rtrim((string)($config['node_url'] ?? ''), '/');

        $nano_pub_feed = [
            // v12 canonical feed metadata. Schema remains v2; protocol_version advertises v12 features.
            'protocol' => 'Nano-Pub',
            'protocol_version' => '12',
            'schema_version' => $schema_version,
            'generated_at' => $now_utc,
            'node' => [
                'name' => $config['node_name'] ?? 'Unknown Node',
                'url' => $node_url,
                'public_key' => $config['public_key'] ?? null,
                'pq_public' => $config['pq_public'] ?? null,
                'signing_public_key' => $config['signing_public_key'] ?? null,
                'capabilities' => $capabilities
            ],
            'capabilities' => $capabilities,

            'posts' => $my_posts
        ];

        $json = json_encode($nano_pub_feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $outbox_path = $config['outbox_path'] ?? (__DIR__ . '/outbox.json');

        deaddrop_atomic_write_file($outbox_path, $json . "\n", 0644);
    }
}
?>
