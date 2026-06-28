<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE GUARD & COURIER (v9.0 - Airgapped & Anti-Forensics)
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

echo "============================================\n";
echo "   DEADDROP WORKER INITIATED (STRICT V9 HYBRID)\n";
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
    $stmt_ping = $db->query("SELECT DISTINCT source_url FROM ping_queue");
    foreach ($stmt_ping->fetchAll(PDO::FETCH_ASSOC) as $p) $target_urls[] = $p['source_url'];
    
    $stmt_follow = $db->query("SELECT onion_url FROM following");
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
        if (!$feed || !isset($feed['posts']) || !is_array($feed['posts'])) {
            echo "    [!] Invalid outbox schema from: " . $host_domain . "\n";
            continue;
        }

        if (count($feed['posts']) > DEADDROP_WORKER_MAX_POSTS_PER_NODE) {
            echo "    [!] Node capped: " . $host_domain . " advertised " . count($feed['posts']) . " posts; processing latest " . DEADDROP_WORKER_MAX_POSTS_PER_NODE . " only.\n";
            $feed['posts'] = array_slice($feed['posts'], -DEADDROP_WORKER_MAX_POSTS_PER_NODE);
        }

        echo "    [>] Syncing Node: " . $host_domain . " (" . format_bytes(strlen($json_response)) . ")\n";

        $my_clean_url = rtrim($config['node_url'], '/');
        $is_mutual = (strpos($json_response, $my_clean_url) !== false) ? 1 : 0;
        $remote_pub_key = $feed['public_key'] ?? null;
        $remote_pq_pub = $feed['pq_public'] ?? null;

        if ($remote_pub_key) {
            $stmt_key = $db->prepare("UPDATE following SET public_key = :pub, pq_public = :pq, is_mutual = :mut WHERE onion_url = :url");
            $stmt_key->execute([':pub' => $remote_pub_key, ':pq' => $remote_pq_pub, ':mut' => $is_mutual, ':url' => $onion_url]);
        } else {
            $stmt_key = $db->prepare("UPDATE following SET is_mutual = :mut WHERE onion_url = :url");
            $stmt_key->execute([':mut' => $is_mutual, ':url' => $onion_url]);
        }

        $author_name = $feed['author'] ?? 'Unknown Node';
        $author_domain = rtrim($feed['domain'] ?? $onion_url, '/');
        $new_posts_count = 0;

        $check_timeline = $db->prepare("SELECT COUNT(*) FROM timeline WHERE remote_id = :rid");
        $check_inbox = $db->prepare("SELECT COUNT(*) FROM inbox WHERE remote_id = :rid");

        $posts_reversed = array_reverse($feed['posts']);

        foreach ($posts_reversed as $post) {
            $remote_id = $post['id'] ?? null;
            if (!$remote_id) continue;

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
    $db->exec("DELETE FROM ping_queue");
    $db->exec("DELETE FROM timeline WHERE is_local = 0 AND id NOT IN (SELECT id FROM timeline WHERE is_local = 0 ORDER BY created_at DESC LIMIT 2000)");

} catch (Exception $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "   WORKER CYCLE COMPLETE\n";
echo "============================================\n";
?>