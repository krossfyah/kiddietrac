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
    token() { return sessionStorage.getItem('kt_token'); },
    user() {
      try { return JSON.parse(sessionStorage.getItem('kt_user') || 'null'); }
      catch (_) { return null; }
    },
    clear() {
      sessionStorage.removeItem('kt_token');
      sessionStorage.removeItem('kt_user');
    },
    requireLogin() {
      if (!this.token()) {
        window.location.href = '/index.html';
        return false;
      }
      return true;
    },
    async logout() {
      try { await Api.post('/auth/logout'); } catch (_) {}
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

      const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      };
      const token = Auth.token();
      if (token) headers['Authorization'] = 'Bearer ' + token;

      let res;
      try {
        res = await fetch(url, {
          method,
          headers,
          body: body ? JSON.stringify(body) : null,
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
    time(iso) {
      if (!iso) return '';
      return new Date(iso).toLocaleTimeString('en-CA', {
        hour: 'numeric', minute: '2-digit', hour12: true,
      });
    },
    date(iso, opts = { weekday: 'long', month: 'long', day: 'numeric' }) {
      if (!iso) return '';
      return new Date(iso).toLocaleDateString('en-CA', opts);
    },
    relative(iso) {
      if (!iso) return '';
      const diff = (Date.now() - new Date(iso).getTime()) / 1000;
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
      let toast = document.getElementById('kt-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'kt-toast';
        toast.style.cssText = `position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
          padding:12px 20px; border-radius:12px; font-size:14px; font-weight:500;
          z-index:9999; box-shadow:0 8px 32px rgba(0,0,0,0.18); transition:opacity 0.2s;`;
        document.body.appendChild(toast);
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
    if (!Auth.requireLogin()) return null;

    const user = Auth.user();
    const navAvatar = Dom.$('#navAvatar');
    const navName = Dom.$('#navName');
    if (navAvatar) navAvatar.textContent = Fmt.initials(user?.name);
    if (navName) navName.textContent = user?.name?.split(' ')[0] || 'You';

    // Click on avatar opens menu
    const navUser = Dom.$('#navUser');
    if (navUser) {
      navUser.addEventListener('click', () => {
        if (confirm('Sign out of Kiddietrac?')) Auth.logout();
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
