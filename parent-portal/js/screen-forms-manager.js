/* ═══════════════════════════════════════════════════════════════════
   Forms Manager (admin) — upload a fillable PDF, assign it to roles, and
   track e-sign completions. Two tabs: Library (upload + manage) and
   Completed (a table of sign-offs with a ⋮ kebab: view / download / email).
   Backend: /admin/managed-forms* + /admin/managed-forms/signoffs.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var API_HOST = ((KT.API_BASE || 'https://api.kiddietrac.com/api/v1')).replace(/\/api\/v1\/?$/, '');
  var AUD = [['guardian', 'Parents', '👪'], ['educator', 'Educators', '🎓'],
             ['home_visitor', 'Home visitors', '🏡'], ['centre_director', 'Directors', '🏫']];
  var audLabel = function (a) { var m = { guardian: 'Parents', educator: 'Educators', home_visitor: 'Home visitors', centre_director: 'Directors' }; return m[a] || a; };
  function fileUrl(u) { return u ? (/^https?:/.test(u) ? u : API_HOST + u) : ''; }
  function openUrl(u) {
    try { if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.Browser) { Capacitor.Plugins.Browser.open({ url: u }); return; } } catch (e) {}
    window.open(u, '_blank');
  }
  function fmtDate(s) { try { return KT.Fmt ? KT.Fmt.date(s) : new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString(); } catch (e) { return s || ''; } }
  function toast(icon, t, m, c) { if (KT.toast) KT.toast(icon, t, m, c || '#16A34A'); }

  function render(container) {
    container.innerHTML =
      '<div style="padding:0 24px 24px;max-width:1080px;margin:0 auto;color:#0F172A;">'
      + '<div style="display:flex;gap:8px;margin:16px 0 16px;">'
      + '<button class="fm-tab" data-t="library" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">📚 Library</button>'
      + '<button class="fm-tab" data-t="package" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">📦 Multiple forms</button>'
      + '<button class="fm-tab" data-t="files" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">📥 Request files</button>'
      + '<button class="fm-tab" data-t="completed" type="button" style="border:1px solid #E2E8F0;border-radius:9px;padding:8px 16px;font-size:13.5px;font-weight:700;cursor:pointer;">✅ Completed</button>'
      + '</div><div id="fm-body"></div></div>';
    var body = container.querySelector('#fm-body');
    var tabs = container.querySelectorAll('.fm-tab');
    function activate(t) {
      tabs.forEach(function (b) { var on = b.getAttribute('data-t') === t; b.style.background = on ? '#1F6080' : '#fff'; b.style.color = on ? '#fff' : '#334155'; });
      if (t === 'completed') renderCompleted(body);
      else if (t === 'files') renderFileRequests(body);
      else if (t === 'package') renderPackage(body);
      else renderLibrary(body);
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-t')); }); });
    activate('library');
  }

  /* ───────── PACKAGE: several forms, several people, one email ─────────

     Assigning used to be one form at a time, from the form's own Edit dialog. Onboarding a
     family meant opening the consent form, picking them, saving; then the photo permission;
     then the medical form — and the parent was told about none of it, because assigning has
     never sent anything.

     The tab now shows the HISTORY — what went to whom, when, and who sent it — and the
     sending itself happens in a dialog over the top. That ordering is deliberate: the
     question an admin arrives with is almost always "did this already go out?", and the
     answer used to be unavailable anywhere in the portal.
     (Anthony, 2026-09-09) */

  /* Survives the tab switch to the Library and back — a render-scoped variable would be
     rebuilt empty by the very re-render it needs to outlive. */
  var PK_RESUME = null;

  function renderPackage(body) {
    body.innerHTML =
      '<div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px;">'
      + '<div style="min-width:0;">'
      +   '<div style="font-size:17px;font-weight:800;color:#0F172A;">📦 Multiple forms</div>'
      +   '<div style="font-size:13px;color:#64748B;margin-top:2px;">Send several forms at once, and see everything that has gone out.</div>'
      + '</div>'
      + '<button id="pk-open" type="button" data-kt-iconized="1" style="margin-left:auto;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:13.5px;cursor:pointer;">+ Send multiple forms</button>'
      + '</div>'
      + '<div id="pk-history"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';

    body.querySelector('#pk-open').addEventListener('click', function () { openPackageDialog(body); });
    loadPackageHistory(body.querySelector('#pk-history'));

    // Coming back from an upload in the Library: reopen the dialog where it left off.
    if (PK_RESUME) { setTimeout(function () { openPackageDialog(body); }, 250); }
  }

  /* ONE POPUP MENU, used by both kebabs on this screen.

     The Completed tab already had this logic inline; a second copy for the sends table
     would be the point at which the two menus start behaving differently -- one closing
     on scroll, one not, one flipping above the button near the bottom of the window and
     one running off the screen. Lifted out unchanged in behaviour. */
  function openMenu(btn, build) {
    var menu = document.createElement('div');
    menu.style.cssText = 'position:fixed;z-index:2147483000;background:#fff;border:1px solid #E5E7EB;'
      + 'border-radius:12px;box-shadow:0 12px 34px rgba(15,23,42,.18);padding:6px 0;min-width:190px;';

    function close() {
      if (menu.parentNode) { menu.remove(); }
      document.removeEventListener('click', onDoc, true);
      window.removeEventListener('scroll', close, true);
    }
    function onDoc(ev) { if (!menu.contains(ev.target) && ev.target !== btn) { close(); } }

    function item(icon, label, danger, fn) {
      var mi = document.createElement('button');
      mi.type = 'button';
      mi.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;'
        + 'background:none;border:none;padding:10px 15px;font-size:13.5px;cursor:pointer;color:'
        + (danger ? '#B91C1C' : '#111827') + ';font-family:inherit;white-space:nowrap;';
      mi.innerHTML = '<span style="width:18px;text-align:center;">' + icon + '</span><span>' + label + '</span>';
      mi.onmouseenter = function () { mi.style.background = '#F1F5F9'; };
      mi.onmouseleave = function () { mi.style.background = 'none'; };
      mi.onclick = function (ev) { ev.stopPropagation(); close(); fn(); };
      menu.appendChild(mi);
      return mi;
    }

    build(item, close);
    if (!menu.children.length) { return; }

    document.body.appendChild(menu);
    var rect = btn.getBoundingClientRect();
    var mw = menu.offsetWidth || 190, mh = menu.offsetHeight || 150;
    menu.style.left = Math.max(8, Math.min(rect.right - mw, innerWidth - mw - 8)) + 'px';
    // Flips above the button rather than off the bottom of a phone screen.
    menu.style.top = (rect.bottom + 6 + mh > innerHeight - 8
      ? Math.max(8, rect.top - mh - 6)
      : rect.bottom + 6) + 'px';
    setTimeout(function () {
      document.addEventListener('click', onDoc, true);
      window.addEventListener('scroll', close, true);
    }, 0);
  }

  /* A plain dialog for the package views. Uses the same scrim and card as the send
     dialog so the screen has one look, and opts out of the modal guard for the same
     reason that one does -- it ships its own ✕. */
  function openSheet(titleHtml, bodyHtml, wide) {
    var stale = document.getElementById('pk-sheet');
    if (stale && stale.parentNode) { stale.parentNode.removeChild(stale); }

    var ov = document.createElement('div');
    ov.id = 'pk-sheet';
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:16px;max-width:' + (wide ? '900px' : '640px')
      + ';width:100%;margin:auto;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);overflow:hidden;';
    card.innerHTML =
      '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div style="min-width:0;">' + titleHtml + '</div>'
      +   '<button class="modal-close" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div id="pk-sheet-body" style="padding:18px 22px;max-height:min(70vh,720px);overflow-y:auto;" data-kt-scroll="1">' + bodyHtml + '</div>';
    ov.appendChild(card);
    document.body.appendChild(ov);
    card.querySelector('.modal-close').addEventListener('click', function () { ov.remove(); });
    return { overlay: ov, card: card, body: card.querySelector('#pk-sheet-body') };
  }

  /** What has been sent — the answer to "did this already go out?" */
  function loadPackageHistory(el) {
    Api.get('/admin/managed-forms/packages').then(function (d) {
      var rows = (d && d.sends) || [];
      if (!rows.length) {
        el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">Nothing sent yet. Use <strong>+ Send multiple forms</strong> above.</div>';
        return;
      }
      var th = function (t, extra) {
        return '<th style="text-align:left;padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;' + (extra || '') + '">' + t + '</th>';
      };
      /* SEARCHABLE AND SORTABLE, like every other table in the portal.

         data-kt-filter-always asks kt-table-filter.js for the search box and the row
         counter even while this is short — a history an admin comes to precisely to
         answer "did this already go out?" should not gain and lose its controls as it
         grows. kt-polish.js makes the headers sortable; the data-kt-sort keys written
         onto the cells below are what make that sort mean anything, since three of the
         five columns hold stacked lines or a badge rather than one plain value.
         (Anthony, 2026-09-09) */
      el.innerHTML = '<div style="overflow-x:auto;background:#fff;border:1px solid #E5E7EB;border-radius:12px;">'
        + '<table data-kt-paginate="25" data-kt-filter-always="1" style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F9FAFB;">'
        +   th('Sent') + th('Forms') + th('Sent to') + th('Signed') + th('Emailed') + th('By') + th('')
        + '</tr></thead><tbody>'
        + rows.map(function (r) {
            var titles = (r.forms || []);
            var people = (r.recipients || []);
            var formCell = titles.slice(0, 3).map(function (t) {
              return '<div style="font-weight:600;color:#111827;">' + esc(t) + '</div>';
            }).join('') + (titles.length > 3
              ? '<div style="font-size:11.5px;color:#94A3B8;">+' + (titles.length - 3) + ' more</div>' : '');
            var whoCell = people.slice(0, 3).map(function (p) {
              return '<div>' + esc(p.name || p.email || '—')
                + (p.name && p.email ? '<span style="color:#94A3B8;font-size:11.5px;"> · ' + esc(p.email) + '</span>' : '')
                + '</div>';
            }).join('') + (people.length > 3
              ? '<div style="font-size:11.5px;color:#94A3B8;">+' + (people.length - 3) + ' more</div>' : '');
            /* Three distinct states, because a tick alone cannot say whether email was
               switched off or simply reached nobody. */
            var mail = !r.notified
              ? '<span style="font-size:11px;font-weight:800;color:#64748B;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;padding:2px 9px;">Not sent</span>'
              : (r.emailed > 0
                  ? '<span style="color:#16A34A;font-weight:700;">✓ ' + r.emailed + ' of ' + r.recipient_count + '</span>'
                  : '<span style="font-size:11px;font-weight:800;color:#B45309;background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:2px 9px;">None went</span>');
            /* Sort keys. Dates sort by the raw stamp, never by the formatted text
               ("9 Sep" would file under 9). The two stacked columns sort by their first
               entry, which is what the eye reads first. Email sorts by state, so the
               three states group instead of interleaving. */
            var kSent  = String(r.sent_at || '');
            var kForms = String(titles[0] || '').toLowerCase();
            var kWho   = String((people[0] && (people[0].name || people[0].email)) || '').toLowerCase();
            var kMail  = !r.notified ? '0 not sent' : (r.emailed > 0 ? '2 sent ' + r.emailed : '1 none went');

            /* HOW MUCH HAS COME BACK. A package is forms x people signatures; this is how
               many of them exist. Sorted on the RATIO, not the count — "2 of 2" is
               finished and "2 of 40" is barely started, and a column that put them next
               to each other would be the wrong list to work from. */
            var pct = (r.slots ? (r.signed || 0) / r.slots : -1);
            var doneCell;
            if (!r.slots) {
              doneCell = '<span style="color:#CBD5E1;">—</span>';
            } else if (r.signed >= r.slots) {
              doneCell = '<span style="font-size:11px;font-weight:800;color:#166534;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:999px;padding:2px 9px;white-space:nowrap;">✓ All ' + r.slots + '</span>';
            } else {
              doneCell = '<span style="font-size:11px;font-weight:800;color:' + (r.signed ? '#B45309' : '#64748B') + ';background:' + (r.signed ? '#FEF3C7' : '#F1F5F9') + ';border:1px solid ' + (r.signed ? '#FDE68A' : '#E2E8F0') + ';border-radius:999px;padding:2px 9px;white-space:nowrap;">'
                + (r.signed || 0) + ' of ' + r.slots + '</span>';
            }
            /* The search box matches on text, so every value a reader might type has to
               BE in the row. Titles and addresses past the third are folded into
               "+2 more", which reads well and cannot be searched — so they ride along
               in a visually-hidden span. Filtering a send history by a form name that
               happens to be fourth in the list should still find it. */
            var hidden = titles.slice(3).concat(people.slice(3).map(function (p) {
              return [p.name, p.email].filter(Boolean).join(' ');
            })).join(' ');
            var hiddenCell = hidden
              ? '<span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">' + esc(hidden) + '</span>'
              : '';
            return '<tr style="border-top:1px solid #F3F4F6;vertical-align:top;">'
              + '<td data-kt-sort="' + esc(kSent) + '" style="padding:11px 14px;white-space:nowrap;color:#374151;">' + esc(fmtStamp(r.sent_at)) + '</td>'
              + '<td data-kt-sort="' + esc(kForms) + '" style="padding:11px 14px;position:relative;">' + formCell + hiddenCell + '</td>'
              + '<td data-kt-sort="' + esc(kWho) + '" style="padding:11px 14px;color:#334155;">' + whoCell + '</td>'
              + '<td data-kt-sort="' + esc(String(pct.toFixed(4))) + '" style="padding:11px 14px;white-space:nowrap;">' + doneCell + '</td>'
              + '<td data-kt-sort="' + esc(kMail) + '" style="padding:11px 14px;white-space:nowrap;">' + mail + '</td>'
              + '<td data-kt-sort="' + esc(String(r.sent_by || '').toLowerCase()) + '" style="padding:11px 14px;color:#475569;white-space:nowrap;">' + esc(r.sent_by || '—') + '</td>'
              + '<td style="padding:11px 8px;text-align:right;">'
              +   '<button class="pk-kebab" data-id="' + r.id + '" data-kt-iconized="1" title="Actions" '
              +   'style="width:32px;height:32px;border:1px solid #E5E7EB;background:#fff;border-radius:8px;cursor:pointer;font-size:17px;color:#475569;">⋮</button>'
              + '</td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>';
      wirePackageKebabs(el, rows);
      /* This table appears on a TAB SWITCH, which changes no hash — so without asking,
         its search box and sortable headers arrive only if the sweep bus happens to be
         alive. See KT.enhanceTables. */
      if (KT.enhanceTables) { KT.enhanceTables(); }
    }).catch(function (e) {
      el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>';
    });
  }

  /* View · Print · Download · Resend · Delete, on each send. */
  function wirePackageKebabs(el, rows) {
    var byId = {};
    rows.forEach(function (r) { byId[String(r.id)] = r; });

    el.querySelectorAll('.pk-kebab').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var r = byId[btn.getAttribute('data-id')];
        if (!r) { return; }

        openMenu(btn, function (item) {
          item('👁', 'View forms', false, function () { viewPackage(r.id); });
          item('🖨', 'Print summary', false, function () { printPackage(r.id); });
          item('⬇️', 'Download PDF', false, function () { downloadPackagePdf(r); });
          /* Absent, not disabled, when the send predates the ids being kept: an action
             that is visible and refuses is worse than one that was never offered. */
          if (r.can_resend) {
            /* TWO DIFFERENT SENDS, named for what they do rather than for the endpoint.
               One chases what is missing; the other posts on what is finished. Labelled
               with the count so nobody has to open the row to find out whether pressing
               it would do anything. */
            var outstanding = r.slots ? (r.slots - (r.signed || 0)) : 0;
            if (outstanding > 0) {
              item('🔔', 'Send reminder (' + outstanding + ' unsigned)', false, function () { resendPackage(r, el); });
            }
            if (r.signed > 0) {
              item('📤', 'Resend ' + r.signed + ' completed copy(ies)', false, function () { redeliverPackage(r); });
            }
          }
          item('🗑', 'Delete from history', true, function () { deletePackage(r, el); });
        });
      });
    });
  }

  /* The three read-only views share one fetch and one renderer, so Print cannot show
     something View does not. */
  function packageSheetHtml(d) {
    /* d.id is the send this table belongs to — the per-form resend posts against it, so
       the server can check the pairing really is part of this package. */
    var forms = (d.forms || []);
    var people = (d.recipients || []);
    var totalSlots = forms.length * people.length;
    var doneSlots = 0;
    people.forEach(function (p) {
      (p.forms || []).forEach(function (f) { if (f.signed_at) { doneSlots++; } });
    });

    var h = '<div style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;color:#334155;margin-bottom:16px;">'
      + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Sent</div>'
      +   esc(fmtStamp(d.sent_at)) + '</div>'
      + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">By</div>'
      +   esc(d.sent_by || '—') + '</div>'
      + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Emailed</div>'
      +   (d.notified ? esc(String(d.emailed)) + ' of ' + people.length : 'not sent') + '</div>'
      + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Signed</div>'
      +   doneSlots + ' of ' + totalSlots + '</div>'
      + '</div>';

    if (d.note) {
      h += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 14px;'
        + 'font-size:13px;color:#334155;margin-bottom:16px;"><strong>Note sent with it:</strong> '
        + esc(d.note) + '</div>';
    }

    h += '<div style="font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Forms in this package</div>';
    h += '<div style="border:1px solid #E5E7EB;border-radius:10px;overflow:hidden;margin-bottom:18px;">';
    forms.forEach(function (f, i) {
      h += '<div style="display:flex;gap:10px;align-items:center;padding:9px 13px;font-size:13px;'
        + (i ? 'border-top:1px solid #F1F5F9;' : '') + '">'
        + '<span style="flex:1;min-width:0;font-weight:600;color:#111827;">' + esc(f.title) + '</span>'
        /* A form withdrawn since the send still belongs in the record of it — saying so
           is the difference between a history and a list of things that happen to exist. */
        + (f.still_here
            ? (f.active ? '' : '<span style="font-size:11px;color:#B45309;background:#FEF3C7;border-radius:999px;padding:1px 8px;">archived</span>')
            : '<span style="font-size:11px;color:#B91C1C;background:#FEE2E2;border-radius:999px;padding:1px 8px;">deleted since</span>')
        + '<span style="font-size:12px;color:#64748B;white-space:nowrap;">' + f.signed + ' signed</span>'
        + '</div>';
    });
    h += '</div>';

    h += '<div style="font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Who it went to</div>';
    h += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12.5px;">'
      + '<thead><tr style="background:#F9FAFB;">'
      + '<th style="text-align:left;padding:8px 12px;font-size:11px;color:#64748B;text-transform:uppercase;">Person</th>'
      + forms.map(function (f) {
          return '<th style="text-align:left;padding:8px 12px;font-size:11px;color:#64748B;text-transform:uppercase;">' + esc(f.title) + '</th>';
        }).join('')
      + '</tr></thead><tbody>';
    people.forEach(function (p) {
      h += '<tr style="border-top:1px solid #F3F4F6;">'
        + '<td style="padding:9px 12px;"><div style="font-weight:600;color:#111827;">' + esc(p.name || p.email || '—') + '</div>'
        + (p.email ? '<div style="font-size:11px;color:#94A3B8;">' + esc(p.email) + '</div>' : '') + '</td>'
        /* A SIGNED CELL IS THE DOCUMENT. Clicking it opens the completed PDF — the
           returned form is the thing an admin came to this row to look at, and until now
           the only route to it was the Completed tab and a second search. Cells with no
           signature stay inert; there is nothing behind them yet. */
        + (p.forms || []).map(function (f, fi) {
            /* NOT BACK YET → the one control that helps: send THIS form to THIS person
               again. Chasing the whole package because one person's one form went astray
               is how a reminder turns into noise everybody learns to ignore. */
            if (!f.signed_at) {
              var fid = ((d.forms || [])[fi] || {}).id;
              return '<td style="padding:9px 12px;white-space:nowrap;">'
                + ((fid && p.id)
                    ? '<button type="button" class="pkv-again" data-f="' + esc(fid) + '" data-u="' + esc(p.id) + '"'
                      + ' data-t="' + esc(f.form) + '" data-n="' + esc(p.name || p.email || 'them') + '"'
                      + ' title="Send this form to this person again"'
                      + ' style="background:none;border:0;padding:0;font:inherit;color:#1F6FB2;font-weight:700;cursor:pointer;text-decoration:underline;">Send again</button>'
                    : '<span style="color:#94A3B8;">—</span>')
                + '</td>';
            }
            return '<td style="padding:9px 12px;white-space:nowrap;">'
              + (f.file_url
                  ? '<button type="button" class="pkv-open" data-u="' + esc(f.file_url) + '" data-t="' + esc((p.name || '') + ' — ' + f.form) + '"'
                    + ' title="Open the completed form"'
                    + ' style="background:none;border:0;padding:0;font:inherit;color:#16A34A;font-weight:700;cursor:pointer;text-decoration:underline;">'
                    + '✓ ' + esc(fmtStamp(f.signed_at)) + '</button>'
                  /* Signed as a read-and-sign notice: there is no filled PDF, and saying
                     so beats a link that opens a blank form. */
                  : '<span style="color:#16A34A;font-weight:700;" title="Signed as a read-and-sign notice — no filled PDF">✓ ' + esc(fmtStamp(f.signed_at)) + '</span>')
              + '</td>';
          }).join('')
        + '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
  }

  function viewPackage(id) {
    var sheet = openSheet(
      '<div style="font-size:17px;font-weight:800;color:#0F172A;">📦 Package sent</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">What went out, to whom, and who has signed since.</div>',
      '<div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div>', true);

    Api.get('/admin/managed-forms/packages/' + id).then(function (r) {
      var d = (r && r.send) || {};
      sheet.body.innerHTML = packageSheetHtml(d);
      sheet.body.querySelectorAll('.pkv-open').forEach(function (b) {
        b.addEventListener('click', function () {
          openPdfPopup(fileUrl(b.getAttribute('data-u')), b.getAttribute('data-t'));
        });
      });
      sheet.body.querySelectorAll('.pkv-again').forEach(function (b) {
        b.addEventListener('click', function () {
          var msg = 'Send “' + b.getAttribute('data-t') + '” to ' + b.getAttribute('data-n') + ' again?';
          Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
            if (!ok) { return; }
            b.disabled = true; b.textContent = 'Sending…';
            Api.post('/admin/managed-forms/packages/' + id + '/resend-one', {
              form_id: Number(b.getAttribute('data-f')),
              user_id: Number(b.getAttribute('data-u')),
            }).then(function (r) {
              b.textContent = 'Sent ✓';
              b.style.color = '#16A34A';
              b.style.textDecoration = 'none';
              toast('📤', 'Sent again', (r && r.message) || '', '#16A34A');
            }).catch(function (e) {
              b.disabled = false; b.textContent = 'Send again';
              toast('⚠️', 'Could not send', (e && e.message) || '', '#B91C1C');
            });
          });
        });
      });
      /* One form, one person, one signature: open it straight away. Making somebody hunt
         for the single link on the page is the kind of small friction that makes a
         feature feel unfinished. */
      var only = onlyCompleted(d);
      if (only) { openPdfPopup(fileUrl(only.url), only.title); }
    }).catch(function (e) {
      sheet.body.innerHTML = '<div style="padding:20px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>';
    });
  }

  function printPackage(id) {
    Api.get('/admin/managed-forms/packages/' + id).then(function (r) {
      var d = (r && r.send) || {};
      /* Printed from a hidden iframe rather than a popup window: a blocker kills
         window.open() that is not a direct click, and the click here is on a menu item
         that has already closed by the time the fetch resolves. */
      var f = document.createElement('iframe');
      f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
      document.body.appendChild(f);
      var doc = f.contentDocument;
      doc.open();
      doc.write('<!doctype html><html><head><meta charset="utf-8"><title>Package sent '
        + esc(fmtStamp(d.sent_at)) + '</title>'
        + '<style>body{font:13px/1.5 system-ui,-apple-system,sans-serif;color:#0F172A;padding:24px;}'
        + 'table{width:100%;border-collapse:collapse;} th,td{text-align:left;}'
        + '@page{margin:14mm;}</style></head><body>'
        + '<h1 style="font-size:19px;margin:0 0 14px;">📦 Package sent</h1>'
        + packageSheetHtml(d)
        + '</body></html>');
      doc.close();
      f.contentWindow.focus();
      f.contentWindow.print();
      // Left in place briefly: removing it during print cancels the job in some browsers.
      setTimeout(function () { f.remove(); }, 60000);
    }).catch(function (e) {
      toast('⚠️', 'Could not print', (e && e.message) || '', '#B91C1C');
    });
  }

  /* The one completed copy, when there is exactly one. */
  function onlyCompleted(d) {
    var hits = [];
    (d.recipients || []).forEach(function (p) {
      (p.forms || []).forEach(function (f) {
        if (f.signed_at && f.file_url) { hits.push({ url: f.file_url, title: (p.name || '') + ' — ' + f.form }); }
      });
    });
    return hits.length === 1 ? hits[0] : null;
  }

  /* pdf-lib, borrowed from the filler rather than loaded a second way. It is already the
     library this screen's forms are written with, and a second copy from a second CDN is
     a second thing to break. */
  function pdfLib() {
    if (window.PDFLib) { return Promise.resolve(window.PDFLib); }
    return new Promise(function (res, rej) {
      var src = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js';
      var ex = document.querySelector('script[data-kt-lib="' + src + '"]');
      if (ex) { ex.addEventListener('load', function () { res(window.PDFLib); }); ex.addEventListener('error', rej); return; }
      var sc = document.createElement('script');
      sc.src = src; sc.async = true; sc.setAttribute('data-kt-lib', src);
      sc.onload = function () { res(window.PDFLib); };
      sc.onerror = function () { rej(new Error('Could not load the PDF tools.')); };
      document.head.appendChild(sc);
    });
  }

  /* EVERY COMPLETED FORM IN THE PACKAGE, AS ONE PDF.

     Was a CSV, which was the wrong reading of "download": what a package produces is
     signed documents, and a spreadsheet of dates is not one of them. The completed copies
     are merged client-side with pdf-lib — the host has no PDF library, which is also why
     the filler flattens in the browser — so a package of four forms downloads as one file
     in the order the summary lists them.

     Falls back to the blank forms when nothing has been signed yet, because "download the
     pack so I can print it" is the other real reason to press this. */
  function downloadPackagePdf(r) {
    toast('⬇️', 'Preparing PDF', 'Collecting the forms…', '#1F6080');
    Api.get('/admin/managed-forms/packages/' + r.id).then(function (res) {
      var d = (res && res.send) || {};
      var items = [];
      (d.recipients || []).forEach(function (p) {
        (p.forms || []).forEach(function (f) {
          if (f.signed_at && f.file_url) { items.push(f.file_url); }
        });
      });
      var completed = items.length;
      if (!completed) {
        (d.forms || []).forEach(function (f) { if (f.file_url) { items.push(f.file_url); } });
      }
      if (!items.length) {
        toast('⚠️', 'Nothing to download', 'No files are attached to this package.', '#B45309');
        return;
      }

      return pdfLib().then(function (PDFLib) {
        return PDFLib.PDFDocument.create().then(function (out) {
          var chain = Promise.resolve();
          var added = 0;
          items.forEach(function (u) {
            chain = chain.then(function () {
              return fetch(fileUrl(u)).then(function (resp) {
                if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
                return resp.arrayBuffer();
              }).then(function (buf) {
                return PDFLib.PDFDocument.load(buf, { ignoreEncryption: true });
              }).then(function (src) {
                return out.copyPages(src, src.getPageIndices()).then(function (pages) {
                  pages.forEach(function (pg) { out.addPage(pg); });
                  added++;
                });
              }).catch(function () {
                /* One unreadable file must not lose the other three. Counted, and
                   reported at the end rather than as a popup per failure. */
              });
            });
          });
          return chain.then(function () {
            if (!added) { throw new Error('None of the files could be read.'); }
            return out.save().then(function (bytes) {
              var name = (completed ? 'completed-forms-' : 'forms-') + r.id + '-'
                + String(r.sent_at || '').slice(0, 10) + '.pdf';
              var blob = new Blob([bytes], { type: 'application/pdf' });
              var a = document.createElement('a');
              a.href = URL.createObjectURL(blob);
              a.download = name;
              document.body.appendChild(a);
              a.click();
              setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 30000);
              toast('✅', 'Downloaded', added + ' form(s) in one PDF'
                + (added < items.length ? ' · ' + (items.length - added) + ' could not be read' : '')
                + (completed ? '' : ' — blank copies, nothing signed yet'), '#16A34A');
            });
          });
        });
      });
    }).catch(function (e) {
      toast('⚠️', 'Could not download', (e && e.message) || '', '#B91C1C');
    });
  }

  /* Post the finished copies on — to the agency and back to whoever signed each one. */
  function redeliverPackage(r) {
    var msg = 'Send the ' + (r.signed || 0) + ' completed form(s) again?\n\n'
      + 'Each goes to the agency’s admins and directors, and a copy back to the person who signed it.';
    Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
      if (!ok) { return; }
      Api.post('/admin/managed-forms/packages/' + r.id + '/redeliver', {}).then(function (d) {
        toast('📤', 'Sent', (d && d.message) || '', '#16A34A');
      }).catch(function (e) {
        toast('⚠️', 'Could not send', (e && e.message) || '', '#B91C1C');
      });
    });
  }


  function resendPackage(r, el) {
    var who = (r.recipients || []).map(function (p) { return p.name || p.email; }).filter(Boolean);
    var msg = 'Email this package again to ' + (who.length > 3
      ? who.slice(0, 3).join(', ') + ' and ' + (who.length - 3) + ' more'
      : who.join(', ')) + '?\n\nAnyone who has already signed everything in it is skipped.';

    Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
      if (!ok) { return; }
      Api.post('/admin/managed-forms/packages/' + r.id + '/resend', {}).then(function (d) {
        toast('📤', 'Sent again', (d && d.message) || '', '#16A34A');
        loadPackageHistory(el);
      }).catch(function (e) {
        toast('⚠️', 'Could not resend', (e && e.message) || '', '#B91C1C');
      });
    });
  }

  function deletePackage(r, el) {
    /* Says exactly what survives. "Delete" on a history row reads like it might undo the
       send, and somebody pressing it deserves to know it does not. */
    var msg = 'Remove this send from the history?\n\nThe forms, the assignments and any '
      + 'signatures stay exactly as they are — this only removes the record that it was sent.';
    Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
      if (!ok) { return; }
      Api.delete('/admin/managed-forms/packages/' + r.id).then(function (d) {
        toast('🗑️', 'Removed', (d && d.message) || '', '#B91C1C');
        loadPackageHistory(el);
      }).catch(function (e) {
        toast('⚠️', 'Could not remove', (e && e.message) || '', '#B91C1C');
      });
    });
  }

  /* ───────── REQUEST FILES: the other direction ─────────

     A form package sends a document out for somebody to fill in. This asks for documents
     they already have — an immunisation card, both sides of an ID, last year's tax slip —
     which until now happened over email, so "what did we ask for and what is still
     missing" could only be answered by reading a thread.

     A request is a LIST of lines, each with its own description, kind and quantity, and
     the recipient gets a page with one button per line. Everything that arrives is filed
     on their own record, so it shows up in their Documents screen and on their user record
     with no separate place to look. (Anthony, 2026-09-10) */

  var PRI = {
    low:    ['Low', '#64748B', '#F1F5F9', '#E2E8F0'],
    normal: ['Normal', '#1E40AF', '#EFF6FF', '#BFDBFE'],
    high:   ['High', '#B45309', '#FEF3C7', '#FDE68A'],
    urgent: ['Urgent', '#B91C1C', '#FEE2E2', '#FECACA'],
  };
  function priChip(p) {
    var v = PRI[p] || PRI.normal;
    return '<span style="font-size:11px;font-weight:800;color:' + v[1] + ';background:' + v[2]
      + ';border:1px solid ' + v[3] + ';border-radius:999px;padding:2px 9px;white-space:nowrap;">' + v[0] + '</span>';
  }

  function renderFileRequests(body) {
    body.innerHTML =
      '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">'
      +   '<div><div style="font-size:17px;font-weight:800;color:#0F172A;">📥 Request files</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">Ask a parent or a staff member for documents. They upload from a link — no password — and everything lands on their record.</div></div>'
      + '<button id="fr-open" type="button" data-kt-iconized="1" style="margin-left:auto;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:13.5px;cursor:pointer;">+ Request files</button>'
      + '</div>'
      + '<div id="fr-list"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';

    body.querySelector('#fr-open').addEventListener('click', function () { openFileRequestDialog(body); });
    loadFileRequests(body.querySelector('#fr-list'));
  }

  function loadFileRequests(el) {
    Api.get('/admin/file-requests').then(function (d) {
      var rows = (d && d.requests) || [];
      if (!rows.length) {
        el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">Nothing requested yet. Use <strong>+ Request files</strong> above.</div>';
        return;
      }
      var th = function (t) {
        return '<th style="text-align:left;padding:10px 14px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">' + t + '</th>';
      };
      el.innerHTML = '<div style="overflow-x:auto;background:#fff;border:1px solid #E5E7EB;border-radius:12px;">'
        + '<table data-kt-no-kebab="1" data-kt-paginate="25" data-kt-filter-always="1" style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F9FAFB;">'
        +   th('Requested') + th('From') + th('What') + th('Received') + th('Priority') + th('Due') + th('By') + th('')
        + '</tr></thead><tbody>'
        + rows.map(function (r) {
            var pct = r.asked ? r.received / r.asked : -1;
            var got = !r.asked
              ? '<span style="color:#CBD5E1;">—</span>'
              : (r.received >= r.asked
                  ? '<span style="font-size:11px;font-weight:800;color:#166534;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:999px;padding:2px 9px;white-space:nowrap;">✓ All ' + r.asked + '</span>'
                  : '<span style="font-size:11px;font-weight:800;color:' + (r.received ? '#B45309' : '#64748B') + ';background:' + (r.received ? '#FEF3C7' : '#F1F5F9') + ';border:1px solid ' + (r.received ? '#FDE68A' : '#E2E8F0') + ';border-radius:999px;padding:2px 9px;white-space:nowrap;">' + r.received + ' of ' + r.asked + '</span>');
            /* An overdue request says so. A due date that has passed and looks like every
               other due date is a due date nobody acts on. */
            var overdue = r.due_on && r.status !== 'complete' && String(r.due_on).slice(0, 10) < new Date().toISOString().slice(0, 10);
            return '<tr style="border-top:1px solid #F3F4F6;vertical-align:top;">'
              + '<td data-kt-sort="' + esc(String(r.created_at || '')) + '" style="padding:11px 14px;white-space:nowrap;color:#374151;">' + esc(fmtStamp(r.created_at)) + '</td>'
              + '<td data-kt-sort="' + esc(String(r.person || '').toLowerCase()) + '" style="padding:11px 14px;"><div style="font-weight:600;color:#111827;">' + esc(r.person) + '</div>'
              +   (r.email ? '<div style="font-size:11.5px;color:#94A3B8;">' + esc(r.email) + '</div>' : '') + '</td>'
              + '<td data-kt-sort="' + esc(String(r.items)) + '" style="padding:11px 14px;color:#334155;white-space:nowrap;">' + r.items + ' item' + (r.items === 1 ? '' : 's') + '</td>'
              + '<td data-kt-sort="' + esc(pct.toFixed(4)) + '" style="padding:11px 14px;white-space:nowrap;">' + got + '</td>'
              + '<td data-kt-sort="' + esc(({ urgent: '3', high: '2', normal: '1', low: '0' })[r.priority] || '1') + '" style="padding:11px 14px;white-space:nowrap;">' + priChip(r.priority) + '</td>'
              + '<td data-kt-sort="' + esc(String(r.due_on || '9999')) + '" style="padding:11px 14px;white-space:nowrap;color:' + (overdue ? '#B91C1C;font-weight:700' : '#475569') + ';">'
              +   (r.due_on ? esc(String(r.due_on).slice(0, 10)) + (overdue ? ' · overdue' : '') : '<span style="color:#CBD5E1;">—</span>') + '</td>'
              + '<td data-kt-sort="' + esc(String(r.requested_by || '').toLowerCase()) + '" style="padding:11px 14px;color:#475569;white-space:nowrap;">' + esc(r.requested_by || '—') + '</td>'
              + '<td style="padding:11px 8px;text-align:right;">'
              +   '<button class="fr-kebab" data-id="' + r.id + '" type="button" data-kt-iconized="1" title="Actions" style="width:32px;height:32px;border:1px solid #E5E7EB;background:#fff;border-radius:8px;cursor:pointer;font-size:17px;color:#475569;">⋮</button>'
              + '</td></tr>';
          }).join('')
        + '</tbody></table></div>';

      var byId = {};
      rows.forEach(function (r) { byId[String(r.id)] = r; });
      el.querySelectorAll('.fr-kebab').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var r = byId[btn.getAttribute('data-id')];
          if (!r) { return; }
          openMenu(btn, function (item) {
            item('👁', 'View files', false, function () { viewFileRequest(r.id); });
            if (r.received > 0) {
              item('⬇️', 'Download ' + r.received + ' file(s)', false, function () { downloadFileRequest(r.id); });
            }
            if (r.received < r.asked) {
              item('🔔', 'Send reminder', false, function () { remindFileRequest(r, el); });
            }
            item('🗑', 'Delete request', true, function () { deleteFileRequest(r, el); });
          });
        });
      });

      if (KT.enhanceTables) { KT.enhanceTables(); }
    }).catch(function (e) {
      el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>';
    });
  }

  function frItemsHtml(d) {
    return (d.items || []).map(function (it) {
      var files = it.files || [];
      return '<div style="border:1px solid ' + (it.done ? '#BBF7D0' : '#E2E8F0') + ';background:' + (it.done ? '#F0FDF4' : '#fff')
        + ';border-radius:11px;padding:12px 14px;margin-bottom:9px;">'
        + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
        +   '<div style="font-weight:700;color:#111827;font-size:13.5px;">' + esc(it.description) + '</div>'
        +   '<span style="font-size:11.5px;color:#64748B;">' + files.length + ' of ' + it.quantity + '</span>'
        +   (it.done ? '<span style="font-size:11px;font-weight:800;color:#166534;">✓ complete</span>' : '')
        + '</div>'
        + (files.length
            ? '<div style="margin-top:8px;display:flex;flex-direction:column;gap:5px;">'
              + files.map(function (f) {
                  /* BOTH VERBS. Somebody checking a document wants to look at it; somebody
                     filing it with a licensing body wants it on disk. Offering only "Open"
                     made the second person right-click and save from a PDF viewer.

                     Both go through /admin/file-requests/{id}/files/{doc}/download rather
                     than the raw /storage path: these are ID documents and immunisation
                     cards, and possession of a URL is not authorisation to read one. */
                  return '<div style="display:flex;align-items:center;gap:9px;font-size:12.5px;">'
                    + '<span>' + (/pdf/i.test(f.file_type || '') ? '📄' : /image/i.test(f.file_type || '') ? '🖼️' : '📎') + '</span>'
                    + '<span style="flex:1;min-width:0;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(f.title) + '</span>'
                    + '<span style="color:#94A3B8;white-space:nowrap;">' + esc(frSize(f.file_size)) + '</span>'
                    + '<button type="button" class="frv-open" data-d="' + esc(f.id) + '" data-t="' + esc(f.title) + '" data-type="' + esc(f.file_type || '') + '"'
                    + ' style="background:none;border:0;padding:0;font:inherit;color:#1F6080;font-weight:700;cursor:pointer;text-decoration:underline;">View</button>'
                    + '<button type="button" class="frv-dl" data-d="' + esc(f.id) + '" data-t="' + esc(f.title) + '"'
                    + ' style="background:none;border:0;padding:0;font:inherit;color:#475569;font-weight:700;cursor:pointer;text-decoration:underline;">Download</button>'
                    + '</div>';
                }).join('')
              + '</div>'
            : '<div style="margin-top:6px;font-size:12.5px;color:#94A3B8;">Nothing sent yet.</div>')
        + '</div>';
    }).join('') || '<div style="color:#94A3B8;font-size:13px;">No items.</div>';
  }

  function frSize(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return Math.round(n / 1024) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /**
   * Fetch one requested file through the API, then either show it or save it.
   *
   * Streamed with the bearer token rather than linked at its /storage path — the endpoint
   * checks the document really belongs to this request and that the caller is an admin
   * here. A blob URL then serves both verbs from one download, so viewing and then saving
   * does not fetch somebody's passport twice.
   */
  function frFetchFile(reqId, btn, save) {
    var was = btn.textContent;
    btn.disabled = true;
    btn.textContent = save ? 'Saving…' : 'Opening…';
    /* Opened on the click, before any await — a popup blocker rejects a window.open()
       that is not a direct result of one. Not needed for a save. */
    var win = save ? null : window.open('', '_blank');

    fetch(API_HOST + '/api/v1/admin/file-requests/' + reqId + '/files/' + btn.getAttribute('data-d') + '/download', {
      headers: {
        Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''),
        'X-Active-Agency-Id': sessionStorage.getItem('kt_active_agency_id') || '',
      },
    }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.blob();
    }).then(function (blob) {
      var u = URL.createObjectURL(blob);
      if (save) {
        var a = document.createElement('a');
        a.href = u;
        a.download = String(btn.getAttribute('data-t') || 'file').replace(/[^A-Za-z0-9._ -]+/g, '-');
        document.body.appendChild(a);
        a.click();
        a.remove();
      } else if (win) {
        win.location = u;
      } else {
        window.open(u, '_blank');
      }
      setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
    }).catch(function (e) {
      if (win) { win.close(); }
      toast('⚠️', save ? 'Could not download' : 'Could not open', (e && e.message) || '', '#B91C1C');
    }).finally(function () {
      btn.disabled = false;
      btn.textContent = was;
    });
  }

  function viewFileRequest(id) {
    var sheet = openSheet(
      '<div style="font-size:17px;font-weight:800;color:#0F172A;">📥 Files requested</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">What was asked for, and what has arrived.</div>',
      '<div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div>', true);

    Api.get('/admin/file-requests/' + id).then(function (r) {
      var d = (r && r.request) || {};
      sheet.body.innerHTML =
        '<div style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;color:#334155;margin-bottom:16px;">'
        + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">From</div>' + esc(d.person || '—') + '</div>'
        + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Requested by</div>' + esc(d.requested_by || '—') + '</div>'
        + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Priority</div>' + priChip(d.priority) + '</div>'
        + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Due</div>' + (d.due_on ? esc(String(d.due_on).slice(0, 10)) : '—') + '</div>'
        + '<div><div style="font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;">Received</div>' + (d.received || 0) + ' of ' + (d.asked || 0) + '</div>'
        + '</div>'
        + (d.note ? '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 14px;font-size:13px;color:#334155;margin-bottom:16px;"><strong>Note:</strong> ' + esc(d.note) + '</div>' : '')
        + frItemsHtml(d);

      sheet.body.querySelectorAll('.frv-open').forEach(function (b) {
        b.addEventListener('click', function () { frFetchFile(id, b, false); });
      });
      sheet.body.querySelectorAll('.frv-dl').forEach(function (b) {
        b.addEventListener('click', function () { frFetchFile(id, b, true); });
      });
    }).catch(function (e) {
      sheet.body.innerHTML = '<div style="padding:20px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>';
    });
  }

  /* Every file this person sent, one after another. Mixed types (a PDF and two photos)
     cannot be merged into one document, so they arrive as separate downloads — spaced out,
     because a browser treats six at once as a popup storm and blocks most of them. */
  function downloadFileRequest(id) {
    Api.get('/admin/file-requests/' + id).then(function (r) {
      var d = (r && r.request) || {};
      var files = [];
      (d.items || []).forEach(function (it) { (it.files || []).forEach(function (f) { files.push(f); }); });
      if (!files.length) {
        toast('⚠️', 'Nothing to download', 'No files have been sent yet.', '#B45309');
        return;
      }
      toast('⬇️', 'Downloading', files.length + ' file(s)…', '#1F6080');
      /* One at a time, through the authorised route, and SEQUENTIALLY — six parallel
         saves read as a popup storm and a browser blocks most of them. */
      var chain = Promise.resolve();
      var ok = 0;
      files.forEach(function (f) {
        chain = chain.then(function () {
          return fetch(API_HOST + '/api/v1/admin/file-requests/' + id + '/files/' + f.id + '/download', {
            headers: {
              Authorization: 'Bearer ' + (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''),
              'X-Active-Agency-Id': sessionStorage.getItem('kt_active_agency_id') || '',
            },
          }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.blob();
          }).then(function (blob) {
            var u = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = u;
            a.download = String(f.title || 'file').replace(/[^A-Za-z0-9._ -]+/g, '-');
            document.body.appendChild(a);
            a.click();
            a.remove();
            ok++;
            setTimeout(function () { URL.revokeObjectURL(u); }, 60000);
          }).catch(function () {
            /* One unreadable file must not lose the rest; counted and reported at the end. */
          });
        });
      });
      return chain.then(function () {
        if (ok < files.length) {
          toast('⚠️', 'Some files failed', ok + ' of ' + files.length + ' downloaded.', '#B45309');
        }
      });
    }).catch(function (e) {
      toast('⚠️', 'Could not download', (e && e.message) || '', '#B91C1C');
    });
  }

  function remindFileRequest(r, el) {
    var msg = 'Send ' + (r.person || 'them') + ' a reminder about the '
      + (r.asked - r.received) + ' item(s) still outstanding?';
    Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
      if (!ok) { return; }
      Api.post('/admin/file-requests/' + r.id + '/remind', {}).then(function (d) {
        toast('🔔', 'Reminder sent', (d && d.message) || '', '#16A34A');
        loadFileRequests(el);
      }).catch(function (e) {
        toast('⚠️', 'Could not send', (e && e.message) || '', '#B91C1C');
      });
    });
  }

  function deleteFileRequest(r, el) {
    /* Says what survives. Deleting the ASK must not read as deleting the documents
       somebody has already handed over in good faith. */
    var msg = 'Delete this request?\n\nAny files ' + (r.person || 'they') + ' already sent stay on their record — '
      + 'only the request itself is removed.';
    Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
      if (!ok) { return; }
      Api.delete('/admin/file-requests/' + r.id).then(function (d) {
        toast('🗑️', 'Deleted', (d && d.message) || '', '#B91C1C');
        loadFileRequests(el);
      }).catch(function (e) {
        toast('⚠️', 'Could not delete', (e && e.message) || '', '#B91C1C');
      });
    });
  }

  /* The ask itself. Recipient, priority, due date, note, and the list of what is wanted. */
  function openFileRequestDialog(hostBody) {
    var LBL2 = 'display:block;font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;';
    var IN = 'width:100%;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:13.5px;box-sizing:border-box;font-family:inherit;';

    var sheet = openSheet(
      '<div style="font-size:17px;font-weight:800;color:#0F172A;">📥 Request files</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">They get one email with a link, and a button for each thing you ask for.</div>',
      '<div style="display:grid;gap:14px;">'
      + '<div><label style="' + LBL2 + '">Ask</label>'
      +   '<select id="fr-user" style="' + IN + 'background:#fff;"><option value="">Loading people…</option></select>'
      +   '<div style="font-size:12px;color:#64748B;margin-top:6px;">…or type an address instead</div>'
      +   '<input id="fr-email" type="email" placeholder="name@example.com" style="' + IN + 'margin-top:4px;"></div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
      +   '<div><label style="' + LBL2 + '">Priority</label><select id="fr-pri" style="' + IN + 'background:#fff;">'
      +     '<option value="low">Low</option><option value="normal" selected>Normal</option>'
      +     '<option value="high">High</option><option value="urgent">Urgent</option></select></div>'
      +   '<div><label style="' + LBL2 + '">Needed by</label><input id="fr-due" type="date" style="' + IN + '"></div>'
      + '</div>'
      + '<div><label style="' + LBL2 + '">Note (optional)</label>'
      +   '<textarea id="fr-note" rows="2" placeholder="Anything they should know — where to find it, why you need it…" style="' + IN + 'resize:vertical;"></textarea></div>'
      + '<div><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">'
      +   '<label style="' + LBL2 + 'margin:0;">What do you need?</label>'
      +   '<button id="fr-add" type="button" style="margin-left:auto;background:#fff;border:1.5px solid #BFDBFE;color:#1F6FB2;border-radius:9px;padding:6px 13px;font-size:12.5px;font-weight:800;cursor:pointer;">+ Add another</button>'
      + '</div><div id="fr-items"></div></div>'
      + '<div id="fr-msg" style="font-size:13px;color:#B91C1C;min-height:18px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #EEF2F7;padding-top:14px;">'
      +   '<label style="display:flex;align-items:center;gap:8px;margin-right:auto;font-size:13px;font-weight:600;color:#334155;cursor:pointer;">'
      +     '<input id="fr-notify" type="checkbox" checked style="width:17px;height:17px;accent-color:#1F6080;"> Email them now</label>'
      +   '<button id="fr-send" type="button" style="background:#1F6080;border:0;color:#fff;border-radius:10px;padding:11px 24px;font-size:14px;font-weight:800;cursor:pointer;">Send request</button>'
      + '</div></div>');

    var items = [{ description: '', kind: 'any', quantity: 1 }];
    var itemsEl = sheet.body.querySelector('#fr-items');

    function paintItems() {
      itemsEl.innerHTML = items.map(function (it, i) {
        return '<div style="display:grid;grid-template-columns:1fr 130px 78px 34px;gap:8px;align-items:center;margin-bottom:8px;">'
          + '<input class="fri-d" data-i="' + i + '" placeholder="e.g. Immunisation card" value="' + esc(it.description) + '" style="' + IN + '">'
          + '<select class="fri-k" data-i="' + i + '" style="' + IN + 'background:#fff;">'
          +   ['any:Any file', 'pdf:PDF', 'image:Photo/scan', 'document:Word doc'].map(function (o) {
                var p = o.split(':');
                return '<option value="' + p[0] + '"' + (it.kind === p[0] ? ' selected' : '') + '>' + p[1] + '</option>';
              }).join('')
          + '</select>'
          + '<input class="fri-q" data-i="' + i + '" type="number" min="1" max="20" value="' + esc(it.quantity) + '" title="How many files" style="' + IN + '">'
          + (items.length > 1
              ? '<button class="fri-x" data-i="' + i + '" type="button" title="Remove" style="background:none;border:0;color:#DC2626;font-size:16px;cursor:pointer;">✕</button>'
              : '<span></span>')
          + '</div>';
      }).join('');

      itemsEl.querySelectorAll('.fri-d').forEach(function (b) {
        b.addEventListener('input', function () { items[+b.getAttribute('data-i')].description = b.value; });
      });
      itemsEl.querySelectorAll('.fri-k').forEach(function (b) {
        b.addEventListener('change', function () { items[+b.getAttribute('data-i')].kind = b.value; });
      });
      itemsEl.querySelectorAll('.fri-q').forEach(function (b) {
        b.addEventListener('input', function () { items[+b.getAttribute('data-i')].quantity = Math.max(1, parseInt(b.value, 10) || 1); });
      });
      itemsEl.querySelectorAll('.fri-x').forEach(function (b) {
        b.addEventListener('click', function () { items.splice(+b.getAttribute('data-i'), 1); paintItems(); });
      });
    }
    paintItems();
    sheet.body.querySelector('#fr-add').addEventListener('click', function () {
      items.push({ description: '', kind: 'any', quantity: 1 });
      paintItems();
    });

    /* The picker only ever offers this agency's people — /admin/users is scoped
       server-side — and the typed address is re-checked there too. */
    Api.get('/admin/users').then(function (d) {
      var people = (d && (d.users || d.data)) || [];
      var sel = sheet.body.querySelector('#fr-user');
      sel.innerHTML = '<option value="">— choose a person —</option>'
        + people.map(function (u) {
            var nm = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || u.email || ('#' + u.id);
            return '<option value="' + u.id + '">' + esc(nm) + (u.email ? ' · ' + esc(u.email) : '') + '</option>';
          }).join('');
    }).catch(function () {
      sheet.body.querySelector('#fr-user').innerHTML = '<option value="">Could not load people — type an address instead</option>';
    });

    sheet.body.querySelector('#fr-send').addEventListener('click', function () {
      var msg = sheet.body.querySelector('#fr-msg');
      var clean = items.filter(function (i) { return (i.description || '').trim(); });
      if (!clean.length) { msg.textContent = 'Add at least one thing you need.'; return; }
      var uid = sheet.body.querySelector('#fr-user').value;
      var email = (sheet.body.querySelector('#fr-email').value || '').trim();
      if (!uid && !email) { msg.textContent = 'Choose a person, or type an address.'; return; }

      var btn = sheet.body.querySelector('#fr-send');
      btn.disabled = true; btn.textContent = 'Sending…';
      msg.style.color = '#64748B'; msg.textContent = '';

      Api.post('/admin/file-requests', {
        user_id: uid ? Number(uid) : null,
        email: email || null,
        priority: sheet.body.querySelector('#fr-pri').value,
        due_on: sheet.body.querySelector('#fr-due').value || null,
        note: (sheet.body.querySelector('#fr-note').value || '').trim() || null,
        notify: sheet.body.querySelector('#fr-notify').checked,
        items: clean.map(function (i) {
          return { description: i.description.trim(), kind: i.kind, quantity: i.quantity };
        }),
      }).then(function (r) {
        sheet.overlay.remove();
        toast('📥', 'Requested', (r && r.message) || '', '#16A34A');
        if (hostBody) { loadFileRequests(hostBody.querySelector('#fr-list')); }
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Send request';
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not send that request.';
      });
    });
  }

  /** The send dialog. Forms as a TICKABLE TABLE, people picked or typed. */
  function openPackageDialog(hostBody) {
    var stale = document.getElementById('pk-ov');
    if (stale && stale.parentNode) stale.parentNode.removeChild(stale);

    var ov = document.createElement('div');
    ov.id = 'pk-ov';
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479000;display:flex;align-items:flex-start;'
      + 'justify-content:center;padding:20px;overflow-y:auto;background:rgba(8,20,40,.55);';
    var card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:16px;max-width:760px;width:100%;margin:auto;'
      + 'box-shadow:0 30px 80px -20px rgba(8,20,40,.6);overflow:hidden;';
    ov.appendChild(card);

    card.innerHTML =
      '<div style="padding:18px 22px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;gap:12px;">'
      +   '<div style="min-width:0;"><div style="font-size:17px;font-weight:800;color:#0F172A;">📦 Send multiple forms</div>'
      +   '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">Everyone chosen is assigned every form ticked, and gets one email listing them all.</div></div>'
      +   '<button id="pk-x" type="button" aria-label="Close" data-kt-iconized="1" style="margin-left:auto;background:#F1F5F9;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;cursor:pointer;color:#475569;">✕</button>'
      + '</div>'
      + '<div style="padding:18px 22px;max-height:min(70vh,720px);overflow-y:auto;">'
      +   '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">'
      +     '<span style="' + LBL + 'margin:0;">Forms in this package</span>'
      +     '<button id="pk-upload" type="button" data-kt-iconized="1" style="margin-left:auto;background:#fff;border:1.5px solid #BFDBFE;color:#1F6FB2;border-radius:9px;padding:6px 13px;font-size:12.5px;font-weight:800;cursor:pointer;">+ Upload a form</button>'
      +   '</div>'
      +   '<div id="pk-forms" style="border:1.5px solid #E2E8F0;border-radius:12px;overflow:hidden;margin-bottom:6px;">'
      +     '<div style="padding:18px;text-align:center;color:#94A3B8;font-size:13px;">Loading forms…</div>'
      +   '</div>'
      +   '<div id="pk-fcount" style="font-size:12.5px;color:#64748B;margin-bottom:16px;">No forms chosen yet.</div>'
      +   peopleBlockHtml('pk', 'Everyone you tick here receives every form above.')
      +   '<div style="' + LBL + '">Or type email addresses</div>'
      +   '<input id="pk-emails" type="text" placeholder="anne@example.com, ben@example.com" style="' + FIELD + 'margin-bottom:4px;">'
      +   '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:16px;">Separate with commas. Each address must already have an account here — a form is signed while signed in, so there is nowhere to put a signature for an address with nobody behind it. Anything unrecognised is reported back, never silently dropped.</div>'
      +   '<div style="' + LBL + '">Add a note (optional)</div>'
      +   '<textarea id="pk-note" rows="2" placeholder="e.g. Please complete these before Monday." style="' + FIELD + 'resize:vertical;margin-bottom:14px;"></textarea>'
      +   toggleCardHtml('pk-notify', 'Email them now', 'Sends one email listing the forms, with a link to sign them. Untick to assign quietly — they will still see the forms in the portal.', true)
      + '</div>'
      + '<div style="padding:14px 22px;border-top:1px solid #EEF2F7;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#FCFDFE;">'
      +   '<button id="pk-send" type="button" style="background:#1F6080;border:none;color:#fff;border-radius:10px;padding:11px 24px;font-size:14px;font-weight:800;cursor:pointer;">Send package</button>'
      +   '<button id="pk-cancel" type="button" style="background:#F1F5F9;border:1px solid #CBD5E1;color:#334155;border-radius:10px;padding:11px 18px;font-size:13.5px;font-weight:700;cursor:pointer;">Cancel</button>'
      +   '<span id="pk-out" style="font-size:13px;"></span>'
      + '</div>';

    document.body.appendChild(ov);

    var chosenForms = {};
    var listEl = ov.querySelector('#pk-forms');
    var countEl = ov.querySelector('#pk-fcount');
    var out = ov.querySelector('#pk-out');
    var close = function () { if (ov.parentNode) ov.parentNode.removeChild(ov); };
    ov.querySelector('#pk-x').addEventListener('click', close);
    ov.querySelector('#pk-cancel').addEventListener('click', close);

    function say(msg, bad) { out.style.color = bad ? '#B91C1C' : '#15803D'; out.textContent = msg; }
    function paintCount() {
      var n = Object.keys(chosenForms).length;
      countEl.textContent = n === 0 ? 'No forms chosen yet.' : (n === 1 ? '1 form chosen.' : n + ' forms chosen.');
      countEl.style.color = n ? '#1E40AF' : '#64748B';
    }

    Api.get('/admin/managed-forms').then(function (d) {
      /* Only ACTIVE forms — an archived one still resolves, and putting it in a package
         would assign paperwork nobody intends to collect. */
      var forms = ((d && d.forms) || []).filter(function (f) { return f.active !== false && f.active !== 0; });
      if (!forms.length) {
        listEl.innerHTML = '<div style="padding:20px;text-align:center;color:#64748B;font-size:13px;">No active forms. Upload one first.</div>';
        return;
      }
      /* A TABLE with real tick boxes, not a row of switches: these are several
         independent choices out of a list, which is exactly what a checkbox column means.
         A toggle reads as "turn this feature on" and gives no column to scan down. */
      listEl.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F8FAFC;">'
        +   '<th style="width:42px;padding:9px 0 9px 14px;"><input type="checkbox" id="pk-all" title="Choose all" style="width:16px;height:16px;accent-color:#1F6080;cursor:pointer;"></th>'
        +   '<th style="text-align:left;padding:9px 10px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">Form</th>'
        +   '<th style="text-align:left;padding:9px 10px;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">What they do</th>'
        + '</tr></thead><tbody>'
        + forms.map(function (f) {
            var desc = (f.description || '').trim();
            /* What the RECIPIENT gets. A list of titles cannot tell the two apart, so a
               package of "forms to fill in" could quietly contain one nobody can type into. */
            var kind = f.fillable
              ? '<span style="display:inline-block;font-size:10.5px;font-weight:800;color:#0F766E;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:999px;padding:2px 8px;white-space:nowrap;">📝 Fill &amp; sign</span>'
              : '<span style="display:inline-block;font-size:10.5px;font-weight:800;color:#475569;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;padding:2px 8px;white-space:nowrap;">✍️ Sign only</span>';
            return '<tr class="pk-row" data-id="' + f.id + '" style="border-top:1px solid #F1F5F9;cursor:pointer;">'
              + '<td style="padding:10px 0 10px 14px;"><input type="checkbox" class="pk-f" data-id="' + f.id + '" style="width:16px;height:16px;accent-color:#1F6080;cursor:pointer;"></td>'
              + '<td style="padding:10px;"><div style="font-weight:700;color:#0F172A;">' + esc(f.title) + '</div>'
              +   (desc ? '<div style="font-size:12.5px;color:#64748B;margin-top:2px;line-height:1.45;">' + esc(desc) + '</div>' : '') + '</td>'
              + '<td style="padding:10px;">' + kind + '</td></tr>';
          }).join('')
        + '</tbody></table>';

      function setRow(c) {
        var id = c.getAttribute('data-id');
        if (c.checked) { chosenForms[id] = true; } else { delete chosenForms[id]; }
        var tr = c.closest('tr'); if (tr) tr.style.background = c.checked ? '#F0F9FF' : '';
      }
      listEl.querySelectorAll('.pk-f').forEach(function (c) {
        c.addEventListener('change', function () { setRow(c); paintCount(); });
      });
      // The whole row is the target — a 16px box is a poor thing to aim at.
      listEl.querySelectorAll('.pk-row').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
          if (e.target && e.target.classList && e.target.classList.contains('pk-f')) return;
          var c = tr.querySelector('.pk-f'); c.checked = !c.checked; setRow(c); paintCount();
        });
      });
      var all = listEl.querySelector('#pk-all');
      all.addEventListener('click', function (e) { e.stopPropagation(); });
      all.addEventListener('change', function () {
        listEl.querySelectorAll('.pk-f').forEach(function (c) { c.checked = all.checked; setRow(c); });
        paintCount();
      });

      if (PK_RESUME) {
        var want = PK_RESUME; PK_RESUME = null;
        listEl.querySelectorAll('.pk-f').forEach(function (c, i) {
          var id = c.getAttribute('data-id');
          if (want.ids.indexOf(id) !== -1 || (want.tickNewest && i === 0)) { c.checked = true; setRow(c); }
        });
        paintCount();
      }
    }).catch(function (e) {
      listEl.innerHTML = '<div style="padding:18px;color:#B91C1C;font-size:13px;">Could not load forms: ' + esc(e.message || '') + '</div>';
    });

    var picker = attachPeoplePicker(ov, {
      toggle: '#pk-rtoggle', panel: '#pk-rpicker', list: '#pk-rlist',
      search: '#pk-rsearch', count: '#pk-rcount'
    }, []);

    ov.querySelector('#pk-upload').addEventListener('click', function () {
      /* Hand off to the LIBRARY's upload dialog rather than building a second one: it owns
         the fillable toggle, the audiences and the people picker, and two upload forms
         would drift apart the moment either changed. */
      PK_RESUME = { ids: Object.keys(chosenForms), tickNewest: true };
      close();
      var libTab = document.querySelector('.fm-tab[data-t="library"]');
      if (libTab) { libTab.click(); }
      setTimeout(function () {
        var open = [].slice.call(document.querySelectorAll('button')).filter(function (b) {
          return /upload a form/i.test(b.textContent || '');
        })[0];
        if (open) { open.click(); }
      }, 500);
    });

    var notifyBox = ov.querySelector('#pk-notify');
    ov.querySelector('#pk-send').addEventListener('click', function () {
      var btn = this;
      var formIds = Object.keys(chosenForms).map(Number);
      var userIds = picker.ids().map(Number);
      var emails = (ov.querySelector('#pk-emails').value || '')
        .split(/[,;\s]+/).map(function (x) { return x.trim(); }).filter(Boolean);

      // Each half named separately — "nothing happened" is the least useful thing to say.
      if (!formIds.length) { say('Tick at least one form to send.', true); return; }
      if (!userIds.length && !emails.length) { say('Choose people, or type an email address.', true); return; }

      btn.disabled = true; say('Sending…', false);
      Api.post('/admin/managed-forms/bulk-assign', {
        form_ids: formIds, user_ids: userIds, emails: emails,
        notify: !notifyBox || notifyBox.checked,
        note: (ov.querySelector('#pk-note').value || '').trim() || null
      }).then(function (r) {
        btn.disabled = false;
        var un = (r && r.unmatched) || [];
        if (un.length) {
          /* Sent for the rest, and SAID so for the ones it could not reach. Closing on a
             success toast here would be a quiet lie about who was written to. */
          say((r.message || 'Sent.') + ' No account for: ' + un.join(', '), true);
          if (hostBody) loadPackageHistory(hostBody.querySelector('#pk-history'));
          return;
        }
        toast('📦', 'Package sent', (r && r.message) || '');
        close();
        if (hostBody) loadPackageHistory(hostBody.querySelector('#pk-history'));
      }).catch(function (e) {
        btn.disabled = false;
        /* The server's message already NAMES the addresses it could not place, so
           appending the list again read as "ghost@nowhere.test ... (ghost@nowhere.test)".
           Only add it when the message did not say it. */
        var msg = (e && e.message) || 'Could not send that package.';
        var un = (e && e.data && e.data.unmatched) || [];
        var missing = un.filter(function (a) { return msg.indexOf(a) === -1; });
        say(missing.length ? (msg + ' (' + missing.join(', ') + ')') : msg, true);
      });
    });
  }

  /* ───────── LIBRARY: upload + list ───────── */
  var FIELD = 'width:100%;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;background:#fff;color:#0F172A;font-family:inherit;';
  var LBL = 'display:block;font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.3px;margin:0 0 6px;';
  function renderLibrary(body) {
    // The upload panel is PORTALED to <body> further down, so that the scrim covers
    // the sidebar and top bar instead of sliding under them. Its listeners survive
    // the move — but they must not assume the panel is still inside #fm-body,
    // because after the move it is not. Every fq() for a field then
    // returned null: choosing a file threw before it could show the name, and
    // Upload did nothing at all, throwing on the first field it touched. Look
    // inside the container first, then anywhere in the document — the ids are
    // unique and any stale overlay is removed above.
    function fq(sel) { return body.querySelector(sel) || document.querySelector(sel); }
    function fqa(sel) { var n = body.querySelectorAll(sel); return n.length ? n : document.querySelectorAll(sel); }
    // The dialog now lives in <body>, so a re-render cannot dispose of it implicitly.
    var _stale = document.getElementById('fm-upload-ov');
    if (_stale && _stale.parentNode) _stale.parentNode.removeChild(_stale);

    body.innerHTML =
      '<div class="kt-card" style="background:#fff;border:1px solid #E7EBF0;border-radius:16px;padding:22px 24px;margin-bottom:18px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
      + '<div style="font-weight:800;font-size:15px;margin:0 0 4px;color:#0F172A;">⬆️ Upload a new form</div>'
      + '<div style="font-size:12.5px;color:#94A3B8;margin-bottom:18px;">Add a PDF, choose who signs it, then assign.</div>'
      + '<div style="display:flex;flex-direction:column;gap:16px;">'
      // Title
      + '<div><label for="fm-title" style="' + LBL + '">Form title</label>'
      + '<input id="fm-title" placeholder="e.g. Consent to photograph" style="' + FIELD + '"></div>'
      // Description
      + '<div><label for="fm-desc" style="' + LBL + '">Description</label>'
      + '<textarea id="fm-desc" placeholder="A short note about this form" rows="2" style="' + FIELD + 'resize:vertical;min-height:52px;"></textarea></div>'
      // Audience chips
      + '<div><label style="' + LBL + '">Who must sign it?</label>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
      + AUD.map(function (a) {
          return '<label class="fm-audchip" style="display:inline-flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:#334155;cursor:pointer;border:1.5px solid #E2E8F0;border-radius:999px;padding:8px 14px;user-select:none;transition:all .12s;">'
            + '<input type="checkbox" class="fm-aud" value="' + a[0] + '" style="accent-color:#1F6FB2;width:16px;height:16px;margin:0;">'
            + '<span>' + a[2] + ' ' + a[1] + '</span></label>';
        }).join('')
      + '</div></div>'
      // File
      + '<div><label style="' + LBL + '">PDF file</label>'
      + '<label id="fm-drop" for="fm-file" style="display:flex;align-items:center;gap:12px;border:1.5px dashed #CBD5E1;border-radius:10px;padding:14px 16px;cursor:pointer;background:#F8FAFC;transition:all .12s;">'
      + '<span style="font-size:22px;line-height:1;">📄</span>'
      + '<span id="fm-fname" style="font-size:13.5px;color:#64748B;font-weight:600;">Choose a PDF…</span>'
      + '<span style="margin-left:auto;font-size:12px;font-weight:800;color:#1F6FB2;border:1.5px solid #BFDBFE;background:#EFF6FF;border-radius:8px;padding:6px 12px;">Browse</span>'
      + '</label>'
      + '<input id="fm-file" type="file" accept="application/pdf" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;"></div>'
      // Fill-and-sign toggle — PER FORM, not a global behaviour. Only makes sense
      // for a PDF that was authored with real form fields; a read-and-sign notice
      // should stay read-and-sign.
      // Named recipients. Role audiences reach EVERY parent or educator; often the
      // real need is narrower — this consent for these three families. Both work
      // together: a form reaches you if your role matches OR you are named here.
      + '<div style="border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;">'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
      + '<span style="font-weight:800;font-size:13.5px;color:#0F172A;">Or send to specific people</span>'
      + '<span id="fm-rcount" style="font-size:12px;font-weight:800;color:#1E40AF;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:999px;padding:2px 10px;">none selected</span>'
      + '<button id="fm-rtoggle" type="button" data-kt-iconized="1" style="margin-left:auto;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:9px;padding:7px 14px;font-size:12.5px;font-weight:800;cursor:pointer;">Choose people</button>'
      + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:4px;">Leave empty to use the audiences above. Pick people to send it only to them.</div>'
      + '<div id="fm-rpicker" style="display:none;margin-top:11px;">'
      + '<input id="fm-rsearch" type="text" placeholder="Search by name or email…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13.5px;margin-bottom:8px;">'
      + '<div id="fm-rlist" style="max-height:230px;overflow-y:auto;border:1px solid #EEF2F7;border-radius:9px;"></div>'
      + '</div></div>'
      + '<label id="fm-fillable-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;cursor:pointer;">'
      + '<input id="fm-fillable" type="checkbox" style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">Let recipients fill this form in</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">'
      + 'Tick this if the PDF has fillable fields. Recipients get the form on screen with typing fields and sign it in place, on desktop or the app. '
      + 'Leave it off for read-and-sign notices.</span></span></label>'
      // Reuse: the same sheet completed over and over (per child, per week) rather
      // than signed once and finished.
      + '<label id="fm-reusable-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;cursor:pointer;">'
      + '<input id="fm-reusable" type="checkbox" style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">Allow this form to be reused</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">'
      + 'Keeps the form available after it is submitted, so staff can complete it again — once per child, or week after week. '
      + 'Each submission is kept as its own record.</span></span></label>'
      // Optional: where a completed copy goes. Often a compliance inbox or a
      // director, so a signed form does not only live in the Completed tab.
      + '<div><label for="fm-notify" style="' + LBL + '">Email completed forms to <span style="color:#CBD5E1;font-weight:600;text-transform:none;letter-spacing:0;">(optional)</span></label>'
      + '<input id="fm-notify" type="email" placeholder="e.g. compliance@youragency.com" style="' + FIELD + '">'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:5px;">Each time someone signs this form, the completed PDF is emailed here. Leave blank to send nothing.</div></div>'
      // Action row
      + '<div style="display:flex;align-items:center;gap:14px;border-top:1px solid #F1F5F9;padding-top:16px;">'
      + '<button id="fm-upload" type="button" data-kt-iconized="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 24px;font-weight:800;font-size:13.5px;cursor:pointer;">Upload form</button>'
      + '<span id="fm-upout" style="font-size:13px;font-weight:700;"></span></div>'
      + '</div></div>'
      + '<div id="fm-list"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';

    // Chip active-state highlight + filename echo + dropzone accent.
    fqa('.fm-audchip').forEach(function (chip) {
      var cb = chip.querySelector('input');
      function sync() {
        chip.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        chip.style.background = cb.checked ? '#EFF6FF' : '#fff';
        chip.style.color = cb.checked ? '#1E40AF' : '#334155';
      }
      cb.addEventListener('change', sync); sync();
    });
    [['#fm-fillable', '#fm-fillable-wrap'], ['#fm-reusable', '#fm-reusable-wrap']].forEach(function (pair) {
      var cb = fq(pair[0]), wrap = fq(pair[1]);
      if (!cb || !wrap) return;
      var sync = function () {
        wrap.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        wrap.style.background = cb.checked ? '#EFF6FF' : '#fff';
      };
      cb.addEventListener('change', sync); sync();
    });
    // People picker, shared with the Edit dialog (see attachPeoplePicker). It used
    // to be inline here, which is how the two dialogs drifted apart.
    var picker = attachPeoplePicker(body, {
      toggle: '#fm-rtoggle', panel: '#fm-rpicker', list: '#fm-rlist',
      search: '#fm-rsearch', count: '#fm-rcount',
    }, []);
    var fileIn = fq('#fm-file'), drop = fq('#fm-drop');
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0];
      fq('#fm-fname').textContent = f ? f.name : 'Choose a PDF…';
      fq('#fm-fname').style.color = f ? '#0F172A' : '#64748B';
      drop.style.borderColor = f ? '#1F6FB2' : '#CBD5E1';
      drop.style.background = f ? '#EFF6FF' : '#F8FAFC';
    });

    fq('#fm-upload').onclick = function () {
      var out = fq('#fm-upout');
      var title = fq('#fm-title').value.trim();
      var desc = fq('#fm-desc').value.trim();
      var auds = [].slice.call(fqa('.fm-aud:checked')).map(function (c) { return c.value; });
      var file = fq('#fm-file').files[0];
      if (!title) { out.style.color = '#B91C1C'; out.textContent = 'Add a title.'; return; }
      // A title alone does not say what the form is for, and the Completed and
      // Library tables both show the description now.
      if (!desc) { out.style.color = '#B91C1C'; out.textContent = 'Add a description.'; return; }
      var people = picker.ids();
      if (!auds.length && !people.length) {
        out.style.color = '#B91C1C';
        out.textContent = 'Pick an audience, or choose specific people.';
        return;
      }
      if (!file) { out.style.color = '#B91C1C'; out.textContent = 'Choose a PDF.'; return; }
      out.style.color = '#64748B'; out.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('title', title); fd.append('description', desc);
      fd.append('fillable', fq('#fm-fillable').checked ? '1' : '0');
      fd.append('reusable', fq('#fm-reusable').checked ? '1' : '0');
      var notify = (fq('#fm-notify').value || '').trim();
      if (notify) fd.append('notify_email', notify);
      if (people.length) fd.append('recipient_ids', JSON.stringify(people.map(Number)));
      auds.forEach(function (a) { fd.append('audiences[]', a); });
      fd.append('file', file);
      Api.post('/admin/managed-forms', fd).then(function () {
        out.style.color = '#047857'; out.textContent = '✓ Uploaded.';
        try { body.dispatchEvent(new CustomEvent('kt-fm-uploaded')); } catch (e) {}
        toast('🗂️', 'Form uploaded', '"' + title + '" is now assigned.', '#16A34A');
        /* Came from the package tab: go back to it rather than stranding somebody in the
           Library holding a half-built package. */
        if (PK_RESUME) {
          var pkTab = document.querySelector('.fm-tab[data-t="package"]');
          if (pkTab) { pkTab.click(); return; }
        }
        renderLibrary(body);
      }).catch(function (e) {
        // Laravel answers a 422 with {message, errors:{field:[...]}}. The summary
        // message only names the first failure ("The title field is required.
        // (and 2 more errors)"), which told the user nothing about the other two.
        // List every field error instead.
        out.style.color = '#B91C1C';
        var errs = e && e.data && e.data.errors;
        if (errs && typeof errs === 'object') {
          var lines = [];
          Object.keys(errs).forEach(function (k) {
            var msgs = Array.isArray(errs[k]) ? errs[k] : [errs[k]];
            msgs.forEach(function (m) { lines.push('• ' + m); });
          });
          out.innerHTML = '';
          out.appendChild(document.createTextNode('✗ Could not upload:'));
          var ul = document.createElement('div');
          ul.style.cssText = 'margin-top:4px;white-space:pre-line;';
          ul.textContent = lines.join(String.fromCharCode(10));
          out.appendChild(ul);
        } else {
          out.textContent = '✗ ' + ((e && e.message) || 'Upload failed');
        }
      });
    };

    // ── Upload panel -> dialog ───────────────────────────────────────────────
    // The panel is built and wired exactly as before, then MOVED into a dialog.
    // Moving a node keeps its listeners, so none of the upload wiring above needs
    // to know it now lives in a modal — and the screen leads with the library
    // instead of a tall form pushing existing forms below the fold.
    (function () {
      var panel = body.firstElementChild;                     // the upload card
      var list = fq('#fm-list');
      if (!panel || !list || panel === list) return;

      var bar = document.createElement('div');
      bar.style.cssText = 'display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;';
      bar.innerHTML = '<div style="min-width:0;">'
        + '<div style="font-size:17px;font-weight:800;color:#0F172A;">Forms library</div>'
        + '<div style="font-size:13px;color:#64748B;margin-top:2px;">Upload a PDF and assign it to roles or to specific people.</div></div>';
      var openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.setAttribute('data-kt-iconized', '1');
      openBtn.textContent = '+ Upload a form';
      openBtn.style.cssText = 'margin-left:auto;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);'
        + 'color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:13.5px;cursor:pointer;';
      bar.appendChild(openBtn);
      body.insertBefore(bar, list);

      // The overlay lives INSIDE the screen container, so a re-render after a
      // successful upload disposes of it automatically.
      var ov = document.createElement('div');
      ov.id = 'fm-upload-ov';
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:2147479000;'
        + 'align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;';
      var card = document.createElement('div');
      card.style.cssText = 'background:#fff;border-radius:16px;max-width:640px;width:100%;margin:auto;'
        + 'box-shadow:0 30px 80px -20px rgba(8,20,40,.6);overflow:hidden;';
      var head = document.createElement('div');
      head.style.cssText = 'background:#0B2545;color:#fff;padding:14px 18px;display:flex;align-items:center;gap:12px;';
      head.innerHTML = '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">FORMS MANAGER</div>'
        + '<div style="font-size:17px;font-weight:800;margin-top:2px;">Upload a form</div></div>';
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.setAttribute('data-kt-iconized', '1');
      closeBtn.textContent = '✕';
      closeBtn.style.cssText = 'background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;'
        + 'width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;';
      head.appendChild(closeBtn);
      var scroller = document.createElement('div');
      // The overlay itself scrolls (overflow-y:auto above), so this must not scroll
      // as well or the upload dialog shows two scrollbars, the same fault as the
      // shell modal. Let it grow and leave the scrolling to the overlay.
      scroller.style.cssText = 'padding:16px;';

      panel.parentNode.removeChild(panel);                    // move, listeners intact
      panel.style.margin = '0';
      panel.style.border = '0';
      panel.style.boxShadow = 'none';
      panel.style.padding = '0';
      scroller.appendChild(panel);
      card.appendChild(head); card.appendChild(scroller); ov.appendChild(card);
      // PORTAL to <body>. Inside #fm-body the sidebar and top bar painted over it
      // regardless of z-index, so the scrim missed them and the dialog slid under the
      // top bar. The other two dialogs already attach here.
      document.body.appendChild(ov);

      var prevOverflow = '';
      function open() {
        prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        ov.style.display = 'flex';
      }
      function close() { document.body.style.overflow = prevOverflow; ov.style.display = 'none'; }
      openBtn.addEventListener('click', open);
      closeBtn.addEventListener('click', close);
      ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && ov.style.display === 'flex') close();
      });
      // A successful upload re-renders the library; make sure the page can scroll
      // again even though this overlay is about to be discarded with it.
      body.addEventListener('kt-fm-uploaded', close);
    })();

    loadList(fq('#fm-list'));
  }

  /**
   * A timestamp in the AGENCY's timezone — never UTC, never the viewer's device.
   * This used to call toLocaleDateString/toLocaleTimeString straight on the server
   * string, which renders in whatever zone the viewer happens to be in, so an
   * evening upload could show the wrong DAY entirely. kt-tz.js owns the agency zone
   * (from /auth/me) and is the single source of truth for this everywhere.
   */
  /* The completed-forms grid showed `String(signed_at).slice(0,10)` — the raw UTC
     date off the server, never converted. An evening submission in Toronto is already
     the NEXT day in UTC, so the grid could date a form to the day after it was signed,
     while the same value elsewhere on the screen read correctly through fmtStamp.
     Everything now goes through fmtStamp, which tells the string it is UTC and renders
     it in the agency's zone with the time. (2026-09-10) */
  function fmtStamp(ts) {
    if (!ts) return '';
    try {
      // Server datetimes are UTC and carry no zone marker, so they must be told
      // they are UTC before being rendered in the agency zone. Formatted in ONE
      // Intl call against KT.tz() — composing KT.fmtDate + KT.fmtTime produced
      // "Aug 12, 2026 05:10 a.m. · 1:10 a.m.", i.e. the UTC time and the agency
      // time side by side.
      var iso = String(ts).trim().replace(' ', 'T');
      if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(iso)) iso += 'Z';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return String(ts);
      var zone = (KT.tz && KT.tz()) || undefined;
      return new Intl.DateTimeFormat(undefined, {
        timeZone: zone, day: 'numeric', month: 'short', year: 'numeric',
        hour: 'numeric', minute: '2-digit',
      }).format(d);
    } catch (e) { return String(ts); }
  }

  /** Rename / re-describe a form without re-uploading the PDF. */
  /**
   * Wires up a "send to specific people" picker inside `scope`.
   *
   * Both the upload dialog and the Edit dialog need this, and it started life inline
   * in the upload dialog only — which is why editing a form could not show, let alone
   * change, who had been named. `preselected` is a list of user ids to start ticked,
   * so Edit opens showing the current selection.
   *
   * Returns { ids() } — the chosen ids as numbers.
   */
  function attachPeoplePicker(scope, sel, preselected) {
    var chosen = {};                              // id -> label
    var loaded = false;
    var toggle = scope.querySelector(sel.toggle);
    var panel  = scope.querySelector(sel.panel);
    var list   = scope.querySelector(sel.list);
    var search = scope.querySelector(sel.search);
    var count  = scope.querySelector(sel.count);
    var ROLE_LABEL = { guardian: 'Parent', educator: 'Educator', home_visitor: 'Home visitor',
                       centre_director: 'Director', agency_admin: 'Admin', platform_admin: 'Super admin',
                       auditor: 'Auditor', sales_rep: 'Sales' };

    (preselected || []).forEach(function (id) { chosen[String(id)] = String(id); });

    function syncCount() {
      var n = Object.keys(chosen).length;
      count.textContent = n ? (n + ' selected') : 'none selected';
      count.style.background = n ? '#ECFDF5' : '#EFF6FF';
      count.style.color = n ? '#0F766E' : '#1E40AF';
      count.style.borderColor = n ? '#A7F3D0' : '#BFDBFE';
    }

    function paint(rows) {
      var q = (search.value || '').trim().toLowerCase();
      var shown = rows.filter(function (u) {
        if (!q) return true;
        return (u.__label + ' ' + (u.email || '')).toLowerCase().indexOf(q) !== -1;
      }).slice(0, 200);
      if (!shown.length) {
        list.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">No one matches that.</div>';
        return;
      }
      list.innerHTML = shown.map(function (u) {
        var on = !!chosen[String(u.id)];
        return '<label style="display:flex;align-items:center;gap:10px;padding:9px 11px;border-bottom:1px solid #F1F5F9;cursor:pointer;">'
          + '<input type="checkbox" data-uid="' + u.id + '" ' + (on ? 'checked' : '')
          + ' style="width:17px;height:17px;accent-color:#1F6FB2;flex:0 0 auto;">'
          + '<span style="min-width:0;"><span style="display:block;font-size:13.5px;font-weight:700;color:#0F172A;">' + esc(u.__label) + '</span>'
          + '<span style="display:block;font-size:11.5px;color:#64748B;">' + esc(u.__role) + (u.email ? ' · ' + esc(u.email) : '') + '</span></span></label>';
      }).join('');
      list.querySelectorAll('input[data-uid]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var id = cb.getAttribute('data-uid');
          var rec = rows.filter(function (x) { return String(x.id) === String(id); })[0];
          if (cb.checked) chosen[id] = rec ? rec.__label : id; else delete chosen[id];
          syncCount();
        });
      });
    }

    function load() {
      loaded = true;
      list.innerHTML = '<div style="padding:16px;text-align:center;color:#94A3B8;font-size:13px;">Loading people…</div>';
      Api.get('/admin/users').then(function (d) {
        var rows = (d && (d.users || d.data || d)) || [];
        if (!Array.isArray(rows)) rows = [];
        rows.forEach(function (u) {
          u.__label = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || u.name || u.email || ('User ' + u.id);
          var r = u.roles || u.primary_role || [];
          if (typeof r === 'string') r = [r];
          u.__role = (r || []).map(function (x) { return ROLE_LABEL[x] || x; }).join(', ') || 'Member';
        });
        rows.sort(function (a, b) { return a.__label.localeCompare(b.__label); });
        paint(rows);
        search.addEventListener('input', function () { paint(rows); });
      }).catch(function (e) {
        list.innerHTML = '<div style="padding:16px;color:#B91C1C;font-size:13px;">Could not load people: ' + esc(e.message || '') + '</div>';
      });
    }

    toggle.addEventListener('click', function () {
      var open = panel.style.display !== 'none';
      panel.style.display = open ? 'none' : 'block';
      toggle.textContent = open ? 'Choose people' : 'Hide list';
      if (! open && ! loaded) load();
    });

    syncCount();
    return { ids: function () { return Object.keys(chosen).map(Number); } };
  }

  /** The "send to specific people" block, shared markup for both dialogs. */
  function peopleBlockHtml(prefix, hint) {
    return '<div style="border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:16px;">'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
      + '<span style="font-weight:800;font-size:13.5px;color:#0F172A;">Or send to specific people</span>'
      + '<span id="' + prefix + '-rcount" style="font-size:12px;font-weight:800;color:#1E40AF;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:999px;padding:2px 10px;">none selected</span>'
      + '<button id="' + prefix + '-rtoggle" type="button" data-kt-iconized="1" style="margin-left:auto;background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:9px;padding:7px 14px;font-size:12.5px;font-weight:800;cursor:pointer;">Choose people</button>'
      + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-top:4px;">' + hint + '</div>'
      + '<div id="' + prefix + '-rpicker" style="display:none;margin-top:11px;">'
      + '<input id="' + prefix + '-rsearch" type="text" placeholder="Search by name or email…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13.5px;margin-bottom:8px;">'
      + '<div id="' + prefix + '-rlist" style="max-height:230px;overflow-y:auto;border:1px solid #EEF2F7;border-radius:9px;"></div>'
      + '</div></div>';
  }

  /** One checkbox card, used for the fillable / reusable toggles in both dialogs. */
  function toggleCardHtml(id, title, blurb, checked) {
    return '<label id="' + id + '-wrap" style="display:flex;gap:11px;align-items:flex-start;border:1.5px solid '
      + (checked ? '#1F6FB2' : '#E2E8F0') + ';border-radius:12px;padding:13px 15px;margin-bottom:12px;cursor:pointer;">'
      + '<input id="' + id + '" type="checkbox" ' + (checked ? 'checked' : '')
      + ' style="width:18px;height:18px;flex:0 0 auto;margin-top:1px;accent-color:#1F6FB2;">'
      + '<span><span style="display:block;font-weight:800;font-size:13.5px;color:#0F172A;">' + title + '</span>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:2px;">' + blurb + '</span></span></label>';
  }

  /**
   * Edit EVERYTHING that was chosen at upload — not just the title and description.
   * Audiences, the fill-in and reuse toggles and the named people were upload-only,
   * so a wrong choice meant deleting the form and re-uploading the PDF to change a
   * boolean. Takes the whole form record so every control opens pre-populated.
   */
  /**
   * Show a PDF in a popup over the portal instead of handing it to the browser.
   *
   * openUrl() opens a new tab (and in the APK, an external browser), which loses the
   * session and drops the user out of the app to find their way back. This keeps the
   * document inside the page, with an explicit "Open in new tab" escape hatch for
   * anyone who prefers the browser's own viewer.
   */
  function openPdfPopup(url, title) {
    // This viewer was generalised into kt-doc-viewer.js so every document in the
    // portal opens the same way. Kept as a thin wrapper: same call sites, one
    // implementation. The local copy below is the fallback if that file fails to load.
    if (window.KT && KT.viewDocument) { KT.viewDocument(url, { title: title || 'Form', label: 'Form' }); return; }
    return openPdfPopupLocal(url, title);
  }
  function openPdfPopupLocal(url, title) {
    if (!url) return;
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;'
      + 'display:flex;align-items:center;justify-content:center;padding:18px;';
    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">FORM</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title || 'Form') + '</div>'
      +   '</div>'
      +   '<button id="fv-new" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">Open in new tab</button>'
      +   '<button id="fv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">\u2715</button>'
      + '</div>'
      + '<iframe src="' + esc(url) + '" title="' + esc(title || 'Form') + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>'
      + '</div>';
    document.body.appendChild(ov);
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.querySelector('#fv-close').addEventListener('click', close);
    ov.querySelector('#fv-new').addEventListener('click', function () { openUrl(url); });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  }

  function openEditDialog(form, el) {
    var auds = form.audiences || [];
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147479500;display:flex;align-items:flex-start;justify-content:center;padding:18px;overflow-y:auto;';
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);margin:auto;">'
      + '<div style="background:#0B2545;color:#fff;padding:14px 18px;font-size:16px;font-weight:800;">Edit form</div>'
      + '<div style="padding:18px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">Title</div>'
      + '<input id="fe-title" type="text" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:15px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;">Description</div>'
      + '<textarea id="fe-desc" rows="3" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:14px 0 6px;">Who signs it</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">'
        + AUD.map(function (a) {
            var on = auds.indexOf(a[0]) !== -1;
            return '<label style="display:inline-flex;align-items:center;gap:7px;border:1.5px solid ' + (on ? '#1F6FB2' : '#E2E8F0')
              + ';border-radius:999px;padding:7px 13px;font-size:13px;font-weight:700;color:#0F172A;cursor:pointer;background:' + (on ? '#EFF6FF' : '#fff') + ';">'
              + '<input type="checkbox" class="fe-aud" value="' + a[0] + '" ' + (on ? 'checked' : '')
              + ' style="accent-color:#1F6FB2;width:16px;height:16px;margin:0;">'
              + '<span>' + a[2] + ' ' + a[1] + '</span></label>';
          }).join('')
      + '</div>'
      + peopleBlockHtml('fe', 'Leave empty to use the audiences above. Pick people to send it only to them.')
      + toggleCardHtml('fe-fillable', 'Let recipients fill this form in',
          'Turn on for a PDF with real form fields. A read-and-sign notice should stay read-and-sign.', !!form.fillable)
      + toggleCardHtml('fe-reusable', 'Reusable form',
          'Stays on the list after signing, so it can be filled again for the next child or week.', !!form.reusable)
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;color:#64748B;text-transform:uppercase;margin:4px 0 6px;">Email completed forms to (optional)</div>'
      + '<input id="fe-notify" type="email" placeholder="e.g. compliance@youragency.com" style="width:100%;box-sizing:border-box;padding:11px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;margin-bottom:6px;">'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin-bottom:14px;">Each signature emails the completed PDF here. Clear it to stop sending.</div>'
      + '<div id="fe-msg" style="font-size:12.5px;color:#B91C1C;min-height:16px;margin-top:2px;"></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">'
      + '<button id="fe-cancel" type="button" data-kt-iconized="1" style="background:#fff;border:1.5px solid #CBD5E1;color:#1F6080;border-radius:10px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;">Cancel</button>'
      + '<button id="fe-save" type="button" data-kt-iconized="1" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;border:0;border-radius:10px;padding:9px 18px;font-weight:800;font-size:13px;cursor:pointer;">Save changes</button>'
      + '</div></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('#fe-title').value = form.title || '';
    ov.querySelector('#fe-desc').value = form.description || '';
    ov.querySelector('#fe-notify').value = form.notify_email || '';

    // Same live border feedback the upload dialog gives its toggles.
    ['fe-fillable', 'fe-reusable'].forEach(function (id) {
      var cb = ov.querySelector('#' + id), wrap = ov.querySelector('#' + id + '-wrap');
      cb.addEventListener('change', function () { wrap.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0'; });
    });
    ov.querySelectorAll('.fe-aud').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var lab = cb.closest('label');
        lab.style.borderColor = cb.checked ? '#1F6FB2' : '#E2E8F0';
        lab.style.background = cb.checked ? '#EFF6FF' : '#fff';
      });
    });

    var picker = attachPeoplePicker(ov, {
      toggle: '#fe-rtoggle', panel: '#fe-rpicker', list: '#fe-rlist',
      search: '#fe-rsearch', count: '#fe-rcount',
    }, form.recipient_ids || []);

    function close() { ov.remove(); }
    ov.querySelector('#fe-cancel').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });

    var save = ov.querySelector('#fe-save');
    save.addEventListener('click', function () {
      var msg = ov.querySelector('#fe-msg');
      var t = ov.querySelector('#fe-title').value.trim();
      if (!t) { msg.textContent = 'A title is required.'; return; }
      if (!ov.querySelector('#fe-desc').value.trim()) { msg.textContent = 'A description is required.'; return; }
      var chosenAuds = [].slice.call(ov.querySelectorAll('.fe-aud:checked')).map(function (c) { return c.value; });
      // Preserve any audience this dialog has no chip for, rather than silently
      // deleting it: the server accepts roles the UI may not list yet, and a save
      // must never quietly narrow who a form reaches.
      var chipValues = AUD.map(function (a) { return a[0]; });
      (auds || []).forEach(function (a) {
        if (chipValues.indexOf(a) === -1 && chosenAuds.indexOf(a) === -1) chosenAuds.push(a);
      });
      var people = picker.ids();
      if (!chosenAuds.length && !people.length) {
        msg.textContent = 'Pick an audience, or choose specific people — otherwise nobody can sign it.';
        return;
      }
      save.disabled = true; save.textContent = 'Saving…';
      Api.patch('/admin/managed-forms/' + form.id, {
        title: t,
        description: ov.querySelector('#fe-desc').value.trim(),
        audiences: chosenAuds,
        recipient_ids: people,
        fillable: ov.querySelector('#fe-fillable').checked,
        reusable: ov.querySelector('#fe-reusable').checked,
        notify_email: ov.querySelector('#fe-notify').value.trim(),
      })
        .then(function () { toast('✏️', 'Form updated', '', '#16A34A'); close(); loadList(el); })
        .catch(function (e) {
          save.disabled = false; save.textContent = 'Save changes';
          msg.textContent = (e && e.message) || 'Could not save.';
        });
    });
  }

  function loadList(el) {
    Api.get('/admin/managed-forms').then(function (d) {
      var forms = (d && d.forms) || [];
      if (!forms.length) { el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">No forms uploaded yet.</div>'; return; }
      el.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Description', 'Assigned to', 'Email copy', 'Uploaded by', 'Signed', 'Status', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + forms.map(function (f) {
          var auds = (f.audiences || []).map(function (a) { return '<span style="display:inline-block;background:#EFF6FB;color:#1F6080;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700;margin:1px 3px 1px 0;">' + esc(audLabel(a)) + '</span>'; }).join('');
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;"><span class="fm-open" data-u="' + esc(fileUrl(f.file_url)) + '" style="color:#2563EB;font-weight:700;cursor:pointer;">' + esc(f.title) + '</span></td>'
            // Description gets its own sortable column. As a grey sub-line under the
            // title it was easy to miss entirely, and it could not be sorted or scanned.
            + '<td style="padding:9px 14px;color:#475569;max-width:320px;">' + (f.description ? esc(f.description) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
            + '<td style="padding:9px 14px;">' + (auds || '—')
            // Whether a completed copy is emailed on, and where to. Without this the
            // only way to know was to open Edit on every form one at a time.
            + '<td style="padding:9px 14px;white-space:nowrap;">' + (f.notify_email
                ? '<span style="font-size:11px;font-weight:800;color:#0F766E;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:999px;padding:2px 9px;">On</span>'
                  + '<div style="font-size:11px;color:#94A3B8;margin-top:3px;">' + esc(f.notify_email) + '</div>'
                : '<span style="font-size:11px;font-weight:800;color:#64748B;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;padding:2px 9px;">Off</span>') + '</td>'
            + (f.named_count ? '<span style="display:inline-block;background:#FFF7ED;color:#C2410C;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;margin-left:4px;">+' + f.named_count + ' named</span>' : '')
            + '</td>'
            + '<td style="padding:9px 14px;color:#475569;white-space:nowrap;">'
            + esc(f.uploaded_by || '—')
            + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(fmtStamp(f.created_at)) + '</div></td>'
            + '<td style="padding:9px 14px;font-weight:700;color:#0F172A;">' + (f.signoff_count || 0) + '</td>'
            + '<td style="padding:9px 14px;">' + (f.active ? '<span style="color:#16A34A;font-weight:700;">● Active</span>' : '<span style="color:#94A3B8;">Off</span>') + '</td>'
            + '<td style="padding:9px 8px;text-align:right;white-space:nowrap;">'
            + '<button class="fm-edit kt-act-icon" data-id="' + f.id + '" data-t="' + esc(f.title) + '" data-d="' + esc(f.description || '') + '" title="Edit details" style="border:1px solid #BFDBFE;background:#EFF6FF;color:#1E40AF;">✏️</button>'
            + '<button class="fm-toggle kt-act-icon" data-id="' + f.id + '" data-active="' + (f.active ? 1 : 0) + '" title="' + (f.active ? 'Deactivate' : 'Activate') + '" style="border:1px solid #E5E7EB;background:#fff;border-radius:8px;padding:5px 9px;cursor:pointer;font-size:13px;">' + (f.active ? '⏸' : '▶️') + '</button> '
            + '<button class="fm-del kt-act-icon" data-id="' + f.id + '" data-t="' + esc(f.title) + '" title="Delete" style="border:1px solid #FECACA;background:#FEF2F2;color:#B91C1C;border-radius:8px;padding:5px 9px;cursor:pointer;font-size:13px;">🗑️</button>'
            + '</td></tr>';
        }).join('') + '</tbody></table>';
      // View it in the portal's document panel like every other document, rather
      // than handing it to the browser — which on the APK opens an EXTERNAL browser
      // and drops the user out of the app.
      el.querySelectorAll('.fm-open').forEach(function (b) {
        b.addEventListener('click', function () { openPdfPopup(b.getAttribute('data-u'), b.textContent.trim()); });
      });
      // Pass the loaded record, not scraped data- attributes: the dialog now needs
      // audiences, both toggles and the named recipients as well.
      var byId = {};
      forms.forEach(function (f) { byId[String(f.id)] = f; });
      el.querySelectorAll('.fm-edit').forEach(function (b) {
        b.addEventListener('click', function () {
          var rec = byId[String(b.getAttribute('data-id'))];
          if (rec) openEditDialog(rec, el);
        });
      });
      el.querySelectorAll('.fm-toggle').forEach(function (b) { b.addEventListener('click', function () {
        var on = b.getAttribute('data-active') === '1';
        Api.patch('/admin/managed-forms/' + b.getAttribute('data-id'), { active: !on }).then(function () { loadList(el); }).catch(function (e) { toast('⚠️', 'Failed', e.message || '', '#B91C1C'); });
      }); });
      el.querySelectorAll('.fm-del').forEach(function (b) { b.addEventListener('click', function () {
        Promise.resolve(KT.confirm ? KT.confirm('Delete "' + b.getAttribute('data-t') + '"? Its sign-offs are removed too.') : confirm('Delete?')).then(function (ok) {
          if (!ok) return;
          Api.delete('/admin/managed-forms/' + b.getAttribute('data-id')).then(function () { toast('🗑️', 'Deleted', '', '#B91C1C'); loadList(el); }).catch(function (e) { toast('⚠️', 'Failed', e.message || '', '#B91C1C'); });
        });
      }); });
    }).catch(function (e) { el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>'; });
  }

  /* ───────── COMPLETED: sign-offs table + kebab ───────── */
  function renderCompleted(body) {
    body.innerHTML = '<div id="fm-comp"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div>';
    var el = body.querySelector('#fm-comp');
    Api.get('/admin/managed-forms/signoffs').then(function (d) {
      var rows = (d && d.signoffs) || [];
      if (!rows.length) { el.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;background:#F8FAFC;border-radius:12px;">No forms have been signed yet.</div>'; return; }
      el.innerHTML = '<table data-kt-no-kebab="1" data-kt-paginate="25" data-kt-filter-always="1" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
        + '<thead><tr style="background:#F9FAFB;">' + ['Form', 'Description', 'Signed by', 'Signed (agency time)', 'Copy emailed', ''].map(function (h) { return '<th style="text-align:left;padding:9px 14px;font-size:11px;color:#6B7280;text-transform:uppercase;">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) {
          var who = (((r.first_name || '') + ' ' + (r.last_name || '')).trim()) || r.signer_name || r.email || '—';
          // The description is what the form is FOR — a list of titles like "test 8"
          // says nothing on its own. It is mandatory at upload, so it is always there.
          var desc = (r.form_description || '').trim();
          return '<tr style="border-top:1px solid #F3F4F6;">'
            + '<td style="padding:9px 14px;font-weight:600;color:#111827;">' + esc(r.form_title) + '</td>'
            + '<td style="padding:9px 14px;color:#475569;max-width:320px;">' + (desc ? esc(desc) : '<span style="color:#CBD5E1;">—</span>') + '</td>'
            + '<td style="padding:9px 14px;">' + esc(who) + (r.email ? '<div style="font-size:11px;color:#94A3B8;">' + esc(r.email) + '</div>' : '') + '</td>'
            + '<td style="padding:9px 14px;color:#374151;white-space:nowrap;">' + esc(fmtStamp(r.signed_at)) + '</td>'
            // Did the completed copy actually reach the address on the form? Three
            // distinct states, because "no tick" alone cannot tell an admin whether
            // sending was off or simply had not happened.
            + '<td style="padding:9px 14px;white-space:nowrap;">' + (
                r.notified_at
                  ? '<span style="color:#16A34A;font-weight:700;">✓ ' + esc(fmtStamp(r.notified_at)) + '</span>'
                    + (r.notified_to ? '<div style="font-size:11px;color:#94A3B8;">' + esc(r.notified_to) + '</div>' : '')
                  : (r.form_notify_email
                      ? '<span style="font-size:11px;font-weight:800;color:#B45309;background:#FEF3C7;border:1px solid #FDE68A;border-radius:999px;padding:2px 9px;">Not sent</span>'
                      : '<span style="font-size:11px;color:#94A3B8;">Not set up</span>')
              ) + '</td>'
            + '<td style="padding:9px 8px;text-align:right;">' + kebab(r) + '</td></tr>';
        }).join('') + '</tbody></table>';
      wireKebabs(el, rows);
      if (KT.enhanceTables) { KT.enhanceTables(); }
    }).catch(function (e) { el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>'; });
  }

  function kebab(r) {
    return '<button class="fm-kebab" data-id="' + r.id + '" data-fid="' + r.managed_form_id + '" style="width:32px;height:32px;border:1px solid #E5E7EB;background:#fff;border-radius:8px;cursor:pointer;font-size:17px;color:#475569;" title="Actions" data-kt-iconized="1">⋮</button>';
  }

  function wireKebabs(el, rows) {
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    el.querySelectorAll('.fm-kebab').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var r = byId[btn.getAttribute('data-id')];
        var menu = document.createElement('div');
        menu.style.cssText = 'position:fixed;z-index:2147483000;background:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 12px 34px rgba(15,23,42,.18);padding:6px 0;min-width:190px;';
        function item(icon, label, danger, fn) {
          var mi = document.createElement('button'); mi.type = 'button';
          mi.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;border:none;padding:10px 15px;font-size:13.5px;cursor:pointer;color:' + (danger ? '#B91C1C' : '#111827') + ';font-family:inherit;white-space:nowrap;';
          mi.innerHTML = '<span style="width:18px;text-align:center;">' + icon + '</span><span>' + label + '</span>';
          mi.onmouseenter = function () { mi.style.background = '#F1F5F9'; }; mi.onmouseleave = function () { mi.style.background = 'none'; };
          mi.onclick = function (ev) { ev.stopPropagation(); close(); fn(); };
          return mi;
        }
        // The signature-only view is gone: the signature is embedded in the document
        // itself (with the signer's name and date), so a separate "here is the
        // squiggle" screen shows less than the PDF does.
        // Download the COMPLETED copy — r.file_url is the blank original, which is
        // why this opened an empty form. Fall back to the original only when no
        // completed copy exists, and say so rather than pretending.
        menu.appendChild(item('\uD83D\uDC41', 'View form', false, function () {
          openPdfPopup(fileUrl(r.filled_file_url || r.file_url), r.form_title || 'Form');
        }));
        // Send the completed copy to the address configured ON THE FORM: for
        // submissions signed before that address was set, and to re-send one that
        // still shows as "Not sent".
        if (r.form_notify_email) {
          menu.appendChild(item('\uD83D\uDCE4', (r.notified_at ? 'Email the copy again' : 'Email the copy') + ' to ' + r.form_notify_email, false, function () {
            Api.post('/admin/managed-forms/signoffs/' + r.id + '/email', {})
              .then(function (d) { toast('\u2709', 'Sent', (d && d.message) || 'Copy emailed.', '#16A34A'); renderCompleted(el.parentNode || el); })
              .catch(function (e) { toast('\u26A0', 'Could not send', (e && e.message) || '', '#B91C1C'); });
          }));
        }
        menu.appendChild(item('⬇️', r.filled_file_url ? 'Download completed form' : 'Download blank form (not completed)', false, function () {
          openUrl(fileUrl(r.filled_file_url || r.file_url));
        }));
        menu.appendChild(item('✉️', 'Email the signer', false, function () {
          var to = r.email || ''; var subj = encodeURIComponent('Re: ' + (r.form_title || 'signed form'));
          window.location.href = 'mailto:' + to + '?subject=' + subj;
        }));

        /* WITHDRAW THE SIGNATURE.

           The one action here that removes a record rather than a note about one, so the
           confirm says exactly what goes and what happens next — the completed PDF, the
           copy filed on the signer's own record, and the form becoming outstanding for
           them again. That last part is usually the reason somebody is doing this: the
           wrong person signed, or signed the wrong thing.

           The server checks the caller is an admin or director of this agency; this menu
           entry is the convenience, not the control. */
        menu.appendChild(item('🗑', 'Delete this sign-off', true, function () {
          var who = (((r.first_name || '') + ' ' + (r.last_name || '')).trim()) || r.signer_name || r.email || 'this person';
          var msg = 'Withdraw ' + who + '’s signature on “' + (r.form_title || 'this form') + '”?\n\n'
            + 'The completed PDF and their filed copy are deleted, and the form becomes outstanding for them again. '
            + 'This is recorded in the audit log and cannot be undone.';
          Promise.resolve(KT.confirm ? KT.confirm(msg) : confirm(msg)).then(function (ok) {
            if (!ok) { return; }
            Api.delete('/admin/managed-forms/signoffs/' + r.id).then(function (d) {
              toast('🗑️', 'Signature withdrawn', (d && d.message) || '', '#B91C1C');
              renderCompleted(el.parentNode || el);
            }).catch(function (e) {
              toast('⚠️', 'Could not delete', (e && e.message) || '', '#B91C1C');
            });
          });
        }));
        document.body.appendChild(menu);
        var rect = btn.getBoundingClientRect();
        var mw = menu.offsetWidth || 190, mh = menu.offsetHeight || 150;
        menu.style.left = Math.max(8, Math.min(rect.right - mw, innerWidth - mw - 8)) + 'px';
        menu.style.top = (rect.bottom + 6 + mh > innerHeight - 8 ? Math.max(8, rect.top - mh - 6) : rect.bottom + 6) + 'px';
        function close() { if (menu.parentNode) menu.remove(); document.removeEventListener('click', onDoc, true); }
        function onDoc(ev) { if (!menu.contains(ev.target) && ev.target !== btn) close(); }
        setTimeout(function () { document.addEventListener('click', onDoc, true); }, 0);
      });
    });
  }

  function viewSignoff(r) {
    Api.get('/admin/managed-forms/' + r.managed_form_id + '/signoff/' + r.id).then(function (d) {
      var s = d && d.signoff; if (!s) return;
      var who = (((s.first_name || '') + ' ' + (s.last_name || '')).trim()) || s.signer_name || s.email || '';
      var ov = document.createElement('div');
      ov.className = 'kt-scrim';
      ov.setAttribute('data-no-modal-guard', '1');
      ov.style.cssText = 'position:fixed;inset:0;z-index:2147483001;display:flex;align-items:center;justify-content:center;padding:20px;';
      ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:460px;width:100%;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">'
        + '<div style="padding:18px 20px;border-bottom:1px solid #EEF2F6;"><div style="font-size:16px;font-weight:800;">' + esc(s.form_title) + '</div><div style="font-size:12.5px;color:#64748B;margin-top:2px;">Signed by ' + esc(who) + ' · ' + esc(fmtDate(s.signed_at)) + '</div></div>'
        + '<div style="padding:20px;">'
        + '<div style="font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Signature</div>'
        + (s.signature ? '<img src="' + esc(s.signature) + '" alt="signature" style="max-width:100%;border:1px solid #E5E7EB;border-radius:10px;background:#fff;">' : '<div style="color:#94A3B8;">No signature on file.</div>')
        + '<div style="display:flex;gap:10px;margin-top:16px;">'
        + '<button id="fm-vform" style="flex:1;background:#1F6080;color:#fff;border:0;border-radius:10px;padding:11px;font-weight:800;font-size:13px;cursor:pointer;">Open the form (PDF)</button>'
        + '<button id="fm-vclose" style="flex:0 0 auto;background:#F1F5F9;color:#334155;border:0;border-radius:10px;padding:11px 18px;font-weight:700;font-size:13px;cursor:pointer;">Close</button>'
        + '</div></div></div>';
      document.body.appendChild(ov);
      ov.querySelector('#fm-vform').onclick = function () { openPdfPopup(fileUrl(s.file_url), s.form_title || 'Form'); };
      ov.querySelector('#fm-vclose').onclick = function () { ov.remove(); };
      ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    }).catch(function (e) { toast('⚠️', 'Could not load', e.message || '', '#B91C1C'); });
  }

  try {
    ['platform_admin', 'agency_admin', 'centre_director'].forEach(function (r) {
      if (KT.Shell && KT.Shell.registerScreen) KT.Shell.registerScreen(r + ':forms-manager', render);
    });
  } catch (e) {}
  KT.renderFormsManager = render;
})();
