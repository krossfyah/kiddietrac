/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p69 — QA fixes
   1) Help search bar bleed → fix CSS background + shadow
   2) Field-trip GPS → replace number input with trip <select>
   3) System Status QA page → live smoke test of every feature
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';
  const { Api, Shell } = window.KT || {};

  // ─── 1) Patch help search bar styles (override v22p68) ────────────
  function injectV22p69CssFixes() {
    if (document.getElementById('kt-v22p69-fixes')) return;
    const s = document.createElement('style');
    s.id = 'kt-v22p69-fixes';
    s.textContent = `
      /* Fix help search bar bleed-through on scroll */
      .kt-help-search-wrap {
        background: #F9FAFB;
        padding: 10px 0;
        border-radius: 14px;
        box-shadow: 0 6px 18px -10px rgba(15, 23, 42, 0.30), 0 0 0 1px rgba(0,0,0,0.04);
      }
      .kt-help-search-wrap::before {
        content: '';
        position: absolute;
        left: 0; right: 0; top: -12px; height: 12px;
        background: linear-gradient(to bottom, rgba(249, 250, 251, 0), rgba(249, 250, 251, 1));
        pointer-events: none;
      }
      .kt-help-search-wrap { position: sticky; }

      /* Better toast */
      .kt-v22p69-toast {
        position: fixed; bottom: 92px; left: 50%; transform: translateX(-50%);
        background: #0F172A; color: white; padding: 12px 22px; border-radius: 28px;
        font-size: 14px; font-weight: 600; z-index: 99999;
        box-shadow: 0 8px 24px rgba(0,0,0,0.3); animation: kt-toast-in 0.3s;
        max-width: 90vw;
      }
      .kt-v22p69-toast.success { background: #10B981; }
      .kt-v22p69-toast.error { background: #DC2626; }
      .kt-v22p69-toast.info { background: #1F6080; }
      @keyframes kt-toast-in { from { opacity: 0; transform: translate(-50%, 12px); } to { opacity: 1; transform: translate(-50%, 0); } }
    `;
    document.head.appendChild(s);
  }

  // ─── 2) Better toast (replaces alert-fallback) ────────────────────
  if (!(window.KT && window.KT.toast)) {
    window.KT = window.KT || {};
    window.KT.toast = function (msg, kind) {
      injectV22p69CssFixes();
      const t = document.createElement('div');
      t.className = 'kt-v22p69-toast ' + (kind || 'info');
      t.textContent = msg;
      document.body.appendChild(t);
      setTimeout(() => {
        t.style.transition = 'opacity 0.25s';
        t.style.opacity = '0';
        setTimeout(() => t.remove(), 250);
      }, 2400);
    };
  }

  // ─── 3) Replace renderTripGps with a friendlier version ───────────
  /* Relative time that degrades honestly. A fix from 40 minutes ago is not "where
     they are", and the screen should say so rather than showing a confident pin. */
  function ftAge(mins) {
    if (mins == null) { return { text: 'no fix yet', tone: '#94A3B8' }; }
    if (mins < 2) { return { text: 'just now', tone: '#15803D' }; }
    if (mins < 10) { return { text: mins + ' min ago', tone: '#15803D' }; }
    if (mins < 30) { return { text: mins + ' min ago', tone: '#B45309' }; }
    if (mins < 60) { return { text: mins + ' min ago', tone: '#B91C1C' }; }
    var h = Math.floor(mins / 60);
    return { text: h + 'h ' + (mins % 60) + 'm ago', tone: '#B91C1C' };
  }

  function ftStatusPill(st) {
    var s2 = String(st || '').toLowerCase();
    var map = {
      active:    { bg: '#DCFCE7', fg: '#15803D', label: 'Out now' },
      completed: { bg: '#F1F5F9', fg: '#475569', label: 'Back' },
      planned:   { bg: '#EFF6FF', fg: '#1D4ED8', label: 'Planned' },
      cancelled: { bg: '#FEE2E2', fg: '#B91C1C', label: 'Cancelled' },
    };
    var c = map[s2] || { bg: '#F1F5F9', fg: '#475569', label: s2 || 'Unknown' };
    return '<span style="background:' + c.bg + ';color:' + c.fg + ';border-radius:999px;'
      + 'padding:3px 11px;font-size:11.5px;font-weight:800;text-transform:uppercase;'
      + 'letter-spacing:.03em;">' + esc(c.label) + '</span>';
  }

  function ftPlace(label, p, icon) {
    if (!p) {
      return '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;'
        + 'color:#94A3B8;margin-bottom:3px;">' + esc(label) + '</div>'
        + '<div style="font-size:13.5px;color:#94A3B8;">No GPS fix</div></div>';
    }
    var when = p.at ? (window.KT && KT.fmtDateTime ? KT.fmtDateTime(p.at) : p.at) : '';
    return '<div style="flex:1;min-width:0;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;'
      + 'color:#94A3B8;margin-bottom:3px;">' + esc(label) + '</div>'
      + '<div style="font-size:14px;color:#0F172A;font-weight:600;line-height:1.35;">'
      + icon + ' ' + esc(p.address || (p.lat.toFixed(5) + ', ' + p.lon.toFixed(5))) + '</div>'
      + (when ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:2px;">' + esc(when) + '</div>' : '')
      + '</div>';
  }

  async function renderTripGpsV2(main) {
    main.setAttribute('data-kt-pretty', '1');

    // Today unless the picker says otherwise. Kept outside the render so paging
    // between days does not reset it.
    if (!renderTripGpsV2._date) {
      var now = new Date();
      renderTripGpsV2._date = now.getFullYear() + '-'
        + String(now.getMonth() + 1).padStart(2, '0') + '-'
        + String(now.getDate()).padStart(2, '0');
    }

    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>📍 Walks &amp; outings</h2>
        <p>Who is out, who has them, and where they are.</p>
      </div>
      <div class="kt-card" style="margin-top:16px;">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <div style="flex:0 0 auto;">
            <label style="font-size:11.5px;font-weight:800;color:#64748B;">Day</label>
            <input id="ft-date" type="date" value="${renderTripGpsV2._date}"
              style="display:block;height:34px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;margin-top:4px;">
          </div>
          <button id="ft-prev" class="kt-btn kt-btn-secondary kt-btn-sm" type="button">‹ Previous</button>
          <button id="ft-today" class="kt-btn kt-btn-secondary kt-btn-sm" type="button">Today</button>
          <button id="ft-next" class="kt-btn kt-btn-secondary kt-btn-sm" type="button">Next ›</button>
          <div style="flex:1;"></div>
          <button id="ft-refresh" class="kt-btn kt-btn-secondary kt-btn-sm" type="button">↻ Refresh</button>
        </div>
      </div>
      <div id="ft-body" style="margin-top:16px;">
        <div style="padding:24px;color:#64748B;">Loading…</div></div>
    </div>`;

    var dateEl = main.querySelector('#ft-date');
    var shift = function (days) {
      var d = new Date(renderTripGpsV2._date + 'T12:00:00');
      d.setDate(d.getDate() + days);
      renderTripGpsV2._date = d.getFullYear() + '-'
        + String(d.getMonth() + 1).padStart(2, '0') + '-'
        + String(d.getDate()).padStart(2, '0');
      dateEl.value = renderTripGpsV2._date;
      load();
    };
    main.querySelector('#ft-prev').onclick = function () { shift(-1); };
    main.querySelector('#ft-next').onclick = function () { shift(1); };
    main.querySelector('#ft-today').onclick = function () {
      var n = new Date();
      renderTripGpsV2._date = n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0')
        + '-' + String(n.getDate()).padStart(2, '0');
      dateEl.value = renderTripGpsV2._date;
      load();
    };
    dateEl.onchange = function () { renderTripGpsV2._date = dateEl.value; load(); };
    main.querySelector('#ft-refresh').onclick = function () { load(); };

    async function load() {
      var host = main.querySelector('#ft-body');
      /* The host is gone once the SPA re-renders this screen — and the poller below
         only checked that `main` was still in the document, which it is: the shell
         reuses that node and swaps its contents. So a 30-second timer kept firing into
         a #ft-body that no longer existed, and `host.innerHTML` threw
         "Cannot set properties of null". That is crash ticket #18, filed from
         index.html?signed_out=idle — the timer outlived the session itself.
         (fixed 2026-08-26) */
      if (!host) {
        if (renderTripGpsV2._timer) { clearInterval(renderTripGpsV2._timer); renderTripGpsV2._timer = null; }
        return;
      }
      host.innerHTML = '<div style="padding:24px;color:#64748B;">Loading…</div>';
      var r;
      try {
        r = await Api.get('/provider/walks/tracker?date=' + encodeURIComponent(renderTripGpsV2._date));
      } catch (e) {
        host.innerHTML = '<div class="kt-card" style="color:#B91C1C;">Could not load that day'
          + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
        return;
      }

      var trips = r.trips || [];
      if (!trips.length) {
        host.innerHTML = `<div class="kt-card" style="text-align:center;padding:44px 24px;color:#64748B;">
          <div style="font-size:54px;line-height:1;margin-bottom:14px;">🚶</div>
          <h3 style="margin:0 0 6px;color:#1F2937;">Nothing on this day</h3>
          <p style="margin:0;">No walks or outings were logged. Try another day.</p></div>`;
        return;
      }

      host.innerHTML = trips.map(function (t) {
        var age = ftAge(t.current ? t.current.age_min : null);
        var live = String(t.status || '').toLowerCase() === 'active';
        var km = (t.distance_km != null && t.distance_km !== '')
          ? Number(t.distance_km).toFixed(2) + ' km'
          : (t.distance_m != null ? (t.distance_m >= 1000 ? (t.distance_m / 1000).toFixed(2) + ' km' : Math.round(t.distance_m) + ' m') : '—');

        return `<div class="kt-card" style="margin-bottom:14px;">
          <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
              <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:4px;">
                ${ftStatusPill(t.status)}
                ${live ? '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:' + age.tone + ';"><span style="width:8px;height:8px;border-radius:50%;background:' + age.tone + ';"></span>' + esc(age.text) + '</span>' : ''}
              </div>
              <h3 style="margin:0;font-size:17px;color:#0F172A;">${esc(t.title || 'Walk')}</h3>
              <div style="font-size:13px;color:#64748B;margin-top:2px;">
                ${esc(t.centre_name || '')}${t.destination ? ' · ' + esc(t.destination) : ''}
                ${t.depart_time ? ' · left ' + esc(String(t.depart_time).slice(0, 5)) : ''}
                ${t.return_time ? ' · back ' + esc(String(t.return_time).slice(0, 5)) : ''}</div>
            </div>
            <div style="text-align:right;flex:0 0 auto;">
              <div style="font-size:20px;font-weight:900;color:#0F172A;">${esc(km)}</div>
              <div style="font-size:11.5px;color:#94A3B8;">${t.ping_count || 0} GPS point${(t.ping_count || 0) === 1 ? '' : 's'}</div>
            </div>
          </div>

          <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid #F1F5F9;">
            <div style="flex:1;min-width:180px;">
              <div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#94A3B8;margin-bottom:3px;">With</div>
              <div style="font-size:14px;color:#0F172A;font-weight:600;">🧑‍🏫 ${esc((t.lead && t.lead.name) || 'Unassigned')}</div>
              ${(t.lead && t.lead.phone) ? '<a href="tel:' + esc(t.lead.phone) + '" style="font-size:12.5px;color:#1F6080;text-decoration:none;">' + esc(t.lead.phone) + '</a>' : ''}
            </div>
            ${ftPlace('Set off from', t.from, '🏁')}
            ${ftPlace(live ? 'Right now' : 'Last seen', t.current, '📍')}
          </div>

          <div style="margin-top:14px;padding-top:14px;border-top:1px solid #F1F5F9;">
            <div style="font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#94A3B8;margin-bottom:7px;">
              Children on this outing (${(t.children || []).length})</div>
            ${(t.children || []).length
              ? '<div style="display:flex;flex-wrap:wrap;gap:7px;">' + t.children.map(function (c) {
                  return '<span style="display:inline-flex;align-items:center;gap:6px;background:#F8FAFC;'
                    + 'border:1px solid #E2E8F0;border-radius:999px;padding:5px 12px 5px 6px;font-size:13px;color:#0F172A;">'
                    + '<span style="width:22px;height:22px;border-radius:50%;background:#E2E8F0;display:inline-flex;'
                    + 'align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#475569;">'
                    + esc((c.name || '?').charAt(0)) + '</span>' + esc(c.name) + '</span>';
                }).join('') + '</div>'
              : '<div style="font-size:13px;color:#94A3B8;">No children recorded against this outing.</div>'}
          </div>

          ${t.map_url ? '<img alt="Route map" src="' + esc(t.map_url) + '" style="margin-top:14px;width:100%;max-width:520px;border-radius:12px;border:1px solid #E2E8F0;display:block;">' : ''}
        </div>`;
      }).join('');
    }

    await load();

    /* Refresh while something is actually out. Polling a day in the past is just
       noise, so the timer only runs when the day being viewed is today. */
    if (renderTripGpsV2._timer) { clearInterval(renderTripGpsV2._timer); }
    renderTripGpsV2._timer = setInterval(function () {
      /* Check the element the work depends on, not just its container: `main` survives
         a re-render, #ft-body does not. Checking the wrong one is what let this timer
         run on after the screen had gone. */
      if (!document.body.contains(main) || !main.querySelector('#ft-body')) {
        clearInterval(renderTripGpsV2._timer); renderTripGpsV2._timer = null; return;
      }
      var n = new Date();
      var today = n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0')
        + '-' + String(n.getDate()).padStart(2, '0');
      if (renderTripGpsV2._date === today) { load(); }
    }, 30000);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function fmtTime(d) { return d ? new Date(d).toLocaleString() : ''; }

  // ─── 4) System Status QA page ──────────────────────────────────────
  async function renderSystemStatus(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = `<div style="padding:24px;max-width:1800px;margin:0 auto;">
      <div class="kt-page-hero">
        <h2>🩺 System status</h2>
        <p>Live health check across every feature. Run any time to see what's working.</p>
      </div>
      <div class="kt-card">
        <button id="ss-run" class="kt-btn kt-btn-primary">▶ Run full smoke test</button>
        <div id="ss-progress" style="margin-top:18px;color:#64748B;"></div>
        <div id="ss-results" style="margin-top:18px;"></div>
      </div>
    </div>`;

    document.getElementById('ss-run').onclick = async () => {
      const progress = document.getElementById('ss-progress');
      const results = document.getElementById('ss-results');
      results.innerHTML = '';

      const checks = [
        { name: 'Help & guide', path: '/help', method: 'GET' },
        { name: 'Help home panels', path: '/help/dashboard', method: 'GET' },
        { name: 'Photos feed', path: '/photos/feed', method: 'GET' },
        { name: 'Photo tags (id=1)', path: '/photos/1/tags', method: 'GET', skipOnEmpty: 'photos' },
        { name: 'Conferences', path: '/conferences/slots', method: 'GET' },
        { name: 'Field trips list', path: '/operations/field-trips', method: 'GET' },
        { name: 'Attendance weekly overview', path: '/attendance/weekly-overview', method: 'GET' },
        { name: 'Support tickets', path: '/tickets', method: 'GET' },
        { name: 'Activity zones (centre 1)', path: '/zones?centre_id=1', method: 'GET' },
        { name: 'Family directory', path: '/directory', method: 'GET' },
        { name: 'Report cards (child 1)', path: '/report-cards/child/1', method: 'GET' },
        { name: 'Wellness today', path: '/wellness/today', method: 'GET' },
        { name: 'Closures list', path: '/closures/list', method: 'GET' },
        { name: 'CACFP roster (centre 1)', path: '/cacfp/roster?centre_id=1', method: 'GET' },
        { name: 'Immunization due', path: '/immunization/agency/due', method: 'GET' },
        { name: 'Custom forms list', path: '/forms', method: 'GET' },
        { name: 'Audit log', path: '/audit-logs?limit=5', method: 'GET' },
        { name: 'Notifications', path: '/notifications/inbox', method: 'GET' },
      ];

      let pass = 0, fail = 0, n = 0;
      const rows = [];
      for (const c of checks) {
        n++;
        progress.textContent = `Running ${n}/${checks.length}: ${c.name}…`;
        let code, body;
        try {
          const t0 = performance.now();
          const r = await fetch('/api/v1' + c.path, {
            headers: {
              'Authorization': 'Bearer ' + sessionStorage.kt_token,
              'Accept': 'application/json',
              'X-Active-Agency-Id': '1',
            },
          });
          code = r.status;
          const t1 = performance.now();
          body = await r.text();
          rows.push({ ...c, code, ms: Math.round(t1 - t0), body: body.slice(0, 200) });
          if (code >= 200 && code < 300) pass++; else fail++;
        } catch (e) {
          fail++;
          rows.push({ ...c, code: 'ERR', ms: 0, body: e.message });
        }
      }
      progress.textContent = `Done. ${pass} passed · ${fail} failed.`;
      const badge = (code) => {
        if (code >= 200 && code < 300) return `<span style="background:#DCFCE7;color:#15803D;padding:3px 10px;border-radius:8px;font-weight:700;font-size:12px;">${code} OK</span>`;
        if (code === 404 || code === 403) return `<span style="background:#FEF3C7;color:#92400E;padding:3px 10px;border-radius:8px;font-weight:700;font-size:12px;">${code}</span>`;
        return `<span style="background:#FEE2E2;color:#B91C1C;padding:3px 10px;border-radius:8px;font-weight:700;font-size:12px;">${code} FAIL</span>`;
      };
      results.innerHTML = `<table style="width:100%;border-collapse:collapse;">
        <thead><tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E2E8F0;">Feature</th>
          <th style="text-align:left;padding:10px;border-bottom:2px solid #E2E8F0;">Endpoint</th>
          <th style="text-align:right;padding:10px;border-bottom:2px solid #E2E8F0;">Time</th>
          <th style="text-align:right;padding:10px;border-bottom:2px solid #E2E8F0;">Status</th>
        </tr></thead>
        <tbody>${rows.map(r => `<tr>
          <td style="padding:10px;border-bottom:1px solid #F1F5F9;"><strong>${esc(r.name)}</strong></td>
          <td style="padding:10px;border-bottom:1px solid #F1F5F9;color:#64748B;font-family:monospace;font-size:13px;">${esc(r.method)} ${esc(r.path)}</td>
          <td style="padding:10px;border-bottom:1px solid #F1F5F9;text-align:right;color:#64748B;">${r.ms}ms</td>
          <td style="padding:10px;border-bottom:1px solid #F1F5F9;text-align:right;">${badge(r.code)}</td>
        </tr>`).join('')}</tbody></table>`;
    };
  }

  // ─── Wire up ──────────────────────────────────────────────────────
  function init() {
    injectV22p69CssFixes();

    // Override the v22p59 renderTripGps in KT.V22p59
    if (window.KT && window.KT.V22p59 && window.KT.V22p59.renderTripGps) {
      window.KT.V22p59.renderTripGps = renderTripGpsV2;
    }

    // Register System Status screen for admins
    if (Shell && Shell.registerScreen) {
      ['agency_admin', 'platform_admin', 'centre_director'].forEach(role => {
        Shell.registerScreen(role + ':system-status', renderSystemStatus);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.KT = window.KT || {};
  window.KT.renderSystemStatus = renderSystemStatus;
})(window);
