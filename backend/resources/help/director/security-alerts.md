---
title: Security alerts & monitoring
category: Compliance
order: 32
---

# Security alerts & monitoring

KiddieTrac watches the audit log for suspicious sign-in activity and records it under **Settings → Security alerts** (platform administrators).

## What is detected

- **Brute force** — many failed logins from one IP address in a short window.
- **MFA hammering** — repeated failed two-factor attempts on one account.
- **Credential stuffing** — many failed logins targeting a single account.

Each detection is recorded with a severity, the subject (IP or account), and a plain-English description, and can be marked **resolved** once you've reviewed it.

## Behind the scenes

A scheduled job scans the audit log every 15 minutes. Every failed sign-in, MFA failure, and password change is written to a tamper-evident audit trail — the same evidence a security auditor looks for.
