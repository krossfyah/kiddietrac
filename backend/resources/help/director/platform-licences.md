---
title: Licences (agency plans and add-ons)
category: Billing
order: 89
roles: platform_admin
screens: licences
---

# Licences (agency plans and add-ons)

**Platform › 🔑 Licences** is where KiddieTrac decides what each agency can use. Every agency has one licence: a plan (for example Starter, Growth or Agency) plus any add-ons, each with a part number, price, term, end date and renewal setting. The licence switches features on and off, and renewals bill through **Platform › 🏦 Accounting AR/AP** when billing is on. Approving a proposal creates the licence for you; this screen is where you add, change, renew or cancel by hand.

[[open: #licences | Open Licences]]

## Reading the list

1. Open **Platform › 🔑 Licences**. The tiles show **Live licences**, **Monthly recurring**, **Renew within 30 days** and **In grace (needs attention)**.
2. Narrow the list with **Agency** and **Status** (**Active**, **Grace**, **Renews within 30 days**, **Expired**, **Cancelled**).
3. Each agency is a group. Press its header row to open or close it, or use **▾ Expand all** / **▸ Collapse all**. Agencies that need attention open by themselves.
4. The header shows the term (**Renews** or **Ends** a date, or **Grace until** a date), how it is billed, the add-ons, the status, the **🏫 open / allowed centres**, and **Trial** or **Complimentary** when it applies.
5. The lines underneath show **Part #**, **Item**, **Price**, **Term** and **Status**. **Not renewing** means that line ends on its date.

## Adding a licence

1. Press **＋ Add licence**.
2. Choose the **Agency** and the **Plan or add-on**. The **Price per term** fills in from the catalogue; change it if you agreed a different price.
3. Choose the **Licence type**: **Paid**, **Complimentary (billed at list, zeroed)** or **Trial**. A trial asks for its **Trial length** in days, weeks, months or years, costs nothing, does not renew and raises no invoice.
4. Set **Billed**, **Starts**, an optional **Note**, and **Renews automatically**.
5. Press **Add licence**. A **Licence added** toast appears.

> ℹ️ An add-on always joins the agency's plan term. A plan for an agency that already has one replaces it and keeps its end date.

## Changing an agency's licence

Open the **⋮** on the agency's header row:

1. **＋ Add add-on** (or **＋ Add plan** when there is none): choose it and press **Add add-on**.
2. **📅 Term & renewal**: set **Ends / renews on**, **Billed**, **Contract term (months)** and **Renews automatically**, then **Save for the whole licence**. The plan and every add-on share this term; a future end date re-opens a licence in grace or one that has ended.
3. **🔁 Renew all**: renews every line together to one new end date. No invoice is raised here; billing raises its own.
4. **💳 Convert to paid** (trials only): every line takes its price and renews from its end date.
5. **🏫 Included centres…**: override how many centres the licence allows. Leave it blank to use the product's number. Existing centres are never closed; only opening a new one is blocked.
6. **⛔ Cancel licence**: the plan and every add-on stop straight away, except invoicing and autopay, which run to the end of the period already paid.

On a single line, the **⋮** offers **✏️ Edit (price, quantity, note)** and, for add-ons, **🗑️ Remove add-on**, whose features switch off straight away.

> ⚠️ After you remove an add-on or cancel a licence, KiddieTrac offers **Reissue invoice** for each open invoice that still bills it. Reissuing voids that invoice and raises a corrected copy for the same period; payments move across. Email it from Accounting AR/AP.

## Sales by part number

Choose the **📊 Sales by part #** tab to see each **Part #** with **Live**, **Monthly recurring**, **Invoiced this year** and **Invoiced all time**.

## Who can do what

- **Platform super-admins**: everything on this screen.
- **Agency admins**: see their own licence, read-only, on their own **Licence** page (from **🏢 My agency**), and can ask to add a feature from there. See [[plan-and-add-ons]].
- **Everyone else**: never sees licences. Features not in the plan are hidden from staff and families.

## FAQs and when something goes wrong

??? "Choose the agency and the plan or add-on" | Both drop-downs must be filled before **Add licence** works.
??? An agency says a feature is still locked after I added it. | Open screens pick up the change within moments; otherwise it shows on their next move to another screen.
??? What is Grace? | The end date has passed without renewal. The agency keeps its features while the renewal invoice is raised. Renew, change the term, or cancel.
??? The list says "No licences yet." | Approving a proposal creates them, or press **＋ Add licence**.
??? The centre count shows in red. | The agency has more open centres than the licence allows. Add an extra-centre add-on or raise **🏫 Included centres…**.

## Related screens

[[open: #sales-invoices | Accounting AR/AP]] · [[open: #platform-sales | Sales reports]] · [[open: #platform-agencies | All agencies]] · [[open: #admin-features | Feature flags]]

See also: [[platform-invoices]] · [[plan-and-add-ons]] · [[platform-sales-reports]]
