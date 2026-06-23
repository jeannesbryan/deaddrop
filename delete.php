<?php
// ==========================================
// 🏴‍☠️ DEADDROP: TOMBSTONE PROTOCOL (v7.0 - Zero-JS Eradication)
// ==========================================
require_once 'db.php';

function terminal_error($message) {
    http_response_code(400);
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='theme-color' content='#110818'><title>Error</title><link href='assets/torminal.css' rel='stylesheet'></head><body class='t-crt' style='padding-top:10vh;'><div class='t-container t-box-md'><div class='t-alert danger mb-4 font-bold'>$message</div><a href='index.php' class='t-btn outline'>[ RETURN TO VOID ]</a></div></body></html>");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Access denied.");
}

$input_pass = $_POST['admin_pass'] ?? '';
$remote_id = trim(strip_tags($_POST['remote_id'] ?? ''));

if (!password_verify($input_pass, $config['admin_hash'])) {
    sleep(2);
    terminal_error("[ ACCESS DENIED ] Invalid cryptographic key.");
}

if (empty($remote_id)) {
    terminal_error("[ ERROR ] Target payload ID missing.");
}

try {
    // 1. LOCATE TARGET SIGNAL IN DATABASES
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
        terminal_error("[ ERROR ] Signal not found or unauthorized to destroy external node data.");
    }

    // 2. PURGE ASSOCIATED MEDIA FROM eMMC
    if (!empty($post['media_url'])) {
        $file_name = basename($post['media_url']);
        $file_path = __DIR__ . '/media/' . $file_name;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }

    // 3. EXECUTE TOMBSTONE MUTATION
    $stmt_update = $db->prepare("UPDATE $table SET status = 'deleted', content = '[☠️ SIGNAL DESTROYED BY AUTHOR]', media_url = NULL WHERE remote_id = :rid");
    $stmt_update->execute([':rid' => $remote_id]);

    // 4. REBUILD OUTBOX.JSON BROADCAST
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

    // 🛡️ CRITICAL SECURITY PATCH: SURGICAL INTERVENTION FOR SPLIT-LEDGER
    // Prevents plaintext leak during outbox rebuild after deletion!
    foreach ($my_posts as &$export_item) {
        if (strpos($export_item['content'], '[[SPLIT_LEDGER]]') !== false) {
            $ledger_parts = explode('[[SPLIT_LEDGER]]', $export_item['content']);
            $export_item['content'] = $ledger_parts[1]; // Strictly preserve ciphertext envelope
        }
    }

    $nano_pub_feed = [
        "protocol"     => "Nano-Pub",
        "author"       => $config['node_name'],
        "domain"       => $config['node_url'],
        "public_key"   => $config['public_key'],
        "pq_public"    => $config['pq_public'] ?? null, 
        "last_updated" => $now_utc,
        "posts"        => $my_posts
    ];
    file_put_contents(__DIR__ . '/outbox.json', json_encode($nano_pub_feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 5. RETURN TO RADAR VIEW (AND TRIGGER AUTO-LOCK)
    $redirect = ($table === 'inbox') ? 'dm.php' : 'index.php';
    header("Location: $redirect?status=destroyed");
    exit;

} catch (Exception $e) {
    terminal_error("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>