/* ============================================================
   KIDDIETRAC v22p20 — Multi-agency switcher widget
   Self-installs above the sidebar user pill when /auth/agencies
   returns more than one. Defaults the active agency to the user's
   first assignment if nothing in sessionStorage yet.
   ============================================================ */
(function (window) {
  'use strict';

  var STORAGE_KEY = 'kt_active_agency_id';
  var ACTIVE_NAME = 'kt_active_agency_name';
  var MOUNT_ATTEMPTS = 0;
  var MAX_MOUNT_ATTEMPTS = 60;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function token() {
    return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
  }

  function apiBase() {
    return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
  }

  async function fetchAgencies() {
    var t = token();
    if (!t) return { agencies: [], is_platform_admin: false };
    try {
      var res = await fetch(apiBase() + '/auth/agencies', {
        headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' },
      });
      if (!res.ok) return { agencies: [], is_platform_admin: false };
      var data = await res.json();
      return { agencies: data.agencies || [], is_platform_admin: !!data.is_platform_admin };
    } catch (e) { return { agencies: [], is_platform_admin: false }; }
  }

  // v22p22: inject a "Platform" sidebar section for platform_admin.
  function injectPlatformNav() {
    var navLinks = document.getElementById('navLinks');
    if (! navLinks || navLinks.querySelector('[data-platform-section]')) return;
    var section = document.createElement('div');
    section.className = 'sidebar-section';
    section.setAttribute('data-platform-section', '1');
    section.style.marginTop = '12px';
    section.innerHTML =
      '<div class="sidebar-section-label" style="color:#7C3AED;">🌐 Platform</div>' +
      '<a href="#platform-overview" class="nav-link" data-hash="platform-overview">' +
        '<span class="nav-icon">🌐</span><span class="nav-label">Platform overview</span>' +
      '</a>' +
      '<a href="#platform-agencies" class="nav-link" data-hash="platform-agencies">' +
        '<span class="nav-icon">🏢</span><span class="nav-label">All agencies</span>' +
      '</a>';
    navLinks.insertBefore(section, navLinks.firstChild);
  }

  async function setActiveServer(agencyId) {
    var t = token();
    if (!t) return false;
    try {
      var res = await fetch(apiBase() + '/auth/active-agency', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + t,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ agency_id: agencyId }),
      });
      return res.ok;
    } catch (e) { return false; }
  }

  function buildWidget(agencies, activeId, isPlatformAdmin) {
    var active = agencies.find(function (a) { return String(a.id) === String(activeId); }) || agencies[0];

    var wrap = document.createElement('div');
    wrap.id = 'kt-agency-switcher';
    wrap.setAttribute('style', [
      'position:relative',
      'margin:6px 10px 4px',
      'border-top:1px solid rgba(0,0,0,.06)',
      'padding-top:8px',
    ].join(';'));

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('style', [
      'display:flex',
      'align-items:center',
      'gap:8px',
      'width:100%',
      'background:rgba(31,96,128,0.06)',
      'border:1px solid rgba(31,96,128,0.18)',
      'border-radius:8px',
      'padding:8px 10px',
      'font-size:12px',
      'color:#1F6080',
      'font-weight:600',
      'cursor:pointer',
      'text-align:left',
      'transition:background .15s',
    ].join(';'));
    var badge = isPlatformAdmin
      ? '<span style="font-size:9px;background:#7C3AED;color:white;padding:1px 5px;border-radius:4px;letter-spacing:0.5px;font-weight:700;margin-right:4px;">PLAT</span>'
      : '';
    btn.innerHTML =
      '<span style="font-size:14px;">🏢</span>' +
      badge +
      '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(active.name) + '</span>' +
      '<span style="font-size:10px;color:#6B7280;">▾</span>';

    var dropdown = document.createElement('div');
    dropdown.setAttribute('style', [
      'position:absolute',
      'bottom:calc(100% + 4px)',
      'left:10px',
      'right:10px',
      'background:white',
      'border:1px solid #D1D5DB',
      'border-radius:10px',
      'box-shadow:0 8px 18px rgba(0,0,0,.14)',
      'z-index:9999',
      'overflow:hidden',
      'display:none',
    ].join(';'));

    dropdown.innerHTML =
      '<div style="padding:8px 12px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#6B7280;font-weight:700;background:#F9FAFB;border-bottom:1px solid #F3F4F6;">Switch agency</div>' +
      agencies.map(function (a) {
        var isActive = String(a.id) === String(active.id);
        return '<button type="button" data-aid="' + a.id + '" style="display:flex;align-items:center;gap:8px;width:100%;background:' + (isActive ? '#EFF6FF' : 'white') + ';border:none;padding:9px 12px;font-size:13px;color:#111827;cursor:pointer;text-align:left;border-bottom:1px solid #F3F4F6;">' +
          '<span style="font-size:14px;">🏢</span>' +
          '<span style="flex:1;font-weight:' + (isActive ? '700' : '500') + ';">' + esc(a.name) + '</span>' +
          (isActive ? '<span style="font-size:11px;color:#1F6080;font-weight:700;">✓</span>' : '') +
        '</button>';
      }).join('');

    wrap.appendChild(btn);
    wrap.appendChild(dropdown);

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) dropdown.style.display = 'none';
    });

    Array.prototype.forEach.call(dropdown.querySelectorAll('button[data-aid]'), function (b) {
      b.addEventListener('click', async function () {
        var aid = parseInt(b.getAttribute('data-aid'), 10);
        if (String(aid) === String(active.id)) {
          dropdown.style.display = 'none';
          return;
        }
        var target = agencies.find(function (a) { return a.id === aid; });
        b.textContent = '⏳ Switching…';
        var ok = await setActiveServer(aid);
        if (!ok) {
          alert('Could not switch agency. You may not have access.');
          b.textContent = target ? target.name : 'Agency ' + aid;
          return;
        }
        sessionStorage.setItem(STORAGE_KEY, String(aid));
        if (target) sessionStorage.setItem(ACTIVE_NAME, target.name);
        // Reload to ensure every screen re-fetches with the new agency scope
        window.location.reload();
      });
    });

    return wrap;
  }

  async function tryMount() {
    MOUNT_ATTEMPTS++;
    if (MOUNT_ATTEMPTS > MAX_MOUNT_ATTEMPTS) return;

    if (!token()) return; // not signed in

    var sidebar = document.getElementById('appSidebar');
    var navUser = document.getElementById('navUser') || document.querySelector('.nav-user');
    if (!sidebar || !navUser) {
      setTimeout(tryMount, 150);
      return;
    }
    if (document.getElementById('kt-agency-switcher')) return; // already mounted

    var resp = await fetchAgencies();
    var agencies = resp.agencies || [];
    var isPlatformAdmin = !!resp.is_platform_admin;
    sessionStorage.setItem('kt_is_platform_admin', isPlatformAdmin ? '1' : '0');

    // v22p22: inject the Platform sidebar section for platform_admin.
    if (isPlatformAdmin) injectPlatformNav();

    if (! agencies.length || (! isPlatformAdmin && agencies.length < 2)) return;

    var activeId = sessionStorage.getItem(STORAGE_KEY) || agencies[0].id;
    if (! agencies.find(function (a) { return String(a.id) === String(activeId); })) {
      activeId = agencies[0].id;
      sessionStorage.setItem(STORAGE_KEY, String(activeId));
    }
    if (! sessionStorage.getItem(ACTIVE_NAME)) {
      var a0 = agencies.find(function (a) { return String(a.id) === String(activeId); });
      if (a0) sessionStorage.setItem(ACTIVE_NAME, a0.name);
    }

    var widget = buildWidget(agencies, activeId, isPlatformAdmin);
    navUser.parentNode.insertBefore(widget, navUser);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryMount);
  } else {
    tryMount();
  }

  if (window.KT) window.KT.AgencySwitcher = { mount: tryMount };
})(window);
