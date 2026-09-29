---
title: Billing setup & settings
category: Billing
order: 10
roles: agency_admin, platform_admin
---
# Billing setup & settings

**Settings → Billing.** Everything that governs what your agency charges, and how, lives on one screen split into tabs.

[[open: #billing-settings | Open Billing settings]]

## The tabs

- **🧾 Tax**: your tax rate and what it is called on the invoice (HST, GST and so on). There is also a switch to tick **Apply tax** by default on new invoices.
- **💵 Tuition plans**: reusable fee plans. See [Tuition plans](tuition-plans).
- **👨‍👩‍👧 Sibling discounts**: see [Sibling discounts](sibling-discounts).
- **⚙️ Defaults & fees**: the settings described below.
- **🛠 Billing comms**: the agency's email and SMS switches, and the default language.
- **🔔 Reminders**: automatic reminders about upcoming and overdue invoices. See [Automated billing reminders](automated-billing-reminders).

Platform admins also see a **🏦 Platform billing** tab. It controls what KiddieTrac bills agencies, not what agencies bill families. See [Platform invoices](platform-invoices).

## Defaults & fees

[[show: #billing-settings @ Defaults & fees > Billing defaults | Show me the Defaults & fees tab]]

**Billing defaults**: the **Default frequency** (weekly, bi-weekly or monthly), the **Deposit** and the **Registration fee** that are pre-selected when you create invoices and fee plans.

**Payment schedules**: **Issue invoices this many days before they are due.** The default is **5** and the maximum is 60. Each instalment on a payment schedule stays a pending draft until that many days before its due date. Then it issues automatically and the family owes it. Set **0** to issue on the due date itself. Due dates never change; this setting only changes how much notice the family gets. See [Payment schedules](payment-plans).

**Late fees**: a **Late fee (%)** of the overdue balance, a **Cap** per fee, and **Grace (days)** after the due date. If you have never changed them, they read 1.5%, $25 and 0 days. See [Late-fee configuration](late-fee-config).

> **Important:** The automatic late-fee run is currently switched off for the whole platform, so saving a rate here does not start charging anybody. See [Late-fee automation](late-fees).

**Accepted payment methods**: tick the ways families may pay: credit or debit card, bank transfer (ACH), Interac e-Transfer, cash, cheque, and other.

**Autopay**: **Enrol new families in autopay by default**, and **Require autopay (families must keep a card on file)**.

**Payment surcharges**: a **Credit / debit card (%)** rate and an **EFT / Interac e-Transfer (%)** rate. Each can be up to 10%, and both default to 0. A worked example under the boxes shows what each rate adds to a $500 payment. See [Payment surcharges](payment-surcharges).

Click **Save billing setup** to apply everything on this tab.

## Related settings elsewhere

- **Payment providers** (Settings): the accounts that actually take card and bank payments. See [Payment providers](payment-providers).
- **Branding**: the invoice style, invoice numbering, logo and colour. See [Invoice style and numbering](invoice-numbering-and-style).

> **Note:** Only agency admins and platform admins can change these settings. Centre directors can manage the billing reminders only.

See also: [Payment surcharges](payment-surcharges) · [Payment providers](payment-providers) · [Payment schedules](payment-plans) · [Late-fee configuration](late-fee-config)
