---
title: Missed-message email notifications
category: Settings
order: 80
---
# Missed-message email notifications

If a chat message sits unread for more than **30 minutes**, Kiddietrac sends the recipient an email reminder so they don't miss something important. The email arrives with your agency's branding — colours, logo, support address — and a one-tap link back into the conversation.

## How it works

Every 15 minutes, a scheduled task scans for unread messages older than the delay. For each one, it looks at who's part of the conversation:

- **Parents → staff**: when a guardian sends a message, everyone with access to the conversation gets the nudge — centre directors, agency admins, educators at the centre.
- **Staff → parents**: when a director or educator replies, the family's guardians get the nudge.

Multiple unread messages in the same thread are bundled into one email. So if three messages came in over an hour, the recipient gets a single tidy summary, not three separate emails.

## What the email shows

Each unread message renders as a card with:
- Sender's name
- Time stamp (relative — "12 minutes ago")
- Truncated body (first 320 characters)
- Attachment count if any

A bright button labelled **"Open conversation"** at the bottom deep-links straight to `#chat` in the portal.

## Why you stop getting reminders

Once a message has been emailed, that message is **marked as notified** in the database (`email_notified_at` timestamp). The scheduler won't re-email the same message even if it stays unread. So:

- Reading the message in the portal → no future email about it (read_at stamps).
- Replying → same effect (read_at stamps on the recipient's side when they open the thread).
- Ignoring → one email is sent, and that's it. The thread stays in your unread list, but the email won't repeat.

## Who's exempt

- Users without an email address on file are skipped.
- Soft-deleted users are skipped.
- Senders never get a nudge about their own messages.
- Users who have already read the message are skipped (the `read_at` stamp lands when the chat panel renders the message).

## Per-agency branding

The email goes through the **same per-agency SMTP** used by daily/weekly digests:
- If you've set up SMTP host/user/password under **Agency edit → Email settings**, the message comes from your domain via your mailbox.
- Otherwise it falls back to the platform default (`MAIL_*` in the server `.env`).
- White-label tenants get clean tenant-branded mail — no "Powered by Kiddietrac" footer.

## Testing

From SSH:

    php artisan kiddietrac:chat-emails --dry-run

Prints what would be sent without actually sending. Useful before configuring SMTP for the first time so you can confirm the audience resolution looks right.

    php artisan kiddietrac:chat-emails --dry-run --delay=0

Same, but ignores the 30-minute delay so every unread message currently in the system gets evaluated. Good for verifying your join logic returns the right recipients.

    php artisan kiddietrac:chat-emails

Real send. Use cautiously — every queued message gets emailed.

## Customising the delay

The default 30 minutes balances "don't spam people" against "don't miss anything urgent". If you want a different cadence:

- **Permanently**: pass `--delay=N` on the artisan call from a custom cron entry.
- **Per agency**: this is a v22p41+ candidate — open a support ticket if you want a different threshold than 30 minutes.

## Audit trail

Each emailing run writes an `audit_logs` entry with `action='chat.email_notified'`, the count sent, and the message ids touched. You can review these from the **Audit log** screen in the Administration menu.
