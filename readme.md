# > DEADDROP_ 🏴‍☠️
**The Tor-Native Asynchronous Social Protocol (Nano-Pub)**

![DeadDrop Logo](https://img.shields.io/badge/Status-Underground-00ff66?style=for-the-badge&logo=tor&logoColor=7D4698&color=110818)
![PHP](https://img.shields.io/badge/PHP-FastCGI-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-WAL_Mode-003B57?style=for-the-badge&logo=sqlite)

DeadDrop is an extreme, static-first, and zero-JS social syndication protocol designed for Tor networks and low-end hardware. It operates on the custom **Nano-Pub** protocol, turning your server into a "Sovereign Node" without the bloat of traditional federated networks.

---

### ⚠️ DISCLAIMER: CASUAL PROJECT AHEAD
> **Please Read Before Deploying!**
> This is a passionate **hobby project** built during my free time. It is **NOT** a professional, enterprise-grade software audited by cybersecurity firms. The codebase is highly experimental and designed for tinkering, learning, and having fun in the darknet ecosystem. Use it at your own risk. Expect bugs, raw PHP scripts, and CLI-based interventions. 

---

### ⚙️ THE ARCHITECTURE: ZERO-PUSH, ZERO-JS
Unlike ActivityPub (Mastodon) that forces real-time, heavy two-way server communications, DeadDrop reverses the paradigm:
1. **Static-First:** You post to your timeline. The engine generates a highly optimized `outbox.json`. That's it. It costs 0% CPU when visitors read your feed.
2. **Deferred Interaction:** The timeline is strictly built by a background worker (`worker.php`) running via Cron Job. It silently pulls data from the `.onion` nodes you follow and drops them into your local SQLite database.
3. **Pure Torminal UI (Mobile Ready):** The frontend is strictly built with HTML and CSS, fully responsive for touch devices. **Zero JavaScript.** It is designed to work flawlessly on Tor Browser's "Safest" mode.
4. **Isolated Command Center:** Dedicated `radar.php` dashboard for managing network syndication and peers without cluttering the main timeline.
5. **Stateless Nano-Paging:** Infinite timeline rendering managed entirely by PHP and SQLite `OFFSET`, ensuring browsers never crash from DOM overload.
6. **Burner DMs & E2EE:** Asymmetric cryptography using Libsodium. Messages flagged as burners are eradicated from the local database in the exact millisecond they are rendered.
7. **Auto-Scaling Hashcash Defense:** Incoming network knocks to the gateway are guarded by a dynamic SHA-256 Proof-of-Work puzzle. The difficulty scales exponentially during DDoS attempts to burn botnet CPU.
8. **Rotational Auto-Backup:** Built-in daily `tar.gz` archiver with strict 7-day retention to protect host eMMC while ensuring node recoverability.
9. **Optional Telegram Bridge:** Built-in silent API triggers to notify your device of new encrypted DMs or valid network knocks.

---

### 🚀 COMPREHENSIVE INSTALLATION GUIDE
DeadDrop is designed for extreme efficiency and can run on headless Linux environments like a 256MB RAM NAT VPS or an Armbian Set-Top Box. Follow these exact steps to build your node from scratch.

#### PHASE 1: System Prep & Autostart Armor
First, update your system and install the required packages (including `nano` for editing).
```bash
apt update && apt upgrade -y
apt install nano nginx php-fpm php-sqlite3 php-curl sqlite3 tor curl libimage-exiftool-perl git -y
```

*Note: Libsodium (E2EE cryptography) is required by DeadDrop but is already compiled directly into the PHP core for versions 7.2 and above, so no separate package is needed.*

*Note: If your hosting provider pre-installed `apache2`, it will conflict with Nginx. Kill it permanently by running:*
```bash
systemctl stop apache2
systemctl disable apache2
```

To guarantee that Nginx and Tor automatically resurrect whenever your node reboots, enforce global autostart immediately:
```bash
sudo systemctl enable --now nginx tor
```

#### PHASE 2: Extreme RAM Tuning (Crucial for Low-End Hardware)
To prevent Out-Of-Memory (OOM) crashes on devices with limited RAM, we must enforce a strict diet.

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
Check your PHP version by typing `php -v` (e.g., 7.4, 8.2). Open the pool config for your version:
```bash
# Adjust '7.4' based on your actual PHP version
nano /etc/php/7.4/fpm/pool.d/www.conf
```
Press `Ctrl+W` to find `pm = ` and change the settings to this exact block:
```ini
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 200
```
Save and exit. Restart both services to apply the diet:
```bash
systemctl restart nginx
systemctl restart php7.4-fpm  # Adjust version if needed
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
   sudo systemctl start tor
   sudo systemctl status tor
   ```

***

#### PHASE 4: Subfolder Deployment & Nginx Bridge
**Crucial Architectural Note:** DeadDrop is explicitly deployed inside a subfolder (`/var/www/html/deaddrop`) rather than the absolute root. This isolates your timeline strictly to `yourdomain.onion/deaddrop`, leaving the primary root `/var/www/html` wide open for you to construct a personalized landing page.

Pull the DeadDrop codebase directly into the subfolder and apply correct web server permissions:
```bash
git clone https://github.com/jeannesbryan/deaddrop.git /var/www/html/deaddrop
chown -R www-data:www-data /var/www/html/deaddrop
chmod -R 775 /var/www/html/deaddrop
```

Create the Nginx routing block:
```bash
nano /etc/nginx/sites-available/deaddrop
```
Paste the following configuration *(replace the `.onion` address and PHP version accordingly)*:
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
        fastcgi_pass unix:/run/php/php7.4-fpm.sock; # Adjust PHP version here
    }

    # Brutally block public access to sensitive DeadDrop subdirectories
    location ~ ^/deaddrop/(data|keys)/ {
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

#### PHASE 5: Identity & Telegram Configuration
```bash
nano /var/www/html/deaddrop/db.php
```
Locate the `$config` array at the top. Insert your complete subfolder endpoint into the `'node_url'` variable. You can also optionally enable the Telegram Bridge here for silent mobile notifications:
```php
'node_url'   => 'http://your_onion_address.onion/deaddrop',

// Optional Telegram Bridge Config:
'tg_on'      => false, // Change to true to enable
'tg_token'   => 'YOUR_BOT_TOKEN_HERE',
'tg_chat'    => 'YOUR_CHAT_ID_HERE'
```
Save and exit.

#### PHASE 6: The Autonomous Heartbeat (Cron Jobs)
DeadDrop's backend runs autonomously in the background. Open your cron editor:
```bash
crontab -e
```
Paste these two target lines at the bottom to maintain syndication workers and the rotational backup system:
```bash
# Pull data from radar and run TTL Sweeper every 1 hour:
0 * * * * php /var/www/html/deaddrop/worker.php >> /var/www/html/deaddrop/data/worker.log 2>&1

# Execute Global Auto-Backup (7-Day Rotation) every day at midnight (00:00):
0 0 * * * php /var/www/html/deaddrop/offload.php >> /var/www/html/deaddrop/data/offload.log 2>&1
```

**Congratulations. Your Sovereign Node is now fully autonomous in the darknet.**

---

### 📡 HOW TO FOLLOW OTHER NODES (SYNDICATION)
To subscribe to another peer's timeline, simply navigate to the **[ RADAR ]** Command Center (`radar.php`) on your node and enter their fully-qualified `.onion` endpoint. 

*Example of a valid peer target:*
```text
http://peer_onion_address_here.onion/deaddrop
```
You can assign them a custom Petname (`@alias`). The system features an **Anti-Duplicate Guard**, which strictly prevents you from accidentally routing E2EE messages to the wrong node by reusing petnames.

Once added, the background worker will periodically pull their updates. If the target node also adds your URL to their radar, a `[🤝 Mutual]` badge will automatically appear in your Command Center.

---

### 🤝 CONTRIBUTING
Feel free to fork, submit PRs, or open issues. Just remember the golden rule: **Keep it light, keep it Tor-native, and absolutely no JS.**

*Developed by [jeannesbryan](https://github.com/jeannesbryan) - See you in the dark.*