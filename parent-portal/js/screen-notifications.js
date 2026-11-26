/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p45 — Notifications inbox
   Hash: #notifications
   Lists every notification for the current user. Click to mark read.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  /* WRITES THE SERVER HAS NOT CONFIRMED YET — AT MODULE SCOPE, DELIBERATELY.

     Marking one read is optimistic: the row flips immediately and a PATCH follows. But
     `cache` is replaced wholesale by every load, and this screen reloads on the 45s poll,
     on the live bus, and whenever it is re-entered — so a refresh landing while the PATCH
     is still in flight hands back the PRE-WRITE row and silently undoes what the reader
     just did. It looks like the tap did nothing, so they tap again. That is the "takes two
     attempts", and a phone makes it far likelier because the round trip is slower.

     This lives OUTSIDE the render function on purpose. My first attempt kept it inside,
     which was useless: a full re-render builds a new closure and therefore a new empty
     ledger — and a full re-render is precisely the event that clobbers the row. It has to
     outlive the render it is protecting. (Anthony, 2026-09-09) */
  var pendingRead = Object.create(null);
  function rememberPending(id, value) { pendingRead[id] = value; }
  function settlePending(id) {
    /* Kept for a moment after the write lands: a GET that set off BEFORE the PATCH
       finished is still in flight and will still be carrying the old row. */
    setTimeout(function () { delete pendingRead[id]; }, 5000);
  }
  /* THE INBOX YOU JUST SAW, SHOWN AGAIN AT ONCE (2026-11-03, Anthony's iPhone recording: "Loading..." every time
     the Inbox opened). Each visit (and every 45s poll / live-bus re-render) built the screen from nothing and
     waited for GET /notifications before showing a row. The last list is kept here, at module scope so it
     outlives the render, and painted immediately; the fresh list is fetched behind it and painted only if it
     differs. Keyed on the sign-in (token), the agency, View-as and the user, so it never crosses any of them.
     The badge counts are NOT cached (GET /badges stays live; kt-badges.js). */
  var NTF_MEMO = Object.create(null);
  var NTF_MEMO_MS = 10 * 60000;
  function ntfMemoKey() {
    var u = {}, a = '', va = '', tk = '';
    try { u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) {}
    try { a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    try { tk = String(sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '').slice(-24); } catch (e) {}
    return [u.id || '', a, va, tk].join('|');
  }
  function applyPending(list) {
    if (!Array.isArray(list)) { return list; }
    for (var i = 0; i < list.length; i++) {
      var id = list[i] && list[i].id;
      if (id != null && Object.prototype.hasOwnProperty.call(pendingRead, id)) {
        list[i].read_at = pendingRead[id];
      }
    }
    return list;
  }

  // KT.confirm returns a PROMISE — it does not take a callback. Passing one meant
  // the action never ran: the confirm box appeared, you pressed Yes, and nothing
  // happened. This wraps both shapes safely.
  async function ktConfirmThen(message, onYes) {
    try {
      if (window.KT && KT.confirm) {
        var r = KT.confirm(message);
        if (r && typeof r.then === 'function') { r.then(function (ok) { if (ok) onYes(); }); return; }
        return;   // a non-promise KT.confirm would already have handled it
      }
    } catch (e) {}
    if (await KT.confirm(message)) onYes();
  }

  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  // MySQL timestamps are UTC with NO zone marker, so new Date(t) read them as
  // LOCAL and every notification was stamped hours out. Parse as UTC, show in the
  // agency's timezone (kt-tz.js).
  function ktParse(t) {
    if (window.KT && KT.parseTs) return KT.parseTs(t);
    var v = String(t || '').trim();
    return new Date(/(Z|[+-]\d{2}:?\d{2})$/.test(v) ? v.replace(' ', 'T') : v.replace(' ', 'T') + 'Z');
  }
  function fmtTime(t) {
    if (!t) return '—';
    try {
      var d = ktParse(t);
      if (isNaN(d)) return t;
      var tz = (window.KT && KT.tz) ? KT.tz() : 'America/Toronto';
      return new Intl.DateTimeFormat('en-CA', { timeZone: tz, dateStyle: 'medium', timeStyle: 'short' }).format(d);
    } catch (e) { return t; }
  }
  function relTime(t) {
    if (!t) return '';
    var ms = Date.now() - ktParse(t).getTime();
    if (ms < 60000) return 'just now';
    if (ms < 3600000) return Math.floor(ms / 60000) + 'm ago';
    if (ms < 86400000) return Math.floor(ms / 3600000) + 'h ago';
    return Math.floor(ms / 86400000) + 'd ago';
  }

  var TYPE_ICONS = {
    chat: '💬', message: '💬',
    invoice: '🧾', billing: '💳', payment: '✅',
    announcement: '📢', digest: '📅',
    incident: '⚠️', medication: '💊',
    welcome: '👋', invite: '✉️',
    daily_report: '📝', photo: '📸',
    form: '📝', system: '🔧',
    headcount_due: '🧮', records_reminder: '📋', safe_arrival: '🚸', supplies: '🧺',
    proposal_opened: '📖', task: '✅', tasks: '✅', clock: '⏱️', late_pickup: '⏰', absence: '🏠',
    observation: '🔭', lesson_plan: '📚', drill: '🚒', medication_due: '💊', birthday: '🎂',
    sales: '💼', lead: '🎯', booking: '📅', voicemail: '📼', missed_call: '📞', line_text: '💬',
  };
  /* WHAT KIND, IN WORDS (2026-10-23, Anthony: "look at inbox ... you will see some issues"). The
     raw type key ("headcount_due", "records_reminder") was printed under every alert. */
  var TYPE_LABELS = {
    chat: 'Message', message: 'Message', invoice: 'Invoice', billing: 'Billing', payment: 'Payment',
    announcement: 'Announcement', digest: 'Summary', incident: 'Incident', medication: 'Medication',
    welcome: 'Welcome', invite: 'Invitation', daily_report: 'Daily report', photo: 'Photo', form: 'Form',
    system: 'System', headcount_due: 'Headcount', records_reminder: 'Daily records', safe_arrival: 'Safe Arrival',
    supplies: 'Supplies', proposal_opened: 'Proposal', task: 'Task', tasks: 'Task', late_pickup: 'Late pick-up',
    absence: 'Absence', sms_reply: 'Text reply', sms: 'Text', clock_reminder: 'Clock reminder', observation: 'Observation', lesson_plan: 'Lesson plan', drill: 'Drill', birthday: 'Birthday',
  };
  function typeLabel(t) {
    if (!t) return 'Notice';
    if (TYPE_LABELS[t]) return TYPE_LABELS[t];
    var w = String(t).replace(/[_.-]+/g, ' ').trim();
    return w.charAt(0).toUpperCase() + w.slice(1);
  }
  function typeIcon(t) {
    if (TYPE_ICONS[t]) return TYPE_ICONS[t];
    var k = String(t || '');
    if (/chat|message/.test(k)) return '💬';
    if (/invoice|bill|pay/.test(k)) return '🧾';
    if (/remind|due/.test(k)) return '⏰';
    if (/safe/.test(k)) return '🚸';
    return '🔔';
  }
  /* "398 min ago" read as a sum to do. Old alerts keep their stored text, so it is tidied here. */
  function tidyMinutes(text) {
    return String(text || '').replace(/(\d+) min ago/g, function (m, n) {
      n = +n; if (n < 60) return m;
      var h = Math.floor(n / 60), r = n % 60;
      return h + 'h' + (r ? ' ' + r + 'm' : '') + ' ago';
    });
  }

  /* "TODAY" IN AN OLD ALERT IS NOT TODAY (2026-11-03, Anthony's iPhone Inbox: "You clocked in at 7:30 AM today and
     have been on the clock 10.5 hours" on a reminder three days old). The system's reminders were written for
     the moment they were sent - "today", "yesterday", "tonight" - and the Inbox keeps them for weeks. A row
     from an earlier AGENCY day has those words replaced by the day itself ("on Thu, Oct 30"), worked out from
     when the row was written, in the agency's timezone. Only system-written kinds: a message somebody typed
     ("dentist at 2pm today") is shown exactly as written. Stored rows are not changed; new clock reminders
     already name the day (ClockReminderCommand). */
  var DATED_KINDS = /^(clock_reminder|absence|checkin_reminder|safe_arrival|birthday|children_still_in|headcount_due|records_reminder)$/;
  function datedText(text, n) {
    if (!text || !n || !n.created_at || !DATED_KINDS.test(String(n.type || ''))) return text;
    try {
      var at = ktParse(n.created_at);
      if (!at || isNaN(at)) return text;
      var tz = (window.KT && KT.tz) ? KT.tz() : 'America/Toronto';
      var ymd = function (d) { return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); };
      if (ymd(at) === ymd(new Date())) return text;                  // still that day: the words are true
      var day = function (d) { return new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric' }).format(d); };
      var on = day(at), before = day(new Date(at.getTime() - 86400000)), after = day(new Date(at.getTime() + 86400000));
      return String(text)
        .replace(/\bhave been on the clock\b/g, 'had been on the clock')
        .replace(/\btoday(['’])s\b/gi, function (m, q) { return (m.charAt(0) === 'T' ? 'T' : 't') + 'hat day' + q + 's'; })
        .replace(/\btoday\b/gi, function (m) { return (m.charAt(0) === 'T' ? 'On ' : 'on ') + on; })
        .replace(/\byesterday\b/gi, function (m) { return (m.charAt(0) === 'Y' ? 'On ' : 'on ') + before; })
        .replace(/\btonight\b/gi, function (m) { return (m.charAt(0) === 'T' ? 'That' : 'that') + ' night'; })
        .replace(/\btomorrow\b/gi, function (m) { return (m.charAt(0) === 'T' ? 'On ' : 'on ') + after; });
    } catch (e) { return text; }
  }

  function ntfCss() {
    if (document.getElementById('kt-ntf-css')) return;
    var st = document.createElement('style'); st.id = 'kt-ntf-css';
    st.textContent = ''
      + '.kt-ntf-bar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin:16px 0}'
      + '.kt-ntf-seg{display:inline-flex;background:#F1F5F9;border-radius:10px;padding:3px;gap:2px}'
      + '.kt-ntf-seg button{height:32px !important;min-height:0 !important;padding:0 14px !important;border:0 !important;border-radius:8px !important;background:transparent !important;font-size:13px !important;font-weight:800 !important;color:#475569 !important;cursor:pointer;box-shadow:none !important}'
      + '.kt-ntf-seg button.on{background:#fff !important;color:#0F172A !important;box-shadow:0 1px 3px rgba(15,23,42,.14) !important}'
      + '.kt-ntf-btns{display:flex;flex-wrap:wrap;gap:8px;align-items:center}'
      + '.kt-ntf-b{height:36px !important;min-height:36px !important;padding:0 12px !important;border:1px solid #CBD5E1 !important;border-radius:9px !important;background:#fff !important;color:#334155 !important;font-size:13px !important;font-weight:700 !important;cursor:pointer;display:inline-flex !important;align-items:center;gap:5px;white-space:nowrap;box-shadow:none !important}'
      + '.kt-ntf-b-main{border-color:#1F6080 !important;color:#1F6080 !important}'
      + '.kt-ntf-ico{width:38px;height:38px;flex-shrink:0;border-radius:11px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:19px}'
      + '.kt-ntf-meta{display:flex;align-items:center;gap:8px;margin-top:5px;font-size:11.5px;color:#64748B}'
      + '.kt-ntf-kind{font-size:10.5px;font-weight:800;letter-spacing:.3px;color:#1F6080;background:#EAF3F6;border-radius:999px;padding:1px 8px}'
      + '.kt-ntf-more{display:block;width:100%;text-align:left;padding:8px 18px 8px 70px !important;min-height:0 !important;height:auto !important;border:0 !important;border-bottom:1px solid #F3F4F6 !important;border-radius:0 !important;background:#F8FAFC !important;color:#1F6080 !important;font-size:12.5px !important;font-weight:800 !important;cursor:pointer;box-shadow:none !important}'
      // The ⋮ stays at the row's right on a phone instead of dropping to a line of its own.
      + '@media (max-width:768px){.kt-ntf-row{flex-wrap:nowrap !important;gap:10px !important;padding:12px 10px 12px 16px !important}.kt-ntf-row>*:last-child{flex:0 0 auto !important;width:auto !important;margin:0 !important;align-self:flex-start !important}.kt-ntf-ico{width:32px;height:32px;font-size:16px}.kt-ntf-bar{flex-direction:column;align-items:stretch}.kt-ntf-seg{width:100%;box-sizing:border-box}.kt-ntf-seg button{flex:1 1 0}'
      + '.kt-ntf-btns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.kt-ntf-b{justify-content:center;padding:0 6px !important;font-size:12.5px !important}.kt-ntf-more{padding-left:62px !important}}';
    document.head.appendChild(st);
  }

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1200px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#7C3AED 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">🔔 INBOX</div><h1>Notifications</h1><div class="kt-hero-sub">Every alert from Kiddietrac for your account, newest first. Tap one to mark it read.</div>';
    wrap.appendChild(hero);

    // Classed so the mobile stylesheet can line the control row up — the select
    // and the buttons had different paddings/heights and fell out of alignment
    // (and wrapped) on a phone.
    ntfCss();
    // data-kt-noband: the tab-band script was painting these ACTION buttons as tabs, with
    // "Mark all read" lit up as if it were the selected tab.
    var bar = Dom.el('div', { class: 'kt-notif-bar kt-ntf-bar', 'data-kt-noband': '1' });
    // The filter stays a <select> underneath (every read of filter.value is unchanged); what
    // people see is three plain choices. The squeezed dropdown read as "A†l" on a phone.
    var filter = Dom.el('select', { class: 'kt-notif-filter', hidden: 'hidden', 'aria-hidden': 'true' });
    [['','All'],['unread','Unread only'],['read','Read only']].forEach(function (o) {
      filter.appendChild(Dom.el('option', { value: o[0] }, o[1]));
    });
    var seg = Dom.el('div', { class: 'kt-ntf-seg', role: 'group', 'aria-label': 'Show' });
    [['', 'All'], ['unread', 'Unread'], ['read', 'Read']].forEach(function (o) {
      var b = Dom.el('button', { type: 'button', 'data-f': o[0], 'data-kt-iconized': '1', class: o[0] === '' ? 'on' : '' }, o[1]);
      b.addEventListener('click', function () {
        filter.value = o[0];
        seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        filter.dispatchEvent(new Event('change'));
      });
      seg.appendChild(b);
    });
    bar.appendChild(filter);
    bar.appendChild(seg);
    var btns = Dom.el('div', { class: 'kt-notif-btns kt-ntf-btns' });
    var selectBtn = Dom.el('button', { type: 'button', class: 'kt-notif-btn kt-ntf-b', 'data-kt-iconized': '1' }, '☑️ Select');
    var markAll = Dom.el('button', { type: 'button', class: 'kt-notif-btn kt-ntf-b kt-ntf-b-main', 'data-kt-iconized': '1', title: 'Mark every notification read' }, '✓ Read all');
    /* Where the sounds are chosen (2026-10-21): this inbox is where people look for
       notification settings, and it had no way to them. */
    var soundsBtn = Dom.el('button', { type: 'button', class: 'kt-notif-btn kt-ntf-b', 'data-kt-iconized': '1', title: 'Choose the tone and vibration for each kind of notification' }, '🔊 Sounds');
    soundsBtn.addEventListener('click', function () { location.hash = '#settings?tab=notifications&sub=sounds'; });
    btns.appendChild(soundsBtn);
    btns.appendChild(selectBtn); btns.appendChild(markAll);
    bar.appendChild(btns);
    wrap.appendChild(bar);

    // ── Selection mode: tick rows, then delete them (or clear the lot) ──
    var selecting = false;
    var selected = {};      // id → true

    var selBar = Dom.el('div', {
      style: 'display:none;align-items:center;justify-content:space-between;gap:8px;background:#0F172A;color:#fff;'
        + 'border-radius:12px;padding:9px 12px;margin-bottom:12px;position:sticky;top:4px;z-index:5;',
    });
    var selCount = Dom.el('div', { style: 'font-size:13px;font-weight:700;' }, '0 selected');
    var selActions = Dom.el('div', { style: 'display:flex;gap:6px;' });
    var selAll = Dom.el('button', { style: 'background:rgba(255,255,255,.14);color:#fff;border:none;padding:7px 11px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;' }, 'Select all');
    var selDel = Dom.el('button', { style: 'background:#DC2626;color:#fff;border:none;padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:800;cursor:pointer;' }, 'Delete');
    var selCancel = Dom.el('button', { style: 'background:transparent;color:rgba(255,255,255,.8);border:none;padding:7px 8px;font-size:12.5px;font-weight:700;cursor:pointer;' }, 'Cancel');
    selActions.appendChild(selAll); selActions.appendChild(selDel); selActions.appendChild(selCancel);
    selBar.appendChild(selCount); selBar.appendChild(selActions);
    wrap.appendChild(selBar);

    function visibleRows() {
      var rows = cache;
      if (filter.value === 'unread') rows = rows.filter(function (r) { return !r.read_at; });
      if (filter.value === 'read') rows = rows.filter(function (r) { return r.read_at; });
      return rows;
    }
    function selectedIds() { return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number); }
    function paintSelBar() {
      var n = selectedIds().length;
      selBar.style.display = selecting ? 'flex' : 'none';
      selCount.textContent = n + ' selected';
      selDel.style.opacity = n ? '1' : '.5';
      selDel.disabled = !n;
      selAll.textContent = (n && n === visibleRows().length) ? 'Select none' : 'Select all';
      selectBtn.textContent = selecting ? 'Done' : 'Select';
    }
    function setSelecting(on) {
      selecting = on;
      if (!on) selected = {};
      paintSelBar(); paint();
    }
    selectBtn.addEventListener('click', function () { setSelecting(!selecting); });
    selCancel.addEventListener('click', function () { setSelecting(false); });
    selAll.addEventListener('click', function () {
      var rows = visibleRows();
      var allOn = selectedIds().length === rows.length;
      selected = {};
      if (!allOn) rows.forEach(function (r) { selected[r.id] = true; });
      paintSelBar(); paint();
    });
    selDel.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      var go = function () {
        selDel.disabled = true; selDel.textContent = 'Deleting…';
        Api.post('/notifications/delete', { ids: ids })
          .then(function () {
            cache = cache.filter(function (r) { return ids.indexOf(r.id) === -1; });
            setSelecting(false);
            selDel.textContent = 'Delete';
            if (KT.toast) KT.toast('🗑', 'Deleted', ids.length + ' notification' + (ids.length === 1 ? '' : 's') + ' removed.', '#0F172A');
          })
          .catch(function () {
            selDel.disabled = false; selDel.textContent = 'Delete';
            if (KT.toast) KT.toast('⚠️', 'Could not delete', 'Please try again.', '#B91C1C');
          });
      };
      ktConfirmThen('Delete ' + ids.length + ' notification' + (ids.length === 1 ? '' : 's') + '? This cannot be undone.', go);
    });

    var listWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    var memoKey = ntfMemoKey();
    var memo = NTF_MEMO[memoKey];
    if (memo && Date.now() - memo.at > NTF_MEMO_MS) { memo = null; }
    if (!memo) { listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…')); }

    var cache = [];

    var _npage = 1, _nlastF = filter.value, N_PER = 12;
    function paint() {
      if (NTF_MEMO[memoKey]) { NTF_MEMO[memoKey].rows = cache; }
      Dom.clear(listWrap);
      var rows = cache;
      if (filter.value === 'unread') rows = rows.filter(function (r) { return !r.read_at; });
      if (filter.value === 'read') rows = rows.filter(function (r) { return r.read_at; });
      if (filter.value !== _nlastF) { _npage = 1; _nlastF = filter.value; }   // reset paging when the filter changes
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'Inbox zero. 🎉'));
        return;
      }
      /* THE SAME ALERT, ONCE (2026-10-23). "Headcount due — Bruni Meeser" every hour filled the
         screen. Back-to-back alerts of the same kind and title show as one, with "N earlier like
         this" opening the rest. Not while selecting, where every row has to be tickable. */
      var groups = [];
      rows.forEach(function (r) {
        var g = groups[groups.length - 1];
        if (!selecting && g && g.key === (r.type || '') + '|' + (r.title || '')) g.more.push(r);
        else groups.push({ key: (r.type || '') + '|' + (r.title || ''), first: r, more: [] });
      });
      rows = groups;
      var total = rows.length, pages = Math.ceil(total / N_PER);
      if (_npage > pages) _npage = pages;
      if (_npage < 1) _npage = 1;
      var start = (_npage - 1) * N_PER, end = Math.min(start + N_PER, total);
      // data-kt-list marks these rows for the kebab sweep. no-controls because the screen
      // already has its own filter + "Mark all read" bar and does not want a second one.
      /* ONE SCROLLBAR (2026-11-25, Anthony: "two scroll bars ... full sweep"). This list was a box capped at
         calc(100vh - 330px) with its own scrollbar, but the banner, filter bar and pager around it take more than
         330px, so the page scrolled as well: two vertical scrollbars on one screen. The list is already paged
         (12 per page), so it needs no box of its own: the page is the one scroll region. */
      var scroller = Dom.el('div', {
        'data-kt-list': '1', 'data-kt-no-controls': '1',
      });
      rows.slice(start, end).forEach(function (g) {
        scroller.appendChild(renderRow(g.first));
        if (!g.more.length) return;
        var unreadMore = g.more.filter(function (r) { return !r.read_at; }).length;
        var hold = Dom.el('div', { style: 'display:none;' });
        g.more.forEach(function (r) { hold.appendChild(renderRow(r)); });
        var tgl = Dom.el('button', { type: 'button', class: 'kt-ntf-more', 'data-kt-iconized': '1', 'data-kt-nobreathe': '1' },
          '＋ ' + g.more.length + ' earlier like this' + (unreadMore ? ' · ' + unreadMore + ' unread' : ''));
        tgl.addEventListener('click', function (e) {
          e.stopPropagation();
          var open = hold.style.display === 'none';
          hold.style.display = open ? '' : 'none';
          tgl.textContent = open ? '－ Hide the earlier ones' : '＋ ' + g.more.length + ' earlier like this' + (unreadMore ? ' · ' + unreadMore + ' unread' : '');
          if (open && KT.sweepRowActions) KT.sweepRowActions();
        });
        scroller.appendChild(tgl);
        scroller.appendChild(hold);
      });
      listWrap.appendChild(scroller);
      // Rows were just rebuilt, so their kebabs went with them. The sweeper is on a
      // 4s interval; nudge it or the actions sit there as raw buttons until it fires.
      if (KT.sweepRowActions) KT.sweepRowActions();
      // Page through them so the list never grows without bound.
      if (pages > 1) {
        var pager = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:12px 18px;border-top:1px solid #F3F4F6;background:#FCFCFD;' });
        // KT.pagerBar — the portal's one pager, not a bespoke "‹ Newer / Older ›" pair.
        pager.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;font-weight:600;' }, 'Showing ' + (start + 1) + '–' + end + ' of ' + total + (total < cache.length ? ' (repeats grouped)' : '')));
        var nav = Dom.el('div', {});
        pager.appendChild(nav);
        if (window.KT && KT.pagerBar) { KT.pagerBar(nav, _npage, pages, function (p) { _npage = p; paint(); }); nav.style.margin = '0'; }
        listWrap.appendChild(pager);
      }
    }

    function renderRow(n) {
      var icon = typeIcon(n.type);
      var row = Dom.el('div', { class: 'kt-ntf-row', style: 'display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;background:' + (n.read_at ? 'white' : '#EFF6FF') + ';position:relative;' });
      row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
      row.addEventListener('mouseleave', function () { row.style.background = n.read_at ? 'white' : '#EFF6FF'; });

      if (!n.read_at) {
        row.appendChild(Dom.el('div', { style: 'width:8px;height:8px;border-radius:50%;background:#3B82F6;position:absolute;left:6px;top:22px;' }));
      }

      if (selecting) {
        var box = Dom.el('input', { type: 'checkbox', style: 'width:20px;height:20px;flex-shrink:0;margin-top:10px;accent-color:#159FB4;' });
        box.checked = !!selected[n.id];
        box.addEventListener('click', function (e) {
          e.stopPropagation();
          selected[n.id] = box.checked;
          paintSelBar();
        });
        row.appendChild(box);
      }

      row.appendChild(Dom.el('div', { class: 'kt-ntf-ico' }, icon));

      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      body.appendChild(Dom.el('div', { style: 'font-weight:' + (n.read_at ? '600' : '700') + ';font-size:14px;color:#111827;' }, datedText(n.title, n) || '(no title)'));
      if (n.body) body.appendChild(Dom.el('div', { style: 'color:#475569;font-size:13px;margin-top:3px;line-height:1.4;' }, datedText(tidyMinutes(n.body), n)));
      var meta = Dom.el('div', { class: 'kt-ntf-meta' });
      meta.appendChild(Dom.el('span', { class: 'kt-ntf-kind' }, typeLabel(n.type)));
      meta.appendChild(Dom.el('span', { title: fmtTime(n.created_at) }, relTime(n.created_at)));
      body.appendChild(meta);
      row.appendChild(body);

      // Row actions go in a bar that is the row's LAST element child and holds nothing
      // but buttons. That exact shape is what kt-row-actions looks for before collapsing
      // them into the house ⋮ kebab (see cardActionCell/isActionsBar) — the same treatment
      // every other list gets. Never hand-roll a kebab; shape the markup and let it happen.
      var acts = Dom.el('div', { style: 'display:flex;flex-direction:column;align-items:flex-end;gap:2px;flex-shrink:0;align-self:flex-start;' });

      // Read is a toggle, not a one-way door. Tapping a row marks it read on the way to
      // wherever it points, so an item opened by mistake needs a way back.
      var readBtn = Dom.el('button', {
        type: 'button', 'data-act': n.read_at ? 'mark-unread' : 'mark-read',
        style: 'background:none;border:none;color:#64748B;font-size:12px;font-weight:600;cursor:pointer;padding:4px 6px;white-space:nowrap;',
      }, n.read_at ? '📩 Mark unread' : '📖 Mark read');
      readBtn.addEventListener('click', function (e) {
        e.stopPropagation();          // the row itself navigates — an action must not
        /* THE ACTION THE BUTTON PROMISES, not a toggle of whatever state we think we
           hold. A toggle asks "what is it now?" and flips — so if this fires twice for
           one gesture (a forwarded kebab click landing alongside the real one, a touch
           that also produces a click) the second flips it straight back and the reader
           sees nothing happen. Reading the intent off the button makes a repeat
           activation idempotent: "mark read" twice is still read. */
        var wantRead = this.getAttribute('data-act') === 'mark-read';
        var prev = n.read_at;
        if (wantRead === !!prev) { return; }        // already where it is going
        var next = wantRead ? new Date().toISOString() : null;
        n.read_at = next;                                        // optimistic
        rememberPending(n.id, next);
        paint();
        Api.patch('/notifications/' + n.id + (wantRead ? '/read' : '/unread'), {})
          .then(function () { settlePending(n.id); })
          .catch(function () {
            n.read_at = prev;                                     // put it back
            delete pendingRead[n.id];
            paint();
            if (KT.toast) KT.toast('⚠️', 'Could not update', 'Please try again.', '#B91C1C');
          });
      });
      acts.appendChild(readBtn);

      // Per-row delete — the common case is binning one notification, and making
      // someone enter select-mode for that is a tax. Labelled, not a bare glyph:
      // inside the kebab the text IS the menu item.
      var del = Dom.el('button', {
        type: 'button', 'aria-label': 'Delete notification',
        style: 'background:none;border:none;color:#94A3B8;font-size:12px;font-weight:600;cursor:pointer;padding:4px 6px;white-space:nowrap;',
      }, '🗑 Delete');
      del.addEventListener('click', function (e) {
        e.stopPropagation();
        // Deleting is not undoable — ask first.
        var go = function () {
          var prevRows = cache.slice();
          cache = cache.filter(function (r) { return r.id !== n.id; });   // optimistic
          paint();
          Api.delete('/notifications/' + n.id).catch(function () {
            cache = prevRows;                                             // put it back
            paint();
            if (KT.toast) KT.toast('⚠️', 'Could not delete', 'Please try again.', '#B91C1C');
          });
        };
        ktConfirmThen('Delete this notification? This cannot be undone.', go);
      });
      acts.appendChild(del);

      row.appendChild(acts);

      row.addEventListener('click', function () {
        if (selecting) {
          selected[n.id] = !selected[n.id];
          paintSelBar(); paint();
          return;
        }
        if (!n.read_at) {
          // Optimistic update
          n.read_at = new Date().toISOString();
          rememberPending(n.id, n.read_at);
          paint();
          Api.patch('/notifications/' + n.id + '/read', {})
            .then(function () { settlePending(n.id); })
            .catch(function () { n.read_at = null; delete pendingRead[n.id]; paint(); });
        }
        // Tapping a notification takes you to the thing it is about.
        //
        // Notifications carry their destination as `url` (older) or `link`
        // (newer) — this only read `url`, so every notification written since
        // then did nothing at all when tapped. A chat notification also carries
        // conversation_id, so we can open the actual thread rather than dumping
        // the user on the message list to find it themselves.
        try {
          var d = typeof n.data === 'string' ? JSON.parse(n.data) : (n.data || {});
          var dest = (d && (d.link || d.url || d.href || d.hash)) || '';
          /* Rows written before 2026-11-04 by the three writers that named no destination. */
          if (!dest) { dest = ({ announcement: '#announcements', award: '#awards', user_invited: '#admin-users' })[n.type] || ''; }
          if (d && d.conversation_id && /chat|message/i.test(dest + ' ' + (n.type || ''))) {
            dest = '#chat?c=' + d.conversation_id;
          }
          if (dest) {
            // Accept '/dashboard.html#chat', '#chat', or 'chat'.
            var hash = String(dest).replace(/^.*#/, '');
            window.location.hash = '#' + hash.replace(/^#/, '');
          }
        } catch (e) {}
      });

      return row;
    }

    filter.addEventListener('change', paint);

    /* ONE request that marks every unread row server-side, then a reload.

       This used to fire a PATCH per notification, in parallel, over whatever the list
       happened to be holding — and that is why stale notifications kept coming back
       (Anthony, 2026-08-31):
         • it could only ever reach the newest 200, the cap on GET /notifications, so
           anything older stayed unread for good;
         • 47 simultaneous PATCHes is a burst the throttle can and does refuse — and
           the .catch() swallowed the failure while .finally() set read_at LOCALLY
           anyway, so the screen showed them read while the server still had them
           unread. On the next load they were all back, which is exactly the symptom.

       POST /notifications/mark-read already updates every unread row for the user in a
       single statement. Re-reading afterwards means the list shows what the server
       actually holds rather than what we hoped it did. */
    markAll.addEventListener('click', async function () {
      var unread = cache.filter(function (r) { return !r.read_at; });
      if (!unread.length) return;
      if (!await KT.confirm('Mark all ' + unread.length + ' as read?')) return;
      markAll.disabled = true; markAll.textContent = 'Marking…';

      /* MARK-ALL HAS TO USE THE PENDING LEDGER TOO (2026-09-14).

         The ledger above was built for the single mark and never wired up here, so
         "Mark all read" carried none of its protection. The hazard is the one the
         ledger's own note describes: this screen reloads on the 45s poll, on the live
         bus, and whenever it is re-entered, and a GET that set off BEFORE this POST
         lands after it — carrying every row still unread. cache is replaced wholesale,
         so the whole list flips back and the button looks like it did nothing.

         One mark was protected; marking forty was not, which is why THIS is the button
         people press two and three times. Every id is recorded before the write, so any
         in-flight reload is corrected on arrival instead of undoing the lot. */
      var nowIso = new Date().toISOString();
      var marked = unread.map(function (r) { return r.id; });
      marked.forEach(function (id) { rememberPending(id, nowIso); });

      try {
        await Api.post('/notifications/mark-read', {});
        var fresh = await Api.get('/notifications');
        cache = applyPending((fresh && fresh.notifications) ? fresh.notifications : (Array.isArray(fresh) ? fresh : cache));
        paint();
        // Released on the same delay a single mark uses, for the same reason.
        marked.forEach(function (id) { settlePending(id); });
        if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
      } catch (e) {
        /* The write failed, so the optimistic entries are a lie — drop them at once
           rather than letting them paper over unread rows for the next five seconds. */
        marked.forEach(function (id) { delete pendingRead[id]; });
        // Say so, and leave the rows alone — claiming they are read when they are not
        // is what made this untrustworthy in the first place.
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Could not mark them read — please try again.', 'error');
        else if (KT.toast) KT.toast('⚠️', 'Could not mark them read', (e && e.message) || '', '#B91C1C');
      }
      markAll.disabled = false; markAll.textContent = 'Mark all read';
    });

    // v22p45: notifications live under /parent/* in the current routes
    // (NotificationController::mine is gated for guardian only). Future
    // ships should extend this to staff via a new endpoint.
    if (memo) { cache = applyPending(memo.rows); paint(); }
    Api.get('/notifications').then(function (data) {
      var fresh = applyPending((data && data.notifications) ? data.notifications : (Array.isArray(data) ? data : []));
      var sig = JSON.stringify(fresh);
      var same = !!memo && sig === JSON.stringify(cache);
      NTF_MEMO[memoKey] = { rows: fresh, at: Date.now() };
      if (!listWrap.isConnected) return;
      if (same) { NTF_MEMO[memoKey].rows = cache; return; }   // nothing changed: leave the screen (and its scroll, open groups) alone
      cache = fresh;
      paint();
    }).catch(function () {
      if (memo) return;                                      // the list from memory stays up; the next refresh tries again
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#B91C1C;' }, 'Could not load your notifications. Check your connection and try again.'));
    });
  }

  if (Shell && Shell.registerScreen) {
    // auditor (2026-11-13 role audit): their menu has a Notifications item that drew the dashboard.
    ['agency_admin', 'centre_director', 'educator', 'guardian', 'platform_admin', 'home_visitor', 'sales_rep', 'auditor'].forEach(function (r) {
      Shell.registerScreen(r + ':notifications', render);
    });
  }
  KT.Notifications = { render: render };
})(window);
