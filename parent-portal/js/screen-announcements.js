/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v13 — Announcements (provider compose + parent inbox)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  // KT.confirm returns a PROMISE — it does not take a callback. Passing one meant
  // the action never ran: the confirm box appeared, you pressed Yes, and nothing
  // happened. This wraps both shapes safely.
  async function ktConfirmThen(message, onYes) {
    try {
      if (window.KT && KT.confirm) {
        var r = KT.confirm(message);
        if (r && typeof r.then === 'function') { r.then(function (ok) { if (ok) onYes(); }); return; }
        return;   // a non-promise KT.confirm would already have handled it
      }
    } catch (e) {}
    if (await KT.confirm(message)) onYes();
  }


  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { try { const v = sessionStorage.getItem('kt_view_as'); if (v) return v; } catch (e) {} const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    // Scope every call to the active agency. Without this, a platform_admin gets
    // 403 on /admin/* — which left the composer's centre dropdown empty and made
    // "Send" silently do nothing. Match the rest of the app's header + storage key.
    var aid = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id');
    if (aid) opts.headers['X-Active-Agency-Id'] = aid;
    if (body instanceof FormData) {
      // No Content-Type: the browser has to set it itself so the multipart
      // boundary is right. Setting it by hand produces a body PHP cannot parse.
      opts.body = body;
    } else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return (r || document).querySelectorAll(s); }

  // Times read in the AGENCY's timezone, not the device's — see kt-tz.js.
  function fmtDate(iso) {
    if (!iso) return '';
    if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(iso);
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    return isNaN(d) ? '' : d.toLocaleString();
  }
  // Date AND time together, for the table Date column.
  function fmtDateTime(iso) {
    if (!iso) return '';
    if (window.KT && KT.fmtDateTime) return KT.fmtDateTime(iso);
    const d = new Date(iso.replace(' ', 'T') + 'Z');
    return isNaN(d) ? '' : d.toLocaleString();
  }

  /* PERSONAL ANNOUNCEMENTS (2026-10-31): a new invoice or payslip, just for this person. Each carries its own
     buttons (View invoice / Pay / View payslip); a button with an invoice id opens that invoice directly. */
  function personalActions(a) {
    var acts = (a && a.actions) || [];
    if (!acts.length) return '';
    return '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">' + acts.map(function (x, i) {
      var primary = i === 0;
      return '<button type="button" class="kt-ann-act" data-kt-iconized="1" data-kt-btn-free="1" data-link="' + esc(x.link || '') + '"' + (x.invoice_id ? ' data-invoice="' + Number(x.invoice_id) + '"' : '')
        + (a.kind === 'payslip' && a.ref_id && /^#my-(hours|pay)/.test(x.link || '') ? ' data-payslip="' + Number(a.ref_id) + '"' : '')
        + ' style="height:34px;padding:0 14px;border-radius:9px;font-size:13px;font-weight:800;cursor:pointer;'
        + (primary ? 'background:#0E7C90;color:#fff;border:1px solid #0E7C90;' : 'background:#fff;color:#0E7C90;border:1px solid #9FD3DC;') + '">' + esc(x.label || 'Open') + '</button>';
    }).join('') + '</div>';
  }
  function bindPersonalActions(root) {
    if (!root || root.__ktAnnActs) return;
    root.__ktAnnActs = true;
    root.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.kt-ann-act');
      if (!b) return;
      e.preventDefault();
      var inv = b.getAttribute('data-invoice');
      if (inv && window.KT && KT.openInvoiceById) { KT.openInvoiceById(inv); return; }
      var slip = b.getAttribute('data-payslip');
      // People with a pay screen go there (it shows the payslip password hint); others (admins) get the payslip itself.
      if (slip && window.KT && KT.openFile && !hasPayScreen()) { openPayslip(slip, b); return; }
      var link = b.getAttribute('data-link') || '';
      if (link.charAt(0) === '#') location.hash = link;
    });
  }
  function hasPayScreen() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      var u2 = u.user || u;
      var roles = (u2.roles || []).map(function (r) { return String((r && (r.role || r.name)) || r); });
      if (u2.role) roles.push(String(u2.role));
      if (window.ktRoles && sessionStorage.getItem('kt_view_as')) roles = window.ktRoles(u2);   // the previewed role
      return roles.some(function (r) { return /^(educator|centre_director|home_visitor|staff)$/.test(r); });
    } catch (e) { return true; }
  }
  function openPayslip(id, btn) {
    var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
    var agency = ''; try { agency = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    var label = btn.textContent; btn.disabled = true; btn.textContent = 'Opening…';
    fetch(base + '/payroll-documents/' + Number(id) + '/pdf', { headers: { Authorization: 'Bearer ' + tok, 'X-Active-Agency-Id': agency } })
      .then(function (r) { if (!r.ok) throw new Error(r.status === 404 ? 'That payslip is no longer available.' : 'Could not open the payslip.'); return r.blob(); })
      .then(function (blob) { KT.openFile(blob, { title: 'Payslip', filename: 'payslip.pdf', label: 'Pay' }); })
      .catch(function (e) { if (window.KT && KT.toast) KT.toast('⚠️', 'Could not open', e.message || '', '#DC2626'); })
      .then(function () { btn.disabled = false; btn.textContent = label; });
  }
  function justForYouChip() {
    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:20px;background:#E0F2F5;color:#0E6B7C;white-space:nowrap;flex-shrink:0;">🔒 Just for you</span>';
  }
  /* FOR YOU, COMPACT (2026-10-31, Anthony: "announcements section something is wrong"). The block showed every
     full letter and was mounted next to the top bar. Now: short cards (title, date, chip, one line, buttons), three
     at a time, placed in the screen's own content just above its list - never before the hero or in the top bar. */
  /* "Sent to" in words (2026-10-31): agency-wide rows read "agency" (the raw scope type). */
  function scopeText(a) {
    if (!a) return '';
    if (a.personal) return 'Just for you';
    if (a.centre_name || a.scope_name) return a.centre_name || a.scope_name;
    return ({ agency: 'Whole agency', centre: 'A centre', room: a.room_name || 'A room', family: 'One family', user: 'Just one person' })[a.scope_type] || a.scope_type || '';
  }
  function personalSummary(html, title) {
    var d = document.createElement('div'); d.innerHTML = String(html || '').replace(/<\/(p|div|li|h[1-6])>|<br\s*\/?>/gi, '$& ');
    var t = (d.textContent || '').replace(/\s+/g, ' ').trim().replace(/^(Hi|Hello|Dear)\s+[^,.!]{1,40}[,.!]\s*/i, '');
    // The letter usually repeats the title ("Your payslip … is ready — net pay $900.00."): keep what it adds.
    var tt = String(title || '').trim();
    if (tt && t.toLowerCase().indexOf(tt.toLowerCase()) === 0) { t = t.slice(tt.length).replace(/^[\s—–\-:,.]+/, ''); t = t.charAt(0).toUpperCase() + t.slice(1); }
    return t.length > 150 ? t.slice(0, 147).replace(/\s+\S*$/, '') + '…' : t;
  }
  function personalBlock(list) {
    list = list || [];
    if (!list.length) return null;
    var SHOW = 3;
    var box = document.createElement('section');
    box.className = 'kt-ann-personal';
    box.setAttribute('aria-label', 'For you');
    box.style.cssText = 'margin:0 0 14px;flex:0 0 auto;';
    var card = function (a, i) {
      return '<div class="kt-ann-pcard"' + (i >= SHOW ? ' data-more="1" hidden' : '') + ' style="background:#fff;border:1px solid #CDE9EE;border-radius:12px;padding:11px 13px;box-shadow:0 1px 3px rgba(15,23,42,.05);min-width:0;">'
        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">'
        + '<div style="font-weight:800;font-size:14px;color:#0F172A;line-height:1.3;min-width:0;">' + esc(a.title) + '</div>'
        + '<div style="font-size:11px;color:#64748B;white-space:nowrap;flex-shrink:0;margin-top:2px;">' + esc(fmtDate(a.sent_at)) + '</div></div>'
        + '<div style="display:flex;align-items:center;gap:8px;margin-top:5px;min-width:0;">' + justForYouChip()
        + '<span style="font-size:12.5px;color:#475569;line-height:1.4;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(personalSummary(a.body, a.title)) + '</span></div>'
        + personalActions(a).replace('margin-top:10px', 'margin-top:8px') + '</div>';
    };
    box.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin:0 2px 8px;">'
      + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#0E6B7C;">For you · ' + list.length + '</div>'
      + (list.length > SHOW ? '<button type="button" class="kt-ann-pmore" data-kt-iconized="1" data-kt-btn-free="1" style="background:none;border:0;color:#0E7C90;font-size:12.5px;font-weight:800;cursor:pointer;padding:2px 4px;min-height:0;">Show all (' + list.length + ')</button>' : '')
      + '</div>'
      + '<div class="kt-ann-pgrid" style="display:grid;gap:9px;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));">' + list.map(card).join('') + '</div>';
    var more = box.querySelector('.kt-ann-pmore');
    if (more) more.addEventListener('click', function () {
      var open = more.getAttribute('data-open') === '1';
      [].forEach.call(box.querySelectorAll('[data-more]'), function (c) { c.hidden = open; });
      more.setAttribute('data-open', open ? '0' : '1');
      more.textContent = open ? 'Show all (' + list.length + ')' : 'Show less';
    });
    bindPersonalActions(box);
    return box;
  }
  /* Mount inside the screen's content: just before `before` (the list), else after `after`. Never into the top bar. */
  function mountPersonal(container, list, after, before) {
    var box = personalBlock(list);
    if (!box || !container) return;
    var old = container.querySelector('.kt-ann-personal');
    if (old) old.remove();
    var bad = function (el) { return !el || !el.parentNode || (el.closest && el.closest('#kt-topbar, .kt-topbar, header')); };
    if (!bad(before)) { before.parentNode.insertBefore(box, before); return; }
    if (!bad(after)) { after.parentNode.insertBefore(box, after.nextSibling); return; }
    if (!bad(container) && !(container.matches && container.matches('#kt-topbar, .kt-topbar'))) container.appendChild(box);
  }

  function isMobile() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native') || (window.matchMedia && window.matchMedia('(max-width:600px)').matches); }

  // ── Phone view: cards, not a table ───────────────────────────────────
  // The desktop view is a sortable/filterable table. Restacking that into cards
  // generically still read like a table (LABEL: value rows), so phones get a
  // purpose-built list: each announcement is a card you can read at a glance,
  // with select + delete (single and bulk). No filter box, no sort headers —
  // there's no room for them and the list is short.
  async function renderProviderMobile(container) {
    container.innerHTML = '<div style="padding:28px;text-align:center;color:#64748B;">Loading…</div>';
    let data;
    try { data = await api('GET', '/provider/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:20px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    let items = data.announcements || [];

    let selecting = false;
    const selected = {};

    const paint = () => {
      const ids = Object.keys(selected).filter(k => selected[k]).map(Number);
      container.innerHTML = `
        <div class="kt-hero" style="background:linear-gradient(120deg,#0E7C90 0%,#159FB4 42%,#7C3AED 100%);">
          <h1>📢 Alerts</h1>
          <div class="kt-hero-sub">${items.length} sent to your families</div>
        </div>
        <div style="display:flex;gap:7px;align-items:center;margin:10px 0 10px;">
          <button id="kt-ann-new" style="flex:1;background:#159FB4;color:#fff;border:none;height:40px;border-radius:10px;font-size:13.5px;font-weight:800;cursor:pointer;">+ New alert</button>
          <button id="kt-ann-select" style="flex:0 0 auto;background:#fff;color:#475569;border:1px solid #CBD5E1;height:40px;padding:0 13px;border-radius:10px;font-size:12.5px;font-weight:700;cursor:pointer;">${selecting ? 'Done' : 'Select'}</button>
        </div>
        ${selecting ? `
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;background:#0F172A;color:#fff;border-radius:11px;padding:8px 11px;margin-bottom:10px;position:sticky;top:2px;z-index:5;">
            <span style="font-size:13px;font-weight:700;">${ids.length} selected</span>
            <span style="display:flex;gap:6px;">
              <button id="kt-ann-all" style="background:rgba(255,255,255,.14);color:#fff;border:none;padding:7px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">${ids.length === items.length && items.length ? 'None' : 'All'}</button>
              <button id="kt-ann-del" ${ids.length ? '' : 'disabled'} style="background:#DC2626;color:#fff;border:none;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;opacity:${ids.length ? 1 : .5};">Delete</button>
            </span>
          </div>` : ''}
        ${items.length === 0
          ? `<div style="text-align:center;padding:34px 16px;background:#fff;border-radius:14px;color:#64748B;">
               <div style="font-size:38px;">📭</div>
               <div style="font-weight:800;color:#0F172A;margin-top:6px;">No alerts sent yet</div>
             </div>`
          : items.map(function (a, i) {
              var when = a.sent_at || a.scheduled_at || a.created_at;
              var scope = scopeText(a);
              var PAL = [['#159FB4', '#0E7C90'], ['#7C3AED', '#5B21B6'], ['#EC4899', '#BE185D'], ['#F59E0B', '#B45309'], ['#10B981', '#047857'], ['#3B82F6', '#1D4ED8']];
              var g = PAL[i % PAL.length];
              var scheduled = (a.scheduled_at && !a.sent_at);
              var chip = function (bg, fg, txt) { return '<span style="display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:20px;background:' + bg + ';color:' + fg + ';">' + txt + '</span>'; };
              var chips = [a.send_email ? chip('#EFF6FF', '#1D4ED8', '📧 Email') : '', a.send_push ? chip('#ECFDF5', '#047857', '🔔 Push') : '', a.send_voice ? chip('#FFFBEB', '#B45309', '📞 Voice') : '', scheduled ? chip('#FEF3C7', '#B45309', '⏱ Scheduled') : '', dueChip(a), doneChip(a)].filter(Boolean).join(' ');
              return `
                <div class="kt-ann-card" data-id="${a.id}" style="display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid #EDF1F6;--kt-edge:${g[0]};border-radius:16px;padding:13px;margin-bottom:11px;box-shadow:0 3px 12px -5px rgba(15,23,42,.18);">
                  ${selecting ? `<input type="checkbox" class="kt-ann-cb" data-id="${a.id}" ${selected[a.id] ? 'checked' : ''} style="width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:${g[0]};">` : `<span style="flex:0 0 auto;width:44px;height:44px;border-radius:13px;background:linear-gradient(135deg,${g[0]},${g[1]});display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 6px 14px -6px ${g[0]};">📢</span>`}
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;font-size:15px;color:#0F172A;line-height:1.3;">${esc(a.title || '(untitled)')}</div>
                    <div style="font-size:13px;color:#475569;line-height:1.45;margin-top:3px;">${esc(plainPreview(a.body, 130))}</div>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:9px;">${chips}<span style="font-size:11px;color:#94A3B8;">${esc(scope)} · ${esc(fmtDate(when))}</span></div>
                  </div>
                  ${selecting ? '' : `<button class="kt-ann-x" data-id="${a.id}" aria-label="Delete" style="background:none;border:none;color:#CBD5E1;font-size:15px;cursor:pointer;padding:4px;flex-shrink:0;">🗑</button>`}
                </div>`;
            }).join('')}
      `;

      var _newBtn = container.querySelector('#kt-ann-new');
      mountPersonal(container, data.personal, _newBtn ? _newBtn.parentNode : container.querySelector('.kt-hero'));
      $('#kt-ann-new', container).addEventListener('click', () => openComposer(container));
      $('#kt-ann-select', container).addEventListener('click', () => {
        selecting = !selecting;
        if (!selecting) Object.keys(selected).forEach(k => delete selected[k]);
        paint();
      });

      container.querySelectorAll('.kt-ann-cb').forEach(cb => {
        cb.addEventListener('change', () => { selected[cb.dataset.id] = cb.checked; paint(); });
      });
      const allBtn = $('#kt-ann-all', container);
      if (allBtn) allBtn.addEventListener('click', () => {
        const on = !(ids.length === items.length && items.length);
        Object.keys(selected).forEach(k => delete selected[k]);
        if (on) items.forEach(a => { selected[a.id] = true; });
        paint();
      });
      const delBtn = $('#kt-ann-del', container);
      if (delBtn) delBtn.addEventListener('click', () => remove(ids));

      container.querySelectorAll('.kt-ann-x').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); remove([Number(b.dataset.id)]); });
      });
      container.querySelectorAll('.kt-ann-card').forEach(card => {
        card.addEventListener('click', () => {
          const id = Number(card.dataset.id);
          if (selecting) {
            selected[id] = !selected[id];
            paint();
            return;
          }
          const a = items.find(x => x.id === id);
          if (a) openDetail(a);
        });
      });
    };

    // Tap a card → read the whole thing: full message, who it went to, when it
    // was sent, and how. The preview on the card is deliberately short, so
    // without this there was no way to read a long announcement at all.
    function openDetail(a) {
      const when = a.sent_at || a.scheduled_at || a.created_at;
      const scope = scopeText(a) || '—';
      const channels = [
        a.send_email ? '📧 Email' : '',
        a.send_push ? '🔔 Push' : '',
        a.send_sms ? '💬 SMS' : '',
        a.send_voice ? '📞 Voice' : '',
      ].filter(Boolean).join(' · ') || 'In-app only';
      const status = a.sent_at ? 'Sent' : (a.scheduled_at ? 'Scheduled' : 'Draft');

      const ov = document.createElement('div');
      ov.style.cssText = 'position:fixed;inset:0;z-index:9600;background:#F6F9FC;overflow-y:auto;-webkit-overflow-scrolling:touch;';
      ov.innerHTML = `
        <div style="position:sticky;top:0;background:#fff;border-bottom:1px solid #E7EDF3;padding:12px 14px;display:flex;align-items:center;gap:10px;z-index:2;">
          <button type="button" data-back style="background:none;border:none;font-size:22px;color:#159FB4;cursor:pointer;padding:0 4px;">‹</button>
          <span style="font-weight:800;font-size:16px;color:#0F172A;">Alert</span>
        </div>
        <div style="padding:14px;">
          <div style="background:#fff;border:1px solid #E7EDF3;--kt-edge:#159FB4;border-radius:14px;padding:15px;">
            <div style="font-weight:800;font-size:18px;color:#0F172A;line-height:1.3;">${esc(a.title || '(untitled)')}</div>
            <div style="font-size:12px;color:#64748B;margin-top:6px;">${esc(fmtDate(when))}</div>
            <div style="font-size:14.5px;color:#334155;line-height:1.6;margin-top:12px;white-space:pre-wrap;">${esc(plainPreview(a.body, 4000))}</div>
            ${pdfLink(a, true)}
            ${askBlock(a, true)}
          </div>
          <div style="background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:15px;margin-top:11px;">
            <div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:9px;">Details</div>
            ${detailRow('Sent to', scope)}
            ${detailRow('Status', status)}
            ${detailRow('Date', fmtDate(when))}
            ${detailRow('Sent via', channels)}
            ${a.created_by_name ? detailRow('Sent by', a.created_by_name) : ''}
            ${a.recipients || a.delivered ? detailRow('Recipients', String(a.recipients || a.delivered)) : ''}
          </div>
          ${a.can_manage ? '<button type="button" id="kt-ann-detail-who" style="width:100%;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:pointer;margin-top:12px;">👥 Who it reached · full details</button>' : ''}
          ${a.can_manage && a.sent_at ? '<button type="button" id="kt-ann-detail-resend" style="width:100%;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:pointer;margin-top:10px;">🔁 Send again</button>' : ''}
          <button id="kt-ann-detail-del" style="width:100%;background:#fff;border:1.5px solid #FCA5A5;color:#B91C1C;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:pointer;margin-top:12px;">Delete this alert</button>
        </div>`;
      document.body.appendChild(ov);

      const close = () => { if (ov.parentNode) ov.parentNode.removeChild(ov); };
      ov.querySelector('[data-back]').addEventListener('click', (e) => {
        e.preventDefault(); e.stopPropagation();
        if (window.KT && KT.popOverlay) KT.popOverlay(ov); else close();
      });
      if (window.KT && KT.pushOverlay) KT.pushOverlay(ov, close);   // Android back closes it

      const who = ov.querySelector('#kt-ann-detail-who'); if (who) who.addEventListener('click', () => annDetails(a));
      const rs = ov.querySelector('#kt-ann-detail-resend'); if (rs) rs.addEventListener('click', () => annResend(a));
      ov.querySelector('#kt-ann-detail-del').addEventListener('click', () => {
        close();
        remove([a.id]);
      });
    }

    function detailRow(label, value) {
      return '<div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #F1F5F9;">'
        + '<span style="font-size:12.5px;color:#64748B;font-weight:700;">' + esc(label) + '</span>'
        + '<span style="font-size:13.5px;color:#0F172A;text-align:right;">' + esc(value) + '</span>'
        + '</div>';
    }

    async function remove(ids) {
      if (!ids || !ids.length) return;
      const msg = 'Delete ' + ids.length + ' alert' + (ids.length === 1 ? '' : 's') + '? This removes it from every family\'s inbox too.';
      const go = async () => {
        try {
          await api('POST', '/provider/announcements/delete', { ids });
          items = items.filter(a => ids.indexOf(a.id) === -1);
          ids.forEach(id => delete selected[id]);
          selecting = false;
          paint();
          if (window.KT && KT.toast) KT.toast('🗑', 'Deleted', ids.length + ' alert' + (ids.length === 1 ? '' : 's') + ' removed.', '#0F172A');
        } catch (e) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', e.message || 'Try again.', '#B91C1C');
        }
      };
      ktConfirmThen(msg, go);
    }

    paint();
  }

  /* VIEW DETAILS + SEND AGAIN (2026-11-09, Anthony: "announcements add a resend option in the kebab including a view
     option to show details of the announcement, who it was sent it, date and all other info"). The sender's side (and
     anyone who could have sent it: can_manage) reads GET /provider/announcements/{id}/details - every person it actually
     reached, when, whether they have read it (and done it, when it asks for something), every channel, its reminders
     and each time it was sent again. Anyone else sees what the list already knows. The popup sits on <body> above
     everything else; Esc, the ✕ and the Android back button close only it. */
  function annPopup(title, bodyEl, actions) {
    const o = document.createElement('div');
    o.setAttribute('data-kt-no-close', '1');
    o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:2147483100;display:flex;align-items:center;justify-content:center;padding:12px;box-sizing:border-box;';
    const m = document.createElement('div');
    m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true'); m.setAttribute('aria-label', title);
    m.style.cssText = 'background:#fff;border-radius:14px;width:100%;max-width:820px;max-height:min(92vh,100%);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,.25);';
    m.innerHTML = '<div class="kt-dlg-head" style="flex:none;position:relative;padding:16px 56px 14px 22px;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);border-bottom:3px solid #2F8C86;">'
      + '<h3 style="margin:0;color:#fff;font-size:17px;font-weight:700;overflow-wrap:anywhere;">' + esc(title) + '</h3>'
      + '<button type="button" data-ap-x aria-label="Close" data-kttip="Close" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:8px;background:rgba(255,255,255,.16);color:#fff;font-size:18px;line-height:1;cursor:pointer;">✕</button></div>'
      + '<div data-ap-body style="flex:1;min-height:0;overflow:auto;padding:14px 22px;scrollbar-width:thin;"></div>'
      + '<div data-ap-foot style="flex:none;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;padding:12px 22px;border-top:1px solid #E2E8F0;"></div>';
    m.querySelector('[data-ap-body]').appendChild(bodyEl);
    const foot = m.querySelector('[data-ap-foot]');
    function close() {
      window.removeEventListener('keydown', onKey, true);
      if (o.isConnected) o.remove();
      if (window.KT && KT.popOverlay) KT.popOverlay(o);
    }
    function onKey(e) {
      if (e.key !== 'Escape' || !o.isConnected) return;
      if ([].some.call(document.body.children, (c) => c !== o && +getComputedStyle(c).zIndex >= 2147483640 && c.getClientRects().length && /fixed/.test(getComputedStyle(c).position))) return;
      e.preventDefault(); e.stopImmediatePropagation(); close();
    }
    (actions || []).forEach((a) => {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = a.label;
      b.className = 'kt-btn kt-btn-sm' + (a.primary ? ' kt-btn-primary' : '');
      b.addEventListener('click', () => { if (a.handler) a.handler(close); else close(); });
      foot.appendChild(b);
    });
    o.appendChild(m);
    window.addEventListener('keydown', onKey, true);
    m.querySelector('[data-ap-x]').addEventListener('click', close);
    let down = false;
    o.addEventListener('mousedown', (e) => { down = e.target === o; });
    o.addEventListener('click', (e) => { if (e.target === o && down) close(); });
    document.body.appendChild(o);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(o, () => { window.removeEventListener('keydown', onKey, true); o.remove(); });
    return { close };
  }
  const AUDIENCE_WORD = { parents: 'Parents', educators: 'Educators', contractors: 'Contractors', admins: 'Admins and directors', all: 'Everyone' };
  function annFact(label, value) {
    if (value == null || value === '') return '';
    return '<div style="color:#64748B;">' + esc(label) + '</div><div style="color:#0F172A;overflow-wrap:anywhere;">' + value + '</div>';
  }
  async function annDetails(it, onResent) {
    const body = document.createElement('div');
    body.innerHTML = '<div style="font-size:13px;color:#64748B;padding:6px 0;">Loading the details…</div>';
    const canResend = !!(it.can_manage && it.sent_at);
    const pop = annPopup('📢 ' + (it.title || 'Announcement'), body, [].concat(
      canResend ? [{ label: '🔁 Send again', handler: () => annResend(it, () => { if (onResent) onResent(); }) }] : [],
      [{ label: 'Close', primary: true }]));
    let d = null;
    if (it.can_manage) {
      try { d = await api('GET', '/provider/announcements/' + it.id + '/details'); }
      catch (e) { body.innerHTML = '<div style="font-size:13px;color:#B91C1C;padding:6px 0;">' + esc(e.message || 'The details could not be loaded.') + '</div>'; return pop; }
    }
    // Without can_manage: what the list knows.
    const ch = d ? d.channels : { in_app: true, push: !!it.send_push, email: !!it.send_email, sms: !!it.send_sms, voice: !!it.send_voice };
    const chText = [ch.in_app ? '🔔 In the app' : '', ch.push ? '📲 Phone notification' : '', ch.email ? '📧 Email' : '', ch.sms ? '💬 Text' : '',
      ch.voice ? '📞 Phone call' + (d && d.voice_category ? ' (' + esc(d.voice_category) + ')' : '') : ''].filter(Boolean).join(' · ');
    const when = (x, iso) => (d ? (d[x] || '') : (iso ? esc(fmtDateTime(iso)) : ''));
    const status = it.sent_at ? '✅ Sent' : (it.scheduled_at ? '🕓 Scheduled' : 'Draft');
    const reminders = d ? (d.reminders || []).map((r) => esc(r.at) + (r.sent ? ' · sent' + (r.delivered != null ? ' to ' + r.delivered : '') : ' · waiting')).join('<br>')
      : (it.reminders || []).map((r) => esc(fmtDateTime(r.remind_at)) + (r.sent_at ? ' · sent' : ' · waiting')).join('<br>');
    let html = '<div style="display:grid;grid-template-columns:auto 1fr;gap:5px 14px;font-size:13px;margin-bottom:12px;">'
      + annFact('Status', status)
      + annFact('Sent to', esc(d ? d.where : (scopeText(it) || '')))
      + annFact('Audience', esc(d ? d.audience : (AUDIENCE_WORD[it.audience] || '')))
      + annFact('Sent by', esc(d ? (d.sender || '') : (it.sender || '')))
      + annFact('Written', when('created_at', it.created_at))
      + (it.scheduled_at ? annFact('Scheduled for', when('scheduled_at', it.scheduled_at)) : '')
      + annFact('Went out', when('sent_at', it.sent_at))
      + annFact('Due by', when('due_at', it.due_at))
      + annFact('Reminders', reminders)
      + annFact('Sent by way of', chText)
      + (d ? annFact('Reached', d.counts.total + ' ' + (d.counts.total === 1 ? 'person' : 'people') + ' · ' + d.counts.read + ' read'
          + (d.counts.done != null ? ' · ' + d.counts.done + ' done' : '')) : '')
      + '</div>'
      + '<div style="border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.6;color:#1F2937;overflow-wrap:anywhere;">'
      + '<div style="font-weight:800;font-size:15px;margin-bottom:6px;">' + esc((d && d.title) || it.title || '') + '</div>'
      + ((d && d.body) || it.body || '') + '</div>'
      + (d && d.image_url ? '<img src="' + esc(d.image_url) + '" alt="" style="display:block;max-width:100%;max-height:260px;border-radius:8px;margin-top:10px;">' : '')
      + pdfLink(d ? { pdf_name: d.pdf_name, pdf_url: d.pdf_url } : it, true);
    if (d && d.resends && d.resends.length) {
      html += '<div style="margin-top:14px;font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;">Sent again</div>'
        + d.resends.map((r) => '<div style="font-size:13px;padding:4px 0;border-bottom:1px solid #F1F5F9;">' + esc(r.at) + ' · ' + esc(r.by || '') + ' · to ' + r.to
          + (r.who === 'unread' ? ' who had not read it' : '') + (r.channels && r.channels.length ? ' (' + esc(r.channels.join(', ')) + ')' : '') + '</div>').join('');
    }
    if (d) {
      html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:16px;">'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-right:auto;">Who it reached</div>'
        + ['all', 'unread', 'read'].map((f) => '<button type="button" class="kt-btn kt-btn-sm" data-kt-chip data-ap-f="' + f + '">'
          + ({ all: 'All ' + d.counts.total, unread: 'Not read ' + (d.counts.total - d.counts.read), read: 'Read ' + d.counts.read })[f] + '</button>').join('')
        + '</div><div data-ap-people style="margin-top:8px;"></div>';
    }
    body.innerHTML = html;
    if (!d) return pop;
    const host = body.querySelector('[data-ap-people]');
    const draw = (f) => {
      body.querySelectorAll('[data-ap-f]').forEach((b) => { const on = b.getAttribute('data-ap-f') === f; b.style.background = on ? '#1F6080' : ''; b.style.color = on ? '#fff' : ''; });
      const list = d.recipients.filter((r) => f === 'all' || (f === 'read' ? r.read : !r.read));
      if (!list.length) { host.innerHTML = '<div style="font-size:13px;color:#64748B;padding:6px 0;">' + (d.recipients.length ? 'Nobody here.' : (it.sent_at ? 'Nobody is on record as receiving it: there was nobody in that audience when it went out, or its delivery history has been cleared.' : 'It has not gone out yet.')) + '</div>'; return; }
      host.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="text-align:left;color:#64748B;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;">'
        + '<th style="padding:6px 8px;">Person</th><th style="padding:6px 8px;">Who</th><th style="padding:6px 8px;">Reached</th><th style="padding:6px 8px;">Read</th>'
        + (d.tracks_done ? '<th style="padding:6px 8px;">Done</th>' : '') + '</tr></thead><tbody>'
        + list.map((r, i) => '<tr style="background:' + (i % 2 ? '#F7F9FB' : '#fff') + ';">'
          + '<td style="padding:6px 8px;"><div style="font-weight:700;color:#0F172A;">' + esc(r.name) + (r.removed ? ' <span style="color:#94A3B8;font-weight:400;">(account closed)</span>' : '') + '</div>'
          + (r.email ? '<div style="color:#64748B;font-size:12px;">' + esc(r.email) + '</div>' : '') + '</td>'
          + '<td style="padding:6px 8px;">' + esc(r.kind) + (r.family ? '<div style="color:#64748B;font-size:12px;">' + esc(r.family) + '</div>' : '') + '</td>'
          + '<td style="padding:6px 8px;white-space:nowrap;">' + esc(r.delivered_at || '') + (r.times > 1 ? '<div style="color:#64748B;font-size:12px;">' + r.times + ' times</div>' : '') + '</td>'
          + '<td style="padding:6px 8px;white-space:nowrap;">' + (r.read ? '<span style="color:#047857;font-weight:700;">✓ Read</span>' + (r.read_at ? '<div style="color:#64748B;font-size:12px;">' + esc(r.read_at) + '</div>' : '') : '<span style="color:#B45309;font-weight:700;">Not yet</span>') + '</td>'
          + (d.tracks_done ? '<td style="padding:6px 8px;">' + (r.done ? '<span style="color:#047857;font-weight:700;">✓ Done</span>' : '<span style="color:#64748B;">Not yet</span>') + '</td>' : '')
          + '</tr>').join('') + '</tbody></table></div>';
    };
    body.querySelectorAll('[data-ap-f]').forEach((b) => b.addEventListener('click', () => draw(b.getAttribute('data-ap-f'))));
    draw('all');
    return pop;
  }
  async function annResend(it, after) {
    if (!(window.KT && KT.prompt)) return;
    const yn = (on) => [{ value: on ? '1' : '0', label: on ? 'Yes' : 'No' }, { value: on ? '0' : '1', label: on ? 'No' : 'Yes' }];
    const v = await KT.prompt({
      title: '🔁 Send this announcement again',
      description: '"' + (it.title || 'Announcement') + '" goes again to the people it reached, and shows as new for them. It never places phone calls again.',
      okLabel: 'Send it again', cancelLabel: 'Cancel',
      fields: [
        { key: 'who', label: 'Send it to', type: 'select', value: 'unread', options: [
          { value: 'unread', label: 'Only people who have not read it yet' }, { value: 'all', label: 'Everyone it went to' }] },
        { key: 'push', label: 'Phone notification', type: 'select', options: yn(it.send_push !== false) },
        { key: 'email', label: 'Email', type: 'select', options: yn(!!it.send_email) },
        { key: 'sms', label: 'Text message', type: 'select', options: yn(!!it.send_sms) },
      ],
    });
    if (!v) return;
    try {
      const r = await api('POST', '/provider/announcements/' + it.id + '/resend', { who: v.who, push: v.push === '1', email: v.email === '1', sms: v.sms === '1' });
      if (window.KT && KT.toast) KT.toast('🔁', 'Sent again', 'Reached ' + r.delivered_to + ' ' + (r.delivered_to === 1 ? 'person' : 'people') + (v.who === 'unread' ? ' who had not read it' : '') + '.', '#16A34A');
      if (after) after(r);
    } catch (e) {
      if (window.KT && KT.toast) KT.toast('⚠️', 'Could not send it again', e.message || 'Try again.', '#B91C1C');
    }
  }

  async function renderProvider(container, opts) {
    const readOnly = !!(opts && opts.readOnly);
    if (isMobile()) return readOnly ? renderReadOnly(container) : renderProviderMobile(container);
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/provider/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    /* ONE LIST (2026-11-03, Anthony: "in annoucements you have the payslip listed there and not in the table - make
       this right"). The person's own notices (payslip, invoice) were a "For you" block above the table; they are
       rows of it now, newest first with the rest, marked "Just for you", their buttons in the opened row. */
    const items = (data.announcements || []).slice();
    const seen = {};
    items.forEach((a) => { seen[a.id] = 1; });
    (data.personal || []).forEach((a) => { if (!seen[a.id]) { seen[a.id] = 1; items.push(a); } });

    // Shared icon-button tooltip (used by the New-announcement icon; native `title`
    // is the fallback). Injected once, reusable platform-wide via `.kt-icon-tip`.
    if (!document.getElementById('kt-icon-tip-style')) {
      const _ts = document.createElement('style'); _ts.id = 'kt-icon-tip-style';
      _ts.textContent = '';   // hover label: kt-tooltip-global.js (one bubble portal-wide, 2026-11-04)
      document.head.appendChild(_ts);
    }

    const state = { sort: 'date', dir: -1, q: '', page: 1, archived: false };
    const liveCount = () => items.filter((a) => !a.archived).length;
    const archCount = () => items.filter((a) => a.archived).length;
    const subText = () => state.archived
      ? archCount() + ' archived · ⋮ on a row to bring it back'
      : liveCount() + (readOnly ? ' announcement' + (liveCount() === 1 ? '' : 's') : ' sent') + ' · tap a row to read it';
    const th = (key, label, extra) => `<th data-sort="${key}" class="kt-ann-th" style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;cursor:pointer;user-select:none;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label} <span class="kt-ar" style="color:#64748B;"></span></th>`;

    container.innerHTML = `
      <div style="display:flex;flex-direction:column;height:100%;min-height:0;padding:20px 24px 0;box-sizing:border-box;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:12px;flex:0 0 auto;">
          <div>
            <h2 style="font-size:22px;margin:0;">📢 Announcements</h2>
            <p data-ann-sub="1" style="color:#6B7280;font-size:13px;margin:3px 0 0;">${subText()}</p>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:nowrap;">
            <input id="kt-ann-filter" type="search" placeholder="🔍 Filter…" style="padding:9px 12px;border:1px solid #D1D5DB;border-radius:9px;font-size:13px;min-width:190px;">
            <button id="kt-ann-allread" type="button" data-kt-iconized="1" style="display:none;align-items:center;gap:5px;height:37px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#1B3A63;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">✓ Mark all read</button>
            <button id="kt-ann-arch" type="button" data-kt-iconized="1" aria-pressed="false" style="display:${archCount() ? 'inline-flex' : 'none'};align-items:center;gap:5px;height:37px;padding:0 12px;border-radius:9px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">🗄 Archived (${archCount()})</button>
            ${readOnly ? '' : `<button id="kt-new-ann" class="kt-icon-tip" title="New announcement" aria-label="New announcement" data-kttip="New announcement" style="background:#1F6080;color:white;border:none;width:37px;height:37px;border-radius:9px;font-size:17px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto;">➕</button>`}
          </div>
        </div>
        ${items.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:48px;margin-bottom:12px;">📭</div>
              No announcements sent yet.
            </div>`
          : `<div class="kt-tbl-scroll" style="flex:1 1 auto;min-height:0;max-height:calc(100vh - 210px);overflow-y:auto;background:#fff;border:1px solid #E5E7EB;border-radius:12px;">
              <table data-kt-filtered="1" data-kt-no-kebab="1" style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
                <thead>
                  <tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
                    ${th('scope', 'Sent to', { w: '180px' })}
                    ${th('title', 'Announcement')}
                    ${th('channels', 'Channels', { w: '90px' })}
                    ${th('date', 'Date', { w: '150px', right: true })}
                    <th style="width:52px;" aria-label="Actions"></th>
                  </tr>
                </thead>
                <tbody id="kt-ann-tbody"></tbody>
              </table>
            </div>`
        }
        <div id="kt-ann-pager"></div>
        <div id="kt-mount"></div>
      </div>
    `;
    if (!readOnly) $('#kt-new-ann', container).addEventListener('click', () => openComposer(container));

    if (items.length) {
      const tbody = $('#kt-ann-tbody', container);
      bindPersonalActions(tbody);
      const sortVal = (a) => {
        if (state.sort === 'scope') return scopeText(a).toLowerCase();
        if (state.sort === 'title') return (a.title || '').toLowerCase();
        if (state.sort === 'channels') return (a.send_email ? 1 : 0) + (a.send_push ? 2 : 0);
        return new Date(String(a.sent_at || a.created_at || '').replace(' ', 'T')).getTime() || 0;
      };
      const paint = () => {
        const q = state.q.trim().toLowerCase();
        let list = items.map((a, i) => ({ a, i })).filter(({ a }) => !!a.archived === state.archived);
        if (q) list = list.filter(({ a }) => ((a.title || '') + ' ' + scopeText(a) + ' ' + plainPreview(a.body, 400)).toLowerCase().indexOf(q) !== -1);
        // Stable order for equal keys (the merged personal rows): newest first.
        const when = (a) => new Date(String(a.sent_at || a.created_at || '').replace(' ', 'T')).getTime() || 0;
        list.sort((x, y) => { const va = sortVal(x.a), vb = sortVal(y.a); return ((va < vb ? -1 : va > vb ? 1 : 0) * state.dir) || (when(y.a) - when(x.a)); });
        /* PAGES AND ROWS PER PAGE (2026-10-20). This table draws its own filter and sort,
           so the portal's table pager never reached it and every announcement showed at
           once. Same bar and the same rows-per-page choice as every other table now. */
        const RPK = 'announcements:list';
        const chosen = (window.KT && KT.rowsPerPage) ? KT.rowsPerPage.get(RPK) : null;
        const total = list.length;
        const size = chosen === 0 ? Math.max(1, total) : (chosen || 25);
        const pages = Math.max(1, Math.ceil(total / size));
        if (state.page > pages) state.page = pages;
        if (state.page < 1) state.page = 1;
        const shown = list.slice((state.page - 1) * size, state.page * size);
        tbody.innerHTML = shown.length ? shown.map(({ a, i }) => annRow(a, i)).join('') : '<tr><td colspan="5" style="padding:26px;text-align:center;color:#64748B;">' + (state.archived ? 'Nothing archived.' : q ? 'No matching announcements.' : 'Nothing here. Archived ones are under 🗄 Archived.') + '</td></tr>';
        const sub = container.querySelector('[data-ann-sub]');
        if (sub) sub.textContent = subText();
        const ab = $('#kt-ann-arch', container);
        if (ab) {
          ab.textContent = state.archived ? '← Back to the list' : '🗄 Archived (' + archCount() + ')';
          ab.style.display = (state.archived || archCount()) ? 'inline-flex' : 'none';
          ab.setAttribute('aria-pressed', state.archived ? 'true' : 'false');
        }
        const ar = $('#kt-ann-allread', container);
        if (ar) ar.style.display = (!state.archived && items.filter((a) => !a.archived && a.key && a.read === false).length > 1) ? 'inline-flex' : 'none';
        const pgEl = $('#kt-ann-pager', container);
        if (pgEl && window.KT && KT.pagerBar) {
          KT.pagerBar(pgEl, state.page, pages, (p) => { state.page = p; paint(); }, KT.rowsPerPage ? {
            size: chosen === 0 ? 0 : size, total: total, allowAll: true,
            onSize: (n) => { KT.rowsPerPage.set(RPK, n); state.page = 1; paint(); },
          } : undefined);
        }
        // Zebra striping + hover + click-to-expand, re-applied after each sort/filter.
        let z = 0;
        tbody.querySelectorAll('.kt-ann-row').forEach((row) => {
          const base = (z++ % 2) ? '#F7F9FB' : '#FFFFFF';
          row.dataset.base = base; row.style.background = base;
          row.addEventListener('mouseenter', () => { if (!row.dataset.open) row.style.background = '#EEF4F7'; });
          row.addEventListener('mouseleave', () => { if (!row.dataset.open) row.style.background = row.dataset.base; });
          row.addEventListener('click', (e) => {
            if (e.target.closest && e.target.closest('[data-ann-menu]')) return;
            const idx = row.getAttribute('data-idx');
            const det = tbody.querySelector('.kt-ann-detail[data-idx="' + idx + '"]');
            const open = det && det.style.display !== 'none';
            if (det) det.style.display = open ? 'none' : 'table-row';
            const it = items[Number(idx)];
            if (!open && it && it.key && it.read === false) {
              it.read = true;
              const dot = row.querySelector('[data-ann-dot]'); if (dot) dot.remove();
              const tt = row.querySelector('[data-ann-title]'); if (tt) tt.style.fontWeight = '600';
              setState([it.key], 'read').catch(() => {});
            }
            row.dataset.open = open ? '' : '1';
            row.style.background = open ? row.dataset.base : '#E4EEF3';
          });
        });
        container.querySelectorAll('.kt-ann-th').forEach((h) => {
          const ar = h.querySelector('.kt-ar'); if (!ar) return;
          ar.textContent = (h.getAttribute('data-sort') === state.sort) ? (state.dir < 0 ? '▾' : '▴') : '';
        });
      };
      container.querySelectorAll('.kt-ann-th').forEach((h) => {
        h.addEventListener('click', () => {
          const k = h.getAttribute('data-sort');
          if (state.sort === k) state.dir = -state.dir; else { state.sort = k; state.dir = (k === 'date') ? -1 : 1; }
          state.page = 1;
          paint();
        });
      });
      /* DELETE FROM THE DESKTOP LIST (2026-11-03, Anthony: "for announcements on desktop add kebab to be able to
         delete"). Phones already had it (renderProviderMobile); the desktop table had no way to remove one. Same
         endpoint and the same warning: it leaves every family's inbox too.
         MARK READ + ARCHIVE (2026-11-07, Anthony: "no kebab in desktop view for announcements to archive, delete, mark
         as read"). The ⋮ now has Mark read / unread and Archive / Unarchive - this person's own list only
         (POST /provider/announcements/state) - and Delete only on rows the server says they may delete (can_delete,
         the same rule the delete applies). Home visitors get this table too, read-only (no ➕). */
      $('#kt-ann-arch', container).addEventListener('click', () => { state.archived = !state.archived; state.page = 1; paint(); });
      $('#kt-ann-allread', container).addEventListener('click', () => {
        const un = items.filter((a) => !a.archived && a.key && a.read === false);
        un.forEach((a) => { a.read = true; });
        paint();
        setState(un.map((a) => a.key), 'read').catch(() => {
          un.forEach((a) => { a.read = false; }); paint();
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not save', 'Try again.', '#B91C1C');
        });
      });
      tbody.addEventListener('click', (e) => {
        const k = e.target.closest && e.target.closest('[data-ann-menu]');
        if (!k) return;
        e.preventDefault(); e.stopPropagation();
        const it = items[Number(k.getAttribute('data-ann-menu'))];
        if (!it) return;
        const id = Number(it.id);
        const btn = (label, fn) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', fn); return b; };
        const flip = (field, val, action, undoMsg) => {
          it[field] = val; paint();
          setState([it.key], action).then(() => {
            if (undoMsg && window.KT && KT.toast) KT.toast('🗄', undoMsg, '', '#0F172A');
          }).catch(() => {
            it[field] = !val; paint();
            if (window.KT && KT.toast) KT.toast('⚠️', 'Could not save', 'Try again.', '#B91C1C');
          });
        };
        const acts = [];
        acts.push(btn('👁 View details', () => annDetails(it)));
        if (it.can_manage && it.sent_at) acts.push(btn('🔁 Send again', () => annResend(it)));
        // Older than 30 days counts as read on the server, so "unread" is only offered where it can stick.
        const when = it.sent_at || it.created_at;
        const recent = when && (Date.now() - new Date(String(when).replace(' ', 'T') + 'Z').getTime()) < 29 * 864e5;
        if (it.key) {
          if (it.read === false) acts.push(btn('✓ Mark read', () => flip('read', true, 'read')));
          else if (recent) acts.push(btn('● Mark unread', () => flip('read', false, 'unread')));
          acts.push(it.archived
            ? btn('↩ Unarchive', () => flip('archived', false, 'unhide', 'Back in your list'))
            : btn('🗄 Archive', () => flip('archived', true, 'hide', 'Archived')));
        }
        if (!it.can_delete) { if (window.KT && KT.openActionMenu) KT.openActionMenu(k, acts); return; }
        const del = document.createElement('button');
        del.type = 'button'; del.textContent = '🗑 Delete';
        acts.push(del);
        del.addEventListener('click', () => {
          ktConfirmThen('Delete this announcement? This removes it from every family\'s inbox too.', async () => {
            try {
              await api('POST', '/provider/announcements/delete', { ids: [id] });
              const at = items.findIndex((x) => Number(x.id) === id);
              if (at !== -1) items.splice(at, 1);
              paint();
              if (window.KT && KT.toast) KT.toast('🗑', 'Deleted', 'Announcement removed.', '#0F172A');
            } catch (err) {
              if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', err.message || 'Try again.', '#B91C1C');
            }
          });
        });
        if (window.KT && KT.openActionMenu) KT.openActionMenu(k, acts);
        else del.click();
      });
      const fi = $('#kt-ann-filter', container);
      if (fi) fi.addEventListener('input', () => { state.q = fi.value || ''; state.page = 1; paint(); });
      paint();
      enableColResize(container);
    }
  }

  // Drag the right edge of any header to widen a column and read content that's
  // otherwise clipped (title/preview especially). On the first drag every column
  // is frozen to its current pixel width and the table switches to horizontal
  // scroll — so widening one column never squashes the others.
  function enableColResize(container) {
    const table = $('table[data-kt-filtered]', container);
    if (!table || table.dataset.ktResizable) return;
    table.dataset.ktResizable = '1';
    const wrapper = table.parentElement;               // the vertical scroll box
    if (wrapper) wrapper.style.overflowX = 'auto';

    if (!document.getElementById('kt-col-grip-css')) {
      const s = document.createElement('style'); s.id = 'kt-col-grip-css';
      s.textContent = '.kt-col-grip{position:absolute;top:0;right:0;width:9px;height:100%;cursor:col-resize;z-index:2;}'
        + '.kt-col-grip:hover,.kt-col-grip.kt-grip-drag{background:linear-gradient(to right,transparent,rgba(31,96,128,.28));}';
      document.head.appendChild(s);
    }

    const ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));
    let frozen = false;
    const freeze = () => {
      if (frozen) return;
      ths.forEach(h => { h.style.width = h.offsetWidth + 'px'; });
      table.style.width = 'auto';
      table.style.minWidth = '100%';
      // Lock the scroll box to its current width so widening a column scrolls INSIDE
      // it instead of growing the flex layout — which pushed the whole page + top bar.
      if (wrapper) {
        wrapper.style.boxSizing = 'border-box';
        wrapper.style.width = wrapper.offsetWidth + 'px';
        wrapper.style.maxWidth = '100%';
        wrapper.style.overflowX = 'auto';
      }
      frozen = true;
    };

    ths.forEach((th, idx) => {
      if (idx === ths.length - 1) return;              // last column just flexes
      th.style.position = 'relative';
      const grip = document.createElement('span');
      grip.className = 'kt-col-grip';
      grip.title = 'Drag to resize column';
      th.appendChild(grip);
      grip.addEventListener('click', (e) => { e.stopPropagation(); });   // never sort
      grip.addEventListener('mousedown', (e) => {
        e.preventDefault(); e.stopPropagation();
        freeze();
        grip.classList.add('kt-grip-drag');
        const startX = e.clientX, startW = th.offsetWidth;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        const move = (ev) => { th.style.width = Math.max(60, startW + (ev.clientX - startX)) + 'px'; };
        const up = () => {
          document.removeEventListener('mousemove', move);
          document.removeEventListener('mouseup', up);
          grip.classList.remove('kt-grip-drag');
          document.body.style.cursor = '';
          document.body.style.userSelect = '';
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
      });
    });
  }

  function plainPreview(html, n) {
    var tmp = document.createElement('div');
    tmp.innerHTML = String(html == null ? '' : html).replace(/<\/(p|div|li|h[1-6])>|<br\s*\/?>/gi, '$& ');
    var t = (tmp.textContent || '').replace(/\s+/g, ' ').trim();
    n = n || 90;
    return t.length > n ? t.slice(0, n) + '…' : t;
  }

  /* An announcement's PDF (2026-10-20). The link is signed and expiring (the server hands
     it only to people who may see the announcement); opened in the portal's viewer where
     there is one, else in a new tab. */
  function pdfLink(a, big) {
    if (!a || !a.pdf_url) return '';
    var st = big
      ? 'display:flex;align-items:center;gap:8px;background:#F1F7FB;border:1px solid #CFE3EE;border-radius:10px;padding:10px 12px;margin-top:12px;color:#1F6080;font-weight:700;font-size:14px;text-decoration:none;'
      : 'display:inline-flex;align-items:center;gap:6px;background:#F1F7FB;border:1px solid #CFE3EE;border-radius:8px;padding:5px 10px;margin-top:8px;color:#1F6080;font-weight:700;font-size:12.5px;text-decoration:none;';
    return '<a href="' + esc(a.pdf_url) + '" target="_blank" rel="noopener" class="kt-ann-pdf" data-name="' + esc(a.pdf_name || 'PDF') + '" style="' + st + '">📄 '
      + esc(a.pdf_name || 'Open the PDF') + '</a>';
  }
  document.addEventListener('click', function (e) {
    var l = e.target && e.target.closest && e.target.closest('a.kt-ann-pdf');
    if (!l) return;
    e.stopPropagation();
    if (!(window.KT && KT.viewBlob)) return;            // plain new-tab open
    e.preventDefault();
    var url = l.getAttribute('href'), name = l.getAttribute('data-name') || 'PDF';
    fetch(url).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (b) { KT.viewBlob(b, { title: name, label: 'PDF' }); })
      .catch(function () { window.open(url, '_blank', 'noopener'); });
  }, true);

  /* ─── THE ASK (2026-10-22) ───────────────────────────────────────────
     Anthony: "add sent reminder function as optional if you are sending an announcement
     to ask for something". An announcement can carry a due date and up to two reminders;
     in 'pending' mode its people get a ✓ Done button and reminders skip whoever has
     tapped it (a parent's tap covers their family). */
  function dueChip(a) {
    if (!a || !a.due_at) return '';
    return '<span style="display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:20px;background:#FFFBEB;color:#B45309;">⏰ Due ' + esc(fmtDate(a.due_at)) + '</span>';
  }
  function doneChip(a) {
    if (!a || a.remind_who !== 'pending' || a.recipient_count == null) return '';
    var all = a.done_count >= a.recipient_count && a.recipient_count > 0;
    return '<span style="display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:20px;background:' + (all ? '#DCFCE7' : '#F1F5F9') + ';color:' + (all ? '#15803D' : '#475569') + ';">✓ ' + a.done_count + ' of ' + a.recipient_count + ' done</span>';
  }
  function doneBtn(a) {
    var done = !!a.my_done;
    return '<button type="button" class="kt-ann-done" data-id="' + a.id + '" data-done="' + (done ? '1' : '0') + '" style="display:inline-flex;align-items:center;gap:6px;border-radius:9px;padding:7px 13px;font-size:13px;font-weight:800;cursor:pointer;'
      + (done ? 'background:#DCFCE7;color:#15803D;border:1px solid #86EFAC;">✓ Done · Undo' : 'background:#159FB4;color:#fff;border:none;">✓ Mark as done') + '</button>';
  }
  /* The sender's view of the ask: due date, each reminder and what became of it, the
     count, and "Who's done". forMe adds the viewer's own Done when it was sent to them. */
  function askBlock(a, forMe) {
    if (!a || (!a.due_at && !(a.reminders && a.reminders.length))) return '';
    var rem = (a.reminders || []).map(function (r) {
      return '<li>' + esc(fmtDate(r.remind_at)) + (r.sent_at ? ' — <span style="color:#15803D;font-weight:700;">sent to ' + (r.delivered || 0) + '</span>' : ' — <span style="color:#64748B;">waiting</span>') + '</li>';
    }).join('');
    var who = a.remind_who === 'pending' ? 'Only people who have not tapped ✓ Done' : 'Everyone again';
    return '<div class="kt-ann-ask" style="margin-top:10px;border:1px solid #FDE68A;background:#FFFBEB;border-radius:10px;padding:10px 12px;font-size:13px;color:#78350F;line-height:1.5;">'
      + (a.due_at ? '<div><strong>⏰ Due:</strong> ' + esc(fmtDate(a.due_at)) + '</div>' : '')
      + (rem ? '<div style="margin-top:4px;"><strong>Reminders</strong> (' + esc(who) + '):<ul style="margin:3px 0 0 18px;padding:0;">' + rem + '</ul></div>' : '')
      + ((a.remind_who === 'pending' || (forMe && a.for_me)) ? '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;">'
          + (a.remind_who === 'pending' ? doneChip(a) + '<button type="button" class="kt-ann-who" data-id="' + a.id + '" style="background:#fff;border:1px solid #FCD34D;color:#92400E;border-radius:8px;padding:5px 11px;font-size:12.5px;font-weight:700;cursor:pointer;">Who’s done?</button>' : '')
          + (forMe && a.for_me ? doneBtn(a) : '')
        + '</div>' : '')
      + '</div>';
  }
  async function toggleDone(btn) {
    var id = btn.getAttribute('data-id');
    var was = btn.getAttribute('data-done') === '1';
    var parent = getRole() === 'guardian';
    btn.disabled = true;
    try {
      var r = await api('POST', (parent ? '/parent' : '/provider') + '/announcements/' + id + '/done', { done: !was });
      var tmp = document.createElement('div');
      tmp.innerHTML = doneBtn({ id: id, my_done: !!r.my_done });
      btn.replaceWith(tmp.firstChild);
      if (window.KT && KT.toast) KT.toast(r.my_done ? '✓' : '↩', r.my_done ? 'Marked as done' : 'Marked as not done',
        r.my_done ? 'Thanks — you will not be reminded about this one.' : 'You will get its reminders again.', '#0E7C90');
    } catch (e) {
      btn.disabled = false;
      if (window.KT && KT.toast) KT.toast('⚠️', 'Could not save', e.message || 'Please try again.', '#B91C1C');
    }
  }
  async function showWhoDone(id) {
    var mount = document.createElement('div');
    mount.innerHTML = '<div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;box-sizing:border-box;">'
      + '<div data-kt-scroll style="background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:calc(100dvh - 32px);overflow-y:auto;padding:20px;box-sizing:border-box;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><h3 style="margin:0;font-size:17px;">Who’s done?</h3>'
      + '<button type="button" data-x aria-label="Close" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748B;">×</button></div>'
      + '<div data-body style="margin-top:12px;color:#64748B;font-size:13px;">Loading…</div></div></div>';
    document.body.appendChild(mount);
    var close = function () { mount.remove(); };
    mount.querySelector('[data-x]').addEventListener('click', close);
    mount.firstChild.addEventListener('click', function (e) { if (e.target === mount.firstChild) close(); });
    var body = mount.querySelector('[data-body]');
    try {
      var d = await api('GET', '/provider/announcements/' + id + '/responses');
      var row = function (p) {
        return '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #F1F5F9;">'
          + '<span><strong style="color:#0F172A;">' + esc(p.name) + '</strong>' + (p.family ? '<br><span style="font-size:11.5px;color:#64748B;">' + esc(p.family) + '</span>' : '') + '</span>'
          + '<span style="text-align:right;font-size:12px;color:#64748B;white-space:nowrap;">' + (p.done ? (p.done_by ? 'by ' + esc(p.done_by) : esc(fmtDate(p.done_at))) : '') + '</span></div>';
      };
      var not = d.people.filter(function (p) { return !p.done; }), yes = d.people.filter(function (p) { return p.done; });
      body.innerHTML = '<div style="font-size:13.5px;color:#0F172A;margin-bottom:10px;"><strong>' + esc(d.title) + '</strong>'
        + (d.due_at ? ' · due ' + esc(fmtDate(d.due_at)) : '') + '<br><span style="color:#64748B;">' + d.done + ' of ' + d.total + ' done</span></div>'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#B45309;text-transform:uppercase;margin-top:6px;">Not done yet (' + not.length + ')</div>'
        + (not.length ? not.map(row).join('') : '<div style="padding:6px 0;color:#64748B;">Everyone has done it. 🎉</div>')
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#15803D;text-transform:uppercase;margin-top:14px;">Done (' + yes.length + ')</div>'
        + (yes.length ? yes.map(row).join('') : '<div style="padding:6px 0;color:#64748B;">Nobody yet.</div>');
    } catch (e) {
      body.innerHTML = '<div style="color:#B91C1C;">' + esc(e.message || 'Could not load.') + '</div>';
    }
  }
  if (!window.__ktAnnAskWired) {
    window.__ktAnnAskWired = true;
    document.addEventListener('click', function (e) {
      var d = e.target && e.target.closest && e.target.closest('.kt-ann-done');
      if (d) { e.preventDefault(); e.stopPropagation(); toggleDone(d); return; }
      var w = e.target && e.target.closest && e.target.closest('.kt-ann-who');
      if (w) { e.preventDefault(); e.stopPropagation(); showWhoDone(w.getAttribute('data-id')); }
    }, true);
  }

  function setState(keys, action) { return api('POST', '/provider/announcements/state', { keys, action }); }

  function annRow(a, i) {
    var scope = esc(scopeText(a));
    var chips = [a.send_email ? '📧' : '', a.send_push ? '🔔' : '', a.send_voice ? '📞' : '', a.pdf_url ? '📄' : '', (a.scheduled_at && !a.sent_at) ? '⏱' : '', (a.due_at || (a.reminders && a.reminders.length)) ? '⏰' : ''].filter(Boolean).join(' ');
    return `
      <tr class="kt-ann-row" data-idx="${i}" style="border-top:1px solid #F1F3F5;cursor:pointer;">
        <td style="padding:11px 14px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${a.personal ? justForYouChip() : '📍 ' + scope}</td>
        <td style="padding:11px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          ${a.read === false ? '<span data-ann-dot="1" aria-label="New" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0E9FB4;margin-right:7px;vertical-align:1px;"></span>' : ''}<span data-ann-title="1" style="font-weight:${a.read === false ? 800 : 600};color:#111827;">${esc(a.title)}</span>
          <span style="color:#6B7280;"> — ${esc(a.personal ? personalSummary(a.body, a.title) : plainPreview(a.body))}</span>
        </td>
        <td style="padding:11px 14px;color:#6B7280;white-space:nowrap;">${chips || '—'}</td>
        <td style="padding:11px 14px;text-align:right;color:#64748B;white-space:nowrap;">${esc(fmtDateTime(a.sent_at || a.created_at))}</td>
        <td style="padding:6px 10px;text-align:center;"><button type="button" class="kt-ka-kebab" data-ann-menu="${i}" aria-haspopup="true" aria-label="Actions" data-kttip="Actions">⋮</button></td>
      </tr>
      <tr class="kt-ann-detail" data-idx="${i}" style="display:none;background:#FAFCFD;">
        <td colspan="5" style="padding:4px 18px 18px;">
          <div style="font-size:12px;color:#64748B;margin:0 0 8px;">${a.personal ? '🔒 Just for you' : '— ' + esc(a.sender) + ' · ' + esc(scopeText(a))}${a.scheduled_at && !a.sent_at ? ' · ⏱ scheduled' : ''}</div>
          <div style="font-size:14px;color:#374151;line-height:1.6;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
          ${a.personal ? personalActions(a) : ''}
          ${pdfLink(a)}
          ${askBlock(a, true)}
        </td>
      </tr>`;
  }

  function annCard(a) {
    return `
      <div style="background:white;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;">
          <div style="font-weight:700;font-size:16px;color:#111827;">${esc(a.title)}</div>
          <div style="font-size:12px;color:#64748B;flex-shrink:0;">${esc(fmtDate(a.sent_at || a.created_at))}</div>
        </div>
        <div style="font-size:14px;color:#6B7280;display:flex;gap:10px;margin-bottom:8px;">
          <span>📍 ${esc(scopeText(a))}</span>
          ${a.send_email ? '<span>📧 email</span>' : ''}
          ${a.send_push ? '<span>🔔 push</span>' : ''}
          ${a.send_voice ? '<span>📞 voice</span>' : ''}
          ${a.scheduled_at && !a.sent_at ? '<span style="color:#F59E0B;">⏱ scheduled</span>' : ''}
        </div>
        <div style="font-size:14px;color:#374151;line-height:1.5;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
        ${pdfLink(a)}
        <div style="font-size:12px;color:#64748B;margin-top:8px;">— ${esc(a.sender)}</div>
      </div>
    `;
  }

  async function openComposer(container) {
    // Load centres so the user can pick scope
    let centres = [];
    try { const r = await api('GET', '/admin/centres'); centres = r.centres || []; } catch (e) {}

    /* Agency-wide is only offered to those the API will actually accept it from:
       AnnouncementController::canBroadcast() allows scope_type 'agency' for an
       agency_admin (or a platform_admin, anywhere). A centre_director sending
       agency-wide would just be handed a 403, so they keep the centre list. */
    var _annAgencyId = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id');
    var _annRole = getRole();
    var canSendAgencyWide = !!_annAgencyId && (_annRole === 'agency_admin' || _annRole === 'platform_admin');
    // A phone call is a director/admin decision, as on SMS broadcast (2026-09-29).
    var canCall = ['agency_admin', 'centre_director', 'platform_admin'].indexOf(_annRole) !== -1;

    // If no centres came back, the account can't broadcast (wrong role, or
    // active-agency not set). Surface it instead of showing an empty dropdown.
    const noCentres = centres.length === 0;
    /* `container` may be DETACHED by the time we get here.

       This function awaits /admin/centres before it touches the DOM, and the shell's
       refreshers tear down #appMain while that fetch is still in flight -- so the node
       this was called with is no longer in the document and $('#kt-mount', container)
       returns null. That is the crash educators hit on "+ New alert":
       "Cannot set properties of null (setting 'innerHTML')".

       KT.uiBusy() cannot help here -- it answers "is a dialog open?", and during the
       await there is no dialog yet. So re-resolve against the LIVE document, and if the
       screen has moved on since the click, give up quietly rather than throw. */
    if (! document.getElementById('kt-ann-new') && ! document.getElementById('kt-new-ann')) { return; }

    /* Drawn on <body>, not inside the screen (2026-10-20). Inside #appMain on a phone the
       slide-in animation left a transform on the mount, which made it the containing block
       for this position:fixed overlay: "+ New alert" opened at the foot of the list, cut off.
       kt-mobile-app.css also strips max-height + scrolling from inline scrollers in #appMain.
       Every lookup below is scoped to `mount`, so it works outside the screen (CONVENTIONS.md). */
    let mount = document.getElementById('kt-ann-composer');
    if (! mount) {
      mount = document.createElement('div');
      mount.id = 'kt-ann-composer';
      document.body.appendChild(mount);
    }

    mount.innerHTML = `
      <div class="kt-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;padding:max(16px,var(--kt-safe-top, env(safe-area-inset-top))) 16px max(16px,var(--kt-safe-bottom, env(safe-area-inset-bottom)));box-sizing:border-box;">
        <div data-kt-scroll style="background:white;border-radius:14px;max-width:560px;width:100%;padding:24px;box-sizing:border-box;max-height:90vh;max-height:calc(100dvh - 32px);overflow-y:auto;-webkit-overflow-scrolling:touch;">
          <h2 style="font-size:20px;margin:0 0 4px;">New Announcement</h2>
          <p style="color:#6B7280;font-size:13px;margin:0 0 18px;">Choose where it goes and who receives it.</p>
          <form id="kt-ann-form" onsubmit="return false;" style="display:grid;gap:12px;">
            <div>
              <!-- "Where" and "Who" below were BOTH labelled "Send to", which is why
                   picking "All educators" appeared to be blocked: the thing refusing to
                   submit was a different question wearing the same name. -->
              <label style="font-size:13px;font-weight:600;">Where</label>
              <select name="scope_id" style="${inp()}">
                ${canSendAgencyWide
                  ? `<option value="agency:${_annAgencyId}" selected>🏢 Everywhere — all centres</option>`
                  : `<option value="">— Pick a centre —</option>`}
                ${centres.map(c => `<option value="centre:${c.id}"${(!canSendAgencyWide && centres.length === 1) ? ' selected' : ''}>📍 Centre: ${esc(c.name)}</option>`).join('')}
              </select>
              ${noCentres
                ? `<div style="font-size:12px;color:#B91C1C;margin-top:4px;">${_annRole === 'educator'
                    /* An educator CAN send to their own centre (2026-11-27): the old text said only directors and
                       admins could, which was wrong for an educator who simply is not placed at a centre yet. */
                    ? '⚠ You are not placed at a centre yet, so there is no one to send to. Ask your director to add you to your centre.'
                    : '⚠ No centres available for this account. Check that a centre is set up and that you are signed in to the right agency.'}</div>`
                : ''}
            </div>

            <div>
              <label style="font-size:12px;font-weight:700;color:#374151;">Who</label>
              <select name="audience" style="${inp()}">
                <option value="parents" selected>All parents</option>
                <option value="educators">All educators</option>
                <option value="contractors">All contractors</option>
                <option value="admins">All admins and directors</option>
                <option value="all">Everyone</option>
              </select>
              <div id="kt-aud-hint" style="font-size:11px;color:#64748B;margin-top:2px;"></div>
            </div>

            <div>
              <label style="font-size:12px;font-weight:700;color:#374151;">Picture (optional)</label>
              <input type="file" name="image" accept="image/jpeg,image/png,image/gif"
                style="${inp()};padding:7px;font-size:13px;background:#F9FAFB;">
              <div style="font-size:11px;color:#64748B;margin-top:2px;">
                JPEG, PNG or GIF. Large photos are resized automatically so carriers will
                carry them &mdash; where a phone cannot take a picture message, the text
                arrives with a link to it instead.</div>
              <div id="kt-img-note" style="font-size:11.5px;margin-top:5px;"></div>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:#374151;">PDF (optional)</label>
              <input type="file" name="pdf" accept="application/pdf,.pdf"
                style="${inp()};padding:7px;font-size:13px;background:#F9FAFB;">
              <div style="font-size:11px;color:#64748B;margin-top:2px;">
                A newsletter, menu, form or policy, up to 10 MB. People open it from the
                announcement in the portal, from a button in the email, or from a link in the text.</div>
              <div id="kt-pdf-note" style="font-size:11.5px;margin-top:5px;"></div>
            </div>
            <input name="title" required placeholder="Title *" maxlength="200" style="${inp()}">
            <div>
              <div id="kt-rte-tb" style="display:flex;gap:3px;border:1px solid #D1D5DB;border-bottom:none;border-radius:8px 8px 0 0;padding:6px;background:#F9FAFB;flex-wrap:wrap;">
                <button type="button" data-cmd="bold"   style="${rteBtn()};font-weight:800;">B</button>
                <button type="button" data-cmd="italic" style="${rteBtn()};font-style:italic;">I</button>
                <button type="button" data-cmd="underline" style="${rteBtn()};text-decoration:underline;">U</button>
                <button type="button" data-cmd="insertUnorderedList" style="${rteBtn()};">• List</button>
                <button type="button" data-cmd="insertOrderedList" style="${rteBtn()};">1. List</button>
                <button type="button" data-cmd="createLink" style="${rteBtn()};">🔗 Link</button>
              </div>
              <div id="kt-rte" contenteditable="true" data-ph="Body" style="border:1px solid #D1D5DB;border-radius:0 0 8px 8px;min-height:120px;max-height:280px;overflow:auto;padding:10px 12px;font-size:14px;line-height:1.5;color:#111827;outline:none;background:white;"></div>
            </div>
            <div style="display:flex;gap:16px;font-size:14px;flex-wrap:wrap;">
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_email" checked> 📧 Send email</label>
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_sms"> 📱 Send SMS</label>
              <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_push" checked> 🔔 In-app notification</label>
              ${canCall ? `<label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" name="send_voice"> 📞 Voice call</label>` : ''}
            </div>
            ${canCall ? `<div id="kt-voice-opts" style="display:none;border:1px solid #FCD34D;background:#FFFBEB;border-radius:10px;padding:10px 12px;">
              <label style="font-size:12px;font-weight:700;color:#374151;">Reason for the call</label>
              <select name="voice_category" style="${inp()};margin-top:4px;"><option value="">Loading…</option></select>
              <div id="kt-voice-note" style="font-size:11.5px;color:#92400E;margin-top:6px;line-height:1.45;">
                Rings each person's phone and reads the title and message aloud, up to 800 characters.
                People who turned calls off, or said "don't phone me", are skipped; a
                non-emergency only reaches people who agreed to be contacted.</div>
            </div>` : ''}
            <details style="font-size:13px;">
              <summary style="cursor:pointer;color:#6B7280;">Schedule for later (optional)</summary>
              <input name="scheduled_at" type="datetime-local" style="${inp()};margin-top:8px;">
              <div style="font-size:11px;color:#64748B;margin-top:4px;">In your agency's time zone. It goes out within a minute of this time, by every channel ticked above.</div>
            </details>
            <details id="kt-ask-box" style="font-size:13px;">
              <summary style="cursor:pointer;color:#6B7280;">⏰ Asking for something? Due date &amp; reminders (optional)</summary>
              <div style="display:grid;gap:10px;margin-top:8px;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;background:#F8FAFC;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                  <label style="font-size:12px;font-weight:700;color:#374151;flex:1;min-width:140px;">Due by
                    <input name="due_date" type="date" style="${inp()};margin-top:4px;font-weight:400;"></label>
                  <label style="font-size:12px;font-weight:700;color:#374151;width:120px;">Time
                    <input name="due_time" type="time" value="17:00" style="${inp()};margin-top:4px;font-weight:400;"></label>
                </div>
                <div>
                  <div style="font-size:12px;font-weight:700;color:#374151;">Remind them <span style="font-weight:400;color:#64748B;">(up to 2)</span></div>
                  <div id="kt-rem-chips" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:5px;">
                    <button type="button" data-rem="d3" class="kt-rem-chip">3 days before</button>
                    <button type="button" data-rem="d1" class="kt-rem-chip">1 day before</button>
                    <button type="button" data-rem="d0" class="kt-rem-chip">Morning of</button>
                    <button type="button" data-rem="custom" class="kt-rem-chip">Custom…</button>
                  </div>
                  <input name="rem_custom" type="datetime-local" style="${inp()};margin-top:6px;display:none;">
                </div>
                <div style="display:grid;gap:4px;">
                  <div style="font-size:12px;font-weight:700;color:#374151;">Who gets the reminder</div>
                  <label style="display:flex;gap:7px;align-items:flex-start;"><input type="radio" name="remind_who" value="pending" checked style="margin-top:3px;">
                    <span>Only people who haven’t tapped <strong>✓ Done</strong><br><span style="font-size:11px;color:#64748B;">They get a Done button on the announcement; one tap covers the whole family. You can see who’s done.</span></span></label>
                  <label style="display:flex;gap:7px;align-items:flex-start;"><input type="radio" name="remind_who" value="all" style="margin-top:3px;">
                    <span>Everyone again</span></label>
                </div>
                <div id="kt-rem-note" style="font-size:11.5px;color:#64748B;"></div>
              </div>
            </details>
            <div id="kt-status" style="min-height:20px;font-size:14px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
              <button type="button" id="kt-cancel" style="background:#F3F4F6;color:#374151;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
              <button type="button" id="kt-send" style="background:#1F6080;color:white;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">Send</button>
            </div>
          </form>
        </div>
      </div>
    `;
    const overlay = $('.kt-modal-overlay', mount);
    const close = () => { window.removeEventListener('hashchange', close); mount.remove(); };
    window.addEventListener('hashchange', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    $('#kt-cancel', mount).addEventListener('click', close);

    // Rich-text editor wiring
    const rte = $('#kt-rte', mount);
    $('#kt-rte-tb', mount).querySelectorAll('button[data-cmd]').forEach((b) => {
      b.addEventListener('mousedown', (e) => e.preventDefault()); // keep selection/focus
      b.addEventListener('click', async () => {
        const cmd = b.getAttribute('data-cmd');
        rte.focus();
        if (cmd === 'createLink') {
          // A KiddieTrac dialog takes focus: keep the selection and put it back before linking.
          let sel = window.getSelection();
          const range = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
          const url = await KT.prompt({ title: 'Insert a link', description: 'The selected text becomes a link to this address.', placeholder: 'https://…', type: 'url', okLabel: 'Insert link' });
          rte.focus();
          if (range) { sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
          if (url) document.execCommand('createLink', false, url);
        } else {
          document.execCommand(cmd, false, null);
        }
      });
    });

    const formEl = $('#kt-ann-form', mount);

    /* Voice call (2026-09-29): only the reasons this agency allows are offered, and
       when calls are switched off for the agency the box says so instead of letting
       somebody send an announcement that rings nobody. */
    const voiceBox = formEl.querySelector('[name="send_voice"]');
    let voiceLoaded = null;
    function loadVoiceReasons() {
      if (voiceLoaded) return voiceLoaded;
      const sel = formEl.querySelector('[name="voice_category"]');
      const note = formEl.querySelector('#kt-voice-note');
      voiceLoaded = api('GET', '/admin/voice/categories').then((res) => {
        const on = (res.data || []).filter((c) => c.enabled);
        sel.innerHTML = '<option value="">— Choose a reason —</option>'
          + on.map((c) => `<option value="${esc(c.key)}">${esc(c.label)}${c.urgent ? '' : ' (agreed contacts only)'}</option>`).join('');
        if (!res.voice_on || !on.length) {
          voiceBox.checked = false; voiceBox.disabled = true;
          formEl.querySelector('#kt-voice-opts').style.display = 'block';
          sel.disabled = true;
          note.textContent = !res.voice_on
            ? 'Voice calls are switched off for this agency. An agency admin turns them on in Settings → Carrier settings → Voice calls.'
            : 'No call reasons are allowed for this agency. An agency admin chooses them in Settings → Carrier settings → Voice calls.';
        }
      }).catch(() => {
        voiceBox.checked = false; voiceBox.disabled = true;
        sel.innerHTML = '<option value="">Not available</option>'; sel.disabled = true;
        note.textContent = 'Voice calls are not available to your account here.';
      });
      return voiceLoaded;
    }
    if (voiceBox) {
      voiceBox.addEventListener('change', () => {
        formEl.querySelector('#kt-voice-opts').style.display = voiceBox.checked ? 'block' : 'none';
        if (voiceBox.checked) loadVoiceReasons();
      });
    }
    async function doSend() {
      const f = formEl;
      const statusEl = $('#kt-status', mount);
      const sendBtn = $('#kt-send', mount);
      /* Still refuses to send an announcement with no destination — it just no longer
         insists on a CENTRE when the user has already said "everywhere". Normally
         preselected, so this only fires for a director with several centres who has
         not picked one. */
      const scopeRaw = f.querySelector('[name="scope_id"]').value;
      if (!scopeRaw) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Pick where this goes — a centre, or everywhere';
        return;
      }
      const [scope_type, scope_id] = scopeRaw.split(':');
      const titleVal = (f.querySelector('[name="title"]').value || '').trim();
      if (!titleVal) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Title is required';
        return;
      }
      const rteEl = f.querySelector('#kt-rte');
      const bodyHtml = (rteEl.innerHTML || '').trim();
      if (!bodyHtml || !rteEl.textContent.trim()) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Body is required';
        return;
      }
      const audienceVal = (f.querySelector('[name="audience"]') || {}).value || 'parents';
      const imgEl = f.querySelector('[name="image"]');
      const imgFile = imgEl && imgEl.files && imgEl.files[0];

      if (imgFile && imgFile.size > 5 * 1024 * 1024) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '\u2717 That picture is over the 5 MB limit';
        return;
      }
      const pdfEl = f.querySelector('[name="pdf"]');
      const pdfFile = pdfEl && pdfEl.files && pdfEl.files[0];
      if (pdfFile && (pdfFile.size > 10 * 1024 * 1024 || !/\.pdf$/i.test(pdfFile.name))) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '\u2717 Choose a PDF file of 10 MB or less';
        return;
      }

      const sched = f.querySelector('[name="scheduled_at"]').value;
      const ask = f.__ktAsk ? f.__ktAsk() : { due_at: '', reminders: [], remind_who: 'pending' };
      if (ask.error) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ ' + ask.error;
        return;
      }
      const asking = !!(ask.due_at || ask.reminders.length);

      const voiceOn = !!(f.querySelector('[name="send_voice"]') || {}).checked;
      const voiceCat = voiceOn ? (f.querySelector('[name="voice_category"]') || {}).value || '' : '';
      if (voiceOn && !voiceCat) {
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ Choose the reason for the phone call';
        return;
      }
      // A call is neither silent nor undoable, so it asks first — as SMS broadcast does.
      if (voiceOn && window.KT && KT.confirm) {
        const reasonTxt = (f.querySelector('[name="voice_category"]').selectedOptions[0] || {}).textContent || voiceCat;
        const ok = await KT.confirm({
          title: 'Place phone calls?',
          description: 'This rings the phones of the people this announcement goes to and reads it aloud (reason: '
            + reasonTxt + ')' + (sched ? ', at the scheduled time' : '') + '. Calls cannot be taken back once placed.',
          okLabel: 'Place calls', tone: 'default',
        });
        if (!ok) return;
      }

      // A file forces multipart; without one, keep the JSON path exactly as it was.
      let data;
      if (imgFile || pdfFile) {
        data = new FormData();
        data.append('scope_type', scope_type);
        data.append('scope_id', String(parseInt(scope_id, 10)));
        data.append('audience', audienceVal);
        data.append('title', titleVal);
        data.append('body', bodyHtml);
        // Booleans over multipart have to be '1'/'0' — PHP reads "false" as truthy.
        data.append('send_email', f.querySelector('[name="send_email"]').checked ? '1' : '0');
        data.append('send_sms', f.querySelector('[name="send_sms"]').checked ? '1' : '0');
        data.append('send_push', f.querySelector('[name="send_push"]').checked ? '1' : '0');
        data.append('send_voice', voiceOn ? '1' : '0');
        if (voiceOn) data.append('voice_category', voiceCat);
        if (sched) data.append('scheduled_at', sched);
        if (asking) {
          if (ask.due_at) data.append('due_at', ask.due_at);
          ask.reminders.forEach(function (t) { data.append('reminders[]', t); });
          data.append('remind_who', ask.remind_who);
        }
        if (imgFile) data.append('image', imgFile, imgFile.name);
        if (pdfFile) data.append('pdf', pdfFile, pdfFile.name);
      } else {
        data = {
          scope_type,
          scope_id: parseInt(scope_id, 10),
          audience: audienceVal,
          title: titleVal,
          body: bodyHtml,
          send_email: f.querySelector('[name="send_email"]').checked,
          send_sms: f.querySelector('[name="send_sms"]').checked,
          send_push: f.querySelector('[name="send_push"]').checked,
          send_voice: voiceOn,
        };
        if (voiceOn) data.voice_category = voiceCat;
        if (sched) data.scheduled_at = sched;
        if (asking) {
          if (ask.due_at) data.due_at = ask.due_at;
          data.reminders = ask.reminders;
          data.remind_who = ask.remind_who;
        }
      }

      const orig = sendBtn ? sendBtn.textContent : 'Send';
      if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Sending…'; }
      statusEl.style.color = '#6B7280';
      statusEl.textContent = 'Sending…';
      try {
        const res = await api('POST', '/provider/announcements', data);
        statusEl.style.color = '#16A34A';
        statusEl.textContent = res.scheduled ? '✓ Scheduled' : '✓ Sent to ' + (res.delivered_to || 0) + ' recipient(s)'
          + (res.voice ? ' · 📞 calling ' + res.voice.calling + ', skipped ' + res.voice.skipped : '')
          + (res.reminders ? ' · ⏰ ' + res.reminders + ' reminder' + (res.reminders === 1 ? '' : 's') + ' set' : '');
        setTimeout(() => { close(); renderProvider(container.isConnected ? container : (document.getElementById('appMain') || container)); }, 1200);
      } catch (err) {
        if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = orig; }
        statusEl.style.color = '#DC2626';
        statusEl.textContent = '✗ ' + err.message;
      }
    }
    // Bind the send action to the button CLICK directly. Relying on implicit
    // form submission (type=submit) was silently dropped inside the app shell —
    // clicking Send fired no submit event at all — so "Send" did nothing with no
    // error. The form 'submit' handler stays as an Enter-key fallback.
    /* One sentence covering both controls. Read separately, "All centres" and
       "All educators" leave you working out the intersection yourself. */
    function refreshAudienceHint() {
      var audSel = formEl.querySelector('[name="audience"]');
      var scopeSel = formEl.querySelector('[name="scope_id"]');
      var hint = formEl.querySelector('#kt-aud-hint');
      if (!audSel || !hint) return;
      var scopeTxt = scopeSel && scopeSel.selectedIndex >= 0
        ? (scopeSel.options[scopeSel.selectedIndex].textContent || '').trim() : '';
      var whereBit = /all centre/i.test(scopeTxt) ? 'across the whole agency' : ('at ' + scopeTxt);
      var who = {
        parents: 'Every parent with a child',
        educators: 'Every educator',
        contractors: 'Everyone marked as a contractor',
        admins: 'Every admin and director',
        all: 'Everyone \u2014 parents, educators, contractors, admins',
      }[audSel.value] || '';
      hint.textContent = scopeTxt ? who + ' ' + whereBit + '.' : who + '.';
    }
    formEl.querySelector('[name="audience"]').addEventListener('change', refreshAudienceHint);
    var scopeSelEl = formEl.querySelector('[name="scope_id"]');
    if (scopeSelEl) scopeSelEl.addEventListener('change', refreshAudienceHint);
    refreshAudienceHint();

    /* Say the size up front. Being told after you press Send that the photo was too
       big is the worst moment to find out. */
    var imgInput = formEl.querySelector('[name="image"]');
    if (imgInput) {
      imgInput.addEventListener('change', function () {
        var note = formEl.querySelector('#kt-img-note');
        var f2 = imgInput.files && imgInput.files[0];
        if (!f2) { note.textContent = ''; return; }
        var kb = Math.round(f2.size / 1024);
        if (f2.size > 5 * 1024 * 1024) {
          note.style.color = '#B91C1C';
          note.textContent = '\u2717 ' + f2.name + ' is ' + Math.round(kb / 1024 * 10) / 10
            + ' MB \u2014 the limit is 5 MB.';
          return;
        }
        note.style.color = '#475569';
        note.textContent = '\ud83d\udcce ' + f2.name + ' (' + kb + ' KB)'
          + (kb > 480 ? ' \u2014 will be resized for sending.' : '');
      });
    }

    var pdfInput = formEl.querySelector('[name="pdf"]');
    if (pdfInput) {
      pdfInput.addEventListener('change', function () {
        var note = formEl.querySelector('#kt-pdf-note');
        var f3 = pdfInput.files && pdfInput.files[0];
        if (!f3) { note.textContent = ''; return; }
        var mb = Math.round(f3.size / 1024 / 1024 * 10) / 10;
        if (f3.size > 10 * 1024 * 1024) { note.style.color = '#B91C1C'; note.textContent = '\u2717 ' + f3.name + ' is ' + mb + ' MB \u2014 the limit is 10 MB.'; return; }
        note.style.color = '#475569';
        note.textContent = '\ud83d\udcc4 ' + f3.name + ' (' + (mb >= 1 ? mb + ' MB' : Math.round(f3.size / 1024) + ' KB') + ')';
      });
    }

    /* Reminder presets work from the due date, in the agency's own wall-clock time, so
       the arithmetic is on the date alone: "1 day before" is 9 AM the day before, "Morning
       of" is 8 AM on the day. Custom is any date and time. Two at most. */
    const remPicked = [];
    const remChips = formEl.querySelectorAll('.kt-rem-chip');
    const remCustom = formEl.querySelector('[name="rem_custom"]');
    function shiftDate(ymd, days) {
      const p = ymd.split('-').map(Number);
      return new Date(Date.UTC(p[0], p[1] - 1, p[2] + days)).toISOString().slice(0, 10);
    }
    function remTimes() {
      const due = formEl.querySelector('[name="due_date"]').value;
      return remPicked.map(function (k) {
        if (k === 'custom') return (remCustom.value || '').slice(0, 16);
        if (!due) return '';
        return k === 'd3' ? shiftDate(due, -3) + 'T09:00' : k === 'd1' ? shiftDate(due, -1) + 'T09:00' : due + 'T08:00';
      });
    }
    // Shown exactly as typed (agency wall-clock). Read through the device's clock it
    // came out hours off for anyone not in the agency's zone.
    function remLabel(t) {
      const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(t || '');
      if (!m) return t;
      const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12));
      const day = d.toLocaleDateString('en-CA', { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric' });
      const h = +m[4];
      return day + ', ' + ((h % 12) || 12) + ':' + m[5] + (h < 12 ? ' AM' : ' PM');
    }
    function paintRem() {
      const due = formEl.querySelector('[name="due_date"]').value;
      remChips.forEach(function (c) {
        const k = c.getAttribute('data-rem');
        const on = remPicked.indexOf(k) !== -1;
        const needsDue = k !== 'custom' && !due;
        c.style.cssText = 'border-radius:999px;padding:5px 11px;font-size:12.5px;font-weight:700;cursor:pointer;'
          + (on ? 'background:#159FB4;color:#fff;border:1px solid #159FB4;' : 'background:#fff;color:#334155;border:1px solid #CBD5E1;')
          + (needsDue && !on ? 'opacity:.45;' : '');
        c.title = needsDue ? 'Pick a due date first' : '';
      });
      remCustom.style.display = remPicked.indexOf('custom') !== -1 ? 'block' : 'none';
      const times = remTimes().filter(Boolean);
      const note = formEl.querySelector('#kt-rem-note');
      note.textContent = times.length
        ? 'Reminders: ' + times.map(remLabel).join(' · ')
        : (due ? 'No reminders — the due date shows on the announcement.' : '');
    }
    remChips.forEach(function (c) {
      c.addEventListener('click', function () {
        const k = c.getAttribute('data-rem');
        const at = remPicked.indexOf(k);
        if (at !== -1) { remPicked.splice(at, 1); }
        else if (k !== 'custom' && !formEl.querySelector('[name="due_date"]').value) { formEl.querySelector('[name="due_date"]').focus(); }
        else if (remPicked.length >= 2) { if (window.KT && KT.toast) KT.toast('⏰', 'Two reminders at most', 'Untick one first.', '#B45309'); }
        else { remPicked.push(k); }
        paintRem();
      });
    });
    formEl.querySelector('[name="due_date"]').addEventListener('change', paintRem);
    remCustom.addEventListener('change', paintRem);
    paintRem();
    formEl.__ktAsk = function () {
      const due = formEl.querySelector('[name="due_date"]').value;
      const times = remTimes();
      if (remPicked.indexOf('custom') !== -1 && !(remCustom.value || '')) return { error: 'Pick the date and time for the custom reminder' };
      if (times.some(function (t) { return !t; })) return { error: 'Pick a due date for the reminders' };
      return {
        due_at: due ? due + 'T' + (formEl.querySelector('[name="due_time"]').value || '17:00') : '',
        reminders: times,
        remind_who: (formEl.querySelector('[name="remind_who"]:checked') || {}).value || 'pending',
      };
    };

    $('#kt-send', mount).addEventListener('click', (e) => { e.preventDefault(); doSend(); });
    formEl.addEventListener('submit', (e) => { e.preventDefault(); doSend(); });
  }

  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/parent/announcements'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }
    const items = data.announcements || [];
    const unread = items.filter(a => a.key && !a.read).length;

    container.innerHTML = `
      <div style="padding:18px 14px;max-width:900px;margin:0 auto;">
        <div class="kt-hero" style="background:linear-gradient(135deg,#1F6080,#0E7C90);margin-bottom:14px;">
          <h1>📰 News</h1>
          <div class="kt-hero-sub">${items.length ? items.length + ' update' + (items.length === 1 ? '' : 's') + ' from your centre' + (unread ? ' · ' + unread + ' new' : '') : 'Updates from your centre'}</div>
        </div>
        ${unread > 1 ? `<div style="display:flex;justify-content:flex-end;margin:-4px 0 10px;"><button type="button" data-ann-allread="1" data-kt-iconized="1" style="background:white;border:1px solid #CBD5E1;color:#1B3A63;border-radius:999px;padding:5px 13px;font-size:12.5px;font-weight:700;cursor:pointer;">✓ Mark all read</button></div>` : ''}
        ${items.length === 0
          ? `<div style="text-align:center;padding:40px 20px;background:white;border-radius:14px;color:#6B7280;">
              <div style="font-size:44px;margin-bottom:10px;">📭</div>
              <div style="font-weight:800;color:#0F172A;margin-bottom:4px;">No news yet</div>
              When your centre posts an update, it'll appear here.
            </div>`
          : `<div data-kt-list="1" data-kt-no-kebab="1" style="display:grid;gap:11px;">
              ${items.map(a => `
                <div data-ann-key="${esc(a.key || '')}" style="background:${a.read === false ? '#F5FCFD' : 'white'};border:1px solid ${a.read === false ? '#A5E3EC' : '#E7EDF3'};--kt-edge:#159FB4;border-radius:13px;padding:14px 16px;box-shadow:0 1px 4px rgba(15,23,42,.05);">
                  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:5px;">
                    <div style="font-weight:800;font-size:15.5px;color:#0F172A;line-height:1.3;min-width:0;">${a.read === false ? '<span data-ann-dot="1" aria-label="New" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0E9FB4;margin-right:7px;vertical-align:2px;"></span>' : ''}${esc(a.title)}</div>
                    <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
                      <div style="font-size:11.5px;color:#64748B;white-space:nowrap;">${esc(fmtDate(a.sent_at))}</div>
                      ${a.key ? `<button type="button" class="kt-ka-kebab" data-ann-pmenu="${esc(a.key)}" aria-haspopup="true" aria-label="Actions" data-kttip="Actions">⋮</button>` : ''}
                    </div>
                  </div>
                  ${a.due_at ? `<div style="display:inline-block;background:#FFFBEB;border:1px solid #FCD34D;color:#92400E;border-radius:8px;padding:4px 10px;font-size:13px;font-weight:700;margin:0 0 8px;">⏰ Please do this by ${esc(fmtDate(a.due_at))}</div>` : ''}
                  ${a.personal ? `<div style="margin:0 0 6px;">${justForYouChip()}</div>` : ''}
                  <div style="font-size:14px;color:#374151;line-height:1.55;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
                  ${pdfLink(a)}
                  ${a.personal ? personalActions(a) : ''}
                  ${a.track_done ? `<div style="margin-top:10px;">${doneBtn(a)}</div>` : ''}
                </div>
              `).join('')}
            </div>`
        }
      </div>
    `;
    bindParentMenu(container, items);
  }

  /* ⋮ ON EACH ITEM IN A PARENT'S NEWS (2026-11-07, Anthony: "no kebab to delete or mark read here"). Mark read /
     unread, and Delete - which removes it from THIS parent's News only (the centre's announcement and every other
     family's copy stay). New items show a teal dot until read; reading one (a tap on it) marks it read too.
     State is the parent's own: POST /parent/announcements/state. */
  function bindParentMenu(container, items) {
    const byKey = {};
    items.forEach(a => { if (a.key) byKey[a.key] = a; });
    const setState = (keys, action) => api('POST', '/parent/announcements/state', { keys, action });
    const card = (key) => container.querySelector('[data-ann-key="' + key + '"]');
    function paintRead(key, read) {
      const a = byKey[key], c = card(key);
      if (!a || !c) return;
      a.read = read;
      c.style.background = read ? 'white' : '#F5FCFD';
      c.style.borderColor = read ? '#E7EDF3' : '#A5E3EC';
      const dot = c.querySelector('[data-ann-dot]');
      if (read && dot) dot.remove();
      if (!read && !dot) {
        const t = c.querySelector(':scope > div > div');
        const d = document.createElement('span');
        d.setAttribute('data-ann-dot', '1'); d.setAttribute('aria-label', 'New');
        d.style.cssText = 'display:inline-block;width:8px;height:8px;border-radius:50%;background:#0E9FB4;margin-right:7px;vertical-align:2px;';
        if (t) t.insertBefore(d, t.firstChild);
      }
      recount();
    }
    function recount() {
      const n = items.filter(x => x.key && !x.read).length;
      const sub = container.querySelector('.kt-hero-sub');
      if (sub) sub.textContent = sub.textContent.replace(/ · \d+ new$/, '') + (n ? ' · ' + n + ' new' : '');
      const all = container.querySelector('[data-ann-allread]');
      if (all && n < 2) all.parentNode.remove();
    }
    function markRead(key, read) {
      paintRead(key, read);
      setState([key], read ? 'read' : 'unread').catch(() => {
        paintRead(key, !read);
        if (window.KT && KT.toast) KT.toast('⚠️', 'Could not save', 'Try again.', '#B91C1C');
      });
    }
    container.addEventListener('click', (e) => {
      const t = e.target;
      const all = t.closest && t.closest('[data-ann-allread]');
      if (all) {
        const keys = items.filter(a => a.key && !a.read).map(a => a.key);
        keys.forEach(k => paintRead(k, true));
        setState(keys, 'read').catch(() => { keys.forEach(k => paintRead(k, false)); });
        return;
      }
      const k = t.closest && t.closest('[data-ann-pmenu]');
      if (!k) {
        // Reading it is a tap on the card: a new item becomes read (links and buttons inside keep their own job).
        const c = t.closest && t.closest('[data-ann-key]');
        if (c && !(t.closest('a,button'))) { const key = c.getAttribute('data-ann-key'); if (byKey[key] && byKey[key].read === false) markRead(key, true); }
        return;
      }
      e.preventDefault(); e.stopPropagation();
      const key = k.getAttribute('data-ann-pmenu');
      const a = byKey[key];
      if (!a) return;
      const btn = (label, fn) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', fn); return b; };
      const acts = [];
      // Older than 30 days counts as read on the server, so "unread" is only offered where it can stick.
      const recent = a.sent_at && (Date.now() - new Date(a.sent_at).getTime()) < 29 * 864e5;
      if (!a.read) acts.push(btn('✓ Mark read', () => markRead(key, true)));
      else if (recent) acts.push(btn('● Mark unread', () => markRead(key, false)));
      acts.push(btn('🗑 Delete', () => {
        ktConfirmThen('Remove this from your News? It only leaves your list: the centre and other families keep it.', async () => {
          try {
            await setState([key], 'hide');
            const c = card(key);
            if (c) c.remove();
            const at = items.indexOf(a);
            if (at !== -1) items.splice(at, 1);
            delete byKey[key];
            recount();
            if (window.KT && KT.toast) KT.toast('🗑', 'Removed', 'Taken out of your News.', '#0F172A');
          } catch (err) {
            if (window.KT && KT.toast) KT.toast('⚠️', 'Could not remove', err.message || 'Try again.', '#B91C1C');
          }
        });
      }));
      if (window.KT && KT.openActionMenu) KT.openActionMenu(k, acts);
    });
  }

  function inp() { return 'width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;'; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.kt-ann-act');
    if (!b || (b.closest && b.closest('.kt-ann-personal'))) return;   // the For-you block binds its own
    e.preventDefault();
    var inv = b.getAttribute('data-invoice');
    if (inv && window.KT && KT.openInvoiceById) { KT.openInvoiceById(inv); return; }
    var link = b.getAttribute('data-link') || '';
    if (link.charAt(0) === '#') location.hash = link;
  });
  function rteBtn() { return 'background:white;border:1px solid #D1D5DB;border-radius:6px;padding:4px 9px;font-size:13px;cursor:pointer;color:#374151;min-width:30px;'; }

  // Allow only a safe subset of HTML from the rich-text editor / stored body.
  function sanitizeHtml(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = String(html == null ? '' : html);
    tmp.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input').forEach(function (n) { n.remove(); });
    tmp.querySelectorAll('*').forEach(function (el) {
      Array.prototype.slice.call(el.attributes).forEach(function (at) {
        var n = at.name.toLowerCase();
        if (n.indexOf('on') === 0) el.removeAttribute(at.name);
        if ((n === 'href' || n === 'src') && /^\s*javascript:/i.test(at.value)) el.removeAttribute(at.name);
      });
    });
    tmp.querySelectorAll('a').forEach(function (a) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer'); });
    return tmp.innerHTML;
  }

  (function injectRteCss() {
    if (document.getElementById('kt-rte-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-rte-css';
    s.textContent = '#kt-rte:empty:before{content:attr(data-ph);color:#64748B;pointer-events:none;}'
      + '#kt-rte-tb button:hover{background:#EFF2F6;}#kt-rte a{color:#1F6080;}';
    document.head.appendChild(s);
  })();

  // Read-only announcements for the home visitor — they see their agency's
  // announcements but never the composer (they don't broadcast).
  async function renderReadOnly(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';
    let data;
    try { data = await api('GET', '/provider/announcements'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }
    const items = data.announcements || data.data || [];
    setTimeout(function () {
      var list = container.querySelector('[data-kt-list]');
      var h2 = container.querySelector('h2');
      mountPersonal(list ? list.parentNode : (h2 ? h2.parentNode : container), data.personal, h2, list);
    }, 0);
    const when = (a) => fmtDate(a.sent_at || a.created_at || a.scheduled_at || '');
    container.innerHTML = `
      <div style="padding:24px;max-width:900px;">
        <h2 style="font-size:24px;margin:0 0 16px;">📢 Announcements</h2>
        ${items.length === 0
          ? `<div style="text-align:center;padding:48px;background:white;border-radius:14px;color:#6B7280;"><div style="font-size:48px;margin-bottom:12px;">📭</div>Nothing here yet — announcements from your agency will appear here.</div>`
          : `<div data-kt-list="1" style="display:grid;gap:12px;">
              ${items.map(a => `
                <div style="background:white;border-radius:12px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                  <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:6px;">
                    <div style="font-weight:700;font-size:16px;">${esc(a.title)}</div>
                    <div style="font-size:12px;color:#64748B;white-space:nowrap;">${esc(when(a))}</div>
                  </div>
                  <div style="font-size:14px;color:#374151;line-height:1.5;" class="kt-ann-body">${sanitizeHtml(a.body)}</div>
                </div>`).join('')}
            </div>`}
      </div>`;
  }

  /* Opening Announcements marks announcement notifications read (2026-09-29).
     Anthony: "I have read the announcement but the indicator on the side panel for new
     announcements isn't going away". The only code that did this lived in
     nav-additions-v14's router, which returns at once now that the v17 shell owns
     routing -- so the count was painted every 30s and never cleared. Throttled because a
     screen must not write on every render (the billing reload loop); kt-live already
     ignores mark-read, so this cannot trigger a re-render either way. */
  let _annMarkedAt = 0;
  function markAnnouncementsRead() {
    if (Date.now() - _annMarkedAt < 5000) return;
    _annMarkedAt = Date.now();
    api('POST', '/notifications/mark-read', { type: 'announcement' })
      .catch(() => {})
      .then(() => { if (window.KT && KT.refreshAnnouncementBadge) KT.refreshAnnouncementBadge(); });
  }

  function render(container) {
    markAnnouncementsRead();
    const role = getRole();
    if (role === 'guardian') return renderParent(container);
    if (role === 'home_visitor') return renderProvider(container, { readOnly: true });
    renderProvider(container);
  }

  window.KT = window.KT || {};
  window.KT.Announcements = { render };
})(window);
