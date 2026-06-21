<?php
// ==========================================
// 🏴‍☠️ DEADDROP: RADAR COMMAND CENTER (v5.0)
// ==========================================
require_once 'db.php';

$status_msg = '';
$alert_type = 'success';

// ==========================================
// ⚙️ HANDLERS: ADD, EDIT, DELETE PEER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $input_pass = $_POST['admin_pass'] ?? '';
    if (!password_verify($input_pass, $config['admin_hash'])) {
        sleep(1);
        $status_msg = "[!] ACCESS DENIED: Invalid Secure Key.";
        $alert_type = 'danger';
    } else {
        // 1. TANGKAP AKSI TAMBAH TEMAN (ADD PEER / LOCK RADAR)
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
                $stmt_check = $db->prepare("SELECT id FROM following WHERE alias = :alias OR onion_url = :url LIMIT 1");
                $stmt_check->execute([':alias' => $alias, ':url' => $peer_url]);
                
                if ($stmt_check->fetch()) {
                    $status_msg = "[!] ALIAS/URL LOCKED: Petname '@$alias' or URL is already strictly assigned in your radar.";
                    $alert_type = 'danger';
                } else {
                    $stmt = $db->prepare("INSERT INTO following (onion_url, alias) VALUES (:url, :alias)");
                    $stmt->execute([':url' => $peer_url, ':alias' => $alias]);
                    $db->prepare("DELETE FROM ping_queue WHERE source_url = :url")->execute([':url' => $peer_url]);
                    
                    header("Location: radar.php?status=peer_added&alias=" . urlencode($alias));
                    exit;
                }
            }
        }
        
        // 2. TANGKAP AKSI PUTUS PERTEMANAN (UNFOLLOW)
        elseif ($_POST['action'] === 'unfollow_peer') {
            $peer_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
            $stmt = $db->prepare("DELETE FROM following WHERE onion_url = :url");
            $stmt->execute([':url' => $peer_url]);
            header("Location: radar.php?status=peer_removed");
            exit;
        }

        // 3. TANGKAP AKSI EDIT ALIAS
        elseif ($_POST['action'] === 'edit_alias') {
            $peer_url = rtrim(trim($_POST['peer_url'] ?? ''), '/');
            $raw_alias = trim($_POST['new_alias'] ?? '');
            
            $alias = ltrim($raw_alias, '@');
            $alias = preg_replace('/[^a-zA-Z0-9_-]/', '', $alias);

            if (!empty($alias)) {
                $stmt_check = $db->prepare("SELECT id FROM following WHERE alias = :alias AND onion_url != :url LIMIT 1");
                $stmt_check->execute([':alias' => $alias, ':url' => $peer_url]);
                
                if ($stmt_check->fetch()) {
                    $status_msg = "[!] ALIAS LOCKED: Petname '@$alias' is already used by another node.";
                    $alert_type = 'danger';
                } else {
                    $stmt = $db->prepare("UPDATE following SET alias = :alias WHERE onion_url = :url");
                    $stmt->execute([':alias' => $alias, ':url' => $peer_url]);
                    
                    $stmt_tl = $db->prepare("UPDATE timeline SET author_name = :alias WHERE author_host = :url");
                    $stmt_tl->execute([':alias' => $alias, ':url' => $peer_url]);
                    
                    $stmt_ib = $db->prepare("UPDATE inbox SET author_name = :alias WHERE author_host = :url");
                    $stmt_ib->execute([':alias' => $alias, ':url' => $peer_url]);
                    
                    header("Location: radar.php?status=alias_updated&alias=" . urlencode($alias));
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'peer_added') $status_msg = "[+] RADAR LOCKED: Successfully tracking petname @" . htmlspecialchars($_GET['alias'] ?? 'peer');
    if ($_GET['status'] === 'alias_updated') $status_msg = "[+] ALIAS UPDATED: Node successfully renamed to @" . htmlspecialchars($_GET['alias'] ?? 'peer');
    if ($_GET['status'] === 'peer_removed') {
        $status_msg = "[-] SYNCHRONIZATION DISCONNECTED: Node removed from radar.";
        $alert_type = 'warning';
    }
}

try {
    $query_following = $db->query("SELECT * FROM following ORDER BY id DESC");
    $following_list = $query_following->fetchAll(PDO::FETCH_ASSOC);
    
    $query_pings = $db->query("SELECT * FROM ping_queue ORDER BY received_at DESC");
    $ping_list = $query_pings->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $following_list = [];
    $ping_list = [];
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
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png" />
</head>
<body class="t-crt">

<div class="t-container t-box-md mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 t-border-bottom" style="padding-bottom: 15px;">
        <div>
            <h1 class="m-0 font-bold t-glow" style="font-size: 1.8rem; color: var(--t-cyan, #00ffff);">&gt; RADAR_COMMAND_</h1>
            <div class="mt-1 fs-small font-bold text-muted">
                NODE: <?= htmlspecialchars($config['node_url']) ?>
            </div>
        </div>
        <a href="../index.php" class="t-btn">⇐ Back</a>
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
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 70%;">
                            <span class="t-glow font-bold text-success" style="font-size: 1.1rem; vertical-align: middle;">@<?= htmlspecialchars($peer['alias']) ?></span>
                            <?php if (isset($peer['is_mutual']) && $peer['is_mutual'] == 1): ?>
                                <span class="t-badge success" style="border:none; background: transparent; font-size: 0.75rem; vertical-align: middle; padding: 0;">[🤝 Mutual]</span>
                            <?php endif; ?>
                            <br><span class="fs-small text-muted" style="font-size: 11px;"><?= htmlspecialchars($peer['onion_url']) ?></span>
                        </div>
                        <form action="radar.php" method="POST" class="m-0 d-flex gap-2 align-items-center" onsubmit="return confirm('Disconnect from @<?= htmlspecialchars($peer['alias']) ?>?');">
                            <input type="hidden" name="action" value="unfollow_peer">
                            <input type="hidden" name="peer_url" value="<?= htmlspecialchars($peer['onion_url']) ?>">
                            <input type="password" name="admin_pass" class="t-input m-0" placeholder="Key" style="width: 60px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                            <button type="submit" class="t-badge outline danger m-0" style="height: 26px; padding: 0 6px; border: none; cursor: pointer;">[ DEL ]</button>
                        </form>
                    </div>
                    
                    <form action="radar.php" method="POST" class="m-0 d-flex gap-2 align-items-center mt-2">
                        <input type="hidden" name="action" value="edit_alias">
                        <input type="hidden" name="peer_url" value="<?= htmlspecialchars($peer['onion_url']) ?>">
                        <span class="text-muted fs-small">↳ Rename:</span>
                        <input type="text" name="new_alias" class="t-input m-0" placeholder="New @alias" style="max-width: 120px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
                        <input type="password" name="admin_pass" class="t-input m-0" placeholder="Key" style="width: 60px; padding: 2px 4px; height: 26px; font-size: 11px;" required>
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

</div>
</body>
</html>