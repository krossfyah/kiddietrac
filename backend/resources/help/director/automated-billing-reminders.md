---
title: Automated billing reminders
category: Billing
order: 25
---

# Automated billing reminders

**Settings → Billing → 🔔 Reminders.** Schedule automatic emails to families about invoices that are coming due and invoices that are overdue. Agency admins **and** centre directors can manage them.

[[show: #billing-settings @ Reminders > Send upcoming-invoice reminders | Show me the reminder settings]]

The same settings also appear under **Settings → Email settings → Billing**, beside your other automated emails. It is one set of settings with two ways in, so a change in either place shows in both.

**Centre directors** see only the reminders, not the rest of the billing settings:

[[open: #billing-setup | Open billing reminders]]

## What you can configure

- **Invoice reminders**: tick **Send upcoming-invoice reminders**, then list the **Days before due date**, separated by commas. The default is `7,3`.
- **Overdue payment reminders**: tick **Send overdue-payment reminders**, then list the **Days after due date**. The default is `1,7,14`.
- **Daily send time**: the hour reminders go out, **in your agency's time zone**. The default is 09:00.
- **✉️ Send by email**: reminders are sent by email.
- **Also copy the agency billing contact**: the office receives a **blind copy** of each reminder. Families never see the office's address on it.
- **Custom note added to each reminder (optional)**: added to every reminder you send.

Click **Save reminders**.

Days are counted from each invoice's due date, using your agency's calendar. Each reminder fires **once**, on its exact day.

## Who gets them

Reminders go to the family's guardians:

- If any guardian is marked to **receive billing**, only those guardians are emailed.
- Otherwise, every guardian who can sign in is emailed.
- Guardians who have not accepted their invite yet, or whose account is switched off, are skipped.

## Which invoices are included

Both invoices raised in KiddieTrac and invoices synced from an outside billing system are included, as long as they still have a balance.

- **Partly paid**: included. The reminder quotes the **remaining balance**, not the original total.
- **Paid**, **refunded**, **void**: never included. Nobody is chased for money they have paid or been given back.
- **Pending** payment-schedule invoices: not included. They are not owed until they are issued.

Each reminder names the invoice number, the balance and the due date. A family with several invoices open can then tell which one it is about.

## Turning reminders on

Reminders are **off by default** for every agency. Nothing is sent until you tick at least one of the two reminder types.

> **Important:** There is also a **platform-wide switch**, and it is currently **off**. When it is, the Reminders tab shows a yellow banner: *Automated reminders are currently OFF platform-wide.* Your schedule is saved, and it starts sending once KiddieTrac turns reminders on for the platform.

> **Tip:** Review your day lists, send time and custom note before the platform switch goes on. From then on, real families start receiving the reminders you scheduled.

## Common questions

??? Why did a family not get a reminder? | Check that they have a guardian with an active sign-in, that the invoice still has a balance, and that the invoice was not still pending on that day.
??? Can I send by SMS? | Not currently. Billing reminders are sent by email only.
??? What time zone is the send time in? | Your agency's own time zone, so 09:00 means 9 a.m. where your centres are.

See also: [Billing setup & settings](billing-setup) · [Payment schedules](payment-plans) · [Late-fee automation](late-fees)
