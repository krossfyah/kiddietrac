/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v10 — API helpers shim
   Adds put/patch/delete/postForm to window.KT.Api if they're missing.
   Safe to load multiple times; idempotent.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  // Wait until KT.Api exists before patching
  function patch() {
    if (!window.KT || !window.KT.Api) {
      // Try again in 100ms
      setTimeout(patch, 100);
      return;
    }

    const Api = window.KT.Api;
    const base = Api.base || 'https://api.kiddietrac.com/api/v1';

    function getToken() {
      return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
    }

    async function request(method, path, body, opts) {
      opts = opts || {};
      const headers = { 'Accept': 'application/json' };
      const token = getToken();
      if (token) headers['Authorization'] = 'Bearer ' + token;

      const init = { method, headers };
      if (body !== undefined && body !== null) {
        if (body instanceof FormData) {
          // Browser sets multipart boundary automatically
          init.body = body;
        } else {
          headers['Content-Type'] = 'application/json';
          init.body = JSON.stringify(body);
        }
      }

      const url = path.startsWith('http') ? path : (base + path);
      const res = await fetch(url, init);
      const text = await res.text();
      let data;
      try { data = text ? JSON.parse(text) : {}; }
      catch (e) { data = { message: text }; }

      if (!res.ok) {
        const err = new Error(data.message || ('HTTP ' + res.status));
        err.status = res.status;
        err.data = data;
        throw err;
      }
      return data;
    }

    if (typeof Api.put !== 'function') {
      Api.put = (path, body) => request('PUT', path, body);
    }
    if (typeof Api.patch !== 'function') {
      Api.patch = (path, body) => request('PATCH', path, body);
    }
    if (typeof Api.delete !== 'function') {
      Api.delete = (path) => request('DELETE', path, null);
    }
    if (typeof Api.postForm !== 'function') {
      Api.postForm = (path, formData) => request('POST', path, formData);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', patch);
  } else {
    patch();
  }
})(window);
