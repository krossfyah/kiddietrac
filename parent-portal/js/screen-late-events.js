/* ============================================================
   KIDDIETRAC — Late pickups & arrivals: THE one section (2026-10-06)
   GET  /provider/late-events?status=        the list
   GET  /provider/late-events/options        children, close times, fee policy
   POST /provider/late-events                log (charge:true = log & charge)
   POST /provider/late-events/{id}/decide    approve (charge) / waive / decline

   Anthony: "there are 2 late pick up sections in the portal pls combine into one going
   forward". #late-pickups charged on logging; #late-events recorded for a decision. Now
   one flow: staff log it, the per-minute fee is SUGGESTED, a director or admin approves
   (the fee goes on the invoice), waives or declines — or logs and charges in one step.
   Past fees were migrated in as approved records. #late-events now opens this screen.

   Action buttons are emitted plain — kt-row-actions.js collapses the last cell into a
   kebab by itself (CONVENTIONS.md).
   ============================================================ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api = KT.Api, Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function money(n) {
    var v = Number(n);
    if (n == null || !isFinite(v)) return '—';
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(v); }
    catch (e) { return '$' + v.toFixed(2); }
  }
  function dur(mins) {
    var m = Number(mins) || 0;
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60), r = m % 60;
    return h + 'h' + (r ? ' ' + r + 'm' : '');
  }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  /* The pickup time is WALL CLOCK (typed at the centre, stored without a zone): printed
     from its parts so nothing shifts it. */
  function wall(s, dateOnly) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
    if (!m) return '—';
    var d = MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
    if (dateOnly || m[4] == null) return d;
    var h = +m[4], ap = h >= 12 ? 'PM' : 'AM';
    return d + ' · ' + ((h % 12) || 12) + ':' + m[5] + ' ' + ap;
  }
  /* Logged / decided are INSTANTS written in UTC: shown in the agency's zone. */
  function instant(s) {
    if (!s) return '—';
    try {
      var tz = (KT.agencyTz && KT.agencyTz()) || undefined;
      var iso = /[zZ]|[+-]\d\d:?\d\d$/.test(String(s)) ? String(s) : String(s).replace(' ', 'T') + 'Z';
      return new Date(iso).toLocaleString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return esc(s); }
  }
  function time12(t) {
    var m = String(t || '').match(/^(\d{2}):(\d{2})/);
    if (!m) return '';
    var h = +m[1];
    return ((h % 12) || 12) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  var TONE = {
    pending:  { bg: '#FEF3C7', fg: '#92400E', label: 'Pending' },
    approved: { bg: '#DCFCE7', fg: '#166534', label: 'Charged' },
    waived:   { bg: '#E0F2FE', fg: '#075985', label: 'Waived' },
    declined: { bg: '#F3F4F6', fg: '#475569', label: 'Declined' },
  };
  function pill(status) {
    var t = TONE[status] || { bg: '#F3F4F6', fg: '#475569', label: status || '—' };
    return '<span style="background:' + t.bg + ';color:' + t.fg + ';padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap;">' + esc(t.label) + '</span>';
  }
  var TABS = [
    { key: 'pending', label: 'Pending' },
    { key: 'approved', label: 'Charged' },
    { key: 'waived', label: 'Waived' },
    { key: 'declined', label: 'Declined' },
    { key: 'all', label: 'All' },
  ];
  var state = { status: 'pending', opts: null, firstLoad: true };

  var CSS = '#appMain .lp-btn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;border:1px solid #CBD5E1;background:#fff;color:#0F172A;font-family:inherit;white-space:nowrap}'
    + '#appMain .lp-btn.pri{background:#1F6080;border-color:#1F6080;color:#fff}'
    + '#appMain .lp-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 10px}'
    // !important + #appMain: the phone layout's big-touch-target button rules otherwise win.
    + '#appMain .lp-tabs button{height:30px !important;min-height:0 !important;width:auto !important;flex:0 0 auto !important;padding:0 12px !important;border-radius:999px !important;border:1px solid #E2E8F0;background:#fff;font-size:12.5px !important;font-weight:700;color:#475569;cursor:pointer;line-height:1 !important}'
    + '#appMain .lp-tabs button.on{background:#0F172A;border-color:#0F172A;color:#fff}'
    + '#appMain .lp-badge{background:#DC2626;color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:0 6px;margin-left:5px}'
    + '#appMain .lp-tabs button.on .lp-badge{background:#fff;color:#DC2626}'
    + '#appMain table.lp-table td,#appMain table.lp-table th{white-space:nowrap}'
    + '#appMain table.lp-table td.lp-wrap{white-space:normal;min-width:160px;max-width:280px}'
    + '#appMain .lp-sub{font-size:11.5px;color:#64748B;margin-top:2px}'
    + '.lp-ov label{display:block;font-size:12px;font-weight:700;color:#475569;margin:10px 0 4px}'
    + '.lp-ov input,.lp-ov select,.lp-ov textarea{width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #D6DEE7;border-radius:8px;font-size:13.5px;font-family:inherit;height:32px}'
    + '.lp-ov textarea{height:auto}'
    + '.lp-ov .lp-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
    + '@media(max-width:520px){.lp-ov .lp-row{grid-template-columns:1fr}}';

  async function render(main) {
    // The old section's address opens this one.
    if (String(location.hash || '').indexOf('#late-events') === 0) {
      try { history.replaceState(null, '', '#late-pickups'); } catch (e) {}
    }
    if (!document.getElementById('lp-css')) {
      var st = document.createElement('style'); st.id = 'lp-css'; st.textContent = CSS; document.head.appendChild(st);
    }
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1500px;margin:0 auto;">'
      + '<div class="kt-page-hero"><h2>⏰ Late pickups</h2>'
      + '<p>Log late pickups and arrivals as they happen. The fee is worked out for you; a director or admin approves it onto the invoice, waives it, or declines it.</p>'
      + '<div class="kt-hero-actions"><button type="button" class="lp-btn pri" id="lp-new">＋ Log late pickup</button></div></div>'
      + '<div id="lp-tabs" class="lp-tabs"></div>'
      + '<div id="lp-body"></div></div>';
    main.querySelector('#lp-new').addEventListener('click', function () { openLog(main); });
    load(main);
  }

  function paintTabs(main, counts) {
    var bar = main.querySelector('#lp-tabs');
    if (!bar) return;
    bar.innerHTML = TABS.map(function (t) {
      var n = counts && counts[t.key];
      var badge = (t.key === 'pending' && n) ? '<span class="lp-badge">' + n + '</span>' : (n ? ' · ' + n : '');
      return '<button type="button" data-lp-tab="' + t.key + '" class="' + (state.status === t.key ? 'on' : '') + '">' + esc(t.label) + badge + '</button>';
    }).join('');
    bar.querySelectorAll('[data-lp-tab]').forEach(function (b) {
      b.addEventListener('click', function () { state.status = b.getAttribute('data-lp-tab'); load(main); });
    });
  }

  async function load(main) {
    var body = main.querySelector('#lp-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;font-size:13px;">Loading…</div>';
    var d = await Api.get('/provider/late-events?status=' + encodeURIComponent(state.status))
      .catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (!main.isConnected) return;
    if (d.__err) { body.innerHTML = '<div class="kt-card" style="color:#DC2626;">Could not load: ' + esc(d.__err) + '</div>'; return; }
    // Nothing pending on first open: show everything rather than an empty tab.
    if (state.firstLoad && state.status === 'pending' && !(d.counts && d.counts.pending) && d.counts && d.counts.all) {
      state.firstLoad = false; state.status = 'all'; return load(main);
    }
    state.firstLoad = false;
    state.canDecide = !!d.can_decide;
    paintTabs(main, d.counts || {});

    var events = d.events || [];
    if (!events.length) {
      body.innerHTML = '<div class="kt-card" style="text-align:center;color:#64748B;padding:38px;">'
        + (state.status === 'pending' ? 'Nothing waiting for a decision.' : 'Nothing here yet.')
        + ' Use <b>＋ Log late pickup</b> when a family collects after closing.</div>';
      return;
    }
    body.innerHTML = '<div class="kt-card" style="padding:0;overflow:auto;">'
      + '<table class="lp-table" data-kt-export-title="Late pickups"><thead><tr>'
      + '<th>Child</th><th>What</th><th>Picked up / arrived</th><th>Late by</th><th>Suggested</th><th>Status</th><th>Fee charged</th>'
      + '<th>Logged</th><th>Logged by</th><th>Decided</th><th>Invoice</th><th>Notes</th><th></th>'
      + '</tr></thead><tbody>'
      + events.map(function (e) {
          var acts = (e.status === 'pending' && state.canDecide)
            ? '<button type="button" class="kt-btn lp-approve" data-id="' + e.id + '" title="Approve and charge the fee">Approve &amp; charge</button>'
              + '<button type="button" class="kt-btn lp-waive" data-id="' + e.id + '" title="Record it, charge nothing">Waive</button>'
              + '<button type="button" class="kt-btn lp-decline" data-id="' + e.id + '" title="Should not have been logged">Decline</button>'
            : '';
          var whenTxt = e.pickup_at ? wall(e.pickup_at) : wall(e.occurred_on, true);
          return '<tr>'
            + '<td><b>' + esc(e.child_name) + '</b><div class="lp-sub">' + esc(e.family_name || '') + (e.centre_name ? ' · ' + esc(e.centre_name) : '') + '</div></td>'
            + '<td>' + (e.kind === 'arrival' ? '🕗 Late arrival' : '🏃 Late pickup') + '</td>'
            + '<td>' + whenTxt + (e.close_time ? '<div class="lp-sub">closed ' + esc(time12(e.close_time)) + '</div>' : '') + '</td>'
            + '<td><b>' + esc(dur(e.minutes)) + '</b></td>'
            + '<td>' + (e.suggested_fee != null ? money(e.suggested_fee) : '—') + '</td>'
            + '<td>' + pill(e.status) + '</td>'
            + '<td>' + (e.status === 'approved' ? '<b>' + money(e.fee_amount) + '</b>' : '—') + '</td>'
            + '<td>' + instant(e.logged_at) + '</td>'
            + '<td>' + esc(e.recorded_by || '—') + '</td>'
            + '<td>' + (e.status !== 'pending' ? esc(e.decided_by || '—') + '<div class="lp-sub">' + instant(e.decided_at) + '</div>' : '—') + '</td>'
            + '<td>' + (e.invoice_id ? '<span title="Added to this invoice — see Accounting">' + esc(e.invoice_number || ('#' + e.invoice_id)) + '</span>'
                : (e.status === 'approved' ? '<span class="lp-sub">not yet billed</span>' : '—')) + '</td>'
            + '<td class="lp-wrap">' + (e.note ? esc(e.note) : '') + (e.decision_note ? '<div class="lp-sub">' + esc(e.decision_note) + '</div>' : '') + '</td>'
            + '<td style="text-align:right;">' + acts + '</td></tr>';
        }).join('')
      + '</tbody></table></div>';

    var byId = {};
    events.forEach(function (e) { byId[String(e.id)] = e; });
    [['lp-approve', 'approved'], ['lp-waive', 'waived'], ['lp-decline', 'declined']].forEach(function (p) {
      body.querySelectorAll('.' + p[0]).forEach(function (b) {
        b.addEventListener('click', function () { decide(main, byId[b.getAttribute('data-id')], p[1]); });
      });
    });
  }

  async function decide(main, ev, status) {
    if (!ev) return;
    var what = ev.child_name + ' — ' + (ev.kind === 'arrival' ? 'late arrival' : 'late pickup') + ', ' + dur(ev.minutes);
    var payload = { status: status };
    if (status === 'approved') {
      var r = await KT.prompt({
        title: 'Approve & charge', description: what + '. The fee goes on the family’s invoice and the parents are told.',
        fields: [
          { key: 'fee', label: 'Fee', type: 'number', value: ev.suggested_fee != null ? String(ev.suggested_fee) : '' },
          { key: 'note', label: 'Note (optional)', type: 'text', value: '' },
        ],
        okLabel: 'Charge fee',
      });
      if (r === null || r === undefined) return;
      var fee = parseFloat(r.fee);
      if (!isFinite(fee) || fee <= 0) { toast('Enter a fee, or use Waive instead.', 'error'); return; }
      payload.fee_amount = fee; payload.decision_note = (r.note || '').trim() || null;
    } else {
      var n = await KT.prompt({
        title: status === 'waived' ? 'Waive this one' : 'Decline',
        description: what + '. ' + (status === 'waived' ? 'It stays on record; nothing is charged.' : 'Recorded as not chargeable — use this when it should not have been logged.'),
        placeholder: 'Note (optional)', okLabel: status === 'waived' ? 'Waive' : 'Decline',
      });
      if (n === null || n === undefined) return;
      payload.decision_note = String(n || '').trim() || null;
    }
    try {
      var res = await Api.post('/provider/late-events/' + ev.id + '/decide', payload);
      toast(status === 'approved' ? ('Fee charged' + (res && res.billed === false ? ' — no open invoice yet, it will be billed next' : '')) : 'Saved', 'success');
      load(main);
    } catch (e) { toast((e && e.message) || 'Could not save', 'error'); }
  }

  function toast(m, kind) {
    if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(m, kind || 'info'); } else if (KT.toast) { KT.toast(kind === 'error' ? '⚠' : '✓', m, ''); }
  }

  async function openLog(main) {
    var o = state.opts || (state.opts = await Api.get('/provider/late-events/options').catch(function () { return null; }));
    if (!o) { toast('Could not load the children list', 'error'); return; }
    var pol = o.policy || { per_minute: 0, grace: 0 };
    var centresById = {};
    (o.centres || []).forEach(function (c) { centresById[c.id] = c; });
    var ov = document.createElement('div');
    ov.className = 'lp-ov';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:480px;width:100%;padding:18px 20px;box-shadow:0 18px 48px rgba(0,0,0,.28);max-height:92vh;overflow:auto;">'
      + '<div style="font-size:16px;font-weight:800;color:#0D1B2A;">Log a late pickup</div>'
      + '<div class="lp-row"><div><label for="lp-kind">What</label><select id="lp-kind"><option value="departure">Late pickup</option><option value="arrival">Late arrival</option></select></div>'
      + '<div><label for="lp-child">Child</label><select id="lp-child">' + (o.children || []).map(function (c) {
          return '<option value="' + c.id + '" data-centre="' + c.centre_id + '">' + esc(c.first_name + ' ' + (c.last_name || '')) + '</option>'; }).join('') + '</select></div></div>'
      + '<div class="lp-row" data-pick><div><label for="lp-at">Picked up at</label><input id="lp-at" type="datetime-local" value="' + esc(o.today || '') + '"></div>'
      + '<div><label for="lp-close">Centre closed at</label><input id="lp-close" type="time" value="18:00"></div></div>'
      + '<div data-arr style="display:none"><label for="lp-min">Minutes late</label><input id="lp-min" type="number" min="1" max="1440" placeholder="e.g. 20"></div>'
      + '<label for="lp-note">Notes (optional)</label><textarea id="lp-note" rows="2" maxlength="1000"></textarea>'
      + '<div id="lp-sugg" style="margin-top:10px;font-size:13px;color:#334155;background:#F8FAFC;border-radius:8px;padding:8px 10px;"></div>'
      + '<div id="lp-err" style="color:#DC2626;font-size:12.5px;min-height:16px;margin-top:6px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:6px;">'
      +   '<button type="button" class="lp-btn" id="lp-cancel">Cancel</button>'
      +   '<button type="button" class="lp-btn' + (o.can_decide ? '' : ' pri') + '" id="lp-review">Log for review</button>'
      +   (o.can_decide ? '<button type="button" class="lp-btn pri" id="lp-charge">Log &amp; charge</button>' : '')
      + '</div></div>';
    document.body.appendChild(ov);
    var $ = function (sel) { return ov.querySelector(sel); };
    function close() { ov.remove(); }
    $('#lp-cancel').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });

    function minutes() {
      if ($('#lp-kind').value === 'arrival') { return parseInt($('#lp-min').value, 10) || 0; }
      var at = $('#lp-at').value, cl = $('#lp-close').value;
      if (!at || !cl) return 0;
      var a = at.match(/T(\d{2}):(\d{2})/), c = cl.match(/(\d{2}):(\d{2})/);
      if (!a || !c) return 0;
      return (+a[1] * 60 + +a[2]) - (+c[1] * 60 + +c[2]);
    }
    function suggest() {
      var m = minutes(), box = $('#lp-sugg');
      if ($('#lp-kind').value === 'arrival') { box.textContent = m > 0 ? (m + ' min late. A late-arrival fee, if any, is set when it is approved.') : ''; return; }
      if (m <= 0) { box.textContent = 'Set the pickup time after the close time.'; return; }
      var fee = Math.max(0, m - pol.grace) * pol.per_minute;
      box.innerHTML = '<b>' + m + ' min late</b> · ' + (fee > 0
        ? 'suggested fee <b>' + money(fee) + '</b> <span style="color:#64748B">(' + money(pol.per_minute) + '/min after ' + pol.grace + ' min grace)</span>'
        : 'within the ' + pol.grace + '-minute grace period — no fee');
    }
    function syncChild() {
      var opt = $('#lp-child').selectedOptions[0];
      var c = opt && centresById[opt.getAttribute('data-centre')];
      if (c && c.close_time) { $('#lp-close').value = String(c.close_time).slice(0, 5); }
      suggest();
    }
    $('#lp-kind').addEventListener('change', function () {
      var arr = $('#lp-kind').value === 'arrival';
      $('[data-pick]').style.display = arr ? 'none' : '';
      $('[data-arr]').style.display = arr ? '' : 'none';
      suggest();
    });
    ['#lp-at', '#lp-close', '#lp-min'].forEach(function (s) { $(s).addEventListener('input', suggest); });
    $('#lp-child').addEventListener('change', syncChild);
    syncChild();

    async function submit(charge, btn) {
      var err = $('#lp-err'); err.textContent = '';
      var arr = $('#lp-kind').value === 'arrival';
      var body = { child_id: +$('#lp-child').value, kind: $('#lp-kind').value, note: ($('#lp-note').value || '').trim() || null };
      if (!body.child_id) { err.textContent = 'Choose a child.'; return; }
      if (arr) {
        body.minutes = parseInt($('#lp-min').value, 10) || 0;
        if (body.minutes < 1) { err.textContent = 'Enter how many minutes late.'; return; }
      } else {
        if (minutes() <= 0) { err.textContent = 'The pickup time must be after the close time.'; return; }
        body.pickup_at = $('#lp-at').value; body.close_time = $('#lp-close').value;
      }
      if (charge) {
        if (arr) {
          var f = await KT.prompt({ title: 'Late arrival fee', description: 'How much to charge for this late arrival?', type: 'number', placeholder: '0.00', okLabel: 'Charge fee' });
          if (f === null || f === undefined) return;
          var fv = parseFloat(f);
          if (!isFinite(fv) || fv <= 0) { err.textContent = 'Enter a fee, or log it for review instead.'; return; }
          body.fee_amount = fv;
        }
        body.charge = true;
      }
      btn.disabled = true; var was = btn.textContent; btn.textContent = 'Saving…';
      try {
        var r = await Api.post('/provider/late-events', body);
        close();
        toast(r.status === 'approved' ? ('Logged and charged ' + money(r.fee))
          : r.status === 'waived' ? 'Logged — within the grace period, no fee'
          : 'Logged — waiting for a director or admin to decide', 'success');
        state.status = r.status === 'pending' ? 'pending' : 'all';
        load(main);
      } catch (e) {
        btn.disabled = false; btn.textContent = was;
        err.textContent = (e && e.message) || 'Could not save.';
      }
    }
    $('#lp-review').addEventListener('click', function () { submit(false, $('#lp-review')); });
    if ($('#lp-charge')) { $('#lp-charge').addEventListener('click', function () { submit(true, $('#lp-charge')); }); }
  }

  KT.LateEvents = { render: render };
  KT.LatePickups = { render: render };

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (role) {
    Shell.registerScreen(role + ':late-pickups', render);
    Shell.registerScreen(role + ':late-events', render);   // the old address
  });
})(window);
