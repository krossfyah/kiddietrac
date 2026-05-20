---
title: QuickBooks Online sync
category: Billing
order: 78
---
# QuickBooks Online sync

Push paid + unpaid invoices from KiddieTrac into your QBO file. One-way today (KT → QBO).

## Prerequisites

Platform-side env vars must be set: `QBO_CLIENT_ID`, `QBO_CLIENT_SECRET`, `QBO_REDIRECT_URI`, `QBO_ENV`. Without these the screen will say "QBO not configured".

## Connecting

1. Sidebar → **Administration → QuickBooks**.
2. Click **Connect to QBO**.
3. A popup opens to Intuit's login. Authorize KiddieTrac for the company you want to sync.
4. After authorization you're returned to KiddieTrac with **Connected ✓**.

Refresh tokens are stored on the agency row — re-authentication is automatic.

## Syncing an invoice

1. Open an invoice in KiddieTrac.
2. Click **⤓ Push to QBO**.
3. We upsert the customer if needed, then create the invoice in QBO with the matching invoice number and line items.

## Disconnecting

Same screen → **Disconnect**. Stored tokens are deleted; QBO won't be touched until you re-authorize.

## Limits today

- One-way only (KT → QBO). Payments captured in QBO don't flow back.
- No category mapping yet — every line books to a generic "Services" item.
- Bulk push is not available — sync one invoice at a time. v22p52 adds bulk sync.
