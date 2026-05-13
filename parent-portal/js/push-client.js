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
    if (Notification.permission === 'granted') {
      subscribeIfPossible(false).catch(() => {});
    }
  }

  window.KT = window.KT || {};
  window.KT.Push = { subscribe: subscribeIfPossible, unsubscribe, status, maybeAutoSubscribe };

  // Try auto-subscribe on load (silent if denied/default)
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', maybeAutoSubscribe);
  else maybeAutoSubscribe();
})(window);
