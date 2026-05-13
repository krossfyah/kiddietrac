---
title: Generating monthly invoices
category: Billing
order: 1
---

# Generating monthly invoices

At the start of each month (or whenever you prefer), generate invoices for every enrolled family in one click.

## How invoice generation works

When you click **Generate invoices**, Kiddietrac:

1. Finds every active enrollment in your centre
2. Groups them by family
3. For each family, creates one invoice with one line per child
4. Applies any active CWELCC subsidy to reduce the amount the family owes
5. Sets the invoice to **Sent** status and emails it to the family (if you've set up email)

The family sees the invoice in their parent app under **Billing**.

## Doing it

1. Go to **Families → Invoices** (or click "Receivables" on your dashboard)
2. Click **Generate invoices for [month]**
3. Confirm — Kiddietrac shows how many invoices will be created and the total amount
4. Click **Generate**

## What if I already generated this month's invoices?

The system won't create duplicates. If you already ran it this month, it will skip families who already have an invoice for that month and only generate for newly enrolled children.

## How invoices are calculated

For each child enrolled:

- **Subtotal** = monthly fee from the enrollment
- **Subsidy** = CWELCC monthly amount, if eligible (default $1,650 for infants, $1,450 for toddlers, $1,250 for preschool)
- **Net** = subtotal − subsidy

If a family has multiple children, all the children's amounts are summed into one invoice.

## Recording payments

When a family pays (e-transfer, cheque, cash, etc.), record it:

1. Click the invoice number
2. Click **Record payment**
3. Enter the amount, method, date paid, and any reference
4. Save

The invoice balance updates automatically. If the family pays the full amount, the invoice status changes to **Paid**.

## What about Stripe / credit cards?

Kiddietrac doesn't currently process credit cards. You handle payments outside the app (e-transfer, cheque, etc.) and just record them in Kiddietrac so the family's account is up to date. Stripe integration may be added in a future release.

## Tips

- **Generate invoices on the 1st of each month**. Set a calendar reminder.
- **Send a reminder email** to families with overdue invoices around the 15th
- **Track payment methods**. Most Ontario centres see e-transfer as the most common method

## Editing or cancelling an invoice

You can't delete an invoice once it's generated (this preserves your financial records). If you need to:

- **Refund a family**: record a "negative" payment with a note explaining the adjustment
- **Correct an error**: contact support — we'll help adjust the records
