---
title: Refunds (partial + multi)
category: Billing
order: 78
---
# Refunds

Process partial or full refunds against any payment, multiple times if needed. Stripe payments refund automatically; manual payments are recorded for audit only.

## Issuing a refund (director / admin)

1. Sidebar → **Refunds**.
2. Enter the **Payment ID** to refund and tap **Load**.
3. The page shows: original payment amount, already-refunded amount, and remaining refundable amount.
4. Enter the **refund amount** (cannot exceed remaining).
5. Pick a reason: Customer requested · Duplicate · Overpayment · Goodwill · Vacation credit.
6. Add notes if helpful.
7. Tap **Issue refund**.

## What happens

- Stripe processes the refund (1-2 business days for cards, 3-5 for ACH)
- A `payment_refunds` row records the action with timestamp + initiator + Stripe refund ID
- The family ledger picks it up as a debit immediately
- All guardians on the family get an in-app notification

## Partial + multi-refund

You can refund any amount up to the remaining refundable balance. Issue a second refund later for more — they stack until the original payment is exhausted.

## Manual refunds

If the original payment was not via Stripe (cash, e-transfer), the refund is recorded but no money moves. Useful for audit trail of in-person refunds.
