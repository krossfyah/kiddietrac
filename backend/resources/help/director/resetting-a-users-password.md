---
title: Resetting a user's password
category: Troubleshooting
order: 2
roles: agency_admin, centre_director
---
# Resetting a user's password

You can do this yourself. Support is not involved.

## First, let them do it

**"Forgot password?"** on the sign-in page sends a reset link to the address on their
account. That is the right route for almost everybody, and it needs nothing from you.

**If several accounts share one email address**, the form also takes a **username**. With
one, the reset goes to exactly that account. Without one, a link is still sent — one
labelled email per live account on that address — because somebody locked out of their
password very often does not know their username either, and demanding one rebuilds the
wall this is here to remove.

Nothing is disclosed either way. An address that does not exist, a username that matches
nothing, and a username belonging to a different person's address all get the same reply.

## Resetting it for them

**Administration → User management**, find them, **🔑 Reset password**. What happens
depends on the account:

**An active account that has signed in before gets a reset link.** Their current password
keeps working until they choose a new one, so pressing this button on a working account
cannot lock anybody out. This matters more than it sounds: the old behaviour minted a new
temporary password every time, which meant pressing Reset on somebody who had just chosen
their own password silently replaced it — and from their side, the reset they completed
ten minutes earlier had simply stopped working.

**An account that has never signed in gets a temporary password**, because there is no
password to preserve and often no confirmed inbox either. The screen shows it once, with
a **Copy details** button covering the portal address, who to sign in as, and the
password.

## A mailed password is a key, not a credential

A password that has travelled through an inbox in plain text is a **one-time key**.
KiddieTrac treats it as one: the account can do nothing but sign in and choose a real
password. Every other screen is refused until they do.

So "tell them to change it immediately" is no longer advice you have to give — it is
enforced. Pass on the temporary password, and they will be asked to replace it the moment
they arrive.

## When somebody says "I can't log in"

Work down this list before resetting anything:

1. Are they at **https://app.kiddietrac.com**?
2. Are they using the address the invite went to?
3. **Does that address have more than one account?** Check User management. If it does,
   they need to give the **username** on the sign-in form — the form asks for it when it
   needs it.
4. Has the account been de-boarded or switched off? A de-boarded account is refused at the
   door, and a reset for it would only produce a password nobody can use. Look at the
   **De-boarded / deactivated** tab.
5. Have they hard-refreshed (Ctrl+Shift+R)?

## If the email never arrives

Every attempt is recorded, successful or not — including a refusal and why.

- **Administration → Audit log** shows the reset attempt.
- **Settings → Email settings** has the agency-wide notifications switch. With it off,
  nothing sends.
- Check the email log for a suppressed or bounced message before assuming it was sent.
- Their spam folder, and whether their provider blocks `kiddietrac.com`.

If the email cannot get through at all, reset with a temporary password and read it out
to them — the sign-in gate above means it is safe to hand over.

See also: [Inviting parents and staff](inviting-parents-and-staff),
[Two-factor authentication](mfa-two-factor), [Audit log](audit-log).
