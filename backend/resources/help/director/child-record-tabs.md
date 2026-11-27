---
title: The child record (tabs)
category: Children
order: 15
roles: agency_admin, centre_director, platform_admin
screens: admin-children
---
# The child record (tabs)

**Children** lists every child in your centres — agency admins see the whole agency, centre directors see their own centres. Open a child to see their full record: who picks them up, their health flags, enrolment, daily reports, documents and everything else on file. This is where you edit a child, print their emergency card or archive them when they leave.

[[open: #admin-children | Open Children]]

## Finding a child

1. Open **Administration › Children**. The list starts on **Enrolled** children.
2. Pick a centre in the first box (it starts on **All centres**) to see one centre only.
3. Change the status box to **All statuses**, **Waitlist**, **Withdrawn** or **Inactive** to see children who are not currently enrolled.
4. Type part of a name in **🔍 Search by name…** — the search covers every child in the agency, not just the rows on screen.
5. On a computer, switch between **▦ Cards** and **☰ Table**. On a phone the list is always a table.

The rings above the list count the children shown: **Children**, **At Centre now**, **With a photo** and **Family linked**. In the table, **Last seen** is the last check-in; grey italics mean two weeks or more without one.

> 💡 Agency admins also see **Active / Archived** under the banner. **Archived** lists children who have left and been archived — their records are kept as history.

## Opening a record

1. Click a child's card or row, or open the row's **⋮** menu and choose **Open**.
2. The record opens in a popup over the list, so your filter and scroll position are kept. Close it with **✕**.

The header shows the photo, name and preferred name, age and date of birth, the **Child ID**, room, family, health-flag count, and the **usual drop-off and pick-up times**. If those times are not set, the header says so — add them with **Edit**. They are wall-clock times: 8:15 means 8:15 on the room's clock and never shifts with anyone's timezone.

![The child record header and its tabs](https://api.kiddietrac.com/help-img/full-history.png)

### The tabs

- **👤 Overview** — guardians, pick-ups and emergency contacts, and active health flags.
- **🏫 Enrollment** — current placement (agency, centre, room), status, enrolled-since date and monthly fee, school details for school-age children, provider history and the full enrolment history.
- **📊 Daily reports** — check-ins and day events from the last 21 days.
- **🩹 Incidents** — incident reports for this child.
- **💉 Immunization** — the child's immunization record.
- **📸 Live feed** — photos and media tagged to the child, plus observations.
- **📎 Attachments** — documents on file. **Open** shows one in the portal viewer, **Download** saves a copy. Signed agreements are a legal record and show a padlock instead of **Remove**.
- **📝 Notes** — notes about the child, with the quick notes you may see.
- **🗄️ Full history** — every record held about the child from across the system: attendance, care logs, observations, incidents, medications, immunizations, photos, chats, documents, report cards, awards, invoices, and the lesson and meal plans of their rooms. Sections start closed with a count; each opens 25 at a time. It works for archived children too — see [[archived-records]].

## Editing a child

1. Open the record and press **✏️ Edit** (or choose **✏️ Edit** from the row's **⋮** menu to go straight in).
2. The record switches to editing: a yellow **✏️ Editing** note appears and two tabs are added at the front, **✏️ Details** and **⚠️ Allergies & health**.
3. On **✏️ Details**, change the name, preferred name, pronouns, date of birth, **Usual drop-off time**, **Usual pick-up time**, gender, doctor, health card (last 4) and medical, dietary or cultural notes. To change the photo, press **Change photo** (JPG, PNG or WebP, up to 4 MB).
4. Press **Save changes**. A **Child updated** toast confirms it.
5. To add documents, open **📎 Attachments**, give an optional title, a category and an expiry date, then press **＋ Attach files** (several at once, 10 MB each).
6. Close the record when you are finished — each section saves with its own button.

## Guardians, pick-ups and emergency contacts

The **👤 Overview** tab lists everyone on the family's file.

- **Guardians** — relationship, **primary**, phone numbers (a second number shows its type and any extension) and **🚸 pick-up login** if that is what they have. Each shows **✓ Signs in**, or **✉ Send login invite** if they have never signed in.
- **Pick-ups & emergency contacts** — the people the family named, authorised pick-ups first, each marked **✓ can pick up** or emergency contact only.

1. To give a pick-up person their own sign-in, press **🔑 Set up login** beside them and enter their email.
2. When a parent has asked for a login for someone, press **✓ Approve** or **Decline**.
3. To take a login away, press **Remove login**. See [[pick-up-logins]].

Parents keep this list themselves in their app. When they change it, the child's educators, the centre's directors and the agency's admins are notified (directors and admins by email too), and the before and after is kept in the audit log. If nobody is on file, add contacts in **Families** › **Edit** › **Emergency**.

## Emergency card and compliance report

1. Press **🚨 Emergency card**. The card opens in a panel over the portal with **Print** and **✕** in its header. In the phone app the button reads **Print in browser** and hands the card to your phone's browser; the link it makes lasts a few minutes and is recorded.
2. Press **📋 Compliance report** for a one-page inspection summary, then **Print / Save as PDF**.

See [[opening-and-printing-documents]].

## Archiving a child who has left

1. Open the record and press **🗄️ Archive** (last in the header, outlined in red).
2. Read the **Archive child?** box: it ends any active enrollment and takes the child off active rosters; invoices, observations and photos are kept.
3. Press **Yes, archive**. A **Child archived** toast appears and the child leaves the active list.

An archived record opens read-only — no **Edit** or **Archive** — but keeps **🚨 Emergency card** and **📋 Compliance report**. Only an agency admin can restore it. To end a placement on a date instead, use [[de-enrolling-a-family]].

## Who can do what

| Role | Sees | Can |
|---|---|---|
| Agency admin | Every child in the agency, plus **Archived** | Edit, attach, set up logins, archive, restore |
| Centre director | Children in their own centres | Edit, attach, set up logins, archive (not restore) |
| Educator | Children in their rooms (their own **Children** screen) | View; no archive |
| Auditor | A read-only copy of this list in their own menu | View and print only |

## FAQs and when something goes wrong

??? I can't see a child. | Check the status box (it starts on **Enrolled**) and the centre box. Centre directors only see their own centres. Archived children are under **Archived** (agency admins).
??? The list says "No children match". | Clear the search and set the filters back to **All centres** and **All statuses**.
??? There is no Edit or Archive button. | The record is archived, so it is read-only history. An agency admin can restore it.
??? "Pop-up blocked. Allow pop-ups to view the emergency card." | Allow pop-ups for the portal in your browser, then press **🚨 Emergency card** again.
??? "That photo is over 4 MB — please choose a smaller one." | Pick a smaller photo or a screenshot of it. Profile photos must show a face.
??? A file won't attach: "is over 10 MB". | Each file can be up to 10 MB. Compress a scanned PDF or take a photo instead.
??? Does editing a record email the family? | No. Editing details, attaching files or archiving sends nothing to the family. Only a login invite emails the person it is for.

## Related screens

[[open: #admin-families | Families]]
[[open: #medications | Medications]]
[[open: #allergy-alerts | Allergy alerts]]
[[open: #withdrawals | Withdrawals]]

See also [[adding-a-family]], [[archived-records]] and [[pick-up-logins]].
