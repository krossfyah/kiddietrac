---
roles: agency_admin, centre_director, platform_admin
title: Marketing campaigns
category: Communications
order: 36
screens: marketing-campaigns
---
# Marketing campaigns

**Marketing › Campaigns** is where you write newsletters, open-house invites and promotions and send them to the
families (or staff) you choose — by email, as a News post in the portal, or both. After it goes out, you can see who
got it, who opened it and whose email bounced, and resend it to people once their address is fixed.

[[open: #marketing-campaigns | Open Campaigns]]

![Click + New campaign on the Campaigns screen](https://api.kiddietrac.com/help-img/campaigns.png)

## Writing a campaign

1. Press **+ New campaign**.
2. Enter a **Campaign title** (only your team sees it) and the **Subject (email)** families will see in their inbox.
3. Choose the **Audience**: **All families**, **Currently enrolled**, **Waitlist**, **Prospects (no active child)** or **Staff only**.
4. Choose the **Channel**: in the portal (a News post), by email, or both.
5. If it is emailed, check **Send from** — the agency default, or a connected Gmail / Google Workspace mailbox if your agency has one.
6. Add a **Hero image (optional)** — the picture at the top of the message.
7. Write the body in the editor (headings, lists, colours, links, pictures). To greet each family by name, type **{first_name}** or press **👤 First name**: "Hi {first_name}," arrives as "Hi Sarah,". Where there is no single reader, or no first name on file, it reads "Hi there,".
8. Press **Save draft**.

> 💡 Your agency logo, name, support email and address appear at the top automatically when your plan includes white-label branding. The footer shows your **Privacy policy** and **Terms & conditions** links from your agency branding settings.

## Previewing and sending

1. Press **👁 Preview** to see the campaign exactly as families will get it, with a sample first name. Always preview before sending.
2. To send later, fill in **Schedule** with a date and time. Leave it blank to send by hand.
3. Press **Send now** and confirm. News posts appear at once; emails go out a few at a time.

Sent campaigns are read-only. To check one without changing it, open its row's **⋮** and choose **View** — View never sends anything.

### Who receives a campaign

Every parent or guardian of a family still with you, **including parents who have not accepted their app invite yet**.
It does not go to families that have left or are suspended, to closed accounts, or to anyone with no email on file,
and each address gets one copy.

## Seeing who got it: Recipients & stats

1. Open the sent campaign's **⋮** and choose **Recipients & stats**. A full-window page opens: "Who this campaign went to, and what happened."
2. Read the tiles, then the table: **Family**, **Email**, **Status**, **Details**, **Actions**.
3. Filter by status with the chips: **Sent** (our mail service accepted it and no bounce came back), **Opened**, **Bounced** (their mail server sent it back, with the reason in plain words), **Failed** (it could not be handed over), **Held back** (another email rule stopped it, for example they turned these emails off or the account is closed), **Waiting** (not sent yet) and **Not known**.
4. Search for a person, or export the list to CSV, Excel or PDF from the bar under the table.
5. Press **View the email they got** on a row to see the exact copy that person received, or **Copy email address**.
6. Press **All campaigns** to go back.

> ℹ️ What it cannot tell you: mail services send no delivery receipts, so "Sent" is the closest there is to "delivered". Opens count when the email's images load, so someone who blocks images shows as not opened. Link clicks are not tracked. If the campaign also posted to News, you see how many have opened the post and read the in-app notice.

## Resending to people whose email bounced

1. Correct the person's email on their record first (**Open their record** in the list takes you there).
2. Press **↻ Resend to bounced** — above the status filter, or in the campaign's **⋮**.
3. Each bounced person is listed with **Bounced at**, **Current email** and a **State**: **Address corrected — ready to resend**, **Same address — fix it first**, **No email on file** or **Account deactivated**.
4. Only ready people can be ticked; they start ticked (**Tick all ready** ticks them again).
5. Press **↻ Resend** and confirm the number. It goes to their corrected address, once each. This cannot be undone.
6. A **Campaign resent** toast appears and the person shows as sent, with the bounced address in **Details**. If many were ticked, press **Resend again** — it sends up to 80 at a time.

It is never sent twice to an address that already got it, and the usual email rules still apply. The audit log names every address it went to.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Write, send, view stats and resend for every centre. |
| Centre director | The same for their own centre(s); sees only their centres' campaigns. |
| Platform admin | The agency chosen in the agency switcher. |

Someone who can see a campaign but not send it sees: "You can see this list, but only someone who can send this campaign can resend it."

## FAQs and when something goes wrong

??? Recipients & stats is not in the ⋮ menu. | It appears once something has gone out. Drafts and scheduled campaigns have no recipients yet.
??? Resend to bounced says nobody is ready. | "Nobody is ready yet: correct their email on their record first." Fix the address, then open it again.
??? Some people show "Not known". | Campaigns sent before 27 November 2026 were rebuilt from the email log, so a few people may have no record.
??? A parent says they never got it. | Check their row: **Held back** gives the rule that stopped it; **Bounced** gives the reason. If it says **Sent**, ask them to check spam.
??? Can I delete a campaign? | Yes, from **⋮ > Delete** ("Delete this campaign? This cannot be undone."). Emails already sent are not recalled.

## Related screens

[[open: #tours | Tours]] [[open: #audit-logs | Audit log]]

See also [[drip-campaigns]] and [[email-templates]].
