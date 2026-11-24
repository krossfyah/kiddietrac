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
    pages:    { icon: '🧭', label: 'Go to' },
    centres:  { icon: '🏢', label: 'Centres' },
    families: { icon: '👨‍👩‍👧', label: 'Families' },
    children: { icon: '👶', label: 'Children' },
    staff:    { icon: '👤', label: 'Staff' },
    parents:  { icon: '👨‍👩‍👧', label: 'Parents' },
    rooms:    { icon: '🚪', label: 'Rooms' },
    // Labels and icons now come from the server (App\Support\SearchRegistry); these are the fallback.
    invoices: { icon: '🧾', label: 'Invoices' },
    provider_invoices: { icon: '📄', label: 'Synced invoices' },
    payee_invoices: { icon: '📑', label: 'Payee invoices' },
    payments: { icon: '💵', label: 'Payments & receipts' },
    expense_bills: { icon: '🧮', label: 'Supplier bills' },
  };

  /* RECORD GROUPS, BEST MATCH FIRST (2026-11-02). Anthony: "make the search on the portal pull
     up invoices, receipts (global search) - keep this current with any new changes as well".

     /admin/search returns `groups` in rank order (an exact invoice number puts Invoices on
     top, not wherever a fixed list put them), each with its label and icon from
     App\Support\SearchRegistry. Drawing from that, rather than from GROUP_META's own key
     list, is what keeps this box current: a new searchable type appears here the day its
     registry entry ships, with nothing to add on this side. Shared by the three boxes
     (this widget, the ⌘K palette, the phone launcher) so they cannot drift. */
  function searchGroups(data) {
    var r = (data && data.results) || {};
    var gs = (data && data.groups) || null;
    if (!gs) {
      gs = Object.keys(r).map(function (k) { return { key: k }; });
    }
    return gs.map(function (g) {
      var m = GROUP_META[g.key] || {};
      return {
        key: g.key, label: g.label || m.label || g.key, icon: g.icon || m.icon || '•',
        best: (typeof g.best === 'number') ? g.best : 3, items: r[g.key] || [],
      };
    }).filter(function (g) { return g.items.length > 0; });
  }
  try { (window.KT = window.KT || {}).searchGroups = searchGroups; } catch (e) {}

  /* Who may ask /admin/search at all. Anyone else got a 403 for every keystroke, which is
     noise in the audit log and a request that can only fail. */
  function canSearchRecords() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return Array.isArray(u.roles) && u.roles.some(function (x) {
        return x === 'agency_admin' || x === 'centre_director' || x === 'platform_admin';
      });
    } catch (e) { return false; }
  }
  try { window.KT.canSearchRecords = canSearchRecords; } catch (e) {}

  /* ── FINDING A SCREEN BY NAME ────────────────────────────────────────────────

     This used to be `label.toLowerCase().indexOf(q) !== -1`, taking the first eight
     hits IN SIDEBAR ORDER. Measured against the live admin nav:

       "audit"      -> Audit log, first        ok
       "audit log"  -> Audit log, first        ok
       "audit logs" -> NOTHING                 the plural alone killed it
       "logs"       -> NOTHING
       "auditlog"   -> NOTHING
       "log"        -> Daily log, THEN Audit log   sidebar order, not relevance

     Anthony, 2026-09-15: "it does not bring that up as the first item and doesnt
     match the words". Three separate faults behind that:

       1. ONE CONTIGUOUS SUBSTRING. "audit logs" is not a substring of "Audit log", so
          a plural, an extra word, or a different word order finds nothing at all.
       2. THE HASH WAS NEVER SEARCHED. The screen's own address is `audit-logs` — the
          very text that would have matched "logs" and "auditlog".
       3. NO RANKING, AND THE CAP APPLIED BEFORE IT. Eight results were taken in nav
          order and never sorted, so a broad query could cut the exact match entirely.

     So: match WORD BY WORD (every word must appear somewhere), search the hash as well
     as the label, tolerate a trailing plural, and SORT by how well the whole thing
     matches before taking the top N. Exact name beats prefix beats word-prefix beats
     substring, and a shorter label wins a tie because it is the more specific one —
     "Daily log" and "Audit log" both contain "log", but "Audit log" is what you typed
     when you typed "audit".

     The nav comes from Shell.navItemsForRole — the role's REAL nav, the same source the
     admin launcher builds from — rather than by scraping the sidebar out of the DOM. On
     a phone the sidebar may not be rendered at all, and a search that silently depends
     on furniture being on screen is a search that works on desktop and not in the app.
     DOM scraping stays as the fallback for any screen that has no Shell.

     Shared on KT so all three search boxes agree: this widget, the ⌘K palette, and the
     admin launcher's own box. They disagreed before, which is how one of them could be
     fixed and the others quietly stay broken. */

  function normWords(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }
  function squash(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
  }
  // "logs" and "log" are the same word to a person looking for a screen.
  function stem(w) {
    return (w.length > 3 && w.charAt(w.length - 1) === 's') ? w.slice(0, -1) : w;
  }

  /* THE WORDS PEOPLE ACTUALLY TYPE.

     This is a Canadian product with British-spelling users, and half the portal's own
     features have a name and an acronym. "immunisations" found nothing while
     "Immunizations" sat right there; "mfa" found nothing because the screen is called
     "My profile & security". Rewriting the QUERY (never the labels) keeps one source of
     truth for what a screen is called and still lets people ask for it their way.

     Deliberately short. A synonym list is a maintenance burden that grows teeth, so it
     covers spelling splits and the acronyms this product genuinely uses, and nothing
     else. */
  var ALIASES = {
    immunisation: 'immunization', immunisations: 'immunization',
    immunization: 'immunization', immunizations: 'immunization',
    vaccination: 'immunization', vaccinations: 'immunization', vaccine: 'immunization',
    enrolment: 'enrollment', enrolments: 'enrollment',
    organisation: 'organization', organisations: 'organization',
    programme: 'program', programmes: 'program',
    licence: 'license',
    mfa: 'security', '2fa': 'security', twofactor: 'security',
    otp: 'security', authenticator: 'security',
    invoice: 'billing', invoices: 'billing', invoicing: 'billing',
    holiday: 'calendar', holidays: 'calendar',
    parent: 'families', parents: 'families', guardian: 'families', guardians: 'families',
    educator: 'staff', educators: 'staff', teacher: 'staff', teachers: 'staff',
    provider: 'centres', providers: 'centres',
  };

  function applyAliases(q) {
    return String(q || '').split(/\s+/).map(function (w) {
      var k = w.toLowerCase();
      return Object.prototype.hasOwnProperty.call(ALIASES, k) ? ALIASES[k] : w;
    }).join(' ');
  }

  /* THE SECTION IS NOT SEARCHABLE TEXT.

     It was, and it made whole sections match a single word: "educators" (aliased to
     staff) returned Tasks, Calendar, Substitutes and Background checks — everything
     filed under Staff — because the section name matched and the label never had to.
     A section is where a screen lives, not what it is called. Name and address only. */
  function navHaystack(item) {
    var label = normWords(item.label);
    var hash = normWords(item.hash);
    return {
      label: label,
      labelSquash: squash(item.label),
      all: (label + ' ' + hash).trim(),
      allSquash: squash(item.label) + ' ' + squash(item.hash),
      words: (label + ' ' + hash).split(/\s+/).filter(Boolean),
    };
  }

  /* AN ALIAS IS A GUESS ABOUT WHAT SOMEBODY MEANT; THE WORD THEY TYPED IS NOT.

     Scored without a penalty, "invoices" put Billing above Invoices — the alias hit
     "Billing" exactly, the literal hit "Invoices" exactly, and a one-character length
     tiebreak decided it. Whatever the reader actually typed has to win: the alias only
     exists to find something where the literal reading finds nothing. */
  var ALIAS_PENALTY = 250;

  /* WHAT A SCREEN IS FOR (2026-10-30): a match only in its description ("backups", "certificates" ->
     Server health) counts, but below any match in its name or hash. */
  function descScore(item, qRaw) {
    if (!item.desc) { return 0; }
    var q = normWords(qRaw); if (!q) { return 0; }
    var words = normWords(item.desc).split(/\s+/).filter(Boolean);
    var qWords = q.split(/\s+/).filter(Boolean);
    for (var i = 0; i < qWords.length; i++) {
      var qw = stem(qWords[i]), ok = false;
      for (var j = 0; j < words.length; j++) { if (stem(words[j]).indexOf(qw) === 0) { ok = true; break; } }
      if (!ok) { return 0; }
    }
    return 150;
  }

  function scoreNav(item, qRaw) {
    var literal = scoreOne(item, qRaw) || descScore(item, qRaw);
    var aliased = applyAliases(qRaw);
    if (normWords(aliased) === normWords(qRaw)) { return literal; }
    var viaAlias = scoreOne(item, aliased) || descScore(item, aliased);
    if (viaAlias > 0) { viaAlias = Math.max(1, viaAlias - ALIAS_PENALTY); }
    return Math.max(literal, viaAlias);
  }

  function scoreOne(item, qRaw) {
    var q = normWords(qRaw);
    if (!q) { return 0; }
    var h = navHaystack(item);
    var qWords = q.split(/\s+/).filter(Boolean);

    // Every word has to land somewhere, or it is not a match at all.
    for (var i = 0; i < qWords.length; i++) {
      var qw = stem(qWords[i]);
      var found = false;
      for (var j = 0; j < h.words.length; j++) {
        if (stem(h.words[j]).indexOf(qw) === 0) { found = true; break; }
      }
      if (!found && h.allSquash.indexOf(squash(qWords[i])) !== -1) { found = true; }
      if (!found) { return 0; }
    }

    var score = 200;                                        // all words matched somewhere
    if (h.all.indexOf(q) !== -1) { score = 400; }           // contiguous, anywhere
    if (h.label.indexOf(q) !== -1) { score = 500; }         // contiguous in the NAME
    if (h.label.indexOf(q) === 0) { score = 800; }          // the name starts with it
    if (h.label === q) { score = 1000; }                    // it IS the name
    // Stemmed equality: "audit logs" should be as good as "audit log".
    if (h.label.split(/\s+/).map(stem).join(' ') === qWords.map(stem).join(' ')) { score = 1000; }
    // Shorter name wins a tie — it is the more specific one.
    return score - Math.min(60, h.label.length);
  }

  /* WHEN NOTHING MATCHES EVERYTHING, MATCH WHAT YOU CAN.

     Requiring every word to land on the SAME screen is right when such a screen exists
     and unhelpful when it does not: "time clock" found nothing at all, because this nav
     calls them "Timesheets" and "Clock settings" and neither contains both words. An
     empty result reads as "this portal has no such thing", which is a worse answer than
     the two screens the person was almost certainly after.

     So a second pass, used ONLY when the strict pass came back empty, scores by how much
     of the query each screen accounts for. Scores stay below the strict band so this can
     never outrank a real match — it is what you get instead of nothing, not as well. */
  function partialScore(item, qRaw) {
    var q = normWords(applyAliases(qRaw));
    var qWords = q.split(/\s+/).filter(Boolean);
    if (qWords.length < 2) { return 0; }
    var h = navHaystack(item);
    var hit = 0;
    qWords.forEach(function (raw) {
      var qw = stem(raw);
      for (var j = 0; j < h.words.length; j++) {
        if (stem(h.words[j]).indexOf(qw) === 0) { hit++; return; }
      }
      if (h.allSquash.indexOf(squash(raw)) !== -1) { hit++; }
    });
    if (!hit) { return 0; }
    return Math.round((hit / qWords.length) * 120) - Math.min(40, h.label.length);
  }

  /* KEYWORDS ON EVERY SCREEN (2026-11-09, Anthony: "when you do a global search can it pick up key words throughout
     the portal as well - full sweep"). kt-search-keys.js holds, per role, the words drawn on each screen and subtab
     (headings, field labels, column headers, buttons; no record text). A screen the person can open whose words match
     is found too, scored BELOW any match on a screen's name (120-140, under the descriptions' 150), with what matched
     as the line under it ("In Settings: Fire drill"). Opening it opens that subtab and highlights the words
     (kwJump). The file is lazy: asked for on the first search or the first focus on a search box, then the query is
     run again. Roles without their own list read the agency admin's (their menu still decides what can match). */
  var KW_ROLE = { platform_admin: 'agency_admin', reseller_admin: 'agency_admin', auditor: 'agency_admin' };
  var kwAsked = false;
  var KW_PENDING = {};   // hash -> {tab, phrase, at}: the keyword hits of the latest search, for kwJump
  /* LEARNED FROM USE (2026-11-09, Anthony: "wire up a middleware to add new words for the global search as they
     become available and to monitor"). The words now come from GET /search-keys (App\Support\SearchKeywords: the
     crawl, plus every word a screen has shown since), kept for the session in KW_LIVE. kt-search-keys.js is only the
     fallback when that call fails. kwHarvest below reports what each screen shows, once a day. */
  var KW_LIVE = {};      // role -> {hash: [[phrase, subtab?]]}
  var kwLiveAsked = {};
  function kwApi(path, opts) {
    var token = ''; try { token = sessionStorage.getItem('kt_token') || ''; } catch (e) {}
    if (!token) { return Promise.reject(new Error('no token')); }
    var h = { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' };
    var ag = ''; try { ag = sessionStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    if (ag) { h['X-Active-Agency-Id'] = ag; }
    if (opts && opts.body) { h['Content-Type'] = 'application/json'; }
    var base = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return fetch(base + path, { method: (opts && opts.method) || 'GET', headers: h, body: opts && opts.body })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }
  function kwRerun() {
    // Run whatever is typed again, now with the keywords.
    var a = document.activeElement;
    if (a && a.tagName === 'INPUT' && a.value) { a.dispatchEvent(new Event('input', { bubbles: true })); }
  }
  function kwStatic() {
    if (!kwAsked && window.KT && KT.screenLoader && KT.screenLoader.load) {
      kwAsked = true;
      KT.screenLoader.load('kt-search-keys.js').then(function () { if (window.__ktScreenKeys) { kwRerun(); } }, function () { kwAsked = false; });
    }
  }
  function screenKeys(role) {
    if (KW_LIVE[role]) { return KW_LIVE[role]; }
    if (!kwLiveAsked[role]) {
      kwLiveAsked[role] = true;
      var cached = null; try { cached = JSON.parse(sessionStorage.getItem('kt_sk_' + role) || 'null'); } catch (e) {}
      if (cached && cached.keys) { KW_LIVE[role] = cached.keys; return cached.keys; }
      kwApi('/search-keys?role=' + encodeURIComponent(role)).then(function (d) {
        if (!d || !d.keys) { throw new Error('empty'); }
        KW_LIVE[role] = d.keys;
        try { sessionStorage.setItem('kt_sk_' + role, JSON.stringify({ version: d.version, keys: d.keys })); } catch (e) {}
        kwRerun();
      }).catch(function () { kwStatic(); });
    }
    var all = window.__ktScreenKeys;
    return all ? (all[role] || all[KW_ROLE[role]] || null) : null;
  }
  function kwScore(rows, qRaw) {
    var q = normWords(qRaw);
    if (q.length < 3) { return null; }
    var qWords = q.split(/\s+/).filter(Boolean), best = null;
    rows.forEach(function (row) {
      var p = normWords(row[0]), sc = 0;
      if (p === q) { sc = 140; }
      else if (p.indexOf(q) === 0) { sc = 136; }
      else if ((' ' + p).indexOf(' ' + q) !== -1) { sc = 130; }
      else {
        var words = p.split(/\s+/);
        var every = qWords.every(function (qw) { qw = stem(qw); return words.some(function (w) { return stem(w).indexOf(qw) === 0; }); });
        if (every) { sc = 120; }
      }
      if (!sc) { return; }
      sc -= Math.min(10, Math.floor(p.length / 6));   // the shorter phrase is the closer one
      if (!best || sc > best.score) { best = { score: sc, phrase: row[0], tab: row[1] || '' }; }
    });
    return best;
  }
  // Ask for the keywords as soon as somebody starts to search, so they are usually here by the first letter.
  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t && t.tagName === 'INPUT' && /search|find|jump/i.test((t.getAttribute('placeholder') || '') + ' ' + (t.getAttribute('aria-label') || '') + ' ' + (t.type || ''))) {
      screenKeys(getRole() || 'agency_admin');
    }
  });

  /* Opening a keyword hit: click its subtab (when it has one), then scroll to the words and highlight them. Only for
     the screen just found by a search; any other page change forgets it. */
  window.addEventListener('hashchange', function () {
    var h = (location.hash || '').replace(/^#/, '').split('?')[0], p = KW_PENDING[h];
    KW_PENDING = {};
    if (p && Date.now() - p.at < 120000) { kwJump(p); }
  });
  function kwText(s) { return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase().replace(/^[^a-z0-9]+/, '').replace(/[\s:*·•›»…]+$/, ''); }
  function kwJump(p) {
    var tries = 0, tabDone = !p.tab;
    var TABS = '[role="tab"], .kt-tabbtn, .kt-tabband button, .kt-tab-band button, .kt-subtabs button, .kt-tabbar button';
    (function step() {
      var main = document.getElementById('appMain');
      if (main && !tabDone) {
        var want = kwText(p.tab);
        var tab = [].slice.call(main.querySelectorAll(TABS)).filter(function (e) {
          return e.getClientRects().length && kwText((e.innerText || '').split('\n')[0]).replace(/\s*\(?\d+\)?$/, '') === want;
        })[0];
        if (tab) {
          tabDone = true; tries = 0;
          if (tab.getAttribute('aria-selected') !== 'true' && !tab.classList.contains('active')) { tab.click(); }
          setTimeout(step, 450);
          return;
        }
      } else if (main) {
        var hit = kwFind(main, p.phrase);
        if (hit) { kwFlash(hit); return; }
      }
      if (++tries < 16) { setTimeout(step, 250); return; }
      if (!tabDone) { tabDone = true; tries = 0; setTimeout(step, 0); }   // no such subtab here: still look for the words
    })();
  }
  function kwFind(main, phrase) {
    var want = kwText(phrase), exact = null, part = null;
    var w = document.createTreeWalker(main, NodeFilter.SHOW_TEXT, null);
    for (var n = w.nextNode(); n && !exact; n = w.nextNode()) {
      var el = n.parentElement;
      if (!el || !el.getClientRects().length || el.closest('[role="dialog"], .kt-modal')) { continue; }
      var t = kwText(el.textContent);
      if (t === want) { exact = el; } else if (!part && t.length < want.length + 40 && t.indexOf(want) !== -1) { part = el; }
    }
    return exact || part;
  }
  function kwFlash(el) {
    if (!document.getElementById('kt-kw-flash-css')) {
      var st = document.createElement('style'); st.id = 'kt-kw-flash-css';
      st.textContent = '.kt-kw-flash{outline:2px solid #F59E0B !important;outline-offset:3px;border-radius:4px;animation:ktKwFlash 2.6s ease-out}'
        + '@keyframes ktKwFlash{0%,35%{background-color:rgba(245,158,11,.22)}100%{background-color:transparent}}'
        + '@media (prefers-reduced-motion:reduce){.kt-kw-flash{animation:none}}';
      document.head.appendChild(st);
    }
    try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { el.scrollIntoView(); }
    el.classList.add('kt-kw-flash');
    setTimeout(function () { el.classList.remove('kt-kw-flash'); }, 2800);
  }

  /* Public: KT.navSearch('audit logs') -> [{label, hash, section, icon, score}, …] */
  function navSearch(q, opts) {
    opts = opts || {};
    var limit = opts.limit || 8;
    if (!normWords(q)) { return []; }

    var items = [], seen = {};
    var role = opts.role || getRole() || 'agency_admin';
    try {
      var S = window.KT && window.KT.Shell;
      var secs = (S && S.navItemsForRole) ? S.navItemsForRole(role) : null;
      if (secs) {
        secs.forEach(function (sec) {
          (sec.items || []).forEach(function (it) {
            var hash = String(it.hash || '').replace(/^#/, '');
            if (!hash || seen[hash]) { return; }
            seen[hash] = 1;
            items.push({ label: it.label || hash, hash: '#' + hash, icon: it.icon, section: sec.label });
          });
        });
      }
    } catch (e) {}

    /* Anything the caller knows about that the nav does not carry. The ⌘K palette has
       a short hand-written list of jump targets that predate the nav; without this, moving
       it onto the real nav would LOSE those screens rather than add the other eighty. */
    (opts.extra || []).forEach(function (it) {
      var eh = String(it.hash || '').replace(/^#/, '');
      if (!eh || seen[eh]) { return; }
      seen[eh] = 1;
      items.push({ label: it.label || eh, hash: '#' + eh, icon: it.icon, section: it.section || '' });
    });

    /* EVERYTHING IN THE MENU IS FINDABLE (2026-10-30, Anthony: "the search under the mobile app not
       finding some sections - wire this up where it finds anything net new added"). The role's nav above
       is not the whole menu: the Platform and Sales blocks (Server health, Licences, Careers...) and the
       Phone entry are added to the sidebar by other scripts, and were only read when the nav was empty -
       so on the phone they could not be found. Every link actually in the menu is now merged in, so
       anything added there, by any script, is searchable the moment it exists. */
    {
      var links = document.querySelectorAll('#appSidebar a.nav-link[data-hash], #navLinks a[href^="#"], #kt-mobilenav-drawer a.nav-link[data-hash]');
      for (var i = 0; i < links.length; i++) {
        var a = links[i];
        var hh = a.getAttribute('href');
        if (!hh || hh.charAt(0) !== '#') { hh = '#' + (a.getAttribute('data-hash') || ''); }
        var hk = hh.replace(/^#/, '').split('?')[0];
        if (hh === '#' || hh.length < 2 || seen[hk]) { continue; }
        seen[hk] = 1;
        var le = a.querySelector('.nav-label');
        var lb = (le ? le.textContent : (a.textContent || '')).replace(/^[^A-Za-z0-9]+/, '').trim();
        if (lb && a.offsetParent !== null || (lb && getComputedStyle(a).display !== 'none')) {
          var sl = a.closest('.nav-section, .nav-group, [data-section]');
          var st = sl && sl.querySelector('.nav-section-label, .nav-group-label, .nav-section-title');
          items.push({ label: lb, hash: hh, section: st ? st.textContent.replace(/^[^A-Za-z]+/, '').trim() : '' });
        }
      }
    }
    var DESC = window.__ktScreenDesc || {};
    items.forEach(function (it) { var k = String(it.hash || '').replace(/^#/, '').split('?')[0]; if (DESC[k]) { it.desc = DESC[k]; } });

    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var KEYS = screenKeys(va || role);   // previewing a role: that role's words
    var scored = [];
    items.forEach(function (it) {
      var sc = scoreNav(it, q);
      var rows = KEYS && KEYS[String(it.hash || '').replace(/^#/, '').split('?')[0]];
      var kw = rows ? kwScore(rows, q) : null;
      if (kw && kw.score > sc) { scored.push({ item: it, score: kw.score, kw: kw }); }
      else if (sc > 0) { scored.push({ item: it, score: sc }); }
    });
    if (!scored.length) {
      items.forEach(function (it) {
        var sc = partialScore(it, q);
        if (sc > 0) { scored.push({ item: it, score: sc }); }
      });
    }
    // SORT, THEN CAP. The other way round is how the exact match got cut.
    scored.sort(function (x, y) { return y.score - x.score || x.item.label.localeCompare(y.item.label); });
    KW_PENDING = {};
    return scored.slice(0, limit).map(function (r) {
      var out = {
        label: r.item.label, hash: r.item.hash, icon: r.item.icon,
        section: r.item.section, score: r.score, sublabel: 'Open section',
      };
      if (r.kw) {
        out.kwPhrase = r.kw.phrase; out.kwTab = r.kw.tab;
        out.sublabel = (r.kw.tab ? 'In ' + r.kw.tab + ': ' : 'On this screen: ') + r.kw.phrase;
        KW_PENDING[String(r.item.hash || '').replace(/^#/, '').split('?')[0]] = { tab: r.kw.tab, phrase: r.kw.phrase, at: Date.now() };
      }
      return out;
    });
  }

  try { (window.KT = window.KT || {}).navSearch = navSearch; } catch (e) {}

  function searchNav(q) { return navSearch(q, { limit: 8 }); }

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
    // v22p25: mounted OUTSIDE #navLinks now — fixed slot between the brand
    // and the nav list, so position:relative is enough. flex:0 0 auto means
    // the search row never participates in the nav-list scroll.
    wrap.setAttribute('style', [
      'position:relative',
      'flex:0 0 auto',
      'background:white',
      'padding:8px 12px 10px',
      'border-bottom:1px solid rgba(0,0,0,.08)',
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

  function renderResults(dropdown, data, q, pages) {
    dropdown.innerHTML = '';
    /* Records in the server's rank order; the screens ("Go to") first unless a record IS
       what was typed (rank 0/1), in which case that record leads. */
    var recs = searchGroups(data);
    var pageGroup = (pages && pages.length) ? [{ key: 'pages', label: GROUP_META.pages.label, icon: GROUP_META.pages.icon, items: pages }] : [];
    var ordered = (recs.length && recs[0].best <= 1) ? recs.concat(pageGroup) : pageGroup.concat(recs);
    var totalHits = 0;
    ordered.forEach(function (g) { totalHits += g.items.length; });

    if (totalHits === 0) {
      dropdown.innerHTML = '<div style="padding:14px;color:#6B7280;font-size:13px;text-align:center;">No results for "' + esc(q) + '"</div>';
      dropdown.style.display = 'block';
      return;
    }

    ordered.forEach(function (g) {
      var rs = g.items;
      if (!rs.length) return;
      var meta = g;
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
      // v22p43: was a relative '/api/v1/...' which resolves against the
      // PORTAL host (app.kiddietrac.com) and 404s because the SPA only
      // serves static assets. Use the absolute API host so the request
      // reaches Laravel. Also forward the X-Active-Agency-Id header so
      // multi-agency platform admins get results from their current tenant.
      var apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
      var activeAgencyId = sessionStorage.getItem('kt_active_agency_id') || '';
      var headers = {
        'Authorization': 'Bearer ' + token,
        'Accept': 'application/json',
      };
      if (activeAgencyId) headers['X-Active-Agency-Id'] = activeAgencyId;
      // Nav/section matches are instant + client-side; merge them with the
      // backend record results (and keep working if the backend search fails).
      var pages = searchNav(q);
      var asked = q;
      fetch(apiBase + '/admin/search?q=' + encodeURIComponent(q), { headers: headers }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (data) {
        // A slower answer to an older keystroke must not replace the newer one.
        if ((input.value || '').trim() !== asked.trim()) return;
        renderResults(dropdown, data, data.q || q, pages);
      }).catch(function () {
        // Backend record search failed — still show the section matches.
        if ((input.value || '').trim() !== asked.trim()) return;
        renderResults(dropdown, null, q, pages);
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
    // Capture-phase pointerdown (2026-11-05): a bubbling click never arrived from a button that stops its click.
    document.addEventListener('pointerdown', function (e) {
      if (!widget.wrap.contains(e.target)) dropdown.style.display = 'none';
    }, true);
  }

  function tryMount() {
    MOUNT_ATTEMPTS++;
    if (MOUNT_ATTEMPTS > MAX_MOUNT_ATTEMPTS) return;

    var role = getRole();
    if (!isStaffSidebarRole(role)) return; // not a sidebar role; never mount

    // v22p25: mount OUTSIDE #navLinks so the search bar is permanently visible
    // at the top of the sidebar, never scrolling away. Anchor between
    // .nav-brand (logo) and #navLinks (scrollable nav list).
    var sidebar = document.getElementById('appSidebar');
    var navLinks = document.getElementById('navLinks');
    if (!sidebar || !navLinks) {
      setTimeout(tryMount, 100);
      return;
    }
    if (document.getElementById('kt-search-widget')) return; // already mounted

    var widget = buildWidget();
    sidebar.insertBefore(widget.wrap, navLinks);
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

  /* HARVEST: once a day per screen + subtab, this browser reports the words the screen shows (headings, labels,
     column titles, buttons, tabs) to POST /diag/search-words. The server keeps only phrases that are written in the
     portal's own code and hold no real name (SearchKeywords gates); new ones join this session's index at once.
     Never while previewing another role ("view as"), never record text (no table cells, inputs or dialogs), and a
     phrase the server turned down is remembered by a short fingerprint and never sent again (reset monthly). */
  var KW_TAKE = 'h1, h2, h3, h4, h5, th, label, legend, summary, button, [role="tab"], .kt-tabbtn, .card-title, .kt-card-title, .section-title, .kt-section-title';
  var KW_TABS = '[role="tab"], .kt-tabbtn, .kt-tabband button, .kt-tab-band button, .kt-subtabs button, .kt-tabbar button';
  function kwFp(t) { var h = 5381; for (var i = 0; i < t.length; i++) { h = ((h << 5) + h + t.charCodeAt(i)) | 0; } return (h >>> 0).toString(36); }
  function kwStore(k, fallback) { try { return JSON.parse(localStorage.getItem(k) || 'null') || fallback; } catch (e) { return fallback; } }
  function kwSave(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function kwDay() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function kwClean(t) {
    t = String(t || '').split('\n')[0].replace(/\s+/g, ' ').replace(/^[^A-Za-z\u00C0-\u024F]+/, '').replace(/[\s:*\u203A>\u2192]+$/, '').trim();
    t = t.replace(/\s*\(?\d+\)?$/, '').trim();   // "Open (3)": the count is not part of the words
    t = t.replace(/(\u2026|\.{3})$/, '').trim();   // "Send setup instructions\u2026"
    return (t.length >= 4 && t.length <= 48 && /[A-Za-z]{3}/.test(t) && !/\d/.test(t)) ? t : '';
  }
  var kwTimer = null;
  function kwHarvest() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    var role = getRole(), main = document.getElementById('appMain');
    if (va || !role || !main || document.hidden) { return; }
    var hash = (location.hash || '').replace(/^#/, '').split('?')[0];
    if (!/^[a-z0-9][a-z0-9-]{0,58}$/.test(hash)) { return; }
    var tabEl = [].slice.call(main.querySelectorAll(KW_TABS)).filter(function (e) {
      return e.getClientRects().length && (e.getAttribute('aria-selected') === 'true' || e.classList.contains('active'));
    })[0];
    var tab = tabEl ? kwClean(tabEl.innerText || tabEl.textContent) : '';
    var day = kwDay(), key = role + '|' + hash + '|' + tab;
    var sent = kwStore('kt_sk_sent', {});
    if (sent[key] === day) { return; }
    var rej = kwStore('kt_sk_rej', { since: Date.now(), fp: [] });
    if (!rej.fp || Date.now() - (rej.since || 0) > 30 * 864e5) { rej = { since: Date.now(), fp: [] }; }
    var seen = {}, phrases = [];
    [].slice.call(main.querySelectorAll(KW_TAKE)).forEach(function (el) {
      if (phrases.length >= 60 || !el.getClientRects().length) { return; }
      if (el.closest('[role="dialog"], .kt-modal, td, input, textarea, select, [contenteditable="true"], [data-kt-private]')) { return; }
      // textContent, not innerText: the words as the code writes them, not as CSS shows them (UPPERCASE headers).
      var t = kwClean(el.textContent), l = t.toLowerCase();
      if (!t || seen[l] || rej.fp.indexOf(kwFp(l)) !== -1) { return; }
      seen[l] = 1; phrases.push(t);
    });
    if (phrases.length < 3) { return; }   // still drawing (or an empty state): try on the next change
    sent[key] = day;
    Object.keys(sent).forEach(function (k) { if (sent[k] !== day) { delete sent[k]; } });
    kwSave('kt_sk_sent', sent);
    kwApi('/diag/search-words', { method: 'POST', body: JSON.stringify({ role: role, hash: hash, tab: tab, phrases: phrases }) }).then(function (d) {
      (d.rejected || []).forEach(function (i) { if (phrases[i]) { rej.fp.push(kwFp(phrases[i].toLowerCase())); } });
      if (rej.fp.length > 3000) { rej.fp = rej.fp.slice(-3000); }
      kwSave('kt_sk_rej', rej);
      if (d.accepted && d.accepted.length && KW_LIVE[role]) {
        var list = KW_LIVE[role][hash] || (KW_LIVE[role][hash] = []);
        d.accepted.forEach(function (row) { list.push(row); });
        try { sessionStorage.removeItem('kt_sk_' + role); } catch (e) {}
      }
    }).catch(function () { delete sent[key]; kwSave('kt_sk_sent', sent); });
  }
  var kwSince = 0;
  function kwSchedule() {
    // Settled = 3s without changes; a screen with a ticking clock never settles, so at most 10s after the first change.
    var now = Date.now();
    if (!kwSince) { kwSince = now; }
    clearTimeout(kwTimer);
    kwTimer = setTimeout(function () { kwSince = 0; kwHarvest(); }, now - kwSince > 7000 ? 0 : 3000);
  }
  (function kwBind(n) {
    if (window.KT && KT.observeMain) { KT.observeMain(kwSchedule, { childList: true, subtree: true }); kwSchedule(); return; }
    if (n < 40) { setTimeout(function () { kwBind(n + 1); }, 500); }
  })(0);
  window.addEventListener('hashchange', kwSchedule);

  // Expose for debug.
  if (window.KT) window.KT.SearchWidget = { mount: tryMount, harvest: kwHarvest };
})(window);
