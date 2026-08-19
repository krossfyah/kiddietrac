---
roles: agency_admin, platform_admin
title: Compliance dashboard
category: Administration
order: 56
---
# Compliance dashboard

One screen with the three "what needs attention" signals every centre director and agency admin wants at a glance. Open from **Administration → Compliance**.

## KPI strip

Five tiles across the top:

- **Expired certs** — staff certifications past their expiry
- **Expiring (30d)** — certs expiring in the next 30 days
- **MFA not enrolled** — directors + agency admins without two-factor enabled
- **At/over capacity** — centres at or above 100% enrolment
- **Tight (95%+)** — centres close to capacity

## Three cards

### Staff certifications

Lists every cert that's expired or expiring within 30 days. Each row shows the staff member, cert type (RECE / First Aid / CPR / etc.), and relative time (e.g. "expires in 4 days").

Empty state: "✅ Every cert is current."

### MFA not enrolled

Lists directors + agency admins without `two_factor_secret` set. Each row shows name, email, and last-login timestamp. Direct them to **Settings → Two-factor (MFA)** to enrol.

Empty state: "✅ Every director + admin has MFA enrolled."

### Centre capacity

For every centre in the agency: enrolled count / licensed capacity, percentage, and a colour-coded progress bar (green < 80%, amber 80-95%, red ≥ 95%).

## Agency scope

Scoped by `getAgencyId()`. Platform admins see the slice for whichever agency they have set via the agency switcher.
