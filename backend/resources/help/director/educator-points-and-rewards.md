---
roles: agency_admin, platform_admin, centre_director
title: Educator points and rewards (with Giftbit and Tango Card gift cards)
category: Staff Management
order: 20
---
# Educator points and rewards

Educators earn **points** every month for using KiddieTrac. At month end you can turn
those points into a reward: a **bonus in the next pay run**, a **payout**, or a **gift card**.

Open **⭐ Educator rewards** in the menu.

## How points are earned

Points come from what each person logged in KiddieTrac. Nobody has to keep a tally.

| Activity | Points | Daily limit |
|---|---|---|
| Observations | 10 | 10 |
| Lesson plans | 10 | 3 |
| Walks & outings | 5 | 4 |
| Incident reports | 5 | 5 |
| Photos & videos shared | 3 | 10 |
| Daily logs | 1 | 40 |

These are the starting values. An **agency admin** can change them under **Settings → 🏅 Educator awards → ⚙️ Point values & rewards**,
along with the daily limits (past the limit, an activity still counts but stops adding points
that day) and an optional **points-to-dollars rate** that suggests an amount for each award.

Educators, home visitors and directors earn points. Educators see their own points, what earned
them and a leaderboard of points at their centre under **⭐ My points**. Money is never shown
on the leaderboard.

## Giving a reward

1. Pick the **month** at the top of **Points & awards**.
2. Click **🎁 Award** on a person's row.
3. Enter the amount and choose how:
   - **Bonus in the next pay run.** Added as a line on their payslip the next time you run
     payroll for them, and marked paid then. You can cancel it until that happens.
   - **Payout.** Paid outside KiddieTrac (e-transfer, cheque). Add a reference if you like.
   - **Gift card you bought.** Paste the card code or link. Only the educator can see it,
     in My points.
   - **Send a gift card via Giftbit or Tango Card.** See below.
4. Add a note if you like, and click **Give reward**. The educator gets a notification and a
   short email. A gift card is never put in the email; it waits for them in the app.

A **director** rewards the staff of their own centres. Rewards to directors are given by an
agency admin.

## Gift cards through Giftbit

KiddieTrac integrates with **Giftbit** to send digital gift cards (Amazon, Tim Hortons and
hundreds more brands) straight from the Award dialog.

**Each agency uses its own Giftbit account** and pays for its own cards.

1. Create a business account at **giftbit.com**. Start with a **testbed** account
   (free, no real cards) to try it out.
2. In Giftbit, go to **Account → API keys** and create a token.
3. In KiddieTrac, an agency admin opens **Settings → 💳 Payment providers → 🎁 Gift cards**,
   chooses **Testbed** or **Live**, pastes the token, ticks **Use Giftbit for educator
   rewards** and clicks **Save**. **Test connection** confirms the token and shows your balance.
4. For live cards, **add funds** in your Giftbit account.

Then, in the Award dialog, choose **Send a Giftbit gift card**, pick a brand (or **Let them
choose**, so the educator picks when they open it), and enter an amount the brand allows.
KiddieTrac checks the amount against the brand and your Giftbit balance before ordering.

- **Being prepared** means Giftbit is still making the card. It appears in the educator's My
  points shortly. **Check gift card status** asks Giftbit now.
- **Waiting for Giftbit funds** means your balance ran short. Add funds in Giftbit and the card
  is sent automatically.
- **Cancel gift card** works until the educator redeems it. The value goes back to your
  Giftbit balance.

The token is stored encrypted and is never shown again in full.

## Gift cards through Tango Card

KiddieTrac also integrates with **Tango Card**. It works the same way, from your agency's own
Tango account:

1. Get a Tango platform (contact Tango Card for an account). You get a **sandbox** to test with
   (no real cards) and a production platform.
2. In the Tango portal, open **API keys → Create credentials** to get the **platform name** and
   **API key**. Note the **customer identifier** and **account identifier** that will pay for
   the cards.
3. In KiddieTrac: **Settings → 💳 Payment providers → 🎁 Gift cards**, Tango Card box: choose **Sandbox** or
   **Production**, fill in the four fields, tick **Use Tango Card for educator rewards** and
   **Save**. **Test connection** shows the account and its balance.
4. Fund the account in Tango.

In the Award dialog, if both Giftbit and Tango Card are connected, choose which to **send
through**, then pick the card. Tango cards are a specific card (no "Let them choose"), and the
amount must suit it. The card (a link, or a card number and PIN) appears only in the educator's
My points, with Tango's instructions for using it.

**Cancel gift card** works for Tango only within Tango's rules: an unredeemed choice link, within
5 days of sending. Otherwise KiddieTrac shows Tango's reason.

> KiddieTrac is not affiliated with Giftbit or Tango Card. Gift card terms are set by each brand.

## Award history

**⭐ Educator rewards → 🗂 Award history** lists every award ever given, month by month (newest first): who, what, the points and amount at the time, the method, who approved it, the date, and whether it was automatic. Filter by months, centre and educator, and export it with the CSV, Excel or PDF buttons under the table. Directors see their own centres.

## Where the settings live

All educator award settings are in **Settings → 🏅 Educator awards**: automatic thank-you awards (and the drafts waiting for review), point values and the suggested award, and the method the Award dialog starts on. Directors can read them; agency admins change them.

The gift card accounts (Giftbit and Tango Card, testbed/sandbox or live) are with the other payment accounts in **Settings → 💳 Payment providers → 🎁 Gift cards** (agency admins).
