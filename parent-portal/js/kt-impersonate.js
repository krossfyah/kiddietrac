/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — platform-admin "View as" (2026-07-20).
   A super admin can see the app exactly as any parent / educator / director /
   admin sees it. Picking a person calls POST /platform/impersonate/{id}, which
   mints a short-lived token for that user; we back up the admin's own session
   and swap to the target's token, so every API call returns THEIR data (true
   impersonation, not a client-side role flip). A persistent banner exits back
   to the admin session. Both endpoints are gated to role:platform_admin.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktImpersonate) return; window.__ktImpersonate = true;

  var API = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
  function ss(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function sset(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  function sdel(k) { try { sessionStorage.removeItem(k); } catch (e) {} }
  function tok() { return ss('kt_token'); }
  function userObj() { try { return JSON.parse(ss('kt_user') || '{}'); } catch (e) { return {}; } }
  function isPlatformAdmin() { var u = userObj(); return !!(u && u.roles && u.roles.indexOf('platform_admin') >= 0); }
  function impersonating() { return ss('kt_impersonating') === '1'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function notify(msg) { try { if (window.KT && KT.toast) return KT.toast('⚠️', 'View-as', msg, '#b91c1c'); } catch (e) {} if (window.console) console.warn('[view-as] ' + msg); }

  function api(path, opts) {
    opts = opts || {};
    var h = { Accept: 'application/json' };
    if (opts.body) h['Content-Type'] = 'application/json';
    var t = tok(); if (t) h.Authorization = 'Bearer ' + t;
    var aid = ss('kt_active_agency_id'); if (aid) h['X-Active-Agency-Id'] = aid;
    return fetch(API + path, { method: opts.method || 'GET', headers: h, body: opts.body ? JSON.stringify(opts.body) : undefined })
      .then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw new Error((j && j.message) || ('HTTP ' + r.status)); }, function () { throw new Error('HTTP ' + r.status); }); });
  }

  // ── exit banner (shown while impersonating) ─────────────────────────
  function showBanner() {
    if (document.getElementById('kt-imp-bar')) return;
    var name = ss('kt_impersonating_name') || 'user';
    var role = ss('kt_impersonating_role') || '';
    var bar = document.createElement('div'); bar.id = 'kt-imp-bar';
    bar.style.cssText = 'position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:2147483600;background:#7c2d12;color:#fff;border-radius:999px;padding:7px 8px 7px 14px;display:flex;align-items:center;gap:10px;font:600 13px/1.2 system-ui,-apple-system,sans-serif;box-shadow:0 8px 24px -8px rgba(0,0,0,.55);max-width:calc(100vw - 20px);';
    bar.innerHTML = '<span style="display:flex;align-items:center;gap:7px;min-width:0;"><span style="font-size:15px;">👁</span><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Viewing as <b>' + esc(name) + '</b>' + (role ? (' · ' + esc(role)) : '') + '</span></span>'
      + '<button id="kt-imp-exit" style="flex:0 0 auto;background:#fff;color:#7c2d12;border:0;border-radius:999px;padding:5px 13px;font-weight:800;font-size:12px;cursor:pointer;">Exit</button>';
    document.body.appendChild(bar);
    bar.querySelector('#kt-imp-exit').onclick = stop;
  }

  // ── launcher (platform admin, not impersonating) ────────────────────
  function showLauncher() {
    if (document.getElementById('kt-imp-fab')) return;
    var b = document.createElement('button'); b.id = 'kt-imp-fab'; b.type = 'button'; b.title = 'View as another user';
    b.style.cssText = 'position:fixed;bottom:16px;left:16px;z-index:2147483500;background:#1F6080;color:#fff;border:0;border-radius:999px;padding:10px 16px;font:800 13px system-ui,-apple-system,sans-serif;box-shadow:0 6px 18px -6px rgba(0,0,0,.5);cursor:pointer;display:flex;align-items:center;gap:7px;';
    b.innerHTML = '<span style="font-size:15px;">👁</span> View as…';
    document.body.appendChild(b);
    b.onclick = openPicker;
  }

  // ── picker modal ────────────────────────────────────────────────────
  function openPicker() {
    if (document.getElementById('kt-imp-modal')) return;
    var ov = document.createElement('div'); ov.id = 'kt-imp-modal';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483550;background:rgba(8,17,33,.55);display:flex;align-items:flex-start;justify-content:center;padding:5vh 12px;font-family:system-ui,-apple-system,sans-serif;';
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);">'
      + '<div style="padding:16px 18px 10px;flex:0 0 auto;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;"><div style="font-weight:800;font-size:16px;color:#0f172a;">👁 View as…</div><button id="kt-imp-x" style="background:none;border:0;font-size:22px;color:#64748B;cursor:pointer;line-height:1;">×</button></div>'
      + '<input id="kt-imp-q" placeholder="Search by name or email…" autocomplete="off" style="width:100%;margin-top:10px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;box-sizing:border-box;">'
      + '<div id="kt-imp-roles" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;"></div>'
      + '</div>'
      + '<div id="kt-imp-list" style="overflow:auto;padding:4px 10px 12px;flex:1;"></div>'
      + '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    ov.querySelector('#kt-imp-x').onclick = function () { ov.remove(); };

    var curRole = '';
    var roleWrap = ov.querySelector('#kt-imp-roles');
    var roleDefs = [['', 'All'], ['guardian', 'Parents'], ['educator', 'Educators'], ['centre_director', 'Directors'], ['agency_admin', 'Admins']];
    roleDefs.forEach(function (r, i) {
      var c = document.createElement('button'); c.type = 'button'; c.textContent = r[1];
      c.style.cssText = 'border:1px solid #cbd5e1;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:700;cursor:pointer;background:' + (r[0] === curRole ? '#1F6080' : '#fff') + ';color:' + (r[0] === curRole ? '#fff' : '#475569') + ';';
      c.onclick = function () {
        curRole = r[0];
        [].forEach.call(roleWrap.children, function (x, j) { var on = roleDefs[j][0] === curRole; x.style.background = on ? '#1F6080' : '#fff'; x.style.color = on ? '#fff' : '#475569'; });
        load();
      };
      roleWrap.appendChild(c);
    });

    var q = ov.querySelector('#kt-imp-q');
    var list = ov.querySelector('#kt-imp-list');
    var timer = null;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });

    function load() {
      list.innerHTML = '<div style="padding:22px;text-align:center;color:#64748B;font-size:13px;">Loading…</div>';
      api('/platform/directory?search=' + encodeURIComponent(q.value.trim()) + '&role=' + encodeURIComponent(curRole))
        .then(function (d) {
          var us = (d && d.users) || []; list.innerHTML = '';
          if (!us.length) { list.innerHTML = '<div style="padding:22px;text-align:center;color:#64748B;font-size:13px;">No matching users.</div>'; return; }
          us.slice(0, 400).forEach(function (u) {
            var row = document.createElement('button'); row.type = 'button';
            row.style.cssText = 'display:flex;align-items:center;gap:11px;width:100%;text-align:left;background:none;border:0;border-radius:10px;padding:9px 10px;cursor:pointer;';
            row.onmouseenter = function () { row.style.background = '#f1f5f9'; };
            row.onmouseleave = function () { row.style.background = 'none'; };
            var initials = (u.name || '?').split(' ').map(function (x) { return x[0]; }).slice(0, 2).join('').toUpperCase();
            row.innerHTML = '<span style="flex:0 0 auto;width:34px;height:34px;border-radius:50%;background:#e2e8f0;color:#475569;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;">' + esc(initials) + '</span>'
              + '<span style="min-width:0;flex:1;"><span style="display:block;font-weight:700;font-size:14px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(u.name) + '</span>'
              + '<span style="display:block;font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(u.role_label) + (u.agency ? (' · ' + esc(u.agency)) : '') + (u.centre ? (' · ' + esc(u.centre)) : '') + '</span></span>'
              + '<span style="flex:0 0 auto;color:#1F6080;font-weight:800;font-size:12px;">View →</span>';
            row.onclick = function () { row.disabled = true; start(u); };
            list.appendChild(row);
          });
        })
        .catch(function (e) { list.innerHTML = '<div style="padding:22px;text-align:center;color:#b91c1c;font-size:13px;">Could not load: ' + esc(e.message) + '</div>'; });
    }
    load();
    setTimeout(function () { try { q.focus(); } catch (e) {} }, 60);
  }

  // ── start / stop impersonation (session swap) ───────────────────────
  function start(u) {
    api('/platform/impersonate/' + u.id, { method: 'POST', body: {} })
      .then(function (d) {
        if (!d || !d.token) { notify('Could not start view-as.'); return; }
        // Back up the admin's own session ONCE (so nested picks can't lose it).
        if (!ss('kt_admin_token')) {
          sset('kt_admin_token', tok() || '');
          sset('kt_admin_user', ss('kt_user') || '{}');
          sset('kt_admin_agency', ss('kt_active_agency_id') || '');
          sset('kt_admin_view', ss('kt_view_as') || '');
        }
        // Swap to the target.
        sset('kt_token', d.token);
        sset('kt_user', JSON.stringify(d.user || u));
        sdel('kt_view_as');
        if (d.user && d.user.agency_id) sset('kt_active_agency_id', String(d.user.agency_id));
        else sdel('kt_active_agency_id');
        sset('kt_impersonating', '1');
        sset('kt_impersonating_name', (d.user && d.user.name) || u.name || 'user');
        sset('kt_impersonating_role', u.role_label || '');
        sdel('kt_welcomed');
        window.location.href = '/dashboard.html';
      })
      .catch(function (e) { notify('Could not start view-as: ' + e.message); });
  }

  function stop() {
    var at = ss('kt_admin_token');
    if (at) {
      sset('kt_token', at);
      sset('kt_user', ss('kt_admin_user') || '{}');
      var ag = ss('kt_admin_agency'); if (ag) sset('kt_active_agency_id', ag); else sdel('kt_active_agency_id');
      var va = ss('kt_admin_view'); if (va) sset('kt_view_as', va); else sdel('kt_view_as');
    }
    ['kt_admin_token', 'kt_admin_user', 'kt_admin_agency', 'kt_admin_view', 'kt_impersonating', 'kt_impersonating_name', 'kt_impersonating_role'].forEach(sdel);
    sdel('kt_welcomed');
    window.location.href = '/dashboard.html';
  }

  function boot() {
    if (!tok()) return;                       // not logged in
    if (impersonating()) { showBanner(); return; }
    if (isPlatformAdmin()) showLauncher();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
