---
title: Attendance anomalies
category: Compliance
order: 40
roles: agency_admin, platform_admin, centre_director
screens: anomalies
---
# Attendance anomalies

The Anomalies screen scans your enrolled children for patterns worth a second look: a child who comes in but has no daily records, observations that have stopped, attendance that has suddenly dropped, very long days in care, or check-ins that were never checked out. Use it every week or two to catch gaps in record keeping before an inspector or a family does.

[[open: #anomalies | Open Anomalies]]

## Reading the results

1. Open **Compliance > Anomalies** in the sidebar. The screen shows "Scanning…" for a moment, then the heading **Attendance anomalies**.
2. Read the summary line under the heading, for example "6 of 48 enrolled children match a pattern in the last 30 days. Watching 5 of 6 patterns."
3. Pick a time window from the list at the top right: **Last 14 days**, **Last 30 days**, **Last 60 days** or **Last 90 days**. The screen scans again.
4. If you run more than one centre, press a centre chip to see only that centre, or **All centres** to see everyone. Each chip shows how many children are flagged out of how many enrolled, for example "Starlight Learning Centre · 3/12". A chip turns red when every child at that centre is flagged, which usually means the records are not being kept there at all.
5. Read the table. **Child** and **Centre / provider** say who it is. **What we noticed** lists each pattern the child matched, with a tag naming the pattern. **Days attended** and **Records / obs** (the days with daily records, then the number of observations in the window) give the numbers behind it.

## What each pattern looks for

- **Daily records missing**: attended at least a few days but has daily records (meals, naps, diapering) on fewer than half of them.
- **No observations**: attended at least 10 days but has no observations in the window.
- **Observations stopped**: had observations in the 90 days before, and none in the window.
- **Attendance dropped**: came on far fewer of their usual days in the last 14 days than in the 60 days before.
- **Long days in care**: in care more than 10 hours on several days. This one is off until you switch it on.
- **Not checked out**: checked in but never checked out on at least 2 days.

The exact numbers are the ones set in **What to detect**.

## Choosing what to detect

1. Press **⚙️ What to detect**. A panel opens under the heading.
2. Tick or untick each pattern to switch it on or off.
3. Change the numbers beside a pattern, for example **Min. days**, **%**, **Min. observations**, **Prior observations**, **Min. prior days** or **Hours**. The sentence beside each pattern explains what the numbers mean.
4. Press **Save and rescan**. A **Saved** toast says it is rescanning with the new patterns.
5. To go back to KiddieTrac's settings, press **Defaults**.

> ℹ️ These settings apply to everyone in your agency. Centre directors can see them, but only an agency admin can change them.

## Acting on what you find

1. Note the child and the pattern in **What we noticed**.
2. Open the child's record or the screen where the gap is (Daily log, Observations or attendance) and fix or complete the records.
3. Come back to Anomalies later. Once the records are complete, the child drops off the list on the next scan.

## Who can do what

- Agency admins see every centre and can change **What to detect**.
- Centre directors see their own centres. The pattern settings are shown read-only, with the note "Only an agency admin can change these."
- Platform admins see the agency they are working in and can change the settings.
- Educators and parents do not see this screen. It needs the Analytics feature in your agency's plan.

## FAQs and troubleshooting

??? The table says "No anomalies here. Every child matches the patterns being watched." | Nothing matched in the window you picked. Try a longer window, or check that the patterns you care about are switched on.
??? The table says "Every pattern is switched off." | Open **⚙️ What to detect**, tick the patterns you want and press **Save and rescan**.
??? I see "Could not scan:" and a message. | The scan did not finish. Refresh the page. If it keeps failing, contact support with the message shown.
??? I changed a number and saw "Not saved". | Your change was not stored. Check the numbers are between 0 and 1000 and press **Save and rescan** again.
??? Does a family or educator get told when a child is flagged? | No. This screen only shows the results to you. Nobody is notified.
??? Why can't I change the settings? | Only an agency admin can change **What to detect**. Ask your agency admin.

## Related

[[open: #care-log | Daily log]] [[open: #observations | Observations]]

- [Correcting attendance](correcting-attendance)
- [Observations](observations)
