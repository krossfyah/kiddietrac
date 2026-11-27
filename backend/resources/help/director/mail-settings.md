---
title: Mail settings — how KiddieTrac sends email
category: Administration
order: 90
roles: agency_admin, platform_admin
screens: mail-settings
---
# Mail settings — how KiddieTrac sends email

**Platform → Mail settings** is where a KiddieTrac platform admin chooses how the whole platform sends outgoing email, and sets up the Microsoft and Google sign-in apps that agencies use to connect mailboxes. Agencies never see this screen: their own sender name and address are on **Settings → Email settings**.

[[open: #mail-settings | Open Mail settings]]

## Choosing the send method and sender

1. Open **Mail settings**. The first card is the platform's outgoing mail.
2. Under **Send method**, pick **Microsoft Graph (recommended — with sendmail fallback)**. **Local sendmail only** is for emergencies: mail goes out, but lands in junk more often.
3. Enter the **From address** and **From name** used when an agency has not set its own.
4. Under **🔐 Microsoft Graph credentials**, fill in **Directory (tenant) ID**, **Application (client) ID** and the **Graph sender mailbox**.
5. Paste the **Client secret** (leave it blank to keep the one saved) and set **Secret expires on** to the date shown in Azure, so you are warned before it lapses.
6. Press **Save settings**. **✓ Saved.** appears beside the button.

> ⚠️ The host blocks ordinary SMTP, so email goes out through Microsoft Graph over HTTPS. When the secret expires, email falls back to sendmail and deliverability drops. Renew the secret in Azure before the date you entered.

## Testing delivery

1. Go to **📤 Send a test** at the bottom.
2. Type an address you can check and press **Send test →**.
3. Read the result line: **✓ Sent via** names the mailer that was used, and **Graph credentials** says whether the Graph login worked. The **Last test** line keeps the outcome for later.

## Microsoft 365 sign-in for mailboxes

The card **🟦 Microsoft 365 sign-in (mailboxes and "Sign in with Microsoft")** is the app people sign in through when they connect a mailbox in **Email** or **Sales email**, and for **Sign in with Microsoft** on the login page.

1. In Microsoft Entra, open **App registrations** and your app. It needs the delegated permissions Mail.ReadWrite, Mail.ReadWrite.Shared, offline_access and User.Read.
2. Copy the **Application (client) ID** from the app's Overview page.
3. Create a secret under Certificates & secrets and paste its Value into **Client secret value**.
4. Press **Copy** beside **Redirect URI** and add it under the app's Authentication → Web.
5. Press **Save Microsoft settings**. KiddieTrac saves and checks with Microsoft; the result shows beside the button. **Check with Microsoft** re-runs the check later.

## Gmail sign-in for RSVP senders

The card **📧 Gmail sign-in for RSVP senders** holds the Google client that lets an agency connect a Gmail account under **Event RSVPs → Sender accounts**.

1. In Google Cloud Console, open Google Auth Platform → Clients and create a web client.
2. Press **Copy** beside **Authorized redirect URI (paste this into the Google client)** and paste it into the client.
3. Enter the **Client ID** and **Client secret** here.
4. Press **Save Gmail settings**, then **Check with Google** whenever you want to confirm it still works.

## Who can do what

Only platform admins open this screen. Agency admins set their own sender name and address in **Settings → Email settings**; centre directors and other roles have no mail settings to change.

## FAQs and when something goes wrong

??? The Microsoft card says "Using the server file (.env)". | The app is still read from the server file, which cannot be checked from here. Enter the client ID and secret and press **Save Microsoft settings** to manage it from this screen.
??? What happens if I press Remove? | You are asked first. For Microsoft, sign-in goes back to the server file and mailboxes already connected keep working until their sign-in needs renewing. For Gmail, agencies cannot connect a Gmail sender, and Gmail senders already connected stop sending.
??? The test says "✗ Send failed". | Read the red reason after it. Most often the client secret has expired or the sender mailbox is wrong. Fix it, press **Save settings** and send another test.
??? "Enter an address to test." | Type the address in the box before pressing **Send test →**.
??? Do agencies see these secrets? | No. Secrets are write-only: once saved, the screen only says one is stored.

## Related screens

[[open: #email-settings | Email settings]] [[open: #email | Email client]]

See also: [Email settings](email-settings), [Email client](email-client).
