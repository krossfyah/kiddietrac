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

## A grid item never shrinks below its content unless you say so

`min-width` defaults to `auto` on a grid (and flex) item, which means "never narrower
than my content". So a `1fr` track does not resolve to the space available — it resolves
to whatever the widest thing inside needs. One wide screen then widens the whole page.

This is what made the entire portal scroll sideways: `.app-shell` is `232px 1fr`, and on
the calendar the second track resolved to 1788px inside a 1905px viewport, giving a
2020px document. The same fault sat one level down in the calendar's own
`repeat(7, 1fr)`, which measured 1372px inside a 1279px card and got clipped.

Two rules, and you generally want both:

- `min-width: 0` on the grid/flex item
- `minmax(0, 1fr)` instead of a bare `1fr` on the track

Then content that is genuinely too wide has to handle its own overflow — which is where
a scrollbar belongs, on the table, not on the page.

To find it: compare `document.documentElement.scrollWidth` with `clientWidth`, then walk
`#appMain` for elements whose `scrollWidth` exceeds `clientWidth` with no scrollable
ancestor. Two false positives to expect — `.kt-card::after` is a decorative blob pinned
at `right:-120px` and clipped by the card, so every card reads as a 120px overshoot; and
map tiles are meant to exceed their container.

## Not every table is a list of records

The global helpers attach to any `<table>` on a screen: a filter box, sortable headers, a
bulk-select checkbox column. Some tables are a **layout** — the weekly menu is five meals
by five days of inputs. There is no bulk action for "breakfast and lunch", and filtering
a fixed list of five can only hide something you wanted.

Opt out with `data-kt-no-filter` and `data-kt-no-bulk`. Note that a `data-kt-*` guard set
by a primitive on itself (`dataset.ktBulk`) is an idempotency marker, not an opt-out —
don't set those by hand; add a named `data-kt-no-*` instead.

## Design tokens live in ONE file

`css/kt-tokens.css` is the only `:root` in the portal. Add a token there.

Nine stylesheets used to define `:root`, so the value a token actually carried was
decided by `<link>` order in `dashboard.html` rather than by anyone's decision.
Seven tokens genuinely disagreed between files — `--kt-danger` was `#D85A6C` in
one and `#EF4444` in another, and a third file settled it. The practical cost was
not the duplication: it was that **editing the copy that did not win changed
nothing on screen**, so a fix looked applied and did nothing.

Consolidated 2026-09-01, every value kept exactly as it already computed, so the
change was invisible.

`css/styles.css` keeps its own `:root` **on purpose**: `index.html` and
`enroll.html` do not load `kt-tokens.css`, so it is the only token set the login
and enrolment pages get (with `kt-login-v22.css` / `kt-login-redesign.css`).
That is a separate page family, not a split — which is why the login page and the
portal can legitimately differ.

## `!important` must not reach a switch

A switch is a `<button role="switch">` or a checkbox styled as one, and it gets
its look from inline styles. `!important` outranks inline, so a bare

```css
button { border-radius: 6px !important; height: 38px !important; }
```

silently squares off or stretches every toggle in the portal. This is the
cosmetic bug that came back four separate times; each fix was correct and each
one was later undone by a new generic rule written elsewhere.

Scope generic rules away from switches:

```css
button:not([role="switch"]):not([aria-pressed]) { ... }
```

## Both rules are enforced

```
php artisan css:tokens --check
```

Fails the build if a second stylesheet defines `:root`, if a token is referenced
with neither a definition nor a fallback (`var(--x)` with no fallback resolves to
`inherit` — usually invisible rather than obviously wrong; the 2026-07-24 audit
found ~350 of these), or if an unscoped `!important` rule can reach a switch.

Run it after touching CSS. Bare `php artisan css:tokens` prints the same report
without failing.

## A sweep must never replace a container it did not create

Sweeps (`KT.sweepBus` callbacks in `kt-polish*.js`, `kt-table-filter.js`) run on every
DOM settle, including **while a screen is awaiting its data**. Decorating what is
already there is safe. Replacing a container is not.

Screens capture the node they will fill *before* they await:

```js
const content = Dom.el('div', { id: 'admin-tab-content' });
wrap.appendChild(content);
await renderFamiliesTab(content);   // appends "Loading…", awaits two API calls
```

If a sweep does `main.innerHTML = …` during that await, `content` is detached. The
fetch resolves, the screen appends its rows into an orphan node — **no error is
thrown**, because appending to a detached element is perfectly legal — and the user
is left looking at whatever the sweep put there, permanently.

That was the stuck loading skeleton on `#admin-families` (2026-09-01). It was
intermittent for two compounding reasons: `data-kt-pretty` is only set 250ms after
navigation, so a fast API reply beat the sweep; and the "already done" flag was
stamped on `#appMain`, which survives every re-render, so it could fire at most once
per page load.

