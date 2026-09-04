/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — All contacts.
   The agency's contact book: the drawer of business cards, digitised.
   Hash: #contacts  ·  agency_admin / centre_director / platform_admin

   NOT the emergency contacts on a child record — those answer "who do we call about
   Aria". This answers "who is our plumber", "which inspector signed off the kitchen",
   "what is the broker's after-hours number" — people who today live in one person's
   phone and leave when they do.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var C = {
    ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', rule: '#E2E8F0', line: '#F1F5F9',
    accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C'
  };

  var state = { search: '', category: '', emergency: '', centre: '', bookOnly: false,
                page: 1, per_page: 50, busy: false, data: null };

  /* Offered, never enforced. The column is free text because every agency keeps a
     different drawer, and a schema change to add "window cleaner" is a schema change
     nobody makes. Whatever an agency has already used is proposed alongside these. */
  var SUGGESTED = ['Supplier', 'Contractor', 'Trades', 'Inspector', 'Health', 'Emergency services',
    'Landlord', 'Insurance', 'Accountant', 'Legal', 'IT', 'Cleaning', 'Food', 'Transport', 'Other'];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* The house phone mask, so a number looks the same here as everywhere else. Never
     destructive: anything that is not a plain 10- or 11-digit North American number —
     an extension, an international line — is shown exactly as it was typed. */
  function phone(v) {
    var raw = String(v == null ? '' : v).trim();
    var d = raw.replace(/\D+/g, '');
    if (d.length === 11 && d[0] === '1') d = d.slice(1);
    if (d.length !== 10) return raw;

    return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
  }

  function chip(text, bg, fg) {
    return '<span style="display:inline-block;background:' + bg + ';color:' + fg
      + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin:1px 5px 1px 0;white-space:nowrap;">'
      + esc(text) + '</span>';
  }

  function render(container) {
    Dom.clear(container);
    try { container.setAttribute('data-kt-pretty', '1'); } catch (e) {}

    var wrap = Dom.el('div', {});
    container.appendChild(wrap);
    wrap.innerHTML =
      '<div class="kt-hero" style="background:linear-gradient(135deg,#334155 0%,#1F6080 60%,#0E7490 100%);">'
      + '<div class="kt-hero-greet">📇 OPERATIONS</div><h1>All contacts</h1>'
      + '<div class="kt-hero-sub">The agency’s contact book — suppliers, trades, inspectors, insurers. '
      + 'Everyone the business deals with — plus the staff and families already on the '
      + 'system — in one place instead of one person’s phone.</div></div>'
      + '<div id="ct-body"><div style="padding:40px;text-align:center;color:' + C.faint + ';">Loading…</div></div>';

    load(container);
  }

  function load(container) {
    if (state.busy) return;
    state.busy = true;
    var body = container.querySelector('#ct-body');
    var qs = '?page=' + state.page + '&per_page=' + state.per_page
      + (state.search ? '&search=' + encodeURIComponent(state.search) : '')
      + (state.category ? '&category=' + encodeURIComponent(state.category) : '')
      + (state.emergency ? '&emergency=1' : '')
      + (state.centre ? '&centre_id=' + state.centre : '')
      + (state.bookOnly ? '&only=book' : '');

    Api.get('/contacts' + qs).then(function (d) {
      state.busy = false;
      if (!body || !body.isConnected) return;      // navigated away mid-fetch
      state.data = d || {};
      paint(container, body);
    }).catch(function (e) {
      state.busy = false;
      if (!body || !body.isConnected) return;
      body.innerHTML = '<div class="kt-card" style="padding:24px;color:' + C.bad + ';">Could not load contacts: '
        + esc((e && e.message) || 'error') + '</div>';
    });
  }

  function paint(container, body) {
    var d = state.data || {};
    var rows = d.contacts || [];
    var meta = d.meta || { total: 0, page: 1, pages: 1 };

    var catOpts = ['<option value="">All categories</option>'].concat((d.categories || []).map(function (c) {
      return '<option value="' + esc(c) + '"' + (state.category === c ? ' selected' : '') + '>' + esc(c) + '</option>';
    })).join('');

    var controls = '<div class="kt-card" style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0 12px;">'
      + '<input id="ct-search" type="search" placeholder="Search name, company, number…" value="' + esc(state.search) + '" '
      + 'style="flex:1 1 240px;min-width:190px;padding:8px 12px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;">'
      /* Search and category only. The three switches that used to sit here —
         emergency-only, added-only, and a centre picker — were narrow filters
         permanently occupying a bar most people reach for with one word. Emergency
         contacts are still pinned to the top of the list, which is what that flag was
         for; it never needed a switch to work. */
      + '<select id="ct-cat" style="padding:8px 10px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;">' + catOpts + '</select>'
      + '<span style="font-size:12.5px;color:' + C.muted + ';">' + (meta.total || 0) + ' contact(s)</span>'
      + '<button type="button" id="ct-new" style="margin-left:auto;padding:8px 14px;border:1px solid ' + C.accent
      + ';background:' + C.accent + ';color:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">+ Add contact</button>'
      + '</div>';

    var th = 'text-align:left;padding:10px 12px;font-size:10.5px;font-weight:800;color:' + C.muted
      + ';text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;background:#F8FAFC;';
    var td = 'padding:10px 12px;font-size:13px;color:#334155;border-top:1px solid ' + C.line + ';vertical-align:top;';

    var tbody = rows.map(function (r) {
      var who = esc(r.display_name);
      /* The company IS the display name when nobody is named on the card, so
         repeating it underneath produced "Dufferin Food Safety / Dufferin Food
         Safety". */
      var sub = [r.job_title, r.company === r.display_name ? null : r.company]
        .filter(Boolean).map(esc).join(' · ');
      /* Their own columns. Stacked together neither could be scanned down a page —
         a column of phone numbers is read at a glance, a column of mixed contact
         details is read one row at a time. */
      var phones = [];
      if (r.phone) phones.push('<a href="tel:' + esc(r.phone) + '" style="color:' + C.accent + ';text-decoration:none;">' + esc(phone(r.phone)) + '</a>');
      if (r.mobile) phones.push('<a href="tel:' + esc(r.mobile) + '" style="color:' + C.accent + ';text-decoration:none;">' + esc(phone(r.mobile)) + ' <span style="color:' + C.faint + ';font-size:11px;">mob</span></a>');
      var mail = r.email
        ? '<a href="mailto:' + esc(r.email) + '" style="color:' + C.accent + ';text-decoration:none;word-break:break-all;">' + esc(r.email) + '</a>'
        : '<span style="color:' + C.faint + ';">—</span>';

      /* A real address, not a rough location — an address column that cannot be
         copied onto an envelope is missing the point. Street on one line, then town,
         province and postcode. */
      var street = [r.address_line1, r.address_line2].filter(Boolean).map(esc).join(', ');
      var town = [r.city, r.province].filter(Boolean).map(esc).join(', ');
      if (r.postal_code) { town = (town ? town + '  ' : '') + esc(r.postal_code); }
      var where = [street, town].filter(Boolean).join('<br>');

      return '<tr>'
        + '<td style="' + td + '">'
        + (r.is_emergency ? chip('EMERGENCY', '#FEE2E2', '#B91C1C') : '')
        + '<span style="font-weight:700;color:' + C.ink + ';">' + who + '</span>'
        + (sub ? '<div style="font-size:11.5px;color:' + C.muted + ';">' + sub + '</div>' : '')
        + ((r.tags || []).length ? '<div style="margin-top:3px;">' + r.tags.map(function (t) { return chip(t, '#F1F5F9', '#475569'); }).join('') + '</div>' : '')
        + '</td>'
        + '<td style="' + td + '">'
        + (r.category
          ? chip(r.category, r.source === 'staff' ? '#EDE9FE' : r.source === 'parent' ? '#F1F5F9' : '#EEF2FF',
                 r.source === 'staff' ? '#5B21B6' : r.source === 'parent' ? '#475569' : '#4338CA')
          : '<span style="color:' + C.faint + ';">—</span>') + '</td>'
        + '<td style="' + td + 'line-height:1.7;white-space:nowrap;">'
        + (phones.join('<br>') || '<span style="color:' + C.faint + ';">—</span>') + '</td>'
      + '<td style="' + td + '">' + mail + '</td>'
        + '<td style="' + td + 'color:' + C.muted + ';line-height:1.6;">' + (where || '—') + '</td>'
        + '<td style="' + td + 'color:' + C.muted + ';max-width:280px;">'
        + (r.notes ? esc(String(r.notes).slice(0, 160)) + (String(r.notes).length > 160 ? '…' : '') : '—') + '</td>'
        /* Plain buttons in the LAST cell — kt-row-actions.js collapses them into the
           house kebab. No word that kt-icon-buttons claims (view/open/back/manage…),
           or the label becomes a bare glyph and the menu row renders blank. */
        /* Only the book's own rows can be edited here. A staff or parent row is a
           view of their account, and editing a copy would leave two versions of a
           phone number with no way to tell which is current — so the row says where it
           comes from instead of offering an action that cannot work. */
        + '<td style="' + td + 'text-align:right;white-space:nowrap;">'
        + (r.editable
          ? '<button type="button" class="ct-edit" data-id="' + r.id + '">✏️ Edit contact</button>'
            + (r.card_image_url ? '<button type="button" class="ct-card" data-url="' + esc(r.card_image_url) + '">🪪 Business card</button>' : '')
            + '<button type="button" class="ct-del" data-id="' + r.id + '" data-name="' + who + '">🗑 Delete contact</button>'
          : '<span style="font-size:11px;color:' + C.faint + ';white-space:nowrap;">from their '
            + (r.source === 'staff' ? 'staff record' : 'family record') + '</span>')
        + '</td></tr>';
    }).join('') || '<tr><td colspan="7" style="' + td + 'text-align:center;color:' + C.faint + ';padding:40px;">'
      + (state.search || state.category || state.emergency || state.centre
        ? 'No contact matches that.'
        : 'No contacts yet. Add the first one, or scan a business card.') + '</td></tr>';

    var pager = (meta.pages > 1)
      ? '<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px;font-size:13px;color:#475569;">'
        + '<button id="ct-prev"' + (meta.page <= 1 ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid ' + C.rule + ';border-radius:8px;background:#fff;cursor:pointer;">‹ Prev</button>'
        + '<span>Page ' + meta.page + ' of ' + meta.pages + '</span>'
        + '<button id="ct-next"' + (meta.page >= meta.pages ? ' disabled' : '') + ' style="padding:6px 12px;border:1px solid ' + C.rule + ';border-radius:8px;background:#fff;cursor:pointer;">Next ›</button>'
        + '</div>' : '';

    body.innerHTML = controls
      + '<div class="kt-card" style="padding:0;overflow:hidden;">'
      + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:900px;">'
      + '<thead><tr>'
      + ['Contact', 'Category', 'Phone', 'Email', 'Address', 'Notes', ''].map(function (h) {
        return '<th style="' + th + '">' + h + '</th>';
      }).join('')
      + '</tr></thead><tbody>' + tbody + '</tbody></table></div>' + pager + '</div>';

    wire(container, body);
  }

  function wire(container, body) {
    /* A SEARCH commits — it re-queries the server, and running that per keystroke
       rearranges the page while somebody is still typing. Enter, blur, or the native
       clear, through the shared helper. */
    var s = body.querySelector('#ct-search');
    if (s && KT.onSearchCommit) {
      var commit = KT.onSearchCommit(s, function (v) {
        state.search = String(v).trim(); state.page = 1; load(container);
      });
      s.insertAdjacentElement('afterend', KT.searchButton(commit));
    }

    var cat = body.querySelector('#ct-cat');
    if (cat) cat.addEventListener('change', function () { state.category = cat.value; state.page = 1; load(container); });
    /* No handlers for the removed switches. The state keys and their query-string
       parameters stay, so the server's filters remain available to any caller that
       wants them — there is simply no permanent control for them here. */

    var prev = body.querySelector('#ct-prev');
    if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(container); } });
    var next = body.querySelector('#ct-next');
    if (next) next.addEventListener('click', function () { state.page++; load(container); });

    var add = body.querySelector('#ct-new');
    if (add) add.addEventListener('click', function () { openEditor(container, null); });

    body.querySelectorAll('.ct-edit').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id');
        openEditor(container, (state.data.contacts || []).filter(function (c) { return +c.id === id; })[0] || null);
      });
    });
    body.querySelectorAll('.ct-card').forEach(function (b) {
      b.addEventListener('click', function () {
        window.open(b.getAttribute('data-url'), '_blank', 'noopener');
      });
    });
    body.querySelectorAll('.ct-del').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id'), name = b.getAttribute('data-name');
        KT.confirm({
          title: 'Delete ' + name + '?',
          description: 'The contact is removed from the book. It is kept in the record rather than destroyed, so it can be restored if this was a mistake.',
          tone: 'danger',
        }).then(function (ok) {
          if (!ok) return;
          Api.delete('/contacts/' + id).then(function () { load(container); });
        });
      });
    });

    try { if (KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}
  }

  /* ── the editor, with the card scanner at the top ──────────────────── */
  function openEditor(container, existing) {
    var e = existing || {};
    var wrap = Dom.el('div', { style: 'width:100%;' });
    var lbl = 'display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:' + C.muted + ';margin-bottom:4px;';
    var fld = 'width:100%;padding:8px 11px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;';

    function row(cols) {
      return '<div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">'
        + cols.map(function (c) {
          return '<div style="flex:1 1 ' + (c.w || '160px') + ';min-width:140px;">'
            + '<label for="ct-' + c.k + '" style="' + lbl + '">' + c.label + '</label>'
            + (c.type === 'textarea'
              ? '<textarea id="ct-' + c.k + '" rows="' + (c.rows || 3) + '" style="' + fld + 'resize:vertical;">' + esc(e[c.k] || '') + '</textarea>'
              : c.type === 'select'
                ? '<select id="ct-' + c.k + '" style="' + fld + '">' + c.options + '</select>'
                : '<input id="ct-' + c.k + '" type="' + (c.type || 'text') + '" value="' + esc(e[c.k] || '') + '" style="' + fld + '">')
            + '</div>';
        }).join('') + '</div>';
    }

    var cats = (state.data && state.data.categories) || [];
    var allCats = SUGGESTED.slice();
    cats.forEach(function (c) { if (allCats.indexOf(c) === -1) allCats.push(c); });

    var centreOpts = '<option value="">The whole agency</option>'
      + ((state.data && state.data.centres) || []).map(function (c) {
        return '<option value="' + c.id + '"' + (String(e.centre_id) === String(c.id) ? ' selected' : '') + '>' + esc(c.name) + '</option>';
      }).join('');

    wrap.innerHTML =
      /* The scanner sits FIRST because it is the fastest way to fill the form — and it
         never saves anything by itself: it proposes, a person confirms. */
      (existing ? '' :
        '<div style="border:1px dashed ' + C.rule + ';border-radius:10px;padding:12px 14px;margin-bottom:14px;background:#F8FAFC;">'
        + '<div style="font-size:13px;color:#334155;margin-bottom:8px;">Have their card? Photograph it and the fields below fill themselves in — you confirm before anything is saved.</div>'
        + '<input id="ct-cardfile" type="file" accept="image/*" capture="environment" style="font-size:13px;">'
        + '<div id="ct-scanmsg" style="margin-top:8px;font-size:12.5px;color:' + C.muted + ';"></div>'
        + '</div>')
      + row([{ k: 'first_name', label: 'First name' }, { k: 'last_name', label: 'Last name' }])
      + row([{ k: 'company', label: 'Company', w: '220px' }, { k: 'job_title', label: 'Job title' }])
      + row([{ k: 'category', label: 'They are our…', type: 'select',
               options: '<option value="">Uncategorised</option>' + allCats.map(function (c) {
                 return '<option value="' + esc(c) + '"' + (e.category === c ? ' selected' : '') + '>' + esc(c) + '</option>';
               }).join('') },
             { k: 'centre_id', label: 'Used by', type: 'select', options: centreOpts }])
      + row([{ k: 'phone', label: 'Phone' }, { k: 'mobile', label: 'Mobile' }])
      + row([{ k: 'email', label: 'Email', type: 'email', w: '220px' }, { k: 'website', label: 'Website' }])
      + row([{ k: 'address_line1', label: 'Address', w: '100%' }])
      + row([{ k: 'city', label: 'City' }, { k: 'province', label: 'Province' }, { k: 'postal_code', label: 'Postal code' }])
      + row([{ k: 'account_number', label: 'Our account no.' }, { k: 'licence_number', label: 'Licence / reg. no.' }, { k: 'hours', label: 'Hours' }])
      + row([{ k: 'notes', label: 'Notes', type: 'textarea', w: '100%' }])
      + '<label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#334155;cursor:pointer;">'
      + '<input id="ct-is_emergency" type="checkbox"' + (e.is_emergency ? ' checked' : '') + ' style="width:16px;height:16px;"> '
      + 'Ring first in an emergency <span style="color:' + C.muted + ';">— pinned to the top of the list</span></label>'
      + '<div id="ct-msg" style="margin-top:12px;font-size:13px;color:' + C.bad + ';"></div>';

    var cardUrl = e.card_image_url || null;

    Shell.Modal.open({
      title: existing ? 'Edit contact' : 'Add contact',
      body: wrap,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: existing ? 'Save changes' : 'Add contact',
          style: 'btn-primary', busyLabel: 'Saving…',
          handler: function () {
            var msg = wrap.querySelector('#ct-msg');
            var body = {};
            ['first_name', 'last_name', 'company', 'job_title', 'category', 'phone', 'mobile',
             'email', 'website', 'address_line1', 'city', 'province', 'postal_code',
             'account_number', 'licence_number', 'hours', 'notes'].forEach(function (k) {
              var el = wrap.querySelector('#ct-' + k);
              body[k] = el && el.value.trim() !== '' ? el.value.trim() : null;
            });
            var cid = wrap.querySelector('#ct-centre_id').value;
            body.centre_id = cid ? +cid : null;
            body.is_emergency = wrap.querySelector('#ct-is_emergency').checked;
            if (cardUrl) body.card_image_url = cardUrl;

            if (!body.first_name && !body.last_name && !body.company) {
              msg.textContent = 'Give the contact a name or a company.';
              return false;
            }

            var p = existing
              ? Api.patch('/contacts/' + existing.id, body)
              : Api.post('/contacts', body);

            return p.then(function () {
              if (Dom.toast) Dom.toast(existing ? 'Contact updated' : 'Contact added');
              load(container);

              return true;
            }).catch(function (err) {
              var why = (err && err.data && err.data.message) || (err && err.message) || 'It could not be saved.';
              msg.textContent = why;
              throw new Error(why);
            });
          },
        },
      ],
    });

    var file = wrap.querySelector('#ct-cardfile');
    if (file) {
      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        var out = wrap.querySelector('#ct-scanmsg');
        out.style.color = C.muted;
        out.textContent = 'Reading the card…';

        var fd = new FormData();
        fd.append('card', f);
        var base = (window.KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        var headers = { Authorization: 'Bearer ' + sessionStorage.getItem('kt_token') };
        var aid = sessionStorage.getItem('kt_active_agency_id');
        if (aid) headers['X-Active-Agency-Id'] = aid;

        fetch(base + '/contacts/scan-card', { method: 'POST', headers: headers, body: fd })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error((res.j && res.j.message) || 'The upload was refused.');
            cardUrl = res.j.card_image_url || cardUrl;

            var got = res.j.parsed && Object.keys(res.j.parsed).length ? res.j.parsed : null;
            if (got) {
              /* Only ever fills a field that is EMPTY. Somebody who has already typed
                 the right number must not have it overwritten by a misread one. */
              var filled = 0;
              Object.keys(got).forEach(function (k) {
                var el = wrap.querySelector('#ct-' + k);
                if (el && !el.value && got[k]) { el.value = got[k]; filled++; }
              });
              out.style.color = C.good;
              out.textContent = 'Card saved and ' + filled + ' field(s) filled in. Check them before saving.';
            } else {
              /* The card is kept regardless — it is the evidence behind the entry, and
                 a scan button that silently does nothing is worse than one that says
                 what happened. */
              out.style.color = C.warn;
              out.textContent = 'Card saved to this contact, but it could not be read'
                + (res.j.reason ? ' — ' + res.j.reason : '') + ' Type the details in.';
            }
          })
          .catch(function (err) {
            out.style.color = C.bad;
            out.textContent = (err && err.message) || 'The card could not be uploaded.';
          });
      });
    }
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'centre_director', 'platform_admin'].forEach(function (role) {
      Shell.registerScreen(role + ':contacts', function (container) {
        state.page = 1;
        render(container);
      });
    });
  }
  KT.Contacts = { render: render };
})(window);
