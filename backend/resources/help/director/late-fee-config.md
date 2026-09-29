---
title: Late-fee configuration
category: Billing
order: 74
roles: agency_admin, platform_admin
---
# Late-fee configuration

**Settings → Billing → Defaults & fees → Late fees.** Each agency sets its own late-fee rule.

[[show: #billing-settings @ Defaults & fees > Late fees | Show me the Late fees settings]]

## What you can set

- **Late fee (%)**: the percentage of the overdue balance that is charged. It can be up to 25%. Set it to **0** to turn late fees off for your agency.
- **Cap**: the most any single late fee can be, in your currency. It can be up to 500.
- **Grace (days)**: how many days after the due date pass before a fee applies. It can be up to 60.

Click **Save billing setup** to apply.

If you have never changed these, they read **1.5%**, a **$25** cap and **0** grace days. Those are starting values, not a choice your agency made, so review them before late fees are switched on.

> **Important:** The automatic late-fee run is currently **switched off for the whole platform**, so no late fee is being added to any invoice. Your settings are saved and will apply once KiddieTrac turns the run on. See [Late-fee automation](late-fees).

## How the fee is worked out

**Fee = the invoice's current balance × your percentage, capped at your cap.**

For example, at 1.5% with a $25 cap, a $900 overdue balance gets a $13.50 fee, and a $2,000 balance gets $25.00.

See also: [Late-fee automation](late-fees) · [Billing setup & settings](billing-setup) · [Automated billing reminders](automated-billing-reminders)
