---
title: Bulk invoice generation
category: Billing
order: 70
roles: agency_admin, platform_admin
screens: bulk-invoices
---
# Bulk invoice generation

**Finance → Bulk invoice run** raises the month's fee invoices for every enrolled family at a centre in one go, then emails them. Sibling discounts and the CWELCC subsidy are applied automatically. The same screen also lets you raise a single invoice for a family, an educator or a contractor.

[[open: #bulk-invoices | Open Bulk invoice run]]

The screen has three tabs: **👪 Parents** (fee invoices for families, by centre), **🧑‍🏫 Educators** and **🧾 Contractors & suppliers**.

## Running the month's invoices for a centre

1. In the sidebar, open **Finance** and choose **Bulk invoice run**.
2. At the top, pick the **Billing period**: the month and the year. It starts on the current month.
3. On the **👪 Parents** tab, find the centre. Each row shows its city, how many children are enrolled and how many families.
4. Open the centre's **⋮** menu and choose **Generate invoices**.
5. Read the prompt and confirm. It creates one invoice per enrolled family; families already invoiced for that month, here or in a connected billing system, are skipped.
6. The row shows "✓ N created" with the total, and an **Invoices generated** toast says how many were created and how many were already billed.

Each invoice carries:

- one **tuition** line per enrolled child, from their enrolment's monthly fee;
- a **CWELCC subsidy** line taken off when the centre is opted in;
- a **sibling discount** line when your agency has tiers set up (see [Sibling discounts](sibling-discounts));
- the full month as its period, issued on the first of the month and due 15 days later.

## Checking and emailing the invoices

1. Open the centre's **⋮** menu and choose **View invoices**. A window lists that centre's invoices for the chosen period.
2. Press the eye icon (**View invoice**) on any row to open the branded invoice.
3. To cancel a wrong one, use the void button on its row and confirm. A paid invoice shows **PAID** instead: refund the payment first, then it can be voided.
4. When you are happy, close the window and choose **⋮ > Email invoices to families**.
5. Confirm. An **Invoices emailed** toast says how many were emailed and how many were skipped because there is no email on file.

> ⚠️ Generating invoices sends nothing. Families are only emailed when you choose **Email invoices to families**, and only if your agency's email is switched on.

## Raising a single invoice

1. Press **🧾 Generate an invoice** on the tab you need (on **🧑‍🏫 Educators**, you can also press **Invoice** beside a person).
2. Choose **Who** from the list, or type a name.
3. Under **Based on**, choose **A set amount** and enter the **Amount**, or **Hours worked × a rate** and enter **Hours** and **Rate / hour** (for an educator, **Use hours from the timesheet** fills the hours in).
4. Optional: set **Period from** / **to**, **Details**, **Extra line items** (**+ Add a line**), **Apply tax**, and **Repeat this on a schedule** (every week, two weeks or month).
5. Press **Generate**. Check the summary under **Check this before it is created**.
6. Press **Create this invoice** (or, for a repeating one, review each period under **Confirm each period** and press **Create these invoices**). An **Invoice created** toast confirms it.

The invoice appears in the list on that tab, where you can **View**, **Resend**, **Mark issued**, **Mark paid** or **Void** it. Nothing is sent when it is created.

> 💡 Educators are normally paid from their recorded hours in **Payroll**, and supplier bills are best handled in **Expenses**. The tabs say so and link there.

## Re-running a month

Invoices already generated for that month are not duplicated. Running again on the same month creates invoices only for families that do not have one for that period yet, so it is safe to run again after enrolling a new family. To redo a family's invoice, void the old one first (in **View invoices** or **Accounting**), then run again.

## Who can do what

- **Agency admins**: run any centre in their agency.
- **Platform admins**: run any centre in the agency chosen in the top-bar switcher.
- **Centre directors**: do not have this screen. Ask your agency admin to run the month, or raise invoices one at a time from **Accounting**.
- **Educators** and **parents**: no access.

## Common questions

??? The run created 0 invoices. | Check that children at the centre are Enrolled (not waitlisted, withdrawn or graduated) with an open enrolment, and that their families were not already invoiced for this month here or in a connected billing system.
??? The toast says "Generation failed". | The message under it says why. Fix that (for example, pick the right agency) and run again. Families invoiced in the first attempt are skipped.
??? Some families were skipped when emailing. | They have no email on file. Add one to the guardian's profile, then email again or use Resend invoice in Accounting.
??? Error "Pop-up blocked". | Allow pop-ups for this site to view an invoice.
??? "No centres in this agency." | A platform admin may need to pick an agency first with the top-bar switcher.
??? What does Export invoices CSV include? | Every invoice in the agency, not only this centre or month.

## Related screens

[[open: #external-billing | Accounting]] [[open: #payment-plans | Payment schedules]] [[open: #billing-settings | Billing settings]]

See also: [Generating monthly invoices](generating-monthly-invoices) · [Sibling discounts](sibling-discounts) · [Accounting](accounting)
