---
title: Audit log viewer
category: Administration
order: 58
roles: agency_admin, platform_admin, centre_director, auditor
screens: audit-logs
---
# Audit log viewer

The audit log is the record of who did what, and when, in your agency: sign-ins, changes to children, families, staff,
invoices and settings, emails, texts, calls and chat. Use it to answer "who changed this?", to check what went out to a
family, or to hand an inspector or auditor proof of what happened. Nothing here can be edited or deleted.

[[open: #audit-logs | Open the Audit log]]

## Finding an action

1. Open **Administration › Audit log**. The **📜 Audit log** tab opens first, newest entry at the top.
2. Pick an **Action** (for example a sign-in or an invoice change) and, if you need to, an **Entity** (the kind of record: user, centre, invoice …). **All** shows everything.
3. Type a name, email or word in **Search** — it looks through the action and its details.
4. Set **From** and **To** to narrow the dates.
5. Click a row to open the full detail of that entry: who did it, from which address, and exactly what changed.
6. Press **Reset** to clear every filter.

On a phone the filters fold behind **⚙️ Filters** (it shows how many are set) and each entry is a card.

> ℹ️ Every time on this screen is in your agency's time zone. Times carry milliseconds, so entries written in the same second still show in the order they really happened.

## Exporting for an inspector or auditor

1. Set the filters you want (dates, action, search).
2. Press **⤓ CSV** beside **Reset**.
3. Open the file in a spreadsheet. It holds up to 5,000 rows with the same filters and the same order as the screen.

## Reading chat conversations

1. Open the **💬 Chat conversations** tab (agency admins only).
2. Narrow with **Search**, **Active from** and **Active until**.
3. Press **View transcript** on a conversation. Deleted messages show struck through with the time they were deleted; edited messages are flagged.
4. Press **⬇️ Download transcript** to save it as a text file.

> ⚠️ Opening a transcript is itself recorded in the log, with your name and the conversation you opened.

### Handling a reported message

When someone reports a chat message, a **🚩 Reported messages** box appears on the chat tab and admins get a bell notice.

1. Read the report: who reported whom, the reason and their note.
2. Press **Open conversation** to read it in context.
3. Deal with it (talk to the person, remove access), then press **Mark handled**.

## Checking texts, calls, emails and payments

- **📱 Text messages** — every text sent or received, with its status and any error. Filter with **Search**, **From**, **To** and save with **⬇️ Export CSV**.
- **📞 Voice calls** — every automated call (announcements, Safe Arrival checks) with how long it lasted and the outcome, including which key the person pressed.
- **📧 Email log** and **⚠️ Email errors** — every email the agency sent and the ones that bounced or failed, with the reason in plain words.
- **💳 Payment integrations** — what the payment provider sent and received.

[[show: #audit-logs @ 📧 Email log | Show me the Email log]]

## Who can do what

| Role | Sees |
|---|---|
| Agency admin | Every tab for the whole agency, including chats and email. |
| Centre director | The audit, payment, text and call tabs, for their own centre(s) only. No chat or email tabs. |
| Auditor | Read-only audit log for the agency, to review and export. |
| Platform admin | Everything, plus **🌐 Platform-level** for events that belong to no agency (for example sign-in attempts for accounts that do not exist). |

## FAQs and when something goes wrong

??? I can't see the Chat conversations or Email log tab. | Those tabs are for agency admins. Centre directors and auditors do not get them.
??? The list is empty. | Check the filters: a date range or Action left over from last time hides rows. Press **Reset**.
??? Old entries show .000 after the seconds. | Entries from before September 2026 were saved without milliseconds. Their order within that second was never recorded.
??? Can I delete or correct an entry? | No. The log is permanent on purpose, so it can be trusted as evidence.
??? Does looking at the log notify anyone? | No, except that opening a chat transcript is recorded in the log itself.

## Related screens

[[open: #compliance | Compliance dashboard]] [[open: #privacy-breaches | Privacy breaches]]

See also [[compliance-dashboard]] and [[data-retention-compliance]].
