---
title: QuickBooks Online sync
category: Billing
order: 78
roles: agency_admin, platform_admin
screens: quickbooks
---
# QuickBooks Online sync

**Settings → QuickBooks (Intuit)** sends your KiddieTrac invoices, voids, payments and refunds to your QuickBooks Online company. Families become customers, invoices keep their KiddieTrac numbers, and syncing again updates them instead of making copies. It goes one way: KiddieTrac → QuickBooks. Nothing here moves money; it is bookkeeping only.

[[open: #quickbooks | Open QuickBooks]]

The screen has three numbered cards, the same as Xero and Sage. Each one turns into a ✓ when it is done and unlocks the next.

## Step 1: Setting up your Intuit app

1. In [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), create an app with the **Accounting** scope.
2. On the KiddieTrac card **Your Intuit app**, press **Copy** beside the **Redirect URI**.
3. In your Intuit app, under **Keys & credentials**, paste the redirect URI.
4. Copy the app's **Client ID** and **Client secret** into KiddieTrac.
5. Choose the **Environment**: *Production* for your real company, or *Sandbox* to try it with a sandbox company and the Development keys.
6. Press **Save app details**. A toast says **QuickBooks app details saved**.

If your agency has no app of its own saved, the screen says "No app of your own saved — the KiddieTrac platform app is being used." The secret is stored privately; leave its box blank later to keep the saved one.

## Step 2: Connecting your company

1. On the **Connect your company** card, press **Connect to QuickBooks →**. QuickBooks opens in a new window.
2. Sign in to Intuit and choose your company.
3. Press **Connect** in the Intuit window.
4. The window closes and the card shows **Connected to** your company's name.

## Step 3: Choosing products, accounts and sync options

The lists are read live from your QuickBooks company ("Reading your products and accounts from QuickBooks…").

1. Choose the **Product/service for tuition *** (required). Invoice lines use this item, so they land in its income account.
2. Choose the **Product/service for subsidies** (subsidy such as CWELCC goes in as a negative line), or leave **Same as tuition**.
3. Choose where to **Deposit payments to** (default: **Undeposited Funds (default)**).
4. Choose the **Tax code for tax-free lines** (Canadian companies need one, usually *Exempt*) and, only if your invoices charge tax, the **Tax code for taxed lines**.
5. Set **Only invoices issued on or after** to your QuickBooks start date, so older invoices are not duplicated.
6. Tick **Record payments and refunds against the invoices in QuickBooks** and, if you want, **Sync automatically every 30 minutes** (off by default).
7. Press **Save products & sync settings**. A toast says **QuickBooks settings saved**.

## Syncing and reading the sync log

1. Press **Sync now**. The button reads **Syncing…** while it sends.
2. Read the result, then check **📜 Sync log**. It lists every invoice and payment with what happened and why.
3. Under the button, **Last sync** shows when it last ran and whether automatic sync is on.

What gets sent:

- **Families → customers**, found again on every sync rather than duplicated.
- **Issued invoices** (never KiddieTrac drafts) with the same invoice number; subsidy and discount are negative lines so the total matches.
- **Changes** update the same QuickBooks invoice; one already fully paid in QuickBooks is left alone.
- **Voids** are voided in QuickBooks, unless payments are applied to them there.
- **Payments** are received against the invoice; a refunded one is deleted.

## Changing app or disconnecting

1. To use a different Intuit app or environment, press **Change app**. QuickBooks is disconnected, because the old connection belongs to the old app; connect again in step 2.
2. To stop syncing, press **Disconnect** and confirm. Everything already in QuickBooks stays there, and reconnecting the same company picks up where it left off.
3. To also remove your app's credentials, press **Clear & disconnect**.

> ℹ️ Before October 2026 the QuickBooks screen could connect but never actually sent an invoice. Nothing was ever pushed, so there is nothing to clean up. Start with step 1.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Set up, connect, sync and disconnect QuickBooks for their agency. |
| Platform admin | The same, for the agency they are viewing. |
| Centre director | Does not see this screen. |

## FAQs and when something goes wrong

??? Steps 2 and 3 are greyed out. | Each card unlocks the next. Save your app details first, then connect; step 3 says "Connect first." until you do.
??? "Could not read your products and accounts from QuickBooks." | The connection has lapsed or was removed in QuickBooks. Press Reconnect and sign in again.
??? An invoice is waiting and not sent. | Open the Sync log for the reason. A common one is an invoice that charges tax while no Tax code for taxed lines is chosen.
??? "Choose the product/service for tuition." | Step 3 needs a tuition item before anything can be saved or synced.
??? Old invoices appeared twice. | Set Only invoices issued on or after to your QuickBooks start date before the first sync.
??? Are families told anything? | No. Nothing is sent to families from this screen.

## Related screens

[[open: #xero | Xero]] [[open: #sage | Sage]] [[open: #external-billing | Accounting]]

See also: [Xero](xero) · [Sage](sage)
