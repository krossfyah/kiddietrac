---
title: Plans & pricing (sales price list)
category: Sales
order: 5
roles: sales_rep, agency_admin, platform_admin
screens: sales-plans
---
# Plans & pricing (sales price list)

**Plans & pricing** is KiddieTrac's own price list for proposals: the plans, add-ons, one-time services and discounts
a rep drops into a proposal with **+ Add preset plan or discount…**, and the terms every new proposal starts with.
Superadmins open it from **Sales › Plans & pricing** in the platform menu; sales reps from the **Plans & pricing** tile
on their Home screen. Changing a price here never changes a proposal already made.

[[open: #sales-plans | Open Plans & pricing]]

## The five tabs

- **📦 Plans** — the customer's plan sets which features they get (its **Feature level**).
- **🧩 Add-ons** — sold on top of a plan; each switches on the features you tick.
- **🛠️ One-time services** — setup, training, data import: billed once, no licence.
- **🏷️ Discounts** — percentage or amount off, added to a proposal from the same menu.
- **📄 Proposal terms** — validity, the "Prepared by" contact, terms, taxes and stage odds.

Each item gets a part number the first time it is saved (KT-PLN plans, KT-ADD add-ons, KT-SVC services, KT-DSC
discounts). Part numbers show on proposals, licences and invoices, and sales are reported by them.

## Adding or changing a plan, add-on or service

1. Open the tab and press **➕ Add plan** (or **➕ Add add-on**, **➕ Add service**). A new card appears at the top.
2. Type the name and the price, and pick **/month** or **/year** (a service is always **one-time**).
3. For a plan, choose the **Feature level** (**Starter features**, **Growth features** or **Agency (all features)**).
4. For an add-on, tick what it **Switches on** (for example **Texting (SMS)**, **Payroll**, **White-label**).
5. Set **Centres included (blank = no centre cap)** if the item includes a number of centres.
6. Write the **Description** shown on proposals and press **Save**. A **✅ Saved** toast appears and the part number shows on the card.

## Adding a discount

1. Open **🏷️ Discounts** and press **➕ Add discount**.
2. Name it (for example "Non-profit discount"), enter how much, and choose **% off** or **$ off**.
3. Pick what it **Applies to**: **the whole proposal**, **recurring items (monthly / yearly)** or **one-time items**. The "Shows as:" line previews the proposal line.
4. Press **Save**.

## Setting the proposal defaults

1. Open **📄 Proposal terms**.
2. Set **Proposals are valid for** … **days**, and the **Title / team**, **Sales email** and **Sales phone** shown as the "Prepared by" contact (replies go to that email).
3. Edit **Proposal terms (short, under the prices)** and **Terms & conditions (their own page, before the signatures)**. `{days}` is replaced by the number of days.
4. Add **Default taxes** with **+ Add tax** (up to 5), and set each stage's **Chance of closing by stage** for the Pipeline's weighted forecast.
5. Press **Save** ("Proposal defaults saved").

## Who can do what

| Role | Can |
|---|---|
| Sales rep | Add, change and delete plans, add-ons, services and discounts, and edit the proposal defaults. |
| Platform admin (superadmin) | The same, from the platform's Sales menu. |
| Agency staff | Never see this screen: an agency's own plan is under its settings, not here. |

## FAQs and when something goes wrong

??? "Give it a name" / "Give the discount a name" | Every item needs a name before **Save** works.
??? "Choose the feature level this plan gives" | A plan must have a **Feature level**; pick one and save again.
??? "Enter how much it takes off" or "A percentage can be at most 100" | Enter a discount above 0, and no more than 100 for **% off**.
??? I changed a price but an old proposal still shows the old one. | That is on purpose: each proposal keeps its own copy. Edit the proposal's line, or make a new proposal.
??? What happens when I delete an item? | It is removed for good from this list. Proposals that already use it keep their lines.

## Related screens

[[open: #sales-quotes | Proposals]] [[open: #sales | Pipeline]]

See also [[sales-proposals]] and [[sales-pipeline]].
