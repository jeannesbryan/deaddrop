<?php
// ==========================================
// 🏴‍☠️ DEADDROP: THE HOLOGRAM (v1.0 Final)
// ==========================================
require_once 'db.php';

$status_msg = '';
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $status_msg = "TRANSMISSION SUCCESSFULLY BROADCASTED TO OUTBOX.JSON";
}

try {
    $query = $db->query("SELECT * FROM timeline ORDER BY created_at DESC LIMIT 100");
    $feeds = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feeds = [];
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
    <meta name="description" content="DeadDrop: The Tor-Native Asynchronous Social Protocol (Nano-Pub). An extreme, zero-push, and JavaScript-free decentralized social syndication platform for the Darknet ecosystem.">
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
        /* Special Styles for Threads (Quoted Reply) */
        .thread-quote { background: rgba(0, 255, 102, 0.05); border-left: 2px dashed var(--t-green-dim); padding: 8px 12px; margin-bottom: 12px; font-size: 0.85rem; color: var(--t-green-dim); }
        .thread-quote .quote-author { font-weight: bold; color: var(--t-green); margin-bottom: 4px; display: block; }
    </style>
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">
    <header class="t-border-bottom mb-4 pb-3">
        <h1 class="t-glow font-bold m-0" style="font-size: 1.8rem; color: var(--t-green);">> <?= htmlspecialchars($config['node_name']) ?>_</h1>
        <div class="text-muted fs-small mt-1">NODE: <?= htmlspecialchars($config['node_url']) ?> // PROTOCOL: NANO-PUB v1.0</div>
    </header>

    <?php if (!empty($status_msg)): ?>
        <div class="t-alert success mb-4">[+] SUCCESS: <?= $status_msg ?></div>
    <?php endif; ?>

    <div class="t-card mb-5">
        <div class="font-bold mb-3" style="color: var(--t-green);">[ Broadcast Station ]</div>
        <form action="publish.php" method="POST" enctype="multipart/form-data">
            <textarea name="content" class="t-textarea mb-2" placeholder="Type your speculations or thought logs here..." required></textarea>
            
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <input type="text" name="reply_to" class="t-input w-auto flex-fill m-0" placeholder="Reply to Post ID (Optional)">
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
                [!] No signals detected in the local timeline yet.
            </div>
        <?php else: ?>
            <?php foreach ($feeds as $post): ?>
                <div class="t-card p-3 post-card <?= $post['is_local'] ? 'local-node' : '' ?>" style="border-top: none;">
                    <div class="d-flex justify-content-between align-items-center t-border-bottom pb-2 mb-2 fs-small">
                        <div>
                            <a href="profile.php?host=<?= urlencode($post['author_host']) ?>" class="t-badge outline" style="text-decoration: none; font-weight: bold;">
                                <?= htmlspecialchars($post['author_name']) ?>
                            </a>
                        </div>
                        <div class="text-muted">ID: <?= htmlspecialchars($post['remote_id']) ?></div>
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