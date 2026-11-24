/* ═══════════════════════════════════════════════════════════════════
   kt-privacy-notice.js: the agency's privacy notice, acknowledged (2026-11-10).

   PIPEDA review. The Data retention switch "Require parent consent at
   enrolment" was saved and never read: no parent was shown the notice and
   nothing was recorded. Now a parent at an agency with it on (the default)
   gets this once the portal is up: who keeps their family's information,
   a link to the agency's privacy notice (or KiddieTrac's, if the agency
   has not set its own), and "I've read it". API: PrivacyNoticeController,
   one row per parent per agency per notice version. When the agency
   changes its notice, the parent is asked again.

   Like the photo permission ask (kt-photo-consent.js) it has no ✕: it is
   one tap, and it steps aside for anything urgent or any other required
   gate and comes back after. A super admin viewing as a parent is never
   asked. Photos, texts and marketing keep their own separate yes/no.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPrivacyNotice) return; window.__ktPrivacyNotice = true;

  var ID = 'kt-privacy-notice';
  var PATH = '/me/privacy-notice';
  var HEAD = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';

  function T(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function api() { return window.KT && KT.Api; }
  function toast(icon, title, msg, colour) { try { if (window.KT && KT.toast) KT.toast(icon, title, msg || '', colour); } catch (e) {} }

  function styles() {
    return '<style>'
      + '#' + ID + '{position:fixed;inset:0;z-index:2147482000;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:16px;}'
      + '#' + ID + ' .kpn-card{background:#fff;color:#0F172A;border-radius:16px;width:100%;max-width:520px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}'
      + '#' + ID + ' .kpn-head{background:' + HEAD + ';box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;padding:14px 18px 15px;flex:0 0 auto;}'
      + '#' + ID + ' .kpn-head h2{margin:0;font-size:17px;font-weight:700;color:#fff;}'
      + '#' + ID + ' .kpn-head div{font-size:12.5px;opacity:.9;margin-top:3px;}'
      + '#' + ID + ' .kpn-body{padding:14px 18px;overflow:auto;flex:1 1 auto;scrollbar-width:thin;font-size:13px;color:#334155;line-height:1.5;}'
      + '#' + ID + ' .kpn-body p{margin:0 0 10px;}'
      + '#' + ID + ' .kpn-link{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 14px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#0F2540;font-size:13px;font-weight:700;text-decoration:none;}'
      + '#' + ID + ' .kpn-foot{padding:10px 18px 14px;border-top:1px solid #E7EBF0;display:flex;gap:10px;align-items:center;flex:0 0 auto;}'
      + '#' + ID + ' .kpn-ok{height:32px;padding:0 16px;border-radius:8px;border:1px solid #0F2540;background:#0F2540;color:#fff;font-size:13px;font-weight:700;cursor:pointer;}'
      + '#' + ID + ' .kpn-ok[disabled]{opacity:.45;cursor:default;}'
      + '#' + ID + ' .kpn-msg{font-size:12.5px;color:#64748B;flex:1 1 auto;}'
      + '</style>';
  }

  // Anything that must come first, or anything urgent - including the photo permission ask - goes first.
  function yieldNow() {
    var sel = '#kt-splash:not(.kt-hide), #kt-launch, #kt-pin-lock, #kt-bio-lock, #kt-bio-ov, #kt-agree, #kt-pw-gate, #kt-force-password, #kt-mfa-gate, '
      + '#kt-upd, #kt-appupdate, #kt-update-required, #kt-urgent, .kt-urgent, #kt-session-timeout, #kt-session-warning, #kt-notify-gate, '
      + '.kt-dialer-call, #kt-call-screen, #kt-dpop, #kt-onboarding-overlay, #kt-photo-consent';
    var els = document.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      if ((els[i].offsetWidth > 0 || els[i].offsetHeight > 0) && getComputedStyle(els[i]).display !== 'none' && getComputedStyle(els[i]).opacity !== '0') return true;
    }
    if (document.documentElement.classList.contains('kt-pw-locked')) return true;
    return /^#(safe-arrival|headcounts?|emergency|onboarding|pending)/.test(location.hash || '');
  }

  var asking = false, lastLook = 0;
  function isParent() {
    var u = user();
    return [].concat(u.roles || [], u.primary_role || []).indexOf('guardian') >= 0;
  }

  function look(force) {
    if (asking || !token() || !api() || !isParent() || document.getElementById(ID)) return;
    if (!force && Date.now() - lastLook < 60000) return;
    if (yieldNow()) { setTimeout(function () { look(true); }, 5000); return; }
    lastLook = Date.now();
    asking = true;
    api().get(PATH).then(function (data) {
      asking = false;
      if (!data || data.viewing_as || !data.pending || !data.pending.length) return;
      if (yieldNow()) { setTimeout(function () { look(true); }, 5000); return; }
      show(data.pending[0], data.pending.length);
    }).catch(function () { asking = false; });
  }

  function show(n, count) {
    var g = document.createElement('div');
    g.id = ID;
    g.setAttribute('role', 'dialog'); g.setAttribute('aria-modal', 'true'); g.setAttribute('aria-labelledby', ID + '-t');
    g.setAttribute('data-kt-no-close', '1'); g.setAttribute('data-kt-centre-always', '1'); g.setAttribute('data-no-modal-guard', '');
    g.innerHTML = styles()
      + '<div class="kpn-card"><div class="kpn-head"><h2 id="' + ID + '-t">🛡️ ' + esc(T('Your family’s privacy')) + '</h2>'
      + '<div>' + esc(n.agency_name) + (count > 1 ? ' · ' + esc(T('one of')) + ' ' + count : '') + '</div></div>'
      + '<div class="kpn-body">'
      + '<p>' + esc(n.agency_name) + ' ' + esc(T('keeps your family’s information - contact details, your child’s care, attendance, health and billing records - in KiddieTrac, to care for your child and run the centre.')) + '</p>'
      + '<p>' + esc(T('Before you carry on, please read how it is collected, used, shared and protected, and what you can ask for: to see it, correct it, or withdraw consent.')) + '</p>'
      + '<p><a class="kpn-link" href="' + esc(n.url) + '" target="_blank" rel="noopener">📄 ' + esc(T('Read the privacy notice')) + '</a></p>'
      + (n.contact ? '<p style="font-size:12.5px;color:#64748B;">' + esc(T('Questions about your information?')) + ' <a href="mailto:' + esc(n.contact) + '">' + esc(n.contact) + '</a></p>' : '')
      + '<p style="font-size:12.5px;color:#64748B;margin:0;">' + esc(T('Photo, text and marketing permissions are asked separately, and you can change them any time in My settings.')) + '</p>'
      + '</div>'
      + '<div class="kpn-foot"><div class="kpn-msg" aria-live="polite"></div><button type="button" class="kpn-ok">' + esc(T('I’ve read it')) + '</button></div></div>';
    document.body.appendChild(g);
    var ok = g.querySelector('.kpn-ok'), msg = g.querySelector('.kpn-msg');
    g.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); } }, true);
    ok.onclick = function () {
      ok.disabled = true; msg.style.color = '#64748B'; msg.textContent = T('Saving…');
      api().post(PATH, { agency_id: n.agency_id, version: n.version }).then(function (res) {
        g.remove();
        toast('🛡️', T('Thank you'), '', '#1E8E60');
        if (res && res.pending && res.pending.length) setTimeout(function () { look(true); }, 1200);
      }).catch(function (e) {
        // 409: the notice changed while it was open - show the new one.
        if (e && (e.status === 409 || /updated/i.test(e.message || ''))) { g.remove(); setTimeout(function () { look(true); }, 300); return; }
        ok.disabled = false; msg.style.color = '#BE4038'; msg.textContent = (e && e.message) || T('That did not save. Try again.');
      });
    };
    setTimeout(function () { try { ok.focus(); } catch (e) {} }, 50);
  }

  (function boot() {
    var n = 0;
    (function wait() {
      if (token() && document.getElementById('appMain') && api()) { setTimeout(function () { look(true); }, 4500); return; }
      if (++n < 120) setTimeout(wait, 1000);
    })();
    window.addEventListener('hashchange', function () { setTimeout(function () { look(false); }, 1500); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) look(false); });
  })();

  window.KT = window.KT || {};
  KT.privacyNotice = { ask: function () { look(true); } };
})();
