# Control Matrix

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| As of | 2026-09-29 |
| Owner | Anthony Hosein, Security Officer |
| Framework | AICPA Trust Services Criteria (2017, revised points of focus 2022) |

This matrix maps each KiddieTrac control to the criteria it supports. It also records the control's status and the evidence an auditor would be shown. Every **Partial** and **Gap** row appears in 12-remediation-plan.md, under the RM reference given.

**Status key:**

- **Implemented:** the control exists and evidence is available.
- **Partial:** the control exists with a known weakness.
- **Gap:** the control does not exist.

"AH" means Anthony Hosein.

## Summary

| Status | Count |
|---|---|
| Implemented | 35 |
| Partial | 15 |
| Gap | 24 |
| **Total** | **74** |

## CC1: Control environment

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| CE-01 | CC1.1, CC5.3 | Management approves the information security policies and reviews them every year | Gap (draft only, not approved) | Signed version table in 00-README.md; approval record (RM-07) | AH |
| CE-02 | CC1.2, CC4.1 | Quarterly security review of risks, incidents, access, backups and remediation, with written minutes | Gap | Dated review minutes (RM-07) | AH |
| CE-03 | CC1.3 | Roles and responsibilities defined; segregation-of-duties limits documented with compensating controls | Partial (documented; segregation not possible) | 01 section 3.3 | AH |
| CE-04 | CC1.4, CC2.2 | Annual security awareness and secure-development training | Gap | Training completion records (RM-18) | AH |
| CE-05 | CC1.1, CC1.5 | Acceptable use acknowledgement signed at onboarding and every year | Gap | Signed acknowledgements (RM-18) | AH |

## CC2: Communication and information

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| CI-01 | CC2.1 | Security events recorded in `audit_logs` (every API call plus authentication and security events, agency-stamped) | Implemented | `audit_logs` export (about 32k rows since 2026-06-29), sample of event types | AH |
| CI-02 | CC2.3 | Public vulnerability reporting channel | Implemented | https://kiddietrac.com/vulnerability-reporting; /.well-known/security.txt | AH |
| CI-03 | CC2.3, P1.1 | Versioned Privacy Policy with recorded user acceptance | Implemented | Privacy Policy (last updated 2026-08-14); consent/acceptance records export | AH |
| CI-04 | CC2.3 | Product changes communicated to customers | Implemented | CHANGELOG; in-app "What's new"; weekly digest email log | AH |
| CI-05 | CC2.3 | Customer (complementary user entity) responsibilities documented in customer terms and the system description | Partial | Customer terms; system description (RM-21) | AH |

## CC3: Risk assessment

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| RA-01 | CC3.1, CC3.2 | Annual risk assessment with scored register and treatment plans | Implemented (first edition 2026-09-29) | 07-risk-assessment.md | AH |
| RA-02 | CC3.3 | Fraud risk considered (payouts, admin misuse) | Implemented | 07, risk R-14 | AH |
| RA-03 | CC3.4 | Risk reassessed on material change (hosting, vendor, feature, team) | Partial (trigger defined; no record of it being run) | Updated register with change notes | AH |
| RA-04 | CC3.2 | Management formally accepts residual risk | Gap | Signed acceptances (RM-07) | AH |

## CC4: Monitoring activities

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| MO-01 | CC4.1, CC7.2 | Security alert job every 15 minutes (brute force by IP, MFA hammering, credential stuffing, backup failure) emails active platform admins | Implemented (one recipient; see MO-03) | Crontab/schedule list; alert emails; 2026-09-20 alert | AH |
| MO-02 | CC4.1, CC7.2 | Alert acknowledge and clear actions are audited; clearing is server-enforced to acknowledged rows | Implemented | `audit_logs` entries for acknowledge and clear | AH |
| MO-03 | CC4.2 | Deficiencies reported to a second person able to act | Gap | Alert recipient list with two contacts (RM-06) | AH |
| MO-04 | CC4.1 | Tenant-isolation probe (`tenant:check`) runs on a schedule, with alert on failure | Partial (exists; manual only) | Schedule list; `tenant:check` output history (RM-05) | AH |

## CC5: Control activities

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| CA-01 | CC5.1, CC5.2 | Controls selected against risks and automated in the platform where possible | Implemented | This matrix; 07 | AH |
| CA-02 | CC5.3 | Policies put into practice through documented procedures | Partial (policies drafted; procedures such as restore and access review not yet performed) | 01 to 10; procedure records | AH |

