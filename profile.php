<?php
// ==========================================
// 🏴‍☠️ DEADDROP: NODE PROFILE INSPECTOR (v9.0 - Asymmetric Obfuscation)
// ==========================================
require_once 'db.php';

$target_host = $_GET['host'] ?? $config['node_url'];
$status_msg = '';
$status_class = '';

// Universal Path Healer
$clean_target = $target_host;
if (!preg_match('#^https?://#i', $clean_target)) $clean_target = 'http://' . $clean_target;
if (!preg_match('#/deaddrop$#i', $clean_target)) $clean_target .= '/deaddrop';

$my_url = rtrim($config['node_url'], '/');
$is_local_profile = ($target_host === $my_url || $clean_target === $my_url || $target_host === 'localhost' || $target_host === '127.0.0.1');

// 🔮 SYMMETRIC ALIAS CRYPTOGRAPHY
function encrypt_alias($plaintext, $key_string) {
    $key = hash('sha256', $key_string, true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return 'ENC:' . base64_encode($nonce . $ciphertext);
}

function decrypt_alias($payload, $key_string) {
    if (strpos($payload, 'ENC:') !== 0) return $payload; 
    $data = base64_decode(substr($payload, 4));
    if (strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return "[ENCRYPTED_BLOB]";
    $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = hash('sha256', $key_string, true);
    $dec = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    return $dec !== false ? $dec : "[DECRYPTION_FAILED]";
}

// 🔐 BLACK SITE MASTER AUTHENTICATION
$unlocked = false;
$unlock_error = '';
$master_key = '';

if (isset($_POST['unlock_pass'])) {
    if (password_verify($_POST['unlock_pass'], $config['admin_hash'])) {
        $unlocked = true;
        $master_key = $_POST['unlock_pass'];
    } else {
        $unlock_error = "[!] AUTHENTICATION FAILED: INVALID MASTER KEY.";
    }
}
if (isset($_POST['admin_pass']) && password_verify($_POST['admin_pass'], $config['admin_hash'])) {
    $unlocked = true; // Valid action execution naturally unlocks the current view
    $master_key = $_POST['admin_pass'];
}

// ==========================================
// ⚙️ HANDLERS: FOLLOW, UNFOLLOW, PING (Strictly Protected)
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
        
        $clean_target_url = $target_url;
        if (!preg_match('#^https?://#i', $clean_target_url)) $clean_target_url = 'http://' . $clean_target_url;
        if (!preg_match('#/deaddrop$#i', $clean_target_url)) $clean_target_url .= '/deaddrop';
        
        $parsed_url = parse_url($clean_target_url);
        $host_domain = $parsed_url['host'] ?? '';
        
        if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
            $status_msg = "[!] ERROR: Strict Protocol! System only accepts Darknet (.onion) domains.";
            $status_class = "danger";
        } else {
            if ($action === 'follow') {
                $enc_alias = encrypt_alias($host_domain, $master_key); // Auto-encrypt default alias
                $stmt = $db->prepare("INSERT OR IGNORE INTO following (onion_url, alias) VALUES (:url, :alias)");
                $stmt->execute([':url' => $clean_target_url, ':alias' => $enc_alias]);
                $status_msg = "[+] SYNCHRONIZATION ACTIVE: Node appended to your radar.";
                $status_class = "success";
            } elseif ($action === 'unfollow') {
                $stmt = $db->prepare("DELETE FROM following WHERE onion_url = :url OR onion_url = :url_clean");
                $stmt->execute([':url' => $target_url, ':url_clean' => $clean_target_url]);
                $status_msg = "[-] SYNCHRONIZATION DISCONNECTED: Node purged from radar.";
                $status_class = "warning";
            } elseif ($action === 'ping_node') {
                // PoW Hashcash Cannon
                $my_pow_url = rtrim($config['node_url'], '/');
                if (!preg_match('#^https?://#i', $my_pow_url)) $my_pow_url = 'http://' . $my_pow_url;
                if (!preg_match('#/deaddrop$#i', $my_pow_url)) $my_pow_url .= '/deaddrop';
                
                $timestamp = time();
                $nonce = 0;
                $difficulty = '0000';
                
                while (true) {
                    $hash = hash('sha256', $my_pow_url . $timestamp . $nonce);
                    if (substr($hash, 0, strlen($difficulty)) === $difficulty) break;
                    $nonce++;
                }

                $target_ping_url = $clean_target_url . '/ping.php';
                $ch = curl_init($target_ping_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'source_url' => $my_pow_url,
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
                
                $clean_resp = strip_tags($response ? $response : 'No response.');
                if ($http_code == 202) {
                    $status_msg = "[+] SIGNAL TRANSMITTED (Nonce: $nonce): " . htmlspecialchars($clean_resp);
                    $status_class = "success";
                } else {
                    $status_msg = "[!] PING FAILED (HTTP $http_code): " . htmlspecialchars($clean_resp);
                    $status_class = "danger";
                }
            }
        }
    }
}

// ==========================================
// 📡 ASYMMETRIC DATA EXTRACTION
// ==========================================
$feeds = [];
$profile_name = "Classified Entity";
$is_following = false;
$allow_view = false;

