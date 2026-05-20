---
title: Notifications inbox
category: Settings
order: 67
---
# Notifications inbox

Every alert from Kiddietrac for your account in one place. Find it under **Settings → 🔔 Notifications** in the sidebar (any role).

## What appears here

Anything that fires `DB::table('notifications')->insert()` server-side:

- New chat messages (when you missed the OS push)
- Marketing campaigns sent to you
- Payments recorded against your family's invoices
- Late fees added
- Tour bookings (director / admin view)
- Digest send acknowledgements

## Filter

Three options at the top:

- **All** — everything, oldest read items mixed with unread
- **Unread only** — what still needs attention
- **Read only** — historical view

## Marking read

Two ways:

- **Click any row** — flips that one to read (optimistic, instant UI; reverts only if the API call fails)
- **Mark all read** — prompts for confirmation, then PATCHes every unread row sequentially

## Deep links

Notifications carry a `data.url` so clicking a chat notification lands you on `#chat`, an invoice on `#billing`, a tour on `#tours`, etc.

## Visual signals

- **Blue dot** on the left edge = unread
- **Light blue background** = unread
- **Emoji icon per type**: 💬 chat, 🧾 invoice, ✅ payment, 📢 announcement, 📅 digest, 📣 marketing, 🚪 tour, 📝 form
