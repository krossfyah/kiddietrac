/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v15 — Branding (agency_admin + platform_admin via Reseller)
   Sub-tabs (2026-10-15): White-label & logo · Invoices · Contact & legal ·
   Email delivery · Payslips · Region & IDs. The last three are cards from
   screen-admin.js (KT.renderEmailDeliveryCard / renderPayslipCard /
   renderCountryCard / buildRefPrefixCard) and save themselves.
   ─────────────────────────────────────────────────────────────────
   Lets an agency admin (or platform admin) edit their brand fields
   on the agencies table:
   - brand_logo_url
   - brand_primary_color
   - brand_support_email
   - brand_bank_info
   - powered_by_visible toggle
   Live preview the invoice via /invoices/preview-sample?agency_id=X.

   Persistence: uses PATCH /admin/agencies/{id}/features
   (FeatureFlagController@update accepts brand_* fields).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function isPlatformAdmin() {
    const u = getUser();
    return !!(u.is_platform_admin || (Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1));
  }

  async function render(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading branding…</div>';
    const user = getUser();
    // v22p87: use the ACTIVE agency (the one being viewed via the agency
    // switcher) rather than the user's home agency. A platform_admin viewing a
    // tenant has no user.agency_id, which produced "Agency not found".
    let agencyId = user.agency_id;
    try {
      const active = parseInt(sessionStorage.getItem('kt_active_agency_id'), 10);
      if (active) agencyId = active;
    } catch (e) { /* sessionStorage unavailable */ }
    if (!agencyId) {
      container.innerHTML = `
        <div style="padding:48px;max-width:600px;margin:0 auto;text-align:center;">
          <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:20px;border-radius:8px;text-align:left;">
            <strong style="color:#92400E;">No agency on your account</strong>
            <div style="margin-top:6px;font-size:13px;color:#78350F;">Branding is configured per-agency. Sign in as an agency admin.</div>
          </div>
        </div>`;
      return;
    }

    let data;
    try { data = await api('GET', '/admin/agencies/' + agencyId + '/features'); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">' + esc(e.message) + '</div>'; return; }

    const brand = data.branding || {};
    const isWL = brand.powered_by_visible == 0;

    /* SUB-TABS (2026-10-15, Anthony: "make sure branding is subtabbed for each of the
       sections"). Every pane is in the DOM at once and only shown/hidden, so the one
       Save button still reads every field it always did, whichever pane is open. The
       open pane is in the hash (?tab=), so a link can land on e.g. Invoices. */
    const TABS = [
      ['whitelabel', '🏷️ White-label & logo'],
      ['invoices',   '🧾 Invoices'],
      ['contact',    '📇 Contact & legal'],
      ['email',      '✉️ Email delivery'],
      ['payslips',   '💵 Payslips'],
      ['region',     '🌎 Region & IDs'],
      /* The banner on every KiddieTrac email, by season — platform-wide, so super admins
         only (it was Settings › Seasonal email headers until 2026-10-15). */
    ].concat(isPlatformAdmin() ? [['seasons', '🍂 Seasonal email headers']] : []);
    const SAVES_HERE = { whitelabel: 1, invoices: 1, contact: 1 };   // the rest save in their own card
    let tab = (location.hash.match(/[?&]tab=([a-z]+)/) || [])[1] || 'whitelabel';
    if (!TABS.some(t => t[0] === tab)) tab = 'whitelabel';

    const card = 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);';
    container.innerHTML = `
      <div style="padding:24px;max-width:1800px;">
        <!-- No title here: the shell's banner already names the page (and repeating it was
             the "name twice" problem normaliseBanners exists for). -->

        <div class="kt-br-tabs" role="tablist" style="display:flex;gap:6px;overflow-x:auto;padding-bottom:2px;margin-bottom:16px;">
          ${TABS.map(t => `<button type="button" role="tab" data-tab="${t[0]}" class="kt-br-tab">${t[1]}</button>`).join('')}
        </div>

        <!-- ── White-label & logo ── -->
        <div data-pane="whitelabel" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start;" class="kt-br-grid">
          <div style="${card}">
            <h3 style="margin:0 0 12px;font-size:15px;">White-label licence</h3>
            ${isPlatformAdmin() ? `
            <div style="display:flex;align-items:center;gap:10px;padding:12px;background:#F9FAFB;border-radius:8px;margin-bottom:16px;">
              <input type="checkbox" id="kt-poweredby" ${isWL ? '' : 'checked'} style="width:18px;height:18px;cursor:pointer;">
              <label for="kt-poweredby" style="font-size:13px;color:#374151;cursor:pointer;flex:1;">
                Show <em>"Powered by Kiddietrac"</em> (white-label licence <strong>off</strong>)
                <div style="font-size:11px;color:#64748B;margin-top:2px;">Uncheck only for an agency on the white-label licence. Only a platform admin can change this.</div>
              </label>
            </div>` : `
            <div style="padding:12px;background:${isWL ? '#ECFDF5' : '#F9FAFB'};border-radius:8px;margin-bottom:16px;font-size:13px;color:#374151;">
              ${isWL
                ? '<strong>White-label licence: on.</strong> Your logo replaces KiddieTrac throughout, and marketing campaigns can be sent from your own mailbox (Marketing \u203A Send from).'
                : '<strong>White-label licence: off.</strong> Your logo appears on invoices and emails; the portal and app keep the KiddieTrac brand. Ask KiddieTrac about the white-label licence to show your own brand throughout.'}
            </div>`}

            <h3 style="margin:0 0 12px;font-size:15px;">Logo &amp; colour</h3>
            <!-- THE LOGO PICKER (2026-10-15). Anthony: "I only see the logos but no replace or
                 upload". The upload button was there, but kt-icon-buttons.js turns any button
                 whose label says "upload" into a bare 📤, so it read as decoration beside a URL
                 box. These buttons opt out (data-kt-iconized) and say what they do. -->
            <label style="display:block;font-size:12px;font-weight:700;color:#6B7280;margin-bottom:6px;">Logo</label>
            <div id="kt-logo-drop" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px;border:1.5px dashed #CBD5E1;border-radius:12px;background:#F8FAFC;margin-bottom:8px;">
              <div style="width:132px;height:72px;flex:none;border-radius:10px;background:#fff;border:1px solid #E2E8F0;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                <img id="kt-logo-thumb" alt="" style="max-width:118px;max-height:60px;object-fit:contain;display:none;">
                <span id="kt-logo-none" style="font-size:11px;color:#94A3B8;">No logo yet</span>
              </div>
              <div style="flex:1;min-width:170px;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <input type="file" id="kt-logo-file" accept="image/png,image/jpeg,image/webp" style="display:none;">
                  <button type="button" id="kt-logo-upload" data-kt-iconized="1" style="height:32px;padding:0 14px;background:#081C41;color:#fff;border:0;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Upload logo</button>
                  <button type="button" id="kt-logo-remove" data-kt-iconized="1" style="height:32px;padding:0 12px;background:#fff;color:#B91C1C;border:1px solid #FECACA;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Remove</button>
                </div>
                <div id="kt-logo-upmsg" style="font-size:12px;color:#64748B;margin-top:6px;">PNG, JPG or WebP, up to 2 MB. Drop a file here too. A wide logo on a transparent background works best.</div>
              </div>
            </div>
            <details style="margin:0 0 14px;">
              <summary style="font-size:12px;color:#1F6080;cursor:pointer;font-weight:600;">Use a link to an image instead</summary>
              <div style="margin-top:8px;">${field('Logo URL', 'kt-logo-url', brand.brand_logo_url || '', 'https://yourdomain.com/logo.png', 'Saved with "Save changes".')}</div>
            </details>
            ${colorField('Primary colour', 'kt-color', brand.brand_primary_color || '#3BBBBE')}
            <div style="font-size:11px;color:#64748B;margin:-4px 0 4px;">Used for the header of your invoices and white-label emails.</div>
          </div>

          <div style="${card}">
            <h3 style="margin:0 0 4px;font-size:15px;">Where your logo appears</h3>
            <div id="kt-wl-caption" style="font-size:12px;color:#64748B;margin-bottom:14px;"></div>
            <div style="font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:.4px;text-transform:uppercase;margin-bottom:6px;">Top bar · every role</div>
            <div style="display:flex;align-items:center;gap:10px;height:56px;padding:0 14px;border:1px solid #E5E7EB;border-radius:10px;background:#fff;margin-bottom:16px;">
              <img id="kt-wl-bar-logo" alt="" style="height:34px;width:auto;max-width:160px;object-fit:contain;">
              <span style="flex:1;"></span>
              <span style="width:28px;height:28px;border-radius:50%;background:#E2E8F0;"></span>
            </div>
            <div style="font-size:11px;font-weight:700;color:#94A3B8;letter-spacing:.4px;text-transform:uppercase;margin-bottom:6px;">App loading &amp; unlock screen</div>
            <div style="height:200px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:radial-gradient(120% 70% at 50% 0%,#12315C 0%,#081C41 62%);margin-bottom:12px;">
              <span id="kt-wl-splash-plate" style="display:inline-block;line-height:0;border-radius:16px;padding:14px 18px;max-width:70%;">
                <img id="kt-wl-splash-logo" alt="" style="max-width:100%;max-height:90px;width:auto;height:auto;object-fit:contain;">
              </span>
            </div>
            <div style="font-size:11.5px;color:#64748B;line-height:1.55;">Also: emails, invoices, forms, and the sign-in page of your own branded app. Your own app icon and name in the app stores come with a white-label app build — ask KiddieTrac.</div>
          </div>
        </div>

        <!-- ── Invoices ── -->
        <div data-pane="invoices" hidden style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start;" class="kt-br-grid">
          <div style="${card}">
            <h3 style="margin:0 0 14px;font-size:15px;">Invoice settings</h3>
            ${(function () {
              /* INVOICE STYLE — it belongs with the logo and the colour, because the
                 invoice IS the brand as far as a family is concerned. The options come
                 from the server (InvoiceDocument::TEMPLATES) so a template added later
                 shows up here with no change to this file. (2026-09-17) */
              var tpls = brand.invoice_templates || null;
              if (!tpls) { return ''; }
              var cur = brand.invoice_template || 'kiddietrac';
              var opts = Object.keys(tpls).map(function (k) {
                return '<option value="' + esc(k) + '"' + (k === cur ? ' selected' : '') + '>' + esc(tpls[k].label || k) + '</option>';
              }).join('');
              var blurb = (tpls[cur] && tpls[cur].blurb) || '';
              return '<div style="margin-bottom:14px;">'
                + '<label style="display:block;font-size:12px;font-weight:700;color:#6B7280;margin-bottom:6px;">Invoice style</label>'
                + '<select id="kt-invoice-template" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">'
                + opts + '</select>'
                + '<div id="kt-invoice-template-blurb" style="font-size:11px;color:#64748B;margin-top:4px;line-height:1.5;">' + esc(blurb) + '</div>'
                + '</div>';
            })()}
            ${(function () {
              /* INVOICE NUMBERING, beside the invoice's look (2026-09-17).

                 Anthony: "invoice numbering naming convention should be definable for
                 each agency on how they want invoice numbering to start and be formatted
                 - can you wire this up under the branding for invoices."

                 A free-text format rather than a fixed dropdown, because the whole point
                 is that an agency invents its own prefix; the presets are a starting
                 point, not the menu. The sample updates as you type, because a token
                 language nobody can see the output of is a guessing game — and the
                 server renders the real thing from the same code that mints the number,
                 so the sample cannot drift from reality. */
              var fmt = brand.invoice_number_format || 'INV-{YYYY}{MM}-{FAMILY}-{N}';
              var start = brand.invoice_number_start || 1001;
              var presets = brand.invoice_number_presets || {};
              var sample = brand.invoice_number_sample || '';
              var opts = Object.keys(presets).map(function (k) {
                return '<option value="' + esc(k) + '"' + (k === fmt ? ' selected' : '') + '>'
                  + esc(presets[k]) + '</option>';
              }).join('');
              return '<div style="margin-bottom:14px;padding-top:12px;border-top:1px solid #F1F5F9;">'
                + '<label style="display:block;font-size:12px;font-weight:700;color:#6B7280;margin-bottom:6px;">Invoice numbering</label>'
                + (opts
                    ? '<select id="kt-invnum-preset" style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:8px;">'
                      + '<option value="">Start from a preset\u2026</option>' + opts + '</select>'
                    : '')
                + '<input id="kt-invnum-format" type="text" value="' + esc(fmt) + '" spellcheck="false"'
                + ' style="width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">'
                + '<div style="display:flex;gap:10px;align-items:flex-end;margin-top:8px;flex-wrap:wrap;">'
                + '<div style="flex:0 0 150px;">'
                + '<label style="display:block;font-size:11px;font-weight:700;color:#6B7280;margin-bottom:4px;">Start numbering at</label>'
                + '<input id="kt-invnum-start" type="number" min="1" step="1" value="' + esc(String(start)) + '"'
                + ' style="width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;"></div>'
                + '<div style="flex:1;min-width:180px;">'
                + '<label style="display:block;font-size:11px;font-weight:700;color:#6B7280;margin-bottom:4px;">Next invoice will look like</label>'
                + '<div id="kt-invnum-sample" style="padding:9px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;'
                + 'font-size:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#0F172A;font-weight:700;">'
                + esc(sample) + '</div></div></div>'
                + '<div style="font-size:11px;color:#64748B;margin-top:6px;line-height:1.55;">'
                + '<strong>{YYYY}</strong> year \u00b7 <strong>{YY}</strong> short year \u00b7 <strong>{MM}</strong> month \u00b7 '
                + '<strong>{DD}</strong> day \u00b7 <strong>{FAMILY}</strong> family number \u00b7 '
                + '<strong>{N}</strong> instalment \u00b7 <strong>{SEQ}</strong> running number. '
                + 'Anything else is used as typed. "Start numbering at" only applies to {SEQ}, '
                + 'and can be raised later but never lowered \u2014 that would reissue numbers '
                + 'invoices already carry.</div>'
                + '</div>';
            })()}
            ${textareaField('Business address (shown on invoices)', 'kt-address', brand.brand_address || '', '123 Main St\\nToronto, ON  M5V 1A1')}
            ${textareaField('Bank info / payment details', 'kt-bank', brand.brand_bank_info || '', 'Bank: ...\nTransit: ...\nAccount: ...\nor e-Transfer: pay@yourdomain.com')}
            <button id="kt-preview-btn" type="button" style="height:32px;padding:0 14px;background:#E5E7EB;color:#374151;border:none;border-radius:8px;font-weight:600;cursor:pointer;">↻ Refresh preview</button>
          </div>

          <div style="background:white;border-radius:14px;padding:0;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;">
              <h3 style="margin:0;font-size:15px;">Live invoice preview</h3>
              <a href="#" id="kt-open-fullscreen" style="font-size:12px;color:#3BBBBE;text-decoration:none;font-weight:600;">↗ Open in new tab</a>
            </div>
            <iframe id="kt-preview-iframe" src="" style="width:100%;height:780px;border:0;background:#F9FAFB;"></iframe>
          </div>
        </div>

        <!-- ── Contact & legal ── -->
        <div data-pane="contact" hidden style="${card}max-width:720px;">
          <h3 style="margin:0 0 14px;font-size:15px;">Contact &amp; legal</h3>
          ${field('Support email', 'kt-support', brand.brand_support_email || '', 'billing@yourdomain.com', 'Shown on invoices and parent emails.')}
          ${field('Privacy policy URL', 'kt-privacy', brand.brand_privacy_url || '', 'https://yourdomain.com/privacy', 'Linked from the footer of your emails and campaigns.')}
          ${field('Terms & conditions URL', 'kt-terms', brand.brand_terms_url || '', 'https://yourdomain.com/terms', 'Linked beside your privacy policy.')}
        </div>

        <!-- ── Cards that save themselves ── -->
        <div data-pane="email" hidden style="max-width:900px;"></div>
        <div data-pane="payslips" hidden></div>
        <div data-pane="region" hidden style="max-width:900px;"></div>
        <div data-pane="seasons" hidden></div>

        <div id="kt-br-savebar" style="display:flex;gap:10px;align-items:center;margin-top:18px;">
          <button id="kt-save" type="button" style="height:36px;padding:0 24px;background:#081C41;color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Save changes</button>
          <div id="kt-save-msg" style="font-size:13px;"></div>
          <div style="flex:1;"></div>
          <div style="font-size:11.5px;color:#94A3B8;">Saves White-label &amp; logo, Invoices and Contact &amp; legal together.</div>
        </div>
      </div>
    `;

    if (!document.getElementById('kt-br-style')) {
      const st = document.createElement('style'); st.id = 'kt-br-style';
      st.textContent = '.kt-br-tab{flex:0 0 auto;height:32px;padding:0 14px;border-radius:999px;border:1px solid #E2E8F0;background:#fff;color:#475569;font:600 13px/1 inherit;cursor:pointer;white-space:nowrap;}'
        + '.kt-br-tab[aria-selected="true"]{background:#081C41;border-color:#081C41;color:#fff;}'
        + '.kt-br-tabs{scrollbar-width:none;}.kt-br-tabs::-webkit-scrollbar{display:none;}'
        + '[data-pane][hidden]{display:none !important;}'
        + '@media(max-width:900px){.kt-br-grid{grid-template-columns:minmax(0,1fr) !important;}}';
      document.head.appendChild(st);
    }

    /* Panes that are someone else's card: drawn once, the first time they are opened. */
    const drawn = {};
    function drawPane(name) {
      if (drawn[name]) return; drawn[name] = true;
      const host = container.querySelector('[data-pane="' + name + '"]');
      try {
        if (name === 'email' && KT.renderEmailDeliveryCard) KT.renderEmailDeliveryCard(host);
        else if (name === 'payslips' && KT.renderPayslipCard) KT.renderPayslipCard(host);
        else if (name === 'region') {
          /* COUNTRY & COMPLIANCE + REFERENCE ID PREFIX — the same cards as Agency
             overview (both live in screen-admin.js). */
          if (KT.renderCountryCard) { const c1 = document.createElement('div'); c1.style.cssText = 'margin:0 0 20px;'; host.appendChild(c1); KT.renderCountryCard(c1); }
          if (KT.buildRefPrefixCard) host.appendChild(KT.buildRefPrefixCard());
        } else if (name === 'seasons' && KT.EmailSeasons) KT.EmailSeasons.render(host);
        else if (name === 'invoices') refreshPreview(container, agencyId);
        if (!host.firstChild && name !== 'invoices') host.innerHTML = '<div style="padding:24px;color:#6B7280;">This section is not available.</div>';
      } catch (e) {}
    }
    function showTab(name) {
      tab = name;
      container.querySelectorAll('.kt-br-tab').forEach(b => b.setAttribute('aria-selected', b.getAttribute('data-tab') === name ? 'true' : 'false'));
      container.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.getAttribute('data-pane') !== name; });
      $('#kt-br-savebar', container).style.display = SAVES_HERE[name] ? 'flex' : 'none';
      drawPane(name);
      try {
        const base = location.hash.replace(/\?.*$/, '') || '#admin-branding';
        history.replaceState(history.state, '', base + (name === 'whitelabel' ? '' : '?tab=' + name));
      } catch (e) {}
    }
    container.querySelectorAll('.kt-br-tab').forEach(b => b.addEventListener('click', () => showTab(b.getAttribute('data-tab'))));

    /* The preview follows the logo field and the licence switch as they change, so
       nobody has to save to find out what families will see. */
    function paintWlPreview() {
      const pb = $('#kt-poweredby', container);
      const wlOn = pb ? !pb.checked : isWL;
      const logo = ($('#kt-logo-url', container).value || '').trim();
      const abs = logo ? (/^(https?:|data:)/i.test(logo) ? logo : apiBase().replace(/\/api\/v1\/?$/, '') + (logo.charAt(0) === '/' ? '' : '/') + logo) : '';
      const showAgency = wlOn && !!abs;
      const thumb = $('#kt-logo-thumb', container), none = $('#kt-logo-none', container);
      if (thumb) { thumb.style.display = abs ? '' : 'none'; if (abs) thumb.src = abs; }
      if (none) none.style.display = abs ? 'none' : '';
      const up = $('#kt-logo-upload', container); if (up) up.textContent = abs ? 'Replace logo' : 'Upload logo';
      const rm = $('#kt-logo-remove', container); if (rm) rm.style.display = abs ? '' : 'none';
      $('#kt-wl-bar-logo', container).src = showAgency ? abs : '/logo-wordmark@2x.png';
      $('#kt-wl-splash-logo', container).src = showAgency ? abs : '/login-wordmark.png';
      const plate = $('#kt-wl-splash-plate', container);
      plate.style.background = showAgency ? '#fff' : 'transparent';
      plate.style.boxShadow = showAgency ? '0 14px 40px -16px rgba(0,0,0,.55)' : 'none';
      $('#kt-wl-caption', container).textContent = showAgency
        ? 'White-label licence on: families and staff see your logo here.'
        : (wlOn ? 'White-label licence on, but no logo yet — upload one to replace KiddieTrac.' : 'Licence off: KiddieTrac shows here. Your logo is used on invoices and emails.');
    }
    $('#kt-logo-url', container).addEventListener('input', paintWlPreview);
    const _pb = $('#kt-poweredby', container);
    if (_pb) _pb.addEventListener('change', paintWlPreview);
    container._ktPaintWl = paintWlPreview;
    paintWlPreview();
    showTab(tab);

    /* The blurb follows the choice, so somebody can read what each style is before
       saving rather than after seeing an invoice go out in it. */
    (function () {
      /* The sample is rendered SERVER-side by the same class that mints the real
         number, so what is shown here and what lands on the invoice cannot disagree.
         Debounced, because it is a request per keystroke otherwise. */
      const fmtEl = $('#kt-invnum-format', container);
      const startEl = $('#kt-invnum-start', container);
      const sampleEl = $('#kt-invnum-sample', container);
      const presetEl = $('#kt-invnum-preset', container);
      if (fmtEl && sampleEl) {
        let t = null;
        const refreshSample = function () {
          clearTimeout(t);
          t = setTimeout(async function () {
            try {
              const q = '?format=' + encodeURIComponent(fmtEl.value || '')
                + '&start=' + encodeURIComponent(startEl ? startEl.value : '');
              const r = await api('GET', '/admin/agencies/' + agencyId + '/invoice-number-preview' + q);
              sampleEl.textContent = (r && r.sample) || '';
              sampleEl.style.color = '#0F172A';
            } catch (e) {
              sampleEl.textContent = 'Could not preview that format';
              sampleEl.style.color = '#DC2626';
            }
          }, 300);
        };
        fmtEl.addEventListener('input', refreshSample);
        if (startEl) { startEl.addEventListener('input', refreshSample); }
        if (presetEl) {
          presetEl.addEventListener('change', function () {
            if (!presetEl.value) { return; }
            fmtEl.value = presetEl.value;
            refreshSample();
          });
        }
      }

      const sel = $('#kt-invoice-template', container);
      const note = $('#kt-invoice-template-blurb', container);
      if (!sel || !note) { return; }
      const tpls = brand.invoice_templates || {};
      sel.addEventListener('change', () => {
        note.textContent = (tpls[sel.value] && tpls[sel.value].blurb) || '';
        refreshPreview(container, agencyId);
      });
    })();

    $('#kt-preview-btn',      container).addEventListener('click', () => refreshPreview(container, agencyId));
    $('#kt-save',             container).addEventListener('click', () => save(container, agencyId));

    // v22p88: logo file upload (in addition to the URL field).
    const logoFile = $('#kt-logo-file', container);
    const logoMsg = $('#kt-logo-upmsg', container);
    $('#kt-logo-upload', container).addEventListener('click', () => logoFile.click());
    logoFile.addEventListener('change', () => { if (logoFile.files[0]) uploadLogo(logoFile.files[0]); logoFile.value = ''; });
    const drop = $('#kt-logo-drop', container);
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.style.borderColor = '#1F6080'; }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.style.borderColor = '#CBD5E1'; }));
    drop.addEventListener('drop', (e) => { const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) uploadLogo(f); });
    $('#kt-logo-remove', container).addEventListener('click', async () => {
      logoMsg.textContent = 'Removing…'; logoMsg.style.color = '#6B7280';
      try {
        await api('PATCH', '/admin/agencies/' + agencyId + '/features', { brand_logo_url: null });
        $('#kt-logo-url', container).value = '';
        logoMsg.textContent = '✓ Logo removed'; logoMsg.style.color = '#16A34A';
        if (container._ktPaintWl) container._ktPaintWl();
        reloadChrome();
      } catch (e) { logoMsg.textContent = 'Could not remove: ' + (e.message || 'error'); logoMsg.style.color = '#DC2626'; }
    });
    async function uploadLogo(file) {
      if (file.size > 2 * 1024 * 1024) { logoMsg.textContent = 'Max 2 MB'; logoMsg.style.color = '#DC2626'; return; }
      logoMsg.textContent = 'Uploading…'; logoMsg.style.color = '#6B7280';
      try {
        const fd = new FormData();
        fd.append('logo', file);
        /* The upload endpoint reads the target agency from the FORM (agency_id, honoured
           for a platform admin), not from X-Active-Agency-Id — without it a super admin's
           upload went to their own agency, or 403'd. */
        fd.append('agency_id', String(agencyId));
        const tok = sessionStorage.getItem('kt_token');
        const headers = { 'Authorization': 'Bearer ' + tok };
        const active = sessionStorage.getItem('kt_active_agency_id');
        if (active) headers['X-Active-Agency-Id'] = active;
        const r = await fetch(apiBase() + '/admin/branding/logo', { method: 'POST', headers, body: fd });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status));
        const url = j.url || j.logo_url || j.brand_logo_url;
        if (url) {
          $('#kt-logo-url', container).value = url;
          // uploadLogo stores it on the agency straight away; Save is not needed for the logo.
          logoMsg.textContent = '✓ Uploaded and saved'; logoMsg.style.color = '#16A34A';
          if (container._ktPaintWl) container._ktPaintWl();
          reloadChrome();
        } else {
          logoMsg.textContent = 'Uploaded but no URL returned'; logoMsg.style.color = '#DC2626';
        }
      } catch (e) {
        logoMsg.textContent = 'Upload failed: ' + (e.message || 'error'); logoMsg.style.color = '#DC2626';
      }
    }
    $('#kt-open-fullscreen',  container).addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        const res = await fetch(apiBase() + '/invoices/preview-sample?agency_id=' + agencyId, {
          headers: { 'Authorization': 'Bearer ' + token() },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        const blob = new Blob([html], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
        // revoke after a delay to let the new tab finish loading
        setTimeout(() => URL.revokeObjectURL(url), 30000);
      } catch (err) {
        alert('Could not open preview: ' + (err.message || 'unknown'));
      }
    });
  }

  function field(label, id, value, placeholder, hint) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <input type="text" id="${id}" value="${esc(value)}" placeholder="${esc(placeholder)}" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;margin-top:4px;">
      ${hint ? `<div style="font-size:11px;color:#64748B;margin-top:3px;">${esc(hint)}</div>` : ''}
    </div>`;
  }
  function textareaField(label, id, value, placeholder) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <textarea id="${id}" placeholder="${esc(placeholder)}" rows="4" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;margin-top:4px;font-family:inherit;resize:vertical;">${esc(value)}</textarea>
    </div>`;
  }
  function colorField(label, id, value) {
    return `<div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#6B7280;">${esc(label)}</label>
      <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
        <input type="color" id="${id}-picker" value="${esc(value)}" style="width:50px;height:38px;padding:0;border:1px solid #D1D5DB;border-radius:6px;cursor:pointer;background:white;">
        <input type="text" id="${id}" value="${esc(value)}" style="flex:1;padding:9px 11px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;font-family:monospace;">
      </div>
    </div>`;
  }

  /* The top bar / splash logo comes from branding-loader's cache; drop it and refetch so
     a saved logo or licence change shows in this session's chrome straight away. */
  function reloadChrome() {
    try { localStorage.removeItem('kt_branding'); } catch (e) {}
    try { if (window.KT && KT.reloadBranding) KT.reloadBranding(); } catch (e) {}
  }

  async function refreshPreview(container, agencyId) {
    const iframe = $('#kt-preview-iframe', container);
    iframe.srcdoc = '<div style="padding:40px;text-align:center;color:#64748B;font-family:sans-serif;">Loading preview…</div>';
    try {
      /* Preview the style that is SELECTED, not only the one that is saved — the
         dropdown sits beside this panel and somebody choosing a style expects to see
         it. */
      const tplEl = $('#kt-invoice-template', container);
      const tplQ = tplEl && tplEl.value ? '&template=' + encodeURIComponent(tplEl.value) : '';
      const res = await fetch(apiBase() + '/invoices/preview-sample?agency_id=' + agencyId + tplQ + '&_=' + Date.now(), {
        headers: { 'Authorization': 'Bearer ' + token() },
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      iframe.srcdoc = html;
    } catch (e) {
      iframe.srcdoc = '<div style="padding:40px;text-align:center;color:#DC2626;font-family:sans-serif;">Preview failed: ' + (e.message || 'unknown') + '</div>';
    }
  }

  async function save(container, agencyId) {
    const colorPicker = $('#kt-color-picker', container);
    const colorText   = $('#kt-color', container);
    // Sync picker → text
    colorPicker.addEventListener('input', () => colorText.value = colorPicker.value);
    const payload = {
      brand_logo_url:       $('#kt-logo-url', container).value.trim() || null,
      brand_primary_color:  colorText.value.trim() || null,
      brand_support_email:  $('#kt-support', container).value.trim() || null,
      brand_address:        $('#kt-address', container).value.trim() || null,
      brand_bank_info:      $('#kt-bank',    container).value.trim() || null,
      invoice_template:     ($('#kt-invoice-template', container) || {}).value || undefined,
      invoice_number_format: (($('#kt-invnum-format', container) || {}).value || '').trim() || undefined,
      invoice_number_start:  (function (v) {
        var n = parseInt(v, 10);
        return isNaN(n) || n < 1 ? undefined : n;
      })(($('#kt-invnum-start', container) || {}).value),
      brand_privacy_url:    $('#kt-privacy', container).value.trim() || null,
      brand_terms_url:      $('#kt-terms',   container).value.trim() || null,
    };
    // The white-label licence is sold, so only a platform admin's save carries it
    // (the server ignores it from anyone else too).
    const pb = $('#kt-poweredby', container);
    if (pb) payload.powered_by_visible = pb.checked ? 1 : 0;
    const msg = $('#kt-save-msg', container);
    msg.textContent = 'Saving…'; msg.style.color = '#6B7280';
    try {
      await api('PATCH', '/admin/agencies/' + agencyId + '/features', payload);
      msg.textContent = '✓ Saved'; msg.style.color = '#16A34A';
      setTimeout(() => refreshPreview(container, agencyId), 400);
      reloadChrome();
    } catch (e) {
      msg.textContent = '✗ ' + e.message; msg.style.color = '#DC2626';
    }
  }

  // Wire colour picker ↔ text input after each render (delegated)
  document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'kt-color-picker') {
      const tgt = document.getElementById('kt-color');
      if (tgt) tgt.value = e.target.value;
    } else if (e.target && e.target.id === 'kt-color') {
      const tgt = document.getElementById('kt-color-picker');
      if (tgt && /^#[0-9a-f]{6}$/i.test(e.target.value)) tgt.value = e.target.value;
    }
  });

  window.KT = window.KT || {};
  window.KT.AdminBranding = { render: render };
})(window);
