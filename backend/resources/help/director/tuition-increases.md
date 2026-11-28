---
title: Tuition increase scheduling
category: Billing
order: 83
roles: agency_admin, centre_director, platform_admin
screens: tuition-increases
---
# Tuition increase scheduling

**Finance → Tuition increases** plans a future rise in tuition: a percentage (for example 3.5%) or a flat dollar amount a month, from a set date. KiddieTrac then does the rest by itself: it tells each affected family before the date, and on the date it writes the new amount to their tuition and to your tuition plans.

[[open: #tuition-increases | Open Tuition increases]]

## How an increase moves along

| Status | What it means |
|---|---|
| **Scheduled** | Saved. Nothing has been sent yet. You can still edit or delete it. |
| **Notified** | Every affected family has been told (bell notification and email), with their own children's old and new monthly tuition. It can no longer be changed. |
| **Applied** | On the effective date, the new amount was written to each child's monthly tuition and to the tuition plans. Invoices raised from then on use it. |
| **Overdue** | The date passed more than 14 days ago and it was never applied (for example, it was planned before this ran). It is not applied late by itself: press **Apply now** or **Delete**. |

All dates follow your agency's time zone. Families are told on the **notice day**: the effective date minus the notice days.

## Scheduling an increase

[[show: #tuition-increases @ + Schedule increase | Show me Schedule increase]]

1. Open **Finance → Tuition increases** and press **+ Schedule increase**.
2. Choose the **Centre**. Agency admins can also choose **All centres**; directors choose one of their own centres.
3. Pick the date in **New tuition starts on**. It must be after today.
4. Under **Type**, choose **Percent (%)** or **Flat amount ($ a month)**.
5. Type the **Increase** without a % or $ sign: 3.5 for 3.5%, or 25 for $25 more a month.
6. **Tell families this many days before** starts at your agency's notice rule (30 days unless changed). Change it for this increase if you need to.
7. Add a **Note for families** if you like (for example "Our annual cost-of-living adjustment"). It is printed in their email.
8. Check the preview under the fields: every affected family, each child's monthly tuition **Now** and **New**, and any tuition plans that change. The line above it says the day families will be told.
9. Press **Schedule increase**.

> ⚠️ If the notice day has already passed (for example 30 days' notice for a date 10 days away), the form warns you. Families are then told as soon as the scheduler next runs, so they get less notice than your rule.

### What a percent and a flat amount change

- **Percent** raises every affected child's monthly tuition and every active tuition plan for that centre (or all centres) by that percentage, rounded to the cent.
- **Flat amount** adds the dollars to each child's **monthly** tuition and to **monthly** tuition plans only. Weekly, biweekly and one-time plans are left as they are; the preview names them.
- Only children who are enrolled, with a monthly tuition above $0, in families that have not left, at open centres, are included.

## Viewing, editing and deleting

Each row's **⋮** menu has:
- **View**: the details, and who it reaches. Before families are told, the list is worked out from today's fees; after, it is the record of what each family was told and what was changed.
- **Edit** (Scheduled only): opens the same form with the preview.
- **Delete** (Scheduled or Overdue): removes it. Families who were already told are not told it is cancelled; tell them yourself.
- **Apply now** (Overdue only): tells families who were not told yet, then writes the new amounts at once.

## Your agency's notice rule

Agency admins press **⚙ Notice rule** to set how many days before an increase families are told. It is the starting value for new increases; increases already scheduled keep their own.

## What families receive

Each guardian of an affected family gets a bell notification and an email "Tuition change from (date)" that lists their own children with the current and new monthly tuition, your note, and a button to their billing. Nobody sees another family's amounts. After the increase is applied, agency admins and the centre's directors get a bell notification saying how many fees were updated.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Schedules, edits and deletes increases for one centre or all centres, applies overdue ones and sets the notice rule. |
| Centre director | Sees their own centres' increases and the agency-wide ones; schedules, edits and deletes increases for their own centres only. |
| Platform admin | The same as an agency admin, in the agency they are working in. |

Educators and parents do not see this screen.

## FAQs and when something goes wrong

??? "The new tuition must start after today." | Pick a later date in **New tuition starts on**.
??? "A percent increase can be at most 50%." | Check the number. For a large change, use **Flat amount** or change the tuition directly.
??? The preview says no families would change. | Nobody enrolled at that centre has a monthly tuition above $0. Check the children's enrolments and tuition plans first.
??? Why can't I edit an increase? | Families have already been told. Delete it only if it is Overdue; otherwise schedule a correcting increase and tell families.
??? Do invoices already raised change? | No. Only invoices raised after the effective date use the new tuition.
??? Will this send a text? | No. Families get a bell notification and an email. Test agencies send nothing.

## Related screens

[[open: #tuition-plans | Tuition plans]] [[open: #payment-plans | Payment schedules]] [[open: #external-billing | Accounting]]

See also: [Tuition & fee plans](tuition-plans) · [Generating monthly invoices](generating-monthly-invoices)
