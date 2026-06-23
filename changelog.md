# 📜 DEADDROP // THE CHRONICLE OF DEVELOPMENT
*The evolutionary progression of the Sovereign Nano-Pub Node (v1.0 to v6.0).*

---

## [v1.0] - THE GENESIS
DeadDrop v1.0 marks the birth of the Nano-Pub protocol. This genesis version establishes the absolute foundation for a decentralized, zero-JS, Tor-native social network designed specifically to run seamlessly on extreme low-power hardware.

### ⚙️ PHASE 1: The Nano-Pub Engine
The core syndication protocol is officially alive. It introduces asynchronous, static-first social broadcasting. Operating via an `outbox.json` architecture ensures it costs 0% CPU overhead when visitors read your timeline.

### 🕵️‍♂️ PHASE 2: Autonomous Worker Patrol
Data synchronization is handled entirely in the background. The `worker.php` script executes silently via a Cron Job, routing all outbound requests strictly through the Tor SOCKS5 proxy to protect host anonymity.

### 🖥️ PHASE 3: Zero-JS Torminal UI
A fully interactive, terminal-inspired user interface built from the ground up using pure CSS hacks. It is inherently compatible with the Tor Browser's "Safest" security tier by eliminating all JavaScript dependencies.

### 💾 PHASE 4: SQLite Database Optimization
Built for low-RAM environments, the SQLite3 engine is hardcoded to utilize WAL (Write-Ahead Logging) mode alongside an automated background garbage collection protocol to maintain memory efficiency.

### 🔐 PHASE 5: Administrative Cryptography
All node commands, transmissions, and configurations are locked behind strict Bcrypt-based authentication to prevent unauthorized tampering.

---

## [v2.0] - THE CYPHERPUNK UPDATE
DeadDrop v2.0 elevates the Nano-Pub protocol from a public broadcast feed into a militarized, cryptographic darknet node. This version introduces true End-to-End Encryption (E2EE), automated cold-storage compression, and aggressive metadata stripping to guarantee extreme hardware longevity.

### 🔐 PHASE 6: Asymmetric Cryptography (E2EE)
The Nano-Pub engine has been armed with military-grade Libsodium encryption to facilitate zero-intercept private drops.
* **Autonomous Keypairs:** Upon initialization, the database natively generates a strictly localized Public/Private keypair, locking it inside `node_identity`.
* **Public Key Broadcasting:** The node now exposes its Public Key via `outbox.json`, allowing verified peers to initiate encrypted handshakes.
* **Sealed Payloads:** Transmissions targeting a specific `.onion` address are sealed via `sodium_crypto_box_seal()`, rendering the ciphertext mathematically impervious to exit-node sniffing.
* **Background Decryption:** The daemon (`worker.php`) captures `E2EE:` prefixed ciphertexts from peer outboxes and silently unlocks them locally using the host's Private Key.

### 🗄️ PHASE 7: Cold Storage Archiving (`offload.php`)
To protect constrained set-top box (STB) memory from data bloating, a localized cold-backup pipeline has been integrated.
* **30-Day Sweep:** A dedicated CLI script scans the SQLite timeline for signals and attached media older than 30 days.
* **RAM-Safe Compression:** Obsolete signals are compiled into raw JSON and wrapped alongside physical media into `.tar.gz` archives using native Linux shell execution rather than PHP memory streams.
* **Sovereign Vault:** Compressed archives are dropped strictly into a localized `/backup/` directory, maintaining absolute zero-cloud reliance.
* **Vacuum Shrinking:** Following archival verification, the script purges physical media from the eMMC and executes a SQLite `VACUUM` command to physically reclaim active disk sectors.

### 🛡️ PHASE 8: Hardened OpSec & Metadata Sanitization
A massive security sweep to close application-level vulnerabilities and protect node perimeter integrity.
* **Zero-EXIF Enforcement:** Uploaded imagery is routed through `exiftool (-all= -overwrite_original)` to brutally scrub GPS coordinates, camera models, and software tags before touching the storage.
* **Anti-OOM Shield:** Enforced a strict 2MB ceiling on cURL worker extractions to prevent memory-exhaustion attacks from bloated foreign nodes.
* **Anti-Ping Flooding:** Capped the gateway queue (`ping.php`) at 200 entries to absorb database spam.
* **SSRF Eradication:** The ping endpoint strictly drops internal loopback requests (`localhost` / `127.0.0.1`), exclusively accepting handshakes from darknet `.onion` domains.
* **Strict Media Validation:** The syndication worker now ruthlessly drops external media links that fail `http://` or `https://` explicit schema validation.