Rules:

- Write only to elements **your own sweep created**, or to the specific element you
  matched — never to its ancestors.
- Prefer decorating in place (add a class, set an attribute) over replacing markup.
- Idempotency flags go on the element you changed, not on `#appMain` — a flag on the
  shell outlives every screen that follows it.
- A teardown is only acceptable if something is guaranteed to repaint afterwards.
  `kt-auto-refresh` may tear a screen down mid-load because it immediately calls
  `renderScreen()`; a cosmetic sweep has no such follow-up, so it must not.

## A server-paged table gets ONE pager: the screen's (2026-09-28)

A screen that pages on the server (fetches `per_page` rows and draws `KT.pagerBar`)
must not be paged again by `kt-table-filter.js`. It was: Contacts loads 50 rows a page,
the filter saw 50 > 25 and drew a second pager, and added a "Filter 50 rows" box beside
the screen's real search, a box that could only see the one server page.

`kt-table-filter` now treats a table as server-paged when it carries
`data-kt-server-paged`, or when a screen's own `.kt-pager-bar` sits beside it (the
nearest table before the bar). On such a table it keeps sorting and drops its pager,
search box and count. You get that for free by drawing your pager with `KT.pagerBar`
next to the table. Mark the table `data-kt-server-paged` if your pager lives somewhere
else.

A screen that searches or filters its own table client-side (Attendance pattern's grid)
takes `data-kt-no-filter` or `data-kt-no-search`, or it gets a second search box.
`data-kt-list` on a card container also brings a "Filter N items" bar. Add
`data-kt-no-controls` when the screen has its own search (Bulk invoices).

## A card's own ⋮ uses KT.openActionMenu, never an absolute menu (2026-09-28)

A hand-rolled ⋮ on a CARD is allowed (no table, so kt-row-actions cannot build it). A
hand-rolled MENU is not. It was `position:absolute` inside `.centre-card-v17`, which is
`overflow:hidden`, so it opened as a sliver clipped by the card: "kebab not showing up
clearly". Call `KT.openActionMenu(anchorBtn, actionButtons)` (kt-row-actions.js). The
buttons may be detached, and each one's click is what runs. The menu draws on `<body>`
at `position:fixed`, flips above near the bottom, and closes on outside click, scroll
and Escape. Give the anchor class `kt-ka-kebab` so it looks and behaves like every
other ⋮.

## Viewing a file is KT.openFile, never window.open (2026-10-09)

Anthony: "when viewing file in file repository it should be a popup and not a new window".
Every "View" of a file goes through `KT.openFile(src, { title, filename, label, mime })`
in kt-doc-viewer.js. `src` can be a URL, a Blob, a Promise of either, or a function that
returns one. It opens the in-portal panel: an iframe on desktop, pdf.js in the app, and
`<img>` for images. The header has Download, Print and ✕. Word, Excel, CSV and zip files
get a card with Download and "Open in a new tab" ("Open with an app" in the APK).
For a protected file, `KT.fetchFileBlob(url)` adds the token and X-Active-Agency-Id.
Do not pre-open a blank window before an await. A new window is right only for real
navigation: OAuth, checkout, app store, another site's page, an explicit
"Open in new tab" escape hatch, or a fallback when KT.openFile is missing.
A Download button downloads (`a[download]`); it does not window.open.

## Photo sets vs single files (2026-10-10)

- **Photo sets → the gallery (`KT.mediaViewer`).** Anywhere several photos/videos are shown
  together (Daily Overview, Live feed, child photo tabs, chat threads, portfolios), opening
  one opens the gallery over the whole set: ‹ › arrows, ←/→ keys, swipe and an "n / N"
  counter. Either call `KT.mediaViewer.open(items, index)` / `fromElements(...)`, or mark the
  images `<img data-kt-media-group="<set>" data-kt-media-src="<full url>">` and the delegate
  in kt-media-viewer.js does it. A link wrapping a photo in a set carries `data-kt-gallery`.
- **Single files → `KT.openFile`.** A document or a lone file opens in the document panel.
- kt-doc-viewer's click delegate leaves alone any click already handled (`defaultPrevented`)
  and any link inside a photo set; never remove that, or galleries lose next/previous.

## Phone width: one gutter, the shell's (2026-10-10)

On a phone (≤768px) the only side gutter is `.app-main`'s 10px. A screen must not add its own
page padding or `max-width` on phones — cards fill the width (370px of a 390px screen).
`js/kt-phone-gutter.js` enforces this by what an element IS: transparent wrappers in the top two
levels of `#appMain` (no background, border or shadow) lose side padding, side margins and
max-width on phones; cards keep theirs. Grids or flex rows that run past the screen edge (outside
an `overflow-x` scroller) are made to fit. Desktop is untouched; widening past 768px restores
everything.
- Opt a wrapper out: `data-kt-keep-gutter`. Opt a deliberately wide block out: `data-kt-allow-overflow`
  (or put it in an `overflow-x:auto` scroller, as wide tables already are).
- Debug: `KT.phoneGutter.report()`; switch off with `window.__ktNoPhoneGutter = true`.

## Dates and "today" are the AGENCY's (2026-10-21)

The server clock is UTC and must stay UTC (stored instants and kt-tz.js assume it); MariaDB's
`NOW()`/`CURDATE()` run on the host's MST. So from 8pm Toronto, anything that asks the server
or the browser for "today" in UTC gets TOMORROW (the Upcoming birthdays bug).

**Server (PHP).** Every API request carries its agency's timezone (middleware
`ResolveAgencyTimezone`, using AuditScope's rules). Never `now()->toDateString()`, `today()`,
`Carbon::today()`, `date('Y-m-d')`, `now()->startOfDay()` or SQL `NOW()/CURDATE()` for a day.
Use `App\Support\AgencyTime`:
- `today()`: the agency's date `'Y-m-d'` (compare with DATE columns; default form values).
- `todayDate()`: that date as a Carbon at 00:00 UTC, for date arithmetic
  (`->addDays()`, `->startOfMonth()`, `->diffInDays($dueDate)`).
- `boundaryUtc($agency, 'day'|'week'|'month', $offsetDays)`: the agency's midnight / week / month
  start as a UTC instant, for comparing with stored instants (`occurred_at`, `created_at`).
- `dayRange()` / `scopeDay()` / `spanRange()`: the half-open UTC bounds of an agency day/range.
- `now()->format('g:i A')`, shown to people, is `AgencyTime::now()->format(...)`.
With no agency id they use the request's agency; console jobs pass the agency they loop over.
`php artisan kt:tz-check` fails on any new UTC "today". Add `// tz-ok: why` only where UTC is
the point (a stored instant, a dedupe key).

**Browser (JS).** `new Date().toISOString().slice(0, 10)` is the UTC date: use
`KT.agencyToday()`. Step dates in whole UTC days from a `'YYYY-MM-DDT00:00:00Z'` anchor, and show
date-only values with `KT.dayLabel()`.

## Subtabs re-render the tab body, never the screen (2026-10-21)

A screen's banner (`.kt-hero`, a screen's own `.kt-page-hero`, `[data-kt-hero]`) and its tab
strip are drawn ONCE per screen visit. A subtab click renders into the tab's own body element
(`pane.innerHTML = …` / `Dom.clear(pane)`), never `main.innerHTML = …`, and never re-runs the
whole screen's render unless that render draws the banner again. The shell only guarantees a
banner for ~9s after a navigation; in-place re-renders after that are on the screen.
- Safety net, not a licence: `kt-banner-normalize.js` `keepHero()` puts the same page-level banner
  back (same render generation + address only, rate-capped, before paint) if an in-place
  re-render leaves the page with none, and steps aside if the screen draws its own again. Opt
  out with `data-kt-hero-off` anywhere in the screen.
