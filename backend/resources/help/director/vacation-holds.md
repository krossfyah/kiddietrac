---
title: Vacation hold credits
category: Billing
order: 84
roles: agency_admin, centre_director, platform_admin
screens: vacation-holds
---
# Vacation holds

**Vacation holds** pauses a family's tuition while they are away. A family asks for a hold (or you add one for them), you approve or deny it, and an approved hold's credit comes off the family's **next invoice** after the hold ends. Directors and agency admins open it when a family says they will be away, and to clear requests waiting for an answer.

[[open: #vacation-holds | Open Vacation holds]]

## The credit rule

Your agency chooses how a hold is credited. Agency admins press **⚙ Credit rule** in the banner:

- **No credit** (the starting rule) — holds are recorded, but nothing comes off the bill.
- **A percent of the daily fee, for each scheduled day** — for example 50%.
- **A flat amount ($) for each scheduled day** — for example $20.

Only the days each child is booked to attend count (a child with no days on file counts Monday to Friday). Days the centre is closed are not counted. A child's daily fee is their monthly tuition × 12 ÷ 52 ÷ their days a week. The banner always shows the rule in force. Changing it does not change holds already approved.

## Approving or denying a request

New requests show **Requested**. The **Credit** column shows what your rule would give ("≈ $…, N scheduled days"), or **No credit**. Directors and agency admins of the family's centre are notified when a family asks — "Vacation hold requested by" the family, with the dates.

1. Open **Administration › Vacation holds** and find the request.
2. Open the row's **⋮** menu and choose **Approve**. The **Approve vacation hold** popup shows each child's scheduled days, daily fee and credit.
3. Keep the worked-out credit, or type a different amount (0 = no credit).
4. Press **Approve**. The row shows **Approved** and the credit, with "On the first invoice after" the hold's last day.
5. To refuse, choose **Deny** in the **⋮** menu, add a note for the family if you like, and press **Deny**.

## How the credit reaches the bill

- Once the hold's last day has passed, the family's next invoice — the monthly batch or their billing schedule — carries a **Vacation hold credit** line with the dates.
- A credit is never put on two invoices. If it is bigger than the invoice, the invoice goes to $0 and the rest comes off the following one.
- If you void the invoice that carried the credit, the credit goes on the next invoice instead.
- The **Credit** column shows where it went, for example "On INV-1042".

## Cancelling a hold

Choose **Cancel hold** in the row's **⋮** menu while the hold is requested or approved and its credit is not on an invoice yet. The credit is reversed and the family is told. Once the credit is on an invoice, void or adjust that invoice in **Billing** instead.

## Adding a hold for a family

1. Press **+ Add hold** in the banner.
2. Pick the family, set **From** and **To**, and add a **Reason (optional)**.
3. Press **Add hold**. The hold joins the list as **Requested**, and the family's guardians are told "A vacation hold was added to your account".
4. Approve it with the steps above to set the credit.

## What the family sees

- Approved: "Your hold for Dec 7 – Dec 11, 2026 is approved. A credit of $… will come off your next invoice after the hold ends." With no credit: "No credit applies to this hold."
- Denied: your note, or "Unable to approve at this time."
- Cancelled: "Your vacation hold for … was cancelled", and that its credit will not be applied.
- The credit line on their invoice. Notices open their **Billing** screen.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | See every hold in the agency, set the credit rule, add, approve, deny and cancel holds |
| Centre director | The same for families in their own centres only; other centres' holds are not listed |
| Platform admin | The same as an agency admin, in the agency being viewed |
| Parent | Is told each decision, and sees the credit on their invoice |

Educators do not see this screen.

## FAQs and when something goes wrong

??? There is no Approve or Deny in the ⋮ menu. | They only show while a hold is **Requested**. A decided hold can't be decided again; cancel it (if its credit is not invoiced) and add a new one if the dates change.
??? "This hold is already approved." | Someone decided it first. Refresh the screen.
??? Cancel hold is missing. | The hold's credit is already on an invoice, or the hold was denied or cancelled. Void or adjust that invoice in **Billing**.
??? The credit shows "Recorded only". | The hold was decided before credits reached invoices. Add that credit to the family's invoice by hand if it is still owed. See [[family-ledger]].
??? The credit is $0 but we give credits. | The rule is **No credit**, or the children have no scheduled days or no tuition in those dates. An agency admin sets the rule with **⚙ Credit rule**.
??? "Family and both dates are required." | Pick a family and fill in both **From** and **To**, then press **Add hold** again.
??? "No families loaded — select an agency first." | Choose your agency in the agency switcher and open the screen again.

## Related screens

[[open: #admin-families | Families]]
[[open: #closures | Closures]]
[[open: #tuition-increases | Tuition increases]]

See also [[family-ledger]] and [[generating-monthly-invoices]].
