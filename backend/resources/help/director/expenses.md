---
title: Expenses (suppliers, purchase orders and bills)
category: Billing
order: 85
roles: agency_admin, centre_director, platform_admin
screens: expenses
---
# Expenses (suppliers, purchase orders and bills)

**Finance → Expenses** is the money your agency or centre pays out. Keep a list of suppliers, raise purchase orders, record the supplier's bill when it arrives, approve it and record each payment. Open it when an invoice from a vendor lands on your desk or when you want to see what is still owed.

[[open: #expenses | Open Expenses]]

The screen has four tabs: **📊 Summary**, **🧾 Bills**, **📦 Purchase orders** and **🏢 Suppliers**.

## Reading the summary

1. Open **Finance → Expenses**. The **📊 Summary** tab opens first.
2. Read the tiles: **Outstanding** (the unpaid bill balance), **Overdue** (the amount past its due date and how many bills), **Paid this month**, **Suppliers** and **Open POs** (purchase orders that are drafts or ordered).
3. Scroll down to **By status** and **Spend by category** to see where the money goes.

## Adding a supplier

1. Open the **🏢 Suppliers** tab.
2. Press **+ New supplier**.
3. Type the **Supplier name** (the only required field).
4. Add the **Contact name**, **Email**, **Phone**, **Address** and **Tax / business #** if you have them.
5. Pick the **Centre** the supplier serves, or leave **All centres**, and a **Default category** so new bills fill it in for you.
6. Press **Create supplier**. A **Supplier saved** toast appears and the supplier joins the list, showing what you still owe them.

> 💡 A supplier you no longer use: open its row menu and choose **🗄 Archive**. It stays on past bills and can be brought back with **♻️ Restore**. Delete only a supplier entered by mistake.

## Raising a purchase order

[[show: #expenses @ Purchase orders > + New purchase order | Show me New purchase order]]

1. Open the **📦 Purchase orders** tab.
2. Press **+ New purchase order**.
3. Choose the **Supplier**, and the **Centre** if the order is for one centre.
4. Set the **Order date** and the **Category**.
5. Under **Line items**, enter a **Description**, **Qty** and **Unit $** for each item, then any **Tax ($)**. The subtotal and total update as you type.
6. Press **Create PO**. A **PO created** toast appears and the order joins the list.

## Turning an order into a bill

1. On the **📦 Purchase orders** tab, open the order.
2. When the supplier's invoice comes in, press **→ Convert to bill**. The bill is created from the order's lines, so nothing is typed twice, and the screen moves to **🧾 Bills**.
3. If the order will not go ahead, choose **🚫 Cancel order** from its row menu instead.

> ⚠️ **→ Convert to bill** is offered only until the order is marked received or cancelled. Convert the order first; use **Mark received** only for an order that will not get its own bill.

## Recording a bill

1. Open the **🧾 Bills** tab.
2. Press **+ New bill**.
3. Choose the **Supplier**. Its default category fills in.
4. Choose the **Centre**, type the **Supplier invoice #** and set the **Due date**.
5. Enter the **Line items** and any **Tax ($)**.
6. Press **Create bill**. A **Bill created** toast appears.

Use the status filter above the list to see only **Draft**, **Awaiting approval**, **Approved**, **Partially paid**, **Overdue** or **Paid** bills.

## Approving and paying a bill

1. On the **🧾 Bills** tab, open the bill (or choose **👁 View** from its row menu).
2. Press **✓ Approve**. A **Bill approved** toast appears.
3. When you pay, press **💵 Record payment**.
4. Enter the **Amount**, choose the **Method** (**Bank transfer**, **Cheque**, **e-Transfer**, **Credit card**, **Cash** or **Other**) and add a **Reference (optional)** such as the cheque number.
5. Press **Record payment**. A **Payment recorded** toast appears. A partial payment leaves the rest as the balance and the bill shows **Partially paid**.

> ℹ️ Recording a payment only records that you paid. KiddieTrac does not send money to the supplier.

To cancel a bill you will not pay, choose **🚫 Void** from its row menu and confirm. It stays on record as **Void**.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Everything, for every centre and agency-wide records. |
| Centre director | Adds and changes suppliers, orders and bills for their own centres. Sees agency-wide suppliers and records but cannot change them. |
| Platform admin | The same as an agency admin, in the agency they are working in. |

Educators and parents do not see this screen. Expenses is part of your KiddieTrac plan; if your agency's plan does not include it, the screen is not in the menu.

## FAQs and when something goes wrong

??? I can't find Expenses in the menu. | It is under **Finance** for agency admins and centre directors. If it is missing, your agency's plan does not include Expenses: ask your agency admin.
??? The save says "Choose one of your centres." | As a centre director you can only save records for your own centres. Pick one of them in **Centre**.
??? "Only a record of one of your centres can be changed." | The bill or order belongs to another centre or to the whole agency. Ask your agency admin to change it.
??? "Choose a supplier" or "Add at least one line item" | A bill or order needs a supplier and at least one line with an amount. Add them and save again.
??? The list says "No bills yet." | Nothing has been recorded for your centres. Press **+ New bill**, or convert a purchase order.
??? Does anyone get an email when I approve or pay a bill? | No. Expenses sends no emails or notifications. Every change is kept in the audit log.
??? Can I link a bill to a grant? | Yes. On **Finance → Grants**, record spending and pick the bill under **Linked expense bill (optional)**.

## Related screens

[[open: #grants | Grants]] [[open: #external-billing | Accounting]]

See also: [Grants](grants) · [Accounting](accounting) · [Payroll runs, hours and payslips](payroll-documents)
