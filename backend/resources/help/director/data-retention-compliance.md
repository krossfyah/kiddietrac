---
title: Data retention & compliance
category: Compliance
order: 30
roles: agency_admin, platform_admin
---

# Data retention & compliance

Under **Settings → Data retention & compliance**, agency administrators set how long each type of record is kept and record the agency's privacy details. These settings document your agency's **data-retention policy** — useful for audits and for parent transparency.

## Retention periods

Set how long to keep each of:

- **Child & enrolment records** (years, after a child leaves)
- **Uploaded documents** (years)
- **Attendance & daily logs** (months)
- **Parent–educator messages (chat)** (months)
- **Announcements & news** (months)
- **Security & audit trail** (months)

Childcare regulations often set minimums — check your jurisdiction (e.g. Ontario's CCEYA).

## Privacy & consent

- **Require parent consent at enrolment** — parents acknowledge your privacy terms before a child is enrolled.
- **Privacy policy URL** — a link to your published policy.
- **Data-protection contact email** — where parents send access or deletion requests.

## Automatic enforcement

Automatic enforcement is **off by default**. These settings record your policy; when you switch **Automatically enforce retention** on, a **nightly job** (runs ~2:30 AM) clears history past your retention window:

- **Chat messages** older than *Parent–educator messages (chat)* months
- **Announcements** older than *Announcements & news* months

The **When enforcing, records are** choice controls how:

- **Anonymised** — the content is blanked but the row is kept (aggregate stats and the audit trail stay intact).
- **Permanently deleted** — the records are removed. This cannot be undone.

Because enforcement runs automatically once enabled, coordinate with your team and double-check your retention periods before turning it on. Nothing is touched for any agency that hasn't enabled enforcement.
