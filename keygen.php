<?php
// ==========================================
// 🔮 DEADDROP: POST-QUANTUM KEY GENERATOR
// ==========================================
require_once 'db.php';

if (php_sapi_name() !== 'cli') {
    die("[!] ERROR: This sacred protocol can only be executed via Terminal CLI.\n");
}

echo "Generating Layer 2 (Post-Quantum Mockup) Keys...\n";

// Utilizing a secondary Libsodium Keypair as a structural ML-KEM (Kyber) placeholder
$pq_keypair = sodium_crypto_box_keypair();
$pq_public = base64_encode(sodium_crypto_box_publickey($pq_keypair));
$pq_private = base64_encode(sodium_crypto_box_secretkey($pq_keypair));

try {
    $stmt = $db->prepare("UPDATE node_identity SET pq_public = :pub, pq_private = :priv WHERE id = 1");
    $stmt->execute([':pub' => $pq_public, ':priv' => $pq_private]);
    
    echo "[+] SUCCESS: Quantum Vault keys generated and securely stored in SQLite!\n";
    echo "[*] Your node will now broadcast this key in outbox.json\n";
} catch (Exception $e) {
    echo "[!] FAILED: " . $e->getMessage() . "\n";
}
?>