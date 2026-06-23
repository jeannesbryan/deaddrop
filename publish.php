<?php
// ==========================================
// 🏴‍☠️ DEADDROP: PUBLISH & SYNDICATE (v6.0 - Strict Quantum Ledger)
// ==========================================
require_once 'db.php';

function terminal_error($message) {
    http_response_code(400);
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='theme-color' content='#110818'><title>Error</title><link href='assets/torminal.css' rel='stylesheet'></head><body class='t-crt' style='padding-top:10vh;'><div class='t-container t-box-md'><div class='t-alert danger mb-4 font-bold'>$message</div><a href='index.php' class='t-btn outline'>[ RETURN ]</a></div></body></html>");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Access denied.");
}

$input_pass = $_POST['admin_pass'] ?? '';
if (!password_verify($input_pass, $config['admin_hash'])) {
    sleep(2);
    terminal_error("[ ACCESS DENIED ] Invalid cryptographic credentials.");
}

$content = trim(strip_tags($_POST['content'] ?? ''));
$reply_to = trim(strip_tags($_POST['reply_to'] ?? ''));
if (empty($content)) {
    terminal_error("[ ERROR ] Transmission payload is empty.");
}

// ⏳ EPHEMERAL TTL CALCULATION
$ttl_hours = isset($_POST['ttl']) ? (int)$_POST['ttl'] : 0;
$expires_at = null;
if ($ttl_hours > 0) {
    $expires_at = gmdate('Y-m-d\TH:i:s\Z', strtotime("+$ttl_hours hours"));
}

// 🔐 HYBRID KEM ENVELOPE & DOUBLE-LEDGER PREPARATION
$target = trim(strip_tags($_POST['target'] ?? ''));
$is_private_drop = false;
$is_burner = (isset($_POST['is_burner']) && $_POST['is_burner'] == '1');

if (!empty($target)) {
    $is_private_drop = true;
    $pristine_plaintext = $content; // Retain clean plaintext for local sender view
    
    if (strpos($target, '@') === 0) {
        $alias = substr($target, 1);
        $stmt_key = $db->prepare("SELECT public_key, pq_public FROM following WHERE alias = :alias LIMIT 1");
        $stmt_key->execute([':alias' => $alias]);
        $target_keys = $stmt_key->fetch(PDO::FETCH_ASSOC);
        if (!$target_keys) terminal_error("[ E2EE ERROR ] Peer alias not registered in active radar.");
    } else {
        $stmt_key = $db->prepare("SELECT public_key, pq_public FROM following WHERE onion_url = :url LIMIT 1");
        $stmt_key->execute([':url' => rtrim($target, '/')]);
        $target_keys = $stmt_key->fetch(PDO::FETCH_ASSOC);
        if (!$target_keys) terminal_error("[ E2EE ERROR ] Target Public Key not found in radar.");
    }

    $target_pub_key = base64_decode($target_keys['public_key']);
    $target_pq_pub  = !empty($target_keys['pq_public']) ? base64_decode($target_keys['pq_public']) : null;

    // 🛡️ DENIABLE UNIFORM NOISE PADDING (4KB BLOCK ALIGNMENT)
    $block_size = 4096; 
    $delimiter = "\n[::NOISE::]";
    $current_len = strlen($content);
    $target_size = ceil(($current_len + strlen($delimiter) + 1) / $block_size) * $block_size;
    $pad_len = $target_size - $current_len - strlen($delimiter);
    if ($pad_len > 0) {
        $noise = base64_encode(random_bytes($pad_len)); 
        $content .= $delimiter . substr($noise, 0, $pad_len);
    }

    // 🔒 3-LAYER HYBRID KEM ENCAPSULATION
    $sym_key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $nonce   = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    
    // Layer 0: Payload Encryption
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($content, '', $nonce, $sym_key);
    
    // Layer 1: Classical ECDH (X25519)
    $kem_layer1 = sodium_crypto_box_seal($sym_key, $target_pub_key);

    // Layer 2: Post-Quantum Mockup Wrap
    if ($target_pq_pub) {
        $kem_layer2 = sodium_crypto_box_seal($kem_layer1, $target_pq_pub);
    } else {
        $kem_layer2 = $kem_layer1; 
    }

    // Assemble Quantum Vault Block
    $final_payload = base64_encode($nonce) . '::' . base64_encode($kem_layer2) . '::' . base64_encode($ciphertext);
    $prefix = $is_burner ? 'HYBRID-BURNER:' : 'HYBRID:';
    $encrypted_vault_envelope = $prefix . $final_payload;

    // 🧬 DOUBLE-LEDGER BINDING: Fuse pristine plaintext with ciphertext envelope
    $content = $pristine_plaintext . "[[SPLIT_LEDGER]]" . $encrypted_vault_envelope;
}

// 📷 EXIF-STRIPPED MEDIA PROCESSING
$media_url = null;
if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
    $file_tmp  = $_FILES['media']['tmp_name'];
    $file_name = $_FILES['media']['name'];
    $file_size = $_FILES['media']['size'];
    
    if ($file_size > 2097152) terminal_error("[ ERROR ] Payload exceeds 2MB limit.");
    
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) terminal_error("[ ERROR ] Unsupported media matrix.");
    
    $new_filename = hash('sha256', uniqid('', true)) . '.' . $ext;
    $upload_dir = __DIR__ . '/media/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
        $target_file = $upload_dir . $new_filename;
        // Strip EXIF Metadata via Perl Engine
        shell_exec('exiftool -all= -overwrite_original ' . escapeshellarg($target_file));
        $media_url = rtrim($config['node_url'], '/') . '/media/' . $new_filename;
    }
}

// 💽 DATABASE INJECTION & SURGICAL JSON REBUILD
try {
    $local_id = generate_local_id(); 
    $now_utc = gmdate('Y-m-d\TH:i:s\Z'); 
    $table_name = $is_private_drop ? 'inbox' : 'timeline';
    
    $stmt = $db->prepare("INSERT INTO $table_name (remote_id, author_name, author_host, content, media_url, is_local, reply_to, status, expires_at, created_at) 
                          VALUES (:rid, :name, :host, :content, :media, 1, :reply, 'active', :expires, :waktu)");
    
    $stmt->execute([
        ':rid'     => $local_id,
        ':name'    => $config['node_name'],
        ':host'    => $config['node_url'],
        ':content' => $content, // Injects Double-Ledger String
        ':media'   => $media_url,
        ':reply'   => empty($reply_to) ? null : $reply_to,
        ':expires' => $expires_at,
        ':waktu'   => $now_utc
    ]);

    // Reconstruct outbox.json for external workers
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

    // 🛡️ SURGICAL INTERVENTION: Eradicate internal plaintext before broadcasting to outbox.json
    foreach ($my_posts as &$export_item) {
        if (strpos($export_item['content'], '[[SPLIT_LEDGER]]') !== false) {
            $ledger_parts = explode('[[SPLIT_LEDGER]]', $export_item['content']);
            $export_item['content'] = $ledger_parts[1]; // Strictly broadcast the ciphertext envelope!
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

    $redirect_target = $is_private_drop ? 'dm.php' : 'index.php';
    header("Location: $redirect_target?status=success");
    exit;
} catch (Exception $e) {
    terminal_error("[ TRANSMISSION FAILED ] " . htmlspecialchars($e->getMessage()));
}
?>