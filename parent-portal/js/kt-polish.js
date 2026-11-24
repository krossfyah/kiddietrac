/* v22p61 — polish layer.
   Extends v22p60's table-filter with: sortable columns, sticky headers,
   per-table CSV export, ISO datetime auto-format. Adds window.KT.prompt
   + window.KT.confirm modals. Mobile sidebar toggle.
*/
(function (window) {
  'use strict';
  if (window.KT_POLISH_LOADED) return;
  window.KT_POLISH_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Modal: prompt + confirm ============================
  function buildOverlay() {
    const o = document.createElement('div');
    /* ABOVE EVERYTHING, because a confirm is a QUESTION that blocks whatever asked it.

       This sat at 9,999,999 while the portal keeps a whole tier of full-screen overlays
       three orders of magnitude higher — the terms/NDA scrim (2147481000), the two-factor
       gate (2147482000), the avatar cropper (2147483000), the photo lightbox (2147483200),
       even the toast (2147483600). Any confirm raised while one of those was up opened
       UNDERNEATH it: the dialog was there, the buttons were there, and the tap landed on
       the thing covering them.

       Measured on a phone: "Mark all read" opened "Mark all 193 as read?" and
       elementFromPoint over the Confirm button returned the agreement's signature canvas.
       Nothing cleared, and nothing explained why — reported as "clicking all read in the
       APK doesn't clear it".

       Sits just under the int32 ceiling so it outranks every existing tier. If something
       ever genuinely needs to be above a confirm, it is a gate that should be suppressing
       the confirm instead. (Anthony, 2026-09-09) */
    o.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:2147483640;display:flex;align-items:center;justify-content:center;animation:kt-fade-in .15s ease-out;';
    return o;
  }
  if (!document.getElementById('kt-modal-css')) {
    const s = document.createElement('style');
    s.id = 'kt-modal-css';
    s.textContent = '@keyframes kt-fade-in { from { opacity: 0; } to { opacity: 1; } } @keyframes kt-pop-in { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }'
      /* Confirm card (2026-10-27): short heading in the band with the tone icon beside it, the
         explanation in the body, and on a phone full-width buttons a thumb can hit. */
      + '.kt-cf{background:#fff;padding:24px;border-radius:14px;max-width:460px;width:92%;box-sizing:border-box;box-shadow:0 20px 60px rgba(15,23,42,.25);animation:kt-pop-in .15s ease-out;}'
      + '.kt-cf .kt-dlg-head{margin:-24px -24px 0;padding:16px 54px 16px 22px;border-radius:14px 14px 0 0;display:flex;gap:12px;align-items:center;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);}'
      + '.kt-cf .kt-cf-ico{width:32px;height:32px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.18);}'
      + '.kt-cf h3{margin:0;color:#fff;font-size:17px;font-weight:700;line-height:1.35;overflow-wrap:anywhere;}'
      + '.kt-cf .kt-cf-body{padding-top:18px;}'
      + '.kt-cf .kt-cf-text{margin:0;color:#334155;font-size:14.5px;line-height:1.6;white-space:pre-line;overflow-wrap:anywhere;}'
      /* A one-line question (2026-10-28, Anthony: "prompts like this looks weird as there is a blank space on the
         second line"): the question is the body, in clear dark type; the header carries a short heading. */
      + '.kt-cf .kt-cf-text.kt-cf-q{color:#0F172A;font-size:16px;font-weight:700;line-height:1.45;}'
      + '.kt-cf .kt-confirm-extra:not(:empty){margin-top:14px;}'
      + '.kt-cf .kt-cf-acts{margin-top:22px;display:flex;justify-content:flex-end;gap:8px;}'
      + '.kt-cf .kt-cf-acts button{border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;min-height:40px;}'
      /* ALARM (2026-11-04): a warning that must not be clicked through - the page behind dims to yellow,
         the card gets an amber ring, and the body leads with a big warning sign and bold amber words. */
      + '.kt-cf.kt-cf-alarm{border:3px solid #F59E0B;box-shadow:0 0 0 6px rgba(245,158,11,.25),0 20px 60px rgba(120,53,15,.35);}'
      + '.kt-cf-warn{display:flex;gap:14px;align-items:flex-start;background:#FEF3C7;border:2px solid #F59E0B;border-radius:12px;padding:14px 16px;}'
      + '.kt-cf-warn .kt-cf-warn-ico{font-size:34px;line-height:1;flex:0 0 auto;}'
      + '.kt-cf-warn .kt-cf-warn-big{margin:0;color:#78350F;font-size:17px;font-weight:800;line-height:1.4;overflow-wrap:anywhere;}'
      + '.kt-cf-warn .kt-cf-warn-names{margin:8px 0 0;display:flex;flex-wrap:wrap;gap:6px;}'
      + '.kt-cf-warn .kt-cf-warn-names b{background:#fff;border:1.5px solid #F59E0B;color:#78350F;border-radius:999px;padding:2px 10px;font-size:13.5px;}'
      + '.kt-cf-warn .kt-cf-warn-sub{margin:10px 0 0;color:#92400E;font-size:13.5px;line-height:1.5;font-weight:600;}'
      + '.kt-cf.kt-cf-alarm .kt-cf-acts button[data-action="ok"]{font-weight:800;}'
      /* A WARNING LOOKS LIKE A WARNING (2026-11-06, Anthony: check-in/out without One-Tap "should be a warning message
         using the warning symbol with a yellow background"): an alarm's header band is yellow with dark amber words and
         the ⚠ sign, instead of the navy band every other popup has. */
      /* !important: kt-dialog-chrome's one-header rule paints every .kt-dlg-head navy with white words, !important. */
      + '.kt-cf.kt-cf-alarm .kt-dlg-head{background:linear-gradient(135deg,#FDE68A 0%,#FCD34D 100%) !important;box-shadow:inset 0 -2px 0 #F59E0B !important;}'
      + '.kt-cf.kt-cf-alarm .kt-dlg-head h3{color:#78350F !important;}'
      + '.kt-cf.kt-cf-alarm .kt-dlg-head .kt-cf-ico{background:#fff !important;color:#B45309 !important;font-size:18px;box-shadow:0 0 0 2px #F59E0B;}'
      /* the guard's ✕ sits beside the card, over its header */
      + '.kt-cf.kt-cf-alarm ~ .kt-modal-x,:has(> .kt-cf.kt-cf-alarm) > .kt-modal-x{color:#78350F !important;-webkit-text-fill-color:#78350F !important;text-shadow:none !important;}'
      + '@media (max-width:520px){.kt-cf{padding:20px;width:calc(100% - 32px);}'
      + '.kt-cf .kt-dlg-head{margin:-20px -20px 0;padding:14px 50px 14px 18px;}'
      + '.kt-cf h3{font-size:16px;}'
      + '.kt-cf .kt-cf-acts button{flex:1 1 0;min-height:44px;}}';
    document.head.appendChild(s);
  }

  /* ONE QUESTION IN THE HEADING, THE REST IN THE BODY (2026-10-27).
     Most callers hand over a single string: "Delete Amelie Tremblay (amelie@…)?\n\nThey will be
     unable to sign in. Their family/child records stay intact for audit." All of it became the
     heading. Split it where the writer did: at a blank line, else after the first sentence. A
     parenthetical (usually an email address) leaves a long heading for the body, and anything
     still too long for a heading goes to the body under a plain "Please confirm". */
  function shapeText(opts) {
    var t = String(opts.title || '').trim();
    if (!t || (t.length <= 60 && t.indexOf('\n') === -1)) { if (t) opts.title = t; return opts; }
    var head = t, rest = '';
    var nlAt = t.search(/\n\s*\n|\n/);
    if (nlAt > 0) { head = t.slice(0, nlAt).trim(); rest = t.slice(nlAt).trim(); }
    if (head.length > 60) {
      var m = head.match(/^[\s\S]{6,110}?[?!.](?=\s|$)/);
      if (m && m[0].length < head.length) { rest = (head.slice(m[0].length).trim() + (rest ? '\n\n' + rest : '')).trim(); head = m[0].trim(); }
    }
    if (head.length > 48) {
      var pm = head.match(/\s*\(([^()]{3,80})\)/);
      if (pm) { head = (head.slice(0, pm.index) + head.slice(pm.index + pm[0].length)).replace(/\s+([?!.])$/, '$1'); rest = pm[1] + (rest ? '\n\n' + rest : ''); }
    }
    if (head.length > 70) {
      // A statement too long for a heading: the question it ends on ("Save anyway?") is the heading.
      var all = head + (rest ? '\n\n' + rest : '');
      var q = all.match(/(^|[.!?]\s+|\n)([^.!?\n]{3,60}\?)\s*$/);
      if (q) { head = q[2].trim(); rest = all.slice(0, q.index + q[1].length).trim(); }
      else { rest = all; head = 'Please confirm'; }
    }
    opts.title = head;
    if (rest) opts.description = rest + (opts.description ? '\n\n' + opts.description : '');
    return opts;
  }
  KT.shapeDialogText = shapeText;

  KT.prompt = function (opts, more) {
    /* (title, { description, ... }) is accepted too: several callers wrote it that way
       and their description and button label were silently dropped (2026-10-05). */
    if (typeof opts === 'string') opts = Object.assign({ title: opts }, more || {});
    opts = shapeText(opts || {});
    return new Promise((resolve) => {
      const o = buildOverlay();
      const m = document.createElement('div');
      // A dialog with a multi-line box gets the room to write in (2026-10-23).
      const wide = (opts.fields || []).some(f => f.type === 'textarea');
      m.style.cssText = 'background:#fff;padding:28px;border-radius:14px;max-width:' + (wide ? 600 : 440) + 'px;width:92%;box-shadow:0 20px 60px rgba(15,23,42,.25);animation:kt-pop-in .15s ease-out;';
      m.innerHTML = `
        <div class="kt-dlg-head" style="margin:-28px -28px 14px;padding:18px 56px 16px 28px;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);border-radius:14px 14px 0 0;">
          <h3 style="margin:0;color:#fff;font-size:18px;font-weight:700;">${esc(opts.title || 'Enter value')}</h3>
          ${opts.description ? `<p style="color:rgba(255,255,255,.85);font-size:13.5px;margin:6px 0 0;line-height:1.5;">${esc(opts.description)}</p>` : ''}
        </div>
        ${(opts.fields || [{ key: 'value', label: opts.label || '', placeholder: opts.placeholder || '', type: opts.type || 'text' }]).map(f => `${f.showWhen ? `<div data-kt-pf="${esc(f.key || 'value')}">` : ''}
          ${f.label ? `<label style="display:block;font-size:13px;font-weight:600;margin-top:10px;color:#374151;">${esc(f.label)}</label>` : ''}
          ${f.type === 'select'
            ? `<select data-field="${esc(f.key || 'value')}" style="width:100%;padding:10px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;margin-top:5px;background:#fff;">${(f.options || []).map(o => `<option value="${esc(o.value)}"${String(o.value) === String(f.value) ? ' selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`
            : f.type === 'textarea'
            ? `<textarea data-field="${esc(f.key || 'value')}" rows="${f.rows || 6}" placeholder="${esc(f.placeholder || '')}" style="width:100%;box-sizing:border-box;padding:11px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;line-height:1.5;margin-top:5px;outline:none;resize:vertical;font-family:inherit;min-height:130px;transition:border-color .15s;">${esc(f.value || '')}</textarea>`
            : `<input data-field="${esc(f.key || 'value')}" type="${esc(f.type || 'text')}" placeholder="${esc(f.placeholder || '')}" value="${esc(f.value || '')}" ${f.min !== undefined ? 'min=' + f.min : ''} ${f.max !== undefined ? 'max=' + f.max : ''} ${f.step !== undefined ? 'step=' + f.step : ''}
            style="width:100%;padding:11px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;margin-top:5px;outline:none;transition:border-color .15s;">`}
        ${f.showWhen ? '</div>' : ''}`).join('')}
        <div style="margin-top:22px;display:flex;justify-content:flex-end;gap:8px;">
          <button data-action="cancel" style="background:#F1F5F9;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;color:#475569;font-size:14px;">${esc(opts.cancelLabel || 'Cancel')}</button>
          <button data-action="ok" style="background:linear-gradient(180deg,#1F6080,#154057);color:#fff;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">${esc(opts.okLabel || 'OK')}</button>
        </div>
      `;
      o.appendChild(m);
      document.body.appendChild(o);

      const inputs = Array.from(m.querySelectorAll('[data-field]'));
      inputs.forEach(i => {
        i.addEventListener('focus', () => i.style.borderColor = '#1F6080');
        i.addEventListener('blur', () => i.style.borderColor = '#E2E8F0');
      });
      setTimeout(() => inputs[0] && inputs[0].focus(), 80);

      /* A field that only applies to some answers of another one (2026-11-10, the drill schedule: a date asked for on
         "Every month" was meaningless): showWhen { field, in: [values] }. A hidden field answers '' on save, so a value
         left from an earlier choice is never sent. */
      const conds = (opts.fields || []).filter(f => f.showWhen);
      const shown = (f) => { const c = m.querySelector('[data-field="' + f.showWhen.field + '"]'); return !c || (f.showWhen.in || []).map(String).indexOf(c.value) > -1; };
      const sync = () => conds.forEach(f => { const w = m.querySelector('[data-kt-pf="' + (f.key || 'value') + '"]'); if (w) w.style.display = shown(f) ? '' : 'none'; });
      if (conds.length) { inputs.forEach(i => i.addEventListener('change', sync)); sync(); }
      const hiddenNow = (i) => { const w = i.closest('[data-kt-pf]'); return !!(w && w.style.display === 'none'); };

      const close = (result) => { o.remove(); resolve(result); };
      m.querySelector('[data-action="cancel"]').onclick = () => close(null);
      m.querySelector('[data-action="ok"]').onclick = () => {
        if (opts.fields && opts.fields.length > 1) {
          const out = {};
          inputs.forEach(i => out[i.dataset.field] = hiddenNow(i) ? '' : i.value);
          close(out);
        } else {
          close(inputs[0].value);
        }
      };
      o.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close(null);
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !opts.fields) m.querySelector('[data-action="ok"]').click();
      });
      o.tabIndex = -1;
      o.focus();
    });
  };

  KT.confirm = function (opts, more) {
    // (title, { description, okLabel, ... }) accepted as well as one object — see KT.prompt.
    if (typeof opts === 'string') opts = Object.assign({ title: opts }, more || {});
    opts = shapeText(opts || {});
    // Auto-flag clearly DESTRUCTIVE actions with the danger (red ⚠) tone + a
    // matching action label, unless the caller set a tone explicitly. This makes
    // the ~47 migrated confirm() sites visually honest with no per-site edits.
    // Kept conservative: only unambiguous destructive verbs (not 'cancel', which
    // collides with the Cancel button, nor 'reset'/'sign out').
    if (!opts.tone) {
      var _dtxt = ((opts.title || '') + ' ' + (opts.description || '')).toLowerCase();
      if (/\b(delete|remove|permanently|deactivate|disconnect|discard|revoke|void|suspend|archive|soft-delete)\b/.test(_dtxt)) {
        opts.tone = 'danger';
        if (!opts.okLabel) {
          opts.okLabel = /\bdelete\b/.test(_dtxt) ? 'Delete'
            : /\bremove\b/.test(_dtxt) ? 'Remove'
            : /\bdisconnect\b/.test(_dtxt) ? 'Disconnect'
            : /\bdiscard\b/.test(_dtxt) ? 'Discard'
            : /\bdeactivate\b/.test(_dtxt) ? 'Deactivate'
            : /\barchive\b/.test(_dtxt) ? 'Archive'
            : /\bvoid\b/.test(_dtxt) ? 'Void'
            : /\bsuspend\b/.test(_dtxt) ? 'Suspend'
            : 'Confirm';
        }
      }
    }
    return new Promise((resolve) => {
      const o = buildOverlay();
      if (opts.alarm) o.style.background = 'rgba(234,179,8,.42)';     // the page behind dims to yellow
      const m = document.createElement('div');
      const tone = opts.tone || 'default';
      const accent = tone === 'danger' ? '#EF4444' : tone === 'warning' ? '#F59E0B' : '#1F6080';
      m.className = 'kt-cf' + (opts.alarm ? ' kt-cf-alarm' : '');
      m.setAttribute('role', 'alertdialog');
      m.setAttribute('aria-modal', 'true');
      /* NO EMPTY MIDDLE (2026-10-28, Anthony: "prompts like this looks weird as there is a blank space on the
         second line ... make this look better through out all prompts like this"). A confirm with only a title
         ("Mark all 5 as read?") drew the title in the header and nothing between it and the buttons. Now the
         question moves into the body as the line you read, and the header says what kind of moment it is. */
      let question = '';
      if (!opts.description && !(opts.extra && opts.extra.nodeType === 1) && !opts.keepHeaderTitle) {
        question = opts.title || 'Are you sure?';
        opts.title = tone === 'danger' ? 'Are you sure?' : tone === 'warning' ? 'Before you continue' : 'Please confirm';
      }
      const hasBody = !!question || !!opts.description || !!(opts.extra && opts.extra.nodeType === 1);
      m.innerHTML = `
        <div class="kt-dlg-head">
          <span class="kt-cf-ico" aria-hidden="true" style="background:${accent};">${opts.alarm ? '⚠' : tone === 'danger' ? '!' : tone === 'warning' ? '!' : '?'}</span>
          <h3>${esc(opts.title || 'Are you sure?')}</h3>
        </div>
        ${hasBody ? `<div class="kt-cf-body">
          ${question ? `<p class="kt-cf-text kt-cf-q">${esc(question)}</p>` : ''}
          ${opts.description ? `<p class="kt-cf-text">${esc(opts.description)}</p>` : ''}
          <div class="kt-confirm-extra"></div>
        </div>` : '<div class="kt-confirm-extra"></div>'}
        <div class="kt-cf-acts">
          <button data-action="cancel" style="background:#F1F5F9;color:#475569;">${esc(opts.cancelLabel || 'Cancel')}</button>
          <button data-action="ok" style="background:${opts.alarm ? '#D97706' : accent};color:#fff;">${esc(opts.okLabel || 'Confirm')}</button>
        </div>
      `;

      /* opts.extra: one element the caller built and still holds, so it can read the
         value back after the dialog resolves. Appended rather than interpolated —
         the markup above is a template string, and an element cannot survive that. */
      if (opts.extra && opts.extra.nodeType === 1) {
        var _slot = m.querySelector('.kt-confirm-extra');
        if (_slot) { _slot.appendChild(opts.extra); }
      }

      o.appendChild(m);
      document.body.appendChild(o);
      const close = (b) => { o.remove(); resolve(b); };
      m.querySelector('[data-action="cancel"]').onclick = () => close(false);
      m.querySelector('[data-action="ok"]').onclick = () => close(true);
      o.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(false); });
      o.tabIndex = -1;
      o.focus();
    });
  };

  /* LOGGING FOR A CHILD WHO IS NOT SIGNED IN (2026-11-04, Anthony: "dimm the background to a yellow and the
     warning popup should have a warning symbol and make this apparent and bold"). Care logs and daily events
     (meals, snacks, naps, room logs, the sleep room) answer 422 not_signed_in; Api.request() brings that here,
     and re-posts with confirm_absent=1 only on "Log anyway". Resolves true / false. */
  KT.warnNotSignedIn = function (d) {
    d = d || {};
    var names = Array.isArray(d.absent) ? d.absent.filter(Boolean) : [];
    var box = document.createElement('div');
    box.className = 'kt-cf-warn';
    var ico = document.createElement('span'); ico.className = 'kt-cf-warn-ico'; ico.setAttribute('aria-hidden', 'true'); ico.textContent = '⚠️';
    var col = document.createElement('div');
    var big = document.createElement('p'); big.className = 'kt-cf-warn-big';
    big.textContent = names.length > 1 ? names.length + ' children are NOT signed in' : (names.length ? names[0] + ' is NOT signed in' : 'This child is NOT signed in');
    col.appendChild(big);
    if (names.length > 1) {
      var list = document.createElement('p'); list.className = 'kt-cf-warn-names';
      names.forEach(function (n) { var b = document.createElement('b'); b.textContent = n; list.appendChild(b); });
      col.appendChild(list);
    }
    var sub = document.createElement('p'); sub.className = 'kt-cf-warn-sub';
    sub.textContent = 'Sign ' + (names.length > 1 ? 'them' : 'this child') + ' in first. Choose Log anyway only if you are catching up after pick-up.';
    col.appendChild(sub);
    box.appendChild(ico); box.appendChild(col);
    return KT.confirm({ title: 'Not signed in', extra: box, tone: 'warning', alarm: true, okLabel: 'Log anyway', cancelLabel: 'Cancel' });
  };

  /* CHECKING A CHILD IN OR OUT BY HAND (2026-11-06, Anthony: "the pop ups for check in and check out for children should
     be in the centre of the screen and should be a warning message using the warning symbol with a yellow background
     ... if they are not using the QR sign in - fully sweep"). Every staff screen that signs a child in or out without
     One-Tap asks through here: the Today roster and the child's quick actions (educator), the director's rooms, and the
     Daily Overview. Centred, yellow, ⚠, short. Resolves true / false.
     d: { name, going: 'in' | 'out' } */
  KT.tempPickupPanel = function (childId, name) {
    var A = (window.KT && KT.Api) || window.Api;
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var hdr = function () { var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') }; var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; return h; };
    if (!A) return Promise.resolve(null);
    var t = new Promise(function (res) { setTimeout(function () { res(null); }, 4000); });
    return Promise.race([A.get('/provider/temp-pickups/child/' + childId).catch(function () { return null; }), t]).then(function (r) {
      var list = (r && r.passes) || [];
      if (!list.length) return null;
      var wrap = document.createElement('div');
      wrap.style.cssText = 'margin-top:10px;border-top:1px solid rgba(146,64,14,.25);padding-top:10px;';
      wrap.innerHTML = '<p class="kt-cf-warn-big" style="margin:0 0 6px;">🎫 Temporary pickup today</p>';
      list.forEach(function (p) {
        var e = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var c = document.createElement('div');
        c.style.cssText = 'display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #FDE68A;border-radius:10px;padding:10px;margin-bottom:8px;color:#0F172A;';
        c.innerHTML = '<img alt="Photo of ' + e(p.full_name) + '" style="width:72px;height:72px;border-radius:10px;object-fit:cover;background:#E2E8F0;flex:0 0 auto;">'
          + '<div style="flex:1;min-width:0;"><div style="font-weight:800;font-size:14px;">' + e(p.full_name) + (p.relationship ? ' · ' + e(p.relationship) : '') + '</div>'
          + '<div style="font-size:12px;color:#475569;margin:2px 0 6px;">Added by ' + e(p.added_by || 'a parent') + ' · ' + e(p.window) + '. Check their photo ID matches.</div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;"><input data-tp-code inputmode="numeric" maxlength="6" placeholder="6-digit code" aria-label="Pickup code" style="width:120px;height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font-size:15px;letter-spacing:2px;">'
          + '<button type="button" data-tp-check style="height:30px;padding:0 10px;border-radius:8px;border:0;background:#1F6080;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;">Check code</button>'
          + '<button type="button" data-tp-scan style="height:30px;padding:0 10px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:12.5px;cursor:pointer;">📷 Scan pass</button>'
          + '<button type="button" data-tp-alert style="height:30px;padding:0 10px;border-radius:8px;border:1px solid #FCA5A5;background:#FEF2F2;color:#B91C1C;font-weight:800;font-size:12.5px;cursor:pointer;">🚨 Not the right person</button></div>'
          + '<div data-tp-msg role="status" style="font-size:12.5px;font-weight:800;margin-top:6px;"></div></div>';
        fetch(base + '/provider/temp-pickups/' + p.id + '/photo', { headers: hdr() }).then(function (x) { return x.ok ? x.blob() : null; })
          .then(function (b) { if (b) c.querySelector('img').src = URL.createObjectURL(b); }).catch(function () {});
        var msg = c.querySelector('[data-tp-msg]');
        var say = function (t, ok) { msg.textContent = t; msg.style.color = ok ? '#047857' : '#B91C1C'; };
        c.querySelector('[data-tp-check]').onclick = function () {
          var b = this, code = c.querySelector('[data-tp-code]').value.replace(/\D/g, '');
          if (code.length !== 6) { say('Enter the 6-digit code from their text or pass.', false); return; }
          b.disabled = true;
          A.post('/provider/temp-pickups/verify', { child_id: childId, code: code, pass_id: p.id }).then(function (x) {
            say('✓ ' + (x.message || 'Code matches.') + ' Tap Check out.', true);
          }, function (x) { b.disabled = false; say('🚨 ' + ((x && x.message) || 'That code does not match. Don\'t release ' + name + '.'), false); });
        };
        /* Scan the QR on their pass (2026-11-24): the scanned code goes through Check code, so the same refusals,
           3-miss lock and high alert apply. Typing stays as the fallback. */
        c.querySelector('[data-tp-scan]').onclick = function () {
          if (!KT.scanQR) { say('Scanning isn\'t available here. Type the code.', false); return; }
          KT.scanQR({ prompt: 'Point the camera at the QR code on their pickup pass.', accept: function (v) { return /^(KTPASS\.)?\d{6}$/.test(String(v).trim()); } })
            .then(function (v) {
              if (!v) return;
              c.querySelector('[data-tp-code]').value = String(v).trim().replace(/\D/g, '').slice(-6);
              c.querySelector('[data-tp-check]').click();
            }, function (e) { say((e && e.message) || 'Could not scan. Type the code.', false); });
        };
        c.querySelector('[data-tp-alert]').onclick = function () {
          var b = this; b.disabled = true;
          A.post('/provider/temp-pickups/alert', { child_id: childId, pass_id: p.id }).then(function () {
            say('🚨 Everyone has been alerted. Don\'t release ' + name + '. Keep them with you.', false);
          }, function (x) { b.disabled = false; say((x && x.message) || 'Could not send the alert. Call the director now.', false); });
        };
        wrap.appendChild(c);
      });
      return wrap;
    });
  };

  /* The child record's temporary pickup people (2026-11-24): name, photo, relationship, one pickup or dates, state.
     GET /provider/temp-pickups/child/{id}/all (the same Visibility guard as Cancel). opts.cancel adds Cancel. */
  KT.tempPickupList = function (host, childId, opts) {
    opts = opts || {};
    var A = (window.KT && KT.Api) || window.Api;
    if (!host || !A) return;
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var hdr = function () { var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') }; var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; return h; };
    var e = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var ST = { active: ['Active', '#047857', '#ECFDF5'], upcoming: ['Starts later', '#1E40AF', '#EFF6FF'], used: ['Used', '#475569', '#F1F5F9'], expired: ['Expired', '#475569', '#F1F5F9'], cancelled: ['Cancelled', '#475569', '#F1F5F9'], locked: ['Locked (wrong code)', '#B91C1C', '#FEF2F2'] };
    A.get('/provider/temp-pickups/child/' + childId + '/all').then(function (r) {
      var list = (r && r.passes) || [];
      if (!host.isConnected) return;
      if (!list.length) { host.innerHTML = ''; return; }
      host.innerHTML = '<div style="font-size:11.5px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.3px;margin:14px 0 6px;">🎫 Temporary pickup people</div>' + list.map(function (p) {
        var st = ST[p.state] || ST.expired;
        return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #EEF2F5;font-size:13px;">'
          + '<img data-tpl-img="' + p.id + '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;background:#E2E8F0;flex:0 0 auto;">'
          + '<div style="min-width:0;flex:1;"><div style="font-weight:700;color:#0F172A;">' + e(p.full_name) + (p.relationship ? ' · ' + e(p.relationship) : '') + '</div>'
          + '<div style="color:#64748B;font-size:12px;">' + e(p.window) + ' · ' + (p.one_time ? 'one time' : 'date range') + ' · added by ' + e(p.added_by || 'a parent') + (p.last_used ? ' · used ' + e(p.last_used) : '') + '</div></div>'
          + '<span style="font-size:11.5px;font-weight:700;color:' + st[1] + ';background:' + st[2] + ';border-radius:999px;padding:3px 10px;white-space:nowrap;">' + st[0] + '</span>'
          + (opts.cancel && (p.state === 'active' || p.state === 'upcoming') ? '<button type="button" data-tpl-cancel="' + p.id + '" style="height:28px;padding:0 10px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#B91C1C;font-weight:800;font-size:12px;cursor:pointer;">Cancel</button>' : '')
          + '</div>';
      }).join('');
      host.querySelectorAll('[data-tpl-img]').forEach(function (img) {
        var p = list.filter(function (x) { return String(x.id) === img.getAttribute('data-tpl-img'); })[0];
        if (!p || !p.has_photo) return;
        fetch(base + '/provider/temp-pickups/' + p.id + '/photo', { headers: hdr() }).then(function (x) { return x.ok ? x.blob() : null; })
          .then(function (b) { if (b) img.src = URL.createObjectURL(b); }).catch(function () {});
      });
      host.querySelectorAll('[data-tpl-cancel]').forEach(function (b) {
        b.onclick = function () {
          var p = list.filter(function (x) { return String(x.id) === b.getAttribute('data-tpl-cancel'); })[0];
          var go = function () {
            b.disabled = true;
            A.post('/provider/temp-pickups/' + p.id + '/cancel', {}).then(function () {
              if (KT.Dom && KT.Dom.toast) KT.Dom.toast(p.full_name + ' can no longer pick up. The room and family have been told.', 'success');
              KT.tempPickupList(host, childId, opts);
            }, function (x) { b.disabled = false; if (KT.Dom && KT.Dom.toast) KT.Dom.toast((x && x.message) || 'Could not cancel', 'error'); });
          };
          if (KT.confirm) KT.confirm({ title: 'Cancel ' + p.full_name + '\u2019s pickup?', description: 'Their code stops working at once, and if they try it everyone is alerted.', okLabel: 'Cancel pickup', cancelLabel: 'Keep it' }).then(function (y) { if (y) go(); });
          else go();
        };
      });
    }).catch(function () {});
  };

  /* ONE-TAP "Scan a pickup pass" (2026-11-24, Anthony: "the one tap on the educator view should have the QR or the
     Camera as an option"). No child is picked first: POST /provider/temp-pickups/lookup {code} finds the pass among
     the children this staff member looks after. Each child still to go gets "Check out {child}", which runs the SAME
     path as the sign-out section: verify (code checked, 3-minute ticket) then POST /provider/check-out (pickup
     recorded, one-time pass closed, parent told). No camera => a friendly line and a type-the-code box. */
  KT.pickupPassScan = function () {
    var A = (window.KT && KT.Api) || window.Api;
    if (!A) return;
    var accept = function (v) { return /^(KTPASS\.)?\d{6}$/.test(String(v).trim()); };
    var digits = function (v) { return String(v || '').replace(/\D/g, '').slice(-6); };
    var e = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var hdr = function () { var h = { Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '') }; var a = sessionStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; return h; };
    var body = null;
    var open = function () {
      if (body && body.isConnected) return body;
      body = document.createElement('div');
      body.id = 'kt-tp-scan';
      body.style.cssText = 'color:#0F172A;font-size:14px;';
      body.innerHTML = '<div data-msg role="status" style="font-weight:800;font-size:13.5px;min-height:18px;margin-bottom:8px;"></div>'
        + '<div data-res></div>'
        + '<div data-type style="display:none;margin-top:10px;"><label style="display:block;font-size:12.5px;font-weight:800;color:#334155;margin-bottom:4px;">Type the 6-digit code from their pass</label>'
        + '<div style="display:flex;gap:8px;"><input data-code inputmode="numeric" maxlength="6" autocomplete="off" aria-label="Pickup code" style="flex:1;min-width:0;height:34px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:17px;letter-spacing:3px;">'
        + '<button type="button" data-find style="height:34px;padding:0 14px;border-radius:8px;border:0;background:#1F6080;color:#fff;font-weight:800;cursor:pointer;">Find pass</button></div>'
        + '<button type="button" data-rescan style="margin-top:8px;height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1F6080;font-weight:800;font-size:12.5px;cursor:pointer;">📷 Scan again</button></div>';
      var Sh = KT.Shell || window.Shell;
      if (Sh && Sh.Modal && Sh.Modal.open) {
        var h = Sh.Modal.open({ title: '🎫 Pickup pass', body: body, actions: [{ label: 'Close' }] });
        if (KT.pushOverlay && h && h.backdrop) KT.pushOverlay(h.backdrop, function () { try { h.close(); } catch (x) {} });
      } else { document.body.appendChild(body); }
      body.querySelector('[data-find]').onclick = function () {
        var c = digits(body.querySelector('[data-code]').value);
        if (c.length !== 6) { say('Enter the 6 digits from their pass.', false); return; }
        find(c);
      };
      body.querySelector('[data-code]').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') body.querySelector('[data-find]').click(); });
      body.querySelector('[data-rescan]').onclick = function () { scan(); };
      return body;
    };
    var say = function (t, ok) { var m = open().querySelector('[data-msg]'); m.textContent = t; m.style.color = ok === true ? '#047857' : ok === false ? '#B91C1C' : '#334155'; };
    var typeBox = function (on) { open().querySelector('[data-type]').style.display = on ? '' : 'none'; if (on) setTimeout(function () { try { body.querySelector('[data-code]').focus(); } catch (x) {} }, 40); };
    var find = function (code) {
      open().querySelector('[data-res]').innerHTML = '';
      say('Checking the pass…');
      A.post('/provider/temp-pickups/lookup', { code: code }).then(function (r) { show(r.pass, code); }, function (x) {
        var msg = (x && x.message) || 'Could not check that pass. Try again.';
        var data = (x && (x.data || x.body)) || {};
        say((data.kind === 'invalid' || /alerted/i.test(msg) ? '🚨 ' : '') + msg, false);
        typeBox(true);
      });
    };
    var show = function (p, code) {
      typeBox(false);
      say('✓ Valid pass. Check their photo ID matches.', true);
      var res = open().querySelector('[data-res]');
      res.innerHTML = '<div style="display:flex;gap:12px;align-items:flex-start;border:1px solid #E2E8F0;border-radius:12px;padding:12px;">'
        + '<img data-photo alt="Photo of ' + e(p.full_name) + '" style="width:88px;height:88px;border-radius:12px;object-fit:cover;background:#E2E8F0;flex:0 0 auto;">'
        + '<div style="min-width:0;flex:1;"><div style="font-weight:800;font-size:16px;">' + e(p.full_name) + '</div>'
        + '<div style="font-size:12.5px;color:#475569;margin-top:2px;">' + e(p.relationship || 'Temporary pickup') + ' · ' + e(p.window) + '</div>'
        + '<div style="font-size:12px;color:#64748B;margin-top:2px;">Added by ' + e(p.added_by || 'a parent') + (p.mode === 'signin' ? ' · may also sign out with One-Tap' : '') + '</div></div></div>'
        + '<div data-kids style="margin-top:10px;display:grid;gap:8px;"></div>';
      if (p.has_photo) fetch(base + '/provider/temp-pickups/' + p.id + '/photo', { headers: hdr() }).then(function (x) { return x.ok ? x.blob() : null; })
        .then(function (b) { if (b) res.querySelector('[data-photo]').src = URL.createObjectURL(b); }).catch(function () {});
      var kids = res.querySelector('[data-kids]');
      (p.children || []).forEach(function (k) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;justify-content:space-between;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;';
        row.innerHTML = '<div style="font-weight:800;">' + e(k.name) + '<div data-st style="font-weight:600;font-size:12px;color:#64748B;">'
          + (k.at_centre ? 'At Centre' : k.out_at ? 'Signed out at ' + e(k.out_at) : 'Not at Centre') + '</div></div>';
        if (k.at_centre && k.room_id) {
          var b = document.createElement('button');
          b.type = 'button'; b.textContent = 'Check out ' + k.name;
          b.style.cssText = 'height:34px;padding:0 14px;border-radius:9px;border:0;background:#1F6080;color:#fff;font-weight:800;font-size:13px;cursor:pointer;white-space:nowrap;';
          b.onclick = function () {
            b.disabled = true; b.textContent = 'Checking out…';
            A.post('/provider/temp-pickups/verify', { child_id: k.id, code: code, pass_id: p.id })
              .then(function () { return A.post('/provider/check-out', { child_id: k.id, room_id: k.room_id }); })
              .then(function () {
                b.remove();
                row.querySelector('[data-st]').textContent = '✓ Signed out with ' + p.full_name;
                row.querySelector('[data-st]').style.color = '#047857';
                say('✓ ' + k.name + ' is signed out. The family has been told.', true);
                try { window.dispatchEvent(new CustomEvent('kt:check-event', { detail: { child_id: k.id, type: 'check_out' } })); } catch (x) {}
              }, function (x) {
                b.disabled = false; b.textContent = 'Check out ' + k.name;
                say('🚨 ' + ((x && x.message) || 'Could not check out. Don\'t release ' + k.name + ' yet.'), false);
              });
          };
          row.appendChild(b);
        }
        kids.appendChild(row);
      });
      if (!(p.children || []).some(function (k) { return k.at_centre; })) say('Valid pass, but no child on it is at the centre right now.', null);
    };
    var scan = function () {
      if (!KT.scanQR) { say('Scanning isn\'t available here.', false); typeBox(true); return; }
      KT.scanQR({ prompt: 'Point the camera at the QR code on their pickup pass.', accept: accept }).then(function (v) {
        if (!v) { if (body && body.isConnected) typeBox(true); return; }
        find(digits(v));
      }, function (err) { say((err && err.message) || 'Could not open the camera.', false); typeBox(true); });
    };
    scan();
  };

  KT.warnManualCheck = function (d) {
    d = d || {};
    var name = String(d.name || 'this child'), going = d.going === 'out' ? 'out' : 'in';
    var box = document.createElement('div');
    box.className = 'kt-cf-warn';
    var ico = document.createElement('span'); ico.className = 'kt-cf-warn-ico'; ico.setAttribute('aria-hidden', 'true'); ico.textContent = '⚠️';
    var col = document.createElement('div');
    var big = document.createElement('p'); big.className = 'kt-cf-warn-big';
    big.textContent = 'Not using One-Tap';
    var sub = document.createElement('p'); sub.className = 'kt-cf-warn-sub';
    sub.textContent = (going === 'in' ? 'Parents should check in' : 'Parents should check out')
      + ' by scanning the centre’s QR code with their KiddieTrac app. Use this only if they can’t (no phone, dead battery).';
    var note = document.createElement('p'); note.className = 'kt-cf-warn-sub';
    note.style.fontWeight = '500';
    note.textContent = going === 'in' ? name + ' will be marked at the centre and the family notified.' : name + ' will be marked as picked up and the family notified.';
    col.appendChild(big); col.appendChild(sub); col.appendChild(note);
    box.appendChild(ico); box.appendChild(col);
    /* TEMPORARY PICKUP (2026-11-24, App\Support\TempPickups): someone with a pass for this child today shows here,
       with their photo. Staff type their code; a match lets the check-out record it (the server keeps a 3-minute
       ticket), a miss or an old pass raises the high alert. "Not the right person" raises it too. */
    if (going === 'out' && d.child_id && !d.__tp) {
      return KT.tempPickupPanel(d.child_id, name).then(function (panel) {
        if (panel) { col.appendChild(panel); }
        return KT.confirm({
          title: 'Check out ' + name + '?', keepHeaderTitle: true, extra: box, tone: 'warning', alarm: true,
          okLabel: 'Check out', cancelLabel: 'Cancel',
        });
      });
    }
    return KT.confirm({
      title: (going === 'in' ? 'Check in ' : 'Check out ') + name + '?',
      keepHeaderTitle: true, extra: box, tone: 'warning', alarm: true,
      okLabel: going === 'in' ? 'Check in anyway' : 'Check out anyway', cancelLabel: 'Cancel',
    });
  };

  // Override window.prompt + window.confirm with nicer modals on dashboard
  if (/dashboard|kiddietrac\.com/.test(location.href)) {
    const _p = window.prompt;
    window.prompt = function (msg, defaultValue) {
      // Synchronous behaviour can't be replicated — fall back to native if caller expects sync return.
      // BUT — most existing callsites use await/then patterns elsewhere; we expose the modal but keep native for safety.
      return _p.call(window, msg, defaultValue || '');
    };
  }

  // ============================ Table polish: sort + CSV + sticky header + ISO date format ============================
  /**
   * Reformat a date-looking table cell — in the AGENCY's timezone.
   *
   * This used to call toLocaleDateString/toLocaleTimeString with `undefined` as the
   * locale-and-zone, i.e. the VIEWER'S DEVICE zone, and it parsed the zone-less
   * "2026-05-20 12:34:56" MySQL form with `new Date(...)`, which reads it as local
   * time. So a UTC value stored by the server was mis-parsed AND then re-rendered
   * in whatever zone the laptop happened to be in — wrong twice, and wrong on the
   * one screen that must never drift. Every displayed time in the portal is
   * agency-local; kt-tz.js owns that zone.
   *
   * Non-dates are returned untouched: this runs over EVERY cell in EVERY table, so
   * it has to be a no-op for anything that isn't a timestamp.
   */
  function fmtDateMaybe(s) {
    if (typeof s !== 'string') return s;
    var zone;
    try { zone = (window.KT && KT.tz && KT.tz()) || undefined; } catch (e) { zone = undefined; }

    // A US agency reads "Oct 10, 2026 09:30 AM" (kt-term.js sets kt_spelling); others keep the device's form.
    var loc; try { loc = sessionStorage.getItem('kt_spelling') === 'us' ? 'en-US' : undefined; } catch (e) { loc = undefined; }
    var dateOpts = { timeZone: zone, year: 'numeric', month: 'short', day: 'numeric' };
    var timeOpts = { timeZone: zone, hour: '2-digit', minute: '2-digit' };

    // ISO with time: 2026-05-20T12:34:56[.mmm][Z|±hh:mm]
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) {
      try {
        // No zone marker means the server sent UTC — say so explicitly.
        var iso = /(Z|[+-]\d{2}:?\d{2})$/.test(s) ? s : s + 'Z';
        var d = new Date(iso);
        if (isNaN(d)) return s;
        return d.toLocaleDateString(loc, dateOpts) + ' ' + d.toLocaleTimeString(loc, timeOpts);
      } catch (e) { return s; }
    }
    // Date only: 2026-05-20. A bare date has no time to shift, so render it as
    // written rather than converting it into the previous day.
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
      try {
        var parts = s.split('-');
        var dd = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
        if (isNaN(dd)) return s;
        return dd.toLocaleDateString(loc, { timeZone: 'UTC', year: 'numeric', month: 'short', day: 'numeric' });
      } catch (e) { return s; }
    }
    /* MySQL datetime: 2026-05-20 12:34:56 — zone-less, therefore UTC. WITH SECONDS only (2026-10-28): a raw DATETIME
       always has them, while "2026-10-10 09:30" is a time a screen already put in the agency's zone (the drill log's
       `local`), and reading that as UTC moved it again — a 9:30 drill in Long Beach showed as 2:30 a.m. */
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)) {
      try {
        var d2 = new Date(s.replace(' ', 'T') + 'Z');
        if (isNaN(d2)) return s;
        return d2.toLocaleDateString(loc, dateOpts) + ' ' + d2.toLocaleTimeString(loc, timeOpts);
      } catch (e) { return s; }
    }
    return s;
  }

  function polishTable(table) {
    if (table.dataset.ktPolished) return;
    table.dataset.ktPolished = '1';
    const tbody = table.querySelector('tbody');
    const thead = table.querySelector('thead');
    if (!tbody || !thead) return;
    if (tbody.children.length < 1) return;

    // ---- ISO date auto-format ----
    Array.from(tbody.children).forEach(row => {
      Array.from(row.children).forEach(td => {
        // Skip cells with HTML children (pills, bars, badges)
        if (td.children.length) return;
        const text = (td.textContent || '').trim();
        if (text.length > 6 && text.length < 35) {
          const fmt = fmtDateMaybe(text);
          if (fmt !== text) {
            td.dataset.ktIso = text;
            td.textContent = fmt;
          }
        }
      });
    });

    // ---- Sticky header ----
    Array.from(thead.querySelectorAll('th')).forEach(th => {
      th.style.position = 'sticky';
      th.style.top = '0';
      th.style.zIndex = '5';
    });

    // ---- Sortable headers ----
    const ths = Array.from(thead.querySelectorAll('th'));
    /* Every header's padding read before any header is written (phone speed sweep 2026-10-30): reading it after
       the previous header's write re-calculated the page's styles once per column. */
    const _prs = ths.map(th => parseFloat(getComputedStyle(th).paddingRight) || 0);
    ths.forEach((th, _i) => {
      const text = (th.textContent || '').trim();
      if (!text || text === '') return;
      if (th.querySelector('button')) return; // action header
      th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      // Add a sort indicator span
      /* PINNED TO THE CORNER, not appended after the header's content.
         This used to be a plain inline span added as the th's LAST child. That is fine
         for a header that is just text, but plenty of headers are built from <div>s —
         a day name over a count, a label over a status pill — and a block-level sibling
         pushes the arrows onto a line of their own UNDERNEATH the header, which is the
         stray mark showing up below the column titles.
         Positioning it against the th works whatever the header is made of: the th is
         already position:sticky just above, so it is the containing block. */
      const indicator = document.createElement('span');
      indicator.className = 'kt-sort-indicator';
      indicator.style.cssText = 'position:absolute;right:6px;top:6px;opacity:0.3;font-size:10px;'
        + 'line-height:1;pointer-events:none;';
      indicator.textContent = '⇅';
      // Only widen a header that would otherwise have its text run under the arrows.
      if (_prs[_i] < 16) { th.style.paddingRight = '16px'; }
      th.appendChild(indicator);
      th.addEventListener('click', () => {
        const currentDir = th.dataset.ktSort || '';
        // Reset all
        ths.forEach(t => {
          t.dataset.ktSort = '';
          const i = t.querySelector('.kt-sort-indicator');
          if (i) { i.textContent = '⇅'; i.style.opacity = '0.3'; }
        });
        const dir = currentDir === 'asc' ? 'desc' : 'asc';
        th.dataset.ktSort = dir;
        indicator.textContent = dir === 'asc' ? '↑' : '↓';
        indicator.style.opacity = '1';

        /* THE COLUMN IS WHERE THIS HEADER IS *NOW* (2026-09-24).

           Anthony: "when I click on the provider name it should sort the providers
           based on their names and the status doesnt sort based on the status types."

           It was sorting the column to the LEFT of whichever one you clicked. colIdx
           was captured when the handler was bound, and kt-polish-v2.js prepends a
           select-all checkbox cell to the header and to every row afterwards
           (`row.insertBefore(td, row.firstChild)`). Every column shifts one to the
           right; the handlers keep pointing at the old seat. On the immunization
           roster, clicking Provider sorted Family, and clicking Status sorted Uploaded
           - which is mostly empty, so it read as "status does not sort at all".

           It only showed where the checkbox column exists, which is why the same table
           sorts correctly on an agency that has not got one.

           Read live from the DOM instead. Any column inserted later - a checkbox now,
           anything else next time - moves the header and the index together. */
        const liveCol = Array.prototype.indexOf.call(th.parentElement.children, th);
        const rows = Array.from(tbody.children);
        rows.sort((a, b) => {
          const r = compareKeys(cellSortKey(a.children[liveCol]), cellSortKey(b.children[liveCol]));
          return dir === 'asc' ? r : -r;
        });
        rows.forEach(r => tbody.appendChild(r));
      });
    });

    // ---- CSV export button (added to existing kt-table-filter wrap if present) ----
    // The table may be wrapped in a .kt-tbl-scroll container; the toolbar sits
    // above that wrapper, so look there too.
    const _sc = table.closest('.kt-tbl-scroll');
    const wrap = _sc ? _sc.previousElementSibling : table.previousElementSibling;
    // Top CSV button retired — CSV/Excel/PDF now live in the bottom export bar
    // (kt-table-export.js) for consistency with the Reports section.
    if (false && wrap && wrap.classList.contains('kt-table-filter')) {
      if (!wrap.querySelector('.kt-csv-btn')) {
        const csvBtn = document.createElement('button');
        csvBtn.className = 'kt-csv-btn';
        csvBtn.title = 'Download visible rows as CSV';
        csvBtn.style.cssText = 'background:#10B981;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;';
        csvBtn.textContent = '⤓ CSV';
        csvBtn.onclick = () => exportTableAsCsv(table);
        const right = wrap.children[1] || wrap;
        right.insertBefore(csvBtn, right.firstChild);
      }
    }
  }

  /* COMPARING A NUMBER WITH A DASH (2026-09-24).

     cellSortKey returns a NUMBER for a cell that looks numeric and a STRING for one
     that does not, and a column full of money with a few blanks in it contains both.
     `1500 < '\u2014'` is false, and so is `1500 > '\u2014'` - a dash coerces to NaN and
     every comparison against it answers false. The comparator read that as "equal",
     so the rows around each blank kept whatever order they were already in and the
     column came out unsorted rather than merely oddly sorted. Found on the children
     roster's Fee column, which runs $0.00, a dash, $1500.00.

     So: numbers against numbers numerically, text against text with localeCompare
     (which also gets names with accents right, where < and > do not), and a number
     before text when the two meet. Blanks and dashes are text, so they gather at one
     end instead of scattering through the middle. */
  function compareKeys(av, bv) {
    const an = typeof av === 'number' && !isNaN(av);
    const bn = typeof bv === 'number' && !isNaN(bv);
    if (an && bn) { return av - bv; }
    if (an) { return -1; }
    if (bn) { return 1; }
    return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' });
  }

  function cellSortKey(td) {
    if (!td) return '';
    /* AN EXPLICIT KEY BEATS WHATEVER THE CELL LOOKS LIKE.

       textContent is the wrong thing to sort on whenever a cell holds more than one
       line: three stacked form titles concatenate into "Consent formPhoto formMedical
       form", and a badge reading "✓ 2 of 3" sorts under the tick. data-kt-sort lets
       the screen say what the column actually means, once, at render time.
       data-kt-iso stays supported — it is the same idea, named for its first use.
       (Anthony, 2026-09-09) */
    if (td.dataset.ktSort) return td.dataset.ktSort;
    // Prefer the original ISO if we stashed one
    if (td.dataset.ktIso) return td.dataset.ktIso;
    const txt = (td.textContent || '').trim();
    // Pure number
    if (/^-?\$?[\d,]+(\.\d+)?%?$/.test(txt)) return parseFloat(txt.replace(/[\$,%]/g, '')) || 0;
    return txt.toLowerCase();
  }

  function exportTableAsCsv(table) {
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    const headers = Array.from(thead.querySelectorAll('th'))
      .map(th => th.cloneNode(true))
      .map(th => { const i = th.querySelector('.kt-sort-indicator'); if (i) i.remove(); return (th.textContent || '').trim().replace(/\s+/g, ' '); });
    const lines = [headers.map(csvCell).join(',')];
    Array.from(tbody.children).forEach(row => {
      if (row.style.display === 'none') return; // skip hidden (filtered) rows
      const cells = Array.from(row.children).map(td => {
        if (td.dataset.ktIso) return td.dataset.ktIso;
        return (td.textContent || '').trim().replace(/\s+/g, ' ');
      });
      lines.push(cells.map(csvCell).join(','));
    });
    // BOM for Excel
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'table-' + ((window.KT && KT.agencyToday) ? KT.agencyToday() : new Date().toISOString().slice(0, 10)) + '.csv';
    a.click();
  }
  function csvCell(s) {
    s = String(s == null ? '' : s);
    if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }


  /* ASK FOR IT, DON'T WAIT TO BE SWEPT.

     Both table enhancers (this one and kt-polish.js) find their work by sweeping, driven
     by hashchange and by KT.sweepBus. That covers a screen you navigate TO. It does not
     cover a table that appears when you click a tab INSIDE a screen — no hash changes, so
     the only remaining chance is a bus tick, and the bus stops for the whole session once
     a tab has been backgrounded. The result is a table with no search box and no sortable
     headers, on a screen where the identical table two tabs over has both.

     So a screen that has just rendered a table can say so. Each enhancer pushes its sweep
     onto the same list and KT.enhanceTables() runs them all; the sweeps are already
     guarded against running twice on the same table, so calling this is free.
     (Anthony, 2026-09-09) */
  window.KT = window.KT || {};
  KT.tableEnhancers = KT.tableEnhancers || [];
  KT.tableEnhancers.push(function () { sweep(); });
  if (!KT.enhanceTables) {
    KT.enhanceTables = function () {
      (KT.tableEnhancers || []).forEach(function (f) { try { f(); } catch (e) {} });
    };
  }

  function sweep() {
    // EVERY table under #appMain, matching kt-table-filter's sweep. Gating on
    // [data-kt-pretty] meant sortable columns only appeared on screens that opted
    // in — the Forms Manager tables (and every other unflagged screen) got a search
    // box from kt-table-filter but no sortable headers, which reads as a bug.
    document.querySelectorAll('#appMain table').forEach(polishTable);
  }
  window.addEventListener('hashchange', () => setTimeout(sweep, 400));
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweep) : setInterval(sweep, 4000);
  setTimeout(sweep, 900);

  // ============================ "Loading…" → skeleton ============================
  /* How many skeletons this navigation may paint. A stuck placeholder is a cosmetic
     problem; a sweep that repaints forever is a locked browser, and the difference
     between them is only ever a bug somebody has not found yet. Reset on navigation. */
  var skelBudget = 12;
  window.addEventListener('hashchange', function () { skelBudget = 12; });

  function sweepLoading() {
    if (skelBudget <= 0) return;
    document.querySelectorAll('[data-kt-pretty]').forEach(host => {
      const text = (host.innerHTML || '').trim();
      // Crude detector for the "Loading…" placeholder pattern used across v22p51-59
      if (text.length >= 200 || !/Loading|Computing|Scanning/i.test(text)) return;

      /* Replace the PLACEHOLDER, never the host.
         This used to do `KT.skeleton(host, 6)`, and host is #appMain — so it wiped out
         whatever the screen had built. Screens render into a container they captured
         BEFORE awaiting their data:

             const content = Dom.el('div', { id: 'admin-tab-content' });
             wrap.appendChild(content);
             await renderFamiliesTab(content);   // appends "Loading families…", then awaits

         Wiping #appMain mid-await left `content` detached, so when the fetch resolved the
         screen rendered its rows into an orphan node — silently, because appending to a
         detached element is perfectly legal. The skeleton then sat there forever.

         It was intermittent because data-kt-pretty is only set 250ms after navigation
         (screen-v22p55 applyPrettyClass): a fast reply beat the sweep, a slow one lost to
         it — so it hit the biggest agencies most often. Reported on #admin-families,
         2026-09-01.

         Swapping only the placeholder's own contents leaves every container the screen is
         holding attached, and the screen's own Dom.clear() disposes of the skeleton when
         the data arrives. */
      /* Never the banner. Screens like Data retention put "Loading…" in the hero's own
         subtitle, so the first match is inside .kt-page-hero — and filling that with
         shimmer bars stretched the teal banner down the page. The hero is also written
         by normaliseBanners()/tidyOwnBanner(), which remove and rebuild it; a cosmetic
         sweep writing into an element another subsystem rewrites is how a render loop
         starts, and that code already needs a spend budget for the same reason. A
         subtitle reading "Loading…" is perfectly good as it stands. */
      let ph = null;
      const all = host.querySelectorAll('*');
      for (let i = 0; i < all.length; i++) {
        const el = all[i];
        if (el.children.length !== 0) continue;
        if (!/Loading|Computing|Scanning/i.test(el.textContent || '')) continue;
        if (el.closest('.kt-page-hero, .kt-hero, .page-header-v17, header, h1, h2, h3')) continue;
        ph = el; break;
      }
      /* No element of its own to take over — the text is a bare child of the host. Leave
         it alone: a plain "Loading…" is a worse look than a skeleton, and a far better one
         than a screen that never arrives. */
      if (!ph || ph.dataset.ktSkeletonDone) return;
      ph.dataset.ktSkeletonDone = '1';
      skelBudget--;
      // The placeholder centres its text in 40px of padding; the skeleton brings its own.
      ph.style.padding = '0';
      if (KT.skeleton) KT.skeleton(ph, 6);
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepLoading) : setInterval(sweepLoading, 4000);

  // ============================ Mobile sidebar toggle ============================
  function mobileSidebar() {
    // v22p79: REMOVED. This top-left ☰ button (z-index 99999) covered the
    // KiddieTrac logo, used a stale selector (.sidebar/#sidebar — the real
    // element is #appSidebar) so it did nothing, and floated above modals.
    // Mobile navigation is now the bottom-nav "More" drawer (kt-polish-v4.js).
    var ex = document.getElementById('kt-mobile-toggle');
    if (ex) ex.remove();
  }
  setTimeout(mobileSidebar, 1500);
  window.addEventListener('resize', () => setTimeout(mobileSidebar, 200));

  // ============================ Helpers ============================
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  /* ============================ Search: commit, don't stream ============================

     A SEARCH box replaces what the view is showing — often by asking the server for
     it. Running that on every keystroke rearranges the page under someone who is still
     typing, and fires one request per letter. So a search is committed:

       Enter          the obvious one
       blur/change    they typed and moved on, or clicked the button beside it
       search event   the native clear (x) in a type=search field, so emptying it
                      restores the full list without needing Enter as well

     A FILTER is a different control and is deliberately NOT routed through here:
     kt-list-controls and kt-table-filter hide rows already on the screen, nothing is
     fetched or replaced, and instant is the correct behaviour there.

     One definition rather than a debounce timer per screen — eleven of those drift
     apart, which is how this codebase ended up with eight differently-named tenant
     guards.

     Returns a function that commits programmatically, for a Search button to call. */

  /* ── An invoice that is not due yet is SCHEDULED ──────────────────────────────
     Anthony, 2026-09-04: "for invoices that are not due yet and show as open mark
     these as SCHEDULED - full sweep to find all areas to make this change".

     Only statuses that read as "owed right now" convert. Deliberately excluded:
       partial  — real money has already been received against it; that is the fact
                  worth showing, and it outranks the calendar.
       draft    — not issued at all, which is a different thing from not yet due and
                  must not be hidden behind a word that sounds deliberate.
       overdue  — past its due date by definition; if one ever says otherwise the data
                  is wrong and quietly relabelling it would hide that.

     Date-only, against the AGENCY's today. A due date is a calendar day, not an
     instant: run it through a timezone conversion and it names the day before. */
  var KT_OPEN_NOW = { open: 1, sent: 1, unpaid: 1, issued: 1, outstanding: 1 };

  /* Today, worked out at most once a minute.

     This is called once PER INVOICE ROW, and KT.agencyToday() builds a fresh
     Intl.DateTimeFormat every time — measured on production at 67us a call, of which
     41us is constructing the formatter. Across a few hundred rows that is tens of
     milliseconds of pure repetition to answer a question whose answer changes once a
     day. Cached for a minute, which is far shorter than the thing it describes. */
  var _ktToday = '', _ktTodayAt = 0;
  function ktTodayCached() {
    var now = Date.now();
    if (!_ktToday || now - _ktTodayAt > 60000) {
      try { _ktToday = (KT.agencyToday && KT.agencyToday()) || ''; } catch (e) { _ktToday = ''; }
      _ktTodayAt = now;
    }
    return _ktToday;
  }


  /* KT.signaturePadInline moved to kt-signature-pad.js (2026-11-06), beside the popup pad, so
     both share the saved signature and the public signing pages get it too. */

  KT.invoiceStatus = function (status, dueAt) {
    var s = String(status == null ? '' : status).trim().toLowerCase();
    if (!KT_OPEN_NOW[s] || !dueAt) return s;
    try {
      var due = String(dueAt).trim().slice(0, 10);          // YYYY-MM-DD, no conversion
      if (!/^\d{4}-\d{2}-\d{2}$/.test(due)) return s;
      var today = ktTodayCached();
      if (!today) return s;                                  // unknown today: say nothing new
      return due > today ? 'scheduled' : s;
    } catch (e) { return s; }
  };

  /** The same answer as a yes/no, for callers that only need to style a row. */
  KT.isScheduled = function (status, dueAt) {
    return KT.invoiceStatus(status, dueAt) === 'scheduled';
  };

  /* ONE INVOICE STATUS PILL, FOR EVERY SCREEN THAT SHOWS ONE (2026-09-17).

     Anthony: "use colourized pills for status on accounting and payment schedules to make
     it easier to read."

     Accounting and the payment schedules each had their own map. They had already drifted
     — one said "Partial" where the other said "Partly paid", one rendered a grey inline
     span for a draft and the other a blue `kt-pill-info` — so the same invoice looked like
     two different states depending on which screen you opened. A third copy was about to
     be written for the pills, which is how the first two happened.

     It lives here beside KT.invoiceStatus because that is where the status VOCABULARY
     already lives: this is the same answer in colour.

     THE COLOURS CARRY THE MEANING, not just decoration:
       red    something is late
       amber  money has partly moved, or come back
       blue   issued and owed
       indigo issued, not due yet — calm, because nothing is wrong
       slate  nothing has happened yet
       green  settled
       grey   withdrawn

     Callers pass the raw status and the due date; the scheduled/overdue distinction is
     derived here rather than by each screen ([[kiddietrac-invoice-scheduled-status]]). */
  var KT_PILL = {
    paid:      { bg: '#DCFCE7', fg: '#166534', bd: '#86EFAC', t: 'Paid' },
    partial:   { bg: '#FEF3C7', fg: '#92400E', bd: '#FCD34D', t: 'Partly paid' },
    refunded:  { bg: '#FEF3C7', fg: '#92400E', bd: '#FCD34D', t: 'Refunded' },
    overdue:   { bg: '#FEE2E2', fg: '#991B1B', bd: '#FCA5A5', t: 'Overdue' },
    scheduled: { bg: '#EEF2FF', fg: '#3730A3', bd: '#C7D2FE', t: 'Scheduled' },
    draft:     { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: 'Pending' },
    upcoming:  { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: 'Upcoming' },
    void:      { bg: '#E5E7EB', fg: '#4B5563', bd: '#D1D5DB', t: 'Void' },
    cancelled: { bg: '#E5E7EB', fg: '#4B5563', bd: '#D1D5DB', t: 'Void' },
    // Four spellings of one state, across the three tables the ledger unions.
    sent:        { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    open:        { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    unpaid:      { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    issued:      { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
    outstanding: { bg: '#E0F2FE', fg: '#075985', bd: '#7DD3FC', t: 'Issued' },
  };

  /** The resolved {bg,fg,bd,t} for a status, never null. */
  KT.invoicePillSpec = function (status, dueAt) {
    var s = KT.invoiceStatus(status, dueAt);
    var hit = KT_PILL[s];
    if (hit) { return hit; }

    /* A status nobody anticipated still reads as a label. Printing the raw database
       value is what made lowercase `sent` and `draft` sit next to a proper "Paid". */
    var label = String(status == null ? '' : status).replace(/[_-]+/g, ' ').trim();
    label = label ? label.charAt(0).toUpperCase() + label.slice(1) : '—';

    return { bg: '#F1F5F9', fg: '#475569', bd: '#CBD5E1', t: label };
  };

  /** Ready-to-insert HTML. `title` adds a tooltip; `min` sets a minimum width so a
   *  column of pills lines up instead of ragging by word length. */
  KT.invoicePill = function (status, dueAt, opts) {
    var b = KT.invoicePillSpec(status, dueAt);
    var o = opts || {};
    var esc = function (v) {
      return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    };

    return '<span class="kt-inv-pill"' + (o.title ? ' title="' + esc(o.title) + '"' : '')
      + ' style="display:inline-block;background:' + b.bg + ';color:' + b.fg
      + ';border:1px solid ' + b.bd + ';padding:2px 10px;border-radius:999px;font-size:11px;'
      + 'font-weight:800;letter-spacing:.02em;white-space:nowrap;text-align:center;'
      + (o.min === false ? '' : 'min-width:74px;')
      + '">' + esc(b.t) + '</span>';
  };

  KT.onSearchCommit = function (input, run) {
    if (!input || typeof run !== 'function') { return function () {}; }
    var last = null;

    function commit() {
      var v = input.value;
      /* Committing the same text twice is a no-op. Enter, then blur on the way to
         clicking something, would otherwise run the same search twice. */
      if (v === last) { return; }
      last = v;
      run(v);
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); commit(); }
    });
    input.addEventListener('change', commit);
    // Fires on the native clear (x). Harmless where unsupported.
    input.addEventListener('search', commit);

    return commit;
  };

  /* A magnifier button that commits the search beside it. Screens that want a visible
     control rather than only Enter get the same one, so it looks the same everywhere. */
  KT.searchButton = function (commit, label) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label || '🔍 Search';
    b.title = 'Search (or press Enter)';
    b.style.cssText = 'padding:8px 12px;border:1px solid #CBD5E1;background:#fff;color:#334155;'
      + 'border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;';
    b.addEventListener('click', function () { commit(); });

    return b;
  };

  /* ═════════════════════════════════════════════════════════════════
     KT.LedgerUI — the account-ledger visual language, shared.

     screen-account-ledgers.js defined these for itself and they are the look
     My Pay was asked to match. One definition, so a change to the tile or the
     palette reaches both screens instead of only the one being edited.
     ═════════════════════════════════════════════════════════════════ */
  var LC = {
    ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', line: '#EEF2F6', rule: '#E2E8F0',
    accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C', out: '#4338CA'
  };

  function lesc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* en-CA is PINNED. `undefined` follows the browser's locale, so the same CAD
     amount renders '$1,234.00' for one member of staff and 'CA$1,234.00' for the
     next. */
  function lmoney(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }
  function lmoney0(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD', maximumFractionDigits: 0 }).format(n); }
    catch (e) { return '$' + Math.round(n); }
  }

  /* A KPI is a number AND its shape: a figure with no sub-line makes the reader
     work out for themselves whether it is good news. Every tile says what it means. */
  function lkpi(label, value, tint, sub) {
    /* min-width:0 — a grid child defaults to min-width:auto, so anything wide inside
       widens the TRACK instead of scrolling in its own box. */
    return '<div class="kt-card" style="min-width:0;padding:14px 16px;--kt-edge:' + tint + ';">'
      + '<div style="font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:' + LC.muted + ';">' + lesc(label) + '</div>'
      + '<div style="font-size:20px;font-weight:800;margin-top:5px;color:' + tint + ';font-variant-numeric:tabular-nums;">' + lesc(value) + '</div>'
      + (sub ? '<div style="font-size:11.5px;color:' + LC.muted + ';margin-top:3px;">' + sub + '</div>' : '')
      + '</div>';
  }

  function lcard(title, hint, inner) {
    return '<div class="kt-card" style="min-width:0;padding:16px 18px;">'
      + '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">'
      + '<h3 style="margin:0;font-size:14px;font-weight:800;color:' + LC.ink + ';">' + lesc(title) + '</h3>'
      + (hint ? '<span style="font-size:11.5px;color:' + LC.muted + ';">' + hint + '</span>' : '')
      + '</div>' + inner + '</div>';
  }

  function lempty(text) {
    return '<div style="padding:26px;text-align:center;color:' + LC.faint + ';font-size:13px;">' + lesc(text) + '</div>';
  }

  /* Responsive without a media query: auto-fit collapses the track count as the box
     narrows, so four tiles become two and then one on a phone. */
  function lgrid(cells, min) {
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(' + (min || 190) + 'px,1fr));gap:12px;">'
      + cells.join('') + '</div>';
  }

  /* One series of bars — "how much, week by week". Hand-rolled, the house pattern:
     this is not worth a charting library on a boot that already parses 162 files.

     Each bar carries a title for hover AND its value is readable from the axis label,
     because hover does not exist on the phone this is mostly read on. */
  function lbars(points, opts) {
    opts = opts || {};
    if (!points || !points.length) { return lempty(opts.emptyText || 'Nothing to chart yet.'); }
    var H = opts.height || 132;
    var tint = opts.tint || LC.accent;
    var fmtV = opts.format || lmoney;
    var max = 0;
    points.forEach(function (p) { max = Math.max(max, Number(p.value) || 0); });
    if (max <= 0) { max = 1; }

    var bars = points.map(function (p) {
      var v = Number(p.value) || 0;
      // A non-zero value always gets at least 2px, or a small week reads as nothing.
      var h = Math.max((v / max) * H, v > 0 ? 2 : 0);
      return '<div style="flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;">'
        + '<div style="height:' + H + 'px;display:flex;align-items:flex-end;width:100%;justify-content:center;" '
        + 'title="' + lesc(p.label + ' — ' + fmtV(v)) + '">'
        + '<div style="width:72%;max-width:26px;height:' + h.toFixed(1) + 'px;background:' + tint + ';'
        + 'opacity:' + (p.dim ? '.4' : '.85') + ';border-radius:3px 3px 0 0;"></div>'
        + '</div>'
        + '<div style="font-size:9.5px;color:' + LC.muted + ';white-space:nowrap;">' + lesc(p.label) + '</div>'
        + '</div>';
    }).join('');

    return '<div style="overflow-x:auto;"><div style="display:flex;align-items:flex-end;gap:5px;'
      + 'border-bottom:1px solid ' + LC.rule + ';padding-bottom:6px;min-width:' + (points.length * 34) + 'px;">'
      + bars + '</div></div>';
  }

  KT.LedgerUI = {
    C: LC, esc: lesc, money: lmoney, money0: lmoney0,
    kpi: lkpi, card: lcard, empty: lempty, grid: lgrid, bars: lbars
  };

  // Expose helpers
  KT.csv = exportTableAsCsv;
  // NOT KT.fmtDate: kt-tz.js owns that name and resolves the agency zone from
  // /auth/me. This file loads AFTER kt-tz.js, so exporting it here overwrote the
  // canonical helper with this cell-formatter and quietly pushed every caller
  // back onto the device timezone. Exposed under its own name instead.
  KT.fmtCellDate = fmtDateMaybe;
})(window);
