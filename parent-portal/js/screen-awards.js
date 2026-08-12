/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Child Awards
   Educators/directors issue awards (daily / weekly / monthly); parents see
   them; educators print a certificate.
     educator/director/admin :awards  → renderAwards (issue + list + print)
     guardian                :awards  → renderParentAwards (read-only)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(d) {
    if (!d) return '';
    try { if (KT.fmtDate) return KT.fmtDate(d); var dt = new Date(String(d).replace(' ', 'T')); return isNaN(dt) ? d : dt.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }); }
    catch (e) { return d; }
  }
  function periodLabel(p) { return ({ daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' })[p] || ''; }
  function childName(a) { return a.child ? ((a.child.first_name || '') + ' ' + (a.child.last_name || '')).trim() : 'Child'; }
  function byName(a) { return a.awarded_by ? ((a.awarded_by.first_name || '') + ' ' + (a.awarded_by.last_name || '')).trim() : ''; }

  var PRESETS = ['Star of the Week', 'Helper of the Day', 'Kindness Award', 'Super Reader', 'Great Listener', 'Most Improved', 'Team Player', 'Creative Genius', 'Little Scientist', 'Tidy-Up Champion'];
  var BADGES = ['🏆', '🌟', '⭐', '🥇', '🎖️', '🏅', '👑', '💫', '🎉', '🦸', '📚', '🎨', '💪', '🤝', '🌈'];

  /* ═══════════ EDUCATOR VIEW ═══════════ */
  async function renderAwards(main) {
    Dom.clear(main);
    var wrap = Dom.el('div', { style: 'padding:20px;max-width:1100px;margin:0 auto;' });
    main.appendChild(wrap);
    wrap.innerHTML =
      '<div style="display:flex;justify-content:flex-end;margin-bottom:16px;">' +
        '<button class="btn btn-primary" id="kt-new-award">+ New award</button></div>' +
      '<div id="kt-awards-list"><div style="padding:40px;text-align:center;color:#64748B;">Loading…</div></div>';
    wrap.querySelector('#kt-new-award').addEventListener('click', function () { openAwardModal(main); });
    loadAwards(main);
  }

  async function loadAwards(main) {
    var listEl = main.querySelector('#kt-awards-list');
    if (!listEl) return;
    var rows;
    try { var d = await Api.get('/provider/awards'); rows = (d && d.data) || []; }
    catch (e) { listEl.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load awards: ' + esc(e.message || '') + '</div>'; return; }
    if (!rows.length) {
      listEl.innerHTML = '<div style="padding:44px;text-align:center;color:#64748B;background:#fff;border-radius:14px;"><div style="font-size:42px;">🏆</div><div style="font-weight:800;color:#475569;margin-top:6px;">No awards yet</div><div style="font-size:13px;margin-top:4px;">Click “+ New award” to celebrate a child.</div></div>';
      return;
    }
    listEl.innerHTML = '<div data-kt-list="1" style="display:grid;gap:11px;">' + rows.map(awardCard).join('') + '</div>';
    listEl.querySelectorAll('[data-award-print]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); printCertificate(rows.find(function (r) { return String(r.id) === b.getAttribute('data-award-print'); })); });
    });
    listEl.querySelectorAll('[data-award-del]').forEach(function (b) {
      b.addEventListener('click', async function (e) {
        e.stopPropagation();
        var ok = (KT.confirm) ? await KT.confirm('Delete this award?') : window.confirm('Delete this award?');
        if (!ok) return;
        try { await Api.delete('/provider/awards/' + b.getAttribute('data-award-del')); loadAwards(main); }
        catch (err) { alert('Could not delete: ' + (err.message || '')); }
      });
    });
    if (typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  function awardCard(a) {
    return '<div style="display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #E7EDF3;border-left:5px solid #F59E0B;border-radius:14px;padding:13px 15px;">' +
      '<div style="flex:0 0 auto;width:52px;height:52px;border-radius:14px;background:#FEF3C7;display:flex;align-items:center;justify-content:center;font-size:28px;">' + (a.badge || '🏆') + '</div>' +
      '<div style="flex:1;min-width:0;">' +
        '<div style="font-weight:800;font-size:15.5px;color:#0F172A;">' + esc(a.title) + (a.period ? ' <span style="font-size:11px;font-weight:800;color:#92400E;background:#FEF3C7;padding:2px 8px;border-radius:20px;margin-left:4px;">' + esc(periodLabel(a.period)) + '</span>' : '') + '</div>' +
        '<div style="font-size:13px;color:#475569;margin-top:2px;">' + esc(childName(a)) + ' · ' + esc(fmtDate(a.awarded_on)) + (byName(a) ? ' · by ' + esc(byName(a)) : '') + '</div>' +
        (a.note ? '<div style="font-size:12.5px;color:#64748B;margin-top:4px;font-style:italic;">“' + esc(a.note) + '”</div>' : '') +
      '</div>' +
      '<div style="display:flex;gap:6px;flex-shrink:0;">' +
        '<button type="button" data-award-print="' + a.id + '" class="kt-act-icon kt-act-info kt-icon-tip" data-kttip="Print certificate" aria-label="Print certificate">🖨️</button>' +
        '<button type="button" data-award-del="' + a.id + '" class="kt-act-icon kt-act-danger kt-icon-tip" data-kttip="Delete" aria-label="Delete">🗑️</button>' +
      '</div></div>';
  }

  async function openAwardModal(main) {
    var children = [];
    try {
      var r = await Api.get('/provider/awards/roster');
      children = (r && r.data) || [];
    } catch (e) { /* empty roster handled below */ }
    var seen = {};
    children = children.filter(function (c) { if (seen[c.id]) return false; seen[c.id] = 1; return true; });
    children.sort(function (a, b) { return ((a.first_name || '') + a.last_name).localeCompare((b.first_name || '') + b.last_name); });

    // Mobile: lift the card above the fixed bottom nav + home indicator so the
    // whole form (incl. the Give-award button) stays reachable and centred.
    var isMobile = window.matchMedia && window.matchMedia('(max-width:768px)').matches;
    var ovPad = isMobile
      ? 'padding:10px 12px calc(84px + env(safe-area-inset-bottom,0px)) 12px;'
      : 'padding:16px;';
    var cardMaxH = isMobile ? 'calc(100vh - 150px)' : '92vh';

    var inp = 'width:100%;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:14px;font-family:inherit;box-sizing:border-box;';
    var ov = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:9999;' + ovPad });
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:520px;width:100%;padding:22px;max-height:' + cardMaxH + ';overflow:auto;">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;"><div style="font-weight:800;font-size:18px;color:#0F172A;">🏆 New award</div><button type="button" data-x style="background:none;border:none;font-size:22px;cursor:pointer;color:#94A3B8;line-height:1;">×</button></div>' +
      '<div style="display:grid;gap:12px;">' +
        '<label style="font-size:13px;font-weight:700;">Child *<select id="aw-child" style="' + inp + '"><option value="">— Pick a child —</option>' + children.map(function (c) { return '<option value="' + c.id + '">' + esc(((c.first_name || '') + ' ' + (c.last_name || '')).trim()) + '</option>'; }).join('') + '</select></label>' +
        (children.length ? '' : '<div style="font-size:12px;color:#B91C1C;">No children found in your rooms.</div>') +
        '<label style="font-size:13px;font-weight:700;">Award *<input id="aw-title" list="aw-presets" placeholder="e.g. Star of the Week" maxlength="120" style="' + inp + '"><datalist id="aw-presets">' + PRESETS.map(function (p) { return '<option value="' + esc(p) + '">'; }).join('') + '</datalist></label>' +
        '<div><div style="font-size:13px;font-weight:700;margin-bottom:6px;">Badge</div><div id="aw-badges" style="display:flex;flex-wrap:wrap;gap:6px;">' + BADGES.map(function (b, i) { return '<button type="button" class="aw-badge" data-badge="' + b + '" style="font-size:22px;width:40px;height:40px;line-height:1;border:2px solid ' + (i === 0 ? '#F59E0B' : '#E2E8F0') + ';border-radius:11px;background:' + (i === 0 ? '#FEF3C7' : '#fff') + ';cursor:pointer;">' + b + '</button>'; }).join('') + '</div></div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' +
          '<label style="font-size:13px;font-weight:700;">Period<select id="aw-period" style="' + inp + '"><option value="">—</option><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select></label>' +
          '<label style="font-size:13px;font-weight:700;">Date<input id="aw-date" type="date" value="' + new Date().toISOString().slice(0, 10) + '" style="' + inp + '"></label>' +
        '</div>' +
        '<label style="font-size:13px;font-weight:700;">Message (optional)<textarea id="aw-note" rows="2" maxlength="1000" placeholder="A note for the child / parents" style="' + inp + ';resize:vertical;"></textarea></label>' +
      '</div>' +
      '<div id="aw-status" style="min-height:18px;font-size:13px;margin-top:8px;"></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;"><button type="button" data-x style="background:#F1F5F9;color:#475569;border:none;border-radius:10px;padding:9px 16px;font-weight:800;cursor:pointer;">Cancel</button><button type="button" id="aw-save" style="background:#F59E0B;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-weight:800;cursor:pointer;">Give award</button></div>' +
      '</div>';
    document.body.appendChild(ov);
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });

    var badge = BADGES[0];
    ov.querySelectorAll('.aw-badge').forEach(function (b) {
      b.addEventListener('click', function () {
        badge = b.getAttribute('data-badge');
        ov.querySelectorAll('.aw-badge').forEach(function (x) { x.style.borderColor = '#E2E8F0'; x.style.background = '#fff'; });
        b.style.borderColor = '#F59E0B'; b.style.background = '#FEF3C7';
      });
    });
    ov.querySelector('#aw-save').addEventListener('click', async function () {
      var childId = +ov.querySelector('#aw-child').value;
      var title = (ov.querySelector('#aw-title').value || '').trim();
      var status = ov.querySelector('#aw-status');
      if (!childId) { status.style.color = '#B91C1C'; status.textContent = 'Pick a child.'; return; }
      if (!title) { status.style.color = '#B91C1C'; status.textContent = 'Enter an award title.'; return; }
      var save = ov.querySelector('#aw-save'); save.disabled = true;
      try {
        await Api.post('/provider/awards', {
          child_id: childId, title: title, badge: badge,
          period: ov.querySelector('#aw-period').value || null,
          note: (ov.querySelector('#aw-note').value || '').trim() || null,
          awarded_on: ov.querySelector('#aw-date').value || null,
        });
        close(); loadAwards(main);
      } catch (e) { save.disabled = false; status.style.color = '#B91C1C'; status.textContent = '✗ ' + (e.message || 'Could not save'); }
    });
  }

  /* ═══════════ PARENT VIEW ═══════════ */
  async function renderParentAwards(main) {
    Dom.clear(main);
    var wrap = Dom.el('div', { style: 'padding:18px 14px;max-width:900px;margin:0 auto;' });
    main.appendChild(wrap);
    wrap.innerHTML = '<div class="kt-hero" style="background:linear-gradient(135deg,#F59E0B,#EC4899);margin-bottom:14px;"><h1>🏆 Awards</h1><div class="kt-hero-sub">Celebrating your child</div></div><div id="kt-pawards"><div style="padding:32px;text-align:center;color:#64748B;">Loading…</div></div>';
    var el = wrap.querySelector('#kt-pawards');
    try {
      var d = await Api.get('/parent/awards');
      var rows = (d && d.data) || [];
      if (!rows.length) { el.innerHTML = '<div style="text-align:center;padding:40px;background:#fff;border-radius:14px;color:#64748B;"><div style="font-size:44px;">🏆</div><div style="font-weight:800;color:#0F172A;margin-top:6px;">No awards yet</div>When an educator celebrates your child, it’ll appear here.</div>'; return; }
      el.innerHTML = '<div style="display:grid;gap:12px;">' + rows.map(parentAwardCard).join('') + '</div>';
    } catch (e) { el.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message || '') + '</div>'; }
  }

  function parentAwardCard(a) {
    return '<div style="background:#fff;border:1px solid #F1E5C8;border-left:6px solid #F59E0B;border-radius:16px;padding:16px 18px;box-shadow:0 2px 10px -6px rgba(245,158,11,.4);display:flex;gap:14px;align-items:center;">' +
      '<div style="flex:0 0 auto;width:58px;height:58px;border-radius:16px;background:#FEF3C7;display:flex;align-items:center;justify-content:center;font-size:32px;">' + (a.badge || '🏆') + '</div>' +
      '<div style="flex:1;min-width:0;"><div style="font-weight:800;font-size:16px;color:#0F172A;">' + esc(a.title) + '</div>' +
      '<div style="font-size:13px;color:#475569;margin-top:2px;">' + esc(childName(a)) + ' · ' + esc(fmtDate(a.awarded_on)) + (a.period ? ' · ' + esc(periodLabel(a.period)) : '') + '</div>' +
      (a.note ? '<div style="font-size:13px;color:#64748B;margin-top:5px;font-style:italic;">“' + esc(a.note) + '”</div>' : '') +
      (byName(a) ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">— ' + esc(byName(a)) + '</div>' : '') +
      '</div></div>';
  }

  /* ═══════════ PRINTABLE CERTIFICATE ═══════════ */
  function printCertificate(a) {
    if (!a) return;
    var agency = a.agency_name || '';
    if (!agency) { try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); agency = u.agency_name || u.centre_name || agency; } catch (e) {} }
    var owner = a.owner_name || '';
    var name = childName(a), by = byName(a);
    var badge = a.badge || '🏆';
    var awardLine = esc(a.title) + (a.period ? ' <span class="per">- ' + esc(periodLabel(a.period)) + '</span>' : '');

    var css =
      '@page{size:A4 portrait;margin:0}' +
      '*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
      'html,body{margin:0}' +
      'body{font-family:Georgia,"Times New Roman",serif;background:#d9d3c4;color:#3a2f18}' +
      '.page{width:210mm;min-height:297mm;margin:0 auto;background:#fff;position:relative;display:flex;padding:12mm}' +
      '.frame{flex:1;position:relative;border:2.5px solid #A9812B;background:radial-gradient(120% 80% at 50% 0%,#FFFDF6 0%,#FBF3DE 55%,#F6EAC9 100%)}' +
      '.frame:before{content:"";position:absolute;inset:7px;border:1px solid #CBAF6A}' +
      '.frame:after{content:"";position:absolute;inset:12px;border:0.6px solid #D9C486}' +
      '.corner{position:absolute;width:26px;height:26px;border:2.5px solid #A9812B;z-index:2}' +
      '.corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}' +
      '.corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}' +
      '.corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}' +
      '.corner.br{bottom:16px;right:16px;border-left:0;border-top:0}' +
      '.inner{position:relative;z-index:1;padding:20mm 18mm 15mm;text-align:center;min-height:100%;display:flex;flex-direction:column;align-items:center}' +
      '.agency{font-size:19px;letter-spacing:2px;text-transform:uppercase;color:#8a6d24;font-weight:bold}' +
      '.agency-rule{width:70px;height:2px;background:#C9A94A;margin:9px auto 0}' +
      '.seal{margin:16px auto 4px;width:104px;height:104px;border-radius:50%;background:radial-gradient(circle at 50% 40%,#FFF7DD,#F3E1A8);border:3px solid #C9A94A;box-shadow:0 0 0 6px #FBF3DE,0 0 0 7.5px #D9C486;display:flex;align-items:center;justify-content:center;font-size:52px}' +
      '.eyebrow{margin-top:16px;font-size:22px;letter-spacing:8px;text-transform:uppercase;color:#B0891F;font-weight:bold}' +
      '.eyebrow-sub{font-size:12px;letter-spacing:4px;text-transform:uppercase;color:#B79B57;margin-top:4px}' +
      '.present{margin-top:26px;font-style:italic;font-size:16px;color:#6f6242}' +
      '.name{font-size:52px;line-height:1.1;font-style:italic;color:#26200f;margin:8px 0 4px;padding:0 18px}' +
      '.name-rule{width:58%;max-width:340px;height:2px;margin:2px auto 0;background:linear-gradient(90deg,transparent,#C9A94A 20%,#C9A94A 80%,transparent)}' +
      '.for{margin-top:22px;font-size:15px;color:#6f6242}' +
      '.award{margin-top:6px;font-size:27px;font-weight:bold;color:#9a6b12}.award .per{font-size:17px;font-weight:normal;color:#B79B57}' +
      '.note{margin:14px auto 0;max-width:130mm;font-style:italic;font-size:15px;color:#5c513a;line-height:1.5}' +
      '.spacer{flex:1;min-height:14mm}' +
      '.rosette{width:66px;height:66px;border-radius:50%;background:radial-gradient(circle at 50% 40%,#FBEFC5,#E9CE80);border:2px solid #C9A94A;display:flex;align-items:center;justify-content:center;font-size:28px;color:#8a6d24;margin-bottom:6px}' +
      '.sigrow{width:100%;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-top:8mm;padding:0 6mm}' +
      '.sig{flex:1;text-align:center}' +
      '.sig .val{font-size:15px;color:#2c2513;font-weight:bold;min-height:20px}' +
      '.sig .line{border-top:1.5px solid #8a7b52;margin:4px 6px 6px;height:0}' +
      '.sig .lbl{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#9a8a5a}' +
      '.sig .sub{font-size:10.5px;color:#a99a6a;font-style:italic;margin-top:2px}' +
      '.foot{margin-top:12px;font-size:11px;color:#a99a6a}' +
      '.bar{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:10}' +
      '.bar button{border:none;border-radius:10px;padding:10px 16px;font-family:Arial,sans-serif;font-weight:bold;font-size:13px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.18)}' +
      '.bar .p{background:#B0891F;color:#fff}.bar .x{background:#fff;color:#444}' +
      '@media print{.bar{display:none}body{background:#fff}.page{margin:0;padding:0}}';

    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Certificate - ' + esc(name) + '</title><style>' + css + '</style></head><body>' +
      '<div class="bar"><button class="p" onclick="window.print()">Print</button><button class="x" onclick="window.close()">Close</button></div>' +
      '<div class="page"><div class="frame">' +
      '<span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>' +
      '<div class="inner">' +
      (agency ? '<div class="agency">' + esc(agency) + '</div><div class="agency-rule"></div>' : '') +
      '<div class="seal">' + badge + '</div>' +
      '<div class="eyebrow">Certificate</div>' +
      '<div class="eyebrow-sub">of Achievement</div>' +
      '<div class="present">This certificate is proudly presented to</div>' +
      '<div class="name">' + esc(name) + '</div><div class="name-rule"></div>' +
      '<div class="for">in recognition of</div>' +
      '<div class="award">' + awardLine + '</div>' +
      (a.note ? '<div class="note">' + esc(a.note) + '</div>' : '') +
      '<div class="spacer"></div>' +
      '<div class="rosette">&#9733;</div>' +
      '<div class="sigrow">' +
        '<div class="sig"><div class="val">' + esc(fmtDate(a.awarded_on)) + '</div><div class="line"></div><div class="lbl">Date</div></div>' +
        '<div class="sig"><div class="val">' + esc(owner || by || ' ') + '</div><div class="line"></div><div class="lbl">' + (owner ? 'On behalf of ' + esc(agency || 'the agency') : 'Educator') + '</div>' + (owner && by ? '<div class="sub">Awarded by ' + esc(by) + '</div>' : '') + '</div>' +
      '</div>' +
      (agency ? '<div class="foot">Presented by ' + esc(agency) + '</div>' : '') +
      '</div></div></div></body></html>';
    var w = window.open('', '_blank');
    if (!w) { alert('Please allow pop-ups to open the certificate.'); return; }
    w.document.open(); w.document.write(html); w.document.close();
    try { w.focus(); } catch (e) {}
  }

  /* ═══════════ REGISTER ═══════════ */
  KT.Awards = { renderAwards: renderAwards, renderParentAwards: renderParentAwards, printCertificate: printCertificate };
  if (Shell && Shell.registerScreen) {
    ['educator', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':awards', renderAwards); });
    Shell.registerScreen('guardian:awards', renderParentAwards);
  }
})(window);
