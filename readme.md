# > DEADDROP_ <img src="assets/favicon-32x32.png" width="32" align="center">
**The Tor-Native Asynchronous Social Protocol (Nano-Pub)**

![Status-Underground](https://img.shields.io/badge/Status-Underground-00ff66?style=for-the-badge&logo=tor&logoColor=7D4698&color=110818)
![PHP](https://img.shields.io/badge/PHP_8.2+-Strict_Post_Quantum-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-Zero_Knowledge_RAM_Vault-003B57?style=for-the-badge&logo=sqlite)

DeadDrop is an extreme, static-first, zero-JS, and post-quantum social syndication protocol designed for Tor networks and low-end hardware. It operates on the custom **Nano-Pub** protocol, turning your server into a "Sovereign Node" without the bloat of traditional federated networks. 

To guarantee absolute operational security, the protocol is hardened with advanced defenses: **Zero-Knowledge Volatile RAM Extrapolation** to protect data at rest, **Deniable Uniform Padding** to defeat ISP traffic analysis, **SOCKS5 Persistent Circuit Pooling** for extreme asynchronous Tor syncs, and a **3-Layer Hybrid Post-Quantum Encapsulation** framework to shield private payloads against future decryption.

---

### ⚠️ DISCLAIMER: CASUAL PROJECT AHEAD
> **Please Read Before Deploying!**
> This is a passionate **hobby project** built during my free time. It is **NOT** a professional, enterprise-grade software audited by cybersecurity firms. The codebase is highly experimental and designed for tinkering, learning, and having fun in the darknet ecosystem. Use it at your own risk. Expect bugs, raw PHP scripts, and CLI-based interventions. 

---

### ⚙️ THE ARCHITECTURE: ZERO-PUSH, ZERO-JS
Unlike ActivityPub (Mastodon) that forces real-time, heavy two-way server communications, DeadDrop reverses the paradigm:
1. **Static-First:** You post to your timeline. The engine generates a highly optimized `outbox.json`. That's it. It costs 0% CPU when visitors read your feed.
2. **SOCKS5 Persistent Pooling:** The background courier (`worker.php`) utilizes asynchronous `curl_multi_init()` over Tor. It holds a single SOCKS5 tunnel open (Keep-Alive) and pulls data from up to 100 `.onion` peers concurrently in seconds, bypassing Tor Daemon TCP handshaking strain.
3. **Pure Torminal UI (Mobile Ready):** The frontend is strictly built with HTML and CSS, fully responsive for touch devices. **Zero JavaScript.** It is designed to work flawlessly on Tor Browser's "Safest" mode.
4. **Isolated Command Center:** Dedicated `radar.php` dashboard for managing network syndication, peer renaming, and autonomous path-healing topologies.
5. **Stateless Nano-Paging:** Infinite timeline rendering managed entirely by PHP and SQLite `OFFSET`, ensuring browsers never crash from DOM overload.
6. **Zero-Knowledge RAM Vault:** Absolute data-at-rest protection. The SQLite database strictly stores raw, unbreakable ciphertexts; plaintext is NEVER written to the eMMC. The inbox temporarily extrapolates messages into volatile RAM only when the operator inputs the master security key. Upon refreshing, the memory is instantly purged.
7. **Double-Ledger Engine:** Outgoing private drops natively bind a split-ledger payload, retaining pristine plaintext for the local author while stripping it entirely before broadcasting the encrypted envelope to the public `outbox.json`.
8. **Post-Quantum Hybrid Armor:** Standard E2EE is retired. Messages are now sealed inside a 3-Layer Vault: **XChaCha20-Poly1305** (Payload) → **Libsodium X25519** (Layer 1 KEM) → **ML-KEM Kyber Mockup** (Layer 2 KEM), securing communication against future Shor's Algorithm decryption.
9. **Deniable Uniform Padding:** Total immunity against ISP Traffic Analysis. All outgoing encrypted payloads are cryptographically injected with digital noise to lock the footprint at an absolute **4096-byte (4KB) block size**.
10. **Auto-Scaling Hashcash Defense:** Incoming network knocks to the gateway are guarded by a dynamic SHA-256 Proof-of-Work puzzle. The difficulty scales exponentially during DDoS attempts to burn botnet CPU.
11. **Rotational Auto-Backup:** Built-in daily `tar.gz` archiver with strict 7-day retention to protect host eMMC while ensuring node recoverability.
12. **Airgapped Telegram Bridge:** Built-in silent API triggers to notify your mobile device of new authenticated DMs or valid gateway intrusions. All API dispatches are strictly routed through the Tor SOCKS5 proxy to guarantee zero clearnet IP leaks.
13. **Cron Jitter (Anti-Timing Analysis):** Background daemons autonomously inject randomized sleep delays (1-10 minutes) before execution to defeat data center traffic analysis and chronometric tracking.

---

### 🚀 COMPREHENSIVE INSTALLATION GUIDE
DeadDrop is designed for extreme efficiency and can run on headless Linux environments like a 256MB RAM NAT VPS or an Armbian Set-Top Box. Follow these exact steps to build your node from scratch.

#### PHASE 1: System Prep & Autostart Armor
**Cryptographic Floor:** The underlying ML-KEM (Kyber) mathematical polyfills strictly require **PHP 8.2 or higher**. Attempting to deploy on PHP 8.1 or older will trigger fatal syntax parser errors.

First, update your system and install the foundational packages. We explicitly target the modern PHP 8.2+ ecosystem to ensure the FastCGI environment supports complex post-quantum cryptography.

```bash
apt update && apt upgrade -y
apt install -y software-properties-common curl git nano nginx sqlite3 tor libimage-exiftool-perl
```

Now, inject the PHP repository and install the strict PHP 8.2 ecosystem:
```bash
# Add SURY repository for the latest PHP builds (Debian/Ubuntu)
add-apt-repository ppa:ondrej/php -y
apt update

# Install PHP 8.2 and its required sovereign extensions
apt install -y php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-xml php8.2-mbstring
```

*Note: Libsodium (E2EE cryptography) is required by DeadDrop but is natively compiled into the PHP 8.2 core, requiring no separate package.*

*Note: If your hosting provider pre-installed `apache2`, it will conflict with Nginx. Eradicate it permanently:*
```bash
systemctl stop apache2
systemctl disable apache2
apt purge apache2 -y
```

To guarantee that Nginx and Tor automatically resurrect whenever your node reboots, enforce global autostart immediately:
```bash
systemctl enable --now nginx tor php8.2-fpm
```

#### PHASE 2: Extreme RAM Tuning (Crucial for Low-End Hardware)
To prevent Out-Of-Memory (OOM) crashes on devices with limited RAM (like a 256MB STB), we must enforce a strict background diet.

**1. Limit Nginx Workers:**
```bash
nano /etc/nginx/nginx.conf
```
Find `worker_processes` and change it to `1`:
```nginx
worker_processes 1;
```
Save and exit (`Ctrl+O`, `Enter`, `Ctrl+X`).

**2. Enable PHP-FPM Hibernation:**
Open the pool configuration for PHP 8.2:
```bash
nano /etc/php/8.2/fpm/pool.d/www.conf
```
Press `Ctrl+W` to find `pm = ` and surgically change the surrounding settings to this exact block:
```ini
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 200
```
Save and exit. Restart the stack to apply the diet:
```bash
systemctl restart nginx
systemctl restart php8.2-fpm
```

#### PHASE 3: The Darknet Gateway (Tor Setup)
We configure Tor to expose our web server locally.
```bash
nano /etc/tor/torrc
```
Scroll to the hidden services section and add these two lines:
```text
HiddenServiceDir /var/lib/tor/hidden_service/
HiddenServicePort 80 127.0.0.1:80
```
Save and exit. Restart Tor to apply the configuration:
```bash
systemctl restart tor
```

**Viewing Your Permanent Address:**
To discover the permanent `.onion` address automatically generated by the system, run:
```bash
sudo cat /var/lib/tor/hidden_service/hostname
```

***

#### 💎 OPTIONAL: Enforcing a Vanity / Custom Tor Domain (v3)
If you already possess a custom vanity `.onion` domain, **do not** let Tor run the randomly generated address. Follow these strict intervention steps to swap the cryptographical keys safely without system conflicts:

1. **Stop the Tor daemon completely:**
```bash
   sudo systemctl stop tor
```
2. **Eradicate the randomly generated default keys:**
```bash
   sudo rm -rf /var/lib/tor/hidden_service/*
```
3. **Inject your custom keys:**
   A custom Tor v3 domain always consists of three crucial files: `hostname`, `hs_ed25519_public_key`, and the highly sensitive `hs_ed25519_secret_key`. Move all three files directly into `/var/lib/tor/hidden_service/`.
4. **Restore strict system ownership and permissions:**
   Tor will aggressively refuse to boot if security permissions are loose. Execute these exact commands:
```bash
   sudo chown -R debian-tor:debian-tor /var/lib/tor/hidden_service/
   sudo chmod 700 /var/lib/tor/hidden_service/
   sudo chmod 600 /var/lib/tor/hidden_service/hs_ed25519_secret_key
```
5. **Ignite Tor and verify propagation:**
```bash
   sudo systemctl start start tor
   sudo systemctl status tor
```

***

#### PHASE 4: Subfolder Deployment & Directory OpSec
**Crucial Architectural Note:** DeadDrop is explicitly deployed inside a subfolder (`/var/www/html/deaddrop`) rather than the absolute root. This isolates your timeline strictly to `yourdomain.onion/deaddrop`, leaving the primary root `/var/www/html` wide open for you to construct a personalized landing page.

Pull the DeadDrop codebase directly into the subfolder:
```bash
git clone https://github.com/jeannesbryan/deaddrop.git /var/www/html/deaddrop
```

**Strict Permission Enforcement (OpSec Protocol):**
To prevent server-side vulnerabilities and ensure the PHP backend can autonomously write to the database, save media, and rotate backups, you must construct the required directories and enforce precise file permissions:

```bash
# 1. Construct dynamic storage directories
mkdir -p /var/www/html/deaddrop/media
mkdir -p /var/www/html/deaddrop/backup

# 2. Assign absolute ownership to the web server
chown -R www-data:www-data /var/www/html/deaddrop

# 3. Enforce baseline read/execute permissions (Safe defaults)
find /var/www/html/deaddrop -type d -exec chmod 755 {} \;
find /var/www/html/deaddrop -type f -exec chmod 644 {} \;

# 4. Grant specific write access to dynamic storage target folders
chmod -R 775 /var/www/html/deaddrop/data
chmod -R 775 /var/www/html/deaddrop/media
chmod -R 775 /var/www/html/deaddrop/backup
```

Create the Nginx routing block:
```bash
nano /etc/nginx/sites-available/deaddrop
```
Paste the following configuration *(replace the `.onion` address accordingly)*:
```nginx
server {
    listen 80;
    server_name your_generated_address.onion;
    root /var/www/html; # Root remains available for landing pages
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock; # Strictly bound to PHP 8.2 FastCGI
    }

    # Brutally block public access to sensitive DeadDrop subdirectories
    location ~ ^/deaddrop/(data|backup|keys)/ {
        deny all;
    }
}
```
Enable the site block and remove default clutter:
```bash
rm /etc/nginx/sites-enabled/default
ln -s /etc/nginx/sites-available/deaddrop /etc/nginx/sites-enabled/
systemctl restart nginx
```

#### PHASE 5: The Post-Quantum Keygen Ritual
To ignite the Hybrid KEM architecture and register your Node Identity into SQLite, you MUST execute the sacred ritual strictly via CLI before opening the browser:
```bash
sudo -u www-data php /var/www/html/deaddrop/keygen.php
```

#### PHASE 6: Master Key & Identity Configuration
Before opening the core configuration, you must generate a secure Bcrypt hash for your Master Key. To maintain strict OpSec, execute the generator strictly via CLI:
```bash
php /var/www/html/deaddrop/password-generator.php
```
Copy the green cryptographic hash outputted to your terminal. Then, open the configuration file:
```bash
nano /var/www/html/deaddrop/db.php
```
Locate the `$config` array at the top. Paste your generated hash into the `'admin_hash'` variable. Next, insert your complete subfolder endpoint into the `'node_url'` variable. You can also optionally enable the Telegram Bridge here for passive security intrusion alerts:
```php
'node_url'   => 'http://your_onion_address.onion/deaddrop',

// Optional Telegram Bridge Config:
'tg_on'      => false, // Change to true to enable
'tg_token'   => 'YOUR_BOT_TOKEN_HERE',
'tg_chat'    => 'YOUR_CHAT_ID_HERE'
```
Save and exit.

#### PHASE 7: The Autonomous Heartbeat (Cron Jobs)
DeadDrop's backend runs autonomously in the background. To guarantee strict OpSec permissions and prevent cron environment failures, you MUST bind the scheduler to the web server's user (`www-data`) and use absolute execution paths. 

Open the restricted cron editor:
```bash
sudo crontab -u www-data -e
```
Paste these two target lines at the bottom to maintain syndication couriers and the rotational backup system:
```bash
# Pull data from active radar and execute Ephemeral Sweeper every 1 hour:
0 * * * * /usr/bin/php /var/www/html/deaddrop/worker.php >> /var/www/html/deaddrop/data/worker.log 2>&1

# Execute Hardware eMMC Diet Protocol (7-Day Backup Rotation) daily at midnight:
0 0 * * * /usr/bin/php /var/www/html/deaddrop/offload.php >> /var/www/html/deaddrop/data/offload.log 2>&1
```

**Congratulations. Your Quantum-Vault Hardened Sovereign Node is now online.**

---

### 📡 HOW TO FOLLOW OTHER NODES (SYNDICATION)
To subscribe to another peer's timeline, simply navigate to the **[ RADAR ]** Command Center (`radar.php`) on your node and enter their fully-qualified `.onion` endpoint. 

*Example of a valid peer target:*
```text
http://peer_onion_address_here.onion/deaddrop
```
You can assign them a custom Petname (`@alias`). The system features an **Anti-Duplicate Guard**, which strictly prevents you from accidentally routing private messages to the wrong node by reusing petnames.

Once added, the background courier will asynchronously pull their updates. If the target node also appends your URL to their radar, a `[🤝 Mutual]` badge will automatically manifest in your Command Center.

---

### 🤝 CONTRIBUTING
Feel free to fork, submit PRs, or open issues. Just remember the absolute doctrine: **Keep it static, keep it Tor-native, and strictly zero JS.**

*Developed by [jeannesbryan](https://github.com/jeannesbryan) - We are ready for the quantum dawn.*