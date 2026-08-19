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

## Marking read, and unread

Three ways:

- **Click any row** — flips it to read on the way to whatever it points at
- **The ⋮ menu on the row** — **Mark read** or **Mark unread**, plus **Delete**
- **Mark all read** — asks first, then clears every unread row

Marking something **unread** is the way back if you opened a notification by accident.
Until recently there was none: a row tapped by mistake dropped out of the unread filter
and the bell count for good.

## Deleting

Use **Delete** in the row's ⋮ menu. It asks first, because deleting a notification
cannot be undone. To clear several at once, use **Select** and tick the rows.

## Deep links

Notifications carry a `data.url` so clicking a chat notification lands you on `#chat`, an invoice on `#billing`, a tour on `#tours`, etc.

## Visual signals

- **Blue dot** on the left edge = unread
- **Light blue background** = unread
- **Emoji icon per type**: 💬 chat, 🧾 invoice, ✅ payment, 📢 announcement, 📅 digest, 📣 marketing, 🚪 tour, 📝 form
