---
title: Bulk invoice generation
category: Billing
order: 70
---
# Bulk invoice generation

Run the monthly invoicing for every enrolled family at a centre with one click. Find it under **Administration → Bulk invoice run** in the sidebar.

## How it works

1. Pick the **billing period** at the top — month + year. Defaults to the current month.
2. Pick a centre from the list and click **Generate invoices**.
3. Confirm the prompt. One invoice per enrolled family is created with:
   - Tuition lines per child (one row per enrolled child at this centre)
   - **CWELCC subsidy** lines subtracted automatically when the centre is opted in
   - **Sibling-discount** lines applied when the agency has tiers configured (see `sibling-discounts.md`)
   - Period-start / period-end set to the full month
   - `issued_at` set to the first of the month, `due_at` 15 days later
   - Status = `sent`
4. After the run, the result chip on each centre row shows how many invoices were created + the total $ value.

## Re-running

Already-generated invoices for that month are **not duplicated** — the existing rows stay as they are. Running again on the same month creates invoices only for families that don't already have one for that period. Safe to re-trigger.

If you need to wipe-and-redo, delete the invoices manually first (or via SQL).

## Permissions

- **Agency admin** — runs across any centre in their agency
- **Platform admin** — runs across any centre on the platform via the agency switcher
- **Centre director** — uses the existing `/director/invoices/generate` route from their dashboard (only their own centre)
- **Educator** — no access

## What's billed

- One **tuition** line per enrolled child, calculated from their room's monthly rate
- One **subsidy** line per child when the centre is CWELCC-opted-in (subtracted from total)
- One **adjustment** line per sibling-discount tier match (subtracted from total)

The invoice header carries the rolled-up `subtotal`, `subsidy_amount`, `discount_amount`, `total`, and `balance_due` numbers.

## Troubleshooting

If a run returns 0 generated, check:

- Are any children at the centre currently `enrolled` (not waitlist / withdrawn / graduated)?
- Do those children have an active enrollment row (`enrollments.end_date IS NULL`)?
- Did the families already have a v22p42 invoice for this exact month? Existing rows are skipped.

The Bulk invoice screen calls the same backend the director-portal "Generate this month" button uses — same logic, same outputs, just operable centre-by-centre across an agency.
