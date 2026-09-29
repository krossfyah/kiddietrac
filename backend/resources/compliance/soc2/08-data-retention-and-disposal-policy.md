# Data Retention and Disposal Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Privacy Contact |
| Criteria | C1.1, C1.2, P4.2, P4.3, P5.1, P5.2 |

## 1. Purpose

This policy sets out how long KiddieTrac keeps information, how it is disposed of, and how requests from individuals to access or delete their information are handled under PIPEDA.

## 2. Scope

It covers all customer data in the platform, the security and application logs, the backups, and the copies held by sub-processors.

## 3. Roles under privacy law

- **Customer agencies** decide what information they collect about the children, families and staff they serve, and how long they keep it. For most customer data, the agency is the organisation in control.
- **KiddieTrac** processes that data on the agency's behalf. It is the organisation in control of its own data: account, billing, marketing-site, security-log and support data.

## 4. Policy statements

### 4.1 Identifying confidential information (C1.1)

| Class | Examples | Handling |
|---|---|---|
| Restricted | Children's records (health, immunisations, incidents, custody notes), family circumstances, staff payout details, credentials and secrets | Access by role and tenant only; secrets encrypted at the application level (see 10); never used in tests or screenshots; Test Agency (agency 6) used for all QA |
| Confidential | Contact details, attendance, messages, invoices, audit logs | Access by role and tenant only |
| Internal | Source code, runbooks | Owner and approved contractors only |
| Public | Marketing site, help articles | None |

### 4.2 Retention settings

- Each agency sets its own retention periods, stored in the agency's settings.
- A **nightly retention purge job** deletes records older than the agency's configured periods, **for agencies that have opted in**.
- **Status: the purge is enabled for 0 agencies.** Customer data is therefore kept until an agency deletes it or leaves.
- **Required:**
  - a documented default retention schedule, offered to agencies at onboarding;
  - a decision on each existing agency's settings, recorded with that agency's administrator.

Proposed default schedule, **for management approval**:

| Data | Proposed default | Note |
|---|---|---|
| Active child and family records | While enrolled | |
| Records of withdrawn children and families | 7 years after withdrawal, or as provincial childcare licensing rules require (confirm per province) | Licensing rules often set minimums |
| Attendance and billing | 7 years | Tax and financial records |
| Messages and daily notes | 2 years | |
| Security and audit logs (`audit_logs`) | To be decided; 24 months proposed | **Currently never purged, with no retention rule** (about 32k rows since 2026-06-29) |
| Application error logs | 14 days | Implemented |
| Email delivery log (`email_logs`) | 12 months proposed | Not governed today |
| Database backups | 14 nightly copies | Implemented (same host; see 05) |
| Breach records | 24 months minimum | PIPEDA requirement (see 04) |

### 4.3 Customer exit

When an agency ends its subscription:

1. KiddieTrac offers an export of the agency's data.
2. After an agreed period (30 days proposed), the agency's data is deleted from production.
3. It then leaves the backups as they rotate out, within 14 days.

Deletion is confirmed to the agency in writing. **Status: no written procedure or record yet.**

### 4.4 Disposal (C1.2)

- **Database records:** deleted by the purge job, or by the platform's archive-and-delete functions.
  - Archived (soft-deleted) records are **not** disposed of. They count as retained until they are purged.
- **Backups:** the oldest dump is deleted when a new one is written, keeping 14.
- **Files and uploads:** deleted along with their parent record, or removed as part of the agency exit.
- **Sub-processors:** request deletion when the vendor is offboarded (see 06).
- **Devices:** a device that holds exports or credentials must be wiped securely before it is reused or thrown away.
- **Logging:** the purge job logs what it deletes (counts by type and agency) to `audit_logs`, so there is evidence of disposal.

### 4.5 Requests from individuals (PIPEDA)

- **Access requests:** an individual may ask for the personal information held about them.
  - Where the agency is in control, KiddieTrac passes the request to the agency and helps it respond.
  - Where KiddieTrac is in control, it responds within **30 days**, the PIPEDA time limit, which can be extended only as the Act allows.
- **Correction:** handled in the same way. Most data can be corrected by the agency in the portal.
- **Deletion or withdrawal of consent:** the request is acted on unless the information must be kept for legal, licensing or contract reasons. If it must be kept, the individual is told why.
- **Record keeping:** every request is logged with the date received, the requester, the identity check, the action taken and the date closed. **Status: Gap. There is no request log yet.**
- **Identity verification:** confirm the requester's identity before disclosing anything. An email address alone is not proof of identity, because one address can belong to accounts in more than one agency.

### 4.6 Consent and notice

- A versioned Privacy Policy (last updated 2026-08-14) is published, and user acceptance is recorded.
- The marketing site uses opt-in cookie consent and no advertising cookies.
- SMS consent is tracked per handset.

## 5. Responsibilities

- **Privacy Contact:** handles requests, maintains the schedule and runs agency exits.
- **Agency administrators:** configure retention for their agency and handle requests from their families and staff.

## 6. Exceptions

A legal hold, such as litigation or a regulator's request, suspends deletion of the records concerned. The hold is documented and reviewed every quarter.

## 7. Review

This policy is reviewed every year, and when privacy law or provincial licensing rules change.
