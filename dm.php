<?php
// ==========================================
// 🏴‍☠️ DEADDROP: SECURE INBOX (v5.0 - Burner & Paging)
// ==========================================
require_once 'db.php';

$status_msg = '';
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') $status_msg = "ENCRYPTED TRANSMISSION SUCCESSFULLY BROADCASTED";
    if ($_GET['status'] === 'destroyed') $status_msg = "TOMBSTONE PROTOCOL ENGAGED: SECURE DROP DESTROYED";
}

// 🧭 STATELESS NANO-PAGING LOGIC
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 100;
$offset = ($page - 1) * $limit;

try {
    // Ambil Total Data untuk Kalkulasi Halaman Akhir
    $total_feeds = $db->query("SELECT COUNT(*) FROM inbox")->fetchColumn();
    $total_pages = ceil($total_feeds / $limit);
    if ($total_pages < 1) $total_pages = 1;

    // Ambil Inbox dengan Offset
    $query = $db->query("SELECT * FROM inbox ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $feeds = $query->fetchAll(PDO::FETCH_ASSOC);

    // 🔥 EKSEKUSI BURNER DMs: Hancurkan pesan masuk setelah ditarik
    $burner_ids = [];
    foreach ($feeds as $post) {
        if ($post['status'] === 'burner' && $post['is_local'] == 0) {
            $burner_ids[] = $post['id'];
        }
    }
    if (!empty($burner_ids)) {
        $placeholders = implode(',', array_fill(0, count($burner_ids), '?'));
        $stmt_burn = $db->prepare("DELETE FROM inbox WHERE id IN ($placeholders)");
        $stmt_burn->execute($burner_ids);
    }
} catch (PDOException $e) {
    $feeds = [];
    $total_pages = 1;
}

// Lightweight function to fetch Parent Post
function get_parent_post($db, $reply_to_id) {
    try {
        $stmt = $db->prepare("SELECT author_name, content FROM timeline WHERE remote_id = :rid LIMIT 1");
        $stmt->execute([':rid' => $reply_to_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
    <style>
        .post-card { border-left: 3px solid #ff0055; transition: 0.2s; margin-bottom: 15px; background: rgba(255,0,85,0.02); }
        .post-card:hover { border-left-color: #ff3377; background: rgba(255,0,85,0.05); }
        .post-card.local-node { border-left-width: 4px; border-left-color: var(--t-green); background: rgba(0,255,65,0.03); }  
        .media-attachment { display: block; max-width: 100%; max-height: 400px; border: 1px dashed var(--t-green-dim); margin-top: 10px; filter: grayscale(80%); transition: 0.3s; }
        .media-attachment:hover { filter: none; }
        .thread-quote { background: rgba(0, 0, 0, 0.5); border-left: 2px dashed #ff0055; padding: 8px 12px; margin-bottom: 12px; font-size: 0.85rem; color: #ff88aa; }
        .thread-quote .quote-author { font-weight: bold; color: #ff3377; margin-bottom: 4px; display: block; }
    </style>
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom" style="padding-bottom: 15px;">
        <div>
            <h1 class="m-0 font-bold t-glow" style="font-size: 1.8rem; color: #ff0055;">&gt; ISOLATED_INBOX_</h1>
            <div class="mt-1 fs-small font-bold text-muted">
                NODE: <?= htmlspecialchars($config['node_url']) ?>
            </div>
        </div>
        <a href="../index.php" class="t-btn">⇐ Back</a>
    </div>

    <div class="d-flex gap-2 mb-4">
        <a href="index.php" class="t-btn outline" style="color: var(--t-green); border-color: var(--t-green);">[ TIMELINE ]</a>
        <a href="dm.php" class="t-btn" style="background: #ff0055; color: white; border-color: #ff0055;">[ INBOX ]</a>
        <a href="radar.php" class="t-btn outline" style="color: var(--t-cyan, #00ffff); border-color: var(--t-cyan, #00ffff);">[ RADAR ]</a>
    </div>

    <?php if (!empty($status_msg)): ?>
        <div class="t-alert mb-4" style="border-color: #ff0055; color: #ff0055;">[+] <?= $status_msg ?></div>
    <?php endif; ?>

    <div class="t-card mb-5" style="border-color: #ff0055;">
        <div class="font-bold mb-3" style="color: #ff0055;">[ Secure Drop Channel ]</div>
        <form action="publish.php" method="POST" enctype="multipart/form-data">
            <textarea name="content" class="t-textarea mb-2" placeholder="Type your encrypted payload here..." required style="border-color: #ff0055;"></textarea>
            
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <input type="text" name="target" class="t-input w-auto flex-fill m-0" placeholder="Target @alias is required for E2EE" required style="border-color: #ff0055;">
				<label class="t-checkbox-label mb-0 t-glow mt-2 w-100" style="color: #ff0055;">
					<input type="checkbox" name="is_burner" value="1" class="t-toggle-checkbox">
					<span class="t-checkmark" style="border-color: #ff0055;"></span> [ 🔥 ENGAGE BURNER MODE ]
				</label>
                <input type="text" name="reply_to" class="t-input w-auto flex-fill m-0" placeholder="Reply to Post ID (Optional)" style="border-color: #ff0055;">
                
                <select name="ttl" class="t-input w-auto m-0" style="font-size: 0.8rem; border-color: #ff0055;">
                    <option value="0">TTL: Forever</option>
                    <option value="1">TTL: 1 Hour</option>
                    <option value="24">TTL: 24 Hours</option>
                    <option value="168">TTL: 7 Days</option>
                </select>

                <input type="file" name="media" accept="image/jpeg, image/png, image/webp, image/gif" class="t-input w-auto m-0" style="font-size: 0.8rem; border-color: #ff0055;">
            </div>

            <div class="d-flex gap-2">
                <input type="password" name="admin_pass" class="t-input flex-fill m-0" placeholder="Secure Key" required style="border-color: #ff0055;">
                <button type="submit" class="t-btn m-0" style="color: #ff0055; border-color: #ff0055;">ENCRYPT & TRANSMIT</button>
            </div>
        </form>
    </div>

    <main>
        <div class="font-bold mb-3 t-glow" style="text-transform: uppercase; color: #ff0055;">[ Decrypted Comms Log ]</div>
        
        <?php if (empty($feeds)): ?>
            <div class="t-empty-state" style="border-color: #ff0055; color: #ff0055;">
                <span class="t-empty-state-icon">Ø</span>
                [!] No private transmissions found on Page <?= $page ?>.
            </div>
        <?php else: ?>
            <?php foreach ($feeds as $post): ?>
                <div class="t-card p-3 post-card <?= $post['is_local'] ? 'local-node' : '' ?>" style="border-top: none; <?= ($post['status'] === 'deleted') ? 'opacity: 0.6;' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center t-border-bottom pb-2 mb-2 fs-small" style="border-color: rgba(255,0,85,0.2);">
                        <div>
                            <a href="profile.php?host=<?= urlencode($post['author_host']) ?>" class="t-badge outline" style="text-decoration: none; font-weight: bold; border-color: #ff0055; color: #ff0055;">
                                <?= htmlspecialchars($post['author_name']) ?>
                            </a>
                        </div>
                        
                        <div class="text-muted d-flex align-items-center gap-2">
                            <span>ID: <?= htmlspecialchars($post['remote_id']) ?></span>
                            <?php if ($post['is_local'] && $post['status'] !== 'deleted'): ?>
                                <form action="delete.php" method="POST" class="m-0 p-0" style="display:inline;" onsubmit="return confirm('Initiate Global Tombstone Protocol? This will destroy the signal across all synced nodes.');">
                                    <input type="hidden" name="remote_id" value="<?= htmlspecialchars($post['remote_id']) ?>">
                                    <input type="password" name="admin_pass" placeholder="Key" class="t-input m-0" style="width:60px; padding:0 4px; font-size:10px; height:20px; display:inline-block; border-color: #ff0055;" required>
                                    <button type="submit" class="t-badge outline danger m-0" style="border:none; cursor:pointer; padding:2px;">[ DEL ]</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($post['expires_at'])): ?>
                                <span class="t-badge outline warning" style="border:none;">[ ⏳ Ephemeral ]</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php 
                    if (!empty($post['reply_to'])): 
                        $parent = get_parent_post($db, $post['reply_to']);
                        if ($parent):
                    ?>
                        <div class="thread-quote">
                            <span class="quote-author">> Replying to <?= htmlspecialchars($parent['author_name']) ?> :</span>
                            <?= nl2br(htmlspecialchars(mb_strimwidth($parent['content'], 0, 150, "..."))) ?>
                        </div>
                    <?php else: ?>
                        <div class="t-badge mb-2" style="background: transparent; border-style: dotted; color: #ff0055; border-color: #ff0055;">Replying to: <?= htmlspecialchars($post['reply_to']) ?> (Signal Lost)</div>
                    <?php 
                        endif;
                    endif; 
                    ?>

                    <?php 
                    $display_content = $post['content'];
                    if (strpos($display_content, 'E2EE:') === 0 || strpos($display_content, 'E2EE-BURNER:') === 0 || strpos($display_content, 'HYBRID:') === 0 || strpos($display_content, 'HYBRID-BURNER:') === 0) {
                        $display_content = "[🔒 ENCRYPTED OUTGOING DROP]\n> Vault Architecture    : 3-Layer Hybrid KEM\n> Ciphertext Block Size : 4096 Bytes\n> Status                : Awaiting target extraction.";
                    }
                    ?>
                    <div class="post-content mt-1" style="white-space: pre-wrap; font-size: 14px; color: #eebbcc; font-family: monospace;"><?= nl2br(htmlspecialchars($display_content)) ?></div>
                    
                    <?php if (!empty($post['media_url'])): ?>
                        <img src="<?= htmlspecialchars($post['media_url']) ?>" alt="Attached Media" class="media-attachment">
                    <?php endif; ?>
                    
                    <div class="text-right mt-3 pt-2" style="border-top: 1px dashed rgba(255,0,85,0.2);">
                        <span class="fs-small text-muted"><?= htmlspecialchars($post['created_at']) ?> UTC</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center align-items-center mt-4 pt-3 t-border-top gap-3" style="border-color: rgba(255,0,85,0.2);">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="t-btn outline" style="color: #ff0055; border-color: #ff0055;">[ ◄ Page <?= $page - 1 ?> ]</a>
            <?php else: ?>
                <span class="t-btn outline text-muted" style="border-color: rgba(255,0,85,0.2); cursor: not-allowed;">[ ◄ Page ]</span>
            <?php endif; ?>

            <span class="font-bold t-glow" style="color: #ff0055;">-- ( Current: <?= $page ?> ) --</span>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>" class="t-btn outline" style="color: #ff0055; border-color: #ff0055;">[ Page <?= $page + 1 ?> ► ]</a>
            <?php else: ?>
                <span class="t-btn outline text-muted" style="border-color: rgba(255,0,85,0.2); cursor: not-allowed;">[ Page ► ]</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>