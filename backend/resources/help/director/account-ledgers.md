---
title: Account ledgers
category: Billing
order: 40
roles: agency_admin, platform_admin
screens: account-ledgers
---
# Account ledgers

**Finance → Account ledgers** answers four questions about any one person on your agency: what were they billed, what have they paid, what is still owed, and what is coming. Open it when a parent asks about their balance, before you send a statement, or before you issue a refund.

[[open: #account-ledgers | Open Account ledgers]]

![The account ledger: pick a person, and their whole financial history is on one page](https://api.kiddietrac.com/help-img/account-ledger.png)

## Opening one person's ledger

1. In the sidebar, open **Finance** and choose **Account ledgers**.
2. Optional: pick a **Role** to narrow the list to parents, educators, directors and so on. Each role shows how many accounts it has.
3. Optional: type a name or email in the search box and press **Enter**. The search waits for Enter; it does not filter as you type.
4. Choose the person in the **Account** list. Each one is shown as their name with their role in brackets, for example *Maya (Parent)*.
5. The ledger opens: **Account details**, the figures at the top, charts, **Still outstanding**, **Coming up** and the **Account history**.

> ℹ️ The account list deliberately does not show balances. In a list of sixty people that is sixty amounts to read past to find somebody, and the balance is the first thing the ledger shows once it opens.

## Seeing every account at once

1. Press **⊞ All accounts**. Every account is listed with what each owes, and totals for **Billed**, **Collected**, **Outstanding**, **Voided** and **Paid out** across the top.
2. Use the filter to show **Everyone**, only those **Owing money**, or only people **Paid by us** (staff and payees you pay).
3. On a row, use **⋮ > 📄 Open ledger** to open that person's ledger, or **⋮ > ✉️ Email statement** to send their statement.
4. Press **📄 One account** to go back to the single-account view.

## Reading the numbers

The figures always tie together:

> 💡 **Invoiced − (Received − Refunded) = Outstanding**

- **Invoiced**: everything billed, excluding anything voided. A voided invoice carries no balance and shows in its own **Voided** figure.
- **Received**: what the invoices themselves say was settled.
- **Refunded**: money handed back. A refund *un-collects*: the balance rises again by exactly what was returned.
- **Outstanding**: what is still owed today. **Overdue** is the part of it past its due date.
- **Due this month**, **Missed** and **Scheduled in total** appear when the person has instalments or recurring billing coming.

**Money is counted once, from the invoice.** Where a payment record exists it is shown as its own line, with the method, reference and real date, but it is not added again. If the two ever disagree, the invoice is right.

**Overpayment is reported, never netted.** If more was received than was billed, an **Overpaid** figure says so. Whether that is credit the family can use or a payment recorded twice is for you to decide, so it is stated rather than silently absorbed.

### Coming up

Everything still expected on the account: each payment on a payment schedule, and any recurring billing. Each row names the invoice behind the amount, and its **⋮** opens, downloads or emails that invoice. Two cases are called out:

- **No invoice raised yet.** A recurring billing schedule only says "we will raise something on this date". The amount is not decided until it is issued, so there is nothing to open.
- The invoice total differs from the scheduled amount: usually the invoice was adjusted after the schedule was agreed. Worth a look.

A missed payment stays in this table, flagged as missed. It is the most useful thing on the page.

### Still outstanding and the history

**Still outstanding** lists every unpaid invoice, oldest first, with how many days late. Each row's **⋮ > 📄 Invoice document** opens it. **Account history** below lists every line; filter it with **Everything**, **Invoices**, **Payments**, **Refunds**, **Voids** or **Paid out**.

## Generating and emailing a statement

1. Open the person's ledger.
2. Press **📄 Generate statement**. The **Generate account statement** window opens.
3. Pick a **Period**: **All time**, **This month**, **Last month**, **Last 3 months**, **This year** or **Custom range…** (then fill in **From** and **To**).
4. Press **👁 View** to read it on screen, **⤓ Download** for the PDF, or **✉️ Email…** to send it.
5. If emailing: check the **Send to** address (it is prefilled with the account's own), add an optional note, tick **Copy me on it** if you want a copy, and press **Send statement**.
6. A message confirms "Statement emailed to" the address it actually went to.

The statement carries your agency's branding, the account holder's contact details and every line: invoices, receipts, refunds, voids and what is still owed. Over a date range it also shows the balance brought forward and carried forward.

> ⚠️ The address is asked for every time and must be confirmed. There is no default recipient, because a statement sent to the wrong person cannot be taken back.

## Issuing a refund from the ledger

1. Open the person's ledger and press **↩ Refund**. The **Issue a refund** window lists the invoices with money on them that can still be refunded.
2. Pick the invoice, and if it was paid in more than one payment, choose **Which payment**.
3. Enter the **Amount** (it starts at the most that can be refunded) and choose a **Reason**, for example **Family requested it**, **Overpayment** or **Duplicate charge**. Add **Notes** if useful.
4. Under **Approval**, type **Your full name** and sign in the box.
5. Press **Approve refund**. The ledger reloads and the balance moves at once.

For how the money gets back to the family, see [Refunding a payment](refunds).

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Everything on this screen, for every account in the agency |
| Platform admin | The same, for the agency chosen in the top-bar switcher |
| Centre director | Does not have this screen. Directors use **Finance → Accounting**, which covers their centres |
| Educator, parent | No access. Parents see their own balance in their **Billing** tab |

## Common questions

??? Generate statement and Refund are greyed out. | They only work once an account is open. Choose a person in the Account list first.
??? The person I want is not in the list. | Check the Role filter is not narrowing the list, clear the search and press Enter. Closed records are left out.
??? The refund window says nothing can be refunded. | "Nothing on this account has money against it that can be refunded." An invoice appears there only once a payment has been received and not already given back.
??? The refund toast says no money has been sent yet. | "Refund approved … no money has been sent yet" means the refund is recorded but the money goes back outside KiddieTrac (cash, cheque or e-Transfer). Send it the way you normally would.
??? The statement email did not go out. | If your agency's email switch is off, or a delivery rule blocks that address, you are told so plainly. "Sent" here means it actually left.
??? Why does a Coming up row say no invoice raised yet? | It comes from a billing schedule. The amount is only known when the invoice is issued.
??? Error "The signature pad did not load". | Reload the page. A refund cannot be approved without a signature.

## Related screens

[[open: #external-billing | Accounting]] [[open: #payment-plans | Payment schedules]] [[open: #refunds | Refunds]]

See also: [Accounting](accounting) · [Refunding a payment](refunds) · [Payment schedules](payment-plans) · [Billing schedule](weekly-billing)
