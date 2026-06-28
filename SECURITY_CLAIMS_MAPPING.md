# DeadDrop Security Claims Mapping

This file maps old marketing-style wording to safer wording for README, changelog, and GitHub descriptions.

| Old wording | Safer wording |
|---|---|
| Post-Quantum Armor | Post-quantum placeholder / reserved field |
| ML-KEM Kyber security | ML-KEM/Kyber is not implemented yet |
| Zero-Knowledge RAM Vault | Short-lived server-side unlock session and ciphertext-oriented inbox design |
| Plaintext is NEVER written | Incoming private drops are stored as ciphertext; outgoing local copies may retain plaintext for sender convenience |
| Total immunity against traffic analysis | Padding reduces simple size-based leakage but does not prevent traffic correlation |
| Absolute data vaporization | Best-effort deletion using SQLite secure_delete and file shredding where supported |
| Zero clearnet leaks | Tor routing is used for configured peer/Telegram requests, but deployment mistakes or integrations may still leak metadata |
| Military-grade | Uses standard Libsodium primitives in an unaudited application protocol |
| Mathematically impervious | Encrypted with modern primitives; protocol is not audited |
| Forensics-proof | Not forensics-proof, especially on flash storage, backups, snapshots, or compromised hosts |
| 0% JavaScript | Designed to work without JavaScript; verify no inline JS remains before claiming strict no-JS |

