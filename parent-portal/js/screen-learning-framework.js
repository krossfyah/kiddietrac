/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Learning framework  (agency admin)

   Which pedagogical framework this agency plans and reports against. It decides the
   areas observations are linked to, the sections of every report card, and the columns
   of the gaps report. Changing it affects new records only: an existing report card
   keeps the framework it was written in (see LearningFrameworks.php).

   Backend: GET /agency/learning-framework, POST /admin/learning-framework
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }

  var css = '<style id="lf-css">'
    + '.lf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:14px}'
    + '.lf-card{border:1.5px solid #E2E8F0;border-radius:14px;padding:14px 16px;background:#fff;cursor:pointer;text-align:left;font-family:inherit;transition:border-color .15s,box-shadow .15s}'
    + '.lf-card:hover{border-color:#94A3B8}'
    + '.lf-card.sel{border-color:#1F6080;box-shadow:0 0 0 3px rgba(31,96,128,.14)}'
    + '.lf-card b{display:block;font-size:14.5px;color:#0F172A}'
    + '.lf-card .lf-reg{font-size:11.5px;font-weight:700;color:#1F6080;text-transform:uppercase;letter-spacing:.03em;margin-top:2px}'
    + '.lf-card ul{margin:8px 0 0;padding-left:18px}'
    + '.lf-card li{font-size:12.5px;color:#475569;line-height:1.5}'
    + '.lf-cur{display:inline-block;margin-left:6px;font-size:10.5px;font-weight:800;color:#166534;background:#DCFCE7;border-radius:10px;padding:1px 7px;vertical-align:2px}'
    + '.lf-custom{margin-top:14px;border:1px dashed #CBD5E1;border-radius:12px;padding:14px}'
    + '.lf-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.6fr) auto;gap:8px;margin-top:8px}'
    + '.lf-row input,.lf-name{padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit;min-width:0}'
    + '.lf-x{border:none;background:none;color:#DC2626;font-size:18px;cursor:pointer;padding:0 6px}'
    + '@media (max-width:640px){.lf-row{grid-template-columns:minmax(0,1fr) auto}.lf-row input:nth-child(2){grid-column:1/-1;grid-row:2}}'
    + '</style>';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = css + '<div style="padding:14px 24px;max-width:1100px;">'
      + '<div class="kt-page-hero"><h2>🧭 Learning framework</h2>'
      + '<p>The framework your agency plans and reports against. It sets the areas observations link to, the sections of report cards, and the gaps report.</p></div>'
      + '<div id="lf-body">Loading…</div></div>';
    var body = main.querySelector('#lf-body');
    var data;
    try { data = await Api.get('/agency/learning-framework'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load: ' + esc(e.message || e) + '</div>'; return; }

    var current = data.framework || {};
    var sel = current.key || 'HDLH';
    var custom = { name: (data.custom && data.custom.name) || '', areas: ((data.custom && data.custom.areas) || []).slice() };
    if (!custom.areas.length) { custom.areas = [{ label: '', hint: '' }, { label: '', hint: '' }, { label: '', hint: '' }]; }

    function paint() {
      var cards = (data.catalogue || []).map(function (f) {
        var isCur = f.key === current.key && current.chosen !== false;
        var list = f.key === 'CUSTOM'
          ? '<ul><li>Name your own areas, for a programme like Reggio Emilia, Montessori or a local curriculum.</li></ul>'
          : '<ul>' + f.areas.map(function (a) { return '<li>' + esc(a.label) + '</li>'; }).join('') + '</ul>';
        return '<button type="button" class="lf-card' + (f.key === sel ? ' sel' : '') + '" data-k="' + esc(f.key) + '">'
          + '<b>' + esc(f.name) + (isCur ? '<span class="lf-cur">In use</span>' : '') + '</b>'
          + '<div class="lf-reg">' + esc(f.short) + ' · ' + esc(f.region) + '</div>' + list + '</button>';
      }).join('');
      var customBox = sel !== 'CUSTOM' ? '' :
        '<div class="lf-custom"><label style="font-size:13px;font-weight:700;">Framework name</label><br>'
        + '<input class="lf-name" id="lf-cname" maxlength="80" style="width:100%;max-width:420px;margin-top:4px" placeholder="e.g. Our Reggio-inspired curriculum" value="' + esc(custom.name) + '">'
        + '<div style="font-size:13px;font-weight:700;margin-top:12px;">Areas <span style="font-weight:400;color:#64748B;">(2 to 10)</span></div>'
        + custom.areas.map(function (a, i) {
          return '<div class="lf-row" data-i="' + i + '"><input data-f="label" maxlength="60" placeholder="Area name" value="' + esc(a.label) + '">'
            + '<input data-f="hint" maxlength="200" placeholder="What it covers (helps the AI and your team)" value="' + esc(a.hint || '') + '">'
            + '<button type="button" class="lf-x" data-del="' + i + '" aria-label="Remove area">×</button></div>';
        }).join('')
        + (custom.areas.length < 10 ? '<button type="button" class="kt-btn" id="lf-add" style="margin-top:10px;">+ Add an area</button>' : '')
        + '</div>';
      var auto = current.chosen === false
        ? '<div class="kt-card" style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;font-size:13.5px;">No framework has been chosen yet, so KiddieTrac is using <strong>'
          + esc(current.name) + '</strong> based on your province. Pick one below to make it official.</div>' : '';
      body.innerHTML = auto + '<div class="lf-grid">' + cards + '</div>' + customBox
        + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px;">'
        + '<button type="button" class="kt-btn kt-btn-primary" id="lf-save">Save framework</button>'
        + '<span id="lf-msg" style="font-size:13px;color:#64748B;">New observations and report cards will use it. Existing report cards keep the framework they were written in.</span></div>';
    }
    function grabCustom() {
      var n = body.querySelector('#lf-cname'); if (n) { custom.name = n.value; }
      body.querySelectorAll('.lf-row').forEach(function (r) {
        var i = +r.getAttribute('data-i');
        custom.areas[i].label = r.querySelector('[data-f="label"]').value;
        custom.areas[i].hint = r.querySelector('[data-f="hint"]').value;
      });
    }
    paint();

    body.addEventListener('click', async function (ev) {
      var card = ev.target.closest('[data-k]');
      if (card) { grabCustom(); sel = card.getAttribute('data-k'); paint(); return; }
      var del = ev.target.closest('[data-del]');
      if (del) { grabCustom(); custom.areas.splice(+del.getAttribute('data-del'), 1); paint(); return; }
      if (ev.target.closest('#lf-add')) { grabCustom(); custom.areas.push({ label: '', hint: '' }); paint(); return; }
      if (!ev.target.closest('#lf-save')) { return; }
      grabCustom();
      var payload = { key: sel };
      if (sel === 'CUSTOM') {
        var areas = custom.areas.filter(function (a) { return String(a.label || '').trim(); });
        if (areas.length < 2) { toast('Add at least two areas to your framework.', 'error'); return; }
        payload.custom_name = custom.name;
        payload.custom_areas = areas;
      }
      var chosen = (data.catalogue || []).find(function (f) { return f.key === sel; }) || {};
      if (current.chosen !== false && sel !== current.key && KT.confirm) {
        var ok = await KT.confirm({ title: 'Switch to ' + (sel === 'CUSTOM' ? (custom.name || 'your own framework') : chosen.name) + '?',
          description: 'New observations and report cards will use its areas. Existing report cards stay as they were written.', okLabel: 'Switch framework' });
        if (!ok) { return; }
      }
      var btn = body.querySelector('#lf-save'); btn.disabled = true;
      try {
        var r = await Api.post('/admin/learning-framework', payload);
        current = r.framework; sel = current.key;
        paint();
        toast('Learning framework saved: ' + current.name, 'success');
      } catch (e) {
        btn.disabled = false;
        toast((e && e.message) || 'Could not save', 'error');
      }
    });
  }

  ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':learning-framework', render); });
})(window);
