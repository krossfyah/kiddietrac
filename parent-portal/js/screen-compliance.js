/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p47 — Compliance dashboard
   Hash: #compliance
   One pane that surfaces three of the most-asked-for "what needs
   attention" signals: expired/expiring staff certs, MFA-laggard
   directors/admins, and centres at or near license capacity.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return d; } }
  function daysUntil(d) {
    if (!d) return null;
    var diff = (new Date(d).getTime() - Date.now()) / 86400000;
    return Math.round(diff);
  }

  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#16637A 0%,#7C3AED 60%,#0F172A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">✅ COMPLIANCE</div><h1>What needs attention</h1><div class="kt-hero-sub">Expired certs, MFA-laggard staff, and centres at or near capacity — one page, agency-wide.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div', { style: 'margin-top:18px;' });
    wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/admin/compliance').then(function (data) {
      Dom.clear(body);
      var s = data.summary || {};

      // Summary KPI strip
      var summary = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;' });
      summary.appendChild(kpiTile('Expired certs', s.expired_certs || 0, '#DC2626'));
      summary.appendChild(kpiTile('Expiring (30d)', s.expiring_soon || 0, '#F59E0B'));
      summary.appendChild(kpiTile('MFA not enrolled', s.mfa_laggards || 0, '#F59E0B'));
      summary.appendChild(kpiTile('At/over capacity', s.over_capacity || 0, '#DC2626'));
      summary.appendChild(kpiTile('Tight (95%+)', s.tight || 0, '#F59E0B'));
      body.appendChild(summary);

      // Two-column layout: certs + MFA, then capacity below
      var top = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;' });
      top.appendChild(renderCertsCard(data.expired_certs || []));
      top.appendChild(renderMfaCard(data.mfa_laggards || []));
      body.appendChild(top);

      body.appendChild(renderCentreCapacityCard(data.centre_ratios || []));
    }).catch(function (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function kpiTile(label, value, accent) {
    var t = Dom.el('div', {
      style: 'background:white;border-radius:12px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05);border-left:4px solid ' + accent + ';',
    });
    t.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, label));
    t.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;color:#111827;line-height:1.05;margin-top:4px;' }, String(value)));
    return t;
  }

  function cardShell(title, subtitle) {
    // NOTE: no data-kt-list here. These are small curated compliance summary
    // panels (2–5 items), not searchable record lists — the global card-list
    // enhancer (kt-list-controls.js) was injecting misplaced Filter/Sort controls
    // and miscounting (it treated the subtitle as a list item), which made the
    // dashboard look broken ("fields all over"). No auto search/sort here.
    var c = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:14px;' });
    c.appendChild(Dom.el('h3', { style: 'margin:0;font-size:14px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:1px;' }, title));
    if (subtitle) c.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin:4px 0 12px;' }, subtitle));
    return c;
  }

  function renderCertsCard(certs) {
    var card = cardShell('Staff certifications', 'Expired or expiring within 30 days');
    if (!certs.length) {
      card.appendChild(Dom.el('div', { style: 'color:#16A34A;font-weight:600;padding:14px 0;' }, '✅ Every cert is current.'));
      return card;
    }
    certs.forEach(function (c) {
      var d = daysUntil(c.expires_at);
      var color = c.status === 'expired' ? '#DC2626' : '#F59E0B';
      var label = c.status === 'expired' ? (Math.abs(d || 0) + ' day' + (Math.abs(d) === 1 ? '' : 's') + ' ago') : ('in ' + d + ' day' + (d === 1 ? '' : 's'));
      var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F4F6;font-size:13px;' });
      row.appendChild(Dom.el('div', { style: 'width:8px;height:36px;border-radius:4px;background:' + color + ';flex-shrink:0;' }));
      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      body.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, c.user_name + ' — ' + String(c.cert_type).replace(/_/g, ' ')));
      body.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;' }, 'Expires ' + fmtDate(c.expires_at) + ' (' + label + ')'));
      row.appendChild(body);
      card.appendChild(row);
    });
    return card;
  }

  function renderMfaCard(users) {
    var card = cardShell('MFA not enrolled', 'Directors + agency admins missing two-factor');
    if (!users.length) {
      card.appendChild(Dom.el('div', { style: 'color:#16A34A;font-weight:600;padding:14px 0;' }, '✅ Every director + admin has MFA enrolled.'));
      return card;
    }
    users.forEach(function (u) {
      var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F4F6;font-size:13px;' });
      row.appendChild(Dom.el('div', { style: 'width:8px;height:36px;border-radius:4px;background:#F59E0B;flex-shrink:0;' }));
      var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
      var name = (u.first_name || '') + ' ' + (u.last_name || '');
      body.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;' }, name.trim() || u.email));
      body.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;' }, u.email + (u.last_login_at ? ' · last login ' + fmtDate(u.last_login_at) : '')));
      row.appendChild(body);
      card.appendChild(row);
    });
    return card;
  }

  function renderCentreCapacityCard(centres) {
    var card = cardShell('Centre capacity', 'Enrolled vs licensed seats');
    if (!centres.length) {
      card.appendChild(Dom.el('div', { style: 'color:#6B7280;padding:14px 0;' }, 'No centres in this agency.'));
      return card;
    }
    centres.forEach(function (c) {
      var pct = c.pct || 0;
      var color = c.status === 'over_capacity' ? '#DC2626' : (c.status === 'tight' ? '#F59E0B' : '#16A34A');
      var row = Dom.el('div', { style: 'padding:10px 0;border-bottom:1px solid #F3F4F6;' });
      var top = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;' });
      top.appendChild(Dom.el('div', { style: 'font-weight:600;color:#111827;font-size:14px;' }, c.centre_name));
      top.appendChild(Dom.el('div', { style: 'font-weight:700;color:' + color + ';font-size:14px;' }, c.enrolled + ' / ' + c.license_capacity + ' · ' + pct + '%'));
      row.appendChild(top);
      var track = Dom.el('div', { style: 'height:8px;border-radius:4px;background:#F3F4F6;overflow:hidden;' });
      var fill = Dom.el('div', { style: 'height:100%;width:' + Math.min(100, pct) + '%;background:' + color + ';transition:width .25s;' });
      track.appendChild(fill);
      row.appendChild(track);
      card.appendChild(row);
    });
    return card;
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:compliance', render);
    Shell.registerScreen('platform_admin:compliance', render);
    Shell.registerScreen('centre_director:compliance', render);
  }
  KT.Compliance = { render: render };
})(window);
