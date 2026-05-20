---
title: Account ledger + statement
category: Billing
order: 77
---
# Account ledger

Your complete invoice / payment / refund history with a running balance. Downloadable as a branded PDF statement.

## Finding it

- **Parent**: Sidebar → **Account ledger** under Account.
- **Director / admin**: Open a family in **Families** → "View ledger" button. Path: `/api/v1/families/{id}/ledger`.

## What's in it

Every row sorted by date:

- **Invoices** appear as debits (money you owe)
- **Payments** appear as credits (money received)
- **Refunds** appear as debits with `Refund:` prefix

The right column shows the **running balance** after each transaction. The top of the page summarises Outstanding balance / Total invoiced / Total paid / Total refunded.

## Statement PDF

Tap **⤓ Download statement PDF** in the hero. Generates a clean branded PDF with the agency logo, the full transaction list, and the current outstanding balance. Useful for record-keeping or tax purposes.

## Different from the Wallet

The Wallet is your saved payment methods. The Ledger is your transaction history. They're separate sidebar entries.
