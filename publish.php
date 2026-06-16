<?php
// ==========================================
// 🏴‍☠️ DEADDROP: PUBLISH & SYNDICATE
// ==========================================
require_once 'db.php';

// Zero-JS Error Handler Function
function terminal_error($message) {
    http_response_code(400);
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='theme-color' content='#110818'><title>DeadDrop // Error</title><link href='assets/torminal.css' rel='stylesheet'></head><body class='t-crt' style='padding-top:10vh;'><div class='t-container t-box-md'><div class='t-alert danger mb-4 font-bold'>$message</div><a href='index.php' class='t-btn outline'>[ RETURN TO RADAR ]</a></div></body></html>");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Access denied.");
}

// 1. BCRYPT AUTHENTICATION
$input_pass = $_POST['admin_pass'] ?? '';
if (!password_verify($input_pass, $config['admin_hash'])) {
    sleep(2); // Mitigate brute-force attacks
    terminal_error("[ ACCESS DENIED ] Invalid security credentials.");
}

// 2. CAPTURE TEXT PAYLOAD
$content = trim(strip_tags($_POST['content'] ?? ''));
$reply_to = trim(strip_tags($_POST['reply_to'] ?? ''));
if (empty($content)) {
    terminal_error("[ ERROR ] Transmission is empty.");
}

// 3. CAPTURE & PROCESS MEDIA (Images Only)
$media_url = null;
if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
    $file_tmp  = $_FILES['media']['tmp_name'];
    $file_name = $_FILES['media']['name'];
    $file_size = $_FILES['media']['size'];
    
    // Limit maximum size to 2MB to conserve memory
    if ($file_size > 2097152) {
        terminal_error("[ ERROR ] Maximum image size is 2MB to conserve memory.");
    }
    
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array($ext, $allowed)) {
        terminal_error("[ ERROR ] Image format not supported.");
    }
    
    // OBFUSCATION: Rename file to SHA-256 Hash to prevent guessing & direct indexing
    $new_filename = hash('sha256', uniqid('', true)) . '.' . $ext;
    $upload_dir = __DIR__ . '/media/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Move file to media/ folder
    if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
        // Save full URL to be written into JSON
        $media_url = rtrim($config['node_url'], '/') . '/media/' . $new_filename;
    } else {
        terminal_error("[ ERROR ] Failed to move image. Check write permissions for /media/.");
    }
}

// 4. SAVE TO DATABASE & REBUILD JSON
try {
    $local_id = generate_local_id(); 
    $now_utc = gmdate('Y-m-d\TH:i:s\Z'); 
    
    // Write to SQLite Timeline
    $stmt = $db->prepare("INSERT INTO timeline (remote_id, author_name, author_host, content, media_url, is_local, reply_to, created_at) 
                          VALUES (:rid, :name, :host, :content, :media, 1, :reply, :waktu)");
    
    $stmt->execute([
        ':rid'     => $local_id,
        ':name'    => $config['node_name'],
        ':host'    => $config['node_url'],
        ':content' => $content,
        ':media'   => $media_url,
        ':reply'   => empty($reply_to) ? null : $reply_to,
        ':waktu'   => $now_utc
    ]);

    // Rebuild outbox.json
    $stmt_out = $db->prepare("SELECT remote_id as id, content, media_url, reply_to, created_at as timestamp 
                              FROM timeline WHERE is_local = 1 ORDER BY created_at DESC LIMIT :limit");
    $stmt_out->bindValue(':limit', $config['max_outbox'], PDO::PARAM_INT);
    $stmt_out->execute();
    $my_posts = $stmt_out->fetchAll(PDO::FETCH_ASSOC);

    $nano_pub_feed = [
        "protocol"     => "Nano-Pub v1.0",
        "author"       => $config['node_name'],
        "domain"       => $config['node_url'],
        "last_updated" => $now_utc,
        "posts"        => $my_posts
    ];

    $json_path = __DIR__ . '/outbox.json';
    if (file_put_contents($json_path, json_encode($nano_pub_feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new Exception("Failed to write outbox.json.");
    }

    header("Location: index.php?status=success");
    exit;

} catch (Exception $e) {
    terminal_error("[ TRANSMISSION FAILED ] " . htmlspecialchars($e->getMessage()));
}
?>