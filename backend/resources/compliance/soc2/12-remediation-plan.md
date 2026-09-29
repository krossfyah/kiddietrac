# Remediation Plan

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Baseline date | 2026-09-29 |
| Owner | Anthony Hosein, Security Officer |
| Tracking | Reviewed at each quarterly security review; progress noted in the Status column |

## Purpose

This plan lists every Gap and Partial control from 11-control-matrix.md and every risk treatment from 07-risk-assessment.md. Each item has an owner and a target date.

## Target windows

| Window | Target date |
|---|---|
| 30 days | 2026-10-29 |
| 60 days | 2026-11-28 |
| 90 days | 2026-12-28 |

The owner for every item is Anthony Hosein (AH) unless stated otherwise. With one person responsible for all of it, the dates are commitments that management has to protect. They should not be allowed to slip quietly.

## Priority 1: by 2026-10-29 (30 days)

These items close the highest risks. An auditor would expect them to be in place before the Type I "as of" date.

| Ref | Item | Closes | Done when | Target | Status |
|---|---|---|---|---|---|
| RM-01 | Enforce MFA **on the server** (API middleware) for platform_admin, agency_admin and centre_director, and remove the platform_admin exemption. Turn on MFA for GitHub, cPanel, the domain registrar and every vendor console | AC-06, AC-08; R-04, R-13 | A direct API call from an admin without MFA is refused (tested); screenshots of MFA on each console; enrolment report showing 100% of admin role rows | 2026-10-29 | Open |
| RM-02 | Encrypted **off-site** copy of the nightly backup, including uploaded media. Use public-key encryption with the private key kept off the host. Keep 14 daily and 3 monthly copies | AV-04, AC-22 (backups); R-03, R-11 | Off-site listing shows daily files; failures raise alerts | 2026-10-29 | Open |
| RM-04 | Reconcile and commit the **138 uncommitted production changes**. Deploy only from git from then on. Add a weekly drift check that alerts when the production tree is not clean | AC-23, CM-01; R-06 | `git status` on production is clean; drift-check job appears in the schedule list | 2026-10-29 | Open |
| RM-07 | Management approves this pack (v1.0). Hold the first **quarterly security review** with minutes. Record written acceptance of residual risks | CE-01, CE-02, RA-03, RA-04, CA-02, CE-03 (compensating review), AV-01 | Signed version table; minutes filed | Approval 2026-10-29; first review by 2026-12-28 | Open |
| RM-08 | Finish the access-review sign-off on the Compliance evidence screen and perform the **first quarterly access review**. Disable or justify the **2 admin accounts that have never logged in** | AC-13; R-08 | Signed Access review CSV stored with its date | 2026-10-29 | Open (feature in progress) |
| RM-10 | Protect `main`: require pull requests for security-relevant paths. Turn on Dependabot, secret scanning and code scanning. Add a minimal CI workflow (`php -l`, migrations, auth and tenant tests) | CM-04, CM-05, OP-01; R-05 | Branch protection screenshot; first green CI run; alert dashboards turned on | 2026-10-29 (CI tests extended by 2026-12-28) | Open |
| RM-11 | External **uptime monitoring** of `/health` and the portal login every 5 minutes, alerting at least two contacts | OP-03; R-09 | Monitor configuration and a test alert | 2026-10-29 | Open |
| RM-14 | Get written confirmation from GoDaddy about **at-rest encryption** of shared MySQL storage and the file system, and confirm the data-centre region | AC-22; R-02, R-11 | Written reply filed with the vendor records | 2026-10-29 | Open |
| RM-17a | Create the **incident and breach register** (kept at least 24 months). File the 2026-09-20 probe with its evidence and the RROSH reasoning | OP-05; PIPEDA record keeping | Register exists with the first entry | 2026-10-29 | Open |
| RM-18 | Complete and record annual **security awareness and secure-development training**. Sign the acceptable-use acknowledgement. Complete the device checklist | CE-04, CE-05 | Training record; signed acknowledgement; checklist | 2026-10-29 | Open |
| RM-19 | Create the **infrastructure access register** (cPanel, SSH, database, GitHub, registrar, vendor consoles) and the **ops change log** | AC-15, CM-07 | Both documents in the repository | 2026-10-29 | Open |

