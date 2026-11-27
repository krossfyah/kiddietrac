---
title: Accounting (all invoices in one list)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
screens: external-billing
---
# Accounting

**Finance → Accounting** puts every invoice on your agency in one list, whoever it is with and wherever it was raised. From here you open invoices, record payments, confirm Interac e-Transfers, resend, edit and void. Open it when money comes in, when a family asks for their invoice again, or when an invoice needs to be cancelled.

[[open: #external-billing | Open Accounting]]

## What is in the list

Accounting reads all three places an invoice can come from, so no money is missing:

- **KiddieTrac invoices**: raised inside KiddieTrac, for example by the Bulk invoice run or a payment schedule. They are tagged **KIDDIETRAC** under the number.
- **Synced invoices**: raised in an outside billing system and synced in.
- **Payee invoices**: money billed to or paid to someone who is not a family, such as an educator or contractor. The row says **Raised in My Pay** instead of offering actions.

The **Outstanding** and **Collected** cards at the top add up whatever the list is showing, so they always describe the filter you chose.

> ℹ️ A pending payment-schedule invoice is listed but not counted as outstanding. Nobody has been asked for that money yet. See [Payment schedules](payment-plans).

## Finding an invoice

1. Pick a tab: **Outstanding** (everything except voided), **Paid**, **Overdue**, **Voided**, or **e-Transfers to confirm**.
2. In the first list, choose who the invoice is with: **Everyone**, **Parents**, **Educators** or **Providers** (the word follows your agency's setting), **Contractors**, or **Other** (anything not yet given a kind, listed so it gets checked).
3. Optional: pick one family in the family list. Choosing anything other than Parents clears the family filter, because a contractor has no family.
4. Optional: search by invoice number, description or status.

[[show: #external-billing @ Voided | Show me the Voided tab]]

## Recording a payment that arrived

1. Find the invoice and open its **⋮** menu.
2. Choose **Record payment**. The dialog shows the **Invoice total** and what is **Outstanding**.
3. Check the **Amount being settled**. It starts at the full balance; a partial payment is allowed.
4. Choose **How it was paid**: e-Transfer / EFT, Cash, Cheque, Bank transfer, Card (taken offline) or Other.
5. Set the **Date received** and type the **Reference number** (e-Transfer or cheque number). Add a **Note (optional)** if useful.
6. If your agency charges a surcharge for that method, a tick-box offers to add the service fee. It is off unless you tick it. See [Payment surcharges](payment-surcharges).
7. Press **Record payment**. The row's balance and status update.

## Confirming an Interac e-Transfer a family reported

When a family says in their billing screen that they sent an e-Transfer, it waits for you on the **e-Transfers to confirm** tab (the tab shows how many).

1. Open the **e-Transfers to confirm** tab, or use **⋮ > Confirm e-Transfer** on the invoice.
2. The **Confirm Interac e-Transfer** window shows who it is from, the **Amount sent**, the **Reference** and the invoices it covers.
3. Check your bank for that e-Transfer before you approve it.
4. If your bank shows a different amount, change **Amount received**.
5. Press **✅ Approve & record payment**. The payment is recorded on the invoice(s).
6. If it has not arrived, press **Reject**, write why under **Why? The family is sent this**, and press **Reject and tell the family**.

## Requesting an e-Transfer from a family

KiddieTrac never sends the money request itself; your centre does, from its own bank's Request money.

1. On an unpaid KiddieTrac or synced invoice, open **⋮ > Send e-Transfer request**.
2. The window lays out what your bank's form asks for, each with a **Copy** button, and the billing guardian's contacts under **Request it from**. Only contacts already on a guardian's profile are offered.
3. In your bank, open Interac e-Transfer → Request money and paste the details.
4. Back in KiddieTrac, press **Mark as requested**. The invoice records the request, and a family who asked for one is told to look in their bank app.

## Other row actions

**On a KiddieTrac invoice:** **View invoice** (staff see it without the family's Pay button), **Record payment**, **Resend invoice**, **Edit** (who it is for and what is on it), **Guardian copies** (each guardian's own copy, when the bill is split), **Download invoice** and **Void invoice**.

**On a synced invoice:** **View** and **Download** when the source provides a document, **Edit**, **Resend invoice**, **Record payment** and **Void invoice**.

> ⚠️ A synced invoice belongs to the billing system it came from. Editing or voiding it changes KiddieTrac's copy only, and that system's next sync may overwrite your change. Make the same change at the source if it needs to last.

## Voiding an invoice

1. Open the invoice's **⋮** menu and choose **Void invoice**.
2. Give the reason when asked and confirm.
3. The invoice keeps its number and stays in the list marked **Void**, under the **Voided** tab.

What else happens: if a payment schedule raised it, that instalment is retired from the schedule. The family is emailed to say it is cancelled and that they owe nothing, and the office is emailed too. Nothing is sent if your agency's email is switched off.

To void several, tick their rows and use the bulk bar (**Download** or void). Voiding several shows the total being cancelled first, and skips any invoice with money against it or already void.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Everything on this screen, for every centre |
| Centre director | Everything on this screen, for their own centres |
| Platform admin | Everything, for the agency chosen in the top-bar switcher |
| Educator, parent | No access. Parents see their invoices in their **Billing** tab |

## Common questions

??? Void invoice is missing or refused. | An invoice with money paid against it cannot be voided. Refund the money first (Finance → Refunds), then void. An invoice with an online payment still in progress also waits until that payment settles or fails.
??? Send e-Transfer request is missing. | It shows only on an invoice with a balance that is not void, paid, draft or refunded.
??? The request window says "No email or mobile on their profile." | Add an email or mobile number to the billing guardian's profile, then try again.
??? The list is empty. | Check the tab and the "who" filter. Voided invoices only show on the Voided tab; a family filter hides everyone else.
??? A pending instalment is listed but not in Outstanding. | It has not been issued yet, so nobody owes it. It counts once its notice period begins.
??? Does Record payment email the family? | On a KiddieTrac invoice, yes: the payer is emailed a receipt, and once the invoice is paid in full the family and you are told it is paid. Nothing is sent if your agency's email is switched off.
??? The e-Transfer window says "That e-Transfer no longer exists." | Someone else already dealt with it. Refresh the tab.

## Related screens

[[open: #payment-plans | Payment schedules]] [[open: #refunds | Refunds]] [[open: #billing-schedule | Billing schedule]]

See also: [Account ledgers](account-ledgers) · [Refunding a payment](refunds) · [Payment schedules](payment-plans) · [What an invoice status means](invoice-statuses)