- Hash subtabs (`#screen/tab`) go through `renderScreen`; putting back a missing banner there is
  rate-capped (3 in 2s), not charged to the per-screen normalising budget.
- Test a tabbed screen AFTER the 9s window: open it, wait 10s, then click every tab and check the
  banner is still first under the top bar, once.

## Every popup can be closed with ✕ or Escape (2026-10-21)

Anthony: "all popup windows should have an X to close where appropriate". `kt-modal-guard.js`
does this for every dialog without the screen opting in — do not hand-roll a second ✕.
- It adds a ✕ (top-right, sticky so it stays visible while the card scrolls) to any full-screen
  overlay whose card has no ✕/×, `aria-label="Close"` or `.modal-close` of its own. A bottom
  "Cancel" does not count as a ✕; the guard's ✕ presses that Cancel/Close, so your clean-up runs.
- Escape closes the dialog on top; a click on the backdrop closes it only when it has no fields.
- Popups inside `#appMain` are found too (inline `position:fixed`), not only ones on `<body>`.
- **Mandatory steps must opt out** with `data-kt-no-close` (or `data-kt-keep-open`) on the overlay:
  gates, required wizards, anything the user must answer. Already excluded by id: `#kt-mfa-gate`,
  `#kt-agree`, `#kt-bio-lock`, `#kt-session-warning`, `#kt-onboarding-overlay`,
  `#kt-onboarding-catchup`, `#kt-urgent`, `#kt-upd`, `#kt-splash`. `KT.confirm`/`KT.prompt`
  (marked by `[data-action="cancel"]`) get Escape but no ✕.
