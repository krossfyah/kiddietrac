# Access Control Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC6.1, CC6.2, CC6.3, CC6.6, CC6.8 (partly) |

## 1. Purpose

This policy makes sure that only authorised people can reach KiddieTrac systems and data, and that each person gets only the access their role needs.

## 2. Scope

The policy covers:

- **Application accounts:** all portal and app roles.
- **Infrastructure access:**
  - GoDaddy cPanel and SSH;
  - the MySQL database;
  - the GitHub repository;
  - DNS and domain registrar accounts;
  - sub-processor admin consoles (payments, email, SMS, Firebase).
- **API tokens and browser sessions.**

## 3. Policy statements

### 3.1 Role-based access (least privilege)

| Role | Purpose | Active role rows (2026-09-29) |
|---|---|---|
| platform_admin | Operates the whole platform across agencies | 1 |
| agency_admin | Administers one agency | 4 |
| centre_director | Manages one or more centres in an agency | 2 |
| educator | Works with children in assigned rooms | 30 |
| home_visitor | Files home-visit reports for assigned rooms | 4 |
| guardian | Parent or guardian access to their own children only | 51 |
| auditor | Read-only access; middleware blocks every write | n/a |

- Every API request is scoped to the **active agency**, and that agency is checked against the user's memberships. Requests for an agency the user does not belong to are refused.
- A tenant-isolation probe command (`tenant:check`) calls read endpoints with tokens from two different agencies and fails if it sees another agency's rows. **Today it runs only manually.** It must be scheduled, and its results kept (see the remediation plan).
- Roles are granted for the job being done. Guardians see only their own linked children. Educators and home visitors see only their assigned rooms.

### 3.2 Provisioning (CC6.2)

- **Customer users** are created by that agency's administrators through the portal's user-invite flow, or by the platform admin when onboarding a new agency.
- **Temporary passwords** are one-time keys. `must_change_password` forces a password change at first login, and middleware blocks every other action until the change is made.
- **Infrastructure access** (cPanel, SSH, database, GitHub, vendor consoles) is limited to the owner. Before anyone else gets infrastructure access, they need written approval from the Security Officer and must acknowledge 09. The grant is recorded in an access register. **Status: the register does not exist yet (Gap).**

### 3.3 Deprovisioning (CC6.2, CC6.3)

- Deactivating a user sets their account status. A job runs **every 15 minutes** and revokes all API tokens held by deactivated users, so any session still open ends within 15 minutes.
- Offboarding and departure jobs run **daily** and process staff and family departures.
- Agencies must deactivate departing staff on or before their last day.
- For infrastructure accounts, credentials must be rotated within 24 hours of a person's departure. This covers cPanel, SSH keys, database passwords and vendor API keys.
- **Weakness:** role assignments carry only a `created_at` timestamp, and role changes are only partly written to `audit_logs`. So the platform cannot fully show who changed a role, or when. **(Partial; remediation R-AC-03.)**

### 3.4 Authentication

| Control | Setting |
|---|---|
| Password complexity | At least 8 characters, with upper case, lower case, a number and a symbol |
| Password age | 90 days at most; reminders at 14, 7 and 1 days (daily job) |
| Password reuse | Not allowed within 6 months (`password_history`) |
| Account lockout | 5 failures within a 15-minute window locks the account for 5 minutes; admins can unlock from an unlock screen, and unlocks are audited |
| Login throttles | 10 per minute per email and IP; 40 per minute per IP |
| Password reset throttles | Forgot password: 3 per minute. Reset: 10 per minute |
| MFA | TOTP. Secret encrypted at the application level |
| Passkeys | WebAuthn on the web portal (2 users enrolled). Not available in the Android app's WebView |

**MFA enforcement is incomplete.**

- MFA is required for `agency_admin` and `centre_director`, but **only the browser enforces it**. There is no server-side middleware, so a client that calls the API directly can skip it.
- `platform_admin` is **exempt**, even though it is the most privileged account.
- Adoption as of 2026-09-29: 3 of 116 users, and 4 of 7 active admin role rows.

**Target state:**

- MFA enforced **on the server** for platform_admin, agency_admin and centre_director.
- MFA also required for cPanel, GitHub, the domain registrar and every sub-processor console.

(Remediation R-AC-01 and R-AC-02.)

### 3.5 Tokens and sessions

- API tokens expire after **30 days**. A daily job prunes expired tokens.
- Idle browser sessions time out.
- Tokens are revoked when a user is deactivated (see 3.3).
- Sign-in activity is written to `audit_logs`, including device and user agent. Figures for the last 30 days:
  - 450 logins
  - 272 failed logins
  - 1 MFA failure
  - 2 admin unlocks

### 3.6 Quarterly access review (CC6.2, CC6.3)

Every quarter, the Security Officer reviews:

1. All platform_admin, agency_admin, centre_director and auditor role rows.
2. Accounts that have **never logged in**, or have not logged in for 90 days. Two admin accounts had never logged in as of 2026-09-29.
3. Infrastructure and vendor console accounts.
4. API keys held by sub-processors.

The review is exported as an **Access review CSV** from the portal's Compliance evidence screen. The reviewer signs it off and stores it with the date. Any access that is no longer needed is removed within 5 business days.

**Status: no review has been recorded yet.** The sign-off feature is being added. The first review is due by the date in 12.

### 3.7 Network and external access (CC6.6)

- All traffic is HTTPS. HSTS is on, and there are security headers (see 10).
- Throttles are set per route. Abuse detection runs every 15 minutes (brute force by IP, credential stuffing, MFA hammering).
- The web server can deny source IPs. This was used in the 2026-09-20 incident.

## 4. Responsibilities

- **Security Officer:**
  - platform and infrastructure accounts;
  - the quarterly access review;
  - MFA enforcement.
- **Agency administrators:** their own agency's users and roles, including timely deactivation.
- **All users:** keep their credentials secret and report anything suspicious.

## 5. Exceptions

A service account or a person may be exempt from MFA only with a written exception (see 01 section 5) that expires within 90 days. The current `platform_admin` exemption has **no approved exception on file**. It is recorded as a gap.

## 6. Review

This policy is reviewed every year, and whenever roles, the authentication method or hosting changes.
