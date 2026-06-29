<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE HOLOGRAM (v9.0 - Social Graph Obfuscation)
// ==========================================
require_once 'db.php';
require_once 'auth.php';

$status_msg = '';
$alert_type = 'success';

if (isset($_GET['lock'])) {
    deaddrop_lock('index.php');
}

// 🔮 SYMMETRIC ALIAS CRYPTOGRAPHY
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

// 🔐 BLACK SITE AUTHENTICATION (SESSION-BASED, NO HIDDEN MASTER KEY)
$unlocked = deaddrop_is_unlocked();
$unlock_error = '';
$master_key = deaddrop_master_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_pass'])) {
    if (deaddrop_unlock($_POST['unlock_pass'], $config['admin_hash'], $unlock_error, deaddrop_session_ttl($config))) {
        $unlocked = true;
        $master_key = deaddrop_master_key();
        $status_msg = "[+] BLACK SITE UNLOCKED: GLOBAL TIMELINE EXTRAPOLATED";
    }
}

if ($unlocked) {
    deaddrop_refresh_unlock(deaddrop_session_ttl($config));
}

// ==========================================
// 📡 SYSTEM NOTIFICATIONS
// ==========================================
if (isset($_GET['status']) && $unlocked) {
    if ($_GET['status'] === 'success') $status_msg = "[+] PUBLIC TRANSMISSION SUCCESSFULLY BROADCASTED";
    if ($_GET['status'] === 'destroyed') $status_msg = "[+] TOMBSTONE PROTOCOL ENGAGED: SIGNAL DESTROYED";
}

// 🧭 STATELESS NANO-PAGING LOGIC
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 100;
$offset = ($page - 1) * $limit;

$feeds = [];
$alias_map = [];
$total_pages = 1;