- `data-no-modal-guard` keeps your own layout (no size cap, no z-index lift) and only gets a ✕
  when its sole way out is a plain Cancel/Close button.

## One banner per screen: the shell decides (2026-10-21)

Security alerts showed two hero banners after a background refresh. A screen gets exactly one banner:
- its own `.kt-hero`;
- or a `.kt-page-hero` that LEADS the page (first content slots, top few levels; a `.kt-page-hero` deeper in is a section header);
- or, if it has neither, the shell's automatic `.kt-hero-auto`.

`ownBanner()` in app-v2-shell.js is the only definition. Both the insert check and `normaliseBanners()` use it. Never write a second "does this screen have a banner" test: two tests that disagree make the shell add and remove its banner on every tick, burn the per-screen budget, and leave two banners once it runs out.

`enforceOneBanner()` runs on every pass outside the budget and removes only banners the shell made. A screen that redraws itself after the shell's watch ends can call `KT.Shell.enforceOneBanner()`.

Opting out of the automatic banner is `data-kt-no-autohero`, never a decoy `.kt-hero`.

## A section's content box is `.kt-section` (2026-10-21)

The Reseller section was narrower than everything else: a private `max-width:1500px;margin:0 auto`
column, and banners wider than the content under them. Every section screen puts its content in
ONE wrapper, `<div class="kt-section">` (kt-consistency-polish.css: 24px padding, max-width
1800px, left-aligned: 44px from the edge of `#appMain` on desktop, the shell's 10px on phones via
kt-phone-gutter.js). Its own banner goes inside it as the first child.
- Never give a screen its own page `max-width`, centring or page padding. Cards and grids inside
  the section are yours.
- A screen that relies on the shell's automatic banner needs nothing else: `alignAutoHero()` lines
  the banner and its action bar up with your content box.

## Phone traps found on Educator points (2026-11-02)

- **A coloured card must not start from `background:#fff`.** kt-mobile-tint.css matches an inline
  `background:#fff;` on a phone and repaints it with a pale tint (`!important`), even when a later
  `background:linear-gradient(...)` in the same style overrides it on desktop. White text on that card
  then disappears (My points' navy total card). Give a dark/gradient card its own style string.
- **A sentence of fields is not a filter bar.** kt-phone-gutter.js marks any flex row with 2+ selects/
  text boxes as `data-kt-filterbar` and stretches each box to the full width on a phone ("day [1] at
  [9:00]", "$ [0] per [100] points" became full-width boxes with orphaned words). Opt out with
  `data-kt-nofb` on the row (kt-field-fit's packBar has its own `data-kt-nopack`).
- **No `grid-column` on an element kt-row-actions turns into a card ⋮.** It positions the action bar
  `absolute` in the row's right gutter, but an absolute child of a grid WITH a grid-column is placed
  inside that grid area instead, so the ⋮ covered the row's last column (Agency overview points totals).
- **A radio/checkbox beside long text in a flex label needs `flex:0 0 auto` and a size**, or flexbox
  shrinks it (8-13px wide) and each option's text starts at a different x.

## Spell check is decided in one place: kt-spellcheck.js (2026-11-02)

- **Never write `spellcheck`, `autocorrect` or `autocapitalize` on a field by hand.** kt-spellcheck.js
  watches document.body (popups and lazily loaded screens included) and sets them by field type:
  textareas, contenteditable and short free-text boxes ON; names, usernames, emails, phones, numbers,
  dates, codes/IDs, URLs, addresses, search/filter boxes OFF. A `spellcheck` attribute written in the
  markup is respected, so a hand-written `spellcheck="false"` silently turns the speller off.
- **Opt a field (or a whole container) out with `data-kt-nospell`**; force it on with
  `data-kt-spellcheck="on"`.
- On a phone `spellcheck=false` also removes the keyboard's suggestion strip and autocorrect, so an
  OFF decision on a prose box costs more than the red underline.

## A new record type needs a search source (2026-11-02)

- **A new record type needs a search source.** The global search (⌘K palette, sidebar widget, phone
  launcher -> `GET /admin/search`) finds only what `App\Support\SearchRegistry::SOURCES` lists. Adding a
  type is one registry entry (label, icon, tables, scope, link) plus one `SearchController::src<Key>()`
  method that applies the active-agency + CentreScope rule. The three boxes draw groups from the
  response's `groups`, so nothing is added on the front end.
- If a type should NOT be searchable, give it a reason in `NOT_SEARCHABLE` (or `KNOWN_GAPS` if it should
  be, later). `php artisan kt:search-coverage` runs nightly and fails, emailing the platform admins, when
  a main record type or a NEW table has neither.
- A result opens its screen through the HASH (so kt-screen-loader runs), never by calling a lazy
  screen's function directly. Show the record's number / RefCode, never a raw id.
