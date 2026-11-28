---
title: Accounting AR/AP (billing agencies for KiddieTrac)
category: Billing
order: 90
roles: platform_admin
screens: sales-invoices
---
# Accounting AR/AP (billing agencies for KiddieTrac)

**Platform › 🏦 Accounting AR/AP** is KiddieTrac's own books: the invoices agencies owe for the platform, the payments they made, extra charges and credits, the billing plan behind each agency, and what KiddieTrac spends. It is separate from the invoices agencies send to families. Only platform super-admins can open it.

[[open: #sales-invoices | Open Accounting AR/AP]]

The tabs across the top are **🧾 Invoices**, **💳 Payments**, **➕ Extra line items**, **🤝 Referral credits**, **🏷️ Billing plans**, **⚙️ Settings** and **📤 Expenses (AP)**.

## Raising the invoices that are due

1. Open **🧾 Invoices**.
2. Press **Raise invoices due now**.
3. Read the preview: each agency that will be billed and the amount, and under **Not due** the agencies skipped and why.
4. Press **Raise invoices** to create them, or **Cancel** to stop. Nothing is created until you confirm.
5. The new invoices appear under **Open** as drafts. Each agency's next invoice date moves on by its billing interval.

> ℹ️ An agency with no next invoice date is not on recurring billing and is never billed automatically. Licence renewals bill here too.

## Making a one-off invoice

1. Press **＋ New invoice**.
2. Choose the **Agency**, set the **Due date**, **Tax %** and **Tax name** (a rate above 0 needs a name such as HST).
3. Add lines with **＋ Add a line**: **Description**, **Qty**, **Price** and **Taxed**. The subtotal, tax and total update as you type.
4. Add a **Note on the invoice (optional)** and press **Save draft**. A toast says **Draft … created**.

## Working with an invoice

The **Open**, **Paid** and **Voided** tabs each show their count, with billed, collected, outstanding, tax and overdue tiles per currency. A ⚠ beside **Due** means overdue. Each row offers only what applies:

1. **View invoice** opens the PDF (on every row, even void ones).
2. **Issue** (drafts only) records the issue date. It does not email anything.
3. **Email invoice** asks for the address every time; if you give none, nothing is sent. It works on any invoice that is not void, so you can resend a copy.
4. **Record payment** records money received.
5. **Lines & payments** adds a line, removes an extra, uses **Waive late fees**, or marks a payment **Returned (NSF)…**.
6. **Edit invoice** changes the lines, due date and tax, with a **Reason for the change** kept in the audit log.
7. **Reissue** voids the invoice and raises a corrected copy from what is licensed now; **Void** cancels it but keeps it on record, dimmed, no longer owed.
8. **Refund…** appears once a payment is recorded, to give money back. **Delete draft** appears only for a draft that was never emailed.

## Confirming payments agencies report

1. Open **💳 Payments**. **Payments to confirm** lists what agency admins reported from their Licence page.
2. Press **Confirm received** to record it and email a receipt, or **Not received** and give a reason; the agency admin who reported it is told.
3. Below, the **Payment ledger** lists every payment, refund and returned payment with **Date**, **Agency**, **Invoice**, **Method**, **Amount**, **Receipt** and **Status**.

## Billing plans, extras and settings

1. **🏷️ Billing plans**: one row per agency with **Before discounts**, **Discounts / complimentary**, **Every**, **Tax**, **Total**, **Next invoice** and **Bill to**. Press **View** to set **Price**, **Currency**, **Every**, **Tax %**, **Tax name**, **Next invoice** and the **Business details on the invoice**, then **Save**. Agencies marked **billed from licences** take their price from **Platform › 🔑 Licences**.
2. **➕ Extra line items**: add a charge to an agency's next invoice or every invoice (**Every invoice** / **Next invoice only**), then **Pause**, **Resume** or **Delete** it.
3. **🤝 Referral credits**: a one-time credit off a chosen month's invoice.
4. **⚙️ Settings**: **🔁 Recurring invoices** (**On**, **Raise invoices at**, **Which agencies**, **Invoices are due**, **BCC on invoices**, **Email them automatically**, then **Save recurring invoices**), plus reminders and receipts, late fees, returned-payment fees and payment-method surcharges (**Save settings**).

> ⚠️ Turning on **Email them automatically** asks you to confirm: invoices are then emailed to agencies as soon as they are raised. Card surcharges in Canada are capped at 2.4% and not allowed on debit.

## Who can do what

**Platform super-admins** only. No agency role, sales rep or reseller can see KiddieTrac's receivables. Agency admins see their own invoices and report payments from their Licence page.

## FAQs and when something goes wrong

??? "No invoices yet" | Set an agency's price under **🏷️ Billing plans** (or give it a licence), then use **Raise invoices due now**.
??? "Every line needs a description and a price" | Fill both on every line, or remove the empty line with ✕.
??? "A tax rate needs a name (HST, GST)" | Type the tax name; an unnamed tax would print as a bare "Tax" line.
??? Why can't I delete an invoice? | Only a draft that was never emailed can be deleted. Once an agency holds a copy, use **Void** or **Reissue**.
??? The agency has "no address on file". | Open **🏷️ Billing plans**, press **View** and fill the business details. Anything left blank is left off the invoice.
??? Does Issue send the invoice? | No. Use **Email invoice**, or turn on automatic emailing in **⚙️ Settings**.

## Related screens

[[open: #licences | Licences]] · [[open: #platform-sales | Sales reports]] · [[open: #admin-mrr | MRR dashboard]] · [[open: #sales-expenses | Expenses]]

See also: [[platform-licences]] · [[platform-sales-reports]] · [[billing-setup]] · [[platform-admin-overview]]
