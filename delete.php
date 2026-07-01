<?php
// ==========================================
// 🏴‍☠️ DEADDROP: TOMBSTONE PROTOCOL (v9.0 - Anti-Forensics Shredder)
// ==========================================
require_once 'db.php';
require_once 'auth.php';
require_once 'outbox.php';

function terminal_error($message) {
    http_response_code(400);
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='theme-color' content='#110818'><title>Error</title><link href='assets/torminal.css' rel='stylesheet'></head><body class='t-crt'><div class='t-center-screen'><div class='t-container t-box-md'><div class='t-alert danger mb-4 font-bold'>$message</div><a href='index.php' class='t-btn outline'>[ RETURN TO VOID ]</a></div></div></body></html>");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Access denied.");
}

$auth_error = null;
if (!deaddrop_action_allowed($auth_error)) {
    sleep(1);
    terminal_error($auth_error ?? "[ ACCESS DENIED ] Session expired.");
}
deaddrop_refresh_unlock(deaddrop_session_ttl($config));
$remote_id = trim(strip_tags($_POST['remote_id'] ?? ''));

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

    // 2. PURGE ASSOCIATED MEDIA FROM eMMC (PHYSICAL DATA VAPORIZATION)
    if (!empty($post['media_url'])) {
        if (strpos((string)$post['media_url'], 'DDM:') === 0) {
            deaddrop_private_media_shred($config, (string)$post['media_url']);
        } else {
            $file_name = basename($post['media_url']);
            $file_path = __DIR__ . '/media/' . $file_name;
            if (file_exists($file_path)) {
                // shred -u (remove), -z (zero out), -n 3 (3 passes)
                exec('shred -u -z -n 3 ' . escapeshellarg($file_path));
            }
        }
    }

    // 3. EXECUTE TOMBSTONE MUTATION
    $stmt_update = $db->prepare("UPDATE $table SET status = 'deleted', content = '[☠️ SIGNAL DESTROYED BY AUTHOR]', media_url = NULL WHERE remote_id = :rid");
    $stmt_update->execute([':rid' => $remote_id]);
    // 4. REBUILD OUTBOX.JSON BROADCAST VIA CENTRALIZED ATOMIC HELPER
    rebuild_outbox($db, $config);

    // 5. RETURN TO RADAR VIEW (AND TRIGGER AUTO-LOCK)
    $redirect = ($table === 'inbox') ? 'dm.php' : 'index.php';
    header("Location: $redirect?status=destroyed");
    exit;

} catch (Exception $e) {
    terminal_error("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>
