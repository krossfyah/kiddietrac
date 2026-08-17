# Front-end conventions

Platform-wide behaviours that are already handled for you. Each one here has been
re-implemented by hand at least once and broke something; read this before adding a
row action, an avatar, a tooltip or a date to any screen.

---

## Row actions — never build a kebab

**`kt-row-actions.js` already does it.** On every data table, on every render, it takes
the action buttons in the **last `<td>`** and collapses them into a single `⋮` kebab
(desktop/tablet ≥601px; phones keep the plain buttons, since `kt-mobile-tables.js` cards
tables at ≤600px).

So:

```js
// RIGHT — plain buttons. The platform collapses them.
`<td style="text-align:center;white-space:nowrap;">
   <button class="x-open" data-id="${r.id}" type="button" title="Open">Open</button>
   <button class="x-del"  data-id="${r.id}" type="button" title="Delete">Delete</button>
 </td>`
```

```js
// WRONG — this gets kebabified too, producing a kebab inside a kebab.
`<td><button class="my-kebab">⋮</button></td>`
```

It forwards a **real** click to the original (hidden) button, so handlers wired directly
on the button *and* delegation on a parent container both keep working. Destructive
labels (`delete`, `remove`, `archive`, `revoke`, `decline`, …) are auto-styled red.

**A hand-rolled menu is only correct where no table exists** — e.g. a card list on
mobile, which nothing collapses. Say so in a comment when you do it, and make it the
only one on the screen.

---

## Avatars — use `KT.avatar`

```js
KT.avatar('Jane Doe', { size: 32, photoUrl: absPhotoUrl(u.photo_url), sex: u.sex, userId: u.id })
```

Returns an HTML string: the real photo when there is one, a sex-based silhouette when
`sex` is known, else initials in a deterministic colour. Passing `userId` lets the global
zoom lightbox name the person and link to their record.

Two things that keep going wrong:

- **Relative paths must be absolutised.** Photos come back as `/storage/avatars/….jpg`,
  which resolve against the SPA host, not the API host. Without `absPhotoUrl()` (or the
  screen's local equivalent) every photo 404s and silently falls back to initials — which
  looks exactly like "no photo on file".
- **Don't hand-roll the initial circle.** If you find yourself writing
  `background:${senderColor(nm)}` you have bypassed photos entirely for that row.

---

## Dates and times — agency timezone, always

Every displayed date/time is in the **agency's** timezone, never UTC and never the
device's. Use `KT.fmtDateTime()` / `KT.agencyToday()` / `KT.agencyDateOf()` from
`kt-tz.js`; server-side use `AgencyTime::tzForCentre()` and `AgencyTime::fmt()`.

The exception, and it matters: **shift start/end times are stored as local wall-clock**
(`08:00`–`16:30`). Those must **not** be timezone-converted — doing so turns an 8am shift
into midday.

---

## Tooltips and icon buttons

One global engine (`kt-tooltips.js`), body-appended. Add `title="…"` and stop.
`kt-icon-buttons.js` turns labelled buttons into icons automatically by matching the
label — don't pre-iconise.

---

## Lists and counts

`data-kt-list="1"` marks a **card list** for the bottom count bar. Never put it on a
`<table>` — tables are counted by the table primitive, and doubling up counts rows twice.
Never leave it on a container holding only an empty-state placeholder: the placeholder
gets counted as one record, so the screen reads "1 record" while showing nothing.

---

## Where patched code LANDS is not where it belongs

Two crashes in one week came from this, so it earns its own section.

An anchor-based edit inserts text **where the anchor is**. If that anchor sits inside a
function body, everything you insert is silently nested in that function:

```js
function renderGrid() {
  ...
  function toHHMM(raw) { ... }   // <- nested, invisible outside renderGrid
  ...
}
function activityCard() {
  toHHMM(a.time);                // ReferenceError: toHHMM is not defined
}
```

Function declarations hoist **within their scope and only within it**. A sibling function
cannot see them. The same trap in reverse: a `var` read by a hoisted function before the
assignment line runs gives `undefined`, not an error at the definition site — which is
how `ROLE_ORDER` produced `undefined['Parent']`.

After any insertion, check the *brace nesting* of the anchor, not its indentation —
indentation is cosmetic and will look perfectly correct while the scope is wrong.

## Popups appended to `<body>`

Any menu or dialog moved to `document.body` is **outside** the screen's container, so
`container.querySelector('#thing')` will no longer find its own elements. Query from the
dialog root, not the screen root. This has silently broken dialogs more than once.

---

## Service worker

Bump the `CACHE` constant in `service-worker.js` on **every** deploy, and the `?v=` on
each changed file in `dashboard.src.html`, then `cp dashboard.src.html dashboard.html`.
Skipping it is the single most common cause of "you didn't fix it" — the client is still
running the old file.
