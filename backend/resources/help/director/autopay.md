---
title: Payment run (auto-pay charges)
category: Billing
order: 76
roles: agency_admin, platform_admin
screens: payment-run
---
# Payment run (auto-pay charges)

**Finance → Payment run** charges every auto-pay family's open invoices on their saved card in one go. You check the list, untick anyone you want to hold back, and run it. Auto-pay also runs by itself every night at 3:00, and every run (manual or nightly) is kept under **Past runs** so you can see who was charged and who was not.

[[open: #payment-run | Open Payment run]]

## Before you start

- [ ] A card processor is connected in **Settings → Payment providers**. Without one, a yellow ⚠️ note at the top says nothing can be charged yet, and **Charge selected** stays greyed out.
- [ ] Families have saved a card and switched auto-pay on (see "How a family turns on auto-pay" below).
- [ ] The invoices you expect to collect are issued (drafts are never charged).

## Charging the auto-pay families now

1. Open **Finance → Payment run**.
2. Look at **Ready to charge**. Each row shows the **Family** (and centre), the **Invoice** number, the **Due** date, the saved **Card** and the **Amount**. Overdue invoices carry a red **Overdue** tag.
3. Untick any family you want to hold back this time (for example, one who called to say they will pay another way). The box in the header (**Select all**) ticks or unticks every row.
4. Check the total beside **Selected:** at the bottom, for example "12 invoices · $4,380.00".
5. Press **Charge selected**.
6. Read the confirmation ("Charge 12 invoices?") and press the **Charge $…** button to go ahead, or cancel.

While it runs the button reads **Charging…**. When it finishes, a toast says how many were charged and how many failed ("11 charged ($4,015.00), 1 failed"), both lists refresh, and the details of the run open by themselves.

> ⚠️ A charge cannot be undone from this screen. To give money back, use **Finance → Refunds**.

## Following up families not covered by auto-pay

The **Not covered by auto-pay** list shows families who owe money but have no auto-pay card on file, with the number of **Invoices**, the **Oldest due** date and the amount **Owing**. Nobody on this list is charged or assumed paid.

1. Note the family in **Not covered by auto-pay**.
2. Open their invoice in **Finance → Accounting**, or their account in **Finance → Account ledgers**.
3. Send a reminder, or record the payment on the invoice when it arrives (e-Transfer, cash, cheque).

## Checking a past run

1. Scroll to **Past runs**. Each row shows **When**, the **Type** (**Nightly (automatic)** or **Manual** with the first name of the person who ran it), the **Result**, how many were **Charged** and **Failed**, and the amount **Collected**.
2. Click a row to open it.
3. Read each family's **Result**: **Charged**, **Failed** or **Not charged**. A failed or skipped row shows the reason underneath (for example, the card was declined or expired).
4. Press **Close**.

The run's result is **Completed** when everything went through, **Some failed** when part of it failed, **Failed** when nothing went through, and **No processor** when no card processor was connected at the time.

## How a family turns on auto-pay

Families do this themselves in the family portal: **Auto-pay → + Add card**, enter the card in the secure card form, then **Save card**. Auto-pay switches on once the card is saved. The card details go straight to the card processor and never reach KiddieTrac. To stop, the family presses **Turn off auto-pay**; the card stays on file so they can switch it back on later with **Turn on auto-pay**.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Open Payment run, charge families, read every past run. |
| Platform admin | The same, for the agency they are working in. |
| Centre director | Does not see this screen. Charging cards moves the agency's money, so it stays with the agency admins. |
| Families | Save a card and turn auto-pay on or off in their own portal. They never see this screen. |

## FAQs and when something goes wrong

??? Charge selected is greyed out. | Either no rows are ticked, or no card processor is connected. The yellow note at the top says "No card processor is connected for this agency, so nothing can be charged yet." Connect one in Settings → Payment providers.
??? Ready to charge is empty. | It says "No auto-pay family has an open balance right now." Either every auto-pay family is paid up, or the families who owe have no card on file (they appear under Not covered by auto-pay).
??? I see "A payment run is already in progress for this agency." | Someone else (or the nightly run) is charging right now. Wait a minute, reload the page and check Past runs before trying again, so nobody is charged twice.
??? A family's card failed. What happens? | Nothing is charged and the invoice stays open. The family gets a notice that the payment failed. Once they update their card, the next run (manual or nightly) tries again.
??? Does the nightly run charge the families I unticked? | Yes. Unticking only holds a family back from this manual run. The nightly run charges every auto-pay family with an open balance. To stop a family being charged, ask them to turn off auto-pay.
??? I charged the wrong amount. | Give the money back with a refund in Finance → Refunds. The refund is signed for and the family is told.
??? Do families get an email when they are charged? | Yes, a paid invoice sends the family their usual payment confirmation. A failed charge sends a payment-failed notice instead.

## Related screens

[[open: #payment-providers | Payment providers]] [[open: #refunds | Refunds]] [[open: #account-ledgers | Account ledgers]] [[open: #external-billing | Accounting]]

See also: [Payment providers](payment-providers) · [Refunding a payment](refunds) · [Account ledgers](account-ledgers)
