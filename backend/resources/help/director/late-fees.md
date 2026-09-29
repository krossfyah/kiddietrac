---
title: Late-fee automation
category: Billing
order: 72
roles: agency_admin, platform_admin
---
# Late-fee automation

**Settings → Billing → Defaults & fees → Late fees** is where your rule is set. This article explains how the automatic run applies it.

> **Important:** The late-fee run is currently **switched off for the whole platform**. No late fees are added automatically until KiddieTrac turns it on. Adding a fee changes what families owe, so it is a deliberate, one-time decision, not a side effect of saving a setting.

## When it runs

Once switched on, the run happens **every night at 02:00 (Toronto time)**. If a night is missed, the next run catches up, and no fee is applied twice.

## Which invoices get a fee

An invoice gets a late fee only when **all** of these are true:

- It was raised in KiddieTrac. Invoices synced in from an outside billing system are not touched.
- Its status is issued, partly paid or overdue. A **Pending** payment-schedule invoice is never charged.
- It still has a balance.
- Its due date, plus your **grace days**, has passed.
- It has not already had a late fee **this calendar month**.
- Your agency's late-fee percentage is above 0.

## What happens to the invoice

1. A line is added to the invoice named **Late fee · YYYY-MM**, for the month it was charged.
2. The invoice total and balance go up by the fee.
3. An invoice that was issued but not yet marked late becomes **Overdue**.
4. Every guardian on the family gets an in-app notification: *Late fee applied to invoice …*, with the amount.
5. The fee is written to the audit log.

The fee is the balance × your percentage, capped at your cap. See [Late-fee configuration](late-fee-config) for the settings.

## Stopping late fees for one invoice

A paid or void invoice is never charged. To stop fees on an invoice that should not be chased, settle it or void it in **Accounting**. To stop late fees for your whole agency, set **Late fee (%)** to 0.

## Common questions

??? Why has no late fee been charged even though an invoice is overdue? | The platform-wide late-fee run is currently off. Your settings are saved and take effect when it is turned on.
??? Can a family be charged twice in one month? | No. An invoice gets at most one late fee per calendar month.
??? Do late fees apply to synced invoices? | No. Only invoices raised in KiddieTrac.

See also: [Late-fee configuration](late-fee-config) · [Automated billing reminders](automated-billing-reminders) · [Accounting](accounting)
