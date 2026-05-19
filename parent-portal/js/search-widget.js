/* ============================================================
   KIDDIETRAC v22p4.7 — Sidebar search widget
   Self-installs into #navLinks once the v17 sidebar exists.
   Only active for agency_admin + centre_director (the roles with
   sidebar nav). 300ms debounced GET /api/v1/admin/search.
   Results grouped by entity, click → navigate to result.hash.
   ============================================================ */
(function (window) {
  'use strict';

  var GROUP_META = {
    centres:  { icon: '🏢', label: 'Centres' },
    families: { icon: '👨‍👩‍👧', label: 'Families' },
    children: { icon: '👶', label: 'Children' },
    staff:    { icon: '👤', label: 'Staff' },
    rooms:    { icon: '🚪', label: 'Rooms' },
  };

  var MOUNT_ATTEMPTS = 0;
  var MAX_MOUNT_ATTEMPTS = 50; // ~5s at 100ms each

  function getRole() {
    try {
      var raw = sessionStorage.getItem('kt_user');
      if (!raw) return null;
      var u = JSON.parse(raw);
      // primary role: prefer agency_admin, then centre_director.
      if (u.roles && u.roles.length) {
        if (u.roles.indexOf('agency_admin') >= 0) return 'agency_admin';
        if (u.roles.indexOf('centre_director') >= 0) return 'centre_director';
        return u.roles[0];
      }
      return u.primary_role || u.role || null;
    } catch (e) { return null; }
  }

  function isStaffSidebarRole(role) {
    return role === 'agency_admin' || role === 'centre_director';
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function buildWidget() {
    var wrap = document.createElement('div');
    wrap.id = 'kt-search-widget';
    wrap.setAttribute('style', [
      'position:relative',
      'padding:12px 12px 6px',
      'border-bottom:1px solid rgba(0,0,0,.06)',
      'margin-bottom:8px',
    ].join(';'));

    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = '🔍  Search…';
    input.id = 'kt-search-input';
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('style', [
      'width:100%',
      'box-sizing:border-box',
      'padding:8px 12px',
      'border:1px solid #D1D5DB',
      'border-radius:8px',
      'background:white',
      'font-size:13px',
      'outline:none',
    ].join(';'));
    wrap.appendChild(input);

    var dropdown = document.createElement('div');
    dropdown.id = 'kt-search-dropdown';
    dropdown.setAttribute('style', [
      'position:absolute',
      'top:calc(100% - 4px)',
      'left:12px',
      'right:12px',
      'background:white',
      'border:1px solid #D1D5DB',
      'border-radius:10px',
      'box-shadow:0 6px 16px rgba(0,0,0,.12)',
      'max-height:60vh',
      'overflow-y:auto',
      'z-index:9999',
      'display:none',
    ].join(';'));
    wrap.appendChild(dropdown);

    return { wrap: wrap, input: input, dropdown: dropdown };
  }

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function renderResults(dropdown, results, q) {
    dropdown.innerHTML = '';
    var totalHits = 0;
    Object.keys(GROUP_META).forEach(function (group) {
      var rs = (results && results[group]) || [];
      totalHits += rs.length;
    });

    if (totalHits === 0) {
      dropdown.innerHTML = '<div style="padding:14px;color:#6B7280;font-size:13px;text-align:center;">No results for "' + esc(q) + '"</div>';
      dropdown.style.display = 'block';
      return;
    }

    Object.keys(GROUP_META).forEach(function (group) {
      var rs = (results && results[group]) || [];
      if (!rs.length) return;
      var meta = GROUP_META[group];
      var header = document.createElement('div');
      header.setAttribute('style', 'padding:8px 14px 4px;font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;');
      header.textContent = meta.icon + ' ' + meta.label + ' (' + rs.length + ')';
      dropdown.appendChild(header);

      rs.forEach(function (r) {
        var item = document.createElement('a');
        item.href = r.hash || '#';
        item.setAttribute('style', [
          'display:block',
          'padding:8px 14px',
          'text-decoration:none',
          'color:#111827',
          'font-size:13px',
          'border-bottom:1px solid #F3F4F6',
          'cursor:pointer',
        ].join(';'));
        item.innerHTML =
          '<div style="font-weight:600;">' + esc(r.label) + '</div>' +
          (r.sublabel ? '<div style="font-size:11px;color:#6B7280;margin-top:1px;">' + esc(r.sublabel) + '</div>' : '');
        item.addEventListener('mouseenter', function () { item.style.background = '#F9FAFB'; });
        item.addEventListener('mouseleave', function () { item.style.background = 'white'; });
        item.addEventListener('click', function (ev) {
          ev.preventDefault();
          dropdown.style.display = 'none';
          window.location.hash = r.hash || '#';
          var input = document.getElementById('kt-search-input');
          if (input) { input.value = ''; input.blur(); }
        });
        dropdown.appendChild(item);
      });
    });
    dropdown.style.display = 'block';
  }

  function attachHandlers(widget) {
    var input = widget.input;
    var dropdown = widget.dropdown;

    var token = sessionStorage.getItem('kt_token');
    function fetchSearch(q) {
      if (!q || q.length < 2) {
        dropdown.style.display = 'none';
        return;
      }
      fetch('/api/v1/admin/search?q=' + encodeURIComponent(q), {
        headers: {
          'Authorization': 'Bearer ' + token,
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (data) {
        renderResults(dropdown, data.results, data.q || q);
      }).catch(function (e) {
        dropdown.innerHTML = '<div style="padding:14px;color:#DC2626;font-size:13px;">Search failed: ' + esc(e.message) + '</div>';
        dropdown.style.display = 'block';
      });
    }

    var debouncedFetch = debounce(fetchSearch, 300);

    input.addEventListener('input', function () { debouncedFetch(input.value); });
    input.addEventListener('focus', function () {
      if (input.value.length >= 2 && dropdown.children.length > 0) {
        dropdown.style.display = 'block';
      }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        dropdown.style.display = 'none';
        input.blur();
      }
    });
    document.addEventListener('click', function (e) {
      if (!widget.wrap.contains(e.target)) dropdown.style.display = 'none';
    });
  }

  function tryMount() {
    MOUNT_ATTEMPTS++;
    if (MOUNT_ATTEMPTS > MAX_MOUNT_ATTEMPTS) return;

    var role = getRole();
    if (!isStaffSidebarRole(role)) return; // not a sidebar role; never mount

    var navLinks = document.getElementById('navLinks');
    if (!navLinks) {
      setTimeout(tryMount, 100);
      return;
    }
    if (document.getElementById('kt-search-widget')) return; // already mounted

    var widget = buildWidget();
    navLinks.insertBefore(widget.wrap, navLinks.firstChild);
    attachHandlers(widget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryMount);
  } else {
    tryMount();
  }

  // Also re-check after the v17 shell finishes its buildNav (which may
  // clear and re-populate #navLinks). It sets KT_V17_NAV_INSTALLED.
  var savedInterval = setInterval(function () {
    if (window.KT_V17_NAV_INSTALLED && !document.getElementById('kt-search-widget')) {
      tryMount();
    }
    // Stop polling after 8s — by then the page is settled.
    if (MOUNT_ATTEMPTS > MAX_MOUNT_ATTEMPTS) clearInterval(savedInterval);
  }, 200);

  // Expose for debug.
  if (window.KT) window.KT.SearchWidget = { mount: tryMount };
})(window);
