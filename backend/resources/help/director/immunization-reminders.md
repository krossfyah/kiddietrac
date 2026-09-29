---
title: Immunization reminders
category: Compliance
order: 35
roles: agency_admin, platform_admin
---
# Immunization reminders

**Settings → Email settings → Immunization.** A scheduled email to your team listing the
children whose immunization record is missing or out of date — and, if you switch it on, a
reminder to each family about their own child.

Both are **off** until somebody turns them on. An email about a child's health record is
not something to start sending because a default said so.

[[show: #email-settings @ Immunization > Send these reminders | Show me the switch]]

## Setting it up

- [ ] Tick **Send these reminders**. Nothing is sent while it is off.
- [ ] Choose **How often** — **Weekly** on a **Day of the week**, or **Monthly** on a
      **Day of the month** (1–28, so every month has one).
- [ ] Set the **Time of day**. It is your agency's local time, and it is sent within that
      hour.
- [ ] Under **Who gets it**, tick **Agency admins**, **Centre directors** and/or
      **Educators**.
- [ ] Under **What counts as outstanding**, decide whether **Children with no record at
      all** count, and whether records **older than** 6, 12, 18 or 24 months count as out
      of date (or **Never counts as out of date**).
- [ ] Optionally add an **Extra line in the email**.
- [ ] **Save**.

The panel shows how many children are outstanding right now, so you can see the size of
the list before you switch it on. You cannot turn reminders on with nobody to send them to:
tick at least one group, or **Email parents about their own child**.

## The team reminder

It lists each enrolled child with **no immunization record and no recorded dose on file**
(when **Children with no record at all** is ticked), or whose newest one is older than the window you chose, with their centre, room and why.
It is about records, not doses: it tells you who to ask for a card.

Each person gets only **the children in their care**. An educator hears about the
children at their own centre; an agency admin sees the whole agency. Somebody with no
centre assignment gets nothing, and somebody whose children are all up to date is not
written to at all.

If nothing is outstanding, nothing is sent. A weekly email reporting zero teaches people to
ignore the sender.

## Also telling the family

The **Also tell the family** box adds a second, separate reminder, sent on the same
schedule. It only goes out while **Send these reminders** is on as well.

- **Email parents about their own child** — one email per family, even with two children.
- **Blind-copy the office on it** — the staff ticked under **Who gets it** are blind-copied,
  but only on families whose children they care for.
- **Send an app notification too** — an in-app notification and a push, alongside the
  email.
- **Required vaccines only** — leaves out doses your schedule marks as optional.
- **Tell them about doses due within** — 1, 2, 3 or 6 months ahead.

Only accepted, active parent accounts are written to.

### What a family is told

What the email says depends on what the centre has actually recorded:

- **Some doses recorded** — it names the doses that are **Overdue** and **Coming up**, and
  asks them to send the updated record if any have already been given.
- **Nothing recorded, and no record on file** — it asks for the record. It does not tell
  them their child is overdue for a list of vaccines, because nobody has read their card
  yet.
- **Nothing recorded, but a record is on file** — the family is not written to at all.
  They have already sent it in; it is waiting for somebody at the centre to read it.

> **Important:** A card on file with nothing recorded from it keeps that family out of the reminder, so read the records families send in (**Details pending**) promptly.

Exempt doses are never included. See [Immunization exemptions](immunization-exemptions).

## Common questions

??? Why did a family not get a reminder? | Either nothing is overdue or coming up for their child, their record is on file waiting to be read, or their account has not been accepted.
??? Can a centre director change these settings? | This screen is in the agency admin's Settings menu. Directors receive the reminder if Centre directors is ticked.
??? Does an educator see other centres' children? | No. Each reader's list is built from the children they care for.

See also: [Filing an immunization record](filing-an-immunization-record),
[Immunization due at age](immunization-due-age),
[Immunization exemptions](immunization-exemptions).
