---
title: Payment surcharges (card and EFT service fees)
category: Billing
order: 14
roles: agency_admin, centre_director, platform_admin
---
# Payment surcharges

**Settings → Billing → Defaults & fees → Payment surcharges.** You can pass the processing cost of card and bank payments on to families as a visible service fee.

[[show: #billing-settings @ Defaults & fees > Payment surcharges | Show me the surcharge settings]]

## The two rates

- **Credit / debit card (%)**: used when a payment is recorded as **Card (taken offline)**.
- **EFT / Interac e-Transfer (%)**: used when a payment is recorded as **e-Transfer / EFT** or **Bank transfer**.

There are two rates because the two methods cost you very different amounts. Each rate can be between 0 and 10%. Both start at **0**, which means you absorb the cost and nothing is added.

**Cash and cheque are never surcharged**, and neither is **Other**. With no processor to pay, a fee on cash would be a fee for nothing.

## When the fee is added

A surcharge is **never added automatically**. It is added only when somebody records a payment and ticks the box for it:

1. In **Accounting**, open the invoice's **Record payment**.
2. Enter the **Amount being settled** and choose **How it was paid**.
3. If that method has a rate, a box appears showing the fee in dollars and the total to collect. Tick it to add the fee.
4. Click **Record payment**.

If the method has no rate, or the rate is 0, the box does not appear at all.

> **Important:** No service fee is added to payments that families make online through the portal. The surcharge applies only to payments your staff record.

## How the fee is worked out

The fee is a **percentage of the payment, not of the invoice**. A family paying $500 now and $500 next month pays the fee on each $500. That is also how the processor bills you.

The amount you enter is what is being settled, and the fee is charged on top of it. For example, at 2.9%, settling a $500.00 balance adds a $14.50 fee. The payment is recorded as $514.50, and the invoice clears to zero.

> **Tip:** Enter the balance you are settling, not the fee-inclusive total. The dialog adds the fee for you.

## What the family sees

On a KiddieTrac invoice, the fee is added as its own line, for example **Service fee (2.9% card)** or **Service fee (0.5% e-Transfer / EFT)**. Their receipt lists the service fee separately.

On an invoice that was synced in from an outside billing system, there is no KiddieTrac line to add. The fee is added to the invoice total instead. That billing system remains the system of record, so its next sync may reset the total.

## Common questions

??? Why is the fee box missing from the Record payment dialog? | The chosen method has no surcharge rate (cash, cheque and Other never do), or the rate for that method is 0.
??? Is the fee charged on a partial payment? | Yes. It is charged on whatever amount you enter as being settled, so each part payment carries its own fee.
??? Does changing a rate affect fees already charged? | No. A fee is worked out when the payment is recorded and saved on the invoice then.

See also: [Billing setup & settings](billing-setup) · [Accounting](accounting) · [Payment providers](payment-providers)