---

## [v3.0] - THE DARKNET EVOLUTION
DeadDrop v3.0 completes the transformation of the Nano-Pub protocol into a fully autonomous, self-cleaning, and spam-resistant darknet ghost node. This major update introduces isolated secure messaging, automated data self-destruction (TTL), global wipe capabilities, and a brutal cryptographic perimeter defense against gateway flooding.

### 🔐 PHASE 9: Identity Isolation & Petname Routing
The End-to-End Encryption (E2EE) protocol has been drastically overhauled for extreme OpSec and human-readable routing.
* **Isolated Secure Inbox:** Decrypted private transmissions are strictly decoupled from public broadcasts. A dedicated `inbox` database table and a secure UI view (`dm.php`) have been established for isolated intelligence gathering.
* **Pure Tor Petnames:** Say goodbye to memorizing 56-character `.onion` strings. The syndication engine now fully supports `@alias` targeting (e.g., `@johndoe`). The engine autonomously resolves the alias against your local radar to fetch the correct Public Key for encryption.
* **Smart Courier Routing:** `worker.php` now acts as an autonomous sorting facility, automatically routing decrypted `E2EE:` payloads into the secure Inbox while directing standard plaintext to the public Timeline.

### ⏳ PHASE 10: Ephemeral Drops & Global Tombstone Protocol
Data persistence is a security liability. v3.0 gives authors absolute control over the lifecycle of their transmitted signals to protect active eMMC disk health.
* **Time-to-Live (TTL) Engine:** Broadcasters can now tag their signals to auto-destruct after 1 Hour, 24 Hours, or 7 Days.
* **Automated Sweeper:** The `worker.php` daemon initiates a localized garbage collection sequence at the start of every cycle, hunting down and irrevocably purging expired ephemeral messages and attached media from disk.
* **Tombstone Protocol (Global Delete):** Authors can now trigger a network-wide purge of their local posts. Activating this sequence destroys local media files, overwrites the payload with a `[☠️ SIGNAL DESTROYED BY AUTHOR]` tombstone, and forces all synchronized foreign nodes to obliterate their mirrored copies upon their next pull.

### 🛡️ PHASE 11: Hashcash Perimeter Defense
The gateway has been militarized to drop malicious traffic and prevent CPU overload from ping flooding.
* **Proof-of-Work (PoW) Gatekeeper:** The `ping.php` endpoint now demands a cryptographic toll. Senders must solve a SHA-256 Hashcash puzzle (target difficulty: 4 leading zeros) before the node accepts the network knock.
* **Replay Attack Mitigation:** A strict 5-minute timestamp expiration window is enforced on all incoming pings to prevent botnets from recycling old, valid cryptographic hashes.
* **Integrated UI Miner:** The Node Inspector (`profile.php`) has been equipped with a local JavaScript-free mining engine. When manually knocking on a foreign node, the server automatically computes the required nonce to solve the target's PoW puzzle before transmitting the request.

---

## [v4.0] - THE PARANOID POCKET NODE
DeadDrop v4.0 brings the darknet to your fingertips while introducing the most paranoid communication protocol yet. This major update delivers a fully responsive, Tor-safe mobile interface, introduces self-eradicating Burner DMs, and transforms the index into a Sovereign Command Center—all while maintaining a strict 0% JavaScript footprint.

### 🔥 PHASE 12: Burner DMs (Zero-JS Self-Destruct)
Standard TTL relies on scheduled cron jobs. Burner Mode introduces true, instantaneous stateless eradication for extreme OpSec.
* **Read-and-Destroy Execution:** Messages flagged as burners are permanently eradicated from the local SQLite database in the exact millisecond they are rendered to the screen. 
* **Zero-JS Operation:** The destruction protocol is executed purely by the PHP backend during the page request cycle, requiring absolutely no frontend JavaScript timers.
* **Cryptographic Tagging:** Secure payloads are now injected with a dedicated `E2EE-BURNER:` prefix during the Libsodium encryption phase to notify the receiving worker.
* **Visual Triage:** Decrypted burner drops are clearly marked with a stark `[🔥 BURNER DROP - DESTROYED UPON READING]` warning inside the isolated inbox before they vaporize on the next refresh.

