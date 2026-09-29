# KiddieTrac SOC 2 Type I Readiness Pack

## What this pack is

This pack holds the policies, control descriptions, risk register and remediation plan that KiddieTrac needs before a SOC 2 Type I examination. It is a readiness pack. It is **not** a SOC 2 report, and it does not claim any certification or attestation.

A SOC 2 report can only be issued by an independent CPA firm, after that firm examines KiddieTrac's system description and controls under the AICPA attestation standards. Nothing in this pack replaces that examination.

## How it maps to SOC 2 Type I

A **Type I** report gives an opinion on whether controls are suitably **designed** and **in place at a single point in time** against the Trust Services Criteria (TSC 2017, revised points of focus 2022). A Type II report also tests whether those controls **operated effectively** over a period, usually 3 to 12 months. Type II is out of scope for this pack. Most controls marked Implemented here will need evidence of operation later.

| TSC category | In scope | Where it is covered |
|---|---|---|
| Security (Common Criteria CC1 to CC9) | Yes (required) | 01, 02, 03, 04, 06, 07, 09, 10, 11 |
| Availability (A1) | Yes | 05, 11 |
| Confidentiality (C1) | Yes | 08, 10, 11 |
| Processing Integrity (PI1) | Light coverage only | 11 |
| Privacy (P1 to P8) | Light coverage only | 08, 11 |

**The system in scope:** the KiddieTrac platform. That covers the Laravel API, the web single-page app (portal), the Android app (a WebView wrapper around the portal), the MySQL database, scheduled jobs, and the marketing site at kiddietrac.com where it handles security reporting, privacy notices and consent. Hosting is GoDaddy shared cPanel in North America, and source code is on GitHub (github.com/krossfyah/kiddietrac, private).

## Documents

| # | File | Main criteria |
|---|---|---|
| 00 | 00-README.md | Pack overview |
| 01 | 01-information-security-policy.md | CC1, CC2, CC3, CC5 |
| 02 | 02-access-control-policy.md | CC6.1 to CC6.3, CC6.6 |
| 03 | 03-change-management-policy.md | CC8.1 |
| 04 | 04-incident-response-plan.md | CC7.3 to CC7.5 |
| 05 | 05-business-continuity-and-backup-policy.md | A1.1 to A1.3, CC7.5, CC9.1 |
| 06 | 06-vendor-management-policy.md | CC9.2 |
| 07 | 07-risk-assessment.md | CC3.1 to CC3.4 |
| 08 | 08-data-retention-and-disposal-policy.md | C1.1, C1.2, P4 |
| 09 | 09-acceptable-use-and-security-awareness.md | CC1.4, CC2.2 |
| 10 | 10-encryption-and-key-management.md | CC6.1, CC6.7 |
| 11 | 11-control-matrix.md | All: the control-to-criteria map |
| 12 | 12-remediation-plan.md | Gaps, owners and dates |

Start with **11-control-matrix.md**. It lists each control, its status (Implemented / Partial / Gap) and the evidence an auditor would be shown. Every Gap and Partial item is carried into **12-remediation-plan.md**.

## Organisational context

KiddieTrac has a very small team. One person, Anthony Hosein, is effectively the owner, the engineer and the only platform administrator. The pack is written to fit that reality:

- Several roles below (Security Officer, Change Approver, Incident Lead) are held by the same person. Where SOC 2 expects segregation of duties, the pack names a compensating control or records a gap. It does not pretend the duties are separated.
- The auditor will focus on key-person risk and on the lack of independent review. See 07-risk-assessment.md, risks R-01 and R-05.

## Ownership and review

| Item | Value |
|---|---|
| Document owner | Anthony Hosein, Owner and Platform Administrator (Security Officer) |
| Approver | KiddieTrac management (currently the same person; see 01 section 4) |
| Contact | info@kiddietrac.com |
| Security reports | https://kiddietrac.com/vulnerability-reporting and https://kiddietrac.com/.well-known/security.txt |
| Review cadence | At least annually, and on any material change: new hosting, new sub-processor, new product line, a Severity 1 or 2 incident, or a change in team size |

## Version history

| Version | Date | Author | Change | Status |
|---|---|---|---|---|
| 1.0 | 2026-09-29 | Anthony Hosein | Initial readiness pack | Draft, pending management approval |

## Conventions

- **Implemented**: the control exists and is in operation today, with evidence available.
- **Partial**: the control exists but has a known weakness in coverage, enforcement or evidence.
- **Gap**: the control does not exist yet.
- Figures such as user counts and log volumes are as of 2026-09-29 unless another date is given.
- Target dates in this pack are commitments made by management, not dates already achieved.