if ($is_local_profile) {
    // 🟢 WAJAH PUBLIK: Selalu terbuka! Hanya pampang post buatan lokal.
    $allow_view = true;
    $profile_name = $config['node_name'];
    try {
        $stmt = $db->query("SELECT * FROM timeline WHERE is_local = 1 ORDER BY created_at DESC LIMIT 100");
        $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $feeds = [];
    }
} else {
    // 🔴 WAJAH ASING: Terkunci rapat kecuali Master Key disuntikkan!
    if ($unlocked) {
        $allow_view = true;
        try {
            $stmt = $db->prepare("SELECT * FROM timeline WHERE author_host = :host OR author_host = :clean_host ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([':host' => $target_host, ':clean_host' => $clean_target]);
            $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($feeds)) $profile_name = $feeds[0]['author_name'];
            
            $stmt_cek = $db->prepare("SELECT alias FROM following WHERE onion_url = :host OR onion_url = :clean_host LIMIT 1");
            $stmt_cek->execute([':host' => $target_host, ':clean_host' => $clean_target]);
            $saved_alias = $stmt_cek->fetchColumn();
            
            if ($saved_alias) {
                $is_following = true;
                $decrypted_name = decrypt_alias($saved_alias, $master_key);
                $profile_name = '@' . $decrypted_name;
                
                // Override foreign author names with your local decrypted petname
                foreach ($feeds as &$post) {
                    $post['author_name'] = $profile_name;
                }
            }
        } catch (PDOException $e) {
            $feeds = [];
        }
    }
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
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png" />
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
                NODE: <?= htmlspecialchars($clean_target) ?>
                <?= $is_local_profile ? '<span class="t-badge success ml-2" style="border:none; padding:1px 6px;">[ Sovereign Host ]</span>' : '' ?>
            </div>
        </div>
        <a href="index.php" class="t-btn">⇐ <?= $unlocked ? 'Command Center' : 'Home' ?></a>
    </div>

    <?php if (!empty($status_msg)): ?>
        <div class="t-alert <?= $status_class ?> mb-4"><?= $status_msg ?></div>
    <?php endif; ?>

    <?php if (!$allow_view): ?>
        <div style="margin-top: 12vh; text-align: center; border: 1px dashed #ff0055; padding: 40px 20px; background: rgba(255,0,85,0.02);">
            <h1 class="m-0 font-bold t-glow mb-2" style="font-size: 2rem; color: #ff0055;">[ 🔒 CLASSIFIED TARGET ]</h1>
            <div class="fs-small text-muted mb-4" style="text-transform: uppercase;">Inspection of foreign entities requires Master Key uplink.</div>
            
            <?php if (!empty($unlock_error)): ?>
                <div class="t-alert danger mb-4" style="display: inline-block; text-align: left; border-color: #ff0055; color: #ff0055;"><?= htmlspecialchars($unlock_error) ?></div><br>
            <?php endif; ?>

            <form action="profile.php?host=<?= urlencode($target_host) ?>" method="POST" style="display: inline-block; text-align: left; width: 100%; max-width: 350px;">
                <input type="password" name="unlock_pass" class="t-input mb-3 w-100" placeholder="Insert Master Key..." required autofocus style="text-align: center; letter-spacing: 2px; border-color: #ff0055;">
                <button type="submit" class="t-btn w-100 m-0 outline danger" style="color: #ff0055; border-color: #ff0055; font-weight: bold;">[ DECRYPT ENTITY CACHE ]</button>
            </form>
        </div>
    <?php else: ?>

        <?php if (!$is_local_profile && $unlocked): ?>
            <div class="t-card mb-4 p-3" style="border-style: dashed; border-color: var(--t-green-dim);">
                <form action="profile.php?host=<?= urlencode($target_host) ?>" method="POST" class="d-flex align-items-center gap-2 m-0 flex-wrap">
                    <input type="hidden" name="target_url" value="<?= htmlspecialchars($clean_target) ?>">
                    <input type="hidden" name="unlock_pass" value="<?= htmlspecialchars($_POST['unlock_pass'] ?? $_POST['admin_pass'] ?? '') ?>">
                    
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
                
                <form action="profile.php?host=<?= urlencode($target_host) ?>" method="POST" class="d-flex align-items-center gap-2 mt-3 pt-3 flex-wrap" style="border-top: 1px dashed rgba(0,255,65,0.2);">
                    <input type="hidden" name="target_url" value="<?= htmlspecialchars($clean_target) ?>">
                    <input type="hidden" name="action" value="ping_node">
                    <input type="hidden" name="unlock_pass" value="<?= htmlspecialchars($_POST['unlock_pass'] ?? $_POST['admin_pass'] ?? '') ?>">
                    <span class="text-muted fs-small flex-fill">Manually knock on their door (Solves PoW puzzle before sending).</span>
                    <input type="password" name="admin_pass" class="t-input w-auto m-0" placeholder="Secure Key" required>
                    <button type="submit" class="t-btn outline m-0" onclick="this.innerHTML='MINING...';">[ KNOCK / PING ]</button>
                </form>
            </div>
        <?php endif; ?>

        <main>
            <div class="font-bold mb-3" style="text-transform: uppercase; color: var(--t-green);">
                <?= $is_local_profile ? '[ Public Broadcast Manifesto ]' : '[ Cached Foreign Intelligence ]' ?>
            </div>
            
            <?php if (empty($feeds)): ?>
                <div class="t-empty-state">
                    <span class="t-empty-state-icon">Ø</span>
                    [!] No public dispatches found for this entity.
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

                        <div class="post-content mt-1" style="white-space: pre-wrap; font-size: 14px; color: var(--t-green); word-break: break-all; overflow-wrap: break-word;"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                        
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
    <?php endif; ?>
</div>

</body>
</html>