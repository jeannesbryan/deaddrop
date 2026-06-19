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
5. **E2EE Ready:** Utilizes Libsodium for End-to-End Encrypted asynchronous messaging between nodes.

---

### 🚀 INSTALLATION (Debian / Armbian)
DeadDrop requires a headless Linux setup with Nginx, PHP-FPM, SQLite3, Libsodium, and Tor Daemon.

```bash
# 1. Install dependencies
sudo apt update
sudo apt install nginx php-fpm php-sqlite3 php-sodium php-curl sqlite3 tor curl exiftool -y

# 2. Clone the repo to your web directory
git clone https://github.com/jeannesbryan/deaddrop.git /var/www/deaddrop
sudo chown -R www-data:www-data /var/www/deaddrop
sudo chmod -R 775 /var/www/deaddrop

# 3. Setup Tor Hidden Service in /etc/tor/torrc
# HiddenServiceDir /var/lib/tor/deaddrop/
# HiddenServicePort 80 127.0.0.1:80

# 4. Setup Cron Jobs for Worker & Cold Storage
# Run `crontab -e` and add the following lines:

# Pull data from radar every 1 hour:
0 * * * * php /var/www/deaddrop/worker.php >> /var/www/deaddrop/data/worker.log 2>&1

# Execute Cold Storage offloading every day at midnight (00:00):
0 0 * * * php /var/www/deaddrop/offload.php >> /var/www/deaddrop/data/offload.log 2>&1
```

---

### 🤝 CONTRIBUTING
Feel free to fork, submit PRs, or open issues. Just remember the golden rule: **Keep it light, keep it Tor-native, and absolutely no JS.**

*Developed by [jeannesbryan](https://github.com/jeannesbryan) - See you in the darknet.*