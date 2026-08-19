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

## A handler can outlive its screen

An idle sign-out redirects to the login page. A click already in flight still runs its
handler, but every `getElementById` on that screen now returns null — so
`el.hidden = x` throws "Cannot set properties of null".

Resolve the elements a handler needs at the TOP of the handler and bail if any are
missing. Do not assume the screen that painted the control is still mounted when the
control is used. This has now been the cause of two filed crash tickets.

## A date is not a timestamp

`new Date("2026-07-27")` parses a bare ISO date as **UTC midnight**. Rendered in any
western timezone that is the 26th. This has now shipped as a visible off-by-one twice
in one day: closure ranges on the calendar and on the closures screen.

A value with no time in it denotes a calendar DAY and has no timezone to convert
between — format it from its own `YYYY-MM-DD` parts. Only values carrying an actual
time go through the agency timezone. Ten screens still build dates with
`new Date(x + "T00:00:00")`; treat each as suspect.

## A hosted screen must not bring its own page furniture

A screen written as a standalone page renders a hero banner, and often its own tab bar.
Host that screen inside a tab and the page shows two banners and two rows of tabs.

Host the PANES, not the page: expose each pane as its own render function and let the host
place them as siblings of its existing tabs. The host in screen-billing-settings.js also
strips `.kt-hero` / `.kt-page-hero` from anything it renders, which caught a second
instance (screen-fee-plans) nobody had reported.

Check afterwards that the hero did not carry the screen only action button.

## Tables: use the house pattern, do not rebuild it

A table looks like the rest of the portal when it is:

  main.setAttribute("data-kt-pretty", "1")      // 27 screens do this
  <div class="kt-card"><table>…</table></div>   // 25 screens do this
  a PLAIN <table> — no inline width, no table-layout, no colgroup

That gets you the card, sortable headers, the filter box, the export bar and the row count
for free, because kt-list-controls / kt-table-export / kt-row-actions all key off them.

Hand-tuning column widths to "match" the others is the wrong instinct and was done twice on
the subscribers table before the pattern was checked. If a table looks out of place, the
question is which of the three lines above is missing.

## `data-kt-list` brings two primitives, not one

Marking a container `data-kt-list` gets it the ⋮ kebab from `kt-row-actions.js` — and
also a search box + A–Z sort bar from `kt-list-controls.js`. On a screen that already
has a filter or toolbar of its own that is a second bar of furniture nobody asked for.
Add `data-kt-no-controls` alongside (notifications does; it already has a filter
dropdown and "Mark all read").

Inside the kebab menu, a button's **text is the menu item** — a bare glyph (`🗑`)
becomes a blank row. Label them (`🗑 Delete`); words matching the DESTRUCTIVE pattern
pick up the red styling for free.

## An event on a calendar should open

Anything drawn on the calendar grid is expected to open when clicked, the way Outlook
does. A `title` attribute is a hover convenience, not a way in: it is unreachable on a
touch screen. When wiring one up, stop `mousedown` as well as `click` — the month grid
drag-selects from mousedown and the day cell opens the new-shift form on click, so a
chip that only stops `click` still fires two things at once.

Edit the record where the screen owns it. The calendar owns closures, so it edits them
in place; time off, vacation holds and absences get an "Open <screen>" button instead.
A second editor for someone else's record only drifts from the real one.
