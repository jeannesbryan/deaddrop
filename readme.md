# > DEADDROP_ 🏴‍☠️
**The Tor-Native Asynchronous Social Protocol (Nano-Pub)**

![DeadDrop Logo](https://img.shields.io/badge/Status-Underground-00ff66?style=for-the-badge&logo=tor&logoColor=7D4698&color=110818)
![PHP](https://img.shields.io/badge/PHP-FastCGI-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-WAL_Mode-003B57?style=for-the-badge&logo=sqlite)

DeadDrop is an extreme, static-first, and 100% Tor-native social syndication platform designed to run on low-end hardware (like a 2GB RAM Armbian Set-Top Box or Raspberry Pi)[cite: 9]. It operates on the custom **Nano-Pub** protocol, turning your server into a "Sovereign Node" without the bloat of traditional federated networks[cite: 9].

---

### ⚠️ DISCLAIMER: CASUAL PROJECT AHEAD
> **Please Read Before Deploying!**
> This is a passionate **hobby project** built during my free time[cite: 9]. It is **NOT** a professional, enterprise-grade software audited by cybersecurity firms[cite: 9]. The codebase is highly experimental and designed for tinkering, learning, and having fun in the darknet ecosystem[cite: 9]. Use it at your own risk[cite: 9]. Expect bugs, raw PHP scripts, and CLI-based interventions[cite: 9]. 

---

### ⚙️ THE ARCHITECTURE: ZERO-PUSH, ZERO-JS
Unlike ActivityPub (Mastodon) that forces real-time, heavy two-way server communications, DeadDrop reverses the paradigm[cite: 9]:
1. **Static-First:** You post to your timeline[cite: 9]. The engine generates a highly optimized `outbox.json`[cite: 9]. That's it[cite: 9]. It costs 0% CPU when visitors read your feed[cite: 9].
2. **Deferred Interaction:** The timeline is strictly built by a background worker (`worker.php`) running via Cron Job[cite: 9]. It silently pulls data from the `.onion` nodes you follow and drops them into your local SQLite database[cite: 9].
3. **Pure Torminal UI:** The frontend is strictly built with HTML and CSS[cite: 9]. **Zero JavaScript.** It is designed to work flawlessly on Tor Browser's "Safest" mode[cite: 9].
4. **Darknet Exclusive:** The syndication engine enforcing Tor SOCKS5 proxy (`127.0.0.1:9050`)[cite: 9]. It actively rejects clearnet domains[cite: 9].

---

### 🚀 INSTALLATION (Debian / Armbian)
DeadDrop requires a headless Linux setup with Nginx, PHP-FPM, SQLite3, and Tor Daemon[cite: 9].

```bash
# 1. Install dependencies
sudo apt update
sudo apt install nginx php-fpm php-sqlite3 php-curl sqlite3 tor curl -y

# 2. Clone the repo to your web directory
git clone [https://github.com/jeannesbryan/deaddrop.git](https://github.com/jeannesbryan/deaddrop.git) /var/www/deaddrop
sudo chown -R www-data:www-data /var/www/deaddrop
sudo chmod -R 775 /var/www/deaddrop

# 3. Setup Tor Hidden Service in /etc/tor/torrc
# HiddenServiceDir /var/lib/tor/deaddrop/
# HiddenServicePort 80 127.0.0.1:80

# 4. Setup Cron Job for the Worker
# Run `crontab -e` and add the following line to pull data every 1 hour:
# 0 * * * * php /var/www/deaddrop/worker.php >> /var/www/deaddrop/data/worker.log 2>&1
```
*For the complete configuration guide, please refer to the [Wiki/Docs](#).*[cite: 9]

---

### 🗺️ ROADMAP (DeadDrop 2.0)
The v1.0 establishes the global timeline[cite: 9]. The next major updates will focus on covert communications and server longevity[cite: 9].

- [ ] **Phase 1: Private Drops (E2EE Direct Messaging)**
  Implementing asymmetric cryptography (Libsodium)[cite: 9]. Your node will be able to lock a message using a target's Public Key, drop it in the public `outbox.json`, and only their Private Key can decrypt and read it[cite: 9].
- [ ] **Phase 2: Data Offloading (Cold Backup System)**
  To prevent low-end servers (STBs) from running out of eMMC memory, an automated background script will compress media/timeline data older than 30 days into `.tar.gz` and push it to an external Cloud API, keeping the local SQLite extremely lean[cite: 9].

---

### 🤝 CONTRIBUTING
Feel free to fork, submit PRs, or open issues[cite: 9]. Just remember the golden rule: **Keep it light, keep it Tor-native, and absolutely no JS.**[cite: 9]

*Developed by [jeannesbryan](https://github.com/jeannesbryan) - See you in the darknet.*[cite: 9]