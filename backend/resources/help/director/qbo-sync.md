---
title: QuickBooks Online sync
category: Billing
order: 78
roles: agency_admin, platform_admin
---
# QuickBooks Online sync

**Settings → QuickBooks.** Send your KiddieTrac invoices, voids, payments and refunds to your
QuickBooks Online company. Families become customers, invoices keep their KiddieTrac numbers,
and syncing again updates them instead of making copies. It goes one way: KiddieTrac → QuickBooks.

The screen has three steps, the same as Xero and Sage.

## 1. Your Intuit app

1. In [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), create an app with the **Accounting** scope.
2. Under **Keys & credentials**, add the **Redirect URI** shown in KiddieTrac (use **Copy**).
3. Paste the **Client ID** and **Client secret** into KiddieTrac.
4. **Environment**: *Production* for your real company, or *Sandbox* to try it out with a sandbox company and the Development keys.
5. **Save app details.**

If your agency has no app of its own saved, the KiddieTrac platform app is used (the screen says so).

## 2. Connect your company

Press **Connect to QuickBooks →**, sign in to Intuit, choose your company and press **Connect**.
The window closes and the screen shows **Connected to …**.

## 3. Products, accounts & sync

The lists are read live from your QuickBooks company.

| Setting | What it does |
|---|---|
| **Product/service for tuition** (required) | Invoice lines use this item, so they land in its income account. |
| **Product/service for subsidies** | Subsidy goes in as a negative line. Blank uses the tuition item. |
| **Deposit payments to** | Where payments are received. Default: Undeposited Funds. |
| **Tax code for tax-free lines** | Canadian companies need one (usually *Exempt*). |
| **Tax code for taxed lines** | Only for invoices that charge tax; those invoices wait until one is chosen. |
| **Only invoices issued on or after** | Your QuickBooks start date, so older invoices are not duplicated. |
| **Record payments and refunds** | Payments are received against the invoice; a refunded one is deleted. |
| **Sync automatically every 30 minutes** | Off by default. |

## What gets sent

- **Families → customers**, found again on every sync rather than duplicated.
- **Issued invoices** (never KiddieTrac drafts) with the same invoice number; subsidy and discount are negative lines so the total matches.
- **Changes** update the same QuickBooks invoice; one already fully paid in QuickBooks is left alone.
- **Voids** are voided in QuickBooks, unless payments are applied to them there.
- **Payments and refunds**, as above.

## Sync now and the sync log

**Sync now** sends everything waiting and reports the result. The **Sync log** lists every
invoice and payment with what happened and why.

## Disconnecting

**Disconnect** stops syncing; what is in QuickBooks stays. **Clear & disconnect** also removes
your app's credentials. Changing the app or the environment disconnects, because the old
connection belongs to the old app.

> **Before October 2026** the QuickBooks screen could connect but never actually sent an invoice.
> Nothing was ever pushed, so there is nothing to clean up. Start with step 1.

See also: [Xero](xero) · [Sage](sage)
