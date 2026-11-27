---
title: Subsidies — CWELCC and provincial fee subsidy
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
screens: cwelcc
---
# Subsidies — CWELCC and provincial fee subsidy

**Compliance → Subsidies** holds the two kinds of fee help your families get. The **CWELCC** tab is the monthly CWELCC report for your claim. The **Provincial subsidy** tab records each child's fee subsidy from the province or municipality, which then comes off that child's tuition on the family's invoice. The screen remembers the tab you were on.

[[open: #cwelcc | Open Subsidies]]

Agency admins see the whole agency. A centre director sees and manages only their own centres.

## Running the CWELCC monthly report

[[show: #cwelcc @ CWELCC | Show me the CWELCC tab]]

1. Open **Compliance → Subsidies** and press **CWELCC**.
2. Pick the **Month**. The **CWELCC monthly report** reloads for that month.
3. Read the tiles: **CHILDREN**, then (in Ontario) **BASE FEE REVENUE**, **FEE SUBSIDY PAID** and **PARENTS PAID**.
4. Check the rows: one per enrolled family, with the **Children**, the **Centre**, the enrolment dates and the **Base fee**, **Fee subsidy** and **Parents** amounts for the month.
5. Press **⤓ CSV** or **⤓ PDF** to download the month for your claim.

In Ontario since 2025, parents pay the base fee, less any fee subsidy. The base-fee limits and extra-fee checks are on **Compliance → OCCMS reporting**.

The report reads each family's **enrolment periods**, so a past month always shows what applied then: a family that has since left CWELCC still appears in the months it was enrolled, and a change only affects months from its start date. Voided and draft invoices are not counted.

## Enrolling a family in CWELCC

1. Open **Administration → Families** and open the family.
2. Scroll to **Subsidies**. It shows the family's whole history: every CWELCC enrolment period with who recorded it, and every provincial subsidy, including ended ones.
3. Press **Enrol in CWELCC**, set **From** (the first day) and the **Subsidy rate (%)** if your province uses one, then press **Save**.
4. When something changes, press **Change rate** (the new rate applies from the date you pick; earlier months keep the old one) or **End CWELCC** and set the **Last day** (earlier months keep their enrolment).

A change never rewrites an earlier period, so a claim reads the same later as it did when it was filed. Every change is in the audit log.

## Adding a provincial fee subsidy

[[show: #cwelcc @ Provincial subsidy | Show me the Provincial subsidy tab]]

1. Open **Compliance → Subsidies** and press **Provincial subsidy**.
2. Press **＋ Add subsidy**. The **Add a provincial subsidy** window opens.
3. Choose the **Child**.
4. Type the **Case number** from the approval letter.
5. Under **The subsidy is paid**, choose **Per month** or **Per day**, then enter the **Monthly amount ($)** or **Daily amount ($)**.
6. Set the **First day**, and the **Last day (optional)** if the approval has one. Add **Approved on (optional)** and **Notes (optional)** if useful.
7. Under **Documents from the province or government**, attach the approval letter (PDF, photo or Word, up to 15 MB each). You can attach several.
8. Press **Save**. The subsidy appears in the month's list.

How it comes off the invoice while it applies:

- **Per month**: the monthly amount comes off each monthly invoice.
- **Per day**: the daily amount × the days the child is **scheduled** to attend that month, not counting days the centre is closed. The invoice line shows it, for example *Subsidy (21 scheduled days × $20.00)*. Record holidays in the centre's calendar so they are not counted.

The family is billed only the rest, never less than zero. Invoices already issued are not changed.

## Changing, ending or removing a subsidy

1. On the **Provincial subsidy** tab, find the child's row. Its **Subsidy** amount turns red if it is more than the **Tuition**: worth a second look.
2. Open the row's **⋮** menu.
3. Choose **Edit** to correct the amount, case number, dates or documents.
4. Choose **End subsidy** and set the **Last day** when the subsidy stops. Invoices after that day no longer include it, and its history is kept.
5. Choose **Remove** only for a subsidy entered by mistake, and confirm.

> ⚠️ A child can have one provincial subsidy at a time. If the dates overlap an existing one, the save is refused and names it. For a renewal, end the old one on the day before the new one starts.

The tab lists the subsidies that apply in the chosen **Month**, with **CHILDREN** and **SUBSIDY THIS MONTH**. Tick **Show every subsidy, including ended** for the full history. **⤓ CSV** downloads the month for reconciling with the province or municipality. Every add, edit, end and removal is in the audit log.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Both tabs for every centre; records CWELCC changes and provincial subsidies. |
| Centre director | The same, for their own centres only. |
| Platform admin | The same as an agency admin, in the agency they are working in. |

Educators do not see this screen. Parents see only the result: a **Subsidy** line on their invoice. Subsidy documents can be opened only by signed-in directors and admins.

## FAQs and when something goes wrong

??? A family left CWELCC. Will it vanish from last spring's report? | No. The report reads the enrolment periods, so the family stays in every month it was enrolled.
??? The CWELCC tab says "No CWELCC-enrolled families for this month." | No family of your centres was enrolled that month. Enrol families from their family record, under **Subsidies**.
??? "Choose the child." / "Enter the monthly amount." / "Enter the first day." | A required field is empty. Fill it in and press **Save** again.
??? "The last day cannot be before the first day." | Check the two dates; the last day must be on or after the first day.
??? "The subsidy was saved, but the document was not uploaded" | The subsidy is in. Open it with **Edit** and attach the document again (PDF, photo or Word, up to 15 MB).
??? Does a provincial subsidy show on the parent's invoice? | Yes. The invoice shows the tuition, a **Subsidy** line taking the amount off, and the balance the family owes.
??? Can a child have CWELCC and a provincial subsidy? | Yes. The CWELCC report comes from the family's CWELCC enrolment; the provincial subsidy is one line per child on the invoice.
??? Where did "CWELCC subsidies" go? | It is now **Subsidies**, with **CWELCC** as the first tab. The report is unchanged.
??? Is grant funding recorded here? | No. Operating and wage grants the agency receives are on **Finance → Grants**.

## Related screens

[[open: #subsidy-roa | OCCMS reporting]] [[open: #admin-families | Families]] [[open: #grants | Grants]]

See also: [OCCMS reporting](occms-reporting) · [Generating monthly invoices](generating-monthly-invoices) · [Invoice statuses](invoice-statuses)
