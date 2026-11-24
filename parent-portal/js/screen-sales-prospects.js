/* screen-sales-prospects.js — SALES → LEADS → COLD REACH (2026-10-30).
 *
 * Anthony: "under leads as a subtab for cold call/reach with ability to cold call and reach out that is not necessarily
 * a real lead and have the ability to convert to a real lead add stats, graphs and indicators as to who opened emails
 * etc (wire this up nicely)" and "cold call list should have settings to reach back out if no response after x days
 * etc - full settings options with follow ups etc".
 *
 * A prospect is someone we reach out to before they are a lead. Call (the dialer), email (from sales@, tracked opens),
 * log the outcome; a follow-up sequence (⚙ Follow-up settings) says what to do after how many days with no reply.
 * Convert turns a prospect into a real lead (Sales → Leads) with the history attached. Rules: App\Support\ColdReach.
 * Leads | Cold reach is a subtab strip shared with the Leads list (KT.salesLeadTabs).
 */
(function () {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (!KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api, Shell = KT.Shell, Dom = KT.Dom;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(m, kind) { if (Dom && Dom.toast) Dom.toast(m, kind || 'success'); }
  function when(iso) {
    if (!iso) return '—';
    var t = Date.parse(String(iso).replace(' ', 'T') + (/[zZ+]/.test(String(iso).slice(10)) ? '' : 'Z'));
    if (!t) return '—';
    try { return new Date(t).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'America/Toronto' }); } catch (e) { return new Date(t).toLocaleString(); }
  }
  function ago(iso) {
    var t = Date.parse(String(iso || '').replace(' ', 'T') + 'Z'); if (!t) return '';
    var m = Math.round((Date.now() - t) / 60000);
    return m < 1 ? 'just now' : m < 60 ? m + 'm ago' : m < 1440 ? Math.round(m / 60) + 'h ago' : Math.round(m / 1440) + 'd ago';
  }
  function untilDue(iso) {
    var t = Date.parse(String(iso || '').replace(' ', 'T') + 'Z'); if (!t) return '';
    var d = Math.round((t - Date.now()) / 86400000);
    return d <= 0 ? 'due now' : d === 1 ? 'in 1 day' : 'in ' + d + ' days';
  }
  var STATUS_COL = { new: ['#475569', '#F1F5F9'], contacted: ['#1F6080', '#E0EEF4'], engaged: ['#6D28D9', '#EDE9FE'], replied: ['#047857', '#DCFCE7'],
    interested: ['#15803D', '#DCFCE7'], not_interested: ['#9A3412', '#FFEDD5'], no_response: ['#64748B', '#F1F5F9'], do_not_contact: ['#B91C1C', '#FEE2E2'], converted: ['#0F766E', '#CCFBF1'] };
  function pill(st, label) { var c = STATUS_COL[st] || STATUS_COL.new; return '<span style="display:inline-block;font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;color:' + c[0] + ';background:' + c[1] + ';white-space:nowrap;">' + esc(label || st) + '</span>'; }
  var FLAGCOL = { contacted: ['#B91C1C', '#FEE2E2'], lead: ['#9A3412', '#FFEDD5'], customer: ['#6D28D9', '#EDE9FE'], unsubscribed: ['#B91C1C', '#FEE2E2'], exists: ['#92400E', '#FEF3C7'] };
  function flagHtml(kind, text) { if (!text) return ''; var c = FLAGCOL[kind] || FLAGCOL.exists; return '<span style="display:inline-block;margin-top:3px;font-size:11.5px;font-weight:700;line-height:1.35;padding:2px 8px;border-radius:8px;color:' + c[0] + ';background:' + c[1] + ';">⚠ ' + esc(text) + '</span>'; }
  var INP = 'width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #D7DFE8;border-radius:8px;font-size:13px;background:#fff;';
  var TA = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D7DFE8;border-radius:8px;font-size:13px;font-family:inherit;line-height:1.45;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;';
  function btn(label, primary) { return '<button type="button" data-kt-iconized="1" data-kt-keep-label="1" style="' + BTN + (primary ? 'background:#0E7C90;color:#fff;border:1px solid #0E7C90;' : 'background:#fff;color:#0F172A;border:1px solid #D7DFE8;') + '">' + label + '</button>'; }
  function field(label, html) { return '<label style="display:block;margin-bottom:9px;"><span style="display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:3px;">' + label + '</span>' + html + '</label>'; }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; }

  /* Leads | Cold reach, on both screens. */
  KT.salesLeadTabs = function (active) {
    var t = function (hash, label, on) {
      return '<a href="#' + hash + '" class="kt-tab" data-kt-inpage="1"' + (on ? ' aria-current="true"' : '') + ' style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;font-size:13.5px;text-decoration:none;border-bottom:2px solid ' + (on ? '#1F6080' : 'transparent') + ';color:' + (on ? '#1F6080' : '#64748B') + ';font-weight:' + (on ? 800 : 600) + ';">' + label + '</a>';
    };
    return el('<div class="kt-subtabs" style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:0 0 12px;">' + t('sales-leads', '🎯 Leads', active === 'leads') + t('sales-followups', '⏰ Follow-ups', active === 'followups') + t('sales-prospects', '📞 Cold reach', active === 'cold') + '</div>');   // Follow-ups joined 2026-11-04
  };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div class="kt-hero kt-banner-fx"><h1>📞 Cold reach</h1><div class="kt-hero-sub">Reach out to centres before they are leads: call, email, follow up on a schedule, and convert the ones who bite.</div></div>';
    main.appendChild(KT.salesLeadTabs('cold'));
    var root = el('<div id="cr-root"></div>'); main.appendChild(root);
    root.innerHTML = '<div style="padding:20px;color:#64748B;">Loading…</div>';
    try { var st = await Api.get('/sales/prospects-settings'); S.cfg = st.settings; S.mailbox = st.mailbox; S.campaigns = st.campaigns || []; } catch (e) { S.cfg = null; }
    await draw(root);
    var m = /[?&]id=(\d+)/.exec(location.hash); if (m) openView(+m[1], root);
  }

  function statsBlock(st) {
    var t = st.tiles || {};
    var tiles = [['👥', 'Prospects', t.total, ''], ['📣', 'Contacted', t.contacted, (t.emailed || 0) + ' emailed · ' + (t.called || 0) + ' called'],
      ['👁', 'Open rate', (t.open_rate || 0) + '%', (t.opened || 0) + ' opened'], ['↩️', 'Reply rate', (t.reply_rate || 0) + '%', (t.replied || 0) + ' replied'],
      ['🔥', 'Interested', t.interested, ''], ['🎯', 'Converted', t.converted, (t.conversion_rate || 0) + '% of contacted'], ['⏰', 'Due now', t.due, 'follow-ups waiting']];
    var wrap = el('<div style="margin-bottom:12px;"></div>');
    wrap.appendChild(el('<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:10px;">' + tiles.map(function (x) {
      return '<div style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:9px 11px;min-width:0;">'
        + '<span style="width:30px;height:30px;flex:0 0 30px;border-radius:50%;background:#EEF6F8;display:inline-flex;align-items:center;justify-content:center;font-size:15px;">' + x[0] + '</span>'
        + '<span style="min-width:0;"><b style="display:block;font-size:18px;line-height:1.1;color:#0F172A;font-variant-numeric:tabular-nums;">' + esc(typeof x[2] === 'number' ? x[2].toLocaleString() : (x[2] == null ? 0 : x[2])) + '</b>'
        + '<span style="display:block;font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(x[3]) + '">' + esc(x[1]) + '</span></span></div>';
    }).join('') + '</div>'));
    var row = el('<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr);gap:10px;" class="cr-charts"></div>');
    row.appendChild(card('Last 30 days', lineChart(st.series || [])));
    row.appendChild(card('Funnel', funnel(st.funnel || [])));
    row.appendChild(card('Who opened recently', opens(st.recent_opens || [])));
    wrap.appendChild(row);
    wrap.appendChild(el('<style>@media(max-width:980px){.cr-charts{grid-template-columns:1fr !important;}}</style>'));
    return wrap;
  }
  function card(title, inner) {
    var c = el('<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:10px 12px;min-width:0;"><div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-bottom:6px;">' + esc(title) + '</div></div>');
    if (typeof inner === 'string') c.insertAdjacentHTML('beforeend', inner); else c.appendChild(inner);
    return c;
  }
  function lineChart(series) {
    var W = 560, H = 150, P = { l: 26, r: 8, t: 8, b: 20 };
    var keys = [['emails', '#0E7C90', 'Emails'], ['opens', '#7C3AED', 'Opens'], ['calls', '#F59E0B', 'Calls'], ['replies', '#16A34A', 'Replies']];
    var max = 1; series.forEach(function (d) { keys.forEach(function (k) { max = Math.max(max, d[k[0]] || 0); }); });
    var n = Math.max(1, series.length - 1), x = function (i) { return P.l + (W - P.l - P.r) * i / n; }, y = function (v) { return P.t + (H - P.t - P.b) * (1 - v / max); };
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block;">';
    [0, .5, 1].forEach(function (f) { var yy = y(max * f); svg += '<line x1="' + P.l + '" x2="' + (W - P.r) + '" y1="' + yy + '" y2="' + yy + '" stroke="#EEF2F6"/><text x="' + (P.l - 4) + '" y="' + (yy + 3) + '" font-size="9" fill="#94A3B8" text-anchor="end">' + Math.round(max * f) + '</text>'; });
    keys.forEach(function (k) {
      var pts = series.map(function (d, i) { return x(i).toFixed(1) + ',' + y(d[k[0]] || 0).toFixed(1); }).join(' ');
      svg += '<polyline points="' + pts + '" fill="none" stroke="' + k[1] + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
    });
    series.forEach(function (d, i) { if (i % 7 === 0 || i === series.length - 1) svg += '<text x="' + x(i) + '" y="' + (H - 5) + '" font-size="9" fill="#94A3B8" text-anchor="middle">' + esc(String(d.date).slice(5)) + '</text>'; });
    svg += '</svg>';
    return svg + '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:11.5px;color:#64748B;margin-top:4px;">' + keys.map(function (k) { return '<span><i style="display:inline-block;width:9px;height:9px;border-radius:3px;background:' + k[1] + ';margin-right:4px;"></i>' + k[2] + '</span>'; }).join('') + '</div>';
  }
  function funnel(f) {
    var top = Math.max(1, (f[0] || [0, 0])[1]);
    return f.map(function (s, i) {
      var w = Math.max(2, Math.round(s[1] / top * 100));
      return '<div style="margin:0 0 6px;"><div style="display:flex;justify-content:space-between;font-size:12px;color:#334155;"><span>' + esc(s[0]) + '</span><b>' + s[1] + '</b></div>'
        + '<div style="height:7px;border-radius:99px;background:#EEF2F7;overflow:hidden;"><i style="display:block;height:100%;width:' + w + '%;border-radius:99px;background:linear-gradient(90deg,#2F8C86,#0E7C90);opacity:' + (1 - i * .1) + ';"></i></div></div>';
    }).join('');
  }
  function opens(list) {
    if (!list.length) return '<div style="font-size:12.5px;color:#94A3B8;padding:6px 0;">No opens yet. Opens show here as people read your emails.</div>';
    return list.map(function (o) {
      return '<a href="#sales-prospects?id=' + o.id + '" style="display:flex;gap:8px;align-items:center;padding:5px 0;border-top:1px solid #F1F4F8;text-decoration:none;color:inherit;">'
        + '<span style="font-size:14px;">👁</span><span style="flex:1;min-width:0;"><span style="display:block;font-size:12.5px;font-weight:700;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(o.organization || o.name) + '</span>'
        + '<span style="display:block;font-size:11px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(o.subject || '') + (o.open_count > 1 ? ' · ' + o.open_count + '×' : '') + '</span></span>'
        + '<span style="font-size:11px;color:#94A3B8;white-space:nowrap;">' + esc(ago(o.opened_at)) + '</span></a>';
    }).join('');
  }

  /* BATCHES (2026-10-31, Anthony: "I want the batch that I uploaded to be in a batch in the table vs individual so it's
     better to manage ... when you click on the batch it can show you all of the lines in the table from that batch").
     The landing view lists batches (one per import, plus "Added by hand"); a batch opens its lines, 100 a page.
     "Select all" ticks the page, and "Select all N in this batch" covers every line, not only the loaded page (it
     sent "0 of 1,000" before: only 1,000 rows were loaded, and most of that list has no email address). */
  var S = { batch: null, batchName: '', status: '', list: '', q: '', page: 1, sel: {}, selAll: false, data: null, cfg: null };

  async function draw(root) {
    root.innerHTML = '<div style="padding:20px;color:#64748B;">Loading…</div>';
    var bar = el('<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;"></div>');
    var bAdd = el(btn('➕ Add prospect', true)), bImp = el(btn('⬆ Import list')), bSet = el(btn('⚙ Follow-up settings'));
    bAdd.onclick = function () { openEdit(null, root); }; bImp.onclick = function () { openImport(root); }; bSet.onclick = function () { openSettings(root); };
    [bAdd, bImp, bSet].forEach(function (b) { bar.appendChild(b); });
    if (S.cfg) bar.appendChild(el('<span style="margin-left:auto;font-size:12px;color:#64748B;">' + (S.cfg.follow_ups ? '🔁 Follow-ups on · ' + S.cfg.steps.length + ' step' + (S.cfg.steps.length === 1 ? '' : 's') + ' · ' + (S.cfg.mode === 'auto' ? 'emails send automatically' : 'reminders only') : '⏸ Follow-ups off') + '</span>'));
    if (S.batch == null) {
      var res = await Promise.all([Api.get('/sales/prospect-batches'), Api.get('/sales/prospects-stats').catch(function () { return null; })]);
      root.innerHTML = '';
      root.appendChild(bar);
      if (res[1]) root.appendChild(statsBlock(res[1]));
      root.appendChild(batchesTable(res[0].batches || [], root));
      return;
    }
    var p = { batch: S.batch, page: S.page, per_page: 100 }; if (S.status) p.status = S.status; if (S.q) p.q = S.q;
    S.data = await Api.get('/sales/prospects', p);
    root.innerHTML = '';
    var bMail = el(btn('✉ Email selected', true)); bMail.onclick = function () { openBulkEmail(root); };
    bar.insertBefore(bMail, bar.firstChild);
    var back = el('<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;"><a href="javascript:void 0" class="cr-back" data-kt-inpage="1" style="font-size:13px;font-weight:700;color:#0E7C90;text-decoration:none;">← All batches</a>'
      + '<b style="font-size:17px;color:#0F172A;">' + esc(S.batchName) + '</b><span style="font-size:12.5px;color:#64748B;">' + (S.data.total || 0).toLocaleString() + ' prospect' + (S.data.total === 1 ? '' : 's') + (S.status || S.q ? ' match' : '') + '</span></div>');
    back.querySelector('.cr-back').onclick = function () { S.batch = null; S.sel = {}; S.selAll = false; S.status = ''; S.q = ''; S.page = 1; draw(root); };
    root.appendChild(back);
    root.appendChild(bar);
    root.appendChild(filters(root));
    root.appendChild(table(root));
    root.appendChild(pager(root));
  }

  function batchesTable(list, root) {
    var wrap = el('<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;overflow:auto;"></div>');
    if (!list.length) { wrap.innerHTML = '<div class="kt-list-empty" style="padding:36px;text-align:center;color:#64748B;font-size:13px;">No prospects yet. Import a list (Excel or CSV) or add one by hand to start reaching out.</div>'; return wrap; }
    var n = function (v) { return Number(v || 0).toLocaleString(); };
    var pct = function (a, b) { return b ? Math.round(a / b * 100) + '%' : '—'; };
    var t = el('<table data-kt-filtered="1" style="width:100%;border-collapse:collapse;font-size:13px;min-width:860px;"><thead><tr style="background:#F8FAFC;text-align:left;">'
      + ['Batch', 'Prospects', 'With email', 'Flagged', 'Contacted', 'Opened', 'Replied', 'Converted', 'Queued', 'Due', ''].map(function (h, i) { return '<th style="padding:8px 10px;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;' + (i && i < 10 ? 'text-align:right;' : '') + '">' + h + '</th>'; }).join('')
      + '</tr></thead><tbody></tbody></table>');
    var tb = t.querySelector('tbody');
    list.forEach(function (b) {
      var num = function (v, extra) { return '<td style="padding:9px 10px;text-align:right;font-variant-numeric:tabular-nums;' + (extra || '') + '">' + v + '</td>'; };
      var tr = el('<table><tr style="border-top:1px solid #F1F4F8;cursor:pointer;">'
        + '<td style="padding:9px 10px;min-width:220px;"><b style="color:#0F172A;">📁 ' + esc(b.name) + '</b><span style="display:block;font-size:11.5px;color:#64748B;">' + esc([b.created_at ? when(b.created_at) : '', b.by ? 'by ' + b.by : '', b.source_file || ''].filter(Boolean).join(' · ')) + '</span></td>'
        + num('<b>' + n(b.total) + '</b>') + num(n(b.with_email) + ' <span style="color:#94A3B8;font-size:11px;">' + pct(b.with_email, b.total) + '</span>')
        + num(b.flagged ? '⚠ ' + n(b.flagged) : '0', b.flagged ? 'color:#B45309;font-weight:800;' : '')
        + num(n(b.contacted)) + num(n(b.opened), b.opened ? 'color:#6D28D9;font-weight:700;' : '') + num(n(b.replied), b.replied ? 'color:#047857;font-weight:700;' : '')
        + num(n(b.converted)) + num(n(b.queued)) + num(n(b.due), b.due ? 'color:#B45309;font-weight:800;' : '')
        + '<td style="padding:6px 8px;text-align:right;white-space:nowrap;"></td></tr></table>').querySelector('tr');
      var act = tr.lastElementChild;
      var A = function (label, fn) { var x = el('<button type="button" style="font-size:12px;padding:4px 8px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;margin-left:4px;">' + label + '</button>'); x.onclick = function (e) { e.stopPropagation(); fn(); }; act.appendChild(x); };
      var open = function () { S.batch = String(b.id); S.batchName = b.name; S.page = 1; S.sel = {}; S.selAll = false; S.status = ''; S.q = ''; draw(root); };
      A('📂 Open', open);
      if (b.ready) A('✉ Email this batch', function () { S.batch = String(b.id); S.batchName = b.name; S.status = ''; S.q = ''; S.selAll = true; S.sel = {}; openBulkEmail(root); });
      if (b.id !== 'none') {
        A('🔁 Re-check for people we know', function () {
          toast('Checking ' + n(b.total) + ' prospects…');
          Api.post('/sales/prospect-batches/' + b.id + '/recheck', {}).then(function (r) { toast((r.flagged ? n(r.flagged) + ' flagged' : 'Nothing flagged') + ' in “' + b.name + '”'); draw(root); }).catch(function (e) { toast(e.message || 'Could not check', 'error'); });
        });
        A('✏️ Rename', function () {
          var body = form(field('Batch name', '<input name="n" style="' + INP + '" value="' + esc(b.name) + '">'));
          Shell.Modal.open({ title: 'Rename batch', body: body, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: async function () { await Api.patch('/sales/prospect-batches/' + b.id, { name: val(body, '[name=n]') }); toast('Renamed'); draw(root); } }] });
        });
        A('🗑 Delete batch', function () {
          confirmDo('Delete the batch “' + b.name + '”? Prospects you already called, emailed or converted are kept; the rest are removed.', function () {
            return Api.delete('/sales/prospect-batches/' + b.id).then(function (r) { if (r && r.kept) toast(r.kept + ' contacted prospects were kept', 'success'); });
          }, 'Batch deleted', root);
        });
      }
      tr.addEventListener('click', function (e) { if (e.target.closest('button,a,input')) return; open(); });
      tb.appendChild(tr);
    });
    wrap.appendChild(t);
    return wrap;
  }

  function pager(root) {
    var d = S.data || {}, total = d.total || 0, per = d.per_page || 100, pages = Math.max(1, Math.ceil(total / per));
    var w = el('<div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:8px;font-size:12.5px;color:#475569;"></div>');
    if (pages <= 1) return w;
    var prev = el(btn('‹ Previous')), next = el(btn('Next ›'));
    prev.disabled = S.page <= 1; next.disabled = S.page >= pages;
    prev.onclick = function () { S.page--; draw(root); }; next.onclick = function () { S.page++; draw(root); };
    w.appendChild(prev); w.appendChild(el('<span>Page ' + S.page + ' of ' + pages.toLocaleString() + '</span>')); w.appendChild(next);
    return w;
  }

  function filters(root) {
    var chips = [['', 'All'], ['with_email', '✉ Has email'], ['no_email', '📞 No email'], ['flagged', '⚠ Flagged'], ['due', '⏰ Due'], ['new', 'New'], ['contacted', 'Contacted'], ['engaged', 'Opened'], ['replied', 'Replied'], ['interested', 'Interested'], ['not_interested', 'Not interested'], ['no_response', 'No response'], ['do_not_contact', 'Do not contact'], ['converted', 'Converted']];
    var w = el('<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;"></div>');
    chips.forEach(function (c) {
      var on = S.status === c[0];
      var b = el('<button type="button" style="height:28px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid ' + (on ? '#0E7C90' : '#E2E8F0') + ';background:' + (on ? '#E6F4F6' : '#fff') + ';color:' + (on ? '#0E7C90' : '#475569') + ';">' + esc(c[1]) + '</button>');
      b.onclick = function () { S.status = c[0]; S.page = 1; S.sel = {}; S.selAll = false; draw(root); };
      w.appendChild(b);
    });
    var q = el('<input type="search" placeholder="🔍 Search name, centre, email, city…" style="' + INP + 'width:240px;margin-left:auto;" value="' + esc(S.q) + '">');
    var go = function () { S.q = q.value.trim(); S.page = 1; S.sel = {}; S.selAll = false; draw(root); };
    if (KT.onSearchCommit) KT.onSearchCommit(q, go); else q.addEventListener('change', go);
    w.appendChild(q);
    return w;
  }

  function table(root) {
    var rows = (S.data && S.data.prospects) || [], total = (S.data && S.data.total) || 0;
    var outer = el('<div></div>');
    var banner = el('<div class="cr-selbar" style="display:none;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;padding:8px 12px;border-radius:10px;background:#E6F4F6;color:#0E5E68;font-size:13px;"></div>');
    outer.appendChild(banner);
    var wrap = el('<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;overflow:auto;"></div>');
    outer.appendChild(wrap);
    if (!rows.length) { wrap.innerHTML = '<div class="kt-list-empty" style="padding:36px;text-align:center;color:#64748B;font-size:13px;">Nobody matches.</div>'; return outer; }
    var t = el('<table data-kt-filtered="1" style="width:100%;border-collapse:collapse;font-size:13px;min-width:820px;"><thead><tr style="background:#F8FAFC;text-align:left;">'
      + '<th style="width:30px;padding:8px 10px;"><input type="checkbox" class="cr-all kt-nosw" data-kt-noswitch="1" title="Select this page"></th>'
      + ['Prospect', 'Status', 'Activity', 'Next follow-up', 'Owner', ''].map(function (h) { return '<th style="padding:8px 10px;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;">' + h + '</th>'; }).join('')
      + '</tr></thead><tbody></tbody></table>');
    var tb = t.querySelector('tbody');
    rows.forEach(function (p) {
      var ind = (p.email ? '<span title="Emails sent" style="margin-right:8px;">✉ ' + (p.emails_sent || 0) + '</span>' : '<span title="No email address - call instead" style="margin-right:8px;color:#94A3B8;">✉ none</span>')
        + (p.opens ? '<span title="Opened ' + p.opens + ' time(s), last ' + esc(when(p.last_opened_at)) + '" style="margin-right:8px;color:#6D28D9;font-weight:700;">👁 ' + p.opens + ' · ' + esc(ago(p.last_opened_at)) + '</span>' : (p.emails_sent ? '<span title="Not opened yet" style="margin-right:8px;color:#94A3B8;">👁 0</span>' : ''))
        + (p.calls ? '<span title="Calls" style="margin-right:8px;">📞 ' + p.calls + '</span>' : '')
        + (p.queued_at ? '<span title="Queued - goes out in the sending window" style="margin-right:8px;color:#0E7C90;font-weight:700;">⏳ queued</span>' : '')
        + (p.replied_at ? '<span title="Replied" style="color:#047857;font-weight:700;">↩️ replied</span>' : '')
        + (p.suppressed ? '<span title="Unsubscribed - will not be emailed" style="color:#B91C1C;">🚫 unsubscribed</span>' : '');
      var nxt = p.next_follow_up_at && p.next_step
        ? '<span style="' + (p.due ? 'color:#B45309;font-weight:800;' : 'color:#334155;') + '">' + (p.next_step.channel === 'call' ? '📞 ' : '✉ ') + esc(p.due ? 'Due now' : untilDue(p.next_follow_up_at)) + '</span><span style="display:block;font-size:11px;color:#94A3B8;">step ' + p.next_step.n + ' of ' + p.next_step.of + (p.follow_ups_paused ? ' · paused' : '') + '</span>'
        : '<span style="color:#94A3B8;">—</span>';
      var tr = el('<table><tr style="border-top:1px solid #F1F4F8;cursor:pointer;">'
        + '<td style="padding:8px 10px;" data-stop="1"><input type="checkbox" class="cr-sel kt-nosw" data-kt-noswitch="1" value="' + p.id + '"' + (S.selAll || S.sel[p.id] ? ' checked' : '') + '></td>'
        + '<td style="padding:8px 10px;min-width:200px;"><b style="color:#0F172A;">' + esc(p.organization || p.name || '—') + '</b><span style="display:block;font-size:12px;color:#64748B;">' + esc([p.organization ? p.name : '', p.email, p.phone, p.city].filter(Boolean).join(' · ')) + '</span>' + flagHtml(p.flag_kind, p.flag) + '</td>'
        + '<td style="padding:8px 10px;">' + pill(p.status, p.status_label) + '</td>'
        + '<td style="padding:8px 10px;font-size:12px;white-space:nowrap;">' + ind + '</td>'
        + '<td style="padding:8px 10px;font-size:12.5px;white-space:nowrap;">' + nxt + '</td>'
        + '<td style="padding:8px 10px;font-size:12.5px;color:#475569;">' + esc(p.owner_name || '—') + '</td>'
        + '<td style="padding:6px 8px;text-align:right;white-space:nowrap;"></td></tr></table>').querySelector('tr');
      var act = tr.lastElementChild;
      var A = function (label, fn) { var b = el('<button type="button" style="font-size:12px;padding:4px 8px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;cursor:pointer;margin-left:4px;">' + label + '</button>'); b.onclick = function (e) { e.stopPropagation(); fn(); }; act.appendChild(b); };
      A('👁 View', function () { openView(p.id, root); });
      if (p.phone) A('📞 Call', function () { callNow(p, root); });
      if (p.email && !p.suppressed && p.status !== 'do_not_contact') A('✉ Email', function () { openEmail(p, root); });
      A('📝 Log call or note', function () { openLog(p, root); });
      A('✏️ Edit', function () { openEdit(p, root); });
      if (p.status !== 'converted') A('🎯 Convert to lead', function () { convert(p, root); });
      if (p.status !== 'do_not_contact') A('🚫 Do not contact', function () { setStatus(p, 'do_not_contact', root); });
      A('🗑 Delete', function () { confirmDo('Delete ' + (p.organization || p.name) + ' from Cold reach?', function () { return Api.delete('/sales/prospects/' + p.id); }, 'Deleted', root); });
      tr.addEventListener('click', function (e) { if (e.target.closest('[data-stop],button,a,input')) return; openView(p.id, root); });
      tb.appendChild(tr);
    });
    var picked = function () { return Object.keys(S.sel).filter(function (k) { return S.sel[k]; }).length; };
    var paintBanner = function () {
      var n = picked();
      if (S.selAll) {
        banner.style.display = 'flex';
        banner.innerHTML = '<b>All ' + total.toLocaleString() + ' in this ' + (S.status || S.q ? 'filtered view' : 'batch') + ' are selected.</b><a href="javascript:void 0" class="cr-clear" style="color:#0E7C90;font-weight:700;">Clear selection</a>';
        banner.querySelector('.cr-clear').onclick = function () { S.selAll = false; S.sel = {}; t.querySelectorAll('.cr-sel,.cr-all').forEach(function (c) { c.checked = false; }); paintBanner(); };
      } else if (n && n === rows.length && total > rows.length) {
        banner.style.display = 'flex';
        banner.innerHTML = '<span>All ' + n + ' on this page are selected.</span><a href="javascript:void 0" class="cr-every" style="color:#0E7C90;font-weight:800;">Select all ' + total.toLocaleString() + ' in this ' + (S.status || S.q ? 'filtered view' : 'batch') + '</a>';
        banner.querySelector('.cr-every').onclick = function () { S.selAll = true; paintBanner(); };
      } else if (n) {
        banner.style.display = 'flex'; banner.innerHTML = '<span>' + n + ' selected</span>';
      } else banner.style.display = 'none';
    };
    t.querySelector('.cr-all').onchange = function () { var on = this.checked; S.selAll = false; t.querySelectorAll('.cr-sel').forEach(function (c) { c.checked = on; S.sel[c.value] = on; }); paintBanner(); };
    t.querySelectorAll('.cr-sel').forEach(function (c) { c.onchange = function () { if (S.selAll && !c.checked) { S.selAll = false; t.querySelectorAll('.cr-sel').forEach(function (x) { S.sel[x.value] = x.checked; }); } else S.sel[c.value] = c.checked; paintBanner(); }; });
    if (S.selAll) t.querySelector('.cr-all').checked = true;
    paintBanner();
    wrap.appendChild(t);
    return outer;
  }

  function confirmDo(msg, req, ok, root) {
    return Promise.resolve(KT.confirm ? KT.confirm(msg) : window.confirm(msg)).then(function (yes) {
      if (!yes) return;
      return req().then(function () { toast(ok); draw(root); }).catch(function (e) { toast(e.message || 'Something went wrong', 'error'); });
    });
  }
  function setStatus(p, st, root) {
    Api.patch('/sales/prospects/' + p.id, { status: st }).then(function () { toast('Updated'); draw(root); }).catch(function (e) { toast(e.message, 'error'); });
  }
  function convert(p, root) {
    confirmDo('Convert ' + (p.organization || p.name) + ' to a lead? Their calls and emails go with them.', function () {
      return Api.post('/sales/prospects/' + p.id + '/convert', {}).then(function (r) { if (r && r.lead_id) setTimeout(function () { location.hash = '#sales-lead?id=' + r.lead_id; }, 400); });
    }, 'Converted to a lead', root);
  }
  function callNow(p, root) {
    if (KT.dialer && KT.dialer.can && KT.dialer.can()) { try { KT.dialer.call(p.phone, { name: p.organization || p.name, context: 'cold_reach' }); } catch (e) {} }
    else { window.location.href = 'tel:' + String(p.phone).replace(/[^\d+]/g, ''); }
    setTimeout(function () { openLog(p, root, true); }, 600);
  }

  function form(html) { var b = el('<div style="width:min(560px,90vw);">' + html + '</div>'); return b; }
  function val(b, sel) { var x = b.querySelector(sel); return x ? (x.type === 'checkbox' ? x.checked : x.value.trim()) : ''; }

  function openEdit(p, root) {
    p = p || {};
    var d = S.data || {};
    var owners = (d.owners || []).map(function (o) { return '<option value="' + o.id + '"' + (+p.owner_id === +o.id ? ' selected' : '') + '>' + esc(o.first_name) + '</option>'; }).join('');
    var cons = Object.keys(d.consent || {}).map(function (k) { return '<option value="' + k + '"' + ((p.consent_basis || 'implied_published') === k ? ' selected' : '') + '>' + esc(d.consent[k]) + '</option>'; }).join('');
    var b = form('<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 10px;">'
      + field('Centre / organization', '<input name="organization" style="' + INP + '" value="' + esc(p.organization || '') + '">')
      + field('Contact name', '<input name="name" style="' + INP + '" value="' + esc(p.name || '') + '">')
      + field('Email', '<input name="email" type="email" style="' + INP + '" value="' + esc(p.email || '') + '">')
      + field('Phone', '<input name="phone" style="' + INP + '" value="' + esc(p.phone || '') + '">')
      + field('Title', '<input name="title" style="' + INP + '" value="' + esc(p.title || '') + '">')
      + field('Website', '<input name="website" style="' + INP + '" value="' + esc(p.website || '') + '">')
      + field('Business address', '<input name="address" style="' + INP + '" value="' + esc(p.address || '') + '">')
      + field('City', '<input name="city" style="' + INP + '" value="' + esc(p.city || '') + '">')
      + field('Province / state', '<input name="province" style="' + INP + '" value="' + esc(p.province || '') + '">')
      + field('Postal / ZIP code', '<input name="postal_code" style="' + INP + '" value="' + esc(p.postal_code || '') + '">')
      + field('List', '<input name="list_name" style="' + INP + '" value="' + esc(p.list_name || '') + '" placeholder="e.g. Toronto home daycares">')
      + field('Owner', '<select name="owner_id" style="' + INP + '">' + owners + '</select>')
      + '</div>' + field('Why we may email them (CASL)', '<select name="consent_basis" style="' + INP + '">' + cons + '</select>')
      + field('Notes', '<textarea name="notes" rows="3" style="' + TA + '">' + esc(p.notes || '') + '</textarea>'));
    Shell.Modal.open({ title: p.id ? 'Edit prospect' : 'Add prospect', body: b, actions: [{ label: 'Cancel' }, { label: p.id ? 'Save' : 'Add', primary: true, handler: async function () {
      var body = {}; ['organization', 'name', 'email', 'phone', 'title', 'website', 'address', 'city', 'province', 'postal_code', 'list_name', 'consent_basis', 'notes'].forEach(function (k) { body[k] = val(b, '[name=' + k + ']') || null; });
      var o = val(b, '[name=owner_id]'); if (o) body.owner_id = +o;
      if (!body.organization && !body.name) throw new Error('Add a name or an organization.');
      if (p.id) await Api.patch('/sales/prospects/' + p.id, body); else await Api.post('/sales/prospects', body);
      toast(p.id ? 'Saved' : 'Prospect added'); draw(root);
    } }] });
  }

  function template(p) {
    var c = S.cfg || {}; var first = c.first || { subject: '', body: '' };
    if (!p || p.status === 'new') return first;
    var st = (c.steps || [])[p.follow_up_step || 0];
    return st && st.channel === 'email' ? st : { subject: 'Re: ' + (first.subject || ''), body: '' };
  }
  function campName(id) { var c = (S.campaigns || []).filter(function (x) { return +x.id === +id; })[0]; return c ? c : null; }
  /* The designed email (a Sales campaign) when the step uses one; "write my own" switches to a plain email. */
  function designBlock(t) {
    var c = t.campaign_id ? campName(t.campaign_id) : null;
    return c ? '<div class="cr-design" style="border:1px solid #CFE6EA;background:#F3FAFB;border-radius:10px;padding:10px 12px;margin-bottom:10px;font-size:13px;color:#0F172A;">🎨 Sends the designed email <b>' + esc(c.name) + '</b><span style="display:block;font-size:12px;color:#475569;margin-top:2px;">Subject: ' + esc(c.subject) + ' · personalised, tracked, with the unsubscribe line</span>'
      + '<label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:12.5px;"><input type="checkbox" name="own"> Write my own email instead</label></div>' : '';
  }
  function wireDesign(b, t) {
    var own = b.querySelector('[name=own]'), plain = b.querySelector('.cr-plain');
    if (!own || !plain) return;
    var sync = function () { plain.style.display = own.checked ? '' : 'none'; };
    own.onchange = sync; sync();
  }
  function payload(b, t) {
    var own = b.querySelector('[name=own]');
    if (t.campaign_id && !(own && own.checked)) return { campaign_id: t.campaign_id };
    return { subject: val(b, '[name=subject]'), body: val(b, '[name=body]') };
  }
  function openEmail(p, root) {
    var t = template(p);
    var b = form('<div style="font-size:12.5px;color:#475569;margin-bottom:8px;">To <b>' + esc(p.name || p.organization) + '</b> &lt;' + esc(p.email) + '&gt; · from sales@kiddietrac.com' + (S.mailbox ? ' (saved in Sales → Email)' : '') + '</div>'
      + designBlock(t)
      + '<div class="cr-plain">' + field('Subject', '<input name="subject" style="' + INP + '" value="' + esc(t.subject || '') + '">')
      + field('Message', '<textarea name="body" rows="9" style="' + TA + '">' + esc(t.body || '') + '</textarea>')
      + '<div style="font-size:11.5px;color:#64748B;">{first_name}, {organization}, {city} and {sender_first_name} fill themselves in. Your first name, sales@ and the phone number are added as the signature, with the unsubscribe line.</div></div>');
    wireDesign(b, t);
    Shell.Modal.open({ title: (p.status === 'new' ? '✉ Email ' : '✉ Follow up with ') + (p.organization || p.name), body: b, actions: [{ label: 'Cancel' }, { label: 'Send', primary: true, busyLabel: 'Sending…', handler: async function () {
      await Api.post('/sales/prospects/' + p.id + '/email', payload(b, t));
      toast('Email sent'); draw(root);
    } }] });
  }
  /* The first email to the selection: the ticked rows, or everything in the batch / filtered view ("Select all N").
     The server counts who will actually get it and why not the rest (no email, already contacted, queued,
     unsubscribed), so the numbers are never a surprise. */
  async function openBulkEmail(root) {
    var ids = Object.keys(S.sel).filter(function (k) { return S.sel[k]; }).map(Number);
    if (!S.selAll && !ids.length) { toast('Tick the prospects to email first, or use “Select all” in the batch.', 'error'); return; }
    var scope = S.selAll ? { all: true, batch: S.batch, status: S.status || undefined, q: S.q || undefined } : { ids: ids };
    var pv;
    try { pv = await Api.post('/sales/prospects/email-preview', scope); } catch (e) { toast(e.message || 'Could not count the selection', 'error'); return; }
    var n = function (v) { return Number(v || 0).toLocaleString(); };
    var why = [];
    if (pv.no_email) why.push(n(pv.no_email) + ' have no email address (call them instead: use the “📞 No email” filter)');
    if (pv.already_contacted) why.push(n(pv.already_contacted) + ' were already contacted (their follow-ups handle them)');
    if (pv.queued) why.push(n(pv.queued) + ' are already queued');
    if (pv.unsubscribed) why.push(n(pv.unsubscribed) + ' unsubscribed');
    var t = (S.cfg && S.cfg.first) || { subject: '', body: '' };
    var cap = (S.cfg && S.cfg.daily_cap) || 192;
    var days = pv.eligible ? Math.ceil(pv.eligible / cap) : 0;
    var everyDay = !S.cfg || S.cfg.send_weekends !== false;
    var b = form('<div style="font-size:13px;color:#0F172A;margin-bottom:6px;"><b>' + n(pv.eligible) + '</b> of the ' + n(pv.total) + ' selected will get this first email.</div>'
      + (why.length ? '<ul style="margin:0 0 8px 18px;padding:0;font-size:12.5px;color:#475569;">' + why.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>' : '')
      + (pv.eligible ? '<div style="font-size:12.5px;color:#475569;margin-bottom:10px;">Each is sent separately, personalised and tracked. They go out a few at a time in your sending window, at most ' + n(cap) + ' a day' + (days > 1 ? ' — about <b>' + n(days) + (everyDay ? ' days' : ' working days') + '</b> for this many' : '') + ' — so they land in inboxes, not spam folders.</div>' : '')
      + designBlock(t)
      + '<div class="cr-plain">' + field('Subject', '<input name="subject" style="' + INP + '" value="' + esc(t.subject) + '">') + field('Message', '<textarea name="body" rows="9" style="' + TA + '">' + esc(t.body) + '</textarea>') + '</div>');
    wireDesign(b, t);
    Shell.Modal.open({ title: '✉ Email ' + n(pv.eligible) + ' prospect' + (pv.eligible === 1 ? '' : 's'), body: b, actions: [{ label: 'Cancel' }, { label: 'Queue ' + n(pv.eligible), primary: true, busyLabel: 'Queuing…', handler: async function () {
      if (!pv.eligible) throw new Error('Nobody in this selection can be emailed.');
      var body = payload(b, t); Object.keys(scope).forEach(function (k) { if (scope[k] !== undefined) body[k] = scope[k]; });
      var r = await Api.post('/sales/prospects/email-bulk', body);
      toast('Queued ' + n(r.queued) + ' — they go out a few at a time, ' + r.window + ', up to ' + r.daily_cap + ' a day'); S.sel = {}; S.selAll = false; draw(root);
    } }] });
  }

  function openLog(p, root, afterCall) {
    var oc = (S.data && S.data.call_outcomes) || {};
    var b = form(field('What happened', '<select name="kind" style="' + INP + '"><option value="call">📞 A call</option><option value="note">📝 A note</option></select>')
      + '<div class="cr-oc">' + field('Outcome', '<select name="outcome" style="' + INP + '">' + Object.keys(oc).map(function (k) { return '<option value="' + k + '">' + esc(oc[k]) + '</option>'; }).join('') + '</select>') + '</div>'
      + field('Notes', '<textarea name="body" rows="4" style="' + TA + '" placeholder="Who you spoke with, what they said, when to try again…"></textarea>'));
    var kind = b.querySelector('[name=kind]'); kind.onchange = function () { b.querySelector('.cr-oc').style.display = kind.value === 'call' ? '' : 'none'; };
    Shell.Modal.open({ title: (afterCall ? 'How did the call go? · ' : 'Log · ') + (p.organization || p.name), body: b, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: async function () {
      await Api.post('/sales/prospects/' + p.id + '/touches', { kind: kind.value, outcome: kind.value === 'call' ? val(b, '[name=outcome]') : null, body: val(b, '[name=body]') || null });
      toast('Logged'); draw(root);
    } }] });
  }

  async function openView(id, root) {
    var d; try { d = await Api.get('/sales/prospects/' + id); } catch (e) { toast(e.message || 'Not found', 'error'); return; }
    var p = d.prospect;
    var kv = function (k, v) { return v ? '<div style="display:flex;gap:10px;padding:4px 0;font-size:13px;"><span style="width:110px;color:#64748B;flex:0 0 110px;">' + k + '</span><span style="min-width:0;word-break:break-word;">' + v + '</span></div>' : ''; };
    var tl = (d.touches || []).map(function (t) {
      var ic = t.kind === 'email' ? '✉' : t.kind === 'call' ? '📞' : t.outcome === 'replied' ? '↩️' : t.outcome === 'converted' ? '🎯' : '📝';
      var meta = [when(t.created_at), t.by, t.automatic ? 'automatic' : '', t.step ? 'follow-up ' + t.step : ''].filter(Boolean).join(' · ');
      var oc = t.outcome && t.kind !== 'email' && t.outcome !== 'status' ? ' — ' + esc(String(t.outcome).replace(/_/g, ' ')) : '';
      var open = t.kind === 'email' ? (t.error ? ' <span style="color:#B91C1C;">failed: ' + esc(t.error) + '</span>' : t.opened_at ? ' <span style="color:#6D28D9;font-weight:700;">👁 opened ' + esc(when(t.opened_at)) + (t.open_count > 1 ? ' · ' + t.open_count + '×' : '') + '</span>' : ' <span style="color:#94A3B8;">not opened yet</span>') : '';
      return '<div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid #F1F4F8;"><span style="font-size:16px;">' + ic + '</span><span style="flex:1;min-width:0;">'
        + '<span style="display:block;font-size:13px;color:#0F172A;font-weight:600;">' + esc(t.subject || (t.kind === 'call' ? 'Call' : 'Note')) + oc + open + '</span>'
        + (t.body && t.kind !== 'email' ? '<span style="display:block;font-size:12.5px;color:#475569;white-space:pre-wrap;">' + esc(t.body) + '</span>' : '')
        + (t.body && t.kind === 'email' ? '<details style="font-size:12px;color:#475569;"><summary style="cursor:pointer;color:#64748B;">Message</summary><div style="white-space:pre-wrap;padding:4px 0;">' + esc(t.body) + '</div></details>' : '')
        + '<span style="display:block;font-size:11px;color:#94A3B8;">' + esc(meta) + '</span></span></div>';
    }).join('') || '<div style="font-size:12.5px;color:#94A3B8;padding:8px 0;">No calls or emails yet.</div>';
    var body = el('<div style="width:min(620px,92vw);">'
      + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">' + pill(p.status, p.status_label)
      + (p.next_follow_up_at && p.next_step ? '<span style="font-size:12px;color:' + (p.due ? '#B45309' : '#475569') + ';font-weight:700;">Next: ' + (p.next_step.channel === 'call' ? '📞 call' : '✉ email') + ' ' + esc(p.due ? 'due now' : untilDue(p.next_follow_up_at)) + ' (step ' + p.next_step.n + ' of ' + p.next_step.of + ')</span>' : '')
      + (p.lead_id ? '<a href="#sales-lead?id=' + p.lead_id + '" style="font-size:12px;font-weight:700;color:#0E7C90;">Open the lead →</a>' : '') + '</div>'
      + kv('Contact', esc([p.name, p.title].filter(Boolean).join(', '))) + kv('Email', p.email ? esc(p.email) + (p.suppressed ? ' <span style="color:#B91C1C;">(unsubscribed)</span>' : '') : '')
      + kv('Phone', esc(p.phone)) + kv('Website', p.website ? '<a href="' + esc(/^https?:/.test(p.website) ? p.website : 'https://' + p.website) + '" target="_blank" rel="noopener">' + esc(p.website) + '</a>' : '')
      + kv('Address', esc([p.address, p.city, [p.province, p.postal_code].filter(Boolean).join(' '), p.country].filter(Boolean).join(', '))) + kv('List', esc(p.list_name)) + kv('Owner', esc(p.owner_name)) + kv('Notes', p.notes ? '<span style="white-space:pre-wrap;">' + esc(p.notes) + '</span>' : '')
      + '<div style="display:flex;gap:16px;margin:10px 0 4px;font-size:12.5px;color:#334155;"><span>✉ ' + (p.emails_sent || 0) + ' sent</span><span>👁 ' + (p.opens || 0) + ' opens</span><span>📞 ' + (p.calls || 0) + ' calls</span></div>'
      + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-top:10px;">History</div>' + tl
      + (p.status === 'contacted' || p.status === 'engaged' ? '<label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;"><input type="checkbox" class="cr-pause"' + (+p.follow_ups_paused ? ' checked' : '') + '> Pause follow-ups for this prospect</label>' : '')
      + '</div>');
    var pause = body.querySelector('.cr-pause');
    if (pause) pause.onchange = function () { Api.patch('/sales/prospects/' + p.id, { follow_ups_paused: pause.checked }).then(function () { toast(pause.checked ? 'Follow-ups paused' : 'Follow-ups on'); draw(root); }); };
    var actions = [{ label: 'Close' }];
    if (p.status !== 'converted') actions.push({ label: '🎯 Convert to lead', handler: function () { convert(p, root); } });
    actions.push({ label: '📝 Log', handler: function () { setTimeout(function () { openLog(p, root); }, 0); } });
    if (p.phone) actions.push({ label: '📞 Call', handler: function () { setTimeout(function () { callNow(p, root); }, 0); } });
    if (p.email && !p.suppressed && p.status !== 'do_not_contact') actions.push({ label: '✉ Email', primary: true, handler: function () { setTimeout(function () { openEmail(p, root); }, 0); } });
    Shell.Modal.open({ title: p.organization || p.name || 'Prospect', body: body, actions: actions });
  }

  function parseCsv(text) {
    var rows = [], row = [], cur = '', q = false;
    for (var i = 0; i < text.length; i++) {
      var ch = text[i];
      if (q) { if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += ch; continue; }
      if (ch === '"') q = true; else if (ch === ',' || ch === '\t') { row.push(cur); cur = ''; } else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cur); rows.push(row); row = []; cur = ''; } else cur += ch;
    }
    if (cur !== '' || row.length) { row.push(cur); rows.push(row); }
    rows = rows.filter(function (r) { return r.some(function (c) { return String(c).trim() !== ''; }); });
    if (!rows.length) return [];
    var map = { name: /^(contact|name|contact name|full name|owner)$/i, organization: /^(organization|organisation|company|centre|center|business|daycare|program)( name)?$/i, email: /e-?mail/i, phone: /phone|tel|mobile/i, city: /^city|town$/i, province: /province|state|region/i, title: /^title|role|position$/i, website: /web|url|site/i, country: /^country$/i };
    var head = rows[0].map(function (h) { h = String(h).trim(); for (var k in map) { if (map[k].test(h)) return k; } return null; });
    return rows.slice(1).map(function (r) { var o = {}; head.forEach(function (k, i) { if (k && r[i] != null && String(r[i]).trim() !== '') o[k] = String(r[i]).trim(); }); return o; });
  }
  /* IMPORT (2026-10-30, Anthony: "have AI or system strip out the relevant important [information] to populate the cold
     lead", then "i uploaded a excel file but it says exceeded number of lines - can you not have this limit and do not
     output garbage data into the popup and only the right data structured properly and readable").
     A FILE (Excel .xlsx/.xls, .ods, .csv, .txt) goes to the server, which reads it as a spreadsheet - cells, never raw
     bytes - finds the header row and maps the columns. PASTED text is read as a table when it is one, otherwise by AI
     (rules when the AI is unavailable). Rows come back clean for a preview: tick, fix, then Import. No row limit: the
     preview pages through them and the import goes in batches. Nothing is saved before Import. */
  function openImport(root) {
    var b = form('<div style="font-size:12.5px;color:#475569;margin-bottom:8px;">Upload a spreadsheet or paste a list - a page copied from a website, email signatures, one centre per line. KiddieTrac picks out each <b>centre / company, person, title, email, phone, website and business address</b> and shows them to you before anything is saved.</div>'
      + field('Spreadsheet or file', '<input type="file" accept=".xlsx,.xls,.ods,.csv,.tsv,.txt" name="file" style="font-size:13px;"><span class="ip-fname" style="display:block;font-size:11.5px;color:#0E7C90;margin-top:3px;"></span>')
      + field('…or paste a list', '<textarea name="csv" rows="9" style="' + TA + 'font-size:12.5px;" placeholder="Sunshine Kids Daycare&#10;Jane Smith, Director&#10;123 Main Street, Barrie, ON L4M 1A1&#10;(705) 555-1234 · jane@sunshinekids.ca"></textarea>'));
    var ta = b.querySelector('[name=csv]'), fi = b.querySelector('[name=file]'), fname = b.querySelector('.ip-fname');
    fi.onchange = function () { var f = fi.files[0]; fname.textContent = f ? '📄 ' + f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB) - read when you press “Read the list”' : ''; };
    Shell.Modal.open({ title: '⬆ Import a list', body: b, actions: [{ label: 'Cancel' }, { label: '🔎 Read the list', primary: true, busyLabel: 'Reading…', handler: async function () {
      var f = fi.files[0], text = ta.value.trim(), res;
      S.importFile = f ? f.name : null;
      if (f) {
        var fd = new FormData(); fd.append('file', f, f.name);
        res = await (Api.postForm ? Api.postForm('/sales/prospects/parse-file', fd) : Api.post('/sales/prospects/parse-file', fd));
      } else if (text) {
        res = await Api.post('/sales/prospects/parse', { text: text });
      } else throw new Error('Choose a spreadsheet or paste a list first.');
      if (!res || !res.rows || !res.rows.length) throw new Error('No contacts found. A spreadsheet needs a header row (e.g. Centre, Contact, Email, Phone, Address).');
      setTimeout(function () { openImportPreview(res, root); }, 0);
    } }] });
  }
  function openImportPreview(res, root) {
    var d = S.data || {};
    var rows = res.rows.map(function (r) { r._on = !r.exists; return r; });
    var cons = Object.keys(d.consent || {}).map(function (k) { return '<option value="' + k + '"' + (k === 'implied_published' ? ' selected' : '') + '>' + esc(d.consent[k]) + '</option>'; }).join('');
    // Widths in % of one table that fits the popup (no sideways scrolling); every cell wraps, so nothing is cut off.
    var COLS = [['organization', 'Centre / company', 14], ['name', 'Person', 9], ['title', 'Title', 8], ['email', 'Email', 15], ['phone', 'Phone', 10.5], ['website', 'Website', 11.5], ['address', 'Street address', 11], ['city', 'City', 9], ['province', 'Prov.', 5], ['postal_code', 'Postal', 7]];
    var NOWRAP = { phone: 1, province: 1, postal_code: 1 };   // short values that read wrong when broken
    var cell = 'display:block;min-height:28px;padding:5px 6px;border:1px solid #E2E8F0;border-radius:6px;font:12px/1.35 inherit;font-family:inherit;width:100%;box-sizing:border-box;background:#fff;resize:none;overflow:hidden;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;';
    // The portal pins fields to one line (32px, !important); a wrapping cell has to win that with !important too.
    var grow = function (t) {
      t.style.setProperty('height', 'auto', 'important');
      t.style.setProperty('height', Math.max(28, t.scrollHeight + 2) + 'px', 'important');
      t.style.setProperty('max-height', 'none', 'important');
    };
    var PER = 100, page = 0, pages = Math.max(1, Math.ceil(rows.length / PER));
    // Which sheet each row came from (a workbook with several tabs): tick or untick a whole sheet at once.
    var sheetCount = {}; rows.forEach(function (r) { if (r.sheet) sheetCount[r.sheet] = (sheetCount[r.sheet] || 0) + 1; });
    var sheets = Object.keys(sheetCount).map(function (k) { return [k, sheetCount[k]]; });
    var dupes = rows.filter(function (r) { return r.exists; }).length;
    var how = res.method === 'ai' ? ' (read by AI)' : res.method === 'table' ? ' (read from the columns)' : '';
    var b = el('<div style="width:100%;">'
      + '<div style="font-size:12.5px;color:#475569;margin-bottom:8px;">Found <b>' + rows.length.toLocaleString() + '</b> contact' + (rows.length === 1 ? '' : 's') + how + '. Untick any you do not want and fix anything that looks wrong.' + '</div>'
      + (function () {
        var fc = res.flag_counts || {}, parts = [];
        [['contacted', 'already contacted'], ['lead', 'existing leads'], ['customer', 'existing customers'], ['unsubscribed', 'unsubscribed'], ['exists', 'already in another batch (not contacted yet)']].forEach(function (k) {
          if (fc[k[0]]) parts.push('<span style="display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;font-weight:700;color:' + FLAGCOL[k[0]][0] + ';background:' + FLAGCOL[k[0]][1] + ';">' + Number(fc[k[0]]).toLocaleString() + ' ' + k[1] + '</span>');
        });
        return parts.length ? '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:-2px 0 10px;font-size:12px;"><b style="color:#0F172A;">⚠ We already know ' + Number(dupes).toLocaleString() + ':</b>' + parts.join('') + '<span style="color:#64748B;">They are unticked; the reason is under each one.</span></div>' : '';
      })()
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 10px;">' + field('Batch name', '<input name="list_name" style="' + INP + '" value="' + esc(S.importFile ? S.importFile.replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ') : '') + '" placeholder="e.g. GTA centres Nov 2026">') + field('Why we may email them (CASL)', '<select name="consent_basis" style="' + INP + '">' + cons + '</select>') + '</div>'
      + (sheets.length > 1 ? '<div class="ip-sheets" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 8px;font-size:12px;color:#475569;"><b>Sheets:</b>' + sheets.map(function (sh) { return '<label style="display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border:1px solid #E2E8F0;border-radius:999px;background:#fff;"><input type="checkbox" class="kt-nosw" data-sheet="' + esc(sh[0]) + '" data-kt-noswitch="1" checked> ' + esc(sh[0]) + ' <span style="color:#94A3B8;">' + sh[1].toLocaleString() + '</span></label>'; }).join('') + '</div>' : '')
      + '<div style="display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:12px;color:#475569;"><label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" class="ip-all kt-nosw" data-kt-noswitch="1"' + (dupes === rows.length ? '' : ' checked') + '> All</label><span class="ip-count" style="font-weight:700;color:#0E7C90;"></span>'
      + '<span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;"><button type="button" class="ip-prev" style="' + BTN + 'height:26px;background:#fff;border:1px solid #D7DFE8;">‹</button><span class="ip-page"></span><button type="button" class="ip-next" style="' + BTN + 'height:26px;background:#fff;border:1px solid #D7DFE8;">›</button></span></div>'
      + '<div style="border:1px solid #E7EBF0;border-radius:10px;"><table data-kt-filtered="1" data-kt-no-kebab="1" data-kt-noexport="1" style="border-collapse:collapse;font-size:12px;width:100%;table-layout:fixed;"><colgroup><col style="width:30px;">' + COLS.map(function (c) { return '<col style="width:' + c[2] + '%;">'; }).join('') + '</colgroup><thead><tr style="position:sticky;top:-1px;z-index:3;">'
      + '<th style="padding:6px;background:#F8FAFC;"></th>' + COLS.map(function (c) { return '<th style="padding:6px 4px;text-align:left;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#64748B;white-space:normal;background:#F8FAFC;box-shadow:inset 0 -1px 0 #E7EBF0;">' + c[1] + '</th>'; }).join('')
      + '</tr></thead><tbody></tbody></table></div><div class="ip-prog" style="font-size:12px;color:#0E7C90;font-weight:700;margin-top:6px;"></div></div>');
    var tb = b.querySelector('tbody'), cnt = b.querySelector('.ip-count');
    var count = function () { cnt.textContent = rows.filter(function (r) { return r._on; }).length.toLocaleString() + ' ticked'; };
    var paint = function () {
      var from = page * PER;
      tb.innerHTML = rows.slice(from, from + PER).map(function (r, j) {
        var i = from + j;
        return '<tr data-i="' + i + '" style="border-top:1px solid #F1F4F8;' + (r.exists ? 'opacity:.6;' : '') + '"' + (r.sheet ? ' title="Sheet: ' + esc(r.sheet) + (r.notes ? ' · ' + esc(r.notes) : '') + '"' : '') + '><td style="padding:4px 6px;"><input type="checkbox" class="ip-sel kt-nosw" data-kt-noswitch="1"' + (r._on ? ' checked' : '') + ' title="' + esc(r.flag || '') + '"></td>'
          + COLS.map(function (c) { return '<td style="padding:3px 3px;vertical-align:top;"><textarea rows="1" data-k="' + c[0] + '" style="' + cell + (NOWRAP[c[0]] ? 'white-space:nowrap;overflow-wrap:normal;word-break:normal;' : '') + '">' + esc(r[c[0]] || '') + '</textarea></td>'; }).join('') + '</tr>'
          + (r.flag ? '<tr><td></td><td colspan="' + COLS.length + '" style="padding:0 4px 6px;">' + flagHtml(r.flag_kind, r.flag) + '</td></tr>' : '');
      }).join('');
      requestAnimationFrame(function () { tb.querySelectorAll('textarea').forEach(grow); });
      b.querySelector('.ip-page').textContent = 'Page ' + (page + 1) + ' of ' + pages;
      b.querySelector('.ip-prev').disabled = page === 0; b.querySelector('.ip-next').disabled = page >= pages - 1;
    };
    // Edits and ticks go straight into the rows, so paging never loses them.
    tb.addEventListener('input', function (e) { var tr = e.target.closest('tr'), k = e.target.getAttribute('data-k'); if (tr && k) { rows[+tr.getAttribute('data-i')][k] = e.target.value.replace(/\n/g, ' '); grow(e.target); } });
    tb.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'TEXTAREA') e.preventDefault(); });   // one-line values that wrap
    tb.addEventListener('change', function (e) { if (!e.target.classList.contains('ip-sel')) return; rows[+e.target.closest('tr').getAttribute('data-i')]._on = e.target.checked; count(); });
    b.querySelector('.ip-all').onchange = function () { var on = this.checked; rows.forEach(function (r) { r._on = on; }); paint(); count(); };
    b.querySelectorAll('[data-sheet]').forEach(function (c) { c.onchange = function () { var sh = c.getAttribute('data-sheet'), on = c.checked; rows.forEach(function (r) { if (r.sheet === sh && !r.exists) r._on = on; }); paint(); count(); }; });
    b.querySelector('.ip-prev').onclick = function () { if (page > 0) { page--; paint(); } };
    b.querySelector('.ip-next').onclick = function () { if (page < pages - 1) { page++; paint(); } };
    paint(); count();
    // This popup holds a wide table: let it use the screen (the large popup stops at 900px, which made a second,
    // sideways scrollbar). The popup body stays the one scroll region.
    setTimeout(function () {
      var m = b.closest('.modal'); if (!m) return;
      m.style.setProperty('max-width', 'min(1240px, 96vw)', 'important'); m.style.setProperty('width', 'min(1240px, 96vw)', 'important');
      tb.querySelectorAll('textarea').forEach(grow);
    }, 0);
    Shell.Modal.open({ title: '⬆ Check and import', body: b, large: true, actions: [{ label: 'Back', handler: function () { setTimeout(function () { openImport(root); }, 0); } }, { label: 'Import ticked', primary: true, busyLabel: 'Importing…', handler: async function () {
      var out = rows.filter(function (r) { return r._on; }).map(function (r) {
        var o = {}; COLS.forEach(function (c) { var v = String(r[c[0]] || '').trim(); if (v) o[c[0]] = v; }); if (r.country) o.country = r.country; if (r.notes) o.notes = r.notes; return o;
      }).filter(function (o) { return o.organization || o.name; });
      if (!out.length) throw new Error('Tick at least one row with a centre or a person.');
      var list = val(b, '[name=list_name]') || null, consent = val(b, '[name=consent_basis]'), prog = b.querySelector('.ip-prog');
      var tot = { added: 0, duplicates: 0, skipped: 0 };
      for (var i = 0; i < out.length; i += 500) {
        prog.textContent = 'Importing ' + Math.min(i + 500, out.length).toLocaleString() + ' of ' + out.length.toLocaleString() + '…';
        var r = await Api.post('/sales/prospects/import', { rows: out.slice(i, i + 500), batch_id: tot.batch || null, batch_name: list, source_file: S.importFile || null, consent_basis: consent });
        tot.added += r.added || 0; tot.duplicates += r.duplicates || 0; tot.skipped += r.skipped || 0; tot.batch = r.batch_id || tot.batch;
      }
      toast('Imported ' + tot.added.toLocaleString() + (tot.duplicates ? ' · ' + tot.duplicates + ' duplicates skipped' : '') + (tot.skipped ? ' · ' + tot.skipped + ' rows skipped' : ''));
      // Open the new batch.
      if (tot.batch) { S.batch = String(tot.batch); S.batchName = list || S.importFile || 'Import'; S.page = 1; S.sel = {}; S.selAll = false; S.status = ''; S.q = ''; }
      draw(root);
    } }] });
  }

  /* DESIGN PICKER WITH PREVIEW (2026-10-30, Anthony: "design picker with preview please"). Every designed email (Sales
     campaigns) as a live thumbnail - the real email, scaled down - plus the plain email. Click one to use it for that
     step; "Open full size" shows it at 100%. Returns to the settings with everything typed so far kept. */
  function previewHtml(id) {
    var tok = ''; try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; } catch (e) {}
    S.prevCache = S.prevCache || {};
    if (S.prevCache[id]) return Promise.resolve(S.prevCache[id]);
    return fetch((KT.API_BASE || 'https://api.kiddietrac.com/api/v1') + '/sales/campaigns/' + id + '/preview', { headers: { Authorization: 'Bearer ' + tok, Accept: 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error('Preview unavailable'); return r.text(); })
      .then(function (h) { S.prevCache[id] = h; return h; });
  }
  function openDesignPicker(cfg, which, root) {
    var cur = which === 'first' ? cfg.first.campaign_id : (cfg.steps[which - 1] || {}).campaign_id;
    var list = S.campaigns || [];
    var card = function (id, name, sub, on) {
      return '<div class="dp-card" data-id="' + (id || '') + '" style="cursor:pointer;border:2px solid ' + (on ? '#0E7C90' : '#E2E8F0') + ';border-radius:14px;background:#fff;overflow:hidden;display:flex;flex-direction:column;' + (on ? 'box-shadow:0 0 0 3px #CDEBEE;' : '') + '">'
        + '<div style="position:relative;height:300px;overflow:hidden;background:#F1F5F9;">' + (id ? '<iframe data-prev="' + id + '" sandbox="" scrolling="no" style="position:absolute;top:0;left:0;width:640px;height:1000px;border:0;transform:scale(.4);transform-origin:0 0;pointer-events:none;background:#fff;"></iframe><div style="position:absolute;inset:0;"></div>'
          : '<div style="padding:22px 18px;font:15px/1.7 Georgia,serif;color:#334155;">Hi Alex,<br><br>A short, plain email in your own words - it looks like a normal personal email, with your first name and the unsubscribe line.</div>') + '</div>'
        + '<div style="padding:8px 10px;border-top:1px solid #EEF2F6;"><div style="font-weight:800;font-size:13px;color:#0F172A;">' + (on ? '✓ ' : '') + esc(name) + '</div><div style="font-size:11.5px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(sub) + '</div>'
        + (id ? '<button type="button" data-full="' + id + '" style="margin-top:6px;' + BTN + 'height:26px;background:#fff;border:1px solid #D7DFE8;">⤢ Open full size</button>' : '') + '</div></div>';
    };
    var b = el('<div style="width:100%;"><div style="font-size:12.5px;color:#475569;margin-bottom:10px;">Choose the email for <b>' + (which === 'first' ? 'the first email' : 'step ' + which) + '</b>. These are your designed emails from Sales → Campaigns; build a new one there and it appears here.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(256px,1fr));gap:12px;">'
      + card(null, '✍️ Plain email', 'Written in the settings', !cur)
      + list.map(function (x) { return card(x.id, x.name, x.subject, +cur === +x.id); }).join('') + '</div></div>');
    b.querySelectorAll('iframe[data-prev]').forEach(function (f) { previewHtml(f.getAttribute('data-prev')).then(function (h) { f.srcdoc = h; }).catch(function () {}); });
    var pick = function (id) {
      if (which === 'first') cfg.first.campaign_id = id; else cfg.steps[which - 1].campaign_id = id;
      setTimeout(function () { openSettings(root, cfg); }, 0);
    };
    b.querySelectorAll('.dp-card').forEach(function (cd) { cd.onclick = function (e) {
      var full = e.target.closest('[data-full]');
      if (full) { e.stopPropagation(); previewHtml(full.getAttribute('data-full')).then(function (h) { var w = window.open('', '_blank'); if (w) { w.document.open(); w.document.write(h); w.document.close(); } }); return; }
      var id = cd.getAttribute('data-id'); pick(id ? +id : null);
    }; });
    Shell.Modal.open({ title: '🎨 Choose an email design', body: b, large: true, actions: [{ label: 'Back', handler: function () { setTimeout(function () { openSettings(root, cfg); }, 0); } }] });
    setTimeout(function () { var m = b.closest('.modal'); if (m) { m.style.setProperty('max-width', 'min(1180px, 96vw)', 'important'); m.style.setProperty('width', 'min(1180px, 96vw)', 'important'); } }, 0);
  }


  function openSettings(root, preset) {
    var c = preset || JSON.parse(JSON.stringify(S.cfg || {}));
    c.steps = c.steps || [];
    var b = el('<div style="width:min(680px,94vw);"></div>');
    var chk = function (name, label, on, hint) { return '<label style="display:flex;align-items:flex-start;gap:10px;padding:6px 0;font-size:13px;"><input type="checkbox" name="' + name + '"' + (on ? ' checked' : '') + '><span><b style="font-weight:600;color:#0F172A;">' + label + '</b>' + (hint ? '<span style="display:block;font-size:11.5px;color:#64748B;">' + hint + '</span>' : '') + '</span></label>'; };
    // The email design for the first email and each email step: a designed Sales campaign, or a plain email.
    var designSel = function (attr, cur) {
      return '<select ' + attr + ' style="' + INP + '"><option value="">✍️ Plain email (written below)</option>' + (S.campaigns || []).map(function (x) {
        return '<option value="' + x.id + '"' + (+cur === +x.id ? ' selected' : '') + '>🎨 ' + esc(x.name) + '</option>'; }).join('') + '</select>';
    };
    var pickBtn = function (which) { return '<button type="button" data-pick="' + which + '" style="' + BTN + 'height:28px;background:#E6F4F6;border:1px solid #9BD3DA;color:#0E7C90;">🎨 Choose with preview</button>'; };
    var testBtn = function (which) { return '<button type="button" data-test="' + which + '" style="' + BTN + 'height:28px;background:#fff;border:1px solid #D7DFE8;color:#0F172A;">✉ Send test</button>'; };
    var paint = function () {
      b.innerHTML = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 8px;padding:8px 10px;border:1px solid #E7EBF0;border-radius:10px;background:#FBFCFD;"><span style="font-size:12.5px;color:#475569;">Send test emails to</span><input type="email" name="test_to" placeholder="you@example.com" style="' + INP + 'width:240px;" value="' + esc(S.testTo || '') + '"><span style="font-size:11.5px;color:#64748B;">Each “Send test” saves these settings first, then sends one [TEST] copy there.</span></div>'
        + chk('follow_ups', 'Follow up when there is no reply', c.follow_ups, 'After the first email or call, each step below comes due after its wait. A reply, “interested”, “not interested”, an unsubscribe or converting to a lead ends it.')
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0 10px;margin-top:4px;">'
        + field('Follow-up emails', '<select name="mode" style="' + INP + '"><option value="remind"' + (c.mode !== 'auto' ? ' selected' : '') + '>Remind me, I send them</option><option value="auto"' + (c.mode === 'auto' ? ' selected' : '') + '>Send automatically</option></select>')
        + '<div style="grid-column:span 2;min-width:0;">' + field('Send between (the same time twice = all day)', '<span style="display:flex;gap:6px;align-items:center;"><input type="time" name="window_start" style="' + INP + 'flex:1;min-width:0;" value="' + esc(c.window_start) + '"><span>–</span><input type="time" name="window_end" style="' + INP + 'flex:1;min-width:0;" value="' + esc(c.window_end) + '"></span>') + '</div>'
        + field('At most per day (all cold emails)', '<input type="number" min="1" max="500" name="daily_cap" style="' + INP + '" value="' + esc(c.daily_cap) + '">')
        + field('After the last step', '<select name="after_last" style="' + INP + '"><option value="no_response"' + (c.after_last !== 'leave' ? ' selected' : '') + '>Mark “No response”</option><option value="leave"' + (c.after_last === 'leave' ? ' selected' : '') + '>Leave as it is</option></select>')
        + '</div>'
        + chk('send_weekends', 'Send every day, weekends too', c.send_weekends !== false, 'Queued and automatic emails keep going on Saturday and Sunday. Off: Monday to Friday only.')
        + chk('business_days', 'Count business days only', c.business_days, 'Follow-up waits skip Saturdays and Sundays.')
        + chk('remind_owner', 'Remind the owner when a step is due', c.remind_owner, 'A bell notification for calls, and for emails when they are not sent automatically.')
        + chk('stop_on_open', 'Stop following up once they open an email', c.stop_on_open, 'Off by default: many email apps open messages on their own, so an open alone is not a reply.')
        + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">First email</div>'
        + '<div style="display:flex;gap:8px;align-items:flex-end;"><div style="flex:1;">' + field('Email design', designSel('name="first_campaign"', c.first.campaign_id)) + '</div><div style="margin-bottom:9px;display:flex;gap:6px;">' + pickBtn('first') + testBtn('first') + '</div></div>'
        + '<div class="cr-plainf"' + (c.first.campaign_id ? ' style="display:none;"' : '') + '>' + field('Subject', '<input name="first_subject" style="' + INP + '" value="' + esc(c.first.subject) + '">')
        + field('Message', '<textarea name="first_body" rows="5" style="' + TA + '">' + esc(c.first.body) + '</textarea>') + '</div>'
        + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Follow-up steps</div>'
        + c.steps.map(function (s, i) {
          return '<div class="cr-step" data-i="' + i + '" style="border:1px solid #E7EBF0;border-radius:10px;padding:8px 10px;margin-bottom:8px;background:#FBFCFD;">'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px;"><b style="font-size:13px;">Step ' + (i + 1) + '</b>'
            + '<span style="font-size:12.5px;color:#475569;">after</span><input type="number" min="1" max="60" data-k="after_days" style="' + INP + 'width:64px;" value="' + esc(s.after_days) + '"><span style="font-size:12.5px;color:#475569;">days with no reply,</span>'
            + '<select data-k="channel" style="' + INP + 'width:auto;"><option value="email"' + (s.channel === 'email' ? ' selected' : '') + '>✉ email</option><option value="call"' + (s.channel === 'call' ? ' selected' : '') + '>📞 call</option></select>'
            + '<button type="button" data-rm="' + i + '" style="margin-left:auto;' + BTN + 'height:26px;background:#fff;border:1px solid #F1C4C4;color:#B91C1C;">Remove</button></div>'
            + (s.channel === 'email'
              ? '<div style="display:flex;gap:8px;align-items:flex-end;"><div style="flex:1;">' + field('Email design', designSel('data-k="campaign_id"', s.campaign_id)) + '</div><div style="margin-bottom:9px;display:flex;gap:6px;">' + pickBtn(i + 1) + testBtn(i + 1) + '</div></div>'
                + '<div class="cr-plains"' + (s.campaign_id ? ' style="display:none;"' : '') + '>' + field('Subject', '<input data-k="subject" style="' + INP + '" value="' + esc(s.subject) + '">') + field('Message', '<textarea data-k="body" rows="3" style="' + TA + '">' + esc(s.body) + '</textarea>') + '</div>'
              : field('Reminder', '<input data-k="body" style="' + INP + '" value="' + esc(s.body) + '">'))
            + '</div>';
        }).join('')
        + (c.steps.length < 8 ? '<button type="button" class="cr-add" style="' + BTN + 'background:#fff;border:1px dashed #94A3B8;color:#334155;">+ Add a step</button>' : '')
        + '<div style="font-size:11.5px;color:#64748B;margin-top:8px;">{first_name}, {organization}, {city} and {sender_first_name} fill themselves in. Every email carries the CASL line and an unsubscribe link, and never goes to an address that unsubscribed.</div>';
      b.querySelectorAll('[data-rm]').forEach(function (x) { x.onclick = function () { read(); c.steps.splice(+x.getAttribute('data-rm'), 1); paint(); }; });
      b.querySelectorAll('.cr-step [data-k=channel], .cr-step [data-k=campaign_id], [name=first_campaign]').forEach(function (x) { x.onchange = function () { read(); paint(); }; });
      b.querySelectorAll('[data-pick]').forEach(function (x) { x.onclick = function () { read(); var w = x.getAttribute('data-pick'); openDesignPicker(c, w === 'first' ? 'first' : +w, root); }; });
      b.querySelectorAll('[data-test]').forEach(function (x) { x.onclick = async function () {
        read();
        var to = (b.querySelector('[name=test_to]').value || '').trim();
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { toast('Type the address to send the test to first.', 'error'); return; }
        S.testTo = to; x.disabled = true; var lbl = x.textContent; x.textContent = 'Sending…';
        try {
          var r = await Api.put('/sales/prospects-settings', c); S.cfg = r.settings; c = JSON.parse(JSON.stringify(r.settings));
          await Api.post('/sales/prospects-settings/test', { to: to, which: String(x.getAttribute('data-test')) });
          toast('Test sent to ' + to);
        } catch (e) { toast(e.message || 'The test could not be sent', 'error'); }
        x.disabled = false; x.textContent = lbl;
      }; });
      var add = b.querySelector('.cr-add'); if (add) add.onclick = function () { read(); c.steps.push({ after_days: 3, channel: 'email', subject: 'Following up', body: 'Hi {first_name},\n\n' }); paint(); };
    };
    var read = function () {
      ['follow_ups', 'business_days', 'send_weekends', 'remind_owner', 'stop_on_open'].forEach(function (k) { var x = b.querySelector('[name=' + k + ']'); if (x) c[k] = x.checked; });
      ['mode', 'window_start', 'window_end', 'after_last'].forEach(function (k) { var x = b.querySelector('[name=' + k + ']'); if (x) c[k] = x.value; });
      var cap = b.querySelector('[name=daily_cap]'); if (cap) c.daily_cap = +cap.value || 192;
      var fs = b.querySelector('[name=first_subject]'), fb = b.querySelector('[name=first_body]'), fc = b.querySelector('[name=first_campaign]');
      if (fs) c.first = { subject: fs.value, body: fb.value, campaign_id: fc && fc.value ? +fc.value : null };
      var tt = b.querySelector('[name=test_to]'); if (tt) S.testTo = tt.value.trim();
      b.querySelectorAll('.cr-step').forEach(function (row) {
        var s = c.steps[+row.getAttribute('data-i')]; if (!s) return;
        row.querySelectorAll('[data-k]').forEach(function (x) { var k = x.getAttribute('data-k'); s[k] = k === 'after_days' ? (+x.value || 1) : k === 'campaign_id' ? (x.value ? +x.value : null) : x.value; });
      });
    };
    paint();
    Shell.Modal.open({ title: '⚙ Cold reach follow-ups', body: b, large: true, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, handler: async function () {
      read();
      if (c.mode === 'auto' && !(KT.confirm ? await KT.confirm('Send follow-up emails automatically? Due email steps will go out on their own, inside your sending window.') : window.confirm('Send follow-up emails automatically?'))) return false;
      var r = await Api.put('/sales/prospects-settings', c); S.cfg = r.settings; toast('Follow-up settings saved'); draw(root);
    } }] });
  }

  // Same roles and guard as the rest of Sales (platform_admin resolves to the agency_admin shell; the guard keeps other
  // agency admins out). screen-sales.js loads first and shares its guard as KT.salesGuarded.
  var guard = KT.salesGuarded || function (f) { return f; };
  ['sales_rep', 'platform_admin', 'agency_admin'].forEach(function (r) { Shell.registerScreen(r + ':sales-prospects', guard(render)); });
})();
