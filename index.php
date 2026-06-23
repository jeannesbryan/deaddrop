<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE HOLOGRAM (v5.0 - Timeline & Paging)
// ==========================================
require_once 'db.php';

$status_msg = '';
$alert_type = 'success';

// ==========================================
// 📡 MENGAMBIL NOTIFIKASI STATUS & DATA
// ==========================================
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') $status_msg = "[+] TRANSMISSION SUCCESSFULLY BROADCASTED TO OUTBOX.JSON";
    if ($_GET['status'] === 'destroyed') $status_msg = "[+] TOMBSTONE PROTOCOL ENGAGED: SIGNAL DESTROYED";
}

// 🧭 STATELESS NANO-PAGING LOGIC
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 100;
$offset = ($page - 1) * $limit;

try {
    // Ambil Total Data untuk Kalkulasi Halaman Akhir
    $total_feeds = $db->query("SELECT COUNT(*) FROM timeline")->fetchColumn();
    $total_pages = ceil($total_feeds / $limit);
    if ($total_pages < 1) $total_pages = 1;

    // Ambil Timeline dengan Offset
    $query = $db->query("SELECT * FROM timeline ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $feeds = $query->fetchAll(PDO::FETCH_ASSOC);
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
    <title>DeadDrop // Network Node</title>
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
        .thread-quote { background: rgba(0, 255, 102, 0.05); border-left: 2px dashed var(--t-green-dim); padding: 8px 12px; margin-bottom: 12px; font-size: 0.85rem; color: var(--t-green-dim); }
        .thread-quote .quote-author { font-weight: bold; color: var(--t-green); margin-bottom: 4px; display: block; }
    </style>
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom" style="padding-bottom: 15px;">
        <div>
            <h1 class="m-0 font-bold t-glow" style="font-size: 1.8rem; color: var(--t-green);">&gt; <?= htmlspecialchars($config['node_name']) ?>_</h1>
            <div class="mt-1 fs-small font-bold text-muted">
                NODE: <?= htmlspecialchars($config['node_url']) ?>
            </div>
        </div>
        <a href="../index.php" class="t-btn">⇐ Back</a>
    </div>

    <div class="d-flex gap-2 mb-4">
        <a href="index.php" class="t-btn" style="background: var(--t-green); color: black;">[ TIMELINE ]</a>
        <a href="dm.php" class="t-btn outline" style="color: #ff0055; border-color: #ff0055;">[ INBOX ]</a>
        <a href="radar.php" class="t-btn outline" style="color: var(--t-cyan, #00ffff); border-color: var(--t-cyan, #00ffff);">[ RADAR ]</a>
    </div>

    <?php if (!empty($status_msg)): ?>
        <div class="t-alert <?= $alert_type ?> mb-4"><?= $status_msg ?></div>
    <?php endif; ?>

    <div class="t-card mb-4">
        <div class="font-bold mb-3" style="color: var(--t-green);">[ Broadcast Station ]</div>
        <form action="publish.php" method="POST" enctype="multipart/form-data">
            <textarea name="content" class="t-textarea mb-2" placeholder="Type your speculations or thought logs here..." required></textarea>
            
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <input type="text" name="target" class="t-input w-auto flex-fill m-0" placeholder="Target .onion or @alias (E2EE)">
                <input type="text" name="reply_to" class="t-input w-auto flex-fill m-0" placeholder="Reply to Post ID (Optional)">
                
                <select name="ttl" class="t-input w-auto m-0" style="font-size: 0.8rem;">
                    <option value="0">TTL: Forever</option>
                    <option value="1">TTL: 1 Hour</option>
                    <option value="24">TTL: 24 Hours</option>
                    <option value="168">TTL: 7 Days</option>
                </select>

                <input type="file" name="media" accept="image/jpeg, image/png, image/webp, image/gif" class="t-input w-auto m-0" style="font-size: 0.8rem;">
            </div>

            <div class="d-flex gap-2">
                <input type="password" name="admin_pass" class="t-input flex-fill m-0" placeholder="Secure Key" required>
                <button type="submit" class="t-btn m-0">TRANSMIT</button>
            </div>
        </form>
    </div>

    <main>
        <div class="font-bold mb-3 t-glow text-success" style="text-transform: uppercase;">[ Global Signals Data Log ]</div>
        
        <?php if (empty($feeds)): ?>
            <div class="t-empty-state">
                <span class="t-empty-state-icon">Ø</span>
                [!] No signals detected on Page <?= $page ?>.
            </div>
        <?php else: ?>
            <?php foreach ($feeds as $post): ?>
                <div class="t-card p-3 post-card <?= $post['is_local'] ? 'local-node' : '' ?>" style="border-top: none; <?= ($post['status'] === 'deleted') ? 'opacity: 0.6;' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center t-border-bottom pb-2 mb-2 fs-small">
                        <div>
                            <a href="profile.php?host=<?= urlencode($post['author_host']) ?>" class="t-badge outline" style="text-decoration: none; font-weight: bold;">
                                <?= htmlspecialchars($post['author_name']) ?>
                            </a>
                        </div>
                        
                        <div class="text-muted d-flex align-items-center gap-2">
                            <span>ID: <?= htmlspecialchars($post['remote_id']) ?></span>
                            <?php if ($post['is_local'] && $post['status'] !== 'deleted'): ?>
                                <form action="delete.php" method="POST" class="m-0 p-0" style="display:inline;" onsubmit="return confirm('Initiate Global Tombstone Protocol? This will destroy the signal across all synced nodes.');">
                                    <input type="hidden" name="remote_id" value="<?= htmlspecialchars($post['remote_id']) ?>">
                                    <input type="password" name="admin_pass" placeholder="Key" class="t-input m-0" style="width:60px; padding:0 4px; font-size:10px; height:20px; display:inline-block;" required>
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
                        <div class="t-badge mb-2" style="background: transparent; border-style: dotted;">Replying to: <?= htmlspecialchars($post['reply_to']) ?> (Signal Lost)</div>
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
                    <div class="post-content mt-1" style="white-space: pre-wrap; font-size: 14px; color: var(--t-green); font-family: monospace;"><?= nl2br(htmlspecialchars($display_content)) ?></div>
                    
                    <?php if (!empty($post['media_url'])): ?>
                        <img src="<?= htmlspecialchars($post['media_url']) ?>" alt="Attached Media" class="media-attachment">
                    <?php endif; ?>
                    
                    <div class="text-right mt-3 pt-2" style="border-top: 1px dashed rgba(0,255,65,0.2);">
                        <span class="fs-small text-muted"><?= htmlspecialchars($post['created_at']) ?> UTC</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center align-items-center mt-4 pt-3 t-border-top gap-3">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="t-btn outline">[ ◄ Page <?= $page - 1 ?> ]</a>
            <?php else: ?>
                <span class="t-btn outline text-muted" style="border-color: rgba(0,255,65,0.2); cursor: not-allowed;">[ ◄ Page ]</span>
            <?php endif; ?>

            <span class="font-bold t-glow" style="color: var(--t-green);">-- ( Current: <?= $page ?> ) --</span>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>" class="t-btn outline">[ Page <?= $page + 1 ?> ► ]</a>
            <?php else: ?>
                <span class="t-btn outline text-muted" style="border-color: rgba(0,255,65,0.2); cursor: not-allowed;">[ Page ► ]</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>