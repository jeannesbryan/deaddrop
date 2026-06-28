# DeadDrop Security Notes

DeadDrop is experimental software and has not been independently audited. This document describes the intended security posture, known limitations, and wording that should be used when describing the project.

---

## Use Accurate Language

Prefer these terms:

- “experimental”
- “best-effort hardening”
- “Libsodium-based private drop prototype”
- “Tor-native pull syndication”
- “post-quantum placeholder”
- “not audited”
- “not formal zero-knowledge”

Avoid these terms unless the implementation has been independently verified:

- “military-grade”
- “mathematically impervious”
- “absolute security”
- “zero clearnet leaks”
- “total immunity”
- “post-quantum secure”
- “zero-knowledge”
- “forensics-proof”

---

## Current Security Design

### Authentication
Admin actions require a bcrypt-verified master password. Recent hardening moves unlock state to a short-lived server-side session instead of placing the raw password in hidden form fields.

### Sessions
When configured with `/run/deaddrop-sessions`, PHP session files live on tmpfs. This reduces disk persistence but does not protect against a compromised host.

### Private Drops
Private drops use a Libsodium-based envelope. Incoming private drops are intended to remain ciphertext at rest until unlocked. Outgoing local copies may retain plaintext for the sender view unless strict mode is implemented later.

### Public Outbox
`outbox.json` is public by design. It must not contain private plaintext. Current hardening sanitizes split-ledger content before export and writes the file atomically.

### Media
Public media remains public. Private media attachments are disabled until encrypted attachment support exists.

### Peer Validation
Production deployments should accept only valid Tor v3 `.onion` hosts. Localhost peers should remain disabled except in lab environments.

### Worker Limits
Remote `outbox.json` responses should be capped to reduce memory-exhaustion attacks. Large remote feeds should be skipped or capped per cycle.

### Storage
SQLite data, backups, and config secrets should live outside the webroot. Nginx should block helper PHP files and sensitive folders even if they are accidentally placed under the public tree.

---

## Known Limitations

- No professional audit has been performed.
- The post-quantum layer is not real ML-KEM/Kyber; it is a placeholder field.
- There is no formal protocol proof.
- The host OS, PHP runtime, web server, Tor daemon, and browser remain trusted components.
- A stolen master password compromises admin access and may decrypt local data.
- Traffic correlation is out of scope.
- `shred` and SQLite `secure_delete` are best-effort and do not guarantee forensic erasure on flash storage, SSD wear leveling, snapshots, or backups.
- Telegram bridge usage creates third-party metadata exposure even when routed through Tor.
- Public posts and public media are intentionally public.

---

## Recommended Production Defaults

```php
'allow_local_peers' => false,
'tg_on' => false,
'backup_include_config' => true, // only if backups are private/encrypted/off-webroot
```

Recommended filesystem layout:

```text
/var/www/html/deaddrop/      public app
/var/lib/deaddrop/           SQLite database
/var/backups/deaddrop/       private backups
/etc/deaddrop/config.php     config/secrets
/run/deaddrop-sessions/      tmpfs sessions
```

---

## Future Hardening Ideas

- Real ML-KEM implementation through a reviewed library.
- Signed public posts and key pinning.
- Encrypted private media attachments.
- Strict mode that never stores outgoing private plaintext locally.
- Encrypted backups by default.
- Per-peer rate limits and quarantine.
- Schema versioning for `outbox.json`.
- Security regression tests for outbox sanitization.

