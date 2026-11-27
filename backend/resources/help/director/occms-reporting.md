---
title: OCCMS reporting — fee subsidy Record of Attendance and CWELCC summary
category: Compliance
order: 47
screens: subsidy-roa
---
# OCCMS reporting

Ontario centres contracted for child care fee subsidy enter a **Record of Attendance (ROA)** in OCCMS every month: a code for each day for every child with a fee subsidy, plus licensed capacity, operating capacity, enrolment and vacancies by age group. York Region's deadline is the **8th of the following month**. OCCMS has no connection KiddieTrac can send to, so KiddieTrac prepares the ROA for you to copy in, and keeps a record of who checked it and when it was entered.

Open **Compliance → OCCMS reporting** (Ontario agencies only; directors see their own centres, agency admins see every centre). The screen has the tabs **Record of Attendance**, **Monthly attendance & fees**, **CWELCC summary** and, for agency admins, **Settings**.

[[open: #subsidy-roa | Open OCCMS reporting]]

## Checking the days

1. Pick the centre and the month at the top.
2. On **Record of Attendance**, each child with a fee subsidy that month (from **Compliance → Subsidies → Provincial subsidy**) has a row with one code per day.
3. Tap any day to change its code. A changed day has an orange underline, and every change (old code, new code, who, when) is listed under **History**.
4. Read the end of each row: days present, absences this month, absences used this calendar year against the allowance, and how many are left.
5. Read **Needs attention**: children who used 80% or all of their allowance, and any child absent **5 days in a row without notice**. Tell the family's caseworker (CSR).

The codes are worked out for you:

- **P** Present: the child was signed in.
- **S** Sick, **V** Vacation, **A** Absent: an absence reported by the family or staff (sick → S, holiday → V, anything else → A).
- **A** with a red outline: absent on a scheduled day and nothing was reported.
- **H** Statutory holiday: a closure of type *Holiday*. **N** Non-paid day: any other closure (PD day, emergency, renovation).
- **W** Withdrawal: on the child's withdrawal date.
- **O** Other: only after you have checked with your municipality.
- Blank: no care that day (weekend, a day the child is not scheduled). A grey dot means no fee subsidy that day.

## Setting capacity

1. Scroll to the capacity table on **Record of Attendance**. Licensed capacity is the total of your rooms' capacities for each age group.
2. Enter the **Operating** capacity for each age group: the spaces you can fill with the staff you have, never more than licensed.
3. Press **Save operating capacity**. It carries forward to the next month. Enrolled and vacancies are worked out for the last day of the month.

## Reviewing, signing off and entering it in OCCMS

1. Check the days and the capacity.
2. Press **Mark reviewed**. It opens on the 1st of the next month.
3. A **different person** presses **Sign off**. The grid is then frozen as signed: later attendance changes do not alter it (you will see how many days changed since).
4. Enter it in OCCMS, then press **Record OCCMS submission**, set the **Date entered** (and a reference if you have one) and press **Record**. The month is locked.

Changing a day after review sends the month back to draft. **Reopen** (with a reason) unlocks a signed-off month; **Amend** (with a reason) unlocks a submitted one so you can correct it and enter the correction in OCCMS.

**ROA as CSV** and **Printable ROA (PDF)** give you the grid laid out like the OCCMS ROA, with the capacity table and the sign-off names.

## Monthly attendance and fees sheet

1. Open the **Monthly attendance & fees** tab.
2. Read each enrolled child's hours in care each day (sign-in to sign-out), the month's hours, **Parent fees**, **Gov. fees**, **Total fees** and **Space type**.
3. Press **Sheet as Excel** or **Printable sheet (PDF)**. The printout adds a parent signature column.

The sheet is read-only: fix sign-ins, absences, closures or invoices at the source. It is also under **Reports → Monthly attendance & fees**.

## CWELCC summary and base fees

1. Open the **CWELCC summary** tab.
2. Read the children enrolled in the centre's rooms by age group, split into CWELCC-eligible and not, next to licensed and operating capacity.
3. Check **Leaving CWELCC in the next two months** (families need 2 months' notice) and **Check before you report** (families whose CWELCC enrolment does not match the child's age).
4. Under **CWELCC base fees**, enter each age group's December 31, 2024 daily fee and press **Save December 31, 2024 fees**. The base fee is the lower of the daily cap or that fee, never below the floor.
5. Review each CWELCC child's fee as a daily figure (**Within** or **Over by**), other charges to CWELCC families, and the closure days used this year.
6. Press **CWELCC summary as CSV** to download it.

A child is eligible under 6; one who turns 6 between January 1 and June 30 stays eligible until June 30 (preschool, kindergarten or family age group), otherwise until the end of the month they turn 6; in a school-age room, until the end of their birth month.

## Settings and reminders (agency admins)

1. Open the **Settings** tab.
2. Choose your **Municipality (CMSM / DSSAB)**: York Region (48 absence days a year, 3 fewer for each month after January the subsidy starts), City of Toronto (35 a year, at most 20 in a row), Region of Peel (enter the allowance from Peel's Service Provider Handbook) or Other (York's rules until you set your own).
3. Change any number, such as **Absence days a year**, **Most absence days in a row** or the **Due day of the month**, or press **Use the municipality's defaults**.
4. Tick or untick the reminder and press **Save**.

On the 1st, directors and admins get a bell and an email that last month's ROA is ready to review, and two days before the due day (the 6th for York) another if it is not signed off yet.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Every centre; all tabs including **Settings**. |
| Centre director | Their own centres; reviews, signs off and records submissions. No **Settings** tab. |
| Platform admin | The same as an agency admin, in the agency they are working in. |

The screen appears only in Ontario agencies. Educators and parents do not see it.

## FAQs and when something goes wrong

??? Mark reviewed is greyed out. | The month is not over yet. It opens on the 1st of the next month.
??? Sign off is greyed out: "A different person must sign off". | You reviewed the month. A second director or admin signs it off.
??? A child is missing from the ROA. | Only children with a provincial fee subsidy that month are listed. Add it under **Compliance → Subsidies → Provincial subsidy**.
??? The absence allowance shows "not set". | An agency admin enters it on the **Settings** tab.
??? A day changed after I signed off. | The signed grid stays as signed and shows how many days changed since. Use **Reopen** to correct it.

## Related screens

[[open: #cwelcc | Subsidies]] [[open: #admin-families | Families]]

See also: [Subsidies — CWELCC and provincial fee subsidy](subsidies)
