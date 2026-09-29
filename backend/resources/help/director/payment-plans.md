---
title: Payment schedules
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Payment schedules

**Finance → Payment schedules.** A payment schedule spreads an agreed total across dated payments, and each payment raises its own invoice shortly before it falls due. The feature used to be called a *payment plan*; only the name changed.

[[open: #payment-plans | Open Payment schedules]]

![Payment schedules, with the invoice and due date for each payment](https://api.kiddietrac.com/help-img/payment-schedules.png)

## Creating one

[[show: #payment-plans @ + New payment schedule | Show me the New payment schedule button]]

**+ New payment schedule** works in two steps.

**First**, the outline. Choose the family, then enter the **Total amount**, how often a payment falls due (**Every** month, 2 weeks or week), and the **First due** and **Last due** dates. Fill in **What is this for?**, which is printed on every invoice as the line the total is for. You can also add **Extra charges** and internal **Notes**, which are never printed.

Under **When is each instalment due?**, choose one of these:

- **Give notice**: the invoice goes out a set number of days before each due date. The number starts at your agency's setting (default **5**) and can be changed for this schedule.
- **Due immediately**: each invoice is issued on its due date and owed the same day.

**Second**, the review. Every payment is listed with its date and amount, and **all of it can be edited** before you save: change any amount, move any date, or add or remove a line. Nothing is written until you confirm.

Before saving, choose **When do these go out?**:

- **Keep as drafts**: each invoice stays pending until its notice period begins, then issues automatically. Nothing is owed before then.
- **Issue now**: every invoice is issued today and owed straight away. Due dates do not change.

## When each invoice is issued

Every payment raises an invoice when you save, held as a **Pending** draft. A daily run issues each one on the morning its notice period begins. For example, with 5 days' notice, an instalment due on the 23rd is issued on the 18th. A family agreeing in June to pay through December is not sent seven invoices in June.

Until it is issued, a pending invoice is not owed: it is left out of the family's balance, the account ledger's outstanding figures and every billing reminder. The due date is never moved; only the amount of notice changes.

The agency-wide default is set under **Settings → Billing → Defaults & fees → Payment schedules**. See [Billing setup & settings](billing-setup).

> **Note:** Issuing does not email anything. The invoice appears in the family's Billing tab. Use **Resend** if you also want to email it to them.

## Reading the table

| Column | What it shows |
|---|---|
| **Due date** / **Amount** | the payment itself |
| **Invoice** | the invoice number behind it |
| **Issued** | the issue date. On a pending row it is greyed and marked *scheduled*, because it has not happened yet |
| **Issued by** | who put the invoice out. On a pending row, the person who built the schedule is shown greyed. **Automatic** means the daily run issued it, and **Imported** means it came from an outside billing system |
| **Status** | the invoice's status, in the same coloured pill as Accounting |

The statuses you will see:

- **Pending**: not issued yet. It is waiting its turn.
- **Scheduled**: issued, but not due yet. Nothing has been missed.
- **Issued**: issued and due now.
- **Partly paid**, **Paid**, **Overdue** and **Void** mean the same here as everywhere else.

The status shown is the **invoice's**, not the schedule line's. The invoice is what records whether the money arrived.

## Row actions

Each row's **⋮** menu offers only what applies to that row:

- **View invoice** and **Download invoice**
- **Issue now**: pending rows only. It issues the invoice today; no email is sent
- **Resend**: issued rows only. You confirm the email address first
- **Edit instalment**: pending rows only. It changes this one payment without touching the rest of the schedule

To change the whole schedule, use **Edit schedule** on the schedule card. That withdraws and re-raises the payments that have not been issued yet.

**Select several rows** to act on them together: **Issue now**, **Download** or **Delete**. Only pending rows can be issued or deleted. Rows already issued are skipped, and the message tells you how many.

## Voiding a payment

Voiding is done from **Accounting**, not from this screen, because it withdraws a document and takes money off a family's balance. When you void an invoice that a schedule raised, **its instalment is retired with it**. It leaves the schedule, and the schedule's total and instalment count are recalculated, so the schedule never counts money that no longer exists. See [Accounting](accounting).

## Cancelling a schedule

**Cancel plan** lists the schedule's invoices. Pending ones are ticked for withdrawal, and you can untick any you want to keep. Invoices that have **already been issued cannot be withdrawn here**, because a payment may already be on its way. Void those deliberately from Accounting if that is really what you want.

## Where they appear

A live schedule shows in the family's **account ledger** under **Coming up**, with each payment naming the invoice behind it. See [Account ledgers](account-ledgers).

## Common questions

??? Why hasn't a pending invoice gone out yet? | It issues on the morning its notice period begins, which is the due date minus the notice days. Use Issue now to send it early.
??? Can I change one payment's amount? | Yes, while it is still pending: use Edit instalment in the row's menu. Once issued, void it in Accounting and add a replacement.
??? Does changing the agency's notice setting affect existing schedules? | A schedule keeps the notice period chosen when it was created, including when it is edited. The new setting is the starting value for new schedules.

See also: [Accounting](accounting) · [Account ledgers](account-ledgers) · [What an invoice status means](invoice-statuses)
