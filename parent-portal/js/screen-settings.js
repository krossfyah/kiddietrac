/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — parent Settings (2026-07-09).
   Profile (photo, name, phone, birthday, contact) + Security (change password,
   biometric sign-in toggle, quick-unlock PIN). Mobile-first; opened from the
   gear in the top-right of the mobile app. Registered for guardian:settings.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.setAttribute('style', attrs[k]);
      else if (k === 'html') e.innerHTML = attrs[k];
      else if (k in e) { try { e[k] = attrs[k]; } catch (x) { e.setAttribute(k, attrs[k]); } }
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  // A real sliding toggle switch — used for the on/off settings that were plain
  // pill buttons before (biometric, urgent alerts, autopay). .kt_set(bool) paints
  // it; .kt_disabled(bool) greys it out when the feature isn't available.
  function mkSwitch() {
    /* -webkit-appearance:none FIRST, for the same reason the checkbox toggle sets it:
       a <button> that does not opt out keeps the platform's native widget styling, and
       the iOS/APK WebView then sizes and paints it its own way while desktop Chrome
       renders it exactly as authored. Inline as well as in kt-consistency-polish.css,
       because this one control is worth not depending on a stylesheet for. */
    var s = el('button', { type: 'button', 'aria-pressed': 'false', style: '-webkit-appearance:none;appearance:none;position:relative;flex:0 0 auto;width:48px;height:28px;min-height:0;max-height:28px;box-sizing:border-box;border-radius:999px;border:none;background:#CBD5E1;cursor:pointer;padding:0;font:inherit;transition:background .18s ease;' });
    var knob = el('span', {});
    knob.style.cssText = 'position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.28);transition:transform .18s ease;';
    s.appendChild(knob);
    s.kt_set = function (on) { s.setAttribute('aria-pressed', on ? 'true' : 'false'); s.style.background = on ? '#159FB4' : '#CBD5E1'; knob.style.transform = on ? 'translateX(20px)' : 'translateX(0)'; };
    s.kt_disabled = function (dis) { s.disabled = !!dis; s.style.opacity = dis ? '.4' : '1'; s.style.cursor = dis ? 'default' : 'pointer'; };
    return s;
  }
  var CARD = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:16px;margin-bottom:14px;';
  var LABEL = 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#475569;margin:0 0 5px;text-transform:uppercase;';
  var INPUT = 'width:100%;box-sizing:border-box;padding:12px 14px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:11px;background:#fff;color:#0D1B2A;';
  var SECT = 'font-size:15px;font-weight:800;color:#0f172a;margin:0 0 12px;';

  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function absUrl(u) { if (!u) return u; if (/^https?:\/\//.test(u)) return u; return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
  function cachedUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }

  /* ctx.role is the role the person is working as RIGHT NOW — the shell resolves it,
     honouring view-as. Not the same question as "which roles does this account hold",
     which is what the tabs below used to ask. */

  /* ══════════════════════════════════════════════════════════════════════
     WHERE THIS PERSON'S PAY GOES.

     The tab this replaces offered staff the parent AUTOPAY page — somewhere to
     hand the centre a card so it could charge them for childcare — on the profile
     of somebody the centre owes money to.

     WRITE-ONLY, like every other secret in the portal. What comes back from the
     server is a HINT: "•••• 4821", "c•••@example.com". Enough to answer "is the
     right account on file", useless to anybody reading over a shoulder, and it
     means a stored account number is never in a response, a log or a DOM node.

     Blank leaves the stored value alone, so fixing a typo in the legal name cannot
     wipe the account number. Removing is explicit, via Remove.
     ══════════════════════════════════════════════════════════════════════ */
  async function renderPayout(pane) {
    pane.innerHTML = '';
    var card = el('div', { style: CARD });
    pane.appendChild(card);
    card.appendChild(el('div', { style: 'font-size:15px;font-weight:800;color:#0D1B2A;margin-bottom:4px;' }, ['How you are paid']));
    card.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-bottom:14px;line-height:1.5;' },
      ['Used by your agency to issue payroll. Only your administrator and centre director can see it, '
       + 'and it is stored encrypted — this page will only ever show you the last few digits.']));

    var body = el('div', {});
    card.appendChild(body);
    body.innerHTML = '<div style="color:#94A3B8;font-size:13px;">Loading…</div>';

    var current = null;
    try {
      var r = await Api.get('/me/payout-method');
      current = (r && r.payout_method) || null;
    } catch (e) { /* first time, or offline — treat as unset */ }

    body.innerHTML = '';

    // ── what is on file now ──
    if (current) {
      var onFile = el('div', {
        style: 'display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #BBF7D0;'
          + 'background:#F0FDF4;border-radius:10px;margin-bottom:14px;flex-wrap:wrap;',
      });
      onFile.appendChild(el('span', { style: 'font-size:16px;' }, ['✓']));
      onFile.appendChild(el('span', { style: 'font-size:13px;color:#166534;font-weight:700;' },
        [current.method === 'interac' ? 'Interac e-Transfer' : 'Direct deposit']));
      if (current.hint) {
        onFile.appendChild(el('span', { style: 'font-size:13px;color:#166534;font-variant-numeric:tabular-nums;' }, [current.hint]));
      }
      if (current.updated_at) {
        onFile.appendChild(el('span', { style: 'margin-left:auto;font-size:11.5px;color:#15803D;' },
          ['updated ' + ((window.KT && KT.Fmt)
            ? (KT.Fmt.date(current.updated_at, { month: 'short', day: 'numeric' }) + ' · ' + KT.Fmt.time(current.updated_at))
            : String(current.updated_at).slice(0, 16))]));
      }
      body.appendChild(onFile);
    }

    // ── choose a method ──
    var method = (current && current.method) || 'interac';
    var pick = el('div', { style: 'display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;' });
    body.appendChild(pick);

    var interacBox = el('div', {});
    var bankBox = el('div', { style: 'display:none;' });

    function paintPick() {
      Array.prototype.forEach.call(pick.children, function (b) {
        var on = b.getAttribute('data-m') === method;
        b.style.background = on ? '#1F6080' : '#fff';
        b.style.color = on ? '#fff' : '#334155';
        b.style.borderColor = on ? '#1F6080' : '#D1D5DB';
      });
      interacBox.style.display = method === 'interac' ? '' : 'none';
      bankBox.style.display = method === 'direct_deposit' ? '' : 'none';
    }
    [['interac', '💸 Interac e-Transfer'], ['direct_deposit', '🏦 Direct deposit']].forEach(function (o) {
      var b = el('button', {
        type: 'button', 'data-m': o[0],
        style: 'appearance:none;border:1px solid #D1D5DB;border-radius:10px;padding:9px 14px;'
          + 'font-size:13.5px;font-weight:700;cursor:pointer;flex:0 0 auto;',
      }, [o[1]]);
      b.addEventListener('click', function () { method = o[0]; paintPick(); });
      pick.appendChild(b);
    });

    function field(label, attrs, hint) {
      var w = el('div', { style: 'margin-bottom:10px;' });
      w.appendChild(el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;' }, [label]));
      var i = el('input', Object.assign({
        style: 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D1D5DB;'
          + 'border-radius:9px;font-size:14px;font-family:inherit;',
      }, attrs || {}));
      w.appendChild(i);
      if (hint) { w.appendChild(el('div', { style: 'font-size:11.5px;color:#94A3B8;margin-top:3px;' }, [hint])); }
      return { wrap: w, input: i };
    }

    var nameF = field('Name on the account', {
      type: 'text', maxlength: '160', value: (current && current.legal_name) || '',
      placeholder: 'As it appears at your bank',
    });
    body.appendChild(nameF.wrap);

    body.appendChild(interacBox);
    body.appendChild(bankBox);

    var emailF = field('e-Transfer address', {
      type: 'email', maxlength: '190',
      placeholder: current && current.method === 'interac' && current.hint
        ? 'On file: ' + current.hint + ' — leave blank to keep it'
        : 'you@example.com',
    }, 'Where the transfer will be sent.');
    interacBox.appendChild(emailF.wrap);

    var instF = field('Institution number', { type: 'text', inputmode: 'numeric', maxlength: '3', placeholder: '004' }, '3 digits');
    var transF = field('Transit number', { type: 'text', inputmode: 'numeric', maxlength: '5', placeholder: '12345' }, '5 digits');
    var acctF = field('Account number', {
      type: 'text', inputmode: 'numeric', maxlength: '12',
      placeholder: current && current.method === 'direct_deposit' && current.hint
        ? 'On file: ' + current.hint + ' — leave blank to keep it'
        : '7 to 12 digits',
    }, 'Found on a void cheque or your bank’s direct-deposit form.');
    bankBox.appendChild(instF.wrap);
    bankBox.appendChild(transF.wrap);
    bankBox.appendChild(acctF.wrap);

    paintPick();

    var msg = el('div', { style: 'font-size:12.5px;margin:8px 0 0;min-height:18px;' });
    var row = el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;' });
    var save = el('button', {
      type: 'button',
      style: 'appearance:none;border:0;border-radius:10px;background:#1F6080;color:#fff;'
        + 'padding:10px 18px;font-size:14px;font-weight:800;cursor:pointer;',
    }, ['Save']);
    row.appendChild(save);

    if (current) {
      var del = el('button', {
        type: 'button',
        style: 'appearance:none;border:1px solid #FECACA;border-radius:10px;background:#fff;'
          + 'color:#DC2626;padding:10px 14px;font-size:13.5px;font-weight:700;cursor:pointer;',
      }, ['Remove']);
      del.addEventListener('click', async function () {
        var ok = KT.confirm
          ? await KT.confirm('Remove your payout details? Payroll will have nowhere to send your pay until you add them again.')
          : window.confirm('Remove your payout details?');
        if (!ok) { return; }
        try {
          await Api.put('/me/payout-method', { method: method, clear: true });
          renderPayout(pane);
        } catch (e) {
          msg.style.color = '#DC2626';
          msg.textContent = (e && e.message) || 'Could not remove those details.';
        }
      });
      row.appendChild(del);
    }

    body.appendChild(row);
    body.appendChild(msg);

    save.addEventListener('click', async function () {
      save.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';
      var payload = { method: method, legal_name: nameF.input.value.trim() };
      if (method === 'interac') {
        if (emailF.input.value.trim()) { payload.interac_email = emailF.input.value.trim(); }
      } else {
        /* Sent only when filled, because blank means "keep what is stored" — the whole
           point of a write-only field. All three or none is enforced server-side. */
        if (instF.input.value.trim())  { payload.institution_number = instF.input.value.trim(); }
        if (transF.input.value.trim()) { payload.transit_number = transF.input.value.trim(); }
        if (acctF.input.value.trim())  { payload.account_number = acctF.input.value.trim(); }
      }
      try {
        await Api.put('/me/payout-method', payload);
        if (KT.toast) { KT.toast('✓', 'Saved', 'Payroll will use this from now on.', '#16A34A'); }
        renderPayout(pane);
      } catch (e) {
        save.disabled = false;
        msg.style.color = '#DC2626';
        msg.textContent = (e && e.message) || 'Could not save those details.';
      }
    });
  }

  async function render(main, ctx) {
    Dom.clear ? Dom.clear(main) : (main.innerHTML = '');
    // Extra bottom padding so the last controls (Remove PIN / Sign out) clear the
    // fixed mobile bottom bar instead of hiding behind it.
    var wrap = el('div', { style: 'padding:2px 2px 12px;max-width:520px;margin:0 auto;' });
    main.appendChild(wrap);
    // No in-screen "Settings" title: the shell's auto-hero already titles the
    // page, and rendering our own stacked a second "Settings" under it.

    var u = cachedUser();
    try { var fresh = await Api.get('/auth/me'); if (fresh) u = fresh.user || fresh; } catch (e) {}

    // v23: tabbed settings — Profile / Documents / Payments (guardian) / Security.
    // Consistent on desktop and mobile; each section below appends to a pane.
    /* THE ROLE THEY ARE WORKING AS, not every role they hold.

       This asked whether the ACCOUNT has a guardian role anywhere, so an educator who is
       also a parent at the centre — which is common, and true of several people here —
       was shown the parent Payments tab while working a shift: a page to set up AUTOPAY,
       so the centre could charge her for childcare, on the profile of somebody the
       centre PAYS. Reported as "there is a payments page as if she was a parent".

       She still has it, in the parent portal, where it belongs. */
    var activeRole = (ctx && ctx.role) || u.primary_role || '';
    var isGuardian = activeRole === 'guardian';

    /* Anybody the agency pays. A director and an admin are on payroll too — and an
       auditor is not, so they are deliberately absent. */
    var isPaidStaff = ['educator', 'home_visitor', 'centre_director', 'agency_admin']
      .indexOf(activeRole) !== -1;
    var tabBar = el('div', { style: 'display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid #E5E7EB;margin-bottom:16px;-webkit-overflow-scrolling:touch;' });
    var paneProfile = el('div', {});
    var paneDocs = el('div', { style: 'display:none;' });
    var panePay = el('div', { style: 'display:none;' });
    var panePayroll = el('div', { style: 'display:none;' });
    var paneSecurity = el('div', { style: 'display:none;' });
    var paneAbout = el('div', { style: 'display:none;' });
    var _stabs = [];
    function stab(label, pane) {
      var b = el('button', { type: 'button', style: 'appearance:none;background:none;border:0;border-bottom:2px solid transparent;padding:9px 8px;margin-bottom:-1px;font-size:14px;font-weight:700;color:#6B7280;cursor:pointer;white-space:nowrap;flex:0 0 auto;' }, [label]);
      b.addEventListener('click', function () { _stabs.forEach(function (t) { t.b.style.color = '#6B7280'; t.b.style.borderBottomColor = 'transparent'; t.p.style.display = 'none'; }); b.style.color = '#1F6080'; b.style.borderBottomColor = '#1F6080'; pane.style.display = ''; });
      _stabs.push({ b: b, p: pane, key: String(label).toLowerCase() }); tabBar.appendChild(b); return b;
    }
    var _t0 = stab('Profile', paneProfile);
    stab('Documents', paneDocs);
    if (isGuardian) stab('Payments', panePay);
    if (isPaidStaff) stab('Payroll', panePayroll);
    stab('Security', paneSecurity);
    stab('About', paneAbout);
    wrap.appendChild(tabBar);
    wrap.appendChild(paneProfile);
    wrap.appendChild(paneDocs);
    if (isGuardian) wrap.appendChild(panePay);
    if (isPaidStaff) wrap.appendChild(panePayroll);
    wrap.appendChild(paneSecurity);
    wrap.appendChild(paneAbout);
    _t0.style.color = '#1F6080'; _t0.style.borderBottomColor = '#1F6080';

    /* OPEN THE TAB THE LINK ASKED FOR — #settings?tab=payroll.

       Sending somebody to Settings and letting them hunt is the same failure as a
       notification whose Open button drops you on a list: the destination was known,
       and the reader was made to find it anyway. */
    (function () {
      var want = String((ctx && ctx.params && ctx.params.tab) || '').toLowerCase();
      if (!want) { return; }
      var hit = _stabs.filter(function (t) { return t.key === want; })[0];
      if (hit) { hit.b.click(); }
    }());
    // Payments (guardian only) — Autopay + Wallet, moved here from the home tiles.
    if (isGuardian) {
      var apBox = el('div', {});
      var wBox = el('div', { style: 'margin-top:10px;' });
      panePay.appendChild(apBox); panePay.appendChild(wBox);
      try { if (KT.Autopay && KT.Autopay.render) KT.Autopay.render(apBox); } catch (e) {}
      try { if (KT.V22p58 && KT.V22p58.renderWallet) KT.V22p58.renderWallet(wBox); } catch (e) {}
    }

    // ── PAYROLL (staff) — where their pay goes ──
    if (isPaidStaff) { renderPayout(panePayroll); }

    // ── PROFILE ──
    var pc = el('div', { style: CARD });
    pc.appendChild(el('div', { style: SECT }, ['Profile']));

    // avatar
    var avatarUrl = u.photo_url || u.avatar_url || u.photo || '';
    var avatarWrap = el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:16px;' });
    var av = el('div', { style: 'width:64px;height:64px;border-radius:50%;flex-shrink:0;background:#1F6080 center/cover no-repeat;color:#fff;font-size:24px;font-weight:800;display:flex;align-items:center;justify-content:center;overflow:hidden;' },
      [avatarUrl ? '' : ((u.first_name || u.name || '?')[0] || '?').toUpperCase()]);
    if (avatarUrl) av.style.backgroundImage = 'url(' + absUrl(avatarUrl) + ')';
    var fileInput = el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    var changeBtn = el('button', { type: 'button', style: 'border:1.5px solid #cbd5e1;background:#fff;color:#1F6080;border-radius:10px;padding:9px 14px;font-size:14px;font-weight:700;cursor:pointer;' }, ['Change photo']);
    changeBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0]; if (!f) return;
      av.style.backgroundImage = 'url(' + URL.createObjectURL(f) + ')'; av.textContent = '';
      changeBtn.textContent = 'Uploading…'; changeBtn.disabled = true;
      var fd = new FormData(); fd.append('avatar', f);
      fetch(apiBase() + '/auth/me/avatar', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' }, body: fd })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function () { changeBtn.textContent = 'Change photo'; changeBtn.disabled = false; })
        .catch(function () { changeBtn.textContent = 'Change photo'; changeBtn.disabled = false; profileStatus('Could not upload photo.', true); });
    });
    avatarWrap.appendChild(av); avatarWrap.appendChild(changeBtn); avatarWrap.appendChild(fileInput);
    pc.appendChild(avatarWrap);

    var first = field(pc, 'First name', 'text', u.first_name || '');
    var last = field(pc, 'Last name', 'text', u.last_name || '');
    var phone = field(pc, 'Phone', 'tel', u.phone || '');
    var dob = field(pc, 'Date of birth', 'date', (u.date_of_birth || '').slice(0, 10));
    var emailF = field(pc, 'Email', 'email', u.email || '');
    emailF.disabled = true; emailF.style.background = '#F1F5F9'; emailF.style.color = '#64748B';
    var usernameF = field(pc, 'Username (optional)', 'text', u.username || '');
    usernameF.setAttribute('placeholder', 'e.g. anthony.h');
    pc.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin:8px 0 10px;line-height:1.4;' },
      ['Set a username to sign in when one email is shared across more than one account.']));

    var pStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:4px 0 8px;' });
    var pSave = el('button', { type: 'button', class: 'kt-actionbtn', style: btn() }, ['Save profile']);
    function profileStatus(m, err) { pStatus.style.color = err ? '#B91C1C' : '#16A34A'; pStatus.textContent = m; }
    pSave.addEventListener('click', function () {
      pSave.disabled = true; pSave.textContent = 'Saving…'; pStatus.textContent = '';
      Api.patch('/auth/me', { first_name: first.value.trim(), last_name: last.value.trim(), phone: phone.value.trim(), date_of_birth: dob.value || null, username: usernameF.value.trim() })
        .then(function () { pSave.disabled = false; pSave.textContent = 'Save profile'; profileStatus('✓ Saved.'); try { var ku = cachedUser(); ku.first_name = first.value.trim(); ku.last_name = last.value.trim(); ku.phone = phone.value.trim(); ku.date_of_birth = dob.value; if (usernameF.value.trim()) ku.username = usernameF.value.trim(); sessionStorage.setItem('kt_user', JSON.stringify(ku)); } catch (e) {} })
        .catch(function (e) { pSave.disabled = false; pSave.textContent = 'Save profile'; profileStatus('Could not save: ' + (e && e.message ? e.message : 'try again'), true); });
    });
    pc.appendChild(pStatus); pc.appendChild(pSave);
    paneProfile.appendChild(pc);

    // ── PROVIDER BIO (home-daycare providers only) ──
    // Onboarding makes this mandatory, but only fires once — already-onboarded
    // providers edit it here. Saves to their centre (centres.provider_bio),
    // which drives their family provider card + welcome email.
    if (u.is_provider) {
      var bc = el('div', { style: CARD });
      bc.appendChild(el('div', { style: SECT }, ['Your bio']));
      bc.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin:-4px 0 12px;line-height:1.45;' },
        ['Introduce yourself to the families in your care — your experience, your approach, what makes your home special. This appears on each family’s provider card and their welcome email.']));
      var bio = el('textarea', { rows: '6', style: 'width:100%;padding:11px 13px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;box-sizing:border-box;resize:vertical;' });
      bio.value = u.provider_bio || '';
      bio.setAttribute('placeholder', 'e.g. Hi, I’m Chearstine! I’ve run a licensed home daycare for 8 years and love nature walks, music, and cozy story time…');
      bc.appendChild(bio);
      var bCount = el('div', { style: 'font-size:11px;margin:6px 0 4px;text-align:right;' });
      bc.appendChild(bCount);
      function bUpd() { var n = (bio.value || '').trim().length; bCount.textContent = n + ' characters' + (n < 40 ? ' · at least 40' : ' ✓'); bCount.style.color = n < 40 ? '#B45309' : '#16A34A'; }
      bio.addEventListener('input', bUpd); bUpd();
      var bStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:2px 0 8px;' });
      var bSave = el('button', { type: 'button', class: 'kt-actionbtn', style: btn() }, ['Save bio']);
      bSave.addEventListener('click', function () {
        var v = (bio.value || '').trim();
        if (v.length < 40) { bStatus.style.color = '#B91C1C'; bStatus.textContent = 'Please write at least a sentence or two (40+ characters).'; bio.focus(); return; }
        bSave.disabled = true; bSave.textContent = 'Saving…'; bStatus.textContent = '';
        Api.patch('/auth/me/provider-bio', { provider_bio: v })
          .then(function () { bSave.disabled = false; bSave.textContent = 'Save bio'; bStatus.style.color = '#16A34A'; bStatus.textContent = '✓ Saved — families will see this on your card.'; try { var ku = cachedUser(); ku.provider_bio = v; sessionStorage.setItem('kt_user', JSON.stringify(ku)); } catch (e) {} })
          .catch(function (e) { bSave.disabled = false; bSave.textContent = 'Save bio'; bStatus.style.color = '#B91C1C'; bStatus.textContent = 'Could not save: ' + (e && e.message ? e.message : 'try again'); });
      });
      bc.appendChild(bStatus); bc.appendChild(bSave);
      paneProfile.appendChild(bc);
    }

    // ── MY DOCUMENTS & AGREEMENTS (v23) ──
    // Shared renderer (defined in screen-parent-forms.js) — the user's signed
    // NDA and any files on their record. Also lives under Forms for parents.
    if (KT.renderMyDocuments) { try { KT.renderMyDocuments(paneDocs); } catch (e) {} }

    // ── LANGUAGE ──
    // Parents/educators pick their app language here. It is saved to the server
    // (users.locale) as well as locally, so it sticks on the next sign-in on any device
    // (kt-i18n seeds from the saved value when there is no local choice yet).
    var lc = el('div', { style: CARD });
    lc.appendChild(el('div', { style: SECT }, ['Language']));
    lc.appendChild(el('div', { style: 'font-size:13px;color:#64748b;margin:-2px 0 12px;' },
      ['Choose the language for the app. Saved for your next sign-in, on any device.']));
    var LANGS = [['en', 'English'], ['fr', 'Fran\u00e7ais'], ['es', 'Espa\u00f1ol'], ['hi', '\u0939\u093f\u0928\u094d\u0926\u0940']];
    var curLang = 'en';
    try { curLang = localStorage.getItem('kt_locale') || u.locale || 'en'; } catch (e) {}
    var langSel = el('select', { style: 'width:100%;padding:10px 12px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:15px;background:#fff;color:#0f172a;cursor:pointer;' });
    LANGS.forEach(function (l) { var o = el('option', { value: l[0] }, [l[1]]); if (l[0] === curLang) o.selected = true; langSel.appendChild(o); });
    var langStatus = el('div', { style: 'font-size:13px;min-height:16px;margin-top:8px;' });
    langSel.addEventListener('change', function () {
      var loc = langSel.value;
      try { localStorage.setItem('kt_locale', loc); } catch (e) {}
      langStatus.style.color = '#16A34A'; langStatus.textContent = '\u2713 Saved. Applying\u2026';
      fetch(apiBase() + '/locale', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
        body: JSON.stringify({ locale: loc })
      }).catch(function () {}).then(function () {
        try { var ku = cachedUser(); ku.locale = loc; sessionStorage.setItem('kt_user', JSON.stringify(ku)); } catch (e) {}
        // Reload so every screen re-renders in the new language (kt-i18n translates the
        // fresh DOM; translating in place would leave already-translated nodes stale).
        setTimeout(function () { window.location.reload(); }, 300);
      });
    });
    lc.appendChild(langSel); lc.appendChild(langStatus);
    paneProfile.appendChild(lc);

    // ── FAMILY DIRECTORY (guardian only) — moved here from the Directory screen so
    //    all of the user's preferences live in one place. ──
    if (/\brole-guardian\b/.test(document.body.className || '')) {
      var fc = el('div', { style: CARD });
      fc.appendChild(el('div', { style: SECT }, ['Family directory']));
      fc.appendChild(el('div', { style: 'font-size:13px;color:#64748b;margin:-2px 0 12px;' },
        ['Connect with other families at your centre. Sharing is opt-in \u2014 choose exactly what to share.']));
      var mkChk = function (label) {
        var w = el('label', { style: 'display:flex;align-items:center;gap:10px;margin:9px 0;font-size:14px;color:#0f172a;cursor:pointer;' });
        var i = el('input', { type: 'checkbox', style: 'width:18px;height:18px;accent-color:#159FB4;flex-shrink:0;' });
        w.appendChild(i); w.appendChild(el('span', {}, [label])); fc.appendChild(w); return i;
      };
      var dEm = mkChk('Share my email'), dPh = mkChk('Share my phone'), dAd = mkChk('Share my address (city only)'), dKn = mkChk("Show my children's first names");
      var dStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:4px 0 8px;' });
      var dSave = el('button', { type: 'button', class: 'kt-actionbtn', style: btn() }, ['Save preferences']);
      fc.appendChild(dStatus); fc.appendChild(dSave);
      paneProfile.appendChild(fc);
      Api.get('/directory/me').then(function (r) { var o = (r && r.data) || {}; dEm.checked = !!o.share_email; dPh.checked = !!o.share_phone; dAd.checked = !!o.share_address; dKn.checked = (o.share_children_names !== 0); }).catch(function () {});
      dSave.addEventListener('click', function () {
        dSave.disabled = true; dSave.textContent = 'Saving\u2026'; dStatus.textContent = '';
        Api.post('/directory/me', { share_email: dEm.checked, share_phone: dPh.checked, share_address: dAd.checked, share_children_names: dKn.checked })
          .then(function () { dSave.disabled = false; dSave.textContent = 'Save preferences'; dStatus.style.color = '#16A34A'; dStatus.textContent = '\u2713 Saved.'; })
          .catch(function (e) { dSave.disabled = false; dSave.textContent = 'Save preferences'; dStatus.style.color = '#B91C1C'; dStatus.textContent = 'Could not save: ' + ((e && e.message) || 'try again'); });
      });
    }

    // ── SECURITY ──
    var sc = el('div', { style: CARD });
    sc.appendChild(el('div', { style: SECT }, ['Security']));

    // change password
    var cur = field(sc, 'Current password', 'password', '');
    var nw = field(sc, 'New password', 'password', '');
    // Strength meter + live requirement checklist. The server enforces
    // PasswordPolicy (8+, mixed case, number, symbol) and 422s on a weak or
    // recently-used password — showing the same rules here means the user meets
    // them before submitting instead of guessing at a rejection.
    var strength = buildStrengthMeter(nw);
    sc.appendChild(strength.node);
    var cf = field(sc, 'Confirm new password', 'password', '');
    var pwStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:4px 0 8px;' });
    var pwBtn = el('button', { type: 'button', class: 'kt-actionbtn', style: btn() }, ['Change password']);
    pwBtn.addEventListener('click', function () {
      pwStatus.textContent = '';
      if (!cur.value || !nw.value) { pwStatus.style.color = '#B45309'; pwStatus.textContent = 'Fill in your current and new password.'; return; }
      if (!strength.meets()) { pwStatus.style.color = '#B45309'; pwStatus.textContent = 'Your new password doesn’t meet all the requirements yet.'; nw.focus(); return; }
      if (nw.value !== cf.value) { pwStatus.style.color = '#B45309'; pwStatus.textContent = 'New passwords don’t match.'; return; }
      pwBtn.disabled = true; pwBtn.textContent = 'Updating…';
      Api.post('/auth/change-password', { current_password: cur.value, new_password: nw.value })
        .then(function () { pwBtn.disabled = false; pwBtn.textContent = 'Change password'; pwStatus.style.color = '#16A34A'; pwStatus.textContent = '✓ Password changed.'; cur.value = nw.value = cf.value = ''; })
        .catch(function (e) { pwBtn.disabled = false; pwBtn.textContent = 'Change password'; pwStatus.style.color = '#B91C1C'; pwStatus.textContent = (e && e.message) ? e.message : 'Could not change password.'; });
    });
    sc.appendChild(pwStatus); sc.appendChild(pwBtn);

    /* Two-factor, in the same card as the password.

       It used to be its own item in the menu, which put the two halves of "how do I
       get into this account" in different places — and left a nav entry pointing at a
       page most people open once, ever. The real screen is rendered here in embedded
       mode rather than copied, so there is exactly one two-factor flow.

       Shown to everyone it applies to. The platform support account is deliberately
       exempt (see kt-mfa-gate.js), and for agency admins and directors it is required
       rather than optional — said plainly, because a required control that looks
       optional gets left off. */
    try {
      var _roles = (cachedUser().roles) || [];
      var _mfa = window.KT && KT.MfaScreen;
      if (_mfa && _mfa.appliesTo && _mfa.appliesTo(_roles)) {
        sc.appendChild(el('div', {
          style: 'height:1px;background:#EEF2F7;margin:16px 0 14px;',
        }));
        var _hdr = el('div', {
          style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 4px;',
        });
        _hdr.appendChild(el('div', {
          style: 'font-size:14px;font-weight:800;color:#0f172a;',
        }, ['🔐 Two-factor authentication']));
        if (_mfa.required && _mfa.required(_roles)) {
          _hdr.appendChild(el('span', {
            style: 'font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;'
              + 'background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:20px;',
          }, ['Required for your role']));
        }
        sc.appendChild(_hdr);
        var _mfaBox = el('div', {});
        sc.appendChild(_mfaBox);
        _mfa.render(_mfaBox, { embedded: true });
      }
    } catch (e) { /* the rest of Settings must render even if this does not */ }

    // biometric toggle (native only)
    sc.appendChild(el('hr', { style: 'border:none;border-top:1px solid #EEF2F6;margin:16px 0;' }));
    var bioRow = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;' });
    var bioSub = el('div', { style: 'font-size:12px;color:#64748B;', id: 'kt-bio-sub' }, ['Checking…']);
    bioRow.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['Fingerprint / Face sign-in']), bioSub]));
    var bioToggle = mkSwitch(); bioToggle.id = 'kt-bio-toggle';
    bioRow.appendChild(bioToggle);
    sc.appendChild(bioRow);
    // Pass the element, never look it up by id: this card isn't in the document
    // yet (it's appended below), so getElementById would return null and every
    // status update would silently no-op — the row stayed on "Checking…" forever.
    wireBiometrics(bioToggle, bioSub);

    // Quick-unlock PIN. KT.pin (kt-pin.js) seals the session under a key derived
    // from the PIN, so on the next launch the PIN alone reopens the app.
    sc.appendChild(el('hr', { style: 'border:none;border-top:1px solid #EEF2F6;margin:16px 0;' }));
    var pin = KT.pin;
    var pinHas = !!(pin && pin.isSet());
    var pinRow = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;' });
    var pinSub = el('div', { style: 'font-size:12px;color:#64748B;' },
      [!pin ? 'Not available on this device.' : (pinHas ? 'A PIN is set — use it to unlock the app.' : 'Unlock the app with a 4–6 digit PIN.')]);
    pinRow.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['Quick-unlock PIN']), pinSub]));
    var pinBtn = el('button', { type: 'button', style: 'border:1.5px solid #cbd5e1;background:#fff;color:#1F6080;border-radius:10px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;' }, [pinHas ? 'Change' : 'Set PIN']);
    if (!pin) pinBtn.disabled = true;
    pinRow.appendChild(pinBtn);
    sc.appendChild(pinRow);
    var pinArea = el('div'); sc.appendChild(pinArea);
    var pinRemove = null;
    function paintPin() {
      var has = !!(pin && pin.isSet());
      pinSub.textContent = has ? 'A PIN is set — use it to unlock the app.' : 'Unlock the app with a 4–6 digit PIN.';
      pinBtn.textContent = has ? 'Change' : 'Set PIN';
      if (has && !pinRemove) {
        pinRemove = el('button', { type: 'button', style: 'background:none;border:none;color:#B91C1C;font-size:12px;font-weight:700;cursor:pointer;margin-top:8px;padding:2px;' }, ['Remove PIN']);
        pinRemove.addEventListener('click', function () { pin.remove().then(function () { pinRemove.remove(); pinRemove = null; paintPin(); }); });
        sc.appendChild(pinRemove);
      } else if (!has && pinRemove) { pinRemove.remove(); pinRemove = null; }
    }
    if (pin) pinBtn.addEventListener('click', function () { pinFlow(pinArea, paintPin); });
    paintPin();

    // Notifications self-test: fires an FCM push to THIS user's own device
    // tokens via /push/test-fcm, bypassing chat-recipient logic — the cleanest
    // way to confirm OS notifications actually land in the Android bar.
    try {
      var nd = el('div', { style: 'background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px;margin-bottom:14px;' });
      nd.appendChild(el('div', { style: 'font-weight:800;font-size:14px;color:#0F172A;margin-bottom:4px;' }, ['🔔 Notifications']));
      nd.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:10px;' }, ['Send yourself a test push. Tip: background the app first — an open app shows it in-app, not in the bar.']));
      var ntBtn = el('button', { type: 'button', style: 'display:block;margin-top:16px;background:linear-gradient(135deg,#0FA3B1,#1F6FB2);border:0;color:#fff;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:800;cursor:pointer;' }, ['Send test notification']);
      var ntMsg = el('div', { style: 'font-size:12px;margin-top:8px;min-height:16px;' });
      ntBtn.addEventListener('click', function () {
        ntBtn.disabled = true; var old = ntBtn.textContent; ntBtn.textContent = 'Sending…'; ntMsg.style.color = '#64748B'; ntMsg.textContent = '';
        fetch(apiBase() + '/push/test-fcm', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            ntBtn.disabled = false; ntBtn.textContent = old; var s = res.j || {};
            if (res.ok && (s.sent | 0) > 0) { ntMsg.style.color = '#16A34A'; ntMsg.textContent = '✓ Sent to ' + s.sent + ' device' + ((s.sent | 0) > 1 ? 's' : '') + '. Background the app and check your notification bar.'; }
            else if (res.ok && (s.sent | 0) === 0) { ntMsg.style.color = '#B45309'; ntMsg.textContent = 'No registered devices yet. Open the app once and allow notifications, then retry.'; }
            else { ntMsg.style.color = '#B91C1C'; ntMsg.textContent = (s.message || ('Could not send. ' + (s.error || ''))); }
          })
          .catch(function () { ntBtn.disabled = false; ntBtn.textContent = old; ntMsg.style.color = '#B91C1C'; ntMsg.textContent = 'Network error — try again.'; });
      });
      // Urgent alerts (staff): the full-screen, chiming, vibrating takeover when
      // a message or alert lands while the app is open. On by default for staff,
      // but it must be switchable — it's deliberately hard to ignore.
      try {
        if (KT.urgentAlert) {
          var ua = el('div', { style: 'border-top:1px solid #EEF2F6;margin-top:14px;padding-top:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;' });
          var uaSub = el('div', { style: 'font-size:12px;color:#64748B;' },
            [KT.urgentAlert.isEnabled() ? 'New chats and alerts take over the screen with sound.' : 'New chats and alerts only show a badge.']);
          ua.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['🚨 Urgent alerts']), uaSub]));
          var uaBtn = mkSwitch();
          var paintUa = function () {
            var on = KT.urgentAlert.isEnabled();
            uaBtn.kt_set(on);
            uaSub.textContent = on ? 'New chats and alerts take over the screen with sound.' : 'New chats and alerts only show a badge.';
          };
          uaBtn.addEventListener('click', function () { KT.urgentAlert.setEnabled(!KT.urgentAlert.isEnabled()); paintUa(); });
          paintUa();
          ua.appendChild(uaBtn);
          nd.appendChild(ua);
          var uaTest = el('button', { type: 'button', style: 'display:inline-flex;align-items:center;gap:6px;margin-top:12px;background:#FEF2F2;color:#B91C1C;border:1.5px solid #FECACA;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:800;cursor:pointer;' }, ['🚨 Test urgent alert']);
          // Opt out of kt-icon-buttons: the old "Preview…" label contained "view",
          // so it was auto-collapsed into a cryptic ℹ️ icon. Keep it a real button.
          uaTest.dataset.ktIconized = '1';
          uaTest.addEventListener('click', function () { KT.urgentAlert.test('message'); });
          nd.appendChild(uaTest);
        }
      } catch (e) {}

      nd.appendChild(ntBtn); nd.appendChild(ntMsg);
      paneProfile.appendChild(nd);

      // ── What you get told about, and how ──
      // Sign-in/out alerts: email and in-app on by default, SMS off — every text
      // costs the centre money, so nobody is opted into it silently.
      try {
        var pc2 = el('div', { style: CARD });
        pc2.appendChild(el('div', { style: SECT }, ['Notify me about…']));
        var prefsBody = el('div', {});
        pc2.appendChild(prefsBody);
        prefsBody.appendChild(el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0;' }, ['Loading…']));
        paneProfile.appendChild(pc2);

        // Consent to be texted at all — a different question from which events you
        // want, and the one that has to be asked explicitly. Loaded in parallel so a
        // pill can tell "never consented" from "consented, switched off".
        var smsConsent = { opted_in: false, consent_text: '' };
        Api.get('/me/sms-consent').then(function (c) {
          smsConsent.opted_in = !!(c && c.opted_in);
          smsConsent.consent_text = (c && c.consent_text) || '';
          if (KT._smsConsentPainted) { KT._smsConsentPainted(); }
        }).catch(function () { /* pills still work; the ask below just refuses */ });

        // Ask, record, and only then let the caller move the pill. Never opts in without
        // an explicit yes — including when the dialog helper is missing, where the safe
        // answer is no rather than carrying on.
        function askSmsConsent(done) {
          if (!KT.confirm || !smsConsent.consent_text) {
            if (KT.toast) KT.toast('⚠️', 'Not available', 'Please try again in a moment.', '#B91C1C');
            done(false);
            return;
          }
          KT.confirm({
            title: 'Receive text messages?',
            description: smsConsent.consent_text,
            okLabel: 'Yes',
            cancelLabel: 'No',
          }).then(function (ok) {
            if (!ok) { done(false); return; }
            Api.post('/me/sms-consent', { agree: true }).then(function () {
              smsConsent.opted_in = true;
              if (KT.toast) KT.toast('💬', 'Text messages on', 'We have sent you a confirmation text.', '#159FB4');
              done(true);
            }).catch(function (e) {
              // A number that replied STOP cannot be re-enrolled from a screen — the
              // server answers 409 and says to reply START from that handset instead.
              var m = (e && e.data && e.data.message) || (e && e.message)
                || 'Could not turn text messages on.';
              if (KT.toast) KT.toast('⚠️', 'Not enabled', m, '#B91C1C');
              done(false);
            });
          });
        }

        Api.get('/me/notification-prefs').then(function (res) {
          Dom.clear ? Dom.clear(prefsBody) : (prefsBody.innerHTML = '');
          (res.preferences || []).forEach(function (p) {
            var row = el('div', { style: 'padding:6px 0;' });
            row.appendChild(el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, [p.label]));
            row.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-bottom:9px;' }, [p.hint]));

            var chans = el('div', { style: 'display:flex;gap:7px;flex-wrap:wrap;' });
            var state = { email: !!p.email, push: !!p.push, sms: !!p.sms };
            // paintChan is per-channel and scoped to the forEach below; the stop-all
            // link needs the SMS one, so it is captured on the way past.
            var smsPaint = null;
            [['email', '✉️ Email'], ['push', '🔔 In-app'], ['sms', '💬 Text']].forEach(function (c) {
              var key = c[0];
              var b = el('button', { type: 'button', style: 'border-radius:999px;padding:9px 14px;font-size:13px;font-weight:800;cursor:pointer;border:1.5px solid;' }, [c[1]]);
              var paintChan = function () {
                var on = state[key];
                b.style.background = on ? '#159FB4' : '#fff';
                b.style.color = on ? '#fff' : '#64748B';
                b.style.borderColor = on ? '#159FB4' : '#E2E8F0';
              };
              var savePrefs = function (revertKey) {
                Api.put('/me/notification-prefs', {
                  key: p.key, email: state.email, push: state.push, sms: state.sms,
                }).catch(function () {
                  state[revertKey] = !state[revertKey]; paintChan();     // put it back
                  if (KT.toast) KT.toast('⚠️', 'Could not save', 'Please try again.', '#B91C1C');
                });
              };
              b.addEventListener('click', function () {
                // Switching text messages on for the first time is a consent decision,
                // not a preference. Ask, and leave the pill alone until the answer is
                // yes — a pill that flips first and asks after has already told the
                // user they are enrolled. Once consent is recorded this is an ordinary
                // switch again.
                if (key === 'sms' && !state.sms && !smsConsent.opted_in) {
                  askSmsConsent(function (granted) {
                    if (!granted) { return; }
                    state.sms = true;
                    paintChan();
                    savePrefs('sms');
                  });
                  return;
                }
                state[key] = !state[key];
                paintChan();
                savePrefs(key);
              });
              paintChan();
              if (key === 'sms') { smsPaint = paintChan; }
              chans.appendChild(b);
            });
            row.appendChild(chans);

            // Stopping the lot, without having to wait for a text to reply STOP to.
            // Only shown when there is something to stop.
            var stopWrap = el('div', { style: 'margin-top:10px;display:none;' });
            var stopLink = el('button', {
              type: 'button',
              style: 'background:none;border:0;padding:0;font-size:12px;color:#64748B;'
                + 'text-decoration:underline;cursor:pointer;',
            }, ['Stop all text messages']);
            stopLink.dataset.ktIconized = '1';
            var paintStop = function () {
              stopWrap.style.display = smsConsent.opted_in ? 'block' : 'none';
            };
            stopLink.addEventListener('click', function () {
              Api.post('/me/sms-consent', { agree: false }).then(function () {
                smsConsent.opted_in = false;
                state.sms = false;
                if (smsPaint) { smsPaint(); }
                paintStop();
                if (KT.toast) KT.toast('💬', 'Text messages off', 'You will not receive any more texts.', '#159FB4');
              }).catch(function () {
                if (KT.toast) KT.toast('⚠️', 'Could not save', 'Please try again.', '#B91C1C');
              });
            });
            stopWrap.appendChild(stopLink);
            row.appendChild(stopWrap);
            // The consent request may still be in flight when this row renders, so it
            // paints now and again when that lands.
            paintStop();
            KT._smsConsentPainted = paintStop;

            prefsBody.appendChild(row);
          });
        }).catch(function () {
          Dom.clear ? Dom.clear(prefsBody) : (prefsBody.innerHTML = '');
          prefsBody.appendChild(el('div', { style: 'color:#64748B;font-size:13px;' }, ['Could not load your notification settings.']));
        });
      } catch (e) {}
    } catch (e) {}

    // Diagnostics: if the native app captured a crash (e.g. biometrics), show it
    // here so it can be screenshotted for support. Populated by MainActivity.
    try {
      var crash = localStorage.getItem('kt_last_crash');
      if (crash) {
        var dc = el('div', { style: 'background:#fff;border:1px solid #FCA5A5;border-radius:14px;padding:16px;margin-bottom:14px;' });
        dc.appendChild(el('div', { style: 'font-weight:800;font-size:14px;color:#B91C1C;margin-bottom:8px;' }, ['⚠️ Last crash report (for support)']));
        dc.appendChild(el('pre', { style: 'white-space:pre-wrap;word-break:break-word;font-size:10.5px;line-height:1.4;color:#334155;max-height:200px;overflow:auto;background:#F8FAFC;border-radius:8px;padding:10px;margin:0 0 10px;' }, [crash.slice(0, 4000)]));
        var clr = el('button', { type: 'button', style: 'background:#F1F5F9;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;color:#475569;cursor:pointer;' }, ['Clear']);
        clr.addEventListener('click', function () { try { localStorage.removeItem('kt_last_crash'); } catch (e) {} dc.remove(); });
        dc.appendChild(clr);
        paneSecurity.appendChild(dc);
      }
    } catch (e) {}

    paneSecurity.appendChild(sc);

    // Delete my account (App Store Guideline 5.1.1(v) in-app deletion path).
    var dz = el('div', { style: CARD + 'border:1px solid #FECACA;' });
    dz.appendChild(el('div', { style: 'font-size:15px;font-weight:800;color:#B91C1C;margin:0 0 8px;' }, ['Delete my account']));
    dz.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:12px;' }, ['Your account is managed by your childcare agency. Request deletion and your agency administrator will permanently remove your account and personal data.']));
    var dzStatus = el('div', { style: 'font-size:12.5px;min-height:16px;margin-bottom:8px;' });
    var dzBtn = el('button', { type: 'button', style: 'background:#fff;color:#B91C1C;border:1.5px solid #FCA5A5;border-radius:11px;padding:11px 18px;font-size:14px;font-weight:800;cursor:pointer;min-height:0;' }, ['Request account deletion']);
    dzBtn.addEventListener('click', async function () {
      var ok = (window.KT && KT.confirm) ? await KT.confirm('Request permanent deletion of your KiddieTrac account and data? Your agency administrator will be notified to complete it.') : window.confirm('Request account deletion?');
      if (!ok) return;
      dzBtn.disabled = true; dzBtn.textContent = 'Sending…';
      try {
        var r = await Api.post('/me/account-deletion', {});
        dzStatus.style.color = '#16A34A'; dzStatus.textContent = (r && r.message) || 'Request sent to your agency administrator.';
        dzBtn.textContent = 'Request sent';
      } catch (e) {
        dzBtn.disabled = false; dzBtn.textContent = 'Request account deletion';
        dzStatus.style.color = '#B91C1C'; dzStatus.textContent = (e && e.message) || 'Could not send — please try again.';
      }
    });
    dz.appendChild(dzStatus); dz.appendChild(dzBtn);
    paneSecurity.appendChild(dz);
    // Sign out deliberately does NOT live here any more — there is exactly one
    // sign-out button, on the home screen, so it's always in the same place.

    // About / build — the single place to confirm what's actually running on a
    // device. Shows THREE things so we can tell installs from cache issues:
    //   · Web build   = window.KT_VERSION (set in the page <head>) — the portal code.
    //   · App build   = the installed APK versionName (versionCode), read live from
    //                   Capacitor App.getInfo(). If this doesn't change after a
    //                   reinstall, the install didn't take (signature conflict etc).
    //   · assets      = the ?v= fingerprint the WebView actually loaded — if this is
    //                   stale while Web build is new, it's a service-worker cache issue.
    var about = el('div', { style: 'text-align:center;margin:10px 0 4px;font-size:11.5px;color:#64748B;line-height:1.7;' });
    about.appendChild(el('div', { style: 'font-weight:800;color:#334155;font-size:12.5px;' }, ['KiddieTrac']));
    about.appendChild(el('div', {}, ['Web build ' + (window.KT_VERSION || '—')]));
    about.appendChild(el('div', { style: 'color:#94A3B8;font-size:10.5px;margin-top:2px;' }, ['© 2021–2026 KiddieTrac. All rights reserved.']));
    var appLine = el('div', { style: 'font-weight:700;color:#334155;' }, ['App build: checking…']);
    about.appendChild(appLine);
    try {
      var _s = document.querySelector('script[src*="screen-settings.js"]');
      var _m = _s && _s.src.match(/[?&]v=([^&"]+)/);
      var _px = window.innerWidth + '×' + window.innerHeight + ' · ≤768:' + (window.matchMedia && window.matchMedia('(max-width:768px)').matches ? 'yes' : 'no');
      about.appendChild(el('div', { style: 'color:#94A3B8;font-size:10px;' }, ['assets ' + (_m ? _m[1] : 'n/a') + ' · ' + _px]));
    } catch (e) {}
    try {
      var _App = window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.App;
      var _native = window.Capacitor && (Capacitor.isNativePlatform ? Capacitor.isNativePlatform() : Capacitor.isNative);
      if (_App && _App.getInfo) {
        _App.getInfo().then(function (info) {
          appLine.textContent = 'App build: ' + (info.version || '?') + ' (' + (info.build || '?') + ')';
        }).catch(function () { appLine.textContent = 'App build: native info unavailable'; });
      } else if (_native) {
        appLine.textContent = 'App build: (App plugin missing)';
      } else {
        appLine.textContent = 'Running in a web browser (not the installed app)';
        appLine.style.color = '#94A3B8';
        appLine.style.fontWeight = '400';
      }
    } catch (e) { appLine.textContent = 'App build: —'; }
    // Everything above lives in the ABOUT tab (its own section), not as a footer
    // repeated under every tab.
    var aboutCard = el('div', { style: CARD });
    aboutCard.appendChild(el('div', { style: SECT }, ['About']));
    aboutCard.appendChild(about);

    // Diagnostics overlay toggle — a support/troubleshooting tool. OFF by default;
    // when on, shows the small on-screen debug chip (version / WebView size / role).
    // Persists in localStorage.kt_diag and applies live via window.__ktDiagRefresh().
    var diagRow = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 2px 2px;padding-top:14px;border-top:1px solid #EEF2F6;' });
    var diagText = el('div', {});
    diagText.appendChild(el('div', { style: 'font-weight:700;color:#334155;font-size:13px;' }, ['Diagnostics overlay']));
    diagText.appendChild(el('div', { style: 'font-size:11.5px;color:#94A3B8;margin-top:1px;' }, ['Show the on-screen debug chip (for support / troubleshooting).']));
    diagRow.appendChild(diagText);
    var diagSw = mkSwitch();
    function diagOn() { try { return localStorage.getItem('kt_diag') === '1'; } catch (e) { return false; } }
    try { diagSw.kt_set(diagOn()); } catch (e) {}
    diagSw.addEventListener('click', function () {
      var nowOn = !diagOn();
      try { localStorage.setItem('kt_diag', nowOn ? '1' : '0'); } catch (e) {}
      diagSw.kt_set(nowOn);
      try { if (window.__ktDiagRefresh) window.__ktDiagRefresh(); } catch (e) {}
    });
    diagRow.appendChild(diagSw);
    aboutCard.appendChild(diagRow);
    paneAbout.appendChild(aboutCard);
  }

  function field(parent, label, type, val) {
    parent.appendChild(el('div', { style: LABEL, style: LABEL + 'margin-top:12px;' }, [label]));
    // Password fields get a reveal button — typing a password blind on a phone
    // keyboard is how people end up locked out.
    if (type === 'password') {
      var wrapF = el('div', { style: 'position:relative;' });
      var pi = el('input', { type: 'password', value: val || '', style: INPUT + 'padding-right:52px;' });
      var eye = el('button', {
        type: 'button', 'aria-label': 'Show password',
        style: 'position:absolute;top:50%;right:6px;transform:translateY(-50%);background:none;border:none;font-size:17px;line-height:1;cursor:pointer;padding:8px;color:#64748B;',
      }, ['👁']);
      eye.addEventListener('click', function () {
        var show = pi.type === 'password';
        pi.type = show ? 'text' : 'password';
        eye.textContent = show ? '🙈' : '👁';
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        pi.focus();
      });
      wrapF.appendChild(pi); wrapF.appendChild(eye);
      parent.appendChild(wrapF);
      return pi;
    }
    var i = el('input', { type: type, value: val || '', style: INPUT });
    parent.appendChild(i);
    return i;
  }
  // Size now comes from the shared .kt-actionbtn class (compact on desktop,
  // full-width 44px on phones) — btn() only carries the brand fill. The old
  // hard-coded width:100%/padding:14px/font-size:15px rendered Save profile and
  // Save preferences as 47px full-card slabs on desktop.
  function btn() { return 'border:0;cursor:pointer;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);margin-top:8px;'; }

  // ── Password strength meter ────────────────────────────────────────
  // Mirrors the server's PasswordPolicy so the rules a user must satisfy are
  // the rules they can see. Returns { node, meets() }.
  var PW_RULES = [
    { key: 'len',   label: 'At least 8 characters', test: function (v) { return v.length >= 8; } },
    { key: 'case',  label: 'Upper and lower case',  test: function (v) { return /[a-z]/.test(v) && /[A-Z]/.test(v); } },
    { key: 'num',   label: 'A number',              test: function (v) { return /\d/.test(v); } },
    { key: 'sym',   label: 'A symbol (!?@#…)',      test: function (v) { return /[^A-Za-z0-9]/.test(v); } },
  ];
  function buildStrengthMeter(input) {
    var wrapEl = el('div', { style: 'margin-top:8px;' });
    var bar = el('div', { style: 'height:6px;border-radius:99px;background:#E7EDF3;overflow:hidden;' });
    var fill = el('div', { style: 'height:100%;width:0;border-radius:99px;background:#EF4444;transition:width .2s ease,background .2s ease;' });
    bar.appendChild(fill);
    var word = el('div', { style: 'font-size:12px;font-weight:700;margin-top:5px;min-height:15px;color:#64748B;' }, ['Enter a new password']);
    var list = el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:6px;' });
    var marks = {};
    PW_RULES.forEach(function (r) {
      var m = el('div', { style: 'font-size:11.5px;color:#64748B;display:flex;align-items:center;gap:4px;' }, ['○ ' + r.label]);
      marks[r.key] = m; list.appendChild(m);
    });
    var gen = el('button', { type: 'button', style: 'background:none;border:none;color:#159FB4;font-size:12px;font-weight:700;cursor:pointer;padding:6px 0 0;' }, ['Suggest a strong password']);
    gen.addEventListener('click', function () {
      var p = suggestPassword();
      input.type = 'text'; input.value = p; paint();
      // Show it briefly so it can be memorised or captured by a password manager.
      setTimeout(function () { input.type = 'password'; }, 6000);
    });
    wrapEl.appendChild(bar); wrapEl.appendChild(word); wrapEl.appendChild(list); wrapEl.appendChild(gen);

    function passed(v) { return PW_RULES.filter(function (r) { return r.test(v); }); }
    function paint() {
      var v = input.value || '';
      var ok = passed(v);
      PW_RULES.forEach(function (r) {
        var hit = r.test(v);
        marks[r.key].textContent = (hit ? '✓ ' : '○ ') + r.label;
        marks[r.key].style.color = hit ? '#16A34A' : '#94A3B8';
        marks[r.key].style.fontWeight = hit ? '700' : '400';
      });
      // A long passphrase that clears every rule reads as "strong"; length alone
      // isn't enough, since the server rejects on the rules, not on entropy.
      var n = ok.length;
      var pct = v ? Math.max(8, (n / PW_RULES.length) * 100) : 0;
      if (n === PW_RULES.length && v.length >= 14) pct = 100;
      var colour = n <= 1 ? '#EF4444' : n === 2 ? '#F59E0B' : n === 3 ? '#EAB308' : '#16A34A';
      var text = !v ? 'Enter a new password' : n <= 1 ? 'Weak' : n === 2 ? 'Fair' : n === 3 ? 'Good' : (v.length >= 14 ? 'Excellent' : 'Strong');
      fill.style.width = pct + '%'; fill.style.background = colour;
      word.textContent = text; word.style.color = v ? colour : '#94A3B8';
    }
    input.addEventListener('input', paint);
    return { node: wrapEl, meets: function () { return passed(input.value || '').length === PW_RULES.length; }, refresh: paint };
  }
  function suggestPassword() {
    var lower = 'abcdefghijkmnpqrstuvwxyz', upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ', nums = '23456789', syms = '!@#$%?&*';
    var all = lower + upper + nums + syms;
    var pick = function (set, n) {
      var out = '', a = new Uint32Array(n); (window.crypto || window.msCrypto).getRandomValues(a);
      for (var i = 0; i < n; i++) out += set.charAt(a[i] % set.length);
      return out;
    };
    // Guarantee one of each class, then fill — so the suggestion always passes.
    var chars = (pick(lower, 4) + pick(upper, 3) + pick(nums, 3) + pick(syms, 2) + pick(all, 4)).split('');
    var r = new Uint32Array(chars.length); (window.crypto || window.msCrypto).getRandomValues(r);
    for (var i = chars.length - 1; i > 0; i--) { var j = r[i] % (i + 1), t = chars[i]; chars[i] = chars[j]; chars[j] = t; }
    return chars.join('');
  }

  function wireBiometrics(toggle, sub) {
    var bio = KT && KT.biometric;
    if (!bio) { if (sub) sub.textContent = 'Not available on this device.'; toggle.kt_disabled(true); return; }
    Promise.resolve(bio.available && bio.available()).then(function (kind) {
      var enabledNow = !!(bio.isEnabled && bio.isEnabled());
      // If the availability probe hiccups (KtBio can return a transient false) but
      // biometric IS already enabled, reflect the real stored state and still let
      // the user turn it off — don't show it as unavailable.
      if (!kind && !enabledNow) { if (sub) sub.textContent = 'Available only in the KiddieTrac app.'; toggle.kt_disabled(true); return; }
      var label = /face/i.test(kind) ? 'Face ID' : (/touch|finger/i.test(kind) ? 'Fingerprint' : 'Fingerprint / face');
      toggle.kt_disabled(false);
      function paint() {
        var on = bio.isEnabled && bio.isEnabled();
        if (sub) sub.textContent = on ? label + ' sign-in is on.' : 'Sign in with your ' + label.toLowerCase() + '.';
        toggle.kt_set(on);
      }
      paint();
      toggle.addEventListener('click', function () {
        if (toggle.disabled) return;
        toggle.kt_disabled(true);
        var was = bio.isEnabled && bio.isEnabled();
        var act = was ? bio.disable() : bio.enroll();
        Promise.resolve(act).then(function (ok) {
          toggle.kt_disabled(false); paint();
          if (!was && ok === false && sub) {
            var err = (bio.lastError && bio.lastError()) || '';
            sub.textContent = err ? ('Couldn’t enable: ' + err) : 'Enable was cancelled — tap the switch to try again.';
          }
        }).catch(function (e) {
          toggle.kt_disabled(false); paint();
          if (sub) sub.textContent = 'Biometric error: ' + ((e && e.message) || e);
        });
      });
    }).catch(function () { if (sub) sub.textContent = 'Available only in the KiddieTrac app.'; toggle.kt_disabled(true); });
  }

  function pinFlow(area, onDone) {
    Dom.clear ? Dom.clear(area) : (area.innerHTML = '');
    var box = el('div', { style: 'margin-top:10px;padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;' });
    var p1 = el('input', { type: 'password', inputmode: 'numeric', maxlength: '6', placeholder: 'New 4–6 digit PIN', style: INPUT + 'letter-spacing:4px;' });
    var p2 = el('input', { type: 'password', inputmode: 'numeric', maxlength: '6', placeholder: 'Confirm PIN', style: INPUT + 'letter-spacing:4px;margin-top:8px;' });
    var st = el('div', { style: 'font-size:12px;min-height:14px;margin-top:6px;' });
    var save = el('button', { type: 'button', style: 'border:none;background:#1F6080;color:#fff;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;margin-top:8px;' }, ['Save PIN']);
    save.addEventListener('click', function () {
      var v = (p1.value || '').trim();
      if (!/^\d{4,6}$/.test(v)) { st.style.color = '#B45309'; st.textContent = 'Enter a 4–6 digit PIN.'; return; }
      if (v !== p2.value) { st.style.color = '#B45309'; st.textContent = 'PINs don’t match.'; return; }
      if (/^(\d)\1+$/.test(v) || /^(0123|1234|2345|3456|4567|5678|6789|9876|4321|1111)/.test(v)) {
        st.style.color = '#B45309'; st.textContent = 'Pick a less guessable PIN.'; return;
      }
      save.disabled = true; save.textContent = 'Saving…';
      KT.pin.enroll(v).then(function (ok) {
        save.disabled = false; save.textContent = 'Save PIN';
        if (!ok) { st.style.color = '#B91C1C'; st.textContent = 'Could not set a PIN — sign in again and retry.'; return; }
        Dom.clear ? Dom.clear(area) : (area.innerHTML = '');
        if (onDone) onDone();
        if (KT.toast) KT.toast('🔢', 'PIN set', 'You can unlock KiddieTrac with your PIN next time.', '#159FB4');
      }).catch(function (e) {
        save.disabled = false; save.textContent = 'Save PIN';
        st.style.color = '#B91C1C'; st.textContent = 'Could not set a PIN: ' + ((e && e.message) || e);
      });
    });
    box.appendChild(p1); box.appendChild(p2); box.appendChild(st); box.appendChild(save);
    area.appendChild(box); p1.focus();
  }

  Shell.registerScreen('guardian:settings', render);
  Shell.registerScreen('home_visitor:settings', render);
  Shell.registerScreen('sales_rep:settings', render);
  Shell.registerScreen('platform_admin:settings', render);
  Shell.registerScreen('educator:settings', render);
  /* Agency admins and centre directors had no personal profile screen, which is the
     only reason two-factor stayed in their agency-settings menu. The panes are
     role-agnostic (Payments is already gated to guardians), so the same screen serves
     them — and two-factor is REQUIRED for these two roles, so this is the pair that
     most needed somewhere personal to manage it. */
  Shell.registerScreen('agency_admin:settings', render);
  Shell.registerScreen('centre_director:settings', render);
  window.KT.renderSettings = render;
})(window);
