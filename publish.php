<?php
// ==========================================
// 🏴‍☠️ DEADDROP: PUBLISH & SYNDICATE (v6.0 - Strict Quantum Ledger)
// ==========================================
require_once 'db.php';
require_once 'auth.php';
require_once 'net.php';
require_once 'outbox.php';

function terminal_error($message) {
    http_response_code(400);
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='theme-color' content='#110818'><title>Error</title><link href='assets/torminal.css' rel='stylesheet'></head><body class='t-crt'><div class='t-center-screen'><div class='t-container t-box-md'><div class='t-alert danger mb-4 font-bold'>$message</div><a href='index.php' class='t-btn outline'>[ RETURN ]</a></div></div></body></html>");
}


function decrypt_alias($payload, $key_string) {
    if (strpos($payload, 'ENC:') !== 0) return $payload; // Legacy plaintext alias support

    $data = base64_decode(substr($payload, 4), true);
    if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return "[ENCRYPTED_BLOB]";
    }

    $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = hash('sha256', $key_string, true);
    $dec = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

    return $dec !== false ? $dec : "[DECRYPTION_FAILED]";
}

function normalize_alias($alias) {
    $alias = ltrim(trim((string)$alias), '@');
    return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $alias));
}

function find_peer_keys_by_alias(PDO $db, string $alias, string $master_key) {
    $target_alias = normalize_alias($alias);
    if ($target_alias === '') return false;

    $stmt = $db->query("SELECT onion_url, alias, public_key, pq_public FROM following");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $peer) {
        $stored_alias = decrypt_alias((string)($peer['alias'] ?? ''), $master_key);
        $stored_alias = normalize_alias($stored_alias);

        if ($stored_alias !== '' && hash_equals($target_alias, $stored_alias)) {
            return $peer;
        }
    }

    return false;
}

function find_peer_keys_by_url(PDO $db, string $target_url, array $config) {
    $policy_error = null;
    $clean = deaddrop_normalize_and_validate_peer_url($target_url, $config, $policy_error);
    if ($clean === null) {
        terminal_error("[ E2EE ERROR ] Invalid target endpoint: " . $policy_error);
    }

    $candidates = array_values(array_unique(array_filter([
        rtrim(trim($target_url), '/'),
        $clean
    ])));

    $stmt = $db->prepare("SELECT public_key, pq_public FROM following WHERE onion_url = :url LIMIT 1");
    foreach ($candidates as $url) {
        $stmt->execute([':url' => $url]);
        $keys = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($keys) return $keys;
    }

    return false;
}

function decode_public_key_or_fail($key, string $label) {
    if (empty($key)) {
        terminal_error("[ E2EE ERROR ] $label is missing. Run worker.php first to sync peer keys.");
    }

    $decoded = base64_decode((string)$key, true);
    if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
        terminal_error("[ E2EE ERROR ] $label is invalid or corrupted.");
    }

    return $decoded;
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
$master_key = deaddrop_master_key();

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
$has_media_upload = (isset($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
$private_media_manifest = null;
$private_media_pointer = null;
$save_private_plaintext_copy = !empty($config['save_private_plaintext_copy']);

if (!empty($target)) {
    $is_private_drop = true;
    if (isset($_POST['save_plaintext_copy'])) {
        $save_private_plaintext_copy = ($_POST['save_plaintext_copy'] === '1');
    }
    $pristine_plaintext = $content;
    
    if (strpos($target, '@') === 0) {
        $alias = substr($target, 1);
        $target_keys = find_peer_keys_by_alias($db, $alias, $master_key);
        if (!$target_keys) {
            terminal_error("[ E2EE ERROR ] Peer alias not registered in active radar or alias cannot be decrypted with this key.");
        }
    } else {
        $target_keys = find_peer_keys_by_url($db, $target, $config);
        if (!$target_keys) terminal_error("[ E2EE ERROR ] Target Public Key not found in radar.");
    }

    $target_pub_key = decode_public_key_or_fail($target_keys['public_key'] ?? null, 'Target Public Key');
    $target_pq_pub  = null;
    if (!empty($target_keys['pq_public'])) {
        $target_pq_pub = decode_public_key_or_fail($target_keys['pq_public'], 'Target PQ Public Key');
    }

    if ($has_media_upload) {
        $file_tmp  = $_FILES['media']['tmp_name'];
        $file_name = (string)($_FILES['media']['name'] ?? 'private-media');
        $file_size = (int)($_FILES['media']['size'] ?? 0);

        if ($file_size <= 0 || $file_size > 2097152) {
            terminal_error("[ E2EE MEDIA ERROR ] Private media exceeds the 2MB limit.");
        }

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_mimes = deaddrop_private_media_allowed_mimes();
        if (!isset($allowed_mimes[$ext])) {
            terminal_error("[ E2EE MEDIA ERROR ] Unsupported private media type.");
        }

        $plaintext_media = file_get_contents($file_tmp);
        if ($plaintext_media === false) {
            terminal_error("[ E2EE MEDIA ERROR ] Could not read uploaded private media.");
        }

        $media_key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $media_nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher_media = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext_media, '', $media_nonce, $media_key);
        sodium_memzero($plaintext_media);

        $stored_name = hash('sha256', $cipher_media . random_bytes(16)) . '.ddm';
        $public_private_dir = deaddrop_public_private_media_path();
        $public_private_file = $public_private_dir . '/' . $stored_name;
        if (file_put_contents($public_private_file, $cipher_media, LOCK_EX) === false) {
            terminal_error("[ E2EE MEDIA ERROR ] Could not store encrypted private media blob.");
        }
        @chmod($public_private_file, 0644);

        $private_media_dir = deaddrop_private_media_path($config);
        $private_media_file = $private_media_dir . '/' . $stored_name;
        @copy($public_private_file, $private_media_file);
        @chmod($private_media_file, 0600);

        $private_media_manifest = [
            'type' => 'deaddrop-private-media',
            'version' => 1,
            'alg' => 'xchacha20poly1305-ietf',
            'stored_name' => $stored_name,
            'url' => rtrim($config['node_url'], '/') . '/media/private/' . $stored_name,
            'mime' => $allowed_mimes[$ext],
            'original_name' => basename($file_name),
            'size' => $file_size,
            'key' => base64_encode($media_key),
            'nonce' => base64_encode($media_nonce),
            'cipher_sha256' => hash('sha256', $cipher_media),
        ];
        $private_media_pointer = 'DDM:' . $stored_name;

        sodium_memzero($media_key);
        sodium_memzero($media_nonce);
        sodium_memzero($cipher_media);

        $content .= deaddrop_private_media_marker($private_media_manifest);
        $pristine_plaintext = $content;
    }

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

    if ($save_private_plaintext_copy) {
        // Optional sender-side comfort mode. Disabled by default in v13.2.
        $content = $pristine_plaintext . "[[SPLIT_LEDGER]]" . $encrypted_vault_envelope;
    } else {
        $content = $encrypted_vault_envelope;
    }
}

// 📷 EXIF-STRIPPED MEDIA PROCESSING
$media_url = $private_media_pointer;
if (!$is_private_drop && isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
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
    // Rebuild outbox.json via centralized atomic helper.
    rebuild_outbox($db, $config);

    $redirect_target = $is_private_drop ? 'dm.php' : 'index.php';
    header("Location: $redirect_target?status=success");
    exit;
} catch (Exception $e) {
    terminal_error("[ TRANSMISSION FAILED ] " . htmlspecialchars($e->getMessage()));
}
?>
