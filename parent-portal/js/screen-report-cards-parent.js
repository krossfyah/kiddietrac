/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Report cards, parent side (2026-11-04).
   Anthony: "add a report card section for parents to see their childs report cards and a
   button to request a report card from their educator (should be done each quarter)".

   guardian:report-cards. One card per child: where THIS quarter's report card stands
   (ready → View, asked for → when, neither → "Request"), then every card the centre has
   sent. Quarters run with the school year (Q1 Sep–Nov … Q4 Jun–Aug), App\Support\ReportCardQuarter.
   A parent only ever sees cards a director/admin approved and sent.
   API: GET /parent/report-cards · POST /parent/report-cards/request · GET /parent/report-cards/{id}/link
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api, Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  // "2026-11-04 16:26:50" → "Nov 4, 2026". Date part only, built locally (a bare date through new Date() lands a day early).
  function fmtDay(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    if (!m) return '';
    return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function toast(msg, kind) { if (KT.toast) KT.toast(msg, kind || 'success'); }

  /* A PDF a phone can open: the Android WebView has no viewer and cannot open blob: or <a download>
     (screen-edocuments openDocSigned), so ask for a short signed https link and hand it to the system. */
  function openUrl(url) {
    try {
      var cap = window.Capacitor;
      if (cap && cap.Plugins && cap.Plugins.Browser && typeof cap.Plugins.Browser.open === 'function') { cap.Plugins.Browser.open({ url: url }); return; }
    } catch (e) { /* the anchor below */ }
    var a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.style.display = 'none';
    document.body.appendChild(a); a.click();
    setTimeout(function () { try { a.remove(); } catch (e) {} }, 300);
  }
  async function viewCard(id, btn) {
    var was = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Opening…'; }
    try {
      var r = await Api.get('/parent/report-cards/' + id + '/link');
      if (!r || !r.url) throw new Error('no link');
      openUrl(r.url);
    } catch (e) { toast('That report card could not be opened. Please try again.', 'error'); }
    if (btn) { btn.disabled = false; btn.textContent = was; }
  }

  var BTN = 'height:30px;padding:0 12px;border-radius:8px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;';
  var B_PRIMARY = BTN + 'background:#1F6080;color:#fff;border:1px solid #1F6080;';
  var B_SOFT = BTN + 'background:#F1F5F9;color:#0F172A;border:1px solid #CBD5E1;';

  function stateRow(c, q) {
    var t = c.this_quarter || {};
    var head = '<div style="font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">This quarter</div>' +
      '<div style="font-weight:800;color:#0F172A;font-size:14.5px;margin-top:2px;">' + esc(q.label) + '</div>';
    var right;
    if (t.status === 'ready') {
      right = '<span style="color:#047857;font-weight:700;font-size:13px;">✓ Ready</span>' +
        '<button type="button" data-kt-iconized="1" data-rc-view="' + t.card_id + '" style="' + B_PRIMARY + '">📑 View</button>';
    } else if (t.status === 'requested') {
      right = '<span style="color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:4px 10px;font-size:12.5px;font-weight:700;">Requested ' + esc(fmtDay(t.requested_at)) + '</span>';
    } else {
      right = '<button type="button" data-kt-iconized="1" data-rc-ask="' + c.id + '" style="' + B_PRIMARY + '">📨 Request report card</button>';
    }
    var note = t.status === 'requested'
      ? '<div style="font-size:12.5px;color:#64748B;margin-top:6px;">' + esc(c.first_name) + '’s educators have been asked. You’ll get a notification when it’s ready.</div>'
      : '';
    return '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;">' +
      '<div>' + head + '</div><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' + right + '</div></div>' + note;
  }

  function childCard(c, q) {
    var cards = c.cards || [];
    var list = cards.length
      ? cards.map(function (r) {
          return '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 2px;border-top:1px solid #F1F5F9;">' +
            '<div style="min-width:0;"><div style="font-weight:700;color:#0F172A;font-size:14px;">' + esc(r.term) + '</div>' +
            '<div style="font-size:12px;color:#64748B;">Sent ' + esc(fmtDay(r.sent_at)) + '</div></div>' +
            '<button type="button" data-kt-iconized="1" data-rc-view="' + r.id + '" style="' + B_SOFT + '">View</button></div>';
        }).join('')
      : '<div style="font-size:13px;color:#64748B;padding:10px 2px 2px;">No report cards yet. They appear here once the centre sends them.</div>';
    return '<div class="kt-card" style="padding:16px;margin:0;">' +
      '<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px;">' +
        '<div style="font-weight:800;font-size:17px;color:#0F172A;">' + esc(c.first_name) + '</div>' +
        (c.room ? '<div style="font-size:13px;color:#64748B;">' + esc(c.room) + '</div>' : '') +
      '</div>' + stateRow(c, q) +
      '<div style="margin-top:14px;font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">All report cards</div>' + list +
    '</div>';
  }

  async function render(main) {
    main.innerHTML = '<div style="padding:20px;">' +
      '<div class="kt-page-hero"><h2>📑 Report cards</h2>' +
      '<p>Your child’s report cards from their educators. One is written each quarter (Sep–Nov, Dec–Feb, Mar–May, Jun–Aug); ask for this quarter’s here.</p></div>' +
      '<div id="kt-prc"><div style="padding:32px;text-align:center;color:#64748B;">Loading…</div></div></div>';
    var el = main.querySelector('#kt-prc');
    var d;
    try { d = await Api.get('/parent/report-cards'); }
    catch (e) { el.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Report cards could not be loaded. Please try again.</div>'; return; }
    var kids = (d && d.children) || [], q = (d && d.quarter) || { label: '' };
    if (!kids.length) {
      el.innerHTML = '<div class="kt-card" style="text-align:center;padding:36px;color:#64748B;"><div style="font-size:40px;">📑</div>' +
        '<div style="font-weight:800;color:#0F172A;margin-top:6px;">No children on your account here</div>Report cards appear once your child is enrolled.</div>';
      return;
    }
    el.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:14px;align-items:start;">' +
      kids.map(function (c) { return childCard(c, q); }).join('') + '</div>';

    el.querySelectorAll('[data-rc-view]').forEach(function (b) {
      b.addEventListener('click', function () { viewCard(b.getAttribute('data-rc-view'), b); });
    });
    el.querySelectorAll('[data-rc-ask]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = +b.getAttribute('data-rc-ask');
        var kid = kids.find(function (k) { return k.id === id; }) || {};
        b.disabled = true; b.textContent = 'Sending…';
        try {
          await Api.post('/parent/report-cards/request', { child_id: id });
          toast('Request sent. ' + (kid.first_name || 'Your child') + '’s educators have been asked for the ' + q.label + ' report card.');
          render(main);
        } catch (e) {
          b.disabled = false; b.textContent = '📨 Request report card';
          toast((e && e.message) || 'The request could not be sent. Please try again.', 'error');
        }
      });
    });
  }

  KT.ParentReportCards = { render: render };
  if (Shell && Shell.registerScreen) Shell.registerScreen('guardian:report-cards', render);
})(window);
