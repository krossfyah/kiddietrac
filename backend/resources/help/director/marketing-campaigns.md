---
title: Marketing campaigns
category: Daily Operations
order: 70
---
# Marketing campaigns

Directors and agency admins can compose rich messages — newsletters, open-house invites, promotions — and send them to a chosen audience straight from Kiddietrac. Find it under **Growth → Marketing** in the sidebar.

## Composing a campaign

Click **+ New campaign**. You'll see one panel with everything you need:

- **Campaign title** — internal label only. Families don't see this; it's how you find the draft later.
- **Email subject** — used when the campaign goes by email. Keep it under 60 characters for mobile readability.
- **Audience** — who receives it:
  - *All families* — every family attached to your centre (or every centre in your agency, if you're an agency admin).
  - *Currently enrolled* — only families with at least one enrolled child.
  - *Waitlist* — families with waitlisted children.
  - *Prospects* — families without any enrolled children yet.
  - *Staff only* — directors + educators at your scope.
- **Channel** — *In-portal* writes to the families' announcements feed, no email sent. *Email* queues to send via your configured mail driver (when v22p35 ships). *Both* does both.
- **Hero image** — optional banner image (jpg / png / webp / gif, max 5 MB). Renders above the body in the announcement feed and in emails.
- **Body** — a rich-text editor with the usual tools:
  - **B / I / U** — bold, italic, underline
  - **H1 / H2 / P** — heading levels and paragraph
  - **List** buttons — bulleted and numbered lists
  - **Block quote**
  - **Link** — wraps the selection in an `<a href>`
  - **Image** — uploads an inline image from your device and inserts it into the body
  - **Undo / Redo / clear** — standard editing
- **Schedule** — leave blank to send manually with **Send now**. Set a date+time to queue (the scheduler ships in v22p35; until then, scheduled campaigns sit as `scheduled` until you open them and press Send).

## Sending

Two buttons:

- **Save draft** — saves the campaign in `draft` status (or `scheduled` if you set a schedule). You can come back and edit it any time.
- **Send now** — saves, then immediately sends to the resolved audience. Asks for confirmation first.

After sending, the campaign moves to `sent` status and shows the recipient count + send timestamp on the list view. Sent campaigns are read-only — you can't edit a campaign that's already gone out (you can compose a new one).

## What's saved, what's logged

Every action writes to `audit_logs` and the campaign row stores the recipient count + delivery count. Open + click tracking will be added when the email channel goes live.

## Security

The body editor lets you paste HTML, but the backend strips anything dangerous (`<script>`, `<iframe>`, inline `on…` handlers, `javascript:` links) before saving — so a campaign can't run code in a recipient's portal.
