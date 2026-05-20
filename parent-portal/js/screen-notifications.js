/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p45 — Notifications inbox
   Hash: #notifications
   Lists every notification for the current user. Click to mark read.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function fmtTime(t) { if (!t) return '—'; try { return new Date(t).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return t; } }
  function relTime(t) {
    if (!t) return '';
    var ms = Date.now() - new Date(t).getTime();
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
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:860px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#7C3AED 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">🔔 INBOX</div><h1>Notifications</h1><div class="kt-hero-sub">Every alert from Kiddietrac for your account, newest first. Tap one to mark it read.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:18px 0;' });
    var filter = Dom.el('select', { style: 'background:white;border:1px solid #D1D5DB;padding:7px 12px;border-radius:8px;font-size:13px;color:#374151;' });
    [['','All'],['unread','Unread only'],['read','Read only']].forEach(function (o) {
      filter.appendChild(Dom.el('option', { value: o[0] }, o[1]));
    });
    bar.appendChild(filter);
    var markAll = Dom.el('button', { style: 'background:white;color:#1F6080;border:1px solid #1F6080;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;' }, 'Mark all read');
    bar.appendChild(markAll);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    var cache = [];

    function paint() {
      Dom.clear(listWrap);
      var rows = cache;
      if (filter.value === 'unread') rows = rows.filter(function (r) { return !r.read_at; });
      if (filter.value === 'read') rows = rows.filter(function (r) { return r.read_at; });
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'Inbox zero. 🎉'));
        return;
      }
      rows.forEach(function (n) { listWrap.appendChild(renderRow(n)); });
    }

    function renderRow(n) {
      var icon = TYPE_ICONS[n.type] || '🔔';
      var row = Dom.el('div', { style: 'display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;background:' + (n.read_at ? 'white' : '#EFF6FF') + ';position:relative;' });
      row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
      row.addEventListener('mouseleave', function () { row.style.background = n.read_at ? 'white' : '#EFF6FF'; });

      if (!n.read_at) {
        row.appendChild(Dom.el('div', { style: 'width:8px;height:8px;border-radius:50%;background:#3B82F6;position:absolute;left:6px;top:22px;' }));
      }

      row.appendChild(Dom.el('div', { style: 'font-size:22px;width:36px;flex-shrink:0;text-align:center;padding-top:2px;' }, icon));

      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      body.appendChild(Dom.el('div', { style: 'font-weight:' + (n.read_at ? '600' : '700') + ';font-size:14px;color:#111827;' }, n.title || '(no title)'));
      if (n.body) body.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;margin-top:3px;line-height:1.4;' }, n.body));
      var meta = Dom.el('div', { style: 'color:#9CA3AF;font-size:11px;margin-top:4px;' });
      meta.textContent = (n.type || 'system') + ' · ' + relTime(n.created_at) + ' · ' + fmtTime(n.created_at);
      body.appendChild(meta);
      row.appendChild(body);

      row.addEventListener('click', function () {
        if (!n.read_at) {
          // Optimistic update
          n.read_at = new Date().toISOString();
          paint();
          Api.patch('/notifications/' + n.id + '/read', {})
            .catch(function () { n.read_at = null; paint(); });
        }
        // Deep-link: try data.url if present
        try {
          var d = typeof n.data === 'string' ? JSON.parse(n.data) : (n.data || {});
          if (d && d.url) { window.location.hash = d.url.replace(/^\/?#?/, '#'); }
        } catch (e) {}
      });

      return row;
    }

    filter.addEventListener('change', paint);

    markAll.addEventListener('click', function () {
      var unread = cache.filter(function (r) { return !r.read_at; });
      if (!unread.length) return;
      if (!confirm('Mark all ' + unread.length + ' as read?')) return;
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
    ['agency_admin', 'centre_director', 'educator', 'guardian', 'platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':notifications', render);
    });
  }
  KT.Notifications = { render: render };
})(window);
