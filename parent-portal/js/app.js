/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Parent Portal Shared JS
   API client, auth helpers, formatting, error handling
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const API_BASE = (window.KT_CONFIG && window.KT_CONFIG.apiBase)
    || 'https://api.kiddietrac.com/api/v1';

  // ─── Auth ───────────────────────────────────────────────────────
  const Auth = {
    // Fall back to localStorage so the session survives a WebView process kill
    // (which wipes sessionStorage) — an educator using the app all day shouldn't be
    // bounced to login every time Android reclaims the tab. The token is only ever
    // MIRRORED to localStorage when no biometric/PIN lock is enrolled (see
    // rememberSession below); enrolled users keep their locked kt_bio_/kt_pin_ vault.
    token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } },
    user() {
      try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || 'null'); }
      catch (_) { return null; }
    },
    // Keep the user signed in across app restarts. Mirrors the live session token
    // into localStorage (survives a WebView kill) and restores it back into
    // sessionStorage on a cold launch. Skipped when a biometric/PIN lock is enrolled
    // — those flows own persistence with their own encrypted/locked vault, and we
    // must not bypass a lock the user deliberately turned on. Sign out still purges
    // both stores (Auth.clear), so this never keeps a token past an explicit logout.
    rememberSession() {
      try {
        if (localStorage.getItem('kt_biometric_enabled') === '1' || localStorage.getItem('kt_pin_enabled') === '1') return;
        var s = sessionStorage.getItem('kt_token');
        var l = localStorage.getItem('kt_token');
        if (s) {
          localStorage.setItem('kt_token', s);
          var su = sessionStorage.getItem('kt_user'); if (su) localStorage.setItem('kt_user', su);
        } else if (l) {
          sessionStorage.setItem('kt_token', l);
          var lu = localStorage.getItem('kt_user'); if (lu) sessionStorage.setItem('kt_user', lu);
        }
      } catch (e) {}
    },
    clear() {
      // SECURITY: the bearer token is also written to localStorage (biometric
      // sign-in, set-password) and every helper reads sessionStorage||localStorage,
      // so a session-only clear left the token live on a shared device after
      // sign-out / expiry. Purge both stores.
      try {
        sessionStorage.removeItem('kt_token'); sessionStorage.removeItem('kt_user');
        localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user');
      } catch (e) {}
    },
    requireLogin() {
      if (!this.token()) {
        window.location.href = '/index.html';
        return false;
      }
      return true;
    },
    async logout() {
      // If biometric/PIN unlock is enrolled, DON'T revoke the token server-side.
      // The biometric/PIN vault stores THIS token to re-open the app on the next
      // launch; revoking it here made the unlock's /auth/me return 401, which
      // wiped the enrolment and bounced the user to the password login ("session
      // ended"). Ordinary sign-out keeps the credential for biometric re-entry
      // (the user's chosen behaviour); fully removing it = Settings → turn off
      // biometric, which clears the vault and lets logout revoke normally.
      var keepToken = false;
      try {
        keepToken = (localStorage.getItem('kt_biometric_enabled') === '1' && !!localStorage.getItem('kt_bio_token'))
          || (localStorage.getItem('kt_pin_enabled') === '1' && !!localStorage.getItem('kt_pin_vault'));
      } catch (e) {}
      if (!keepToken) { try { await Api.post('/auth/logout'); } catch (_) {} }
      this.clear();
      window.location.href = '/index.html';
    },
  };

  // ─── API client ─────────────────────────────────────────────────
  const Api = {
    async request(path, { method = 'GET', body = null, query = null } = {}) {
      let url = API_BASE + path;
      if (query) {
        const qs = new URLSearchParams(query).toString();
        if (qs) url += (path.includes('?') ? '&' : '?') + qs;
      }

      // A FormData body must NOT be JSON-encoded, and must NOT carry an explicit
      // Content-Type — the browser has to set multipart/form-data plus the
      // boundary itself. Without this, JSON.stringify(new FormData()) produced the
      // string "{}", so file uploads reached the API with NO fields at all and came
      // back as "The title field is required" plus one error per other field.
      const isForm = (typeof FormData !== 'undefined') && (body instanceof FormData);
      const headers = {
        'Accept': 'application/json',
      };
      if (!isForm) headers['Content-Type'] = 'application/json';
      const token = Auth.token();
      if (token) headers['Authorization'] = 'Bearer ' + token;
      // Multi-agency scope: tell the API which agency the user is currently
      // viewing (set by the agency switcher; a platform_admin can switch freely).
      // WITHOUT this, agency-scoped endpoints fall back to the user's first
      // agency, so opening a different agency showed the wrong agency's data.
      try {
        const _activeAgencyId = sessionStorage.getItem('kt_active_agency_id');
        if (_activeAgencyId) headers['X-Active-Agency-Id'] = _activeAgencyId;
        // "View as" role preview (platform_admin) — only Help reads this, to show
        // the previewed role's articles instead of the admin's own.
        const _viewAs = sessionStorage.getItem('kt_view_as');
        if (_viewAs) headers['X-View-As-Role'] = _viewAs;
      } catch (e) { /* sessionStorage unavailable */ }

      let res;
      try {
        res = await fetch(url, {
          method,
          headers,
          body: body ? (isForm ? body : JSON.stringify(body)) : null,
        });
      } catch (e) {
        throw new ApiError('network', 'Network error — check your connection', 0);
      }

      // Auth expired? send to login
      if (res.status === 401) {
        Auth.clear();
        if (!window.location.pathname.endsWith('index.html')
            && window.location.pathname !== '/') {
          window.location.href = '/index.html';
        }
        throw new ApiError('unauthorized', 'Session expired. Please sign in again.', 401);
      }

      if (res.status === 204) return null;

      let data = null;
      try { data = await res.json(); } catch (_) {}

      if (!res.ok) {
        const msg = data?.message || data?.error || res.statusText;
        throw new ApiError(data?.error || 'error', msg, res.status, data);
      }

      return data;
    },
    get(path, query) { return this.request(path, { query }); },
    post(path, body) { return this.request(path, { method: 'POST', body }); },
    /** Multipart upload. Same as post() — request() detects FormData — but named
     *  so call sites reading as an upload are obvious. */
    postForm(path, formData) { return this.request(path, { method: 'POST', body: formData }); },
    patch(path, body) { return this.request(path, { method: 'PATCH', body }); },
    delete(path) { return this.request(path, { method: 'DELETE' }); },
  };

  class ApiError extends Error {
    constructor(type, message, status, data) {
      super(message);
      this.type = type; this.status = status; this.data = data;
    }
  }

  // ─── Formatting ─────────────────────────────────────────────────
  const Fmt = {
    money(amount, currency = 'CAD') {
      const n = typeof amount === 'string' ? parseFloat(amount) : amount;
      if (!isFinite(n)) return '—';
      return new Intl.NumberFormat('en-CA', {
        style: 'currency', currency, currencyDisplay: 'symbol',
      }).format(n);
    },
    // Parse a server timestamp to a real instant. MySQL/PHP hand us UTC with NO
    // zone marker ("2026-08-05 13:30:04"); a bare new Date() reads that as the
    // browser's LOCAL time, so on anything behind UTC (all of North America) the
    // instant lands in the FUTURE → "just now" for events that are hours old.
    // This was the recurring notifications bug: individual screens appended 'Z'
    // but this shared Fmt never did, so every screen using Fmt stayed broken.
    // Date-only strings ("2026-08-05") are left as-is (already UTC midnight).
    parse(iso) {
      if (iso == null || iso === '') return null;
      if (iso instanceof Date) return isNaN(iso.getTime()) ? null : iso;
      let v = String(iso).trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(v)) { const d0 = new Date(v); return isNaN(d0.getTime()) ? null : d0; }
      v = v.replace(' ', 'T');
      if (!/(Z|[+-]\d{2}:?\d{2})$/.test(v)) v += 'Z';     // treat zone-less as UTC
      const d = new Date(v);
      return isNaN(d.getTime()) ? null : d;
    },
    // Render in the AGENCY's timezone (America/Toronto by default via kt-tz.js),
    // not the device's — a director on a phone set to another zone must still see
    // the centre's local time. Falls back to device tz only if kt-tz isn't loaded.
    time(iso) {
      const d = this.parse(iso);
      if (!d) return '';
      const z = (window.KT && KT.tz) ? KT.tz() : null;
      const opts = { hour: 'numeric', minute: '2-digit', hour12: true };
      if (z) opts.timeZone = z;
      return d.toLocaleTimeString('en-CA', opts);
    },
    date(iso, opts = { weekday: 'long', month: 'long', day: 'numeric' }) {
      const d = this.parse(iso);
      if (!d) return '';
      const z = (window.KT && KT.tz) ? KT.tz() : null;
      return d.toLocaleDateString('en-CA', z ? Object.assign({ timeZone: z }, opts) : opts);
    },
    relative(iso) {
      const d = this.parse(iso);
      if (!d) return '';
      let diff = (Date.now() - d.getTime()) / 1000;
      if (diff < 0) diff = 0;                              // clock skew → clamp, never negative
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
      return this.date(iso, { month: 'short', day: 'numeric' });
    },
    initials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/);
      return (parts[0][0] + (parts[1]?.[0] || '')).toUpperCase();
    },
  };

  // ─── DOM helpers ────────────────────────────────────────────────
  const Dom = {
    $(sel, root = document) { return root.querySelector(sel); },
    $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); },
    el(tag, attrs = {}, ...children) {
      const node = document.createElement(tag);
      for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.substring(2).toLowerCase(), v);
        else if (k === 'html') node.innerHTML = v;
        else if (v === true) node.setAttribute(k, '');
        else if (v != null && v !== false) node.setAttribute(k, v);
      }
      for (const c of children.flat()) {
        if (c == null || c === false) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      }
      return node;
    },
    clear(node) { while (node.firstChild) node.removeChild(node.firstChild); },
    show(node) { node.hidden = false; },
    hide(node) { node.hidden = true; },
    toast(message, type = 'info') {
      // Unified toast: hand off to the canonical KT.toast (kt-toasts.js) so every
      // toast in the app shares ONE look (top-right rich card, mobile-aware) instead
      // of this bottom-centre pill. Map the simple type → icon + colour and call
      // KT.toast's explicit 4-arg form (no signature ambiguity). The local pill
      // below stays only as a fallback for when KT.toast hasn't loaded yet.
      try {
        if (window.KT && typeof window.KT.toast === 'function') {
          var _m = ({ info: ['ℹ️', '#1F6080'], success: ['✅', '#16A34A'], error: ['⚠️', '#DC2626'],
            warning: ['⚠️', '#D97706'], danger: ['⚠️', '#DC2626'] })[type] || ['ℹ️', '#1F6080'];
          return window.KT.toast(_m[0], message, '', _m[1]);
        }
      } catch (e) {}
      let toast = document.getElementById('kt-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'kt-toast';
        // Center transform-free (left/right + margin auto) and attach to <html>,
        // NOT <body>: a transformed ancestor (mobile screen transitions) turns a
        // transform-based fixed toast off-screen. Clear the bottom nav too.
        toast.style.cssText = `position:fixed; bottom:calc(env(safe-area-inset-bottom,0px) + 88px); left:0; right:0; margin:0 auto; width:max-content; max-width:calc(100vw - 24px);
          padding:12px 20px; border-radius:12px; font-size:14px; font-weight:500;
          z-index:2147483600; box-shadow:0 8px 32px rgba(0,0,0,0.18); transition:opacity 0.2s;`;
        (document.documentElement || document.body).appendChild(toast);
      }
      const colors = {
        info: ['#1F6080', '#FFFFFF'],
        success: ['#3DB6A0', '#FFFFFF'],
        error: ['#D85A6C', '#FFFFFF'],
      };
      const [bg, fg] = colors[type] || colors.info;
      toast.style.background = bg;
      toast.style.color = fg;
      toast.textContent = message;
      toast.style.opacity = '1';
      clearTimeout(toast._t);
      toast._t = setTimeout(() => { toast.style.opacity = '0'; }, 3500);
    },
  };

  // ─── Page bootstrap ─────────────────────────────────────────────
  // All authenticated pages call this on load to populate the nav
  async function bootstrapPage() {
    Auth.rememberSession();   // restore a persisted session (survives WebView restarts) before gating
    if (!Auth.requireLogin()) return null;

    const user = Auth.user();
    const navAvatar = Dom.$('#navAvatar');
    const navName = Dom.$('#navName');
    if (navAvatar) navAvatar.textContent = Fmt.initials(user?.name);
    if (navName) navName.textContent = user?.name?.split(' ')[0] || 'You';

    // Click on avatar opens menu
    const navUser = Dom.$('#navUser');
    if (navUser) {
      navUser.addEventListener('click', async () => {
        if (await KT.confirm('Sign out of Kiddietrac?')) Auth.logout();
      });
    }

    // Periodically refresh notification badge
    refreshNotificationBadge();
    setInterval(refreshNotificationBadge, 60_000);

    return user;
  }

  async function refreshNotificationBadge() {
    try {
      const { unread_count } = await Api.get('/notifications', { unread_only: 1 }) || {};
      const badge = Dom.$('#navMsgBadge');
      if (!badge) return;
      if (unread_count > 0) {
        badge.textContent = unread_count;
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    } catch (_) { /* silent */ }
  }

  // Export
  window.KT = { Auth, Api, ApiError, Fmt, Dom, bootstrapPage, API_BASE };
})(window);
