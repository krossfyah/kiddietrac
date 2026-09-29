# Business Continuity and Backup Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Security Officer |
| Criteria | A1.1, A1.2, A1.3, CC7.5, CC9.1 |

## 1. Purpose

This policy sets out how KiddieTrac protects the platform's availability and recovers data and service after a disruption.

## 2. Scope

The policy covers:

- the production MySQL database;
- application code and configuration;
- uploaded media and documents;
- TLS certificates;
- scheduled jobs;
- the people and accounts needed to recover all of these.

## 3. Policy statements

### 3.1 Capacity (A1.1)

- The platform runs on GoDaddy shared cPanel hosting in North America.
- Known limits: in the 8 to 9 a.m. peak, the shared plan's process and entry limits cause some requests to fail with HTTP 508 or take about 10 seconds.
- Capacity is reviewed at the quarterly security review, using the slow and failed request logs.
- The decision on whether to move to a dedicated host or VPS is tracked as risk R-02.

### 3.2 Backups (A1.2)

| Item | Current state |
|---|---|
| What | Full MySQL database dump |
| When | Nightly at 03:30 (server scheduler) |
| Integrity | Each dump is compressed with gzip and passes a gzip integrity test |
| Retention | Last 14 dumps kept |
| Location | **The same host as production**, in a directory outside the webroot with permissions 0700 |
| Failure handling | A failed backup raises a security alert to the platform admins |
| Visibility | Settings → Backups (platform admin only) lists the backups; there is no download route |
| Off-site copy | **None** |
| Code | GitHub, but production has uncommitted changes (see 03) |
| Uploaded media | **Not confirmed** to be in the nightly backup. Must be confirmed and documented |

**Required state:**

1. An encrypted **off-site** copy of each nightly backup, held with a different provider or in a different account from the production host. Keep at least 14 daily and 3 monthly copies.
2. Uploaded media backed up on the same schedule.
3. Backup encryption keys stored separately from the backups (see 10).

### 3.3 Restore testing (A1.3)

- Perform a restore test **every quarter**: restore the latest backup to a separate database, check that the table counts and a sample of records match production, and record how long it took.
- Record each test: date, backup file, result, duration, and any problems found.
- **Status: no restore test has been documented.** The first test is due by the date in 12.

### 3.4 Recovery targets

These are **targets**. They have not been demonstrated yet.

| Target | Value | Basis |
|---|---|---|
| RPO (maximum data loss) | 24 hours | Nightly backup at 03:30 |
| RTO (time to restore service) | 24 hours for database or application failure on the existing host; 72 hours for loss of the host | Not tested. Loss of the host means losing the same-host backups as well, so **the RPO for a host-loss event cannot be met today** |

Once off-site backups exist and a restore test has been timed, the targets will be confirmed or revised.

### 3.5 Continuity scenarios

| Scenario | Response | Readiness |
|---|---|---|
| Database corruption or bad migration | Restore the latest nightly dump; replay changes from `audit_logs` where practical | Partial: restore not tested |
| Host outage (GoDaddy) | Wait for the provider, and post status to customers by email | Partial: no status page and no external uptime monitor |
| Host loss or account compromise | Rebuild on a new host from GitHub and an off-site backup | **Gap:** no off-site backup, and production code differs from git |
| TLS certificate failure | acme.sh renews automatically every day, and an expiry email is sent when fewer than 21 days remain | Implemented |
| Sub-processor outage (email, SMS, payments) | Fallback email transports exist; SMS can go through Twilio or Telnyx | Partial |
| Owner unavailable (illness, accident) | A named backup person with sealed break-glass credentials and a written runbook | **Gap** |

### 3.6 Monitoring availability

- The only health check is a `/health` endpoint. **No external uptime monitoring exists.**
- Required: an external monitor that checks `/health` and the portal login page every 5 minutes and alerts at least two contacts.

## 4. Responsibilities

- **Security Officer:**
  - runs backups and restore tests;
  - maintains the off-site copy;
  - keeps the break-glass runbook up to date.
- **Backup person, once named:** holds the sealed credentials and knows the runbook.

## 5. Exceptions

Any exception needs written approval (01 section 5). The absence of an off-site copy is not an approved exception. It is a gap.

## 6. Review

This policy is reviewed every year, after every restore test that fails, and when hosting changes.
