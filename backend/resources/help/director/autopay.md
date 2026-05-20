---
title: Auto-pay (saved card)
category: Billing
order: 76
---
# Auto-pay

Save a credit card once and we'll automatically charge it for every invoice due. Cancel any time.

## Setting it up (parent)

1. Sidebar → **Auto-pay**.
2. Click **+ Add card**.
3. Enter your card details in the Stripe form — number, expiry, CVC, postal code.
4. Click **Save card**. Auto-pay turns on automatically.

Your card details never touch our servers — they go directly to Stripe via the secure form.

## How charging works

A daily cron at 03:00 ET finds all unpaid invoices for families with auto-pay enabled and charges the saved card. Successful charges insert a payment row + drop the invoice balance + flip status to paid when balance hits zero.

If a charge fails (expired card, insufficient funds, etc.), you get a `payment_failed` notification in your inbox immediately. Update the card and we'll try again on the next run.

## Turning it off

Sidebar → **Auto-pay** → **Turn off auto-pay**. Your saved card stays on file (so you can re-enable later) but no automatic charges will occur.

## Manual charge by your provider

Your centre director can also press **Charge saved card** on any invoice — useful if you want to pay early or settle a balance immediately.
