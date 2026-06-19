<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NODE PROFILE INSPECTOR (v3.0 - PoW Ready)
// ==========================================
require_once 'db.php';

$target_host = $_GET['host'] ?? $config['node_url'];
$status_msg = '';
$status_class = '';

// ==========================================
// ⚙️ HANDLER: FOLLOW, UNFOLLOW, OR PING NODE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $input_pass = $_POST['admin_pass'] ?? '';
    
    if (!password_verify($input_pass, $config['admin_hash'])) {
        sleep(2);
        $status_msg = "[!] ACCESS DENIED: Invalid Secure Key.";
        $status_class = "danger";
    } else {
        $action = $_POST['action'];
        $target_url = rtrim($_POST['target_url'], '/'); 
        
        $parsed_url = parse_url($target_url);
        $host_domain = $parsed_url['host'] ?? '';
        
        if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
            $status_msg = "[!] ERROR: Strict Protocol! System only accepts Darknet (.onion) domains.";
            $status_class = "danger";
        } else {
            if ($action === 'follow') {
                $stmt = $db->prepare("INSERT OR IGNORE INTO following (onion_url, alias) VALUES (:url, :alias)");
                $stmt->execute([':url' => $target_url, ':alias' => $target_url]);
                $status_msg = "[+] SYNCHRONIZATION ACTIVE: Node added to the Cron Job radar.";
                $status_class = "success";
            } elseif ($action === 'unfollow') {
                $stmt = $db->prepare("DELETE FROM following WHERE onion_url = :url");
                $stmt->execute([':url' => $target_url]);
                $status_msg = "[-] SYNCHRONIZATION DISCONNECTED: Node removed from radar.";
                $status_class = "warning";
            } elseif ($action === 'ping_node') {
                // 🛡️ PHASE 3: Hashcash Mining Engine
                $my_url = rtrim($config['node_url'], '/');
                $timestamp = time();
                $nonce = 0;
                $difficulty = '0000';
                
                // Start mining process...
                while (true) {
                    $hash = hash('sha256', $my_url . $timestamp . $nonce);
                    if (substr($hash, 0, strlen($difficulty)) === $difficulty) {
                        break;
                    }
                    $nonce++;
                }

                // Fire the validated PoW via cURL
                $target_ping_url = $target_url . '/ping.php';
                $ch = curl_init($target_ping_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'source_url' => $my_url,
                    'timestamp'  => $timestamp,
                    'nonce'      => $nonce
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                if (preg_match('/\.onion$/i', $host_domain)) {
                    curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                }
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code == 202) {
                    $status_msg = "[+] SIGNAL TRANSMITTED (Nonce: $nonce): " . htmlspecialchars($response);
                    $status_class = "success";
                } else {
                    $status_msg = "[!] PING FAILED (HTTP $http_code): " . htmlspecialchars($response);
                    $status_class = "danger";
                }
            }
        }
    }
}

