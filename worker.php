<?php
// ==========================================
// DEADDROP: THE GUARD & COURIER (v11 - Schema v2+ Courier)
// ==========================================
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("[!] Worker is CLI only.\n");
}

require_once 'db.php';
require_once 'net.php';

header('Content-Type: text/plain; charset=utf-8');

// 🛡️ ANTI-OOM RESPONSE GUARD
// Never keep a remote outbox.json larger than 2 MB in memory.
define('DEADDROP_WORKER_MAX_RESPONSE_BYTES', 2 * 1024 * 1024);
define('DEADDROP_WORKER_MAX_POSTS_PER_NODE', 100);
define('DEADDROP_SUPPORTED_OUTBOX_SCHEMA', 2);

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function delete_signal_by_remote_id(PDO $db, string $remote_id): void {
    $stmtDelTimeline = $db->prepare("DELETE FROM timeline WHERE remote_id = :rid");
    $stmtDelInbox = $db->prepare("DELETE FROM inbox WHERE remote_id = :rid");

    $stmtDelTimeline->execute([':rid' => $remote_id]);
    $stmtDelInbox->execute([':rid' => $remote_id]);
}


function deaddrop_read_outbox_schema_version(array $feed): ?int {
    if (!array_key_exists('schema_version', $feed)) {
        return null;
    }

    $version = filter_var($feed['schema_version'], FILTER_VALIDATE_INT);
    if ($version === false || $version < 2) {
        return null;
    }

    return $version;
}

function deaddrop_normalize_remote_outbox(array $feed, string $fallback_url): ?array {
    if (!isset($feed['posts']) || !is_array($feed['posts'])) {
        return null;
    }

    $schema_version = deaddrop_read_outbox_schema_version($feed);
    if ($schema_version === null) {
        return null;
    }

    if (!isset($feed['node']) || !is_array($feed['node'])) {
        return null;
    }
    $node = $feed['node'];

    $capabilities = [];
    if (isset($node['capabilities']) && is_array($node['capabilities'])) {
        $capabilities = $node['capabilities'];
    } elseif (isset($feed['capabilities']) && is_array($feed['capabilities'])) {
        $capabilities = $feed['capabilities'];
    }

    $author = (string)($node['name'] ?? 'Unknown Node');
    $domain = rtrim((string)($node['url'] ?? $fallback_url), '/');
    if ($domain === '') {
        $domain = rtrim($fallback_url, '/');
    }

    return [
        'schema_version' => $schema_version,
        'protocol_version' => (string)($feed['protocol_version'] ?? 'unknown'),
        'author' => $author,
        'domain' => $domain,
        'public_key' => $node['public_key'] ?? null,
        'pq_public' => $node['pq_public'] ?? null,
        'signing_public_key' => $node['signing_public_key'] ?? null,
        'capabilities' => $capabilities,
        'posts' => $feed['posts']
    ];
}

function deaddrop_capability_list(array $capabilities): string {
    $enabled = [];
    foreach ($capabilities as $name => $enabled_flag) {
        if ($enabled_flag === true || $enabled_flag === 1 || $enabled_flag === '1') {
            $enabled[] = (string)$name;
        }
    }
    return empty($enabled) ? 'none advertised' : implode(',', $enabled);
}

function deaddrop_same_nullable_key(?string $a, ?string $b): bool {
    $a = $a ?? '';
    $b = $b ?? '';
    return hash_equals($a, $b);
}

