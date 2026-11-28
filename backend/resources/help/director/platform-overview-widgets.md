---
roles: agency_admin, platform_admin
title: Platform overview
category: Administration
order: 90
screens: platform-overview
---
# Platform overview

**Platform › 🌐 Platform overview** is the cross-agency dashboard for KiddieTrac platform administrators: every centre, every family and every dollar across the whole platform on one page. Open it first thing to see whether the business is growing, whether email and the system are healthy, and whether anything has crashed. It only shows totals and recent events; you change things from the other **Platform** screens.

[[open: #platform-overview | Open Platform overview]]

## Doing a daily check

1. Open **Platform › 🌐 Platform overview**. The banner reads **Cross-agency overview**.
2. Read the top tiles: **Agencies** (active), **Centres**, **Children** (enrolled), **Families**, **Staff** (active users), **MRR** (monthly recurring revenue) and **Sessions** (active in the last 24 hours).
3. Check **Recent 30 days**: **New agencies**, **Cancellations** and **Net change**.
4. Scroll to **Platform performance** and look for anything red: **Failed sends** above 2% of email, **API status** showing **Down**, or **Dormant** agencies.
5. At the bottom, check **💥 Crash reports**. **✓ No server errors recorded. All clear.** means nothing failed; otherwise each row shows **When**, **Who** and **Error**.

## Reading the growth charts

1. **MRR trend** shows the last six months of monthly recurring revenue in Canadian dollars, with the amount above each bar.
2. **Agency growth** shows sign-ups against cancellations for the last six months. Purple is a **Net positive month**, amber **Had cancellations**, red **Net negative**.
3. **Top agencies by enrolment** lists the five largest agencies by children on roll, with a bar against the largest.
4. **Recent platform activity** shows the last 10 events across all agencies: what was done, by whom and when.

## Reading the business metrics

The **📊 Business metrics** strip is worked out from current MRR and its trend:

| Tile | Meaning |
|---|---|
| **ARR** | Annualised recurring revenue |
| **ARPA** | Average revenue per active agency, per month |
| **ARPU** | Revenue per enrolled child, per month |
| **MRR growth** | Change on last month, as a % and in dollars |
| **Churn (30d)** | Agencies lost against the starting count; red at 5% or more |
| **LTV** | Estimated lifetime value per agency |
| **NRR (6m)** | Today's MRR against six months ago; over 100% means existing agencies are spending more |
| **Capacity** | Children enrolled against licensed seats |

## Reading Platform performance

- **Operational health**: **Email delivered** and **Open rate** (last 7 days), **Failed sends**, **Suppressed** (held back by the email kill-switch), **Database** size and **API status**.
- **Usage & engagement**: **Logins today**, **Logins (7d)**, **Active users** (24 hours), **Active agencies** (30 days, out of all) and **Dormant** (no activity in 30 days).

> 💡 A dormant agency is often one that has stopped using KiddieTrac without cancelling. It is worth a call from the sales team.

## Who can do what

- **Platform administrators**: see this screen. It covers every agency.
- **Agency admins and centre directors**: never see cross-agency figures. They use their own **Dashboard**, which only covers their agency.

## FAQs and when something goes wrong

??? "Could not load" appears under the banner. | The connection dropped or your session ended. Refresh the page.
??? MRR here is different from the MRR dashboard or Sales reports. | Each screen measures at a different moment and range. This tile is the current monthly recurring total; Sales reports counts what was invoiced in the period you choose.
??? Crash reports lists an error. | Note who and what it was, and check **Server health**. One error after a deploy is usually harmless; repeats need a fix.
??? Failed sends is red. | More than 2% of email failed in the last 7 days. Open **Mail settings** and the email error log to see why.
??? Why are some numbers zero? | A brand-new platform or a quiet period. Totals fill in as agencies use KiddieTrac.

## Related screens

[[open: #platform-agencies | All agencies]] · [[open: #server-health | Server health]] · [[open: #admin-mrr | MRR dashboard]] · [[open: #platform-sales | Sales reports]] · [[open: #usage-spend | Usage & spend]]

See also: [[platform-admin-overview]] · [[platform-sales-reports]]
