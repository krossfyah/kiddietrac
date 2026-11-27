---
title: Email settings — your agency's emails, sender and reminders
category: Settings
order: 64
roles: agency_admin, platform_admin
screens: email-settings
---
# Email settings — your agency's emails, sender and reminders

**Settings → Email settings** controls how emails go out for your agency: the master switch, the sender name families see, which centres and rooms receive email, and the automatic reminders (closures, holidays, birthdays, immunizations, billing). Open it when you set up a new agency, open a new centre, or someone says they are not getting emails.

[[open: #email-settings | Open Email settings]]

The screen has tabs: **📬 General**, **📤 Outbound**, **📥 Mailbox**, **🎛️ Delivery**, **🗓 Closures**, **🎂 Birthdays**, **🩹 Immunization** and **💳 Billing**. Switches save as soon as you change them; boxes you type in save with **Save settings**.

## Turning email on or off for the whole agency

1. Open the **📬 General** tab.
2. Find **📬 Mail & notifications**. This is the master switch for every outgoing email and notification for this agency.
3. Leave it on. Turn it off only to stop everything (for example while you set up a new agency): with it off, nothing goes out to families or staff, texts and calls included.

> ⚠️ When this switch is off, a broadcast or announcement can still say "sent" while nobody receives it. Check this switch first when nobody is getting anything.

## Setting the sender name and address

1. On **📬 General**, type the **From name** families should see, for example your agency's name plus "Childcare".
2. Type the **From address**. If you leave it blank, the platform's default sender is used.
3. Press **Save settings**.
4. Press **Send test email to me** and check your inbox.

## Choosing other automatic emails

On **📬 General** you also find:

- **⏰ Attendance reminders** — emails an educator who logs daily moments for a child who is not signed in. Admins and directors are BCC'd.
- **📨 Onboarding reminders** — a daily email to people who were invited but have not finished setting up. Pick the hour in **Send each day at** (agency local time).
- **📷 One-Tap nudges** — a weekly email to parents whose child was signed in or out by hand more than twice in a week, encouraging One-Tap.
- **⏰ Unread chat messages** — how long a chat message can sit unread before the person is emailed. Set the minutes in **Email after** and press **Save settings**. Everything waiting goes in one email per person.

## Holding email back for a centre or room

1. Open the **🎛️ Delivery** tab. **🎛️ Centre & room email delivery** lists every centre and its rooms.
2. Switch a centre or a room off to hold back every email to its educators and families, for example a centre that is not live yet.
3. A toast **Email on** or **Email off** confirms each change.

## Closure and holiday reminders

1. Open the **🗓 Closures** tab and turn on **🗓 Closure reminders**.
2. Turn on **Send as soon as it is added** to announce a closure the moment it is entered on the calendar.
3. Under **Then remind again**, tick the days before the closure (for example **3 days** and **Day before**).
4. To close on public holidays, turn on **Close automatically on statutory holidays**, choose the **Holiday calendar**, tick any extra days under **Also close for**, and pick when to **Tell everyone** (counted in working days).

[[show: #email-settings @ Closures | Show me the Closures tab]]

## Mailboxes, outbound email and linking emails to people

- **📥 Mailbox**: turn on **🔗 Link emails to people** to file emails from the agency's mailboxes on the parent, staff member or contact they are with. Choose whether to **Link automatically by email address**, which kinds of people, and **Who can see linked emails**.
- **📤 Outbound**: **📤 Outbound email (SMTP)** lets you send through your own provider. Leave **Sending method** on **Platform default** unless your provider has given you SMTP details.

> ⚠️ After choosing **My own SMTP server**, always press **Send test email to me**. If the test does not arrive, switch back to **Platform default** so families keep getting your emails.

## Who can do what

| Role | Access |
|---|---|
| Agency admin | Everything on this screen, for their agency |
| Platform admin | The same, plus the **🌐 Platform mail** tab |
| Centre director | Not in their menu. Ask an agency admin |

## FAQs and when something goes wrong

??? Nobody is getting emails. | Check **📬 Mail & notifications** on General, then the centre and room on **🎛️ Delivery**. Then check the person's email address and their own choices in **My settings**.
??? The test email did not arrive. | Look in junk mail. If you use your own SMTP server, switch back to **Platform default** and test again.
??? "Could not load email settings". | Reload the page. If it keeps happening, raise a support ticket.
??? Can I change the wording of an email? | Yes, on **Settings → Email templates**.
??? Where are the counter-signature, file and bio reminders? | On **Settings → Agency notifications**.

## Related screens

[[open: #email-templates | Email templates]] [[open: #notification-settings | Agency notifications]] [[open: #email | Email client]]

See also: [Agency notifications](agency-notifications), [Email templates](email-templates), [Missed-message email notifications](missed-chat-emails).