function deaddrop_apply_peer_key_pinning(PDO $db, string $onion_url, ?string $remote_pub_key, ?string $remote_pq_pub, ?string $remote_signing_pub, int $is_mutual): array {
    if (empty($remote_pub_key) || empty($remote_signing_pub)) {
        $stmt = $db->prepare("UPDATE following SET is_mutual = :mut WHERE onion_url = :url");
        $stmt->execute([':mut' => $is_mutual, ':url' => $onion_url]);
        return ['allowed' => true, 'status' => 'missing_key', 'message' => 'Encryption or signing public key missing.'];
    }

    $stmt_peer = $db->prepare("SELECT public_key, pq_public, signing_public_key, trust_status, pending_public_key, pending_signing_public_key FROM following WHERE onion_url = :url LIMIT 1");
    $stmt_peer->execute([':url' => $onion_url]);
    $peer = $stmt_peer->fetch(PDO::FETCH_ASSOC);

    if (!$peer) {
        return ['allowed' => true, 'status' => 'untracked', 'message' => 'Peer is not in following table.'];
    }

    $pinned_pub = $peer['public_key'] ?? null;
    $pinned_pq = $peer['pq_public'] ?? null;
    $pinned_signing = $peer['signing_public_key'] ?? null;

    if (empty($pinned_pub) || empty($pinned_signing)) {
        $stmt_pin = $db->prepare("
            UPDATE following
            SET public_key = :pub,
                pq_public = :pq,
                signing_public_key = :sign_pub,
                pending_public_key = NULL,
                pending_pq_public = NULL,
                pending_signing_public_key = NULL,
                key_changed_at = NULL,
                trust_status = 'trusted',
                trust_updated_at = CURRENT_TIMESTAMP,
                is_mutual = :mut
            WHERE onion_url = :url
        ");
        $stmt_pin->execute([
            ':pub' => $remote_pub_key,
            ':pq' => $remote_pq_pub,
            ':sign_pub' => $remote_signing_pub,
            ':mut' => $is_mutual,
            ':url' => $onion_url,
        ]);
        return ['allowed' => true, 'status' => 'pinned', 'message' => 'First encryption and signing keys pinned.'];
    }

    if (
        hash_equals((string)$pinned_pub, (string)$remote_pub_key)
        && deaddrop_same_nullable_key($pinned_pq, $remote_pq_pub)
        && hash_equals((string)$pinned_signing, (string)$remote_signing_pub)
    ) {
        $stmt_ok = $db->prepare("
            UPDATE following
            SET pending_public_key = NULL,
                pending_pq_public = NULL,
                pending_signing_public_key = NULL,
                key_changed_at = NULL,
                trust_status = 'trusted',
                trust_updated_at = CURRENT_TIMESTAMP,
                is_mutual = :mut
            WHERE onion_url = :url
        ");
        $stmt_ok->execute([':mut' => $is_mutual, ':url' => $onion_url]);
        return ['allowed' => true, 'status' => 'trusted', 'message' => 'Pinned key matched.'];
    }

    $stmt_changed = $db->prepare("
        UPDATE following
        SET pending_public_key = :pending_pub,
            pending_pq_public = :pending_pq,
            pending_signing_public_key = :pending_sign_pub,
            key_changed_at = CURRENT_TIMESTAMP,
            trust_status = 'key_changed',
            is_mutual = :mut
        WHERE onion_url = :url
    ");
    $stmt_changed->execute([
        ':pending_pub' => $remote_pub_key,
        ':pending_pq' => $remote_pq_pub,
        ':pending_sign_pub' => $remote_signing_pub,
        ':mut' => $is_mutual,
        ':url' => $onion_url,
    ]);

    return ['allowed' => false, 'status' => 'key_changed', 'message' => 'Remote encryption or signing key changed; sync paused until Radar approval.'];
}

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

function deaddrop_verify_outbox_post_signature(array $post, string $node_url, ?string $signing_public_key): bool {
    if (($post['signature_algorithm'] ?? '') !== 'ed25519') {
        return false;
    }

    $signature = base64_decode((string)($post['post_signature'] ?? ''), true);
    if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return false;
    }

    $public_key = base64_decode((string)$signing_public_key, true);
    if ($public_key === false || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return false;
    }

    try {
        $payload = deaddrop_post_signing_payload($post, $node_url);
    } catch (Throwable $e) {
        return false;
    }

    return sodium_crypto_sign_verify_detached($signature, $payload, $public_key);
}

function deaddrop_peer_moderation_policy(PDO $db, string $onion_url): array {
    $stmt = $db->prepare("SELECT moderation_status, remote_media_policy FROM following WHERE onion_url = :url LIMIT 1");
    $stmt->execute([':url' => $onion_url]);
    $peer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$peer) {
        return ['moderation_status' => 'active', 'remote_media_policy' => 'allow'];
    }

    $moderation_status = (string)($peer['moderation_status'] ?? 'active');
    if (!in_array($moderation_status, ['active', 'quarantined', 'blocked'], true)) {
        $moderation_status = 'active';
    }

    $remote_media_policy = (string)($peer['remote_media_policy'] ?? 'allow');
    if (!in_array($remote_media_policy, ['allow', 'drop'], true)) {
        $remote_media_policy = 'allow';
    }

    return [
        'moderation_status' => $moderation_status,
        'remote_media_policy' => $remote_media_policy,
    ];
}

echo "============================================\n";
echo "   DEADDROP WORKER INITIATED (V12 SIGNED SCHEMA V2+)\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

// 👶 OBAT TIDUR ACAK (Cron Jitter 1 - 10 Menit)
$jitter = random_int(1, 600);
echo "[*] OpSec Jitter Engaged: Courier sleeping for $jitter seconds to obfuscate cron signature...\n";
sleep($jitter);

try {
    $now_utc = gmdate('Y-m-d\TH:i:s\Z');

    echo "[>] Running Ephemeral Sweeper...\n";
    $stmt_exp = $db->prepare("
        SELECT media_url, remote_id FROM timeline WHERE expires_at IS NOT NULL AND expires_at <= :now
        UNION ALL
        SELECT media_url, remote_id FROM inbox WHERE expires_at IS NOT NULL AND expires_at <= :now
    ");
    $stmt_exp->execute([':now' => $now_utc]);
    $expired_count = 0;
    foreach ($stmt_exp->fetchAll(PDO::FETCH_ASSOC) as $exp) {
        if (!empty($exp['media_url'])) {
            $target_media = __DIR__ . '/media/' . basename($exp['media_url']);
            if (file_exists($target_media)) exec('shred -u -z -n 3 ' . escapeshellarg($target_media));
        }
        delete_signal_by_remote_id($db, (string)$exp['remote_id']);
        $expired_count++;
    }
    if ($expired_count > 0) echo "    [+] Purged and shredded $expired_count expired ephemeral signals.\n\n";

    // 📡 GATHER TARGET NODES
    $target_urls = [];
    $stmt_ping = $db->query("SELECT DISTINCT source_url FROM ping_queue WHERE status = 'trusted'");
    foreach ($stmt_ping->fetchAll(PDO::FETCH_ASSOC) as $p) $target_urls[] = $p['source_url'];
    
    $stmt_follow = $db->query("SELECT onion_url FROM following WHERE moderation_status = 'active'");
    foreach ($stmt_follow->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (!in_array($f['onion_url'], $target_urls)) $target_urls[] = $f['onion_url'];
    }

    if (empty($target_urls)) die("[*] Radar and Queue are empty. Going back to sleep...\n");

    // 🔐 CRYPTOGRAPHIC KEYPAIR IGNITION
    $my_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        base64_decode($config['private_key']), base64_decode($config['public_key'])
    );
    
    $my_pq_keypair = null;
    if (!empty($config['pq_private']) && !empty($config['pq_public'])) {
        $my_pq_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            base64_decode($config['pq_private']), base64_decode($config['pq_public'])
        );
    }

    echo "[>] Constructing SOCKS5 Persistent Tunnels for " . count($target_urls) . " nodes...\n";
    
    $multi_handle = curl_multi_init();
    $curl_handles = [];
    $curl_buffers = [];
    $curl_too_large = [];

    foreach ($target_urls as $peer_url_raw) {
        $policy_error = null;
        $onion_url = deaddrop_normalize_and_validate_peer_url((string)$peer_url_raw, $config, $policy_error);
        if ($onion_url === null) {
            echo "    [!] Skipping rejected peer endpoint: " . strip_tags((string)$peer_url_raw) . " // " . $policy_error . "\n";
            continue;
        }

        $peer_policy = deaddrop_peer_moderation_policy($db, $onion_url);
        if ($peer_policy['moderation_status'] !== 'active') {
            echo "    [!] Skipping moderated peer: " . deaddrop_url_host($onion_url) . " is " . $peer_policy['moderation_status'] . ".\n";
            continue;
        }

        $outbox_url = $onion_url . '/outbox.json';
        $host_domain = deaddrop_url_host($onion_url);
        $is_onion = deaddrop_should_use_tor_proxy($onion_url);

        $curl_buffers[$onion_url] = '';
        $curl_too_large[$onion_url] = false;

        $ch = curl_init($outbox_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 0); 
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 0);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$curl_buffers, &$curl_too_large, $onion_url): int {
            $current_size = strlen($curl_buffers[$onion_url]);
            $chunk_size = strlen($chunk);

            if (($current_size + $chunk_size) > DEADDROP_WORKER_MAX_RESPONSE_BYTES) {
                $curl_too_large[$onion_url] = true;
                return 0; // Abort this transfer immediately.
            }

            $curl_buffers[$onion_url] .= $chunk;
            return $chunk_size;
        });

        if ($is_onion) {
            curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
        
        curl_multi_add_handle($multi_handle, $ch);
        $curl_handles[$onion_url] = $ch;
    }

    echo "[>] Firing Concurrent Requests (Ignition)...\n";
    
    $active = null;
    do {
        $mrc = curl_multi_exec($multi_handle, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($multi_handle) == -1) usleep(100);
        do {
            $mrc = curl_multi_exec($multi_handle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }

    echo "[+] Data received. Processing payloads...\n\n";

    foreach ($curl_handles as $onion_url => $ch) {
        $json_response = $curl_buffers[$onion_url] ?? '';
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $was_too_large = $curl_too_large[$onion_url] ?? false;
        
        curl_multi_remove_handle($multi_handle, $ch);
        curl_close($ch);
        unset($curl_buffers[$onion_url], $curl_too_large[$onion_url]);

        $host_domain = deaddrop_url_host($onion_url);

        if ($was_too_large || strlen($json_response) > DEADDROP_WORKER_MAX_RESPONSE_BYTES) {
            echo "    [!] Node skipped: " . $host_domain . " sent an outbox larger than " . format_bytes(DEADDROP_WORKER_MAX_RESPONSE_BYTES) . ".\n";
            continue;
        }
        
        if ($http_code !== 200 || $json_response === '') {
            $reason = $curl_error ? " (cURL: $curl_error)" : '';
            echo "    [!] Node Offline or Timeout: " . $host_domain . $reason . "\n";
            continue;
        }

        $feed = json_decode($json_response, true);
        if (!is_array($feed)) {
            echo "    [!] Invalid JSON outbox from: " . $host_domain . "\n";
            continue;
        }

        $normalized_feed = deaddrop_normalize_remote_outbox($feed, $onion_url);
        if ($normalized_feed === null) {
            echo "    [!] Skipping legacy or invalid outbox from: " . $host_domain . " (schema_version >= 2 and node metadata required).\n";
            continue;
        }

        $schema_version = (int)$normalized_feed['schema_version'];
        if ($schema_version > DEADDROP_SUPPORTED_OUTBOX_SCHEMA) {
            echo "    [!] Future outbox schema v" . $schema_version . " from " . $host_domain . "; attempting compatibility mode.\n";
        }

        if (count($normalized_feed['posts']) > DEADDROP_WORKER_MAX_POSTS_PER_NODE) {
            echo "    [!] Node capped: " . $host_domain . " advertised " . count($normalized_feed['posts']) . " posts; processing latest " . DEADDROP_WORKER_MAX_POSTS_PER_NODE . " only.\n";
            $normalized_feed['posts'] = array_slice($normalized_feed['posts'], -DEADDROP_WORKER_MAX_POSTS_PER_NODE);
        }

        echo "    [>] Syncing Node: " . $host_domain . " (schema v" . $schema_version . ", " . format_bytes(strlen($json_response)) . ")\n";
        echo "        Capabilities: " . deaddrop_capability_list($normalized_feed['capabilities']) . "\n";

        $my_clean_url = rtrim($config['node_url'], '/');
        $is_mutual = (strpos($json_response, $my_clean_url) !== false) ? 1 : 0;
        $remote_pub_key = $normalized_feed['public_key'] ?? null;
        $remote_pq_pub = $normalized_feed['pq_public'] ?? null;
        $remote_signing_pub = $normalized_feed['signing_public_key'] ?? null;

        $trust_result = deaddrop_apply_peer_key_pinning($db, $onion_url, $remote_pub_key, $remote_pq_pub, $remote_signing_pub, $is_mutual);
        if (!empty($trust_result['message'])) {
            echo "        Trust: " . $trust_result['message'] . "\n";
        }
        if (empty($trust_result['allowed'])) {
            echo "    [!] KEY CHANGED: " . $host_domain . " sync paused. Approve or reject the pending key in Radar.\n";
            continue;
        }

        $author_name = $normalized_feed['author'] ?? 'Unknown Node';
        $author_domain = rtrim($normalized_feed['domain'] ?? $onion_url, '/');
        $peer_policy = deaddrop_peer_moderation_policy($db, $onion_url);
        $new_posts_count = 0;

        $check_timeline = $db->prepare("SELECT COUNT(*) FROM timeline WHERE remote_id = :rid");
        $check_inbox = $db->prepare("SELECT COUNT(*) FROM inbox WHERE remote_id = :rid");

        $posts_reversed = array_reverse($normalized_feed['posts']);

        foreach ($posts_reversed as $post) {
            $remote_id = $post['id'] ?? null;
            if (!$remote_id) continue;

            if (!deaddrop_verify_outbox_post_signature($post, $author_domain, $remote_signing_pub)) {
                echo "        [!] Skipping unsigned or invalidly signed post: " . $remote_id . "\n";
                continue;
            }

            $remote_status = $post['status'] ?? 'active';

            // Tombstone Protocol Intercept
            if ($remote_status === 'deleted') {
                $stmt_del_media = $db->prepare("SELECT media_url FROM timeline WHERE remote_id = :rid UNION SELECT media_url FROM inbox WHERE remote_id = :rid");
                $stmt_del_media->execute([':rid' => $remote_id]);
                $del_media = $stmt_del_media->fetchColumn();
                
                // PHYSICAL DATA VAPORIZATION (Shredding external media)
                if ($del_media) {
                    $target_del_media = __DIR__ . '/media/' . basename($del_media);
                    if (file_exists($target_del_media)) exec('shred -u -z -n 3 ' . escapeshellarg($target_del_media));
                }
                
                delete_signal_by_remote_id($db, (string)$remote_id);
                continue;
            }

            $check_timeline->execute([':rid' => $remote_id]);
            $check_inbox->execute([':rid' => $remote_id]);

            if ($check_timeline->fetchColumn() == 0 && $check_inbox->fetchColumn() == 0) {
                
                $raw_content = $post['content'] ?? '';
                $is_decrypted_successfully = false;
                $is_burner_received = false;

                if (strpos($raw_content, 'HYBRID:') === 0 || strpos($raw_content, 'HYBRID-BURNER:') === 0) {
                    $is_burner_received = (strpos($raw_content, 'HYBRID-BURNER:') === 0);
                    $offset = $is_burner_received ? 14 : 7;
                    
                    $payload_str = substr($raw_content, $offset);
                    $decrypted = false;

                    $parts = explode('::', $payload_str);
                    if (count($parts) === 3) {
                        $nonce = base64_decode($parts[0]);
                        $kem_layer2 = base64_decode($parts[1]);
                        $ciphertext = base64_decode($parts[2]);

                        $kem_layer1 = false;
                        if ($my_pq_keypair) $kem_layer1 = sodium_crypto_box_seal_open($kem_layer2, $my_pq_keypair);
                        if ($kem_layer1 === false) $kem_layer1 = $kem_layer2; 

                        $sym_key = sodium_crypto_box_seal_open($kem_layer1, $my_keypair);
                        if ($sym_key !== false) {
                            $decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $sym_key);
                        }
                    }

                    if ($decrypted !== false) {
                        $is_decrypted_successfully = true; 
                        
                        if ($config['tg_on'] && !empty($config['tg_token']) && !empty($config['tg_chat'])) {
                            $drop_type = $is_burner_received ? "🔥 HYBRID BURNER" : "🔓 HYBRID DROP";
                            $msg_tg = "INBOX SECURE ALERT: 1 valid $drop_type received and authenticated from @" . $author_name;
                            
                            $url_tg = "https://api.telegram.org/bot" . $config['tg_token'] . "/sendMessage";
                            $chTg = curl_init($url_tg);
                            curl_setopt($chTg, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($chTg, CURLOPT_POST, true);
                            curl_setopt($chTg, CURLOPT_POSTFIELDS, ['chat_id' => $config['tg_chat'], 'text' => $msg_tg]);
                            curl_setopt($chTg, CURLOPT_TIMEOUT, 15);
                            
                            curl_setopt($chTg, CURLOPT_PROXY, "127.0.0.1:9050");
                            curl_setopt($chTg, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                            
                            curl_exec($chTg);
                            curl_close($chTg);
                        }
                    } else {
                        $raw_content = "[🔒 ENCRYPTED HYBRID CIPHERTEXT] // Foreign vault payload.";
                        $is_burner_received = false;
                    }
                }

                $safe_media = $post['media_url'] ?? null;
                if ($is_decrypted_successfully) {
                    // Private drops must not auto-load remote/public media URLs.
                    // Encrypted media support should carry a file key inside the encrypted payload instead.
                    $safe_media = null;
                } elseif ($peer_policy['remote_media_policy'] === 'drop') {
                    $safe_media = null;
                } elseif ($safe_media && !preg_match('/^https?:\/\//i', $safe_media)) {
                    $safe_media = null;
                }

                $target_table = $is_decrypted_successfully ? 'inbox' : 'timeline';
                $expires_at = $post['expires_at'] ?? null;
                $final_status = $is_burner_received ? 'burner' : 'active';

                $stmt_insert = $db->prepare("INSERT INTO $target_table (remote_id, author_name, author_host, content, media_url, is_local, reply_to, status, expires_at, created_at) 
                                             VALUES (:rid, :name, :host, :content, :media, 0, :reply, :stat, :expires, :waktu)");

                $stmt_insert->execute([
                    ':rid'     => $remote_id,
                    ':name'    => $author_name,
                    ':host'    => $author_domain,
                    ':content' => $raw_content,
                    ':media'   => $safe_media,
                    ':reply'   => $post['reply_to'] ?? null,
                    ':stat'    => $final_status,
                    ':expires' => $expires_at,
                    ':waktu'   => $post['timestamp'] ?? $now_utc
                ]);
                $new_posts_count++;
            }
        }

        $db->prepare("UPDATE following SET last_pulled = CURRENT_TIMESTAMP WHERE onion_url = :url")->execute([':url' => $onion_url]);
        echo "    [+] Extracted $new_posts_count new signals.\n";
    }
    
    curl_multi_close($multi_handle);

    // Garbage Collection & Trim
    $db->exec("DELETE FROM ping_queue WHERE status = 'trusted'");
    $db->exec("DELETE FROM timeline WHERE is_local = 0 AND id NOT IN (SELECT id FROM timeline WHERE is_local = 0 ORDER BY created_at DESC LIMIT 2000)");

} catch (Exception $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "   WORKER CYCLE COMPLETE\n";
echo "============================================\n";
?>
