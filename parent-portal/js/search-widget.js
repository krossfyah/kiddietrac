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
    rooms:    { icon: '🚪', label: 'Rooms' },
  };

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

  function scoreNav(item, qRaw) {
    var literal = scoreOne(item, qRaw);
    var aliased = applyAliases(qRaw);
    if (normWords(aliased) === normWords(qRaw)) { return literal; }
    var viaAlias = scoreOne(item, aliased);
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

  /* Public: KT.navSearch('audit logs') -> [{label, hash, section, icon, score}, …] */
  function navSearch(q, opts) {
    opts = opts || {};
    var limit = opts.limit || 8;
    if (!normWords(q)) { return []; }

    var items = [], seen = {};
    try {
      var S = window.KT && window.KT.Shell;
      var role = opts.role || getRole() || 'agency_admin';
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

    if (!items.length) {
      // No Shell (or no nav for this role) — read whatever the page is showing.
      var links = document.querySelectorAll('a.nav-link[data-hash], #navLinks a[href^="#"]');
      for (var i = 0; i < links.length; i++) {
        var a = links[i];
        var hh = a.getAttribute('href');
        if (!hh || hh.charAt(0) !== '#') { hh = '#' + (a.getAttribute('data-hash') || ''); }
        if (hh === '#' || hh.length < 2 || seen[hh]) { continue; }
        seen[hh] = 1;
        var le = a.querySelector('.nav-label');
        var lb = (le ? le.textContent : (a.textContent || '')).replace(/^[^A-Za-z0-9]+/, '').trim();
        if (lb) { items.push({ label: lb, hash: hh, section: '' }); }
      }
    }

    var scored = [];
    items.forEach(function (it) {
      var sc = scoreNav(it, q);
      if (sc > 0) { scored.push({ item: it, score: sc }); }
    });
    if (!scored.length) {
      items.forEach(function (it) {
        var sc = partialScore(it, q);
        if (sc > 0) { scored.push({ item: it, score: sc }); }
      });
    }
    // SORT, THEN CAP. The other way round is how the exact match got cut.
    scored.sort(function (x, y) { return y.score - x.score || x.item.label.localeCompare(y.item.label); });
    return scored.slice(0, limit).map(function (r) {
      return {
        label: r.item.label, hash: r.item.hash, icon: r.item.icon,
        section: r.item.section, score: r.score, sublabel: 'Open section',
      };
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
      fetch(apiBase + '/admin/search?q=' + encodeURIComponent(q), { headers: headers }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (data) {
        var results = data.results || {};
        results.pages = pages;
        renderResults(dropdown, results, data.q || q);
      }).catch(function () {
        // Backend record search failed — still show the section matches.
        renderResults(dropdown, { pages: pages }, q);
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

  // Expose for debug.
  if (window.KT) window.KT.SearchWidget = { mount: tryMount };
})(window);
