---
title: Refunding a payment
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
screens: refunds
---
# Refunding a payment

**Finance → Refunds** gives money back to a family, in full or in part, against a payment they made. A refund is the one money movement with nobody on the other side confirming it, so every refund is signed for by the person who approves it and recorded with the date, the time and where it came from.

[[open: #refunds | Open Refunds]]

![The Refunds screen: choose the payment, or enter the invoice number](https://api.kiddietrac.com/help-img/refunds.png)

## Finding the payment to refund

You can find the money two ways: from the list, or by invoice number.

1. Open **Finance → Refunds**.
2. Open the **Choose the payment to refund** list. It shows everything on the agency that still has money to refund: the family, the refundable amount, the invoice or payment number, the date, and anything already refunded.
3. Pick the payment. Its details open below.

Or, when you are working from a number rather than a name:

1. Type the number into **Or enter the invoice number to refund:** — the number printed on the invoice the family was sent. Part of a number works.
2. Press **Find invoice** (or press Enter).
3. If one invoice matches, it opens straight away. If several match, each is listed with the family named, what was billed and what was received; press **Refund** beside the right one.

> ⚠️ One number can belong to several invoices (for example, numbers made from a date can be shared by many families). Every match is listed and you choose. Nothing is picked for you.

Agency admins can also start from a family's account: open it in **Finance → Account ledgers** and press **↩ Refund**. You see only that family's payments, which is the safer route when you already know whose money it is.

## Issuing the refund

At the top you see three figures: **Payment amount**, **Already refunded** and **Refundable**. Below them, **Refund history** lists earlier refunds on this payment with their status.

1. Under **Issue new refund**, type the **Amount**. Any amount up to the refundable figure works, so a partial refund is just a smaller number.
2. Choose a **Reason**: **Customer requested**, **Duplicate charge**, **Overpayment**, **Goodwill** or **Vacation credit**.
3. Add any **Notes** (the family sees the reason and notes in their notice).
4. Type your full name in **Your name**.
5. Sign in the **Signature** box ("Sign here to approve"). Press **Clear signature** to start again.
6. Press **Issue refund**.

A green box confirms the refund and its status. The figures refresh a few seconds later.

Already-refunded money is netted off: a payment of $1,680 that has had $420 returned shows **$1,260** refundable. Refunding more than remains is refused, with the exact amount left.

## What happens to the money

It depends on how the family paid.

- **Card paid through Helcim or Stripe**: the money goes back to the card automatically. With Helcim, a same-day full refund is first tried as a **void**, so it disappears from the card statement instead of showing a charge and a credit. The family is told "Refund sent" and that it should reach their account in a few days.
- **Any other payment** (e-Transfer, cash, cheque, bank): the refund is recorded and the balance re-opens on the account, but no money moves by itself. The family is told their centre will arrange the payment. A box says **No money has been sent yet**; where Interac sending is set up, press **Send $… by Interac now**, or press **I'll arrange it myself**.

For an invoice that came from an external billing system, the balance is re-opened on the family's ledger rather than written back to the invoice, because that system overwrites its invoices on every sync.

## Refunding money with no payment record

Money that arrived through an integration may sit on an invoice with no receipt in KiddieTrac. A blue note says so. You can still refund it: the screen shows **Received**, **Refundable** and the **Invoice**, and the receipt is written the moment you approve, so the refund has something to reverse. Fill in the same fields and press **Issue refund**; the button then reads **Refund recorded**.

## Refund first, then void

An invoice with money paid against it cannot be voided. To cancel one, refund the money here first, then void the invoice in **Finance → Accounting**.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Refund any payment in the agency, from this screen or from Account ledgers. |
| Centre director | Refund payments for families they can see, from this screen. |
| Platform admin | The same as an agency admin, for the agency they are viewing. |
| Educator | Cannot open Refunds. The server answers "Only an agency admin or a centre director can approve a refund." |

## FAQs and when something goes wrong

??? The list says "Nothing on this agency has money that can be refunded". | No payment has money left to refund: nothing has been paid yet, or it has all been refunded. Check the invoice number with Find invoice to be sure.
??? "No invoice found with the number …" | Check the number on the invoice the family received, or choose the family from the list above.
??? "Cannot refund $…; only $… of this payment is left to refund." | Part of the payment was refunded already. Enter the amount shown or less.
??? "Type your full name to approve this refund." or "Please sign in the box to approve this refund." | Both your name and your signature are required. Fill in the missing one and press Issue refund again.
??? "This payment was taken through Helcim (or Stripe), but … is no longer set up for this agency" | The money cannot go back to the card automatically, so nothing was recorded. An agency admin can reconnect the provider in Settings → Payment providers, or arrange the refund another way.
??? The card provider refused the refund. | Its reason is shown in red and nothing is recorded. Read the reason (for example, the card was closed) and try again or arrange the payment another way.
??? Does the family hear about it? | Yes. Each guardian gets a notice in the app and an email: "Refund sent" when money went back to the card, "Refund approved" when the centre will arrange the payment.
??? Where can I see refunds later? | Pick the same payment again: Refund history lists every refund with its date, amount, reason and status. Agency admins also see it on the family's account in Account ledgers.

## Related screens

[[open: #external-billing | Accounting]] [[open: #billing-schedule | Billing schedule]]

See also: [Accounting](accounting) · [Account ledgers](account-ledgers) · [Payment providers](payment-providers)
