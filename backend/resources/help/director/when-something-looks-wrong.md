---
title: When something looks wrong
category: Troubleshooting
order: 1
---

# When something looks wrong

A quick guide to the most common "huh, that's weird" moments.

## "Staff on floor is 0 but my educators are here"

Your educators aren't clocked in. They need to:

1. Open Kiddietrac on the tablet
2. From the room view, tap their name or the **Clock in** button

Or you can clock them in from your director account on their behalf — go to **Staff → [Educator name] → Clock in**.

## "A room shows BREACH but my numbers look fine"

Check who's actually clocked in. Look at **Staff → Schedule** for today. If you're seeing BREACH:

- The ratio target might be too strict (you can adjust the room's ratio if it doesn't match Ontario standards)
- An educator might be assigned to a different room via shifts
- Children might be checked in to a room their educator isn't in

## "I added a child but they're not showing in the room"

A few things to check:

- Is the child's **enrollment status** "enrolled"? (Not "waitlist" or "withdrawn")
- Is the **start date** today or earlier? (Future start dates won't show until that date)
- Is the **room** correct? Look at the child's profile.

## "A parent says they don't see their child"

Check that:

- The parent is linked to the right family
- The child is enrolled in that same family
- The parent's account status is **active** (not **invited** — they need to have logged in once)

You can verify in **Families → [family name]** — the child should appear in the family, and the parent should appear in the guardian list.

## "I generated invoices but a family didn't get one"

Either:

- They have no enrolled children with monthly fees set — check that the enrollment has a monthly_fee
- An invoice already exists for this month — check **Families → Invoices** filtered by family
- Email didn't go through — check your SMTP setup and the family's email

## "The AI digest is showing 'Loading...' for hours"

The digest is only generated after 4 PM. Before 4 PM, parents will see a message saying "Daily digest will be available after 4 PM." This is intentional — we want enough of the day captured before generating.

If it's been past 4 PM and still loading, the Anthropic API might be having an outage. Check `https://status.anthropic.com`. The system falls back to a template-based digest if the API is unreachable.

## "A photo I uploaded isn't showing"

- Wait 30 seconds — uploaded photos take a few moments to process
- Hard refresh the parent's app: **Ctrl+Shift+R** on desktop
- Check that the photo was tagged to the right child(ren) when uploaded
- The file might be too large (>8 MB) or in an unsupported format

## "An educator can't log in"

Their account might be in **invited** status, meaning they were sent an invite but haven't completed first login. Have them:

1. Find the original welcome email
2. Use the temporary password from that email
3. Log in once successfully — this activates their account
4. Change their password right away

If they lost the welcome email, you can reset their password (see **Resetting a user's password**).

## When in doubt

Click **Ask** at the top of any help page and describe what's happening. The AI assistant has access to all this documentation and can give you a more specific answer.

If the AI doesn't know, email support@kiddietrac.com with:

- What you were doing
- What you expected to happen
- What actually happened
- A screenshot if possible