### 📱 PHASE 13: Mobile Torminal UI (Zero-JS Responsive)
Operating a sovereign node from a mobile device is now seamlessly integrated without compromising Tor Browser's "Safest" security level.
* **Flexbox & Media Queries:** The Torminal CSS framework has been heavily overhauled using pure CSS3 `@media` queries to stack elements dynamically on smaller screens.
* **Touch-Optimized Layout:** Giant input fields, full-width transmission buttons, and expanded padding ensure flawless thumb navigation.
* **Anti-Zoom Scaling:** Typography and form inputs are rigidly scaled to `16px` to actively prevent mobile operating systems (iOS/Android) from forcing auto-zoom behaviors.
* **Stateless Toggles:** All mobile navigation tabs and interactive menus rely entirely on CSS pseudo-classes, completely eliminating the need for client-side scripts.

### 📡 PHASE 14: Sovereign Command Center (`index.php` Overhaul)
The primary timeline interface has evolved from a passive broadcast feed into an active Node Management Dashboard.
* **Integrated Radar Synchronizer:** Operators can now input new peer endpoints (`.onion`) and assign custom Petnames (`@alias`) directly from the home timeline without SSH terminal intervention.
* **Strict Alias Sanitization:** The backend automatically intercepts registration strings to strip accidental `@` prefixes, whitespace, and illegal characters before committing to the database.
* **Inline Peer Disconnection:** Active radar targets are unrolled into a dedicated home management list, equipped with one-click `[ DEL ]` execution to instantly sever syndication ties on the fly.

---

## [v5.0] - THE SOVEREIGN MATRIX
DeadDrop v5.0 evolves the node from a passive communication tool into a fully hardened, autonomous fortress. This massive update introduces a dedicated management dashboard, dynamic DDoS defenses, infinite stateless paging, and silent push notifications—all while fiercely maintaining our strict 0% JavaScript and low-RAM philosophy.

### 📡 PHASE 15: Dedicated Radar Command Center (`radar.php`)
Node management has been completely decoupled from the primary timeline to establish a pristine, dedicated command dashboard.
* **Gateway Knockers Log:** The radar now intercepts and displays inbound `ping_queue` activity. Operators can instantly inspect which foreign `.onion` nodes successfully bypassed the local Hashcash defense and bind them to the radar with a single click.
* **Anti-Duplicate Guard (Satpam):** The backend now enforces strict uniqueness checks, rejecting duplicate Petnames (`@alias`) or target URLs to permanently prevent Libsodium E2EE key-collision traps.
* **Cascading Renames:** Editing a peer's Petname inside the Radar now triggers a cascading SQL update, instantly refactoring their legacy author names across both your public timeline and isolated inbox.

### 🛡️ PHASE 16: Hashcash Auto-Scaling Defense
The `ping.php` gateway is now self-aware and capable of autonomous, active mathematical retaliation against gateway spam.
* **Dynamic Proof-of-Work:** Prior to demanding a SHA-256 verification, the gateway evaluates the live density of the local SQLite `ping_queue`.
* **Anti-DDoS Nuke:** If an inbound flood is detected (>150 queued knocks), the required cryptographic difficulty automatically escalates from 65,536 computations (`0000`) to over 16,777,216 computations (`000000`), melting the attacker's botnet CPU while keeping the host instance perfectly stable.

### 🤝 PHASE 17: Stateless Mutual Badge
Operators can now discover bidirectional syndication ties without violating the zero-push, pull-only architecture.
* **Silent Reconnaissance:** During the hourly cron job cycle, `worker.php` quietly parses the `outbox.json` of your tracked peers. If it detects your node's explicit URL string inside their feed, it updates your local database.
* **Zero-Handshake UI:** A vibrant green `[🤝 Mutual]` badge natively renders adjacent to their Petname inside the Radar dashboard. Zero live API queries, zero SOCKS5 latency overhead.

