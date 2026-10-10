/* ═══════════════════════════════════════════════════════════════════════
   kt-bio-nudge.js — "Families would love to meet you" card (2026-10-09)

   Anthony: "add reminders for educators to write a bio and show them where to do this if
   they have not done so and the importance behind this". A provider whose bio is missing
   or short (auth/me → bio_nudge, which also honours the agency's Settings → Notifications →
   Provider bios → "Show a reminder card") sees a small card on every screen saying why it
   matters, with one button that opens Settings → Profile on the bio box. "Later" hides it
   for the rest of the day; saving the bio hides it for good.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var ID = 'kt-bio-nudge';
  var LATER = 'kt_bio_nudge_later';

  function user() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; }
  }
  function today() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function snoozed() { try { return localStorage.getItem(LATER) === today(); } catch (e) { return false; } }
  function onSettings() { return /^#settings\b/.test(location.hash || ''); }

  function hide() { var el = document.getElementById(ID); if (el) el.remove(); }
  function show() {
    if (document.getElementById(ID)) return;
    var box = document.createElement('div');
    box.id = ID;
    box.setAttribute('role', 'status');
    box.setAttribute('data-kt-no-tone', '1');
    box.style.cssText = 'position:fixed;left:16px;bottom:calc(84px + env(safe-area-inset-bottom,0px));z-index:9000;max-width:360px;width:calc(100% - 32px);'
      + 'background:#fff;border:1px solid #CFE3EA;border-left:4px solid #2EA9AC;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.18);'
      + 'padding:12px 14px;font-family:inherit;color:#0F172A;';
    box.innerHTML = '<div style="display:flex;gap:10px;align-items:flex-start;">'
      + '<div style="font-size:22px;line-height:1;">✍️</div>'
      + '<div style="flex:1;min-width:0;">'
      +   '<div style="font-weight:800;font-size:14px;">Families would love to meet you</div>'
      +   '<div style="font-size:12.5px;color:#475569;line-height:1.45;margin-top:3px;">Your bio is the first thing a new family reads: it goes in their welcome email and on your provider card. '
      +   'Right now they see a placeholder. It takes about 2 minutes.</div>'
      +   '<div style="display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;">'
      +     '<a href="#settings?tab=profile&focus=bio" data-go style="display:inline-flex;align-items:center;height:30px;padding:0 12px;border-radius:8px;background:#1F6080;color:#fff;font-weight:700;font-size:13px;text-decoration:none;">Write my bio</a>'
      +     '<button type="button" data-later style="height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">Later</button>'
      +   '</div></div>'
      + '<button type="button" data-later aria-label="Close" style="border:0;background:none;color:#94A3B8;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;">×</button></div>';
    box.querySelectorAll('[data-later]').forEach(function (b) {
      b.addEventListener('click', function () { try { localStorage.setItem(LATER, today()); } catch (e) {} hide(); });
    });
    box.querySelector('[data-go]').addEventListener('click', function () { hide(); });
    document.body.appendChild(box);
  }

  function check() {
    var u = user();
    if (!u || !u.bio_nudge || !u.is_provider || snoozed() || onSettings()) { hide(); return; }
    show();
  }

  KT.bioNudge = { hide: hide, check: check };
  window.addEventListener('hashchange', function () { setTimeout(check, 300); });
  // After sign-in and once the shell has stored the user.
  setTimeout(check, 2500);
  setTimeout(check, 8000);
})(window, document);
