/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — slide-in toasts (2026-07-08).
   • Welcome toast once per sign-in.
   • New-message toasts (polls the accurate unread-message count and slides in
     when it goes up — messages from any user).
   Exposes window.KT.toast(icon,title,body,color) for other code to reuse.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktToasts) return; window.__ktToasts = true;
  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }

  function container() {
    var c = document.getElementById('kt-toast-wrap');
    if (!c) {
      c = document.createElement('div'); c.id = 'kt-toast-wrap';
      c.style.cssText = 'position:fixed;top:14px;right:14px;z-index:12000;display:flex;flex-direction:column;gap:10px;max-width:340px;pointer-events:none;';
      document.body.appendChild(c);
      var st = document.createElement('style');
      st.textContent = '@keyframes kt-toast-in{from{opacity:0;transform:translateX(44px);}to{opacity:1;transform:none;}}@keyframes kt-toast-out{to{opacity:0;transform:translateX(44px);}}';
      document.head.appendChild(st);
    }
    return c;
  }
  function toast(icon, title, body, color) {
    var c = container();
    var t = document.createElement('div');
    t.style.cssText = 'pointer-events:auto;display:flex;gap:11px;align-items:flex-start;background:#fff;border:1px solid #E7EBF0;border-left:4px solid ' + (color || '#0E7C90') + ';border-radius:12px;padding:12px 14px;box-shadow:0 12px 30px -12px rgba(15,23,42,.4);animation:kt-toast-in .35s cubic-bezier(.2,.7,.2,1) both;font-family:system-ui,-apple-system,sans-serif;';
    t.innerHTML = '<span style="font-size:20px;line-height:1.15;flex-shrink:0;">' + icon + '</span>' +
      '<div style="min-width:0;"><div style="font-weight:700;font-size:13.5px;color:#0D1B2A;">' + title + '</div>' +
      (body ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + body + '</div>' : '') + '</div>';
    var x = document.createElement('button'); x.textContent = '×'; x.setAttribute('aria-label', 'Dismiss');
    x.style.cssText = 'margin-left:auto;border:none;background:transparent;color:#94A3B8;font-size:17px;cursor:pointer;line-height:1;flex-shrink:0;';
    var timer = setTimeout(function () { dismiss(); }, 6000);
    function dismiss() { clearTimeout(timer); t.style.animation = 'kt-toast-out .3s ease forwards'; setTimeout(function () { t.remove(); }, 320); }
    x.onclick = dismiss; t.appendChild(x);
    c.appendChild(t);
    return t;
  }
  window.KT = window.KT || {}; window.KT.toast = toast;

  function welcome() {
    try { if (sessionStorage.getItem('kt_welcomed') === '1') return; sessionStorage.setItem('kt_welcomed', '1'); } catch (e) {}
    var u = user(); var name = (u.name || '').split(' ')[0] || 'there';
    var h = new Date().getHours();
    var g = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night';
    toast('👋', g + ', ' + name + '!', 'Welcome back to KiddieTrac.', '#0E7C90');
  }

  var lastCount = null;
  function pollMessages() {
    var t = tok(); if (!t) return;
    fetch(API + '/chats/unread-count', { headers: { 'Authorization': 'Bearer ' + t } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var n = d.unread_count != null ? d.unread_count : (d.count != null ? d.count : (d.unread != null ? d.unread : 0));
        n = parseInt(n, 10) || 0;
        if (lastCount !== null && n > lastCount) {
          var diff = n - lastCount;
          toast('💬', diff === 1 ? 'New message' : diff + ' new messages', 'Open Messages to read.', '#1F6080');
        }
        lastCount = n;
      }).catch(function () {});
  }

  function start() {
    if (!tok()) return;
    welcome();
    pollMessages();
    setInterval(pollMessages, 12000);   // near-real-time message alerts
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
