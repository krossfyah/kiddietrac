/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p72 — collapsible sidebar nav sections
   The admin nav has ~10 groups (Operations, Growth, Programs, Staff,
   Compliance, Reseller, Enrollment, Administration, Engagement, Settings).
   That's a long scroll. This makes each group header click-to-collapse,
   with a chevron + persisted state, and an "Expand/Collapse all" control.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  var LS_KEY = 'kt_nav_collapsed_sections';

  function getCollapsed() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch (e) { return {}; }
  }
  function setCollapsed(map) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(map)); } catch (e) {}
  }

  function labelText(section) {
    var l = section.querySelector('.sidebar-section-label');
    return l ? (l.textContent || '').trim() : '';
  }

  function applyCollapsedState(section, collapsed) {
    if (collapsed) section.classList.add('kt-nav-collapsed');
    else section.classList.remove('kt-nav-collapsed');
    var chev = section.querySelector('.kt-nav-chev');
    if (chev) chev.textContent = collapsed ? '▸' : '▾';
  }

  function enhance() {
    var nav = document.getElementById('navLinks');
    if (!nav) return;
    var sections = nav.querySelectorAll('.sidebar-section');
    if (!sections.length) return;

    var state = getCollapsed();

    sections.forEach(function (section) {
      var label = section.querySelector('.sidebar-section-label');
      if (!label || label._ktCollapsible) return;
      label._ktCollapsible = true;

      var name = labelText(section);

      // Add chevron
      var chev = document.createElement('span');
      chev.className = 'kt-nav-chev';
      chev.textContent = '▾';
      label.appendChild(chev);
      label.style.cursor = 'pointer';
      label.style.userSelect = 'none';
      label.setAttribute('role', 'button');
      label.title = 'Collapse / expand ' + name;

      // Restore persisted state
      applyCollapsedState(section, !!state[name]);

      label.addEventListener('click', function () {
        var map = getCollapsed();
        var nowCollapsed = !section.classList.contains('kt-nav-collapsed');
        map[name] = nowCollapsed;
        setCollapsed(map);
        applyCollapsedState(section, nowCollapsed);
      });
    });

    injectExpandAllControl(nav, sections);
    injectStyles();
  }

  function injectExpandAllControl(nav, sections) {
    if (document.getElementById('kt-nav-allctl')) return;
    var bar = document.createElement('div');
    bar.id = 'kt-nav-allctl';
    bar.className = 'kt-nav-allctl';
    var btn = document.createElement('button');
    btn.className = 'kt-nav-allbtn';
    btn.textContent = '⇕ Collapse all';
    var allCollapsed = false;
    btn.addEventListener('click', function () {
      allCollapsed = !allCollapsed;
      var map = getCollapsed();
      document.querySelectorAll('#navLinks .sidebar-section').forEach(function (s) {
        var name = labelText(s);
        // Never collapse the Overview section (keep dashboard reachable)
        if (/overview/i.test(name)) return;
        map[name] = allCollapsed;
        applyCollapsedState(s, allCollapsed);
      });
      setCollapsed(map);
      btn.textContent = allCollapsed ? '⇕ Expand all' : '⇕ Collapse all';
    });
    bar.appendChild(btn);
    nav.insertBefore(bar, nav.firstChild);
  }

  function injectStyles() {
    if (document.getElementById('kt-nav-collapse-styles')) return;
    var s = document.createElement('style');
    s.id = 'kt-nav-collapse-styles';
    s.textContent = [
      '.sidebar-section-label { display:flex !important; align-items:center; justify-content:space-between; gap:6px; transition: color .12s; }',
      '.sidebar-section-label:hover { color: var(--kt-primary, #1F6080); }',
      '.kt-nav-chev { font-size: 10px; opacity: .65; transition: transform .15s; flex:0 0 auto; }',
      /* Collapse: hide the links but keep the label */
      '.sidebar-section.kt-nav-collapsed .nav-link { display: none !important; }',
      '.sidebar-section { transition: margin .15s; }',
      '.sidebar-section.kt-nav-collapsed { margin-bottom: 2px; }',
      '.kt-nav-allctl { padding: 6px 10px 10px; }',
      '.kt-nav-allbtn { width:100%; background: rgba(31,96,128,.06); border:1px solid var(--kt-border,#E5E7EB); color: var(--kt-text-muted,#4B5563); border-radius:8px; padding:7px 10px; font-size:11px; font-weight:700; letter-spacing:.4px; cursor:pointer; transition: all .12s; }',
      '.kt-nav-allbtn:hover { background: rgba(31,96,128,.14); color: var(--kt-primary,#1F6080); }',
    ].join('\n');
    document.head.appendChild(s);
  }

  // Run after nav builds, and re-run if nav is rebuilt
  function init() {
    enhance();
    var nav = document.getElementById('navLinks');
    if (nav && !nav._ktCollapseObserved) {
      nav._ktCollapseObserved = true;
      new MutationObserver(function () { enhance(); }).observe(nav, { childList: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
  } else {
    setTimeout(init, 400);
  }
  // Also hook hashchange (nav active state updates) + a periodic safety net
  window.addEventListener('hashchange', function () { setTimeout(enhance, 100); });
  setTimeout(init, 1200);
})(window);
