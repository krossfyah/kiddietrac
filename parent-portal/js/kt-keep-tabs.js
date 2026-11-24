/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — A REFRESH KEEPS YOU ON YOUR TAB (2026-10-23).

   Anthony: "under website and subscribers - removing/deleting a subscriber makes the
   section jump and leaves that section - look at the behaviour and do a full sweep".

   THE MECHANISM. Every successful save or delete rings the live-refresh bus (kt-live.js),
   and ~600 ms later the shell re-renders the current screen from scratch. A screen with
   tabs builds itself on its FIRST tab, so the refresh threw you from Subscribers back to
   Overview, and the scroll position that the shell carefully restores was then applied to
   a different, shorter tab: "jumps and leaves that section". The same happened on every
   tabbed screen in the portal after any write; Settings and Carrier settings had each been
   patched on their own (IGNORE entries, a sessionStorage tab key), which is how the next
   screen ships with the bug.

   THE RULE. The tabs you click on a screen are remembered (per screen, until you leave
   it). When the SAME screen is re-rendered - a live refresh, or a screen's own reload -
   the shell asks this file to put them back, in the order you chose them (outer tab, then
   inner), by clicking them exactly as you did, before the refresh is uncovered. Then the
   shell's scroll restore lands on the right content. Navigating to another screen forgets
   them, so a fresh visit still opens on the first tab.

   A "tab" is the same thing kt-steady-dialog.js recognises: role=tab, a data-…tab
   attribute (data-tab, data-sub-tab, data-eatab…), a kt-subtabs / tab-button class, or a
   child of a tablist / data-kt-tabbar. Dialogs are excluded - they are not re-rendered.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktKeepTabs) return;
  window.__ktKeepTabs = true;

  var TAB_ATTR = /^data-(?:[a-z0-9]+-)*[a-z0-9]*tabs?$/;
  var TAB_CLASS = /(^|\s)(kt-)?(sub)?tabs?(-btn|-button|-item|-link)?(\s|$)|(^|\s)[a-z-]*subtab[a-z-]*(\s|$)|(^|\s)[a-z]+Tab(\s|$)/;

  function screenKey() { return (location.hash || '').split('?')[0] || '#'; }
  var memo = { key: screenKey(), picks: [] };   // picks: [{group, attr, val, text}]

  function attrOf(el) {
    if (!el.attributes) return null;
    for (var i = 0; i < el.attributes.length; i++) {
      var n = el.attributes[i].name;
      if (TAB_ATTR.test(n) && n.indexOf('table') < 0) return n;
    }
    return null;
  }
  function inDialog(el) {
    return !!(el.closest && el.closest('.modal, .modal-backdrop, [role=dialog], .kt-scrim, .kt-modal-overlay'));
  }
  /* The tab element a click landed on, or null. */
  function tabOf(target) {
    var main = document.getElementById('appMain');
    if (!main || !main.contains(target) || inDialog(target)) return null;
    for (var e = target; e && e !== main; e = e.parentElement) {
      if (e.getAttribute('role') === 'tab' || attrOf(e)) return e;
      if (typeof e.className === 'string' && TAB_CLASS.test(e.className) && !/table/i.test(e.className)) return e;
      var p = e.parentElement;
      if (p && (p.getAttribute('role') === 'tablist' || p.hasAttribute('data-kt-tabbar')) && /^(BUTTON|A|LI|DIV|SPAN)$/.test(e.tagName)) return e;
    }
    return null;
  }
  function describe(el) {
    var a = attrOf(el);
    var text = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    var p = el.parentElement;
    var group = a ? 'a:' + a : 'p:' + (p ? (p.getAttribute('role') || '') + '|' + (typeof p.className === 'string' ? p.className.split(/\s+/)[0] : '') + '|' + p.children.length : '');
    return { group: group, attr: a, val: a ? el.getAttribute(a) : null, text: text };
  }

  document.addEventListener('click', function (e) {
    if (screenKey() !== memo.key) memo = { key: screenKey(), picks: [] };
    var t = tabOf(e.target);
    if (!t) return;
    // A tab that navigates (href to another hash) is navigation, not a tab state.
    var href = t.getAttribute('href');
    if (href && href.charAt(0) === '#' && href.split('?')[0] !== memo.key) return;
    var d = describe(t);
    // One pick per tab group; a later choice in a group replaces the earlier one, and
    // anything picked after it (an inner tab of the old choice) goes with it.
    for (var i = 0; i < memo.picks.length; i++) {
      if (memo.picks[i].group === d.group) { memo.picks = memo.picks.slice(0, i); break; }
    }
    memo.picks.push(d);
  }, true);
  window.addEventListener('hashchange', function () {
    if (screenKey() !== memo.key) memo = { key: screenKey(), picks: [] };
  });

  /* SEARCH TEXT TOO. The same rebuild emptied every search / filter box: you typed a name
     to find a row, acted on it, and the refresh brought the whole unfiltered list back.
     Only search-type boxes - their input event filters and never saves. Dropdowns are
     deliberately left alone: on several screens changing a select saves at once (a lead's
     stage), and replaying that would be a write nobody asked for. */
  var searches = { key: screenKey(), vals: {} };
  function isSearch(el) {
    return el && el.tagName === 'INPUT' && !inDialog(el)
      && (el.type === 'search' || /search|filter|find/i.test(el.getAttribute('placeholder') || ''));
  }
  /* Digits out: the shared filter's hint carries the row count ("Filter 31 rows…"), and the
     write that triggered the refresh - a delete - is exactly what changes it to 30. */
  function searchKey(el) { return (el.id ? '#' + el.id : '') + '|' + (el.getAttribute('placeholder') || '').replace(/\d+/g, '#') + '|' + (el.name || ''); }
  document.addEventListener('input', function (e) {
    var el = e.target;
    var main = document.getElementById('appMain');
    if (!main || !main.contains(el) || !isSearch(el) || e.__ktReplay) return;
    if (screenKey() !== searches.key) searches = { key: screenKey(), vals: {} };
    searches.vals[searchKey(el)] = el.value;
  }, true);
  window.addEventListener('hashchange', function () {
    if (screenKey() !== searches.key) searches = { key: screenKey(), vals: {} };
  });
  /* Most list screens get their search box from the shared table filter, which adds it a
     moment AFTER the screen has drawn - so the value is put back on a few passes, each
     touching only a box that does not already hold it. */
  function restoreSearches(main) {
    [0, 250, 700, 1400, 2500, 4000].forEach(function (ms) { setTimeout(function () { try { restoreSearchesOnce(main); } catch (e) {} }, ms); });
  }
  function restoreSearchesOnce(main) {
    if (!main.isConnected) main = document.getElementById('appMain') || main;
    if (screenKey() !== searches.key) return;
    var keys = Object.keys(searches.vals);
    if (!keys.length) return;
    [].forEach.call(main.querySelectorAll('input'), function (el) {
      if (!isSearch(el)) return;
      var k = searchKey(el);
      if (!(k in searches.vals) || el.value === searches.vals[k] || !searches.vals[k]) return;
      el.value = searches.vals[k];
      try { var ev = new Event('input', { bubbles: true }); ev.__ktReplay = true; el.dispatchEvent(ev); } catch (er) {}
      try { var kv = new KeyboardEvent('keyup', { bubbles: true }); kv.__ktReplay = true; el.dispatchEvent(kv); } catch (er) {}
    });
  }

  function find(main, d) {
    if (d.attr) {
      var el = null;
      try { el = main.querySelector('[' + d.attr + '="' + (window.CSS && CSS.escape ? CSS.escape(d.val) : d.val) + '"]'); } catch (e) {}
      if (el && !inDialog(el)) return el;
      return null;
    }
    var cands = main.querySelectorAll('[role=tab], button, a, li');
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      if (inDialog(c)) continue;
      if ((c.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60) !== d.text) continue;
      if (tabOf(c) === c && describe(c).group === d.group) return c;
    }
    return null;
  }

  /* Put the remembered tabs back on a re-render of the same screen. Resolves when done
     (each missing tab is waited for briefly - some screens paint their tab bar late). */
  function restore(main) {
    return new Promise(function (resolve) {
      if (!main || screenKey() !== memo.key || !memo.picks.length) { try { if (main) restoreSearches(main); } catch (e) {} resolve(); return; }
      var picks = memo.picks.slice(), i = 0, waited = 0;
      (function next() {
        if (i >= picks.length || screenKey() !== memo.key || !main.isConnected) { memo.picks = picks; try { restoreSearches(main); } catch (e) {} resolve(); return; }
        var el = find(main, picks[i]);
        if (!el) {
          if (waited < 3000) { waited += 100; setTimeout(next, 100); return; }   // tab bars painted after their data arrives (Sales → Bookings)
          picks = picks.slice(0, i); i = picks.length; next(); return;   // gone: stop here
        }
        waited = 0;
        try { el.click(); } catch (e) {}
        i++;
        setTimeout(next, 30);
      })();
    });
  }

  window.KT = window.KT || {};
  window.KT.__keepTabs = { restore: restore, _memo: function () { return memo; } };
})();
