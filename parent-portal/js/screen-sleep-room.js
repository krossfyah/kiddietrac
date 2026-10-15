/* ═══════════════════════════════════════════════════════════════════════
   Sleep room (#sleep-room) — 2026-10-13

   Ontario O. Reg. 137/15 s.33.1 (Licensing Manual Part 5): every sleeping child under 24
   months gets documented DIRECT VISUAL checks at the frequency the centre's sleep policy
   sets — a monitor does not replace them — and infants under 12 months sleep on their back
   unless a physician has directed otherwise in writing.

   A child is asleep from the existing nap_start daily-log entry (Today roster "Nap down",
   the nap quick-log, or "Asleep now" here) until "Woke up" (nap_end). Each check records
   time, educator, position, breathing and colour, and an optional note.

   COUNTDOWNS ARE LOCAL. The phone counts down from the due time the server gave it, and
   re-reads the room only every 90 s while the page is visible (other educators' checks).
   Overdue alerts are sent by ONE scheduled command on the server (sleep-checks:alert), not
   by every phone polling.

   Directors and admins also get a Report tab: per child and nap, every check and every gap
   longer than the interval, with CSV and PDF — what a licensing inspector asks for.
   Settings → Room Controls → Sleep checks (KT.sleepChecks.paintSettings below; was under Notifications until 2026-10-15).
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};

  function Api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function errMsg(e) { return (e && e.message) || 'Something went wrong.'; }
  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function absUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//.test(u)) return u;
    return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u);
  }
  function roles() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').roles || []); } catch (e) { return []; } }
  function hashParam(k) { var m = (location.hash.split('?')[1] || '').match(new RegExp('(?:^|&)' + k + '=([^&]+)')); return m ? decodeURIComponent(m[1]) : ''; }

  /* ── menu: hidden unless sleep checks are on at a centre this person works in ── */
  (function () {
    var st = document.createElement('style');
    st.textContent = 'body:not(.kt-sleep-on) [data-hash="sleep-room"], body:not(.kt-sleep-on) [href="#sleep-room"] { display:none !important; }';
    (document.head || document.documentElement).appendChild(st);
  })();
  var probedFor = null;
  function probe() {
    var A = Api();
    if (!A || !A.get || !document.body) return;
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (!tok) return;
    var bc = document.body.classList;
    if (!/\brole-/.test(document.body.className || '')) return;
    var ag = sessionStorage.getItem('kt_active_agency_id') || '';
    if (probedFor === ag) return;
    if (!(bc.contains('role-educator') || bc.contains('role-centre-director') || bc.contains('role-agency-admin') || bc.contains('role-platform-admin'))) {
      probedFor = ag; bc.remove('kt-sleep-on'); return;
    }
    if (!ag && roles().indexOf('platform_admin') !== -1) return;
    probedFor = ag;
    // Remembered for the session per agency: the menu does not need asking on every load.
    var key = 'kt_sleep_on_' + ag;
    try { var c = sessionStorage.getItem(key); if (c === '1' || c === '0') { bc.toggle('kt-sleep-on', c === '1'); return; } } catch (e) {}
    A.get('/sleep-checks/status').then(function (d) {
      var on = !!(d && d.on);
      bc.toggle('kt-sleep-on', on);
      try { sessionStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    }).catch(function () { bc.remove('kt-sleep-on'); });
  }
  setTimeout(probe, 2500); setTimeout(probe, 8000);
  window.addEventListener('kt:agency-changed', function () { probedFor = null; probe(); });

  var CSS_ID = 'kt-sleep-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.sl-wrap{padding:16px 20px;max-width:1100px;margin:0 auto;}'
      + '.sl-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin-top:12px;}'
      + '.sl-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.sl-i{height:30px;padding:0 9px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.sl-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;color:#0F172A;}'
      + '.sl-b.p{background:#4338CA;border-color:#4338CA;color:#fff;}'
      + '.sl-b.g{background:#047857;border-color:#047857;color:#fff;}'
      + '.sl-b.big{height:44px;padding:0 18px;font-size:15px;border-radius:10px;}'
      + '.sl-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 0;}'
      + '.sl-tab{height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.sl-tab.on{background:#1E1B4B;border-color:#1E1B4B;color:#fff;}'
      + '.sl-kid{display:flex;gap:12px;align-items:center;border:1px solid #E2E8F0;border-left:6px solid #CBD5E1;border-radius:12px;padding:10px 12px;margin-top:10px;background:#fff;flex-wrap:wrap;}'
      + '.sl-kid.asleep{border-left-color:#6366F1;background:#F8FAFF;}'
      + '.sl-kid.soon{border-left-color:#F59E0B;background:#FFFBEB;}'
      + '.sl-kid.over{border-left-color:#DC2626;background:#FEF2F2;border-color:#FCA5A5;}'
      + '.sl-av{position:relative;width:42px;height:42px;border-radius:50%;background:#E0E7FF;color:#3730A3;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;flex:0 0 auto;overflow:hidden;}'
      + '.sl-av img{width:100%;height:100%;object-fit:cover;}'
      + '.sl-main{flex:1 1 180px;min-width:0;}'
      + '.sl-name{font-weight:800;font-size:15px;color:#0F172A;}'
      + '.sl-sub{font-size:12.5px;color:#475569;margin-top:2px;line-height:1.45;}'
      + '.sl-cd{font-variant-numeric:tabular-nums;font-weight:800;font-size:20px;color:#3730A3;min-width:92px;text-align:right;}'
      + '.sl-kid.soon .sl-cd{color:#B45309;}.sl-kid.over .sl-cd{color:#B91C1C;}'
      + '.sl-cdl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748B;text-align:right;}'
      + '.sl-act{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
      + '.sl-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11.5px;font-weight:700;background:#EEF2FF;color:#3730A3;margin-left:4px;}'
      + '.sl-pill.w{background:#FEF3C7;color:#92400E;}.sl-pill.r{background:#FEE2E2;color:#B91C1C;}.sl-pill.g{background:#DCFCE7;color:#166534;}'
      + '.sl-note{font-size:12.5px;color:#64748B;margin-top:6px;line-height:1.5;}'
      + '.sl-pos{display:flex;gap:6px;flex-wrap:wrap;}'
      + '.sl-pos button{height:34px;padding:0 12px;border-radius:8px;border:1.5px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.sl-pos button.on{border-color:#4338CA;background:#EEF2FF;color:#3730A3;}'
      + '.sl-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.sl-t th,.sl-t td{padding:7px 8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.sl-t th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.sl-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px;}'
      + '@media (max-width:600px){.sl-wrap{padding:12px 12px;}.sl-cd{font-size:18px;min-width:80px;}.sl-act{width:100%;}.sl-act .sl-b.big{flex:1 1 auto;}}';
    document.head.appendChild(s);
  }

  var S = { tab: 'room', room: null, data: null, skew: 0, timer: null, refreshAt: 0, rep: {} };

  function initials(n) { return String(n || '?').split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase(); }
  function ageText(m) { return m == null ? '' : (m < 24 ? m + ' mo' : Math.floor(m / 12) + ' yr'); }
  function mmss(ms) {
    var neg = ms < 0; ms = Math.abs(ms);
    var t = Math.floor(ms / 1000), m = Math.floor(t / 60), s = t % 60;
    return (neg ? '+' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  function serverNow() { return Date.now() + S.skew; }

  async function render(main) {
    css();
    var isMgr = roles().some(function (r) { return r === 'centre_director' || r === 'agency_admin' || r === 'platform_admin'; });
    // A bell link names the room (#sleep-room?room=22); otherwise keep the one last picked.
    if (hashParam('room')) S.room = hashParam('room');
    if (hashParam('tab') === 'report' && isMgr) S.tab = 'report';
    if (hashParam('centre') && !S.rep.centre) S.rep.centre = hashParam('centre');
    if (/^\d{4}-\d{2}-\d{2}$/.test(hashParam('date')) && !S.rep.date) S.rep.date = hashParam('date');
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="sl-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#1E1B4B 0%,#3730A3 55%,#6366F1 100%);">'
      + '<div class="kt-hero-greet">😴 SLEEP SUPERVISION</div><h1>Sleep room</h1>'
      + '<div class="kt-hero-sub">Look at each sleeping child directly, then record it. Under 12 months: on their back unless a physician directed otherwise in writing. A monitor does not replace a check.</div></div>'
      + (isMgr ? '<div class="sl-tabs"><button class="sl-tab' + (S.tab === 'room' ? ' on' : '') + '" data-tab="room">😴 Sleep room</button><button class="sl-tab' + (S.tab === 'report' ? ' on' : '') + '" data-tab="report">📋 Report</button></div>' : '')
      + '<div id="sl-body"><div class="sl-card" style="color:#64748B;">Loading…</div></div></div>';
    main.querySelectorAll('[data-tab]').forEach(function (b) {
      b.addEventListener('click', function () { S.tab = b.getAttribute('data-tab'); render(main); });
    });
    var body = main.querySelector('#sl-body');
    if (S.tab === 'report' && isMgr) return paintReport(body);
    return loadRoom(body);
  }

  async function loadRoom(body) {
    var d;
    try { d = await Api().get('/sleep-checks/room' + (S.room ? '?room_id=' + encodeURIComponent(S.room) : '')); }
    catch (e) { body.innerHTML = '<div class="sl-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    S.data = d; S.room = d.room_id; S.refreshAt = Date.now() + 90000;
    var sn = Date.parse(d.now); if (!isNaN(sn)) S.skew = sn - Date.now();
    paintRoom(body);
    startTimer(body);
  }

  function startTimer(body) {
    if (S.timer) clearInterval(S.timer);
    S.timer = setInterval(function () {
      if (!document.body.contains(body)) { clearInterval(S.timer); S.timer = null; return; }
      tick(body);
      if (!document.hidden && Date.now() > S.refreshAt && !document.querySelector('.modal-backdrop')) {
        S.refreshAt = Date.now() + 90000;
        Api().get('/sleep-checks/room?room_id=' + encodeURIComponent(S.room)).then(function (d) {
          if (!document.body.contains(body)) return;
          S.data = d; var sn = Date.parse(d.now); if (!isNaN(sn)) S.skew = sn - Date.now();
          paintRoom(body);
        }).catch(function () {});
      }
    }, 1000);
  }

  function tick(body) {
    body.querySelectorAll('[data-due]').forEach(function (el) {
      var due = Date.parse(el.getAttribute('data-due'));
      var left = due - serverNow();
      var card = el.closest('.sl-kid');
      var lab = card && card.querySelector('.sl-cdl');
      el.textContent = left >= 0 ? mmss(left) : mmss(left);
      if (card) {
        card.classList.toggle('over', left < 0);
        card.classList.toggle('soon', left >= 0 && left < 3 * 60000);
      }
      if (lab) lab.textContent = left < 0 ? 'Overdue by' : 'Next check in';
    });
    var over = body.querySelectorAll('.sl-kid.over').length;
    var ob = body.querySelector('#sl-overdue');
    if (ob) { ob.style.display = over ? '' : 'none'; ob.textContent = '⚠️ ' + over + ' sleep check' + (over === 1 ? ' is' : 's are') + ' overdue. Check now.'; }
  }

  function paintRoom(body) {
    var d = S.data;
    var isMgr = !!d.can_manage;
    if (!d.rooms || !d.rooms.length) { body.innerHTML = '<div class="sl-card">You are not assigned to a room yet.</div>'; return; }
    var multiCentre = {}; d.rooms.forEach(function (r) { multiCentre[r.centre_id] = 1; });
    var showCentre = Object.keys(multiCentre).length > 1;
    var sel = '<select class="sl-i" id="sl-room" style="max-width:100%;">' + d.rooms.map(function (r) {
      return '<option value="' + r.id + '"' + (r.id === d.room_id ? ' selected' : '') + '>' + esc((showCentre ? r.centre_name + ' · ' : '') + r.name) + '</option>';
    }).join('') + '</select>';
    var asleep = (d.children || []).filter(function (c) { return c.nap; });
    var h = '<div class="sl-card"><div class="sl-row" style="justify-content:space-between;">'
      + '<div class="sl-row">' + (d.rooms.length > 1 ? sel : '<b style="font-size:15px;">' + esc(d.room.name) + '</b>') + '</div>'
      + (d.enabled && asleep.length > 1 ? '<button type="button" class="sl-b g big" id="sl-all" data-kt-iconized="1">✓ Check all (' + asleep.length + ')</button>' : '')
      + '</div>';
    if (d.enabled) {
      h += '<div class="sl-note">Your sleep policy: check under-12-month-olds every <b>' + d.policy.infant_minutes + ' min</b> and 12–24-month-olds every <b>'
        + d.policy.toddler_minutes + ' min</b>. ' + asleep.length + ' asleep now.</div>';
    }
    h += '</div>';
    if (!d.enabled) {
      body.innerHTML = h + '<div class="sl-card">Sleep checks are switched off for this centre. An agency admin can turn them on in <a href="#educator-rooms?tab=sleep">Settings → Room Controls → Sleep checks</a>.</div>';
      bindRoomSel(body); return;
    }
    h += '<div id="sl-overdue" class="sl-card" style="display:none;background:#FEF2F2;border-color:#FCA5A5;color:#991B1B;font-weight:800;"></div>';
    if (d.missing_dob) h += '<div class="sl-note">' + d.missing_dob + ' child' + (d.missing_dob === 1 ? ' in this room has' : 'ren in this room have') + ' no date of birth on file, so the Sleep room cannot tell whether they are under 24 months.</div>';
    var list = d.children || [];
    if (!list.length) h += '<div class="sl-card" style="color:#64748B;">No children under 24 months are booked into this room today.</div>';
    list.forEach(function (c) {
      var n = c.nap;
      // Initials underneath, photo on top; a photo that fails to load just disappears.
      var av = '<span>' + esc(initials(c.name)) + '</span>' + (c.photo_url ? '<img src="' + esc(absUrl(c.photo_url)) + '" alt="" onerror="this.remove()" style="position:absolute;inset:0;">' : '');
      var tags = '<span class="sl-pill">' + esc(ageText(c.age_months)) + '</span>'
        + (c.under12 ? '<span class="sl-pill w" title="Under 12 months: on their back unless a physician directed otherwise in writing">Back to sleep</span>' : '')
        + (c.physician_direction ? '<span class="sl-pill g" title="' + esc(c.physician_note || '') + '">Physician direction</span>' : '')
        + (!c.here && !n ? '<span class="sl-pill" style="background:#F1F5F9;color:#64748B;">Not signed in</span>' : '');
      var sub, right = '', act;
      if (n) {
        var since = Math.max(0, Math.round((serverNow() - Date.parse(n.since)) / 60000));
        sub = 'Asleep since ' + esc(n.since_local) + ' (' + since + ' min) · every ' + n.interval + ' min<br>'
          + (n.last_check ? 'Last check ' + esc(n.last_check.at_local) + ' by ' + esc(n.last_check.by) + ' · ' + esc((d.positions || {})[n.last_check.position] || n.last_check.position)
            + (n.last_check.breathing_ok && n.last_check.colour_ok ? '' : ' · <b style="color:#B91C1C;">concern noted</b>') + ' · ' + n.checks_count + ' check' + (n.checks_count === 1 ? '' : 's')
            : '<b>No check recorded yet</b>');
        right = '<div><div class="sl-cdl">Next check in</div><div class="sl-cd" data-due="' + esc(n.due_at) + '">–</div></div>';
        act = '<button type="button" data-kt-iconized="1" class="sl-b g big" data-check="' + n.nap_event_id + '">✓ Checked</button>'
          + '<button type="button" data-kt-iconized="1" class="sl-b" data-wake="' + c.child_id + '">🌅 Woke up</button>';
      } else {
        sub = 'Awake';
        act = '<button type="button" data-kt-iconized="1" class="sl-b p" data-sleep="' + c.child_id + '">😴 Asleep now</button>';
      }
      if (isMgr && c.under12) act += '<button type="button" data-kt-iconized="1" class="sl-b" data-dir="' + c.child_id + '" title="Physician\'s written direction on sleep position">🩺 Direction</button>';
      h += '<div class="sl-kid' + (n ? ' asleep' : '') + '"><div class="sl-av">' + av + '</div>'
        + '<div class="sl-main"><div class="sl-name">' + esc(c.name) + tags + '</div><div class="sl-sub">' + sub + '</div></div>'
        + right + '<div class="sl-act">' + act + '</div></div>';
    });
    body.innerHTML = h;
    bindRoomSel(body);
    tick(body);

    var byNap = {}; list.forEach(function (c) { if (c.nap) byNap[c.nap.nap_event_id] = c; });
    var byChild = {}; list.forEach(function (c) { byChild[c.child_id] = c; });
    body.querySelectorAll('[data-check]').forEach(function (b) { b.addEventListener('click', function () { openCheck([byNap[b.getAttribute('data-check')]], body); }); });
    var all = body.querySelector('#sl-all');
    if (all) all.addEventListener('click', function () { openCheck(asleep, body); });
    body.querySelectorAll('[data-sleep]').forEach(function (b) { b.addEventListener('click', function () { napEvent(byChild[b.getAttribute('data-sleep')], 'nap_start', body, b); }); });
    body.querySelectorAll('[data-wake]').forEach(function (b) { b.addEventListener('click', function () { napEvent(byChild[b.getAttribute('data-wake')], 'nap_end', body, b); }); });
    body.querySelectorAll('[data-dir]').forEach(function (b) { b.addEventListener('click', function () { openDirection(byChild[b.getAttribute('data-dir')], body); }); });
  }

  function bindRoomSel(body) {
    var s = body.querySelector('#sl-room');
    if (s) s.addEventListener('change', function () { S.room = s.value;
      // Drop a ?room= from a bell link so a later re-render keeps the room picked here.
      try { if (hashParam('room')) history.replaceState(null, '', location.pathname + location.search + '#sleep-room'); } catch (e) {}
      body.innerHTML = '<div class="sl-card" style="color:#64748B;">Loading…</div>'; loadRoom(body); });
  }

  function napEvent(c, type, body, btn) {
    if (!c) return;
    if (btn) btn.disabled = true;
    Api().post('/provider/events', { child_id: c.child_id, room_id: S.room, event_type: type, occurred_at: new Date().toISOString(), payload: {} })
      .then(function () {
        toast(type === 'nap_start' ? '😴' : '🌅', type === 'nap_start' ? c.first_name + ' is asleep' : c.first_name + ' woke up', type === 'nap_start' ? 'Sleep checks have started.' : 'Sleep checks have stopped.', '#4338CA');
        loadRoom(body);
      })
      .catch(function (e) { if (btn) btn.disabled = false; toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
  }

  /* One child or the whole room. Position starts at the last one recorded (else back) so a
     routine check is two taps, but it is always on screen to be confirmed by looking. */
  function openCheck(kids, body) {
    kids = (kids || []).filter(Boolean);
    if (!kids.length) return;
    var P = S.data.positions || { back: 'Back', side: 'Side', tummy: 'Tummy' };
    var w = document.createElement('div');
    var h = '<div class="sl-note" style="margin:0 0 6px;">Look at ' + (kids.length === 1 ? esc(kids[0].first_name) : 'each child') + ' directly — breathing, colour, position — then save.</div>';
    kids.forEach(function (c) {
      var last = (c.nap.last_check && c.nap.last_check.position) || 'back';
      h += '<div data-kid="' + c.nap.nap_event_id + '" style="border-top:1px solid #EEF2F6;padding:10px 0 4px;">'
        + '<div style="font-weight:800;font-size:14px;">' + esc(c.name) + (c.under12 ? ' <span class="sl-pill w">Under 12 months</span>' : '') + (c.physician_direction ? ' <span class="sl-pill g">Physician direction: ' + esc(c.physician_note || '') + '</span>' : '') + '</div>'
        + '<span class="sl-l">Position</span><div class="sl-pos">' + Object.keys(P).filter(function (k) { return k === 'back' || k === 'side' || k === 'tummy' || k === 'sitting'; }).map(function (k) {
            return '<button type="button" data-pos="' + k + '" class="' + (k === last ? 'on' : '') + '">' + esc(P[k]) + '</button>';
          }).join('') + '</div>'
        + '<div class="sl-row" style="margin-top:8px;gap:14px;">'
        + '<label style="display:inline-flex;gap:6px;align-items:center;font-size:13.5px;font-weight:600;"><input type="checkbox" data-br checked> Breathing normal</label>'
        + '<label style="display:inline-flex;gap:6px;align-items:center;font-size:13.5px;font-weight:600;"><input type="checkbox" data-co checked> Colour normal</label></div>'
        + '<input class="sl-i" data-note placeholder="Note (optional)" style="width:100%;margin-top:8px;" maxlength="1000">'
        + '<div data-warn style="display:none;font-size:12.5px;color:#B45309;margin-top:6px;"></div>'
        + '</div>';
    });
    h += '<div class="sl-msg" style="font-size:13px;margin-top:8px;"></div>';
    w.innerHTML = h;
    function refreshWarn(box, c) {
      var onBtn = box.querySelector('[data-pos].on');
      var pos = onBtn ? onBtn.getAttribute('data-pos') : 'back';
      var warn = box.querySelector('[data-warn]');
      var msgs = [];
      if (c.under12 && (pos === 'side' || pos === 'tummy') && !c.physician_direction) msgs.push('Under 12 months: turn ' + c.first_name + ' onto their back, and add a note saying so.');
      if (!box.querySelector('[data-br]').checked || !box.querySelector('[data-co]').checked) msgs.push('Get help now if breathing is not normal. Add a note describing what you saw.');
      warn.style.display = msgs.length ? '' : 'none';
      warn.textContent = msgs.join(' ');
    }
    kids.forEach(function (c) {
      var box = w.querySelector('[data-kid="' + c.nap.nap_event_id + '"]');
      box.querySelectorAll('[data-pos]').forEach(function (b) {
        b.addEventListener('click', function () {
          box.querySelectorAll('[data-pos]').forEach(function (x) { x.classList.remove('on'); });
          b.classList.add('on'); refreshWarn(box, c);
        });
      });
      box.querySelectorAll('[data-br],[data-co]').forEach(function (x) { x.addEventListener('change', function () { refreshWarn(box, c); }); });
    });
    KT.Shell.Modal.open({
      title: kids.length === 1 ? '😴 Sleep check: ' + kids[0].first_name : '😴 Check all (' + kids.length + ')',
      body: w,
      actions: [{ label: 'Cancel' }, {
        label: 'Save check' + (kids.length > 1 ? 's' : ''), style: 'btn-primary', busyLabel: 'Saving…',
        handler: function () {
          var checks = kids.map(function (c) {
            var box = w.querySelector('[data-kid="' + c.nap.nap_event_id + '"]');
            var on = box.querySelector('[data-pos].on');
            return { nap_event_id: c.nap.nap_event_id, position: on ? on.getAttribute('data-pos') : 'back',
              breathing_ok: box.querySelector('[data-br]').checked, colour_ok: box.querySelector('[data-co]').checked,
              note: box.querySelector('[data-note]').value.trim() };
          });
          var msg = w.querySelector('.sl-msg');
          return Api().post('/sleep-checks', { checks: checks }).then(function (r) {
            toast('✅', r.message || 'Checked', (r.refused && r.refused.length) ? r.refused.length + ' not saved: ' + r.refused[0].reason : '', '#047857');
            loadRoom(body);
          }, function (e) {
            msg.style.color = '#B91C1C'; msg.textContent = errMsg(e);
            return false;
          });
        },
      }],
    });
  }

  function openDirection(c, body) {
    if (!c) return;
    var w = document.createElement('div');
    w.innerHTML = '<div class="sl-note" style="margin-top:0;">Ontario: an infant under 12 months is placed on their back to sleep unless a <b>physician</b> has directed otherwise <b>in writing</b>. Record it here, and keep the signed letter in the child\'s Documents.</div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px;font-weight:700;font-size:13.5px;"><input type="checkbox" id="sl-d-on"' + (c.physician_direction ? ' checked' : '') + '> A physician has directed a different sleep position in writing</label>'
      + '<span class="sl-l">What the direction says</span><textarea id="sl-d-note" class="sl-i" style="width:100%;height:80px;padding:8px;" maxlength="1000" placeholder="e.g. Side position, Dr. Lee, letter dated 2026-09-30">' + esc(c.physician_note || '') + '</textarea>'
      + '<div class="sl-msg" style="font-size:13px;margin-top:6px;"></div>';
    KT.Shell.Modal.open({ title: '🩺 Sleep position: ' + c.first_name, body: w, actions: [{ label: 'Cancel' }, { label: 'Save', style: 'btn-primary', busyLabel: 'Saving…', handler: function () {
      return Api().post('/sleep-checks/children/' + c.child_id + '/direction', { on: w.querySelector('#sl-d-on').checked, note: w.querySelector('#sl-d-note').value.trim() })
        .then(function () { toast('🩺', 'Saved', '', '#047857'); loadRoom(body); }, function (e) { var m = w.querySelector('.sl-msg'); m.style.color = '#B91C1C'; m.textContent = errMsg(e); return false; });
    } }] });
  }

  /* ── Report (directors, admins) ── */
  async function paintReport(body) {
    var R = S.rep;
    var q = [];
    if (R.centre) q.push('centre_id=' + R.centre);
    if (R.room) q.push('room_id=' + R.room);
    if (R.date) q.push('date=' + R.date);
    if (R.to) q.push('to=' + R.to);
    var d;
    try { d = await Api().get('/sleep-checks/report' + (q.length ? '?' + q.join('&') : '')); }
    catch (e) { body.innerHTML = '<div class="sl-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    R.centre = d.centre_id; R.date = d.date; R.to = d.to;
    var qs = 'centre_id=' + d.centre_id + '&date=' + d.date + '&to=' + d.to + (R.room ? '&room_id=' + R.room : '');
    /* The two downloads are the DETAILED record (every check), not the table below, so
       they keep their own buttons. kt-table-export strips any button labelled only
       "⬇ CSV"/"Download PDF" once a table export bar exists; these labels say more. */
    var h = '<div class="sl-card"><div class="sl-row">'
      + (d.centres.length > 1 ? '<select class="sl-i" id="sr-c" style="width:auto;max-width:280px;">' + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id === d.centre_id ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '<b>' + esc(d.centre_name) + '</b>')
      + '<select class="sl-i" id="sr-r" style="width:auto;max-width:220px;"><option value="">All rooms</option>' + d.rooms.map(function (r) { return '<option value="' + r.id + '"' + (String(r.id) === String(R.room || '') ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') + '</select>'
      + '<input type="date" class="sl-i" id="sr-d" value="' + esc(d.date) + '"><span style="color:#64748B;font-size:13px;">to</span><input type="date" class="sl-i" id="sr-t" value="' + esc(d.to) + '">'
      + '<button type="button" class="sl-b" id="sr-csv" data-kt-iconized="1" title="Every check, every gap, as a spreadsheet">⬇ Check log (CSV)</button><button type="button" class="sl-b" id="sr-pdf" data-kt-iconized="1" title="Printable record for an inspector">⬇ Inspector report (PDF)</button></div>'
      + '<div class="sl-note">Every nap of a child under 24 months, each check, and any stretch longer than your sleep policy (under 12 months: every ' + d.policy.infant_minutes
      + ' min; 12–24 months: every ' + d.policy.toddler_minutes + ' min), measured from when the child fell asleep to when they woke. Times in ' + esc(d.timezone) + '. '
      + '<b>' + d.summary.naps + '</b> naps, <b>' + d.summary.checks + '</b> checks, <b style="color:' + (d.summary.naps_with_gaps ? '#B91C1C' : '#047857') + ';">' + d.summary.naps_with_gaps + '</b> with a gap.</div></div>';
    if (!d.naps.length) {
      h += '<div class="sl-card" style="color:#64748B;">No naps logged for children under 24 months in this period.</div>';
    } else {
      h += '<div class="kt-card" style="margin-top:12px;"><table data-kt-export-title="Sleep checks summary"><thead><tr><th>Date</th><th>Child</th><th>Room</th><th>Asleep</th><th>Woke</th><th>Checks</th><th>Longest gap</th><th>Status</th><th></th></tr></thead><tbody>';
      d.naps.forEach(function (n, i) {
        var woke = n.end_reason === 'woke' ? n.end_local : n.end_reason === 'signed_out' ? 'Signed out ' + n.end_local : n.end_reason === 'asleep' ? 'Still asleep' : 'Not logged';
        var st = n.ok ? '<span class="sl-pill g">On time</span>' : (n.gaps.length ? '<span class="sl-pill r">' + n.gaps.length + ' gap' + (n.gaps.length === 1 ? '' : 's') + '</span>' : '<span class="sl-pill w">Wake not logged</span>');
        h += '<tr><td style="white-space:nowrap;">' + esc(n.date) + '</td><td>' + esc(n.child) + ' <span style="color:#64748B;font-size:12px;">' + n.age_months + ' mo</span></td><td>' + esc(n.room) + '</td><td>' + esc(n.start_local) + '</td><td>' + esc(woke) + '</td>'
          + '<td>' + n.checks.length + ' <span style="color:#64748B;font-size:12px;">(every ' + n.interval + ')</span></td><td>' + n.max_gap + ' min</td><td>' + st + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" class="sr-view" data-i="' + i + '" title="View checks">View checks</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    body.innerHTML = h;
    function reload() { body.innerHTML = '<div class="sl-card" style="color:#64748B;">Loading…</div>'; paintReport(body); }
    var c = body.querySelector('#sr-c'); if (c) c.addEventListener('change', function () { R.centre = c.value; R.room = ''; reload(); });
    body.querySelector('#sr-r').addEventListener('change', function () { R.room = this.value; reload(); });
    body.querySelector('#sr-d').addEventListener('change', function () { R.date = this.value; if (!R.to || R.to < R.date) R.to = R.date; reload(); });
    body.querySelector('#sr-t').addEventListener('change', function () { R.to = this.value; reload(); });
    body.querySelector('#sr-csv').addEventListener('click', function () {
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/sleep-checks/report?format=csv&' + qs); }, { title: 'Sleep checks', filename: 'sleep-checks-' + d.date + '.csv', mime: 'text/csv' });
    });
    body.querySelector('#sr-pdf').addEventListener('click', function () {
      KT.openFile(function () { return KT.fetchFileBlob(apiBase() + '/sleep-checks/report?format=pdf&' + qs); }, { title: 'Sleep checks', filename: 'sleep-checks-' + d.date + '.pdf', mime: 'application/pdf' });
    });
    body.querySelectorAll('.sr-view').forEach(function (b) { b.addEventListener('click', function () {
      var n = d.naps[+b.getAttribute('data-i')];
      if (!n) return;
      var t = '<div class="sl-note" style="margin-top:0;">Asleep ' + esc(n.start_local) + ' · ' + esc(n.end_reason === 'woke' ? 'woke ' + n.end_local : n.end_reason === 'asleep' ? 'still asleep' : n.end_reason === 'signed_out' ? 'signed out ' + n.end_local : 'wake time not logged')
        + ' · checks every ' + n.interval + ' min' + (n.physician_direction ? ' · physician direction on file' : '') + '</div>';
      t += '<table class="sl-t" style="margin-top:8px;"><thead><tr><th>Time</th><th>By</th><th>Position</th><th>Breathing</th><th>Colour</th><th>Gap</th><th>Note</th></tr></thead><tbody>';
      if (!n.checks.length) t += '<tr><td colspan="7" style="color:#B91C1C;font-weight:700;">No checks recorded</td></tr>';
      n.checks.forEach(function (k) {
        t += '<tr><td>' + esc(k.at_local) + '</td><td>' + esc(k.by) + '</td><td>' + esc(k.position_label) + '</td><td>' + (k.breathing_ok ? 'Normal' : '<b style="color:#B91C1C;">No</b>') + '</td><td>'
          + (k.colour_ok ? 'Normal' : '<b style="color:#B91C1C;">No</b>') + '</td><td' + (k.over ? ' style="color:#B91C1C;font-weight:800;"' : '') + '>' + k.minutes_since_previous + ' min</td><td>' + esc(k.note || '') + '</td></tr>';
      });
      t += '</tbody></table>';
      if (n.gaps.length) t += '<div class="sl-note" style="color:#B91C1C;">Over the interval: ' + n.gaps.map(function (g) { return esc(g.from + '–' + g.to + ' (' + g.minutes + ' min)'); }).join('; ') + '</div>';
      KT.Shell.Modal.open({ title: '😴 ' + n.child + ', ' + n.date, body: t, actions: [{ label: 'Close' }] });
    }); });
  }

  /* ── Settings → Room Controls → Sleep checks (painted by screen-educator-rooms.js) ── */
  async function paintSettings(pane) {
    css();
    var d;
    try { d = await Api().get('/sleep-checks/settings'); } catch (e) { return; }
    var a = d.agency, ed = !!d.can_edit, dis = ed ? '' : ' disabled';
    function num(id, v, ph) { return '<input id="' + id + '" type="number" min="' + d.limits.min + '" max="' + d.limits.max + '" value="' + esc(v == null ? '' : v) + '"' + (ph ? ' placeholder="' + esc(ph) + '"' : '') + ' class="sl-i" style="width:72px;"' + dis + '>'; }
    function row(label, hint, ctl) {
      return '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid #F1F5F9;">'
        + '<div style="min-width:0;flex:1 1 260px;"><div style="font-size:14px;color:#334155;font-weight:600;">' + label + '</div>' + (hint ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + hint + '</div>' : '') + '</div>'
        + '<div style="flex-shrink:0;display:flex;align-items:center;gap:8px;">' + ctl + '</div></div>';
    }
    var h = '<div class="kt-card" style="max-width:760px;"><div class="kt-card-header"><h3 class="kt-card-title">😴 Sleep checks — your sleep policy</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 6px;">Ontario (O. Reg. 137/15 s.33.1) requires documented direct visual checks of every sleeping child under 24 months, '
      + 'at the frequency <b>your centre\'s sleep policy</b> sets — the regulation does not name a number of minutes. Set the intervals to match your written policy. '
      + 'Educators record checks in the Sleep room; an overdue check alerts the room\'s educators (bell and push), then the director. '
      + (d.default_enabled ? 'On by default for Ontario agencies.' : 'Off by default outside Ontario.') + '</div>'
      + row('Sleep checks', 'Show the Sleep room and send overdue alerts.', '<input type="checkbox" id="sc-on"' + (a.enabled ? ' checked' : '') + dis + '>')
      + row('Under 12 months: check every', 'Minutes between checks (' + d.limits.min + '–' + d.limits.max + '). Default 15.', num('sc-inf', a.infant_minutes) + ' min')
      + row('12 to 24 months: check every', 'Default 15.', num('sc-tod', a.toddler_minutes) + ' min')
      + row('Tell the director when a check is overdue', 'After the grace period below, the centre\'s director gets the alert too (an agency admin if the centre has no director).', '<input type="checkbox" id="sc-dir"' + (a.alert_director ? ' checked' : '') + dis + '>')
      + row('Grace period before the director is told', '', '<input id="sc-grace" type="number" min="0" max="60" value="' + esc(a.director_grace_minutes) + '" class="sl-i" style="width:72px;"' + dis + '> min');
    if (d.centres.length) {
      h += '<div style="font-size:13px;font-weight:800;color:#0F172A;margin:14px 0 4px;">Centres with their own policy</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin-bottom:6px;">Leave blank to use the agency setting above.</div>'
        + '<table class="sl-t" data-kt-no-filter data-kt-no-bulk><thead><tr><th>Centre</th><th>Sleep checks</th><th>Under 12 mo</th><th>12–24 mo</th></tr></thead><tbody>';
      d.centres.forEach(function (c) {
        var o = c.override || {};
        var en = o.enabled === true ? '1' : o.enabled === false ? '0' : '';
        h += '<tr data-cen="' + c.id + '"><td>' + esc(c.name) + '</td><td><select class="sl-i" data-k="enabled"' + dis + '><option value="">Agency setting</option><option value="1"' + (en === '1' ? ' selected' : '') + '>On</option><option value="0"' + (en === '0' ? ' selected' : '') + '>Off</option></select></td>'
          + '<td>' + num('sc-ci-' + c.id, o.infant_minutes, String(a.infant_minutes)).replace('<input ', '<input data-k="infant_minutes" ') + '</td>'
          + '<td>' + num('sc-ct-' + c.id, o.toddler_minutes, String(a.toddler_minutes)).replace('<input ', '<input data-k="toddler_minutes" ') + '</td></tr>';
      });
      h += '</tbody></table>';
    }
    h += ed ? '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;"><button id="sc-save" class="kt-btn kt-btn-primary">Save</button><span id="sc-msg" style="font-size:13px;"></span></div>'
      : '<div style="font-size:12.5px;color:#64748B;margin-top:12px;">Only agency administrators can change this.</div>';
    h += '</div>';
    pane.innerHTML = h;
    var save = pane.querySelector('#sc-save');
    if (!save) return;
    save.addEventListener('click', function () {
      var msg = pane.querySelector('#sc-msg');
      var centres = {};
      pane.querySelectorAll('[data-cen]').forEach(function (tr) {
        var o = {};
        var en = tr.querySelector('[data-k="enabled"]').value;
        if (en !== '') o.enabled = en === '1';
        var i = tr.querySelector('[data-k="infant_minutes"]').value, t = tr.querySelector('[data-k="toddler_minutes"]').value;
        if (i !== '') o.infant_minutes = +i;
        if (t !== '') o.toddler_minutes = +t;
        if (Object.keys(o).length) centres[tr.getAttribute('data-cen')] = o;
      });
      var out = { enabled: pane.querySelector('#sc-on').checked, infant_minutes: +pane.querySelector('#sc-inf').value, toddler_minutes: +pane.querySelector('#sc-tod').value,
        alert_director: pane.querySelector('#sc-dir').checked, director_grace_minutes: +pane.querySelector('#sc-grace').value, centres: centres };
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      Api().post('/sleep-checks/settings', out).then(function () {
        msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved — applies straight away';
        try { Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('kt_sleep_on_') === 0) sessionStorage.removeItem(k); }); } catch (e) {}
        probedFor = null; probe();
      }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = errMsg(e); });
    });
  }

  KT.sleepChecks = { paintSettings: paintSettings };

  function register() {
    if (!KT.Shell || !KT.Shell.registerScreen) return false;
    ['educator', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':sleep-room', render); });
    return true;
  }
  if (!register()) { document.addEventListener('DOMContentLoaded', register); }
})(window);
