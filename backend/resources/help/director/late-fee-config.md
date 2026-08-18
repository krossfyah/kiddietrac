---
title: Late-fee configuration
category: Billing
order: 74
roles: agency_admin, platform_admin
---
# Late-fee configuration

You can now set per-agency late-fee rules. Before v22p51 these were hardcoded at 1.5% of balance capped at $25.

## Where to find it

Sidebar → **Administration → Billing settings** (agency admin only).

## What you can set

- **Late-fee percent** — of the overdue balance, default 1.5%. Set to 0 to disable late fees entirely for this agency.
- **Late-fee cap** — maximum dollar amount of any single late fee. Default $25.
- **Grace days** — number of days past `due_at` before a fee applies. Default 0.

## When fees are applied

A scheduled job runs daily at 02:00 ET. It finds invoices with status sent/partial/overdue and `due_at` older than (today − grace_days). For each invoice it inserts one late_fee line per calendar month — so the same invoice won't be hit twice in a month.

## Notifications

Every guardian on the family gets a `late_fee` notification in their inbox the moment a fee is applied. Status flips from sent → overdue automatically.
