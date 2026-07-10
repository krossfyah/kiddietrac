/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — social sign-in on the login page (2026-07-08)
   Shows a "Continue with …" button for each provider the server reports as
   configured (GET /auth/social/providers), and handles the OAuth callback
   (token arrives in the URL fragment). Providers with no credentials yet
   simply don't appear. Include on index.html only.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';

  var META = {
    google:    { label: 'Continue with Google',    bg: '#fff',     color: '#3c4043', border: '#dadce0', icon: 'G' },
    microsoft: { label: 'Continue with Microsoft', bg: '#fff',     color: '#3c4043', border: '#dadce0', icon: '⊞' },
    facebook:  { label: 'Continue with Facebook',  bg: '#1877F2',  color: '#fff',    border: '#1877F2', icon: 'f' }
  };

  function showError(msg) {
    var host = document.querySelector('.auth-form') || document.body;
    var box = document.createElement('div');
    box.style.cssText = 'background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;border-radius:8px;padding:10px 12px;font-size:13px;margin:10px 0;';
    box.textContent = msg;
    host.insertBefore(box, host.firstChild);
  }

  // OAuth return: token in the fragment (#kt_social=…) → store + go to dashboard.
  function handleCallback() {
    var m = (location.hash || '').match(/kt_social=([^&]+)/);
    if (m) {
      var token = decodeURIComponent(m[1]);
      try { history.replaceState(null, '', location.pathname); } catch (e) { location.hash = ''; }
      sessionStorage.setItem('kt_token', token);
      var now = String(Date.now());
      sessionStorage.setItem('kt_login_at', now);
      sessionStorage.setItem('kt_last_activity', now);
      fetch(API + '/auth/me', { headers: { 'Authorization': 'Bearer ' + token } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (u) { if (u) sessionStorage.setItem('kt_user', JSON.stringify(u.user || u)); location.replace('/dashboard.html'); })
        .catch(function () { location.replace('/dashboard.html'); });
      return true;
    }
    var err = (location.search || '').match(/social_error=([^&]+)/);
    if (err) { try { showError(decodeURIComponent(err[1].replace(/\+/g, ' '))); } catch (e) {} }
    return false;
  }

  function injectButtons() {
    var form = document.getElementById('loginForm') || document.querySelector('.auth-form');
    if (!form || document.getElementById('kt-social')) return;
    fetch(API + '/auth/social/providers')
      .then(function (r) { return r.ok ? r.json() : { providers: [] }; })
      .then(function (d) {
        var ps = (d && d.providers) || [];
        if (!ps.length) return;                     // nothing configured yet
        var wrap = document.createElement('div');
        wrap.id = 'kt-social'; wrap.style.cssText = 'margin-bottom:6px;';
        // Buttons FIRST (above the form), then an "or" divider, so social sign-in
        // is the prominent, first option on the login page.
        ps.forEach(function (p) {
          var m = META[p] || { label: 'Continue with ' + p, bg: '#fff', color: '#333', border: '#ddd', icon: '•' };
          var a = document.createElement('a');
          a.href = API + '/auth/social/' + p + '/redirect';
          a.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:10px;width:100%;box-sizing:border-box;padding:11px 14px;margin-bottom:9px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;border:1px solid ' + m.border + ';background:' + m.bg + ';color:' + m.color + ';';
          a.innerHTML = '<span style="font-weight:800;font-size:16px;">' + m.icon + '</span>' + m.label;
          wrap.appendChild(a);
        });
        var sep = document.createElement('div');
        sep.style.cssText = 'display:flex;align-items:center;gap:10px;color:#94A3B8;font-size:12px;margin:12px 0 14px;';
        sep.innerHTML = '<span style="flex:1;height:1px;background:#E2E8F0;"></span>or sign in with email<span style="flex:1;height:1px;background:#E2E8F0;"></span>';
        wrap.appendChild(sep);
        form.parentNode.insertBefore(wrap, form);
      })
      .catch(function () {});
  }

  if (handleCallback()) return;                     // redirecting away — skip button injection
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectButtons);
  else injectButtons();
})();
