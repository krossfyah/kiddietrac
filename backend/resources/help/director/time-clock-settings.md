---
title: Clock settings
category: Settings
order: 47
roles: agency_admin, platform_admin, centre_director
screens: clock-settings
---
# Clock settings

Clock settings hold everything about the time clock in one place: when to remind people who have not clocked in or are still on the clock, when to close shifts and days somebody forgot to close, and how often to remind approvers about time off that is waiting. All times are in your agency's own timezone.

[[open: #clock-settings | Open Clock settings]]

The screen has three tabs: **🔔 Reminders**, **⏱️ Auto sign-off** and **🌴 Time-off approvals**. Each tab has its own save button, so save before you switch tabs.

## Setting time clock reminders

1. Open **Clock settings** and stay on the **🔔 Reminders** tab. The card is called **🔔 Time clock reminders**.
2. Tick **Remind people who have not clocked in** and pick a time. Educators and directors with no punch yet that day get a reminder.
3. Tick **Remind people still on the clock** and pick a time. Anybody whose shift is still open gets a reminder, including a shift left open on an earlier day.
4. Tick **Weekdays only** to keep reminders off people on Saturdays and Sundays.
5. Choose how reminders go out: **Send by email**, **Send to the app** (a push notification on the phone app), or both.
6. Press **Save reminders**. **✓ Saved** appears beside the button.

> ℹ️ Reminders are checked once an hour, so they go out during the hour you choose, not at the exact minute.

## Reminding educators when their room is empty

Under **⏱️ Room empty, still clocked in** on the same tab:

1. Tick **Remind educators to clock out when their room is empty**. They get an app notification once every child has left their room and they are still on the clock. It never fires before the first child arrives, and directors and admins are not reminded.
2. In **After the last child leaves**, enter how many minutes the room must be empty before the first reminder (5 to 240, default 15).
3. In **Remind again every**, enter the minutes between reminders. There are at most 3 reminders; enter 0 to send just one. If children come back, the count starts over.
4. Press **Save reminders**.

## Closing forgotten shifts and sign-outs

1. Press the **⏱️ Auto sign-off** tab.
2. Tick **Close staff shifts** and pick a time. A punch nobody clocked out of is closed at that time on the day it started, never at whatever time the job runs, so a Tuesday punch is never recorded as a 48-hour shift.
3. In **Give up on a shift after**, enter a number of hours (1 to 24, default 14). A punch still open that long after it began is treated as abandoned.
4. Tick **Sign children out** and pick a time to sign out any child nobody signed out at the end of the day.
5. Press **Save auto sign-off**.

## Reminding approvers about time-off requests

1. Press the **🌴 Time-off approvals** tab. The card shows how many requests are pending right now and when the last reminder went out.
2. Tick **Send reminders**. Leave it off for no reminders; people are still told when a request is first made.
3. In **How often**, choose **Every day**, **Weekdays only**, **Once a week** (then pick the day) or **Every few days** (then enter the number of days).
4. Set **Time of day**. Reminders are checked every 15 minutes.
5. In **Only requests waiting at least**, enter the hours a request must wait before it is chased, so a request made an hour ago is not chased the same morning.
6. In **Who is reminded**, choose **Directors and admins**, **Directors only** or **Admins only**. Directors only see their own centre's requests; a centre with no director sends its requests to the admins.
7. Choose the channels: **Send by email**, **Show in the app** (a notification in their bell) and **Push to phones**.
8. Press **👁 Preview** to see the email as approvers will get it. Nothing is sent.
9. Press **📤 Send test…**, enter an email address and press **Send test** to get one copy marked [Test]. Its buttons only open the portal.
10. Press **Save**.

## Who can do what

| Role | Reminders | Auto sign-off | Time-off approvals |
|---|---|---|---|
| Agency admin | Change | Change | Change |
| Centre director | Change | Change | Sees "Only agency administrators can change time-off approval reminders." |
| Platform admin | Change | Change | Change |

Educators and parents do not see this screen. Settings apply to the whole agency.

## FAQs and troubleshooting

??? A tab says "Could not load:" and a message. | The settings did not load. Reload the page; if it keeps happening, send the message to KiddieTrac support.
??? I pressed save and see "Could not save". | The change was not saved. Check your connection and press the tab's save button again.
??? A reminder came at 10:40, not 10:00. | Clock reminders are checked once an hour, so they arrive during the hour you picked.
??? Somebody still got a reminder on Saturday. | Tick **Weekdays only** on the **🔔 Reminders** tab and press **Save reminders**.
??? My test reminder says "Not sent". | The text after it gives the reason. Check the address you typed and try again.
??? Who gets the empty-room reminder? | Only educators, through the app. Directors and admins are never sent it, and it is never sent by email.

## Related

[[open: #time-clock | Clock in/out]]
[[open: #time-off | Time off]]

- [[time-clock]]
- [[auto-sign-off]]
- [[time-off]]
