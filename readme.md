# > DEADDROP_ 🏴‍☠️
**The Tor-Native Asynchronous Social Protocol (Nano-Pub)**

![DeadDrop Logo](https://img.shields.io/badge/Status-Underground-00ff66?style=for-the-badge&logo=tor&logoColor=7D4698&color=110818)
![PHP](https://img.shields.io/badge/PHP-FastCGI-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-WAL_Mode-003B57?style=for-the-badge&logo=sqlite)

DeadDrop is an extreme, static-first, and 100% Tor-native social syndication platform designed to run on low-end hardware (like a 2GB RAM Armbian Set-Top Box or Raspberry Pi). It operates on the custom **Nano-Pub** protocol, turning your server into a "Sovereign Node" without the bloat of traditional federated networks.

---

### ⚠️ DISCLAIMER: CASUAL PROJECT AHEAD
> **Please Read Before Deploying!**
> This is a passionate **hobby project** built during my free time. It is **NOT** a professional, enterprise-grade software audited by cybersecurity firms. The codebase is highly experimental and designed for tinkering, learning, and having fun in the darknet ecosystem. Use it at your own risk. Expect bugs, raw PHP scripts, and CLI-based interventions. 

---

### ⚙️ THE ARCHITECTURE: ZERO-PUSH, ZERO-JS
Unlike ActivityPub (Mastodon) that forces real-time, heavy two-way server communications, DeadDrop reverses the paradigm:
1. **Static-First:** You post to your timeline. The engine generates a highly optimized `outbox.json`. That's it. It costs 0% CPU when visitors read your feed.
2. **Deferred Interaction:** The timeline is strictly built by a background worker (`worker.php`) running via Cron Job. It silently pulls data from the `.onion` nodes you follow and drops them into your local SQLite database.
3. **Pure Torminal UI:** The frontend is strictly built with HTML and CSS. **Zero JavaScript.** It is designed to work flawlessly on Tor Browser's "Safest" mode.
4. **Darknet Exclusive:** The syndication engine enforcing Tor SOCKS5 proxy (`127.0.0.1:9050`). It actively rejects clearnet domains.
5. **Isolated E2EE & Petnames:** Asymmetric cryptography using Libsodium. Secure messages are routed to an isolated inbox using human-readable `@alias` routing instead of 56-character `.onion` strings.
6. **Ephemeral & Tombstone Protocols:** Built-in Time-to-Live (TTL) sweeper and global delete mechanisms to actively protect the host's eMMC from data bloating.
7. **Hashcash Perimeter Defense:** Incoming network knocks to the gateway are guarded by a brutal SHA-256 Proof-of-Work puzzle to automatically drop DDoS attempts and botnet spam.

---

### 🚀 INSTALLATION (Debian / Armbian)
DeadDrop requires a headless Linux setup with Nginx, PHP-FPM, SQLite3, Libsodium, and Tor Daemon.

```bash
# 1. Install dependencies
sudo apt update
sudo apt install nginx php-fpm php-sqlite3 php-sodium php-curl sqlite3 tor curl exiftool -y

# 2. Clone the repo to your web directory
git clone [https://github.com/jeannesbryan/deaddrop.git](https://github.com/jeannesbryan/deaddrop.git) /var/www/deaddrop
sudo chown -R www-data:www-data /var/www/deaddrop
sudo chmod -R 775 /var/www/deaddrop

# 3. Setup Tor Hidden Service in /etc/tor/torrc
# HiddenServiceDir /var/lib/tor/deaddrop/
# HiddenServicePort 80 127.0.0.1:80

# 4. Setup Cron Jobs for Worker & Cold Storage
# Run `crontab -e` and add the following lines:

# Pull data from radar and run TTL Sweeper every 1 hour:
0 * * * * php /var/www/deaddrop/worker.php >> /var/www/deaddrop/data/worker.log 2>&1

# Execute Cold Storage offloading every day at midnight (00:00):
0 0 * * * php /var/www/deaddrop/offload.php >> /var/www/deaddrop/data/offload.log 2>&1
```

---

### 🗜️ EXTREME RAM TUNING (For STB / Raspberry Pi)
If you are deploying DeadDrop on a low-end Set-Top Box or Raspberry Pi with limited RAM, it is highly recommended to enforce these strict resource limits to prevent Out-Of-Memory (OOM) crashes:

**1. Limit Nginx Workers:**
Open `/etc/nginx/nginx.conf` and change the worker processes to 1:
```nginx
worker_processes 1;
```

**2. Enable PHP-FPM Hibernation (Ondemand):**
Open your PHP pool configuration (e.g., `/etc/php/8.2/fpm/pool.d/www.conf` - adjust the version number to match your installed PHP) and apply these exact settings so PHP consumes 0 MB RAM when idle:
```ini
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 200
```

**3. Apply Changes:**
Restart both services to enforce the military-grade diet:
```bash
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
```

---

### 🤝 CONTRIBUTING
Feel free to fork, submit PRs, or open issues. Just remember the golden rule: **Keep it light, keep it Tor-native, and absolutely no JS.**

*Developed by [jeannesbryan](https://github.com/jeannesbryan) - See you in the darknet.*