## CC6: Logical and physical access

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| AC-01 | CC6.1 | Role-based access with defined roles; auditor role read-only, enforced by middleware | Implemented | Role list; middleware code; role counts by role | AH |
| AC-02 | CC6.1 | Every request scoped to the active agency and validated against the user's memberships | Implemented | Middleware code; `tenant:check` run output | AH |
| AC-03 | CC6.1 | Password policy: at least 8 characters with 4 character classes; 90-day maximum age with 14/7/1-day reminders; no reuse within 6 months | Implemented | Validation code; `password_history` table; schedule list (reminder job) | AH |
| AC-04 | CC6.1 | Lockout after 5 failures in 15 minutes (5-minute lock); admin unlock screen, audited | Implemented | Config; `audit_logs` unlock events (2 in the last 30 days) | AH |
| AC-05 | CC6.1, CC6.6 | Login and reset throttles (10/min per email and IP, 40/min per IP, forgot 3/min, reset 10/min) | Implemented | Route middleware list | AH |
| AC-06 | CC6.1 | MFA (TOTP, encrypted secret) enforced on the server for all admin roles, including platform_admin | Partial (enforced in the browser only; platform_admin exempt; 3/116 users enrolled) | Middleware code; MFA enrolment report (RM-01) | AH |
| AC-07 | CC6.1 | Passkeys (WebAuthn) available on the web | Implemented (not available in the Android app) | Settings → Security; passkey count (2 users) | AH |
| AC-08 | CC6.1 | MFA on infrastructure and vendor consoles (GitHub, cPanel, registrar, payments, email, SMS, Firebase) | Gap (not verified) | Screenshots of MFA settings per console (RM-01) | AH |
| AC-09 | CC6.2 | Temporary passwords force a change at first login (`must_change_password` plus middleware) | Implemented | Middleware code; user flag sample | AH |
| AC-10 | CC6.2 | User provisioning through the invite flow by agency admins | Implemented | Invite records; `audit_logs` user-create events | Agency admins / AH |
| AC-11 | CC6.2, CC6.3 | Tokens of deactivated users revoked every 15 minutes; offboarding and departure jobs run daily | Implemented | Crontab/schedule list; job logs | AH |
| AC-12 | CC6.1 | API tokens expire after 30 days and expired tokens are pruned daily; idle browser sessions time out | Implemented | Config; schedule list; token table age query | AH |
| AC-13 | CC6.2, CC6.3 | Quarterly access review with sign-off (privileged roles, dormant accounts, infrastructure accounts) | Gap (feature in progress; no review recorded; 2 admins never logged in) | Compliance evidence screen → Access review CSV with sign-off (RM-08) | AH |
| AC-14 | CC6.3 | Role changes recorded with who, when, and before/after values | Partial (only `created_at`; partly audited) | `audit_logs` role-change events (RM-09) | AH |
| AC-15 | CC6.2 | Register of infrastructure and vendor-console access | Gap | Access register (RM-19) | AH |
| AC-16 | CC6.4 | Physical security of data centre | Implemented (carved out to GoDaddy) | GoDaddy assurance documentation (RM-16) | GoDaddy |
| AC-17 | CC6.5 | Data disposed of when no longer needed (purge job, backup rotation) | Partial (purge enabled for 0 agencies) | Retention settings per agency; purge `audit_logs` counts (RM-12) | AH |
| AC-18 | CC6.6 | Abusive source IPs can be denied at the web server | Implemented | Web server deny rules; 2026-09-20 incident record | AH |
| AC-19 | CC6.7 | TLS on all endpoints; HSTS and security headers (X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, CORP, CSP) | Implemented | Response header capture; SSL test output | AH |
| AC-20 | CC6.7 | TLS certificates auto-renew daily (acme.sh), with an expiry email when fewer than 21 days remain | Implemented | acme.sh cron; `cert-check.log` | AH |
| AC-21 | CC6.1, C1.1 | Secrets (MFA, SMS, email, payment, accounting credentials, payout details) encrypted at the application level; credentials write-only in the UI | Implemented | Model casts/code; database sample showing ciphertext | AH |
| AC-22 | CC6.1, C1.1 | Personal information encrypted at rest (host volume and/or field level) | Gap (host encryption unconfirmed; no field encryption) | GoDaddy written confirmation; decision record (RM-14, RM-23) | AH |
| AC-23 | CC6.8 | Protection against unauthorised software: production deployed only from reviewed git commits; drift detection | Gap (138 uncommitted production changes) | Clean `git status` on production; drift-check alerts (RM-04) | AH |

## CC7: System operations

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| OP-01 | CC7.1 | Vulnerability management: dependency and secret scanning on the repository | Gap | GitHub Dependabot / code-scanning / secret-scanning settings and alerts (RM-10) | AH |
| OP-02 | CC7.2 | Failed and slow requests logged; application error logs kept 14 days | Implemented | Log files; retention config | AH |
| OP-03 | CC7.2, A1.1 | External uptime monitoring with alerts | Gap (only a `/health` endpoint) | Monitor configuration and alert history (RM-11) | AH |
| OP-04 | CC7.3, CC7.4 | Written incident response plan with severity levels, roles and steps | Implemented (as of this pack; approval pending) | 04-incident-response-plan.md | AH |
| OP-05 | CC7.4 | Incident and breach register kept 24 months (PIPEDA) | Gap | Breach/incident register incl. the 2026-09-20 entry (RM-17) | AH |
| OP-06 | CC7.4 | Incident handled per procedure: 2026-09-20 credential probe detected by alert, IPs denied, forced password reset | Implemented (single occurrence) | Alert email; `audit_logs` export; deny rule; reset record | AH |
| OP-07 | CC7.5 | Post-incident review and annual tabletop exercise | Gap | Review and tabletop records (RM-17) | AH |

