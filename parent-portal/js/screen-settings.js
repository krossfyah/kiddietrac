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
  var CARD = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:16px;margin-bottom:14px;';
  var LABEL = 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#475569;margin:0 0 5px;text-transform:uppercase;';
  var INPUT = 'width:100%;box-sizing:border-box;padding:12px 14px;font-size:16px;border:1.5px solid #E3EAF1;border-radius:11px;background:#fff;color:#0D1B2A;';
  var SECT = 'font-size:15px;font-weight:800;color:#0f172a;margin:0 0 12px;';

  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function absUrl(u) { if (!u) return u; if (/^https?:\/\//.test(u)) return u; return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u); }
  function cachedUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }

  async function render(main) {
    Dom.clear ? Dom.clear(main) : (main.innerHTML = '');
    // Extra bottom padding so the last controls (Remove PIN / Sign out) clear the
    // fixed mobile bottom bar instead of hiding behind it.
    var wrap = el('div', { style: 'padding:2px 2px calc(env(safe-area-inset-bottom,0px) + 96px);max-width:520px;margin:0 auto;' });
    main.appendChild(wrap);
    // No in-screen "Settings" title: the shell's auto-hero already titles the
    // page, and rendering our own stacked a second "Settings" under it.

    var u = cachedUser();
    try { var fresh = await Api.get('/auth/me'); if (fresh) u = fresh.user || fresh; } catch (e) {}

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

    var pStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:4px 0 8px;' });
    var pSave = el('button', { type: 'button', style: btn() }, ['Save profile']);
    function profileStatus(m, err) { pStatus.style.color = err ? '#B91C1C' : '#16A34A'; pStatus.textContent = m; }
    pSave.addEventListener('click', function () {
      pSave.disabled = true; pSave.textContent = 'Saving…'; pStatus.textContent = '';
      Api.patch('/auth/me', { first_name: first.value.trim(), last_name: last.value.trim(), phone: phone.value.trim(), date_of_birth: dob.value || null })
        .then(function () { pSave.disabled = false; pSave.textContent = 'Save profile'; profileStatus('✓ Saved.'); try { var ku = cachedUser(); ku.first_name = first.value.trim(); ku.last_name = last.value.trim(); ku.phone = phone.value.trim(); ku.date_of_birth = dob.value; sessionStorage.setItem('kt_user', JSON.stringify(ku)); } catch (e) {} })
        .catch(function (e) { pSave.disabled = false; pSave.textContent = 'Save profile'; profileStatus('Could not save: ' + (e && e.message ? e.message : 'try again'), true); });
    });
    pc.appendChild(pStatus); pc.appendChild(pSave);
    wrap.appendChild(pc);

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
    wrap.appendChild(lc);

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
    var pwBtn = el('button', { type: 'button', style: btn() }, ['Change password']);
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

    // biometric toggle (native only)
    sc.appendChild(el('hr', { style: 'border:none;border-top:1px solid #EEF2F6;margin:16px 0;' }));
    var bioRow = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;' });
    var bioSub = el('div', { style: 'font-size:12px;color:#94A3B8;', id: 'kt-bio-sub' }, ['Checking…']);
    bioRow.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['Fingerprint / Face sign-in']), bioSub]));
    var bioToggle = el('button', { type: 'button', id: 'kt-bio-toggle', style: 'border:none;border-radius:20px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;background:#E2E8F0;color:#475569;' }, ['…']);
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
    var pinSub = el('div', { style: 'font-size:12px;color:#94A3B8;' },
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
      var ntBtn = el('button', { type: 'button', style: 'background:linear-gradient(135deg,#0FA3B1,#1F6FB2);border:0;color:#fff;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:800;cursor:pointer;' }, ['Send test notification']);
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
          var uaSub = el('div', { style: 'font-size:12px;color:#94A3B8;' },
            [KT.urgentAlert.isEnabled() ? 'New chats and alerts take over the screen with sound.' : 'New chats and alerts only show a badge.']);
          ua.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['🚨 Urgent alerts']), uaSub]));
          var uaBtn = el('button', { type: 'button', style: 'border:none;border-radius:20px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;' }, ['…']);
          var paintUa = function () {
            var on = KT.urgentAlert.isEnabled();
            uaBtn.textContent = on ? 'On' : 'Off';
            uaBtn.style.background = on ? '#159FB4' : '#E2E8F0';
            uaBtn.style.color = on ? '#fff' : '#475569';
            uaSub.textContent = on ? 'New chats and alerts take over the screen with sound.' : 'New chats and alerts only show a badge.';
          };
          uaBtn.addEventListener('click', function () { KT.urgentAlert.setEnabled(!KT.urgentAlert.isEnabled()); paintUa(); });
          paintUa();
          ua.appendChild(uaBtn);
          nd.appendChild(ua);
          var uaTest = el('button', { type: 'button', style: 'background:none;border:none;color:#159FB4;font-size:12px;font-weight:700;cursor:pointer;padding:8px 0 0;' }, ['Preview an urgent alert']);
          uaTest.addEventListener('click', function () { KT.urgentAlert.test('message'); });
          nd.appendChild(uaTest);
        }
      } catch (e) {}

      nd.appendChild(ntBtn); nd.appendChild(ntMsg);
      wrap.appendChild(nd);

      // ── What you get told about, and how ──
      // Sign-in/out alerts: email and in-app on by default, SMS off — every text
      // costs the centre money, so nobody is opted into it silently.
      try {
        var pc2 = el('div', { style: CARD });
        pc2.appendChild(el('div', { style: SECT }, ['Notify me about…']));
        var prefsBody = el('div', {});
        pc2.appendChild(prefsBody);
        prefsBody.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px;padding:6px 0;' }, ['Loading…']));
        wrap.appendChild(pc2);

        Api.get('/me/notification-prefs').then(function (res) {
          Dom.clear ? Dom.clear(prefsBody) : (prefsBody.innerHTML = '');
          (res.preferences || []).forEach(function (p) {
            var row = el('div', { style: 'padding:6px 0;' });
            row.appendChild(el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, [p.label]));
            row.appendChild(el('div', { style: 'font-size:12px;color:#94A3B8;margin-bottom:9px;' }, [p.hint]));

            var chans = el('div', { style: 'display:flex;gap:7px;flex-wrap:wrap;' });
            var state = { email: !!p.email, push: !!p.push, sms: !!p.sms };
            [['email', '✉️ Email'], ['push', '🔔 In-app'], ['sms', '💬 Text']].forEach(function (c) {
              var key = c[0];
              var b = el('button', { type: 'button', style: 'border-radius:999px;padding:9px 14px;font-size:13px;font-weight:800;cursor:pointer;border:1.5px solid;' }, [c[1]]);
              var paintChan = function () {
                var on = state[key];
                b.style.background = on ? '#159FB4' : '#fff';
                b.style.color = on ? '#fff' : '#64748B';
                b.style.borderColor = on ? '#159FB4' : '#E2E8F0';
              };
              b.addEventListener('click', function () {
                state[key] = !state[key];
                paintChan();
                Api.put('/me/notification-prefs', {
                  key: p.key, email: state.email, push: state.push, sms: state.sms,
                }).catch(function () {
                  state[key] = !state[key]; paintChan();     // put it back
                  if (KT.toast) KT.toast('⚠️', 'Could not save', 'Please try again.', '#B91C1C');
                });
              });
              paintChan();
              chans.appendChild(b);
            });
            row.appendChild(chans);
            prefsBody.appendChild(row);
          });
        }).catch(function () {
          Dom.clear ? Dom.clear(prefsBody) : (prefsBody.innerHTML = '');
          prefsBody.appendChild(el('div', { style: 'color:#94A3B8;font-size:13px;' }, ['Could not load your notification settings.']));
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
        wrap.appendChild(dc);
      }
    } catch (e) {}

    wrap.appendChild(sc);
    // Sign out deliberately does NOT live here any more — there is exactly one
    // sign-out button, on the home screen, so it's always in the same place.
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
  function btn() { return 'width:100%;border:0;cursor:pointer;padding:14px;border-radius:12px;font-size:15px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);margin-top:8px;'; }

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
    var word = el('div', { style: 'font-size:12px;font-weight:700;margin-top:5px;min-height:15px;color:#94A3B8;' }, ['Enter a new password']);
    var list = el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:6px;' });
    var marks = {};
    PW_RULES.forEach(function (r) {
      var m = el('div', { style: 'font-size:11.5px;color:#94A3B8;display:flex;align-items:center;gap:4px;' }, ['○ ' + r.label]);
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
    if (!bio) { if (sub) sub.textContent = 'Not available on this device.'; toggle.textContent = '—'; toggle.disabled = true; return; }
    Promise.resolve(bio.available && bio.available()).then(function (kind) {
      if (!kind) { if (sub) sub.textContent = 'Available only in the KiddieTrac app.'; toggle.textContent = '—'; toggle.disabled = true; return; }
      // Both the native plugin and WebAuthn just report "biometric" — they don't
      // say which sensor the phone has — so the generic case names both.
      var label = /face/i.test(kind) ? 'Face ID' : (/touch|finger/i.test(kind) ? 'Fingerprint' : 'Fingerprint / face');
      function paint() {
        var on = bio.isEnabled && bio.isEnabled();
        if (sub) sub.textContent = on ? label + ' sign-in is on.' : 'Sign in with your ' + label.toLowerCase() + '.';
        toggle.textContent = on ? 'On' : 'Off';
        toggle.style.background = on ? '#0E7C90' : '#E2E8F0';
        toggle.style.color = on ? '#fff' : '#475569';
      }
      paint();
      toggle.addEventListener('click', function () {
        toggle.disabled = true;
        var was = bio.isEnabled && bio.isEnabled();
        var act = was ? bio.disable() : bio.enroll();
        Promise.resolve(act).then(function (ok) {
          toggle.disabled = false; paint();
          // Surface why enabling didn't take (cancelled / device error) instead of
          // failing silently — helps diagnose device-specific biometric issues.
          if (!was && ok === false && sub) {
            var err = (bio.lastError && bio.lastError()) || '';
            sub.textContent = err ? ('Couldn’t enable: ' + err) : 'Enable was cancelled — tap On to try again.';
          }
        }).catch(function (e) {
          toggle.disabled = false; paint();
          if (sub) sub.textContent = 'Biometric error: ' + ((e && e.message) || e);
        });
      });
    }).catch(function () { if (sub) sub.textContent = 'Available only in the KiddieTrac app.'; toggle.disabled = true; toggle.textContent = '—'; });
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
  Shell.registerScreen('educator:settings', render);
  window.KT.renderSettings = render;
})(window);
