# Risk Assessment

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Assessment date | 2026-09-29 |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC3.1, CC3.2, CC3.3, CC3.4, CC9.1 |

## 1. Purpose

This document identifies and rates the risks to KiddieTrac's service commitments and system requirements, and records how each risk is treated.

## 2. Scope

The KiddieTrac platform and its supporting people, vendors and processes, as described in 00.

## 3. Method

**Objectives (CC3.1)** are to:

- keep each agency's data confidential to that agency;
- keep children's and families' records accurate;
- keep the service available during childcare operating hours;
- meet privacy law (PIPEDA and applicable provincial laws).

**Identifying risks (CC3.2)** draws on:

- the platform architecture;
- incidents and alerts;
- audit findings;
- vendor changes;
- a workshop with management.

**Fraud risk (CC3.3)** is considered explicitly. Examples: misuse of the payments and payouts features, and misuse of platform-admin privilege.

**Change-driven risk (CC3.4)** is reassessed when there is new hosting, a new sub-processor, a new product feature that handles sensitive data, or a change in team size.

**Scoring:**

| Score | Likelihood | Impact |
|---|---|---|
| 1 | Rare: unlikely within 3 years | Minor: little effect on customers |
| 2 | Possible: could happen within 1 to 3 years | Moderate: limited outage or limited exposure, recoverable |
| 3 | Likely: expected within 12 months | Major: multi-agency outage, exposure of personal information, regulator notification |
| 4 | Almost certain | Severe: loss of customer data, a breach affecting children's records at scale, or business failure |

- **Inherent risk** = Likelihood × Impact, with current controls taken into account. The rating bands are:
  - 1 to 4: Low
  - 5 to 8: Medium
  - 9 to 12: High
  - 16: Critical
- **Treatment options:** Mitigate, Accept (management must sign), Transfer (insurance or contract) or Avoid.

## 4. Risk register

| ID | Risk | L | I | Score | Treatment | Plan reference |
|---|---|---|---|---|---|---|
| R-01 | **Key person.** One person holds all engineering, admin, infrastructure and incident roles. If they are unavailable, nobody can operate, recover or respond, and there is no segregation of duties. | 3 | 4 | 12 High | Mitigate: name a backup person; sealed break-glass credentials; written runbooks; send alerts to a second contact | RM-06, RM-07 |
| R-02 | **Shared hosting.** GoDaddy shared cPanel gives limited isolation from other tenants, peak-time resource limits (508s), limited control of logs and at-rest encryption, and depends on one provider. | 3 | 3 | 9 High | Mitigate: confirm at-rest encryption with GoDaddy; assess a move to a VPS or managed host in Canada | RM-14, RM-20 |
| R-03 | **Backups on the same host.** Losing the host, or a compromise of the hosting account, destroys production and all 14 backups together. No restore test has been documented. | 2 | 4 | 8 Medium (a single event with severe impact) | Mitigate: encrypted off-site copy; quarterly restore test | RM-02, RM-03 |
| R-04 | **MFA not enforced on the server.** MFA is required only in the browser; platform_admin is exempt; 3 of 116 users are enrolled. A phished or stuffed admin password gives full access. | 3 | 4 | 12 High | Mitigate: MFA middleware on the server for admin roles, including platform_admin; MFA on infrastructure consoles | RM-01 |
| R-05 | **No code review, CI or staging.** Changes go straight to production without independent review or automated tests. A defect can leak data across tenants or corrupt records. Past tenant-scoping defects show this can happen. | 3 | 4 | 12 High | Mitigate: CI with tests and `tenant:check`; pull requests for security-relevant changes; staging | RM-05, RM-10, RM-15 |
| R-06 | **Production differs from source control.** 138 uncommitted changes in production. Code cannot be rebuilt from git, and the running code cannot be audited or recovered. | 4 | 3 | 12 High | Mitigate: reconcile and commit; deploy only from git; automated drift check | RM-04 |
| R-07 | **Tenant isolation defect.** A mis-scoped query exposes one agency's children or families to another. `tenant:check` exists but runs only by hand. | 2 | 4 | 8 Medium | Mitigate: schedule `tenant:check` and alert on failure; run it in CI | RM-05 |
| R-08 | **Unreviewed access.** No quarterly access review has been done; 2 admin accounts have never logged in; role changes are only partly audited. | 3 | 3 | 9 High | Mitigate: quarterly review with sign-off; audit timestamps on role changes; disable dormant admins | RM-08, RM-09 |
| R-09 | **Undetected outage.** There is no external uptime monitoring, so an outage is found by customers. | 3 | 2 | 6 Medium | Mitigate: external monitor alerting two contacts | RM-11 |
| R-10 | **Unmanaged retention.** The purge job is enabled for 0 agencies; `audit_logs` are never purged. Personal information is kept longer than needed, which increases the impact of any breach and conflicts with PIPEDA limiting-retention principles. | 3 | 2 | 6 Medium | Mitigate: default retention schedule; audit log retention rule; agency onboarding step | RM-12, RM-13 |
| R-11 | **Personal information not encrypted at field level.** Children and family records are stored in plaintext in the database, and encryption at rest by the host is unconfirmed. A database dump or backup theft exposes everything. | 2 | 4 | 8 Medium | Mitigate: confirm host encryption; encrypt backups; assess field-level encryption for the most sensitive fields | RM-14, RM-02 |
| R-12 | **Sub-processor exposure.** 17 or more vendors receive personal information; DPAs are not confirmed; AI and email vendors receive message content. | 2 | 3 | 6 Medium | Mitigate: complete the DPA register; publish the sub-processor list; minimise what is sent | RM-16 |
| R-13 | **Credential attacks on login.** The 2026-09-20 probe; 272 failed logins in 30 days. | 3 | 2 | 6 Medium | Mitigated by lockout, throttles, alerts and IP denial. Residual risk accepted once server-side MFA is in place | RM-01 |
| R-14 | **Platform-admin misuse or payout fraud.** One person can change payout details and access all agencies, with no second approver. | 1 | 4 | 4 Low | Mitigate: audit logs cover admin actions; quarterly self-review; add a second approver when staff are added | RM-07 |

## 5. Acceptance

Residual risks rated Medium or above that are not being mitigated within 90 days must be accepted in writing by management. **No acceptances are recorded yet.**

## 6. Responsibilities

The Security Officer runs the assessment. Management approves the treatments and accepts residual risk.

## 7. Exceptions

None.

## 8. Review

The assessment is repeated fully every year, and updated when the triggers in section 3 occur or after any SEV-1 or SEV-2 incident. Progress is tracked at the quarterly security review.
