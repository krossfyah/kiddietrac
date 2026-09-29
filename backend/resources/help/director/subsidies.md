---
title: Subsidies — CWELCC and provincial fee subsidy
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
---
# Subsidies — CWELCC and provincial fee subsidy

**Sidebar → Subsidies** has two tabs: **CWELCC** and **Provincial subsidy**. The screen
remembers the tab you were on.

[[open: #cwelcc | Open Subsidies]]

Agency admins see the whole agency. A centre director sees and manages only their own
centres.

## CWELCC

The monthly CWELCC report for families enrolled in CWELCC: each child's gross fees, the
subsidy and the parent portion, with totals. Pick a **Month** to see another month, and
download it with **⤓ CSV** or **⤓ PDF** for your claim.

[[show: #cwelcc @ CWELCC | Show me the CWELCC tab]]

## Provincial subsidy

A child's **provincial fee subsidy**: the case number from the approval letter, the
monthly amount, and the dates it applies. **While it applies, the monthly amount comes
off that child's tuition on the family's invoice** — the family is only billed the rest.

[[show: #cwelcc @ Provincial subsidy | Show me the Provincial subsidy tab]]

### Recording one

- [ ] Press **＋ Add subsidy**.
- [ ] Choose the **child**.
- [ ] Enter the **case number** and the **monthly amount**.
- [ ] Set the **first day**, and the **last day** if the approval has one.
- [ ] Press **Save**.

The list shows each child's tuition beside the subsidy, and turns the amount red if the
subsidy is more than the tuition — worth a second look.

### When it changes

- **Edit** corrects the amount, case number or dates.
- **End subsidy** sets its last day. Invoices issued after that day no longer include it.
  Use this when a subsidy stops, so its history is kept.
- **Remove** is only for a subsidy entered by mistake.

All three are in the row's **⋮** menu.

> **Important:** A child can have one subsidy at a time, because invoices apply one per child. If the dates overlap an existing subsidy, the save is refused and names the one in the way. End the old one first — for a renewal, end it on the day before the new one starts.

> **Note:** Changes apply to invoices generated from now on. Invoices already issued keep the amounts they were issued with.

### The monthly list

The tab shows the subsidies that apply in the chosen **Month**, with the number of
children and the month's total. Tick **Show every subsidy, including ended** to see the
full history. **⤓ CSV** downloads the month for reconciling with the province or
municipality.

Every add, edit, end and removal is written to the audit log with the child, case number,
amount and dates.

## Questions

??? Where did "CWELCC subsidies" go? | It is now Subsidies, with CWELCC as the first tab. The report is unchanged.
??? Does a provincial subsidy show on the parent's invoice? | Yes. The invoice shows the tuition, a Subsidy line taking the amount off, and the balance the family owes.
??? Can a child have CWELCC and a provincial subsidy? | The CWELCC report is worked out from the family's CWELCC enrolment. On an invoice, a child has one subsidy line at a time, and the Provincial subsidy tab refuses an overlapping one.

See also: [Generating monthly invoices](generating-monthly-invoices),
[Invoice statuses](invoice-statuses).
