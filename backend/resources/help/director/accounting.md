---
title: Accounting (all invoices in one list)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
---
# Accounting

**Finance → Accounting.** Every invoice on the agency is in one list, whoever it is with and wherever it was raised. From here you can open invoices, record payments, resend, edit and void them.

[[open: #external-billing | Open Accounting]]

## What is in the list

Accounting reads all three places an invoice can come from, so no money is missing from it:

- **KiddieTrac invoices**: raised inside KiddieTrac, for example by a monthly run or a payment schedule. They are tagged **KIDDIETRAC** under the number.
- **Synced invoices**: raised in an outside billing system and synced in.
- **Payee invoices**: money billed to or paid to somebody who is not a family, such as an educator or a contractor. These are raised in **My Pay**, and the row says **Raised in My Pay** instead of offering actions.

The **Outstanding** and **Collected** cards at the top add up whatever the list is showing, so they always describe the filter you have chosen.

> **Note:** A pending payment-schedule invoice is listed but **not counted as outstanding**. Nobody has been asked for that money yet. See [Payment schedules](payment-plans).

## Filtering

**Tabs**: **Outstanding** (everything except voided), **Paid**, **Overdue** and **Voided**. Voided invoices are kept out of the default view because they are no longer owed, but they are one click away.

[[show: #external-billing @ Voided | Show me the Voided tab]]

**Who the invoice is with**: the first list narrows the invoices to one kind of counterparty:

- **Everyone**
- **Parents**: every family-billed invoice, whether KiddieTrac or synced
- **Educators** or **Providers**: the word follows your agency's setting for what its facilities are called
- **Contractors**
- **Other**: anything that has not been given a kind. It is listed rather than hidden, because an unlabelled row is exactly the one worth checking

Choosing anything other than Parents clears the **family** filter, because a contractor has no family.

**Family** and **search** (invoice number, description or status) work on whatever is selected.

## Row actions

**On a KiddieTrac invoice:**

- **View invoice**: opens the invoice sheet. Staff see the invoice without the family's **Pay** button, so nobody pays a family's invoice on their own card by mistake.
- **Record payment**: see below.
- **Resend invoice**
- **Edit**: changes who the invoice is for and what is on it.
- **Download invoice**
- **Void invoice**

**On a synced invoice:** **View** and **Download** (when the source provides a document), **Edit**, **Resend invoice**, **Record payment** and **Void invoice**.

> **Important:** A synced invoice belongs to the billing system it came from. Editing or voiding it changes KiddieTrac's copy only, and that system's next sync may overwrite your change. Make the same change at the source if it needs to last.

## Recording a payment

**Record payment** opens a dialog that shows the invoice total and what is outstanding. Enter:

- **Amount being settled**. As you type, it tells you whether this pays the invoice in full, leaves a balance, or is more than is owed. A partial payment is allowed.
- **How it was paid**: e-Transfer / EFT, Cash, Cheque, Bank transfer, Card (taken offline) or Other.
- **Date received**, a **Reference number**, and an optional **Note**.

If your agency charges a card or EFT surcharge, a tick-box appears offering to add the service fee. See [Payment surcharges](payment-surcharges).

## Voiding an invoice

**Void invoice** cancels the invoice for good, after you confirm and give a reason:

- It keeps its number and stays in the list marked **Void**.
- If a payment schedule raised it, **that instalment is retired** from the schedule.
- The family is emailed to say it is cancelled and that they owe nothing, and the office is emailed too. Nothing is sent if your agency's email is switched off.

An invoice with money paid against it cannot be voided. Refund the money first, then void. An invoice with an online payment still in progress also cannot be voided until that payment settles or fails.

## Working on several at once

Tick rows to act on them together: **Download** and **Void**. Voiding several shows the total being cancelled first, and skips any invoice that has money against it or is already void.

## Who can use this

**Agency admins**, **centre directors** and **platform admins**.

See also: [Account ledgers](account-ledgers) · [Refunding a payment](refunds) · [Payment schedules](payment-plans) · [What an invoice status means](invoice-statuses)
