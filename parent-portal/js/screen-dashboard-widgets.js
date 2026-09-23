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
  var CARD = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:18px;height:404px;display:flex;flex-direction:column;box-sizing:border-box;overflow:hidden;';
  var TITLE = 'font-family:var(--kt-font-display,inherit);font-weight:700;font-size:15px;margin:0 0 13px;color:var(--kt-text,#0D1B2A);display:flex;align-items:center;gap:8px;';

  function calendarHtml() {
    var now = new Date();
    var y = now.getFullYear(), mo = now.getMonth(), today = now.getDate();
    var label = now.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    var first = new Date(y, mo, 1).getDay();
    var days = new Date(y, mo + 1, 0).getDate();
    var cells = '';
    ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function (d) { cells += '<div style="text-align:center;font-size:10px;font-weight:800;color:#64748B;padding:2px 0;">' + d + '</div>'; });
    for (var i = 0; i < first; i++) cells += '<div></div>';
    for (var dn = 1; dn <= days; dn++) {
      var t = dn === today;
      cells += '<div data-cal-day="' + dn + '" style="position:relative;text-align:center;font-size:12px;padding:4px 0;border-radius:8px;' + (t ? 'background:var(--brand-blue,#1F6080);color:#fff;font-weight:800;' : 'color:#334155;') + '">' + dn + '</div>';
    }
    return '<div style="' + CARD + '"><div style="' + TITLE + '">📅 <span>' + esc(label) + '</span></div>'
      + '<div class="kt-cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">' + cells + '</div>'
      + '<div class="kt-cal-events" style="margin-top:10px;padding-top:9px;border-top:1px solid #F1F5F9;'
        + 'font-size:12px;color:#64748B;">Loading events…</div></div>';
  }

  function injectStyleOnce() {
    if (document.getElementById('kt-dash-extra-style')) return;
    var s = document.createElement('style'); s.id = 'kt-dash-extra-style';
    s.textContent =
      // Equal-height cards on a tidy grid.
      '#kt-dash-extra{align-items:stretch;}'
      // The scrolling content region of every widget (title stays pinned on top).
      + '#kt-dash-extra .bd{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;}'
      + '#kt-dash-extra .kt-cal-events{flex:1 1 auto;min-height:0;overflow-y:auto;'
        + 'overscroll-behavior:contain;}'
      /* macOS and the WebView hide the overlay scrollbar until you are already
         scrolling, so a reader who cannot see one concludes there is nothing below the
         fold. These regions keep a visible bar. */
      + '#kt-dash-extra .bd,#kt-dash-extra .kt-cal-events{scrollbar-width:thin;'
        + 'scrollbar-color:#CBD5E1 transparent;}'
      + '#kt-dash-extra .bd::-webkit-scrollbar,'
        + '#kt-dash-extra .kt-cal-events::-webkit-scrollbar{width:8px;}'
      + '#kt-dash-extra .bd::-webkit-scrollbar-thumb,'
        + '#kt-dash-extra .kt-cal-events::-webkit-scrollbar-thumb{'
        + 'background:#CBD5E1;border-radius:99px;border:2px solid #fff;}'
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
    fillPresence(); fillBirthdays(); fillSecurity(); fillActivity(); fillCalendarEvents();
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
      /* The dots and the strip under the grid used to be drawn from here, which is why
         a closure or four absences left the day looking empty. fillCalendarEvents owns
         both now, from a feed that knows every kind of event. */
    }).catch(function () {
      bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Unavailable.</div>';
    });
  }

  /* -- The calendar card: dots on the grid, and the day's events beneath ------
     One feed, the same one the calendar screen draws, so the two never disagree. */
  var CAL_TONE = {
    closed:    { fg: '#B91C1C', dot: '#DC2626' },
    away:      { fg: '#B45309', dot: '#F59E0B' },
    pending:   { fg: '#92400E', dot: '#F59E0B' },
    celebrate: { fg: '#BE185D', dot: '#E91E8C' }
  };
  // When a day carries more than one event the dot takes the most consequential tone:
  // a closure must not be hidden behind a birthday.
  var TONE_RANK = { closed: 4, pending: 3, away: 2, celebrate: 1 };
  function calTone(t) { return CAL_TONE[t] || { fg: '#475569', dot: '#94A3B8' }; }

  function fillCalendarEvents() {
    var ev = document.querySelector('.kt-cal-events');
    if (!ev) return;
    var now = new Date();
    /* Local parts, not toISOString(): west of UTC that shifts the date back a day, and
       "today" on this card would be yesterday's events all evening. */
    var ymd = function (d) {
      return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
        + '-' + ('0' + d.getDate()).slice(-2);
    };
    var todayStr = ymd(now);
    // To the end of the visible month at least, so every dot the grid can show is asked
    // for; the overshoot keeps the list useful near a month boundary.
    var to = new Date(now.getFullYear(), now.getMonth() + 1, 30);

    Api.get('/director/calendar/overlays?from=' + todayStr + '&to=' + ymd(to)).then(function (d) {
      var events = (d && d.events) || [];

      // -- dots --
      var monthPrefix = todayStr.slice(0, 7);
      var byDay = {};
      events.forEach(function (e) {
        if (!e || !e.date) return;
        (byDay[e.date] = byDay[e.date] || []).push(e);
      });
      Object.keys(byDay).forEach(function (date) {
        if (date.slice(0, 7) !== monthPrefix) return;
        var dn = parseInt(date.slice(8, 10), 10);
        var cell = document.querySelector('.kt-cal-grid [data-cal-day="' + dn + '"]');
        if (!cell || cell.querySelector('.kt-cal-dot')) return;
        var best = byDay[date].reduce(function (a, b) {
          return (TONE_RANK[b.tone] || 0) > (TONE_RANK[a.tone] || 0) ? b : a;
        });
        var dot = document.createElement('span');
        dot.className = 'kt-cal-dot';
        dot.title = byDay[date].length + (byDay[date].length === 1 ? ' event' : ' events');
        dot.style.cssText = 'position:absolute;bottom:2px;left:50%;transform:translateX(-50%);'
          + 'width:5px;height:5px;border-radius:50%;background:'
          // On the filled "today" cell a coloured dot disappears; white reads on it.
          + (dn === now.getDate() ? '#fff' : calTone(best.tone).dot) + ';';
        cell.appendChild(dot);
      });

      // -- the list --
      var row = function (e) {
        var t = calTone(e.tone);
        return '<div style="display:flex;gap:7px;padding:3px 0;align-items:baseline;">'
          + '<span style="flex:0 0 auto;font-size:12px;">' + esc(e.icon || '•') + '</span>'
          + '<div style="min-width:0;flex:1;">'
            + '<div style="color:#334155;font-weight:600;overflow:hidden;text-overflow:ellipsis;'
              + 'white-space:nowrap;">' + esc(e.title) + '</div>'
            + (e.detail ? '<div style="color:' + t.fg + ';font-size:11.5px;overflow:hidden;'
                + 'text-overflow:ellipsis;white-space:nowrap;">' + esc(e.detail) + '</div>' : '')
          + '</div></div>';
      };
      var head = function (txt) {
        return '<div style="font-weight:800;color:#475569;font-size:11px;letter-spacing:.04em;'
          + 'text-transform:uppercase;margin:7px 0 2px;">' + esc(txt) + '</div>';
      };

      // Today is always drawn, even when empty: "nothing today" is an answer, and a
      // heading that vanishes on a quiet day teaches people to stop reading the card.
      var html = head('Today')
        + ((byDay[todayStr] || []).length
            ? byDay[todayStr].map(row).join('')
            : '<div style="color:#94A3B8;padding:3px 0;">Nothing scheduled.</div>');

      /* Local midnight from numeric parts. kt-tz-global.js parses a zone-less string as
         UTC, so new Date('2026-08-30T00:00:00') is 8pm on the 29th here — which labelled
         Sunday the 30th as "SATURDAY". The day COUNT was right either way (both sides
         shift equally), so only the name gave it away. */
      var ymdToLocalDate = function (v) {
        var p = String(v || '').split('-');
        return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
      };
      var ahead = Object.keys(byDay).filter(function (x) { return x > todayStr; }).sort();
      ahead.slice(0, 8).forEach(function (date) {
        var dt = ymdToLocalDate(date);
        var diff = Math.round((dt - ymdToLocalDate(todayStr)) / 86400000);
        var label = diff === 1 ? 'Tomorrow'
          : (diff < 7 ? dt.toLocaleDateString(undefined, { weekday: 'long' })
                      : dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }));
        html += head(label) + byDay[date].map(row).join('');
      });

      ev.innerHTML = html;
    }).catch(function () {
      ev.innerHTML = '<span style="color:#94A3B8;">Couldn’t load events.</span>';
    });
  }

  /* Is the signed-in user a PLATFORM admin (not merely an agency admin)?
     Checked the same way kt-topbar.js and kt-impersonate.js do, plus the flag the shell
     stores, because different sign-in paths populate different fields. */
  function ktIsPlatformAdmin() {
    try { if (sessionStorage.getItem('kt_is_platform_admin') === '1') { return true; } } catch (e) {}
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return !!(u.is_platform_admin || u.role === 'platform_admin' || u.role_key === 'platform_admin'
        || (Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1));
    } catch (e) { return false; }
  }

  /* CLEARING THE LOG FROM THE CARD, not only from the full screen.

     The button existed on #security-alerts and nowhere else, so anyone working from
     Agency Overview - which is where these alerts are actually noticed - had no way to
     tidy the log without first finding the other screen. Same endpoint, same rules: it
     deletes ACKNOWLEDGED alerts only, the server enforces that, and the clear is itself
     written to the audit log.

     It is drawn whenever there is something it could remove, including when every alert
     has been acknowledged and the card is otherwise showing "All clear" - which is
     precisely the moment somebody wants to empty the log. */
  function secClearBar(bd, resolvedCount, openCount) {
    /* ALWAYS DRAWN, even with nothing to clear yet.
 
       Hiding it until something is acknowledged means the control is missing exactly
       when somebody goes looking for it, and they report it as absent rather than as
       not-yet-applicable - which is how this went unnoticed twice. The full screen
       already shows it unconditionally and explains on click; the card now matches. */
    var bar = document.createElement('div');
    bar.style.cssText = 'margin-top:10px;padding-top:9px;border-top:1px solid #F1F5F9;display:flex;'
      + 'align-items:center;gap:8px;';
    bar.innerHTML = '<span style="font-size:11.5px;color:#94A3B8;flex:1;">'
        + (resolvedCount ? (resolvedCount + ' acknowledged') : 'Nothing acknowledged yet') + '</span>'
      + '<button type="button" class="kt-w-sec-clear" title="Removes acknowledged alerts only. Open alerts are never cleared."'
      + ' style="font-size:12px;font-weight:700;padding:5px 11px;border-radius:8px;border:1px solid #F2C9C3;'
      + 'background:#fff;color:#BE4038;cursor:pointer;white-space:nowrap;">Clear log</button>';
    /* PINNED BELOW THE LIST, NOT INSIDE IT.
 
       The card body is overflow-y:auto and the alerts routinely overflow it, so a bar
       appended into .bd landed below the fold of an inner scroller - present in the DOM,
       invisible on the card, and reported as missing. It belongs to the CARD, under the
       scrolling area, where it is always on screen.
 
       fillSecurity() re-runs (it is called again after a successful clear) and only
       rewrites .bd, so the previous bar has to be removed or they stack up. */
    var card = bd.parentNode || bd;
    var prev = card.querySelector('.kt-w-sec-clearbar');
    if (prev && prev.parentNode) { prev.parentNode.removeChild(prev); }
    bar.className = 'kt-w-sec-clearbar';
    card.appendChild(bar);

    bar.querySelector('.kt-w-sec-clear').onclick = function (ev) {
      /* The rows above are click-through to the full screen; without this the confirm
         opens and the card navigates away underneath it. */
      ev.stopPropagation();
      var btn = ev.currentTarget;
      /* Say why rather than doing nothing. An unread alert is never cleared - that is
         the whole guarantee of the log - so the answer is "acknowledge them first", not
         a dead button. */
      if (!resolvedCount) {
        if (KT.toast) {
          KT.toast('ℹ', 'Nothing to clear',
            openCount ? 'Acknowledge the open alerts first — unread alerts are never cleared.'
                      : 'The log is already empty.', '#0369A1');
        }
        return;
      }
      Promise.resolve(
        KT.confirm
          ? KT.confirm({
              title: 'Clear ' + resolvedCount + ' acknowledged alert' + (resolvedCount === 1 ? '' : 's') + '?',
              description: 'They are deleted from the log permanently. '
                + (openCount ? ('The ' + openCount + ' alert' + (openCount === 1 ? '' : 's')
                    + ' still open will be left in place. ') : '')
                + 'Clearing is itself recorded in the audit log.',
              okLabel: 'Clear log'
            })
          : window.confirm('Clear ' + resolvedCount + ' acknowledged alert(s)?')
      ).then(function (ok) {
        if (!ok) { return; }
        btn.disabled = true; btn.textContent = 'Clearing\u2026';
        Api.post('/platform/security-alerts/clear', {}).then(function (r) {
          if (KT.toast) {
            KT.toast('\u2713', 'Log cleared',
              ((r && r.cleared) ? r.cleared : resolvedCount) + ' acknowledged alert(s) removed.', '#16A34A');
          }
          fillSecurity();
        }).catch(function (e) {
          btn.disabled = false; btn.textContent = 'Clear log';
          if (KT.toast) { KT.toast('\u26A0', 'Could not clear', (e && e.message) || '', '#DC2626'); }
        });
      });
    };
  }

  function fillSecurity() {
    var bd = document.querySelector('#kt-w-security .bd'); if (!bd) return;
    /* /platform/security-alerts is platform_admin only, and this widget was drawn and
       fetched for EVERY admin. An agency admin got a "Security alerts — Unavailable"
       card they can never use, and every dashboard load wrote a 403 into the audit log
       under their name. The card is removed for them rather than left showing an error
       about something that was never theirs to see. */
    if (!ktIsPlatformAdmin()) {
      var card = document.getElementById('kt-w-security');
      if (card && card.parentNode) { card.parentNode.removeChild(card); }
      return;
    }
    Api.get('/platform/security-alerts').then(function (d) {
      var rows = (d && (d.alerts || d.data || (Array.isArray(d) ? d : []))) || [];
      var open = rows.filter(function (a) { return !a.resolved_at && !a.resolved; });
      if (!open.length) {
        bd.innerHTML = '<div style="color:#16A34A;font-size:14px;font-weight:700;">✓ All clear — no active alerts.</div>';
        secClearBar(bd, rows.length - open.length, 0);
        return;
      }
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
      secClearBar(bd, rows.length - open.length, open.length);
    }).catch(function () { bd.innerHTML = '<div style="color:#64748B;font-size:13px;">Unavailable.</div>'; });
  }

  function start() {
    var m = document.getElementById('appMain');
    if (!m) { setTimeout(start, 300); return; }
    /* Re-binds when the shell swaps #appMain (kt:main-swapped); a MutationObserver follows a NODE, and this one used to die silently at the first render. */
    var _wgFn = function () { if (document.getElementById('kt-widgets-grid')) inject(); };
    if (window.KT && KT.observeMain) { KT.observeMain(_wgFn, { childList: true, subtree: true }); }
    else { new MutationObserver(_wgFn).observe(m, { childList: true, subtree: true }); }
    if (document.getElementById('kt-widgets-grid')) inject();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})(window);
