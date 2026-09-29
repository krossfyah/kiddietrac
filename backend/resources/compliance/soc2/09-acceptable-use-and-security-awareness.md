# Acceptable Use and Security Awareness Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC1.1, CC1.4, CC1.5, CC2.2 |

## 1. Purpose

This policy sets the rules for using KiddieTrac systems and data, and the security training everyone with access must complete.

## 2. Scope

It applies to everyone with access to production systems, source code, the hosting account, vendor consoles or customer data. That includes the owner, future employees and contractors. It does not apply to customer end users, who are covered by the customer terms.

## 3. Policy statements

### 3.1 Acknowledgement

Before getting access, and then every year, each person signs an acknowledgement of:

- 01 (Information Security);
- 02 (Access Control);
- 04 (Incident Response);
- this policy.

Signed acknowledgements are kept for the whole period of access plus 2 years. **Status: no acknowledgements are on file yet.**

### 3.2 Handling customer data

- Use customer data only to provide, support or secure the service.
- Never copy production data to a personal device, a personal cloud account or a non-approved tool, including AI tools outside the approved sub-processors.
- Use the **Test Agency (agency 6)** for testing, demos, screenshots and QA. Never test against a real agency.
- Never send sample or test messages to real parents or staff. Test email goes only to an address controlled by KiddieTrac.
- Do not put customer data in git commits, issue trackers or support tickets.

### 3.3 Credentials

- Use a password manager, with a unique password for each system.
- Turn on MFA wherever it is offered. This is required for GitHub, cPanel, the domain registrar and every vendor console.
- Never share accounts. Never put secrets in source code or chat.
- Report a suspected exposure of credentials immediately. Rotate the credential within 24 hours.

### 3.4 Devices

A device used to administer KiddieTrac must have:

- full-disk encryption;
- a screen lock of 5 minutes or less;
- supported, automatically updated operating system and browser;
- endpoint protection turned on.

The device must not be shared with other people while an admin session is open.

**Status: not yet verified or recorded.** A simple annual device checklist is required.

### 3.5 Reporting

Report suspected incidents, phishing, lost devices or vulnerabilities right away. Use info@kiddietrac.com, or the channel in 04. Reporting in good faith never leads to discipline.

### 3.6 Violations

A breach of this policy may lead to access being removed and to disciplinary or contract action. For contractors, it may lead to the contract ending.

### 3.7 Security awareness training (CC2.2)

| Item | Requirement |
|---|---|
| When | At onboarding and every year |
| Content | Phishing and social engineering; password and MFA hygiene; handling children's personal information; tenant isolation; PIPEDA breach duties; incident reporting |
| Secure development (for engineers) | OWASP Top 10; tenant scoping on every query; not trusting client-side enforcement; secrets handling; dependency updates |
| Evidence | Completion date and the material used, recorded per person |
| Status | **No training records exist yet** |

## 4. Responsibilities

- **Security Officer:** provides the training, collects acknowledgements and tracks completion.
- **Everyone in scope:** follows this policy.

## 5. Exceptions

Any exception needs written approval (01 section 5).

## 6. Review

This policy is reviewed every year.
