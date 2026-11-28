---
title: Weekly / biweekly billing schedule
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
screens: billing-schedule
---
# Billing schedule

**Finance → Billing schedule** bills one family on their own rhythm: weekly, every two weeks or monthly, on the day you choose. On each date KiddieTrac raises the family's tuition invoice by itself, emails it, and, if the family pays by autopay, charges their saved card.

[[open: #billing-schedule | Open Billing schedule]]

## Setting a family's schedule

1. In the sidebar, open **Finance** and choose **Billing schedule**.
2. Pick the family by name in the **Family** list. Their schedule loads on its own (or press **Load**).
3. Choose **How often**: **Weekly**, **Every two weeks** or **Monthly**.
4. For weekly or every two weeks, choose the weekday in **Invoice on**. For monthly, choose the day in **Invoice on day**.
5. Read the **Next invoice** line: the date the first invoice will be raised and the dates it covers. It changes as you change the fields.
6. Press **Save schedule**. The schedule is running from then on.

### What each invoice covers and costs

| How often | The invoice covers | Amount per child | Due |
|---|---|---|---|
| Weekly | the 7 days from the invoice date | monthly tuition × 12 ÷ 52 | 7 days after |
| Every two weeks | the 14 days from the invoice date | monthly tuition × 24 ÷ 52 | 7 days after |
| Monthly | on the 1st, that month; on a later day, the **next** month (billed ahead) | the monthly tuition | 15 days after |

Subsidies and sibling discounts are worked out the same way as in the monthly invoice run, scaled to the period. Days 29 to 31 use the last day of a shorter month (for example the 31st becomes April 30). All dates follow your agency's time zone.

## What happens on each date

- The invoice is raised with one tuition line per enrolled child, and the family gets a notification and the invoice email.
- If the family (or a guardian, for split billing) has autopay, the card is charged straight away through your usual payment run. The run is in **Finance → Payment run** like any other.
- Nothing is raised, and the run says why, when: another invoice already covers those dates (for example the monthly invoice run, or the partner's billing), your centre is closed for the whole period (closures marked as affecting billing), or no child has a tuition fee.
- A date is billed once only. If the system was down and a date passed more than 3 days ago, it is not billed late; it shows as skipped so you can raise that invoice by hand if needed.

## Recent runs

Under the form, **Recent runs** lists the last 12 dates for the family: the date, the dates covered, the result (**Invoiced**, **Charged**, **Charge failed**, **Skipped** or **Failed**), the amount, the invoice number and a note.

If an invoice could not be raised or the card was declined, agency admins and the centre's directors get a notification **Scheduled billing needs attention**. A declined card leaves the invoice open for the family to pay; the usual failed-payment emails go out.

## Changing or stopping a schedule

1. Pick the family in the **Family** list.
2. To change it, choose a new frequency or day and press **Save schedule**. The next invoice date is worked out again from today.
3. To stop it, press **Stop schedule** and confirm. No more invoices are raised on its dates; invoices already raised stay as they are. The family goes back to your normal billing (the monthly invoice run).

> ⚠️ A schedule saved before schedules raised invoices shows **Not running yet**. It starts only when you press **Save schedule** again, so nobody is billed by surprise.

## Which tool do I need?

| You want to… | Use |
|---|---|
| Bill a family weekly, every two weeks or monthly, on their own day | **Billing schedule** (this screen) |
| Split one agreed total into dated payments, each with its own invoice | [Payment schedules](payment-plans) |
| Choose which card or account a family pays with | The family's wallet and [Autopay](autopay) |
| Set the agency-wide default frequency for new invoices and fee plans | **Settings → Billing → Defaults & fees** ([Billing setup & settings](billing-setup)) |

## Who can do what

- **Agency admins** and **platform admins**: set, change and stop a schedule for any family in the agency (platform admins for the agency chosen in the top-bar switcher).
- **Centre directors**: the same, for families at their own centres. A family at another centre cannot be opened.
- **Educators** and **parents**: no access to this screen.

## Common questions

??? Do I still run the monthly invoice run for this family? | No need. The monthly run skips a family whose month is already covered by a scheduled invoice, and the schedule skips a period the monthly run already billed, so a family is never billed twice.
??? "Choose the day of the week to bill on." | Pick a weekday in **Invoice on**.
??? The status says "Not running yet". | The schedule was saved before schedules raised invoices. Press **Save schedule** to start it.
??? A run says "Already billed for this period". | Another invoice already covers those dates. Nothing more was raised.
??? Will the family be told? | Yes. Each invoice reaches the family by notification and email, as any invoice does. Test agencies send nothing.
??? Why was a card not charged? | The family has no autopay, autopay is not in your plan, or no payment processor is connected. The note in **Recent runs** says which; the invoice stays open.

## Related screens

[[open: #payment-plans | Payment schedules]] [[open: #payment-run | Payment run]] [[open: #external-billing | Accounting]]

See also: [Payment schedules](payment-plans) · [Autopay](autopay) · [Account ledgers](account-ledgers)
