---
title: Weekly / biweekly billing schedule
category: Billing
order: 80
---
# Billing schedule

Some families need weekly or biweekly billing instead of monthly. Set this per family.

## Setting the schedule

1. Sidebar → **Billing schedule**.
2. Enter the family ID and tap **Load**.
3. Pick **Frequency**: weekly / biweekly / monthly.
4. For weekly/biweekly, pick a **day of week** (0=Sun … 6=Sat).
5. For monthly, pick a **day of month** (1-31).
6. Tap **Save schedule**.

The system computes the next charge date and shows it back.

## How autopay knows when to charge

When the daily autopay cron runs at 03:00 ET, it checks each family's schedule. If a billing schedule exists, it charges on the next-charge date. If no schedule, it falls back to the standard invoice due-date pattern.

## Disabling

Tap **Disable** to remove the custom schedule. The family reverts to standard monthly billing.

## Differences

- **Schedule** = WHEN to charge (cadence)
- **Wallet default** = WHAT to charge against (payment method)
- **Payment plan** = ONE balance split into multiple due dates
