<?php
// ==========================================
// 🏴‍☠️ DEADDROP: SECURE KEY GENERATOR
// ==========================================

$hash_result = '';
$raw_password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
    $raw_password = $_POST['password'];
    // Generate Bcrypt Hash
    $hash_result = password_hash($raw_password, PASSWORD_DEFAULT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#110818">
    <title>DeadDrop // Keygen</title>
    <link href="assets/torminal.css" rel="stylesheet" />
</head>
<body class="t-crt" style="padding-top: 10vh;">

<div class="t-container t-box-md">
    <div class="t-card border-warning">
        <div class="t-border-bottom pb-3 mb-4">
            <h2 class="t-glow text-warning m-0 font-bold">> ENCRYPTION_MODULE</h2>
            <div class="text-muted fs-small mt-1">Bcrypt Access Key Generator System for db.php</div>
        </div>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="d-block text-muted fs-small mb-2">ENTER RAW PASSWORD:</label>
                <input type="text" name="password" class="t-input w-100" placeholder="Type your secret password..." required autocomplete="off" value="<?= htmlspecialchars($raw_password) ?>">
            </div>
            <button type="submit" class="t-btn warning w-100">[ GENERATE SECURE HASH ]</button>
        </form>

        <?php if (!empty($hash_result)): ?>
            <div class="mt-4 pt-3 t-border-top">
                <div class="text-success font-bold mb-2">[+] ENCRYPTION SUCCESSFUL</div>
                <div class="text-muted fs-small mb-2">Copy the green text below, then paste it into the <code>'admin_hash'</code> parameter in the <code>db.php</code> file:</div>
                
                <div class="t-alert success" style="word-break: break-all; font-family: monospace; font-size: 0.9rem;">
                    <?= htmlspecialchars($hash_result) ?>
                </div>
                
                <div class="text-danger fs-small mt-3 font-bold t-blink">
                    [!] WARNING: Delete this keygen.php file from the server when no longer needed!
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="text-center mt-4">
        <a href="index.php" class="t-btn outline">[ RETURN TO HOLOGRAM ]</a>
    </div>
</div>

</body>
</html>