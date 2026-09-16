---
title: Payroll runs, hours and payslips
category: Staff Management
order: 30
roles: agency_admin, platform_admin
---
# Payroll runs, hours and payslips

One screen answers both halves of the same question: what did this person work, and what were they paid for it.

![Payroll: Educators, Other staff and Contractors, each with their payslips](https://api.kiddietrac.com/help-img/payroll.png)

## Three groups

**Educators**, **Other staff** and **Contractors** are separate tabs, not one long list. They are paid on different terms and signed off by different people, and each carries its own subtotal. The screen opens on the first tab that has anybody in it.

The overall **Total hours** across both staff groups stays visible underneath.

## Contractors are different, deliberately

A contractor does not clock in. They hold no login and are paid by name, so there are no punches and no hours to report — showing them beside people with punch counts would put a blank column and zero hours against money that was really paid.

Their tab reports **what they were paid** in the period instead, and says so on the page.

## Somebody paid without clocking in

The report includes anyone **paid** in the period, not only anyone **clocked**. A home visitor paid per visit, a salaried manager, anyone on a flat amount — they appear with 0 punches and 0 hours, which is true of them, and their payslips.

Without that, a month in which nobody happened to punch a clock rendered as an empty report while payslips existed for it.

## Payslips on the row

The **Payslips** column shows how many documents a person has in the period and what they add up to. Open it and each document is listed underneath with its own **⋮** menu:

- **Payslip** — opens the PDF.
- **Print** — opens it and sends it to the printer.
- **Download** — saves the PDF.
- **Email payslip** — sends it to an address you confirm, with the PDF attached.
- **Send by Interac** — pays it, if the person has an account (see below).
- **Mark as paid** / **Mark unpaid** — the manual record, which toggles.

Emailing asks for the address every time. It is prefilled where one is on file, and it must be confirmed — there is no default recipient for somebody's pay.

## Paying a payslip

**Send by Interac** moves real money, so it confirms first and names the amount and the person:

> Send $1,424.90 by Interac to Bruni Meeser? This moves real money.

The payment is tied to the payslip it settles, so the record shows what was paid and for what.

**Contractors cannot be paid this way.** Interac sends to a person's account, and a contractor is a name rather than an account. Their payslips are marked paid by hand — everything else on the menu works normally.

## Generating payroll

**＋ Generate payroll** opens the run wizard: pick the period, tick the people, and hours are calculated from their closed clock punches. Add line items and notes, approve, and payslips are written.

Only **closed** punches count. Anyone still clocked in is named on their row so you can see what was left out.

**The rate is never filled in for you.** It starts empty and somebody types it, every run. The historical figures cannot tell an hourly rate from a whole period's pay — the data says "hours" against amounts that are plainly not hourly — and multiplying by the wrong one produces a payslip nobody meant to approve.

## The CSV export

**⤓ CSV** exports the hours report for the period. It is an hours file, so it lists people who clocked in; payslips for people with no punches are on the screen rather than in it.

## Where the hours come from

Every hour on this screen was a punch on the time clock. **Timesheets** is where those
punches are read and corrected, one **⋮** per shift — and it is the place to settle an
open shift or a missing one *before* a payroll run rather than after payslips exist.
See [Timesheets and correcting hours](timesheets).
