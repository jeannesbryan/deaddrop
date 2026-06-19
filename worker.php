<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE GUARD & COURIER (Worker v1.0 - E2EE Ready)
// ==========================================
require_once 'db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "============================================\n";
echo "   DEADDROP WORKER INITIATED\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

try {
    $target_urls = [];

    $stmt_ping = $db->query("SELECT DISTINCT source_url FROM ping_queue");
    foreach ($stmt_ping->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $target_urls[] = $p['source_url'];
    }

    $stmt_follow = $db->query("SELECT onion_url FROM following");
    foreach ($stmt_follow->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (!in_array($f['onion_url'], $target_urls)) $target_urls[] = $f['onion_url'];
    }

    if (empty($target_urls)) die("[*] Radar and Queue are empty. Going back to sleep...\n");

    // Reconstruct Libsodium Keypair for Decryption
    $my_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        base64_decode($config['private_key']), 
        base64_decode($config['public_key'])
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
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($clientp, $dltotal, $dlnow, $ultotal, $ulnow) {
            if ($dltotal > 2097152 || $dlnow > 2097152) return 1; 
            return 0;
        });

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

        // 🔐 PHASE 1 E2EE: Capture and Update Target's Public Key
        $remote_pub_key = $feed['public_key'] ?? null;
        if ($remote_pub_key) {
            $stmt_key = $db->prepare("UPDATE following SET public_key = :pub WHERE onion_url = :url");
            $stmt_key->execute([':pub' => $remote_pub_key, ':url' => $onion_url]);
        }

        $author_name = $feed['author'] ?? 'Unknown Node';
        $author_domain = rtrim($feed['domain'] ?? $onion_url, '/');
        $new_posts_count = 0;

        $stmt_check = $db->prepare("SELECT COUNT(*) FROM timeline WHERE remote_id = :rid");
        $stmt_insert = $db->prepare("INSERT INTO timeline (remote_id, author_name, author_host, content, media_url, is_local, reply_to, created_at) 
                                     VALUES (:rid, :name, :host, :content, :media, 0, :reply, :waktu)");

        $posts_reversed = array_reverse($feed['posts']);

        foreach ($posts_reversed as $post) {
            $remote_id = $post['id'] ?? null;
            if (!$remote_id) continue;

            $stmt_check->execute([':rid' => $remote_id]);
            if ($stmt_check->fetchColumn() == 0) {
                
                // 🔐 PHASE 1 E2EE: Decryption Protocol
                $raw_content = $post['content'] ?? '';
                if (strpos($raw_content, 'E2EE:') === 0) {
                    $ciphertext = base64_decode(substr($raw_content, 5));
                    $decrypted = sodium_crypto_box_seal_open($ciphertext, $my_keypair);
                    
                    if ($decrypted !== false) {
                        $raw_content = "[🔓 DECRYPTED PRIVATE DROP]\n" . $decrypted;
                    } else {
                        $raw_content = "[🔒 ENCRYPTED CIPHERTEXT] // This message is securely locked for another node.";
                    }
                }

                $safe_media = $post['media_url'] ?? null;
                if ($safe_media && !preg_match('/^https?:\/\//i', $safe_media)) $safe_media = null;

                $stmt_insert->execute([
                    ':rid'     => $remote_id,
                    ':name'    => $author_name,
                    ':host'    => $author_domain,
                    ':content' => $raw_content,
                    ':media'   => $safe_media,
                    ':reply'   => $post['reply_to'] ?? null,
                    ':waktu'   => $post['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z')
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