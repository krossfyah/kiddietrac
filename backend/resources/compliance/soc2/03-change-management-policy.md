# Change Management Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Change Approver |
| Criteria | CC8.1 (also CC3.4, CC7.1) |

## 1. Purpose

This policy makes sure that changes to KiddieTrac's software, database schema, configuration and infrastructure are recorded, tested, approved and reversible.

## 2. Scope

The policy covers:

- application code: the API, the portal and the Android app;
- database migrations;
- scheduled jobs and crontab;
- server and PHP configuration;
- security settings;
- DNS and TLS changes;
- email and SMS templates held in code.

## 3. Current state (as of 2026-09-29)

| Practice | State |
|---|---|
| Source control | GitHub, private repo `krossfyah/kiddietrac`, one `main` branch |
| Volume | About 650 commits in the last 90 days, almost all by one person |
| Peer review / pull requests | **None** |
| Automated tests / CI gate | **None** |
| Staging environment | **None** |
| How production is changed | Files edited or copied directly onto the production host; **138 uncommitted changes** on the production working tree |
| Deployment documentation | Exists |
| Front-end cache control | The service-worker cache version is bumped on each front-end deploy |
| Change communication | CHANGELOG, plus the in-app "What's new" panel and weekly digest |

Right now an auditor cannot tell from git alone what code is running in production. That is the most important thing to fix in this policy.

## 4. Policy statements

### 4.1 Source of truth

1. What runs in production must match a commit on `main`. The 138 uncommitted production changes must be reconciled and committed by the date in 12.
2. From then on, production is deployed from git only (a pull or checkout of a tagged commit). Direct edits on the host are not allowed except under the emergency procedure in 4.5.
3. A weekly automated drift check (`git status --porcelain` on production) raises an alert if the working tree is not clean. **Status: Gap.**

### 4.2 Recording changes

Every change is recorded in:

- a commit message that describes what changed and why;
- the CHANGELOG, for user-visible or security-relevant changes;
- a "What's new" entry, for customer-facing features.

### 4.3 Testing

- Before deployment, the author tests each change: they exercise the affected screen or endpoint against the **Test Agency (agency 6)**, never against live customer data.
- Changes to tenant scoping, authentication or authorisation must also pass `tenant:check` before and after deployment.
- **Target:** a basic automated test suite and a CI workflow that runs on every push. It should at minimum cover `php -l` syntax, the migrations, the authentication and tenant-isolation tests, and `tenant:check`. **Status: Gap.**

### 4.4 Approval and review

- The Change Approver approves changes before deployment. Today the approver is the author.
- **Compensating control while there is only one engineer:**
  - Security-relevant changes (auth, roles, tenant scoping, encryption, logging, backups) go through a pull request, even though the author merges it.
  - Each such pull request has a written self-review checklist and an automated code-scanning review, such as GitHub's code scanning or dependency alerts.
  - Management reviews a sample of changes at the quarterly security review.
- **Target:** independent review by a second engineer or contractor for high-risk changes once one is available.

**Status: Gap.**

### 4.5 Emergency changes

A fix may be applied directly to production if it is needed to stop an active incident or a Severity 1 outage. It must be committed to git within **1 business day** and noted in the incident record.

### 4.6 Database changes

- Schema changes are made only through Laravel migrations kept in git.
- A fresh backup must exist before any migration that is destructive or rewrites data.
- Never change the database or application timezone. Doing so reinterprets every existing row.

### 4.7 Rollback

Each deployment must be reversible, either by redeploying the previous commit or, for data changes, by restoring from backup. The deployment doc describes the rollback steps.

### 4.8 Infrastructure and configuration

These changes are recorded in a dated change log in the repository (`/docs/ops-changes.md` or similar):

- crontab and scheduler changes;
- PHP and web server configuration;
- DNS;
- TLS;
- vendor credentials (note which key was rotated, never the key itself).

**Status: Gap.**

## 5. Responsibilities

- **Author:** implements, tests and records the change.
- **Change Approver:** approves the change and makes sure it is deployed from git.
- **Security Officer:** samples changes every quarter and keeps this policy current.

## 6. Exceptions

Only the emergency procedure in 4.5 is allowed. Any other deviation needs a written exception (01 section 5).

## 7. Review

This policy is reviewed every year, and when the team grows or a staging or CI environment is added.
