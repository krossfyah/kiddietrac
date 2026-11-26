/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — reminder toasts, Outlook-style (2026-11-26).
   Anthony: "wire up reminders for upcoming events/meetings as a toast in the portal". He chose "Like Outlook":
   ONE reminder, 15 minutes before by default, that STAYS until it is dismissed or snoozed, with Join / Open, and
   several due at once stack in one window with "Dismiss all".

   WHAT: the person's own KiddieTrac meetings (organiser or invited, not declined), their connected Outlook / Google
   calendar, sales items (sales team), their open tasks (a reminder time, or due today) and Quick-note reminders,
   all from ONE call, GET /me/reminders (App\Support\Reminders), which looks two hours ahead. Parents get only the
   meetings they were invited to. A date-only item (task due today, all-day event) reminds at 9:00 that day.

   HOW OFTEN: the list is fetched when the portal settles, every 5 minutes while the tab is visible, when it comes
   back into view, and after a choice here. Firing is timed locally from that list (a 15-second tick), so the
   reminder appears on the minute without polling the server. Nothing is fetched while the tab is hidden.

   ONE TAB: the most recently focused tab is the leader (localStorage heartbeat) and is the only one that shows
   the window and plays the sound; a dismiss or snooze in any tab is told to the others (BroadcastChannel, with a
   storage-event fallback) and is saved on the server (POST /me/reminders/state), so a reload, another tab or
   another device never brings a dismissed reminder back. A moved event has a new key and reminds again.

   SOUND: KT.sounds category "reminder" (Settings › Notifications › Sounds & vibration). A browser notification
   only if the person ALREADY allowed notifications and every KiddieTrac tab is hidden; this never asks.
   Nothing here sends a push, an email or a text.

   SETTINGS: lead time per person (ui-prefs "reminders": Off, at start, 5/10/15/30 min, 1 hour; default 15), in
   My settings › Reminders and in the My day panel's gear. KT.reminders exposes leads / getLead / loadLead / setLead
   / leadMenu / refresh.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  if (w.__ktReminders) return; w.__ktReminders = true;
  var KT = w.KT || (w.KT = {});

  var REFETCH_MS = 5 * 60 * 1000, TICK_MS = 15000, HEARTBEAT_MS = 4000, STALE_MS = 12000;
  var LEADS = [['off', 'Off'], [0, 'At start time'], [5, '5 minutes before'], [10, '10 minutes before'], [15, '15 minutes before'], [30, '30 minutes before'], [60, '1 hour before']];
  var SNOOZES = [[5, '5 minutes'], [10, '10 minutes'], [15, '15 minutes'], ['start', 'Until it starts']];
  var ID = Math.random().toString(36).slice(2) + Date.now().toString(36);

  var S = { items: [], lead: 15, tz: null, skew: 0, fetchedAt: 0, ok: true, min: false };
  var local = {};          // key -> { d: true } | { s: ms }   (this session's own choices, before the server echoes them)
  var root = null, tickT = null, fetchT = null, hbT = null, placeT = null, busy = false, started = false;

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function api() { return KT.Api; }
  function T(s) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function lang() { try { var l = KT.i18n && KT.i18n.locale && KT.i18n.locale(); if (l) return l; } catch (e) {} return d.documentElement.lang || 'en'; }
  function now() { return Date.now() + S.skew; }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { return null; } }

  /* ── one tab leads ───────────────────────────────────────────────── */
  var LKEY = 'kt_rem_leader';
  function leaderRec() { try { return JSON.parse(ls(LKEY) || 'null'); } catch (e) { return null; } }
  function claim() { ls(LKEY, JSON.stringify({ id: ID, at: Date.now() })); }
  function isLeader() {
    var r = leaderRec();
    if (!r || !r.id || Date.now() - (r.at || 0) > STALE_MS) { claim(); return true; }
    return r.id === ID;
  }
  function heartbeat() { var r = leaderRec(); if (r && r.id === ID) claim(); }
  w.addEventListener('focus', function () { claim(); render(); });
  d.addEventListener('visibilitychange', function () { if (!d.hidden) { claim(); maybeFetch(true); render(); } });
  w.addEventListener('pagehide', function () { var r = leaderRec(); if (r && r.id === ID) ls(LKEY, null); });

  /* ── other tabs hear about choices ───────────────────────────────── */
  var bc = null;
  try { bc = new BroadcastChannel('kt-reminders'); bc.onmessage = function (e) { hear(e.data); }; } catch (e) { bc = null; }
  w.addEventListener('storage', function (e) {
    if (e.key === 'kt_rem_msg' && e.newValue) { try { hear(JSON.parse(e.newValue)); } catch (x) {} }
    if (e.key === LKEY) render();
  });
  function tell(msg) {
    msg.from = ID;
    try { if (bc) { bc.postMessage(msg); return; } } catch (e) {}
    ls('kt_rem_msg', JSON.stringify(msg));
  }
  function hear(m) {
    if (!m || m.from === ID) return;
    if (m.t === 'state') { (m.keys || []).forEach(function (k) { local[k] = m.action === 'dismiss' ? { d: true } : { s: Date.parse(m.until) || (now() + 300000) }; }); render(); }
    if (m.t === 'lead') { S.lead = m.lead; fetchNow(); }
  }

  /* ── data ────────────────────────────────────────────────────────── */
  function fetchNow() {
    if (busy || !tok() || !api()) return Promise.resolve();
    busy = true;
    return api().get('/me/reminders').then(function (j) {
      busy = false; S.ok = true; S.fetchedAt = Date.now();
      if (!j) return;
      var srv = Date.parse(j.now); if (!isNaN(srv)) S.skew = srv - Date.now();
      S.lead = j.lead === null ? 'off' : j.lead; S.tz = j.tz || null;
      S.items = (j.items || []).filter(function (it) { return it && it.key; });
      // The server's own dismissed/snoozed state wins once it has it; drop local choices it already knows.
      var keys = {}; S.items.forEach(function (it) { keys[it.key] = it; });
      Object.keys(local).forEach(function (k) { if (!keys[k]) delete local[k]; });
      render();
    }).catch(function (e) {
      busy = false; S.fetchedAt = Date.now();
      var st = e && (e.status || (e.response && e.response.status));
      if (st === 401 || st === 403) { S.ok = false; S.items = []; render(); }
    });
  }
  function maybeFetch(force) {
    if (d.hidden || !tok()) return;
    if (force || Date.now() - S.fetchedAt > REFETCH_MS) fetchNow();
  }

  function due() {
    var t = now();
    return S.items.filter(function (it) {
      var l = local[it.key];
      if (l && l.d) return false;
      var sn = (l && l.s) || (it.snooze_until ? Date.parse(it.snooze_until) : 0);
      if (sn && sn > t) return false;
      return Date.parse(it.fire_at) <= t;
    });
  }

  /* ── choices ─────────────────────────────────────────────────────── */
  function save(keys, action, until) {
    keys.forEach(function (k) { local[k] = action === 'dismiss' ? { d: true } : { s: Date.parse(until) }; });
    tell({ t: 'state', keys: keys, action: action, until: until || null });
    render();
    var body = { keys: keys, action: action }; if (until) body.until = until;
    // A housekeeping write: never the "Saving… Saved" prompt (kt-progress quiet flag).
    try { api().post('/me/reminders/state', body, { quiet: true }).catch(function () {}); } catch (e) {}
  }
  function dismiss(keys) { save(keys, 'dismiss'); }
  function snooze(key, choice) {
    var it = find(key); if (!it) return;
    var until = choice === 'start' ? new Date(Date.parse(it.start)) : new Date(now() + choice * 60000);
    save([key], 'snooze', until.toISOString());
  }
  function find(k) { for (var i = 0; i < S.items.length; i++) if (S.items[i].key === k) return S.items[i]; return null; }

  /* ── lead time (per person, server-side) ─────────────────────────── */
  function leadLabel(v) { for (var i = 0; i < LEADS.length; i++) if (String(LEADS[i][0]) === String(v)) return T(LEADS[i][1]); return T('15 minutes before'); }
  function loadLead() {
    return api().get('/auth/me/ui-prefs').then(function (j) {
      var r = j && j.prefs && j.prefs.reminders; var v = r && r.lead !== undefined ? r.lead : 15;
      S.lead = v === false || v === null ? 'off' : v; return S.lead;
    }).catch(function () { return S.lead; });
  }
  function setLead(v) {
    var val = v === 'off' ? 'off' : parseInt(v, 10);
    return api().put('/auth/me/ui-prefs', { reminders: { lead: val } }).then(function () {
      S.lead = val; tell({ t: 'lead', lead: val }); local = {}; fetchNow(); return val;
    });
  }
  function leadMenu(anchor) {
    if (!KT.quickPop) return;
    KT.quickPop({ anchor: anchor, label: T('Reminders'), items: LEADS.map(function (r) {
      return { icon: String(r[0]) === String(S.lead) ? '✓' : '·', label: T(r[1]), run: function () {
        setLead(r[0]).then(function () { if (KT.savedToast) KT.savedToast(); }).catch(function () { if (KT.toast) KT.toast(T('Could not save that setting.'), 'error'); });
      } };
    }) });
  }

  /* ── drawing ─────────────────────────────────────────────────────── */
  var IC = {
    bell: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    meeting: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3"/>',
    sales: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    task: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/>',
    note: '<path d="M5 3h10l4 4v14H5z"/><path d="M15 3v4h4M8 12h8M8 16h5"/>',
    video: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3"/>',
    open: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    snooze: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M5 3L2 6M19 3l3 3"/>',
    check: '<path d="M5 12l5 5 9-10"/>',
    min: '<path d="M6 12h12"/>'
  };
  function ico(n, s) { return '<svg class="kt-rem-ic" viewBox="0 0 24 24" width="' + (s || 16) + '" height="' + (s || 16) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IC[n] || IC.bell) + '</svg>'; }

  function css() {
    if (d.getElementById('kt-rem-css')) return;
    var st = d.createElement('style'); st.id = 'kt-rem-css';
    st.textContent = ''
      + '#kt-rem{position:fixed;right:16px;bottom:16px;z-index:9500;width:min(380px,calc(100vw - 24px));font-family:inherit;}'
      + '#kt-rem .kt-rem-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;box-shadow:0 18px 48px -12px rgba(15,23,42,.35),0 2px 6px rgba(15,23,42,.08);overflow:hidden;}'
      + '#kt-rem .kt-rem-head{display:flex;align-items:center;gap:8px;padding:9px 10px 9px 14px;color:#fff;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '#kt-rem .kt-rem-head b{font-size:14px;font-weight:700;flex:1;min-width:0;}'
      + '#kt-rem .kt-rem-hbtn{background:rgba(255,255,255,.14);border:0;color:#fff;border-radius:8px;height:28px;padding:0 10px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}'
      + '#kt-rem .kt-rem-hbtn:hover{background:rgba(255,255,255,.26);}'
      + '#kt-rem .kt-rem-hbtn.kt-rem-sq{width:28px;padding:0;justify-content:center;}'
      + '#kt-rem .kt-rem-list{max-height:min(52vh,420px);overflow-y:auto;overscroll-behavior:contain;}'
      + '#kt-rem .kt-rem-row{display:flex;gap:10px;padding:11px 12px 10px 14px;border-top:1px solid #EEF2F7;}'
      + '#kt-rem .kt-rem-row:first-child{border-top:0;}'
      + '#kt-rem .kt-rem-k{flex:0 0 30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:#E6F6F4;color:#0F766E;}'
      + '#kt-rem .kt-rem-k.sales{background:#FCE7F3;color:#BE185D;}#kt-rem .kt-rem-k.task{background:#EDE9FE;color:#6D28D9;}'
      + '#kt-rem .kt-rem-k.calendar{background:#DBEAFE;color:#1D4ED8;}#kt-rem .kt-rem-k.note{background:#FEF3C7;color:#B45309;}'
      + '#kt-rem .kt-rem-b{flex:1;min-width:0;}'
      + '#kt-rem .kt-rem-t{font-size:14px;font-weight:700;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '#kt-rem .kt-rem-w{font-size:12.5px;color:#475569;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '#kt-rem .kt-rem-w .kt-rem-rel{font-weight:700;color:#0F766E;}#kt-rem .kt-rem-w .kt-rem-rel.now{color:#B91C1C;}'
      + '#kt-rem .kt-rem-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}'
      + '#kt-rem .kt-rem-btn{height:28px;padding:0 10px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#0F172A;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}'
      + '#kt-rem .kt-rem-btn:hover{background:#F1F5F9;}'
      + '#kt-rem .kt-rem-btn.join{background:#0F766E;border-color:#0F766E;color:#fff;}#kt-rem .kt-rem-btn.join:hover{background:#115E59;}'
      + '#kt-rem .kt-rem-pill{display:inline-flex;align-items:center;gap:6px;float:right;height:36px;padding:0 14px;border-radius:999px;border:0;cursor:pointer;color:#fff;font-weight:700;font-size:13px;'
      +   'background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:0 10px 26px -8px rgba(15,23,42,.45);}'
      + '@media (max-width:760px){#kt-rem{left:12px;right:12px!important;width:auto;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 84px)!important;}}'
      + '@media (prefers-reduced-motion:no-preference){#kt-rem .kt-rem-card{animation:ktRemIn .22s ease-out;}@keyframes ktRemIn{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}}'
      + 'html.kt-dark #kt-rem .kt-rem-card,html[data-theme="dark"] #kt-rem .kt-rem-card{background:#0F172A;border-color:#1E293B;}'
      + 'html.kt-dark #kt-rem .kt-rem-row,html[data-theme="dark"] #kt-rem .kt-rem-row{border-top-color:#1E293B;}'
      + 'html.kt-dark #kt-rem .kt-rem-t,html[data-theme="dark"] #kt-rem .kt-rem-t{color:#F1F5F9;}'
      + 'html.kt-dark #kt-rem .kt-rem-w,html[data-theme="dark"] #kt-rem .kt-rem-w{color:#94A3B8;}'
      + 'html.kt-dark #kt-rem .kt-rem-w .kt-rem-rel,html[data-theme="dark"] #kt-rem .kt-rem-w .kt-rem-rel{color:#5EEAD4;}'
      + 'html.kt-dark #kt-rem .kt-rem-btn,html[data-theme="dark"] #kt-rem .kt-rem-btn{background:#1E293B;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark #kt-rem .kt-rem-btn.join,html[data-theme="dark"] #kt-rem .kt-rem-btn.join{background:#0D9488;border-color:#0D9488;color:#fff;}'
      + 'html.kt-dark #kt-rem .kt-rem-k,html[data-theme="dark"] #kt-rem .kt-rem-k{background:#134E4A;color:#5EEAD4;}'
      + 'html.kt-dark #kt-rem .kt-rem-k.sales,html[data-theme="dark"] #kt-rem .kt-rem-k.sales{background:#500724;color:#F9A8D4;}'
      + 'html.kt-dark #kt-rem .kt-rem-k.task,html[data-theme="dark"] #kt-rem .kt-rem-k.task{background:#2E1065;color:#C4B5FD;}'
      + 'html.kt-dark #kt-rem .kt-rem-k.calendar,html[data-theme="dark"] #kt-rem .kt-rem-k.calendar{background:#172554;color:#93C5FD;}'
      + 'html.kt-dark #kt-rem .kt-rem-k.note,html[data-theme="dark"] #kt-rem .kt-rem-k.note{background:#451A03;color:#FCD34D;}'
      + '@media print{#kt-rem{display:none!important;}}';
    d.head.appendChild(st);
  }

  function fmtTime(iso) {
    try { return new Intl.DateTimeFormat(lang(), { hour: 'numeric', minute: '2-digit', timeZone: S.tz || undefined }).format(new Date(iso)); } catch (e) { return ''; }
  }
  function rel(iso) {
    var m = Math.round((Date.parse(iso) - now()) / 60000);
    if (Math.abs(m) < 1) return { t: T('Now'), now: true };
    try {
      var f = new Intl.RelativeTimeFormat(lang(), { numeric: 'auto' });
      if (Math.abs(m) >= 90) return { t: f.format(Math.round(m / 60), 'hour'), now: m < 0 };
      return { t: f.format(m, 'minute'), now: m < 0 };
    } catch (e) { return { t: (m > 0 ? 'in ' + m + ' min' : Math.abs(m) + ' min ago'), now: m < 0 }; }
  }
  function whenLine(it) {
    if (it.all_day) return '<span class="kt-rem-rel">' + esc(T(it.kind === 'task' ? 'Due today' : 'Today')) + '</span>' + (it.kind === 'task' ? '' : ' · ' + esc(T('All day')));
    var r = rel(it.start), span = fmtTime(it.start) + (it.end ? ' – ' + fmtTime(it.end) : '');
    var where = it.location ? ' · ' + esc(it.location) : (it.join_url ? ' · ' + esc(T(it.video === 'teams' ? 'Microsoft Teams meeting' : 'Video meeting')) : '');
    return '<span class="kt-rem-rel' + (r.now ? ' now' : '') + '">' + esc(r.t) + '</span> · <span data-no-i18n>' + esc(span) + '</span><span data-no-i18n>' + where + '</span>';
  }
  function canOpen(it) {
    if (it.kind === 'meeting' && it.meeting_id && KT.Meeting && KT.Meeting.view) return true;
    return !!(it.open || it.web_link);
  }

  function place() {
    if (!root) return;
    if (w.innerWidth <= 760) { root.style.right = ''; root.style.bottom = ''; return; }
    var right = 16, bottom = 16;
    try {
      // Beside the My day panel (its column is the shell's right padding), like every other toast.
      if (d.body.classList.contains('kt-rp-on')) { var sh = d.getElementById('appShell'); if (sh) right += parseFloat(getComputedStyle(sh).paddingRight) || 0; }
      // Above a bottom bar (touch laptops / tablets get the phone bar too): the fixed full-width strip at the bottom.
      // Sampled at a quarter and halfway across (the middle may be the raised Add button), ignoring our own window.
      [0.25, 0.5].forEach(function (fx) {
        // Every layer under the point (a full-screen overlay such as the notification gate may sit on top).
        var stack = d.elementsFromPoint ? d.elementsFromPoint(Math.round(w.innerWidth * fx), w.innerHeight - 4) : [];
        for (var j = 0; j < stack.length && j < 12; j++) {
          for (var el = stack[j], i = 0; el && el !== d.body && el !== d.documentElement && i < 10; i++, el = el.parentElement) {
            if (root.contains(el)) break;
            if (getComputedStyle(el).position !== 'fixed') continue;
            var br = el.getBoundingClientRect();
            if (br.bottom >= w.innerHeight - 2 && br.width > w.innerWidth * 0.6 && br.height < 160) { bottom = Math.max(bottom, w.innerHeight - br.top + 12); j = 99; }
            break;
          }
        }
      });
      // Never over the chat dock: sit to its left when it is open at the bottom right.
      var dock = d.getElementById('kt-chat-dock');
      if (dock && dock.offsetWidth && getComputedStyle(dock).display !== 'none' && getComputedStyle(dock).visibility !== 'hidden') {
        var r = dock.getBoundingClientRect();
        if (r.bottom >= w.innerHeight - 40 && r.left > 0) {
          // To its left when there is room; above it when the window still fits; otherwise over it (z above the dock).
          var hgt = root.offsetHeight || 220;
          if (r.left - 16 >= 330) right = Math.max(right, w.innerWidth - r.left + 12);
          else if (r.top - hgt - 24 >= 60) bottom = Math.max(bottom, w.innerHeight - r.top + 12);
        }
      }
    } catch (e) {}
    // Never pushed past the top of the window.
    bottom = Math.min(bottom, Math.max(16, w.innerHeight - (root.offsetHeight || 220) - 12));
    root.style.right = right + 'px'; root.style.bottom = bottom + 'px';
  }

  var rung = {};
  function ringFor(list) {
    var store = {}; try { store = JSON.parse(ls('kt_rem_rung') || '{}') || {}; } catch (e) {}
    var t = Date.now(), changed = false;
    Object.keys(store).forEach(function (k) { if (t - store[k] > 86400000) { delete store[k]; changed = true; } });
    list.forEach(function (it) {
      if (rung[it.key] || store[it.key]) { rung[it.key] = 1; return; }
      rung[it.key] = 1; store[it.key] = t; changed = true;
      try { if (KT.sounds && KT.sounds.play) KT.sounds.play('reminder', { key: 'rem:' + it.key }); } catch (e) {}
      try {
        if (d.hidden && w.Notification && Notification.permission === 'granted') {
          var n = new Notification(it.title, { body: it.all_day ? T('Due today') : rel(it.start).t + ' · ' + fmtTime(it.start), tag: 'kt-rem-' + it.key, silent: true });
          n.onclick = function () { try { w.focus(); S.min = false; render(); n.close(); } catch (e) {} };
        }
      } catch (e) {}
    });
    if (changed) ls('kt_rem_rung', JSON.stringify(store));
  }

  var lastSig = '';
  function render() {
    if (!d.body) return;
    var list = S.ok ? due() : [];
    var lead = isLeader();
    if (!list.length || !lead) { if (root) { root.remove(); root = null; } lastSig = ''; stopPlace(); return; }
    var fresh = list.filter(function (it) { return !rung[it.key]; });
    if (fresh.length) { S.min = false; ringFor(fresh); }
    css();
    var sig = list.map(function (it) { return it.key; }).join('|') + '#' + S.min + '#' + Math.floor(now() / 30000);
    if (!root) { root = d.createElement('div'); root.id = 'kt-rem'; root.setAttribute('role', 'region'); root.setAttribute('aria-label', T('Reminders')); root.addEventListener('click', onClick); d.body.appendChild(root); }
    if (sig !== lastSig) {
      lastSig = sig;
      var n = list.length, title = n === 1 ? T('1 reminder') : n + ' ' + T('reminders');
      if (S.min) {
        root.innerHTML = '<button type="button" class="kt-rem-pill" data-rem="max" aria-label="' + esc(T('Show reminders')) + '">' + ico('bell', 15) + '<span>' + esc(title) + '</span></button>';
      } else {
        var h = '<div class="kt-rem-card" aria-live="polite"><div class="kt-rem-head">' + ico('bell', 16) + '<b>' + esc(title) + '</b>'
          + (n > 1 ? '<button type="button" class="kt-rem-hbtn" data-rem="dismiss-all">' + esc(T('Dismiss all')) + '</button>' : '')
          + '<button type="button" class="kt-rem-hbtn kt-rem-sq" data-rem="min" data-kttip="' + esc(T('Minimize')) + '" aria-label="' + esc(T('Minimize')) + '">' + ico('min', 15) + '</button></div><div class="kt-rem-list">';
        list.forEach(function (it) {
          var k = it.kind === 'meeting' && it.source === 'sales' ? 'sales' : it.kind;
          h += '<div class="kt-rem-row" data-key="' + esc(it.key) + '"><span class="kt-rem-k ' + esc(k) + '">' + ico(k === 'sales' ? 'sales' : k, 16) + '</span><div class="kt-rem-b">'
            + '<div class="kt-rem-t" data-no-i18n data-kttip="' + esc(it.title) + '">' + esc(it.title) + '</div>'
            + '<div class="kt-rem-w">' + whenLine(it) + '</div><div class="kt-rem-acts">'
            + (it.join_url ? '<button type="button" class="kt-rem-btn join" data-rem="join">' + ico('video', 14) + esc(T('Join')) + '</button>' : '')
            + (canOpen(it) ? '<button type="button" class="kt-rem-btn" data-rem="open">' + ico('open', 14) + esc(T('Open')) + '</button>' : '')
            + '<button type="button" class="kt-rem-btn" data-rem="snooze">' + ico('snooze', 14) + esc(T('Snooze')) + '</button>'
            + '<button type="button" class="kt-rem-btn" data-rem="dismiss">' + ico('check', 14) + esc(T('Dismiss')) + '</button>'
            + '</div></div></div>';
        });
        root.innerHTML = h + '</div></div>';
      }
    }
    place(); startPlace();
  }
  function startPlace() { if (!placeT) placeT = setInterval(place, 2000); }
  function stopPlace() { if (placeT) { clearInterval(placeT); placeT = null; } }

  function onClick(e) {
    var b = e.target.closest('[data-rem]'); if (!b) return;
    var a = b.getAttribute('data-rem'), row = b.closest('[data-key]'), key = row && row.getAttribute('data-key'), it = key ? find(key) : null;
    if (a === 'min') { S.min = true; render(); return; }
    if (a === 'max') { S.min = false; render(); return; }
    if (a === 'dismiss-all') { dismiss(due().map(function (x) { return x.key; })); return; }
    if (!it) return;
    if (a === 'dismiss') { dismiss([it.key]); return; }
    if (a === 'join') { if (/^https:\/\//i.test(it.join_url || '')) w.open(it.join_url, '_blank', 'noopener'); return; }
    if (a === 'open') {
      if (it.kind === 'meeting' && it.meeting_id && KT.Meeting && KT.Meeting.view) { KT.Meeting.view(it.meeting_id); return; }
      if (it.web_link && /^https:\/\//i.test(it.web_link)) { w.open(it.web_link, '_blank', 'noopener'); return; }
      if (it.open) location.hash = '#' + it.open;
      return;
    }
    if (a === 'snooze') {
      var opts = SNOOZES.filter(function (s) { return s[0] !== 'start' || (!it.all_day && Date.parse(it.start) - now() > 60000); });
      if (KT.quickPop) KT.quickPop({ anchor: b, label: T('Snooze'), items: opts.map(function (s) { return { icon: '⏰', label: T(s[1]), run: function () { snooze(it.key, s[0]); } }; }) });
      else snooze(it.key, 5);
    }
  }

  /* ── start ───────────────────────────────────────────────────────── */
  function tick() { if (!tok()) return; if (!d.hidden) heartbeat(); maybeFetch(false); render(); }
  function start() {
    if (started) return; started = true;
    if (!tok()) { started = false; setTimeout(start, 3000); return; }
    claim(); fetchNow();
    tickT = setInterval(tick, TICK_MS);
    hbT = setInterval(function () { if (!d.hidden) heartbeat(); }, HEARTBEAT_MS);
    w.addEventListener('resize', place);
  }
  w.addEventListener('kt:screen-settled', function () { setTimeout(start, 1200); }, { once: true });
  setTimeout(start, 8000);

  KT.reminders = {
    leads: LEADS, getLead: function () { return S.lead; }, loadLead: loadLead, setLead: setLead, leadMenu: leadMenu, leadLabel: leadLabel,
    refresh: function () { return fetchNow(); }, _state: function () { return { items: S.items, due: due().map(function (x) { return x.key; }), leader: isLeader(), lead: S.lead }; }
  };
})(window, document);
