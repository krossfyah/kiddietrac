/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p45 — Notifications inbox
   Hash: #notifications
   Lists every notification for the current user. Click to mark read.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

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
  };

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
    var bar = Dom.el('div', { class: 'kt-notif-bar', style: 'display:flex;justify-content:space-between;align-items:center;gap:8px;margin:18px 0;' });
    var filter = Dom.el('select', { class: 'kt-notif-filter', style: 'background:white;border:1px solid #D1D5DB;padding:7px 12px;border-radius:8px;font-size:13px;color:#374151;' });
    [['','All'],['unread','Unread only'],['read','Read only']].forEach(function (o) {
      filter.appendChild(Dom.el('option', { value: o[0] }, o[1]));
    });
    bar.appendChild(filter);
    var btns = Dom.el('div', { class: 'kt-notif-btns', style: 'display:flex;gap:8px;align-items:center;' });
    var selectBtn = Dom.el('button', { class: 'kt-notif-btn', style: 'background:white;color:#475569;border:1px solid #CBD5E1;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;' }, 'Select');
    var markAll = Dom.el('button', { class: 'kt-notif-btn', style: 'background:white;color:#1F6080;border:1px solid #1F6080;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;' }, 'Mark all read');
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
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…'));

    var cache = [];

    var _npage = 1, _nlastF = filter.value, N_PER = 12;
    function paint() {
      Dom.clear(listWrap);
      var rows = cache;
      if (filter.value === 'unread') rows = rows.filter(function (r) { return !r.read_at; });
      if (filter.value === 'read') rows = rows.filter(function (r) { return r.read_at; });
      if (filter.value !== _nlastF) { _npage = 1; _nlastF = filter.value; }   // reset paging when the filter changes
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'Inbox zero. 🎉'));
        return;
      }
      var total = rows.length, pages = Math.ceil(total / N_PER);
      if (_npage > pages) _npage = pages;
      if (_npage < 1) _npage = 1;
      var start = (_npage - 1) * N_PER, end = Math.min(start + N_PER, total);
      // A bounded, internally-scrolling window — like a Gmail message list.
      var scroller = Dom.el('div', { style: 'max-height:calc(100vh - 330px);min-height:140px;overflow-y:auto;' });
      rows.slice(start, end).forEach(function (n) { scroller.appendChild(renderRow(n)); });
      listWrap.appendChild(scroller);
      // Page through them so the list never grows without bound.
      if (pages > 1) {
        var pager = Dom.el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-top:1px solid #F3F4F6;background:#FCFCFD;' });
        var mkBtn = function (label, disabled, fn) {
          var b = Dom.el('button', { type: 'button', style: 'background:' + (disabled ? '#F3F4F6' : '#fff') + ';border:1px solid #E5E7EB;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;color:' + (disabled ? '#9CA3AF' : '#159FB4') + ';cursor:' + (disabled ? 'default' : 'pointer') + ';' }, label);
          if (!disabled) b.addEventListener('click', fn);
          return b;
        };
        pager.appendChild(mkBtn('‹ Newer', _npage <= 1, function () { _npage--; paint(); }));
        pager.appendChild(Dom.el('div', { style: 'font-size:12.5px;color:#6B7280;font-weight:600;' }, 'Showing ' + (start + 1) + '–' + end + ' of ' + total));
        pager.appendChild(mkBtn('Older ›', _npage >= pages, function () { _npage++; paint(); }));
        listWrap.appendChild(pager);
      }
    }

    function renderRow(n) {
      var icon = TYPE_ICONS[n.type] || '🔔';
      var row = Dom.el('div', { style: 'display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;background:' + (n.read_at ? 'white' : '#EFF6FF') + ';position:relative;' });
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

      row.appendChild(Dom.el('div', { style: 'font-size:22px;width:36px;flex-shrink:0;text-align:center;padding-top:2px;' }, icon));

      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      body.appendChild(Dom.el('div', { style: 'font-weight:' + (n.read_at ? '600' : '700') + ';font-size:14px;color:#111827;' }, n.title || '(no title)'));
      if (n.body) body.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;margin-top:3px;line-height:1.4;' }, n.body));
      var meta = Dom.el('div', { style: 'color:#64748B;font-size:11px;margin-top:4px;' });
      meta.textContent = (n.type || 'system') + ' · ' + relTime(n.created_at) + ' · ' + fmtTime(n.created_at);
      body.appendChild(meta);
      row.appendChild(body);

      // Per-row delete — the common case is binning one notification, and making
      // someone enter select-mode for that is a tax.
      var del = Dom.el('button', {
        type: 'button', 'aria-label': 'Delete notification',
        style: 'background:none;border:none;color:#CBD5E1;font-size:16px;cursor:pointer;padding:6px 2px;flex-shrink:0;align-self:flex-start;',
      }, '🗑');
      del.addEventListener('mouseenter', function () { del.style.color = '#DC2626'; });
      del.addEventListener('mouseleave', function () { del.style.color = '#CBD5E1'; });
      del.addEventListener('click', function (e) {
        e.stopPropagation();
        // Deleting is not undoable — ask first. A stray tap on a 16px bin icon
        // shouldn't silently destroy something.
        var go = function () {
          var prev = cache.slice();
          cache = cache.filter(function (r) { return r.id !== n.id; });   // optimistic
          paint();
          Api.delete('/notifications/' + n.id).catch(function () {
            cache = prev;                                                  // put it back
            paint();
            if (KT.toast) KT.toast('⚠️', 'Could not delete', 'Please try again.', '#B91C1C');
          });
        };
        ktConfirmThen('Delete this notification? This cannot be undone.', go);
      });
      row.appendChild(del);

      row.addEventListener('click', function () {
        if (selecting) {
          selected[n.id] = !selected[n.id];
          paintSelBar(); paint();
          return;
        }
        if (!n.read_at) {
          // Optimistic update
          n.read_at = new Date().toISOString();
          paint();
          Api.patch('/notifications/' + n.id + '/read', {})
            .catch(function () { n.read_at = null; paint(); });
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
          var dest = (d && (d.link || d.url)) || '';
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

    markAll.addEventListener('click', async function () {
      var unread = cache.filter(function (r) { return !r.read_at; });
      if (!unread.length) return;
      if (!await KT.confirm('Mark all ' + unread.length + ' as read?')) return;
      markAll.disabled = true; markAll.textContent = 'Marking…';
      var done = 0;
      unread.forEach(function (n) {
        Api.patch('/notifications/' + n.id + '/read', {})
          .catch(function () {})
          .finally(function () {
            n.read_at = new Date().toISOString();
            done++;
            if (done === unread.length) {
              markAll.disabled = false; markAll.textContent = 'Mark all read';
              paint();
              if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
            }
          });
      });
    });

    // v22p45: notifications live under /parent/* in the current routes
    // (NotificationController::mine is gated for guardian only). Future
    // ships should extend this to staff via a new endpoint.
    Api.get('/notifications').then(function (data) {
      cache = (data && data.notifications) ? data.notifications : (Array.isArray(data) ? data : []);
      paint();
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'educator', 'guardian', 'platform_admin', 'home_visitor', 'sales_rep'].forEach(function (r) {
      Shell.registerScreen(r + ':notifications', render);
    });
  }
  KT.Notifications = { render: render };
})(window);
