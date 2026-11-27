---
title: HR: dashboard, reports, probation and missing information
category: Staff Management
order: 68
roles: agency_admin, platform_admin, centre_director
---
# HR: dashboard, reports, probation and missing information

Part of the KiddieTrac HR add-on. Agency admins see the whole agency; directors see the staff at their own centres.

## The Dashboard

Open **Human Resources > HR dashboard**.

### Stats and charts

The top of the dashboard shows your HR figures at a glance. Choose **This month**, **This quarter** or **This year**, and (agency admins, or directors with several centres) a centre. Each tile compares the period so far with the same point of the period before (for example, November 1 to 27 against October 1 to 27): ▲ and ▼ show the change, green when it is good news and red when it is not.

- **Tiles**: headcount and FTE (weekly hours on the HR file ÷ your full-time week), starters, leavers, turnover, who is off today and this week, the absence rate (days of approved leave ÷ people × weekdays), late clock-ins, everything waiting for a decision (time off, clock-in fixes, timesheets to approve, overtime, claims, shift pick-ups), overtime hours, open shifts and shifts not accepted yet, certificates to renew, probation reviews due, onboarding in progress, training completed and, for people who can see pay, this week's labour cost against the budget.
- **Charts**: headcount over 12 months, starters and leavers by month (hover a month for its turnover), absence by reason, the overtime trend, leave balances and use, staff by centre, roles and employment types, length of service, certificates (valid, expiring, expired) and who is off over the next 14 days.
- Hover a mark for its figures, or press **Table** on any chart to see the numbers. Click a tile, a month or a day to open the matching screen or report with those dates already chosen. Clicking a centre in **Staff by centre** filters the whole dashboard to it.
- Charts draw in as you scroll to them. If your device is set to reduce motion, they appear straight away.
- The figures are a minute or two behind at most. Old or corrected clock-ins are compared with their shifts the first time you open the dashboard; the late clock-ins tile says when some are still being checked.

- **HR setup checklist**: five steps with a progress bar: add your leave types, set how much leave people get, complete everyone's HR file, give everyone a manager who approves their requests, and have your staff sign in. Press **Set up** beside a step to go straight to it.
- **Probation ending**: people whose probation ends in the next 30 days, or ended without being confirmed. Use the ⋮ menu to **Mark probation passed**, **Extend probation** to a new date, or open their HR file.
- **To do**: how many people have missing information, and how many have never signed in.

Agency admins can press **Probation and invite settings** to set:

- the probation length (90 days unless you change it);
- how many days before the end the manager gets a reminder (14 days unless you change it);
- how many days before an unused invite counts as expired (60 days unless you change it).

The reminder goes to the manager on the person's HR file. If they have none, it goes to their centre director.

## Reports

Open **Human Resources > HR reports** and choose a report.

- **Headcount**: current staff by centre and by employment type.
- **Starters and leavers**: who joined and who left between two dates, month by month, with **turnover** (leavers ÷ the average headcount) and **retention** (the people here on the first day who are still here on the last).
- **Retention**: for each year people started, how many are still here and how many stayed 90 days and a year.
- **Length of service**: average and median years, in bands.
- **Leave liability**: what you would owe if everyone left today. It counts the leave balances that are paid out on leaving, multiplied by each person's pay rate. Only people who can see pay can open it.
- **Overtime**: overtime requests by person, with the hours paid and the hours banked.
- **Lateness**: clock-ins compared with the shift start, by centre and by person.
- **Schedule vs worked**: scheduled hours against the hours clocked, for up to 93 days.
- **Leave this year vs last**: what each person earned and took, this leave year and last.
- **Team absence**: who is off, or has asked to be off, between two dates, and how many are off each day.
- **Missing information**: see the section below.
- **Not signed in yet**: staff who have never signed in, with the date of their invite. An invite older than your setting is marked **Expired**. Agency admins can choose **Resend invite** from the ⋮ menu, which sends a new welcome email with a new temporary password. The expiry only marks the invite on this list. It does not stop anyone signing in.
- **Probation**: everyone in probation. Tick **Include passed and older** to see everyone.
- **Everything we hold**: pick a person to see every kind of record KiddieTrac holds about them, for example for a privacy request. Admins can also download all their data.

Press **Print** to print a report. Use the export buttons under each table to download it as CSV, Excel or PDF.

## Missing information

The check looks for a missing start date, manager, emergency contact, job title or employment type. Agency admins have no manager above them, so they are not flagged for one.

People who can see pay also get payroll checks, highlighted in red:

- no pay rate;
- no pay deposit details;
- no SIN or SSN (agency admins only);
- pay below the minimum wage.

The minimum wage check uses the main centre's province or state. It covers hourly pay, and salaries where the HR file has hours a week. Contractors are not checked.

The minimum wage table is shown under the report, with the date it was last checked. It covers the provinces and states where KiddieTrac agencies run today, and applies each new rate from its start date. Local city minimums can be higher, so check your own.

## Leave payout and leave types

In **Human Resources > Time off > Leave types**, **Paid out when someone leaves** decides which balances the leaving pack and the leave liability report count. By default, that is your vacation type and the Overtime bank. Sick and personal days are not counted unless you choose **Yes** for that type.
