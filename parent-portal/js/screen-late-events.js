/* ============================================================
   KIDDIETRAC — Late arrivals & pick-ups (director / admin review)
   GET  /provider/late-events?status=
   POST /provider/late-events/{id}/decide

   Educators record; this is where somebody decides whether it costs the family
   anything. Action buttons are emitted plain — kt-row-actions.js collapses the last
   cell into a kebab by itself, and building one here produces a kebab inside a kebab.
   See CONVENTIONS.md.
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
    if (!isFinite(v)) return '—';
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(v); }
    catch (e) { return '$' + v.toFixed(2); }
  }

  function dur(mins) {
    var m = Number(mins) || 0;
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60), r = m % 60;
    return h + 'h' + (r ? ' ' + r + 'm' : '');
  }

  var TONE = {
    pending:  { bg: '#FEF3C7', fg: '#92400E', label: 'Pending' },
    approved: { bg: '#DCFCE7', fg: '#166534', label: 'Approved' },
    waived:   { bg: '#E0F2FE', fg: '#075985', label: 'Waived' },
    declined: { bg: '#F3F4F6', fg: '#475569', label: 'Declined' },
  };

  function pill(status) {
    var t = TONE[status] || { bg: '#F3F4F6', fg: '#475569', label: status || '—' };
    return '<span style="background:' + t.bg + ';color:' + t.fg + ';padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap;">' + esc(t.label) + '</span>';
  }

  var TABS = [
    { key: 'pending', label: 'Awaiting review' },
    { key: 'approved', label: 'Approved' },
    { key: 'waived', label: 'Waived' },
    { key: 'declined', label: 'Declined' },
    { key: 'all', label: 'All' },
  ];

  var state = { status: 'pending' };

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1200px;">'
      + '<div class="kt-page-hero"><h2>⏰ Late arrivals &amp; pick-ups</h2>'
      + '<p>Logged by educators as they happen. You decide whether each one carries a fee — nothing is charged until you say so.</p></div>'
      + '<div id="le-tabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
      + '<div id="le-body"></div></div>';

    var tabBar = main.querySelector('#le-tabs');
    function paintTabs(pending) {
      tabBar.innerHTML = TABS.map(function (t) {
        var on = state.status === t.key;
        var badge = (t.key === 'pending' && pending)
          ? '<span style="background:#DC2626;color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:1px 7px;margin-left:6px;">' + pending + '</span>' : '';
        return '<button type="button" data-le-tab="' + t.key + '" style="background:none;border:0;border-bottom:2px solid '
          + (on ? '#1F6FB2' : 'transparent') + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:'
          + (on ? '#0F172A' : '#64748B') + ';cursor:pointer;border-radius:8px 8px 0 0;">' + esc(t.label) + badge + '</button>';
      }).join('');
      tabBar.querySelectorAll('[data-le-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          state.status = b.getAttribute('data-le-tab');
          load(main);
        });
      });
    }
    paintTabs(0);
    load(main, paintTabs);
  }

  async function load(main, paintTabs) {
    var body = main.querySelector('#le-body');
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;font-size:13px;">Loading…</div>';

    var d = await Api.get('/provider/late-events?status=' + encodeURIComponent(state.status))
      .catch(function (e) { return { __err: (e && e.message) || 'error' }; });
    if (d.__err) {
      body.innerHTML = '<div class="kt-card" style="color:#DC2626;">Could not load: ' + esc(d.__err) + '</div>';
      return;
    }

    var events = d.events || [];
    if (typeof paintTabs === 'function') { paintTabs((d.counts && d.counts.pending) || 0); }
    else {
      var pb = main.querySelector('[data-le-tab="pending"] span');
      if (pb) { pb.textContent = (d.counts && d.counts.pending) || 0; }
    }

    if (! events.length) {
      body.innerHTML = '<div class="kt-card" style="text-align:center;color:#64748B;padding:38px;">'
        + (state.status === 'pending' ? 'Nothing waiting for review. ' : 'Nothing here. ')
        + 'Educators log late arrivals and pick-ups from the child’s card.</div>';
      return;
    }

    var th = 'text-align:left;padding:10px 12px;font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.6px;';
    var td = 'padding:10px 12px;font-size:13.5px;color:#0F172A;border-top:1px solid #F1F5F9;vertical-align:top;';

    body.innerHTML = '<div class="kt-card" style="padding:0;overflow:hidden;">'
      + '<table style="width:100%;border-collapse:collapse;">'
      + '<thead style="background:#F9FAFB;"><tr>'
      +   '<th style="' + th + '">Child</th><th style="' + th + '">What</th><th style="' + th + '">When</th>'
      +   '<th style="' + th + '">Late by</th><th style="' + th + '">Note</th><th style="' + th + '">Status</th>'
      +   '<th style="' + th + 'width:150px;"></th>'
      + '</tr></thead><tbody>'
      + events.map(function (e) {
          var decided = e.status !== 'pending';
          return '<tr>'
            + '<td style="' + td + '"><div style="font-weight:700;">' + esc(e.child_name) + '</div>'
            +   '<div style="font-size:12px;color:#64748B;">' + esc(e.family_name || '—') + (e.centre_name ? ' · ' + esc(e.centre_name) : '') + '</div></td>'
            + '<td style="' + td + '">' + (e.kind === 'departure' ? '🏃 Late pick-up' : '🕗 Late arrival') + '</td>'
            + '<td style="' + td + 'white-space:nowrap;">' + esc(String(e.occurred_on || '').slice(0, 10)) + '</td>'
            + '<td style="' + td + 'white-space:nowrap;font-weight:700;">' + esc(dur(e.minutes)) + '</td>'
            + '<td style="' + td + 'max-width:280px;">' + (e.note ? esc(e.note) : '<span style="color:#94A3B8;">—</span>')
            +   (e.recorded_by ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:2px;">logged by ' + esc(e.recorded_by) + '</div>' : '') + '</td>'
            + '<td style="' + td + '">' + pill(e.status)
            +   (e.status === 'approved' && e.fee_amount != null ? '<div style="font-size:12.5px;font-weight:700;color:#166534;margin-top:3px;">' + esc(money(e.fee_amount)) + '</div>' : '')
            +   (decided && e.decided_by ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:2px;">by ' + esc(e.decided_by) + '</div>' : '')
            +   (e.decision_note ? '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">' + esc(e.decision_note) + '</div>' : '') + '</td>'
            // Plain buttons: kt-row-actions.js collapses this cell into a kebab itself.
            + '<td style="' + td + 'white-space:nowrap;text-align:right;">'
            +   (decided ? '<span style="color:#94A3B8;font-size:12.5px;">Decided</span>'
                  : '<button class="le-act le-approve" data-id="' + e.id + '" type="button" title="Approve with a fee">Approve</button>'
                    + '<button class="le-act le-waive" data-id="' + e.id + '" type="button" title="Waive">Waive</button>'
                    + '<button class="le-act le-decline" data-id="' + e.id + '" type="button" title="Decline">Decline</button>')
            + '</td></tr>';
        }).join('')
      + '</tbody></table></div>';

    var byId = {};
    events.forEach(function (e) { byId[String(e.id)] = e; });
    body.querySelectorAll('.le-approve').forEach(function (b) {
      b.addEventListener('click', function () { openDecision(main, byId[b.getAttribute('data-id')], 'approved'); });
    });
    body.querySelectorAll('.le-waive').forEach(function (b) {
      b.addEventListener('click', function () { openDecision(main, byId[b.getAttribute('data-id')], 'waived'); });
    });
    body.querySelectorAll('.le-decline').forEach(function (b) {
      b.addEventListener('click', function () { openDecision(main, byId[b.getAttribute('data-id')], 'declined'); });
    });
  }

  /* One dialog for all three outcomes. The fee box only appears for Approve, because a
     waived or declined event carries no fee — the server enforces that too, so a stale
     value in a hidden field cannot leak into a charge. */
  function openDecision(main, ev, status) {
    if (! ev) { return; }
    var titles = { approved: 'Approve and charge', waived: 'Waive this one', declined: 'Decline' };
    var blurb = {
      approved: 'The family is charged the amount below.',
      waived: 'Recorded, but nothing is charged — for a first occurrence, or where the delay was not the family’s doing.',
      declined: 'Recorded as not chargeable. Use this when the event should not have been logged.',
    };

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:420px;width:100%;padding:20px 22px;box-shadow:0 18px 48px rgba(0,0,0,.28);">'
      + '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">' + esc(titles[status]) + '</div>'
      + '<div style="font-size:13px;color:#334155;margin:0 0 4px;">' + esc(ev.child_name) + ' — '
      +   (ev.kind === 'departure' ? 'late pick-up' : 'late arrival') + ', ' + esc(dur(ev.minutes))
      +   ' on ' + esc(String(ev.occurred_on || '').slice(0, 10)) + '</div>'
      + (ev.note ? '<div style="font-size:12.5px;color:#64748B;font-style:italic;margin:0 0 10px;">“' + esc(ev.note) + '”</div>' : '<div style="height:8px;"></div>')
      + '<div style="font-size:12.5px;color:#64748B;margin:0 0 12px;">' + esc(blurb[status]) + '</div>'
      + (status === 'approved'
          ? '<label for="le-fee" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Fee</label>'
            + '<input id="le-fee" type="number" min="0" step="0.01" placeholder="0.00" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;margin-bottom:12px;">'
          : '')
      + '<label for="le-note" style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Note (optional)</label>'
      + '<textarea id="le-note" rows="2" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div id="le-err" style="color:#DC2626;font-size:12.5px;min-height:17px;margin-top:6px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px;">'
      +   '<button id="le-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
      +   '<button id="le-go" style="background:' + (status === 'declined' ? '#64748B' : '#1F6080') + ';color:#fff;border:0;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">' + esc(titles[status]) + '</button>'
      + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#le-cancel').addEventListener('click', function () { overlay.remove(); });

    overlay.querySelector('#le-go').addEventListener('click', function () {
      var err = overlay.querySelector('#le-err');
      var payload = { status: status, decision_note: (overlay.querySelector('#le-note').value || '').trim() || null };
      if (status === 'approved') {
        var fee = parseFloat((overlay.querySelector('#le-fee') || {}).value);
        // Approving with no amount is almost certainly a slip — waive is the button for
        // "recorded, nothing charged".
        if (! isFinite(fee) || fee <= 0) { err.textContent = 'Enter a fee, or use Waive instead.'; return; }
        payload.fee_amount = fee;
      }
      var go = overlay.querySelector('#le-go');
      go.disabled = true; go.textContent = 'Saving…';
      Api.post('/provider/late-events/' + ev.id + '/decide', payload).then(function () {
        overlay.remove();
        if (KT.Dom && KT.Dom.toast) { KT.Dom.toast('Saved', 'success'); }
        load(main);
      }).catch(function (e) {
        go.disabled = false; go.textContent = titles[status];
        err.textContent = (e && e.message) || 'Could not save.';
      });
    });
  }

  KT.LateEvents = { render: render };

  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (role) {
    Shell.registerScreen(role + ':late-events', render);
  });
})(window);
