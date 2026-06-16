<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE GUARD & COURIER (Worker v1.0)
// ==========================================
require_once 'db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "============================================\n";
echo "   DEADDROP WORKER INITIATED\n";
echo "   TIME: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "============================================\n\n";

try {
    $target_urls = [];

    // 1A. CHECK PING QUEUE (Foreign nodes replying/mentioning us)
    $stmt_ping = $db->query("SELECT DISTINCT source_url FROM ping_queue");
    $pings = $stmt_ping->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pings as $p) {
        $target_urls[] = $p['source_url'];
    }

    // 1B. CHECK RADAR LIST (Peers we follow)
    $stmt_follow = $db->query("SELECT onion_url FROM following");
    $follows = $stmt_follow->fetchAll(PDO::FETCH_ASSOC);
    foreach ($follows as $f) {
        if (!in_array($f['onion_url'], $target_urls)) {
            $target_urls[] = $f['onion_url'];
        }
    }

    if (empty($target_urls)) {
        die("[*] Radar and Queue are empty. Going back to sleep...\n");
    }

    // 2. BEGIN PATROL & DATA EXTRACTION
    foreach ($target_urls as $onion_url) {
        $onion_url = rtrim($onion_url, '/');
        $outbox_url = $onion_url . '/outbox.json';
        $parsed_url = parse_url($onion_url);
        $host_domain = $parsed_url['host'] ?? '';

        echo "[>] Inspecting Node: " . $host_domain . "\n";

        $is_onion = preg_match('/\.onion$/i', $host_domain);
        $is_local = ($host_domain === 'localhost' || $host_domain === '127.0.0.1');

        if (!$is_onion && !$is_local) {
            echo "    [!] ERROR: Protocol rejected. Not a Darknet network.\n\n";
            continue;
        }

        $ch = curl_init($outbox_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 

        // Tor Proxy Enforcement
        if ($is_onion) {
            curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            echo "    [+] Tor Proxy (SOCKS5) enabled.\n";
        } else {
            echo "    [+] Localhost Bypass enabled (Dev Mode).\n";
        }

        $json_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || !$json_response) {
            echo "    [!] Failed to pull data. Node might be offline.\n\n";
            continue;
        }

        $feed = json_decode($json_response, true);
        if (!$feed || !isset($feed['posts']) || !is_array($feed['posts'])) {
            echo "    [!] Invalid outbox.json format.\n\n";
            continue;
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
                // This is where cross-validation belongs. 
                // If the URL comes from ping_queue (not following),
                // the Worker SHOULD ideally only save the post if it replies to our ID.
                // But for v1.0, we extract everything like a P2P radar.
                $stmt_insert->execute([
                    ':rid'     => $remote_id,
                    ':name'    => $author_name,
                    ':host'    => $author_domain,
                    ':content' => $post['content'] ?? '',
                    ':media'   => $post['media_url'] ?? null,
                    ':reply'   => $post['reply_to'] ?? null,
                    ':waktu'   => $post['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z')
                ]);
                $new_posts_count++;
            }
        }

        // If this is a followed node, update the pulled timestamp
        $db->prepare("UPDATE following SET last_pulled = CURRENT_TIMESTAMP WHERE onion_url = :url")
           ->execute([':url' => $onion_url]);

        echo "    [+] Successfully extracted $new_posts_count new signals from @$author_name.\n\n";
    }

    // 3. CLEAR PING QUEUE
    // Since all ping_queue entries were processed above, we clear the table.
    if (count($pings) > 0) {
        $db->exec("DELETE FROM ping_queue");
        echo "[*] Ping_Queue successfully cleared.\n";
    }

    // 4. AUTO-PRUNING (Garbage Collection)
    echo "[*] Executing Garbage Collection (Auto-Pruning)...\n";
    $db->exec("
        DELETE FROM timeline 
        WHERE is_local = 0 AND id NOT IN (
            SELECT id FROM timeline WHERE is_local = 0 ORDER BY created_at DESC LIMIT 2000
        )
    ");
    echo "[+] Garbage Collection completed.\n";

} catch (Exception $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
}

echo "============================================\n";
echo "   WORKER CYCLE COMPLETE\n";
echo "============================================\n";
?>