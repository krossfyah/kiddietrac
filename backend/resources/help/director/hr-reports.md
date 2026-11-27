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
