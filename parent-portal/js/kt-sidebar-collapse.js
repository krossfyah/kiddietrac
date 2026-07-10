/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — collapsible left sidebar (2026-07-08)
   A small « button collapses the sidebar (slides it out, content expands to
   full width); a floating ☰ button brings it back. State persists in
   localStorage. Purely additive — two fixed buttons on <body> + a body class;
   the CSS lives in kt-consistency-polish.css.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KEY = 'kt_sidebar_collapsed';

  function apply(collapsed) {
    document.body.classList.toggle('kt-sidebar-collapsed', !!collapsed);
  }
  function toggle() {
    var c = !document.body.classList.contains('kt-sidebar-collapsed');
    try { localStorage.setItem(KEY, c ? '1' : '0'); } catch (e) {}
    apply(c);
  }
  function ensure() {
    // Parents (guardians) navigate from the tile home, not the sidebar, so the
    // collapse/expand arrows are just clutter for them — never show them.
    var isParent = document.body.classList.contains('role-guardian') || document.body.classList.contains('kt-parentview');
    if (isParent) {
      var oc = document.getElementById('kt-sb-collapse'); if (oc) oc.remove();
      var ot = document.getElementById('kt-sb-toggle'); if (ot) ot.remove();
      document.body.classList.remove('kt-sidebar-collapsed');
      return;
    }
    if (!document.getElementById('kt-sb-collapse')) {
      var b = document.createElement('button');
      b.id = 'kt-sb-collapse'; b.type = 'button';
      b.title = 'Collapse menu'; b.setAttribute('aria-label', 'Collapse menu');
      b.textContent = '«';
      b.addEventListener('click', toggle);
      document.body.appendChild(b);
    }
    if (!document.getElementById('kt-sb-toggle')) {
      var f = document.createElement('button');
      f.id = 'kt-sb-toggle'; f.type = 'button';
      f.title = 'Expand menu'; f.setAttribute('aria-label', 'Expand menu');
      f.textContent = '»';
      f.addEventListener('click', toggle);
      document.body.appendChild(f);
    }
    // In the collapsed rail only icons show — give each a hover tooltip of its
    // section name so it's still obvious what each icon is.
    var links = document.querySelectorAll('#navLinks .nav-link');
    for (var i = 0; i < links.length; i++) {
      if (links[i].getAttribute('title')) continue;
      var lbl = links[i].querySelector('.nav-label');
      if (lbl) links[i].setAttribute('title', (lbl.textContent || '').trim());
    }
  }
  function init() {
    ensure();
    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch (e) {}
    apply(saved === '1');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  setInterval(ensure, 2500); // safety net if the shell re-mounts
  if (w.KT) w.KT.toggleSidebar = toggle;
})(window);
