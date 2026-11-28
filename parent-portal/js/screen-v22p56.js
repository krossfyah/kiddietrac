/* v22p56 — Procare-parity screens. All use kt-design-v22p55.css conventions. */
(function (window) {
  'use strict';
  const KT = (window.KT = window.KT || {});
  const Api = new Proxy({}, {
    get(_, prop) {
      const a = window.KT && window.KT.Api;
      if (!a) throw new Error('KT.Api not loaded yet — call after app.js initialises');
      const v = a[prop];
      return typeof v === 'function' ? v.bind(a) : v;
    }
  });

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  /* A date-only value is a calendar day, not an instant. `new Date('2026-07-27')` is
     parsed as UTC midnight, and rendering that in a western timezone lands on the 26th —
     every closure here was drawn a day early. Date-only is formatted from its own parts;
     anything carrying a time still goes through the agency timezone. */
  const fmtDate = (s) => {
    if (!s) return '';
    const str = String(s);
    const dOnly = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (dOnly && !/[T ]\d{2}:/.test(str)) {
      return (MONTHS_ABBR[+dOnly[2] - 1] || dOnly[2]) + ' ' + (+dOnly[3]) + ', ' + dOnly[1];
    }
    try {
      const tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      return sdInstant(str).toLocaleDateString(undefined,
        { year: 'numeric', month: 'short', day: 'numeric', timeZone: tz });
    } catch (e) { return str.slice(0, 10); }
  };

  /* A ZONE-LESS SERVER TIMESTAMP IS UTC.

     "2026-07-29 03:40:08" carries no zone, and `new Date()` reads that as the BROWSER's
     local time — so the same row rendered a different day on a different desk. Safia
     Ali's NDA is 03:40 UTC, which is the 28th in Toronto and the 29th on a machine set
     to Mountain time, and both were being printed as fact.

     KT.Fmt.parse already settles this for the rest of the portal ("treat zone-less as
     UTC"); this file had its own formatter and never used it. */
  function sdInstant(str) {
    var F = window.KT && KT.Fmt;
    if (F && F.parse) { var d = F.parse(str); if (d) { return d; } }
    var v = String(str).replace(' ', 'T');
    if (!/(Z|[+-]\d{2}:?\d{2})$/.test(v)) { v += 'Z'; }
    return new Date(v);
  }

  /* THE HOST IS ALREADY ON IT.

     doc_file_url and signature_data used to be stored paths — "/storage/agreements/…" —
     and this screen glued the API host on the front. They are not any more: every
     protected /storage URL leaving the API is replaced by SignProtectedMedia with a
     complete signed link, so the concatenation produced

       https://api.kiddietrac.comhttps://api.kiddietrac.com/api/v1/media/f?…

     which resolves to nothing. That is the broken viewer on every privacy_nda row.
     Absolute stays absolute; a data: URI is already a document; only a genuine path
     gets a host. (Anthony, 2026-09-16) */
  function sdAbs(u) {
    var v = String(u == null ? '' : u).trim();
    if (!v) { return ''; }
    if (/^https?:\/\//i.test(v) || v.indexOf('data:') === 0) { return v; }
    return esignApiHost() + (v.charAt(0) === '/' ? v : '/' + v);
  }

  /* Is there a file behind this row, whatever shape the URL arrives in? This asked
     `signature_data.indexOf('/storage') === 0`, which stopped being true the day the
     signing middleware shipped — so handbook rows lost their Download button while the
     file sat on disk the whole time. */
  function sdHasFile(s) {
    if (!s) { return false; }
    if (s.doc_file_url) { return true; }
    var sig = String(s.signature_data || '');
    if (!sig || sig.indexOf('data:') === 0) { return false; }
    return /^https?:\/\//i.test(sig) || sig.indexOf('/storage') === 0 || sig.indexOf('storage/') === 0;
  }

  /* "privacy_nda" is a database value, not a document name. It was printed raw in the
     one column whose job is to say what the document IS. */
  const SD_TYPES = {
    privacy_nda: 'Privacy policy & NDA',
    privacy_nda_declined: 'Privacy policy & NDA — declined',
    handbook: 'Handbook acknowledgement',
    parent_handbook: 'Parent handbook acknowledgement',
    enrollment_contract: 'Enrolment contract',
    photo_consent: 'Photo consent',
    medical_authorization: 'Medical authorisation',
    field_trip: 'Field trip permission',
  };
  function sdTypeLabel(t) {
    var k = String(t || '').trim();
    if (SD_TYPES[k]) { return SD_TYPES[k]; }
    return k ? k.replace(/[_-]+/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }) : 'Document';
  }
  const fmtMoney = (n) => '$' + (Number(n) || 0).toFixed(2);

  // ============================ Closures =================================
  async function renderClosures(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/closures').catch(() => ({ data: [] }));
    /* MOST RECENTLY ADDED FIRST (2026-10-13, Anthony: "closures section should default to the
       latest in the table"). The API returns date order, so a closure added today sat among a
       year of pre-loaded holidays. Newest entry at the top; same-moment rows by newest id. */
    r.data = (r.data || []).slice().sort((x, y) =>
      String(y.created_at || '').localeCompare(String(x.created_at || '')) || (Number(y.id) - Number(x.id)));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🗓 Closures & holidays</h2>
        <p>Days you'll be closed. Families are notified automatically. Billing pauses on closures with affects_billing = yes.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="cl-new">+ New closure</button></div>
      </div>
      <div id="cl-overview"></div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Date</th><th>Centre</th><th>Type</th><th>Reason</th><th>Billing</th><th>Added</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${fmtDate(c.closure_date)}${c.end_date ? ' – ' + fmtDate(c.end_date) : ''}</strong></td>
            <td>${esc(c.centre_name)}</td>
            <td><span class="kt-pill kt-pill-info">${esc(c.closure_type)}</span></td>
            <td>${esc(c.reason || '—')}</td>
            <td>${c.affects_billing ? 'Paused' : 'No change'}</td>
            <td style="font-size:12.5px;color:#64748B;">${esc(c.added_by || '—')}${c.created_at ? '<div style="font-size:11.5px;color:#94A3B8;">' + esc(fmtStamp(c.created_at)) + '</div>' : ''}</td>
            <td><button class="kt-act-icon kt-icon-tip" data-ed="${c.id}" title="Edit" aria-label="Edit" data-kttip="Edit">✏️</button><button class="kt-act-icon kt-act-danger kt-icon-tip" data-rm="${c.id}" title="Remove" aria-label="Remove" data-kttip="Remove">🗑️</button></td>
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No closures scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('cl-new').onclick = () => openClosureModal();
    try { closureOverview(main.querySelector('#cl-overview'), r.data || []); } catch (e) {}
    main.querySelectorAll('button[data-ed]').forEach(b => b.onclick = () => {
      const row = (r.data || []).filter(c => String(c.id) === String(b.dataset.ed))[0];
      if (row) { openClosureModal(row); }
    });
    main.querySelectorAll('button[data-rm]').forEach(b => b.onclick = async () => {
      if (!await KT.confirm('Remove this closure?')) return;
      await Api.delete(`/operations/closures/${b.dataset.rm}`);
      renderClosures(main);
    });
  }
  /* ── CLOSURES AT A GLANCE (2026-10-28, Anthony: "closures and holidays section add some stats cards, graphs or
     something cool to gauge whats happening from this perspective"). Everything is worked out from the rows the
     table already has, so it can never disagree with it:
       - DAYS are WEEKDAYS (Mon-Fri) in a closure's range: a long weekend's Saturday is not a lost care day.
         A day counts once per centre that closes (two centres closed on Family Day = 2 centre-days).
       - Dates are read from their parts (never through Date() parsing), and "today" is the agency's date.
     Stats: next closure + countdown, next 90 days, centre-days closed this year, billing-paused days, centres
     touched. Charts (inline SVG): closed days by month stacked by type, type share donut, days per centre, and
     the next closures as a timeline. A year picker switches the year-based parts. */
  const CL_TYPES = {
    holiday: ['Holiday', '#0E7490'] /* not #1F6080: agency branding repaints that colour */, pd_day: ['PD day', '#7C3AED'], emergency: ['Emergency', '#DC2626'],
    renovation: ['Renovation', '#D97706'], other: ['Other', '#64748B'],
  };
  function clType(t) { return CL_TYPES[t] || [String(t || 'Other').replace(/_/g, ' '), '#64748B']; }
  function clParts(d) { const m = String(d || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? [+m[1], +m[2], +m[3]] : null; }
  function clUtc(p) { return Date.UTC(p[0], p[1] - 1, p[2]); }
  function clIso(ms) { return new Date(ms).toISOString().slice(0, 10); }
  function clToday() {
    try { return new Date().toLocaleDateString('en-CA', { timeZone: (window.KT && KT.agencyTz && KT.agencyTz()) || undefined }); }
    catch (e) { return new Date().toISOString().slice(0, 10); }
  }
  /* Every weekday a closure covers, as YYYY-MM-DD strings (capped at a year: a typo'd end date must not hang the page). */
  function clDays(c) {
    const a = clParts(c.closure_date); if (!a) { return []; }
    const b = clParts(c.end_date) || a;
    const out = [];
    for (let t = clUtc(a), end = Math.min(clUtc(b), clUtc(a) + 366 * 864e5); t <= end; t += 864e5) {
      const wd = new Date(t).getUTCDay();
      if (wd !== 0 && wd !== 6) { out.push(clIso(t)); }
    }
    return out;
  }
  function clNice(iso) { const p = clParts(iso); return p ? (MONTHS_ABBR[p[1] - 1] || p[1]) + ' ' + p[2] : iso; }
  function closureOverview(host, rows) {
    if (!host) { return; }
    if (!document.getElementById('cl-ov-css')) {
      const st = document.createElement('style'); st.id = 'cl-ov-css';
      st.textContent = '.cl-ov{margin:0 0 16px}'
        + '.cl-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}'
        + '.cl-tile{position:relative;overflow:hidden;border-radius:14px;padding:14px 16px;border:1px solid #E2E8F0;background:linear-gradient(160deg,var(--t1),#fff 70%);box-shadow:0 1px 3px rgba(15,23,42,.05)}'
        + '.cl-tile .k{font-weight:800;font-size:10.5px;line-height:1.2;letter-spacing:.6px;text-transform:uppercase;color:var(--t2)}'
        + '.cl-tile .v{font-weight:800;font-size:26px;line-height:1.15;color:#0F172A;margin-top:6px}'
        + '.cl-tile .s{font-size:12.5px;color:#64748B;margin-top:3px;line-height:1.35}'
        + '.cl-tile .ic{position:absolute;right:12px;top:10px;font-size:22px;opacity:.85}'
        + '.cl-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}'
        + '.cl-chart{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;box-shadow:0 1px 3px rgba(15,23,42,.05);min-width:0}'
        + '.cl-chart h4{margin:0 0 10px;font-size:14px;color:#0F172A;display:flex;justify-content:space-between;align-items:center;gap:8px}'
        + '.cl-chart h4 small{font-weight:600;color:#94A3B8;font-size:12px}'
        + '.cl-leg{display:flex;flex-wrap:wrap;gap:6px 12px;margin-top:8px;font-size:12px;color:#475569}'
        + '.cl-leg .sw{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}'
        + '.cl-yr{display:inline-flex;gap:4px;flex-wrap:wrap}.cl-yr button{border:1px solid #E2E8F0;background:#fff;border-radius:999px;padding:3px 10px;font-weight:700;font-size:12px;color:#475569;cursor:pointer}'
        + '.cl-yr button.on{background:#1F6080;border-color:#1F6080;color:#fff}'
        + '.cl-hbar{display:grid;grid-template-columns:minmax(90px,38%) 1fr auto;gap:8px;align-items:center;font-size:12.5px;margin:6px 0}'
        + '.cl-hbar .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;font-weight:600}'
        + '.cl-hbar .b{height:10px;border-radius:999px;background:#EEF2F7;overflow:hidden}.cl-hbar .b i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#14B8C4,#1F6080)}'
        + '.cl-hbar .c{font-weight:800;color:#0F172A;min-width:22px;text-align:right}'
        + '.cl-next{display:flex;flex-direction:column;gap:8px}'
        + '.cl-next .r{display:grid;grid-template-columns:52px 1fr auto;gap:10px;align-items:center}'
        + '.cl-next .d{border-radius:10px;text-align:center;padding:5px 0;color:#fff;font-weight:800;font-size:11px;line-height:1.15;}.cl-next .d b{display:block;font-size:17px}'
        + '.cl-next .t{min-width:0}.cl-next .t div{font-weight:700;color:#0F172A;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cl-next .t span{font-size:12px;color:#64748B}'
        + '.cl-next .in{font-weight:800;font-size:11.5px;color:#1F6080;background:#E0F2F7;border-radius:999px;padding:3px 9px;white-space:nowrap}'
        + '.cl-empty{color:#94A3B8;font-size:13px;padding:18px 0;text-align:center}';
      document.head.appendChild(st);
    }
    const today = clToday(), tp = clParts(today), thisYear = tp ? tp[0] : new Date().getFullYear();
    const items = rows.map(c => ({ c: c, days: clDays(c) })).filter(x => x.days.length || clParts(x.c.closure_date));
    const years = Array.from(new Set(items.flatMap(x => x.days.map(d => +d.slice(0, 4))).concat([thisYear]))).sort();
    let year = thisYear;

    function draw() {
      const inYear = d => +d.slice(0, 4) === year;
      // Next closure (any year) and the next 90 days.
      const upcoming = items.map(x => ({ c: x.c, first: x.days.find(d => d >= today) || (x.days.length ? null : (String(x.c.closure_date).slice(0, 10) >= today ? String(x.c.closure_date).slice(0, 10) : null)) }))
        .filter(x => x.first).sort((a, b) => a.first.localeCompare(b.first));
      const t90 = clIso(clUtc(tp || [thisYear, 1, 1]) + 90 * 864e5);
      const next90 = items.reduce((n, x) => n + x.days.filter(d => d >= today && d <= t90).length, 0);
      const next90cl = items.filter(x => x.days.some(d => d >= today && d <= t90)).length;
      const centreDays = items.reduce((n, x) => n + x.days.filter(inYear).length, 0);
      const pastDays = items.reduce((n, x) => n + x.days.filter(d => inYear(d) && d < today).length, 0);
      const billDays = items.reduce((n, x) => n + (x.c.affects_billing ? x.days.filter(inYear).length : 0), 0);
      const centres = new Set(items.filter(x => x.days.some(inYear)).map(x => x.c.centre_name || x.c.centre_id));
      const allCentres = new Set(rows.map(c => c.centre_name || c.centre_id));
      const daysTo = upcoming[0] ? Math.round((clUtc(clParts(upcoming[0].first)) - clUtc(tp)) / 864e5) : null;

      const tile = (ic, k, v, sub, c1, c2) => `<div class="cl-tile" style="--t1:${c1};--t2:${c2}"><span class="ic">${ic}</span><div class="k">${k}</div><div class="v">${v}</div><div class="s">${sub}</div></div>`;
      const nx = upcoming[0];
      const tiles = '<div class="cl-tiles">'
        + tile('⏭️', 'Next closure', nx ? clNice(nx.first) : '—', nx ? esc((nx.c.reason || clType(nx.c.closure_type)[0]) + ' · ' + (daysTo === 0 ? 'today' : daysTo === 1 ? 'tomorrow' : 'in ' + daysTo + ' days')) : 'Nothing scheduled', '#E0F2F7', '#0E7490')
        + tile('📅', 'Next 90 days', String(next90), 'centre-days closed · ' + next90cl + ' closure' + (next90cl === 1 ? '' : 's'), '#EDE9FE', '#6D28D9')
        + tile('🏫', 'Closed in ' + year, String(centreDays), pastDays + ' already · ' + Math.max(0, centreDays - pastDays) + ' still to come', '#FEF3C7', '#B45309')
        + tile('💳', 'Billing paused', String(billDays), centreDays ? Math.round(billDays * 100 / centreDays) + '% of closed days in ' + year : 'No closed days', '#DCFCE7', '#15803D')
        + tile('📍', 'Centres affected', centres.size + ' / ' + allCentres.size, 'with a closure in ' + year, '#FCE7F3', '#BE185D')
        + '</div>';

      // Month bars, stacked by type.
      const byMonth = Array.from({ length: 12 }, () => ({}));
      items.forEach(x => x.days.filter(inYear).forEach(d => { const m = +d.slice(5, 7) - 1, t = x.c.closure_type || 'other'; byMonth[m][t] = (byMonth[m][t] || 0) + 1; }));
      const mTot = byMonth.map(o => Object.values(o).reduce((a, b) => a + b, 0));
      const mMax = Math.max(1, ...mTot);
      const W = 360, H = 150, bw = 20, gap = (W - 12 * bw) / 12;
      let bars = '';
      byMonth.forEach((o, i) => {
        let y = H - 18; const x = i * (bw + gap) + gap / 2;
        Object.keys(CL_TYPES).concat(Object.keys(o).filter(k => !CL_TYPES[k])).forEach(t => {
          const v = o[t]; if (!v) { return; }
          const h = Math.max(2, (H - 34) * v / mMax); y -= h;
          bars += `<rect x="${x}" y="${y}" width="${bw}" height="${h}" rx="4" fill="${clType(t)[1]}"><title>${MONTHS_ABBR[i]}: ${v} ${clType(t)[0]}</title></rect>`;
        });
        if (mTot[i]) { bars += `<text x="${x + bw / 2}" y="${y - 4}" text-anchor="middle" font-size="10" font-weight="800" fill="#0F172A">${mTot[i]}</text>`; }
        const isNow = year === thisYear && tp && tp[1] - 1 === i;
        bars += `<text x="${x + bw / 2}" y="${H - 4}" text-anchor="middle" font-size="10" fill="${isNow ? '#1F6080' : '#94A3B8'}" font-weight="${isNow ? 800 : 600}">${(MONTHS_ABBR[i] || '').slice(0, 1)}</text>`;
      });
      const usedTypes = Object.keys(CL_TYPES).filter(t => byMonth.some(o => o[t]));
      const monthChart = `<div class="cl-chart"><h4>Closed days by month <small>${year}</small></h4>`
        + (centreDays ? `<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Closed days by month">${bars}</svg>` : '<div class="cl-empty">No closures in ' + year + '.</div>')
        + `<div class="cl-leg">${usedTypes.map(t => `<span><svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" style="margin-right:6px;vertical-align:-1px"><rect width="10" height="10" rx="3" fill="${clType(t)[1]}"/></svg>${clType(t)[0]}</span>`).join('')}</div></div>`;

      // Type donut.
      const byType = {};
      items.forEach(x => { const n = x.days.filter(inYear).length; if (n) { byType[x.c.closure_type || 'other'] = (byType[x.c.closure_type || 'other'] || 0) + n; } });
      const tot = Object.values(byType).reduce((a, b) => a + b, 0);
      let arcs = '', a0 = -Math.PI / 2;
      const R = 52, cx = 70, cy = 70, sw = 20;
      Object.keys(byType).sort((a, b) => byType[b] - byType[a]).forEach(t => {
        const frac = byType[t] / tot, a1 = a0 + frac * Math.PI * 2;
        if (frac >= 0.999) { arcs += `<circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="${clType(t)[1]}" stroke-width="${sw}"/>`; }
        else {
          const x0 = cx + R * Math.cos(a0), y0 = cy + R * Math.sin(a0), x1 = cx + R * Math.cos(a1), y1 = cy + R * Math.sin(a1);
          arcs += `<path d="M${x0.toFixed(2)} ${y0.toFixed(2)} A${R} ${R} 0 ${frac > 0.5 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}" fill="none" stroke="${clType(t)[1]}" stroke-width="${sw}"><title>${clType(t)[0]}: ${byType[t]}</title></path>`;
        }
        a0 = a1;
      });
      const donut = `<div class="cl-chart"><h4>What closes us <small>${year}</small></h4>` + (tot
        ? `<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap"><svg viewBox="0 0 140 140" width="140" height="140" role="img" aria-label="Closure types">${arcs}<text x="70" y="68" text-anchor="middle" font-size="22" font-weight="800" fill="#0F172A">${tot}</text><text x="70" y="86" text-anchor="middle" font-size="10.5" fill="#64748B">centre-days</text></svg>`
          + `<div style="flex:1;min-width:140px">${Object.keys(byType).sort((a, b) => byType[b] - byType[a]).map(t => `<div class="cl-hbar" style="grid-template-columns:1fr auto"><span class="n"><svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" style="margin-right:6px;vertical-align:-1px"><rect width="10" height="10" rx="3" fill="${clType(t)[1]}"/></svg>${clType(t)[0]}</span><span class="c">${byType[t]} <span style="color:#94A3B8;font-weight:600">· ${Math.round(byType[t] * 100 / tot)}%</span></span></div>`).join('')}</div></div>`
        : '<div class="cl-empty">No closures in ' + year + '.</div>') + '</div>';

      // Per centre.
      const byCentre = {};
      items.forEach(x => { const n = x.days.filter(inYear).length; if (n) { const k = x.c.centre_name || ('Centre ' + x.c.centre_id); byCentre[k] = (byCentre[k] || 0) + n; } });
      const cMax = Math.max(1, ...Object.values(byCentre));
      const centreChart = `<div class="cl-chart"><h4>Days closed per centre <small>${year}</small></h4>` + (Object.keys(byCentre).length
        ? Object.keys(byCentre).sort((a, b) => byCentre[b] - byCentre[a]).slice(0, 8).map(k => `<div class="cl-hbar"><span class="n" title="${esc(k)}">${esc(k)}</span><span class="b"><i style="width:${Math.round(byCentre[k] * 100 / cMax)}%"></i></span><span class="c">${byCentre[k]}</span></div>`).join('')
        : '<div class="cl-empty">No closures in ' + year + '.</div>') + '</div>';

      // Up next.
      const nextChart = `<div class="cl-chart"><h4>Up next <small>${upcoming.length} scheduled</small></h4>` + (upcoming.length
        ? '<div class="cl-next">' + upcoming.slice(0, 5).map(u => {
            const p = clParts(u.first), dd = Math.round((clUtc(p) - clUtc(tp)) / 864e5), ty = clType(u.c.closure_type);
            const n = clDays(u.c).length;
            return `<div class="r"><div class="d" style="background:${ty[1]}">${(MONTHS_ABBR[p[1] - 1] || '').toUpperCase()}<b>${p[2]}</b></div>`
              + `<div class="t"><div>${esc(u.c.reason || ty[0])}</div><span>${esc(u.c.centre_name || '')} · ${ty[0]}${n > 1 ? ' · ' + n + ' days' : ''}${u.c.affects_billing ? ' · billing paused' : ''}</span></div>`
              + `<span class="in">${dd === 0 ? 'Today' : dd === 1 ? 'Tomorrow' : 'in ' + dd + 'd'}</span></div>`;
          }).join('') + '</div>'
        : '<div class="cl-empty">Nothing scheduled. Add the next holiday with "+ New closure".</div>') + '</div>';

      const picker = years.length > 1 ? `<div class="cl-yr" role="tablist" aria-label="Year">${years.map(y => `<button type="button" data-y="${y}" class="${y === year ? 'on' : ''}" role="tab" aria-selected="${y === year}">${y}</button>`).join('')}</div>` : '';
      host.innerHTML = '<div class="cl-ov">' + (picker ? `<div style="display:flex;justify-content:flex-end;margin:0 0 8px">${picker}</div>` : '') + tiles
        + '<div class="cl-charts">' + nextChart + monthChart + donut + centreChart + '</div></div>';
      host.querySelectorAll('.cl-yr button').forEach(b => b.onclick = () => { year = +b.dataset.y; draw(); });
    }
    draw();
  }

  /* A closure's created_at is stored UTC. Rendered in the agency's timezone, like
     every other timestamp — never the device's. */
  function fmtStamp(ts) {
    if (!ts) { return ''; }
    try {
      var tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      /* THE YEAR, because this now stamps signatures as well as closures. A closure is
         always about now; a signature record goes back years, and "Jul 29, 11:40 p.m."
         with no year is not an audit trail. */
      return sdInstant(ts).toLocaleString('en-CA',
        { year: 'numeric', month: 'short', day: 'numeric',
          hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return String(ts).slice(0, 16); }
  }

  /* One dialog for both jobs. `existing` turns it into an edit, which PATCHes rather than
     creating a second closure — the previous way to fix a wrong date was to delete and
     re-add, and that announces the same closure to every family twice. */
  async function openClosureModal(existing) {
    const _cr = await Api.get('/admin/centres').catch(() => ({})); const centres = _cr.centres || _cr.data || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">${existing ? 'Edit closure' : 'New closure'}</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="cl-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Type</label>
      <select id="cl-type" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="holiday">Holiday</option><option value="pd_day">PD day</option>
        <option value="emergency">Emergency</option><option value="renovation">Renovation</option>
        <option value="other">Other</option></select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Date (start)</label>
      <input id="cl-date" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">End date (optional, for multi-day)</label>
      <input id="cl-end" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Reason</label>
      <input id="cl-reason" placeholder="e.g. Family Day" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13.5px;font-weight:600;"><input id="cl-bill" type="checkbox" checked> Pause billing on these days</label>
      <div style="margin-top:20px;text-align:right;">
        <button id="cl-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="cl-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Save</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#cl-cancel').onclick = () => m.remove();

    // Prefill when editing. Dates are sliced, not parsed: running a date-only value
    // through Date() shifts it a day in either direction depending on the engine.
    if (existing) {
      m.querySelector('#cl-centre').value = String(existing.centre_id);
      m.querySelector('#cl-centre').disabled = true;   // moving a closure between centres is a new closure
      m.querySelector('#cl-type').value = existing.closure_type || 'holiday';
      m.querySelector('#cl-date').value = String(existing.closure_date || '').slice(0, 10);
      m.querySelector('#cl-end').value = existing.end_date ? String(existing.end_date).slice(0, 10) : '';
      m.querySelector('#cl-reason').value = existing.reason || '';
      m.querySelector('#cl-bill').checked = !!existing.affects_billing;
    }

    m.querySelector('#cl-save').onclick = async () => {
      const btn = m.querySelector('#cl-save');
      const body = {
        closure_date: m.querySelector('#cl-date').value,
        end_date: m.querySelector('#cl-end').value || null,
        closure_type: m.querySelector('#cl-type').value,
        reason: m.querySelector('#cl-reason').value,
        affects_billing: m.querySelector('#cl-bill').checked,
      };
      if (!body.closure_date) {
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast('Pick a start date', 'error'); }
        return;
      }
      btn.disabled = true; btn.textContent = 'Saving…';
      try {
        if (existing) {
          await Api.patch(`/operations/closures/${existing.id}`, body);
        } else {
          body.centre_id = +m.querySelector('#cl-centre').value;
          await Api.post('/operations/closures', body);
        }
      } catch (e) {
        btn.disabled = false; btn.textContent = 'Save';
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast((e && e.message) || 'Could not save', 'error'); }
        return;
      }
      m.remove();
      renderClosures(document.querySelector('main'));
    };
  }

  // ============================ Late pickup =================================
  /* LATE PICKUP TIMES (2026-10-06). "Picked up" is the wall-clock time typed on the form
     (stored without a zone) — printed from its parts so nothing shifts it. "Logged" is
     when it was recorded, a real UTC instant, shown in the agency's zone. */
  function lpWall(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (!m) { return esc(s || '—'); }
    const h = +m[4], ap = h >= 12 ? 'PM' : 'AM', h12 = (h % 12) || 12;
    return (MONTHS_ABBR[+m[2] - 1] || m[2]) + ' ' + (+m[3]) + ', ' + m[1] + ' · ' + h12 + ':' + m[5] + ' ' + ap;
  }
  function lpLogged(s) {
    if (!s) { return '—'; }
    try {
      const tz = (window.KT && KT.agencyTz && KT.agencyTz()) || undefined;
      return sdInstant(String(s)).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return esc(String(s)); }
  }

  async function renderLatePickup(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/late-pickups').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>⏰ Late pickup fees</h2>
        <p>Charge a per-minute fee for pickups after centre close. Auto-appends to the family's next invoice.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="lp-new">+ Log late pickup</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Picked up</th><th>Child</th><th>Minutes late</th><th>Fee</th><th>Logged</th><th>Logged by</th><th>Notes</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td style="white-space:nowrap">${lpWall(c.pickup_at)}</td>
            <td><strong>${esc(c.child_name)}</strong></td>
            <td><span class="kt-pill kt-pill-warning">${c.minutes_late} min</span></td>
            <td><strong>${fmtMoney(c.fee_amount)}</strong></td>
            <td style="white-space:nowrap">${lpLogged(c.created_at)}</td>
            <td>${esc(c.logged_by || '—')}</td>
            <td>${esc(c.notes || '')}</td>
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No late pickups logged.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('lp-new').onclick = () => openLatePickupModal();
  }
  async function openLatePickupModal() {
    // Agency-scoped picker options — works for educators too (not just admins).
    const _opts = await Api.get('/operations/late-pickup-options').catch(() => ({ centres: [], children: [] }));
    const centres = _opts.centres || [];
    const children = _opts.children || [];
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
    m.innerHTML = `<div style="background:#fff;padding:28px;border-radius:14px;max-width:480px;width:92%;">
      <h3 style="margin:0 0 16px;color:#0F172A;">Log late pickup</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Centre</label>
      <select id="lp-centre" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${centres.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Child</label>
      <select id="lp-child" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">${children.map(c => `<option value="${c.id}">${esc(c.first_name)} ${esc(c.last_name)}</option>`).join('')}</select>
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Pickup time</label>
      <input id="lp-time" type="datetime-local" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Centre close time</label>
      <input id="lp-close" type="time" value="18:00" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">
      <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Notes</label>
      <textarea id="lp-notes" rows="2" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;"></textarea>
      <div style="margin-top:20px;text-align:right;">
        <button id="lp-cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
        <button id="lp-save" class="kt-btn kt-btn-primary" style="margin-left:8px;">Log + charge</button>
      </div></div>`;
    document.body.appendChild(m);
    m.querySelector('#lp-cancel').onclick = () => m.remove();
    m.querySelector('#lp-save').onclick = async () => {
      const r = await Api.post('/operations/late-pickup', {
        centre_id: +m.querySelector('#lp-centre').value,
        child_id: +m.querySelector('#lp-child').value,
        pickup_at: m.querySelector('#lp-time').value,
        close_time: m.querySelector('#lp-close').value,
        notes: m.querySelector('#lp-notes').value,
      });
      alert(r.status === 'within_grace' ? 'Within grace period — no charge.' : `Charged ${fmtMoney(r.fee)} for ${r.minutes_late} min`);
      m.remove();
      renderLatePickup(document.querySelector('main'));
    };
  }

  // ============================ Room ratios =================================
  /* LIVE (2026-10-13). Children signed in and staff on the floor right now, from the ratio
     engine (App\Support\RatioRules; Ontario licensed centres: O. Reg. 137/15 ss.8, 8.1, 54).
     It used to count ENROLLED children against every staff role at the centre. Per room:
     required vs present and why (age group, reduced-ratio window, supervisor counting), group
     size, mixed-age, two-adult and RECE findings. Ontario agencies set each location's licence
     type, hours and windows, and each room's licensed age group, under "Ratio settings". */
  async function renderRoomRatios(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/operations/ratio-status').catch(() => ({ data: [] }));
    const rooms = r.data || [];
    const centres = r.centres || [];
    const on = r.jurisdiction === 'CA-ON';
    const groups = r.groups || {};
    const red = rooms.filter(x => x.status === 'breach' || x.status === 'over_group').length;
    const amber = rooms.filter(x => x.status === 'attention').length;
    const okN = rooms.length - red - amber;
    const fmtN = (v) => { const n = Number(v) || 0; return Number.isInteger(n) ? String(n) : n.toFixed(1); };
    const pill = (st) => st === 'breach' ? '<span class="kt-pill kt-pill-danger">⚠ Out of ratio</span>'
      : st === 'over_group' ? '<span class="kt-pill kt-pill-danger">⚠ Over group size</span>'
      : st === 'attention' ? '<span class="kt-pill kt-pill-warning">Check staffing</span>'
      : st === 'tight' ? '<span class="kt-pill kt-pill-warning">At the limit</span>'
      : '<span class="kt-pill kt-pill-success">✓ In ratio</span>';
    const roomRow = (x) => {
      const max = x.standard && x.standard.max_group;
      const over = x.over_max_group;
      const why = esc(x.reason || ('Room ratio ' + ((x.standard && x.standard.ratio) || '')));
      const warn = (x.warnings || []).map(w => `<div style="color:#92400E;">⚠️ ${esc(w)}</div>`).join('');
      return `<tr>
        <td><strong>${esc(x.room_name)}</strong><div style="margin-top:3px;"><span class="kt-pill kt-pill-purple">${esc((x.standard && x.standard.label) || '')}</span></div></td>
        <td><strong${over ? ' style="color:#B91C1C;"' : ''}>${x.children_present}</strong>${max ? ` <span style="color:#64748B;">/ ${max} max</span>` : ''}</td>
        <td><strong${x.staff_present < x.required_staff ? ' style="color:#B91C1C;"' : ''}>${fmtN(x.staff_present)}</strong> <span style="color:#64748B;">/ ${x.required_staff} needed</span></td>
        <td style="font-size:12px;line-height:1.4;max-width:420px;"><div style="color:#475569;">${why}</div>${warn}</td>
        <td>${pill(x.status)}</td>
      </tr>`;
    };
    const centreBlock = (c) => {
      const list = rooms.filter(x => x.centre_id === c.id);
      const badge = !on ? ''
        : c.ontario_rules ? '<span class="kt-pill kt-pill-info">Ontario centre rules</span>'
        : '<span class="kt-pill" title="Set the licence type to Licensed child care centre in Ratio settings to use O. Reg. 137/15 rules">Room’s own ratio</span>';
      return `<div class="kt-card" style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
          <h3 style="margin:0;font-size:16px;">${esc(c.name)}</h3>${badge}
          ${on ? `<button type="button" class="kt-btn kt-btn-ghost" data-rr-settings="${c.id}" style="margin-left:auto;">⚙ Ratio settings</button>` : ''}
        </div>
        ${c.ontario_rules && c.settings ? `<div style="font-size:12px;color:#64748B;margin-bottom:6px;">${esc(windowsLine(c.settings))}</div>` : ''}
        <table data-kt-no-bulk>
          <thead><tr><th>Room</th><th>Children</th><th>Staff</th><th>Why / checks</th><th>Status</th></tr></thead>
          <tbody>${list.map(roomRow).join('') || '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">No active rooms.</td></tr>'}</tbody>
        </table>
      </div>`;
    };
    function windowsLine(s) {
      if (!s) return '';
      const parts = ['Program ' + s.program_start + '–' + s.program_end];
      if (s.reduced_enabled) {
        parts.push('reduced ratio: arrival ' + s.arrival_minutes + ' min, departure ' + s.departure_minutes + ' min' + (s.rest_start && s.rest_minutes ? ', rest ' + s.rest_start + ' for ' + s.rest_minutes + ' min' : ''));
      } else parts.push('reduced ratios off');
      if ((s.outdoor || []).length) parts.push('outdoor ' + s.outdoor.map(w => w.from + '–' + w.to).join(', '));
      return parts.join(' · ');
    }
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>👥 Real-time room ratios</h2>
        <p>${on ? 'Ontario O. Reg. 137/15. ' : ''}Children signed in and staff on the floor right now. ${red} of ${rooms.length} room${rooms.length === 1 ? '' : 's'} out of ratio${amber ? ', ' + amber + ' to check' : ''}.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-${red > 0 ? 'danger' : 'success'}"><div class="kt-kpi-label">Out of ratio</div><div class="kt-kpi-value">${red}</div></div>
        <div class="kt-kpi kt-kpi-${amber > 0 ? 'warning' : 'success'}"><div class="kt-kpi-label">Check staffing</div><div class="kt-kpi-value">${amber}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Rooms tracked</div><div class="kt-kpi-value">${rooms.length}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">In ratio</div><div class="kt-kpi-value">${okN}</div></div>
      </div>
      ${centres.map(centreBlock).join('') || '<div class="kt-card" style="text-align:center;padding:40px;color:#64748B;">No rooms tracked.</div>'}
    </div>`;
    main.querySelectorAll('button[data-rr-settings]').forEach(b => b.addEventListener('click', () => {
      const c = centres.find(x => String(x.id) === b.getAttribute('data-rr-settings'));
      if (c) openRatioSettings(main, c, rooms.filter(x => x.centre_id === c.id), groups);
    }));
    paintRatioAlerts(main, r.alerts);
  }

  /* BREACH ALERTS (2026-10-30): every 15 minutes, 6 AM-7 PM, a room that goes out of ratio alerts the people below once,
     and again when it is back in ratio (kiddietrac:check-ratios). A one-line summary under the banner; admins edit it. */
  function paintRatioAlerts(main, a) {
    if (!a) return;
    const kpi = main.querySelector('.kt-kpi-grid');
    if (!kpi) return;
    const who = [a.notify_educators && 'educators in the room', a.notify_directors && 'centre directors', a.notify_admins && 'agency admins'].filter(Boolean);
    const line = document.createElement('div');
    line.setAttribute('data-rr-alerts', '1');
    line.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0 14px;font-size:12.5px;color:#475569;';
    line.innerHTML = '<span>🔔 ' + (a.enabled
      ? 'Breach alerts on: ' + esc(who.length ? who.join(', ') : 'nobody selected') + ' are told when a room goes out of ratio, and when it is back.'
      : 'Breach alerts are off: nobody is told when a room goes out of ratio.') + '</span>'
      + (a.can_edit ? '<button type="button" class="kt-btn kt-btn-ghost" data-rr-alerts-edit style="height:28px;padding:0 10px;font-size:12px;">Change</button>' : '');
    const old = main.querySelector('[data-rr-alerts]'); if (old) old.remove();
    kpi.parentNode.insertBefore(line, kpi.nextSibling);
    const b = line.querySelector('[data-rr-alerts-edit]');
    if (b) b.addEventListener('click', () => openRatioAlerts(main, a));
  }
  function openRatioAlerts(main, a) {
    const Shell = window.KT && KT.Shell;
    const row = (k, label, hint) => `<label style="display:flex;align-items:flex-start;gap:10px;padding:6px 0;font-size:13px;"><input type="checkbox" data-k="${k}"${a[k] ? ' checked' : ''}><span><b style="font-weight:600;">${label}</b>${hint ? `<span style="display:block;font-size:11.5px;color:#64748B;">${hint}</span>` : ''}</span></label>`;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:min(460px,90vw);';
    wrap.innerHTML = row('enabled', 'Alert when a room goes out of ratio', 'Checked every 15 minutes from 6 AM to 7 PM. One alert per breach, and a note when the room is back in ratio.')
      + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:10px 0 2px;">Who is told</div>'
      + row('notify_educators', 'Educators in the room', 'On a shift in the room, or clocked in and assigned to it. If nobody is, everyone clocked in at the centre.')
      + row('notify_directors', 'Centre directors', 'The directors of that centre. A centre with no director: the agency admins instead.')
      + row('notify_admins', 'Agency admins', 'Every agency admin, for every centre.');
    Shell.Modal.open({ title: '🔔 Ratio breach alerts', body: wrap, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: async () => {
      const body = {}; wrap.querySelectorAll('input[data-k]').forEach(i => { body[i.getAttribute('data-k')] = i.checked; });
      const res = await Api.put('/operations/ratio-alerts', body);
      if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Ratio alerts saved', 'success');
      paintRatioAlerts(main && main.isConnected ? main : (document.getElementById('appMain') || main), res.alerts);
    } }] });
  }

  // Ontario: licence type, program hours, reduced-ratio windows (s.8(4)), outdoor play, room age groups.
  function openRatioSettings(main, c, rooms, groups) {
    const s = c.settings || {};
    const Shell = window.KT && KT.Shell;
    const lbl = 'display:block;font-size:12.5px;font-weight:700;margin:10px 0 4px;';
    const inp = 'height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:7px;box-sizing:border-box;';
    const gOpts = (cur) => Object.keys(groups).map(k => `<option value="${k}"${k === cur ? ' selected' : ''}>${esc(groups[k])}</option>`).join('');
    const derive = (x) => x.licensed_age_group || (x.age_group === 'school_age' ? 'primary_junior' : x.age_group);
    const wrap = document.createElement('div');
    wrap.innerHTML = `
      <label style="${lbl}">This location is a</label>
      <select data-f="license_type" style="${inp}width:100%;">
        <option value="">Not set — use each room’s own ratio</option>
        <option value="on_centre"${c.license_type === 'on_centre' ? ' selected' : ''}>Licensed child care centre — O. Reg. 137/15 rules</option>
        <option value="on_hcc_home"${c.license_type === 'on_hcc_home' ? ' selected' : ''}>Home child care (agency-affiliated home)</option>
        <option value="on_hcc_agency"${c.license_type === 'on_hcc_agency' ? ' selected' : ''}>Home child care agency</option>
      </select>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <div><label style="${lbl}">Program starts</label><input data-f="program_start" type="time" value="${esc(s.program_start || '07:00')}" style="${inp}width:100%;"></div>
        <div><label style="${lbl}">Program ends</label><input data-f="program_end" type="time" value="${esc(s.program_end || '18:00')}" style="${inp}width:100%;"></div>
      </div>
      <label style="${lbl}display:flex;align-items:center;gap:8px;"><input data-f="reduced_enabled" type="checkbox"${s.reduced_enabled !== false ? ' checked' : ''}> Use reduced ratios at arrival, departure and rest (s.8(4))</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <div><label style="${lbl}">Arrival window (min)</label><input data-f="arrival_minutes" type="number" min="0" max="90" value="${s.arrival_minutes != null ? s.arrival_minutes : 90}" style="${inp}width:100%;"></div>
        <div><label style="${lbl}">Departure window (min)</label><input data-f="departure_minutes" type="number" min="0" max="60" value="${s.departure_minutes != null ? s.departure_minutes : 60}" style="${inp}width:100%;"></div>
        <div><label style="${lbl}">Rest period starts</label><input data-f="rest_start" type="time" value="${esc(s.rest_start || '')}" style="${inp}width:100%;"></div>
        <div><label style="${lbl}">Rest period (min, max 120)</label><input data-f="rest_minutes" type="number" min="0" max="120" value="${s.rest_minutes || 0}" style="${inp}width:100%;"></div>
      </div>
      <div style="font-size:11.5px;color:#64748B;margin-top:6px;line-height:1.4;">Programs of 6 hours or more: arrival up to 90 min after opening, departure up to 60 min before closing; shorter programs 30 and 30. Rest period applies to toddler and preschool groups only. Never for infants, junior school age or family age groups.</div>
      <label style="${lbl}">Outdoor play times (always full ratio)</label>
      <input data-f="outdoor" placeholder="e.g. 10:00-11:00, 15:30-16:30" value="${esc((s.outdoor || []).map(w => w.from + '-' + w.to).join(', '))}" style="${inp}width:100%;">
      <label style="${lbl}">Licensed age group of each room</label>
      ${rooms.map(x => `<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><span style="flex:1;min-width:0;font-size:13px;">${esc(x.room_name)}</span>
        <select data-room="${x.room_id}" style="${inp}">${gOpts(derive(x))}</select></div>`).join('') || '<div style="font-size:12px;color:#64748B;">No active rooms.</div>'}
      <div data-err style="color:#B91C1C;font-size:12.5px;min-height:16px;margin-top:6px;"></div>`;
    const f = (k) => wrap.querySelector(`[data-f="${k}"]`);
    const save = async () => {
      const err = wrap.querySelector('[data-err]');
      const outdoor = [];
      const raw = f('outdoor').value.trim();
      if (raw) {
        for (const part of raw.split(',')) {
          const m = part.trim().match(/^(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})$/);
          if (!m) { err.textContent = 'Outdoor play times look like 10:00-11:00, separated by commas.'; return false; }
          const p2 = (h) => String(h).padStart(2, '0');
          outdoor.push({ from: p2(m[1]) + ':' + m[2], to: p2(m[3]) + ':' + m[4] });
        }
      }
      const body = {
        license_type: f('license_type').value || null,
        reduced_enabled: f('reduced_enabled').checked,
        program_start: f('program_start').value, program_end: f('program_end').value,
        arrival_minutes: parseInt(f('arrival_minutes').value || '0', 10),
        departure_minutes: parseInt(f('departure_minutes').value || '0', 10),
        rest_start: f('rest_start').value || null,
        rest_minutes: parseInt(f('rest_minutes').value || '0', 10),
        outdoor,
        rooms: Array.from(wrap.querySelectorAll('select[data-room]')).map(sel => ({ id: parseInt(sel.getAttribute('data-room'), 10), licensed_age_group: sel.value || null })),
      };
      try {
        await Api.put('/operations/ratio-settings/' + c.id, body);
      } catch (e) { err.textContent = (e && e.message) || 'Could not save.'; return false; }
      if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Ratio settings saved', 'success');
      renderRoomRatios(main && main.isConnected ? main : (document.getElementById('appMain') || main));
      return true;
    };
    Shell.Modal.open({ title: 'Ratio settings — ' + c.name, body: wrap, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: save }] });
  }

  // ============================ Vacation holds =================================
  async function renderVacationHolds(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/billing/vacation-holds').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🏖 Vacation holds</h2>
        <p>Pause tuition while a family is away. Approve or deny requests with an optional credit amount.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-primary" id="vh-add">+ Add hold</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Family</th><th>Centre</th><th>Dates</th><th>Reason</th><th>Status</th><th>Credit</th><th></th></tr></thead>
          <tbody>${(r.data || []).map(h => `<tr>
            <td><strong>${esc(h.family_name)}</strong></td>
            <td>${esc(h.centre_name || '')}</td>
            <td>${fmtDate(h.start_date)} – ${fmtDate(h.end_date)}</td>
            <td>${esc(h.reason || '')}</td>
            <td><span class="kt-pill ${h.status === 'approved' ? 'kt-pill-success' : h.status === 'denied' ? 'kt-pill-danger' : 'kt-pill-warning'}">${esc(h.status)}</span></td>
            <td>${h.credit_amount ? fmtMoney(h.credit_amount) : '—'}</td>
            <td>${h.status === 'requested' ? `<button class="kt-btn kt-btn-success" data-decide="${h.id}" data-action="approved">Approve</button> <button class="kt-btn kt-btn-danger" data-decide="${h.id}" data-action="denied" style="margin-left:6px;">Deny</button>` : ''}</td>
          </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;color:#64748B;">No vacation holds.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    main.querySelectorAll('button[data-decide]').forEach(b => b.onclick = async () => {
      /* Cancel means "don't approve" (2026-10-05). It used to approve anyway, with an
         automatic credit — a family's bill changed by a click meant to back out.
         OK with the field blank still approves with the automatic credit. */
      const credit = b.dataset.action === 'approved'
        ? await KT.prompt({ title: 'Approve vacation hold', description: 'Enter a credit amount for the family, or leave it blank to calculate it automatically.', placeholder: 'Credit amount (blank = automatic)', okLabel: 'Approve' })
        : null;
      if (b.dataset.action === 'approved' && credit === null) return;
      await Api.patch(`/billing/vacation-holds/${b.dataset.decide}`, { status: b.dataset.action, credit_amount: credit ? parseFloat(credit) : null });
      renderVacationHolds(main);
    });
    const vhAdd = document.getElementById('vh-add');
    if (vhAdd) vhAdd.onclick = () => openAddHoldModal(main);
  }

  // Staff adds a vacation hold for a family (family picker + dates + reason, dimmed modal).
  async function openAddHoldModal(main) {
    let families = [];
    try { const fr = await Api.get('/admin/families'); families = (fr && fr.families) || (Array.isArray(fr) ? fr : (fr && fr.data) || []); } catch (e) {}
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:24px;box-shadow:0 20px 50px -12px rgba(15,23,42,.4);">
      <h3 style="margin:0 0 14px;color:#0F172A;">🏖 Add vacation hold</h3>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Family</label>
      <select id="vh-family" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;margin-bottom:12px;box-sizing:border-box;">
        <option value="">Select a family…</option>
        ${families.map(f => `<option value="${f.id}">${esc(f.family_name || f.name || ('Family #' + f.id))}</option>`).join('')}
      </select>
      ${families.length ? '' : '<div style="color:#B91C1C;font-size:12.5px;margin:-6px 0 12px;">No families loaded — select an agency first.</div>'}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">From</label><input id="vh-start" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;"></div>
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">To</label><input id="vh-end" type="date" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;"></div>
      </div>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Reason (optional)</label>
      <input id="vh-reason" placeholder="e.g. Family holiday" maxlength="120" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">
      <div id="vh-status" style="font-size:12.5px;min-height:16px;margin-top:8px;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
        <button id="vh-cancel" style="background:#F1F5F9;color:#475569;border:none;border-radius:9px;padding:9px 16px;font-weight:700;cursor:pointer;">Cancel</button>
        <button id="vh-save" class="kt-btn kt-btn-primary">Add hold</button>
      </div>
    </div>`;
    const close = () => m.remove();
    m.addEventListener('click', e => { if (e.target === m) close(); });
    document.body.appendChild(m);
    m.querySelector('#vh-cancel').onclick = close;
    m.querySelector('#vh-save').onclick = async () => {
      const fid = m.querySelector('#vh-family').value;
      const start = m.querySelector('#vh-start').value;
      const end = m.querySelector('#vh-end').value;
      const st = m.querySelector('#vh-status');
      if (!fid || !start || !end) { st.style.color = '#B91C1C'; st.textContent = 'Family and both dates are required.'; return; }
      const btn = m.querySelector('#vh-save'); btn.disabled = true;
      const done = (window.KT && KT.busy) ? KT.busy(btn) : function () {};
      try {
        await Api.post('/billing/vacation-holds/for-family', { family_id: parseInt(fid, 10), start_date: start, end_date: end, reason: m.querySelector('#vh-reason').value || null });
        close();
        renderVacationHolds(main);
      } catch (e) { st.style.color = '#B91C1C'; st.textContent = '✗ ' + (e.message || 'Could not add hold.'); btn.disabled = false; }
      finally { done(); }
    };
  }

  // ============================ Tuition increases =================================
  /* Scheduled -> Notified -> Applied (2026-11-28). The scheduler (billing:tuition-increases) tells families on the
     notice day and writes the new rates on the effective date, in the agency's timezone. Here: a real form with a
     Type picker and a live preview of who is affected and old -> new, edit/delete while Scheduled, a View popup with
     what was sent / changed, and the agency's notice rule. */
  const TI_STATUS = {
    scheduled: ['Scheduled', 'kt-pill-warning'], notified: ['Notified', 'kt-pill-info'],
    applied: ['Applied', 'kt-pill-success'], overdue: ['Overdue', 'kt-pill-danger'],
  };
  const isAgencyAdminUi = () => /\brole-(agency-admin|platform-admin)\b/.test(document.body.className || '');
  const isDirectorUi = () => /\brole-centre-director\b/.test(document.body.className || '');
  const tiAmount = (t) => t.increase_type === 'flat' ? fmtMoney(t.amount) + ' a month' : (+t.amount) + '%';
  const fieldCss = 'width:100%;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;box-sizing:border-box;font:inherit;font-size:13.5px;';
  const labelCss = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin:0 0 4px;';

  async function renderTuitionIncreases(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    let r;
    try { r = await Api.get('/billing/tuition-increases'); }
    catch (e) { main.innerHTML = '<div style="padding:24px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load tuition increases.') + '</div>'; return; }
    const rows = r.data || [];
    main.innerHTML = `<div style="padding:24px;">
      <div class="kt-page-hero">
        <h2>📈 Tuition increases</h2>
        <p>Plan a percent or flat increase. Families are told ${r.notice_days} days before it starts (your agency's rule), and their tuition changes on the date by itself.</p>
        <div class="kt-hero-actions">
          ${isAgencyAdminUi() ? '<button class="kt-btn kt-btn-ghost" id="ti-rules" type="button">⚙ Notice rule</button>' : ''}
          <button class="kt-btn kt-btn-ghost" id="ti-new" type="button">+ Schedule increase</button>
        </div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Effective</th><th>Centre</th><th>Increase</th><th>Families told</th><th>Status</th><th></th></tr></thead>
          <tbody>${rows.map(t => {
            const st = TI_STATUS[t.status] || TI_STATUS.scheduled;
            const told = t.notification_sent_at ? `${t.notified_families} on ${fmtDate(String(t.notification_sent_at))}` : `On ${fmtDate(t.notice_date)} (${t.notify_families_days_before} days before)`;
            return `<tr>
            <td><strong>${fmtDate(t.effective_date)}</strong></td>
            <td>${esc(t.centre_name || 'All centres')}</td>
            <td><strong>${esc(tiAmount(t))}</strong>${t.increase_type === 'flat' ? '' : ' <span style="color:#64748B;">percent</span>'}</td>
            <td>${esc(told)}</td>
            <td><span class="kt-pill ${st[1]}">${st[0]}</span>${t.status === 'applied' ? `<div style="font-size:11.5px;color:#64748B;margin-top:2px;">${t.applied_count} fees changed</div>` : ''}</td>
            <td style="text-align:center;white-space:nowrap;">
              <button type="button" data-ti-view="${t.id}" title="View">View</button>
              ${t.can_edit ? `<button type="button" data-ti-edit="${t.id}" title="Edit">Edit</button>` : ''}
              ${t.can_apply ? `<button type="button" data-ti-apply="${t.id}" title="Apply now">Apply now</button>` : ''}
              ${t.can_delete ? `<button type="button" data-ti-del="${t.id}" title="Delete">Delete</button>` : ''}
            </td>
          </tr>`; }).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;color:#64748B;">No tuition increases scheduled.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    const again = () => renderTuitionIncreases(main.isConnected ? main : (document.getElementById('appMain') || main));
    const byId = (id) => rows.find(x => String(x.id) === String(id));
    main.querySelector('#ti-new').onclick = () => openIncreaseForm(null, r.notice_days, again);
    const rb = main.querySelector('#ti-rules');
    if (rb) rb.onclick = () => openNoticeRule(r.notice_days, again);
    main.querySelectorAll('[data-ti-view]').forEach(b => b.onclick = () => openIncreaseView(b.dataset.tiView));
    main.querySelectorAll('[data-ti-edit]').forEach(b => b.onclick = () => openIncreaseForm(byId(b.dataset.tiEdit), r.notice_days, again));
    main.querySelectorAll('[data-ti-del]').forEach(b => b.onclick = async () => {
      const t = byId(b.dataset.tiDel);
      const ok = await KT.confirm({ title: 'Delete this increase?', description: `The ${tiAmount(t)} increase from ${fmtDate(t.effective_date)} will be removed. Families have ${t.notification_sent_at ? 'already been told; tell them it is cancelled' : 'not been told yet'}.`, okLabel: 'Delete' });
      if (!ok) return;
      try { await Api.delete('/billing/tuition-increases/' + t.id); KT.Dom.toast('Increase deleted', 'success'); again(); }
      catch (e) { KT.Dom.toast((e && e.message) || 'Could not delete the increase.', 'error'); }
    });
    main.querySelectorAll('[data-ti-apply]').forEach(b => b.onclick = async () => {
      const t = byId(b.dataset.tiApply);
      const ok = await KT.confirm({ title: 'Apply this increase now?', description: `Its date (${fmtDate(t.effective_date)}) has passed. ${t.notification_sent_at ? '' : 'Families will be told first, then '}the new tuition is written to their fees from now on. Invoices already raised do not change.`, okLabel: 'Apply now' });
      if (!ok) return;
      try { const x = await Api.post('/billing/tuition-increases/' + t.id + '/apply', {}); KT.Dom.toast(`Applied: ${x.fees_changed} fees changed`, 'success'); again(); }
      catch (e) { KT.Dom.toast((e && e.message) || 'Could not apply the increase.', 'error'); }
    });
  }

  function affectedHtml(a, type) {
    const fams = a.families || [];
    if (!fams.length && !(a.plans || []).length) {
      return '<div style="padding:14px;color:#64748B;font-size:13px;background:#F8FAFC;border-radius:8px;">No families or tuition plans would change: nobody enrolled here has a monthly tuition above $0.</div>';
    }
    const kids = fams.reduce((n, f) => n + f.children.length, 0);
    const rowsHtml = fams.map(f => f.children.map((c, i) => `<tr>
        <td>${i === 0 ? '<strong>' + esc(f.family_name) + '</strong>' + (f.centre_name ? `<div style="font-size:11.5px;color:#64748B;">${esc(f.centre_name)}</div>` : '') : ''}</td>
        <td>${esc(c.name)}</td><td style="text-align:right;">${fmtMoney(c.old)}</td><td style="text-align:right;"><strong>${fmtMoney(c.new)}</strong></td></tr>`).join('')).join('');
    const plans = (a.plans || []).map(p => `<tr><td colspan="2">📋 ${esc(p.name)} <span style="color:#64748B;">(${esc(p.frequency || '')} plan)</span></td><td style="text-align:right;">${fmtMoney(p.old)}</td><td style="text-align:right;"><strong>${fmtMoney(p.new)}</strong></td></tr>`).join('');
    const skipped = (a.skipped_plans || []).length
      ? `<div style="font-size:12px;color:#92400E;margin-top:8px;">A flat amount is added to monthly fees only, so ${a.skipped_plans.length} ${a.skipped_plans.length === 1 ? 'plan' : 'plans'} (${esc(a.skipped_plans.map(p => p.name + ', ' + p.frequency).join('; '))}) will not change.</div>` : '';
    return `<div style="font-size:12.5px;color:#334155;margin:0 0 6px;"><strong>${fams.length}</strong> ${fams.length === 1 ? 'family' : 'families'}, <strong>${kids}</strong> ${kids === 1 ? 'child' : 'children'}${(a.plans || []).length ? `, <strong>${a.plans.length}</strong> tuition ${a.plans.length === 1 ? 'plan' : 'plans'}` : ''}. Monthly tuition, old → new:</div>
      <table style="width:100%;font-size:13px;"><thead><tr><th>Family</th><th>Child / plan</th><th style="text-align:right;">Now</th><th style="text-align:right;">New</th></tr></thead><tbody>${rowsHtml}${plans}</tbody></table>${skipped}`;
  }

  async function openIncreaseForm(existing, noticeDays, after) {
    const Shell = window.KT && KT.Shell;
    const _cr = await Api.get('/admin/centres').catch(() => ({}));
    const centres = (_cr.centres || _cr.data || []).filter(c => !c.deleted_at);
    const opts = (isDirectorUi() ? [] : [{ v: '', l: 'All centres' }]).concat(centres.map(c => ({ v: String(c.id), l: c.name })));
    const cur = existing || { centre_id: null, increase_type: 'percent', amount: '', effective_date: '', notify_families_days_before: noticeDays, notes: '' };
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:min(860px,100%);';
    wrap.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
        <div><label style="${labelCss}" for="ti-centre">Centre</label>
          <select id="ti-centre" style="${fieldCss}">${opts.map(o => `<option value="${esc(o.v)}"${String(cur.centre_id || '') === o.v ? ' selected' : ''}>${esc(o.l)}</option>`).join('')}</select></div>
        <div><label style="${labelCss}" for="ti-eff">New tuition starts on</label>
          <input id="ti-eff" type="date" value="${esc(String(cur.effective_date || '').slice(0, 10))}" style="${fieldCss}"></div>
        <div><span style="${labelCss}">Type</span>
          <div role="radiogroup" style="display:flex;gap:6px;">
            <label style="flex:1;display:flex;gap:6px;align-items:center;border:1px solid #CBD5E1;border-radius:8px;padding:7px 10px;font-size:13px;cursor:pointer;"><input type="radio" name="ti-type" value="percent"${cur.increase_type !== 'flat' ? ' checked' : ''}> Percent (%)</label>
            <label style="flex:1;display:flex;gap:6px;align-items:center;border:1px solid #CBD5E1;border-radius:8px;padding:7px 10px;font-size:13px;cursor:pointer;"><input type="radio" name="ti-type" value="flat"${cur.increase_type === 'flat' ? ' checked' : ''}> Flat amount ($ a month)</label>
          </div></div>
        <div><label style="${labelCss}" for="ti-amt" id="ti-amt-l">Increase</label>
          <input id="ti-amt" type="number" min="0" step="0.01" inputmode="decimal" value="${esc(cur.amount)}" placeholder="e.g. 3.5" style="${fieldCss}"></div>
        <div><label style="${labelCss}" for="ti-days">Tell families this many days before</label>
          <input id="ti-days" type="number" min="0" max="365" step="1" value="${esc(cur.notify_families_days_before)}" style="${fieldCss}"></div>
        <div><label style="${labelCss}" for="ti-notes">Note for families (optional)</label>
          <input id="ti-notes" maxlength="2000" value="${esc(cur.notes || '')}" placeholder="e.g. Our annual cost-of-living adjustment." style="${fieldCss}"></div>
      </div>
      <div id="ti-when" style="font-size:12.5px;margin:12px 0 8px;min-height:16px;"></div>
      <div id="ti-prev" style="border-top:1px solid #E2E8F0;padding-top:10px;"><div style="color:#64748B;font-size:13px;">Fill in the date and the increase to see who is affected.</div></div>
      <div id="ti-err" role="alert" style="color:#B91C1C;font-size:13px;min-height:16px;margin-top:8px;"></div>`;
    const q = (s) => wrap.querySelector(s);
    const body = () => ({
      centre_id: q('#ti-centre').value ? parseInt(q('#ti-centre').value, 10) : null,
      effective_date: q('#ti-eff').value,
      increase_type: (wrap.querySelector('input[name="ti-type"]:checked') || {}).value || 'percent',
      amount: q('#ti-amt').value === '' ? null : parseFloat(q('#ti-amt').value),
      notify_families_days_before: q('#ti-days').value === '' ? null : parseInt(q('#ti-days').value, 10),
      notes: q('#ti-notes').value.trim() || null,
    });
    // Client-side checks with the same wording as the server's.
    const check = (b) => {
      if (!b.effective_date) return 'Choose the date the new tuition starts.';
      if (b.amount == null || isNaN(b.amount)) return 'Enter the increase.';
      if (b.amount <= 0) return 'The increase must be more than zero.';
      if (b.increase_type === 'percent' && b.amount > 50) return 'A percent increase can be at most 50%. Check the amount, or choose Flat amount.';
      if (b.increase_type === 'flat' && b.amount > 5000) return 'A flat increase can be at most $5,000 a month. Check the amount.';
      if (b.notify_families_days_before != null && (b.notify_families_days_before < 0 || b.notify_families_days_before > 365)) return 'Notice must be between 0 and 365 days.';
      const today = (KT.agencyToday && KT.agencyToday()) || '';
      if (today && b.effective_date <= today) return 'The new tuition must start after today.';
      return '';
    };
    let seq = 0, timer = null;
    const preview = () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const b = body(); const why = check(b); const my = ++seq;
        const amtL = q('#ti-amt-l'); if (amtL) amtL.textContent = b.increase_type === 'flat' ? 'Increase ($ a month)' : 'Increase (%)';
        if (why) { q('#ti-err').textContent = (b.effective_date && b.amount != null) ? why : ''; q('#ti-when').textContent = ''; return; }
        q('#ti-err').textContent = '';
        q('#ti-prev').innerHTML = '<div style="color:#64748B;font-size:13px;">Working out who is affected…</div>';
        try {
          const p = (await Api.post('/billing/tuition-increases/preview', b)).data;
          if (my !== seq || !wrap.isConnected) return;
          q('#ti-when').innerHTML = p.short_notice
            ? `<span style="color:#92400E;">⚠ ${p.notice_days} days' notice would mean telling families on ${fmtDate(p.notice_date)}, which has passed. They will be told as soon as you save (${p.days_of_notice} days before).</span>`
            : `<span style="color:#334155;">📣 Families are told on <strong>${fmtDate(p.notice_date)}</strong>; the new tuition starts on <strong>${fmtDate(b.effective_date)}</strong>.</span>`;
          q('#ti-prev').innerHTML = affectedHtml(p, b.increase_type);
        } catch (e) {
          if (my !== seq || !wrap.isConnected) return;
          q('#ti-prev').innerHTML = '';
          q('#ti-err').textContent = (e && e.message) || 'Could not work out who is affected.';
        }
      }, 350);
    };
    wrap.addEventListener('input', preview);
    wrap.addEventListener('change', preview);
    Shell.Modal.open({
      title: existing ? '📈 Edit tuition increase' : '📈 Schedule a tuition increase', body: wrap, large: true,
      actions: [{ label: 'Cancel' }, { label: existing ? 'Save changes' : 'Schedule increase', primary: true, busyLabel: 'Saving…', handler: async () => {
        const b = body(); const why = check(b);
        if (why) { q('#ti-err').textContent = why; return false; }
        try {
          if (existing) await Api.patch('/billing/tuition-increases/' + existing.id, b);
          else await Api.post('/billing/tuition-increases', b);
        } catch (e) { q('#ti-err').textContent = (e && e.message) || 'Could not save the increase.'; return false; }
        KT.Dom.toast(existing ? 'Increase updated' : 'Increase scheduled', 'success');
        after();
        return true;
      } }],
    });
    if (existing) preview();
  }

  async function openIncreaseView(id) {
    const Shell = window.KT && KT.Shell;
    let r;
    try { r = await Api.get('/billing/tuition-increases/' + id); }
    catch (e) { KT.Dom.toast((e && e.message) || 'Could not open the increase.', 'error'); return; }
    const t = r.data, a = r.affected || {};
    const st = TI_STATUS[t.status] || TI_STATUS.scheduled;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:min(860px,100%);';
    const fact = (k, v) => `<div><div style="font-size:11.5px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.04em;">${k}</div><div style="font-size:14px;margin-top:2px;">${v}</div></div>`;
    wrap.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
        ${fact('Status', `<span class="kt-pill ${st[1]}">${st[0]}</span>`)}
        ${fact('Centre', esc(t.centre_name || 'All centres'))}
        ${fact('Increase', esc(tiAmount(t)))}
        ${fact('Starts', fmtDate(t.effective_date))}
        ${fact('Families told', t.notification_sent_at ? `${t.notified_families} on ${fmtDate(String(t.notification_sent_at))}` : 'On ' + fmtDate(t.notice_date))}
        ${t.applied_at ? fact('Applied', `${t.applied_count} fees on ${fmtDate(String(t.applied_at))}`) : ''}
      </div>
      ${t.notes ? `<div style="font-size:13px;color:#334155;background:#F8FAFC;border-radius:8px;padding:8px 10px;margin-bottom:12px;">${esc(t.notes)}</div>` : ''}
      <div style="font-size:12px;color:#64748B;margin-bottom:6px;">${a.from_record ? (t.applied_at ? 'What was changed:' : 'What families were told:') : 'Who it will reach, from today’s fees:'}</div>
      ${affectedHtml(a, t.increase_type)}`;
    Shell.Modal.open({ title: '📈 Tuition increase', body: wrap, large: true, actions: [{ label: 'Close' }] });
  }

  function openNoticeRule(days, after) {
    const Shell = window.KT && KT.Shell;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:min(460px,90vw);';
    wrap.innerHTML = `<label style="${labelCss}" for="ti-rule-days">Tell families this many days before a tuition increase</label>
      <input id="ti-rule-days" type="number" min="0" max="365" step="1" value="${esc(days)}" style="${fieldCss}">
      <div style="font-size:12px;color:#64748B;margin-top:6px;">The starting value for each new increase (you can change it on the increase). Increases already scheduled keep their own.</div>
      <div id="ti-rule-err" role="alert" style="color:#B91C1C;font-size:13px;min-height:16px;margin-top:8px;"></div>`;
    Shell.Modal.open({ title: '⚙ Tuition increase notice', body: wrap, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: async () => {
      const v = parseInt(wrap.querySelector('#ti-rule-days').value, 10);
      if (isNaN(v) || v < 0 || v > 365) { wrap.querySelector('#ti-rule-err').textContent = 'Notice must be between 0 and 365 days.'; return false; }
      try { await Api.patch('/admin/billing-setup', { tuition_notice_days: v }); }
      catch (e) { wrap.querySelector('#ti-rule-err').textContent = (e && e.message) || 'Could not save the rule.'; return false; }
      KT.Dom.toast('Notice rule saved', 'success'); after(); return true;
    } }] });
  }

  // ============================ Re-enrollment campaigns =================================
  async function renderReenrollment(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/engagement/reenrollment').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🔁 Re-enrollment campaigns</h2>
        <p>Confirm next term's enrolment with a notification + portal action for every guardian.</p>
        <div class="kt-hero-actions"><button class="kt-btn kt-btn-ghost" id="re-new">+ Launch campaign</button></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Campaign</th><th>Centre</th><th>Term</th><th>Deadline</th><th>Status</th><th>Invited</th><th>Responded</th><th>Renewed</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${esc(c.campaign_name)}</strong></td>
            <td>${esc(c.centre_name || 'All centres')}</td>
            <td>${esc(c.target_term)}</td>
            <td>${fmtDate(c.deadline)}</td>
            <td><span class="kt-pill kt-pill-info">${esc(c.status)}</span></td>
            <td>${c.total_invited}</td>
            <td>${c.total_responded}</td>
            <td><strong style="color:#15803D;">${c.total_renewed}</strong></td>
          </tr>`).join('') || '<tr><td colspan="8" style="text-align:center;padding:40px;color:#64748B;">No campaigns yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
    document.getElementById('re-new').onclick = async () => {
      /* Which centre (2026-11-27): the server requires one for a director ("Choose one of your centres") but this
         dialog never asked, so a director could not launch a campaign at all. Same list as Tuition increases. */
      const _cr = await Api.get('/admin/centres').catch(() => ({}));
      const centres = (_cr.centres || _cr.data || []).filter(c => !c.deleted_at);
      const isDirector = /\brole-centre-director\b/.test(document.body.className || '');
      const centreOpts = (isDirector ? [] : [{ value: '', label: 'All centres' }])
        .concat(centres.map(c => ({ value: String(c.id), label: c.name })));
      // One dialog for what used to be three prompts in a row.
      const v = await KT.prompt({
        title: 'Launch a re-enrolment campaign',
        description: 'Every guardian is notified and asked to confirm next term in the portal.',
        fields: [
          { key: 'centre', label: 'Centre', type: 'select', options: centreOpts, value: centreOpts.length ? centreOpts[0].value : '' },
          { key: 'name', label: 'Campaign name', value: 'Fall 2026 re-enrolment' },
          { key: 'term', label: 'Target term', value: 'Fall 2026' },
          { key: 'deadline', label: 'Deadline', type: 'date' },
        ],
        okLabel: 'Launch campaign',
      });
      if (!v) return;
      const name = v.name, term = v.term, deadline = v.deadline;
      if (!name || !term || !deadline) return;
      Api.post('/engagement/reenrollment', { centre_id: v.centre ? parseInt(v.centre, 10) : null, campaign_name: name, target_term: term, deadline })
        .then(r => { KT.Dom.toast(`Invited ${r.invited} children`, 'success'); renderReenrollment(main); })
        .catch(e => KT.Dom.toast((e && e.message) || 'Could not launch the campaign.', 'error'));
    };
  }

  // ============================ Engagement scores =================================
  async function renderEngagement(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Computing…</div>';
    const r = await Api.get('/engagement/scores').catch(() => ({ data: [] }));
    const rows = r.data || [];
    const eng = rows.filter(x => x.bucket === 'engaged').length;
    const mod = rows.filter(x => x.bucket === 'moderate').length;
    const low = rows.length - eng - mod;
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>💚 Family engagement</h2>
        <p>How actively families interact with the portal — messages, feedback, forms, observation views, custom form submissions over 30 days.</p>
      </div>
      <div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Engaged</div><div class="kt-kpi-value">${eng}</div></div>
        <div class="kt-kpi kt-kpi-warning"><div class="kt-kpi-label">Moderate</div><div class="kt-kpi-value">${mod}</div></div>
        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Low</div><div class="kt-kpi-value">${low}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Total</div><div class="kt-kpi-value">${rows.length}</div></div>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Family</th><th>Score</th><th>Bucket</th><th>Signals</th></tr></thead>
          <tbody>${rows.map(r => {
            const pill = r.bucket === 'engaged' ? 'kt-pill-success' : r.bucket === 'moderate' ? 'kt-pill-warning' : 'kt-pill-danger';
            const colour = r.bucket === 'engaged' ? '#10B981' : r.bucket === 'moderate' ? '#F59E0B' : '#EF4444';
            return `<tr>
              <td><strong>${esc(r.family_name)}</strong></td>
              <td><div style="display:flex;align-items:center;gap:10px;"><div style="background:#F1F5F9;height:8px;width:100px;border-radius:4px;overflow:hidden;"><div style="background:${colour};height:100%;width:${Math.max(2, r.score)}%;"></div></div><strong>${r.score}</strong></div></td>
              <td><span class="kt-pill ${pill}">${esc(r.bucket)}</span></td>
              <td style="color:#475569;font-size:13px;">${(r.signals || []).join(' · ') || '—'}</td>
            </tr>`;
          }).join('') || '<tr><td colspan="4" style="text-align:center;padding:40px;color:#64748B;">No engagement data yet.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  // ============================ NPS summary =================================
  async function renderNps(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/engagement/nps').catch(() => ({ data: [], summary: {} }));
    const s = r.summary || {};
    const npsScore = s.nps || 0;
    const npsColour = npsScore >= 50 ? '#10B981' : npsScore >= 0 ? '#F59E0B' : '#EF4444';
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📊 NPS — Net Promoter Score</h2>
        <p>${s.count || 0} responses in the last 12 months.</p>
      </div>
      <div class="kt-card" style="text-align:center;padding:36px;">
        <div style="font-size:14px;color:#64748B;font-weight:700;letter-spacing:2px;text-transform:uppercase;">NPS</div>
        <div style="font-size:72px;font-weight:800;color:${npsColour};line-height:1;margin:12px 0;">${npsScore}</div>
        <div style="display:flex;justify-content:center;gap:20px;margin-top:14px;">
          <div><div style="color:#10B981;font-weight:700;font-size:22px;">${s.promoters || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">PROMOTERS (9-10)</div></div>
          <div><div style="color:#64748B;font-weight:700;font-size:22px;">${s.passive || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">PASSIVE (7-8)</div></div>
          <div><div style="color:#EF4444;font-weight:700;font-size:22px;">${s.detractors || 0}</div><div style="font-size:12px;color:#64748B;font-weight:600;">DETRACTORS (0-6)</div></div>
        </div>
      </div>
      <div class="kt-card">
        <h3 style="margin:0 0 14px;color:#0F172A;font-size:16px;font-weight:700;">Recent comments</h3>
        <div data-kt-list="1">${(r.data || []).slice(0, 30).map(n => `<div style="padding:12px 0;border-bottom:1px solid #F1F5F9;">
          <div style="font-size:13px;color:#64748B;"><strong style="color:${n.score >= 9 ? '#10B981' : n.score >= 7 ? '#F59E0B' : '#EF4444'};">${n.score}/10</strong> · ${fmtDate(n.created_at)}</div>
          <div style="font-size:14px;color:#0F172A;margin-top:4px;">${esc(n.comment || '')}</div>
        </div>`).join('') || '<div style="text-align:center;padding:30px;color:#64748B;">No responses yet.</div>'}</div>
      </div>
    </div>`;
  }

  // ============================ E-sign documents =================================
  async function renderSignedDocs(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const isGuardian = /\brole-guardian\b/.test(document.body.className || '');
    const [sr, pr] = await Promise.all([
      Api.get('/engagement/signed-documents').catch(() => ({ data: [] })),
      isGuardian ? Api.get('/parent/edocuments').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]);
    const rows = sr.data || [];
    const pending = ((pr.data || pr.documents || [])).filter(d => !d.signed);

    // Pending signatures FIRST (parents only) — each card taps through to the
    // e-signature flow (#doc-workflows) with a clear Sign action.
    const pendingHtml = pending.length ? `
      <div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#B91C1C;margin:0 2px 10px;">⚠ Awaiting your signature (${pending.length})</div>
      ${pending.map(d => `<a href="#edocuments" style="display:flex;align-items:center;gap:12px;text-decoration:none;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:14px;padding:13px 15px;margin-bottom:10px;">
        <span style="width:40px;height:40px;flex-shrink:0;border-radius:11px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;font-size:20px;">📝</span>
        <span style="flex:1;min-width:0;"><span style="display:block;font-weight:800;color:#0F172A;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(d.title || d.name || d.document_name || 'Document')}</span><span style="display:block;font-size:12.5px;color:#B91C1C;margin-top:2px;">Tap to review &amp; sign</span></span>
        <span style="flex-shrink:0;background:#DC2626;color:#fff;font-size:13px;font-weight:800;padding:9px 18px;border-radius:11px;">Sign</span>
      </a>`).join('')}
      <div style="height:8px;"></div>` : '';

    /* A TABLE, not a stack of cards.

       Every signed document carries the same four facts — what it is, who signed it,
       when, and how to open it — and a card repeats the labels for each one while
       aligning none of them. Once a family has more than a handful, "which of these did
       my partner sign in March" means reading every card in full. In columns the eye
       runs down one axis, and the global table tools come free with it: the search box
       and the column sort both attach themselves to any #appMain table, so this gains
       both without a line of code here.

       data-kt-paginate asks the shared pager for 25 rows a page. NOT data-kt-list —
       that primitive is for card lists and mangles a table.
       (Anthony, 2026-09-09) */
    const th = (t, extra) => `<th style="text-align:left;padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;${extra || ''}">${t}</th>`;
    const td = (v, extra) => `<td style="padding:11px 14px;border-top:1px solid #F1F5F9;${extra || ''}">${v}</td>`;

    const signedHtml = rows.length ? `
      <div style="overflow-x:auto;background:#fff;border:1px solid #E7EDF3;border-radius:14px;">
        <table data-kt-paginate="25" style="width:100%;border-collapse:collapse;font-size:13.5px;">
          <thead><tr style="background:#F8FAFC;">
            ${th('Document')}${th('Signed by')}${th('Signed (agency time)')}${th('', 'text-align:right;')}
          </tr></thead>
          <tbody>
            ${rows.map((s, i) => `<tr>
              ${td(`<span style="display:inline-flex;align-items:center;gap:9px;min-width:0;">
                      <span style="width:26px;height:26px;flex:0 0 auto;border-radius:8px;background:#ECFDF5;display:inline-flex;align-items:center;justify-content:center;font-size:14px;">✅</span>
                      <span style="font-weight:700;color:#0F172A;">${esc(sdTypeLabel(s.document_type))}</span>
                    </span>`)}
              ${td(s.signer_name ? esc(s.signer_name) : '<span style="color:#CBD5E1;">—</span>', 'color:#334155;')}
              ${td(esc(fmtStamp(s.signed_at)), 'color:#475569;white-space:nowrap;')}
              ${td(`<span style="display:inline-flex;gap:6px;justify-content:flex-end;">${_sdActions(s, i)}</span>`, 'text-align:right;white-space:nowrap;')}
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`
      : '<div style="text-align:center;padding:34px 16px;color:#64748B;background:#fff;border:1px dashed #CBD5E1;border-radius:14px;">No signed documents yet.</div>';

    main.innerHTML = `<div style="padding:18px 14px;max-width:900px;margin:0 auto;">
      <div class="kt-page-hero"><h2>📄 Documents</h2><p>Documents awaiting your signature, and your completed, audit-trailed signatures.</p></div>
      ${pendingHtml}
      <div style="font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:2px 2px 10px;">Signed &amp; on file</div>
      <div>${signedHtml}</div>
    </div>`;
    main.querySelectorAll('.kt-sd-view').forEach(b => { b.onclick = (e) => { e.preventDefault(); viewSignedDoc(rows[+b.dataset.i]); }; });
    main.querySelectorAll('.kt-sd-dl').forEach(b => { b.onclick = (e) => { e.preventDefault(); downloadSignedDoc(rows[+b.dataset.i]); }; });
    /* Collapse the per-row View/Download icons into the standard ⋮ kebab — the same one
       every other row in the portal uses, so a signed document behaves like a family row
       or a conversation row rather than growing its own pair of buttons.

       Which is why this table must NOT carry data-kt-no-kebab: that attribute opts a
       table OUT of the sweep, and setting it here would have left two loose icons per
       row. (Anthony, 2026-09-09) */
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  // Row action buttons — View (always) + Download (only when a server file is stored).
  const _SD_BTN_STYLE = 'width:36px;height:36px;min-height:0;box-sizing:border-box;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid #CFE3FF;border-radius:9px;background:#EFF6FF;cursor:pointer;color:#1D4ED8;';
  const _SD_EYE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const _SD_DL = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
  function _sdActions(s, i) {
    const hasFile = sdHasFile(s);
    let h = '<button class="kt-sd-view kt-icon-tip" data-i="' + i + '" title="View document" data-kttip="View document" aria-label="View document" style="' + _SD_BTN_STYLE + '">' + _SD_EYE + '</button>';
    if (hasFile) h += '<button class="kt-sd-dl kt-icon-tip" data-i="' + i + '" title="Download" data-kttip="Download" aria-label="Download" style="margin-left:6px;' + _SD_BTN_STYLE + '">' + _SD_DL + '</button>';
    return h;
  }

  // Download the signed file with a datestamped name (Type-Signer-YYYY-MM-DD.ext). Uses
  // the authed API endpoint (Bearer + active-agency) → blob → save, so the browser keeps
  // the server-provided filename regardless of the cross-origin /storage host.
  function downloadSignedDoc(s) {
    if (!s || !s.id) return;
    const base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    const tok = (window.KT && KT.Auth && KT.Auth.token && KT.Auth.token()) || localStorage.getItem('kt_token') || '';
    const hdrs = { 'Authorization': 'Bearer ' + tok };
    try { const a = sessionStorage.getItem('kt_active_agency_id'); if (a) hdrs['X-Active-Agency-Id'] = a; } catch (e) {}
    fetch(base + '/engagement/signed-documents/' + s.id + '/download', { headers: hdrs })
      .then(r => {
        if (!r.ok) throw new Error(r.status === 404 ? 'No downloadable file for this signature.' : 'Download failed');
        const disp = r.headers.get('Content-Disposition') || '';
        const m = disp.match(/filename="?([^"]+)"?/);
        const fname = m ? m[1] : ('signed-document-' + s.id);
        return r.blob().then(b => ({ b: b, fname: fname }));
      })
      .then(o => {
        const url = URL.createObjectURL(o.b);
        const a = document.createElement('a');
        a.href = url; a.download = o.fname; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
      })
      .catch(e => { if (window.KT && KT.toast) KT.toast('⚠️', 'Could not download', e.message || '', '#B91C1C'); });
  }

  // The API HOST (not the /api/v1 base) — stored documents & signature images are
  // served from https://api.kiddietrac.com/storage/… , not under /api/v1.
  function esignApiHost() {
    const b = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return b.replace(/\/api\/v1\/?$/, '');
  }
  // Open the signed artifact. NDA/agreement rows carry doc_file_url (the full signed
  // PDF/HTML) → open it directly. Handbook / ad-hoc rows have only a signature image
  // + audit metadata → show that in a modal (there is no stored document body).
  function viewSignedDoc(s) {
    if (!s) return;
    if (s.doc_file_url) {
      var _u = sdAbs(s.doc_file_url);
      if (window.KT && KT.viewDocument) { KT.viewDocument(_u, { title: sdTypeLabel(s.document_type), label: 'Signed document' }); return; }
      window.open(_u, '_blank', 'noopener'); return;
    }
    /* The signature image, by the same rule. `charAt(0) === '/'` was the whole test, so
       an absolute signed URL fell to the empty string and the dialog told the reader
       "no downloadable document is stored" about a file that is stored. */
    const src = sdAbs(s.signature_data);
    const m = document.createElement('div');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = '<div style="background:#fff;padding:24px;border-radius:14px;max-width:520px;width:100%;">'
      + '<h3 style="margin:0 0 4px;color:#0F172A;">✍ ' + esc(sdTypeLabel(s.document_type)) + '</h3>'
      /* Signature stamp (2026-11-27): Signed date at time zone / IP / approximate location, built by the server. */
      + '<p style="margin:0 0 14px;color:#64748B;font-size:13px;">Signed by ' + esc(s.signer_name || '') + ((s.stamp && window.KT && KT.sigStampHtml) ? '' : ' · ' + fmtStamp(s.signed_at)) + '</p>'
      + (src ? '<div style="border:1px solid #E2E8F0;border-radius:8px;padding:10px;text-align:center;background:#fff;"><img src="' + src + '" alt="Signature" style="max-width:100%;max-height:200px;"></div>'
             : '<p style="color:#64748B;">No downloadable document is stored for this signature — only the audit record below.</p>')
      + ((s.stamp && window.KT && KT.sigStampHtml) ? KT.sigStampHtml(s.stamp, 'margin-top:10px;font-size:12px;line-height:1.5;color:#64748B') : '')
      + '<div style="margin-top:12px;font-size:12px;color:#64748B;font-family:ui-monospace,monospace;word-break:break-all;">SHA-256: ' + esc(s.document_hash || '—') + ((s.stamp && window.KT && KT.sigStampHtml) ? '' : '<br>IP: ' + esc(s.ip_address || '—')) + '</div>'
      + '<div style="text-align:right;margin-top:16px;"><button class="kt-btn kt-btn-primary" style="padding:7px 16px;">Close</button></div>'
      + '</div>';
    m.querySelector('button').onclick = () => m.remove();
    m.addEventListener('click', e => { if (e.target === m) m.remove(); });
    document.body.appendChild(m);
  }
  /* openESignModal (its own signature canvas, posting to /engagement/sign-document) was removed
     2026-11-06: nothing called it, and every signature now goes through kt-signature-pad.js. */

  // ============================ Bus routes (transport) =================================
  /* MANAGED HERE (2026-10-16). Anthony: "how do you add bus routes" — you could not: this
     screen only listed routes. Now a route is added, edited, paused and deleted here, and
     each route has its roster of children: stop order, pick-up address (started from the
     family's address), pick-up and drop-off times. House table pattern (CONVENTIONS.md).
     The API confines all of it to the active agency and a director's own centres. */
  const BUS = { main: null, data: null };
  const busT12 = (t) => {
    const m = /^(\d{1,2}):(\d{2})/.exec(String(t || ''));
    if (!m) return '';
    const h = +m[1];
    return ((h % 12) || 12) + ':' + m[2] + (h < 12 ? ' am' : ' pm');
  };
  const busToast = (msg, kind) => { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind || 'success'); };
  const BUS_F = 'display:block;width:100%;box-sizing:border-box;height:38px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;background:#fff;margin-top:4px;';
  const BUS_L = 'display:block;font-size:13px;font-weight:700;color:#334155;';

  async function renderBusRoutes(main) {
    BUS.main = main;
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;color:#64748B;">Loading bus routes…</div>';
    let d;
    try { d = await Api.get('/operations/bus-routes'); }
    catch (e) { main.innerHTML = `<div class="kt-card" style="color:#B91C1C;">${esc((e && e.message) || 'Could not load bus routes.')}</div>`; return; }
    BUS.data = d;
    const rows = d.data || [];
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="kt-hero" style="background:linear-gradient(135deg,#7C2D12 0%,#C2410C 55%,#F59E0B 100%);">
        <div class="kt-hero-greet">🚐 TRANSPORTATION</div><h1>Bus routes</h1>
        <div class="kt-hero-sub">Set up each route with its centre, driver and vehicle, then add the children who ride it — in stop order, with their pick-up address and times.</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0 12px;">
        <span style="font-size:13px;color:#64748B;">${rows.length} route${rows.length === 1 ? '' : 's'} · ${rows.reduce((n, r) => n + (r.children_count || 0), 0)} children riding</span>
        <span style="flex:1"></span>
        <button type="button" id="bus-add" class="btn btn-primary" style="height:34px;">+ Add route</button>
      </div>
      <div id="bus-list"></div>`;
    main.innerHTML = '';
    main.appendChild(box);
    box.querySelector('#bus-add').onclick = () => openBusRoute(null);
    const host = box.querySelector('#bus-list');
    if (!rows.length) {
      host.innerHTML = `<div class="kt-card" style="color:#475569;font-size:13.5px;line-height:1.6;">
        <b>No routes yet.</b> Press <b>+ Add route</b>, give it a name (e.g. “Morning — North loop”), pick the centre, and optionally a driver and vehicle.
        Then open the route’s <b>Children</b> to add who rides it.${(d.centres || []).length ? '' : '<br><span style="color:#B45309;">You have no open centres to add a route to.</span>'}</div>`;
      return;
    }
    host.innerHTML = '<div class="kt-card"><table data-kt-no-bulk><thead><tr><th>Route</th><th>Centre</th><th>Driver</th><th>Vehicle</th><th>Riders</th><th>Status</th><th></th></tr></thead><tbody>'
      + rows.map(b => `<tr>
          <td><strong>${esc(b.route_name)}</strong>${b.notes ? `<div style="font-size:12px;color:#64748B;white-space:pre-wrap;max-width:320px;">📝 ${esc(b.notes)}</div>` : ''}</td>
          <td>${esc(b.centre_name || '—')}</td>
          <td>${esc(b.driver_name || '—')}</td>
          <td>${esc(b.vehicle_label || '—')}</td>
          <td>${b.children_count || 0}</td>
          <td><span class="kt-pill ${b.active ? 'kt-pill-success' : 'kt-pill-warning'}">${b.active ? 'Active' : 'Paused'}</span></td>
          <td style="text-align:right;white-space:nowrap;">
            <button type="button" class="btn btn-secondary btn-sm" data-kids="${b.id}">🧒 Children</button>
            <button type="button" class="btn btn-secondary btn-sm" data-edit="${b.id}">✏️ Edit</button>
            <button type="button" class="btn btn-secondary btn-sm" data-pause="${b.id}">${b.active ? '⏸ Pause' : '▶️ Resume'}</button>
            <button type="button" class="btn btn-secondary btn-sm" data-del="${b.id}">🗑 Delete</button>
          </td></tr>`).join('')
      + '</tbody></table></div>';
    const byId = (id) => rows.find(r => String(r.id) === String(id));
    host.querySelectorAll('[data-kids]').forEach(b => b.onclick = () => openBusChildren(byId(b.dataset.kids)));
    host.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openBusRoute(byId(b.dataset.edit)));
    host.querySelectorAll('[data-pause]').forEach(b => b.onclick = async () => {
      const r = byId(b.dataset.pause);
      try { await Api.patch('/operations/bus-routes/' + r.id, { active: !r.active }); }
      catch (e) { busToast((e && e.message) || 'Could not update', 'error'); return; }
      busToast(r.active ? 'Route paused' : 'Route resumed');
      renderBusRoutes(BUS.main);
    });
    host.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      const r = byId(b.dataset.del);
      const ok = await KT.confirm({ title: 'Delete “' + r.route_name + '”?', description: (r.children_count ? r.children_count + ' child' + (r.children_count === 1 ? '' : 'ren') + ' will be taken off this route. ' : '') + 'To stop using a route for now, Pause keeps it. This cannot be undone.', okLabel: 'Delete route' });
      if (!ok) return;
      try { await Api.delete('/operations/bus-routes/' + r.id); }
      catch (e) { busToast((e && e.message) || 'Could not delete', 'error'); return; }
      busToast('Route deleted');
      renderBusRoutes(BUS.main);
    });
  }

  function openBusRoute(route) {
    const d = BUS.data || {};
    const centres = d.centres || [];
    const drivers = (d.drivers || []).slice();
    if (route && route.driver_id && !drivers.some(x => String(x.id) === String(route.driver_id))) drivers.unshift({ id: route.driver_id, name: route.driver_name || 'Current driver' });
    const body = document.createElement('div');
    body.innerHTML = `<div style="display:grid;gap:12px;">
      <label style="${BUS_L}">Route name
        <input id="bus-name" maxlength="120" placeholder="e.g. Morning — North loop" value="${route ? esc(route.route_name) : ''}" style="${BUS_F}"></label>
      <label style="${BUS_L}">Centre
        <select id="bus-centre" style="${BUS_F}">
          ${centres.length === 1 ? '' : '<option value="">— choose a centre —</option>'}
          ${centres.map(c => `<option value="${c.id}" ${route && String(route.centre_id) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
        </select></label>
      <label style="${BUS_L}">Driver <span style="font-weight:500;color:#64748B;">(optional)</span>
        <select id="bus-driver" style="${BUS_F}">
          <option value="">— no driver yet —</option>
          ${drivers.map(x => `<option value="${x.id}" ${route && String(route.driver_id) === String(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}
        </select></label>
      <label style="${BUS_L}">Vehicle <span style="font-weight:500;color:#64748B;">(optional)</span>
        <input id="bus-vehicle" maxlength="80" placeholder="e.g. Van 2 · ABCD 123" value="${route ? esc(route.vehicle_label || '') : ''}" style="${BUS_F}"></label>
      <label style="${BUS_L}">Notes <span style="font-weight:500;color:#64748B;">(optional)</span>
        <textarea id="bus-notes" maxlength="2000" rows="3" placeholder="e.g. Use the side entrance on Maple St · Booster seats in the back row" style="${BUS_F}height:auto;min-height:70px;padding:8px 10px;font-family:inherit;resize:vertical;">${route ? esc(route.notes || '') : ''}</textarea></label>
      <div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="${BUS_L}">Children on this route</span><span id="bus-kcount" style="font-size:12px;color:#64748B;"></span>
          <span style="flex:1"></span>
          <button type="button" id="bus-kall" class="btn btn-secondary btn-sm">Select all</button>
          <button type="button" id="bus-knone" class="btn btn-secondary btn-sm">Clear</button>
        </div>
        <input id="bus-ksearch" type="search" placeholder="🔍 Find a child…" style="${BUS_F}height:34px;">
        <div id="bus-klist" style="margin-top:6px;max-height:240px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:4px 6px;background:#fff;"></div>
        <div style="font-size:12px;color:#64748B;margin-top:4px;">Stop order, pick-up address and times are set in the route's 🧒 Children.</div>
      </div>
      ${route ? `<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="bus-active" ${route.active ? 'checked' : ''}> Active (running)</label>` : ''}
      <div id="bus-err" style="color:#B91C1C;font-size:13px;min-height:16px;"></div>
    </div>`;
    /* CHOOSE THE CHILDREN HERE (2026-10-16, "bus route should also allow you to choose
       children"). The list is the chosen centre's current children; ticking is kept across a
       centre change only for children of the new centre. Saving makes the route's riders
       exactly the ticked children (the server keeps each existing rider's stop details). */
    const allKids = d.children || [];
    const chosen = new Set(((route && route.child_ids) || []).map(String));
    const centreSel = body.querySelector('#bus-centre');
    const paintKids = () => {
      const cid = centreSel.value;
      const q = body.querySelector('#bus-ksearch').value.trim().toLowerCase();
      const kids = allKids.filter(k => cid && String(k.centre_id) === cid);
      const shown = kids.filter(k => !q || (k.name + ' ' + (k.ref || '') + ' ' + (k.school || '')).toLowerCase().includes(q));
      const listEl = body.querySelector('#bus-klist');
      listEl.innerHTML = !cid ? '<div style="padding:8px;color:#64748B;font-size:13px;">Choose the centre first.</div>'
        : !kids.length ? '<div style="padding:8px;color:#64748B;font-size:13px;">No current children at this centre.</div>'
        : !shown.length ? '<div style="padding:8px;color:#64748B;font-size:13px;">No match.</div>'
        : shown.map(k => `<label style="display:flex;gap:10px;align-items:center;padding:7px 4px;border-bottom:1px solid #F1F5F9;cursor:pointer;font-size:13.5px;">
            <input type="checkbox" data-kid="${k.id}" ${chosen.has(String(k.id)) ? 'checked' : ''} style="flex:0 0 auto;">
            <span style="flex:1;min-width:0;"><b>${esc(k.name)}</b>${k.ref ? ` <span style="color:#64748B;font-size:12px;">${esc(k.ref)}</span>` : ''}${k.school ? `<div style="color:#64748B;font-size:12px;">🏫 ${esc(k.school)}</div>` : ''}</span></label>`).join('');
      listEl.querySelectorAll('[data-kid]').forEach(cb => cb.onchange = () => { if (cb.checked) chosen.add(cb.dataset.kid); else chosen.delete(cb.dataset.kid); count(); });
      count();
    };
    const visibleIds = () => allKids.filter(k => String(k.centre_id) === centreSel.value).map(k => String(k.id));
    const count = () => {
      const n = visibleIds().filter(id => chosen.has(id)).length;
      body.querySelector('#bus-kcount').textContent = n ? n + ' chosen' : '';
    };
    centreSel.addEventListener('change', paintKids);
    body.querySelector('#bus-ksearch').addEventListener('input', paintKids);
    body.querySelector('#bus-kall').onclick = () => { body.querySelectorAll('#bus-klist [data-kid]').forEach(cb => chosen.add(cb.dataset.kid)); paintKids(); };
    body.querySelector('#bus-knone').onclick = () => { visibleIds().forEach(id => chosen.delete(id)); paintKids(); };
    paintKids();
    KT.Shell.Modal.open({
      title: route ? 'Edit route' : 'Add a bus route',
      body,
      actions: [
        { label: 'Cancel' },
        { label: route ? 'Save changes' : 'Add route', primary: true, handler: async () => {
          const err = body.querySelector('#bus-err');
          const payload = {
            route_name: body.querySelector('#bus-name').value.trim(),
            centre_id: parseInt(body.querySelector('#bus-centre').value, 10) || null,
            driver_id: parseInt(body.querySelector('#bus-driver').value, 10) || null,
            vehicle_label: body.querySelector('#bus-vehicle').value.trim(),
            notes: body.querySelector('#bus-notes').value.trim(),
          };
          if (!payload.route_name) { err.textContent = 'Give the route a name.'; return false; }
          if (!payload.centre_id) { err.textContent = 'Choose the centre.'; return false; }
          if (route) payload.active = body.querySelector('#bus-active').checked;
          // Only children of the chosen centre ride (a centre change drops the others).
          payload.child_ids = allKids.filter(k => String(k.centre_id) === String(payload.centre_id) && chosen.has(String(k.id))).map(k => k.id);
          try {
            if (route) await Api.patch('/operations/bus-routes/' + route.id, payload);
            else await Api.post('/operations/bus-routes', payload);
          } catch (e) { err.textContent = (e && e.message) || 'Could not save.'; return false; }
          busToast(route ? 'Route updated' : 'Route added');
          renderBusRoutes(BUS.main);
        } },
      ],
    });
  }

  /* The roster of one route: who rides, in stop order, and a form to add or change one. */
  function openBusChildren(route) {
    if (!route) return;
    const body = document.createElement('div');
    body.innerHTML = '<div style="color:#64748B;">Loading…</div>';
    KT.Shell.Modal.open({
      title: '🚐 ' + route.route_name + ' — children',
      body,
      large: true,
      actions: [{ label: 'Done', primary: true }],
    });
    let editing = null;   // the roster row being changed, or null when adding
    const paint = async () => {
      let d;
      try { d = await Api.get('/operations/bus-routes/' + route.id + '/children'); }
      catch (e) { body.innerHTML = `<div style="color:#B91C1C;">${esc((e && e.message) || 'Could not load the roster.')}</div>`; return; }
      const rows = d.data || [];
      const cands = d.candidates || [];
      const nextStop = rows.reduce((m, r) => Math.max(m, +r.stop_order || 0), 0) + 1;
      const pick = editing
        ? `<input value="${esc(editing.child_name)}" disabled style="${BUS_F}background:#F8FAFC;">`
        : `<select id="bk-child" style="${BUS_F}"><option value="">${cands.length ? '— choose a child —' : 'Every child at this centre is on the route'}</option>${cands.map(c => `<option value="${c.id}">${esc(c.name)}${c.ref ? ' · ' + esc(c.ref) : ''}${c.school ? ' · ' + esc(c.school) : ''}</option>`).join('')}</select>`;
      body.innerHTML = `
        <div style="font-size:13px;color:#64748B;margin-bottom:10px;">${esc(route.centre_name || '')}${route.driver_name ? ' · Driver ' + esc(route.driver_name) : ''}${route.vehicle_label ? ' · ' + esc(route.vehicle_label) : ''}</div>
        ${rows.length ? '<div class="kt-card" style="padding:0;overflow:auto;"><table data-kt-no-bulk data-kt-no-filter data-kt-noexport><thead><tr><th>Stop</th><th>Child</th><th>Pick-up address</th><th>Pick-up</th><th>Drop-off</th><th>Notes</th><th></th></tr></thead><tbody>'
          + rows.map(r => `<tr>
              <td>${r.stop_order != null ? esc(r.stop_order) : '—'}</td>
              <td><strong>${esc(r.child_name)}</strong>${r.child_ref ? `<div style="font-size:12px;color:#64748B;">${esc(r.child_ref)}</div>` : ''}${r.school ? `<div style="font-size:12px;color:#64748B;">🏫 ${esc(r.school)}</div>` : ''}</td>
              <td>${esc(r.pickup_address || '—')}</td>
              <td style="white-space:nowrap;">${busT12(r.pickup_time) || '—'}</td>
              <td style="white-space:nowrap;">${busT12(r.dropoff_time) || '—'}</td>
              <td style="font-size:13px;white-space:pre-wrap;max-width:240px;">${esc(r.notes || '—')}</td>
              <td style="text-align:right;white-space:nowrap;">
                <button type="button" class="btn btn-secondary btn-sm" data-ed="${r.child_id}">✏️ Edit</button>
                <button type="button" class="btn btn-secondary btn-sm" data-rm="${r.child_id}">🗑 Remove</button>
              </td></tr>`).join('')
          + '</tbody></table></div>'
          : '<div style="background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:14px;color:#475569;font-size:13.5px;">No children on this route yet. Add the first one below.</div>'}
        <div style="margin-top:16px;padding:14px;border:1px solid #E2E8F0;border-radius:12px;background:#fff;">
          <div style="font-weight:800;font-size:14px;margin-bottom:10px;">${editing ? 'Change ' + esc(editing.child_name) : '➕ Add a child to this route'}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
            <label style="${BUS_L}">Child ${pick}</label>
            <label style="${BUS_L}">Stop #
              <input id="bk-stop" type="number" min="0" max="999" value="${editing ? esc(editing.stop_order != null ? editing.stop_order : '') : nextStop}" style="${BUS_F}"></label>
            <label style="${BUS_L}">Pick-up time
              <input id="bk-pu" type="time" value="${editing && editing.pickup_time ? esc(editing.pickup_time) : ''}" style="${BUS_F}"></label>
            <label style="${BUS_L}">Drop-off time
              <input id="bk-do" type="time" value="${editing && editing.dropoff_time ? esc(editing.dropoff_time) : ''}" style="${BUS_F}"></label>
          </div>
          <label style="${BUS_L}margin-top:12px;">Pick-up address
            <input id="bk-addr" maxlength="255" placeholder="Filled in from the family's address — change it if the stop is somewhere else" value="${editing ? esc(editing.pickup_address || '') : ''}" style="${BUS_F}"></label>
          <label style="${BUS_L}margin-top:12px;">Notes
            <textarea id="bk-notes" maxlength="2000" rows="2" placeholder="e.g. Grandma meets the bus on Tuesdays · Needs a booster seat" style="${BUS_F}height:auto;min-height:56px;padding:8px 10px;font-family:inherit;resize:vertical;">${editing ? esc(editing.notes || '') : ''}</textarea></label>
          <div id="bk-err" style="color:#B91C1C;font-size:13px;min-height:16px;margin-top:6px;"></div>
          <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
            ${editing ? '<button type="button" id="bk-cancel" class="btn btn-secondary">Cancel</button>' : ''}
            <button type="button" id="bk-save" class="btn btn-primary">${editing ? 'Save changes' : 'Add to route'}</button>
          </div>
        </div>`;
      const sel = body.querySelector('#bk-child');
      if (sel) sel.onchange = () => {
        const c = cands.find(x => String(x.id) === sel.value);
        const a = body.querySelector('#bk-addr');
        if (c && !a.value.trim()) a.value = c.address || '';
      };
      const cancel = body.querySelector('#bk-cancel');
      if (cancel) cancel.onclick = () => { editing = null; paint(); };
      body.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => { editing = rows.find(r => String(r.child_id) === b.dataset.ed); paint(); });
      body.querySelectorAll('[data-rm]').forEach(b => b.onclick = async () => {
        const r = rows.find(x => String(x.child_id) === b.dataset.rm);
        const ok = await KT.confirm({ title: 'Take ' + r.child_name + ' off this route?', description: 'They can be added again later.', okLabel: 'Remove' });
        if (!ok) return;
        try { await Api.delete('/operations/bus-routes/' + route.id + '/children/' + r.child_id); }
        catch (e) { busToast((e && e.message) || 'Could not remove', 'error'); return; }
        renderBusRoutes(BUS.main);   // the list's child count, underneath if (editing && String(editing.child_id) === String(r.child_id)) editing = null;
        busToast(r.child_name + ' removed from the route');
        paint();
      });
      body.querySelector('#bk-save').onclick = async () => {
        const err = body.querySelector('#bk-err');
        const childId = editing ? editing.child_id : parseInt((sel && sel.value) || '', 10);
        if (!childId) { err.textContent = 'Choose the child.'; return; }
        const pu = body.querySelector('#bk-pu').value, dof = body.querySelector('#bk-do').value;
        const stop = body.querySelector('#bk-stop').value;
        const btn = body.querySelector('#bk-save');
        btn.disabled = true;
        try {
          await Api.post('/operations/bus-routes/' + route.id + '/children', {
            child_id: childId,
            stop_order: stop === '' ? null : parseInt(stop, 10),
            pickup_time: pu || null,
            dropoff_time: dof || null,
            pickup_address: body.querySelector('#bk-addr').value.trim(),
            notes: body.querySelector('#bk-notes').value.trim(),
          });
        } catch (e) { err.textContent = (e && e.message) || 'Could not save.'; btn.disabled = false; return; }
        renderBusRoutes(BUS.main);   // the list's child count, underneath
        busToast(editing ? 'Saved' : 'Added to the route');
        editing = null;
        paint();
      };
    };
    paint();
  }

  // ============================ Room rotation candidates =================================
  // Age as whole years + months (no decimals) — "2 yr 7 mo" / "9 mo".
  function fmtAgeYM(months) {
    var m = Math.round(Number(months) || 0);
    if (m < 0) m = 0;
    var y = Math.floor(m / 12), r = m % 12;
    if (y <= 0) return r + ' mo';
    if (r === 0) return y + ' yr';
    return y + ' yr ' + r + ' mo';
  }
  async function renderRoomRotations(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading…</div>';
    const r = await Api.get('/operations/rotation-candidates').catch(() => ({ data: [] }));
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🔄 Room rotation candidates</h2>
        <p>Children aging into the next age band within 60 days. Plan their move so the receiving room can prepare.</p>
      </div>
      <div class="kt-card">
        <table>
          <thead><tr><th>Child</th><th>Current age</th><th>Reaches threshold</th><th>Days until</th><th>Centre</th></tr></thead>
          <tbody>${(r.data || []).map(c => `<tr>
            <td><strong>${esc(c.child_name)}</strong></td>
            <td>${fmtAgeYM(c.current_age_months)}</td>
            <td>${fmtAgeYM(c.threshold)} on ${fmtDate(c.reach_date)}</td>
            <td><span class="kt-pill ${c.days_until <= 14 ? 'kt-pill-danger' : c.days_until <= 30 ? 'kt-pill-warning' : 'kt-pill-info'}">${c.days_until} days</span></td>
            <td>${esc(c.centre_name)}</td>
          </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;color:#64748B;">No upcoming rotations.</td></tr>'}</tbody>
        </table>
      </div>
    </div>`;
  }

  window.KT = KT;
  window.KT.V22p56 = {
    renderClosures, renderLatePickup, renderRoomRatios,
    renderVacationHolds, renderTuitionIncreases,
    renderReenrollment, renderEngagement, renderNps,
    renderSignedDocs, renderBusRoutes, renderRoomRotations,
  };
})(window);