## CC8: Change management

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| CM-01 | CC8.1 | Changes tracked in git (GitHub, private) | Partial (about 650 commits in 90 days, but production not fully in git) | Commit history; production `git status` (RM-04) | AH |
| CM-02 | CC8.1 | Changes documented (CHANGELOG, "What's new", deployment doc) | Implemented | CHANGELOG; whats-new.json; deployment doc | AH |
| CM-03 | CC8.1 | Front-end deploys bump the service-worker cache version so clients receive the new assets | Implemented | Service-worker CACHE history in git | AH |
| CM-04 | CC8.1 | Independent review or pull request for security-relevant changes; branch protection | Gap (no PRs or reviews) | PR history; branch protection settings (RM-10) | AH |
| CM-05 | CC8.1 | Automated tests / CI gate before deployment | Gap | CI workflow runs (RM-10) | AH |
| CM-06 | CC8.1 | Changes tested in a non-production environment | Partial (Test Agency data in production; no staging) | Test Agency QA notes; staging environment (RM-15) | AH |
| CM-07 | CC8.1 | Infrastructure and configuration changes logged | Gap | Ops change log (RM-19) | AH |

## CC9: Risk mitigation

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| BC-01 | CC9.1 | Business continuity plan covering host loss and loss of the key person | Partial (plan written; break-glass and backup person missing) | 05; break-glass kit record (RM-06) | AH |
| VM-01 | CC9.2 | Sub-processor register with data shared, tier and DPA status | Partial (register drafted; DPAs not confirmed) | 06 register; DPA folder (RM-16) | AH |
| VM-02 | CC9.2 | Annual review of high-tier vendors' assurance reports | Gap | Collected SOC 2/ISO reports (RM-16) | AH |

## A1: Availability

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| AV-01 | A1.1 | Capacity monitored (slow and failed request logs; peak load review) | Partial (logs exist; no documented review) | Quarterly review minutes (RM-07) | AH |
| AV-02 | A1.2 | Nightly database backup at 03:30 with gzip integrity check; 14 retained; directory 0700 | Implemented | Settings → Backups list; crontab/schedule list | AH |
| AV-03 | A1.2 | Backup failure raises an alert | Implemented | Alert job code; test alert | AH |
| AV-04 | A1.2 | Encrypted off-site backup copy (database and uploaded media) | Gap (backups on the same host only) | Off-site storage listing (RM-02) | AH |
| AV-05 | A1.3 | Quarterly restore test, documented | Gap | Restore test record with timing (RM-03) | AH |

## C1: Confidentiality

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| CO-01 | C1.1 | Data classification defined; Test Agency used for all testing | Implemented (as of this pack) | 08 section 4.1; QA practice | AH |
| CO-02 | C1.2 | Retention schedule defined and purge enabled per agency | Partial (job exists; 0 agencies enabled; no default schedule) | Retention settings; purge logs (RM-12) | AH / agency admins |
| CO-03 | C1.2 | Audit log retention defined and enforced | Gap (`audit_logs` never purged) | Retention rule and job (RM-13) | AH |

## PI1: Processing integrity (light)

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| PI-01 | PI1.2, PI1.3 | Input validation on API requests (Laravel request validation) | Partial (validation used; no documented processing specifications) | Request validation code sample; processing spec (RM-22) | AH |
| PI-02 | PI1.4 | Output delivery recorded: each email send outcome written to `email_logs` | Implemented | `email_logs` export | AH |

## P: Privacy (light)

| Control ID | Criteria | Control description | Status | Evidence | Owner |
|---|---|---|---|---|---|
| PR-01 | P2.1, P3.1 | Consent captured: privacy acceptance, opt-in marketing cookies (no advertising cookies), SMS consent per handset | Implemented | Acceptance records; cookie banner; SMS consent table | AH |
| PR-02 | P5.1, P5.2 | Access, correction and deletion requests handled within 30 days and logged | Gap (no request log) | Request log (RM-25) | AH |
| PR-03 | P6.1 | Sub-processors disclosed to customers | Gap | Published sub-processor list (RM-16) | AH |
| PR-04 | P6.3 | Breach notification to the OPC, individuals and agencies per PIPEDA | Implemented (in design, in 04; not yet exercised) | 04 section 8 | AH |

## Notes for the auditor

- **Carved-out sub-service organisation:** GoDaddy (hosting and physical security). Its controls are expected to be covered by its own assurance reports.
- **Complementary user entity controls (agencies):**
  - manage their own users and roles, and deactivate leavers promptly;
  - enrol their administrators in MFA;
  - configure data retention;
  - obtain consents from families;
  - respond to privacy requests where they are the organisation in control.
