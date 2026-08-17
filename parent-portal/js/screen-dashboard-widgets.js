/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — extra dashboard widgets (2026-07-09).
   Month calendar (dots upcoming birthdays) · Team on the floor (names click to
   quick-message) · Upcoming birthdays · Security alerts. Self-contained: watches
   for #kt-widgets-grid (the agency/director overview) and injects a widget row.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Api) return;
  if (window.__ktDashWidgets) return; window.__ktDashWidgets = true;
  var Api = KT.Api;

  var PAL = ['#7C3AED', '#E91E8C', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#0f9d6b', '#DB2777', '#0891B2'];
  function colorFor(s) { s = String(s || ''); var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return PAL[h % PAL.length]; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // Photos come back as /storage/... on the API host; make them absolute.
  function wAbs(u) { if (!u) return ''; if (/^https?:\/\//.test(u)) return u; var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; return base.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
  // Avatar HTML: photo → emoji face (via the shared helper). Never initials.
  function wAvatar(name, photo, sex, size, isChild) {
    if (window.KT && KT.avatar) return KT.avatar(name, { size: size, photoUrl: wAbs(photo), sex: sex, kind: isChild ? 'child' : undefined });
    var em = (window.KT && KT.emojiFor) ? KT.emojiFor(sex, isChild) : (isChild ? '🧒' : '🧑');
    return '<span style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + colorFor(name) + ';font-size:' + Math.round(size * 0.6) + 'px;line-height:1;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">' + em + '</span>';
  }

  // All widgets share one fixed height + a flex column so they line up perfectly;
  // content that overflows scrolls inside the card (never truncated).
  var CARD = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:18px;height:300px;display:flex;flex-direction:column;box-sizing:border-box;overflow:hidden;';
  var TITLE = 'font-family:var(--kt-font-display,inherit);font-weight:700;font-size:15px;margin:0 0 13px;color:var(--kt-text,#0D1B2A);display:flex;align-items:center;gap:8px;';

  function calendarHtml() {
    var now = new Date();
    var y = now.getFullYear(), mo = now.getMonth(), today = now.getDate();
    var label = now.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    var first = new Date(y, mo, 1).getDay();
    var days = new Date(y, mo + 1, 0).getDate();
    var cells = '';
    ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function (d) { cells += '<div style="text-align:center;font-size:10px;font-weight:800;color:#64748B;padding:3px 0;">' + d + '</div>'; });
    for (var i = 0; i < first; i++) cells += '<div></div>';
    for (var dn = 1; dn <= days; dn++) {
      var t = dn === today;
      cells += '<div data-cal-day="' + dn + '" style="position:relative;text-align:center;font-size:12px;padding:6px 0;border-radius:8px;' + (t ? 'background:var(--brand-blue,#1F6080);color:#fff;font-weight:800;' : 'color:#334155;') + '">' + dn + '</div>';
    }
    return '<div style="' + CARD + '"><div style="' + TITLE + '">📅 <span>' + esc(label) + '</span></div>'
      + '<div class="kt-cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">' + cells + '</div>'
      + '<div class="kt-cal-events" style="margin-top:12px;font-size:12px;color:#64748B;">Loading events…</div></div>';
  }

  function injectStyleOnce() {
    if (document.getElementById('kt-dash-extra-style')) return;
    var s = document.createElement('style'); s.id = 'kt-dash-extra-style';
    s.textContent =
      // Equal-height cards on a tidy grid.
      '#kt-dash-extra{align-items:stretch;}'
      // The scrolling content region of every widget (title stays pinned on top).
      + '#kt-dash-extra .bd{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;}'
      + '#kt-dash-extra .kt-cal-events{flex:1 1 auto;min-height:0;overflow-y:auto;}'
      // Recent activity is a MOBILE-only widget (it duplicates the desktop
      // "Recent activity" section, which the user didn't want added to desktop).
      + '@media(min-width:769px){#kt-w-activity{display:none !important;}}';
    document.head.appendChild(s);
  }

  function inject() {
    var grid = document.getElementById('kt-widgets-grid');
    if (!grid || document.getElementById('kt-dash-extra')) return;
    var section = grid.closest('section') || grid.parentNode;
    if (!section || !section.parentNode) return;
    injectStyleOnce();
    var extra = document.createElement('div');
    extra.id = 'kt-dash-extra';
    extra.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin:14px 0 4px;';
    extra.innerHTML = calendarHtml()
      + '<div id="kt-w-presence" style="' + CARD + '"><div style="' + TITLE + '">👩‍🏫 <span>Team on the floor</span></div><div class="bd" style="color:#64748B;font-size:13px;">Loading…</div></div>'
      + '<div id="kt-w-bdays" style="' + CARD + '"><div style="' + TITLE + '">🎂 <span>Upcoming birthdays</span></div><div class="bd" style="color:#64748B;font-size:13px;">Loading…</div></div>'
      + '<div id="kt-w-security" style="' + CARD + '"><div style="' + TITLE + '">🛡️ <span>Security alerts</span></div><div class="bd" style="color:#64748B;font-size:13px;">Loading…</div></div>'
      + '<div id="kt-w-activity" style="' + CARD + '"><div style="' + TITLE + '">🔔 <span>Recent activity</span></div><div class="bd" style="color:#64748B;font-size:13px;">Loading…</div></div>';
    section.parentNode.insertBefore(extra, section.nextSibling);
    fillPresence(); fillBirthdays(); fillSecurity(); fillActivity();
  }

  // ── Recent activity: logins, clock in/out, incidents (shows on mobile too) ──
  function timeAgo(ts) {
    try {
      // Zone-less server timestamps are UTC — append 'Z' so a browser behind UTC
      // doesn't read them as local time and land them in the future ("just now").
      var v = String(ts || '').trim().replace(' ', 'T');
      if (v && !/(Z|[+-]\d{2}:?\d{2})$/.test(v)) v += 'Z';
      var d = new Date(v);
      var s = Math.floor((Date.now() - d.getTime()) / 1000);
      if (s < 0) s = 0;
      if (s < 60) return 'just now';
      if (s < 3600) return Math.floor(s / 60) + 'm ago';
      if (s < 86400) return Math.floor(s / 3600) + 'h ago';
      return Math.floor(s / 86400) + 'd ago';
    } catch (e) { return ''; }
  }
  function fillActivity() {
    var bd = document.querySelector('#kt-w-activity .bd'); if (!bd) return;
    Api.get('/agency/dashboard').then(function (d) {
      var list = (d && d.recent_activity) || [];
      if (!list.length) { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">No recent activity.</div>'; return; }
      bd.innerHTML = list.slice(0, 8).map(function (e) {
        return '<div style="display:flex;align-items:center;gap:9px;padding:6px 0;border-bottom:1px solid #F1F5F9;">'
          + '<span style="font-size:15px;flex-shrink:0;">' + esc(e.icon || '•') + '</span>'
          + '<span style="flex:1;font-size:13px;color:#334155;line-height:1.35;">' + esc(e.text || e.action || '') + '</span>'
          + '<span style="font-size:11px;color:#64748B;white-space:nowrap;flex-shrink:0;">' + esc(timeAgo(e.created_at)) + '</span></div>';
      }).join('');
    }).catch(function () { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Couldn’t load activity.</div>'; });
  }

  // ── Team on the floor: names click to send a one-tap message ──
  function fillPresence() {
    var bd = document.querySelector('#kt-w-presence .bd'); if (!bd) return;
    Api.get('/admin/floor-staff').then(function (d) {
      var staff = (d && d.staff) || [];
      if (!staff.length) { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">No staff active right now.</div>'; return; }
      bd.innerHTML = '<div style="font-size:26px;font-weight:800;color:var(--brand-blue,#1F6080);line-height:1;margin-bottom:11px;">' + staff.length + '<span style="font-size:13px;font-weight:600;color:#64748B;"> on the floor</span></div>';
      staff.slice(0, 10).forEach(function (s) {
        var row = document.createElement('button');
        row.type = 'button';
        row.title = 'Send ' + s.name + ' a quick message';
        row.style.cssText = 'width:100%;text-align:left;display:flex;align-items:center;gap:9px;padding:6px 4px;border:none;background:none;cursor:pointer;border-radius:8px;';
        row.onmouseenter = function () { row.style.background = '#F1F5F9'; };
        row.onmouseleave = function () { row.style.background = 'none'; };
        var pDot = (s.presence === 'available' || s.presence === 'idle')
          ? '<span title="' + (s.presence === 'available' ? 'Available' : 'Idle') + '" style="display:inline-block;width:9px;height:9px;border-radius:50%;flex-shrink:0;background:' + (s.presence === 'available' ? '#16A34A' : '#F59E0B') + ';box-shadow:0 0 0 2px #fff;"></span>'
          : '';
        row.innerHTML = wAvatar(s.name, s.photo_url, s.sex, 26)
          + pDot
          + '<span style="font-size:13px;font-weight:600;color:#334155;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(s.name) + '</span>'
          + (s.role_label ? '<span style="font-size:10px;font-weight:700;color:#1F6080;background:#E8F1F5;border-radius:999px;padding:2px 8px;flex-shrink:0;">' + esc(s.role_label) + '</span>' : '')
          + '<span style="font-size:12px;color:var(--brand-blue,#1F6080);flex-shrink:0;">💬</span>';
        row.addEventListener('click', function () { quickMessage(s, bd); });
        bd.appendChild(row);
      });
    }).catch(function () { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Unavailable.</div>'; });
  }

  function quickMessage(staff, container) {
    var box = document.createElement('div');
    box.style.cssText = 'margin-top:8px;padding:10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;';
    box.innerHTML = '<div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Message ' + esc(staff.name) + '</div>';
    var ta = document.createElement('textarea');
    ta.placeholder = 'Type a quick message…';
    ta.style.cssText = 'width:100%;box-sizing:border-box;min-height:56px;border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;resize:vertical;';
    box.appendChild(ta);
    var status = document.createElement('div'); status.style.cssText = 'font-size:12px;margin-top:5px;min-height:14px;';
    var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:6px;justify-content:flex-end;margin-top:6px;';
    var cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = 'Cancel';
    cancel.style.cssText = 'border:1px solid #CBD5E1;background:#fff;color:#475569;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer;';
    cancel.onclick = function () { box.remove(); };
    var send = document.createElement('button'); send.type = 'button'; send.textContent = 'Send';
    send.style.cssText = 'border:none;background:var(--brand-blue,#1F6080);color:#fff;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;';
    send.onclick = function () {
      if (!ta.value.trim()) { status.style.color = '#B45309'; status.textContent = 'Type a message first.'; return; }
      send.disabled = true; send.textContent = 'Sending…';
      Api.post('/admin/quick-notify', { user_id: staff.user_id, message: ta.value.trim() })
        .then(function () { box.innerHTML = '<div style="color:#16A34A;font-size:13px;font-weight:600;">✓ Sent to ' + esc(staff.name) + '.</div>'; setTimeout(function () { box.remove(); }, 1800); })
        .catch(function (e) { send.disabled = false; send.textContent = 'Send'; status.style.color = '#B91C1C'; status.textContent = 'Could not send: ' + (e && e.message ? e.message : 'try again'); });
    };
    row.appendChild(cancel); row.appendChild(send);
    box.appendChild(status); box.appendChild(row);
    // one compose at a time
    var old = container.querySelector('.kt-qmsg'); if (old) old.remove();
    box.className = 'kt-qmsg';
    container.appendChild(box);
    ta.focus();
  }

  // ── Upcoming birthdays widget + calendar dots ──
  function fillBirthdays() {
    var bd = document.querySelector('#kt-w-bdays .bd'); if (!bd) return;
    Api.get('/admin/upcoming-birthdays').then(function (d) {
      var list = (d && d.birthdays) || [];
      if (!list.length) { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">No birthdays in the next 30 days.</div>'; }
      else {
        bd.innerHTML = list.slice(0, 8).map(function (b) {
          return '<div style="display:flex;align-items:center;gap:9px;padding:6px 0;border-bottom:1px solid #F4F6F9;">'
            + wAvatar(b.name, b.photo_url, b.sex, 28, b.kind === 'child')
            + '<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:700;color:#334155;">' + esc(b.name) + (b.is_today ? ' <span style="color:#E91E8C;">🎉 today!</span>' : '') + '</div>'
            + '<div style="font-size:12px;color:#64748B;">' + esc(b.display) + ' · turning ' + b.turning + '</div></div></div>';
        }).join('');
      }
      // dot the calendar days that fall in the CURRENT month
      var thisMonth = new Date().getMonth() + 1;
      var events = [];
      list.forEach(function (b) {
        if (b.month === thisMonth) {
          var cell = document.querySelector('.kt-cal-grid [data-cal-day="' + b.day + '"]');
          if (cell && !cell.querySelector('.kt-cal-dot')) {
            var dot = document.createElement('span');
            dot.className = 'kt-cal-dot';
            dot.style.cssText = 'position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#E91E8C;';
            cell.appendChild(dot);
          }
        }
      });
      var ev = document.querySelector('.kt-cal-events');
      if (ev) {
        var soon = list.slice(0, 3).map(function (b) { return '🎂 ' + esc(b.name) + ' · ' + esc(b.display); });
        ev.innerHTML = soon.length
          ? '<div style="font-weight:700;color:#475569;margin-bottom:4px;">Upcoming</div>' + soon.map(function (t) { return '<div style="padding:2px 0;">' + t + '</div>'; }).join('')
          : '<span style="color:#64748B;">No upcoming events.</span>';
      }
    }).catch(function () {
      bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Unavailable.</div>';
      var ev = document.querySelector('.kt-cal-events'); if (ev) ev.textContent = '';
    });
  }

  function fillSecurity() {
    var bd = document.querySelector('#kt-w-security .bd'); if (!bd) return;
    Api.get('/platform/security-alerts').then(function (d) {
      var rows = (d && (d.alerts || d.data || (Array.isArray(d) ? d : []))) || [];
      var open = rows.filter(function (a) { return !a.resolved_at && !a.resolved; });
      if (!open.length) { bd.innerHTML = '<div style="color:#16A34A;font-size:14px;font-weight:700;">✓ All clear — no active alerts.</div>'; return; }
      var TYPE_LABEL = { brute_force_ip: 'Brute force (IP)', mfa_hammering: 'MFA hammering', credential_stuffing: 'Credential stuffing', account_takeover: 'Account takeover' };
      bd.innerHTML = open.slice(0, 5).map(function (a) {
        var sev = String(a.severity || a.level || 'info').toLowerCase();
        var col = /crit|high/.test(sev) ? '#DC2626' : (/med|warn/.test(sev) ? '#B45309' : '#16A34A');
        var label = TYPE_LABEL[a.type] || esc(a.type || 'Alert');
        return '<div class="kt-w-sec-row" style="display:flex;gap:9px;padding:8px 0;border-bottom:1px solid #F1F5F9;cursor:pointer;">'
          + '<span style="width:8px;height:8px;border-radius:50%;background:' + col + ';margin-top:6px;flex-shrink:0;"></span>'
          + '<div style="flex:1;min-width:0;">'
          +   '<div style="display:flex;align-items:center;gap:6px;">'
          +     '<span style="font-size:13px;font-weight:600;color:#334155;">' + label + '</span>'
          +     '<span style="font:700 9.5px/1 ui-monospace,monospace;text-transform:uppercase;padding:2px 6px;border-radius:100px;color:' + col + ';border:1px solid ' + col + '55;">' + esc(sev) + '</span>'
          +   '</div>'
          +   '<div style="font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(a.details || a.message || a.subject || '') + '</div>'
          +   '<div style="font-size:11px;color:#94A3B8;margin-top:1px;">' + esc(a.subject || '') + (a.created_at ? ' · ' + esc(timeAgo(a.created_at)) : '') + '</div>'
          + '</div>'
          + '<span style="color:#CBD5E1;align-self:center;font-size:16px;">›</span>'
          + '</div>';
      }).join('');
      bd.querySelectorAll('.kt-w-sec-row').forEach(function (r) {
        r.onclick = function () {
          if (window.KT && KT.Shell && KT.Shell.navigate) KT.Shell.navigate('security-alerts');
          else location.hash = '#security-alerts';
        };
      });
    }).catch(function () { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Unavailable.</div>'; });
  }

  function start() {
    var m = document.getElementById('appMain');
    if (!m) { setTimeout(start, 300); return; }
    new MutationObserver(function () { if (document.getElementById('kt-widgets-grid')) inject(); }).observe(m, { childList: true, subtree: true });
    if (document.getElementById('kt-widgets-grid')) inject();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})(window);
