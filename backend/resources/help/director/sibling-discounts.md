---
title: Sibling discount tiers
category: Billing
order: 25
roles: agency_admin, platform_admin
---
# Sibling discount tiers

When a family has more than one child enrolled, you typically charge the second + subsequent children at a discount. Kiddietrac automates this on the monthly invoice batch — no per-family math required.

## How sibling discounts work

The OLDEST-enrolled child in a family is rank 1 — they always pay full tuition. The second-oldest is rank 2, third-oldest is rank 3, and so on. Each rank above 1 gets the highest matching discount tier you've configured.

Example tier table:
- Rank 2 -> 10% off
- Rank 3 -> 15% off
- Rank 4 -> 20% off

A family with 4 enrolled children would see:
- Child 1: full price
- Child 2: 10% off (matches tier rank=2)
- Child 3: 15% off (matches tier rank=3)
- Child 4: 20% off (matches tier rank=4)

A family with 5 children: child 5 also gets 20% off — the highest defined tier extends to higher ranks.

## Setting up tiers

Sidebar → **Settings → Sibling discounts**. The editor shows current tiers as rows: rank + percent + Remove button.

Click **+ Add tier** to append a new row. Default suggestion is the next-available rank with 10%. Edit the rank and percent to your needs.

Click **Save** to commit. Changes apply to the NEXT invoice batch — already-generated invoices are not retroactively adjusted.

Common configurations:
- Conservative: rank 2 -> 5%, rank 3 -> 10%
- Standard: rank 2 -> 10%, rank 3 -> 15%, rank 4 -> 20%
- Aggressive: rank 2 -> 15%, rank 3 -> 25%, rank 4 -> 35%

## Disabling discounts

Remove every tier row and click Save with an empty list. The system records discounts as disabled — subsequent invoice batches charge full tuition for every child.

## On the invoice

When discounts apply, you'll see a line per discounted child on the invoice PDF:

```
Tuition - Sienna Patel (Sunflower Room) ............. $1,400.00
Tuition - Theo Patel (Sunflower Room) ............... $1,400.00
Sibling discount (10%) - Theo ....................... -$140.00
```

The `discount_amount` on the invoice header reflects the sum of all discount lines, and the `total` shows the post-discount amount due.

## Per-agency setting

Each agency configures its own tiers independently. Switching agencies via the switcher and updating tiers only affects that agency's invoice math.
