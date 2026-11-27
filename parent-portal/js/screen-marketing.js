/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p34 — Marketing campaigns
   Hash: #marketing-campaigns
   Available to centre_director and agency_admin (and platform_admin
   when scoped to an active agency).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  var ASSET_BASE = ((KT.Api && KT.Api.base) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
  function absUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return ASSET_BASE + p;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d; } }
  /* {first_name} (2026-11-27): the server fills it per recipient (App\Support\CampaignMerge, same spellings).
     Preview and View show it with a sample name. */
  var FIRST_NAME_RE = /\{\{?\s*first[\s_-]?name\s*\}?\}/gi;
  var SAMPLE_NAME = 'Sarah';
  function withSample(s, isHtml) { return s == null ? s : String(s).replace(FIRST_NAME_RE, isHtml ? esc(SAMPLE_NAME) : SAMPLE_NAME); }
  function normUrl(u) { if (!u) return ''; return /^https?:\/\//i.test(u) ? u : 'https://' + u; }
  function activeAgencyId() {
    try { var a = parseInt(sessionStorage.getItem('kt_active_agency_id'), 10); if (a) return a; } catch (e) {}
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); return u.agency_id || null; } catch (e) { return null; }
  }

  // Branding + plan entitlements for the campaign frame — fetched once, cached.
  // White-label is NOT a per-campaign choice: it is granted by the agency's
  // tier/package (the `white_label` feature flag). When the plan includes it,
  // the agency logo + details are applied automatically; otherwise campaigns
  // use a standard header.
  var _featCache = null;
  function loadFeatures() {
    if (_featCache) return Promise.resolve(_featCache);
    var id = activeAgencyId();
    if (!id) return Promise.resolve({ branding: {}, whiteLabel: false });
    return Api.get('/admin/agencies/' + id + '/features')
      .then(function (d) {
        _featCache = {
          branding: (d && d.branding) || {},
          whiteLabel: !!(d && d.flags && d.flags.white_label),
        };
        return _featCache;
      })
      .catch(function () { return { branding: {}, whiteLabel: false }; });
  }

  // ── List view ──────────────────────────────────────────────────────
  function render(container) {
    // #marketing-campaigns?stats=ID: one campaign's Recipients & stats, full window (2026-11-27).
    var statsId = statsIdFromHash();
    if (statsId) { renderStats(container, statsId); return; }
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#FF8A65 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📣 MARKETING</div><h1>Campaigns</h1><div class="kt-hero-sub">Newsletters, open-house invites, promotions. Compose, preview, schedule and send to the families you choose.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:18px 0;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Drafts, scheduled, and recently sent'));
    var newBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;' }, '+ New campaign');
    newBtn.addEventListener('click', function () { openComposer(null, container); });
    bar.appendChild(newBtn);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { 'data-kt-list': '1', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/marketing/campaigns').then(function (data) {
      Dom.clear(listWrap);
      if (!data.campaigns || !data.campaigns.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No campaigns yet. Click + New campaign to compose your first.'));
        return;
      }
      data.campaigns.forEach(function (c) { listWrap.appendChild(renderRow(c, container)); });
      // This list renders async (no hashchange), so nudge the kebab sweep directly.
      if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function renderRow(c, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });

    if (c.hero_image_url) {
      var thumb = Dom.el('img', { src: absUrl(c.hero_image_url), style: 'width:56px;height:56px;border-radius:8px;object-fit:cover;background:#F3F4F6;flex-shrink:0;' });
      row.appendChild(thumb);
    } else {
      row.appendChild(Dom.el('div', { style: 'width:56px;height:56px;border-radius:8px;background:#F3F4F6;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;' }, '📣'));
    }

    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;color:#111827;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, c.title));
    var meta = (c.channel || 'in_portal').replace('_', ' ') + ' · ' + (c.audience || '').replace('_', ' ');
    if (c.scheduled_for) meta += ' · scheduled for ' + fmtDate(c.scheduled_for);
    else if (c.sent_at) meta += ' · sent ' + fmtDate(c.sent_at) + (c.recipient_count != null ? ' · ' + c.recipient_count + ' recipients' : '');
    /* The email goes out after "Send now", from the scheduler (paced for a Gmail sender), so
       say where it actually is rather than implying it already arrived (2026-10-20). */
    if (c.sent_at && (c.channel === 'email' || c.channel === 'both')) {
      if (c.email_sent_at) meta += ' · emailed ' + (c.email_delivery_count || 0) + (c.email_failure_count ? ' (' + c.email_failure_count + ' failed)' : '');
      else meta += ' · email sending' + (c.email_progress ? ' (' + c.email_progress + ' / ' + (c.recipient_count || '?') + ')' : '');
      if (c.email_from) meta += ' · from ' + c.email_from;
    }
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, meta));
    row.appendChild(body);

    row.appendChild(statusPill(c.status));

    // Trailing action bar (Edit / Delete) — kt-row-actions.js collapses it into the
    // standard ⋮ kebab because the list is [data-kt-list] and this is the row's last
    // child holding only action buttons. Row click still opens the composer.
    var actionsBar = Dom.el('div', { style: 'display:flex;gap:6px;flex-shrink:0;' });
    // View (2026-11-27): the email as families get it, read-only, in the house popup. Never sends.
    var viewBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-view kt-icon-tip', 'data-kttip': 'View', 'aria-label': 'View' }, '👁️');
    viewBtn.addEventListener('click', function (e) { e.stopPropagation(); openView(c, container); });
    var editBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-edit kt-icon-tip', 'data-kttip': 'Edit', 'aria-label': 'Edit' }, '✏️');
    editBtn.addEventListener('click', function (e) { e.stopPropagation(); openComposer(c, container); });
    var delBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-danger kt-icon-tip', 'data-kttip': 'Delete', 'aria-label': 'Delete' }, '🗑️');
    delBtn.addEventListener('click', async function (e) {
      e.stopPropagation();
      if (!await KT.confirm('Delete this campaign? This cannot be undone.')) return;
      Api.delete('/marketing/campaigns/' + c.id).then(function () { render(container); });
    });
    actionsBar.appendChild(viewBtn);
    // Recipients & stats (2026-11-27): who it went to and what happened. Once something has gone out.
    if (c.sent_at || c.status === 'sent' || c.status === 'sending') {
      var statsBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-stats kt-icon-tip', 'data-kttip': 'Recipients & stats', 'aria-label': 'Recipients & stats' }, '📊');
      statsBtn.addEventListener('click', function (e) { e.stopPropagation(); openStats(c); });
      actionsBar.appendChild(statsBtn);
    }
    actionsBar.appendChild(editBtn);
    actionsBar.appendChild(delBtn);
    row.appendChild(actionsBar);

    row.addEventListener('click', function () { openComposer(c, container); });
    return row;
  }

  function statusPill(s) {
    var map = {
      draft:     { bg: '#F3F4F6', fg: '#6B7280', t: 'DRAFT' },
      scheduled: { bg: '#FEF3C7', fg: '#92400E', t: 'SCHEDULED' },
      sending:   { bg: '#DBEAFE', fg: '#1E40AF', t: 'SENDING' },
      sent:      { bg: '#DCFCE7', fg: '#166534', t: 'SENT' },
      archived:  { bg: '#FEE2E2', fg: '#991B1B', t: 'ARCHIVED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    var span = Dom.el('span', { style: 'padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;flex-shrink:0;' }, m.t);
    return span;
  }

  // ── Recipients & stats (2026-11-27, Anthony: "add a view to see who it was sent to and successfully
  //    delivered, read, or error/bounce") ─────────────────────────────────────────────────────────────
  // A full-window view of this screen (#marketing-campaigns?stats=ID), so the long list gets the house
  // table: sortable headers, filter box, row count, CSV/Excel/PDF bar and the row ⋮. Only real data:
  // where a status cannot be known (delivery receipts, clicks) the screen says so.
  var STAT_DEFS = [
    { k: 'sent',    t: 'Sent',      i: '📤', c: '#1F6080', tip: 'Our mail service accepted it and no bounce has come back. Mail services do not send delivery receipts.' },
    { k: 'opened',  t: 'Opened',    i: '👀', c: '#16A34A', tip: 'The email\'s images loaded. Approximate: blocked images show as not opened.' },
    { k: 'bounced', t: 'Bounced',   i: '↩️', c: '#DC2626', tip: 'Their mail server sent it back (checked every hour).' },
    { k: 'failed',  t: 'Failed',    i: '⚠️', c: '#B45309', tip: 'It could not be handed over at all.' },
    { k: 'held',    t: 'Held back', i: '⏸️', c: '#6B7280', tip: 'Not sent because of the email rules (not yet accepted their invite, email switched off, demo address).' },
    { k: 'pending', t: 'Waiting',   i: '⏳', c: '#7C3AED', tip: 'Not sent yet: a paced sender sends a few at a time.' },
    { k: 'clicked', t: 'Clicked',   i: '🖱️', c: '#94A3B8', tip: 'Link clicks are not tracked.' }
  ];
  var STATUS_LABEL = { sent: 'Sent, no bounce', opened: 'Opened', bounced: 'Bounced', failed: 'Failed', held: 'Held back', unknown: 'Not known' };
  var STATUS_TONE = { sent: ['#E0F2FE', '#075985'], opened: ['#DCFCE7', '#166534'], bounced: ['#FEE2E2', '#991B1B'],
    failed: ['#FEF3C7', '#92400E'], held: ['#F1F5F9', '#475569'], unknown: ['#F1F5F9', '#64748B'] };
  function when(ts) { if (!ts) return '—'; try { return KT.fmtDateTime ? KT.fmtDateTime(ts) : fmtDate(ts); } catch (e) { return fmtDate(ts); } }
  function statsIdFromHash() {
    var q = (location.hash || '').split('?')[1] || '';
    var m = q.match(/(?:^|&)stats=(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }
  function openStats(c) { location.hash = 'marketing-campaigns?stats=' + c.id; }

  function renderStats(container, id) {
    Dom.clear(container);
    container.setAttribute('data-kt-pretty', '1');
    var wrap = Dom.el('div', { style: 'padding:24px;' });
    container.appendChild(wrap);
    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#FF8A65 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📊 RECIPIENTS &amp; STATS</div><h1>Loading…</h1><div class="kt-hero-sub">Who this campaign went to, and what happened.</div>';
    wrap.appendChild(hero);
    var bar = Dom.el('div', { style: 'display:flex;gap:8px;align-items:center;margin:14px 0;flex-wrap:wrap;' });
    var allBtn = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm', 'data-kt-iconized': '1' }, '📣 All campaigns');
    allBtn.addEventListener('click', function () { location.hash = 'marketing-campaigns'; });
    bar.appendChild(allBtn);
    var viewBtn = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm', 'data-kt-iconized': '1' }, '👁️ View the email');
    bar.appendChild(viewBtn);
    wrap.appendChild(bar);
    var body = Dom.el('div', {});
    wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/marketing/campaigns/' + id + '/recipients').then(function (d) {
      var c = d.campaign || {};
      hero.querySelector('h1').textContent = c.title || 'Campaign';
      var sub = [];
      if (c.sent_at) sub.push('Sent ' + when(c.sent_at));
      sub.push((c.channel || '').replace('_', ' '));
      if (c.audience) sub.push((c.audience || '').replace('_', ' '));
      if (c.email_from) sub.push('from ' + c.email_from);
      hero.querySelector('.kt-hero-sub').textContent = sub.join(' · ');
      viewBtn.addEventListener('click', function () { openView(c, container); });
      Dom.clear(body);
      drawStats(body, d, id);
    }).catch(function (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, (e && e.message) || 'Could not load the recipients.'));
    });
  }

  function drawStats(body, d, id) {
    var t = d.tiles || {};
    // Tiles
    var tiles = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px;' });
    var totalTile = Dom.el('div', { class: 'kt-card', style: 'padding:12px 14px;border-radius:12px;' });
    totalTile.innerHTML = '<div style="font-size:12px;color:#64748B;font-weight:700;">👥 Recipients</div><div style="font-size:24px;font-weight:800;color:#0F172A;">' + (t.total || 0) + '</div>';
    tiles.appendChild(totalTile);
    STAT_DEFS.forEach(function (s) {
      if (!d.emailing && s.k !== 'clicked') return;
      if (s.k === 'pending' && !t.pending) return;
      var v = s.k === 'clicked' ? 'Not tracked' : String(t[s.k] || 0);
      var el = Dom.el('div', { class: 'kt-card', 'data-kttip': s.tip, style: 'padding:12px 14px;border-radius:12px;border-left:4px solid ' + s.c + ';' });
      el.innerHTML = '<div style="font-size:12px;color:#64748B;font-weight:700;">' + s.i + ' ' + esc(s.t) + '</div>'
        + '<div style="font-size:' + (s.k === 'clicked' ? '14px;padding-top:6px' : '24px') + ';font-weight:800;color:' + s.c + ';">' + esc(v) + '</div>'
        + (s.k === 'opened' && (t.sent || t.opened) ? '<div style="font-size:11px;color:#64748B;">' + Math.round((t.opened || 0) / ((t.sent || 0) + (t.opened || 0)) * 100) + '% of those sent</div>' : '');
      tiles.appendChild(el);
    });
    if (t.unknown) {
      var u = Dom.el('div', { class: 'kt-card', 'data-kttip': 'No email record was found for these people.', style: 'padding:12px 14px;border-radius:12px;border-left:4px solid #CBD5E1;' });
      u.innerHTML = '<div style="font-size:12px;color:#64748B;font-weight:700;">❔ Not known</div><div style="font-size:24px;font-weight:800;color:#64748B;">' + t.unknown + '</div>';
      tiles.appendChild(u);
    }
    body.appendChild(tiles);

    // In-app notice + News
    var extra = [];
    if (d.bell && d.bell.sent) extra.push('🔔 In-app notice: ' + d.bell.sent + ' sent, ' + d.bell.read + ' read');
    if (d.news) extra.push(d.news.for == null ? '📰 News post: posted before per-person tracking, so reads are not known'
      : ('📰 News post: for ' + d.news.for + ' ' + (d.news.for === 1 ? 'person' : 'people') + ', opened by ' + d.news.read));
    if (extra.length) {
      var ex = Dom.el('div', { style: 'display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;' });
      extra.forEach(function (x) { ex.appendChild(Dom.el('div', { class: 'kt-card', style: 'padding:9px 14px;border-radius:10px;font-size:13px;color:#0F172A;font-weight:600;' }, x)); });
      body.appendChild(ex);
    }

    // What can and cannot be known
    var notes = d.notes || {};
    var nb = Dom.el('details', { style: 'margin-bottom:14px;font-size:12.5px;color:#475569;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 12px;' });
    nb.innerHTML = '<summary style="cursor:pointer;font-weight:700;color:#334155;">ℹ️ What these numbers can and cannot tell you</summary>'
      + '<ul style="margin:8px 0 2px 18px;padding:0;line-height:1.6;">'
      + (notes.delivered ? '<li>' + esc(notes.delivered) + '</li>' : '')
      + (notes.opened ? '<li>' + esc(notes.opened) + '</li>' : '')
      + (notes.clicked ? '<li>' + esc(notes.clicked) + '</li>' : '')
      + (d.backfilled ? '<li>This campaign went out before per-person records were kept. The list was rebuilt from its in-app notices and the email log; anyone with no matching log entry shows as "Not known".</li>' : '')
      + '</ul>';
    body.appendChild(nb);

    if (!d.emailing) {
      body.appendChild(Dom.el('div', { class: 'kt-card', style: 'padding:22px;color:#64748B;' }, 'This campaign was not emailed, so there is no email list. See the News and in-app notice counts above.'));
      return;
    }
    var rows = d.rows || [];
    if (!rows.length) {
      body.appendChild(Dom.el('div', { class: 'kt-card', style: 'padding:22px;color:#64748B;' }, d.campaign && d.campaign.sent_at ? 'No per-person email records for this campaign.' : 'Nothing has been sent yet.'));
      return;
    }

    // Filter chips
    var counts = {}; rows.forEach(function (r) { counts[r.status] = (counts[r.status] || 0) + 1; });
    var chips = Dom.el('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;' });
    var current = 'all';
    var chipDefs = [{ k: 'all', t: 'All', n: rows.length }].concat(['opened', 'sent', 'bounced', 'failed', 'held', 'unknown']
      .filter(function (k) { return counts[k]; }).map(function (k) { return { k: k, t: STATUS_LABEL[k], n: counts[k] }; }));
    var chipEls = {};
    chipDefs.forEach(function (cd) {
      var b = Dom.el('button', { type: 'button', class: 'kt-chip', 'data-k': cd.k,
        style: 'border:1px solid #CBD5E1;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:700;cursor:pointer;background:#fff;color:#334155;' }, cd.t + ' · ' + cd.n);
      b.addEventListener('click', function () { current = cd.k; paint(); });
      chipEls[cd.k] = b; chips.appendChild(b);
    });
    body.appendChild(chips);

    var card = Dom.el('div', { class: 'kt-card' });
    var table = Dom.el('table', { 'data-kt-export-title': 'Campaign recipients - ' + ((d.campaign && d.campaign.title) || '') });
    table.innerHTML = '<thead><tr><th>Name</th><th>Family</th><th>Email</th><th>Status</th><th>When</th><th>Details</th><th>Actions</th></tr></thead>';
    var tb = Dom.el('tbody', {});
    rows.forEach(function (r) {
      var tone = STATUS_TONE[r.status] || STATUS_TONE.unknown;
      var details = [];
      if (r.reason) details.push(esc(r.reason));
      if (r.opens) details.push('Opened ' + r.opens + (r.opens === 1 ? ' time' : ' times'));
      if (r.bell) details.push('In-app notice ' + (r.bell === 'read' ? 'read' : 'not read yet'));
      var tr = Dom.el('tr', { 'data-status': r.status });
      tr.innerHTML = '<td>' + esc(r.name || '—') + '</td><td>' + esc(r.family || '—') + '</td><td>' + esc(r.email) + '</td>'
        + '<td data-export="' + esc(STATUS_LABEL[r.status] || r.status) + '"><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:700;background:' + tone[0] + ';color:' + tone[1] + ';">' + esc(STATUS_LABEL[r.status] || r.status) + '</span></td>'
        + '<td>' + esc(when(r.at)) + '</td><td style="font-size:12.5px;color:#475569;">' + (details.join(' · ') || '—') + '</td>';
      var act = Dom.el('td', { style: 'text-align:center;white-space:nowrap;' });
      if (r.has_email) {
        var v = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm' }, '📧 View the email they got');
        v.addEventListener('click', function (e) { e.stopPropagation(); viewSent(id, r); });
        act.appendChild(v);
      }
      var cp = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm' }, '📋 Copy email address');
      cp.addEventListener('click', function (e) {
        e.stopPropagation();
        try { navigator.clipboard.writeText(r.email); if (KT.toast) KT.toast('📋', 'Copied', r.email, '#16A34A'); } catch (x) {}
      });
      act.appendChild(cp);
      tr.appendChild(act);
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    card.appendChild(table);
    body.appendChild(card);

    function paint() {
      Object.keys(chipEls).forEach(function (k) {
        var on = k === current;
        chipEls[k].style.background = on ? '#1F6080' : '#fff';
        chipEls[k].style.color = on ? '#fff' : '#334155';
        chipEls[k].style.borderColor = on ? '#1F6080' : '#CBD5E1';
      });
      Array.prototype.forEach.call(tb.rows, function (tr) {
        tr.style.display = (current === 'all' || tr.getAttribute('data-status') === current) ? '' : 'none';
      });
      try { if (KT.tableExport) KT.tableExport.sweep(); } catch (e) {}
    }
    paint();
    if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
  }

  // The stored copy of the email that went to one person (open pixel removed server-side, so viewing it is not an open).
  function viewSent(id, r) {
    Api.get('/marketing/campaigns/' + id + '/recipients/' + r.id + '/email').then(function (m) {
      var box = Dom.el('div', {});
      box.appendChild(Dom.el('div', { style: 'font-size:13px;color:#475569;margin-bottom:8px;' },
        'To ' + (m.to || r.email) + ' · ' + when(m.at) + ' · ' + (m.subject || '')));
      var frame = Dom.el('iframe', { sandbox: '', style: 'width:100%;height:520px;border:1px solid #E2E8F0;border-radius:10px;background:#fff;' });
      frame.srcdoc = m.html || '';
      box.appendChild(frame);
      KT.Shell.Modal.open({ title: '📧 The email ' + (r.name || r.email) + ' got', body: box, actions: [{ label: 'Close' }], large: true });
    }).catch(function (e) {
      if (KT.toast) KT.toast('⚠️', 'Not available', (e && e.message) || 'There is no stored copy of this email.', '#B45309');
    });
  }

  // ── Composer ───────────────────────────────────────────────────────
  function openComposer(existing, container) {
    /* ONE COMPOSER, AND IT DOES NOT OUTLIVE THE SCREEN.

       The overlay is appended to <body>, not to #appMain, so navigating away left it
       sitting on top of whatever you went to — and pressing "+ New campaign" again stacked
       a second one over the first, with the older copy still holding its own state
       underneath. Found while testing the asset pickers: two live composers, eight picker
       cards, and typing going into whichever happened to be on top.

       So: any stale composer is removed before a new one opens, and the overlay tears
       itself down on the next hash change. Marked with an id so both checks have something
       to find. (Anthony, 2026-09-10) */
    var stale = document.getElementById('kt-campaign-composer');
    if (stale) { stale.remove(); }

    var overlay = Dom.el('div', { id: 'kt-campaign-composer', style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;' });

    /* Leaving the screen closes it. Registered once per composer and removed with it, so
       a closed composer leaves no listener behind. */
    var closeOnNav = function () { overlay.remove(); };
    window.addEventListener('hashchange', closeOnNav);
    var _origRemove = overlay.remove.bind(overlay);
    overlay.remove = function () {
      window.removeEventListener('hashchange', closeOnNav);
      _origRemove();
    };
    var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:920px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:2;' });
    header.appendChild(Dom.el('h2', { style: 'margin:0;font-size:18px;' }, existing ? 'Edit campaign' : 'New campaign'));
    var closeBtn = Dom.el('button', { style: 'background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;padding:4px 10px;' }, '×');
    closeBtn.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(closeBtn);
    modal.appendChild(header);

    var body = Dom.el('div', { style: 'padding:20px 24px;' });
    modal.appendChild(body);

    // Title
    body.appendChild(labelEl('Campaign title'));
    var titleIn = Dom.el('input', { type: 'text', placeholder: 'Spring open house · April newsletter · etc.', value: existing ? existing.title : '', style: inputStyle() });
    body.appendChild(titleIn);

    // Subject (email)
    body.appendChild(labelEl('Email subject (used when channel includes email) · {first_name} works here too'));
    var subjectIn = Dom.el('input', { type: 'text', placeholder: 'Save the date: our spring open house', value: existing ? (existing.subject || '') : '', style: inputStyle() });
    body.appendChild(subjectIn);

    // Audience + channel row
    var row2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;' });
    var audWrap = Dom.el('div');
    audWrap.appendChild(labelEl('Audience'));
    var audSel = Dom.el('select', { style: inputStyle() });
    [['all_families','All families'],['active_families','Currently enrolled'],['waitlist','Waitlist'],['prospects','Prospects (no active child)'],['staff','Staff only']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.audience === o[0]) opt.selected = true;
      audSel.appendChild(opt);
    });
    audWrap.appendChild(audSel);
    row2.appendChild(audWrap);

    var chWrap = Dom.el('div');
    chWrap.appendChild(labelEl('Channel'));
    var chSel = Dom.el('select', { style: inputStyle() });
    [['in_portal','In-portal (announcements)'],['email','Email (via scheduler)'],['both','Both']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.channel === o[0]) opt.selected = true;
      chSel.appendChild(opt);
    });
    chWrap.appendChild(chSel);
    row2.appendChild(chWrap);
    body.appendChild(row2);

    /* SEND FROM (white-label licence, 2026-10-20). Which mailbox the email leaves from: the
       agency's normal delivery, or one of its sender accounts (Event RSVPs > Sender
       accounts: a Gmail / Google Workspace mailbox connected by signing in). Offered to a
       white-label agency; anyone else sees the default and that it is part of the licence.
       The server checks the same thing (CampaignSender::validateChoice). */
    var fromWrap = Dom.el('div', { style: 'margin-bottom:14px;' });
    fromWrap.appendChild(labelEl('Send from'));
    var fromSel = Dom.el('select', { style: inputStyle() + 'margin-bottom:4px;', disabled: 'disabled' });
    fromSel.appendChild(Dom.el('option', { value: '' }, 'Loading…'));
    var fromHint = Dom.el('div', { style: 'font-size:12px;color:#64748B;line-height:1.45;' });
    fromWrap.appendChild(fromSel);
    fromWrap.appendChild(fromHint);
    body.appendChild(fromWrap);
    var fromWL = false;
    function syncFromVisible() { fromWrap.style.display = (chSel.value === 'in_portal') ? 'none' : ''; }
    chSel.addEventListener('change', syncFromVisible);
    syncFromVisible();
    Api.get('/marketing/senders').then(function (r) {
      fromWL = !!(r && r.white_label);
      var def = (r && r.default) || {};
      fromSel.innerHTML = '';
      fromSel.appendChild(Dom.el('option', { value: '' }, 'Agency default' + (def.address ? ' (' + def.address + ')' : '')));
      if (!fromWL) {
        fromHint.textContent = 'Sending a campaign from another mailbox, such as your own Google Workspace account, is part of the white-label licence. Ask KiddieTrac about it.';
        return;
      }
      var list = (r && r.senders) || [];
      list.forEach(function (s) {
        var o = Dom.el('option', { value: String(s.id) }, s.label + (s.address ? ' (' + s.address + ')' : '') + (s.ready ? '' : ' (not connected)'));
        if (!s.ready) o.disabled = true;
        if (existing && Number(existing.sender_id) === s.id) o.selected = true;
        fromSel.appendChild(o);
      });
      fromSel.disabled = false;
      var chosen = existing && existing.sender_id ? list.filter(function (s) { return s.id === Number(existing.sender_id); })[0] : null;
      fromHint.innerHTML = '';
      if (chosen && !chosen.ready) {
        fromHint.appendChild(document.createTextNode('\u26A0\uFE0F ' + chosen.label + ' is not connected, so this would go from the agency default. Reconnect it, or pick another. '));
      } else {
        fromHint.appendChild(document.createTextNode(list.length
          ? 'A Gmail account sends about 60 emails every 5 minutes, so a large list takes a little longer. '
          : 'No sender accounts yet. '));
      }
      var link = Dom.el('a', { href: '#rsvp?tab=senders', style: 'color:#1F6080;font-weight:600;' }, list.length ? 'Manage sender accounts' : 'Add one under Event RSVPs \u203A Sender accounts');
      link.addEventListener('click', function () { try { overlay.remove(); } catch (e) {} });
      fromHint.appendChild(link);
    }).catch(function () {
      fromSel.innerHTML = '';
      fromSel.appendChild(Dom.el('option', { value: '' }, 'Agency default'));
      fromHint.textContent = 'Could not load your sender accounts; the agency default will be used.';
    });

    /* Declared before the first picker, not after.

       This used to sit further down, beside the header and footer selects that were its
       only readers. Moving the hero onto the same picker made it the FIRST reader — and
       `var` hoists the declaration without the assignment, so assetState was undefined
       when the hero picker painted itself: "Cannot read properties of undefined (reading
       'list')", and the whole composer failed to open. */
    var assetState = { header: null, footer: null, list: [] };

    /* HERO IMAGE — KEPT, NOT THROWN AWAY.

       This used to post straight to /marketing/images, which returns a URL and keeps no
       record: the picture existed, nothing listed it, and the next campaign that wanted
       the same banner had to go and find the original file again. A hero is the same kind
       of thing as a header — an image an agency reuses — so it is now saved as a marketing
       asset and picked from the same library, with the picture itself on screen rather
       than a filename in a dropdown. (Anthony, 2026-09-10) */
    var heroUrl = existing ? (existing.hero_image_url || '') : '';
    var heroPicker = assetPicker('hero', 'Hero image (optional)',
      'The picture at the top of the message. Uploads are kept — pick one you have used before, or add a new one.',
      {
        selectedUrl: heroUrl,
        onPick: function (a) { heroUrl = a ? (a.image_url || '') : ''; },
      });
    body.appendChild(heroPicker);

    // White-label status — driven by the agency's plan/package, NOT a manual
    // choice. The "Powered by Kiddietrac" footer (with privacy/terms) is always
    // added. We resolve the entitlement when the composer opens (below).
    var wlState = { on: false };
    var wlNote = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:11px 12px;background:#F9FAFB;border:1px solid #EEF0F2;border-radius:8px;margin-bottom:14px;font-size:13px;color:#6B7280;' }, 'Checking your plan…');
    body.appendChild(wlNote);
    loadFeatures().then(function (f) {
      wlState.on = f.whiteLabel;
      if (f.whiteLabel) {
        wlNote.style.background = '#ECFDF5'; wlNote.style.borderColor = '#A7F3D0'; wlNote.style.color = '#065F46';
        wlNote.innerHTML = '✓ <strong>White-label branding is included in your plan.</strong> Your agency logo &amp; details appear at the top of every campaign. <span style="opacity:.8;">Edit them under Settings → Branding.</span>';
      } else {
        wlNote.innerHTML = 'Campaigns use a standard header. <strong>White-label branding</strong> (your logo &amp; details at the top) is included on the Growth plan and above.';
      }
    });

    // Rich-text editor
    body.appendChild(labelEl('Body'));
    /* Header and footer pickers. The saved blocks are fetched once and both selects
       are filled from the same list, so uploading a header immediately shows up in the
       footer list too if that is where it belongs. */

    /* ONE PICKER FOR HERO, HEADER AND FOOTER.

       Header and footer were a <select> of names with a preview underneath, and the hero
       was a lone upload button. Three different answers to the same question, and none of
       them let you SEE what you were choosing between — which is the whole difficulty with
       a banner. This shows the library as cards: the image itself, its name, click to
       choose. A footer that is words rather than a picture shows its text in the card, so
       the two kinds sit in one list without one of them being invisible.

       Reopening a campaign restores what it was saved with: `opts.selectedId` for the
       header and footer, `opts.selectedUrl` for the hero — which is all the campaign row
       keeps for it, since a hero is stored as a URL rather than an asset id.
       (Anthony, 2026-09-10) */
    function assetPicker(kind, label, hint, opts) {
        opts = opts || {};
        var wrap = Dom.el('div', { style: 'margin-top:14px;margin-bottom:4px;' });
        wrap.appendChild(labelEl(label));
        if (hint) {
            wrap.appendChild(Dom.el('div', {
                style: 'font-size:12px;color:#64748B;margin:-4px 0 8px;',
            }, hint));
        }

        var strip = Dom.el('div', {
            style: 'display:flex;gap:10px;overflow-x:auto;padding:2px 2px 8px;align-items:stretch;',
            'data-kt-scroll': '1',
        });
        wrap.appendChild(strip);

        var upBtn = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm' },
            kind === 'hero' ? '＋ Upload a hero image' : '＋ Upload new');
        upBtn.setAttribute('data-kt-no-icon', '1');
        wrap.appendChild(upBtn);

        var chosenId = null;

        function card(a) {
            var isNone = !a;
            var on = isNone ? (chosenId === null) : String(a.id) === String(chosenId);
            var c = Dom.el('div', {
                style: 'flex:0 0 auto;width:150px;border:2px solid ' + (on ? '#1F6080' : '#E2E8F0') + ';'
                    + 'border-radius:10px;overflow:hidden;cursor:pointer;background:#fff;'
                    + 'box-shadow:' + (on ? '0 0 0 3px rgba(31,96,128,.12)' : 'none') + ';',
            });

            var thumb = Dom.el('div', {
                style: 'height:76px;background:#F3F4F6;background-size:cover;background-position:center;'
                    + 'display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:12px;'
                    + 'text-align:center;padding:6px;box-sizing:border-box;overflow:hidden;',
            });
            if (isNone) {
                thumb.textContent = 'None';
            } else if (a.image_url) {
                thumb.style.backgroundImage = 'url(' + absUrl(a.image_url) + ')';
            } else {
                /* Text-only block: show the words, because "no image" is not the same as
                   "nothing here" and a blank tile reads as broken. */
                thumb.style.fontSize = '11px';
                thumb.style.color = '#475569';
                thumb.textContent = String(a.html || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 90) || 'Text block';
            }
            c.appendChild(thumb);

            c.appendChild(Dom.el('div', {
                style: 'padding:6px 8px;font-size:12px;font-weight:700;color:' + (on ? '#1F6080' : '#334155') + ';'
                    + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;',
            }, isNone ? '— none —' : (a.name || 'Untitled')));

            c.addEventListener('click', function () {
                chosenId = isNone ? null : a.id;
                apply();
                paint();
            });
            return c;
        }

        /* Tell the outside world what is chosen. assetState is what the save and the
           preview read; onPick is for the hero, which is stored on the campaign as a URL
           rather than an id. */
        function apply() {
            var a = mine().filter(function (x) { return String(x.id) === String(chosenId); })[0] || null;
            if (kind === 'hero') {
                if (typeof opts.onPick === 'function') { opts.onPick(a); }
            } else {
                assetState[kind] = a ? a.id : null;
            }
        }

        function mine() {
            return assetState.list.filter(function (a) { return a.kind === kind; });
        }

        function paint() {
            strip.innerHTML = '';
            strip.appendChild(card(null));
            var list = mine();
            list.forEach(function (a) { strip.appendChild(card(a)); });
            if (!list.length) {
                strip.appendChild(Dom.el('div', {
                    style: 'flex:0 0 auto;align-self:center;font-size:12.5px;color:#94A3B8;padding-left:4px;',
                }, 'Nothing saved yet — upload one and it stays here for next time.'));
            }
        }

        /* Restore what this campaign was saved with. By id for header/footer, by URL for
           the hero — which is all the campaign row keeps for it. */
        function restore() {
            var list = mine();
            if (kind === 'hero') {
                if (opts.selectedUrl) {
                    var hit = list.filter(function (a) {
                        return a.image_url && absUrl(a.image_url) === absUrl(opts.selectedUrl);
                    })[0];
                    chosenId = hit ? hit.id : null;
                }
            } else if (opts.selectedId) {
                chosenId = String(opts.selectedId);
            }
            apply();
            paint();
        }

        upBtn.addEventListener('click', function () {
            openAssetUpload(kind, function (created) {
                if (created && created.id) { chosenId = created.id; }
                apply();
                paint();
            });
        });

        wrap._paint = paint;
        wrap._restore = restore;
        paint();
        return wrap;
    }


    var KIND_WORD = { header: 'header', footer: 'footer', hero: 'hero image' };

    function openAssetUpload(kind, onSaved) {
        var m = document.createElement('div');
        m.setAttribute('data-no-modal-guard', '1');
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;'
            + 'display:flex;align-items:center;justify-content:center;padding:20px;';
        m.innerHTML = '<div style="background:#fff;padding:26px;border-radius:14px;max-width:520px;width:100%;'
            + 'max-height:calc(100vh - 40px);overflow-y:auto;">'
            + '<h3 style="margin:0 0 14px;">New ' + (KIND_WORD[kind] || 'header') + '</h3>'
            + '<label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Name it</label>'
            + '<input id="ma-name" placeholder="e.g. Spring 2026 banner" '
            + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">'
            + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Image</label>'
            + '<input id="ma-file" type="file" accept="image/jpeg,image/png,image/gif" '
            + 'style="width:100%;padding:9px;border:1px dashed #CBD5E1;border-radius:8px;font-size:13px;'
            + 'background:#F8FAFC;box-sizing:border-box;">'
            + '<div style="font-size:12px;color:#64748B;margin-top:4px;">'
            + 'JPEG, PNG or GIF. Wide images are resized to 600px for email.</div>'
            /* A hero is a picture by definition — offering it a text box would invite
               somebody to save a "hero" with nothing to show. Headers and footers are
               often words (an address, unsubscribe wording), so they keep it. */
            + (kind === 'hero' ? ''
                : '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Or text (optional)</label>'
                  + '<textarea id="ma-html" rows="3" placeholder="Address, unsubscribe wording, a closing line…" '
                  + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;'
                  + 'box-sizing:border-box;"></textarea>')
            + '<div id="ma-msg" style="font-size:13px;margin-top:12px;min-height:18px;"></div>'
            + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
            + '<button id="ma-cancel" class="kt-btn kt-btn-secondary" type="button">Cancel</button>'
            + '<button id="ma-save" class="kt-btn kt-btn-primary" type="button">Save</button>'
            + '</div></div>';
        document.body.appendChild(m);

        var msg = m.querySelector('#ma-msg');
        m.querySelector('#ma-cancel').onclick = function () { m.remove(); };

        m.querySelector('#ma-save').onclick = function () {
            var name = (m.querySelector('#ma-name').value || '').trim();
            var file = m.querySelector('#ma-file').files[0];
            var htmlEl = m.querySelector('#ma-html');
            var htmlTxt = htmlEl ? (htmlEl.value || '').trim() : '';
            if (!name) { msg.style.color = '#B91C1C'; msg.textContent = 'Give it a name so you can find it again.'; return; }
            if (!file && !htmlTxt) {
                msg.style.color = '#B91C1C';
                msg.textContent = kind === 'hero' ? 'Choose an image.' : 'Add an image or some text.';
                return;
            }

            var fd = new FormData();
            fd.append('kind', kind);
            fd.append('name', name);
            if (htmlTxt) { fd.append('html', htmlTxt); }
            if (file) { fd.append('image', file, file.name); }

            var btn = m.querySelector('#ma-save');
            btn.disabled = true;
            msg.style.color = '#64748B';
            msg.textContent = 'Saving…';

            Api.post('/marketing/assets', fd).then(function (res) {
                /* Reload first, THEN hand the new asset back: the picker selects by id
                   from the library, so telling it about an asset the library has not
                   heard of yet would select nothing. */
                return loadAssets().then(function () {
                    if (onSaved) { onSaved(res || null); }
                    m.remove();
                });
            }).catch(function (e) {
                btn.disabled = false;
                msg.style.color = '#B91C1C';
                msg.textContent = (e && e.message) || 'Could not save that.';
            });
        };
    }

    function loadAssets() {
        return Api.get('/marketing/assets').then(function (r) {
            assetState.list = (r && r.data) || [];
        }).catch(function () { assetState.list = []; });
    }

    var headerBlock = assetPicker('header', 'Header (optional)',
        'Sits above the message. Upload once and reuse it on every campaign.',
        { selectedId: existing ? existing.header_asset_id : null });
    var footerBlock = assetPicker('footer', 'Footer (optional)',
        'Sits below the message — an address, a sign-off, unsubscribe wording.',
        { selectedId: existing ? existing.footer_asset_id : null });
    body.appendChild(headerBlock);
    body.appendChild(footerBlock);

    /* All three restore together, after the library has arrived — none of them can show a
       selection before there is a list to select from. */
    loadAssets().then(function () {
        heroPicker._restore();
        headerBlock._restore();
        footerBlock._restore();
    });

    body.appendChild(buildRichEditor(existing ? existing.body_html : '', function () { return null; }));
    body.appendChild(Dom.el('div', { class: 'kt-campaign-merge-hint', style: 'font-size:12px;color:#64748B;line-height:1.45;margin:-8px 0 14px;' },
      'Tip: press 👤 First name (or type {first_name}) to greet each family by name, e.g. "Hi {first_name}," arrives as "Hi Sarah,". '
      + 'In the News post, and for anyone without a first name on file, it reads "there" ("Hi there,").'));

    // Schedule
    body.appendChild(labelEl('Schedule (optional — leave blank to send manually)'));
    var schedIn = Dom.el('input', { type: 'datetime-local', value: existing && existing.scheduled_for ? toDatetimeLocal(existing.scheduled_for) : '', style: inputStyle() });
    body.appendChild(schedIn);

    // Action row
    var actions = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid #E5E7EB;flex-wrap:wrap;' });
    var leftBtns = Dom.el('div', { style: 'display:flex;gap:8px;' });
    if (existing) {
      var delBtn = Dom.el('button', { style: btnDanger() }, 'Delete');
      delBtn.addEventListener('click', async function () {
        if (!await KT.confirm('Delete this campaign? This cannot be undone.')) return;
        Api.delete('/marketing/campaigns/' + existing.id).then(function () { overlay.remove(); render(container); });
      });
      leftBtns.appendChild(delBtn);
    }
    actions.appendChild(leftBtns);

    var rightBtns = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
    var previewBtn = Dom.el('button', { style: btnSecondary() }, '👁 Preview');
    var saveBtn = Dom.el('button', { style: btnSecondary() }, 'Save draft');
    var sendBtn = Dom.el('button', { style: btnPrimary() }, 'Send now');
    rightBtns.appendChild(previewBtn);
    rightBtns.appendChild(saveBtn);
    rightBtns.appendChild(sendBtn);

    previewBtn.addEventListener('click', function () {
      var payload = collect();
      if (!payload.body_html) { alert('Add some body content to preview.'); return; }
      previewBtn.disabled = true; previewBtn.textContent = 'Loading…';
      loadFeatures().then(function (f) {
        /* assetState belongs to THIS function. openPreview is a sibling, so it could
           never see it — the reference there threw ReferenceError on every preview
           (ticket #58). Passed in, which also makes it obvious that the preview needs
           the chosen header and footer to be worth looking at. */
        openPreview(payload, f.branding, f.whiteLabel, assetState);
      }).finally(function () { previewBtn.disabled = false; previewBtn.innerHTML = '👁 Preview'; });
    });
    actions.appendChild(rightBtns);
    body.appendChild(actions);

    function collect() {
      var editor = modal.querySelector('.kt-rt-editor');
      var out = {
        title: titleIn.value.trim(),
        subject: subjectIn.value.trim() || null,
        body_html: editor ? editor.innerHTML : '',
        header_asset_id: assetState.header,
        footer_asset_id: assetState.footer,
        hero_image_url: heroUrl || null,
        audience: audSel.value,
        channel: chSel.value,
        scheduled_for: schedIn.value ? schedIn.value.replace('T', ' ') + ':00' : null,
      };
      // Only a white-label agency sends a choice; the server refuses one from anyone else.
      if (fromWL) out.sender_id = fromSel.value ? Number(fromSel.value) : null;
      return out;
    }

    saveBtn.addEventListener('click', function () {
      var payload = collect();
      if (!payload.title) { alert('Title is required.'); return; }
      if (!payload.body_html) { alert('Body is required.'); return; }
      payload.status = payload.scheduled_for ? 'scheduled' : 'draft';
      var promise = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload)
        : Api.post('/marketing/campaigns', payload);
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      promise.then(function () { overlay.remove(); render(container); })
        .catch(function (e) { alert('Save failed: ' + e.message); saveBtn.disabled = false; saveBtn.textContent = 'Save draft'; });
    });

    sendBtn.addEventListener('click', async function () {
      if (!await KT.confirm('Send this campaign now? This cannot be undone.')) return;
      var payload = collect();
      if (!payload.title || !payload.body_html) { alert('Title and body are required before sending.'); return; }
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      var ensure = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload).then(function () { return existing.id; })
        : Api.post('/marketing/campaigns', payload).then(function (r) { return r.id; });
      ensure
        .then(function (id) { return Api.post('/marketing/campaigns/' + id + '/send', {}); })
        .then(function (r) {
          alert(r.email_pending
            ? (r.channel === 'both' ? 'Posted in the portal for ' + r.recipients + ' recipients. ' : '')
              + 'The email to ' + r.recipients + ' recipients goes out within a few minutes; the list shows its progress.'
            : 'Sent to ' + r.recipients + ' recipients in the portal.');
          overlay.remove(); render(container);
        })
        .catch(function (e) { alert('Send failed: ' + e.message); sendBtn.disabled = false; sendBtn.textContent = 'Send now'; });
    });

    document.body.appendChild(overlay);
  }

  function toDatetimeLocal(d) {
    try { var dt = new Date(d); return dt.toISOString().slice(0, 16); } catch (e) { return ''; }
  }

  // ── Mini rich-text editor — contentEditable + toolbar ──────────────
  function buildRichEditor(initialHtml, getContext) {
    var wrap = Dom.el('div', { style: 'border:1px solid #D1D5DB;border-radius:10px;overflow:hidden;margin-bottom:14px;background:white;' });
    var toolbar = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;padding:8px;border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });

    function btn(label, title, action, extraStyle) {
      var b = Dom.el('button', { type: 'button', title: title, style: 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:5px 9px;font-size:13px;cursor:pointer;color:#374151;min-width:30px;transition:background .12s,border-color .12s;' + (extraStyle || '') }, label);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus on editor
      b.addEventListener('click', action);
      b.addEventListener('mouseenter', function () { b.style.background = '#F3F4F6'; b.style.borderColor = '#D1D5DB'; });
      b.addEventListener('mouseleave', function () { b.style.background = 'white'; b.style.borderColor = '#E5E7EB'; });
      return b;
    }
    function selectStyle() { return 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:4px 6px;font-size:12px;color:#374151;cursor:pointer;'; }
    // v22p35: enable styleWithCSS so foreColor/hiliteColor produce inline-style
    // spans the sanitiser allows, rather than deprecated <font> tags. Also try
    // both hiliteColor (Chrome / Webkit) and backColor (Firefox) so highlight
    // works across browsers.
    function exec(cmd, val) {
      try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
      if (cmd === 'hiliteColor') {
        if (!document.execCommand('hiliteColor', false, val || null)) {
          document.execCommand('backColor', false, val || null);
        }
      } else {
        document.execCommand(cmd, false, val == null ? null : val);
      }
      editor.focus();
    }

    // ── Font family ───────────────────────────────────────────────
    var fontSel = Dom.el('select', { title: 'Font family', style: selectStyle() });
    var fonts = [
      ['', 'Default'],
      ['Inter, system-ui, sans-serif', 'Inter (clean)'],
      ['Georgia, "Times New Roman", serif', 'Georgia (serif)'],
      ['"Helvetica Neue", Arial, sans-serif', 'Helvetica'],
      ['"Courier New", monospace', 'Courier (mono)'],
      ['"Comic Sans MS", "Comic Sans", cursive', 'Comic Sans'],
      ['Verdana, sans-serif', 'Verdana'],
      ['"Trebuchet MS", sans-serif', 'Trebuchet'],
    ];
    fonts.forEach(function (f) { var o = Dom.el('option', { value: f[0] }, f[1]); fontSel.appendChild(o); });
    fontSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fontSel.addEventListener('change', function () { if (fontSel.value) { exec('fontName', fontSel.value); } fontSel.selectedIndex = 0; });
    toolbar.appendChild(fontSel);

    // ── Font size (1=8px ... 7=36px in execCommand) ───────────────
    var sizeSel = Dom.el('select', { title: 'Text size', style: selectStyle() });
    [['', 'Size'], ['1', 'Tiny'], ['2', 'Small'], ['3', 'Normal'], ['4', 'Medium'], ['5', 'Large'], ['6', 'X-large'], ['7', 'Huge']].forEach(function (s) {
      sizeSel.appendChild(Dom.el('option', { value: s[0] }, s[1]));
    });
    sizeSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    sizeSel.addEventListener('change', function () { if (sizeSel.value) exec('fontSize', sizeSel.value); sizeSel.selectedIndex = 0; });
    toolbar.appendChild(sizeSel);
    toolbar.appendChild(divider());

    // ── Bold / italic / underline / strike ────────────────────────
    toolbar.appendChild(btn('B', 'Bold (Ctrl/Cmd+B)', function () { exec('bold'); }, 'font-weight:800;'));
    toolbar.appendChild(btn('I', 'Italic (Ctrl/Cmd+I)', function () { exec('italic'); }, 'font-style:italic;'));
    toolbar.appendChild(btn('U', 'Underline (Ctrl/Cmd+U)', function () { exec('underline'); }, 'text-decoration:underline;'));
    toolbar.appendChild(btn('S', 'Strikethrough', function () { exec('strikeThrough'); }, 'text-decoration:line-through;'));
    toolbar.appendChild(divider());

    // ── Text colour + highlight (background) ──────────────────────
    var fgInput = Dom.el('input', { type: 'color', title: 'Text colour', value: '#1F6080', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    fgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fgInput.addEventListener('input', function () { exec('foreColor', fgInput.value); });
    toolbar.appendChild(fgInput);
    var bgInput = Dom.el('input', { type: 'color', title: 'Highlight colour', value: '#FEF3C7', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    bgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    bgInput.addEventListener('input', function () { exec('hiliteColor', bgInput.value); });
    toolbar.appendChild(bgInput);
    toolbar.appendChild(divider());

    // ── Headings + paragraph ──────────────────────────────────────
    toolbar.appendChild(btn('H1', 'Heading 1', function () { exec('formatBlock', '<h1>'); }));
    toolbar.appendChild(btn('H2', 'Heading 2', function () { exec('formatBlock', '<h2>'); }));
    toolbar.appendChild(btn('H3', 'Heading 3', function () { exec('formatBlock', '<h3>'); }));
    toolbar.appendChild(btn('P', 'Paragraph', function () { exec('formatBlock', '<p>'); }));
    toolbar.appendChild(divider());

    // ── Alignment ─────────────────────────────────────────────────
    toolbar.appendChild(btn('⬱', 'Align left',    function () { exec('justifyLeft'); }));
    toolbar.appendChild(btn('☰', 'Align centre',  function () { exec('justifyCenter'); }));
    toolbar.appendChild(btn('⬲', 'Align right',   function () { exec('justifyRight'); }));
    toolbar.appendChild(btn('☷', 'Justify',       function () { exec('justifyFull'); }));
    toolbar.appendChild(divider());

    // ── Lists + quote ─────────────────────────────────────────────
    toolbar.appendChild(btn('•', 'Bulleted list', function () { exec('insertUnorderedList'); }));
    toolbar.appendChild(btn('1.', 'Numbered list', function () { exec('insertOrderedList'); }));
    toolbar.appendChild(btn('❝', 'Block quote', function () { exec('formatBlock', '<blockquote>'); }));
    toolbar.appendChild(btn('⇥', 'Indent', function () { exec('indent'); }));
    toolbar.appendChild(btn('⇤', 'Outdent', function () { exec('outdent'); }));
    toolbar.appendChild(divider());

    // ── Line break + horizontal rule ──────────────────────────────
    toolbar.appendChild(btn('↵', 'Soft line break (no paragraph)', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<br />');
    }));
    toolbar.appendChild(btn('─', 'Horizontal rule', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<hr style="border:none;border-top:1px solid #E5E7EB;margin:14px 0;" />');
    }));
    toolbar.appendChild(divider());

    // ── Merge field: the reader's first name (2026-11-27) ──────────
    // Inserted as plain text so it is never split across tags (the server only fills a whole token).
    toolbar.appendChild(btn('👤 First name', 'Insert the reader’s first name. "Hi {first_name}," arrives as "Hi Sarah,"', function () {
      editor.focus();
      if (!document.execCommand('insertText', false, '{first_name}')) {
        document.execCommand('insertHTML', false, '{first_name}');
      }
    }));
    toolbar.appendChild(divider());

    // ── Link + image ──────────────────────────────────────────────
    toolbar.appendChild(btn('🔗', 'Insert link', async function () {
      // A KiddieTrac dialog takes focus: keep the selection and put it back before linking.
      var sel = window.getSelection(), range = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
      var url = await KT.prompt({ title: 'Insert a link', description: 'The selected text becomes a link to this address.', placeholder: 'https://…', type: 'url', okLabel: 'Insert link' });
      editor.focus();
      if (range) { sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
      if (url) exec('createLink', url);
    }));
    toolbar.appendChild(btn('🚫🔗', 'Remove link', function () { exec('unlink'); }));
    var imgBtn = btn('🖼 Image', 'Insert image', function () { fileIn.click(); });
    toolbar.appendChild(imgBtn);
    var fileIn = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;', 'data-kt-noface': '1' });
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('image', f);
      imgBtn.disabled = true; imgBtn.textContent = '⏳';
      Api.postForm('/marketing/images', fd).then(function (r) {
        editor.focus();
        var html = '<img src="' + absUrl(r.url) + '" alt="" style="max-width:100%;height:auto;border-radius:8px;margin:8px 0;" />';
        document.execCommand('insertHTML', false, html);
      }).catch(function (e) { alert('Image upload failed: ' + e.message); })
        .finally(function () { imgBtn.disabled = false; imgBtn.innerHTML = '🖼 Image'; });
    });
    toolbar.appendChild(fileIn);
    toolbar.appendChild(divider());

    // ── Undo / redo / clear ───────────────────────────────────────
    toolbar.appendChild(btn('↶', 'Undo', function () { exec('undo'); }));
    toolbar.appendChild(btn('↷', 'Redo', function () { exec('redo'); }));
    toolbar.appendChild(btn('clear', 'Remove formatting', function () { exec('removeFormat'); }));

    wrap.appendChild(toolbar);
    var editor = Dom.el('div', { class: 'kt-rt-editor', contenteditable: 'true', style: 'min-height:260px;padding:14px 16px;font-size:14px;line-height:1.5;color:#111827;outline:none;' });
    editor.innerHTML = initialHtml || '<p>Type your message here…</p>';
    wrap.appendChild(editor);
    return wrap;
  }

  function divider() { return Dom.el('div', { style: 'width:1px;background:#E5E7EB;margin:0 4px;' }); }

  // ── Branded preview ────────────────────────────────────────────────
  // Renders the campaign as recipients will see it: optional white-label
  // header (logo + agency details), the message body, and an always-present
  // "Powered by Kiddietrac" footer carrying the agency's privacy & terms links.
  /**
   * The campaign as the reader will get it.
   *
   * `assets` is the composer's header/footer selection: { header, footer, list }. It used
   * to be reached for as a bare `assetState`, which is declared inside openComposer — a
   * sibling function — so every click on Preview threw ReferenceError and the overlay
   * never opened (ticket #58, 2026-09-09). Passed in now, and defaulted, so a caller that
   * has no assets gets a preview without header and footer rather than an exception.
   */
  function openPreview(payload, brand, whiteLabel, assets) {
    brand = brand || {};
    assets = assets || { header: null, footer: null, list: [] };
    if (!Array.isArray(assets.list)) { assets.list = []; }
    var color = brand.brand_primary_color || '#1F6080';
    var name = brand.brand_name || 'Your agency';
    var logo = brand.brand_logo_url ? absUrl(brand.brand_logo_url) : '';
    var hero = payload.hero_image_url ? absUrl(payload.hero_image_url) : '';
    // The reader's first name shows as the sample name, as it will for each family.
    payload = Object.assign({}, payload, { subject: withSample(payload.subject, false), body_html: withSample(payload.body_html, true) });

    var headerHtml;
    if (whiteLabel) {
      headerHtml =
        '<div style="border-top:5px solid ' + esc(color) + ';background:#fff;padding:20px 28px;border-bottom:1px solid #EEF0F2;display:flex;align-items:center;gap:14px;">' +
          (logo
            ? '<img src="' + esc(logo) + '" alt="" style="max-height:54px;max-width:200px;object-fit:contain;flex-shrink:0;" />'
            : '<div style="font-size:20px;font-weight:800;color:' + esc(color) + ';flex-shrink:0;">' + esc(name) + '</div>') +
          '<div style="flex:1;text-align:right;font-size:12px;color:#6B7280;line-height:1.5;">' +
            (logo ? '<div style="font-weight:700;color:#111827;font-size:14px;">' + esc(name) + '</div>' : '') +
            (brand.brand_support_email ? '<div>' + esc(brand.brand_support_email) + '</div>' : '') +
            (brand.brand_address ? '<div>' + esc(brand.brand_address).replace(/\n/g, '<br>') + '</div>' : '') +
          '</div>' +
        '</div>';
    } else {
      headerHtml = '<div style="height:5px;background:' + esc(color) + ';"></div>';
    }

    var privacy = normUrl(brand.brand_privacy_url);
    var terms = normUrl(brand.brand_terms_url);
    var legal = [];
    if (privacy) legal.push('<a href="' + esc(privacy) + '" target="_blank" rel="noopener" style="color:#6B7280;text-decoration:underline;">Privacy policy</a>');
    if (terms) legal.push('<a href="' + esc(terms) + '" target="_blank" rel="noopener" style="color:#6B7280;text-decoration:underline;">Terms &amp; conditions</a>');
    var legalRow = legal.length
      ? '<div style="margin-top:6px;">' + legal.join(' &nbsp;·&nbsp; ') + '</div>'
      : '<div style="margin-top:6px;color:#B0B6BE;">Add privacy &amp; terms links under Settings → Branding</div>';

    var footerHtml =
      '<div style="background:#F9FAFB;border-top:1px solid #EEF0F2;padding:18px 28px;text-align:center;font-size:11px;color:#64748B;">' +
        '<div style="font-weight:700;color:#6B7280;">Powered by Kiddietrac</div>' +
        legalRow +
        '<div style="margin-top:8px;">You’re receiving this because you are part of ' + esc(name) + '.</div>' +
      '</div>';

    var inner =
      '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.12);font-family:Inter,system-ui,sans-serif;">' +
        headerHtml +
        (payload.subject ? '<div style="padding:18px 28px 0;font-size:18px;font-weight:800;color:#111827;">' + esc(payload.subject) + '</div>' : '') +
        (hero ? '<div style="padding:16px 28px 0;"><img src="' + esc(hero) + '" alt="" style="width:100%;border-radius:8px;display:block;" /></div>' : '') +
        /* The preview has to include the header and footer, or it is a preview of
           something nobody receives. */
        (function () {
          var a = assets.list.filter(function (x) { return String(x.id) === String(assets.header); })[0];
          if (!a) { return ''; }
          return (a.image_url ? '<img src="' + esc(a.image_url) + '" alt="" style="display:block;width:100%;">' : '')
            + (a.html || '');
        })() +
        '<div style="padding:18px 28px 24px;font-size:14px;line-height:1.6;color:#1F2937;">' + (payload.body_html || '') + '</div>' +
        (function () {
          var a = assets.list.filter(function (x) { return String(x.id) === String(assets.footer); })[0];
          if (!a) { return ''; }
          return (a.image_url ? '<img src="' + esc(a.image_url) + '" alt="" style="display:block;width:100%;">' : '')
            + (a.html ? '<div style="padding:0 28px 20px;font-size:13px;color:#64748B;">' + a.html + '</div>' : '');
        })() +
        footerHtml +
      '</div>';

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1100;display:flex;flex-direction:column;align-items:center;padding:24px;overflow:auto;' });
    var topBar = Dom.el('div', { style: 'width:100%;max-width:600px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;' });
    topBar.appendChild(Dom.el('div', { style: 'color:#fff;font-weight:700;font-size:14px;' }, '👁 Campaign preview' + (whiteLabel ? ' · white-label' : '')));
    var cls = Dom.el('button', { style: 'background:rgba(255,255,255,.16);color:#fff;border:none;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer;' }, 'Close preview');
    cls.addEventListener('click', function () { overlay.remove(); });
    topBar.appendChild(cls);
    overlay.appendChild(topBar);
    var frame = Dom.el('div', { style: 'width:100%;' });
    frame.innerHTML = inner;
    overlay.appendChild(frame);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  // ── View (read-only) ───────────────────────────────────────────────
  /* THE ⋮ VIEW (2026-11-27, Anthony: "with the campaign table and kebab add ability to view the campaign in a
     popup"). The house popup (KT.Shell.Modal: navy-to-teal header, ✕ in the header, the body is the one scroll
     region). The email is the server's own build (GET /marketing/campaigns/{id}?render=1, the same builder the
     send uses) with {first_name} shown as a sample name. Reads only: nothing here sends or saves. */
  var AUDIENCE_LABEL = { all_families: 'All families', active_families: 'Currently enrolled', waitlist: 'Waitlist', prospects: 'Prospects (no active child)', staff: 'Staff only', custom: 'Custom' };
  var CHANNEL_LABEL = { in_portal: 'In-portal (News post)', email: 'Email', both: 'Email + News post' };
  function when(ts) {
    if (!ts) return '—';
    try { return KT.fmtDateTime ? KT.fmtDateTime(ts) : fmtDate(ts); } catch (e) { return String(ts); }
  }
  function openView(c, container) {
    var M = KT.Shell && KT.Shell.Modal;
    if (!M || !M.open) { openComposer(c, container); return; }
    var bodyEl = Dom.el('div', { class: 'kt-campaign-view', 'data-kt-scroll': '1' });
    bodyEl.appendChild(Dom.el('div', { style: 'padding:24px;text-align:center;color:#64748B;' }, 'Loading…'));
    var actions = [{ label: 'Close' }];
    if (c.status !== 'sent' && c.status !== 'sending') {
      actions.push({ label: 'Edit', primary: true, handler: function () { openComposer(c, container); } });
    }
    M.open({ title: '👁 ' + (c.title || 'Campaign'), body: bodyEl, actions: actions, large: true });

    Api.get('/marketing/campaigns/' + c.id + '?render=1&sample=' + encodeURIComponent(SAMPLE_NAME)).then(function (r) {
      var row = (r && r.campaign) || c;
      var em = (r && r.email) || null;
      Dom.clear(bodyEl);

      var facts = [
        ['Status', (row.status || '').charAt(0).toUpperCase() + (row.status || '').slice(1)],
        ['Audience', AUDIENCE_LABEL[row.audience] || row.audience || '—'],
        ['Channel', CHANNEL_LABEL[row.channel] || row.channel || '—'],
        ['Subject', em ? em.subject : (row.subject || '—')],
        ['Preview text', em && em.preheader ? em.preheader : '—'],
        ['Scheduled for', when(row.scheduled_for)],
        ['Sent', when(row.sent_at)],
      ];
      if (row.sent_at) {
        facts.push(['Recipients', row.recipient_count != null ? String(row.recipient_count) : '—']);
        if (row.channel === 'email' || row.channel === 'both') {
          facts.push(['Emailed', row.email_sent_at
            ? (row.email_delivery_count || 0) + ' delivered' + (row.email_failure_count ? ', ' + row.email_failure_count + ' failed' : '') + ' · ' + when(row.email_sent_at)
            : 'Sending' + (row.email_progress ? ' (' + row.email_progress + ' / ' + (row.recipient_count || '?') + ')' : '')]);
          if (row.email_from) facts.push(['Sent from', row.email_from]);
        }
        facts.push(['Opens · clicks', (row.open_count || 0) + ' · ' + (row.click_count || 0)]);
      }
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 18px;margin:0 0 16px;' });
      facts.forEach(function (f) {
        var cell = Dom.el('div', { style: 'min-width:0;' });
        cell.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#64748B;' }, f[0]));
        cell.appendChild(Dom.el('div', { style: 'font-size:13.5px;color:#0F172A;overflow-wrap:anywhere;' }, f[1] || '—'));
        grid.appendChild(cell);
      });
      bodyEl.appendChild(grid);

      if (!em) {
        bodyEl.appendChild(Dom.el('div', { style: 'padding:14px;border:1px dashed #CBD5E1;border-radius:10px;color:#64748B;font-size:13px;' }, 'The email could not be shown.'));
        return;
      }
      bodyEl.appendChild(Dom.el('div', { style: 'font-size:12px;color:#64748B;margin:0 0 8px;' },
        row.channel === 'in_portal'
          ? 'In-portal only: families read this in News. Shown here in the email frame.'
          : (em.uses_first_name ? 'The email each family receives. {first_name} is shown as "' + (em.sample_name || SAMPLE_NAME) + '"; each family sees their own.' : 'The email each family receives.')));

      /* The email in a sandboxed frame: no scripts run, links open in a new tab, the frame grows to the email's
         height so the popup body stays the one scroll region. */
      var frame = Dom.el('iframe', { title: 'Campaign email', sandbox: 'allow-same-origin allow-popups allow-popups-to-escape-sandbox', scrolling: 'no',
        style: 'display:block;width:100%;min-height:420px;border:1px solid #E2E8F0;border-radius:12px;background:#F4F6F9;' });
      var html = String(em.html || '').replace(/<head>/i, '<head><base target="_blank">');
      frame.srcdoc = html;
      function fit() {
        try { var d = frame.contentDocument; if (d && d.documentElement) frame.style.height = (d.documentElement.scrollHeight + 4) + 'px'; } catch (e) {}
      }
      frame.addEventListener('load', function () {
        fit();
        try { Array.prototype.forEach.call(frame.contentDocument.images || [], function (im) { if (!im.complete) im.addEventListener('load', fit); }); } catch (e) {}
      });
      var onResize = function () { if (!frame.isConnected) { window.removeEventListener('resize', onResize); return; } fit(); };
      window.addEventListener('resize', onResize);
      bodyEl.appendChild(frame);
    }).catch(function (e) {
      Dom.clear(bodyEl);
      bodyEl.appendChild(Dom.el('div', { style: 'padding:18px;color:#B91C1C;' }, 'Could not open this campaign. ' + ((e && e.message) || '')));
    });
  }

  // ── Tiny styling helpers ───────────────────────────────────────────
  function labelEl(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;' }, t); }
  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:14px;font-family:inherit;'; }
  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnSecondary() { return 'background:white;color:#1F6080;border:1px solid #1F6080;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnDanger() { return 'background:white;color:#DC2626;border:1px solid #DC2626;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }

  // ── Shell registration ─────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:marketing-campaigns', render);
    Shell.registerScreen('centre_director:marketing-campaigns', render);
    Shell.registerScreen('platform_admin:marketing-campaigns', render);
  }
  // Also expose as window.KT for the legacy shim
  KT.Marketing = { render: render };
})(window);
