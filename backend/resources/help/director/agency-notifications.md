---
title: Agency notifications — reminders and who is told what
category: Settings
order: 66
roles: agency_admin, platform_admin
screens: notification-settings
---
# Agency notifications — reminders and who is told what

**Settings → Agency notifications** is where an agency admin decides the reminders KiddieTrac sends on the agency's behalf and who receives them: forms waiting for counter-signature, provider bios, shared files, Message Centre rules, daily summaries, instant moments, staff app notifications, escalated daily records, immunizations and drills, and staff certificates. Each person can still fine-tune their own notices in **My settings**; a role you switch off here gets nothing about that topic.

[[open: #notification-settings | Open Agency notifications]]

The screen has one tab per topic: **✍️ Counter-signature**, **🧾 Forms-only parents**, **🧑‍🏫 Provider bios**, **📎 Shared files**, **💬 Message Centre**, **📨 Daily summaries**, **🌈 Instant moments**, **📲 Required for staff**, **🚩 Escalated daily records**, **💉 Immunizations & drills** and **🎓 Staff certifications**. Each tab has its own **Save** button.

## Reminding reviewers about forms to counter-sign

1. Open the **✍️ Counter-signature** tab.
2. Turn on **Send reminders**.
3. Choose **How often** (**Every day**, **Weekdays only**, **Once a week** or **Every few days**) and the **Time of day**, in your agency's time zone.
4. Set **Only forms signed at least** a number of hours ago, so a form signed a few minutes ago is not chased straight away.
5. Choose **Who is reminded**. Directors only see forms from their own centre; a centre with no director sends its forms to the admins.
6. Tick how they are told: **Send by email**, **Show in the app**, **Push to phones**.
7. Press **👁 Preview** to see the reminder, **📤 Send test…** to send one marked [Test] to an address you type, then **Save**.

> ℹ️ Only forms set to **Requires counter-signature** in **Forms Manager → Edit** count.

## Deciding who educators can message

1. Open the **💬 Message Centre** tab.
2. For **Educators** and **Home visitors**, choose **Families they can message**: **Families in their rooms** or **All families at their centre**.
3. Tick the staff they can message (agency admins, directors, other educators, home visitors).
4. Choose what happens to **Conversations these rules no longer allow**: **Keep as read-only history** or **Hide them**.
5. Set **Nudges** (the 👋 button), **Families can nudge staff**, **Time between nudges**, **Ring through Do Not Disturb**, **Calls** and **Families can call staff**.
6. Press **Save**. **✓ Saved — applies straight away** appears.

> ℹ️ Directors and admins can always message everyone, and parents can always reply to the people messaging them.

## When families and staff get the daily summary

1. Open **📨 Daily summaries**.
2. For **Parents**, choose how long after the child is checked out the summary goes; set **Parents — end of day** for a child who was never checked out.
3. For **Educators**, choose the delay after they clock out, and **Educators — end of day** for anyone who never clocked out.
4. Press **Save**.

On **🌈 Instant moments** you can instead tell families straight away about **Daily moments**, **Learning observations**, **Medication doses** and **Milestones**. Turn **Instant moment notifications** off and families see these only in the end-of-day summary.

## Other topics

- **📲 Required for staff** — turn on **Require app notifications** so staff must allow notifications on their phone.
- **🚩 Escalated daily records** — the roles and people who get escalated records, and how they are told.
- **💉 Immunizations & drills** — switch a role off to stop every notice about that topic for people in that role (bell, phone and email).
- **🎓 Staff certifications** — **Expiry alerts** before, on and after a certificate runs out, to the staff member, their directors and admins. **Preview the next reminders** shows who is due.
- **📎 Shared files** — **Email parents about new files** and **Email staff when a family sends a file**. The email only says a file is waiting; the file is never attached.
- **🧑‍🏫 Provider bios** — remind providers who have no bio yet, and **Send admins a summary**.
- **🧾 Forms-only parents** — parents who signed forms but have no family yet.

[[show: #notification-settings @ 💬 Message Centre | Show me the Message Centre tab]]

## Who can do what

| Role | Access |
|---|---|
| Agency admin | Every tab, for their agency |
| Platform admin | The same, for the agency they are working in |
| Centre director, educator | Not in their menu. They set their own notices in **My settings** |

## FAQs and when something goes wrong

??? I saved but nothing was sent. | Check **📬 Mail & notifications** on **Settings → Email settings**: with it off nothing goes out. Then check the role is ticked on the topic's tab.
??? The test says "Not sent". | The reason follows in brackets. Most often the address is wrong, or the agency's email is switched off.
??? A director gets counter-signature reminders for another centre. | They should not: directors only see forms from their own centre. Check which centres the director is assigned to on **Staff**.
??? Someone still gets a notice after I switched their role off. | A person with two roles is told if either role is on.
??? Where is the counter-signature setting for one form? | In **Forms Manager → Edit**, tick **Requires counter-signature**.

## Related screens

[[open: #email-settings | Email settings]] [[open: #forms-manager | Forms Manager]] [[open: #chat | Message Centre]]

See also: [Email settings](email-settings), [Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms), [Message Centre](message-centre-rules).
