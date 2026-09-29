---
title: Expenses (suppliers, purchase orders and bills)
category: Billing
order: 85
roles: agency_admin, platform_admin
---
# Expenses

**Finance → Expenses.** This is the money your agency pays out. Track suppliers, purchase orders and supplier bills, from order to approval to payment.

[[open: #expenses | Open Expenses]]

The screen has four tabs.

## 📊 Summary

- **Outstanding**: the unpaid balance across all bills.
- **Overdue**: the amount past its due date, and how many bills.
- **Paid this month**, **Suppliers**, and **Open POs** (drafts and orders not yet received).

Below the tiles, spending is broken down **By status** and **Spend by category**.

## 🏢 Suppliers

**+ New supplier** records a vendor: **Supplier name** (required), **Contact name**, **Centre**, **Email**, **Phone**, **Default category**, **Tax / business #**, **Address**, and an **Active** switch. The list shows what you still owe each supplier. Click a supplier to edit or delete it.

> **Tip:** Untick **Active** for a supplier you no longer use. It stays on record with its history instead of being deleted.

## 📦 Purchase orders

[[show: #expenses @ Purchase orders > + New purchase order | Show me New purchase order]]

**+ New purchase order**: choose the **Supplier** and optionally a **Centre**, set the **Order date** and **Category**, then add the **Line items** and any tax. **Create PO** saves it as ordered.

Open a purchase order to move it along:

- **Mark received** when the goods arrive.
- **→ Convert to bill** when the supplier's invoice comes in. This creates the bill from the order, so nothing is typed twice. It is offered until the order is marked received.
- **Edit** to change it.

## 🧾 Bills

Bills are supplier invoices. **+ New bill** records one with its **Supplier**, **Centre**, **Supplier invoice #**, **Category**, **Due date** and **Line items**. You can filter the list by status: **Awaiting approval**, **Approved**, **Partially paid**, **Overdue**, **Paid** or **Draft**.

Open a bill to act on it:

- [ ] **✓ Approve**: for a draft bill or one awaiting approval.
- [ ] **💵 Record payment**: enter the **Amount**, the **Method** (bank transfer, cheque, e-Transfer, credit card, cash or other) and an optional **Reference**. A partial payment leaves the rest showing as due.
- [ ] **Void**: cancels a bill you should not pay. You are asked to confirm.

> **Note:** Recording a payment here records that you paid. It does not send money to the supplier.

## Who can use this

It is on the **Finance** menu for **agency admins** and **platform admins**.

See also: [Accounting](accounting) · [Payroll runs, hours and payslips](payroll-documents)
