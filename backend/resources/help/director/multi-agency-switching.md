---
roles: agency_admin, platform_admin
title: My agency and switching agencies
category: Administration
order: 30
screens: agencies
---
# My agency and switching agencies

**My agency** shows the agency you are signed in to: its web address, plan, number of centres and users, and its billing status. From here an agency admin manages the agency's own details, address, email sender and automation switches. If you look after more than one agency, the agency switcher in the sidebar moves you between them without signing out. Open it from the sidebar: **Overview > My agency**.

[[open: #agencies | Open My agency]]

## Reading the agency card

The screen shows one card for your agency, with:

- the agency name and a coloured billing status tag (for example **ACTIVE** or **TRIAL**);
- 🌐 the web address people sign in at, when the agency has its own subdomain or domain;
- 📦 the plan, 🏫 the number of centres and 👥 the number of users;
- a **Manage** button on the right.

## Changing the agency's details

1. Press **Manage** on the agency card. The **Manage: (agency name)** window opens on the **Details** tab.
2. Check or change **Name**, **Legal name** (only if different from the trading name), **Website** and **Timezone**. Every time the agency records or shows (sign-ins, rotas, reports) uses this time zone.
3. Open the **Address** tab and fill in **Address line 1**, **City**, **Province / State**, **Postal / ZIP code** and **Country**. This address is printed on invoices and receipts and sets the statutory holiday calendar.
4. Open the **Email** tab. **Contact email** and **Contact phone** are where KiddieTrac writes to the agency itself. **From name** and **From address** are how your mail appears to families.
5. Press **Save**. A green **✓ Saved** appears and the window closes.

> ⚠️ Changing the **Timezone** changes how every time is shown for the whole agency. Only change it if it is genuinely wrong, and ask KiddieTrac support first if you are unsure.

## Turning sending and automation on or off

1. Press **Manage** and open the **Plan & automation** tab.
2. **Send notifications and emails** is the master switch. Turned off, nothing goes out to staff or families: no email, no text, no push, no in-app alert. Everything is still recorded.
3. **Send daily & weekly summaries** controls the summary emails to admins, directors, parents and educators.
4. **Fill staff schedules automatically** rosters every centre 28 days ahead each night at 04:30, from each centre's opening hours. Closure days are skipped, rostered days are never overwritten and a deleted shift stays deleted.
5. Press **View my licence →** to see your plan, add-ons and renewal date.
6. Press **Save**.

## Switching to another agency

1. Look at the bottom of the sidebar, just above your name. When you can use more than one agency, a pill shows the current agency's name.
2. Click it. Every agency you can manage is listed, with a tick on the active one.
3. Pick another agency. The portal shows "⏳ Switching…" and reloads; every screen now shows that agency only.

Nothing is copied between agencies: centres, families, staff, invoices, roles, branding and settings stay separate. Your own sign-in, two-factor set-up and chats belong to you and follow you across agencies.

## Who can do what

- **Agency admin** – sees **My agency**, edits details, address, email and automation, and can switch between agencies they were invited to as agency admin.
- **Platform admin** – does not have **My agency** in the menu; uses **All agencies** instead, which also offers **+ New Agency**, **Suspend** / **Resume** and pinning the billing status.
- **Centre director** – does not see this screen.

## FAQs and troubleshooting

??? How do I get access to a second agency? | An agency admin of that agency invites you as Agency admin from their User management screen. The agency appears in your switcher the next time the page loads.
??? I don't see the agency switcher. | It only appears when you have more than one agency.
??? The switcher says "Could not switch agency. You may not have access." | Your access to that agency was removed or has not been set up. Ask that agency's admin.
??? I pressed Delete and got "Only platform admins can delete agencies." | Deleting an agency is for KiddieTrac platform admins only. Contact KiddieTrac support if an agency should close.
??? Where do I change the plan or add-ons? | Not here. Press **View my licence →** on the **Plan & automation** tab.
??? Where do I set the logo and colours? | In **Branding**; mail servers and templates are in **Email settings**.

## Related

[[open: #dashboard | Agency overview]] [[open: #admin-centres | Centres]] [[open: #email-settings | Email settings]]

- [[plan-and-add-ons]]
- [[white-label-branding]]
- [[email-and-digests]]
