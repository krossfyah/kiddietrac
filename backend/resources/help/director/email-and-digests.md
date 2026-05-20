---
title: Email settings and digests
category: Settings
order: 65
---
# Email settings and digests

Every customer agency on Kiddietrac can use its own outgoing email so that **digests, marketing campaigns, invoices, password resets and welcome emails arrive from your domain** — not the Kiddietrac platform address. Find the settings in the Agency edit modal under **All agencies → Edit → Email settings**.

## SMTP fields

| Field | What to put | Notes |
|---|---|---|
| SMTP host | `smtp.gmail.com` / `smtp.office365.com` / `mail.yourcentre.com` | Your provider's outgoing host |
| Port | `587` (TLS) / `465` (SSL) / `25` (none) | Match your encryption choice |
| Username | `noreply@yourcentre.com` | The full mailbox login |
| Password | mailbox password or app token | We store this encrypted; leave blank when editing to keep the existing one |
| Encryption | TLS / SSL / None | TLS (port 587) is the safe default |
| From address | `noreply@yourcentre.com` | What recipients see in the From header |
| From name | "Sunshine Childcare" | Friendly name beside the from address |

**Leave any of these blank to fall back to the platform default** (`MAIL_*` in our `.env`). This is the right setting if you're a sub-tenant that hasn't bought white-label.

**Gmail / Workspace** users — generate an *app password* (not your account password). 2-step verification must be on first. Same for Outlook.

**GoDaddy / cPanel** mailboxes — host is usually `mail.<your-domain>`, port 465 with SSL.

## Daily digest

Every morning at **7:00 AM Toronto time**, directors and agency admins get a daily summary email per agency they belong to. Contents:

- Signed-in count today vs total enrolled (with % present)
- Open invoice balance + count
- Meds given so far today
- Observations logged yesterday
- A warning banner if any staff haven't enrolled in MFA

The digest goes via that agency's SMTP if configured, otherwise via the platform default. Suspended agencies are skipped.

## Weekly digest

Every Monday at **7:05 AM Toronto time**, directors and agency admins get a weekly summary covering the **prior week** (Mon–Sun). Contents:

- Net enrolment change (new vs withdrawn)
- Total billed last week + paid-so-far
- Observations logged
- Incidents recorded

## Testing your config

Two ways to sanity-check your settings:

1. **Dry-run from SSH** — `php artisan kiddietrac:digest-daily --dry-run` prints what the email would say and to whom, without sending anything.
2. **Real-run from SSH** — `php artisan kiddietrac:digest-daily` sends to all directors/admins. Use cautiously.

## Cron status

The scheduler runs from a server-level cron entry that fires `php artisan schedule:run` every minute. The actual digest commands only run at their declared times (07:00 daily, Monday 07:05). Output is written to `backend/storage/logs/scheduler.log` on the server. If you ever see "no scheduled commands ready", that's normal — most minutes there's nothing due.

## Privacy

SMTP passwords are stored as plain text in the database (encrypted at rest by GoDaddy's disk-level encryption). They are never returned in API responses — only an `email_smtp_pass_set: true` flag — so a leaked dump of `/platform/agencies` won't expose them. If you suspect a password is compromised, change it in the Email settings modal (or rotate the mailbox itself).
