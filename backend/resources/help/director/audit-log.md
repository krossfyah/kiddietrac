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

## Filtering

Five filters at the top:

- **Action** — pick from the dropdown (auto-populated with action types seen in your agency)
- **Entity** — limit to one entity type (user / centre / invoice / etc.)
- **Search** — free text against the action name or JSON payload
- **From / To** — date range bounds

Filters apply both to the on-screen rows and the CSV download.

## CSV export

Click the green **⤓ CSV** button beside Reset. Downloads up to 5,000 rows with When / Action / Entity / Actor / Email / IP / Payload columns. Honours every active filter.

## Detail modal

Click any row for a full detail view including pretty-printed JSON payload — useful for understanding *exactly* what changed.

## Scope

Agency admins see actions by users in their agency (role-based scope OR guardian path). Platform admins see every row across the platform.
