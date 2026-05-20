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
  async function renderTripGpsV2(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:24px;">Loading trips…</div>';
    let trips = [];
    try {
      const r = await Api.get('/operations/field-trips');
      trips = r.data || [];
    } catch (e) { /* ignore */ }

    if (!trips.length) {
      main.innerHTML = `
        <div style="padding:24px;max-width:1800px;margin:0 auto;">
          <div class="kt-page-hero">
            <h2>📍 Field-trip live tracker</h2>
            <p>Live location of staff lead on each active trip.</p>
          </div>
          <div class="kt-card" style="text-align:center;padding:48px 24px;color:#64748B;">
            <div style="font-size:64px;line-height:1;margin-bottom:18px;">🚌</div>
            <h3 style="margin:0 0 8px;color:#1F2937;">No field trips yet</h3>
            <p style="margin:0 0 18px;">Create one from <strong>Operations → Field trips</strong> to enable live GPS tracking.</p>
            <a href="#field-trips" class="kt-btn kt-btn-primary" style="display:inline-block;text-decoration:none;">Create a field trip</a>
          </div>
        </div>`;
      return;
    }

    main.innerHTML = `
      <div style="padding:24px;max-width:1800px;margin:0 auto;">
        <div class="kt-page-hero">
          <h2>📍 Field-trip live tracker</h2>
          <p>Pick a trip below to see the lead's live GPS location.</p>
        </div>
        <div class="kt-card">
          <label style="font-size:13px;font-weight:600;">Select a trip</label>
          <select id="ft-select" style="width:100%;padding:11px;border:2px solid #E2E8F0;border-radius:8px;margin-top:6px;background:white;">
            <option value="">— pick a trip —</option>
            ${trips.map(t => `<option value="${t.id}">${esc(t.title)} · ${esc(t.destination || '')} · ${esc(t.trip_date || '')}</option>`).join('')}
          </select>
          <div id="ft-detail" style="margin-top:20px;"></div>
        </div>
      </div>`;
    document.getElementById('ft-select').onchange = async (e) => {
      const tid = +e.target.value;
      const det = document.getElementById('ft-detail');
      if (!tid) { det.innerHTML = ''; return; }
      det.innerHTML = '<div style="color:#64748B;padding:14px;">Loading…</div>';
      let r;
      try { r = await Api.get(`/field-trips/${tid}/location`); }
      catch (err) {
        det.innerHTML = `<div style="background:#FEF2F2;color:#B91C1C;padding:14px;border-radius:10px;">Cannot load this trip: ${esc(err.message || 'unknown error')}</div>`;
        return;
      }
      if (!r.latest) {
        det.innerHTML = `<div style="background:#FEF3C7;color:#92400E;padding:18px;border-radius:10px;">
          <strong>${esc(r.trip ? r.trip.title : 'Trip')}</strong><br>
          No location pings yet. The staff lead needs to open this trip on their phone and tap <em>Start GPS sharing</em>.
        </div>`;
        return;
      }
      det.innerHTML = `<div class="kt-kpi-grid">
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Latitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lat).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-info"><div class="kt-kpi-label">Longitude</div><div class="kt-kpi-value">${parseFloat(r.latest.lon).toFixed(5)}</div></div>
        <div class="kt-kpi kt-kpi-success"><div class="kt-kpi-label">Last ping</div><div class="kt-kpi-value" style="font-size:18px;">${fmtTime(r.latest.recorded_at)}</div></div>
        <div class="kt-kpi"><div class="kt-kpi-label">Trail length</div><div class="kt-kpi-value">${r.trail.length}</div></div>
      </div>
      <div style="margin-top:18px;">
        <iframe width="100%" height="500" frameborder="0" style="border-radius:12px;border:1px solid #E2E8F0;"
          src="https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(r.latest.lon)-0.01},${parseFloat(r.latest.lat)-0.01},${parseFloat(r.latest.lon)+0.01},${parseFloat(r.latest.lat)+0.01}&layer=mapnik&marker=${r.latest.lat},${r.latest.lon}"></iframe>
        <div style="margin-top:8px;font-size:12px;color:#94A3B8;">
          <a href="https://www.openstreetmap.org/?mlat=${r.latest.lat}&mlon=${r.latest.lon}#map=15/${r.latest.lat}/${r.latest.lon}" target="_blank" style="color:#1F6080;">View larger map</a>
        </div>
      </div>`;
    };
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
          <td style="padding:10px;border-bottom:1px solid #F1F5F9;text-align:right;color:#94A3B8;">${r.ms}ms</td>
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
