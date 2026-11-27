---
title: Sage (Sage Accounting and Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
screens: sage
---
# Sage (Sage Accounting and Sage 50)

**Settings → Sage** sends your KiddieTrac invoices and payments to Sage. It covers both Sage products; pick the tab for the one you use:

- **☁️ Sage Accounting (cloud)** syncs live, like Xero and QuickBooks.
- **🖥️ Sage 50 (desktop)** has no online connection, so KiddieTrac makes the files Sage 50 imports.

[[open: #sage | Open Sage]]

## Sage Accounting: setting up your Sage app

1. Open the **☁️ Sage Accounting (cloud)** tab.
2. In [Sage Developer Self Service](https://developerselfservice.sageone.com/), create an app.
3. On the KiddieTrac card **Your Sage app**, press **Copy** beside the **Callback URL** and add it to the Sage app.
4. Paste the app's **Client ID** and **Client secret** into KiddieTrac.
5. Press **Save app details**. A toast says **Sage app details saved**.

## Sage Accounting: connecting your business

1. On the **Connect your business** card, press **Connect to Sage →**. Sage opens in a new window.
2. Sign in to Sage and allow access.
3. If you have several businesses in Sage, choose the one to send invoices to and press **Use this business**. You can change it later with **Switch business**.

## Sage Accounting: accounts and sync

The lists are read live from Sage ("Reading your accounts from Sage…").

1. Choose the **Sales ledger account for tuition *** (required): where invoice lines are booked.
2. Choose the **Ledger account for subsidies** (subsidy such as CWELCC goes in as a negative line), or leave **Same as tuition**.
3. Choose the **Bank account for payments**: payments taken in KiddieTrac become customer receipts here.
4. Choose the **Tax rate for tax-free lines** (child care is usually exempt) and, only if your invoices charge tax, the **Tax rate for taxed lines**. An invoice that charges tax waits until a taxed rate is chosen.
5. Set **Only invoices issued on or after** to your Sage start date, so older invoices are not duplicated.
6. Tick **Record payments and refunds against the invoices in Sage** and, if you want, **Sync automatically every 30 minutes** (off by default).
7. Press **Save accounts & sync settings**. A toast says **Sage settings saved**.
8. Press **Sync now**, then check the **Sync log** for what happened to each invoice and payment, and why.

What is sent: each family becomes a Sage customer (reference `KT-` + family number); each issued invoice becomes a sales invoice whose **reference** is the KiddieTrac invoice number (Sage numbers its own invoices); voids are voided in Sage; payments are allocated to the invoice and refunds remove them.

**Disconnect** stops syncing; everything already in Sage stays, and reconnecting picks up where it left off. **Clear & disconnect** also removes your app's ID and secret. **Change app** disconnects, because a different app cannot use the current connection.

## Sage 50: setting your account numbers

Sage 50 will **not** import journal lines to its own linked **Accounts Receivable** account once your company is set up, so you need one extra account first.

1. In Sage 50, create an asset account for family balances (**Setup → Chart of Accounts**), for example **1210 KiddieTrac family receivables**.
2. In KiddieTrac, open the **🖥️ Sage 50 (desktop)** tab.
3. Under **Your Sage 50 account numbers**, enter your account numbers (4 to 8 digits):

| Account | Example | Needed? |
|---|---|---|
| **Family receivable account** | 1210 | Required. The account you just created, not your linked A/R. |
| **Tuition revenue account** | 4100 | Required. |
| **Bank account for payments** | 1060 | To include payments. Must be different from the receivable. |
| **Subsidy account** | 1220 | Optional. Blank: tuition is booked net of subsidy. |
| **Discount account** | 4190 | Optional. Blank: tuition is booked net of discounts. |
| **Tax payable account** | 2310 | Only if your invoices charge tax. |

4. Press **Save account numbers**. You see **Sage 50 account numbers saved**.

## Sage 50: importing your customers

Do this once, then again when new families join.

1. Under **Customers file (once, then for new families)**, press **⬇️ Download customers (CSV)**. Your current families download in the column order Sage 50 expects.
2. In Sage 50, choose **File → Import/Export → Import Records → Customers**.
3. Choose the file, check the columns line up, and finish.

## Sage 50: importing invoices and payments

1. Under **Journal file — invoices and payments**, optionally set **From (optional)** and **To (optional)**. The first time, set **From** to your Sage 50 start date. Leave both blank to include everything not yet exported.
2. Press **Preview** to see what the file will hold, for example "12 invoices, 9 payments — 21 journal entries". Any ⚠️ warning tells you what to fix first.
3. Press **⬇️ Download journal file**.
4. Back up your Sage 50 company.
5. In Sage 50, choose **File → Import/Export → Import Transactions → Import general journal entries**, and choose the `.TXT` file.

Each issued invoice is one balanced entry: the family receivable is debited with what the family owes, subsidy and discount (if mapped) are debited, tuition revenue and tax are credited. Each payment debits the bank and credits the family receivable.

**Each file holds only what is new since the last one.** If an invoice's amounts change after it was exported, the next file carries an **adjusting entry** for the difference; an exported invoice that is voided, or an exported payment that is refunded, gets a **reversing entry**. Every file you make is listed under **📁 Files made** and can be downloaded again.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Set up and run both Sage tabs for their agency. |
| Platform admin | The same, for the agency they are viewing. |
| Centre director | Does not see this screen. |

## FAQs and when something goes wrong

??? Sage 50 refused my journal file. | Nothing is posted when Sage 50 refuses a file. Fix the account numbers, then download the same file again from Files made and import it.
??? Why can't I use my Accounts Receivable account in Sage 50? | Sage 50 does not accept imported journal lines to its linked A/R account once the company is set up. Use the separate family receivable account.
??? Preview says "Nothing new to export." | Everything in that date range has already been exported. Each file holds only what is new since the last one; download an earlier file again from Files made if you need it.
??? Steps 2 and 3 on the cloud tab are greyed out. | Each card unlocks the next. Save your app details first, then connect; step 3 says "Connect first." until you do.
??? An invoice is waiting and not sent to Sage Accounting. | Open the Sync log for the reason, for example an invoice that charges tax while no Tax rate for taxed lines is chosen.
??? Are families told anything? | No. Nothing is sent to families from this screen.

## Related screens

[[open: #xero | Xero]] [[open: #quickbooks | QuickBooks]] [[open: #external-billing | Accounting]]

See also: [Xero](xero) · [QuickBooks Online sync](qbo-sync)
