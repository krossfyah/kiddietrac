---
title: Payment schedules
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Payment schedules

A payment schedule spreads an agreed total across dated payments. It was previously called a *payment plan*; the wording changed, the screen did not.

![Payment schedules, with the invoice and due date for each payment](https://api.kiddietrac.com/help-img/payment-schedules.png)

## Creating one

**+ New payment schedule** opens in two steps.

**First**, the outline: choose the family from the list or search for them, then set the **first due date** and the **last due date**.

**Second**, the schedule itself. Every payment is listed with its date and amount, and **all of it is editable** before you save — change any amount, move any date, take a line out. Nothing is written until you confirm.

## What saving does

An invoice is raised for each payment on the schedule, and each one is **issued on the first of the month its payment falls in** — not all at once on the day you create the schedule. A family agreeing in June to pay through December is not sent seven invoices in June.

Until its issue date an invoice sits as a draft. A nightly job promotes each one on the morning it is due to be issued.

## Reading the table

Each payment shows its **invoice number**, its **due date** and one status.

That status is the **invoice's**, not the schedule line's. An imported schedule line stays `pending` forever because the system it came from does not update it — the invoice is what records whether the money arrived, so that is the one shown. Two statuses that disagree are worse than one that is occasionally terse.

## Cancelling a schedule

**Cancel schedule** lists every invoice that has not yet been paid and asks which to void. They are ticked by default, but you choose: a payment already on its way should stay open. Nothing is voided that you did not select, and paid invoices are never touched.

## Where they appear

A live schedule shows in the family's **account ledger** under **Coming up**, each payment naming the invoice behind it. See **[Account ledgers](account-ledgers)**.
