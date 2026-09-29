# Information Security Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Effective | 2026-09-29 |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC1.1 to CC1.5, CC2.1 to CC2.3, CC3.1, CC5.1 to CC5.3 |

## 1. Purpose

This policy sets out how KiddieTrac governs information security. It covers who is accountable, how security expectations are communicated, and how controls are chosen and maintained. It is the top-level policy, and the other documents in this pack apply it in detail.

## 2. Scope

The policy applies to:

- The KiddieTrac platform: the Laravel API, web portal, Android app, MySQL database, scheduled jobs, and the marketing site at kiddietrac.com.
- All data the platform processes for customer agencies. That includes children's records, family and guardian contact details, staff records, billing data, messages and media.
- Everyone with access to production systems or source code: the owner, any future staff, and contractors.
- Sub-processors, as far as covered by 06-vendor-management-policy.md.

## 3. Policy statements

### 3.1 Commitment and tone (CC1.1)

- KiddieTrac holds personal information about children and families. It treats confidentiality, integrity and availability of that data as core product requirements.
- Management commits to the policies in this pack and to closing the gaps in 12-remediation-plan.md by the dates stated there.
- Anyone with system access must acknowledge this policy and 09-acceptable-use-and-security-awareness.md before access is granted, and again every year.

### 3.2 Oversight (CC1.2)

- KiddieTrac has no board or separate governance body. Management (the owner) oversees security.
- Because oversight and operation sit with the same person, management will hold a **quarterly security review** and record it in writing. The review covers open risks, incidents, the access review, backup status and remediation progress. **Status: not yet performed** (see the remediation plan).
- An independent party, such as an external advisor or the SOC 2 auditor during readiness, will review this pack at least once a year. **Status: not yet arranged.**

### 3.3 Structure, authority and responsibility (CC1.3)

| Role | Held by | Responsibilities |
|---|---|---|
| Security Officer | Anthony Hosein | Owns this pack; runs risk assessment, access reviews and incident response |
| Platform Administrator | Anthony Hosein | The only `platform_admin` account; production and hosting access |
| Change Approver | Anthony Hosein | Approves and deploys production changes (see 03) |
| Incident Lead | Anthony Hosein | Runs incidents (see 04) |
| Privacy Contact | Anthony Hosein (info@kiddietrac.com) | Handles privacy requests and complaints; liaises with the OPC |
| Agency Administrators | Customer staff | Manage their own agency's users, roles and settings within the portal |

**Segregation of duties is not possible today.** The compensating controls are:

1. Everything done through the API is written to `audit_logs`, including actions by the platform admin.
2. Security events generate automated alerts.
3. Every production change is recorded in git history and the CHANGELOG.
4. Management performs a quarterly self-review and keeps a written record of it.

These controls are weaker than true segregation. The risk is recorded as R-01 and R-05 in 07.

### 3.4 Competence (CC1.4)

- Anyone with production access must have practical knowledge of secure web development: OWASP Top 10, tenant isolation, and secrets handling.
- Security awareness training is completed every year (see 09).

### 3.5 Accountability (CC1.5)

- Policy breaches are handled under 09 section 3.6.
- Security objectives and remediation dates are reviewed at the quarterly security review.

### 3.6 Information and communication (CC2.1 to CC2.3)

- **Internal:** security procedures live in this pack and in the repository's deployment documentation. The security alert job emails active platform administrators.
- **External:**
  - A versioned Privacy Policy (last updated 2026-08-14) is published, and user acceptance is recorded.
  - Vulnerability reports are accepted at https://kiddietrac.com/vulnerability-reporting, with contact details at /.well-known/security.txt.
  - Customers are told about product changes through the in-app "What's new" panel and the weekly digest email.
  - Incident notifications to customers follow 04.
- **Customer responsibilities:** agencies are responsible for:
  - managing their own users and roles;
  - enabling MFA for their administrators;
  - configuring data retention;
  - obtaining any consents required from the families they serve.

  These responsibilities should be written into the customer terms and the system description. **Status: Partial.** They are not yet collected in one "complementary user entity controls" section.

### 3.7 Risk assessment (CC3)

KiddieTrac assesses risk at least once a year and on material change, following 07-risk-assessment.md.

### 3.8 Control activities (CC5.1 to CC5.3)

- Controls are chosen to reduce the risks in the risk register and are listed in 11-control-matrix.md.
- Where possible, controls are automated in the platform so they do not depend on a person remembering. Examples: token expiry, lockout, alert jobs, backups and certificate renewal.
- Policies are put into practice through the specific policies in this pack (02 to 10).

### 3.9 Monitoring (CC4)

- Automated monitoring: the security alert job (every 15 minutes), the backup failure alert, and the certificate expiry check.
- Periodic evaluation: the quarterly security review and the annual policy review.
- Deficiencies are logged in 12-remediation-plan.md with an owner and a date.

## 4. Responsibilities

- **Management / Security Officer:**
  - approves this pack;
  - funds and schedules remediation;
  - runs the quarterly review.
- **Anyone with system access:**
  - follows these policies;
  - reports suspected incidents immediately to info@kiddietrac.com, or directly to the Security Officer.

## 5. Exceptions

Any exception must be requested in writing and must state the risk, the compensating control and an expiry date. The Security Officer approves exceptions, and they are listed in the quarterly review record. Because the requester and the approver are currently the same person, every exception must also be shown to the independent reviewer at the annual review.

## 6. Review

This policy is reviewed every year and on material change. Changes are recorded in the version table in 00-README.md.
