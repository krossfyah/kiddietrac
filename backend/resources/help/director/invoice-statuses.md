---
title: What an invoice status means
category: Billing
order: 41
roles: agency_admin, centre_director, platform_admin
---
# What an invoice status means

![Invoices, with future-dated ones marked Scheduled](https://api.kiddietrac.com/help-img/invoices-scheduled.png)

Accounting and the payment schedules show the same coloured pill with the same word, so an invoice reads the same on both screens.

| Status | Meaning |
|---|---|
| **Pending** | Not issued yet. A payment-schedule invoice waiting for its issue date. Nobody owes it yet. |
| **Scheduled** | Issued, unpaid, and **not due yet**. Nothing has been missed. |
| **Issued** | Issued, due now or already past its date, and unpaid. |
| **Overdue** | Past its due date. |
| **Partly paid** | Some money has been received against it. |
| **Paid** | Settled. |
| **Refunded** | Money has been given back. |
| **Void** | Cancelled. Carries no balance. |

## Why Scheduled exists

Most unpaid invoices at any moment are simply not due yet. Calling those "open" buries the ones that actually need chasing — on this platform 159 of 166 open invoices were future-dated, so a list of 166 items was really a list of 7.

**Scheduled** says the true thing: it is on the calendar and nobody has missed anything.

## How it is worked out

It is not stored. An invoice becomes due by the passage of time, so the status is worked out **when the screen is drawn**, from the due date against today in your agency's timezone. It can never go stale, and there is no nightly job to fail.

Three statuses are deliberately never relabelled:

- **Partly paid** — money has already been received; that fact outranks the calendar.
- **Pending** — not issued at all, which is a different thing from not yet due.
- **Overdue** — past its due date by definition.

An invoice due **today** stays Issued. It is due; it just is not late.

## What it does not change

Only the word changes. Outstanding totals still include scheduled money, because it is still owed eventually — the ledger reports **Overdue** separately when you want only what is late. **Pending** invoices are the exception: they have not been issued, so they are not counted as outstanding anywhere.

See also: [Payment schedules](payment-plans) · [Accounting](accounting) · [Account ledgers](account-ledgers)
