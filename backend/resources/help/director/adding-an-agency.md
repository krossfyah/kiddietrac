---
roles: agency_admin, platform_admin
title: Adding a new agency
category: Administration
order: 5
---
# Adding a new agency

Adding a customer agency is a **platform-admin** action. It runs as a short
**4-step wizard** and provisions everything the agency needs in one go: the
agency, a first centre, and the first admin login.

## Where to start

1. Bottom-left, click the **agency switcher** (the pill showing the current
   agency) and choose **All agencies** — or use **Platform → All agencies**.
2. Click **+ Create agency** (top-right). The wizard opens.

## The 4 steps (everything is required)

1. **Plan & logo**
   - **Plan** — choose **Starter ($49)**, **Growth ($149)**, or
     **Enterprise ($349)**. Growth and Enterprise **include white-label** (the
     agency admin sets up their logo/colours on first login).
   - **Agency logo** — upload a PNG/SVG (max 2 MB).
   - **Primary colour**.
2. **Agency details** — agency name, address (line 1, city, province, postal
   code), **country of residence** (sets the agency's currency + compliance),
   **default language**, contact email, and contact phone.
3. **Admin** — the first agency admin's first name, last name, email, and phone.
4. **Review** — check everything, then **Create agency**.

## What happens on create

- The agency is created on a **30-day trial** with a **default centre** (named
  after the agency, using the address — rename or add more under **Centres**).
- The admin is created and **emailed an invite** to set their password. (The
  confirmation screen also shows the invite link in case the email is delayed.)
- If the plan includes white-label, branding is enabled automatically — the
  admin finishes their logo/colours/privacy links on first login under
  **Branding**.

## If the admin didn't get the invite email

Email delivery depends on your domain's DNS (SPF/DKIM), so an invite can land in
spam or not arrive. On the **All agencies** screen:

- Click **✉ Resend** on the agency's row to re-send the invite — a dialog shows
  the **set-password link** you can copy and send directly, so onboarding never
  depends on email delivery.
- Click **📧 Email log** (top of the screen) to see **every email the system
  sent** — who it went to, the subject, and when. (This confirms the send; it
  can't confirm the recipient's inbox accepted it.)

> Who can use this: **Platform admin**.
