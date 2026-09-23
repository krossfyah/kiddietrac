---
title: Refunding a payment
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
---
# Refunding a payment

A refund is the one money movement with nobody on the other side confirming it. An invoice is agreed with the family; a payment arrives because somebody chose to send it. A refund is authorised by one person inside the agency and then simply happens — so it is signed for, and every one is recorded.

![The Refunds screen: choose the payment, or enter the invoice number](https://api.kiddietrac.com/help-img/refunds.png)

## Who can do it

**Agency admins and centre directors only.** An educator who opens the screen is refused, with a message saying why.

## Two ways in

**From the account ledger** — open the person's ledger and press **↩ Refund**. You see only their invoices, which is the safer route when you already know whose money it is.

**From the Refunds screen** — for when you are working from a number rather than a name.

- The **dropdown** lists everything on the agency with money that can still be refunded, showing the family, the refundable amount and the invoice number. Pick the person.
- Or type the **invoice number** — the one printed on the invoice the family was sent, like `iL-INV-1779383993378`. Partial numbers work.

> **One number can belong to several invoices.** `PINVO-05082026` is shared by eleven different families. When that happens every match is listed with the family named, and you choose. Nothing is picked for you.

## Partial or full

The amount defaults to everything still refundable and the cap is stated on screen. Type any smaller amount for a partial refund. Refunding more than remains is refused, and the message tells you exactly what is left:

> Cannot refund $1,512.00; only $567.00 of this payment is left to refund.

Already-refunded money is netted off. An invoice that received $1,680 and has had $420 returned shows **$1,260 refundable**.

## Approving it

Choose a reason, add any note, then **type your name and sign in the box**. Both are required — the signature is stored with the refund along with the date, the time and the address it came from.

That record is what turns *"somebody refunded $735"* into *"this named person approved this refund at this minute and put their name to it"*.

## What happens next

**The money does not move on its own.** The refund is recorded, the balance re-opens on the account, and the family is told their centre will arrange payment. Where Interac is available you are offered the transfer as a separate, deliberate step.

For a KiddieTrac invoice the balance is written back to the invoice itself. For an invoice that came from an external billing system it is not — that system overwrites those fields on every sync, so a refund written there would quietly disappear at the next poll. The ledger re-opens the balance instead, which nothing overwrites.

## Money with no payment record

Where a payment arrived through an integration, KiddieTrac may hold the invoice but no receipt. Those invoices are still refundable: the receipt is written the moment the refund is approved, so there is something for the refund to reverse. You do not have to do anything — it happens as part of approving.
