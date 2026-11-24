/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — "What's new" changelog (2026-07-08).
   A top-bar 🎁 button (admins + centre directors only) opening a popup that
   lists recently-added features (last 3 months — older ones auto-archive) and
   what's coming soon, each with the date introduced. Content lives in
   /whats-new.json. An unseen-entry badge shows until the user opens it.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktWhatsNew) return; window.__ktWhatsNew = true;
  /* ONE FIRST-RUN QUESTION AT A TIME (2026-11-03, Anthony's iPhone recording of a first sign-in: the biometric offer,
     the phone's "allow notifications" alert and What's new came back to back and on top of each other). Each of the
     three ran on its own clock. They now take turns: KT.promptTurn(name) resolves when nothing else is asking and a
     breather (1.5 s) has passed since the last one closed; KT.promptDone(name) frees the turn. When several are
     waiting, the order is biometric, then notifications, then What's new. Nothing is dropped - a question that is
     waiting keeps its place. While one is up, html.kt-prompt-up puts the toast stack under it (kt-toasts.js).
     Installed by whichever of kt-biometric.js, kt-native-push.js and kt-whatsnew.js loads first. */
  (function (w) {
    w.KT = w.KT || {};
    if (w.KT.promptTurn) return;
    var ORDER = { biometric: 1, push: 2, whatsnew: 3 }, GAP = 1500;
    var active = null, freeAt = 0, waiting = [];
    /* WHAT MOVES THE SCREEN RIGHT AFTER A QUESTION CLOSES (2026-11-03). On Anthony's iPhone, Learning Observations opened
       by itself ~2 s after "Not now" on the biometric offer, and the screen went back to Home when What's new closed;
       neither happens in a desktop or WebKit test. A screen change in the 6 s after one of these questions closes is
       reported once (diag/scroll, "PROMPTNAV"): from/to, how long after which question, and the last click (trusted or
       synthetic, where, on what). Diagnostic only - nothing is changed for the person. */
    var closedAt = 0, closedName = '', lastTap = null, told = 0;
    try {
      document.addEventListener('click', function (e) {
        var t = e.target, el = '';
        try { el = ((t.closest && t.closest('a,button,[data-hash],[role=button]')) || t).outerHTML.slice(0, 140); } catch (x) {}
        lastTap = { ago: Date.now(), trusted: !!e.isTrusted, x: Math.round(e.clientX || 0), y: Math.round(e.clientY || 0), el: el };
      }, true);
      w.addEventListener('hashchange', function (e) {
        if (!closedAt || Date.now() - closedAt > 6000 || told >= 3) return;
        told++;
        var tap = lastTap ? { trusted: lastTap.trusted, x: lastTap.x, y: lastTap.y, el: lastTap.el, msBefore: Date.now() - lastTap.ago } : null;
        var tok = ''; try { tok = sessionStorage.getItem('kt_token') || ''; } catch (x) {}
        fetch('https://api.kiddietrac.com/api/v1/diag/scroll', { method: 'POST', keepalive: true,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: tok ? 'Bearer ' + tok : '' },
          body: JSON.stringify({ data: ('PROMPTNAV ' + String(e.oldURL || '').split('#')[1] + ' -> ' + location.hash + ' ' + (Date.now() - closedAt) + 'ms after ' + closedName
            + ' closed; vis=' + document.visibilityState + ' focus=' + document.hasFocus() + ' last click ' + JSON.stringify(tap)).slice(0, 600) }) }).catch(function () {});
      });
    } catch (e) {}
    function up(on) { try { document.documentElement.classList.toggle('kt-prompt-up', on); } catch (e) {} }
    function pump() {
      if (active || !waiting.length) return;
      var wait = freeAt + GAP - Date.now();
      if (wait > 0) { setTimeout(pump, wait + 20); return; }
      waiting.sort(function (a, b) { return (a.o - b.o) || (a.t - b.t); });
      var n = waiting.shift();
      active = n.name; up(true);
      n.go();
    }
    w.KT.promptTurn = function (name) {
      return new Promise(function (res) {
        waiting.push({ name: name, o: ORDER[name] || 9, t: Date.now(), go: res });
        setTimeout(pump, 0);
      });
    };
    w.KT.promptDone = function (name) {
      if (active !== name) return;
      active = null; freeAt = closedAt = Date.now(); closedName = name; up(false);
      setTimeout(pump, GAP + 20);
    };
  })(window);

  var URL = '/whats-new.json';
  var ADMIN = ['platform_admin', 'agency_admin', 'centre_director'];
  var DATA = null;

  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function isAdmin() { var r = myRoles(); return ADMIN.some(function (x) { return r.indexOf(x) !== -1; }); }

  function myRoles() {
    var u = user();
    var r = (u.roles && u.roles.length) ? u.roles.slice() : [];
    // role_key is what the portal actually gates on elsewhere; a kt_user rebuilt by
    // hand often has it and no roles array at all.
    if (u.role_key && r.indexOf(u.role_key) === -1) r.push(u.role_key);
    return r;
  }

  /**
   * The entries THIS person should be told about.
   *
   * An entry may name the roles it matters to (`roles: ["educator", ...]`). One that
   * does not is admin news, which is what every entry written before this was — so the
   * default keeps the existing 45 exactly where they were rather than suddenly showing
   * an educator three months of billing changes.
   *
   * This is why the panel is no longer admin-only: an educator now has features of
   * their own to hear about (Anthony, 2026-08-31: "add them to the new feature list for
   * all roles that are relevant to the new features"), and a release note nobody in that
   * role can open is a release note that was not written.
   */
  function forMe(entries) {
    var mine = myRoles();
    var admin = isAdmin();
    return (entries || []).filter(function (e) {
      if (! e.roles || ! e.roles.length) return admin;
      for (var i = 0; i < e.roles.length; i++) {
        if (mine.indexOf(e.roles[i]) !== -1) return true;
      }
      return false;
    });
  }
  /* 'new' AND 'improved' (2026-11-02). 28 entries were written as type "improved" and the panel (and the Monday
     email) only ever listed type "new", so every one of them was silently dropped: improvements shipped and the
     list never said so. Both are shown now; an improvement carries a small "Improved" tag. */
  function shown(e) { return !!e && (e.type === 'new' || e.type === 'improved'); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  /* Numeric parts, never `new Date('2026-08-31T00:00:00')` — kt-tz-global patches Date and
     a date-only string parses as UTC, which shifts the day for anyone west of Greenwich. */
  /* THE LAST 30 DAYS (2026-11-04, Anthony: "only show whats new in the last 30 days, archive the rest"; was 3 months).
     The list and the dot both use this, so the dot never lights for something only the Archive holds. */
  var RECENT_DAYS = 30;
  var FIRST = 15;   // entries drawn when the panel opens; the rest as the list is scrolled (ticket #222)
  function within3mo(d) {
    if (!d) return false;
    var p = String(d).split('-');
    if (p.length !== 3) return false;
    var t = new Date(+p[0], +p[1] - 1, +p[2]).getTime();
    if (isNaN(t)) return false;
    return (Date.now() - t) <= RECENT_DAYS * 86400000;
  }
  // Date-only: see KT.dayLabel.
  /* An entry may carry its own translation: title_fr / body_fr, _es, _hi (2026-10-22).
     Otherwise the English text is shown, and kt-i18n translates what it knows. */
  function wl(e, f) {
    var l = 'en';
    try { l = (window.KT && KT.i18n && KT.i18n.locale) ? KT.i18n.locale() : (localStorage.getItem('kt_locale') || 'en'); } catch (x) {}
    return (l !== 'en' && e[f + '_' + l]) || e[f];
  }
  function fmtDate(s) {
    // In the reader's language when it is not English (2026-10-22); KT.dayLabel is English.
    var l = 'en';
    try { l = (window.KT && KT.i18n && KT.i18n.locale) ? KT.i18n.locale() : 'en'; } catch (x) {}
    var tag = { fr: 'fr-CA', es: 'es-ES', hi: 'hi-IN' }[l];
    if (tag && /^\d{4}-\d{2}-\d{2}$/.test(String(s))) {
      try { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(tag, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (x) {}
    }
    return (window.KT && KT.dayLabel) ? KT.dayLabel(s) : s;
  }
  /* THE SAME ENTRIES THE PANEL WILL SHOW — this is what makes the dot honest.

     The dot was computed from every 'new' entry that had a date, while the panel renders
     only those `within3mo`. The moment the newest entry aged past 92 days the two
     disagreed: the badge stayed lit because a newest still existed, and opening it showed
     an empty list. "Showing a new notification when there is nothing new" is exactly that
     gap, and it is permanent once it starts — nothing new arrives to clear it.

     One filter, used by both, so the dot cannot claim something the panel will not show.
     (Anthony, 2026-09-09) */
  function newest(d) {
    var ns = forMe(d.entries)
      .filter(function (e) { return shown(e) && within3mo(e.date); })
      .map(function (e) { return e.date; })
      .sort();
    return ns.length ? ns[ns.length - 1] : '';
  }
  /* Account-wide — see kt-markers.js. Reading the release notes on one device should not
     leave the dot showing on another. */
  function seenDate() {
    if (window.KT && KT.markers) { return KT.markers.get('kt_whatsnew_seen'); }
    try { return localStorage.getItem('kt_whatsnew_seen') || ''; } catch (e) { return ''; }
  }
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  /* Mark it read — and never with an empty string.

     This used to store newest(d) directly, and newest() returns '' whenever
     forMe() matches nothing, which happens whenever myRoles() finds no kt_user in
     storage: during boot, across a role switch, or for a moment after a hard
     reload. Storing '' means every later tick compares a real date against '' and
     shows the dot again — the badge coming back after it had been read, however
     many times it was read.

     So: fall back to today, and never move the marker backwards. */
  function markSeen(d) {
    try {
      var n = d ? newest(d) : '';
      var cur = seenDate();
      var v = n || cur || todayISO();
      if (cur && v < cur) { v = cur; }
      if (window.KT && KT.markers) { KT.markers.set('kt_whatsnew_seen', v); return; }
      localStorage.setItem('kt_whatsnew_seen', v);
    } catch (e) { /* private mode — the dot is not worth an exception */ }
  }

  function load(cb) {
    if (DATA) { cb(DATA); return; }
    fetch(URL + '?t=' + Date.now()).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { DATA = d; cb(d); }).catch(function () { cb(null); });
  }
  function badge(show) { var b = document.getElementById('kt-tb-wn-badge'); if (b) b.hidden = !show; }

  function openWhatsNew(onClose) {
    /* Cleared the moment it is opened. It used to clear only after the JSON came
       back, so a failed or slow fetch left the dot sitting there on a panel the
       user had just read. */
    badge(false);
    load(function (d) {
      if (!d) { markSeen(null); if (typeof onClose === 'function') onClose(); return; }
      /* MARKED READ HERE, the moment the data arrives — not after the overlay is built.

         markSeen(d) used to run at the very bottom of this callback, after ~40 lines of
         DOM building. Anything that threw in between (a malformed entry, a missing field)
         left the panel open and the marker unwritten, so the dot came back on the next
         sweep and kept coming back. Opening it IS the read; the rendering is just how it
         is shown. (Anthony, 2026-09-09) */
      markSeen(d);
      var news = forMe(d.entries).filter(function (e) { return shown(e) && within3mo(e.date); }).sort(function (a, b) { return a.date < b.date ? 1 : (a.date > b.date ? -1 : 0); });   // same day: keep the order written (headline first)
      var up = forMe(d.entries).filter(function (e) { return e.type === 'upcoming'; });
      // Older than 30 days: kept, folded away under Archive at the bottom.
      var old = forMe(d.entries).filter(function (e) { return shown(e) && e.date && !within3mo(e.date); }).sort(function (a, b) { return a.date < b.date ? 1 : (a.date > b.date ? -1 : 0); });
      var entry = function (e) {
        return '<div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #F1F5F9;">' +
          '<span style="font-size:22px;flex-shrink:0;line-height:1.2;">' + (e.icon || '✨') + '</span>' +
          '<div style="min-width:0;"><div style="font-weight:700;font-size:14px;color:#0D1B2A;">' + esc(wl(e, 'title')) +
          (e.type === 'improved' ? '<span style="font-weight:700;font-size:10px;letter-spacing:.3px;text-transform:uppercase;color:#0F766E;background:#E6F7F5;border-radius:6px;padding:1px 6px;margin-left:8px;vertical-align:1px;">Improved</span>' : '') +
          (e.date ? '<span style="font-weight:600;font-size:11px;color:#64748B;margin-left:8px;">' + fmtDate(e.date) + '</span>' : '') + '</div>' +
          '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(wl(e, 'body') || '') + '</div></div></div>';
      };
      /* THE HOUSE POPUP SHAPE (2026-11-02, Anthony: "the whats new popup look at the scroll bar and top header").
         The card itself used to be the scroll box: the browser's grey scrollbar ran from the top corner, beside an old
         teal-to-blue header, down into the bottom corner, and the backdrop could scroll as well. Now: the backdrop never
         scrolls, the card is a column that clips to its rounded corners, the header is the house navy-to-teal band with
         the teal line and never moves, and only the list below it scrolls (slim thumb from kt-modal-guard's .kt-dlg-scroll). */
      var overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:12001;display:flex;align-items:center;justify-content:center;padding:24px;overflow:hidden;';
      var modal = document.createElement('div');
      modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', 'What’s new');
      modal.style.cssText = 'background:#fff;border-radius:16px;max-width:540px;width:100%;max-height:min(calc(100vh - 48px), 100%);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 16px 40px rgba(0,0,0,.28);';
      modal.innerHTML =
        '<div class="kt-dlg-head" style="flex:0 0 auto;padding:15px 56px 15px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;border-radius:16px 16px 0 0;">' +
          '<h2 style="margin:0;font-size:18px;color:#fff;">🎁 What’s new</h2>' +
          '<button id="kt-wn-x" aria-label="Close" style="background:transparent;border:none;font-size:22px;color:rgba(255,255,255,.92);cursor:pointer;line-height:1;">×</button></div>' +
        '<div class="kt-dlg-scroll" style="flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:14px 22px 20px;">' +
          (news.length
            ? '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#0E7C90;margin:2px 0 2px;">✨ Recently added</div>' + news.slice(0, FIRST).map(entry).join('')
              + (news.length > FIRST ? '<div class="kt-wn-more" style="height:1px;"></div>' : '')
            : '<div style="color:#64748B;font-size:13px;padding:10px 0;">No new features in the last 30 days.</div>') +
          (up.length ? '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#B45309;margin:20px 0 2px;">🔜 Coming soon</div>' + up.map(entry).join('') : '') +
          (old.length ? '<details class="kt-wn-archive" style="margin-top:18px;border-top:1px solid #E2E8F0;padding-top:10px;">'
            + '<summary style="cursor:pointer;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;padding:4px 0;">🗄️ Archive · ' + old.length + ' older</summary>'
            + '</details>' : '') +
          '<div style="font-size:11px;color:#B6C2CE;margin-top:16px;text-align:center;">New features show here for 30 days, then move to the Archive.</div>' +
        '</div>';
      overlay.appendChild(modal); document.body.appendChild(overlay);
      /* DRAWN AS IT IS READ (2026-11-05, ticket #222: a 20.7s freeze on an educator's PC, starting the moment this
         panel opened at sign-in with 122 entries). The newest FIRST entries are drawn at once; the next ones only when
         the list is scrolled near its end, and the Archive only when it is opened - so a slow PC or a browser add-on
         that works on every new element has a dozen entries to handle at sign-in, not the whole history. */
      var more = modal.querySelector('.kt-wn-more'), drawn = FIRST;
      var drawMore = function () {
        if (!more || !more.isConnected) return;
        more.insertAdjacentHTML('beforebegin', news.slice(drawn, drawn + FIRST).map(entry).join(''));
        drawn += FIRST;
        if (drawn >= news.length) { more.remove(); more = null; if (io) io.disconnect(); }
      };
      var io = null;
      if (more) {
        if (window.IntersectionObserver) {
          io = new IntersectionObserver(function (es) { if (es.some(function (x) { return x.isIntersecting; })) drawMore(); },
            { root: modal.querySelector('.kt-dlg-scroll'), rootMargin: '0px 0px 300px 0px' });
          io.observe(more);
        } else { while (more) drawMore(); }
      }
      var arch = modal.querySelector('.kt-wn-archive');
      if (arch) arch.addEventListener('toggle', function () {
        if (!arch.open || arch.getAttribute('data-drawn')) return;
        arch.setAttribute('data-drawn', '1');
        arch.insertAdjacentHTML('beforeend', old.map(entry).join(''));
      });
      var close = function () { overlay.remove(); if (typeof onClose === 'function') { var f = onClose; onClose = null; f(); } };
      modal.querySelector('#kt-wn-x').onclick = close;
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
      badge(false);   // markSeen already ran, before any of this was built
    });
  }

  window.KT = window.KT || {};
  window.KT.openWhatsNew = openWhatsNew;
  window.KT.whatsNewIsAdmin = isAdmin;

  /* OPENS BY ITSELF AFTER SIGN-IN WHEN THERE IS SOMETHING NEW (2026-10-20).
     Anthony: "when logging into kiddietrac admins/directors etc the whats new should pop
     up automatically". Staff only (admins, directors, educators, home visitors); a parent
     keeps the 🎁 dot.

     Only when something new has been added since they last read it — opening it marks it
     read (account-wide, kt-markers), so it shows once per batch of news, not at every
     sign-in. The decision waits for the markers to sync: deciding from this browser's
     cache would re-open it for someone who already read it on their phone.

     It never lands on top of something else: the sign-in splash, the PIN / biometric lock,
     the clock-in gate, onboarding, the update notice, or any open dialog. If one is up it
     tries again on the next sweep until the way is clear. */
  var AUTO_ROLES = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor'];
  var autoDone = false, autoArmed = false, autoTries = 0, autoSynced = false;
  function somethingElseOpen() {
    var open = document.querySelectorAll('#kt-pin-lock, #kt-bio-lock, #kt-clockgate, #kt-onboarding-overlay, #kt-onboarding-catchup, '
      + '#kt-onboard-overlay, #kt-appupdate, #kt-ff, .kt-face-offer, .kt-face-editor, .kt-lightbox, .kt-scrim, '
      + '[role="dialog"][aria-modal="true"], #modalRoot > *');
    for (var i = 0; i < open.length; i++) {
      // Present AND showing: a container kept in the page while hidden is not in the way.
      if (open[i].offsetWidth > 0 || open[i].offsetHeight > 0) { return true; }
    }
    /* The boot cover and the launch fade (2026-10-30, Anthony's iPhone recording: What's new opened over the
       "Signing you in…" splash while the home screen was still loading). They let taps through while they fade,
       so the elementFromPoint test below never saw them; ask for them by name, and wait for the home screen to
       have content. */
    var boot = document.querySelectorAll('#kt-splash:not(.kt-hide), #kt-launch, #kt-portal-fade');
    for (var j = 0; j < boot.length; j++) {
      if ((boot[j].offsetWidth > 0 || boot[j].offsetHeight > 0) && getComputedStyle(boot[j]).opacity !== '0') { return true; }
    }
    var mainEl = document.getElementById('appMain');
    if (mainEl && (mainEl.textContent || '').replace(/\s+/g, ' ').trim().length < 60) { return true; }
    // The "Signing in…" splash and anything else full-screen sitting over the page.
    var el = document.elementFromPoint(Math.round(window.innerWidth / 2), Math.round(window.innerHeight / 2));
    var main = document.getElementById('appMain');
    if (!el || !main) { return true; }
    var cs = el.closest && el.closest('[style*="position:fixed"],[style*="position: fixed"]');
    return !!(cs && !main.contains(cs) && cs.offsetWidth >= window.innerWidth * 0.9 && cs.offsetHeight >= window.innerHeight * 0.9);
  }
  function autoOpen() {
    if (autoDone || autoArmed) { return; }
    if (window.KT_PUBLIC_DEMO) return;   // website demo person: no set-up nudges (2026-10-31)
    var mine = myRoles();
    if (!AUTO_ROLES.some(function (r) { return mine.indexOf(r) !== -1; })) { return; }   // not signed in yet, or a parent
    if (!document.getElementById('appMain')) { return; }
    autoArmed = true;
    var decide = function () {
      autoArmed = false;
      load(function (d) {
        if (!d || autoDone) { return; }
        var n = newest(d);
        if (!n || n <= seenDate()) { autoDone = true; return; }     // nothing they haven't seen
        // Wait it out, however long: the terms, the two-step sign-in or a lock screen can
        // take minutes, and giving up would skip the news for this whole visit.
        if (somethingElseOpen()) { autoTries++; return; }
        autoDone = true;
        // A beat after the screen settles, and only if nothing has opened meanwhile - then its turn among the
        // first-run questions (KT.promptTurn: after the biometric offer and the notifications alert, never with them).
        setTimeout(function () {
          window.KT.promptTurn('whatsnew').then(function () {
            var done = function () { window.KT.promptDone('whatsnew'); };
            if (somethingElseOpen() || !(newest(d) > seenDate())) { done(); autoDone = false; return; }
            openWhatsNew(done);
          });
        }, 2500);
      });
    };
    if (window.KT && KT.markers && KT.markers.onSync) {
      if (!autoSynced) { autoSynced = true; try { KT.markers.sync(); } catch (e) {} }
      KT.markers.onSync(decide);
    } else { decide(); }
  }

  /* Anyone with something addressed to them, not just admins — and the badge clears
     itself when there is nothing left to say, which the old one never did. */
  function tick() {
    load(function (d) {
      if (! d) return;
      var n = newest(d);
      badge(!!n && n > seenDate());
    });
    autoOpen();
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(tick) : setInterval(tick, 2500);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
})();
