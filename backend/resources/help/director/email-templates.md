---
title: Email templates — changing the wording
category: Settings
order: 42
roles: agency_admin, platform_admin
screens: email-templates
---
# Email templates — changing the wording

**Settings → Email templates** lists every email KiddieTrac sends for your agency, grouped by who it is for (parents, billing, staff, accounts, admin alerts). From here you can see exactly what each email looks like and rewrite its wording in your own voice. Your logo, colours, footer and the details each email fills in (names, dates, amounts) stay automatic: you only change the words.

[[open: #email-templates | Open Email templates]]

## Finding an email

1. Open **Email templates**. The line at the top says how many emails there are, how many can be reworded and how many you have already **customised**.
2. Type in **Find an email…** to filter the list by name, when it is sent, who it goes to or its subject.
3. Read each row: the email's name, when it is sent, and **To** (who receives it).
4. Look at the badges: **● customised** (no longer the standard wording), **Preview only** (cannot be reworded), **Edited per event** (worded on each event in Event RSVPs) and **also push / text** (editing here changes the email only; the push notification and text keep their standard wording).

## Previewing an email

1. Press **⋮** on the row, then **👁 Preview**.
2. The email is built by the code that really sends it, with sample names and figures. Nothing is sent.
3. Close the preview with **×** or **Escape**.

## Rewording an email

1. Press **⋮** on the row, then **✏️ Edit**. The editor opens in a popup.
2. Change the wording in each box. Leave a box empty to keep the automatic wording.
3. To add a detail that changes for each person, put your cursor in a box and click a chip under **Merge tags — click to add to the field you are in**, for example `{{agency_name}}`. Keep the double braces.
4. Use the toolbar for bold, italic, underline, links, images, font and size where the box offers it. In plain boxes, write `**bold**` with two asterisks; a new line starts a new line.
5. Press **👁 Preview** to see it with your changes.
6. Press **📤 Send test…**, type your address and press **Send test**. A copy marked [Test] arrives with sample details. Leave **use my unsaved changes** ticked to test before saving.
7. Press **Save**. **Saved.** appears and the row shows **● customised**.

> 💡 **↺ Reset to default** beside a box puts back KiddieTrac's original wording for that box.

> ⚠️ Closing the editor with unsaved changes asks **Discard your changes?** Choose **Keep editing** to go back, or **Discard changes** to lose them.

[[show: #email-templates @ Find an email… | Show me the search box]]

## Staff welcome — what is automatic

You write the heading, welcome, first steps and sign-off. KiddieTrac adds the rest for each person:

- your **care values**, taken from the Provider welcome's **Our care** text — edit them there and both emails change;
- a short tour of the portal features they will use;
- a link to **Help & guides**;
- who to ask — their director's name and contact details.

## Who can do what

| Role | Access |
|---|---|
| Agency admin | Preview and reword every email marked as editable, for their agency |
| Platform admin | The same, plus emails marked **KiddieTrac-wide**, whose wording is used for every agency |
| Centre director, staff, parents | No access |

## FAQs and when something goes wrong

??? I saved a change but an email still shows the old wording. | Emails already sent keep the words they were sent with. The next email uses your new wording.
??? There is no ✏️ Edit for an email. | It is marked **Preview only** (its wording is fixed), or **Edited per event**: press **📅 Edit in Event RSVPs** instead.
??? A merge tag shows "Unknown tag — it will be sent exactly as typed". | The tag is misspelled. Click the tag from the chip list instead of typing it.
??? Can each centre have its own wording? | No. Templates are per agency.
??? Where do I change the logo and colours? | On the **🎨 Branding** screen. The templates use them automatically.
??? Can I stop an email being sent? | Not here. The switches for automatic emails are on **Settings → Email settings** and **Settings → Agency notifications**.

## Related screens

[[open: #email-settings | Email settings]] [[open: #notification-settings | Agency notifications]]

See also: [Email settings](email-settings), [Agency notifications](agency-notifications), [Inviting parents and staff](inviting-parents-and-staff), [White-label branding](white-label-branding).
