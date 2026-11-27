---
title: HR: setting up leave types
category: Staff Management
order: 61
roles: agency_admin, platform_admin
screens: time-off?tab=types
---
# HR: setting up leave types

**Human Resources > Time off > Leave types** is where an agency admin sets up the kinds of leave your team takes (vacation, sick days, personal days…), how much each person gets, how it builds up and what carries over. These are your agency's own rules: KiddieTrac does not apply any province's or state's employment law for you. Set this up once when you start with HR, and again whenever your leave policy changes.

[[open: #time-off?tab=types | Open Leave types]]

## Starting from the example set

1. Open **Human Resources > Time off > Leave types**.
2. Press **Load example set**.
3. Read the **Load the example set?** message and press **Load examples**. Vacation, Sick days, Personal days, Bereavement and Unpaid leave are added with example amounts, each marked **Example**. Names you already use are skipped.
4. Press **⋮ > Edit** on each one and change every value to your own policy.

> ⚠️ The example amounts are not any law's minimums. Nothing is ever applied by itself.

## Adding a leave type

1. Press **Add leave type**.
2. Type the **Name** (for example Vacation) and pick a **Colour**.
3. Choose **Counted in**: **Days** or **Hours**.
4. Choose **Pay**: **Paid** or **Unpaid**.
5. Choose **Staff can see and request it**: **Yes**, or **No, managers only**.
6. Choose **Approval**: **Needs approval**, or **No approval: recorded at once, manager reviews it**.
7. Choose **Paid out when someone leaves** (the default pays out vacation and the overtime bank only).
8. Choose **How it builds up** (see below) and what happens **At the end of the leave year** (see Carry-over).
9. If people used the older time-off form, set **Old requests filed as** (for example Vacation or Sick).
10. Press **Save**. The **Entitlement rules** window opens next so you can say how much people get.

> ℹ️ A type with no approval is recorded and taken off the balance as soon as it is asked for. Managers still see it as **To review** on **Time off** until one of them presses **Reviewed**, which changes nothing in the balance.

## How leave builds up

- **Yearly, all at once** at the start of the leave year.
- **Each pay period**: choose **Pay periods a year** (12, 24, 26 or 52).
- **By hours worked**, from the time clock: set **Hours worked to earn 1 unit**, up to the yearly amount.
- **Not tracked**: requests are recorded but there is no balance.

Someone hired part-way through a leave year gets a share of a yearly grant for the days left, rounded to the nearest half day (quarter hour for hour-based types).

## Setting the entitlement rules

1. On the type's row, press **⋮ > Entitlement rules**.
2. In the first row, choose the **Employment type** (or **Everyone**), the **After years of service** and the **Amount a year**.
3. Press **Add a rule** for each step, for example 10 days for everyone, 15 days after 5 years.
4. Press **Remove** on a row you do not need.
5. Press **Save rules**.
6. Press **Run grants and accruals now** to apply them today. They also run every night by themselves.

The rule with the most years of service a person has reached applies, and a rule for their employment type beats one for **Everyone**.

## Carry-over

1. In **⋮ > Edit**, choose **At the end of the leave year**: **None**, **All of it** or **Up to a limit**.
2. For **Up to a limit**, type the **Carry-over limit**.
3. To make carried leave expire, set **Carried balance expires after (months)**. Leave it empty for **Never**.
4. Press **Save**.

## How days are counted

Requests count working days. Weekends are skipped, and so are the statutory holidays and closures on the person's centre calendar. A closure made for someone's own leave, or a PD day, still counts. To take a winter shutdown off people's leave, see [[hr-closures-leave]].

## Counting requests made before HR

1. Set **Old requests filed as** on the type and press **Save**.
2. Set up its entitlement rules.
3. On the row, press **⋮ > Count this year's approved requests**, then **Count them**. Requests already approved this leave year are taken off the balances, once. The requests themselves never change.

## Archiving a type

Press **⋮ > Archive**. The type stops being offered, and balances are kept. **⋮ > Restore** brings it back.

## Who can do what

- **Agency admins** set up leave types and rules. This tab is shown only to them.
- **Centre directors** see the results on **Balances** and approve requests, but cannot change types.
- **Staff** pick the types marked **Staff can see and request it** on their time-off form and see their balance in **My HR**.

## FAQs and when something goes wrong

??? I added a rule but balances did not change. | Press **Run grants and accruals now**, or wait for the nightly run. Grants that were already given are never changed; add an adjustment on **Balances** for a past difference.
??? Staff cannot see a leave type on their form. | Edit the type and set **Staff can see and request it** to **Yes**. Archived types are never offered.
??? "Give it a name." | The **Name** field is empty. Type a name and press **Save** again.
??? Where do I set when the leave year starts, or whether requests may go below zero? | In **Human Resources > HR settings**: **Leave year starts** and **Requests that go below zero** (**Refuse them** or **Allow them, with a warning**).
??? Will staff be told when I change a type? | No. Nothing is emailed. They see their new balance in **My HR**.

## Related screens

[[open: #time-off?tab=balances | Balances]] [[open: #time-off | Time off requests]] [[open: #hr-settings | HR settings]]

- [[hr]] — people, employee files and balances
- [[hr-closures-leave]] — closures taken from leave and part-time pro-rata
- [[time-off]] — requesting and approving time off
