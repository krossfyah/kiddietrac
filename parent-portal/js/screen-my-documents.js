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
    return (typeof window !== 'undefined' && window.Api) ? window.Api : null;
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
  function filedOn(t) {
    if (!t) { return ''; }
    try {
      return new Date(t).toLocaleDateString('en-CA', {
        year: 'numeric', month: 'short', day: 'numeric',
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
       · /parent/documents   — reports the centre shared about their children. Guardians
         only, and it 403s for everybody else, which is why a failure here is not an
         error: it is the ordinary answer for a person with no children on file.

       Both are allowed to fail independently. One source being down should not blank a
       screen that the other could have filled. */
    var mine = [], shared = [];
    var mineOk = false, sharedOk = false;

    var results = await Promise.all([
      api().get('/auth/me/documents').then(function (r) {
        mineOk = true; return (r && r.documents) || [];
      }).catch(function () { return []; }),
      api().get('/parent/documents').then(function (r) {
        sharedOk = true; return (r && r.documents) || [];
      }).catch(function () { return []; }),
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
