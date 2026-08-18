---
title: Late-fee automation
category: Billing
order: 72
roles: agency_admin, platform_admin
---
# Late-fee automation

A scheduled job runs every morning at **02:00 ET** and adds a late-fee line to any invoice that:

- Has status `sent`, `partial`, or `overdue`
- Has a `due_at` before today
- Has a balance > 0
- Doesn't already have a late-fee line for the current month (idempotent)

## How the fee is calculated

**1.5% of the current balance, capped at \$25.00**. Adjusting the formula is a v22p51 candidate — for now the constant is baked into `ApplyLateFeesCommand`.

## What happens automatically

For each candidate invoice:

1. A new `invoice_lines` row is inserted with `line_type=late_fee` and `description='Late fee · YYYY-MM'`.
2. `invoices.balance_due` and `invoices.total` are bumped by the fee amount.
3. If the invoice was `sent`, it gets promoted to `overdue`.
4. Every guardian on the family receives a notification ("Late fee added — INV-XXXX-NNNN").
5. An `audit_logs` row records the action.

## Manual run

From SSH:

    php artisan kiddietrac:late-fees --dry-run    # print what would happen
    php artisan kiddietrac:late-fees              # real send

## Disabling

To pause late fees temporarily, comment out the `Schedule::command('kiddietrac:late-fees')` line in `routes/console.php`. To skip a specific invoice, mark it `paid`, `void`, or `refunded` before 02:00 ET.
