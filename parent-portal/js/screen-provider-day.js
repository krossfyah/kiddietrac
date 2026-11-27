/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Provider Daily Overview (director/admin quick glance).
   Pick a provider + date → attendance roster (kids by name, in/out, QR vs
   manual), staff clock in/out, the week's meal plan, activities & all daily
   logs, plus summary stats with charts. Today by default; any past date works.
   Registered for agency_admin / centre_director / platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // Presence dot: green = available (active in the app), amber = idle (on shift but
  // quiet). No dot for anything else (e.g. clocked out).
  function presenceDot(p) {
    if (p !== 'available' && p !== 'idle') return '';
    var c = p === 'available' ? '#16A34A' : '#F59E0B';
    return '<span title="' + (p === 'available' ? 'Available' : 'Idle') + '" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + c + ';box-shadow:0 0 0 2px #fff;margin-right:7px;vertical-align:middle;"></span>';
  }
  function todayISO() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  /* Stepped in UTC on purpose — the same helper screen-care.js uses for the educator's
     Daily log. A local-midnight Date lands on the wrong day across a daylight-saving
     boundary, and "‹" quietly skipping a day is worse than no arrow at all. */
  function shiftDate(iso, days) {
    var d = new Date(iso + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }
  /* Says WHICH day you are on. Flipping through a week by arrow with only a numeric
     field to read means counting back to work out whether you are on Friday. */
  function prettyDay(iso) {
    if (iso === todayISO()) { return 'Today'; }
    if (iso === shiftDate(todayISO(), -1)) { return 'Yesterday'; }
    try {
      // Numeric parts, never new Date('YYYY-MM-DD') — that parses as UTC and names the
      // day before for anybody west of Greenwich.
      var p = iso.split('-');
      return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString('en-CA', {
        weekday: 'long', month: 'short', day: 'numeric',
      });
    } catch (e) { return iso; }
  }
  function avatar(name, photo, sex, size, child) {
    if (window.KT && KT.avatar) return KT.avatar(name, { size: size, photoUrl: photo ? _abs(photo) : '', sex: sex, kind: child ? 'child' : undefined });
    return '<span style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:#E2E8F0;display:inline-block;"></span>';
  }
  function _abs(u) { if (!u) return ''; if (/^https?:\/\//.test(u)) return u; var b = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; return b.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }

  var state = { centres: [], centreId: null, date: todayISO(), gen: 0 };

  /* THE ELEMENT THAT IS ACTUALLY ON THE PAGE, re-resolved after every await.

     A reference taken before an await can be detached by the time the await resolves —
     a cosmetic sweep that round-trips #appMain's innerHTML leaves the markup looking
     identical but replaces every node. Writing to the old one succeeds silently and
     shows nobody anything, which is how this screen sat on "Loading…" forever.

     Prefer the copy inside our own container; fall back to whatever carries the id on
     the live page; return null when neither is attached, which means the screen is gone
     and the caller should stop. */
  function liveEl(container, id) {
    var el = (container && container.querySelector) ? container.querySelector('#' + id) : null;
    if (el && document.contains(el)) { return el; }
    el = document.getElementById(id);
    return (el && document.contains(el)) ? el : null;
  }

  // Donut: QR (teal) vs Manual (amber) share of check-ins.
  function donut(qr, manual) {
    var total = qr + manual;
    if (!total) return '<div style="color:#94A3B8;font-size:12px;padding:20px 0;text-align:center;">No check-ins on this day.</div>';
    var C = 2 * Math.PI * 42, qrLen = C * (qr / total);
    return '<div style="display:flex;align-items:center;gap:16px;">'
      + '<svg width="112" height="112" viewBox="0 0 120 120" style="flex-shrink:0;">'
      + '<circle cx="60" cy="60" r="42" fill="none" stroke="#F59E0B" stroke-width="15"/>'
      + '<circle cx="60" cy="60" r="42" fill="none" stroke="#0EA5E9" stroke-width="15" stroke-dasharray="' + qrLen + ' ' + (C - qrLen) + '" transform="rotate(-90 60 60)" stroke-linecap="round"/>'
      + '<text x="60" y="57" text-anchor="middle" font-size="21" font-weight="800" fill="#0F172A">' + Math.round((qr / total) * 100) + '%</text>'
      + '<text x="60" y="74" text-anchor="middle" font-size="9.5" fill="#64748B">via One-Tap</text></svg>'
      + '<div style="font-size:12.5px;">'
      + '<div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;"><span style="width:11px;height:11px;border-radius:3px;background:#0EA5E9;display:inline-block;"></span><b>' + qr + '</b> One-Tap / kiosk scan' + (qr === 1 ? '' : 's') + '</div>'
      + '<div style="display:flex;align-items:center;gap:7px;"><span style="width:11px;height:11px;border-radius:3px;background:#F59E0B;display:inline-block;"></span><b>' + manual + '</b> manual check-in' + (manual === 1 ? '' : 's') + '</div>'
      + '</div></div>';
  }
  // Stacked attendance bar: in / out / away.
  function attBar(s) {
    var total = Math.max(1, s.enrolled);
    var seg = function (n, col, lbl) { var w = (n / total) * 100; return w > 0 ? '<div title="' + lbl + ': ' + n + '" style="width:' + w + '%;background:' + col + ';"></div>' : ''; };
    /* Children not booked that day are not "absent" (2026-11-01): their own grey slice. */
    var unsched = Math.max(0, s.not_scheduled || 0);
    var away = Math.max(0, s.enrolled - s.attended - unsched);
    return '<div style="display:flex;height:16px;border-radius:8px;overflow:hidden;background:#F1F5F9;">'
      + seg(s.still_in, '#16A34A', 'At Centre') + seg(s.went_home, '#F59E0B', 'Not at Centre') + seg(away, '#CBD5E1', 'Absent') + seg(unsched, '#E2E8F0', 'Not scheduled') + '</div>'
      + '<div style="display:flex;gap:14px;margin-top:8px;font-size:11.5px;color:#475569;flex-wrap:wrap;">'
      + '<span><span style="color:#16A34A;">●</span> ' + s.still_in + ' at Centre</span>'
      + '<span><span style="color:#F59E0B;">●</span> ' + s.went_home + ' not at Centre</span>'
      + '<span><span style="color:#CBD5E1;">●</span> ' + away + ' absent</span>'
      + (unsched ? '<span><span style="color:#E2E8F0;">●</span> ' + unsched + ' not scheduled</span>' : '') + '</div>';
  }
  /**
   * "Daily forms" for the provider being viewed, on the date being viewed.
   *
   * Submitting a form left no trace on any screen — the signoff went into the database
   * and nothing said so — which is what Anthony asked to fix. What makes this worth a
   * card rather than a number is the STARTED vs SUBMITTED split: opening a form saves a
   * draft, only a signature counts, and on the day this was written 4 of 10 entries
   * agency-wide were drafts nobody had finished.
   */
  /* HOW A DEPARTURE WAS RECORDED (2026-10-22). Automatic sign-outs are placeholders; a
     parent (or the educator) can now enter the real time from the email, and that is
     applied straight away — so it is marked, with the automatic time it replaced. */
  function outHowBadge(how, autoAt, who) {
    if (!how) { return ''; }
    var pill = 'display:inline-block;margin-left:6px;font-size:10.5px;font-weight:800;border-radius:999px;padding:1px 7px;white-space:nowrap;vertical-align:middle;';
    if (how === 'parent_email' || how === 'educator_email') {
      return '<span title="' + esc('Entered by the ' + who + ' from the automatic sign-out email' + (autoAt ? ' — the automatic time was ' + autoAt : '')) + '" style="'
        + pill + 'color:#1E40AF;background:#DBEAFE;">entered by ' + (how === 'parent_email' ? 'parent' : 'educator') + '</span>';
    }
    /* The row closed for lunch / a break, not for the day (StaffBreaks, 2026-11-05). */
    if (how === 'lunch' || how === 'break') {
      return '<span data-kttip="' + esc(how === 'lunch' ? 'Clocked out for lunch' : 'Clocked out for a break') + '" style="' + pill + 'color:#6D28D9;background:#EDE9FE;">' + (how === 'lunch' ? '🍽️ lunch' : '☕ break') + '</span>';
    }
    if (how === 'automatic') {
      return '<span title="Nobody signed out — the nightly job filled this in" style="' + pill + 'color:#92400E;background:#FEF3C7;">automatic</span>';
    }
    return '<span title="' + esc('Corrected by staff' + (autoAt ? ' — the automatic time was ' + autoAt : '')) + '" style="' + pill + 'color:#475569;background:#F1F5F9;">corrected</span>';
  }

  function formsCardHtml(fd) {
    if (!fd || !fd.expected) {
      return '<div style="color:#94A3B8;font-size:12px;padding:16px 0;text-align:center;">'
        + 'No daily forms are assigned to this provider.</div>';
    }
    var staff = fd.staff || [];
    if (!staff.length) {
      return '<div style="color:#94A3B8;font-size:12px;padding:16px 0;text-align:center;">'
        + 'No educators at this provider.</div>';
    }

    return staff.map(function (p) {
      var done = p.submitted >= p.expected;
      var head = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'
        + '<span style="flex:1;min-width:0;font-size:13.5px;font-weight:700;color:#0F172A;'
          + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(p.name) + '</span>'
        + '<span style="font-size:12px;font-weight:800;font-variant-numeric:tabular-nums;color:'
          + (done ? '#166534' : (p.drafts ? '#92400E' : '#64748B')) + ';">'
          + p.submitted + '/' + p.expected + '</span></div>';

      var rows = (p.forms || []).map(function (f) {
        var mark = f.state === 'submitted' ? '✓' : (f.state === 'draft' ? '◌' : '○');
        var col = f.state === 'submitted' ? '#166534' : (f.state === 'draft' ? '#92400E' : '#94A3B8');
        var note = f.state === 'submitted' ? esc(f.at)
          : (f.state === 'draft' ? 'started ' + esc(f.at) + ', not submitted' : 'not started');
        return '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;">'
          + '<span style="width:13px;text-align:center;color:' + col + ';font-size:13px;">' + mark + '</span>'
          + '<span style="flex:1;min-width:0;font-size:12.5px;color:#334155;overflow:hidden;'
            + 'text-overflow:ellipsis;white-space:nowrap;">' + esc(f.title)
            /* Only reusable forms are due every day; a one-time form shows until it is
               signed, then leaves the list (formDayItem, 2026-11-27). */
            + '</span>' + (f.reusable === false ? '<span data-kttip="One-time form: once signed it leaves this list" style="flex:0 0 auto;font-size:10px;font-weight:800;color:#6D28D9;background:#F3F0FF;border-radius:999px;padding:1px 7px;white-space:nowrap;">one-time</span>' : '')
          + '<span style="font-size:11px;color:' + col + ';white-space:nowrap;">' + note + '</span>'
          + '</div>';
      }).join('');

      return '<div style="padding:9px 0;border-top:1px solid #F1F5F9;">' + head + rows + '</div>';
    }).join('');
  }

  /* LIGHTLY COLOURED CARDS (2026-10-27, Anthony: "daily overview, the cards, pls lightly
     colourized and make this look better"). Each section takes a tone from its title: a soft
     wash of that colour at the top fading to white, a border in the same hue, and the title
     in the darker shade. Kept light so the figures inside still lead. */
  var CARD_TONES = [
    [/planned|lesson/i, '#7C3AED'], [/walk/i, '#0284C7'], [/performance/i, '#1F6080'],
    [/check-in/i, '#16A34A'], [/children/i, '#D97706'], [/provider|educator/i, '#0F766E'],
    [/headcount/i, '#DB2777'], [/photo|video/i, '#EA580C'], [/chat/i, '#0EA5E9'],
    [/forms/i, '#4F46E5'], [/observation/i, '#9333EA'], [/meal/i, '#65A30D'], [/activit|log/i, '#E11D48'],
  ];
  function toneFor(title) {
    for (var i = 0; i < CARD_TONES.length; i++) { if (CARD_TONES[i][0].test(title)) return CARD_TONES[i][1]; }
    return '#1F6080';
  }
  function card(title, inner) {
    var t = toneFor(String(title).replace(/<[^>]*>/g, ''));
    return '<div class="pd-card" style="background:linear-gradient(180deg,' + t + '14 0,' + t + '08 70px,#fff 160px);border:1px solid ' + t + '30;border-radius:16px;padding:15px 16px;box-shadow:0 2px 10px ' + t + '12;min-width:0;">'
      + '<div style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:' + t + ';margin-bottom:12px;">'
      + '<span style="width:6px;height:16px;border-radius:3px;background:' + t + ';flex-shrink:0;"></span><span style="min-width:0;">' + title + '</span></div>' + inner + '</div>';
  }
  /* AUTO-ARRANGE (2026-11-04). Each card in .pd-pack spans the 4px grid rows its own height needs (plus the 16px
     gap), so the grid's dense packing can slot a short card into the hole beside a tall one. The children's table
     spans two columns when there are three or more, and is the full width at two. Re-measured on any card's
     resize, one pass per frame. On a phone the pack is a plain column and nothing is set. */
  function packCards(body) {
    var pack = body.querySelector('.pd-pack');
    if (!pack) return;
    var ROW = 4, GAP = 16, raf = 0;
    function run() {
      raf = 0;
      if (!pack.isConnected) { if (pack.__ro) pack.__ro.disconnect(); return; }
      var grid = getComputedStyle(pack).display === 'grid';
      var cols = grid ? getComputedStyle(pack).gridTemplateColumns.split(' ').filter(Boolean).length : 1;
      Array.prototype.forEach.call(pack.children, function (el) {
        if (!grid) { el.style.gridRowEnd = ''; el.style.gridColumn = ''; return; }
        if (el.classList.contains('pd-wide')) el.style.gridColumn = 'span ' + Math.min(2, cols);
        /* SIZED TO ITS CONTENT (2026-11-05, Anthony: the meal plan "with all of the leading white
           space ... could be fitted nicely along side other cards"). A .pd-fit card spans only the
           pack columns its items need (data-fit-n items of data-fit-min px), never the whole row. */
        if (el.classList.contains('pd-fit')) {
          var n = +el.getAttribute('data-fit-n') || 1, min = +el.getAttribute('data-fit-min') || 160;
          var colW = (pack.getBoundingClientRect().width - GAP * (cols - 1)) / cols;
          var need = n * min + (n - 1) * 10 + 36;   // items + their gaps + the card's padding
          el.style.gridColumn = 'span ' + Math.max(1, Math.min(cols, Math.ceil((need + GAP) / (colW + GAP))));
        }
        var inner = el.firstElementChild || el;
        var h = inner.getBoundingClientRect().height;
        el.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((h + GAP) / ROW));
      });
    }
    function soon() { if (!raf) raf = requestAnimationFrame(run); }
    run();
    if (window.ResizeObserver) {
      if (pack.__ro) pack.__ro.disconnect();
      var ro = new ResizeObserver(soon);
      ro.observe(pack);
      Array.prototype.forEach.call(pack.children, function (el) { ro.observe(el.firstElementChild || el); });
      pack.__ro = ro;
    } else {
      window.addEventListener('resize', soon);
    }
  }

  function pdCss() {
    if (document.getElementById('pd-css')) return;
    var st = document.createElement('style');
    st.id = 'pd-css';
    st.textContent = ''
      // Side-by-side sections stack on a phone: at 392px the second column was ~120px wide
      // and "Check-in method" / "Provider & educators" printed one letter per line.
      + '@media (max-width:760px){.pd-split{grid-template-columns:minmax(0,1fr) !important;}'
      + '.pd-tiles{grid-template-columns:1fr 1fr !important;}'
      + '.pd-tiles > :last-child:nth-child(odd){grid-column:1 / -1;}}'
      + '.pd-split > *{min-width:0;}'
      // Each card keeps its own height: "Provider & educators" was stretched to the
      // Children table's height and sat as a tall empty card.
      + '.pd-split{align-items:start;}'
      // Wide screens: the nine figures as one even strip (they wrapped 6 + 3, ragged).
      + '@media (min-width:1100px){.pd-tiles{grid-template-columns:repeat(9,minmax(0,1fr)) !important;}'
      +   '.pd-tile{flex-direction:column;align-items:center !important;text-align:center;gap:7px !important;padding:13px 8px !important;}'
      +   '.pd-tile > div:last-child{text-align:center;}}'
      // The day's feed: soft rounded rows, lightly striped in the card's tone.
      + '#pd-feed .pd-feed-row{padding:9px 10px !important;border-bottom:0 !important;border-radius:10px;margin-bottom:3px;transition:background .12s;}'
      + '#pd-feed .pd-feed-row:nth-child(odd){background:#E11D4808;}'
      + '#pd-feed .pd-feed-row:hover{background:#E11D4812;}'
      /* THE WIDTH IS USED (2026-11-04). The side column stacks its small cards; the three-up row fits as many as the
         width allows (3 on a desktop, 2 on a tablet, 1 on a phone); the day's feed reads in two columns, top to
         bottom then the next column, on a wide screen - it was one 100-row strip with short lines across 1100px. */
      + '.pd-side{display:flex;flex-direction:column;gap:16px;min-width:0;}'
      /* AUTO-ARRANGED (2026-11-04, "auto arrange ... to ensure white space is utilized properly"). */
      + '.pd-pack{display:flex;flex-direction:column;gap:16px;margin-bottom:16px;}'
      + '.pd-pack > *{min-width:0;}'
      + '@media (min-width:761px){.pd-pack{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));'
      +   'grid-auto-rows:4px;grid-auto-flow:row dense;column-gap:16px;row-gap:0;}'
      +   '.pd-pack > *{align-self:start;}'
      +   '.pd-pack > .pd-full{grid-column:1 / -1;}}'
      + '.pd-three{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:16px;align-items:start;}'
      + '.pd-three > *{min-width:0;}'
      + '@media (min-width:1100px){.pd-full #pd-feed{column-count:2;column-gap:22px;}'
      +   '#pd-feed .pd-feed-row{break-inside:avoid;-webkit-column-break-inside:avoid;}}';
    (document.head || document.documentElement).appendChild(st);
  }
  // A donut/pie of the day's logged-moment breakdown.
  var PIE_COLS = ['#0EA5E9', '#7C3AED', '#F59E0B', '#DB2777', '#94A3B8', '#16A34A'];
  function pie(data) {
    var keys = Object.keys(data).filter(function (k) { return data[k] > 0; });
    var total = keys.reduce(function (a, k) { return a + data[k]; }, 0);
    if (!total) return '<div style="color:#94A3B8;font-size:12px;padding:20px 0;text-align:center;">No moments logged.</div>';
    var C = 2 * Math.PI * 42, off = 0, segs = '';
    keys.forEach(function (k, i) {
      var frac = data[k] / total, len = C * frac;
      segs += '<circle cx="60" cy="60" r="42" fill="none" stroke="' + PIE_COLS[i % PIE_COLS.length] + '" stroke-width="16" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90 60 60)"></circle>';
      off += len;
    });
    var legend = keys.map(function (k, i) {
      // Count sits right after the label (small gap), NOT pushed to the far right —
      // the old flex:1 on the label stretched it across the whole column.
      return '<div style="display:flex;align-items:center;gap:7px;margin-bottom:5px;font-size:12.5px;"><span style="width:11px;height:11px;border-radius:3px;background:' + PIE_COLS[i % PIE_COLS.length] + ';display:inline-block;flex-shrink:0;"></span><span style="color:#334155;text-transform:capitalize;">' + k + '</span><b style="color:#0F172A;">' + data[k] + '</b></div>';
    }).join('');
    return '<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;"><svg width="120" height="120" viewBox="0 0 120 120" style="flex-shrink:0;">' + segs
      + '<text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="#0F172A">' + total + '</text>'
      + '<text x="60" y="73" text-anchor="middle" font-size="9" fill="#64748B">moments</text></svg>'
      + '<div style="min-width:150px;">' + legend + '</div></div>';
  }
  /* POINTS IN THE PROVIDER PERFORMANCE CARD (2026-10-28, Anthony: "daily overview and where it shows provider
     performance add their scoring/points graph as well here"). The month's educator points for this provider's
     centre - /educator-points?centre_id&month, the same numbers and the same director/admin access as Educator
     rewards - drawn day by day with the overview's date marked, then who earned them and from what. The days
     come from the same counted (daily-capped) activity as the month's totals, so they add up exactly. */
  function ptsMonthName(month) {
    try { return new Date(+month.slice(0, 4), +month.slice(5, 7) - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }); }
    catch (e) { return month; }
  }
  // "on Nov 2", not "on 02" (2026-11-02 phone sweep). The date is the overview's own Y-m-d (agency day), read at noon.
  function ptsDayName(date) {
    try { return new Date(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10), 12).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
    catch (e) { return String(date || '').slice(8); }
  }
  function pointsHtml(r, date) {
    var all = r.rows || [];
    var month = r.month || String(date || '').slice(0, 7);
    if (r.settings && r.settings.enabled === false) {
      return '<div style="color:#94A3B8;font-size:12.5px;">Educator points are turned off for this agency.</div>';
    }
    var nDays = new Date(+month.slice(0, 4), +month.slice(5, 7), 0).getDate();
    var days = [];
    for (var i = 1; i <= nDays; i++) {
      var key = month + '-' + (i < 10 ? '0' : '') + i, v = 0;
      all.forEach(function (x) { v += Number((x.days || {})[key] || 0); });
      days.push({ n: i, key: key, v: v });
    }
    var max = Math.max.apply(null, days.map(function (x) { return x.v; }).concat([1]));
    var sum = days.reduce(function (a, x) { return a + x.v; }, 0);
    var today = days.filter(function (x) { return x.key === date; })[0];
    var head = '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:8px;">'
      + '<div style="font-size:13px;font-weight:800;color:#0F172A;">⭐ Points · ' + esc(ptsMonthName(month)) + '</div>'
      + '<div style="font-size:12px;color:#64748B;"><b style="color:#6D28D9;font-size:14px;">' + sum.toLocaleString() + '</b> pts this month'
      + (today ? ' · <b style="color:#B45309;">' + today.v + '</b> on ' + esc(ptsDayName(date)) : '') + '</div></div>';
    var bars = '<div style="display:flex;align-items:flex-end;gap:2px;height:96px;padding:0 2px;border-bottom:1px solid #E2E8F0;">'
      + days.map(function (x) {
        var sel = x.key === date, h = x.v ? Math.max(4, Math.round(x.v / max * 100)) : 0;
        return '<div title="' + esc(x.key) + ': ' + x.v + ' pts" style="flex:1 1 0;min-width:0;height:100%;display:flex;align-items:flex-end;justify-content:center;">'
          + '<div style="width:78%;height:' + h + '%;border-radius:3px 3px 0 0;background:' + (sel ? '#F59E0B' : 'linear-gradient(180deg,#A78BFA,#7C3AED)') + ';"></div></div>';
      }).join('') + '</div>'
      + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:#94A3B8;margin:3px 2px 12px;">'
      + [1, 8, 15, 22, nDays].map(function (n) { return '<span>' + n + '</span>'; }).join('') + '</div>';
    var act = {};
    (r.activities || []).forEach(function (a) { act[a.key] = a; });
    var people = all.slice().sort(function (a, b) { return (b.total || 0) - (a.total || 0); });
    var top = Math.max.apply(null, people.map(function (x) { return x.total || 0; }).concat([1]));
    var list = people.length ? people.map(function (x) {
      var from = Object.keys(x.by || {}).map(function (k) { return [k, (x.by[k] || {}).points || 0]; })
        .filter(function (e) { return e[1] > 0; }).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 3)
        .map(function (e) { var a = act[e[0]] || {}; return (a.icon || '•') + ' ' + esc(a.label || e[0]) + ' ' + e[1]; }).join(' · ');
      return '<div style="padding:6px 0;border-top:1px solid #F1F5F9;">'
        + '<div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;"><b style="color:#0F172A;">' + esc(x.name) + '</b>'
        + '<span style="font-weight:800;color:#6D28D9;white-space:nowrap;">' + (x.total || 0).toLocaleString() + ' pts</span></div>'
        + '<div style="height:6px;background:#F1F5F9;border-radius:999px;margin:4px 0 3px;overflow:hidden;"><div style="height:100%;width:' + Math.round((x.total || 0) / top * 100) + '%;background:linear-gradient(90deg,#A78BFA,#7C3AED);border-radius:999px;"></div></div>'
        + (from ? '<div style="font-size:11px;color:#64748B;">' + from + '</div>' : '<div style="font-size:11px;color:#94A3B8;">No points yet this month</div>')
        + '</div>';
    }).join('') : '<div style="color:#94A3B8;font-size:12px;">No one at this provider earns points.</div>';
    return head + bars + list;
  }
  function loadPoints(body, date, gen) {
    var host = body.querySelector('#pd-points');
    if (!host) { return; }
    var month = String(date || '').slice(0, 7);
    Api.get('/educator-points?centre_id=' + encodeURIComponent(state.centreId) + '&month=' + encodeURIComponent(month))
      .then(function (r) {
        if (gen !== state.gen || !host.isConnected) { return; }
        host.innerHTML = pointsHtml(r || {}, date);
      })
      .catch(function () {
        if (gen !== state.gen || !host.isConnected) { return; }
        host.innerHTML = '<div style="color:#94A3B8;font-size:12.5px;">Points could not be loaded.</div>';
      });
  }

  function metric(label, value, sub, accent) {
    return '<div style="background:linear-gradient(180deg,' + accent + '12,#fff);border:1px solid ' + accent + '2E;border-top:3px solid ' + accent + ';border-radius:12px;padding:13px 14px;text-align:center;">'
      + '<div style="font-size:24px;font-weight:800;color:' + accent + ';">' + value + '</div>'
      + '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748B;margin-top:2px;">' + label + '</div>'
      + (sub ? '<div style="font-size:11px;color:#94A3B8;margin-top:2px;">' + sub + '</div>' : '') + '</div>';
  }

  async function render(container) {
    pdCss();
    container.innerHTML = '<div style="padding:24px;max-width:1100px;margin:0 auto;color:#0F172A;">'
      + '<h2 style="margin:0 0 4px;font-size:21px;font-weight:800;">🗓️ Daily Overview</h2>'
      + '<div style="color:#64748B;font-size:13px;margin-bottom:16px;line-height:1.5;">A quick glance at a provider on any day — attendance, clock in/out, the week’s meals, activities and every daily log.</div>'
      + '<div id="pd-controls" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;"></div>'
      + '<div id="pd-body"></div></div>';

    if (!state.centres.length) {
      var got = await loadCentres();
      state.noAgency = (got === null);
      state.centres = got || [];
    }
    // Pre-select the provider when arriving via a "Review day" shortcut (one-shot).
    if (!state.centreId) { try { var pre = sessionStorage.getItem('kt_pd_centre'); if (pre) { sessionStorage.removeItem('kt_pd_centre'); if (state.centres.some(function (c) { return String(c.id) === String(pre); })) state.centreId = parseInt(pre, 10); } } catch (e) {} }
    if (!state.centreId && state.centres.length === 1) state.centreId = state.centres[0].id;
    /* Select a provider first (2026-10-24): with several, nothing is shown until one is picked
       (the picker below carries a "Select a provider…" option while none is chosen). */

    /* After the await above — see liveEl(). Without this the controls are built on a
       detached node and the provider picker does nothing at all. */
    var ctrl = liveEl(container, 'pd-controls');
    if (!ctrl) { return; }
    /* THE ARROWS MATCH THE FIELDS, NOT THE OTHER WAY ROUND.

       The row was ragged because four controls had four heights: 30, 30, 35, 38. The
       obvious fix — give them all height:38px — does not work and should not: kt-polish-v22
       forces `height:auto !important` and compact padding on every input, textarea and
       select in the product (v22p98), deliberately, because oversized fields have been
       flagged here more than once. An inline height loses to it, silently, which is why
       the first attempt measured 29.59px against an inline 38px.

       So the fields keep the platform's compact sizing and everything else is sized to
       THEM: the arrows stretch to whatever height the date input resolves to, and Today
       gets the same padding and font the compact rule applies. Nothing is pinned to a
       number that another stylesheet is entitled to change. (Anthony, 2026-09-10) */
    ctrl.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:5px;"><span style="font-size:11.5px;font-weight:700;color:#475569;">Provider</span>'
      + '<select id="pd-centre" style="border:1px solid #DCE3EC;border-radius:10px;min-width:220px;background:#fff;">'
      + (state.centreId ? '' : '<option value="" selected disabled>Select a provider…</option>')
      + state.centres.map(function (c) { return '<option value="' + c.id + '"' + (c.id == state.centreId ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      /* ‹ › EITHER SIDE OF THE DATE.

         Reviewing a provider means walking back through the week, and picking each day
         out of a native date picker is four taps for what should be one. The educator's
         own Daily log has had these arrows for months; this screen — the one an admin
         opens every morning — did not. Same shape, same order, so the two read alike.

         › is disabled on today rather than hidden: a control that vanishes makes the row
         jump, and the input is already capped at today so a forward step has nowhere to
         go. (Anthony, 2026-09-10) */
      /* THE DAY NAME GOES ON THE LABEL LINE, NOT UNDER THE FIELD.

         Putting it below the input gave this column THREE rows where Provider has two,
         and the controls row is aligned `flex-end` — so the extra line pushed the date
         field up out of line with the provider dropdown and the Today button. Sitting it
         beside the label keeps every column two rows tall and the controls on one line,
         and it reads better there anyway: "Date · Yesterday" is a caption, not a field.
         (Anthony, 2026-09-10) */
      + '<div style="display:flex;flex-direction:column;gap:5px;">'
      + '<span style="font-size:11.5px;font-weight:700;color:#475569;white-space:nowrap;">Date'
      +   '<span id="pd-dayname" style="font-weight:600;color:#94A3B8;"></span></span>'
      /* stretch, not center: the arrows take whatever height the date field resolves to,
         so they cannot drift apart from it when that rule changes. */
      + '<div style="display:flex;align-items:stretch;gap:6px;">'
      +   '<button id="pd-prev" type="button" title="Previous day" aria-label="Previous day" data-kt-iconized="1" style="flex:0 0 auto;width:34px;padding:0;border:1px solid #DCE3EC;background:#fff;border-radius:10px;font-size:15px;line-height:1;cursor:pointer;color:#334155;">‹</button>'
      +   '<input id="pd-date" type="date" value="' + state.date + '" max="' + todayISO() + '" style="flex:1;min-width:0;border:1px solid #DCE3EC;border-radius:10px;">'
      +   '<button id="pd-next" type="button" title="Next day" aria-label="Next day" data-kt-iconized="1" style="flex:0 0 auto;width:34px;padding:0;border:1px solid #DCE3EC;background:#fff;border-radius:10px;font-size:15px;line-height:1;cursor:pointer;color:#334155;">›</button>'
      + '</div></div>'
      /* min-height rather than padding: the fields resolve to 30px from their own
         line-height, and matching that with padding alone lands 2px short. */
      + '<button id="pd-today" style="min-height:30px;padding:5px 14px;border:1.5px solid #CBD5E1;background:#fff;border-radius:8px;font-size:13px;line-height:1.2;font-weight:700;cursor:pointer;white-space:nowrap;">Today</button>';
    ctrl.querySelector('#pd-centre').addEventListener('change', function (e) {
      state.centreId = parseInt(e.target.value, 10);
      load(container);
    });
    /* ONE PLACE that moves the day, so the input, the caption, the disabled state and the
       reload cannot get out of step — which is exactly what happens when each control
       does its own thing. */
    function goToDate(iso) {
      if (!iso) { iso = todayISO(); }
      if (iso > todayISO()) { iso = todayISO(); }   // ISO dates compare as strings
      state.date = iso;
      var live = liveEl(container, 'pd-controls');
      if (live) {
        var inp = live.querySelector('#pd-date');
        if (inp) { inp.value = iso; }
        var nm = live.querySelector('#pd-dayname');
        // Rendered as part of the label, so it carries its own separator.
        if (nm) { nm.textContent = '  ·  ' + prettyDay(iso); }
        var nx = live.querySelector('#pd-next');
        if (nx) {
          var atToday = iso >= todayISO();
          nx.disabled = atToday;
          nx.style.opacity = atToday ? '.4' : '1';
          nx.style.cursor = atToday ? 'default' : 'pointer';
        }
      }
      load(container);
    }

    ctrl.querySelector('#pd-date').addEventListener('change', function (e) { goToDate(e.target.value); });
    ctrl.querySelector('#pd-prev').addEventListener('click', function () { goToDate(shiftDate(state.date, -1)); });
    ctrl.querySelector('#pd-next').addEventListener('click', function () { goToDate(shiftDate(state.date, 1)); });
    ctrl.querySelector('#pd-today').addEventListener('click', function () { goToDate(todayISO()); });

    /* Paints the caption and the disabled arrow for the day we opened on, and does the
       first load — goToDate is the only thing that ever sets them. */
    goToDate(state.date);
  }

  /* A PLATFORM ADMIN HAS NO AGENCY OF THEIR OWN.
     /admin/centres answers "No agency access" until one is selected, and the agency
     switcher seeds kt_active_agency_id asynchronously at boot (it has to fetch
     /auth/agencies first). This screen used to lose that race in silence: the catch set
     an empty list and the page rendered "No providers found." — which reads as "this
     provider has nothing today" rather than "no agency is selected yet" — and nothing
     re-rendered once the agency did arrive, so it stayed broken until you changed the
     date or navigated away and back.

     On a desktop the switcher usually wins the race. On the phone it does not, which is
     why Daily Overview worked for every role except the super admin, and only there.

     Returns: an array of centres, [] for genuinely none, or null for "no agency yet".
     (Anthony, 2026-09-08) */
  function activeAgency() {
    try { return sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) { return ''; }
  }

  /* Timed against the CLOCK, not by adding up the interval. A browser throttles timers
     in a backgrounded or inactive tab — which is the normal state of a phone — so a
     150ms interval fires roughly once a second, and counting ticks turned a 4-second
     cap into 27 real seconds of "Loading…". Date.now() cannot be throttled. */
  function waitForAgency(ms) {
    return new Promise(function (resolve) {
      var until = Date.now() + ms;
      var t = setInterval(function () {
        if (activeAgency() || Date.now() >= until) { clearInterval(t); resolve(!!activeAgency()); }
      }, 150);
    });
  }

  async function loadCentres() {
    for (var attempt = 0; attempt < 2; attempt++) {
      try {
        var r = await Api.get('/admin/centres');
        return (r && r.centres || []).map(function (c) { return { id: c.id, name: c.name }; });
      } catch (e) {
        // Only worth retrying for the one cause that fixes itself: the switcher is still
        // booting and has not chosen an agency yet.
        if (attempt === 0 && !activeAgency()) {
          var arrived = await waitForAgency(4000);
          if (arrived) { continue; }
        }
        return activeAgency() ? [] : null;
      }
    }
    return null;
  }

  /* OPEN MEDIA IN THE APP, NOT IN A NEW TAB.
     These tiles were plain <a target="_blank">. A browser opens a tab; the APK's WebView
     opens nothing at all, so tapping an educator's photo on the phone did visibly
     nothing — while the parent app, which has its own in-app lightbox, worked fine. That
     asymmetry is the whole bug: it was never about the image or the URL (the API returns
     absolute media URLs), only about how the tile tried to show it.

     Images reuse KT.avatarZoom, which is loaded on every page and already does exactly
     this. Video needs a <video> element, so it gets a small overlay of its own.
     (Anthony, 2026-09-08) */
  function openMedia(url, caption, isVideo) {
    if (!url) { return; }
    if (!isVideo && window.KT && KT.avatarZoom && typeof KT.avatarZoom.open === 'function') {
      try { KT.avatarZoom.open(url, caption || 'Photo'); return; } catch (e) { /* fall through */ }
    }
    var ov = document.createElement('div');
    ov.className = 'kt-av-zoom';   // the shared class every "is a dialog open?" check knows
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483200;background:rgba(8,20,35,.88);'
      + 'display:flex;align-items:center;justify-content:center;padding:24px;cursor:zoom-out;';
    var el;
    if (isVideo) {
      el = document.createElement('video');
      el.src = url; el.controls = true; el.autoplay = true; el.playsInline = true;
      el.setAttribute('playsinline', '');   // iOS refuses to play inline without it
    } else {
      el = document.createElement('img');
      el.src = url; el.alt = caption || 'Photo';
    }
    el.style.cssText = 'max-width:94vw;max-height:88vh;border-radius:12px;background:#0b1626;cursor:default;';
    el.addEventListener('click', function (e) { e.stopPropagation(); });
    ov.appendChild(el);
    ov.addEventListener('click', function () { close(); });
    function close() {
      try { if (ov.parentNode) { ov.parentNode.removeChild(ov); } } catch (e) {}
      try { if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } } catch (e) {}
    }
    document.body.appendChild(ov);
    // Registered so the Android back button and the app's ‹ back close it first.
    try { if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); } } catch (e) {}
  }

  async function load(container) {
    /* Whose load this is. The provider picker, the date field and the Today button all
       call load(), so a slow first request must not land after a fast second one and
       leave the previous provider's roster under the new provider's name. */
    var gen = ++state.gen;
    var body = liveEl(container, 'pd-body');
    if (!body) { return; }
    if (!state.centreId) {
      /* Say which of the two it is. "No providers found" for a super admin who simply
         has not picked an agency sent people looking for missing data that was never
         missing. */
      body.innerHTML = state.noAgency
        ? '<div style="color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:12px;'
          + 'padding:14px 16px;font-size:13.5px;font-weight:600;">Choose an agency first — use the agency '
          + 'switcher, then reopen Daily Overview.</div>'
        : (state.centres.length
          ? '<div style="background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:26px 16px;text-align:center;color:#64748B;font-size:13.5px;">Select a provider to see its day.</div>'
          : '<div style="color:#64748B;padding:20px;">No providers found.</div>');
      return;
    }
    body.innerHTML = '<div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div>';
    var d;
    try { d = await Api.get('/provider/day-activity?centre_id=' + state.centreId + '&date=' + state.date); }
    catch (e) {
      if (gen !== state.gen) { return; }
      body = liveEl(container, 'pd-body');
      if (body) { body.innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load: ' + esc(e.message) + '</div>'; }
      return;
    }
    /* Superseded, or the screen has gone. Either way this result is no longer wanted. */
    if (gen !== state.gen) { return; }
    body = liveEl(container, 'pd-body');
    if (!body) { return; }
    var s = d.summary || {};

    /* The forms this provider was asked to submit on THIS day. Fetched here rather than
       lazily inside the card so the whole overview paints in one pass — and non-fatal,
       because a missing forms card must never cost somebody the attendance roster. */
    var formsData = null;
    try {
      formsData = await Api.get('/admin/forms-today?provider_only=1&centre_id=' + state.centreId + '&date=' + state.date);
    } catch (e) { formsData = null; }

    /* Second await, same two guards — and this is the one that usually caught us, because
       it runs while "Loading…" is already on screen and a phone pays for another full
       round trip here. Everything below (the innerHTML at the end AND the .pd-fix /
       .pd-act / walk-map bindings) has to happen on the attached node or the screen
       paints nowhere and the buttons bind to a copy nobody can tap. */
    if (gen !== state.gen) { return; }
    body = liveEl(container, 'pd-body');
    if (!body) { return; }

    /* EVERYTHING BELOW IS GUARDED. Both requests have returned by here, so any failure
       from this point is ours — building the markup or binding the buttons. Without a
       catch that exception escapes an async function into a rejected promise nobody
       reads, and the "Loading…" written before the await simply stays put, which
       kt-polish then upgrades to a spinner. The screen ends up indistinguishable from
       one still waiting on the network. (Anthony, 2026-09-07: Bruni Meeser spun forever
       while every other provider painted.) */
    try {

    // Summary cards
    var cards = [
      ['👶', 'Attended', s.attended + ' / ' + s.enrolled, '#1F6080'],
      ['🟢', 'At Centre', s.still_in, '#16A34A'],
      ['👋', 'Not at Centre', s.went_home, '#B45309'],
      ['🍽️', 'Meals & snacks', s.meals, '#0EA5E9'],
      ['😴', 'Naps', s.naps, '#7C3AED'],
      ['✨', 'Activities', s.activities, '#DB2777'],
      ['⚠️', 'Incidents', s.incidents, s.incidents > 0 ? '#DC2626' : '#94A3B8'],
      ['📋', 'Moments', s.moments, '#0F172A'],
      ['🚶', 'Walked', (s.walk_km ? s.walk_km + ' km' : '—')
        + (s.walk_count ? ' · ' + s.walk_count : ''), '#0284C7'],
    ].map(function (c) {
      return '<div class="pd-tile" style="background:linear-gradient(135deg,' + c[3] + '1A,' + c[3] + '08);border:1px solid ' + c[3] + '2E;border-radius:14px;padding:11px 13px;display:flex;align-items:center;gap:11px;min-width:0;">'
        + '<div style="width:36px;height:36px;border-radius:11px;background:#fff;box-shadow:0 1px 4px ' + c[3] + '33;display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1;flex-shrink:0;">' + c[0] + '</div>'
        + '<div style="min-width:0;"><div style="font-size:20px;font-weight:800;color:' + c[3] + ';line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(String(c[2])) + '</div>'
        + '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#475569;margin-top:2px;">' + c[1] + '</div></div></div>';
    }).join('');

    // Roster table
    var TH = { in: ['#16A34A', 'At Centre'], out: ['#F59E0B', 'Not at Centre'], away: ['#CBD5E1', 'Absent'] };
    var roster = (d.roster || []).map(function (r) {
      var t = TH[r.status] || TH.away;
      // Not booked and not here: "Not scheduled", muted, instead of "Absent" (2026-11-01).
      var unbooked = r.scheduled === false && r.status === 'away';
      if (unbooked) { t = ['#94A3B8', 'Not scheduled']; }
      // Running late (2026-11-06): the family said they'll be in later; Safe Arrival waits until then.
      if (r.status === 'away' && r.running_late) { t = ['#4338CA', 'Late drop-off · by ' + r.running_late.by]; }
      var srcBadge = r.source ? '<span style="font-size:10.5px;font-weight:800;border-radius:6px;padding:2px 7px;background:' + (r.source === 'QR' ? '#E0F2FE;color:#0369A1' : '#FEF3C7;color:#92400E') + ';">' + (r.source === 'QR' ? 'One-Tap' : r.source) + '</span>' : '<span style="color:#CBD5E1;">—</span>';
      var care = r.care || {};
      var napTxt = (care.naps || []).map(function (n) { return esc(n.start || '?') + (n.end ? '→' + esc(n.end) : '…'); }).join(', ');
      var careBits = [];
      if (care.naps && care.naps.length) careBits.push('😴 ' + napTxt);
      if (care.meals && care.meals.length) careBits.push('🍽️ ' + care.meals.length);
      if (care.diapers) careBits.push('🧷 ' + care.diapers);
      var careHtml = careBits.length ? '<span style="font-size:11.5px;color:#475569;">' + careBits.join(' · ') + '</span>' : '<span style="color:#CBD5E1;">—</span>';
      return '<tr style="border-top:1px solid #F1F5F9;">'
        /* NOT DUE IN TODAY.

           A child's attendance days are set when the family is created and live on the
           enrolment; the educator's own roster has always respected them, this screen did
           not. Marked rather than hidden, deliberately: a child CAN be signed in on a day
           they were not booked (a swap, a parent working late), and that is a real
           attendance record — worth noticing, not worth concealing. Present only when the
           server actually said so, so an older response reads as "no opinion" rather
           than "not scheduled". */
        + '<td style="padding:8px 6px;"><div style="display:flex;align-items:center;gap:9px;">' + avatar(r.name, r.photo_url, r.gender, 30, true)
        +   '<span style="font-weight:700;font-size:13px;' + (r.scheduled === false ? 'color:#64748B;' : '') + '">' + esc(r.name) + '</span>'
        +   ((r.scheduled === false && !unbooked)
              ? '<span title="This child is not scheduled to attend on this day" style="font-size:10px;font-weight:800;color:#7C3AED;background:#F5F3FF;border:1px solid #DDD6FE;border-radius:999px;padding:1px 7px;white-space:nowrap;">Not booked</span>'
              : '')
        + '</div></td>'
        /* SIGNED IN AND OUT MORE THAN ONCE (2026-11-05): one line per visit in both
           columns, in order, so the in and out of each visit sit side by side. */
        + (r.visit_list && r.visit_list.length > 1
          ? '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;line-height:1.7;">' + r.visit_list.map(function (v) { return v.in ? esc(v.in) : '<span style="color:#CBD5E1;">—</span>'; }).join('<br>') + '</td>'
            + '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;line-height:1.7;">' + r.visit_list.map(function (v) { return v.out ? esc(v.out) + outHowBadge(v.out_how, null, 'parent') : '<span style="color:#16A34A;font-weight:700;">here now</span>'; }).join('<br>') + '</td>'
          : '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;">' + (r.in ? esc(r.in) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
            + '<td style="padding:8px 6px;font-size:12.5px;font-variant-numeric:tabular-nums;">' + (r.out ? esc(r.out) + outHowBadge(r.out_how, r.out_auto, 'parent') : '<span style="color:#CBD5E1;">—</span>') + '</td>')
        + '<td style="padding:8px 6px;">' + careHtml + '</td>'
        + '<td style="padding:8px 6px;">' + srcBadge + '</td>'
        + '<td style="padding:8px 6px;"><span style="font-size:11px;font-weight:800;color:' + t[0] + ';">● ' + t[1] + '</span></td>'
        /* Manual sign in / out stays TODAY-only. Back-dating from a date picker would
           quietly rewrite a licensing record, which is why this button was limited in
           the first place (Anthony, 2026-08-26) — and that reasoning still holds for a
           control that silently stamps "now".

           An earlier day gets the correction tool instead, which is the opposite of
           quiet: it asks for the real times and a reason, flags the entry as entered
           late, records who typed it, and audits both timestamps. Directors and admins
           only; the server checks that again. */
        + '<td style="padding:8px 6px;text-align:right;">' + (
            (state.date === todayISO() && r.room_id)
              ? '<button type="button" class="pd-act" data-child="' + r.id + '" data-room="' + r.room_id + '"'
                + ' data-going="' + (r.status === 'in' ? 'out' : 'in') + '"'
                + ' style="padding:5px 10px;font-size:11.5px;font-weight:700;font-family:inherit;cursor:pointer;border-radius:7px;border:1px solid '
                + (r.status === 'in' ? '#E2E8F0;background:#fff;color:#475569;' : '#BFDBFE;background:#EFF6FF;color:#1E40AF;')
                + '">' + (r.status === 'in' ? 'Sign out' : 'Sign in') + '</button>'
                /* Running late (2026-11-06): only for a child not here yet today. */
                + (r.status === 'away' ? '<button type="button" class="pd-late" data-child="' + r.id + '"'
                    + ' data-kttip="' + (r.running_late ? 'Expected by ' + esc(r.running_late.by) + ' — change or take back' : 'The family said they will be in later today') + '"'
                    + ' style="margin-left:4px;padding:5px 10px;font-size:11.5px;font-weight:700;font-family:inherit;cursor:pointer;border-radius:7px;border:1px solid #C7D2FE;background:#EEF2FF;color:#3730A3;">'
                    + '⏰ ' + (r.running_late ? esc(r.running_late.by) : 'Late drop-off') + '</button>' : '')
                // Here now: a late pickup.
                + (r.status === 'in' ? '<button type="button" class="pd-late" data-kind="departure" data-child="' + r.id + '"'
                    + ' data-kttip="' + (r.late_pickup ? 'Picked up by ' + esc(r.late_pickup.by) + ' — change or take back' : 'The family said they will pick up later today') + '"'
                    + ' style="margin-left:4px;padding:5px 10px;font-size:11.5px;font-weight:700;font-family:inherit;cursor:pointer;border-radius:7px;border:1px solid #C7D2FE;background:#EEF2FF;color:#3730A3;">'
                    + '⏰ ' + (r.late_pickup ? esc(r.late_pickup.by) : 'Pickup') + '</button>' : '')
                /* AND a correction for TODAY too (2026-10-13, Anthony: "how to manually edit
                   sign in and out for children"). Sign in/out above stamps NOW; a sign-out an
                   educator missed at 4:15 needs the real time, not the time somebody noticed. */
                + (pdMayCorrect() ? '<button type="button" class="pd-fix" data-child="' + r.id + '"'
                    + ' data-name="' + esc(r.name || 'this child') + '"'
                    + ' title="Correct a sign in or out time (entered late, with a reason)"'
                    + ' style="margin-left:4px;padding:5px 10px;font-size:11.5px;font-weight:700;font-family:inherit;'
                    + 'cursor:pointer;border-radius:7px;border:1px dashed #CBD5E1;background:#fff;color:#64748B;">'
                    + '⏱ Fix</button>' : '')
              : (pdMayCorrect()
                  ? '<button type="button" class="pd-fix" data-child="' + r.id + '"'
                    + ' data-name="' + esc(r.name || 'this child') + '"'
                    + ' title="Record a sign in or out that was missed"'
                    + ' style="padding:5px 10px;font-size:11.5px;font-weight:700;font-family:inherit;'
                    + 'cursor:pointer;border-radius:7px;border:1px dashed #CBD5E1;background:#fff;color:#64748B;">'
                    + '⏱ Fix</button>'
                  : '<span style="color:#CBD5E1;">—</span>')
          ) + '</td></tr>';
    }).join('');
    var rosterTable = (d.roster && d.roster.length)
      ? '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#94A3B8;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;"><th style="padding:0 6px 6px;">Child</th><th style="padding:0 6px 6px;">In</th><th style="padding:0 6px 6px;">Out</th><th style="padding:0 6px 6px;">Care today</th><th style="padding:0 6px 6px;">Via</th><th style="padding:0 6px 6px;">Status</th><th style="padding:0 6px 6px;text-align:right;">Action</th></tr></thead>'
        + '<tbody>' + roster + '</tbody></table></div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:12px 0;">No children enrolled.</div>';

    // Staff clock. Everyone assigned to the home is listed (2026-10-21), so a second
    // educator who has not clocked in shows as "Not clocked in" rather than not at all.
    function notInPill(st) {
      var pill = 'font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px;white-space:nowrap;';
      if (!st.scheduled) { return '<span style="' + pill + 'color:#64748B;background:#F1F5F9;">Not scheduled</span>'; }
      if (state.date < todayISO()) { return '<span style="' + pill + 'color:#B91C1C;background:#FEE2E2;">Did not clock in</span>'; }
      return '<span style="' + pill + 'color:#B45309;background:#FEF3C7;">Not clocked in</span>';
    }
    var staff = (d.staff || []).map(function (st) {
      return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #F1F5F9;' + (st.not_in ? 'opacity:.8;' : '') + '">'
        + avatar(st.name, st.photo_url, st.sex, 30, false)
        + '<div style="flex:1;min-width:0;font-weight:700;font-size:13px;">' + presenceDot(st.presence) + esc(st.name)
        +   (!st.is_provider && st.not_in ? ' <span style="font-size:11px;color:#94A3B8;font-weight:500;">· educator</span>' : '')
        +   (st.source ? ' <span style="font-size:10px;color:#94A3B8;font-weight:600;">(' + esc(st.source) + ')</span>' : '') + '</div>'
        + (st.not_in ? notInPill(st)
          : '<div style="font-size:12.5px;color:#334155;font-variant-numeric:tabular-nums;">' + esc(st.in || '—') + ' → ' + (st.out ? esc(st.out) + outHowBadge(st.out_how, st.out_auto, 'educator') + (st.on_break ? ' <span style="color:#6D28D9;font-weight:700;">· ' + (st.on_break === 'lunch' ? 'on lunch now' : 'on a break now') + '</span>' : '') : '<span style="color:#16A34A;">on shift</span>') + '</div>')
        + '</div>';
    }).join('');
    var staffCard = (d.staff && d.staff.length) ? staff : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No staff clock-ins recorded.</div>';

    /* LAST HEADCOUNT, PER EDUCATOR (2026-10-20). When, how many children of how many, and
       whether everyone was accounted for; on-shift educators with none are flagged. */
    var isToday = state.date === todayISO();
    function ago(iso) {
      if (!isToday || !iso) { return ''; }
      var m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
      if (!(m >= 0)) { return ''; }
      if (m < 1) { return 'just now'; }
      if (m < 60) { return m + ' min ago'; }
      var h = Math.floor(m / 60), r = m % 60;
      return h + ' h' + (r ? ' ' + r + ' min' : '') + ' ago';
    }
    /* STRIKES (2026-11-03): missed headcounts, per educator (HeadcountStrikesCommand). */
    var strikeBy = {};
    (d.headcount_strikes || []).forEach(function (s) { strikeBy[s.user_id] = s; });
    function strikeChip(uid) {
      var s = strikeBy[uid];
      if (!s) { return ''; }
      var when = (s.items || []).map(function (i) { return i.time + (i.room ? ' · ' + i.room : ''); }).join(', ');
      return '<span style="font-size:12px;font-weight:800;color:#fff;background:#DC2626;border-radius:999px;padding:3px 9px;white-space:nowrap;" title="Missed: '
        + esc(when) + '">✖ ' + s.count + ' missed · ' + (s.count === 1 ? 'strike' : 'strikes') + '</span>';
    }
    var hcList = (d.headcounts || []).slice();
    (d.headcount_strikes || []).forEach(function (s) {
      if (!hcList.some(function (h) { return h.user_id === s.user_id; })) { hcList.push({ user_id: s.user_id, name: s.name, photo_url: s.photo_url, sex: s.sex, none: true, strikeOnly: true }); }
    });
    var hcRows = hcList.map(function (h) {
      var left = '<div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 160px;">'
        + avatar(h.name, h.photo_url, h.sex, 30, false)
        + '<div style="min-width:0;"><div style="font-weight:700;font-size:13px;">' + esc(h.name) + '</div>';
      if (h.none) {
        return '<div style="display:flex;align-items:center;gap:8px 10px;flex-wrap:wrap;padding:8px 0;border-top:1px solid #F1F5F9;">'
          + left + '<div style="font-size:12px;color:#94A3B8;">' + (h.strikeOnly ? 'Missed headcounts' : 'On shift') + '</div></div></div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin-left:auto;">' + strikeChip(h.user_id)
          + '<span style="font-size:12px;font-weight:700;color:#B45309;background:#FFFBEB;border:1px solid #FDE68A;border-radius:999px;padding:3px 9px;white-space:nowrap;">'
          + (isToday ? 'No headcount yet today' : 'No headcount that day') + '</span></div></div>';
      }
      var a = ago(h.at);
      // A home provider's room is usually named after them: do not say the name twice.
      var sub = [h.context, (h.room && h.room !== h.name) ? h.room : null, h.count_today + (h.count_today === 1 ? ' headcount' : ' headcounts') + (isToday ? ' today' : '')]
        .filter(Boolean).map(esc).join(' · ');
      var status = h.all_accounted
        ? '<span style="font-size:12px;font-weight:700;color:#166534;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:999px;padding:3px 9px;white-space:nowrap;">✓ All accounted for</span>'
        : '<span style="font-size:12px;font-weight:700;color:#B91C1C;background:#FEF2F2;border:1px solid #FECACA;border-radius:999px;padding:3px 9px;white-space:nowrap;" title="'
          + esc((h.missing || []).join(', ')) + '">⚠ ' + (h.missing_count || 0) + ' not accounted for' + (h.resolved ? ' · resolved' : '') + '</span>';
      return '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 0;border-top:1px solid #F1F5F9;">'
        + left + '<div style="font-size:12px;color:#64748B;">' + sub + '</div></div></div>'
        + '<div style="text-align:right;margin-left:auto;">'
        +   '<div style="font-size:13px;font-weight:800;color:#0F172A;font-variant-numeric:tabular-nums;">' + esc(h.time || '—')
        +     ' · ' + (h.counted || 0) + ' of ' + (h.expected || 0) + ' children</div>'
        +   '<div style="margin-top:3px;display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap;">'
        +     (a ? '<span style="font-size:11.5px;color:#94A3B8;">' + esc(a) + '</span>' : '') + strikeChip(h.user_id) + status
        +     (!h.all_accounted && h.missing && h.missing.length ? '<span style="font-size:11.5px;color:#B91C1C;">' + esc(h.missing.join(', ')) + '</span>' : '')
        +   '</div>'
        + '</div></div>';
    }).join('');
    var hcCard = hcRows || '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No headcounts recorded ' + (isToday ? 'yet today.' : 'that day.') + '</div>';

    // Weekly menu
    var DAYMAP = { '1': 'Mon', '2': 'Tue', '3': 'Wed', '4': 'Thu', '5': 'Fri', '6': 'Sat', '7': 'Sun', monday: 'Mon', tuesday: 'Tue', wednesday: 'Wed', thursday: 'Thu', friday: 'Fri', saturday: 'Sat', sunday: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri' };
    var ORDER = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    var menu = d.menu || {};
    var byDay = {};
    Object.keys(menu).forEach(function (k) { var lbl = DAYMAP[String(k).toLowerCase()] || DAYMAP[k] || k; (byDay[lbl] = byDay[lbl] || []).push.apply(byDay[lbl], menu[k]); });
    var menuHasData = Object.keys(byDay).length > 0;
    // Highlight the day being viewed so the right day's meals jump out of the week.
    var _vd = new Date((String(d.date || '').slice(0, 10) || todayISO()) + 'T12:00:00');
    var todayLbl = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][_vd.getDay()];
    var isTodayView = String(d.date || '').slice(0, 10) === todayISO();
    var menuGrid = menuHasData
      /* auto-FIT, not auto-fill: auto-fill kept empty day columns at the end of a wide card. */
      ? '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">'
        + ORDER.filter(function (day) { return byDay[day]; }).map(function (day) {
          var hi = (day === todayLbl);
          return '<div style="background:' + (hi ? 'rgba(14,124,144,.12)' : '#F8FAFC') + ';border:' + (hi ? '2px solid #0E7C90' : '1px solid #EEF2F6') + ';border-radius:10px;padding:10px;' + (hi ? 'box-shadow:0 5px 14px -7px rgba(14,124,144,.55);' : '') + '">'
            + '<div style="font-weight:800;font-size:11px;color:' + (hi ? '#0B5563' : '#1F6080') + ';text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">' + day
            + (hi ? ' <span style="background:#0E7C90;color:#fff;border-radius:5px;padding:1px 6px;font-size:9px;letter-spacing:.3px;vertical-align:middle;">' + (isTodayView ? 'TODAY' : 'THIS DAY') + '</span>' : '') + '</div>'
            /* Food photos (2026-11-02): the dish's photo beside its name, as on the Weekly menu. */
            + byDay[day].map(function (m) {
              var ph = m.image_url
                ? '<span style="flex:0 0 40px;width:40px;height:30px;border-radius:6px;overflow:hidden;background:#E2E8F0;display:block;"><img src="' + esc(m.image_url) + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;"></span>'
                : '';
              return '<div style="font-size:12px;margin-bottom:6px;display:flex;align-items:center;gap:7px;">' + ph
                + '<span style="display:block;min-width:0;flex:1 1 auto;"><span style="color:#94A3B8;font-size:10.5px;text-transform:capitalize;">' + esc(String(m.meal || '').replace('_', ' ')) + '</span><br><b>' + esc(m.name || '') + '</b>'
                + (m.notes ? '<span style="display:block;color:#475569;font-size:11.5px;line-height:1.35;margin-top:1px;">' + esc(m.notes) + '</span>' : '')
                + (m.allergens ? '<span style="display:block;color:#92400E;font-size:11px;margin-top:1px;">⚠ ' + esc(m.allergens) + '</span>' : '') + '</span></div>';
            }).join('')
            + '</div>';
        }).join('') + '</div>'
        // The week's own notes (2026-11-04), as the Weekly menu shows them.
        + (d.menu_notes ? '<div style="margin-top:10px;background:#F8FAFC;border:1px solid #EEF2F6;border-radius:10px;padding:9px 12px;font-size:12.5px;color:#334155;white-space:pre-wrap;"><b>📝 Notes:</b> ' + esc(d.menu_notes) + '</div>' : '')
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No menu published for the week of ' + esc(d.week_start || '') + '.</div>';

    // Timeline
    var tl = d.timeline || [];
    // Child filter dropdown (only when there's more than one child in the feed).
    var _feedKids = Array.from(new Set(tl.map(function (e) { return e.child; }).filter(Boolean))).sort();
    var feedFilter = _feedKids.length > 1
      ? '<div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
        + '<label style="font-size:12px;font-weight:700;color:#475569;">Child</label>'
        + '<select id="pd-child-filter" style="padding:8px 11px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;background:#fff;min-width:180px;">'
        + '<option value="">All children</option>' + _feedKids.map(function (k) { return '<option value="' + esc(k) + '">' + esc(k) + '</option>'; }).join('')
        + '</select><span id="pd-feed-count" style="font-size:12px;color:#94A3B8;"></span></div>'
      : '';
    var feed = feedFilter + (tl.length
      ? '<div id="pd-feed">' + tl.map(function (e) {
        return '<div class="pd-feed-row" data-child="' + esc(e.child || '') + '" style="display:flex;gap:12px;padding:9px 0;border-bottom:1px solid #F1F5F9;align-items:flex-start;">'
          + '<div style="font-size:12px;font-weight:700;color:#64748B;min-width:70px;text-align:right;font-variant-numeric:tabular-nums;">' + esc(e.time || '') + '</div>'
          + '<div style="font-size:16px;width:22px;text-align:center;">' + (e.brand === 'play-spark'
            ? '<img src="/img/play-spark.svg?v=2" width="18" height="18" alt="Play Spark" style="display:block;margin:1px auto 0;">'
            : (e.icon || '•')) + '</div>'
          + '<div style="flex:1;min-width:0;"><b style="font-size:13px;">' + esc(e.child || '') + '</b> <span style="font-size:13px;color:#334155;">— ' + esc(e.title || '') + (e.detail ? ' <span style="color:#64748B;">· ' + esc(e.detail) + '</span>' : '') + '</span>'
          // Play Spark: the end-result picture of the activity (2026-11-24, KT.PlaySparkPic)
          + (e.play_spark_id && window.KT && KT.PlaySparkPic ? ' <button type="button" class="ps-pic-link" data-pd-ps="' + e.play_spark_id + '" data-t="' + esc(String(e.title || '').replace(/^Play Spark: /, '')) + '">🎨 End result</button>' : '')
          + '</div></div>';
      }).join('') + '</div>'
      : '<div style="color:#94A3B8;padding:18px;text-align:center;">Nothing logged on ' + esc(d.date) + '.</div>');

    // 🎨 End result on a Play Spark row: fetch the drawing and show it big (one listener for the whole screen).
    if (window.KT && KT.PlaySparkPic && !window.__ktPdPsBound) {
      window.__ktPdPsBound = true;
      KT.PlaySparkPic.addCss();
      document.addEventListener('click', function (ev) {
        var b = ev.target && ev.target.closest && ev.target.closest('[data-pd-ps]');
        if (!b) return;
        ev.preventDefault();
        var id = b.getAttribute('data-pd-ps'), t = b.getAttribute('data-t') || 'Play Spark';
        var c = KT.PlaySparkPic.cached('i' + id), po = { key: 'i' + id, redraw: KT.PlaySparkPic.redrawIdea ? KT.PlaySparkPic.redrawIdea(id) : null };
        if (c) { KT.PlaySparkPic.open(c, t, po); return; }
        KT.Api.get('/provider/play-spark/ideas/' + id + '/picture').then(function (r) {
          if (r && r.status === 'ready' && r.svg) { KT.PlaySparkPic.remember('i' + id, r.svg, r.steps); KT.PlaySparkPic.open(r.svg, t, Object.assign({ steps: r.steps }, po)); }
          else if (KT.toast) KT.toast('🎨', 'Play Spark', r && r.status === 'drawing' ? 'The picture is still being drawn. Try again in a minute.' : 'There is no picture for this one.');
        }).catch(function (er) { if (KT.toast) KT.toast('🎨', 'Play Spark', (er && er.message) || 'The picture could not open.'); });
      });
    }

    // Performance analytics + moment-breakdown pie
    var an = d.analytics || {};
    var perfMetrics = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(115px,1fr));gap:11px;margin-bottom:14px;">'
      + metric('Attendance', (an.attendance_rate || 0) + '%', s.attended + '/' + s.enrolled, '#1F6080')
      + metric('Care logged', (an.care_coverage_pct || 0) + '%', 'of present kids', '#0F9D6B')
      + metric('One-Tap check-ins', (an.qr_pct || 0) + '%', 'vs manual', '#0EA5E9')
      + metric('Avg moments', (an.avg_moments || 0), 'per child', '#7C3AED')
      + '</div>';

    // Photos & videos
    var pics = d.photos || [];
    var photosHtml = pics.length
      ? '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:8px;">'
        + pics.map(function (p) {
          var isVid = /video/i.test(p.type || '');
          /* data-pd-media: opened IN-APP by the handler below. The href stays so a
             desktop right-click / middle-click still works, but the click itself is
             intercepted — see openMedia(). */
          return '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" data-kt-gallery="pd-day"'
            + ' data-pd-media="' + esc(p.url) + '"'
            + (isVid ? ' data-pd-vid="1"' : '')
            + ' data-pd-cap="' + esc(p.caption || '') + '"'
            + ' data-pd-time="' + esc(p.time || '') + '"'
            + ' style="display:block;position:relative;border-radius:10px;overflow:hidden;padding-top:100%;background:#EEF2F6 center/cover no-repeat;background-image:url(' + esc(p.thumb || p.url) + ');text-decoration:none;cursor:zoom-in;" title="' + esc((p.caption || '') + ' · ' + (p.time || '')) + '">'
            + (isVid ? '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.6);">▶</span>' : '')
            + '<span style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.55));color:#fff;font-size:9.5px;padding:10px 5px 3px;">' + esc(p.time || '') + '</span></a>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No photos or videos captured on this day.</div>';

    // Educator ↔ parent chat log
    var msgs = d.chat || [];
    var chatHtml = msgs.length
      ? '<div style="max-height:360px;overflow:auto;">'
        + msgs.map(function (m) {
          var p = m.is_parent;
          var bg = p ? '#EAF3F6' : '#1F6080', fg = p ? '#0C6070' : '#fff', al = p ? 'flex-start' : 'flex-end';
          return '<div style="display:flex;justify-content:' + al + ';margin-bottom:8px;">'
            + '<div style="max-width:80%;background:' + bg + ';color:' + fg + ';border-radius:12px;padding:8px 12px;">'
            + '<div style="font-size:10px;font-weight:800;opacity:.72;margin-bottom:2px;">' + esc(m.from) + (p ? ' · parent' : ' · staff') + ' · ' + esc(m.time || '') + '</div>'
            + '<div style="font-size:13px;line-height:1.45;">' + esc(m.body || '') + '</div></div></div>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No messages between educators and parents on this day.</div>';

    // Observations recorded this day (learning stories / HDLH notes)
    var obsList = d.observations || [];
    var obsHtml = obsList.length
      ? '<div style="max-height:420px;overflow:auto;display:flex;flex-direction:column;gap:10px;">'
        + obsList.map(function (o) {
          var chips = [o.framework, o.domain].filter(Boolean).map(function (t) {
            return '<span style="display:inline-block;font-size:10px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;color:#6D28D9;background:#F3F0FF;border:1px solid rgba(124,58,237,.16);border-radius:999px;padding:2px 9px;">' + esc(t) + '</span>';
          }).join(' ');
          var shareChip = o.shared
            ? '<span style="font-size:10px;font-weight:800;color:#0F9D6B;background:#E7F8F0;border-radius:999px;padding:2px 9px;">Shared with family</span>'
            : '<span style="font-size:10px;font-weight:800;color:#94A3B8;background:#F1F5F9;border-radius:999px;padding:2px 9px;">Not shared</span>';
          return '<div style="border:1px solid #EEF0F4;--kt-edge:#7C3AED;border-radius:10px;padding:11px 13px;background:#FCFCFF;">'
            + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;">'
            +   '<b style="font-size:13.5px;color:#0D1B2A;">' + esc(o.child || '') + (o.title ? ' — <span style="font-weight:700;color:#334155;">' + esc(o.title) + '</span>' : '') + '</b>'
            +   '<span style="font-size:11px;color:#94A3B8;white-space:nowrap;">' + esc(o.time || '') + (o.educator ? ' · ' + esc(o.educator) : '') + '</span>'
            + '</div>'
            + (o.body ? '<div style="font-size:12.5px;color:#475569;line-height:1.5;margin-top:5px;white-space:pre-wrap;">' + esc(o.body) + '</div>' : '')
            + '<div style="margin-top:7px;display:flex;gap:5px;flex-wrap:wrap;">' + chips + shareChip + '</div>'
            + '</div>';
        }).join('') + '</div>'
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No observations recorded on this day.</div>';

    // Walks & outings on this day — with live map for active ones.
    var _wd = function (m) { if (m == null) return '—'; return ((m) / 1000).toFixed(2) + ' km (' + Math.round(m).toLocaleString() + ' m)'; };
    var _ws = function (n) { if (n == null) return '—'; return n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n); };
    var _wt = function (m) { if (!m) return '—'; return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; };
    var walks = d.walks || [];
    var walksHtml = walks.length
      ? walks.map(function (w) {
          var live = w.status === 'active';
          var stat = live
            ? '<span style="font-size:10.5px;font-weight:800;color:#065F46;background:#D1FAE5;border-radius:999px;padding:2px 9px;">● LIVE</span>'
            : '<span style="font-size:10.5px;font-weight:800;color:#475569;background:#F1F5F9;border-radius:999px;padding:2px 9px;">Ended</span>';
          return '<div style="border:1px solid #E5E7EB;border-radius:12px;padding:12px 14px;background:#fff;">'
            + '<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">'
            +   '<div style="flex:1;min-width:0;">'
            +     '<div style="font-weight:800;color:#0F172A;">🚶 ' + esc(w.title || 'Walk') + ' &nbsp;' + stat + '</div>'
            +     '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(w.destination || '') + ' · ' + esc(w.lead) + ' · ' + w.children + ' child' + (w.children === 1 ? '' : 'ren') + (w.depart ? ' · out ' + esc(w.depart) + (w.return ? '–' + esc(w.return) : '') : '') + '</div>'
            +     '<div style="font-size:12px;color:#334155;margin-top:4px;font-weight:700;">' + _wd(w.distance_m) + ' walked · ' + _ws(w.steps_est) + ' steps · ' + _wt(w.duration_min) + '</div>'
            +   '</div>'
            +   (w.has_location ? '<button class="kt-walk-map-btn" data-id="' + w.id + '" data-t="' + esc(w.title || 'Walk') + '" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:10px;padding:9px 13px;font-weight:800;font-size:12.5px;cursor:pointer;white-space:nowrap;">📍 ' + (live ? 'Live map' : 'View map') + '</button>' : '<span style="font-size:11.5px;color:#94A3B8;align-self:center;">No location shared</span>')
            + '</div>'
            + (live && w.has_location ? '<div class="kt-walk-inline" data-id="' + w.id + '" style="height:240px;margin-top:10px;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;background:#EAF2F8;"></div>' : '')
            // Finished: keep the route visible. The same rendered PNG the parent is
            // emailed, so staff and families are looking at exactly the same picture.
            + (!live && w.map_url ? '<img src="' + esc(w.map_url) + '" alt="Route walked" loading="lazy" style="display:block;width:100%;max-width:600px;height:auto;margin-top:10px;border-radius:12px;border:1px solid #E2E8F0;">' : '')
            + '</div>';
        }).join('<div style="height:10px;"></div>')
      : '<div style="color:#94A3B8;font-size:12.5px;padding:8px 0;">No walks or outings logged on this day.</div>';

    // Provider identity header — the provider's own photo (matched by email) + name,
    // so the overview clearly shows WHOSE day this is (not just a centre name).
    var _pc = d.centre || {};
    var provHeader = '<div style="display:flex;align-items:center;gap:13px;margin-bottom:16px;padding:12px 15px;background:linear-gradient(135deg,#1F608017,#0EA5E90C 55%,#fff);border:1px solid #1F608030;border-radius:16px;box-shadow:0 2px 10px #1F608012;">'
      + avatar(_pc.name, _pc.provider_photo_url, null, 50, false)
      + '<div><div style="font-size:17px;font-weight:800;color:#0D1B2A;line-height:1.2;">' + esc(_pc.name || 'Provider') + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:2px;">Daily overview · ' + esc(d.date || state.date) + '</div></div></div>';

    body.innerHTML =
      provHeader
      + '<div class="pd-tiles" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:18px;">' + cards + '</div>'
      + (function () {
          // Above the record of the day, because "what were they meant to be doing" is
          // the question a director is holding while reading everything below it.
          var lp = d.lesson_plan;
          if (!lp || !(lp.items || []).length) { return ''; }
          var DOMAIN = {
            social_emotional: 'Social & emotional', physical: 'Physical',
            language_literacy: 'Language & literacy', cognitive: 'Cognitive',
            creative_arts: 'Creative arts', self_care: 'Self-care', outdoor: 'Outdoor',
          };
          var rows = lp.items.map(function (a) {
            var meta = [a.time_label || a.time || '', DOMAIN[a.domain] || ''].filter(Boolean).join(' · ');
            // The activity's picture (2026-11-02, lesson plan pictures); tap opens it full size.
            var pic = a.thumb_url || a.image_url;
            var credit = a.image_credit ? 'Picture: ' + a.image_credit : '';
            return '<div style="padding:7px 0;border-top:1px solid #F1F5F9;display:flex;gap:10px;align-items:flex-start;">'
              + '<div style="flex:1 1 auto;min-width:0;">'
              + '<div style="font-size:14px;font-weight:700;color:#0F172A;">' + esc(a.title) + '</div>'
              + (meta ? '<div style="font-size:12px;color:#64748B;margin-top:1px;">' + esc(meta) + '</div>' : '')
              + (a.notes ? '<div style="font-size:13px;color:#475569;margin-top:3px;line-height:1.5;">' + esc(a.notes) + '</div>' : '')
              + (credit ? '<div style="font-size:10.5px;color:#94A3B8;margin-top:2px;">' + esc(credit) + '</div>' : '')
              + '</div>'
              + (pic ? '<img src="' + esc(pic) + '" alt="' + esc(a.title) + '" loading="lazy" decoding="async" onerror="this.remove()"'
                  + ' data-pd-lpfull="' + esc(a.image_url || pic) + '" data-pd-lpcap="' + esc(a.title + (credit ? ' · ' + credit : '')) + '"'
                  + ' onclick="if(window.KT&&KT.mediaViewer){KT.mediaViewer.open([{url:this.dataset.pdLpfull,type:\'image\',caption:this.dataset.pdLpcap}],0)}"'
                  + ' style="flex:0 0 64px;width:64px;height:48px;object-fit:cover;border-radius:8px;background:#F1F5F9;cursor:zoom-in;">' : '')
              + '</div>';
          }).join('');
          var head = (lp.theme ? '<div style="font-size:14px;font-weight:800;color:#0F172A;margin-bottom:2px;">' + esc(lp.theme) + '</div>' : '')
            + (lp.room_name ? '<div style="font-size:12px;color:#64748B;margin-bottom:4px;">' + esc(lp.room_name) + '</div>' : '');
          return '<div style="margin-bottom:16px;">' + card('📚 Planned for this day', head + rows) + '</div>';
        })()
      /* The children's table with the day's smaller cards beside it, rather than four thin full-width bands and an
         empty block next to the table. */
      /* One packed grid: packCards() gives each card the rows its height needs and the grid fills every gap with
         the next card that fits, whatever is busy or empty on the day. The children's table takes two columns;
         the meal plan and the day's feed run full width. */
      + '<div class="pd-pack">'
      +   '<div class="pd-wide">' + card('Children — clock in / out &amp; care', rosterTable) + '</div>'
      // As wide as its days need (packCards .pd-fit) and EARLY in the pack, so the smaller cards after it
      // flow into the columns beside it instead of leaving them empty (2026-11-05).
      +   '<div' + (menuHasData ? ' class="pd-fit" data-fit-min="150" data-fit-n="' + ORDER.filter(function (dd) { return byDay[dd]; }).length + '"' : '') + '>' + card('This week’s meal plan', menuGrid) + '</div>'
      +   '<div>' + card('Provider &amp; educators — clock in / out', staffCard) + '</div>'
      +   '<div>' + card('🧮 Last headcount — by educator', hcCard) + '</div>'
      +   '<div>' + card('Check-in method', donut((d.qr_vs_manual || {}).qr || 0, (d.qr_vs_manual || {}).manual || 0) + '<div style="height:14px;"></div>' + attBar(s)) + '</div>'
      +   '<div>' + card('🚶 Walk Watch', walksHtml) + '</div>'
      +   '<div>' + card('📊 Provider performance', perfMetrics + pie(an.breakdown || {})
            + '<div id="pd-points" style="margin-top:16px;padding-top:14px;border-top:1px solid #F1F5F9;min-height:150px;"></div>') + '</div>'
      +   '<div>' + card('👀 Observations recorded', obsHtml) + '</div>'
      +   '<div>' + card('📸 Photos &amp; videos captured', photosHtml) + '</div>'
      +   '<div>' + card('💬 Educator ↔ parent chat', chatHtml) + '</div>'
      +   '<div>' + card('📝 Daily forms submitted', formsCardHtml(formsData)) + '</div>'
      // Full width only when there is something to lay out; an empty one is a small card that fills a gap.
      +   '<div' + (tl.length > 6 ? ' class="pd-full"' : '') + '>' + card('Activities &amp; daily logs — ' + esc(d.date), feed) + '</div>'
      + '</div>'
      + '<div style="height:24px;"></div>';

    loadPoints(body, d.date || state.date, gen);
    packCards(body);

    // Child filter for the Activities & daily logs feed.
    var _cf = body.querySelector('#pd-child-filter');
    if (_cf) {
      var _applyFilter = function () {
        var v = _cf.value, shown = 0, rows = body.querySelectorAll('.pd-feed-row');
        rows.forEach(function (r) { var ok = !v || r.getAttribute('data-child') === v; r.style.display = ok ? '' : 'none'; if (ok) shown++; });
        var cnt = body.querySelector('#pd-feed-count'); if (cnt) cnt.textContent = v ? (shown + ' of ' + rows.length + ' entries') : '';
      };
      _cf.addEventListener('change', _applyFilter);
    }

    /* Manual sign in / out. Re-bound on every load() because the roster is rebuilt
       from an HTML string each time — binding once at boot would leave dead buttons. */
    body.querySelectorAll('.pd-fix').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!(window.KT && KT.AttendanceFix)) { return; }
        KT.AttendanceFix.open(
          { id: Number(b.getAttribute('data-child')), name: b.getAttribute('data-name') },
          function () { load(container); },
          { date: state.date }          // the day they are already looking at
        );
      });
    });
    body.querySelectorAll('.pd-act').forEach(function (b) {
      b.addEventListener('click', function () { pdCheckEvent(b, container); });
    });
    body.querySelectorAll('.pd-late').forEach(function (b) {
      b.addEventListener('click', function () {
        var row = (d.roster || []).filter(function (x) { return String(x.id) === b.getAttribute('data-child'); })[0];
        if (!row || !(window.KT && KT.runningLate)) { return; }
        var dep = b.getAttribute('data-kind') === 'departure';
        KT.runningLate({ child_id: row.id, name: row.name, late: dep ? row.late_pickup : row.running_late },
          { kind: dep ? 'departure' : 'arrival', onDone: function () { load(container); } });
      });
    });

    // Walk map buttons + inline live maps for active walks.
    body.querySelectorAll('.kt-walk-map-btn').forEach(function (b) {
      b.onclick = function () { if (window.KT && KT.WalkTracker && KT.WalkTracker.openMap) KT.WalkTracker.openMap(+b.getAttribute('data-id'), b.getAttribute('data-t')); };
    });
    body.querySelectorAll('.kt-walk-inline').forEach(function (el) {
      try { if (window.KT && KT.WalkTracker && KT.WalkTracker.mountLiveMap) KT.WalkTracker.mountLiveMap(el, +el.getAttribute('data-id')); } catch (e) {}
    });

    /* One delegated listener rather than one per tile, so it survives the grid being
       rebuilt when the provider or date changes. */
    if (body.getAttribute('data-pd-media-wired') !== '1') {
      body.setAttribute('data-pd-media-wired', '1');
      body.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('[data-pd-media]') : null;
        if (!a) { return; }
        e.preventDefault();
        /* The whole day, not just the tile: ‹ › and swipe step through every photo and
           video captured, starting at the one tapped (Anthony, 2026-10-01). */
        if (window.KT && KT.mediaViewer) {
          var tiles = Array.prototype.slice.call(body.querySelectorAll('[data-pd-media]'));
          KT.mediaViewer.open(tiles.map(function (t) {
            return {
              url: t.getAttribute('data-pd-media'),
              type: t.getAttribute('data-pd-vid') === '1' ? 'video' : 'image',
              caption: t.getAttribute('data-pd-cap') || '',
              meta: t.getAttribute('data-pd-time') || '',
            };
          }), tiles.indexOf(a));
          return;
        }
        openMedia(a.getAttribute('data-pd-media'), a.getAttribute('data-pd-cap'),
                  a.getAttribute('data-pd-vid') === '1');
      });
    }

    // responsive: stack 2-col grids on narrow screens
    if (window.innerWidth < 720) {
      body.querySelectorAll('[style*="grid-template-columns:1fr 1fr"],[style*="grid-template-columns:1.6fr 1fr"]').forEach(function (g) { g.style.gridTemplateColumns = '1fr'; });
    }

    } catch (err) {
      /* Say what happened, where the user is already looking. The console keeps the
         stack for anyone who can reach it; the phone cannot, which is exactly why the
         message has to be on screen. */
      try { console.error('[Daily Overview] render failed for centre ' + state.centreId + ' on ' + state.date, err); } catch (e2) {}
      if (gen !== state.gen) { return; }
      var eb = liveEl(container, 'pd-body');
      if (!eb) { return; }
      eb.innerHTML = '<div style="padding:18px;color:#B91C1C;font-size:13px;line-height:1.55;">'
        + '<b>This provider\'s day could not be displayed.</b><br>'
        + 'The information loaded, but something went wrong drawing it.<br>'
        + '<span style="color:#7F1D1D;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;">'
        + esc((err && (err.message || err.name)) || String(err)) + '</span>'
        + '</div>';
    }
  }

  /**
   * Manual sign in / out from the Daily Overview.
   *
   * The API always allowed this — /provider/check-in is gated on
   * role:educator,centre_director,agency_admin,platform_admin, and requireClockIn()
   * exempts supervisors. What was missing was a screen: the roster tap lives only on
   * educator screens, and an agency admin has no "Today" nav item at all — their
   * attendance screen is this one. (Anthony, 2026-08-26)
   */
  /* Mirrors the server's rule. An educator records attendance as it happens; changing
     what is already on the record belongs with whoever answers for it. */
  function pdMayCorrect() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var roles = u.roles || [];
      return ['centre_director', 'agency_admin', 'platform_admin'].some(function (r) {
        return roles.indexOf(r) !== -1;
      });
    } catch (e) { return false; }
  }

  function pdCheckEvent(btn, container) {
    var childId = btn.getAttribute('data-child');
    var roomId = btn.getAttribute('data-room');
    var going = btn.getAttribute('data-going');   // 'in' | 'out'
    var name = (btn.closest('tr') || {}).textContent || 'this child';
    name = String(name).trim().split(/\s{2,}/)[0] || 'this child';

    /* Not One-Tap: the centred yellow warning every manual check-in/out asks with (2026-11-06). */
    (KT.warnManualCheck ? KT.warnManualCheck({ name: name, going: going, child_id: Number(childId) }) : KT.confirm({
      title: (going === 'in' ? 'Sign in ' : 'Sign out ') + name + '?',
      description: going === 'in'
        ? (name + ' will be marked present as of now, and their family is notified.')
        : (name + ' will be marked as gone home as of now, and their family is notified.'),
      okLabel: going === 'in' ? 'Sign in' : 'Sign out',
    })).then(function (ok) {
      if (!ok) return;
      btn.disabled = true;
      btn.textContent = going === 'in' ? 'Signing in\u2026' : 'Signing out\u2026';
      KT.Api.post(going === 'in' ? '/provider/check-in' : '/provider/check-out', {
        child_id: Number(childId), room_id: Number(roomId),
      }).then(function () {
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast(name + (going === 'in' ? ' signed in' : ' signed out'), 'success');
        load(container);   // repaint the roster so times and status catch up
      }).catch(function (e) {
        btn.disabled = false;
        btn.textContent = going === 'in' ? 'Sign in' : 'Sign out';
        var msg = (e && e.message) || 'Could not record that.';
        if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, 'error'); else alert(msg);
      });
    });
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':provider-day', render); });
  }
  KT.ProviderDayScreen = { render: render };
})(window);