// 🛡️ ZERO-LEAK QUERY & ON-THE-FLY ALIAS DECRYPTION
if ($unlocked) {
    try {
        $total_feeds = $db->query("SELECT COUNT(*) FROM timeline")->fetchColumn();
        $total_pages = ceil($total_feeds / $limit);
        if ($total_pages < 1) $total_pages = 1;

        $query = $db->query("SELECT * FROM timeline ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $feeds = $query->fetchAll(PDO::FETCH_ASSOC);

        // 🔮 DECRYPT RADAR ALIASES ON THE FLY
        $stmt_alias = $db->query("SELECT onion_url, alias FROM following");
        foreach ($stmt_alias->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $alias_map[$row['onion_url']] = decrypt_alias($row['alias'], $master_key);
        }

        // Apply Petnames silently to the UI
        foreach ($feeds as &$post) {
            if ($post['is_local'] == 0 && isset($alias_map[$post['author_host']])) {
                $post['author_name'] = '@' . $alias_map[$post['author_host']];
            }
        }
    } catch (PDOException $e) {
        $feeds = [];
        $total_pages = 1;
    }
}

function get_parent_post($db, $reply_to_id, $alias_map = []) {
    try {
        $stmt = $db->prepare("SELECT author_name, author_host, content FROM timeline WHERE remote_id = :rid LIMIT 1");
        $stmt->execute([':rid' => $reply_to_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($parent && isset($alias_map[$parent['author_host']])) {
            $parent['author_name'] = '@' . $alias_map[$parent['author_host']];
        }
        
        return $parent;
    } catch (PDOException $e) {
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#110818">
    <title>DeadDrop // Network Node</title>
    <link href="assets/torminal.css" rel="stylesheet" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png" />
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">

    <?php if (!$unlocked): ?>
        <div class="t-lock-panel">
            <h1 class="t-vault-title t-glow text-success">[ 🔒 RESTRICTED ZONE ]</h1>
            <div class="t-vault-subtitle">Sovereign Timeline is Classified. Authentication Required.</div>
            
            <?php if (!empty($unlock_error)): ?>
                <div class="t-alert danger mb-4 d-inline-block text-left"><?= htmlspecialchars($unlock_error) ?></div><br>
            <?php endif; ?>

            <form action="index.php?page=<?= $page ?>" method="POST" class="t-lock-form">
                <input type="password" name="unlock_pass" class="t-input mb-3 w-100 t-input-center" placeholder="Insert Master Key..." required autofocus>
                <button type="submit" class="t-btn w-100 m-0 outline font-bold">[ INITIALIZE UPLINK ]</button>
            </form>
        </div>
    <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom pb-3 t-stack-mobile">
            <div>
                <h1 class="m-0 font-bold t-glow t-page-title">&gt; <?= htmlspecialchars($config['node_name']) ?>_</h1>
                <div class="mt-1 fs-small font-bold text-muted">
                    NODE: <?= htmlspecialchars($config['node_url']) ?>
                    <br>UNLOCK TTL: <?= deaddrop_unlocked_remaining() ?>s
                </div>
            </div>
            <a href="index.php?lock=1" class="t-btn danger outline">[ LOCK ]</a>
        </div>

        <div class="t-toolbar">
            <a href="index.php" class="t-btn active">[ TIMELINE ]</a>
            <a href="dm.php" class="t-btn private outline">[ INBOX ]</a>
            <a href="radar.php" class="t-btn info outline">[ RADAR ]</a>
        </div>

        <?php if (!empty($status_msg)): ?>
            <div class="t-alert <?= $alert_type ?> mb-4"><?= $status_msg ?></div>
        <?php endif; ?>

        <div class="t-card mb-4">
            <div class="t-card-title">[ Broadcast Station ]</div>
            <form action="publish.php" method="POST" enctype="multipart/form-data">
                <?= deaddrop_csrf_input() ?>
                <textarea name="content" class="t-textarea mb-2" placeholder="Type your public speculations or thought logs here..." required></textarea>
                
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input type="text" name="target" class="t-input w-auto flex-fill m-0" placeholder="Target @alias is required for Hybrid E2EE">
                    <input type="text" name="reply_to" class="t-input w-auto flex-fill m-0" placeholder="Reply to Post ID (Optional)">
                    
                    <select name="ttl" class="t-input w-auto m-0 t-input-sm">
                        <option value="0">TTL: Forever</option>
                        <option value="1">TTL: 1 Hour</option>
                        <option value="24">TTL: 24 Hours</option>
                        <option value="168">TTL: 7 Days</option>
                    </select>

                    <input type="file" name="media" accept="image/jpeg, image/png, image/webp, image/gif" class="t-input w-auto m-0 t-input-sm">
                </div>

                <div class="d-flex gap-2 align-items-center t-stack-mobile">
                    <span class="t-badge ghost">[ SESSION AUTH ACTIVE ]</span>
                    <button type="submit" class="t-btn m-0">TRANSMIT</button>
                </div>
            </form>
        </div>

        <main>
            <div class="t-section-label t-glow">[ Global Signals Data Log ]</div>
            
            <?php if (empty($feeds)): ?>
                <div class="t-empty-state">
                    <span class="t-empty-state-icon">Ø</span>
                    [!] No public signals detected on Page <?= $page ?>.
                </div>
            <?php else: ?>
                <?php foreach ($feeds as $post): ?>
                    <div class="t-post <?= $post['is_local'] ? 'local-node' : '' ?> <?= ($post['status'] === 'deleted') ? 'deleted' : '' ?>">
                        <div class="t-post-header fs-small">
                            <div>
                                <a href="profile.php?host=<?= urlencode($post['author_host']) ?>" class="t-badge outline t-post-author t-link-plain">
                                    <?= htmlspecialchars($post['author_name']) ?>
                                </a>
                            </div>
                            
                            <div class="text-muted d-flex align-items-center gap-2">
                                <span>ID: <?= htmlspecialchars($post['remote_id']) ?></span>
                                <?php if ($post['is_local'] && $post['status'] !== 'deleted'): ?>
                                    <form action="delete.php" method="POST" class="m-0 p-0 t-inline-form" onsubmit="return confirm('Initiate Global Tombstone Protocol? This will destroy the signal across all synced nodes.');">
                                        <?= deaddrop_csrf_input() ?>
                                        <input type="hidden" name="remote_id" value="<?= htmlspecialchars($post['remote_id']) ?>">
                                        <button type="submit" class="t-badge outline danger m-0 t-delete-mini">[ DEL ]</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($post['expires_at'])): ?>
                                    <span class="t-badge outline warning ghost">[ ⏳ Ephemeral ]</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php 
                        if (!empty($post['reply_to'])): 
                            $parent = get_parent_post($db, $post['reply_to'], $alias_map);
                            if ($parent):
                        ?>
                            <div class="t-quote">
                                <span class="t-quote-author">> Replying to <?= htmlspecialchars($parent['author_name']) ?> :</span>
                                <?= nl2br(htmlspecialchars(mb_strimwidth($parent['content'], 0, 150, "..."))) ?>
                            </div>
                        <?php else: ?>
                            <div class="t-badge dotted mb-2">Replying to: <?= htmlspecialchars($post['reply_to']) ?> (Signal Lost)</div>
                        <?php 
                            endif;
                        endif; 
                        ?>

                        <div class="t-post-content mt-1"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                        
                        <?php if (!empty($post['media_url'])): ?>
                            <img src="<?= htmlspecialchars($post['media_url']) ?>" alt="Attached Media" class="t-media-attachment terminal-filter">
                        <?php endif; ?>
                        
                        <div class="t-post-footer">
                            <span class="fs-small text-muted"><?= htmlspecialchars($post['created_at']) ?> UTC</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
            <div class="t-pagination">
                <?php if ($page > 1): ?>
                    <a href="index.php?page=<?= $page - 1 ?>" class="t-page-link">[ ◄ Page <?= $page - 1 ?> ]</a>
                <?php else: ?>
                    <span class="t-page-link t-page-disabled">[ ◄ Page ]</span>
                <?php endif; ?>

                <span class="t-page-current">-- ( Current: <?= $page ?> ) --</span>

                <?php if ($page < $total_pages): ?>
                    <a href="index.php?page=<?= $page + 1 ?>" class="t-page-link">[ Page <?= $page + 1 ?> ► ]</a>
                <?php else: ?>
                    <span class="t-page-link t-page-disabled">[ Page ► ]</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </main>
    <?php endif; ?>

</div>
</body>
</html>