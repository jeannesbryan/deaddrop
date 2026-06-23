<?php
// ==========================================
// 🏴‍☠️ DEADDROP: RADAR COMMAND CENTER (v9.0 - Social Graph Obfuscation)
// ==========================================
require_once 'db.php';

$status_msg = '';
$alert_type = 'success';

// 🔐 BLACK SITE AUTHENTICATION & ACTION HANDLERS
$unlocked = false;
$unlock_error = '';
$master_key = '';

// 🔮 SYMMETRIC ALIAS CRYPTOGRAPHY (LIBSODIUM)
function encrypt_alias($plaintext, $key_string) {
    $key = hash('sha256', $key_string, true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return 'ENC:' . base64_encode($nonce . $ciphertext);
}

function decrypt_alias($payload, $key_string) {
    if (strpos($payload, 'ENC:') !== 0) return $payload; // Backward compatibility for unencrypted legacy aliases
    $data = base64_decode(substr($payload, 4));
    if (strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return "[ENCRYPTED_BLOB]";
    $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = hash('sha256', $key_string, true);
    $dec = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    return $dec !== false ? $dec : "[DECRYPTION_FAILED]";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. VOID UNLOCK ATTEMPT
    if (isset($_POST['unlock_pass'])) {
        if (password_verify($_POST['unlock_pass'], $config['admin_hash'])) {
            $unlocked = true;
            $master_key = $_POST['unlock_pass']; // Capture raw password for volatile symmetric derivation
            $status_msg = "[+] BLACK SITE UNLOCKED: COMMAND CENTER ONLINE";
            $alert_type = 'success';
        } else {
            $unlock_error = "[!] AUTHENTICATION FAILED: INVALID MASTER KEY.";
        }
    }
    
    // 2. RADAR ACTIONS (Inherently unlocks if pass is correct)
    if (isset($_POST['action'])) {
        $input_pass = $_POST['admin_pass'] ?? '';
        if (!password_verify($input_pass, $config['admin_hash'])) {
            sleep(1);
            $unlock_error = "[!] ACCESS DENIED: Invalid Secure Key.";
        } else {
            $unlocked = true; 
            $master_key = $input_pass;

            // INTERCEPT PEER LOCK (ADD PEER)
            if ($_POST['action'] === 'add_peer') {
                $peer_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
                $raw_alias = trim($_POST['peer_alias'] ?? '');
                
                $alias = ltrim($raw_alias, '@');
                $alias = preg_replace('/[^a-zA-Z0-9_-]/', '', $alias);
                if (empty($alias)) $alias = 'node_' . substr(md5($peer_url), 0, 6);

                $parsed_url = parse_url($peer_url);
                $host_domain = $parsed_url['host'] ?? '';

                if (!preg_match('/\.onion$/i', $host_domain) && $host_domain !== 'localhost' && $host_domain !== '127.0.0.1') {
                    $status_msg = "[!] PROTOCOL REJECTED: Only external Darknet (.onion) endpoints are allowed.";
                    $alert_type = 'danger';
                } else {
                    $stmt_check = $db->prepare("SELECT id FROM following WHERE onion_url = :url LIMIT 1");
                    $stmt_check->execute([':url' => $peer_url]);
                    
                    if ($stmt_check->fetch()) {
                        $status_msg = "[!] URL LOCKED: URL is already strictly assigned in your radar.";
                        $alert_type = 'danger';
                    } else {
                        $enc_alias = encrypt_alias($alias, $master_key);
                        $stmt = $db->prepare("INSERT INTO following (onion_url, alias) VALUES (:url, :alias)");
                        $stmt->execute([':url' => $peer_url, ':alias' => $enc_alias]);
                        $db->prepare("DELETE FROM ping_queue WHERE source_url = :url")->execute([':url' => $peer_url]);
                        
                        $status_msg = "[+] RADAR LOCKED: Successfully tracking petname @" . htmlspecialchars($alias);
                        $alert_type = 'success';
                    }
                }
            }
            
            // INTERCEPT UNFOLLOW ACTION
            elseif ($_POST['action'] === 'unfollow_peer') {
                $peer_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
                $stmt = $db->prepare("DELETE FROM following WHERE onion_url = :url");
                $stmt->execute([':url' => $peer_url]);
                $status_msg = "[-] SYNCHRONIZATION DISCONNECTED: Node removed from radar.";
                $alert_type = 'warning';
            }

            // INTERCEPT ALIAS MUTATION
            elseif ($_POST['action'] === 'edit_alias') {
                $peer_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
                $raw_alias = trim($_POST['new_alias'] ?? '');
                
                $alias = ltrim($raw_alias, '@');
                $alias = preg_replace('/[^a-zA-Z0-9_-]/', '', $alias);

                if (!empty($alias)) {
                    // DOCTRINE ENFORCEMENT: We strictly encrypt the alias inside the following table.
                    // We DO NOT cascade this update to timeline/inbox to prevent plaintext leakage.
                    $enc_alias = encrypt_alias($alias, $master_key);
                    $stmt = $db->prepare("UPDATE following SET alias = :alias WHERE onion_url = :url");
                    $stmt->execute([':alias' => $enc_alias, ':url' => $peer_url]);
                    
                    $status_msg = "[+] ALIAS UPDATED: Node successfully renamed to @" . htmlspecialchars($alias);
                    $alert_type = 'success';
                }
            }

            // INTERCEPT DIRECT KNOCK / PING CANNON
            elseif ($_POST['action'] === 'ping_peer') {
                $target_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
                
                // Universal Path Healer
                $clean_target_url = $target_url;
                if (!preg_match('#^https?://#i', $clean_target_url)) $clean_target_url = 'http://' . $clean_target_url;
                if (!preg_match('#/deaddrop$#i', $clean_target_url)) $clean_target_url .= '/deaddrop';

                $parsed_url = parse_url($clean_target_url);
                $host_domain = $parsed_url['host'] ?? '';

                // PoW Hashcash Mining Engine
                $my_url = rtrim($config['node_url'], '/');
                if (!preg_match('#^https?://#i', $my_url)) $my_url = 'http://' . $my_url;
                if (!preg_match('#/deaddrop$#i', $my_url)) $my_url .= '/deaddrop';
                
                $timestamp = time();
                $nonce = 0;
                $difficulty = '0000'; // Default normal shield
                
                while (true) {
                    $hash = hash('sha256', $my_url . $timestamp . $nonce);
                    if (substr($hash, 0, strlen($difficulty)) === $difficulty) break;
                    $nonce++;
                }

                // Fire PoW Payload via cURL
                $target_ping_url = $clean_target_url . '/ping.php';
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
                
                $clean_response = strip_tags($response ? $response : 'No response from host.');
                if ($http_code == 202) {
                    $status_msg = "[+] SIGNAL TRANSMITTED: " . htmlspecialchars($clean_response);
                    $alert_type = 'success';
                } else {
                    $status_msg = "[!] PING REJECTED (HTTP " . $http_code . "): " . htmlspecialchars($clean_response);
                    $alert_type = 'danger';
                }
            }
        }
    }
}

// 🛡️ ZERO-LEAK QUERY: Data is only fetched if vault is unlocked!
$following_list = [];
$ping_list = [];

if ($unlocked) {
    try {
        $query_following = $db->query("SELECT * FROM following ORDER BY id DESC");
        $following_list = $query_following->fetchAll(PDO::FETCH_ASSOC);
        
        // DECRYPT ALIASES ON THE FLY FOR UI PRESENTATION
        foreach ($following_list as &$peer) {
            $peer['alias'] = decrypt_alias($peer['alias'], $master_key);
        }
        
        $query_pings = $db->query("SELECT * FROM ping_queue ORDER BY received_at DESC");
        $ping_list = $query_pings->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $following_list = [];
        $ping_list = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#110818">
    <title>DeadDrop // Radar Command Center</title>
    <link href="assets/torminal.css" rel="stylesheet" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png" />
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">

    <?php if (!$unlocked): ?>
        <div style="margin-top: 15vh; text-align: center; border: 1px dashed var(--t-cyan, #00ffff); padding: 40px 20px; background: rgba(0,255,255,0.02);">
            <h1 class="m-0 font-bold t-glow mb-2" style="font-size: 2rem; color: var(--t-cyan, #00ffff);">[ 🔒 RESTRICTED ZONE ]</h1>
            <div class="fs-small text-muted mb-4" style="text-transform: uppercase;">Command Center is Classified. Authentication Required.</div>
            
            <?php if (!empty($unlock_error)): ?>
                <div class="t-alert danger mb-4" style="display: inline-block; text-align: left; border-color: #ff0055; color: #ff0055;"><?= htmlspecialchars($unlock_error) ?></div><br>
            <?php endif; ?>

            <form action="radar.php" method="POST" style="display: inline-block; text-align: left; width: 100%; max-width: 350px;">
                <input type="password" name="unlock_pass" class="t-input mb-3 w-100" placeholder="Insert Master Key..." required autofocus style="text-align: center; letter-spacing: 2px; border-color: var(--t-cyan, #00ffff);">
                <button type="submit" class="t-btn w-100 m-0 outline" style="color: var(--t-cyan, #00ffff); border-color: var(--t-cyan, #00ffff); font-weight: bold;">[ INITIALIZE UPLINK ]</button>
            </form>
        </div>
    <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom" style="padding-bottom: 15px;">
            <div>
                <h1 class="m-0 font-bold t-glow" style="font-size: 1.8rem; color: var(--t-cyan, #00ffff);">&gt; RADAR_COMMAND_</h1>
                <div class="mt-1 fs-small font-bold text-muted">
                    NODE: <?= htmlspecialchars($config['node_url']) ?>
                </div>
            </div>
            <a href="radar.php" class="t-btn danger outline" style="color: #ff0055; border-color: #ff0055;">[ LOCK ]</a>
        </div>

        <div class="d-flex gap-2 mb-4">
            <a href="index.php" class="t-btn outline" style="color: var(--t-green); border-color: var(--t-green);">[ TIMELINE ]</a>
            <a href="dm.php" class="t-btn outline" style="color: #ff0055; border-color: #ff0055;">[ INBOX ]</a>
            <a href="radar.php" class="t-btn" style="background: var(--t-cyan, #00ffff); color: black; border-color: var(--t-cyan, #00ffff);">[ RADAR ]</a>
        </div>

        <?php if (!empty($status_msg)): ?>
            <div class="t-alert <?= $alert_type ?> mb-4"><?= $status_msg ?></div>
        <?php endif; ?>

        <div class="t-card mb-4" style="border-style: dashed; border-color: var(--t-cyan, #00ffff);">
            <div class="font-bold mb-3 d-flex justify-content-between align-items-center" style="color: var(--t-cyan, #00ffff);">
                <span>[ Target Lock // Manual Entry ]</span>
            </div>
            <form action="radar.php" method="POST">
                <input type="hidden" name="action" value="add_peer">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input type="text" name="peer_url" class="t-input flex-fill m-0" placeholder="Endpoint URL (http://peer.onion/deaddrop)" required style="min-width: 250px; border-color: var(--t-cyan, #00ffff);">
                    <input type="text" name="peer_alias" class="t-input w-auto m-0" placeholder="Petname (@target)" required style="max-width: 150px; border-color: var(--t-cyan, #00ffff);">
                </div>
                <div class="d-flex gap-2">
                    <input type="password" name="admin_pass" class="t-input flex-fill m-0" placeholder="Secure Key" required style="border-color: var(--t-cyan, #00ffff);">
                    <button type="submit" class="t-btn m-0 outline" style="color: var(--t-cyan, #00ffff); border-color: var(--t-cyan, #00ffff);">[ LOCK RADAR ]</button>
                </div>
            </form>
        </div>

        <div class="t-card mb-4">
            <div class="font-bold mb-3 t-glow" style="color: var(--t-green); text-transform: uppercase;">[ Active Radar Targets ]</div>
            <?php if (empty($following_list)): ?>
                <div class="t-empty-state fs-small text-muted">[!] Radar is offline. You are not following any nodes.</div>
            <?php else: ?>
                <?php foreach ($following_list as $peer): ?>
                    <div class="mb-3 pb-3" style="border-bottom: 1px dotted rgba(0,255,65,0.2);">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 200px; flex-fill;">
                                <a href="profile.php?host=<?= urlencode($peer['onion_url']) ?>" class="t-glow font-bold text-success" style="font-size: 1.1rem; text-decoration: none; border-bottom: 1px dashed var(--t-green);">@<?= htmlspecialchars($peer['alias']) ?></a>
                                
                                <?php if (isset($peer['is_mutual']) && $peer['is_mutual'] == 1): ?>
                                    <span class="t-badge success" style="border:none; background: transparent; font-size: 0.75rem; vertical-align: middle; padding: 0;">[🤝 Mutual]</span>
                                <?php endif; ?>
                                <br><span class="fs-small text-muted" style="font-size: 11px;"><?= htmlspecialchars($peer['onion_url']) ?></span>
                            </div>
                            
                            <form action="radar.php" method="POST" class="m-0 d-flex gap-1 align-items-center flex-wrap">
                                <input type="hidden" name="peer_url" value="<?= htmlspecialchars($peer['onion_url']) ?>">
                                <input type="password" name="admin_pass" class="t-input m-0" placeholder="Key" style="width: 55px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                                
                                <button type="submit" name="action" value="ping_peer" class="t-badge outline info m-0 font-bold" style="height: 26px; padding: 0 6px; border-color: var(--t-cyan, #00ffff); color: var(--t-cyan, #00ffff); cursor: pointer;" onclick="this.innerHTML='MINING...';">[ KNOCK ]</button>
                                
                                <button type="submit" name="action" value="unfollow_peer" class="t-badge outline danger m-0" style="height: 26px; padding: 0 6px; border: none; cursor: pointer;" onclick="return confirm('Disconnect from @<?= htmlspecialchars($peer['alias']) ?>?');">[ DEL ]</button>
                            </form>
                        </div>
                        
                        <form action="radar.php" method="POST" class="m-0 d-flex gap-2 align-items-center mt-2">
                            <input type="hidden" name="action" value="edit_alias">
                            <input type="hidden" name="peer_url" value="<?= htmlspecialchars($peer['onion_url']) ?>">
                            <span class="text-muted fs-small">↳ Rename:</span>
                            <input type="text" name="new_alias" class="t-input m-0" placeholder="New @alias" style="max-width: 120px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                            <input type="password" name="admin_pass" class="t-input m-0" placeholder="Key" style="width: 55px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                            <button type="submit" class="t-badge outline warning m-0" style="height: 26px; padding: 0 6px; border: none; cursor: pointer;">[ EDIT ]</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="t-card mb-4" style="border-style: dotted; border-color: var(--t-green-dim);">
            <div class="font-bold mb-3 fs-small text-muted" style="text-transform: uppercase;">[ Gateway Knockers Log ]</div>
            <div class="fs-small text-muted mb-3">Nodes that successfully bypassed your PoW Hashcash defense.</div>
            
            <?php if (empty($ping_list)): ?>
                <div class="t-empty-state fs-small text-muted">[!] No recent gateway activity detected.</div>
            <?php else: ?>
                <?php foreach ($ping_list as $ping): ?>
                    <div class="mb-3 pb-3" style="border-bottom: 1px dashed rgba(255,255,255,0.1);">
                        <div class="text-muted fs-small mb-1">Incoming Signal From:</div>
                        <div class="t-glow text-warning font-bold" style="font-size: 11px; word-wrap: break-word;"><?= htmlspecialchars($ping['source_url']) ?></div>
                        <div class="text-muted fs-small mb-2" style="font-size: 10px;">Time: <?= htmlspecialchars($ping['received_at']) ?> UTC</div>
                        
                        <form action="radar.php" method="POST" class="m-0 d-flex gap-2 align-items-center">
                            <input type="hidden" name="action" value="add_peer">
                            <input type="hidden" name="peer_url" value="<?= htmlspecialchars($ping['source_url']) ?>">
                            <input type="text" name="peer_alias" class="t-input m-0" placeholder="Set @alias" style="max-width: 100px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                            <input type="password" name="admin_pass" class="t-input m-0" placeholder="Key" style="width: 60px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                            <button type="submit" class="t-badge outline success m-0" style="height: 26px; padding: 0 6px; border: none; cursor: pointer;">[ + LOCK TO RADAR ]</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>