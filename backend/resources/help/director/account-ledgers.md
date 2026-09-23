---
title: Account ledgers
category: Billing
order: 40
roles: agency_admin, platform_admin
---
# Account ledgers

One screen that answers, for any one person: what were they billed, what have they paid, what is still owed, and what is coming.

![The account ledger: pick a person, and their whole financial history is on one page](https://api.kiddietrac.com/help-img/account-ledger.png)

## Finding an account

The picker across the top has three parts:

- **Role** — narrows the list to parents, educators, directors and so on.
- **Search** — name or email. It waits for **Enter** or the search button; it does not filter as you type.
- **Account** — the person themselves, shown as **name and role in brackets**: *Amarachi Ihenekwe (Parent)*.

The account list deliberately does **not** show balances. In a list of sixty-eight people that is sixty-eight amounts to read past to find somebody, and the balance is the first thing the ledger states once it opens.

**⊞ All accounts** switches to every account at once, with what each owes. **📄 One account** goes back.

## What the numbers mean

Four figures tie together, always:

> **Billed − (Collected − Refunded) = Balance**

- **Billed** — everything invoiced, excluding anything voided. A voided invoice carries no balance and is reported separately.
- **Collected** — what the invoices themselves say was settled.
- **Refunded** — money handed back. A refund *un-collects*: the balance rises again by exactly what was returned.
- **Balance** — what is still owed today.

**Money is counted once, from the invoice.** Where a payment record exists it is shown as its own line — with the method, reference and real date, which is better information than a summary — but it is not added again. If those two ever disagree, the invoice is right.

**Overpayment is reported, never netted.** Where more was received than was billed, the line says so and the amount appears in its own figure. Whether that is credit the family can draw on, or a payment recorded twice at source, is a decision for the person reading — so it is stated rather than silently absorbed.

## Coming up

Everything still expected on the account: each payment on a schedule, and any recurring billing.

Each row names **the invoice behind the amount**, and its **⋮** menu will open, download or email that invoice. Two cases are called out rather than hidden:

- **"No invoice raised yet"** — a recurring billing schedule is only *"we will raise something on the 1st"*. The amount is not decided until it is issued, so there is nothing to open.
- **"The invoice total differs from this amount"** — the schedule and the invoice disagree, usually because the invoice was adjusted after the schedule was agreed. Worth a look.

A missed payment is kept in this table and flagged **missed**, not dropped. It is the most useful thing on the page.

## Still outstanding

Every unpaid invoice, oldest first, with how many days late. Each row's **⋮** opens the invoice document.

## Generating a statement

**📄 Generate statement** builds a full statement for the account. From that window you can set a date range, read it on screen, download the PDF, or email it.

The statement carries your agency's branding, the account holder's contact details, and every line: invoices, receipts, refunds, voids and what is still owed. Over a date range it also shows the balance brought forward and carried forward.

**Emailing asks for the address every time.** It is prefilled with the account's own, and it must be confirmed — there is no default recipient, because a statement sent to the wrong person is not something you can take back.

If the send is held back — the agency's email switch is off, or a delivery rule blocks that recipient — you are told so plainly. "Sent" here means it actually left.

## Issuing a refund

See **[Refunding a payment](refunds)**.
