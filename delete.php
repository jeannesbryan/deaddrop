<?php
// ==========================================
// 🏴‍☠️ DEADDROP: TOMBSTONE PROTOCOL (Global Delete)
// ==========================================
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Access denied.");
}

$input_pass = $_POST['admin_pass'] ?? '';
$remote_id = trim(strip_tags($_POST['remote_id'] ?? ''));

if (!password_verify($input_pass, $config['admin_hash'])) {
    die("<script>alert('ACCESS DENIED: Invalid key.'); history.back();</script>");
}

if (empty($remote_id)) {
    die("<script>alert('ERROR: Payload ID missing.'); history.back();</script>");
}

try {
    // 1. Locate the signal in either timeline or inbox
    $table = null;
    $stmt_check = $db->prepare("SELECT media_url, is_local FROM timeline WHERE remote_id = :rid");
    $stmt_check->execute([':rid' => $remote_id]);
    $post = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($post) {
        $table = 'timeline';
    } else {
        $stmt_check = $db->prepare("SELECT media_url, is_local FROM inbox WHERE remote_id = :rid");
        $stmt_check->execute([':rid' => $remote_id]);
        $post = $stmt_check->fetch(PDO::FETCH_ASSOC);
        if ($post) $table = 'inbox';
    }

    if (!$table || $post['is_local'] == 0) {
        die("<script>alert('ERROR: Signal not found or you lack authority to destroy external node data.'); history.back();</script>");
    }

    // 2. Erase local media file to free eMMC
    if (!empty($post['media_url'])) {
        $file_name = basename($post['media_url']);
        $file_path = __DIR__ . '/media/' . $file_name;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }

    // 3. Convert to Tombstone
    $stmt_update = $db->prepare("UPDATE $table SET status = 'deleted', content = '[☠️ SIGNAL DESTROYED BY AUTHOR]', media_url = NULL WHERE remote_id = :rid");
    $stmt_update->execute([':rid' => $remote_id]);

    // 4. Rebuild outbox.json to broadcast the destruction sequence
    $now_utc = gmdate('Y-m-d\TH:i:s\Z'); 
    $stmt_out = $db->prepare("
        SELECT id, content, media_url, reply_to, status, expires_at, timestamp FROM (
            SELECT remote_id as id, content, media_url, reply_to, status, expires_at, created_at as timestamp 
            FROM timeline WHERE is_local = 1 
            UNION ALL 
            SELECT remote_id as id, content, media_url, reply_to, status, expires_at, created_at as timestamp 
            FROM inbox WHERE is_local = 1 
        ) ORDER BY timestamp DESC LIMIT :limit
    ");
    $stmt_out->bindValue(':limit', $config['max_outbox'], PDO::PARAM_INT);
    $stmt_out->execute();
    $my_posts = $stmt_out->fetchAll(PDO::FETCH_ASSOC);

    $nano_pub_feed = [
        "protocol"     => "Nano-Pub",
        "author"       => $config['node_name'],
        "domain"       => $config['node_url'],
        "public_key"   => $config['public_key'],
        "last_updated" => $now_utc,
        "posts"        => $my_posts
    ];
    file_put_contents(__DIR__ . '/outbox.json', json_encode($nano_pub_feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Return to previous view
    $redirect = ($table === 'inbox') ? 'dm.php' : 'index.php';
    header("Location: $redirect?status=destroyed");
    exit;

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>