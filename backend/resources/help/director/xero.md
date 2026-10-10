---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
---
# Xero

**Settings → Xero.** Send your KiddieTrac invoices, voids, payments and refunds to your Xero
organisation. Families become Xero contacts, invoices keep their KiddieTrac numbers, and
syncing again updates them instead of making copies. It goes one way: KiddieTrac → Xero.

The screen has three steps. Each one unlocks the next.

## 1. Your Xero app

KiddieTrac connects through **your own** Xero app, so the connection belongs to your agency.

1. Go to [developer.xero.com → My Apps](https://developer.xero.com/app/manage) and create a **Web app**.
2. Copy the **Redirect URI** from the KiddieTrac screen (there is a Copy button) and paste it into the app.
3. Copy the app's **Client ID**, generate a **Client secret**, and paste both into KiddieTrac.
4. **Xero app type**: choose *Created March 2026 or later* for a new app. Xero gives newer apps
   different permissions; if Xero ever says **"invalid scope"**, switch this setting and connect again.
5. **Save app details.**

The secret is stored encrypted. Leave the secret box blank later to keep the saved one.

## 2. Connect your organisation

Press **Connect to Xero →**. Xero opens in a new window: sign in, choose your organisation and
press **Allow access**. The window closes itself and the screen shows **Connected to …**.

If you gave access to more than one organisation, KiddieTrac asks which one to send invoices to.
You can switch later with **Switch organisation**.

## 3. Accounts & sync

The lists here are read live from your Xero organisation.

| Setting | What it does |
|---|---|
| **Sales account for tuition** (required) | Where invoice lines are booked. |
| **Account for subsidies** | Subsidy (e.g. CWELCC) goes in as a negative line. Blank uses the tuition account. |
| **Bank account for payments** | Card and bank payments taken in KiddieTrac are recorded here. Blank: payments are not sent yet. |
| **Tax rate** | Only for invoices that charge tax. Child care is usually exempt; an invoice that charges tax waits until a rate is chosen. |
| **Invoices arrive in Xero as** | *Approved* (ready for payments) or *Draft* (you approve them in Xero). Payments can only be recorded on approved invoices. |
| **Only invoices issued on or after** | Set this to your Xero start date so older invoices are not sent twice. |
| **Record payments and refunds** | Sends payments against the invoices, and removes a payment that was refunded. |
| **Sync automatically every 30 minutes** | Off by default. Off means nothing goes to Xero until you press **Sync now**. |

## What gets sent

- **Families → contacts.** Each family becomes one Xero contact, tagged with contact number `KT-` and the family number, so it is found again rather than duplicated.
- **Issued invoices** (sent, partly paid, paid, overdue) → sales invoices with the same number. KiddieTrac drafts are never sent. Subsidy and discount are separate negative lines, so the total matches.
- **Changes** to an invoice update the same Xero invoice. An invoice already paid in Xero is left alone, and the sync log says so.
- **Voided invoices** are voided in Xero (or deleted, if still a Xero draft). One with payments in Xero is not voided; remove the payments there first.
- **Payments** are recorded against the invoice in your chosen bank account; **refunds** remove that payment.

Nothing here moves money. It is bookkeeping only.

## Sync now and the sync log

**Sync now** sends everything waiting and reports what was created, updated, voided, skipped or
failed. A large first sync may say *more waiting*; press **Sync now** again.

The **Sync log** lists every result with the reason, e.g. *"Choose the Xero sales account for
tuition first"* or *"Changed in KiddieTrac, but invoice INV-0042 is paid in Xero, so it was left as it is."*

## Disconnecting

**Disconnect** stops syncing; everything already in Xero stays there, and reconnecting the same
organisation picks up where it left off. **Clear & disconnect** also removes your app's ID and secret.

## Common questions

**"Xero said invalid scope."** Switch **Xero app type** in step 1 and connect again.

**"Xero ended the connection."** Xero revokes a connection that is unused for 60 days, or removed
in Xero. Press **Connect to Xero →** again.

**"A family appears twice in Xero."** A Xero contact name must be unique. If another contact
already had the family's name, KiddieTrac's one is named with `(KT-…)` after it.

See also: [QuickBooks Online sync](qbo-sync) · [Sage](sage)
