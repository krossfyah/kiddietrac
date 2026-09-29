# Encryption and Key Management Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC6.1, CC6.7 (also C1.1) |

## 1. Purpose

This policy sets out where KiddieTrac uses encryption to protect data in transit and at rest, and how it manages the keys.

## 2. Scope

It covers:

- traffic to kiddietrac.com, the portal and the API;
- the database and backups;
- application secrets and third-party credentials;
- email and push delivery.

## 3. Policy statements

### 3.1 In transit (CC6.7)

| Control | State |
|---|---|
| HTTPS for the portal, API and marketing site | Implemented. Separate Let's Encrypt certificates are issued through acme.sh |
| Certificate renewal | acme.sh runs a renewal check every day |
| Expiry monitoring | A daily check emails when a certificate has fewer than 21 days left (`cert-check.log`) |
| HSTS | Sent by the security-headers middleware |
| Other headers | X-Frame-Options, X-Content-Type-Options: nosniff, Referrer-Policy, Permissions-Policy, Cross-Origin-Resource-Policy and Content-Security-Policy |
| Traffic to sub-processors | HTTPS/TLS APIs |
| Database connection | Local to the host. Confirm with GoDaddy whether it is a local socket or a network connection |

**Minimum:** TLS 1.2 or higher. The TLS versions and cipher suites should be checked against the live hosts and recorded as evidence. **Status: not yet recorded.**

### 3.2 At rest (CC6.1)

| Data | State |
|---|---|
| MFA (TOTP) secrets | Encrypted at the application level (Laravel encryption, using `APP_KEY`) |
| SMS, email, payment and accounting credentials | Encrypted at the application level; write-only in the portal |
| Staff payout details | Encrypted at the application level |
| Passwords | One-way hashes (Laravel hashing); never stored in a reversible form |
| Children and family personal information | **Not encrypted at field level.** Stored in plaintext in MySQL |
| MySQL storage volume | **Encryption at rest not confirmed** with GoDaddy (shared hosting) |
| Database backups | gzip only; **not encrypted**; kept on the same host in a 0700 directory |
| Android app local storage | The app is a WebView wrapper. Tokens are held in web storage on the device and protected by the operating system |

**Required:**

1. Get written confirmation from GoDaddy about at-rest encryption of the shared MySQL storage and the file system.
2. Encrypt backups before they are copied off-site. Use GPG or age with a public key, so the private key is never kept on the host.
3. Assess field-level encryption for the most sensitive child fields, such as health, custody and incident notes. Weigh it against search and reporting needs, and record the decision.

### 3.3 Key management

| Key | Where kept | Rotation | Access |
|---|---|---|---|
| Laravel `APP_KEY` (encrypts the secrets above) | `.env` on the production host, outside the webroot | On suspected compromise. Rotating it needs a re-encryption step, which must be documented first | Owner only |
| Database credentials | `.env` | Every year, and when someone with access leaves | Owner only |
| Vendor API keys | Encrypted in the database or in `.env` | Every year, on suspected exposure, and when someone with access leaves | Owner only |
| TLS private keys | acme.sh directory on the host | Every 60 to 90 days, automatically | Owner only |
| SSH keys for the host | Owner's device (encrypted disk, key passphrase) | Every year | Owner only |
| Backup encryption key (planned) | Private key **off the host**: in the password manager and sealed in the break-glass kit | Every year | Owner and backup person |

**Rules:**

- Never commit keys or `.env` to git. Check with a secret scan (GitHub secret scanning, or an equivalent tool).
- Keep an escrow copy of `APP_KEY` and the backup private key in the break-glass kit (see 05). Losing `APP_KEY` makes the encrypted secrets unreadable.
- Keep a key inventory, like the table above, with the date each key was last rotated. **Status: rotation dates are not recorded yet.**

## 4. Responsibilities

The Security Officer owns every key, the rotation schedule and the break-glass escrow.

## 5. Exceptions

Any exception needs written approval (01 section 5).

## 6. Review

This policy is reviewed every year, and when hosting or encryption methods change.
