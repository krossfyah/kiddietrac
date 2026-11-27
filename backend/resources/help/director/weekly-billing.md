---
title: Weekly / biweekly billing schedule
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
screens: billing-schedule
---
# Billing schedule

**Finance → Billing schedule** records how often one family is billed: weekly, biweekly (every two weeks) or monthly, and on which day. Use it for a family who has agreed to pay by the week or fortnight instead of once a month, so everyone in the office can see their rhythm and their next billing date.

[[open: #billing-schedule | Open Billing schedule]]

## Setting a family's schedule

1. In the sidebar, open **Finance** and choose **Billing schedule**.
2. Pick the family by name in the **Family** list. Their schedule loads on its own (or press **Load**).
3. Choose the **Frequency**: **Weekly**, **Biweekly** or **Monthly**. A family with no schedule yet shows **Monthly**.
4. For weekly or biweekly, enter the **Day of week (0=Sun … 6=Sat)**. For example 1 is Monday and 5 is Friday.
5. For monthly, enter the **Day of month (1-31)**, for example 1 for the first of the month.
6. Press **Save schedule**. The form reloads and shows **Next charge** with the date worked out from what you chose.

> 💡 Only the field that matches the frequency is used: the day of week for weekly and biweekly, the day of month for monthly. You can leave the other one as it is.

### How the next date is worked out

- **Weekly**: the next time that weekday comes round.
- **Biweekly**: one week after the next time that weekday comes round, so the first date is 8 to 14 days away.
- **Monthly**: that day of next month.

## Changing or switching off a schedule

1. Pick the family in the **Family** list.
2. To change it, pick a new **Frequency** or day and press **Save schedule** again. The new next date replaces the old one.
3. To stop it, press **Disable**. The family goes back to your agency's normal billing.

## Where the schedule shows up

A family's live schedule appears in their **account ledger** (agency admins: **Finance → Account ledgers**), under **Coming up**, as "Next weekly invoice" (or biweekly / monthly) with the date. That row has no amount and says no invoice has been raised yet, because the amount is only decided when an invoice is issued. See [Account ledgers](account-ledgers).

> ℹ️ Saving a billing schedule does not by itself raise an invoice or charge a card. Invoices still come from your usual routes: the **Bulk invoice run**, a **payment schedule**, or an invoice you raise by hand. Autopay charges a saved card when an invoice falls due. See [Autopay](autopay).

## Which tool do I need?

| You want to… | Use |
|---|---|
| Record that a family is billed weekly, every two weeks or monthly | **Billing schedule** (this screen) |
| Split one agreed total into dated payments, each with its own invoice | [Payment schedules](payment-plans) |
| Choose which card or account a family pays with | The family's wallet and [Autopay](autopay) |
| Set the agency-wide default frequency for new invoices and fee plans | **Settings → Billing → Defaults & fees** ([Billing setup & settings](billing-setup)) |

## Who can do what

- **Agency admins** and **platform admins**: set, change and disable a schedule for any family in the agency (platform admins for the agency chosen in the top-bar switcher).
- **Centre directors**: the same, for families at their own centres. A family at another centre cannot be opened.
- **Educators** and **parents**: no access to this screen.

## Common questions

??? The Family list says "Could not load families". | The list could not be fetched. Reload the page; a platform admin should first pick an agency with the top-bar switcher.
??? I pressed Load and see "Choose a family first." | Pick a family in the Family list, then press Load.
??? Nothing seemed to happen when I pressed Save schedule. | The form reloads in place. Check the Next charge line under the fields: it shows the new date.
??? Will the family be told about the change? | No. This screen sends no email, text or notification. Tell the family yourself if their billing day changes.
??? Does this change the amount a family pays? | No. It records when they are billed, not how much. Amounts come from their tuition plan and their invoices.
??? Why does the account ledger show no amount for the next date? | The amount is only set when the invoice is issued, so the ledger names the date and leaves the amount blank.

## Related screens

[[open: #payment-plans | Payment schedules]] [[open: #external-billing | Accounting]]

See also: [Payment schedules](payment-plans) · [Autopay](autopay) · [Account ledgers](account-ledgers)