## Priority 2: by 2026-11-28 (60 days)

| Ref | Item | Closes | Done when | Target | Status |
|---|---|---|---|---|---|
| RM-03 | **First documented restore test** from the off-site copy into a separate database. Record the duration and check it against the RTO target | AV-05; R-03 | Restore record with timing and row-count comparison | 2026-11-28 | Open |
| RM-05 | **Schedule `tenant:check`** (daily) with an alert on failure, and run it in CI | MO-04; R-07 | Schedule list entry; output history; CI step | 2026-11-28 | Open |
| RM-06 | Name a **backup person**. Build a sealed break-glass kit: hosting and registrar access, `APP_KEY`, and the backup private key. Write a recovery runbook. Add a second alert recipient | MO-03, BC-01; R-01 | Named person; kit custody record; runbook; alert recipient list showing two contacts | 2026-11-28 | Open |
| RM-09 | Record role changes with actor, time and before/after values in `audit_logs`. Add `updated_at` / `updated_by` to role assignments | AC-14; R-08 | Test role change appears in the `audit_logs` export | 2026-11-28 | Open |
| RM-12 | Approve a **default retention schedule** (08 section 4.2). Offer it at onboarding. Record each existing agency's decision and turn on the purge where agreed | AC-17, CO-02; R-10 | Retention settings per agency; purge counts in `audit_logs` | 2026-11-28 | Open |
| RM-13 | Set and enforce **`audit_logs` and `email_logs` retention** (proposed 24 and 12 months) | CO-03; R-10 | Retention job appears in the schedule list | 2026-11-28 | Open |
| RM-16 | Complete the **sub-processor register**: DPAs on file, regions confirmed, and the IP geolocation provider named. Collect SOC 2 or ISO reports for GoDaddy and GitHub. **Publish the sub-processor list** | VM-01, VM-02, PR-03, AC-16; R-12 | Every "DPA on file" box resolved; list published | 2026-11-28 | Open |
| RM-21 | Write the customer responsibilities into the customer terms and the system description | CI-05 | Updated terms | 2026-11-28 | Open |
| RM-25 | Create the **privacy request log** and procedure (30-day response, identity verification) | PR-02 | Log in use | 2026-11-28 | Open |

## Priority 3: by 2026-12-28 (90 days)

| Ref | Item | Closes | Done when | Target | Status |
|---|---|---|---|---|---|
| RM-15 | Set up a **staging environment** with non-production data for pre-release testing | CM-06; R-05 | Staging URL; deployment doc updated | 2026-12-28 | Open |
| RM-17b | Run the first **tabletop exercise** of the incident response plan (scenario: cross-agency data exposure) | OP-07 | Exercise record with findings | 2026-12-28 | Open |
| RM-20 | **Hosting assessment**: compare staying on GoDaddy shared hosting with a VPS or managed host, preferably in Canada. Cover isolation, peak capacity, at-rest encryption and logging | AV-01; R-02 | Written decision approved by management | 2026-12-28 | Open |
| RM-22 | Draft the **SOC 2 system description**, including processing-integrity specifications. Choose an independent CPA firm and agree the Type I "as of" date | PI-01; readiness | Draft description; engagement letter | 2026-12-28 | Open |
| RM-23 | Decide on **field-level encryption** for the most sensitive child fields (health, custody, incident notes) | AC-22; R-11 | Decision record; implementation plan if approved | 2026-12-28 | Open |
| RM-24 | Create the **key inventory with rotation dates**; rotate the database and vendor credentials that are overdue | 10 section 3.3 | Inventory showing a last-rotated date for every key | 2026-12-28 | Open |

## After 90 days (ongoing)

- A quarterly access review, restore test and security review. The next ones are due by 2026-12-28, then every quarter.
- Annual training, policy review, risk assessment and vendor review.
- Recruit or contract a second engineer to do independent code review. No date is set; this depends on the business. It is the long-term fix for R-01 and R-05.

## Readiness summary

The auditor should not be engaged for a Type I "as of" date until **all Priority 1 and Priority 2 items are closed**. Those items cover the controls an auditor would most likely report as design exceptions:

- server-side MFA;
- off-site backups and a restore test;
- production matching git;
- the access review;
- change review and CI;
- uptime monitoring;
- key-person cover.
