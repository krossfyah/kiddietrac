---
title: Compliance dashboard
category: Administration
order: 56
screens: compliance
---
# Compliance dashboard

**Administration › Compliance** is one page that answers "what needs attention before an inspector sees it?": staff
certificates that have run out or are about to, leaders who have not turned on two-factor sign-in, and centres that
are full or nearly full. Open it at the start of each week. The page only reads your records — fix things on the
screen where the record lives, then come back and the count drops.

[[open: #compliance | Open the Compliance dashboard]]

## Reading the page

1. Open **Administration › Compliance**. The banner reads **What needs attention**.
2. Look at the five tiles across the top: **Expired certs**, **Expiring (30d)**, **MFA not enrolled**, **At/over capacity** and **Tight (95%+)**. Red means act now, amber means plan for it.
3. Scroll to the cards under the tiles for the names behind each number.

## Renewing staff certificates

The **Staff certifications** card lists every certificate (for example First Aid, CPR or ECE registration) that has
expired or expires within 30 days, with the person, the type and "Expires … (in 4 days)" or "(3 days ago)".

1. Note who is on the list.
2. Ask the staff member for the renewed certificate, or open their staff record and upload it with its new expiry date.
3. Come back to **Administration › Compliance**. Once the new expiry date is saved the person drops off the list.

When nobody is due, the card says **✅ Every cert is current.**

## Getting leaders onto two-factor sign-in

The **MFA not enrolled** card lists centre directors and agency admins who sign in with a password only, with their
email and last sign-in date.

1. Send each person a message asking them to turn on two-factor sign-in.
2. They open the two-factor screen, scan the code with an authenticator app and type the 6-digit code to confirm.
3. Refresh the dashboard: they leave the list. When everyone is enrolled it says **✅ Every director + admin has MFA enrolled.**

[[open: #mfa | Open two-factor sign-in]]

## Watching centre capacity

The **Centre capacity** card shows each centre's enrolled children against its licensed seats, as a percentage and a
bar: green under 80%, amber from 80%, red at 95% or more.

1. Find the centres in red.
2. Before enrolling anyone else there, check the licence and the waitlist.
3. If the licensed capacity is wrong, correct it on the centre's settings so the bar is measured against the right number.

If your province's rules list staff training and checks, a further card shows who is missing which item.

## Who can do what

| Role | Sees |
|---|---|
| Agency admin | Every centre in the active agency. |
| Centre director | Their own centre(s) only. |
| Auditor | The same page, read-only. |
| Platform admin | The agency chosen in the agency switcher. |

## FAQs and when something goes wrong

??? A certificate was renewed but the person is still listed. | The new certificate has not been saved with its new expiry date yet. Upload it with the date on the staff record.
??? The page says "Could not load". | Refresh the page. If it keeps happening, raise a ticket from Help.
??? The capacity card says "No centres in this agency." | The agency has no open centre yet. An agency admin adds one on the Centres screen.
??? Does this page email anyone? | No. It only shows the list; you decide who to contact.

## Related screens

[[open: #inspection | Inspection checklist]] [[open: #audit-logs | Audit log]]

See also [[audit-log]] and [[compliance-and-reporting]].
