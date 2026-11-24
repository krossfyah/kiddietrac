/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Web Push client
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    return res.ok ? res.json() : null;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  /**
   * Ask permission and subscribe to push.
   * Returns: { status: 'subscribed' | 'denied' | 'unsupported' | 'not_configured' | 'error', detail? }
   */
  async function subscribeIfPossible(forceAsk) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return { status: 'unsupported' };
    if (!token()) return { status: 'error', detail: 'Not signed in' };

    let pubKey;
    try {
      const r = await api('GET', '/push/public-key');
      if (!r || !r.configured || !r.public_key) return { status: 'not_configured' };
      pubKey = r.public_key;
    } catch (e) { return { status: 'error', detail: e.message }; }

    let reg;
    try {
      reg = await navigator.serviceWorker.ready;
    } catch (e) { return { status: 'error', detail: 'Service worker not registered' }; }

    let existing = await reg.pushManager.getSubscription();
    if (existing) {
      // Re-send to backend in case server doesn't have it
      try { await api('POST', '/push/subscribe', { subscription: existing.toJSON() }); } catch (e) {}
      return { status: 'subscribed' };
    }

    // Need permission
    if (typeof Notification === 'undefined') return { status: 'unsupported' };
    let permission = Notification.permission;
    if (permission === 'default' && forceAsk) {
      permission = await Notification.requestPermission();
    }
    if (permission !== 'granted') return { status: 'denied' };

    let sub;
    try {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(pubKey),
      });
    } catch (e) { return { status: 'error', detail: e.message }; }

    try {
      await api('POST', '/push/subscribe', { subscription: sub.toJSON(), device_name: navigator.userAgent.substring(0, 120) });
    } catch (e) { return { status: 'error', detail: e.message }; }

    return { status: 'subscribed' };
  }

  async function unsubscribe() {
    if (!('serviceWorker' in navigator)) return false;
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        const endpoint = sub.endpoint;
        await sub.unsubscribe();
        try { await api('POST', '/push/unsubscribe', { endpoint }); } catch (e) {}
        return true;
      }
    } catch (e) {}
    return false;
  }

  async function status() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return 'unsupported';
    if (typeof Notification === 'undefined') return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      return sub ? 'subscribed' : 'unsubscribed';
    } catch (e) { return 'unknown'; }
  }

  // Auto-subscribe at login if permission already granted; never prompt without user click
  function maybeAutoSubscribe() {
    if (!token()) return;
    // The iPhone app's web view has no Notification API at all (2026-11-02 if): this threw on every load there.
    // Native push in the apps is kt-native-push.js.
    if (typeof Notification === 'undefined') return;
    if (Notification.permission === 'granted') {
      subscribeIfPossible(false).catch(() => {});
    }
  }

  /* ── BLOCKED IS NOT A DEAD END (2026-10-22) ──

     Anthony, on his Android phone: Message Centre showed a pale red "Notifications
     blocked" button that did nothing. It was disabled, and its only help was a hover
     tooltip, which a phone never shows. A site cannot lift a block itself — the browser
     will not even ask again — so the honest fix is to say exactly where the switch is on
     THIS device, and to notice the moment it is turned back on.

     KT.Push.helpBlocked() opens those steps; KT.Push.onChange(fn) calls fn when the
     browser's notification permission changes while the page is open. */
  function T(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function device() {
    var ua = navigator.userAgent || '';
    var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var installed = false;
    try { installed = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches; } catch (e) {}
    if (ios) return installed ? 'ios-app' : 'ios';
    if (/Android/.test(ua)) return /SamsungBrowser/.test(ua) ? 'samsung' : (/Firefox/.test(ua) ? 'android-firefox' : 'android');
    if (/Firefox/.test(ua)) return 'firefox';
    if (/Safari/.test(ua) && !/Chrome|Chromium|Edg\//.test(ua)) return 'mac-safari';
    return 'desktop';
  }
  function steps(d) {
    var site = '<b>' + location.host + '</b>';
    switch (d) {
      case 'android': return [
        T('Tap the settings icon to the left of the web address at the top of this screen.'),
        T('Tap Permissions (or Site settings), then Notifications, and choose Allow.'),
        T('Not there? Open the ⋮ menu → Settings → Site settings → Notifications, find') + ' ' + site + ' ' + T('and choose Allow.'),
        T('Still nothing? On your phone open Settings → Apps → Chrome → Notifications and turn them on.'),
      ];
      case 'samsung': return [
        T('Open the ☰ menu → Settings → Sites and downloads → Notifications.'),
        T('Find') + ' ' + site + ' ' + T('and allow it.'),
        T('Still nothing? On your phone open Settings → Apps → Samsung Internet → Notifications and turn them on.'),
      ];
      case 'android-firefox': return [
        T('Tap the lock icon to the left of the web address.'),
        T('Turn Notifications on (or clear the Blocked setting), then reload the page.'),
      ];
      case 'ios': return [
        T('On an iPhone, alerts only work once KiddieTrac is on your Home Screen.'),
        T('Tap Share, then Add to Home Screen, and open KiddieTrac from the new icon.'),
        T('Sign in there and tap Enable notifications.'),
      ];
      case 'ios-app': return [
        T('Open your iPhone Settings → Notifications → KiddieTrac.'),
        T('Turn on Allow Notifications, then come back here.'),
      ];
      case 'firefox': return [
        T('Click the icon to the left of the web address.'),
        T('Next to Send notifications, clear Blocked (or choose Allow), then reload the page.'),
      ];
      case 'mac-safari': return [
        T('Open Safari → Settings → Websites → Notifications.'),
        T('Find') + ' ' + site + ' ' + T('and choose Allow, then reload the page.'),
      ];
      default: return [
        T('Click the icon to the left of the web address (a lock or two sliders).'),
        T('Set Notifications to Allow, then reload the page.'),
        T('Not listed? Open your browser settings → Privacy and security → Site settings → Notifications, and move') + ' ' + site + ' ' + T('from Not allowed to Allowed.'),
      ];
    }
  }

  var _listeners = [];
  function onChange(fn) { if (typeof fn === 'function') _listeners.push(fn); }
  function changed() { _listeners.forEach(function (fn) { try { fn(Notification.permission); } catch (e) {} }); }
  try {
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'notifications' }).then(function (p) {
        p.onchange = function () {
          if (Notification.permission === 'granted') subscribeIfPossible(false).then(changed, changed);
          else changed();
        };
      }).catch(function () {});
    }
  } catch (e) {}
  // Coming back from the browser's settings is a visibility change, not a reload.
  var _lastPerm = (typeof Notification !== 'undefined') ? Notification.permission : null;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden || typeof Notification === 'undefined') return;
    if (Notification.permission !== _lastPerm) {
      _lastPerm = Notification.permission;
      if (_lastPerm === 'granted') subscribeIfPossible(false).then(changed, changed); else changed();
    }
  });

  function helpBlocked() {
    var ID = 'kt-push-help';
    var old = document.getElementById(ID); if (old) old.remove();
    var d = device();
    var wrap = document.createElement('div');
    wrap.id = ID;
    ['data-no-modal-guard', 'data-kt-nomove', 'data-kt-nochrome'].forEach(function (a) { wrap.setAttribute(a, '1'); });
    wrap.setAttribute('role', 'dialog'); wrap.setAttribute('aria-modal', 'true'); wrap.setAttribute('aria-labelledby', ID + '-t');
    var title = d === 'ios' ? T('Get alerts on this iPhone') : T('Turn notifications back on');
    var lead = d === 'ios'
      ? T('Safari on an iPhone can only show alerts from apps on your Home Screen.')
      : T('This browser is blocking notifications from KiddieTrac, so new messages and Safe Arrival alerts cannot reach you. Only you can lift the block — it takes a few seconds:');
    wrap.innerHTML =
      '<div class="kph-card">'
      + '<div class="kph-head"><span class="kph-ic" aria-hidden="true">🔕</span><h3 id="' + ID + '-t"></h3>'
      + '<button type="button" class="kph-x" aria-label="' + T('Close') + '" data-kt-iconized="1">✕</button></div>'
      + '<p class="kph-lead"></p><ol class="kph-steps"></ol>'
      + '<div class="kph-msg" aria-live="polite"></div>'
      + '<div class="kph-foot">'
      + (d === 'ios' ? '' : '<button type="button" class="kph-go" data-kt-iconized="1"></button>')
      + '<button type="button" class="kph-close" data-kt-iconized="1"></button></div></div>';
    wrap.querySelector('h3').textContent = title;
    wrap.querySelector('.kph-lead').textContent = lead;
    var ol = wrap.querySelector('.kph-steps');
    steps(d).forEach(function (s) { var li = document.createElement('li'); li.innerHTML = s; ol.appendChild(li); });
    wrap.querySelector('.kph-close').textContent = T('Close');
    var go = wrap.querySelector('.kph-go');
    if (go) go.textContent = T('I’ve allowed it — check again');
    var msg = wrap.querySelector('.kph-msg');

    if (!document.getElementById(ID + '-css')) {
      var st = document.createElement('style'); st.id = ID + '-css';
      st.textContent =
        '#' + ID + '{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}'
        + '#' + ID + ' .kph-card{background:#fff;border-radius:16px;max-width:440px;width:100%;max-height:calc(100vh - 32px);overflow:auto;padding:18px 18px 14px;box-shadow:0 20px 50px rgba(15,23,42,.3);box-sizing:border-box;color:#0F172A;font-size:14px;line-height:1.5}'
        + '#' + ID + ' .kph-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}'
        + '#' + ID + ' .kph-ic{font-size:22px}'
        + '#' + ID + ' h3{margin:0;font-size:17px;font-weight:800;flex:1}'
        + '#' + ID + ' .kph-x{width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#64748B;font-size:15px;cursor:pointer}'
        + '#' + ID + ' .kph-lead{margin:0 0 10px;color:#334155}'
        + '#' + ID + ' .kph-steps{margin:0 0 10px;padding-left:22px}'
        + '#' + ID + ' .kph-steps li{margin:0 0 7px}'
        + '#' + ID + ' .kph-msg{min-height:0;font-size:13px;font-weight:600;margin:0 0 8px}'
        + '#' + ID + ' .kph-msg:empty{display:none}'
        + '#' + ID + ' .kph-foot{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}'
        + '#' + ID + ' .kph-foot button{height:38px;padding:0 14px;border-radius:10px;font-weight:700;font-size:13.5px;cursor:pointer}'
        + '#' + ID + ' .kph-go{background:#1F6080;color:#fff;border:0}'
        + '#' + ID + ' .kph-close{background:#fff;color:#334155;border:1px solid #CBD5E1}'
        + '@media (max-width:480px){#' + ID + ' .kph-foot button{flex:1 1 100%}}';
      document.head.appendChild(st);
    }
    document.body.appendChild(wrap);

    function close() { document.removeEventListener('keydown', onKey, true); wrap.remove(); }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }
    document.addEventListener('keydown', onKey, true);
    wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
    wrap.querySelector('.kph-x').addEventListener('click', close);
    wrap.querySelector('.kph-close').addEventListener('click', close);
    if (go) {
      var busy = false;
      go.addEventListener('click', async function () {
        if (busy) return; busy = true;
        msg.style.color = '#334155'; msg.textContent = T('Checking…');
        var perm = (typeof Notification !== 'undefined') ? Notification.permission : 'denied';
        if (perm === 'denied') {
          msg.style.color = '#B91C1C';
          msg.textContent = T('Still blocked. Change the setting above, then reload this page if it does not pick it up.');
          busy = false; return;
        }
        var r = { status: 'error' };
        try { r = await subscribeIfPossible(true); } catch (e) {}
        busy = false;
        if (r.status === 'subscribed') {
          msg.style.color = '#15803D'; msg.textContent = T('Notifications are on for this device.');
          changed();
          setTimeout(close, 1600);
        } else {
          msg.style.color = '#B91C1C';
          msg.textContent = r.status === 'denied' ? T('Still blocked. Change the setting above, then reload this page if it does not pick it up.')
            : T('Could not turn notifications on just now. Please try again in a moment.');
        }
      });
    }
    setTimeout(function () { try { (go || wrap.querySelector('.kph-close')).focus(); } catch (e) {} }, 30);
  }

  window.KT = window.KT || {};
  window.KT.Push = { subscribe: subscribeIfPossible, unsubscribe, status, maybeAutoSubscribe, helpBlocked, onChange };

  // Try auto-subscribe on load (silent if denied/default)
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', maybeAutoSubscribe);
  else maybeAutoSubscribe();
})(window);
