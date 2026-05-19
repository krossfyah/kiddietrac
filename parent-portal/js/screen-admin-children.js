/* ============================================================
   KIDDIETRAC v22p13 — Agency-admin children list
   Hash: #admin-children — agency_admin only
   Backend: /api/v1/director/enrollments (already accepts agency_admin)
           /api/v1/admin/centres (for the centre filter dropdown)
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) {
    return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function parseHashParams() {
    var q = (window.location.hash || '').split('?')[1] || '';
    var out = {};
    q.split('&').forEach(function (kv) {
      if (!kv) return;
      var parts = kv.split('=');
      out[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || '');
    });
    return out;
  }

  function statusBadge(status, isPresent) {
    if (isPresent) {
      return '<span style="padding:2px 8px;border-radius:999px;background:#16A34A;color:white;font-size:10px;font-weight:700;letter-spacing:0.5px;">AT CENTRE</span>';
    }
    var map = {
      enrolled:  ['#DBEAFE', '#1F6080', 'ENROLLED'],
      waitlist:  ['#FEF3C7', '#92400E', 'WAITLIST'],
      withdrawn: ['#F3F4F6', '#6B7280', 'WITHDRAWN'],
      inactive:  ['#F3F4F6', '#6B7280', 'INACTIVE'],
    };
    var m = map[status] || ['#F3F4F6', '#6B7280', (status || 'UNKNOWN').toUpperCase()];
    return '<span style="padding:2px 8px;border-radius:999px;background:' + m[0] + ';color:' + m[1] + ';font-size:10px;font-weight:700;letter-spacing:0.5px;">' + m[2] + '</span>';
  }

  function renderChildren(container) {
    Dom.clear(container);

    var wrap = Dom.el('div', { style: 'padding: 24px; max-width: 1800px; margin: 0 auto;' });
    container.appendChild(wrap);

    // Hero
    var clouds = (KT.Illustrations && KT.Illustrations.cloudsAndStars) ? KT.Illustrations.cloudsAndStars() : '';
    var heroEl = Dom.el('div', { class: 'kt-hero', style: 'padding: 24px 28px; margin-bottom: 20px;' });
    heroEl.innerHTML =
      '<h1 style="font-size:24px;margin:0 0 4px;">🧒 Children</h1>' +
      '<div class="kt-hero-sub" style="font-size:14px;">Every enrolled child across your centres. Filter by centre or status to narrow down.</div>' +
      '<div class="kt-hero-svg" style="width:180px;height:140px;right:16px;bottom:-4px;">' + clouds + '</div>';
    wrap.appendChild(heroEl);

    // Filter bar
    var filters = parseHashParams();
    var filterBar = Dom.el('div', {
      style: 'display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;background:white;border-radius:10px;padding:12px 14px;box-shadow:0 1px 3px rgba(0,0,0,.04);',
    });
    var centreSelect = Dom.el('select', {
      style: 'padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;background:white;min-width:200px;',
    });
    centreSelect.appendChild(Dom.el('option', { value: '' }, 'All centres'));
    var statusSelect = Dom.el('select', {
      style: 'padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;background:white;',
    });
    [
      { value: '', label: 'All statuses' },
      { value: 'enrolled', label: 'Enrolled' },
      { value: 'waitlist', label: 'Waitlist' },
      { value: 'withdrawn', label: 'Withdrawn' },
      { value: 'inactive', label: 'Inactive' },
    ].forEach(function (o) {
      var opt = Dom.el('option', { value: o.value }, o.label);
      if (filters.status === o.value) opt.selected = true;
      statusSelect.appendChild(opt);
    });
    var searchInput = Dom.el('input', {
      type: 'search', placeholder: 'Search by name…',
      style: 'flex:1;min-width:180px;padding:7px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;',
    });
    searchInput.value = filters.search || '';

    filterBar.appendChild(Dom.el('span', { style: 'font-size:12px;color:#6B7280;font-weight:600;' }, 'Filter:'));
    filterBar.appendChild(centreSelect);
    filterBar.appendChild(statusSelect);
    filterBar.appendChild(searchInput);
    wrap.appendChild(filterBar);

    var resultsEl = Dom.el('div');
    wrap.appendChild(resultsEl);

    function showLoading() {
      resultsEl.innerHTML = '<div style="padding:40px;text-align:center;color:#6B7280;">Loading children…</div>';
    }
    function showError(msg) {
      resultsEl.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(msg) + '</div>';
    }

    function fetchAndRender() {
      showLoading();
      var params = {};
      if (centreSelect.value) params.centre_id = centreSelect.value;
      if (statusSelect.value) params.status = statusSelect.value;
      if (searchInput.value.trim()) params.search = searchInput.value.trim();
      Api.get('/director/enrollments', params).then(function (data) {
        renderResults(data.children || []);
      }).catch(function (e) {
        showError(e.message || 'Server error');
      });
    }

    function renderResults(children) {
      Dom.clear(resultsEl);
      if (!children.length) {
        var emptySvg = (KT.Illustrations && KT.Illustrations.emptyChildren) ? KT.Illustrations.emptyChildren() : '';
        var empty = Dom.el('div', { class: 'kt-empty', style: 'background:white;border-radius:14px;' });
        empty.innerHTML =
          (emptySvg ? '<div class="kt-empty-svg">' + emptySvg + '</div>' : '') +
          '<h3>No children match</h3>' +
          '<p>Try removing a filter, or use the search box for a partial name.</p>';
        resultsEl.appendChild(empty);
        return;
      }

      // Count summary
      resultsEl.appendChild(Dom.el('div', {
        style: 'font-size:13px;color:#6B7280;margin-bottom:10px;',
      }, children.length + ' child' + (children.length === 1 ? '' : 'ren')));

      // Grid of cards
      var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;' });
      children.forEach(function (c) {
        var card = Dom.el('a', {
          href: '#child-detail?id=' + c.id + (centreSelect.value ? '&centre_id=' + centreSelect.value : ''),
          class: 'kt-lift',
          style: 'display:block;background:white;padding:14px 16px;border-radius:12px;text-decoration:none;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.04);border-left:4px solid ' + (c.room_color || '#1F6080') + ';',
        });
        var header = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;' });
        var nameWrap = Dom.el('div');
        nameWrap.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;' }, c.full_name || (c.first_name + ' ' + c.last_name)));
        nameWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:1px;' },
          (c.age && c.age.human ? c.age.human : '') +
          (c.room_name ? ' · ' + c.room_name : '')));
        header.appendChild(nameWrap);
        var badge = Dom.el('div');
        badge.innerHTML = statusBadge(c.enrollment_status, c.is_at_centre);
        header.appendChild(badge);
        card.appendChild(header);
        if (c.family_name) {
          card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, '👪 ' + c.family_name + ' family'));
        }
        if (c.monthly_fee) {
          card.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:4px;' }, '💰 $' + c.monthly_fee + ' / month'));
        }
        grid.appendChild(card);
      });
      resultsEl.appendChild(grid);
    }

    // Load centres for the dropdown (parallel with the initial fetch)
    Api.get('/admin/centres').then(function (data) {
      (data.centres || []).forEach(function (c) {
        var opt = Dom.el('option', { value: c.id }, c.name);
        if (filters.centre_id && String(filters.centre_id) === String(c.id)) opt.selected = true;
        centreSelect.appendChild(opt);
      });
    });

    // Wire change handlers
    var debounceT;
    function rerunDebounced() {
      clearTimeout(debounceT);
      debounceT = setTimeout(fetchAndRender, 300);
    }
    centreSelect.addEventListener('change', fetchAndRender);
    statusSelect.addEventListener('change', fetchAndRender);
    searchInput.addEventListener('input', rerunDebounced);

    fetchAndRender();
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:admin-children', renderChildren);
  }
  KT.AdminChildrenScreen = { render: renderChildren };
})(window);
