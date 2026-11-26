/* SIDEBAR SECTIONS READ A TO Z (2026-11-04). Anthony: "based on the sidebar in the portal for each section add a
   rule/guard to ensure it's sorted by alphabetical order when adding new sections and existing - full sweep".

   The menu is built by three hands - navItemsForRole() in app-v2-shell.js, the Platform/Sales block agency-switcher.js
   injects after its fetch, and kt-term.js renaming labels to the agency's own words - so sorting any one source list
   would be undone by the next. This sorts the sidebar AS DRAWN, by the label people actually read, every time it
   changes: a link added to any section by anyone, now or later, lands in its alphabetical place with no list to keep
   in order by hand.

   - Each .sidebar-section in #navLinks: the heading stays first, the links below it go A to Z (case, accents and a
     leading emoji ignored; numbers in number order).
   - A sub-item (.nav-link--sub, e.g. "New lead" under "Leads") travels with the link above it and is sorted among
     its own siblings.
   - Anything in a section that is not a link keeps its place after the links.
   - The order of the SECTIONS and the flat educator / parent menus (no sections) are left as they are.
   - A section marked data-kt-keep-order (app-v2-shell keepOrder) keeps its listed order (Human Resources, 2026-11-26).
   - Nothing is moved when the section is already in order, so the observer settles after one pass. */
(function () {
  'use strict';
  if (window.__ktNavSort) return;
  window.__ktNavSort = true;

  function key(a) {
    var l = a.querySelector('.nav-label') || a;
    return String(l.textContent || '').replace(/^[^\p{L}\p{N}]+/u, '').trim();
  }
  var cmp = (window.Intl && Intl.Collator) ? new Intl.Collator(undefined, { sensitivity: 'base', numeric: true }).compare
    : function (x, y) { return x.toLowerCase() < y.toLowerCase() ? -1 : x.toLowerCase() > y.toLowerCase() ? 1 : 0; };
  function byKey(x, y) { return cmp(x.k, y.k); }
  function isLink(n) { return n.nodeType === 1 && n.classList.contains('nav-link'); }
  function isSub(n) { return isLink(n) && n.classList.contains('nav-link--sub'); }

  function sortSection(sec) {
    // A section built with an explicit order (data-kt-keep-order, e.g. Human Resources: HR, My HR, Time off requests,
    // Timesheets, Payroll, Background checks - Anthony 2026-11-26) keeps it.
    if (sec.hasAttribute('data-kt-keep-order')) return false;
    var kids = Array.prototype.slice.call(sec.children);
    var groups = [], rest = [], cur = null;
    kids.forEach(function (n) {
      if (n.classList.contains('sidebar-section-label')) return;
      if (isSub(n) && cur) { cur.subs.push({ n: n, k: key(n) }); return; }
      if (isLink(n)) { cur = { n: n, k: key(n), subs: [] }; groups.push(cur); return; }
      cur = null; rest.push(n);
    });
    if (groups.length < 2 && !(groups[0] && groups[0].subs.length > 1)) return false;
    groups.sort(byKey);
    var want = [];
    groups.forEach(function (g) { want.push(g.n); g.subs.sort(byKey).forEach(function (s) { want.push(s.n); }); });
    want = want.concat(rest);
    var have = kids.filter(function (n) { return !n.classList.contains('sidebar-section-label'); });
    var same = want.length === have.length && want.every(function (n, i) { return n === have[i]; });
    if (same) return false;
    want.forEach(function (n) { sec.appendChild(n); });   // appendChild moves; the heading stays in front
    return true;
  }

  function sortAll() {
    var nl = document.getElementById('navLinks');
    if (!nl) return 0;
    var moved = 0;
    Array.prototype.forEach.call(nl.querySelectorAll('.sidebar-section'), function (sec) {
      try { if (sortSection(sec)) moved++; } catch (e) {}
    });
    return moved;
  }

  var raf = 0, watched = null, obs = null;
  function soon() { if (!raf) raf = requestAnimationFrame(function () { raf = 0; sortAll(); }); }
  function watch() {
    var nl = document.getElementById('navLinks');
    if (!nl) return;
    if (nl !== watched && window.MutationObserver) {
      if (obs) obs.disconnect();
      watched = nl;
      // childList: links added or rebuilt; characterData: a label renamed (kt-term) after the sort.
      obs = new MutationObserver(soon);
      obs.observe(nl, { childList: true, subtree: true, characterData: true });
    }
    soon();
  }

  window.KT = window.KT || {};
  window.KT.sortNav = sortAll;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch); else watch();
  window.addEventListener('kt:platform-nav', soon);
  window.addEventListener('hashchange', watch);
  // A rebuilt sidebar can be a NEW #navLinks the observer never saw: re-attach on a slow tick (one lookup).
  setInterval(watch, 3000);
})();
