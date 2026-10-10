---
title: Sage (Sage Accounting and Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
---
# Sage

**Settings → Sage** covers both Sage products. Pick the tab for the one you use:

- **☁️ Sage Accounting (cloud)** syncs live, like Xero and QuickBooks.
- **🖥️ Sage 50 (desktop)** has no online connection, so KiddieTrac makes the files Sage 50 imports.

## Sage Accounting (cloud)

### 1. Your Sage app

1. In [Sage Developer Self Service](https://developerselfservice.sageone.com/), create an app.
2. Add the **Callback URL** from the KiddieTrac screen (use **Copy**).
3. Paste the app's **Client ID** and **Client secret** into KiddieTrac and **Save app details**.

### 2. Connect your business

Press **Connect to Sage →**, sign in and allow access. If you have several businesses in Sage,
choose the one to send invoices to (you can switch later).

### 3. Accounts & sync

| Setting | What it does |
|---|---|
| **Sales ledger account for tuition** (required) | Where invoice lines are booked. |
| **Ledger account for subsidies** | Subsidy goes in as a negative line. Blank uses tuition. |
| **Bank account for payments** | Payments taken in KiddieTrac become customer receipts here. |
| **Tax rate for tax-free lines / taxed lines** | Child care is usually exempt. An invoice that charges tax waits until a taxed rate is chosen. |
| **Only invoices issued on or after** | Your Sage start date, so older invoices are not duplicated. |
| **Record payments and refunds** / **Sync automatically every 30 minutes** | As for Xero. Auto-sync is off by default. |

What is sent: each family becomes a Sage customer (reference `KT-` + family number); each issued
invoice becomes a sales invoice whose **reference** is the KiddieTrac invoice number (Sage numbers
its own invoices); voids are voided in Sage; payments are allocated to the invoice and refunds
remove them. **Sync now** and the **Sync log** work exactly as on the Xero screen.

## Sage 50 (desktop)

Sage 50 imports two kinds of file: **records** (customers) and **general journal entries**.
KiddieTrac makes both.

### 1. Your account numbers — and one account to create first

Sage 50 will **not** import journal lines to its own linked **Accounts Receivable** account once
your company is set up. So first create an asset account for family balances in Sage 50
(**Setup → Chart of Accounts**), for example **1210 KiddieTrac family receivables**.

Then enter your account numbers (4 to 8 digits) in KiddieTrac:

| Account | Example | Needed? |
|---|---|---|
| **Family receivable** | 1210 | Required. The account you just created, not your linked A/R. |
| **Tuition revenue** | 4100 | Required. |
| **Bank account for payments** | 1060 | To include payments. Must be different from the receivable. |
| **Subsidy account** | 1220 | Optional. Blank: tuition is booked net of subsidy. |
| **Discount account** | 4190 | Optional. Blank: tuition is booked net of discounts. |
| **Tax payable** | 2310 | Only if your invoices charge tax. |

### 2. Customers file

**⬇️ Download customers (CSV)**: your current families, in the column order Sage 50 expects.
In Sage 50: **File → Import/Export → Import Records → Customers**, choose the file, check the
columns line up, and finish. Do this once, then again when new families join.

### 3. Journal file

1. Optionally set **From / To** dates. The first time, set **From** to your Sage 50 start date.
2. Press **Preview** to see what the file will hold.
3. Press **⬇️ Download journal file**.
4. Back up your Sage 50 company, then **File → Import/Export → Import Transactions → Import general journal entries**, and choose the `.TXT` file.

Each issued invoice is one balanced entry: the family receivable is debited with what the family
owes, subsidy and discount (if mapped) are debited, tuition revenue and tax are credited. Each
payment debits the bank and credits the family receivable.

**Each file holds only what is new since the last one.** If an invoice's amounts change after it
was exported, the next file carries an **adjusting entry** for the difference; an exported invoice
that is voided, or an exported payment that is refunded, gets a **reversing entry**.

Every file you make is listed under **Files made**, and can be downloaded again. If Sage 50
refuses a file, nothing is posted: fix the account numbers and import the same file again.

See also: [Xero](xero) · [QuickBooks Online sync](qbo-sync)
