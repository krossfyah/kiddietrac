---
title: Managing resellers
category: Administration
order: 12
roles: agency_admin, platform_admin
screens: resellers
---
# Managing resellers

**Resellers** is the platform team's screen for partners who sell KiddieTrac under their own brand. Each reseller owns its agencies and sets one brand for them. Here you create a reseller, set its brand and licence, choose its agencies, give people access, set its commission and prepare its own branded Android app.

[[open: #resellers | Open Resellers]]

## Creating a reseller

1. Open **Resellers** from the **Reseller** section of the menu.
2. Press **+ New reseller** in the banner.
3. Type the **Company name** and press **Create**.
4. The new reseller is selected in the list on the left, on its **Brand** tab. Its **App build code** shows beside the tabs.

## Setting its brand and licence

1. Pick the reseller in the list on the left (each shows its number of agencies and status).
2. On the **Brand** tab, fill in **App & product name** and **Support email**, upload the **Logos**, and set the **Colours** and **Page banner**, watching the **Live preview**.
3. Under **Licence**, tick **Hide KiddieTrac everywhere** if the licence includes it: its agencies then see only the reseller's name and logos on screens, emails, texts, documents and notifications. Only the platform can change this box.
4. Tick **Let my agencies use their own logo and colours** if its agencies may override the brand.
5. Press **Save brand**.

## Choosing its agencies

1. Open the **Agencies** tab.
2. Tick each agency that belongs to this reseller. An agency that is greyed out with "— with …" already belongs to another reseller: untick it there first.
3. Press **Save agencies**. A green "Agencies updated." toast appears and the ticked agencies show the reseller's brand.

## Giving people access

1. Open the **People** tab. It lists who manages the reseller.
2. Type the **Email of an existing account** and press **Add**. They get the Reseller role: they work as agency admin in every agency the reseller owns, add agencies, manage those agencies' admins, see billing and commission, and change the brand.
3. Press **Remove** beside a person to take the role away.

[[show: #resellers @ People | Show me the People tab]]

## Setting the commission

1. Open the **Commission** tab.
2. Enter the **Percentage** of the money collected before tax.
3. Enter the **Flat per paying agency ($)** amount, or leave it at 0.
4. Press **Save commission**. Press **Monthly statement →** to open that reseller's **Billing & commissions** statement.

## Preparing the reseller's own app

1. Open the **App** tab.
2. Enter the **Package id** (for example `com.littlesteps.app`). It is permanent once the app is published.
3. Set **Status** (**Not started**, **Kit downloaded**, **Built**, **Published**), and optionally **Version number (optional)** and **Version name (optional)**.
4. Add the **Google Play listing** link once it exists, and tick **Firebase file is in place** when it is.
5. Press **Save**, or press **⬇ Download build kit** to save and download the kit file for the build computer.
6. Work down the **Checklist**; each line ticks itself as it is done. **On the phone** shows the icon (the Brand tab's square logo) and name people will see.

> ℹ️ People can also use the standard KiddieTrac app: it switches to the reseller's brand after they sign in.

## Who can do what

| Role | What they can do |
|---|---|
| Platform admin | Everything on this screen. |
| Reseller | Does not see this screen. Changes its own brand on **Branding** and reads its terms on **Billing & commissions**. |
| Agency admin | Does not see this screen. |

## FAQs and when something goes wrong

??? "No account uses that email. Create the person first (Admin → Users), then add them here." | The person needs a KiddieTrac account before they can be given the Reseller role. Create the user, then add their email again.
??? "Several accounts share that email…" | Ask the person which account to use and add that one.
??? "That short name is already used by a reseller or an agency." | Pick a different company name when creating the reseller.
??? "That is the KiddieTrac app's own package id; a reseller app needs its own." | Enter a package id unique to the reseller, such as com.theirname.app.
??? "Choose the package id first." | Fill in **Package id** before you press **⬇ Download build kit**.
??? The list says "No resellers yet." | Press **+ New reseller** to create the first one.
??? Is anyone emailed when I add a person or save the brand? | No. A person you add sees the Reseller section the next time they sign in or reload the page; agencies see a saved brand the next time they open a page.

## Related screens

[[open: #reseller | Reseller overview]] [[open: #reseller-billing | Billing & commissions]] [[open: #platform-agencies | All agencies]]

- [[reseller-all-my-agencies]]
- [[reseller-billing-and-commissions]]
- [[white-label-branding]]
