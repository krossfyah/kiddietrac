---
title: Late pickup fees
category: Billing
order: 82
---
# Late pickup fees

Auto-charge a per-minute fee when a parent picks up their child after the centre's close time.

## Centre-wide settings

Sidebar → **Administration → Billing settings** (agency admin):

- **Late-pickup per minute** — dollars per minute past close (default $1.00).
- **Grace minutes** — free minutes before the fee starts (default 5).

## Logging a late pickup

1. Sidebar → **Late pickups**.
2. Tap **+ Log late pickup**.
3. Pick the centre, the child, the pickup time, and the centre's close time.
4. The system calculates minutes late, subtracts grace minutes, and computes the fee.
5. If the family has an open invoice, the fee is auto-appended as a `late_pickup` line item. Otherwise the charge is recorded for the next invoice.
6. Guardians get a notification with the fee amount.

## Within grace

Pickups within the grace window return `within_grace` with zero fee — no invoice change.

## Audit trail

Every late pickup is recorded in `late_pickup_charges` with: child, centre, pickup time, close time, minutes late, fee amount, notes, recorded-by user.