### 🧭 PHASE 18: Stateless Nano-Paging
Operating a sovereign instance archiving thousands of historical drops will no longer trigger DOM overload or Tor Browser memory leaks.
* **Stateless Navigation (0% JS):** Both the primary broadcast feed (`index.php`) and the secure inbox (`dm.php`) now utilize pure backend pagination driven by explicit URL parameters (`?page=`) paired with SQLite `LIMIT` and `OFFSET` calculations.
* **Strict Memory Ceiling:** The DOM strictly caps rendering to 100 signals per view, guaranteeing instant sub-second page loads even on Tor's "Safest" security tier.

### 📦 PHASE 19: Rotational Auto-Backup
The legacy 30-day data deletion algo has been permanently retired in favor of an elite, self-pruning cold storage protocol.
* **Core Asset Archiving:** Every midnight, `offload.php` executes a native Linux shell command to securely bundle your physical SQLite database, all media attachments, `outbox.json`, and `db.php` master keys into a single compressed `.tar.gz` archive.
* **7-Day eMMC Diet:** To actively protect host SD card storage from overflowing, the script enforces a rigid 7-day rolling window, autonomously pruning the 8th oldest backup file from the vault upon verification.

### 📲 PHASE 20: Optional Telegram Bridge
Designed for mobile operators who require instantaneous intelligence alerts without keeping a Tor SOCKS5 daemon actively open.
* **Silent cURL Triggers:** The `worker.php` and `ping.php` background daemons can now execute hidden clearnet API dispatches directly to a designated Telegram Bot.
* **OpSec Triage:** You receive an immediate push notification to your phone the exact second a new E2EE Burner DM is successfully unlocked or a valid Hashcash knock hits the gateway.
* **Absolute Airgap Switch:** Fully optional. If `'tg_on'` is configured to `false` inside `db.php`, the entire bridge block is bypassed at the PHP interpreter level, ensuring zero clearnet packets ever leave the host.

---

## [v6.0] - THE QUANTUM VAULT
DeadDrop v6.0 radically overhauls the cryptographic architecture and network topology into a future-proof military standard. This massive update transforms the node from a secure communication relay into an absolute black box. We are introducing Post-Quantum layered encryption, traffic-obfuscating digital noise injection, a Zero-Knowledge RAM Vault, and an asynchronous parallel worker engine that multiplies sync speeds without straining system memory.

### 🛡️ PHASE 21: Deniable Uniform Padding (Anti-Traffic Analysis)
Operational Security (OpSec) vulnerabilities related to packet size Traffic Analysis at Tor relays are now permanently sealed.
* **Cryptographic Noise Injection:** The `publish.php` engine now forcefully injects randomized digital noise into the plaintext payload BEFORE it is locked by Libsodium.
* **Absolute Block Size:** All private messages broadcasted across the network are now manipulated to possess an absolute size in multiples of 4096 bytes (4KB). Sending a single letter "A" or a full page of classified intel now produces the exact same ciphertext size, rendering network observers completely blind.

### ⚡ PHASE 22: SOCKS5 Persistent Circuit Pooling
The `worker.php` courier engine has been completely rebuilt utilizing a `curl_multi_init()` architecture, resulting in extreme-speed data extraction.
* **Asynchronous Extraction:** The courier no longer knocks on peer doors sequentially. The system now holds a single Tor SOCKS5 tunnel open (Keep-Alive) and pulls up to 100 `outbox.json` files synchronously in parallel.
* **Zero Daemon Strain:** Tor Daemon processor load is drastically reduced as repetitive TCP handshakes and teardowns have been completely eliminated.

