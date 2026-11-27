/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR quick actions, My HR to-do and starter paths (HR Phase 4 batch 10, 2026-11-27).

   KT.hrQuick.open(anchor)        the "＋" quick-request popover (KT.quickPop, the one quick-choice look):
                                  time off, off sick (with a sick note), overtime, a claim, a hazard, an injury,
                                  and the person's to-do. Each one opens the SAME form the screen already has
                                  (it goes to the screen and presses its own button), so there is one form each.
   phone "＋" button               #kt-hrq-fab, docked in the phone header left of the gear (and of the 📞 when that
                                  is there), never floated over content and never added to a bottom bar: every role
                                  keeps its own five-tab bar (kt-mobilenav). kt-mobilenav positions it (dockHrq) and
                                  clears the name row for it (padGearClearance). Staff roles only, phone only.
   KT.hrQuick.todoCard(host)      the ONE My HR to-do list (GET /hr/me/todo: every pending item from every part of HR).
   KT.hrQuick.starterCard(host, role)   the role's starter path (GET /hr/me/starter): a short checklist, live.
   KT.hrQuick.helpButton(slugs)   the round "?" an HR tab shows; opens the article in the help drawer.
   Loaded on every page (small); the HR screens call into it.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var STAFF = ['educator', 'home_visitor', 'centre_director', 'agency_admin'];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c); } catch (e) {} }
  function user() { try { return (KT.Auth && KT.Auth.user && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function role() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return va;
    var u = user(); return u.primary_role || u.role_key || '';
  }
  function off(h) { var u = user(); return !!(u && Array.isArray(u.hidden_screens) && u.hidden_screens.indexOf(h) !== -1); }
  function hrOn() { return !off('my-hr') && !off('hr'); }
  function base(h) { return String(h || '').replace(/^#/, '').split('?')[0]; }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) : ''; }

  var CSS_ID = 'kt-hrq-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '#kt-hrq-fab{position:fixed;top:8px;right:60px;z-index:9450;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.94);box-shadow:0 2px 10px rgba(15,23,42,.2);cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;color:#0E7490;}'
      + '#kt-hrq-fab svg{width:22px;height:22px;}'
      + 'html.kt-dark #kt-hrq-fab{background:#1E293B;color:#5EEAD4;}'
      + '.hrq-help{width:30px;height:30px;min-width:30px;padding:0;border-radius:50%;border:1px solid #BAE6FD;background:#F0F9FF;color:#0E7490;font-weight:800;font-size:14px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}'
      + '.hrq-help:hover{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + 'html.kt-dark .hrq-help{background:#0B2530;border-color:#155E75;color:#5EEAD4;}'
      + '.hrq-card{border:1px solid #E2E8F0;border-radius:12px;background:#fff;padding:10px 14px;margin:0 0 12px;}'
      + '.hrq-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 6px;}.hrq-h b{font-size:13.5px;color:#0F172A;}'
      + '.hrq-h .hrq-n{font-size:12px;color:#64748B;font-weight:700;}.hrq-h .sp{flex:1;}'
      + '.hrq-row{display:flex;align-items:center;gap:10px;min-height:36px;padding:5px 4px;border-top:1px dashed #EEF2F6;font-size:13px;cursor:pointer;border-radius:8px;}'
      + '.hrq-row:first-of-type{border-top:0;}.hrq-row:hover{background:rgba(14,116,144,.06);}'
      + '.hrq-row .i{font-size:17px;width:22px;text-align:center;flex:0 0 auto;}.hrq-row .t{flex:1;min-width:0;}'
      + '.hrq-row .t small{display:block;font-size:11.5px;color:#64748B;font-weight:700;}'
      + '.hrq-row .t span{display:block;color:#0F172A;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.hrq-due{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#F1F5F9;color:#475569;white-space:nowrap;}'
      + '.hrq-due.o{background:#FEF2F2;color:#B91C1C;}'
      + '.hrq-b{height:28px;padding:0 11px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:12px;cursor:pointer;color:#0F172A;white-space:nowrap;}'
      + '.hrq-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}'
      + '.hrq-bar{height:6px;border-radius:999px;background:#E2E8F0;overflow:hidden;flex:1 1 120px;max-width:220px;}.hrq-bar i{display:block;height:100%;background:#0E7490;border-radius:999px;}'
      + '.hrq-st{display:flex;align-items:center;gap:10px;min-height:36px;padding:5px 0;border-top:1px dashed #EEF2F6;font-size:13px;}'
      + '.hrq-st .m{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex:0 0 auto;background:#F1F5F9;color:#64748B;border:1px solid #CBD5E1;}'
      + '.hrq-st.d .m{background:#DCFCE7;color:#047857;border-color:#DCFCE7;}.hrq-st.d .t b{color:#64748B;font-weight:600;}'
      + '.hrq-st .t{flex:1;min-width:0;}.hrq-st .t b{display:block;color:#0F172A;}.hrq-st .t small{display:block;font-size:11.5px;color:#64748B;}'
      + 'html.kt-dark .hrq-card{background:#0F172A;border-color:#334155;}html.kt-dark .hrq-h b,html.kt-dark .hrq-row .t span,html.kt-dark .hrq-st .t b{color:#E2E8F0;}'
      + 'html.kt-dark .hrq-row,html.kt-dark .hrq-st{border-color:#1E293B;}html.kt-dark .hrq-b{background:#0F172A;border-color:#334155;color:#E2E8F0;}'
      + 'html.kt-dark .hrq-b.p{background:#0E7490;border-color:#0E7490;color:#fff;}html.kt-dark .hrq-bar{background:#334155;}'
      + 'html.kt-dark .hrq-due{background:#1E293B;color:#CBD5E1;}html.kt-dark .hrq-due.o{background:#3B0D0D;color:#FCA5A5;}';
    document.head.appendChild(s);
  }

  /* ── go to a screen and press its own button ─────────────────────────────────────────────── */
  function visible(el) { if (!el) return false; var r = el.getBoundingClientRect(); return !!(r.width || r.height); }
  function waitFor(find, ms) {
    return new Promise(function (res) {
      var t0 = Date.now();
      (function tick() { var el = null; try { el = find(); } catch (e) {} if (el) return res(el); if (Date.now() - t0 > (ms || 8000)) return res(null); setTimeout(tick, 150); })();
    });
  }
  function goPress(hash, sels, missing, then) {
    var main = function () { return document.getElementById('appMain') || document; };
    if (base(location.hash) !== base(hash)) location.hash = '#' + hash;
    return waitFor(function () {
      for (var i = 0; i < sels.length; i++) { var el = main().querySelector(sels[i]); if (el && visible(el)) return el; }
      return null;
    }).then(function (el) {
      if (!el) { if (missing) toast('ℹ️', missing[0], missing[1]); return; }
      if (then) then(el); else el.click();
    });
  }
  function pickSick() {
    return waitFor(function () { return document.getElementById('tor-type'); }, 4000).then(function (sel) {
      if (!sel) return;
      var o = [].filter.call(sel.options, function (x) { return /sick|maladie|enferm|बीमार/i.test(x.textContent || '') || x.value === 'sick'; })[0];
      if (o) { sel.value = o.value; sel.dispatchEvent(new Event('change', { bubbles: true })); }
      var note = document.querySelector('[data-tor-pick]');
      if (note) { try { note.scrollIntoView({ block: 'center' }); } catch (e) {} }
    });
  }

  /* The quick requests this person can make, in their current role. */
  function items() {
    var r = role();
    if (STAFF.indexOf(r) === -1) return [];
    var hr = hrOn(), out = [];
    if (!off('time-off')) {
      out.push({ icon: '🌴', label: 'Request time off', run: function () { goPress('time-off', ['#tor-new'], ['Time off', 'Time off requests are not available here.']); } });
      out.push({ icon: '🤒', label: 'Off sick', sub: 'With a sick note or photo', run: function () { goPress('time-off', ['#tor-new'], ['Time off', 'Time off requests are not available here.'], function (b) { b.click(); pickSick(); }); } });
    }
    if (hr) {
      out.push({ icon: '⏱️', label: 'Log overtime', run: function () { goPress('my-hr', ['[data-mot="new"]'], ['Overtime', 'Your director logs overtime for you.']); } });
      out.push({ icon: '🧾', label: 'New claim', sub: 'Expenses or mileage', run: function () { goPress('my-hr', ['[data-mcl="new"]'], ['Claims', 'Claims are not open to you here yet.']); } });
    }
    if (!off('hazards')) {
      out.push({ icon: '🚧', label: 'Report a hazard', run: function () {
        goPress('hazards', ['#hz-new', '#hz-send'], ['Hazards', 'Hazard reports are not available here.'], function (b) {
          if (b.id === 'hz-new') { b.click(); return; }
          var box = b.closest('.kt-card') || b.parentNode.parentNode, f = box && box.querySelector('textarea, input:not([type=hidden]), select');
          try { (box || b).scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
          if (f) setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) {} }, 350);
        });
      } });
    }
    if (hr) {
      out.push({ icon: '🩹', label: 'Report an injury', sub: 'Or a near miss', run: function () { goPress('my-hr', ['[data-mij="new"]'], ['Injury reports', 'Injury reports are not available here.']); } });
      out.push({ icon: '📋', label: 'My HR to-do', sub: TODO_N != null ? (TODO_N ? TODO_N + ' waiting' : 'Nothing waiting') : '', run: function () { location.hash = '#my-hr?go=todo'; } });
    }
    return out;
  }
  var TODO_N = null;

  function open(anchor) {
    var its = items();
    if (!its.length) { toast('ℹ️', 'Quick requests', 'Nothing to ask for from here.'); return; }
    if (KT.quickPop) { KT.quickPop({ anchor: anchor, id: 'kt-hrq-pop', label: 'Quick requests', items: its }); return; }
    its[0].run();
  }

  /* ── the phone "＋" in the header ───────────────────────────────────────────────────────── */
  var PLUS = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8.5" fill="rgba(14,116,144,.12)"/><path d="M12 8v8M8 12h8"/></svg>';
  function syncFab() {
    var gear = document.getElementById('kt-gear'), f = document.getElementById('kt-hrq-fab');
    var want = !!gear && window.getComputedStyle(gear).display !== 'none' && items().length > 0;
    if (!want) { if (f) { f.remove(); if (KT.mnavPlace) KT.mnavPlace(); } return; }
    if (f) return;
    css();
    f = document.createElement('button');
    f.id = 'kt-hrq-fab'; f.type = 'button'; f.setAttribute('aria-label', 'Quick requests'); f.setAttribute('data-kttip', 'Quick requests');
    f.setAttribute('data-kt-iconized', '1'); f.setAttribute('aria-haspopup', 'menu');
    f.innerHTML = PLUS;
    f.addEventListener('click', function (e) { e.stopPropagation(); open(f); });
    document.body.appendChild(f);
    if (KT.mnavPlace) KT.mnavPlace();
  }
  var syncQ = null;
  function queueSync() { clearTimeout(syncQ); syncQ = setTimeout(syncFab, 250); }
  function boot() {
    queueSync();
    window.addEventListener('hashchange', queueSync);
    window.addEventListener('resize', queueSync);
    // The gear arrives with the phone chrome (kt-mobilenav) after sign-in and view-as switches.
    try { new MutationObserver(function (ms) { for (var i = 0; i < ms.length; i++) { if (ms[i].addedNodes.length || ms[i].removedNodes.length) { queueSync(); return; } } }).observe(document.body, { childList: true }); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  /* ── "?" help on an HR tab ─────────────────────────────────────────────────────────────── */
  function helpButton(slugs, title) {
    css();
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'hrq-help'; b.textContent = '?';
    b.setAttribute('data-kttip', title || 'Help for this page'); b.setAttribute('aria-label', title || 'Help for this page');
    b.setAttribute('data-kt-iconized', '1');
    b.addEventListener('click', function (e) { e.stopPropagation(); if (KT.openHelpDrawer) KT.openHelpDrawer(slugs); else location.hash = '#help/' + [].concat(slugs)[0]; });
    return b;
  }

  /* ── My HR: jump to a section once it has drawn ───────────────────────────────────────────── */
  var SECTION = { onboarding: /^your onboarding/i, courses: /^your courses/i, reviews: /^your reviews/i, documents: /^my documents/i, shifts: /^shifts to accept/i,
    punchfix: /^fix my clock-in/i, hstasks: /^my h&s tasks/i, duties: /^my h&s duties/i, availability: /^my shifts and availability/i, directory: /^team directory/i };
  function toSection(key) {
    var rx = SECTION[key];
    var find = function () {
      if (key === 'todo') return document.querySelector('[data-hrq-todo]');
      if (!rx) return null;
      return [].filter.call(document.querySelectorAll('#appMain .hrt-sec'), function (s) { return rx.test((s.textContent || '').trim()); })[0] || null;
    };
    return waitFor(find, 6000).then(function (el) {
      if (!el) return false;
      try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { el.scrollIntoView(); }
      return true;
    });
  }
  function goItem(it) {
    if (it.hash) { location.hash = '#' + it.hash; return; }
    if (base(location.hash) !== 'my-hr') { location.hash = '#my-hr?go=' + (it.section || 'todo'); return; }
    toSection(it.section).then(function (ok) { if (!ok) toast('ℹ️', it.label || 'My HR', 'Open My HR to see it.'); });
  }

  /* ── the ONE My HR to-do ─────────────────────────────────────────────────────────────────── */
  function todoCard(host, opts) {
    css(); opts = opts || {};
    host.setAttribute('data-hrq-todo', '1');
    host.innerHTML = '<div class="hrq-card" data-kt-nogrid><div class="hrq-h"><b>To do</b><span class="hrq-n">Loading…</span></div></div>';
    return KT.Api.get('/hr/me/todo').then(function (d) {
      if (!host.isConnected) return d;
      var list = (d && d.items) || [];
      TODO_N = list.length;
      var all = !!opts.all, max = 8;
      var paint = function () {
        var show = all ? list : list.slice(0, max);
        host.innerHTML = '<div class="hrq-card" data-kt-nogrid><div class="hrq-h">' + (opts.title === false ? '' : '<b>To do</b>') + '<span class="hrq-n">' + (list.length ? esc(list.length + ' waiting' + (d.overdue ? ' · ' + d.overdue + ' overdue' : '')) : '') + '</span></div>'
          + (list.length ? show.map(function (it, i) {
            return '<div class="hrq-row" role="button" tabindex="0" data-i="' + i + '"><span class="i" aria-hidden="true">' + esc(it.icon) + '</span><div class="t"><small>' + esc(it.label) + '</small><span data-no-i18n>' + esc(it.title) + '</span></div>'
              + (it.due ? '<span class="hrq-due' + (it.overdue ? ' o' : '') + '">' + esc((it.overdue ? 'Overdue · ' : 'Due ') + day(it.due)) + '</span>' : '') + '</div>';
          }).join('') + (list.length > max ? '<div style="margin-top:6px;"><button type="button" class="hrq-b" data-more data-kt-iconized="1">' + esc(all ? 'Show fewer' : 'Show all ' + list.length) + '</button></div>' : '')
            : '<div class="hr-sub" style="font-size:12.5px;color:#64748B;">Nothing waiting for you.</div>') + '</div>';
        host.querySelectorAll('[data-i]').forEach(function (r) {
          var it = show[+r.getAttribute('data-i')];
          r.addEventListener('click', function () { goItem(it); });
          r.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goItem(it); } });
        });
        var mo = host.querySelector('[data-more]'); if (mo) mo.addEventListener('click', function () { all = !all; paint(); });
      };
      paint();
      return d;
    }, function () { if (host.isConnected) host.innerHTML = ''; });
  }

  /* ── the role's starter path ─────────────────────────────────────────────────────────────── */
  var TITLES = { admin: 'Your first week in HR', director: 'Your first week as a director', educator: 'Getting set up in HR', home_visitor: 'Getting set up in HR' };
  function starterCard(host, roleKey) {
    css();
    return KT.Api.get('/hr/me/starter' + (roleKey ? '?role=' + encodeURIComponent(roleKey) : '')).then(function (d) {
      if (!host.isConnected) return;
      if (!d || !d.path || !(d.steps || []).length) { host.innerHTML = ''; return; }
      var hidden = !!d.hidden, all = d.done === d.total;
      var head = '<div class="hrq-h"><span aria-hidden="true">🚀</span><b>' + esc(TITLES[d.path] || 'Getting started') + '</b><span class="hrq-n">' + esc(d.done + ' of ' + d.total + ' done') + '</span>'
        + '<span class="hrq-bar"><i style="width:' + Math.round(100 * d.done / Math.max(1, d.total)) + '%;"></i></span><span class="sp"></span>'
        + '<button type="button" class="hrq-b" data-tg data-kt-iconized="1">' + (hidden ? 'Show' : 'Hide') + '</button></div>';
      if (hidden) { host.innerHTML = '<div class="hrq-card" data-kt-nogrid style="padding:6px 14px;">' + head + '</div>'; }
      else {
        host.innerHTML = '<div class="hrq-card" data-kt-nogrid>' + head
          + (all ? '<div class="hr-sub" style="font-size:12.5px;color:#047857;margin:2px 0 6px;">🎉 All done. You can hide this.</div>' : '<div style="font-size:12px;color:#64748B;margin:0 0 4px;">A few steps to get set up. Each one opens the right screen, and ? explains it.</div>')
          + d.steps.map(function (s, i) {
            return '<div class="hrq-st' + (s.done ? ' d' : '') + '"><span class="m">' + (s.done ? '✓' : (i + 1)) + '</span><div class="t"><b>' + esc(s.label) + '</b><small>' + esc(s.detail || '') + '</small></div>'
              + (s.done ? '' : '<button type="button" class="hrq-b p" data-go="' + i + '" data-kt-iconized="1">Go</button>') + '<span data-help="' + i + '"></span></div>';
          }).join('') + '</div>';
        host.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { var s = d.steps[+b.getAttribute('data-go')]; location.hash = '#' + s.hash; }); });
        host.querySelectorAll('[data-help]').forEach(function (sp) { var s = d.steps[+sp.getAttribute('data-help')]; if (s.help) sp.appendChild(helpButton([s.help], 'How to do this')); });
      }
      var tg = host.querySelector('[data-tg]');
      if (tg) tg.addEventListener('click', function () {
        tg.disabled = true;
        KT.Api.post('/hr/me/starter', { path: d.path, hidden: !hidden }).then(function () { starterCard(host, roleKey); }, function () { tg.disabled = false; toast('⚠️', 'Could not save', 'Please try again.', '#DC2626'); });
      });
    }, function () { if (host.isConnected) host.innerHTML = ''; });
  }

  KT.hrQuick = { role: role, open: open, items: items, todoCard: todoCard, starterCard: starterCard, helpButton: helpButton, toSection: toSection, sync: queueSync };
})(window);
