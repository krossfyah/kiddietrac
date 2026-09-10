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
  function kb(n) {
    n = Number(n) || 0;
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

  async function open(doc, btn) {
    var was = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Opening…';
    /* Opened before the await — a popup blocker rejects window.open() that is not
       a direct result of the click. */
    var win = window.open('', '_blank');
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
      var u = URL.createObjectURL(await r.blob());
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

  async function render(main) {
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

    docs.forEach(function (d) {
      var kid = [d.child && d.child.first_name, d.child && d.child.last_name].filter(Boolean).join(' ');
      var card = Dom.el('div', {
        style: 'background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:14px;'
             + 'padding:15px 16px;margin-bottom:10px;display:flex;gap:13px;align-items:center;',
      });
      card.appendChild(Dom.el('div', {
        style: 'flex:0 0 auto;width:42px;height:42px;border-radius:11px;background:var(--kt-bg);'
             + 'display:flex;align-items:center;justify-content:center;font-size:20px;',
      }, ICON[d.category] || '📄'));

      var mid = Dom.el('div', { style: 'flex:1;min-width:0;' });
      mid.appendChild(Dom.el('div', {
        style: 'font-weight:700;font-size:14.5px;color:var(--kt-text);line-height:1.35;',
      }, d.title || (LABEL[d.category] || 'Document')));
      mid.appendChild(Dom.el('div', {
        style: 'font-size:12.5px;color:var(--kt-text-muted);margin-top:2px;',
      }, [
        kid,
        LABEL[d.category] || null,
        d.signed_at ? 'signed ' + filedOn(d.signed_at) : 'filed ' + filedOn(d.created_at),
        kb(d.file_size),
      ].filter(Boolean).join('  ·  ')));
      card.appendChild(mid);

      var btn = Dom.el('button', {
        class: 'btn btn-secondary',
        style: 'flex:0 0 auto;padding:8px 15px;font-size:13.5px;font-weight:700;',
      }, 'Open');
      btn.addEventListener('click', function () { open(d, btn); });
      card.appendChild(btn);

      list.appendChild(card);
    });
  }

  KT.renderMyDocuments = render;
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
