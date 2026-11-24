/* screen-platform-careers.js — Platform → Careers (2026-10-30; Insights tab 2026-11-02).
 *
 * Job applications from www.kiddietrac.com/careers (App\Http\Controllers\Api\CareersController):
 * who applied for what, their CV and cover letter (private files, downloaded through the API with the
 * signed-in session), and where each one stands. Super admins only; the API enforces it.
 *
 * Insights (Anthony 2026-11-02: "career section add stats, graphs, charts - useful info for me to review"):
 * GET /platform/careers/stats?days=&role= — tiles, applications over time, pipeline, postings, weekday/hour
 * (agency timezone), what applicants send, and who is waiting on us. Only what the platform records:
 * there is no source/referrer, and status history starts 2026-11-02, so time-to-respond/hire fill in from then.
 */
(function (w) {
  'use strict';
  var KT = w.KT = w.KT || {};
  var Shell = KT.Shell;
  if (!Shell || !Shell.registerScreen) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function when(iso) { try { return KT.fmtDateTime ? KT.fmtDateTime(iso) : new Date(String(iso).replace(' ', 'T') + 'Z').toLocaleString(); } catch (e) { return iso || ''; } }
  function day(ymd) { return KT.dayLabel ? KT.dayLabel(ymd) : String(ymd || ''); }
  function ssGet(k, d) { try { return sessionStorage.getItem(k) || d; } catch (e) { return d; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  function toast(m, t) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(m, t); }
  var LABEL = { new: 'New', reviewing: 'Reviewing', interview: 'Interview', offer: 'Offer', hired: 'Hired', declined: 'Declined', withdrawn: 'Withdrawn' };
  var STAGE_LABEL = { new: 'Applied', reviewing: 'Reviewed', interview: 'Interviewed', offer: 'Offered', hired: 'Hired' };

  /* ── Styles: colour tokens on .kt-cs. The portal's dark theme (html.kt-dark / data-theme=dark) swaps them;
     the OS setting alone does not, because the portal itself stays light and dark panels on it would clash. ── */
  var CSS_ID = 'kt-cs-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    var dark = '--cs-panel:#111C2B;--cs-line:#24344A;--cs-soft:#18263A;--cs-ink:#E2E8F0;--cs-mute:#94A3B8;--cs-track:#1E2D42;' +
      '--cs-s1:#3B82C4;--cs-s1l:#1F4A73;--cs-good:#34D399;--cs-warn:#FBBF24;--cs-bad:#FB7185;--cs-link:#7DD3FC;';
    s.textContent =
      '.kt-cs{--cs-panel:#fff;--cs-line:#E7EBF0;--cs-soft:#F8FAFC;--cs-ink:#0D1B2A;--cs-mute:#64748B;--cs-track:#EEF2F7;' +
        '--cs-s1:#2F7FC1;--cs-s1l:#BFD8EF;--cs-good:#15803D;--cs-warn:#B45309;--cs-bad:#BE123C;--cs-link:#1F6080;color:var(--cs-ink);}' +
      'html.kt-dark .kt-cs,html[data-theme="dark"] .kt-cs{' + dark + '}' +
      '.kt-cs-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:var(--cs-panel);border:1px solid var(--cs-line);border-radius:12px;padding:10px 12px;margin:0 0 12px;}' +
      '.kt-cs-bar label{font-size:12px;font-weight:700;color:var(--cs-mute);display:flex;flex-direction:column;gap:4px;min-width:0;}' +
      '.kt-cs-bar select{height:32px;max-width:100%;}' +
      '.kt-cs-bar .kt-cs-meta{margin-left:auto;font-size:12px;color:var(--cs-mute);align-self:center;}' +
      '.kt-cs-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:0 0 12px;}' +
      '.kt-cs-tile{background:var(--cs-panel);border:1px solid var(--cs-line);border-radius:12px;padding:10px 12px;min-width:0;}' +
      '.kt-cs-tile .l{font-size:11.5px;font-weight:700;color:var(--cs-mute);letter-spacing:.2px;}' +
      '.kt-cs-tile .v{font-size:24px;font-weight:800;line-height:1.2;margin:4px 0 2px;font-variant-numeric:tabular-nums;}' +
      '.kt-cs-tile .s{font-size:12px;color:var(--cs-mute);line-height:1.35;}' +
      '.kt-cs-tile .s b.up{color:var(--cs-good);} .kt-cs-tile .s b.down{color:var(--cs-bad);}' +
      '.kt-cs-tile.warn .v{color:var(--cs-warn);}' +
      '.kt-cs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:12px;margin:0 0 12px;}' +
      '.kt-cs-panel{background:var(--cs-panel);border:1px solid var(--cs-line);border-radius:12px;padding:12px 14px;min-width:0;margin:0 0 12px;}' +
      '.kt-cs-grid .kt-cs-panel{margin:0;}' +
      '.kt-cs-panel h3{margin:0 0 2px;font-size:14.5px;font-weight:800;color:var(--cs-ink);}' +
      '.kt-cs-panel .cap{font-size:12px;color:var(--cs-mute);margin:0 0 10px;line-height:1.4;}' +
      '.kt-cs-leg{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--cs-mute);margin:0 0 4px;}' +
      '.kt-cs-leg i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px;}' +
      '.kt-cs svg text{fill:var(--cs-mute);font-size:11px;font-family:inherit;}' +
      '.kt-cs svg .gl{stroke:var(--cs-line);stroke-width:1;}' +
      '.kt-cs svg .b1{fill:var(--cs-s1);} .kt-cs svg .b2{fill:var(--cs-s1l);}' +
      '.kt-cs svg rect.hit{fill:transparent;} .kt-cs svg g:hover .b1,.kt-cs svg g:hover .b2{opacity:.8;}' +
      '.kt-cs-row{display:grid;grid-template-columns:minmax(90px,38%) minmax(0,1fr) auto;gap:8px;align-items:center;font-size:12.5px;margin:6px 0;}' +
      '.kt-cs-row .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
      '.kt-cs-row .t{height:10px;border-radius:5px;background:var(--cs-track);overflow:hidden;}' +
      '.kt-cs-row .t i{display:block;height:100%;border-radius:5px;background:var(--cs-s1);}' +
      '.kt-cs-row b{font-variant-numeric:tabular-nums;min-width:2.5em;text-align:right;}' +
      '.kt-cs-row .p{color:var(--cs-mute);font-weight:600;margin-left:4px;}' +
      '.kt-cs-funnel .kt-cs-row{grid-template-columns:minmax(80px,110px) minmax(0,1fr) auto;}' +
      '.kt-cs-funnel .step{font-size:11px;color:var(--cs-mute);margin:-3px 0 2px 0;padding-left:118px;}' +
      '.kt-cs-h{margin:16px 2px 6px;}.kt-cs-h h3{margin:0 0 2px;font-size:15px;font-weight:800;color:var(--cs-ink);}.kt-cs-h .cap{font-size:12px;color:var(--cs-mute);margin:0;}' +
      '.kt-cs-note{font-size:12px;color:var(--cs-mute);margin:2px 0 12px;line-height:1.45;}' +
      '.kt-cs-empty{font-size:12.5px;color:var(--cs-mute);padding:14px;text-align:center;border:1px dashed var(--cs-line);border-radius:10px;}' +
      '.kt-cs-flag{color:var(--cs-warn);font-weight:800;}' +
      '.kt-cs-pill{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:999px;background:var(--cs-track);color:var(--cs-ink);white-space:nowrap;}' +
      '.kt-cs-err{background:var(--cs-panel);border:1px solid var(--cs-line);border-radius:12px;padding:14px;color:var(--cs-bad);display:flex;gap:10px;align-items:center;flex-wrap:wrap;}' +
      '@media (max-width:600px){.kt-cs-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}.kt-cs-tile .v{font-size:20px;}' +
        '.kt-cs-bar .kt-cs-meta{margin-left:0;width:100%;}.kt-cs-bar label{flex:1 1 140px;}.kt-cs-bar select{width:100%;}' +
        '.kt-cs-funnel .step{padding-left:0;}}';
    document.head.appendChild(s);
  }

  function num(v) { return v == null ? '—' : Number(v).toLocaleString(); }
  function pct(a, b) { return b ? Math.round(a / b * 100) + '%' : '—'; }
  function dur(h) {
    if (h == null) return '—';
    if (h < 1) return Math.max(1, Math.round(h * 60)) + ' min';
    if (h < 48) return (Math.round(h * 10) / 10) + ' h';
    return (Math.round(h / 24 * 10) / 10) + ' days';
  }
  function days(d) { return d == null ? '—' : (Math.round(d * 10) / 10) + ' days'; }

  function tile(label, value, sub, cls) {
    return '<div class="kt-cs-tile' + (cls ? ' ' + cls : '') + '"><div class="l">' + esc(label) + '</div><div class="v">' + value + '</div><div class="s">' + (sub || '&nbsp;') + '</div></div>';
  }
  /* Horizontal meters: label, bar, value (+ share). */
  function rows(list, total, opts) {
    opts = opts || {};
    if (!list.length) return '<div class="kt-cs-empty">' + esc(opts.empty || 'Nothing in this period.') + '</div>';
    var max = opts.max || 0; if (!max) list.forEach(function (r) { max = Math.max(max, r.count); });
    return list.map(function (r) {
      var wv = max ? Math.max(r.count ? 2 : 0, Math.round(r.count / max * 100)) : 0;
      return '<div class="kt-cs-row" title="' + esc(r.label + ': ' + r.count) + '"><span class="n">' + esc(r.label) + '</span>' +
        '<span class="t"><i style="width:' + wv + '%;' + (r.color ? 'background:' + r.color + ';' : '') + '"></i></span>' +
        '<b>' + num(r.count) + (total ? '<span class="p">' + pct(r.count, total) + '</span>' : '') + '</b></div>';
    }).join('');
  }
  /* Column chart (inline SVG, scales to its box). series: [{label, a, b?, tip}] — b drawn on top of a as the darker part. */
  var CH = [];
  function columns(series, o) {
    o = o || {};
    if (!o.W) { CH.push([series, o]); return '<div class="kt-cs-chart" data-ch="' + (CH.length - 1) + '" style="height:' + (o.h || 180) + 'px"></div>'; }
    var n = series.length; if (!n) return '<div class="kt-cs-empty">Nothing in this period.</div>';
    var W = Math.max(260, o.W), H = o.h || 180, L = 28, R = 6, T = 10, B = 22, max = 0;
    series.forEach(function (p) { max = Math.max(max, p.a || 0); });
    var nice = max <= 4 ? Math.max(1, max) : Math.ceil(max / 4) * 4, step = (W - L - R) / n, bw = Math.max(2, Math.min(34, step * 0.66));
    var out = '';
    (nice % 2 ? [0, 1] : [0, 0.5, 1]).forEach(function (f) {
      var y = T + (H - T - B) * (1 - f), v = nice * f;
      out += '<line class="gl" x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 6) + '" y="' + (y + 4) + '" text-anchor="end">' + v + '</text>';
    });
    var every = Math.max(1, Math.ceil(n / Math.min(o.maxLabels || 12, Math.floor((W - L) / (o.labelW || 52)))));
    series.forEach(function (p, i) {
      var x = L + i * step + (step - bw) / 2, ha = (p.a || 0) / nice * (H - T - B), hb = (p.b || 0) / nice * (H - T - B);
      out += '<g><title>' + esc(p.tip || (p.label + ': ' + (p.a || 0))) + '</title>' +
        '<rect class="hit" x="' + (L + i * step) + '" y="' + T + '" width="' + step + '" height="' + (H - T - B) + '"/>' +
        (ha ? '<rect class="' + (o.single ? 'b1' : 'b2') + '" x="' + x + '" y="' + (H - B - ha) + '" width="' + bw + '" height="' + ha + '" rx="2"/>' : '') +
        (hb ? '<rect class="b1" x="' + x + '" y="' + (H - B - hb) + '" width="' + bw + '" height="' + hb + '" rx="2"/>' : '') + '</g>';
      if (i % every === 0) out += '<text x="' + (L + i * step + step / 2) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(p.short != null ? p.short : p.label) + '</text>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" style="display:block;height:' + H + 'px" role="img" aria-label="' + esc(o.aria || 'Chart') + '">' + out + '</svg>';
  }

  /* VIEW, NOT ONLY DOWNLOAD (2026-11-04, Anthony: "have the ability to view resumes with a view function in the
     kebab"). /view/{kind} answers a PDF or picture as the file (shown in the document viewer) and a Word file as a
     page of HTML, shown in a frame with scripts off. */
  function viewFile(id, kind, name, btn) {
    var tok = ''; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
    var base = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1');
    var what = kind === 'cover' ? 'Cover letter' : 'Resume';
    if (btn) btn.disabled = true;
    fetch(base + '/platform/careers/' + id + '/view/' + kind, { headers: { Authorization: 'Bearer ' + tok, Accept: '*/*' } })
      .then(function (r) {
        var ct = r.headers.get('Content-Type') || '';
        if (/json/.test(ct)) return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Could not open that file.'); return { html: j.html, file: j.name }; });
        if (!r.ok) throw new Error('Could not open that file.');
        var cd = r.headers.get('Content-Disposition') || '', m = cd.match(/filename="?([^";]+)"?/);
        return r.blob().then(function (b) { return { blob: b, file: m ? m[1] : kind + '.pdf' }; });
      })
      .then(function (x) {
        var title = what + ' - ' + name;
        if (x.blob) {
          if (KT.openFile) { KT.openFile(x.blob, { title: title, filename: x.file, label: x.file }); return; }
          window.open(URL.createObjectURL(x.blob), '_blank', 'noopener');
          return;
        }
        var f = document.createElement('iframe');
        f.setAttribute('sandbox', 'allow-same-origin');
        f.title = title;
        f.style.cssText = 'width:100%;height:70vh;border:1px solid #E2E8F0;border-radius:10px;background:#fff;display:block;';
        f.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;padding:28px 34px;font:14.5px/1.6 Calibri,"Segoe UI",Arial,sans-serif;color:#1F2937;background:#fff;}'
          + 'h1{font-size:24px;margin:0 0 6px;}h2{font-size:18px;margin:18px 0 6px;color:#0F3D5E;border-bottom:1px solid #E2E8F0;padding-bottom:3px;}h3,h4,h5,h6{font-size:15px;margin:14px 0 4px;}'
          + 'p{margin:0 0 6px;}p.sp{margin:0;line-height:.8;}ul,ol{margin:2px 0 8px 22px;padding:0;}li{margin:0 0 3px;}'
          + 'table{border-collapse:collapse;width:100%;margin:6px 0;}td{border:1px solid #E5E7EB;padding:6px 8px;vertical-align:top;}td p{margin:0;}</style></head><body>' + x.html + '</body></html>';
        var wrap = document.createElement('div');
        var bar = document.createElement('div');
        bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 0 10px;font-size:12.5px;color:#64748B;';
        bar.innerHTML = '<span>' + esc(x.file) + ' · shown as text; the layout may differ from Word.</span>';
        var dl = document.createElement('button'); dl.type = 'button'; dl.className = 'kt-btn kt-btn-ghost'; dl.textContent = '⬇️ Download';
        dl.addEventListener('click', function () { download(id, kind, dl, true); });
        bar.appendChild(dl); wrap.appendChild(bar); wrap.appendChild(f);
        Shell.Modal.open({ title: '📄 ' + title, body: wrap, large: true, actions: [{ label: 'Close' }] });
      })
      .catch(function (e) { toast((e && e.message) || 'Could not open that file.', 'error'); })
      .then(function () { if (btn) btn.disabled = false; });
  }

  /* ── Applications tab (the list, unchanged in behaviour) ── */
  function download(id, kind, btn, save) {
    var tok = ''; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
    var base = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1');
    btn.disabled = true;
    fetch(base + '/platform/careers/' + id + '/file/' + kind, { headers: { Authorization: 'Bearer ' + tok, Accept: '*/*' } })
      .then(function (r) {
        if (!r.ok) throw new Error('Could not download that file.');
        var cd = r.headers.get('Content-Disposition') || '', m = cd.match(/filename="?([^";]+)"?/);
        return r.blob().then(function (b) { return { b: b, name: m ? m[1] : (kind + '.pdf') }; });
      })
      .then(function (x) {
        if (!save && KT.openFile) { KT.openFile(x.b, { title: x.name, filename: x.name, label: x.name }); return; }
        var u = URL.createObjectURL(x.b), a = document.createElement('a'); a.href = u; a.download = x.name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
      })
      .catch(function (e) { toast(e.message, 'error'); })
      .then(function () { btn.disabled = false; });
  }

  async function renderList(root) {
    root.innerHTML = '<div style="padding:20px;color:#64748B">Loading…</div>';
    var d;
    try { d = await KT.Api.get('/platform/careers'); }
    catch (e) { root.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load applications. Please try again.</div>'; return; }
    var apps = d.applications || [], statuses = d.statuses || Object.keys(LABEL);
    if (!apps.length) { root.innerHTML = '<div class="kt-card" style="color:#64748B">No applications yet. They arrive here from www.kiddietrac.com/careers, and info@kiddietrac.com gets a copy of each one.</div>'; return; }
    root.innerHTML = '<div class="kt-card" style="overflow-x:auto"><table data-kt-noexport="1"><thead><tr><th>Received</th><th>Role</th><th>Applicant</th><th>Contact</th><th>Status</th><th></th></tr></thead><tbody>'
      + apps.map(function (a) {
        return '<tr data-id="' + a.id + '">'
          + '<td style="white-space:nowrap">' + esc(when(a.created_at)) + '</td>'
          + '<td style="font-weight:700">' + esc(a.role) + '</td>'
          + '<td>' + esc(a.name) + (a.linkedin ? '<div><a href="' + esc(a.linkedin) + '" target="_blank" rel="noopener" style="font-size:12px">LinkedIn / portfolio ↗</a></div>' : '') + '</td>'
          + '<td><a href="mailto:' + esc(a.email) + '">' + esc(a.email) + '</a>' + (a.phone ? '<div style="font-size:12px;color:#64748B">' + esc(a.phone) + '</div>' : '') + '</td>'
          + '<td><select class="pc-status" aria-label="Status">' + statuses.map(function (s) { return '<option value="' + s + '"' + (s === a.status ? ' selected' : '') + '>' + esc(LABEL[s] || s) + '</option>'; }).join('') + '</select></td>'
          + '<td style="white-space:nowrap">'
          // Plain buttons in the last cell: kt-row-actions folds them into the row's ⋮ menu.
          + (a.has_resume ? '<button type="button" class="pc-view" data-kind="resume">👁️ View resume</button> <button type="button" class="pc-dl" data-kind="resume">⬇️ Download resume</button> ' : '')
          + (a.has_cover ? '<button type="button" class="pc-view" data-kind="cover">👁️ View cover letter</button> <button type="button" class="pc-dl" data-kind="cover">⬇️ Download cover letter</button> ' : '')
          + (a.cover_text ? '<button type="button" class="pc-note">💬 Note</button>' : '')
          + '</td></tr>';
      }).join('') + '</tbody></table></div>';
    root.querySelectorAll('tr[data-id]').forEach(function (tr) {
      var id = +tr.getAttribute('data-id'), a = apps.filter(function (x) { return x.id === id; })[0];
      tr.querySelectorAll('.pc-dl').forEach(function (b) { b.addEventListener('click', function () { download(id, b.getAttribute('data-kind'), b, true); }); });
      tr.querySelectorAll('.pc-view').forEach(function (b) { b.addEventListener('click', function () { viewFile(id, b.getAttribute('data-kind'), a.name, b); }); });
      var nb = tr.querySelector('.pc-note');
      if (nb) nb.addEventListener('click', function () {
        Shell.Modal.open({ title: 'Note from ' + a.name, body: '<div style="white-space:pre-wrap;max-width:560px;font-size:14px;line-height:1.55">' + esc(a.cover_text) + '</div>', actions: [{ label: 'Close' }] });
      });
      tr.querySelector('.pc-status').addEventListener('change', function () {
        var sel = this;
        KT.Api.patch('/platform/careers/' + id, { status: sel.value })
          .then(function () { a.status = sel.value; toast('Status updated'); })
          .catch(function (e) { toast((e && e.message) || 'Could not update', 'error'); sel.value = a.status; });
      });
    });
  }

  /* ── Insights tab ── */
  var F = null;
  async function renderInsights(root) {
    css();
    F = F || { days: ssGet('kt_pc_days', '90'), role: ssGet('kt_pc_role', '') };
    root.innerHTML = '<div class="kt-cs"><div class="kt-cs-note">Loading insights…</div></div>';
    var d;
    try { d = await KT.Api.get('/platform/careers/stats?days=' + encodeURIComponent(F.days) + (F.role ? '&role=' + encodeURIComponent(F.role) : '')); }
    catch (e) {
      root.innerHTML = '<div class="kt-cs"><div class="kt-cs-err">' + esc(e && e.status === 403 ? 'Careers insights are for super admins.' : 'The insights could not be loaded. Please try again.') +
        ' <button type="button" class="btn btn-secondary" data-cs-retry>Try again</button></div></div>';
      var rb = root.querySelector('[data-cs-retry]'); if (rb) rb.addEventListener('click', function () { renderInsights(root); });
      return;
    }
    CH = [];
    var k = d.kpi || {}, total = k.period || 0;
    var periodName = { '30': 'last 30 days', '90': 'last 90 days', '365': 'last 12 months', '0': 'all time' }[String(d.days)] || 'this period';
    var roleOpts = (d.roles_all || []).slice().sort();
    if (F.role && roleOpts.indexOf(F.role) < 0) roleOpts.unshift(F.role);

    /* Tiles */
    var delta = '';
    if (k.previous_period != null && d.days) {
      var diff = total - k.previous_period;
      delta = diff === 0 ? 'Same as the ' + esc(periodName.replace('last ', 'previous ')) :
        '<b class="' + (diff > 0 ? 'up' : 'down') + '">' + (diff > 0 ? '▲ ' : '▼ ') + Math.abs(diff) + '</b> vs previous ' + esc(periodName.replace('last ', '')) + ' (' + num(k.previous_period) + ')';
    }
    var zeroPost = (d.postings || []).filter(function (p) { return !p.period; }).length;
    var oldest = (d.stale || []).filter(function (s) { return s.status === 'new'; })[0];
    var funnel = d.funnel || [], reachedInterview = (funnel[2] || {}).count || 0;
    var measured = function (n) { return n ? 'Median of ' + n + ' application' + (n === 1 ? '' : 's') : 'Measured from ' + esc(day(d.history_since || '2026-11-02')) + ' on'; };
    var tiles =
      tile('Open postings', k.open_postings == null ? '—' : num(k.open_postings),
        k.open_postings == null ? 'The careers page could not be read' : (zeroPost ? '<span class="kt-cs-flag">' + zeroPost + '</span> with no applicants (' + esc(periodName) + ')' : 'All have applicants (' + esc(periodName) + ')')) +
      tile('Applications', num(total), delta || (num(k.all_time) + ' all time')) +
      tile('New this week', num(k.this_week), num(k.today) + ' today · ' + num(k.all_time) + ' all time') +
      tile('Waiting for a first look', num(k.awaiting_review), oldest ? 'Oldest ' + oldest.age_days + ' day' + (oldest.age_days === 1 ? '' : 's') + ' old' : 'Nobody is waiting', k.awaiting_review ? 'warn' : '') +
      tile('In progress', num(k.in_progress), (k.open_now ? num(k.open_now.reviewing) + ' reviewing · ' + num(k.open_now.interview) + ' interview · ' + num(k.open_now.offer) + ' offer' : '')) +
      tile('Interview rate', pct(reachedInterview, total), num(reachedInterview) + ' of ' + num(total) + ' reached interview') +
      tile('Hired', num(k.hired), num(k.declined) + ' declined · ' + num(k.withdrawn) + ' withdrawn') +
      tile('Time to first response', dur(k.first_response_hours_median), measured(k.first_response_n)) +
      tile('Time to hire', days(k.days_to_hire_median), measured(k.days_to_hire_n));

    /* Over time */
    var unit = d.unit === 'month' ? 'month' : 'week';
    var ser = (d.series || []).map(function (p) {
      var lab = unit === 'month' ? day(p.start).replace(/ \d+,/, '') : 'Week of ' + day(p.start);
      return { label: lab, short: unit === 'month' ? day(p.start).replace(/ \d+, (\d{2})(\d{2})$/, " '$2") : day(p.start).replace(/, \d{4}$/, ''), a: p.count, b: p.progressed,
        tip: lab + ': ' + p.count + ' application' + (p.count === 1 ? '' : 's') + (p.progressed ? ', ' + p.progressed + ' reached interview' : '') };
    });
    var overTime = '<div class="kt-cs-panel"><h3>Applications by ' + unit + '</h3><p class="cap">When applications arrived (' + esc(periodName) + ', ' + esc(d.timezone || '') + '). Hover a bar for the figures.</p>' +
      '<div class="kt-cs-leg"><span><i style="background:var(--cs-s1l)"></i>Applications</span><span><i style="background:var(--cs-s1)"></i>Reached interview or later</span></div>' +
      columns(ser, { aria: 'Applications by ' + unit, maxLabels: 10 }) + '</div>';

    /* Pipeline */
    var applied = (funnel[0] || {}).count || 0;
    var fun = '<div class="kt-cs-funnel">' + funnel.map(function (f, i) {
      var prev = i ? funnel[i - 1].count : 0;
      return (i && prev ? '<div class="step">↓ ' + pct(f.count, prev) + ' of the step before</div>' : '') +
        rows([{ label: STAGE_LABEL[f.stage] || f.stage, count: f.count }], applied, { max: applied });
    }).join('') + '</div>';
    var pipeline = '<div class="kt-cs-panel"><h3>Pipeline</h3><p class="cap">How far applications from the ' + esc(periodName) + ' got. Declined ones count as reviewed; a stage is counted once it was reached, even if the applicant later withdrew.</p>' +
      (applied ? fun : '<div class="kt-cs-empty">No applications in this period.</div>') + '</div>';

    var SC = { new: '#64748B', reviewing: '#2F7FC1', interview: '#7C3AED', offer: '#D97706', hired: '#15803D', declined: '#BE123C', withdrawn: '#94A3B8' };
    var bs = d.by_status || {};
    var statusNow = '<div class="kt-cs-panel"><h3>Where they stand now</h3><p class="cap">Current status of applications from the ' + esc(periodName) + '.</p>' +
      rows(Object.keys(LABEL).filter(function (s) { return bs[s] || ['new', 'reviewing', 'interview', 'hired'].indexOf(s) > -1; })
        .map(function (s) { return { label: LABEL[s], count: bs[s] || 0, color: SC[s] }; }), total, { empty: 'No applications in this period.' }) + '</div>';

    var byRole = '<div class="kt-cs-panel"><h3>By posting</h3><p class="cap">Applications per role (' + esc(periodName) + ').</p>' +
      rows((d.by_role || []).map(function (r) { return { label: r.role + (r.posted ? '' : ' (not on the site now)'), count: r.total }; }), total) + '</div>';
    var byDept = '<div class="kt-cs-panel"><h3>By department</h3><p class="cap">From each role’s department on the careers page.</p>' + rows((d.by_department || []).map(function (r) { return { label: r.label, count: r.count }; }), total) + '</div>';
    var byLoc = '<div class="kt-cs-panel"><h3>By location</h3><p class="cap">The location each posting lists (applicants’ own locations are not asked).</p>' + rows((d.by_location || []).map(function (r) { return { label: r.label, count: r.count }; }), total) + '</div>';

    var WD = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    var dow = columns((d.by_weekday || []).map(function (c, i) { return { label: WD[i], a: c, tip: WD[i] + ': ' + c }; }), { single: 1, h: 150, labelW: 30, aria: 'Applications by weekday' });
    var hrs = columns((d.by_hour || []).map(function (c, i) {
      var h12 = (i % 12 || 12) + (i < 12 ? 'a' : 'p');
      return { label: h12, short: i % 3 === 0 ? h12 : '', a: c, tip: (i % 12 || 12) + ':00 ' + (i < 12 ? 'AM' : 'PM') + ': ' + c };
    }), { single: 1, h: 150, maxLabels: 24, labelW: 12, aria: 'Applications by hour' });
    var timing = '<div class="kt-cs-panel"><h3>Day of the week</h3><p class="cap">When people apply, in ' + esc(d.timezone || 'the agency’s') + ' time.</p>' + (total ? dow : '<div class="kt-cs-empty">Nothing in this period.</div>') + '</div>' +
      '<div class="kt-cs-panel"><h3>Hour of the day</h3><p class="cap">Hour received, ' + esc(d.timezone || '') + '.</p>' + (total ? hrs : '<div class="kt-cs-empty">Nothing in this period.</div>') + '</div>';

    var m = d.materials || {};
    var mats = '<div class="kt-cs-panel"><h3>What applicants send</h3><p class="cap">Share of applications from the ' + esc(periodName) + ' that include each item. A resume is required.</p>' +
      rows([{ label: 'Resume', count: m.resume || 0 }, { label: 'Cover letter (file or typed)', count: m.cover || 0 }, { label: 'Cover letter as a file', count: m.cover_file || 0 },
        { label: 'LinkedIn / portfolio', count: m.linkedin || 0 }, { label: 'Phone number', count: m.phone || 0 }], total, { empty: 'No applications in this period.' }) +
      (total ? '<div class="kt-cs-note" style="margin:8px 0 0">' + num(k.people) + ' different ' + (k.people === 1 ? 'person' : 'people') + ' sent the ' + num(total) + ' application' + (total === 1 ? '' : 's') + '.</div>' : '') + '</div>';

    /* Tables */
    var postT = (d.postings || []).length ? '<div class="kt-card" style="overflow-x:auto"><table data-kt-no-bulk="1"><thead><tr><th>Posting</th><th>Department</th><th>Location</th><th>Type</th><th>Applicants (' + esc(periodName) + ')</th><th>All time</th></tr></thead><tbody>' +
      d.postings.map(function (p) {
        return '<tr><td style="font-weight:700">' + esc(p.role) + '</td><td>' + esc(p.department || '—') + '</td><td>' + esc(p.location || '—') + '</td><td>' + esc(p.type || '—') + '</td>' +
          '<td data-kt-sort="' + String(p.period).padStart(6, '0') + '">' + (p.period ? num(p.period) : '<span class="kt-cs-flag">0 — no applicants</span>') + '</td><td data-kt-sort="' + String(p.all_time).padStart(6, '0') + '">' + num(p.all_time) + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      : '<div class="kt-cs-empty">' + (d.postings_found ? 'There are no roles on the careers page right now.' : 'The careers page could not be read, so postings are not listed.') + '</div>';

    var roleT = (d.by_role || []).length ? '<div class="kt-card" style="overflow-x:auto"><table data-kt-no-bulk="1"><thead><tr><th>Role</th><th>Applications</th><th>Waiting</th><th>Interviewed</th><th>Hired</th><th>Declined</th><th>Withdrawn</th><th>Latest</th></tr></thead><tbody>' +
      d.by_role.map(function (r) {
        return '<tr><td style="font-weight:700">' + esc(r.role) + (r.posted ? '' : ' <span class="kt-cs-pill">Not on the site now</span>') + '</td><td>' + num(r.total) + '</td><td>' + (r.waiting ? '<span class="kt-cs-flag">' + num(r.waiting) + '</span>' : '0') + '</td><td>' + num(r.interviewed) + ' <span style="color:var(--cs-mute)">(' + pct(r.interviewed, r.total) + ')</span></td><td>' + num(r.hired) + '</td><td>' + num(r.declined) + '</td><td>' + num(r.withdrawn) + '</td>' +
          '<td data-kt-sort="' + esc(r.last_at || '') + '" style="white-space:nowrap">' + esc(when(r.last_at)) + '</td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="kt-cs-empty">No applications in this period.</div>';

    var staleT = (d.stale || []).length ? '<div class="kt-card" style="overflow-x:auto"><table data-kt-no-bulk="1"><thead><tr><th>Applicant</th><th>Role</th><th>Status</th><th>Received</th><th>Waiting</th></tr></thead><tbody>' +
      d.stale.map(function (s) {
        var wait = s.status === 'new' ? s.age_days + ' day' + (s.age_days === 1 ? '' : 's') + ' since applying' : s.idle_days + ' days with no change';
        return '<tr><td style="font-weight:700">' + esc(s.name) + '</td><td>' + esc(s.role) + '</td><td><span class="kt-cs-pill">' + esc(LABEL[s.status] || s.status) + '</span></td>' +
          '<td data-kt-sort="' + esc(s.created_at) + '" style="white-space:nowrap">' + esc(when(s.created_at)) + '</td>' +
          '<td data-kt-sort="' + String(s.status === 'new' ? s.age_days : s.idle_days).padStart(5, '0') + '">' + ((s.status === 'new' ? s.age_days : s.idle_days) >= 5 ? '<span class="kt-cs-flag">' + esc(wait) + '</span>' : esc(wait)) + '</td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="kt-cs-empty">Nobody is waiting on you: every application has been looked at, and nothing in progress has sat for a week.</div>';

    var notTracked = (d.not_tracked || []).length ? '<div class="kt-cs-note">ℹ️ Not recorded, so not shown: ' + d.not_tracked.map(esc).join(' · ') + '. Status changes are recorded from ' + esc(day(d.history_since || '2026-11-02')) + ', so response and hiring times fill in from then.</div>' : '';

    var html = '<div class="kt-cs">' +
      '<div class="kt-cs-bar">' +
        '<label>Period<select data-cs="days">' + [['30', 'Last 30 days'], ['90', 'Last 90 days'], ['365', 'Last 12 months'], ['0', 'All time']].map(function (o) { return '<option value="' + o[0] + '"' + (String(F.days) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>' +
        '<label>Posting<select data-cs="role"><option value="">All postings</option>' + roleOpts.map(function (r) { return '<option value="' + esc(r) + '"' + (r === F.role ? ' selected' : '') + '>' + esc(r) + '</option>'; }).join('') + '</select></label>' +
        '<span class="kt-cs-meta">Times in ' + esc(d.timezone || '') + ' · updated ' + esc(when(d.generated_at)) + '</span>' +
      '</div>' +
      '<div class="kt-cs-tiles">' + tiles + '</div>' +
      overTime +
      '<div class="kt-cs-grid">' + pipeline + statusNow + '</div>' +
      '<div class="kt-cs-grid">' + byRole + byDept + byLoc + '</div>' +
      '<div class="kt-cs-grid">' + timing + mats + '</div>' +
      '<div class="kt-cs-h"><h3>Waiting on you</h3><p class="cap">Applications nobody has opened yet (status New), and ones in progress with no change for 7+ days. Oldest first.</p></div>' + staleT +
      '<div class="kt-cs-h"><h3>Postings on the careers page</h3><p class="cap">Every role on www.kiddietrac.com/careers now, with applicants. Postings with none are flagged.</p></div>' + postT +
      '<div class="kt-cs-h"><h3>Roles by applicants</h3><p class="cap">Every role applied for in the ' + esc(periodName) + ', most applicants first.</p></div>' + roleT +
      '<div style="margin-top:12px">' + notTracked + '</div>' +
    '</div>';
    var charts = CH; CH = [];
    root.innerHTML = html;
    var paint = function () {
      [].forEach.call(root.querySelectorAll('.kt-cs-chart'), function (box) {
        var c = charts[+box.getAttribute('data-ch')], wv = Math.round(box.clientWidth);
        if (!c || !wv || box._w === wv) return;
        box._w = wv; c[1].W = wv; box.innerHTML = columns(c[0], c[1]); c[1].W = 0;
      });
    };
    paint();
    if (w.ResizeObserver) { var ro = new ResizeObserver(function () { if (!root.isConnected) { ro.disconnect(); return; } paint(); }); ro.observe(root); }
    [].forEach.call(root.querySelectorAll('[data-cs]'), function (el) {
      el.addEventListener('change', function () {
        F[el.getAttribute('data-cs')] = el.value;
        ssSet('kt_pc_days', F.days); ssSet('kt_pc_role', F.role);
        renderInsights(root);
      });
    });
    if (KT.enhanceTables) setTimeout(KT.enhanceTables, 0);
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    var tab = ssGet('kt_pc_tab', 'insights');
    if (tab !== 'insights' && tab !== 'list') tab = 'insights';
    main.innerHTML = '<div class="kt-section"><div class="kt-hero kt-hero-auto kt-banner-fx"><h1>💼 Careers</h1><div class="kt-hero-sub">Job applications from the website: CVs, cover letters, where each one stands, and how hiring is going.</div></div>'
      + '<div role="tablist" aria-label="Careers views" id="pc-tabs" style="display:flex;gap:4px;margin:0 0 12px;">'
      + [['insights', '📊 Insights'], ['list', '📋 Applications']].map(function (t) { return '<button type="button" role="tab" data-v="' + t[0] + '" aria-selected="' + (t[0] === tab ? 'true' : 'false') + '">' + t[1] + '</button>'; }).join('')
      + '</div><div id="pc-root"></div></div>';
    var root = main.querySelector('#pc-root'), bar = main.querySelector('#pc-tabs');
    var draw = function (v) { if (v === 'list') renderList(root); else renderInsights(root); };
    bar.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-v]'); if (!b) return;
      var v = b.getAttribute('data-v'); ssSet('kt_pc_tab', v);
      [].forEach.call(bar.querySelectorAll('[data-v]'), function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      draw(v);
    });
    draw(tab);
  }

  ['platform_admin', 'agency_admin'].forEach(function (r) { Shell.registerScreen(r + ':platform-careers', render); });
})(window);
