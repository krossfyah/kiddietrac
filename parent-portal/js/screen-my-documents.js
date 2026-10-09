/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Documents, the family's own copies.

   A parent was told about an incident report by email and could read it in the
   portal, but there was no filed COPY they could come back to — nothing to keep,
   nothing to hand to a doctor or a lawyer, nothing that still existed once the
   email was buried. The report is now filed on the child's record when the family
   is told, re-filed when it is closed, and this screen is where they open it.

   The API returns an ALLOWLIST of categories (incident reports today). A child's
   record holds plenty that is not the family's to read, so nothing new becomes
   visible here until somebody decides it should.

   Opened through the API rather than the signed /storage link: the mobile wrapper
   cannot reliably follow a signed redirect, and a download deserves its own
   authorization check rather than relying on possession of a URL.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Shell = window.Shell;
  var Dom = KT.Dom || window.Dom;
  /* RESOLVED WHEN IT IS CALLED, never captured while this file parses.

     This was `var Api = window.Api;` — and this file is the only screen in the portal
     that did that. Every script here is `defer`red, so they parse in document order:
     this one is parsed BEFORE whatever assigns Api, and the variable was captured as
     undefined for the life of the page. Opening Settings → My profile & security then
     threw "Cannot read properties of undefined (reading 'get')" as an unhandled
     rejection, and My documents rendered blank. Every other screen reads the global at
     the moment it calls it, which is why every other screen worked. (ticket #60) */
  function api() {
    /* KT.Api — read off window.KT at CALL time.

       `window.Api` does not exist and never did: app.js publishes the client as
       `window.KT = { Auth, Api, ... }`, and every other screen reaches it as KT.Api.
       The original `var Api = window.Api` therefore captured undefined, and my first
       pass at this kept the wrong source while fixing only the timing — so it went on
       throwing, now one line lower. Read from window.KT rather than the KT captured at
       the top of this file, because app.js REPLACES window.KT wholesale rather than
       merging into it. (ticket #60, then #63) */
    var k = (typeof window !== 'undefined') ? window.KT : null;
    return (k && k.Api) ? k.Api : null;
  }

  function apiHost() {
    var a = api();
    return (KT.API_BASE) || (a && a.base) || 'https://api.kiddietrac.com/api/v1';
  }
  function token() {
    return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '';
  }
  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  /* A signed form is filed without a size (file_size is null), and "0 B" read as an
     empty file. An unknown size now just isn't shown. */
  function kb(n) {
    n = Number(n) || 0;
    if (n <= 0) { return null; }
    if (n < 1024) { return n + ' B'; }
    if (n < 1024 * 1024) { return Math.round(n / 1024) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }
  /* created_at is a real INSTANT written by the server in UTC, so it is converted
     into the agency's zone — unlike the incident's own occurred_at, which is a
     wall-clock time and is already baked into the document's title as typed. */
  /* THE MOMENT IT WAS FILED, in the AGENCY's zone, with the time.

     Two things were wrong and one was missing. `new Date(t)` on the server's zone-less
     UTC string is read as the DEVICE's local time — hours off — and the rendering used
     the device zone as well, so a document filed at 8pm Toronto could show the next
     day's date to somebody whose phone was set elsewhere. And it showed a date alone,
     which cannot answer "when was this signed" for anything filed today.

     KT.Fmt is the one place that knows both answers: parse() tells the string it is UTC,
     date()/time() render against KT.tz(). */
  function filedOn(t) {
    if (!t) { return ''; }
    try {
      if (window.KT && KT.Fmt && KT.Fmt.parse(t)) {
        return KT.Fmt.date(t, { year: 'numeric', month: 'short', day: 'numeric' })
          + ' · ' + KT.Fmt.time(t);
      }
      var iso = String(t).trim().replace(' ', 'T');
      if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(iso)) { iso += 'Z'; }
      return new Date(iso).toLocaleString('en-CA', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit',
      });
    } catch (e) { return String(t).slice(0, 10); }
  }

  var LABEL = {
    incident_report: 'Incident report',
    signed_form: 'Signed form',
    requested_file: 'File you sent in',
    agreement: 'Terms, Privacy & NDA',
    contract: 'Contract',
    certificate: 'Certificate',
    id: 'ID document',
    file: 'File',
  };
  var ICON = {
    incident_report: '🩹', signed_form: '📝', agreement: '🔏', requested_file: '📥',
    contract: '📃', certificate: '🎓', id: '🪪', file: '📎',
  };

  async function open(doc, btn, wantPrint) {
    var was = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Opening…';
    /* IN THE PORTAL'S PANEL, NOT A NEW TAB (2026-10-02). This was the last document
       screen still doing createObjectURL + window.open, which in the phone app opens an
       external browser with no session, the blank window Help says is fixed. KT.viewBlob
       is the shared panel every other document screen uses; the tab stays only as a
       fallback for a page that loaded without the viewer. */
    var inPanel = !!(window.KT && KT.viewBlob);
    /* Opened before the await — a popup blocker rejects window.open() that is not
       a direct result of the click. */
    var win = inPanel ? null : window.open('', '_blank');
    try {
      /* Two sources, two download routes, each with its own authorization check:
         /parent/documents is "a report about my child", /auth/me/documents is "a file on
         my own record". Neither will serve the other's rows, which is the point — the
         tag travels with the document rather than being inferred here. */
      var path = doc.__mine
        ? '/auth/me/documents/' + doc.id + '/download'
        : '/parent/documents/' + doc.id + '/download';
      var r = await fetch(apiHost() + path, {
        headers: { Authorization: 'Bearer ' + token() },
      });
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      var blob = await r.blob();
      if (inPanel) {
        KT.viewBlob(blob, { title: doc.title || LABEL[doc.category] || 'Document', label: LABEL[doc.category] || 'Document' });
        /* PRINT FROM THE ⋮ (2026-10-09: "add a print button in the kebab for the parents,
           educator, staff for the documents"). Opens the document in the shared viewer and
           presses ITS Print, which already knows how to print in the phone app (where
           window.print() does nothing) and prints just the document, not the portal. */
        if (wantPrint) {
          setTimeout(function () {
            var all = document.querySelectorAll('.ktdv-fprint, .ktdv-print');
            var p = all[all.length - 1];
            if (p) { p.click(); }
          }, 700);
        }
        return;
      }
      var u = URL.createObjectURL(blob);
      if (win) { win.location = u; } else { window.open(u, '_blank'); }
      setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
    } catch (e) {
      if (win) { win.close(); }
      if (Dom && Dom.toast) { Dom.toast('That document could not be opened.', 'error'); }
    } finally {
      btn.disabled = false;
      btn.textContent = was;
    }
  }

  var ROLE_WORD = { centre_director: 'Director', agency_admin: 'Admin', platform_admin: 'Admin', director: 'Director', admin: 'Admin' };
  function reviewCell(r) {
    var cell = Dom.el('div', { 'data-kt-doc-review': r.state, style: 'flex:0 1 250px;min-width:170px;font-size:12.5px;line-height:1.4;' });
    var tone = {
      countersigned: ['#ECFDF5', '#047857', '✅ Counter-signed'],
      returned:      ['#FFFBEB', '#B45309', '↩️ Sent back for correction'],
      corrected:     ['#F1F5F9', '#475569', '🔁 Replaced by a corrected copy'],
      pending:       ['#F8FAFC', '#64748B', '⏳ Not counter-signed yet'],
    }[r.state] || ['#F8FAFC', '#64748B', r.state];
    cell.appendChild(Dom.el('div', {
      style: 'display:inline-block;padding:2px 9px;border-radius:999px;font-weight:700;font-size:12px;'
           + 'background:' + tone[0] + ';color:' + tone[1] + ';white-space:nowrap;',
    }, tone[2]));
    var who = r.by ? (r.by + (ROLE_WORD[r.role] ? ' (' + ROLE_WORD[r.role] + ')' : '')) : '';
    var line = [who ? 'by ' + who : '', r.at ? filedOn(r.at) : ''].filter(Boolean).join(' · ');
    if (line) { cell.appendChild(Dom.el('div', { style: 'color:var(--kt-text-muted);margin-top:3px;' }, line)); }
    if (r.note && r.state !== 'corrected') {
      cell.appendChild(Dom.el('div', { style: 'color:var(--kt-text-muted);margin-top:2px;font-style:italic;overflow-wrap:anywhere;' }, '“' + r.note + '”'));
    }
    return cell;
  }

  async function render(main) {
    Dom = (window.KT && window.KT.Dom) || Dom || window.Dom;   // read at call time, like api()
    Dom.clear(main);
    var wrap = Dom.el('div', { style: 'padding:20px 16px 90px;max-width:820px;margin:0 auto;' });
    main.appendChild(wrap);

    wrap.appendChild(Dom.el('h1', {
      style: 'font-family:var(--kt-font-display);font-size:23px;margin:0 0 4px;',
    }, 'Documents'));
    wrap.appendChild(Dom.el('p', {
      style: 'color:var(--kt-text-muted);font-size:14px;line-height:1.6;margin:0 0 18px;',
    }, 'Every form you have signed and every report shared with you, filed and kept. These stay here — you can open or save them at any time.'));

    var list = Dom.el('div', {});
    list.appendChild(Dom.el('div', {
      style: 'color:var(--kt-text-muted);font-size:13.5px;',
    }, 'Loading…'));
    wrap.appendChild(list);

    /* TWO SOURCES, ONE SCREEN.

       A signed form used to exist only in the Forms Manager, which is an admin screen —
       so the parent who signed it, and the educator who signed it, could never see it
       again. Filing puts it on their own record; this is where they open it.

       · /auth/me/documents  — their own record. EVERY role has one, so this screen is
         no longer parent-only: an educator's signed policies land here too.
       · /parent/documents   — reports the centre shared about their children. It sits
         behind role:guardian, so ASKED BY ANYBODY ELSE IT IS A 403 — and since failing
         GETs are audited, every educator opening this screen wrote a "403 forbidden"
         line into the agency's audit log. Twenty-three of them in a day, from people
         doing nothing wrong, in the log an administrator reads to spot people doing
         something wrong.

         So it is no longer ASKED unless the account has a guardian role. Catching the
         refusal was never the problem; making a request whose answer is already known
         was. Same conclusion the top-bar activity feed reached after 761 of these.

       Both are allowed to fail independently. One source being down should not blank a
       screen that the other could have filled. */
    var mine = [], shared = [];
    var mineOk = false, sharedOk = false;

    var A = api();
    if (!A) {
      // Nothing to fetch with. Say so rather than throwing into an unhandled rejection.
      list.innerHTML = '<div style="padding:24px;text-align:center;color:#94A3B8;font-size:13px;">'
        + 'Could not load your documents just now. Please refresh.</div>';
      return;
    }

    /* Does this ACCOUNT have children here — not "is it acting as a parent right now".
       A member of staff whose own child attends should still see that child's shared
       reports on her own documents screen; she just should not be asked to prove it
       with a refused request. */
    var isGuardian = false;
    try {
      var ku = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      isGuardian = (Array.isArray(ku.roles) && ku.roles.indexOf('guardian') !== -1)
        || ku.primary_role === 'guardian';
    } catch (e) { isGuardian = false; }

    var results = await Promise.all([
      A.get('/auth/me/documents').then(function (r) {
        mineOk = true; return (r && r.documents) || [];
      }).catch(function () { return []; }),
      isGuardian
        ? A.get('/parent/documents').then(function (r) {
            sharedOk = true; return (r && r.documents) || [];
          }).catch(function () { return []; })
        : Promise.resolve([]),
    ]);
    mine = results[0]; shared = results[1];
    if (mineOk) { setCount(mine.length + shared.length); }

    /* Awaiting your signature (parents): what the old e-signature "Documents" screen
       showed on top, kept so merging the two screens loses nothing. Never blocks the list. */
    if (isGuardian) {
      A.get('/parent/edocuments').then(function (r) {
        var pend = ((r && (r.data || r.documents)) || []).filter(function (d) { return !d.signed; });
        if (!pend.length || !list.isConnected) { return; }
        var box = Dom.el('div', { 'data-kt-doc-awaiting': '1', style: 'margin:0 0 14px;' });
        box.appendChild(Dom.el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#B91C1C;margin:0 2px 8px;' },
          '⚠ Awaiting your signature (' + pend.length + ')'));
        pend.forEach(function (d) {
          var a = Dom.el('a', { href: '#edocuments', style: 'display:flex;align-items:center;gap:12px;text-decoration:none;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:14px;padding:12px 14px;margin-bottom:8px;' });
          a.appendChild(Dom.el('span', { style: 'flex:1;min-width:0;font-weight:800;color:#0F172A;font-size:14.5px;overflow-wrap:anywhere;' }, d.title || d.name || d.document_name || 'Document'));
          a.appendChild(Dom.el('span', { style: 'flex:0 0 auto;background:#DC2626;color:#fff;font-size:13px;font-weight:800;padding:7px 16px;border-radius:10px;' }, 'Sign'));
          box.appendChild(a);
        });
        list.parentNode.insertBefore(box, list);
      }).catch(function () {});
    }

    // The tag open() reads. Set here, once, where the origin is actually known.
    mine.forEach(function (d) { d.__mine = true; });

    if (!mineOk && !sharedOk) {
      Dom.clear(list);
      list.appendChild(Dom.el('div', {
        style: 'background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:14px;'
             + 'padding:26px;text-align:center;color:var(--kt-text-muted);font-size:14px;',
      }, 'These could not be loaded just now. Please try again in a moment.'));
      return;
    }

    var docs = mine.concat(shared);
    /* Newest first across both sources. signed_at when there is one — the date a person
       remembers is the day they signed, not the moment a row happened to be written. */
    docs.sort(function (a, b) {
      return String(b.signed_at || b.created_at || '')
        .localeCompare(String(a.signed_at || a.created_at || ''));
    });
    Dom.clear(list);

    if (!docs.length) {
      var empty = Dom.el('div', {
        style: 'background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:16px;'
             + 'padding:38px 24px;text-align:center;',
      });
      empty.appendChild(Dom.el('div', { style: 'font-size:34px;margin-bottom:10px;' }, '📄'));
      empty.appendChild(Dom.el('div', {
        style: 'font-weight:800;font-size:16px;color:var(--kt-text);margin-bottom:5px;',
      }, 'Nothing filed yet'));
      empty.appendChild(Dom.el('div', {
        style: 'color:var(--kt-text-muted);font-size:13.5px;line-height:1.6;max-width:380px;margin:0 auto;',
      }, 'When you sign a form, or your centre shares a report with you, a copy is kept here for you to open whenever you need it.'));
      list.appendChild(empty);
      return;
    }

    /* A TABLE WITH HEADINGS (2026-10-09: "it should also have a column that shows counter
       signed as well by admin/director name"). Document | Signed | Counter-signed | Open.
       Every row carries the same facts, so columns let the eye run down one axis — the
       same reasoning as the signed-documents table (Anthony, 2026-09-09). The Open button
       sits alone in the last cell, where kt-row-actions folds it into the standard ⋮. */
    var th = function (t) {
      return Dom.el('th', { style: 'text-align:left;padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;'
        + 'text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:1px solid var(--kt-border);' }, t);
    };
    var tdStyle = 'padding:11px 14px;border-bottom:1px solid var(--kt-border);vertical-align:middle;';
    var shell = Dom.el('div', { style: 'overflow-x:auto;background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:14px;' });
    var table = Dom.el('table', { 'data-kt-paginate': '25', style: 'width:100%;border-collapse:collapse;font-size:13.5px;min-width:640px;' });
    var head = Dom.el('tr', { style: 'background:#F8FAFC;' });
    [th('Document'), th('Signed'), th('Counter-signed'), th('')].forEach(function (x) { head.appendChild(x); });
    var thead = Dom.el('thead', {}); thead.appendChild(head); table.appendChild(thead);
    var tbody = Dom.el('tbody', {});

    docs.forEach(function (d) {
      var kid = [d.child && d.child.first_name, d.child && d.child.last_name].filter(Boolean).join(' ');
      var tr = Dom.el('tr', {});

      var c1 = Dom.el('td', { style: tdStyle });
      var line = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;min-width:0;' });
      line.appendChild(Dom.el('span', {
        style: 'flex:0 0 auto;width:30px;height:30px;border-radius:9px;background:var(--kt-bg);'
             + 'display:inline-flex;align-items:center;justify-content:center;font-size:15px;',
      }, ICON[d.category] || '📄'));
      var nm = Dom.el('div', { style: 'min-width:0;' });
      nm.appendChild(Dom.el('div', { style: 'font-weight:700;color:var(--kt-text);line-height:1.35;' },
        d.title || (LABEL[d.category] || 'Document')));
      var sub = [kid, LABEL[d.category] || null, kb(d.file_size)].filter(Boolean).join('  ·  ');
      if (sub) { nm.appendChild(Dom.el('div', { style: 'font-size:12px;color:var(--kt-text-muted);margin-top:1px;' }, sub)); }
      line.appendChild(nm);
      c1.appendChild(line);
      tr.appendChild(c1);

      tr.appendChild(Dom.el('td', { style: tdStyle + 'color:#475569;white-space:nowrap;' },
        d.signed_at ? filedOn(d.signed_at) : 'filed ' + filedOn(d.created_at)));

      // The office's review: counter-signed by whom and when, sent back, or not yet.
      var c3 = Dom.el('td', { style: tdStyle });
      if (d.review) { c3.appendChild(reviewCell(d.review)); }
      else { c3.appendChild(Dom.el('span', { style: 'color:#CBD5E1;' }, '—')); }
      tr.appendChild(c3);

      var c4 = Dom.el('td', { style: tdStyle + 'text-align:right;white-space:nowrap;' });
      var btn = Dom.el('button', { type: 'button', class: 'btn btn-secondary', style: 'padding:6px 13px;font-size:13px;font-weight:700;' }, 'Open');
      btn.addEventListener('click', function () { open(d, btn); });
      c4.appendChild(btn);
      // Print sits beside Open in the last cell, so kt-row-actions puts both in the ⋮.
      var pbtn = Dom.el('button', { type: 'button', class: 'btn btn-secondary', style: 'padding:6px 13px;font-size:13px;font-weight:700;margin-left:6px;' }, '🖨 Print');
      pbtn.addEventListener('click', function () { open(d, pbtn, true); });
      c4.appendChild(pbtn);
      tr.appendChild(c4);

      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    shell.appendChild(table);
    list.appendChild(shell);
    try { if (window.KT && KT.sweepRowActions) { KT.sweepRowActions(); } } catch (e) {}
  }

  /* A COUNT ON EVERY "DOCUMENTS" LINK (2026-10-09: "add a counter to the documents
     section under parents, educators and staff as well"). How many documents are on
     file for you. The sidebar entries carry a nav-badge (badgeKey my_documents); the
     home tiles and the phone menu have no badge slot, so any other link to
     #my-documents gets a small pill of its own. Neutral grey, not the red of an unread
     count: it says how many you have, not that something needs you. Asked once at
     start, again when the Documents screen draws (its own list is the truth), and at
     most every five minutes after that. */
  var docCount = null, docCountAt = 0, countBusy = false;
  function paintCount() {
    if (docCount === null) { return; }
    try { if (window.KT && KT.Shell && KT.Shell.setBadge) { KT.Shell.setBadge('my_documents', docCount); } } catch (e) {}
    var links = document.querySelectorAll('a[href="#my-documents"],[data-hash="my-documents"],a[href="#signed-docs"]');
    Array.prototype.forEach.call(links, function (a) {
      if (a.querySelector('[data-badge-key="my_documents"]')) { return; }   // the sidebar's own badge
      var pill = a.querySelector('.kt-doc-count');
      if (!docCount) { if (pill) { pill.remove(); } return; }
      if (!pill) {
        pill = document.createElement('span'); pill.className = 'kt-doc-count';
        pill.style.cssText = 'display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#E2E8F0;'
          + 'color:#334155;font-size:11px;font-weight:800;line-height:1.5;vertical-align:middle;';
        var label = a.querySelector('.nav-label, .kt-tile-label, span:last-child') || a;
        label.appendChild(pill);
      }
      var txt = docCount > 99 ? '99+' : String(docCount);
      if (pill.textContent !== txt) { pill.textContent = txt; }
    });
  }
  function setCount(n) { docCount = n; docCountAt = Date.now(); paintCount(); }
  function refreshCount(force) {
    var A = api();
    if (!A || countBusy || (!force && docCountAt && Date.now() - docCountAt < 300000)) { paintCount(); return; }
    var tok = token && token();
    if (!tok) { return; }   // signed out: nothing to count
    countBusy = true;
    A.get('/auth/me/documents').then(function (r) { setCount(((r && r.documents) || []).length); })
      .catch(function () {}).then(function () { countBusy = false; });
  }
  KT.refreshDocumentCount = refreshCount;
  try {
    var css = document.createElement('style');
    css.textContent = '.app-sidebar .nav-link .nav-badge[data-badge-key="my_documents"]{background:#E2E8F0;color:#334155;}';
    (document.head || document.documentElement).appendChild(css);
  } catch (e) {}
  setTimeout(function () { refreshCount(true); }, 2500);
  window.addEventListener('hashchange', function () { setTimeout(function () { refreshCount(false); }, 400); });
  // Menus and tiles are drawn after the fact (and redrawn): paint any new link, cheaply.
  try {
    var painting = false;
    new MutationObserver(function () {
      if (painting || docCount === null) { return; }
      painting = true;
      setTimeout(function () { painting = false; paintCount(); }, 60);   // not rAF: it never fires on a hidden tab
    }).observe(document.body || document.documentElement, { childList: true, subtree: true });
  } catch (e) {}

  KT.renderMyDocuments = render;
  /* THE SCREEN NEVER REGISTERED (2026-10-09: "I still don't see the signed files under
     Samantha Kicks parent view under Documents"). `var Shell = window.Shell` — but the
     shell is published as window.KT.Shell and there is no window.Shell, so this block
     was skipped for every role. #my-documents then fell through to the role's home
     screen under a "Documents" banner: a parent saw "Today with <child>", and a parent
     with no children (Samantha) saw nothing at all, while every signed form sat filed
     on the server. Resolved from window.KT here, at registration. */
  Shell = (window.KT && window.KT.Shell) || Shell;
  if (Shell && Shell.registerScreen) {
    /* EVERY role, not only guardians. The screen used to be parent-only because its one
       source was parent-only; now that it also shows the person's own filed documents,
       an educator or a home visitor has exactly as much reason to open it. */
    ['guardian', 'educator', 'home_visitor', 'centre_director', 'agency_admin',
     'platform_admin', 'auditor'].forEach(function (r) {
      Shell.registerScreen(r + ':my-documents', render);
    });
  }
})(window);
