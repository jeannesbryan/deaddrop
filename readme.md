# > DEADDROP_ <img src="assets/favicon-32x32.png" width="32" align="center">
**Tor-native asynchronous micro-publishing for low-power nodes.**

![Status](https://img.shields.io/badge/Status-Experimental-00ff66?style=for-the-badge&logo=tor&logoColor=7D4698&color=110818)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-Local_First-003B57?style=for-the-badge&logo=sqlite)
![Tor](https://img.shields.io/badge/Tor-v3_onion_required-7D4698?style=for-the-badge&logo=tor)

DeadDrop is an experimental **Tor-native Nano-Pub node** built with PHP, SQLite, and static `outbox.json` syndication. It is designed for small VPS instances, Armbian boxes, and other low-resource machines where heavy federated stacks are impractical.

The project focuses on a simple idea: publish locally, expose a static feed, and let a background courier pull updates from trusted peers over Tor. The web UI is intentionally minimal and designed to remain usable in restrictive browser settings.

---

## ⚠️ Security Status

DeadDrop is a hobby/learning project and **has not been professionally audited**. Treat it as experimental software. Do not rely on it for life-critical, journalist-source-protection, dissident-safety, or high-risk operational security without an independent security review.

Current security posture:

- Uses Libsodium primitives for private-drop encryption experiments.
- Uses Tor hidden services for network reachability.
- Uses SQLite with local storage and optional off-webroot deployment.
- Uses server-side authentication and short-lived unlock sessions.
- Applies defensive limits against oversized remote `outbox.json` responses.
- Requires strict Tor v3 `.onion` peer validation by default.

Important limitations:

- The “post-quantum” layer is currently a **placeholder/mockup**, not real ML-KEM/Kyber security.
- The project is **not zero-knowledge** in the formal cryptographic sense.
- Outgoing private drops may keep a local sender-side plaintext copy for usability, while the public outbox exports only the encrypted envelope.
- Metadata reduction and deletion features are best-effort, not a guarantee against forensic recovery on all storage media.
- Telegram bridge integration, if enabled, contacts Telegram’s infrastructure through Tor but still creates third-party metadata exposure.

See [`SECURITY_NOTES.md`](SECURITY_NOTES.md) before deploying.

---

## Architecture

DeadDrop uses a pull-based Nano-Pub model:

1. **Local publishing** writes posts into SQLite.
2. **Static export** rebuilds `outbox.json` for public syndication.
3. **Worker sync** periodically pulls peer `outbox.json` files over Tor.
4. **Radar** tracks peers, aliases, public keys, and mutual status.
5. **Inbox** isolates private encrypted drops from the public timeline.

This avoids always-on push fanout. Visitors can fetch `outbox.json` or the public profile without forcing expensive real-time federation behavior.

---

## Features

### Nano-Pub Static Outbox
DeadDrop exports posts into a static `outbox.json`. Public reads are cheap and suitable for low-power hosts.

### Tor v3 Peer Policy
Production mode accepts only valid Tor v3 `.onion` peers by default. Localhost peers are disabled unless explicitly allowed for lab testing.

### Background Courier
`worker.php` pulls peer feeds through Tor SOCKS5 using concurrent cURL requests. Remote responses are capped to reduce memory-exhaustion risk.

### Private Drops
Private drops are encrypted as a Libsodium-based envelope using XChaCha20-Poly1305 for payload encryption and X25519 sealed boxes for key wrapping.

### PQ Placeholder Field
The `pq_public` / `pq_private` fields are reserved for future post-quantum work. Current “PQ” behavior is a structural placeholder and must not be described as real ML-KEM security.

### Private Media Lockdown
Private media attachments are disabled until encrypted-media support exists. Public media remains supported for public posts.

### Atomic Outbox Writes
`outbox.json` is written through a temporary file and `rename()` to reduce the chance of partial/corrupted feed exports.

### Off-Webroot Storage
Recommended deployments keep SQLite data, backups, and secrets outside `/var/www/html`.

### Short-Lived Unlock Sessions
Admin unlock state is stored server-side for a short period instead of carrying the master password in hidden form fields.

### Hashcash Knock Gate
`ping.php` can require a SHA-256 proof-of-work puzzle before accepting peer knocks. This is a throttling tool, not a full DDoS solution.

---

## Recommended Deployment Layout

```text
/var/www/html/deaddrop/      # public PHP app and static assets
/var/www/html/deaddrop/media # public media for public posts
/var/lib/deaddrop/           # private SQLite database
/var/backups/deaddrop/       # private rotating backups
/etc/deaddrop/config.php     # private node config/secrets
/run/deaddrop-sessions/      # tmpfs-backed PHP sessions, if available
```

The public webroot should not contain SQLite databases, backup archives, node secrets, or helper files intended only for inclusion by other PHP scripts.

---

## Live Server File Policy

A clean v10 production node should keep only runtime web files in `/var/www/html/deaddrop`. Helper scripts that are included by PHP may remain in the directory, but they must be blocked from direct browser access by Nginx.

### Keep in the live webroot

```text
/var/www/html/deaddrop/
├── assets/
├── auth.php      # internal auth/session helper; block direct web access
├── db.php        # bootstrap only; reads /etc/deaddrop/config.php; block direct web access
├── delete.php
├── dm.php
├── index.php
├── net.php       # internal network policy helper; block direct web access
├── offload.php   # CLI/cron only; block direct web access
├── outbox.php    # internal outbox rebuild helper; block direct web access
├── ping.php
├── profile.php
├── publish.php
├── radar.php
└── worker.php    # CLI/cron only; block direct web access
```

`outbox.json` is generated by the application and must remain publicly readable because it is the Nano-Pub feed. `media/` may remain public for public-post attachments.

### Keep outside the webroot

```text
/etc/deaddrop/config.php        # actual private config/secrets
/var/lib/deaddrop/deaddrop.sqlite
/var/backups/deaddrop/
/run/deaddrop-sessions/
```

The actual `config.php` should not live in `/var/www/html/deaddrop`. For an open-source repository, publish only `config.example.php`.

### Remove from live server after setup

```text
keygen.php
password-generator.php
migrate.sh
```

`keygen.php` and `password-generator.php` are init-only utilities. `migrate.sh` is only useful when migrating an older node; for a fresh v10 reinstall it is not needed on the live server. If these files are kept in the repository, place them under a `scripts/` or `tools/` directory rather than deploying them to the live webroot.

### Documentation files

```text
README.md
CHANGELOG.md
SECURITY_NOTES.md
SECURITY_CLAIMS_MAPPING.md
```

These are useful for the source repository, but optional on STB/VPS live deployments.

---

## Nginx Configuration: Tor-Only + v10 Hardening

DeadDrop is intended to be served through Tor, with Nginx bound only to localhost. The PHP application can still render public pages and `outbox.json`, but direct browser access to helper scripts, private storage, backup files, SQLite files, and dotfiles must be blocked.

Use this as the combined v10 server block:

```nginx
server {
    listen 127.0.0.1:80;
    server_tokens off;
    client_max_body_size 2M;

    server_name YOUR_ONION_ADDRESS.onion;

    root /var/www/html;
    index index.php index.html;

    # Disable directory listing globally.
    autoindex off;

    # v10: block include-only / CLI-only / secret-bearing PHP files.
    # Keep this block before the generic PHP-FPM handler.
    location ~ ^/deaddrop/(db|auth|net|outbox|worker|offload|keygen|password-generator)\.php$ {
        return 403;
    }

    # v10: block private storage paths if old deployments still contain them.
    # New deployments should keep these outside /var/www/html entirely.
    location ^~ /deaddrop/data/ {
        return 403;
    }

    location ^~ /deaddrop/backup/ {
        return 403;
    }

    location ^~ /deaddrop/keys/ {
        return 403;
    }

    # Defense-in-depth: block accidental database, backup, env, log, and swap files.
    location ~* \.(sqlite|sqlite3|db|bak|backup|old|swp|env|ini|log)$ {
        return 403;
    }

    # Block hidden files and directories such as .git and .env.
    location ~ /\. {
        return 403;
    }

    # Normal public routing.
    location / {
        try_files $uri $uri/ =404;
    }

    # PHP-FPM handler.
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

`outbox.json` must remain public because it is the Nano-Pub feed. The `media/` directory may remain public for public-post attachments. Private DM attachments are disabled until encrypted media support is implemented.

For production, keep SQLite, backups, and `/etc/deaddrop/config.php` outside the webroot; the Nginx blocks above are defense-in-depth for older installs or accidental file placement.


---

## PHP Session Storage on tmpfs

DeadDrop v10 uses short-lived server-side PHP sessions for the unlock flow. This prevents the master password from being carried through hidden form fields.

For low-power nodes and eMMC-based devices, session files should live in `/run` so they are stored on tmpfs instead of persistent storage.

```bash
sudo install -o www-data -g www-data -m 700 -d /run/deaddrop-sessions
echo 'd /run/deaddrop-sessions 0700 www-data www-data -' | sudo tee /etc/tmpfiles.d/deaddrop-sessions.conf
sudo systemd-tmpfiles --create /etc/tmpfiles.d/deaddrop-sessions.conf
```

`auth.php` automatically uses `/run/deaddrop-sessions` when the directory exists and is writable by `www-data`. If the directory is unavailable, PHP falls back to the default session path.

---

## Configuration Notes

Use a private config file outside the webroot:

```php
<?php
return [
    'node_name'   => 'YOUR_NODE_NAME',
    'node_url'    => 'http://your-v3-onion-address.onion/deaddrop',
    'admin_hash'  => 'YOUR_BCRYPT_ADMIN_HASH',
    'max_outbox'  => 50,

    'db_path'     => '/var/lib/deaddrop/deaddrop.sqlite',
    'backup_path' => '/var/backups/deaddrop',
    'backup_retention' => 7,
    'backup_include_config' => true,

    'allow_local_peers' => false,

    'tg_on'       => false,
    'tg_token'    => '',
    'tg_chat'     => ''
];
```

For production, keep:

```php
'allow_local_peers' => false,
```

Only enable localhost peers in an isolated lab.

---

## Private Drop Model

DeadDrop’s private-drop model is experimental and intentionally simple:

```text
plaintext -> optional padding -> XChaCha20-Poly1305 ciphertext
symmetric key -> X25519 sealed box
optional second wrap -> placeholder field, not real PQ security
```

Incoming private drops are stored as ciphertext and decrypted only when the vault is unlocked. Outgoing private drops may keep a local plaintext copy for the sender’s own view, while the public `outbox.json` export strips the plaintext and publishes only the encrypted envelope.

This is not a formal zero-knowledge system.

---

## Threat Model

DeadDrop attempts to reduce risk from:

- casual web crawling,
- accidental clearnet exposure when deployed behind Tor correctly,
- oversized peer feed memory abuse,
- public feed corruption during write interruptions,
- accidental private-media leakage in `outbox.json`,
- basic unauthorized admin access,
- low-grade ping flooding.

DeadDrop does **not** currently protect against:

- a compromised host,
- malicious PHP extensions or OS-level malware,
- a stolen admin password,
- browser compromise,
- traffic correlation by powerful adversaries,
- professional forensic recovery across all storage types,
- cryptographic attacks caused by unaudited protocol design,
- social graph discovery through operational mistakes,
- metadata exposure from optional third-party integrations.

---

## Development Philosophy

DeadDrop prefers:

- boring, local-first components,
- small PHP scripts over heavy services,
- static syndication over real-time push,
- explicit Tor routing,
- low memory use,
- deployability on cheap hardware,
- honest security language over theatrical certainty.

The aesthetic can stay cyberpunk. The security claims should stay precise.

---

## Contributing

Issues, hardening patches, threat-model reviews, and documentation fixes are welcome.

Security-related contributions should clearly state:

- what risk is being reduced,
- what attack scenario is still out of scope,
- whether the patch changes the public `outbox.json` schema,
- whether it affects old nodes or backward compatibility.

---

## Disclaimer

DeadDrop is experimental software. Use it at your own risk. Run it only if you understand the tradeoffs of operating a Tor hidden service, storing secrets on a server, and using unaudited cryptographic application code.

