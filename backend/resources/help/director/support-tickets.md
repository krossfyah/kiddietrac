---
title: Support tickets
category: Administration
order: 69
roles: agency_admin, platform_admin
---
# Support tickets

**Administration → Support tickets.** Operational problems that need tracking to an
answer: something broken in the app, a repair, a billing or policy question. Each ticket
keeps its files, its replies and every change in one place, and ends with a written note
saying what fixed it.

[[open: #tickets | Open Support tickets]]

## Raising a ticket

1. Press **+ Raise ticket**.
2. Pick a **Category** and a **Severity**. Each severity says what it means, from
   **Low — a question, no rush** to **Urgent — we cannot run the day, or a child is
   affected**. Choose honestly: if everything is urgent, nothing is.
3. Give it a **Subject**. This is required, so the ticket can be found again.
4. Under **What happened?**, say what you were doing, what you expected, and what
   happened instead.
5. Attach **Screenshots or logs** if you have them: images, PDFs, logs, CSV, JSON, XML
   or a zip, up to 10 MB each. You can pick several at once.
6. Press **Raise ticket**.

The ticket is created first and the files are uploaded after. If a file fails, you are
told, and the ticket itself still stands.

> **Tip:** A screenshot usually explains a problem faster than a paragraph.

## Reading a ticket

Click a row to open it. The ticket shows:

- Its number, status and severity, then who raised it and when, the category, the centre,
  who it is assigned to and when it last changed.
- **What happened**: the original description.
- **Attached**: the files. An image opens in place; anything else opens in a new tab.
  Files are only opened through the portal, by people allowed to see the ticket.
- **History**: one timeline, in order, of every reply and every change. Status changes,
  severity changes and assignments each show who made them and when. Replies from support
  are marked **SUPPORT**.

A status reads **Open**, **Waiting on a reply**, **Resolved** or **Closed**.

## Replying, resolving and reopening

- Type in **Write a reply…** and press **Send reply**.
- **Mark resolved** asks **What fixed it?** before anything is saved. Write a line or two,
  then press **Confirm resolved**. The next person to hit the same problem reads your
  answer, so a resolution with no note is shown as "No note was left explaining what
  fixed it."
- A resolved ticket shows the resolution at the top, with who resolved it and when.
  **Reopen** puts it back to Open and clears the old answer, because it no longer holds.

When a ticket is resolved or closed, the person who raised it is emailed. Tickets filed
automatically from an app crash do not send that email, because nobody wrote in.

## Who sees which tickets

Agency admins and directors see the tickets for the agency they are working in, and can
change a ticket's status. A platform admin also sees tickets that could not be tied to
any agency, such as a server error before anybody signed in. Anyone else sees only the
tickets they raised themselves.

See also: [When something looks wrong](when-something-looks-wrong), [Audit log](audit-log).