### 🔮 PHASE 23: ML-KEM / Kyber Hybrid Wrap (Post-Quantum Armor)
The standard E2EE encryption protocol has been retired and replaced by a **Hybrid KEM Envelope (3-Layer Vault)** to anticipate the destruction of classical cryptography by future Quantum Computers (Shor's Algorithm).
* **XChaCha20-Poly1305 Payload:** Message payloads are now locked using a high-speed, single-use ephemeral symmetric key.
* **Double KEM Encapsulation:** The symmetric key is sealed inside a Libsodium X25519 Public Key (Layer 1), and that capsule is sealed *again* by a Quantum Key (Layer 2).

### 🧠 PHASE 24: Zero-Knowledge RAM Vault & Double-Ledger Engine
Absolute data-at-rest protection has been enforced for the Direct Message (DM) ecosystem.
* **Volatile Extrapolation:** The SQLite database now strictly stores raw, unbreakable ciphertexts. Plaintext is NEVER written to the eMMC. The inbox only decrypts messages temporarily in volatile RAM when the node operator inputs the master secure key.
* **Split-Ledger Broadcast:** Outgoing private messages natively strip their plaintext variants before broadcasting the cryptographic payload to `outbox.json`, ensuring zero data leaks.

### 🧭 PHASE 25: Autonomous Path Healing (Self-Healing Topology)
Network transmission failures (HTTP 404) caused by truncated or malformed peer URLs are now resolved autonomously.
* **Dynamic Probing:** The profile inspector engine now possesses an autonomous brain. If it detects a peer address missing the `/deaddrop` subfolder route, it will silently reconstruct and heal the URL in the background before firing the Hashcash Proof-of-Work cannon.

---

## [v7.0] - THE BLACK SITE
DeadDrop v7.0 transitions the node from a public syndication relay into an invisible darknet bunker. This update introduces Asymmetric Visibility, zero-leak database queries, and POST-powered stateless sessions, enforcing an absolute 0% JavaScript doctrine across all error-handling mechanisms.

### 🕳️ PHASE 26: The Void (UI Amputation)
The public-facing architecture has been militarized into a "Black Site".
* **Restricted Zone:** The primary broadcast timeline (`index.php`), command center (`radar.php`), and secure inbox (`dm.php`) now amputate all navigational UI elements from unauthorized visitors, displaying only a stark authentication terminal.
* **Auto-Lock Protocol:** Executing critical operations (publishing payloads or triggering global deletions) now autonomously purges the active session, instantly slamming the vault shut upon transmission.

### 🛡️ PHASE 27: Zero-Leak Data Extraction
Database queries are now cryptographically gated at the PHP interpreter level.
* **Query Blockade:** If the master key is absent, the backend explicitly refuses to execute `SELECT` queries against the SQLite database, ensuring zero CPU cycles are wasted on unauthorized reconnaissance and neutralizing passive memory leaks.

### 🎭 PHASE 28: Asymmetric Profile Visibility
The node inspector (`profile.php`) now functions as a cryptographic chameleon based on the target host.
* **Public Manifesto:** When visitors view your sovereign node, it acts as a read-only public blog. All operational levers (`SYNC`, `KNOCK`) are safely hidden.
* **Foreign Intel Lockdown:** If a visitor attempts to use your node to inspect a foreign entity, the UI instantly transforms into a restricted Black Site, preventing third parties from mapping your decentralized social graph.

### 🧭 PHASE 29: POST-Powered Stateless Paging
Pagination across the Timeline and Inbox has been completely rebuilt to eliminate traditional `GET` parameter vulnerabilities.
* **Cookie-less Sessions:** Navigating through pages now utilizes hidden `POST` forms to seamlessly carry the decrypted state across the void without ever relying on browser cookies or PHP sessions.

### 💀 PHASE 30: Absolute Zero-JS Doctrine
A final forensic sweep has eradicated all lingering JavaScript dependencies from the codebase.
* **Terminal Error Screens:** Legacy `<script>alert()</script>` fallbacks in the Tombstone deletion protocol (`delete.php`) have been purged and replaced with native HTML/CSS `terminal_error()` outputs, guaranteeing flawless execution on Tor Browser's "Safest" security tier.

---

## [v8.0] - ABSOLUTE AIRGAP & STEALTH TOPOLOGY
DeadDrop v8.0 seals the final operational security leaks by decoupling background processes from predictable chronological patterns and isolating all clearnet API triggers through the Tor proxy.

### ⏱️ PHASE 31: Cron Jitter (Anti-Timing Analysis)
Predictable cron execution times can be mapped by data center traffic analysis.
* **Randomized Sleep:** Background daemons (`worker.php` and `offload.php`) now inject a randomized mathematical delay (1 to 600 seconds) prior to execution, causing the node's network signature to blend flawlessly with background noise.

### 🧤 PHASE 32: Airgapped Telegram Bridge
The node no longer exposes its host IP to clearnet API servers when dispatching push notifications.
* **SOCKS5 Proxied API:** The optional Telegram Bridge now forcefully routes all `curl` requests through the local Tor daemon (`127.0.0.1:9050`), guaranteeing that your host machine remains 100% cloaked from Telegram's server logs.

---