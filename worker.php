<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE GUARD & COURIER (Worker v5.0 Final - Mutual & Telegram)
// ==========================================
require_once 'db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "============================================\n";
echo "   DEADDROP WORKER INITIATED\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

try {
    $now_utc = gmdate('Y-m-d\TH:i:s\Z');

    echo "[>] Running Ephemeral Sweeper...\n";
    $stmt_exp = $db->query("
        SELECT media_url, remote_id FROM timeline WHERE expires_at IS NOT NULL AND expires_at <= '$now_utc'
        UNION ALL
        SELECT media_url, remote_id FROM inbox WHERE expires_at IS NOT NULL AND expires_at <= '$now_utc'
    ");
    $expired_count = 0;
    foreach ($stmt_exp->fetchAll(PDO::FETCH_ASSOC) as $exp) {
        if (!empty($exp['media_url'])) @unlink(__DIR__ . '/media/' . basename($exp['media_url']));
        $db->exec("DELETE FROM timeline WHERE remote_id = '{$exp['remote_id']}'");
        $db->exec("DELETE FROM inbox WHERE remote_id = '{$exp['remote_id']}'");
        $expired_count++;
    }
    if ($expired_count > 0) echo "    [+] Purged $expired_count expired ephemeral signals.\n\n";

    $target_urls = [];
    $stmt_ping = $db->query("SELECT DISTINCT source_url FROM ping_queue");
    foreach ($stmt_ping->fetchAll(PDO::FETCH_ASSOC) as $p) $target_urls[] = $p['source_url'];
    
    $stmt_follow = $db->query("SELECT onion_url FROM following");
    foreach ($stmt_follow->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (!in_array($f['onion_url'], $target_urls)) $target_urls[] = $f['onion_url'];
    }

    if (empty($target_urls)) die("[*] Radar and Queue are empty. Going back to sleep...\n");

    $my_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        base64_decode($config['private_key']), base64_decode($config['public_key'])
    );

    foreach ($target_urls as $onion_url) {
        $onion_url = rtrim($onion_url, '/');
        $outbox_url = $onion_url . '/outbox.json';
        $host_domain = parse_url($onion_url)['host'] ?? '';

        echo "[>] Inspecting Node: " . $host_domain . "\n";
        $is_onion = preg_match('/\.onion$/i', $host_domain);
        $is_local = ($host_domain === 'localhost' || $host_domain === '127.0.0.1');

        if (!$is_onion && !$is_local) continue;

        $ch = curl_init($outbox_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if ($is_onion) {
            curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
        $json_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || !$json_response) continue;

        $feed = json_decode($json_response, true);
        if (!$feed || !isset($feed['posts'])) continue;

        // 🤝 MUTUAL BADGE TRACKING
        $my_clean_url = rtrim($config['node_url'], '/');
        $is_mutual = (strpos($json_response, $my_clean_url) !== false) ? 1 : 0;
        $remote_pub_key = $feed['public_key'] ?? null;

        if ($remote_pub_key) {
            $stmt_key = $db->prepare("UPDATE following SET public_key = :pub, is_mutual = :mut WHERE onion_url = :url");
            $stmt_key->execute([':pub' => $remote_pub_key, ':mut' => $is_mutual, ':url' => $onion_url]);
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

            if ($remote_status === 'deleted') {
                $stmt_del_media = $db->prepare("SELECT media_url FROM timeline WHERE remote_id = :rid UNION SELECT media_url FROM inbox WHERE remote_id = :rid");
                $stmt_del_media->execute([':rid' => $remote_id]);
                $del_media = $stmt_del_media->fetchColumn();
                
                if ($del_media) @unlink(__DIR__ . '/media/' . basename($del_media));
                
                $db->exec("DELETE FROM timeline WHERE remote_id = '$remote_id'");
                $db->exec("DELETE FROM inbox WHERE remote_id = '$remote_id'");
                continue;
            }

            $check_timeline->execute([':rid' => $remote_id]);
            $check_inbox->execute([':rid' => $remote_id]);

            if ($check_timeline->fetchColumn() == 0 && $check_inbox->fetchColumn() == 0) {
                
                $raw_content = $post['content'] ?? '';
                $is_decrypted_successfully = false;
                $is_burner_received = false;

                if (strpos($raw_content, 'E2EE:') === 0 || strpos($raw_content, 'E2EE-BURNER:') === 0) {
                    $is_burner_received = (strpos($raw_content, 'E2EE-BURNER:') === 0);
                    $offset = $is_burner_received ? 12 : 5;
                    $ciphertext = base64_decode(substr($raw_content, $offset));
                    
                    $decrypted = sodium_crypto_box_seal_open($ciphertext, $my_keypair);
                    if ($decrypted !== false) {
                        $prefix_text = $is_burner_received ? "[🔥 BURNER DROP - DESTROYED UPON READING]\n" : "[🔓 DECRYPTED PRIVATE DROP]\n";
                        $raw_content = $prefix_text . $decrypted;
                        $is_decrypted_successfully = true; 
                        
                        // 📡 TELEGRAM BRIDGE DM TRIGGER
                        if ($config['tg_on'] && !empty($config['tg_token']) && !empty($config['tg_chat'])) {
                            $tipe_pesan = $is_burner_received ? "🔥 BURNER DROP" : "🔓 PRIVATE DROP";
                            $msg_tg = "INBOX NODE: 1 $tipe_pesan berhasil didekripsi dari @" . $author_name;
                            
                            $url_tg = "https://api.telegram.org/bot" . $config['tg_token'] . "/sendMessage";
                            $chTg = curl_init($url_tg);
                            curl_setopt($chTg, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($chTg, CURLOPT_POST, true);
                            curl_setopt($chTg, CURLOPT_POSTFIELDS, ['chat_id' => $config['tg_chat'], 'text' => $msg_tg]);
                            curl_setopt($chTg, CURLOPT_TIMEOUT, 5);
                            curl_exec($chTg);
                            curl_close($chTg);
                        }
                    } else {
                        $raw_content = "[🔒 ENCRYPTED CIPHERTEXT] // This message is securely locked.";
                        $is_burner_received = false;
                    }
                }

                $safe_media = $post['media_url'] ?? null;
                if ($safe_media && !preg_match('/^https?:\/\//i', $safe_media)) $safe_media = null;

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
        echo "    [+] Extracted $new_posts_count new signals from @$author_name.\n\n";
    }

    $db->exec("DELETE FROM ping_queue");
    $db->exec("DELETE FROM timeline WHERE is_local = 0 AND id NOT IN (SELECT id FROM timeline WHERE is_local = 0 ORDER BY created_at DESC LIMIT 2000)");

} catch (Exception $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
}

echo "============================================\n";
echo "   WORKER CYCLE COMPLETE\n";
echo "============================================\n";
?>