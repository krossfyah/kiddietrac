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
    wrap.appendChild(el('div', { style: 'font-size:18px;font-weight:800;color:#0f172a;margin:2px 2px 14px;' }, ['Settings']));

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

    // ── SECURITY ──
    var sc = el('div', { style: CARD });
    sc.appendChild(el('div', { style: SECT }, ['Security']));

    // change password
    var cur = field(sc, 'Current password', 'password', '');
    var nw = field(sc, 'New password', 'password', '');
    var cf = field(sc, 'Confirm new password', 'password', '');
    var pwStatus = el('div', { style: 'font-size:13px;min-height:16px;margin:4px 0 8px;' });
    var pwBtn = el('button', { type: 'button', style: btn() }, ['Change password']);
    pwBtn.addEventListener('click', function () {
      pwStatus.textContent = '';
      if (!cur.value || !nw.value) { pwStatus.style.color = '#B45309'; pwStatus.textContent = 'Fill in your current and new password.'; return; }
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
    bioRow.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['Fingerprint / Face sign-in']), el('div', { style: 'font-size:12px;color:#94A3B8;', id: 'kt-bio-sub' }, ['Checking…'])]));
    var bioToggle = el('button', { type: 'button', id: 'kt-bio-toggle', style: 'border:none;border-radius:20px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;background:#E2E8F0;color:#475569;' }, ['…']);
    bioRow.appendChild(bioToggle);
    sc.appendChild(bioRow);
    wireBiometrics(bioToggle);

    // quick-unlock PIN (client-side)
    sc.appendChild(el('hr', { style: 'border:none;border-top:1px solid #EEF2F6;margin:16px 0;' }));
    var pinHas = localStorage.getItem('kt_pin_hash');
    var pinRow = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:10px;' });
    pinRow.appendChild(el('div', {}, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;' }, ['Quick-unlock PIN']), el('div', { style: 'font-size:12px;color:#94A3B8;', id: 'kt-pin-sub' }, [pinHas ? 'A PIN is set.' : 'No PIN set.'])]));
    var pinBtn = el('button', { type: 'button', style: 'border:1.5px solid #cbd5e1;background:#fff;color:#1F6080;border-radius:10px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;' }, [pinHas ? 'Change' : 'Set PIN']);
    pinRow.appendChild(pinBtn);
    sc.appendChild(pinRow);
    var pinArea = el('div'); sc.appendChild(pinArea);
    pinBtn.addEventListener('click', function () { pinFlow(pinArea, pinBtn); });
    if (pinHas) {
      var rm = el('button', { type: 'button', style: 'background:none;border:none;color:#B91C1C;font-size:12px;font-weight:700;cursor:pointer;margin-top:8px;padding:2px;' }, ['Remove PIN']);
      rm.addEventListener('click', function () { localStorage.removeItem('kt_pin_hash'); localStorage.removeItem('kt_pin_enabled'); document.getElementById('kt-pin-sub').textContent = 'No PIN set.'; pinBtn.textContent = 'Set PIN'; rm.remove(); });
      sc.appendChild(rm);
    }

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

    // sign out
    var so = el('button', { type: 'button', style: 'display:block;margin:6px auto 0;background:none;border:none;color:#94A3B8;font:700 13px/1 inherit;cursor:pointer;padding:10px;' }, ['Sign out']);
    so.addEventListener('click', function () {
      try { if (window.KT && KT.biometric && KT.biometric.disable) KT.biometric.disable(); } catch (e) {}
      try { if (KT.Auth && KT.Auth.clear) KT.Auth.clear(); } catch (e) {}
      try { sessionStorage.clear(); localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user'); } catch (e) {}
      location.href = '/index.html';
    });
    wrap.appendChild(so);
  }

  function field(parent, label, type, val) {
    parent.appendChild(el('div', { style: LABEL, style: LABEL + 'margin-top:12px;' }, [label]));
    var i = el('input', { type: type, value: val || '', style: INPUT });
    parent.appendChild(i);
    return i;
  }
  function btn() { return 'width:100%;border:0;cursor:pointer;padding:14px;border-radius:12px;font-size:15px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);margin-top:8px;'; }

  function wireBiometrics(toggle) {
    var bio = KT && KT.biometric;
    var sub = document.getElementById('kt-bio-sub');
    if (!bio) { if (sub) sub.textContent = 'Not available on this device.'; toggle.textContent = '—'; toggle.disabled = true; return; }
    Promise.resolve(bio.available && bio.available()).then(function (kind) {
      if (!kind) { if (sub) sub.textContent = 'Available only in the KiddieTrac app.'; toggle.textContent = '—'; toggle.disabled = true; return; }
      var label = /face/i.test(kind) ? 'Face ID' : (/touch|finger/i.test(kind) ? 'Fingerprint' : 'Biometric');
      function paint() {
        var on = bio.isEnabled && bio.isEnabled();
        if (sub) sub.textContent = on ? label + ' sign-in is on.' : 'Sign in with ' + label + '.';
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

  async function sha(s) {
    try { var b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s)); return [].map.call(new Uint8Array(b), function (x) { return x.toString(16).padStart(2, '0'); }).join(''); }
    catch (e) { return 'plain:' + s; }
  }
  function pinFlow(area, btn) {
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
      sha(v).then(function (h) {
        localStorage.setItem('kt_pin_hash', h); localStorage.setItem('kt_pin_enabled', '1');
        document.getElementById('kt-pin-sub').textContent = 'A PIN is set.'; btn.textContent = 'Change';
        Dom.clear ? Dom.clear(area) : (area.innerHTML = '');
      });
    });
    box.appendChild(p1); box.appendChild(p2); box.appendChild(st); box.appendChild(save);
    area.appendChild(box); p1.focus();
  }

  Shell.registerScreen('guardian:settings', render);
  Shell.registerScreen('educator:settings', render);
  window.KT.renderSettings = render;
})(window);
