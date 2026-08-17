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
        // Clean, icon-only buttons (real brand logos) in a centered row.
        var ICONS = {
          google: '<svg width="26" height="26" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
          microsoft: '<svg width="24" height="24" viewBox="0 0 23 23" aria-hidden="true"><rect x="1" y="1" width="10" height="10" fill="#F25022"/><rect x="12" y="1" width="10" height="10" fill="#7FBA00"/><rect x="1" y="12" width="10" height="10" fill="#00A4EF"/><rect x="12" y="12" width="10" height="10" fill="#FFB900"/></svg>',
          facebook: '<svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12c0-6.63-5.37-12-12-12S0 5.37 0 12c0 5.99 4.39 10.95 10.13 11.85v-8.38H7.08V12h3.05V9.41c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.23 2.68.23v2.95h-1.51c-1.49 0-1.95.92-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38C19.61 22.95 24 17.99 24 12z"/></svg>'
        };
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;justify-content:center;gap:14px;margin-bottom:2px;';
        ps.forEach(function (p) {
          var name = p.charAt(0).toUpperCase() + p.slice(1);
          var a = document.createElement('a');
          a.href = API + '/auth/social/' + p + '/redirect';
          a.title = 'Continue with ' + name;
          a.setAttribute('aria-label', 'Continue with ' + name);
          a.style.cssText = 'display:flex;align-items:center;justify-content:center;width:58px;height:58px;border-radius:15px;border:1px solid #E2E8F0;background:#fff;text-decoration:none;box-shadow:0 1px 3px rgba(15,23,42,.06);transition:transform .12s ease, box-shadow .12s ease;';
          a.addEventListener('mouseenter', function () { a.style.transform = 'translateY(-2px)'; a.style.boxShadow = '0 8px 18px -8px rgba(15,23,42,.28)'; });
          a.addEventListener('mouseleave', function () { a.style.transform = ''; a.style.boxShadow = '0 1px 3px rgba(15,23,42,.06)'; });
          a.innerHTML = ICONS[p] || ('<span style="font-weight:800;font-size:20px;color:#334155;">' + (name.charAt(0) || '?') + '</span>');
          row.appendChild(a);
        });
        wrap.appendChild(row);
        var sep = document.createElement('div');
        sep.style.cssText = 'display:flex;align-items:center;gap:10px;color:#64748B;font-size:12px;margin:14px 0;';
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
