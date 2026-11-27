---
title: Choosing your learning framework
category: Settings
order: 8
roles: agency_admin, platform_admin
screens: hdlh-gaps, learning-framework
---
# Choosing your learning framework

Your learning framework is the curriculum framework your agency plans and reports against. Agency admins choose it once on **Settings > Learning framework**, and KiddieTrac uses it in three places:

- **Observations**: when an educator uses **Structure with AI**, the observation is linked to your framework's areas.
- **Report cards**: each report card has one narrative for each area of your framework.
- **Framework gaps**: the gaps report on **Engagement > Framework gaps** shows, for every child, how many observations each area has, so you can see where your programme is light.

[[open: #learning-framework | Open Learning framework]]

## The frameworks you can choose

| Framework | Where it's used |
|---|---|
| How Does Learning Happen? (HDLH) | Ontario |
| Early Learning for Every Child Today (ELECT) | Ontario (continuum) |
| British Columbia Early Learning Framework (BC ELF) | British Columbia |
| Flight: Alberta's Early Learning and Care Framework | Alberta |
| Accueillir la petite enfance | Québec |
| Head Start Early Learning Outcomes Framework (ELOF) | United States |
| Ohio's Early Learning and Development Standards (Ohio ELDS) | Ohio |
| Early Years Foundation Stage (EYFS) | England |
| HighScope Curriculum | International |
| Our own framework | Your agency: 2 to 10 areas you name yourself |

Each card on the screen lists its areas, for example HDLH's Belonging, Well-being, Engagement and Expression. If nobody has chosen one yet, a blue note at the top says "No framework has been chosen yet, so KiddieTrac is using" the framework for your province (HDLH in Ontario). The framework in use carries a green **In use** tag.

## Choosing or changing your framework

1. Open **Settings > Learning framework**.
2. Click the card of the framework you use. Its border turns blue.
3. Press **Save framework**.
4. If you are changing from another framework, confirm **Switch framework** in the window that asks "Switch to" the new one. A toast says "Learning framework saved" and the **In use** tag moves to your choice.

> ℹ️ Changing framework affects new observations and report cards only. A report card already written keeps the framework it was written in, and still prints with those sections.

## Setting up your own framework

Use this for a programme such as Reggio Emilia, Montessori or a local curriculum.

1. Click the **Our own framework** card. A box opens under the cards.
2. Type a **Framework name**, for example "Our Reggio-inspired curriculum".
3. For each area, fill in the **Area name** and a short note on **What it covers**. The note helps the AI and your team link observations to the right area.
4. Press **+ Add an area** for more rows (up to 10), or **×** to remove one.
5. Press **Save framework**.

You need at least two areas with a name. Empty rows are ignored.

## Reading the framework gaps report

[[open: #hdlh-gaps | Open Framework gaps]]

1. Open **Engagement > Framework gaps**. The screen counts each child's observations, so it takes a few seconds to load.
2. Read the banner: it names your framework, for example "HDLH gap detection".
3. Look at the **Gaps** column. Children with the most gaps are at the top. Red is 2 or more gaps, amber is 1, green is none.
4. Read across the row. Each area column shows how many observations the child has in that area: red under 2 (a gap), amber 2 to 4, green 5 or more (strong).
5. Check the **📋** line under a count. It shows how many planned activities in the child's room are tagged with that area ("planned" from published weeks, "draft" from drafts), over the last 8 weeks and the next 4.
6. Plan observations and activities for the areas where a child shows red, then come back to watch the counts rise.

> 💡 A red area with plenty of 📋 planned activities means the activities are happening but nobody is writing observations about them. A red area with no 📋 means it is missing from your plans.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Choose the framework and read the gaps report. |
| Platform admin | The same, for the agency they have chosen. |
| Centre directors and educators | Do not see these screens. They use the framework through observations, lesson plans and report cards. |

## FAQs and troubleshooting

??? Do older observations count toward the new framework? | Yes. An observation without framework links counts toward the area its domain matches (for example, a Language & literacy observation counts toward Expression in HDLH, or Communication and language in EYFS).
??? Why does the gaps report show an area as a gap for every child? | Some areas, such as EYFS Literacy or Mathematics, only fill up when observations are linked to them. Observations structured with AI are linked directly; manual ones count by their domain.
??? Saving my own framework says "Add at least two areas to your framework." | Give at least two rows an **Area name** before you press **Save framework**.
??? The gaps report says "No children on file." | No children are enrolled in the agency you have chosen. Check the agency, or add children first.
??? Not every child is on the gaps report. | The report checks the first 30 children in your list. Use the child's portfolio or developmental milestones to look at a child who is not shown.
??? The learning framework screen says "Could not load". | The page could not reach KiddieTrac. Refresh, and if it keeps happening, raise a ticket.

## Related

[[open: #curriculum | Curriculum]]

- [[curriculum-library]]
- [[year-end-report-cards]]
- [[developmental-milestones]]
