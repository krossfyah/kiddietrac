---
title: Payment schedules
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
screens: payment-plans
---
# Payment schedules

**Finance → Payment schedules** spreads an agreed total across dated payments, and each payment raises its own invoice shortly before it falls due. Use it when a family agrees to pay a balance, a registration fee or a term's fees in instalments. (It used to be called a *payment plan*; only the name changed.)

[[open: #payment-plans | Open Payment schedules]]

![Payment schedules, with the invoice and due date for each payment](https://api.kiddietrac.com/help-img/payment-schedules.png)

At the top, **📊 Insights** shows active schedules, what is due in the next 30 days, missed payments, how families pay and expected collections, for your agency (or your centres, for a director). Filter it by **Centre** and **Status**.

## Creating a payment schedule

[[show: #payment-plans @ + New payment schedule | Show me the New payment schedule button]]

1. Press **+ New payment schedule**.
2. Choose the **Family**.
3. Enter the **Total amount ($)** and choose how often a payment falls due under **Every**: **Month**, **2 weeks** or **Week**.
4. Set the **First due** and **Last due** dates.
5. Fill in **What is this for?**. It is printed on every invoice. You can use {child}, {service}, {month} and {period} in the wording; if it does not name the child, the child's name is added.
6. Under **When is each instalment due?**, choose **Give notice** (the invoice goes out a set number of days before each due date, starting at your agency's setting, default 5) or **Due immediately** (each invoice is issued on its due date).
7. Optional: add **Extra charges (optional)** with **+ Add line**, and internal **Notes (optional)**, which are never printed.
8. Press **Build the schedule**. The review opens.
9. Check every payment. You can change any amount, move any date, **Remove** a line or **+ Add instalment**. Nothing is written yet.
10. Under **When do these go out?**, choose **Keep as drafts** (each invoice stays pending until its notice period begins) or **Issue now** (every invoice is issued today and owed straight away; due dates do not change).
11. Press **Save schedule**. The schedule card appears with a row per payment.

## When each invoice is issued

Every payment raises an invoice when you save, held as a **Pending** draft. A daily run issues each one on the morning its notice period begins: with 5 days' notice, an instalment due on the 23rd is issued on the 18th. A family agreeing in June to pay through December is not sent seven invoices in June.

Until it is issued, a pending invoice is not owed: it is left out of the family's balance, the account ledger's outstanding figures and every billing reminder. The due date never moves; only the notice changes. The agency default is under **Settings → Billing → Defaults & fees → Payment schedules** ([Billing setup & settings](billing-setup)).

> ℹ️ Issuing does not email anything. The invoice appears in the family's Billing tab. Use **Resend** if you also want to email it.

## Reading the table

| Column | What it shows |
|---|---|
| **Due date** / **Amount** | the payment itself |
| **Invoice** | the invoice number behind it |
| **Issued** | the issue date; on a pending row it is greyed and marked *scheduled* |
| **Issued by** | who put the invoice out. **Automatic** means the daily run issued it; **Imported** means it came from an outside billing system |
| **Status** | the invoice's status, in the same coloured pill as Accounting |

- **Pending**: not issued yet, waiting its turn.
- **Scheduled**: issued, but not due yet. Nothing has been missed.
- **Issued**: issued and due now.
- **Partly paid**, **Paid**, **Overdue** and **Void** mean the same as everywhere else.

The status shown is the invoice's, not the schedule line's: the invoice records whether the money arrived.

## Working with one payment

1. Pick the family in the **Family** list (the screen remembers your last choice).
2. On the payment's row, open **⋮**.
3. Choose what you need. Only actions that apply to that row appear:
   - **View invoice** or **Download invoice**;
   - **Issue now** (pending rows only): issues it today, with no email;
   - **Resend** (issued rows only): you confirm the email address first;
   - **Edit instalment** (pending rows only): changes this payment without touching the rest.

To act on several, tick their rows and use **Issue now**, **Download** or **Delete**. Only pending rows can be issued or deleted; the message tells you how many were skipped.

## Changing or cancelling a whole schedule

1. On the schedule card, press **Edit schedule** to change the outline. This withdraws and re-raises the payments not yet issued, and keeps the notice period the schedule was created with.
2. To stop a schedule, press **Cancel plan**. Its invoices are listed, with pending ones ticked for withdrawal; untick any you want to keep.
3. Confirm. Invoices already issued cannot be withdrawn here, because a payment may be on its way. Void them deliberately from **Accounting** if that is really what you want.

Voiding is always done in [Accounting](accounting). When you void an invoice a schedule raised, its instalment is retired: it leaves the schedule and the total and instalment count are recalculated.

## Who can do what

- **Agency admins** and **platform admins**: create, edit and cancel schedules for any family in the agency.
- **Centre directors**: the same for families at their own centres; Insights covers their centres only.
- **Educators**: no access.
- **Parents**: see their own schedules, read-only, and pay the invoices from their Billing tab.

A live schedule also shows in the family's **account ledger** under **Coming up**. See [Account ledgers](account-ledgers).

## Common questions

??? Why hasn't a pending invoice gone out yet? | It issues on the morning its notice period begins: the due date minus the notice days. Use Issue now to send it early.
??? Can I change one payment's amount? | Yes, while it is pending: use Edit instalment in the row's menu. Once issued, void it in Accounting and add a replacement.
??? Does changing the agency's notice setting affect existing schedules? | No. A schedule keeps the notice period chosen when it was created, including when edited.
??? The screen says "Select a family above to see their payment schedules." | Pick a family in the Family list. A platform admin may first need to pick an agency with the top-bar switcher.
??? Error "That range does not contain a single due date." | The first and last due dates are too close for the frequency you chose. Widen the range or pick a shorter frequency.
??? Error "Say what this is for — it is printed on every invoice." | Fill in What is this for? before building the schedule.
??? Insights says "Insights are for directors and administrators of this agency." | Your role or active agency cannot see agency figures. Switch to the right agency or ask your admin.

## Related screens

[[open: #external-billing | Accounting]] [[open: #billing-schedule | Billing schedule]]

See also: [Accounting](accounting) · [Account ledgers](account-ledgers) · [What an invoice status means](invoice-statuses) · [Billing setup & settings](billing-setup)
