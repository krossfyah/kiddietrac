---
title: Sign-in methods (Google, Microsoft and Facebook)
category: Settings
order: 40
roles: agency_admin, platform_admin
screens: social-settings
---

# Sign-in methods (Google, Microsoft and Facebook)

**🔐 Sign-in methods** lets staff and families sign in with an existing Google, Microsoft or Facebook account instead of typing a KiddieTrac password. A platform administrator sets each provider up once, for the whole platform, from **Platform › 🔏 Sign-in methods**. A provider's button stays hidden on the sign-in page until its details are saved here.

[[open: #social-settings | Open Sign-in methods]]

## How it works for the people signing in

- The **Google**, **Microsoft** or **Facebook** button appears on the sign-in page only for providers that show **● Enabled** here.
- Social sign-in only **links to an existing invited account** by its verified email address. It never creates a new account. Someone whose Google or Microsoft email does not match an invited person is turned away.
- Email and password always keeps working, so nobody is forced to use a social account.

## Setting up a provider

Each provider has its own card. Do this once per provider.

1. Open **Platform › 🔏 Sign-in methods**.
2. In the blue **Redirect / callback URL** box, press **Copy**. The button shows **Copied ✓**. Swap `{provider}` in that address for `google`, `microsoft` or `facebook` when you paste it.
3. On the provider's card, open **How to create the Google app** (or the Microsoft or Facebook one) and follow its steps in that provider's developer console. Paste the redirect address where the steps say.
4. Copy the app's ID and secret from the developer console.
5. Paste them into **Client ID** and **Client Secret** on the card. For Microsoft, leave **Tenant** as `common` unless you want to allow only one organisation.
6. Press **Save Google** (or **Save Microsoft**, **Save Facebook**). A **Saved** toast appears and the card shows **● Enabled**.
7. Open the sign-in page in a private window and check the new button is there.

[[show: #social-settings @ How to create the Google app | Show me the setup steps]]

> ℹ️ For Microsoft, copy the secret's **Value**, not its **Secret ID**. For Facebook, the app must pass Meta's **App Review** and be switched to **Live** before people other than the app's own testers can use it.

## Changing or turning off a provider

1. To replace a secret, type the new one into **Client Secret** and press **Save …**. Leave the field blank to keep the saved secret: it shows **•••••••• (saved — leave blank to keep)**.
2. To turn a provider off, press **Disable** on its card.
3. Confirm **Disable google sign-in? Its credentials will be cleared.** The button disappears from the sign-in page and the card shows **Not set up**.

> ⚠️ Disabling does not lock anyone out: people who used that button sign in with their email and password instead (or reset it with **Forgot password**).

## Security notes

- Secrets are **write-only**: once saved they are never shown again, even to you.
- Only these sign-in keys are changed on the server; no other setting is touched.

## Who can do what

- **Platform administrators**: set up, change and disable every provider.
- **Agency admins and everyone else**: cannot change these settings; an agency admin who opens the screen sees **This page is available to platform administrators only.** They simply see the buttons on the sign-in page once a provider is enabled.

## FAQs and when something goes wrong

??? The Google button does not show on the sign-in page. | The card must show **● Enabled**. Refresh the sign-in page; a cached page can still show the old buttons for a moment.
??? Someone gets turned away after choosing Google. | Their Google email does not match the email on their KiddieTrac account. Invite or update them with that address, or ask them to sign in with email and password.
??? The provider says the redirect address does not match. | The address registered in the developer console must match exactly, with `google`, `microsoft` or `facebook` in place of `{provider}`.
??? "Save failed" appeared. | The ID or secret was rejected or the connection dropped. Check you pasted the whole value and try again.
??? Can an agency turn on its own Google sign-in? | No. Sign-in methods are set for the whole platform by a platform administrator.

## Related screens

[[open: #security-alerts | Security alerts]] · [[open: #audit-logs | Audit log]]

See also: [[security-alerts]] · [[passkeys]] · [[signing-in-and-unlocking]]
