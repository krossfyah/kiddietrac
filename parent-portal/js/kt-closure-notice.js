/* ═══════════════════════════════════════════════════════════════════
   kt-closure-notice.js — tell people the centre is closed, when they arrive.

   A closure used to produce an in-app bell for guardians and nothing else. A bell
   is something you notice eventually; a closure is something you need to know
   BEFORE you get in the car. So it is said plainly on arrival, once, to both
   staff and parents.

   The server decides whether there is anything to say (Closures::noticeForUser)
   and how to word it. This file only decides WHEN to show it — the wording lives
   with the dates it describes.

   ONCE. Keyed on the closure and the day, so it appears once per day rather than
   on every screen change, and a multi-day closure greets you each morning
   without nagging all afternoon. Dismissal is remembered per device, which is
   the right granularity: the same person on their phone and the front-desk PC
   should be told on both.
   ═══════════════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function storedUser() {
    try {
      return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
    } catch (e) { return {}; }
  }

  /* The agency's date, not the device's — a notice about "today" that flips over at
     the wrong midnight would re-appear at 8pm for a closure that has passed. */
  function agencyToday() {
    try { if (KT.agencyToday) { return KT.agencyToday(); } } catch (e) {}
    return new Date().toISOString().slice(0, 10);
  }

  function seenKey(n) { return 'kt_closure_seen_' + n.id + '_' + agencyToday(); }

  function alreadySeen(n) {
    try { return localStorage.getItem(seenKey(n)) === '1'; } catch (e) { return false; }
  }

  function markSeen(n) {
    try {
      localStorage.setItem(seenKey(n), '1');
      // Yesterday's keys are dead weight; clear them rather than growing the store
      // by one entry per closure per day forever.
      var today = agencyToday();
      for (var i = localStorage.length - 1; i >= 0; i--) {
        var k = localStorage.key(i);
        if (k && k.indexOf('kt_closure_seen_') === 0 && k.indexOf(today) === -1) {
          localStorage.removeItem(k);
        }
      }
    } catch (e) {}
  }

  function show(n) {
    if (!n || alreadySeen(n)) { return; }

    var isToday = !!n.is_today;
    var accent = isToday ? '#F59E0B' : '#159FB4';
    var tint = isToday ? '#FEF3C7' : '#E0F2FE';

    var back = document.createElement('div');
    back.setAttribute('role', 'dialog');
    back.setAttribute('aria-modal', 'true');
    back.style.cssText = 'position:fixed;inset:0;background:rgba(13,27,42,.55);z-index:99999;'
      + 'display:flex;align-items:center;justify-content:center;padding:20px;';

    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:16px;max-width:430px;width:100%;'
      + 'padding:26px;box-shadow:0 20px 60px rgba(15,23,42,.28);text-align:center;';

    var icon = document.createElement('div');
    icon.style.cssText = 'width:62px;height:62px;border-radius:50%;background:' + tint + ';color:' + accent
      + ';display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 14px;';
    icon.textContent = isToday ? '🌷' : '📅';
    card.appendChild(icon);

    var h = document.createElement('h3');
    h.style.cssText = 'margin:0 0 8px;font-size:19px;font-weight:800;color:#0F172A;';
    h.textContent = n.title || 'Centre closure';
    card.appendChild(h);

    var p = document.createElement('p');
    p.style.cssText = 'margin:0 0 6px;font-size:14.5px;line-height:1.6;color:#475569;';
    p.textContent = n.message || '';
    card.appendChild(p);

    if (n.dates) {
      var d = document.createElement('div');
      d.style.cssText = 'margin:14px 0 0;padding:10px 14px;background:#F8FAFC;border-radius:10px;'
        + 'font-size:13px;font-weight:700;color:#334155;';
      d.textContent = n.dates + (n.reason ? ' · ' + n.reason : '');
      card.appendChild(d);
    }

    var ok = document.createElement('button');
    ok.type = 'button';
    ok.textContent = isToday ? 'Thanks, got it' : 'Good to know';
    ok.dataset.ktIconized = '1';   // keep the words; do not auto-collapse to an icon
    ok.style.cssText = 'margin-top:18px;width:100%;background:' + accent + ';color:#fff;border:0;'
      + 'padding:13px;border-radius:11px;font-size:15px;font-weight:800;cursor:pointer;';
    card.appendChild(ok);

    function close() {
      markSeen(n);
      if (back.parentNode) { back.parentNode.removeChild(back); }
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') { close(); } }

    ok.addEventListener('click', close);
    back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
    document.addEventListener('keydown', onKey);

    back.appendChild(card);
    document.body.appendChild(back);
    ok.focus();
  }

  /* The notice rides along on the login payload, so the common case costs no request.
     A session that predates this feature has no such key, and asking /auth/me once is
     cheaper than making everybody sign in again to see it. */
  function start() {
    var u = storedUser();
    if (u && u.closure_notice) { show(u.closure_notice); return; }
    if (!u || !u.id || !window.Api || !Api.get) { return; }

    Api.get('/auth/me').then(function (me) {
      var n = (me && (me.closure_notice || (me.user && me.user.closure_notice))) || null;
      if (n) { show(n); }
    }).catch(function () { /* never block the app for a notice */ });
  }

  KT.showClosureNotice = show;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 900); });
  } else {
    setTimeout(start, 900);
  }
})(window, document);
