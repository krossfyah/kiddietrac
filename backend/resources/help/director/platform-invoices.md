---
title: Platform invoices (billing agencies)
category: Billing
order: 90
roles: agency_admin, platform_admin
---
# Platform invoices

**Reseller → Invoices.** This is what agencies owe KiddieTrac for the platform: each agency's price, the invoices raised for it, and whether they have been paid. It is separate from the invoices agencies send to families.

[[open: #sales-invoices | Open platform Invoices]]

## Billing plans: what each agency is charged

[[show: #sales-invoices @ Billing plans | Show me Billing plans]]

**Billing plans** opens a table with one row per agency:

- **Price**, **Currency** (CAD or USD) and **Every** (how often the agency is billed).
- **Tax**: a rate and its name, e.g. 13% HST. A rate above 0 must have a name, because an unnamed tax would print as a bare "Tax" line.
- **Total**: the price plus tax. It updates as you type, so you see the tax before saving.
- **Next invoice**: the date the agency is next billed. **An agency with no next invoice date is not on recurring billing** and is never billed automatically. Use this to pause an agency without losing its pricing.
- **Business…**: the agency's details for the **Bill To** block, such as registered name, address, tax registration number and billing email. Anything left blank is left off the invoice.

Click **Save** on the row. The price and the business details are saved together.

## Raising invoices

**Raise invoices due now** bills every agency whose next invoice date has arrived, and moves each date forward by that agency's billing interval. It shows you a preview first, naming each agency and amount and listing any that were skipped with the reason. Nothing is created until you confirm.

Invoices are raised as **drafts**.

## Working with an invoice

Tabs across the top show **Open**, **Paid** and **Voided**, with totals for each currency. Each row offers only what applies to it:

- **View invoice**: opens the PDF. This works on every row, including void ones.
- **Issue**: drafts only. Issuing records the date; it does not email anything.
- **Email invoice**: asks for the address every time. If you give none, nothing is sent. You can use it on any invoice that is not void, including one already issued.
- **Mark paid**: asks you to confirm.
- **Void**: asks you to confirm. The invoice stays on record, dimmed, but no longer counts as owed.
- **Edit invoice**: drafts only. Change the amount before tax, the due date and the tax rate.
- **Delete draft**: only for a draft that has never been emailed. Once an agency holds a copy, the invoice can only be voided.

## Automatic billing

**Settings → Billing → 🏦 Platform billing** controls the nightly run at 06:00. With both switches off, nothing happens on its own.

[[show: #billing-settings @ Platform billing > Raise invoices automatically | Show me Platform billing]]

- **Raise invoices automatically**: creates drafts for any agency whose billing date has arrived. Nothing is sent.
- **Email them to the agency automatically**: issues each invoice and emails it to the agency contact with the PDF attached. An agency with no usable contact address is left as a draft.

**Your business details**, the **Business address** and **GST/HST registration number**, are printed on every invoice KiddieTrac issues. Automatic emailing **cannot run until both are filled in**, and the tab says so. Without them, every PDF would go out marked incomplete.

## Who can use this

**Platform admins** only. No agency role can see it.

See also: [Billing setup & settings](billing-setup) · [Platform admin overview](platform-admin-overview)
