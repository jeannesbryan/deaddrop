<?php
// ==========================================
// 🏴‍☠️ DEADDROP: SECURE INBOX (v9.0 - Social Graph Obfuscation)
// ==========================================
require_once 'db.php';
require_once 'auth.php';

$status_msg = '';

if (isset($_GET['lock'])) {
    deaddrop_lock('dm.php');
}
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') $status_msg = "ENCRYPTED TRANSMISSION SUCCESSFULLY BROADCASTED";
    if ($_GET['status'] === 'destroyed') $status_msg = "TOMBSTONE PROTOCOL ENGAGED: SECURE DROP DESTROYED";
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

// 🔐 VOLATILE RAM DECRYPTION & BLACK SITE AUTHENTICATION
$unlocked = deaddrop_is_unlocked();
$unlock_error = '';
$my_keypair = null;
$my_pq_keypair = null;
$master_key = deaddrop_master_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_pass'])) {
    if (deaddrop_unlock($_POST['unlock_pass'], $config['admin_hash'], $unlock_error, deaddrop_session_ttl($config))) {
        $unlocked = true;
        $master_key = deaddrop_master_key();
        $status_msg = "VAULT UNLOCKED IN RAM // VOLATILE EXTRAPOLATION ACTIVE";
    }
}

if ($unlocked) {
    deaddrop_refresh_unlock(deaddrop_session_ttl($config));
    $my_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        base64_decode($config['private_key']), base64_decode($config['public_key'])
    );
    if (!empty($config['pq_private']) && !empty($config['pq_public'])) {
        $my_pq_keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            base64_decode($config['pq_private']), base64_decode($config['pq_public'])
        );
    }
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
        $total_feeds = $db->query("SELECT COUNT(*) FROM inbox")->fetchColumn();
        $total_pages = ceil($total_feeds / $limit);
        if ($total_pages < 1) $total_pages = 1;

        $query = $db->query("SELECT * FROM inbox ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $feeds = $query->fetchAll(PDO::FETCH_ASSOC);

        // 🔮 DECRYPT RADAR ALIASES ON THE FLY
        $stmt_alias = $db->query("SELECT onion_url, alias FROM following");
        foreach ($stmt_alias->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $alias_map[$row['onion_url']] = decrypt_alias($row['alias'], $master_key);
        }

        // 🔥 DELAYED BURNER PROTOCOL & ALIAS MAPPING
        $burner_ids = [];
        foreach ($feeds as &$post) {
            // Map decrypted alias
            if ($post['is_local'] == 0 && isset($alias_map[$post['author_host']])) {
                $post['author_name'] = '@' . $alias_map[$post['author_host']];
            }
            
            // Queue burners for destruction
            if ($post['status'] === 'burner' && $post['is_local'] == 0) {
                $burner_ids[] = $post['id'];
            }
        }
        
        // Exterminate burners from eMMC
        if (!empty($burner_ids)) {
            $placeholders = implode(',', array_fill(0, count($burner_ids), '?'));
            $stmt_burn = $db->prepare("DELETE FROM inbox WHERE id IN ($placeholders)");
            $stmt_burn->execute($burner_ids);
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
    <title>DeadDrop // Secure Inbox</title>
    <link href="assets/torminal.css" rel="stylesheet" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png" />
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">

    <?php if (!$unlocked): ?>
        <div class="t-lock-panel private">
            <h1 class="t-vault-title t-glow text-private">[ 🔒 CLASSIFIED VAULT ]</h1>
            <div class="t-vault-subtitle">Direct Message Subsystem is Encrypted at Rest.</div>
            
            <?php if (!empty($unlock_error)): ?>
                <div class="t-alert danger mb-4 d-inline-block text-left"><?= htmlspecialchars($unlock_error) ?></div><br>
            <?php endif; ?>
            
            <?php if (!empty($status_msg) && empty($unlock_error)): ?>
                <div class="t-alert danger mb-4 d-inline-block text-left">[+] <?= htmlspecialchars($status_msg) ?></div><br>
            <?php endif; ?>

            <form action="dm.php?page=<?= $page ?>" method="POST" class="t-lock-form">
                <input type="password" name="unlock_pass" class="t-input mb-3 w-100 t-input-center border-private" placeholder="Insert Master Key to Decrypt..." required autofocus>
                <button type="submit" class="t-btn w-100 m-0 outline private font-bold">[ EXTRAPOLATE TO RAM ]</button>
            </form>
        </div>
    <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom pb-3 t-stack-mobile border-private-soft">
            <div>
                <h1 class="m-0 font-bold t-glow t-page-title private">&gt; ISOLATED_INBOX_</h1>
                <div class="mt-1 fs-small font-bold text-muted">
                    NODE: <?= htmlspecialchars($config['node_url']) ?>
                    <br>UNLOCK TTL: <?= deaddrop_unlocked_remaining() ?>s
                </div>
            </div>
            <a href="dm.php?lock=1" class="t-btn danger outline">[ PURGE RAM & LOCK ]</a>
        </div>

        <div class="t-toolbar border-private-soft">
            <a href="index.php" class="t-btn outline">[ TIMELINE ]</a>
            <a href="dm.php" class="t-btn private active">[ INBOX ]</a>
            <a href="radar.php" class="t-btn info outline">[ RADAR ]</a>
        </div>

        <?php if (!empty($status_msg)): ?>
            <div class="t-alert mb-4">[+] <?= htmlspecialchars($status_msg) ?></div>
        <?php endif; ?>

        <div class="t-card private mb-5">
            <div class="t-card-title private">[ Secure Drop Channel ]</div>
            <form action="publish.php" method="POST" enctype="multipart/form-data">
                <?= deaddrop_csrf_input() ?>
                <textarea name="content" class="t-textarea mb-2 border-private" placeholder="Type your encrypted payload here..." required></textarea>
                
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input type="text" name="target" class="t-input w-auto flex-fill m-0 border-private" placeholder="Target @alias is required for Hybrid E2EE" required>
                    <label class="t-checkbox-label mb-0 t-glow mt-2 w-100 text-private">
                        <input type="checkbox" name="is_burner" value="1" class="t-toggle-checkbox">
                        <span class="t-checkmark"></span> [ 🔥 ENGAGE BURNER MODE ]
                    </label>
                    <input type="text" name="reply_to" class="t-input w-auto flex-fill m-0 border-private" placeholder="Reply to Post ID (Optional)">
                    
                    <select name="ttl" class="t-input w-auto m-0 t-input-sm border-private">
                        <option value="0">TTL: Forever</option>
                        <option value="1">TTL: 1 Hour</option>
                        <option value="24">TTL: 24 Hours</option>
                        <option value="168">TTL: 7 Days</option>
                    </select>

                    <input type="file" name="media" accept="image/jpeg, image/png, image/webp, image/gif" class="t-input w-auto m-0 t-input-sm border-private">
                </div>

                <div class="d-flex gap-2 align-items-center t-stack-mobile">
                    <span class="t-badge private ghost">[ SESSION AUTH ACTIVE ]</span>
                    <button type="submit" class="t-btn private active m-0">ENCRYPT & TRANSMIT</button>
                </div>
            </form>
        </div>

        <main>
            <div class="t-section-label private t-glow">[ Decrypted Comms Log ]</div>
            
            <?php if (empty($feeds)): ?>
                <div class="t-empty-state border-private text-private">
                    <span class="t-empty-state-icon">Ø</span>
                    [!] No private transmissions found.
                </div>
            <?php else: ?>
                <?php foreach ($feeds as $post): ?>
                    <div class="t-post private <?= $post['is_local'] ? 'local-node' : '' ?> <?= ($post['status'] === 'deleted') ? 'deleted' : '' ?>">
                        <div class="t-post-header fs-small">
                            <div>
                                <a href="profile.php?host=<?= urlencode($post['author_host']) ?>" class="t-badge outline private t-post-author t-link-plain">
                                    <?= htmlspecialchars($post['author_name']) ?>
                                </a>
                            </div>
                            
                            <div class="text-muted d-flex align-items-center gap-2">
                                <span>ID: <?= htmlspecialchars($post['remote_id']) ?></span>
                                <?php if ($post['is_local'] && $post['status'] !== 'deleted'): ?>
                                    <form action="delete.php" method="POST" class="m-0 p-0 t-inline-form" onsubmit="return confirm('Initiate Global Tombstone Protocol?');">
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
                            <div class="t-quote private">
                                <span class="t-quote-author">> Replying to <?= htmlspecialchars($parent['author_name']) ?> :</span>
                                <?= nl2br(htmlspecialchars(mb_strimwidth($parent['content'], 0, 150, "..."))) ?>
                            </div>
                        <?php else: ?>
                            <div class="t-badge dotted private mb-2">Replying to: <?= htmlspecialchars($post['reply_to']) ?> (Signal Lost)</div>
                        <?php 
                            endif;
                        endif;

                        // 🔓 PURE EXTRAPOLATION LOGIC
                        $display_content = $post['content'];
                        $is_render_dimmed = false;

                        // 1. CEK DOUBLE-LEDGER (Pesan Keluar Murni)
                        if ($post['is_local'] == 1 && strpos($display_content, '[[SPLIT_LEDGER]]') !== false) {
                            $ledger_parts = explode('[[SPLIT_LEDGER]]', $display_content);
                            $display_content = "[🔓 DECRYPTED OUTGOING DROP]\n\n" . $ledger_parts[0];
                        } 
                        // 2. CEK CIPHERTEXT MASUK (Strictly HYBRID Framework)
                        elseif (strpos($display_content, 'HYBRID:') === 0 || strpos($display_content, 'HYBRID-BURNER:') === 0) {
                            $is_burner_drop = (strpos($display_content, 'HYBRID-BURNER:') === 0);
                            $offset = $is_burner_drop ? 14 : 7;
                            $payload_str = substr($display_content, $offset);
                            $decrypted = false;

                            $parts = explode('::', $payload_str);
                            if (count($parts) === 3) {
                                $nonce = base64_decode($parts[0]);
                                $kem_layer2 = base64_decode($parts[1]);
                                $ciphertext = base64_decode($parts[2]);

                                $kem_layer1 = false;
                                if ($my_pq_keypair) $kem_layer1 = sodium_crypto_box_seal_open($kem_layer2, $my_pq_keypair);
                                if ($kem_layer1 === false) $kem_layer1 = $kem_layer2; // Backward compatible safety net

                                $sym_key = sodium_crypto_box_seal_open($kem_layer1, $my_keypair);
                                if ($sym_key !== false) {
                                    $decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $sym_key);
                                }
                            }

                            if ($decrypted !== false) {
                                $noise_pos = strpos($decrypted, "\n[::NOISE::]");
                                if ($noise_pos !== false) $decrypted = substr($decrypted, 0, $noise_pos);

                                $prefix_label = $is_burner_drop ? "[🔥 DECRYPTED BURNER DROP - DESTROYED UPON READING]\n\n" : "[🔓 DECRYPTED PRIVATE DROP]\n\n";
                                $display_content = $prefix_label . $decrypted;
                            } else {
                                $display_content = "[!] DECRYPTION FAILED: CORRUPTED CIPHERTEXT OR KEY MISMATCH.\n\n" . $display_content;
                                $is_render_dimmed = true;
                            }
                        } 
                        // 3. ANOMALY DETECTION
                        else {
                            $display_content = "[!] UNRECOGNIZED OR CORRUPTED PAYLOAD FORMAT.\n\n" . $display_content;
                            $is_render_dimmed = true;
                        }
                        ?>
                        <div class="t-post-content private mt-1 <?= $is_render_dimmed ? 'dimmed' : '' ?>"><?= nl2br(htmlspecialchars($display_content)) ?></div>
                        
                        <?php if (!empty($post['media_url'])): ?>
                            <img src="<?= htmlspecialchars($post['media_url']) ?>" alt="Attached Media" class="t-media-attachment">
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
                    <a href="dm.php?page=<?= $page - 1 ?>" class="t-page-link border-private text-private">[ ◄ Page <?= $page - 1 ?> ]</a>
                <?php else: ?>
                    <span class="t-page-link t-page-disabled border-private-soft">[ ◄ Page ]</span>
                <?php endif; ?>

                <span class="t-page-current private">-- ( Current: <?= $page ?> ) --</span>

                <?php if ($page < $total_pages): ?>
                    <a href="dm.php?page=<?= $page + 1 ?>" class="t-page-link border-private text-private">[ Page <?= $page + 1 ?> ► ]</a>
                <?php else: ?>
                    <span class="t-page-link t-page-disabled border-private-soft">[ Page ► ]</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </main>
    <?php endif; ?>

</div>
</body>
</html>