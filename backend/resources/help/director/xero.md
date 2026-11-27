---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
screens: xero
---
# Xero

**Settings → Xero** sends your KiddieTrac invoices, voids, payments and refunds to your Xero organisation. Families become Xero contacts, invoices keep their KiddieTrac numbers, and syncing again updates them instead of making copies. It goes one way: KiddieTrac → Xero. Nothing here moves money; it is bookkeeping only.

[[open: #xero | Open Xero]]

The screen has three numbered cards. Each one turns into a ✓ when it is done and unlocks the next.

## Step 1: Setting up your Xero app

KiddieTrac connects through **your own** Xero app, so the connection belongs to your agency.

1. Go to [developer.xero.com → My Apps](https://developer.xero.com/app/manage) and create a **Web app**.
2. On the KiddieTrac card **Your Xero app**, press **Copy** beside the **Redirect URI** and paste it into the Xero app.
3. Copy the app's **Client ID** into KiddieTrac.
4. Generate a **Client secret** in Xero and paste it into KiddieTrac.
5. Set **Xero app type**: choose **Created March 2026 or later** for a new app, or **Created before March 2026** for an older one.
6. Press **Save app details**. A toast says **Xero app details saved**.

The secret is stored encrypted. Leave the secret box blank later to keep the saved one.

## Step 2: Connecting your organisation

1. On the **Connect your organisation** card, press **Connect to Xero →**. Xero opens in a new window.
2. Sign in to Xero and choose your organisation.
3. Press **Allow access**.
4. The window closes and the card shows **Connected to** your organisation's name.
5. If you gave access to more than one organisation, choose the one to send invoices to and press **Use this organisation**.

To change it later, press **Switch organisation**, choose another and press **Switch**.

## Step 3: Choosing accounts and sync options

The lists are read live from your Xero organisation ("Reading accounts from Xero…").

1. Choose the **Sales account for tuition *** (required): where invoice lines are booked.
2. Choose the **Account for subsidies** (subsidy such as CWELCC goes in as a negative line), or leave **Same as tuition**.
3. Choose the **Bank account for payments**: card and bank payments taken in KiddieTrac are recorded here. Leave it blank and payments are not sent.
4. Choose the **Tax rate (only for invoices that charge tax)**. Child care is usually exempt; an invoice that charges tax waits until a rate is chosen.
5. Choose how **Invoices arrive in Xero as**: **Approved (ready for payments)** or **Draft (you approve them in Xero)**. Payments can only be recorded on approved invoices.
6. Set **Only invoices issued on or after** to your Xero start date, so older invoices are not sent twice.
7. Tick **Record payments and refunds against the invoices in Xero** and, if you want, **Sync automatically every 30 minutes**. It is off by default; off means nothing goes to Xero until you press **Sync now**.
8. Press **Save accounts & sync settings**. A toast says **Xero settings saved**.

## Syncing and reading the sync log

1. Press **Sync now**. The button reads **Syncing…** while it sends.
2. Read the result: what was created, updated, voided, skipped or failed. A large first sync may say *more waiting*; press **Sync now** again.
3. Check the **Sync log**. It lists every result with the reason, for example "Choose the Xero sales account for tuition first" or "Changed in KiddieTrac, but invoice INV-0042 is paid in Xero, so it was left as it is."

What gets sent:

- **Families → contacts.** Each family becomes one Xero contact, tagged with contact number `KT-` and the family number, so it is found again rather than duplicated.
- **Issued invoices** (sent, partly paid, paid, overdue) → sales invoices with the same number. KiddieTrac drafts are never sent. Subsidy and discount are separate negative lines, so the total matches.
- **Changes** to an invoice update the same Xero invoice. An invoice already paid in Xero is left alone, and the sync log says so.
- **Voided invoices** are voided in Xero (or deleted, if still a Xero draft). One with payments in Xero is not voided; remove the payments there first.
- **Payments** are recorded against the invoice in your chosen bank account; **refunds** remove that payment.

## Changing app or disconnecting

1. To use a different Xero app, press **Change app**. Xero is disconnected, because a different app cannot use the current connection; connect again in step 2.
2. To stop syncing, press **Disconnect** and confirm. Everything already in Xero stays there, and reconnecting the same organisation picks up where it left off.
3. To also remove your app's ID and secret, press **Clear & disconnect**.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Set up, connect, sync and disconnect Xero for their agency. |
| Platform admin | The same, for the agency they are viewing. |
| Centre director | Does not see this screen. |

## FAQs and when something goes wrong

??? Xero said "invalid scope". | Xero gives newer apps different permissions. Switch Xero app type in step 1, save, and connect again.
??? Xero ended the connection. | Xero revokes a connection that is unused for 60 days, or removed in Xero. Press Connect to Xero → (or Reconnect) again.
??? A family appears twice in Xero. | A Xero contact name must be unique. If another contact already had the family's name, KiddieTrac's one is named with (KT-…) after it.
??? Payments are not reaching Xero. | Choose a Bank account for payments, tick Record payments and refunds, and send invoices as Approved: Xero cannot record a payment on a draft.
??? Steps 2 and 3 are greyed out. | Each card unlocks the next. Save your app details first, then connect; step 3 says "Connect first." until you do.
??? Are families told anything? | No. Nothing is sent to families from this screen.

## Related screens

[[open: #quickbooks | QuickBooks]] [[open: #sage | Sage]] [[open: #external-billing | Accounting]]

See also: [QuickBooks Online sync](qbo-sync) · [Sage](sage)
