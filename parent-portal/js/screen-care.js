/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p49 — Care logs, milestones, portfolio, time clock,
   tour bookings. One file, multiple hashes.

   Hashes:
     #care-log          quick-tap daily care log entry (educator/director)
     #portfolio?id=N    chronological observation timeline for one child
     #milestones?id=N   developmental milestones checklist for one child
     #time-clock        staff punch in/out + history
     #tours             agency_admin/director view of tour bookings
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  /**
   * Shrink a photo BEFORE upload.
   *
   * A phone camera JPEG is 4-6MB, and the galleries draw it into a ~220px card —
   * so the app was downloading roughly 20x the pixels it can display and throwing
   * the rest away. That is the whole reason shared photos loaded slowly on mobile
   * data. 1600px on the long edge is still far more than any view uses (and plenty
   * for a full-screen tap), at roughly a tenth of the bytes.
   *
   * Videos are passed through untouched — re-encoding video in the browser is not
   * realistic; they are capped at 30MB at the picker instead.
   *
   * Always resolves: if anything about the decode/encode fails we hand back the
   * ORIGINAL file, because a slow upload beats a failed one.
   */
  /**
   * Grab a still from a chosen video, in the browser, to use as its poster.
   *
   * ffmpeg is not installed on the host, so a video tile had no image at all and the
   * player had to pull actual video data before it could show a first frame. Seeking
   * a hidden <video> to just past the start and painting it to a canvas gives us a
   * real frame for about 20 KB.
   *
   * Always resolves — null on any failure, because a missing poster must never stop
   * the upload.
   */
  function ktVideoPoster(file) {
    return new Promise(function (resolve) {
      try {
        if (!file || !/^video\//.test(file.type)) return resolve(null);
        var done = false;
        var finish = function (b) { if (!done) { done = true; resolve(b); } };
        setTimeout(function () { finish(null); }, 8000);   // never hang the upload

        var url = URL.createObjectURL(file);
        var v = document.createElement('video');
        v.muted = true; v.playsInline = true; v.preload = 'metadata';
        v.onloadedmetadata = function () {
          // 0.4s in: frame zero is often a black or half-exposed frame.
          try { v.currentTime = Math.min(0.4, (v.duration || 1) / 2); } catch (e) { finish(null); }
        };
        v.onseeked = function () {
          try {
            var w = v.videoWidth, h = v.videoHeight;
            if (!w || !h) return finish(null);
            var scale = Math.min(1, 640 / Math.max(w, h));
            var cv = document.createElement('canvas');
            cv.width = Math.round(w * scale); cv.height = Math.round(h * scale);
            cv.getContext('2d').drawImage(v, 0, 0, cv.width, cv.height);
            cv.toBlob(function (blob) {
              try { URL.revokeObjectURL(url); } catch (e) {}
              finish(blob ? new File([blob], 'poster.jpg', { type: 'image/jpeg' }) : null);
            }, 'image/jpeg', 0.78);
          } catch (e) { finish(null); }
        };
        v.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} finish(null); };
        v.src = url;
      } catch (e) { resolve(null); }
    });
  }

  function ktShrinkImage(file, maxEdge, quality) {
    return new Promise(function (resolve) {
      try {
        if (!file || !/^image\//.test(file.type) || /image\/(gif|svg)/.test(file.type)) return resolve(file);
        if (!window.createImageBitmap && !window.FileReader) return resolve(file);
        var done = false;
        var finish = function (f) { if (!done) { done = true; resolve(f); } };
        // Never let a stuck decode block the upload.
        setTimeout(function () { finish(file); }, 8000);

        var img = new Image();
        var url = URL.createObjectURL(file);
        img.onload = function () {
          try {
            var w = img.naturalWidth, h = img.naturalHeight;
            if (!w || !h) return finish(file);
            var scale = Math.min(1, maxEdge / Math.max(w, h));
            if (scale >= 1 && file.size < 1200000) return finish(file);   // already small
            var cw = Math.round(w * scale), ch = Math.round(h * scale);
            var cv = document.createElement('canvas');
            cv.width = cw; cv.height = ch;
            var g = cv.getContext('2d');
            g.drawImage(img, 0, 0, cw, ch);
            cv.toBlob(function (blob) {
              try { URL.revokeObjectURL(url); } catch (e) {}
              // Keep the original if the "shrunk" copy somehow is not smaller.
              if (!blob || blob.size >= file.size) return finish(file);
              var name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
              finish(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
            }, 'image/jpeg', quality || 0.82);
          } catch (e) { finish(file); }
        };
        img.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} finish(file); };
        img.src = url;
      } catch (e) { resolve(file); }
    });
  }

  // Media uploads go through raw fetch, not KT.Api: Api.request JSON-encodes its
  // body, which would destroy a FormData multipart payload.
  function _careApiBase() { return (KT.API_BASE) || (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function _careToken() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) { return ''; } }
  function fmt(d) {
    if (!d) return '—';
    try {
      // v22p75.1: MySQL datetimes use a space ("2026-06-15 10:00:00") which
      // Safari/Firefox parse as Invalid Date. Normalise to ISO before parsing.
      var iso = (typeof d === 'string') ? d.replace(' ', 'T') : d;
      var dt = new Date(iso);
      if (isNaN(dt.getTime())) return String(d);
      return dt.toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) { return String(d); }
  }
  function fmtDateOnly(d) {
    if (!d) return '';
    try {
      var iso = (typeof d === 'string') ? d.replace(' ', 'T') : d;
      var dt = new Date(iso);
      if (isNaN(dt.getTime())) return String(d);
      return dt.toLocaleDateString('en-CA', { dateStyle: 'medium' });
    } catch (e) { return String(d); }
  }
  // v22p77: safe Date parse for MySQL space-format datetimes (Safari/Firefox).
  function parseDt(d) {
    if (!d) return null;
    var iso = (typeof d === 'string') ? d.replace(' ', 'T') : d;
    var dt = new Date(iso);
    return isNaN(dt.getTime()) ? null : dt;
  }
  function paramId() {
    var m = (window.location.hash || '').match(/[?&]id=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  // ─── Time clock ───────────────────────────────────────────────────
  function renderTimeClock(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:760px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#7C3AED 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">⏱ TIME CLOCK</div><h1>Punch in / punch out</h1><div class="kt-hero-sub">Tap the big button when you start or end a shift. Your manager sees the rolled-up hours in the centre dashboard.</div>';
    wrap.appendChild(hero);

    var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:32px;margin:18px 0;box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;' });
    var statusEl = Dom.el('div', { style: 'font-size:14px;color:#6B7280;margin-bottom:10px;' }, 'Loading…');
    card.appendChild(statusEl);
    var btn = Dom.el('button', {
      style: 'background:#16A34A;color:white;border:none;padding:18px 40px;border-radius:14px;font-weight:800;cursor:pointer;font-size:18px;box-shadow:0 4px 14px rgba(22,163,74,.28);transition:transform .15s,box-shadow .15s;',
    }, 'Clock in');
    btn.addEventListener('mouseenter', function () { btn.style.transform = 'translateY(-1px)'; });
    btn.addEventListener('mouseleave', function () { btn.style.transform = ''; });
    card.appendChild(btn);
    wrap.appendChild(card);

    var history = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);' });
    history.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Recent punches'));
    var list = Dom.el('div', { 'data-kt-list': '1' }); history.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'padding:18px;color:#64748B;font-size:13px;text-align:center;' }, 'Loading…'));
    wrap.appendChild(history);

    function refresh() {
      Api.get('/staff/punches/me').then(function (data) {
        var open = (data.punches || []).find(function (p) { return !p.punched_out_at; });
        if (open) {
          var since = new Date(open.punched_in_at);
          var hrs = (Date.now() - since.getTime()) / 3600000;
          statusEl.innerHTML = 'You\'re clocked in since <strong>' + fmt(open.punched_in_at) + '</strong> · ' + hrs.toFixed(1) + ' h elapsed';
          btn.textContent = 'Clock out';
          btn.style.background = '#DC2626';
          btn.style.boxShadow = '0 4px 14px rgba(220,38,38,.28)';
        } else {
          statusEl.textContent = 'You\'re currently clocked out.';
          btn.textContent = 'Clock in';
          btn.style.background = '#16A34A';
          btn.style.boxShadow = '0 4px 14px rgba(22,163,74,.28)';
        }

        Dom.clear(list);
        if (!data.punches || !data.punches.length) {
          list.appendChild(Dom.el('div', { style: 'padding:18px;color:#64748B;font-size:13px;text-align:center;' }, 'No punches yet — tap the button to start your first shift.'));
          return;
        }
        var T = function (d) { return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '…'; };
        data.punches.forEach(function (p) {
          var inO = new Date(p.punched_in_at);
          var outO = p.punched_out_at ? new Date(p.punched_out_at) : null;
          var live = !outO;
          var durTxt = outO ? ((outO - inO) / 3600000).toFixed(2) + ' h' : 'In progress';
          var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:11px;padding:11px 4px;border-bottom:1px solid #F1F5F9;' });
          row.appendChild(Dom.el('span', { style: 'flex:0 0 auto;width:9px;height:9px;border-radius:50%;background:' + (live ? '#F59E0B' : '#16A34A') + ';box-shadow:0 0 0 3px ' + (live ? 'rgba(245,158,11,.15)' : 'rgba(22,163,74,.15)') + ';' }));
          var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
          mid.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:13.5px;color:#0F172A;' }, fmt(p.punched_in_at)));
          mid.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:1px;' }, T(inO) + ' → ' + T(outO)));
          row.appendChild(mid);
          row.appendChild(Dom.el('div', { style: 'flex:0 0 auto;font-size:12.5px;font-weight:800;padding:5px 12px;border-radius:20px;background:' + (live ? '#FEF3C7' : '#DCFCE7') + ';color:' + (live ? '#B45309' : '#15803D') + ';white-space:nowrap;' }, durTxt));
          list.appendChild(row);
        });
      });
    }
    btn.addEventListener('click', function () {
      btn.disabled = true; btn.textContent = '…';
      Api.post('/staff/punch', {}).then(function () { refresh(); }).catch(function (e) {
        alert('Failed: ' + (e.message || 'error'));
        refresh();
      }).finally(function () { btn.disabled = false; });
    });
    refresh();
  }

  // "HH:MM" now, and an ISO timestamp for a "HH:MM" wall-clock time TODAY (local
  // → correct UTC instant for the server). Used by the optional "When?" pickers.
  function _careNowHHMM() {
    var d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  function _careIsoAt(hhmm) {
    if (!hhmm) return null;
    var p = String(hhmm).split(':');
    if (p.length < 2) return null;
    var d = new Date();
    d.setHours(parseInt(p[0], 10) || 0, parseInt(p[1], 10) || 0, 0, 0);
    return d.toISOString();
  }

  // ─── Daily care log entry — quick-tap UI ──────────────────────────
  function renderCareLog(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:780px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#8EC73C 0%,#1F6080 60%,#7C3AED 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📝 DAILY LOG</div><h1>Log a moment</h1><div class="kt-hero-sub">Quick-tap diaper / bathroom / nap / meal / bottle entries. They roll up into the parent\'s Today screen instantly.</div>';
    wrap.appendChild(hero);

    // Provider filter — an agency admin spans the WHOLE agency, so they pick a
    // provider first, then the child. Hidden for staff/parents (single scope).
    var providerSelWrap = Dom.el('div', { style: 'margin:18px 0 -4px;background:white;padding:16px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);display:none;' });
    providerSelWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;' }, 'Provider'));
    var providerSel = Dom.el('select', { style: 'width:100%;padding:10px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;background:white;' });
    providerSelWrap.appendChild(providerSel);
    wrap.appendChild(providerSelWrap);

    var childSelWrap = Dom.el('div', { style: 'margin:18px 0;background:white;padding:16px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
    childSelWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;' }, 'Child'));
    var childSel = Dom.el('select', { style: 'width:100%;padding:10px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;background:white;' });
    childSel.appendChild(Dom.el('option', { value: '' }, 'Loading children…'));
    childSelWrap.appendChild(childSel);
    wrap.appendChild(childSelWrap);

    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;' });
    var TYPES = [
      { type: 'diaper',    icon: '🧷', label: 'Diaper',    color: '#FF8A65' },
      { type: 'bathroom',  icon: '🚽', label: 'Bathroom',  color: '#1F6080' },
      { type: 'nap',       icon: '😴', label: 'Nap',       color: '#7C3AED' },
      { type: 'meal',      icon: '🍽️', label: 'Meal',      color: '#16A34A' },
      { type: 'snack',     icon: '🍎', label: 'Snack',     color: '#F59E0B' },
      { type: 'bottle',    icon: '🍼', label: 'Bottle',    color: '#8EC73C' },
      { type: 'sunscreen', icon: '☀️', label: 'Sunscreen', color: '#F59E0B' },
      { type: 'mood',      icon: '🙂', label: 'Mood',      color: '#7C3AED' },
      // Media tiles. These are NOT care logs — `daily_care_logs.log_type` is a
      // MySQL enum of the eight types above, so filing a photo there would need a
      // schema change. They post straight to /photos (the table the parent gallery,
      // the admin/director daily overview and the daily digest all read), which is
      // the whole point: one upload, visible everywhere.
      { type: 'photo',     icon: '📸', label: 'Photo',     color: '#0E7C90', media: 'image' },
      { type: 'video',     icon: '🎬', label: 'Video',     color: '#7C3AED', media: 'video' },
    ];
    TYPES.forEach(function (t) {
      var btn = Dom.el('button', {
        type: 'button',
        style: 'background:white;border:2px solid #E5E7EB;border-radius:14px;padding:18px 12px;cursor:pointer;font-size:14px;font-weight:700;color:#0F172A;display:flex;flex-direction:column;align-items:center;gap:6px;transition:transform .15s,border-color .15s,background .15s;',
      });
      btn.innerHTML = '<span style="font-size:30px;">' + t.icon + '</span><span>' + t.label + '</span>';
      btn.addEventListener('mouseenter', function () { btn.style.borderColor = t.color; btn.style.background = '#FAFBFC'; });
      btn.addEventListener('mouseleave', function () { btn.style.borderColor = '#E5E7EB'; btn.style.background = 'white'; });
      btn.addEventListener('click', function () {
        if (!childSel.value || childSel.value === '__all__') { alert('Pick a child to log for.'); return; }
        if (t.media) openMediaModal(t, parseInt(childSel.value, 10));
        else openDetailsModal(t, parseInt(childSel.value, 10));
      });
      grid.appendChild(btn);
    });
    wrap.appendChild(grid);

    var recentWrap = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);' });

    // Which day are we looking at? The agency's today, not the device's — a tablet left on
    // the wrong timezone should not decide which day an educator sees.
    function agencyToday() {
      try { if (window.KT && KT.agencyToday) return KT.agencyToday(); } catch (e) {}
      var d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    var VIEW_DATE = agencyToday();

    var dayHead = Dom.el('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;' });
    var dayTitle = Dom.el('h3', { style: 'margin:0;flex:1;min-width:120px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Today\'s log');
    var navBtn = function (label, title) {
      var b = Dom.el('button', { type: 'button', title: title, style: 'width:32px;height:32px;border-radius:9px;border:1px solid #D1D5DB;background:#fff;cursor:pointer;font-size:14px;line-height:1;color:#374151;' }, label);
      b.dataset.ktIconized = '1';
      return b;
    };
    var prevDay = navBtn('‹', 'Previous day');
    var nextDay = navBtn('›', 'Next day');
    var dayInput = Dom.el('input', { type: 'date', value: VIEW_DATE, max: agencyToday(),
      style: 'padding:7px 9px;border:1px solid #D1D5DB;border-radius:9px;font-size:13px;background:#fff;color:#374151;' });
    var todayBtn = Dom.el('button', { type: 'button', style: 'padding:7px 12px;border-radius:9px;border:1px solid #D1D5DB;background:#fff;cursor:pointer;font-size:12.5px;font-weight:700;color:#374151;' }, 'Today');
    todayBtn.dataset.ktIconized = '1';

    dayHead.appendChild(dayTitle);
    dayHead.appendChild(prevDay);
    dayHead.appendChild(dayInput);
    dayHead.appendChild(nextDay);
    dayHead.appendChild(todayBtn);
    recentWrap.appendChild(dayHead);

    function shiftDate(iso, days) {
      // Parsed as UTC and stepped in whole days, so it cannot land on the wrong date
      // through a daylight-saving hour the way a local-midnight Date would.
      var d = new Date(iso + 'T00:00:00Z');
      d.setUTCDate(d.getUTCDate() + days);
      return d.toISOString().slice(0, 10);
    }
    function prettyDay(iso) {
      if (iso === agencyToday()) return 'Today\'s log';
      if (iso === shiftDate(agencyToday(), -1)) return 'Yesterday\'s log';
      try {
        return new Date(iso + 'T00:00:00Z').toLocaleDateString(undefined,
          { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }) + ' — log';
      } catch (e) { return iso + ' — log'; }
    }
    function setViewDate(iso) {
      if (!iso) return;
      if (iso > agencyToday()) iso = agencyToday();      // no logs from the future
      VIEW_DATE = iso;
      dayInput.value = iso;
      dayTitle.textContent = prettyDay(iso);
      nextDay.disabled = (iso === agencyToday());
      nextDay.style.opacity = nextDay.disabled ? '.4' : '1';
      nextDay.style.cursor = nextDay.disabled ? 'default' : 'pointer';
      loadRecent();
    }
    prevDay.addEventListener('click', function () { setViewDate(shiftDate(VIEW_DATE, -1)); });
    nextDay.addEventListener('click', function () { setViewDate(shiftDate(VIEW_DATE, 1)); });
    todayBtn.addEventListener('click', function () { setViewDate(agencyToday()); });
    dayInput.addEventListener('change', function () { setViewDate(dayInput.value); });
    // The view opens on today, so "next day" starts disabled. Set directly rather than by
    // calling setViewDate, which would fire a load before the child list has arrived.
    nextDay.disabled = true;
    nextDay.style.opacity = '.4';
    nextDay.style.cursor = 'default';

    var recent = Dom.el('div'); recentWrap.appendChild(recent);
    wrap.appendChild(recentWrap);

    // Load the child list for whoever is looking.
    //
    // This used to call /parent/children FIRST for everyone. That route is
    // guardian-only, so for an educator it failed and the catch below wrote
    // "Sign in failed" into the dropdown — the screen looked broken and no
    // child could be picked, even one standing checked-in in the room. Its only
    // fallback was /admin/children, which an educator can't call either.
    //
    // So: parents use /parent/children; staff use the rooms they actually work
    // in (/provider/bootstrap + each room's roster — the same source the Today
    // screen uses), which also tells us who is currently checked in. Admins keep
    // the agency-wide list as a last resort.
    function roleOf() {
      try {
        var va = sessionStorage.getItem('kt_view_as') || '';
        if (va) return va;
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        return u.primary_role || '';
      } catch (e) { return ''; }
    }
    var CHILDREN = [];
    function fillOptions(kids) {
      childSel.innerHTML = '';
      if (!kids.length) {
        childSel.innerHTML = '<option value="">No children found</option>';
        return;
      }
      // Children who are AT the centre come first and are marked — they're the
      // ones you're logging a nappy/nap/meal for.
      kids.sort(function (a, b) {
        if (!!a.at_centre !== !!b.at_centre) return a.at_centre ? -1 : 1;
        return String(a.name).localeCompare(String(b.name));
      });
      CHILDREN = kids.slice();
      // "All children" filter — view every child's logs; pick one to filter down.
      childSel.appendChild(Dom.el('option', { value: '__all__' }, 'All children'));
      kids.forEach(function (c) {
        childSel.appendChild(Dom.el('option', { value: c.id },
          c.name + (c.at_centre ? ' · here now' : '') + (c.suffix ? ' · ' + c.suffix : '')));
      });
      loadRecent();
    }

    function loadStaffChildren() {
      return Api.get('/provider/bootstrap').then(function (boot) {
        var rooms = (boot && boot.rooms) || [];
        if (!rooms.length) return [];
        return Promise.all(rooms.map(function (rm) {
          return Api.get('/provider/rooms/' + rm.id + '/roster')
            .then(function (d) {
              return ((d && d.roster) || []).map(function (c) {
                return {
                  id: c.id,
                  name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.display_name,
                  at_centre: !!c.is_at_centre,
                  suffix: rm.name,
                };
              });
            })
            .catch(function () { return []; });
        })).then(function (lists) {
          return lists.reduce(function (a, b) { return a.concat(b); }, []);
        });
      });
    }

    var role = roleOf();
    // Agency-wide roles (super admin / agency admin) see EVERY provider's kids,
    // filterable by provider — the reported bug was that they saw only the one
    // centre /provider/bootstrap returned. Educators/directors keep their rooms.
    function loadAgencyWide() {
      var ALL = [];
      function applyFilter() {
        var cid = providerSel.value;
        var list = cid ? ALL.filter(function (k) { return String(k.centre_id) === String(cid); }) : ALL;
        fillOptions(list.map(function (c) {
          return { id: c.id, name: c.name, centre_id: c.centre_id, suffix: cid ? '' : (c.centre_name || '') };
        }));
      }
      return Api.get('/admin/children').then(function (r) {
        ALL = ((r && r.children) || []).map(function (c) {
          return { id: c.id, name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim(), centre_id: c.centre_id, centre_name: c.centre_name || '' };
        });
        var seen = {}; var provs = [];
        ALL.forEach(function (k) { if (k.centre_id && !seen[k.centre_id]) { seen[k.centre_id] = 1; provs.push({ id: k.centre_id, name: k.centre_name }); } });
        provs.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        providerSel.innerHTML = '<option value="">All providers</option>';
        provs.forEach(function (p) { providerSel.appendChild(Dom.el('option', { value: p.id }, p.name)); });
        providerSelWrap.style.display = provs.length > 1 ? '' : 'none';
        providerSel.addEventListener('change', applyFilter);
        applyFilter();
      });
    }

    if (role === 'guardian') {
      Api.get('/parent/children')
        .then(function (r) {
          fillOptions(((r && r.children) || []).map(function (c) {
            return { id: c.id, name: (c.first_name + ' ' + c.last_name).trim(), at_centre: !!c.is_at_centre };
          }));
        })
        .catch(function () { childSel.innerHTML = '<option value="">Could not load your children</option>'; });
    } else if (role === 'agency_admin' || role === 'platform_admin') {
      loadAgencyWide().catch(function () { childSel.innerHTML = '<option value="">No children loaded</option>'; });
    } else {
      loadStaffChildren()
        .then(function (kids) {
          if (kids.length) { fillOptions(kids); return; }
          // Directors/educators with no rooms of their own → agency-wide list.
          return loadAgencyWide();
        })
        .catch(function () { childSel.innerHTML = '<option value="">No children loaded</option>'; });
    }
    childSel.addEventListener('change', loadRecent);

    // Does this entry belong to the day being viewed? Compared as AGENCY dates — the
    // date it fell on at the centre — so an evening entry stays on its own evening
    // whatever zone the reader's device is in.
    function onViewDate(l) {
      var ts = l && l.occurred_at;
      if (!ts) return false;
      try {
        if (window.KT && KT.agencyDateOf) return KT.agencyDateOf(ts) === VIEW_DATE;
      } catch (e) {}
      return String(ts).slice(0, 10) === VIEW_DATE;
    }
    // Fetch from the start of the day being viewed. The endpoint defaults to the last 7
    // days, which silently hides anything older — so looking back a fortnight would have
    // returned nothing and looked like an empty day.
    function sinceParam() {
      return '?since=' + encodeURIComponent(shiftDate(VIEW_DATE, -1) + ' 00:00:00');
    }
    function emptyDayText() {
      return VIEW_DATE === agencyToday() ? 'Nothing logged today yet.' : 'Nothing was logged on this day.';
    }
    function logRow(l, childName) {
      var tInfo = TYPES.find(function (t) { return t.type === l.log_type; }) || { icon: '•', color: '#6B7280' };
      var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F4F6;' });
      row.appendChild(Dom.el('div', { style: 'font-size:24px;width:32px;text-align:center;' }, tInfo.icon));
      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      var head = Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#0F172A;' });
      head.textContent = l.log_type.charAt(0).toUpperCase() + l.log_type.slice(1);
      if (l.details) head.textContent += ' — ' + l.details;
      body.appendChild(head);
      // time_display is the server's own rendering, in the CENTRE's timezone. Preferred
      // over re-deriving it here: this screen's fmt() reads the DEVICE clock, which is
      // right only while everyone happens to be in the same zone as the centre.
      var meta = (l.time_display || fmt(l.occurred_at)) + ' · by ' + (l.logged_by || 'staff');
      if (childName) meta = childName + ' · ' + meta;
      body.appendChild(Dom.el('div', { style: 'font-size:11px;color:#64748B;' }, meta));
      if (l.notes) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, l.notes));
      row.appendChild(body);
      return row;
    }
    function loadRecent() {
      var v = childSel.value;
      if (v === '__all__') return loadRecentAll();
      if (!v) return;
      Api.get('/care/logs/child/' + v + sinceParam()).then(function (data) {
        Dom.clear(recent);
        var dayLogs = (data.logs || []).filter(onViewDate);
        if (!dayLogs.length) { recent.appendChild(Dom.el('div', { style: 'padding:24px;color:#64748B;font-size:13px;text-align:center;' }, emptyDayText())); return; }
        dayLogs.forEach(function (l) { recent.appendChild(logRow(l, null)); });
      }).catch(function (e) {
        Dom.clear(recent);
        recent.appendChild(Dom.el('div', { style: 'padding:18px;color:#B91C1C;font-size:13px;text-align:center;' }, 'Could not load the log: ' + (e.message || 'error')));
      });
    }
    // "All children" — aggregate today's logs across every child in scope, newest first.
    function loadRecentAll() {
      Dom.clear(recent);
      recent.appendChild(Dom.el('div', { style: 'padding:18px;color:#64748B;font-size:13px;text-align:center;' }, 'Loading all children…'));
      var kids = (CHILDREN || []).filter(function (c) { return c.id; });
      if (!kids.length) { Dom.clear(recent); recent.appendChild(Dom.el('div', { style: 'padding:24px;color:#64748B;font-size:13px;text-align:center;' }, 'No children found.')); return; }
      Promise.all(kids.map(function (c) {
        return Api.get('/care/logs/child/' + c.id + sinceParam()).then(function (data) { return (data.logs || []).map(function (l) { l.__cn = c.name; return l; }); }).catch(function () { return []; });
      })).then(function (lists) {
        var all = lists.reduce(function (a, b) { return a.concat(b); }, []).filter(onViewDate);
        all.sort(function (a, b) { var da = parseDt(a.occurred_at), db = parseDt(b.occurred_at); return (db ? db.getTime() : 0) - (da ? da.getTime() : 0); });
        Dom.clear(recent);
        if (!all.length) { recent.appendChild(Dom.el('div', { style: 'padding:24px;color:#64748B;font-size:13px;text-align:center;' }, emptyDayText())); return; }
        all.forEach(function (l) { recent.appendChild(logRow(l, l.__cn)); });
      });
    }

    // Quick-pick options per type. Typing "wet only" into a free-text box while
    // holding a baby is not a workflow — one tap should do it. The picked value
    // goes into the same `details` field the free-text box used, so nothing
    // downstream changes, and a custom note is still available underneath.
    var DETAIL_OPTIONS = {
      diaper:    ['Wet', 'BM', 'Wet + BM', 'Dry'],
      bathroom:  ['Pee', 'BM', 'Both', 'Tried, nothing', 'Accident'],
      nap:       ['Slept well', 'Short nap', 'Restless', 'Did not sleep'],
      meal:      ['Ate all', 'Ate most', 'Ate some', 'Refused'],
      snack:     ['Ate all', 'Ate most', 'Ate some', 'Refused'],
      bottle:    ['Finished', 'Most of it', 'A few sips', 'Refused'],
      sunscreen: ['Applied', 'Reapplied'],
      mood:      ['Happy', 'Calm', 'Playful', 'Tired', 'Fussy', 'Upset', 'Unwell'],
    };

    // ── Photo / Video tiles ──────────────────────────────────────────────
    // Media-first sibling of openDetailsModal. One upload lands in the parent's
    // "Photos & video", the admin/director daily overview and that evening's
    // digest email, and pushes a notification to the child's guardians — because
    // all four read the same `photos` table this posts to.
    function openMediaModal(t, childId) {
      var isVideo = t.media === 'video';
      var MEDIA_MAX = 30 * 1024 * 1024;
      var kid = (CHILDREN || []).filter(function (c) { return String(c.id) === String(childId); })[0];
      var kidName = kid ? kid.name : 'this child';

      var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:18px;' });
      var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:440px;width:100%;padding:20px;max-height:88vh;overflow-y:auto;' });
      overlay.appendChild(modal);
      modal.innerHTML = '<h2 style="margin:0 0 4px;font-size:18px;">' + t.icon + ' ' + (isVideo ? 'Share a video' : 'Share a photo') + '</h2>'
        + '<p style="margin:0 0 14px;font-size:13px;color:#64748B;line-height:1.45;">'
        + esc(kidName) + '’s family sees this in Photos &amp; video, and it appears in the daily overview and tonight’s summary email.</p>';

      var file = Dom.el('input', {
        type: 'file',
        accept: isVideo ? 'video/mp4,video/quicktime,video/webm,video/3gpp' : 'image/jpeg,image/png,image/webp',
        style: 'display:none;',
      });
      var pick = Dom.el('button', {
        type: 'button',
        style: 'width:100%;box-sizing:border-box;background:#F8FAFC;border:1.5px dashed #CBD5E1;color:' + t.color + ';border-radius:12px;padding:22px 12px;font-size:14.5px;font-weight:800;cursor:pointer;',
      }, isVideo ? '🎬 Choose a video' : '📷 Choose a photo');
      var preview = Dom.el('div', { style: 'display:none;margin-top:10px;' });
      modal.appendChild(pick); modal.appendChild(file); modal.appendChild(preview);

      modal.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;' }, 'Description (required)'));
      var cap = Dom.el('input', {
        type: 'text', maxlength: '500', placeholder: 'What is happening in this ' + (isVideo ? 'video' : 'photo') + '?',
        style: 'width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:16px;',
      });
      modal.appendChild(cap);
      var msg = Dom.el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:8px;min-height:16px;' });
      modal.appendChild(msg);

      var chosen = null;
      pick.addEventListener('click', function () { file.click(); });
      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        if (f.size > MEDIA_MAX) {
          msg.style.color = '#DC2626';
          msg.textContent = 'That file is ' + (f.size / 1048576).toFixed(1) + ' MB — the limit is 30 MB (about 30 seconds of video).';
          file.value = ''; chosen = null; return;
        }
        chosen = f;
        msg.style.color = '#64748B';
        msg.textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + ' MB';
        pick.textContent = '🔁 Choose a different file';
        Dom.clear(preview);
        var url = URL.createObjectURL(f);
        preview.appendChild(/^video\//.test(f.type)
          ? Dom.el('video', { src: url, controls: true, preload: 'metadata', playsInline: true, style: 'width:100%;max-height:210px;border-radius:12px;background:#0F172A;display:block;' })
          : Dom.el('img', { src: url, alt: 'Preview', style: 'width:100%;max-height:210px;object-fit:cover;border-radius:12px;display:block;' }));
        preview.style.display = 'block';
      });

      var actions = Dom.el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:16px;' });
      var cancel = Dom.el('button', { style: 'background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;cursor:pointer;font-size:13px;' }, 'Cancel');
      cancel.addEventListener('click', function () { overlay.remove(); });
      var send = Dom.el('button', { style: 'background:' + t.color + ';color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Share it');
      send.addEventListener('click', function () {
        if (!chosen) { msg.style.color = '#B45309'; msg.textContent = 'Pick a ' + (isVideo ? 'video' : 'photo') + ' first.'; return; }
        // A description is mandatory: an untitled photo tells the parent nothing,
        // and the caption is what carries the moment into the digest email.
        if (!cap.value.trim()) {
          msg.style.color = '#B45309';
          msg.textContent = 'Add a short description so the family knows what they are looking at.';
          cap.style.borderColor = '#F59E0B'; cap.focus();
          return;
        }
        send.disabled = true; send.textContent = 'Uploading…';
        msg.style.color = '#64748B'; msg.textContent = 'Uploading — please keep this open.';
        ktShrinkImage(chosen, 1600, 0.82).then(async function (toSend) {
        if (toSend !== chosen) {
          msg.style.color = '#64748B';
          msg.textContent = 'Optimised ' + (chosen.size / 1048576).toFixed(1) + ' MB \u2192 '
            + (toSend.size / 1048576).toFixed(1) + ' MB, uploading\u2026';
        }
        var fd = new FormData();
        fd.append('photo', toSend);
        var _poster = await ktVideoPoster(toSend);
        if (_poster) fd.append('poster', _poster);
        fd.append('caption', cap.value.trim());
        fd.append('child_ids', JSON.stringify([childId]));
        fetch(_careApiBase() + '/photos', { method: 'POST', headers: { 'Authorization': 'Bearer ' + _careToken() }, body: fd })
          .then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
              if (!r.ok) throw new Error((d && d.message) || ('Upload failed (' + r.status + ')'));
              return d;
            });
          })
          .then(function () {
            overlay.remove();
            loadRecent();
            if (KT.toast) KT.toast(t.icon, isVideo ? 'Video shared' : 'Photo shared', kidName + '’s family can see it now.', 'success');
          })
          .catch(function (e) {
            msg.style.color = '#DC2626';
            msg.textContent = (e && e.message) || 'Upload failed — please try again.';
            send.disabled = false; send.textContent = 'Share it';
          });
        });
      });
      actions.appendChild(cancel); actions.appendChild(send);
      modal.appendChild(actions);
      document.body.appendChild(overlay);
    }

    function openDetailsModal(t, childId) {
      var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:18px;' });
      var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:420px;width:100%;padding:20px;max-height:88vh;overflow-y:auto;' });
      overlay.appendChild(modal);
      modal.innerHTML = '<h2 style="margin:0 0 12px;font-size:18px;">' + t.icon + ' ' + t.label + '</h2>';

      // Snack: a quick Morning / Afternoon / Evening pick, folded into the detail.
      var snackWhen = '';
      if (t.type === 'snack') {
        modal.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:7px;' }, 'Which snack?'));
        var swRow = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;' });
        var swEls = [];
        [['Morning', '🌅 Morning'], ['Afternoon', '☀️ Afternoon'], ['Evening', '🌆 Evening']].forEach(function (pair) {
          var val = pair[0];
          var chip = Dom.el('button', { type: 'button', style: 'border:1.5px solid #E2E8F0;background:#fff;color:#0F172A;border-radius:999px;padding:9px 14px;font-size:14px;font-weight:700;cursor:pointer;' }, pair[1]);
          chip.addEventListener('click', function () {
            snackWhen = (snackWhen === val) ? '' : val;
            swEls.forEach(function (c) {
              var on = snackWhen && c.textContent.indexOf(snackWhen) !== -1;
              c.style.background = on ? t.color : '#fff';
              c.style.color = on ? '#fff' : '#0F172A';
              c.style.borderColor = on ? t.color : '#E2E8F0';
            });
          });
          swEls.push(chip); swRow.appendChild(chip);
        });
        modal.appendChild(swRow);
      }

      var chosen = '';
      var opts = DETAIL_OPTIONS[t.type] || [];
      var detailsIn = Dom.el('input', { type: 'hidden' });   // carries the chosen value

      // "Other" is always offered: the fixed chips cover the common cases, but a
      // real day produces things no list anticipates, and without an escape hatch
      // the quick-pick would be a step backwards from free text.
      var otherIn = Dom.el('input', {
        type: 'text', placeholder: 'Describe it…',
        style: 'width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #159FB4;border-radius:10px;font-size:16px;margin:-6px 0 14px;display:none;',
      });

      if (opts.length) {
        modal.appendChild(Dom.el('div', {
          style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:7px;',
        }, 'What happened?'));
        var chips = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;' });
        var chipEls = [];
        opts.concat(['Other']).forEach(function (label) {
          var chip = Dom.el('button', {
            type: 'button',
            style: 'border:1.5px solid #E2E8F0;background:#fff;color:#0F172A;border-radius:999px;'
              + 'padding:9px 14px;font-size:14px;font-weight:700;cursor:pointer;',
          }, label);
          chip.addEventListener('click', function () {
            chosen = (chosen === label) ? '' : label;      // tap again to unpick
            detailsIn.value = chosen;
            chipEls.forEach(function (c) {
              var on = c.textContent === chosen;
              c.style.background = on ? t.color : '#fff';
              c.style.color = on ? '#fff' : '#0F172A';
              c.style.borderColor = on ? t.color : '#E2E8F0';
            });
            var other = chosen === 'Other';
            otherIn.style.display = other ? 'block' : 'none';
            if (other) otherIn.focus(); else otherIn.value = '';
          });
          chipEls.push(chip);
          chips.appendChild(chip);
        });
        modal.appendChild(chips);
        modal.appendChild(otherIn);
      }

      // Nap: capture the actual asleep → woke window (the parent sees real times).
      var napAsleep = null, napWoke = null;
      if (t.type === 'nap') {
        var napGrid = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;' });
        var col1 = Dom.el('div');
        col1.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;' }, 'Fell asleep'));
        napAsleep = Dom.el('input', { type: 'time', style: 'width:100%;box-sizing:border-box;padding:10px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;' });
        col1.appendChild(napAsleep);
        var col2 = Dom.el('div');
        col2.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;' }, 'Woke up'));
        napWoke = Dom.el('input', { type: 'time', style: 'width:100%;box-sizing:border-box;padding:10px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;' });
        col2.appendChild(napWoke);
        napGrid.appendChild(col1); napGrid.appendChild(col2);
        modal.appendChild(napGrid);
      }

      modal.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;',
      }, 'Note (optional)'));
      var notesIn = Dom.el('textarea', { placeholder: 'Anything the parent should know…', style: 'width:100%;padding:11px;border:1px solid #D1D5DB;border-radius:10px;font-size:16px;min-height:70px;box-sizing:border-box;font-family:inherit;' });
      modal.appendChild(notesIn);
      var amtWrap;
      if (t.type === 'bottle') {
        amtWrap = Dom.el('div', { style: 'display:flex;gap:8px;margin-top:8px;align-items:center;' });
        amtWrap.appendChild(Dom.el('label', { style: 'font-size:13px;color:#6B7280;' }, 'oz:'));
        var amtIn = Dom.el('input', { type: 'number', step: '0.5', placeholder: '4', style: 'width:80px;padding:8px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;' });
        amtWrap.appendChild(amtIn);
        modal.appendChild(amtWrap);
      }
      // Optional "when" — educators frequently log a moment well after it happened
      // (mid-room you can't always stop to log). Defaults to now; change it to
      // back-time the entry. Nap uses its own asleep/woke times instead.
      var whenIn = null;
      if (t.type !== 'nap') {
        modal.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:12px 0 6px;' }, 'When did it happen? (optional)'));
        whenIn = Dom.el('input', { type: 'time', value: _careNowHHMM(), style: 'width:100%;box-sizing:border-box;padding:10px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;' });
        modal.appendChild(whenIn);
      }

      // ── Photo / video of the moment ──────────────────────────────────────
      // Uploads to POST /photos (PhoteFeedController), which is the table the
      // parent gallery reads (`photos`, tagged via child_ids) and which already
      // notifies the child's guardians. Accepts stills AND short clips; the server
      // caps at 30MB, so we reject earlier with a clearer message than a 422.
      var MEDIA_MAX = 30 * 1024 * 1024;
      var mediaFile = null;
      modal.appendChild(Dom.el('div', {
        style: 'font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;',
      }, 'Photo or video (optional)'));
      var fileIn = Dom.el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/3gpp', style: 'display:none;' });
      var pickBtn = Dom.el('button', {
        type: 'button',
        style: 'width:100%;box-sizing:border-box;background:#F8FAFC;border:1.5px dashed #CBD5E1;color:#1F6080;border-radius:10px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;',
      }, '📷 Add a photo or video');
      var mediaPreview = Dom.el('div', { style: 'display:none;margin-top:10px;' });
      var capIn = Dom.el('input', {
        type: 'text', maxlength: '500', placeholder: 'Describe this moment for the parent… (required)',
        style: 'width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #159FB4;border-radius:10px;font-size:16px;margin-top:9px;display:none;',
      });
      var mediaMsg = Dom.el('div', { style: 'font-size:12px;color:#64748B;margin-top:6px;min-height:15px;' });

      function clearMedia() {
        mediaFile = null; fileIn.value = '';
        Dom.clear(mediaPreview); mediaPreview.style.display = 'none';
        capIn.style.display = 'none'; capIn.value = '';
        pickBtn.textContent = '📷 Add a photo or video';
        mediaMsg.textContent = '';
      }
      pickBtn.addEventListener('click', function () { fileIn.click(); });
      fileIn.addEventListener('change', function () {
        var f = fileIn.files && fileIn.files[0];
        if (!f) return;
        if (f.size > MEDIA_MAX) {
          mediaMsg.style.color = '#DC2626';
          mediaMsg.textContent = 'That file is ' + (f.size / 1048576).toFixed(1) + ' MB — the limit is 30 MB (about 30 seconds of video).';
          fileIn.value = ''; return;
        }
        mediaFile = f;
        mediaMsg.style.color = '#64748B';
        mediaMsg.textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + ' MB';
        pickBtn.textContent = '🔁 Choose a different file';
        Dom.clear(mediaPreview);
        var url = URL.createObjectURL(f);
        var isVid = /^video\//.test(f.type);
        mediaPreview.appendChild(isVid
          ? Dom.el('video', { src: url, controls: true, preload: 'metadata', playsInline: true, style: 'width:100%;max-height:190px;border-radius:10px;background:#0F172A;display:block;' })
          : Dom.el('img', { src: url, alt: 'Preview', style: 'width:100%;max-height:190px;object-fit:cover;border-radius:10px;display:block;' }));
        var rm = Dom.el('button', { type: 'button', style: 'background:none;border:0;color:#B91C1C;font-size:12.5px;font-weight:700;cursor:pointer;padding:6px 0 0;' }, '✕ Remove');
        rm.addEventListener('click', function () { try { URL.revokeObjectURL(url); } catch (e) {} clearMedia(); });
        mediaPreview.appendChild(rm);
        mediaPreview.style.display = 'block';
        capIn.style.display = 'block';
      });
      modal.appendChild(pickBtn);
      modal.appendChild(fileIn);
      modal.appendChild(mediaPreview);
      modal.appendChild(capIn);
      modal.appendChild(mediaMsg);

      var actions = Dom.el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:14px;' });
      var cancel = Dom.el('button', { style: 'background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;cursor:pointer;font-size:13px;' }, 'Cancel');
      cancel.addEventListener('click', function () { overlay.remove(); });
      var save = Dom.el('button', { style: 'background:' + t.color + ';color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Log it');
      var logSaved = false;
      save.addEventListener('click', function () {
        // "Other" means the typed text IS the detail — don't file it as the
        // literal word "Other".
        var detail = detailsIn.value.trim();
        if (detail === 'Other') detail = otherIn.value.trim();
        // Fold the nap window / snack time-of-day into the detail string (the care
        // log stores one detail field, which rolls up to the parent's Today feed).
        function _t12(v) { if (!v) return ''; var p = String(v).split(':'); var h = parseInt(p[0], 10); if (isNaN(h)) return ''; var ap = h < 12 ? 'AM' : 'PM'; var h12 = h % 12; if (h12 === 0) h12 = 12; return h12 + ':' + p[1] + ' ' + ap; }
        if (t.type === 'nap' && napAsleep && (napAsleep.value || napWoke.value)) {
          var win = (_t12(napAsleep.value) || '?') + ' → ' + (_t12(napWoke.value) || '?');
          detail = detail ? (detail + ' · ' + win) : win;
        }
        if (t.type === 'snack' && snackWhen) {
          detail = detail ? (snackWhen + ' snack · ' + detail) : (snackWhen + ' snack');
        }
        var body = { child_id: childId, log_type: t.type, details: detail || null, notes: notesIn.value.trim() || null };
        if (t.type === 'bottle' && amtIn && amtIn.value) body.amount_oz = parseFloat(amtIn.value);
        // Timestamps: nap uses its asleep→woke inputs (occurred/ended); everything
        // else uses the optional "When?" field so a late-logged moment lands at the
        // real time, not when the educator got round to tapping it.
        if (t.type === 'nap') {
          var aIso = _careIsoAt(napAsleep && napAsleep.value);
          var wIso = _careIsoAt(napWoke && napWoke.value);
          if (aIso) body.occurred_at = aIso;
          if (wIso) body.ended_at = wIso;
        } else if (whenIn && whenIn.value) {
          var whenIso = _careIsoAt(whenIn.value);
          if (whenIso) body.occurred_at = whenIso;
        }
        // Media requires a description. Checked BEFORE any POST so we never file the
        // care log and then refuse the upload, which would leave the two out of step.
        if (mediaFile && !capIn.value.trim()) {
          mediaMsg.style.color = '#B45309';
          mediaMsg.textContent = 'Add a short description for the photo or video before saving.';
          capIn.style.borderColor = '#F59E0B'; capIn.focus();
          return;
        }
        save.disabled = true; save.textContent = 'Saving…';
        // `logSaved` makes "Retry upload" retry ONLY the upload. Without it, a
        // failed upload followed by a retry would POST /care/logs a second time and
        // file the same moment twice on the parent's timeline.
        (logSaved ? Promise.resolve() : Api.post('/care/logs', body).then(function () { logSaved = true; })).then(function () {
          // The care log is saved. If media was attached, upload it as a second
          // step so a failed/slow upload can never lose the log itself — we report
          // the upload separately rather than rolling anything back.
          if (!mediaFile) { overlay.remove(); loadRecent(); return null; }
          save.textContent = 'Uploading…';
          mediaMsg.style.color = '#64748B';
          mediaMsg.textContent = 'Uploading ' + (/^video\//.test(mediaFile.type) ? 'video' : 'photo') + '…';
          // Shrink first (see ktShrinkImage): a phone photo is 4-6MB and every view
          // of it is a few hundred pixels wide.
          return ktShrinkImage(mediaFile, 1600, 0.82).then(async function (toSend) {
          if (toSend !== mediaFile) {
            mediaMsg.textContent = 'Optimised ' + (mediaFile.size / 1048576).toFixed(1) + ' MB → '
              + (toSend.size / 1048576).toFixed(1) + ' MB, uploading…';
          }
          var fd = new FormData();
          fd.append('photo', toSend);
          var _poster2 = await ktVideoPoster(toSend);
          if (_poster2) fd.append('poster', _poster2);
          // Caption priority: the dedicated description, else the note, else the
          // picked detail — the parent should never see an untitled photo.
          fd.append('caption', capIn.value.trim());
          fd.append('child_ids', JSON.stringify([childId]));
          return fetch(_careApiBase() + '/photos', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + _careToken() },
            body: fd,
          }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
              if (!r.ok) throw new Error((d && d.message) || ('Upload failed (' + r.status + ')'));
              return d;
            });
          }).then(function (d) {
            overlay.remove(); loadRecent();
            if (KT.toast) {
              KT.toast('📸', 'Moment shared', (d && d.media_type === 'video' ? 'The video' : 'The photo') + ' is now in the parent’s Photos & video.', 'success');
            }
          });
          });
        }).catch(function (e) {
          // Distinguish "the log didn't save" from "the log saved, the upload didn't".
          mediaMsg.style.color = '#DC2626';
          mediaMsg.textContent = (e && e.message) || 'Something went wrong.';
          save.disabled = false; save.textContent = mediaFile ? 'Retry upload' : 'Log it';
        });
      });
      actions.appendChild(cancel); actions.appendChild(save);
      modal.appendChild(actions);
      document.body.appendChild(overlay);
    }
  }

  // ─── Portfolio — chronological observations + milestone progress ──
  function renderPortfolio(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);
    var childId = paramId();
    if (!childId) { wrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Missing child id — open from a child detail page.')); return; }

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📚 PORTFOLIO</div><h1>Learning journey</h1><div class="kt-hero-sub">Every observation, photo, and milestone — in one chronological view.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div', { style: 'margin-top:18px;' });
    wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:32px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/care/portfolio/' + childId).then(function (data) {
      Dom.clear(body);
      var c = data.child || {};
      var st = data.stats || {};

      var head = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:14px;display:flex;align-items:center;gap:18px;' });
      head.appendChild(Dom.el('div', { style: 'width:64px;height:64px;border-radius:50%;background:#1F6080;color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;' }, (c.preferred_name || c.first_name || '?').charAt(0).toUpperCase()));
      var headBody = Dom.el('div');
      headBody.appendChild(Dom.el('div', { style: 'font-size:20px;font-weight:800;color:#0F172A;' }, (c.preferred_name || c.first_name) + ' ' + c.last_name));
      headBody.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;' }, c.date_of_birth ? ('Born ' + c.date_of_birth) : ''));
      head.appendChild(headBody);
      body.appendChild(head);

      var stats = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;' });
      function tile(label, value, color) {
        var t = Dom.el('div', { style: 'background:white;padding:14px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);border-left:4px solid ' + color + ';' });
        t.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, label));
        t.appendChild(Dom.el('div', { style: 'font-size:24px;font-weight:800;color:#0F172A;' }, String(value)));
        return t;
      }
      stats.appendChild(tile('Observations', st.observations || 0, '#7C3AED'));
      stats.appendChild(tile('Milestones achieved', st.milestones_achieved || 0, '#16A34A'));
      stats.appendChild(tile('In progress', st.milestones_in_progress || 0, '#F59E0B'));
      stats.appendChild(tile('Emerging', st.milestones_emerging || 0, '#1F6080'));
      body.appendChild(stats);

      // Timeline of observations
      var feed = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);' });
      feed.appendChild(Dom.el('h3', { style: 'margin:0 0 14px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Observation timeline'));
      if (!data.observations.length) {
        feed.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'No observations recorded yet.'));
      } else {
        data.observations.forEach(function (o) {
          var item = Dom.el('div', { style: 'border-left:3px solid #7C3AED;padding:6px 14px;margin-bottom:14px;background:#F8FAFC;border-radius:0 10px 10px 0;' });
          item.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;font-weight:700;letter-spacing:.5px;' }, fmt(o.observed_at) + ' · ' + o.framework + ' / ' + o.domain + ' · ' + o.recorded_by));
          if (o.title) item.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#0F172A;margin-top:3px;' }, o.title));
          item.appendChild(Dom.el('div', { style: 'font-size:13px;color:#374151;margin-top:3px;line-height:1.5;' }, o.body || ''));
          feed.appendChild(item);
        });
      }
      body.appendChild(feed);
    }).catch(function (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  // ─── Milestones checklist ─────────────────────────────────────────
  function renderMilestones(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);
    var childId = paramId();
    if (!childId) { wrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Missing child id.')); return; }

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#16A34A 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">🌱 MILESTONES</div><h1>Developmental milestones</h1><div class="kt-hero-sub">Tap each milestone to mark it Emerging, In progress, or Achieved. Aligned to HDLH domains.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div'); wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'Loading…'));

    Promise.all([
      Api.get('/care/milestones/catalog'),
      Api.get('/care/milestones/child/' + childId),
    ]).then(function (results) {
      var catalog = results[0].catalog;
      var records = results[1].records || {};
      Dom.clear(body);

      Object.keys(catalog).forEach(function (band) {
        var bandWrap = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-top:14px;' });
        bandWrap.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:18px;color:#0F172A;text-transform:capitalize;' }, band));
        bandWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-bottom:12px;' }, 'Age band'));
        Object.keys(catalog[band]).forEach(function (domain) {
          bandWrap.appendChild(Dom.el('h4', { style: 'margin:14px 0 6px;font-size:12px;color:#7C3AED;font-weight:700;letter-spacing:1px;text-transform:uppercase;' }, domain));
          catalog[band][domain].forEach(function (m) {
            var current = records[m.key] ? records[m.key].status : 'emerging';
            var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;' });
            row.appendChild(Dom.el('div', { style: 'flex:1;font-size:13px;color:#0F172A;' }, m.label));
            ['emerging','in_progress','achieved'].forEach(function (s) {
              var colors = { emerging: '#9CA3AF', in_progress: '#F59E0B', achieved: '#16A34A' };
              var b = Dom.el('button', {
                style: 'background:' + (current === s ? colors[s] : 'white') + ';color:' + (current === s ? 'white' : '#6B7280') + ';border:1px solid ' + (current === s ? colors[s] : '#E5E7EB') + ';padding:5px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;text-transform:capitalize;',
              }, s.replace('_',' '));
              b.addEventListener('click', function () {
                Api.post('/care/milestones', {
                  child_id: childId, framework: 'HDLH', domain: domain,
                  milestone_key: m.key, milestone_label: m.label,
                  status: s, observed_at: new Date().toISOString().slice(0,10),
                }).then(function () { renderMilestones(container); }).catch(function (e) { alert('Failed: ' + e.message); });
              });
              row.appendChild(b);
            });
            bandWrap.appendChild(row);
          });
        });
        body.appendChild(bandWrap);
      });
    });
  }

  // ─── Tour bookings (admin view) ───────────────────────────────────
  function renderTours(container) {
    Dom.clear(container);
    // v22p75.2: fully self-contained inline styling — NO data-kt-pretty and NO
    // design-system classes (.kt-card/.kt-kpi/.kt-btn), which double-applied from
    // v22p55 + v22p72 CSS and broke the layout. Everything below is explicit.
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    // No banner here: the shell renders the standard one for every screen, and this
    // hand-rolled gradient hero was stacking a SECOND banner underneath it. The
    // explanatory line lives on as a caption above the table.
    var intro = Dom.el('div', { style: 'font-size:14px;color:#5A6B7B;max-width:640px;margin:0 0 4px;' });
    intro.textContent = 'Prospective families that requested a centre tour through your public booking page. Confirm, complete, or cancel each one.';
    wrap.appendChild(intro);

    // Public booking link card
    var agencyId = sessionStorage.getItem('kt_active_agency_id') || '';
    var publicUrl = (window.location.origin || 'https://app.kiddietrac.com') + '/book-tour.html' + (agencyId ? ('?agency=' + agencyId) : '');
    var cardStyle = 'background:#fff;border:1px solid #EAEFF3;border-radius:14px;box-shadow:0 1px 2px rgba(16,40,64,.04),0 6px 16px -8px rgba(16,40,64,.12);';
    var linkCard = Dom.el('div', { style: cardStyle + 'margin-top:18px;padding:16px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;' });
    linkCard.appendChild(Dom.el('span', { style: 'font-size:13px;font-weight:700;color:#1F6080;flex-shrink:0;' }, '🔗 Public booking link'));
    var linkInput = Dom.el('input', { readonly: 'readonly', value: publicUrl, style: 'flex:1;min-width:220px;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:12px;background:#F8FAFC;box-sizing:border-box;' });
    linkInput.addEventListener('click', function () { linkInput.select(); });
    linkCard.appendChild(linkInput);
    var copyBtn = Dom.el('button', { style: 'flex-shrink:0;font-size:13px;font-weight:700;padding:9px 16px;border:none;border-radius:8px;cursor:pointer;background:#1F6080;color:#fff;' }, 'Copy');
    copyBtn.addEventListener('click', function () {
      try { navigator.clipboard.writeText(publicUrl); copyBtn.textContent = '✓ Copied'; setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1400); } catch (e) {}
    });
    linkCard.appendChild(copyBtn);
    var csvBtn = Dom.el('button', { style: 'flex-shrink:0;font-size:13px;font-weight:700;padding:9px 16px;border:none;border-radius:8px;cursor:pointer;background:#F1F5F9;color:#1F2937;' }, '⤓ Export CSV');
    linkCard.appendChild(csvBtn);
    wrap.appendChild(linkCard);

    // Summary KPI row (explicit grid + tiles)
    var kpiRow = Dom.el('div', { style: 'margin-top:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;' });
    wrap.appendChild(kpiRow);

    // Status filter
    var filterBar = Dom.el('div', { style: 'margin-top:18px;display:flex;gap:8px;flex-wrap:wrap;' });
    wrap.appendChild(filterBar);

    var listWrap = Dom.el('div', { 'data-kt-list': '1', style: cardStyle + 'overflow:hidden;margin-top:14px;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'Loading…'));

    var allTours = [];
    var activeFilter = '';

    function kpi(label, value, color) {
      var k = Dom.el('div', { style: cardStyle + 'padding:16px 18px;' });
      k.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:12px;font-weight:600;' }, label));
      k.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;line-height:1.1;margin-top:4px;color:' + (color || '#111827') + ';' }, String(value)));
      return k;
    }

    function drawFilterBar() {
      Dom.clear(filterBar);
      [['', 'All'], ['requested', 'Requested'], ['confirmed', 'Confirmed'], ['completed', 'Completed'], ['no_show', 'No-show'], ['cancelled', 'Cancelled']].forEach(function (f) {
        var fval = f[0];
        var on = (activeFilter === fval);
        var chip = Dom.el('button', {
          style: 'font-size:12px;font-weight:600;padding:7px 15px;border-radius:18px;cursor:pointer;border:1px solid ' + (on ? '#1F6080' : '#E2E8F0') + ';' + (on ? 'background:#1F6080;color:#fff;' : 'background:#fff;color:#4B5563;')
        }, f[1]);
        chip.addEventListener('click', function () { activeFilter = fval; drawFilterBar(); drawList(); });
        filterBar.appendChild(chip);
      });
    }

    function drawList() {
      Dom.clear(listWrap);
      var rows = activeFilter ? allTours.filter(function (t) { return t.status === activeFilter; }) : allTours;
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, activeFilter ? ('No ' + activeFilter.replace('_', ' ') + ' tours.') : 'No tour requests yet. Share your public link above to start collecting bookings.'));
        return;
      }
      rows.forEach(function (t) { listWrap.appendChild(renderTourRow(t, container)); });
    }

    Api.get('/admin/tours').then(function (data) {
      allTours = (data && data.tours) || [];
      // KPIs
      Dom.clear(kpiRow);
      var counts = { requested: 0, confirmed: 0, completed: 0, no_show: 0, cancelled: 0 };
      allTours.forEach(function (t) { if (counts[t.status] != null) counts[t.status]++; });
      kpiRow.appendChild(kpi('Total', allTours.length, '#1F6080'));
      kpiRow.appendChild(kpi('Requested', counts.requested, '#F59E0B'));
      kpiRow.appendChild(kpi('Confirmed', counts.confirmed, '#1F6080'));
      kpiRow.appendChild(kpi('Completed', counts.completed, '#16A34A'));
      kpiRow.appendChild(kpi('No-show', counts.no_show, '#DC2626'));
      drawFilterBar();
      drawList();
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Failed: ' + (e.message || 'error')));
    });

    csvBtn.addEventListener('click', function () {
      if (!allTours.length) return;
      var head = ['Parent', 'Email', 'Phone', 'Centre', 'Tour at', 'Preferred start', 'Child age (mo)', 'Status', 'Notes'];
      var lines = [head.join(',')];
      allTours.forEach(function (t) {
        var cells = [t.parent_name, t.parent_email, t.parent_phone || '', t.centre_name || '', t.tour_at || '', t.preferred_start_date || '', t.child_age_months || '', t.status, (t.notes || '').replace(/\n/g, ' ')];
        lines.push(cells.map(function (c) { return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(','));
      });
      var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'tour-bookings.csv';
      a.click();
    });
  }

  function renderTourRow(t, container) {
    var statusColors = { requested: '#F59E0B', confirmed: '#1F6080', completed: '#16A34A', no_show: '#DC2626', cancelled: '#6B7280' };
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;' });
    row.appendChild(Dom.el('div', { style: 'width:8px;height:48px;border-radius:4px;background:' + (statusColors[t.status] || '#E5E7EB') + ';flex-shrink:0;' }));
    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#111827;' }, (t.parent_name || 'Unnamed') + ' · ' + (t.centre_name || '—')));
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, 'Tour ' + fmt(t.tour_at) + ' · ' + (t.parent_email || 'no email') + (t.parent_phone ? ' · ' + t.parent_phone : '') + (t.child_age_months ? ' · child ' + t.child_age_months + ' mo' : '')));
    if (t.preferred_start_date) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#1F6080;margin-top:2px;font-weight:600;' }, '📅 Wants to start: ' + fmtDateOnly(t.preferred_start_date)));
    if (t.notes) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#374151;margin-top:4px;' }, '“' + t.notes + '”'));
    row.appendChild(body);
    var sel = Dom.el('select', { style: 'width:130px;min-width:0;flex-shrink:0;padding:6px 8px;border:1px solid #D1D5DB;border-radius:8px;font-size:12px;background:white;' });
    ['requested','confirmed','completed','no_show','cancelled'].forEach(function (s) {
      var opt = Dom.el('option', { value: s }, s.replace('_',' '));
      if (s === t.status) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', function () {
      Api.patch('/admin/tours/' + t.id, { status: sel.value }).then(function () { renderTours(container); });
    });
    row.appendChild(sel);
    return row;
  }

  // ─── Shell registration ───────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    ['agency_admin','centre_director','educator'].forEach(function (r) {
      Shell.registerScreen(r + ':time-clock', renderTimeClock);
      Shell.registerScreen(r + ':care-log',   renderCareLog);
    });
    Shell.registerScreen('guardian:care-log', renderCareLog);
    ['agency_admin','centre_director','educator','guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':portfolio',  renderPortfolio);
      Shell.registerScreen(r + ':milestones', renderMilestones);
    });
    ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':tours', renderTours);
    });
  }
  KT.Care = {
    renderTimeClock: renderTimeClock,
    renderCareLog: renderCareLog,
    renderPortfolio: renderPortfolio,
    renderMilestones: renderMilestones,
    renderTours: renderTours,
  };
})(window);
