---
title: Billing setup & settings
category: Billing
order: 10
roles: agency_admin, platform_admin
screens: billing-settings
---
# Billing setup & settings

**Settings → Billing** holds everything that governs what your agency charges and how: tax, fee plans, sibling discounts, invoice defaults, late fees, payment methods, surcharges, billing email and reminders. Set it up once when you start billing in KiddieTrac, and come back when a rate or rule changes.

[[open: #billing-settings | Open Billing settings]]

## The tabs

- **🧾 Tax**: your tax rate and what it is called on the invoice.
- **💵 Tuition plans**: reusable fee plans. See [Tuition plans](tuition-plans).
- **👨‍👩‍👧 Sibling discounts**: see [Sibling discounts](sibling-discounts).
- **⚙️ Defaults & fees**: invoice defaults, payment schedules, late fees, the late pickup fee, payment methods, autopay and surcharges.
- **🛠 Billing comms**: the agency's email and SMS switches and the default language.
- **🔔 Reminders**: automatic reminders about upcoming and overdue invoices. See [Automated billing reminders](automated-billing-reminders).

Platform admins also see **🏦 Platform billing**: what KiddieTrac bills agencies, not what agencies bill families. See [Platform invoices](platform-invoices).

The screen remembers the last tab you used.

## Setting your tax rate

1. Open **Settings → Billing**. The **🧾 Tax** tab opens first.
2. Enter the **Rate** as a percentage of the invoice subtotal, for example 13.
3. In **Called**, type the name printed on the invoice, for example HST or GST.
4. Tick **Tick "Apply tax" by default on new invoices** if your agency is registered to charge tax. Leave it off if not.
5. Check the example line under the fields: it shows what the rate adds to a $100.00 invoice. If the rate is over 30% it warns you that it looks like a typo.
6. Press **Save changes**. "✓ Saved" appears beside the button and a **Billing settings saved** toast confirms it.

Tax applies to the subtotal: the base charge plus any line items. On each invoice it can still be changed or switched off, for example for a payee who is not charged tax.

## Setting invoice defaults and fees

[[show: #billing-settings @ Defaults & fees > Billing defaults | Show me the Defaults & fees tab]]

1. Open the **⚙️ Defaults & fees** tab.
2. Under **Billing defaults**, choose the **Default frequency** (Weekly, Bi-weekly or Monthly) and enter the **Deposit** and **Registration fee** that are pre-selected when you create invoices and fee plans.
3. Under **Payment schedules**, set **Issue invoices this many days before they are due** (default 5, up to 60; 0 issues on the due date itself).
4. In the same card, set **Show unpaid invoices to families this many days before they are due** (default 14). A family's Billing screen and "due now" total only include invoices due within that window; overdue invoices always show.
5. Under **Late fees**, enter the **Late fee (%)** of the overdue balance, the **Cap** per fee and the **Grace (days)** after the due date.
6. Under **Late pickup fee**, enter the **Fee per minute** (in your currency, from 0 to 50) and the **Grace (minutes before the fee starts)** (0 to 120). If you never changed them they read 1.00 and 5.
7. Under **Accepted payment methods**, tick each way families may pay: card, bank transfer (ACH), Interac e-Transfer, cash, cheque or other.
8. Under **Autopay**, tick **Enrol new families in autopay by default** and, if you want it, **Require autopay (families must keep a card on file)**.
9. Under **Payment surcharges**, enter a **Credit / debit card (%)** and an **EFT / Interac e-Transfer (%)** rate (each up to 10%, default 0). The example underneath shows what each adds to a $500.00 payment.
10. Press **Save billing setup**. "✓ Saved." appears and a **Billing setup saved** toast confirms it.

### What these settings do

- **Payment schedules notice**: each instalment on a payment schedule stays a pending draft until that many days before its due date, then issues on its own and the family owes it. Due dates never change; only the notice does. See [Payment schedules](payment-plans).
- **Late fees**: if you never changed them they read 1.5%, $25 and 0 days. See [Late-fee configuration](late-fee-config).
- **Late pickup fee**: when staff log a late pickup on **Late pickups**, the suggested fee is the fee per minute for every minute past the centre's close time after the grace minutes. With 1.00 and 5, a child picked up 12 minutes late gets a suggested fee of $7.00. Nothing is charged until a director or admin approves it; they can also change the amount, waive it or decline it. See [Late pickups and fees](late-pickup-fees).
- **Interac e-Transfer**: the line under the payment methods shows where e-Transfers go, or says "Interac e-Transfer is not set up yet." with a **Set up in Payment providers →** link.
- **Credit/debit card (shown on invoices)**: adds "Pay by credit or debit card in the app" to the How to pay section of invoices and invoice emails, once your card payments are live.
- **Announce new invoices to families**: each new invoice also appears in the paying parent's News, just for them, with their amount and View invoice / Pay buttons.
- **Surcharges** are charged on the amount being paid, not the invoice total, and never on cash or cheque. The fee shows as its own line on the invoice. See [Payment surcharges](payment-surcharges).

> ⚠️ The automatic late-fee run is currently switched off for the whole platform, so saving a rate here does not start charging anybody. See [Late-fee automation](late-fees).

## Billing email, SMS and language

1. Open the **🛠 Billing comms** tab.
2. Tick or untick **✉️ Enable email for this agency (invoices, reminders, announcements)**.
3. Tick or untick **📱 Enable SMS for this agency (billing reminders, alerts)**.
4. Choose the **Default language** for parent-facing billing messages.
5. Press **Save settings**.

> ⚠️ Turning email off stops invoice emails, billing reminders and announcements for the whole agency, including statements and resends from Accounting.

## Who can do what

- **Agency admins**: every tab.
- **Platform admins**: every tab for the agency chosen in the top-bar switcher, plus **🏦 Platform billing**.
- **Centre directors**: do not have this screen. Billing reminders are their only billing setting; ask your agency admin for anything else.
- **Educators** and **parents**: no access.

## Common questions

??? The Tax tab says "This section is available to agency administrators". | Your role cannot change agency billing settings. Ask your agency admin.
??? A tab says "Could not load this section." | Reload the page. If it keeps happening, contact KiddieTrac support.
??? I saved a late fee but nobody was charged. | The automatic late-fee run is switched off platform-wide. Nothing is charged until it is turned on.
??? I changed the late pickup fee. Do pickups already logged change? | No. A late pickup keeps the suggested fee worked out when it was logged. The new rate and grace apply to pickups logged after you save.
??? Does changing the tax rate change old invoices? | No. It fills in on new invoices. Invoices already raised keep their own tax.
??? Does changing the notice days move existing payment schedules? | No. A schedule keeps the notice period it was created with; the new value is the starting point for new schedules.
??? Save failed. | The message after "Save failed:" says why, for example a value out of range. Fix the field and press Save again.

## Related screens

[[open: #payment-providers | Payment providers]] [[open: #external-billing | Accounting]] [[open: #payment-plans | Payment schedules]]

See also: [Payment surcharges](payment-surcharges) · [Payment providers](payment-providers) · [Payment schedules](payment-plans) · [Late-fee configuration](late-fee-config) · [Invoice style and numbering](invoice-numbering-and-style)