// ==========================================
// 📡 FETCH LOCAL CACHED PROFILE DATA
// ==========================================
try {
    $stmt = $db->prepare("SELECT * FROM timeline WHERE author_host = :host ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([':host' => $target_host]);
    $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $profile_name = (!empty($feeds)) ? $feeds[0]['author_name'] : "Unknown Entity";
    $is_local_profile = ($target_host === rtrim($config['node_url'], '/'));
    
    $is_following = false;
    if (!$is_local_profile) {
        $stmt_cek = $db->prepare("SELECT COUNT(*) FROM following WHERE onion_url = :host");
        $stmt_cek->execute([':host' => $target_host]);
        $is_following = (bool) $stmt_cek->fetchColumn();
    }
    
} catch (PDOException $e) {
    $feeds = [];
    $profile_name = "Error";
    $is_following = false;
    $is_local_profile = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#110818">
    <title>DeadDrop // Inspect Node</title>
    <link href="assets/torminal.css" rel="stylesheet" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png" />
    <style>
        .post-card { border-left: 3px solid var(--t-green-dim); transition: 0.2s; margin-bottom: 15px; }
        .post-card:hover { border-left-color: var(--t-green); background: rgba(0,255,65,0.03); }
        .post-card.local-node { border-left-width: 4px; border-left-color: var(--t-green); }
        .media-attachment { display: block; max-width: 100%; max-height: 400px; border: 1px dashed var(--t-green-dim); margin-top: 10px; filter: grayscale(80%) sepia(100%) hue-rotate(80deg) brightness(0.8) contrast(1.2); transition: 0.3s; }
        .media-attachment:hover { filter: none; border-color: var(--t-green); }
    </style>
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom" style="padding-bottom: 15px;">
        <div>
            <h1 class="m-0 font-bold t-glow" style="font-size: 1.8rem; color: var(--t-green);">&gt; <?= htmlspecialchars($profile_name) ?>_</h1>
            <div class="mt-1 fs-small font-bold text-muted">
                NODE: <?= htmlspecialchars($target_host) ?>
            </div>
        </div>
        <a href="index.php" class="t-btn">⇐ Back</a>
    </div>

    <?php if (!empty($status_msg)): ?>
        <div class="t-alert <?= $status_class ?> mb-4" style="border-color: var(--t-<?= $status_class ?>); color: var(--t-<?= $status_class ?>);">
            <?= $status_msg ?>
        </div>
    <?php endif; ?>

    <?php if (!$is_local_profile): ?>
        <div class="t-card mb-4 p-3" style="border-style: dashed; border-color: var(--t-green-dim);">
            <form action="" method="POST" class="d-flex align-items-center gap-2 m-0 flex-wrap">
                <input type="hidden" name="target_url" value="<?= htmlspecialchars($target_host) ?>">
                
                <?php if ($is_following): ?>
                    <input type="hidden" name="action" value="unfollow">
                    <span class="text-success fs-small flex-fill font-bold t-glow">[✓] This node is synchronized to your radar.</span>
                    <input type="password" name="admin_pass" class="t-input w-auto m-0" placeholder="Secure Key" required>
                    <button type="submit" class="t-btn danger m-0">[ DISCONNECT ]</button>
                <?php else: ?>
                    <input type="hidden" name="action" value="follow">
                    <span class="text-muted fs-small flex-fill">Add this node to your radar to periodically pull its data.</span>
                    <input type="password" name="admin_pass" class="t-input w-auto m-0" placeholder="Secure Key" required>
                    <button type="submit" class="t-btn warning m-0">[ SYNC NODE ]</button>
                <?php endif; ?>
            </form>
            
            <form action="" method="POST" class="d-flex align-items-center gap-2 mt-3 pt-3 flex-wrap" style="border-top: 1px dashed rgba(0,255,65,0.2);">
                <input type="hidden" name="target_url" value="<?= htmlspecialchars($target_host) ?>">
                <input type="hidden" name="action" value="ping_node">
                <span class="text-muted fs-small flex-fill">Manually knock on their door (Solves PoW puzzle before sending).</span>
                <input type="password" name="admin_pass" class="t-input w-auto m-0" placeholder="Secure Key" required>
                <button type="submit" class="t-btn outline m-0" onclick="this.innerHTML='MINING...';">[ KNOCK / PING ]</button>
            </form>
        </div>
    <?php endif; ?>

    <main>
        <div class="font-bold mb-3" style="text-transform: uppercase; color: var(--t-green);">[ Local Cached Data ]</div>
        
        <?php if (empty($feeds)): ?>
            <div class="t-empty-state">
                <span class="t-empty-state-icon">Ø</span>
                [!] Signals not fully pulled yet. Local data is empty.
            </div>
        <?php else: ?>
            <?php foreach ($feeds as $post): ?>
                <div class="t-card p-3 post-card <?= $post['is_local'] ? 'local-node' : '' ?>" style="border-top: none;">
                    <div class="d-flex justify-content-between align-items-center t-border-bottom pb-2 mb-2 fs-small">
                        <div>
                            <span class="t-badge outline font-bold"><?= htmlspecialchars($post['author_name']) ?></span>
                        </div>
                        <div class="text-muted">ID: <?= htmlspecialchars($post['remote_id']) ?></div>
                    </div>
                    
                    <?php if (!empty($post['reply_to'])): ?>
                        <div class="t-badge mb-2" style="background: transparent; border-style: dotted;">Replying to: <?= htmlspecialchars($post['reply_to']) ?></div>
                    <?php endif; ?>

                    <div class="post-content mt-1" style="white-space: pre-wrap; font-size: 14px; color: var(--t-green);"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                    
                    <?php if (!empty($post['media_url'])): ?>
                        <img src="<?= htmlspecialchars($post['media_url']) ?>" alt="Attached Media" class="media-attachment">
                    <?php endif; ?>
                    
                    <div class="text-right mt-3 pt-2" style="border-top: 1px dashed rgba(0,255,65,0.2);">
                        <span class="fs-small text-muted"><?= htmlspecialchars($post['created_at']) ?> UTC</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>

</body>
</html>