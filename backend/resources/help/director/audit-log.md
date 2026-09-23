---
title: Audit log viewer
category: Administration
order: 58
roles: agency_admin, platform_admin
---
# Audit log

Every meaningful action in the portal is recorded. Find the viewer under **Administration → Audit log**.

## What gets recorded

Examples (50+ action types as of v22p49):

- `user.created` / `user.deleted` / `user.revived` / `user.role_changed`
- `centre.created` / `centre.updated` / `centre.archived`
- `agency.suspended` / `agency.resumed` / `agency.updated`
- `invoice.created` / `invoice.late_fee_applied` / `payment.recorded`
- `branding.updated`
- `campaign.email_sent` / `digest.daily_sent` / `digest.weekly_sent`
- `chat.email_notified` / `form.submitted`

## Reading the order

![The audit log, timestamped to the millisecond](https://api.kiddietrac.com/help-img/audit-log.png)

**Timestamps carry milliseconds.** Whole seconds were not enough resolution to read this log: of the 300 most recent rows, 241 share their second with another row, and a single second in the middle of an integration sync holds 25 of them. At second precision those all read as one moment, so a correctly ordered list looked arbitrary.

Rows are ordered newest first, and ties within the same instant are broken by the order they were written — so what you see is the order things actually happened, and now the timestamp shows it.

Every time on this screen is in **your agency's timezone**, never UTC and never your device's.

Rows written before September 2026 show `.000`. That is honest rather than tidy: their sub-second order was never recorded, and inventing one would be worse than admitting it.

## Filtering

Five filters at the top:

- **Action** — pick from the dropdown (auto-populated with action types seen in your agency)
- **Entity** — limit to one entity type (user / centre / invoice / etc.)
- **Search** — free text against the action name or JSON payload
- **From / To** — date range bounds

Filters apply both to the on-screen rows and the CSV download.

## CSV export

Click the green **⤓ CSV** button beside Reset. Downloads up to 5,000 rows with When / Action / Entity / Actor / Email / IP / Payload columns. Honours every active filter.

The export is ordered exactly as the screen is, ties included. It previously sorted on the timestamp alone, which left rows sharing a second in whatever order the database happened to return — in the one copy of the log that gets filed and handed to somebody. The **When** column is written as text so a spreadsheet cannot round the milliseconds away and quietly reorder it.

## Detail modal

Click any row for a full detail view including pretty-printed JSON payload — useful for understanding *exactly* what changed.

## Scope

Agency admins see actions by users in their agency (role-based scope OR guardian path). Platform admins see every row across the platform.
