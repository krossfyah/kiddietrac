/* ============================================================
   KIDDIETRAC v21 - AI Observation Notes (educator)
   ============================================================ */
(function (window) {
  'use strict';
  const { Api, Dom, Shell } = window.KT;
  const { emptyState } = Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmt(d) {
    if (!d) return '-';
    try {
      // Agency-local time (kt-tz.js): the raw value is UTC with no marker.
      var _d = (window.KT && KT.parseTs) ? KT.parseTs(d) : new Date(String(d).replace(' ', 'T') + 'Z');
      return _d.toLocaleString('en-CA', {
        timeZone: (window.KT && KT.tz) ? KT.tz() : 'America/Toronto',
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
      });
    } catch (e) { return d; }
  }

  function foundationBadge(f) {
    const map = {
      belonging:  { bg: '#FEF3C7', fg: '#92400E', label: 'Belonging' },
      wellbeing:  { bg: '#DCFCE7', fg: '#166534', label: 'Well-being' },
      engagement: { bg: '#DBEAFE', fg: '#1E40AF', label: 'Engagement' },
      expression: { bg: '#EDE9FE', fg: '#5B21B6', label: 'Expression' },
    };
    const m = map[f] || { bg: '#F3F4F6', fg: '#374151', label: f };
    return '<span style="background:' + m.bg + ';color:' + m.fg + ';padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;">' + m.label + '</span>';
  }

  /* ============================================================
     OBSERVATIONS LIST
     ============================================================ */
  /* Columns that still look like bubbles. One grid template shared by the header and
     every row keeps them aligned without a <table> — which would pull in the row-actions
     kebab and lose the card look. Below 760px the columns collapse and each row falls
     back to the stacked bubble it always was. */
  /* The last track is FIXED, not auto. Sized to content it measured one width in the
     header (empty) and another in a row (badges), and that difference redistributed every
     fr track - the header drifted 80px right of its own data. */
  var OBS_COLS = '1.35fr .85fr 1.1fr 1.1fr .9fr 172px';

  function ensureObsCss() {
    if (document.getElementById('kt-obs-css')) { return; }
    var s = document.createElement('style');
    s.id = 'kt-obs-css';
    s.textContent =
      /* !important: #kt-obs-bar carries an inline display:flex from the markup. */
      '#kt-obs-bar{display:none !important;}' +
      '.kt-obs-head{display:grid;grid-template-columns:' + OBS_COLS + ';gap:12px;align-items:center;' +
        /* 19px = the row's 18px padding + its 1px border, so column one starts level. */
        'padding:0 19px 8px;font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;' +
        'color:var(--kt-text-faint);}' +
      '.kt-obs-head button{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;' +
        'display:inline-flex;align-items:center;gap:4px;text-align:left;}' +
      '.kt-obs-head button:hover{color:var(--kt-text-muted);}' +
      '.kt-obs-head .on{color:var(--kt-primary,#1F6080);}' +
      '.kt-obs-row{display:grid;grid-template-columns:' + OBS_COLS + ';gap:12px;align-items:center;' +
        'background:var(--kt-surface);border:1px solid var(--kt-border);border-radius:14px;' +
        'padding:13px 18px;margin-bottom:10px;cursor:pointer;transition:box-shadow .15s,border-color .15s;}' +
      '.kt-obs-row:hover{border-color:#BFDBFE;box-shadow:0 2px 10px rgba(15,23,42,.07);}' +
      '.kt-obs-cell{font-size:13.5px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
      '.kt-obs-child{font-weight:700;font-size:14.5px;}' +
      '.kt-obs-muted{color:var(--kt-text-muted);}' +
      '.kt-obs-pill{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:2px 9px;' +
        'background:#EEF2FF;color:#3730A3;white-space:nowrap;}' +
      '.kt-obs-flags{display:flex;gap:5px;justify-content:flex-end;align-items:center;}' +
      '.kt-obs-body{grid-column:1/-1;margin:8px 0 0;padding-top:10px;border-top:1px solid var(--kt-border);' +
        'font-size:13.5px;line-height:1.6;white-space:normal;}' +
      '.kt-obs-chev{color:var(--kt-text-faint);font-size:11px;}' +
      '@media (max-width:760px){' +
        '#kt-obs-bar{display:flex !important;}' +   /* no header to sort with on a phone */
        '.kt-obs-head{display:none;}' +
        '.kt-obs-row{grid-template-columns:1fr;gap:4px;padding:15px 16px;}' +
        '.kt-obs-cell{white-space:normal;}' +
        '.kt-obs-flags{justify-content:flex-start;margin-top:4px;}' +
      '}';
    document.head.appendChild(s);
  }

  async function renderObservations(main, ctx) {
    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs"><span>Home</span><span class="sep">&gt;</span><span style="color:var(--kt-text-muted);">Observations</span></div>' +
          '<h1>Learning Observations</h1>' +
          '<div class="sub">Capture and share developmental moments</div>' +
        '</div>' +
        '<div class="actions">' +
          '<button class="btn btn-primary" id="kt-new-obs">+ New observation</button>' +
        '</div>' +
      '</div>' +
      '<div id="kt-obs-bar" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;">' +
        '<label for="kt-obs-sort" style="font-size:13px;font-weight:600;color:var(--kt-text-muted);">Sort by</label>' +
        '<select id="kt-obs-sort" style="height:30px;padding:0 8px;border:1px solid var(--kt-border);border-radius:8px;font-size:13px;background:var(--kt-surface);color:inherit;">' +
          '<option value="date|desc">Newest first</option>' +
          '<option value="date|asc">Oldest first</option>' +
          '<option value="child|asc">Child (A-Z)</option>' +
          '<option value="educator|asc">Educator (A-Z)</option>' +
          '<option value="centre|asc">Provider / centre (A-Z)</option>' +
          '<option value="domain|asc">Domain (A-Z)</option>' +
        '</select>' +
      '</div>' +
      '<div id="kt-obs-head"></div>' +
      '<div id="kt-obs-list"><div class="loading-state"><div class="spinner"></div></div></div>'
    );

    ensureObsCss();

    wrap.querySelector('#kt-new-obs').addEventListener('click', function () {
      window.location.hash = '#observation-new';
    });

    // Sorting is a server round-trip, not a client re-order — see the note at the top.
    var obsSort = 'date', obsDir = 'desc';
    var sortSel = wrap.querySelector('#kt-obs-sort');
    sortSel.addEventListener('change', function () {
      var parts = String(sortSel.value || 'date|desc').split('|');
      obsSort = parts[0] || 'date';
      obsDir = parts[1] || 'desc';
      loadList();
    });

    // Header doubles as the sort control on wide screens; the dropdown stays for
    // phones, where the header is hidden. Both drive the same server-side sort.
    function paintObsHead() {
      var head = wrap.querySelector('#kt-obs-head');
      if (!head) { return; }
      head.className = 'kt-obs-head';

      reseatObsHead();
      var cols = [['child', 'Child'], ['date', 'When'], ['educator', 'Educator'],
                  ['centre', 'Provider'], ['domain', 'Domain']];
      head.innerHTML = cols.map(function (c) {
        var on = obsSort === c[0];
        return '<div><button type="button" data-obs-sort="' + c[0] + '" class="' + (on ? 'on' : '') + '">'
          + esc(c[1]) + (on ? ' <span>' + (obsDir === 'asc' ? '\u25b4' : '\u25be') + '</span>' : '') + '</button></div>';
      }).join('') + '<div></div>';

      head.querySelectorAll('[data-obs-sort]').forEach(function (b) {
        b.addEventListener('click', function () {
          var key = b.getAttribute('data-obs-sort');
          // Same column toggles direction; a new column starts in its natural order —
          // newest-first for dates, A-Z for names.
          if (obsSort === key) { obsDir = (obsDir === 'asc' ? 'desc' : 'asc'); }
          else { obsSort = key; obsDir = (key === 'date' ? 'desc' : 'asc'); }
          if (sortSel) { sortSel.value = obsSort + '|' + obsDir; }
          loadList();
        });
      });
    }

    /* kt-list-controls attaches its search/count bar AFTER this screen renders, via its
       own observer, and inserts it directly before the list — back between the header and
       the rows. Re-seat immediately, then again after the current task and once more a
       moment later, so the header ends up directly above its rows whichever order the two
       modules settle in. Cheap, and idempotent: it moves nothing once already correct. */
    function reseatObsHead() {
      var head = wrap.querySelector('#kt-obs-head');
      var listNow = wrap.querySelector('#kt-obs-list');
      if (!head || !listNow || !listNow.parentElement) { return; }
      if (head.nextElementSibling !== listNow) {
        listNow.parentElement.insertBefore(head, listNow);
      }
    }

    /* Watch for the controls bar arriving rather than guessing a delay. It attaches
       through its own observer, so the moment it inserts itself before the list we put
       the header back directly above its rows. Self-disconnects after a few seconds:
       nothing should keep observing a screen the user has left. */
    var _obsHeadWatch = null;
    function watchObsHead() {
      reseatObsHead();
      var listNow = wrap.querySelector('#kt-obs-list');
      if (!listNow || !listNow.parentElement || typeof MutationObserver !== 'function') { return; }
      if (_obsHeadWatch) { _obsHeadWatch.disconnect(); }
      _obsHeadWatch = new MutationObserver(function () { reseatObsHead(); });
      _obsHeadWatch.observe(listNow.parentElement, { childList: true });
      setTimeout(function () {
        if (_obsHeadWatch) { _obsHeadWatch.disconnect(); _obsHeadWatch = null; }
      }, 5000);
    }

    async function loadList() {
    const listEl = wrap.querySelector('#kt-obs-list');
    listEl.innerHTML = '<div class="loading-state"><div class="spinner"></div></div>';

    let data;
    try {
      data = await Api.get('/provider/observations?limit=30&sort=' + encodeURIComponent(obsSort) + '&dir=' + encodeURIComponent(obsDir));
    } catch (e) {
      Dom.clear(listEl);
      listEl.appendChild(emptyState('!', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }

    const rows = (data && data.observations) || [];
    Dom.clear(listEl);

    if (rows.length === 0) {
      // Do NOT mark an empty list as [data-kt-list]: the bottom count bar counts
      // the container's children, so the "No observations yet" placeholder itself
      // was counted as "1 record" (shown a count, displayed nothing).
      listEl.removeAttribute('data-kt-list');
      listEl.appendChild(emptyState('-', 'No observations yet',
        'Click "+ New observation" to capture your first one with AI structuring.'));
      return;
    }
    listEl.setAttribute('data-kt-list', '1');
    paintObsHead();

    var DOMAIN_LABEL = {
      social_emotional: 'Social & emotional', physical: 'Physical',
      language_literacy: 'Language & literacy', cognitive: 'Cognitive',
      creative_arts: 'Creative arts', self_care: 'Self-care', outdoor: 'Outdoor',
    };

    function renderObsCard(o) {
      const card = document.createElement('div');
      card.className = 'kt-obs-row';
      const milestones = Array.isArray(o.hdlh_milestones) ? o.hdlh_milestones : [];
      const badges = milestones.map(function (m) { return foundationBadge(m.foundation); }).join(' ');
      // Blanks come back as an em dash from the API; show nothing rather than a stray rule.
      var clean = function (v) { return (v && v !== '\u2014') ? String(v) : ''; };
      var domain = o.domain ? (DOMAIN_LABEL[o.domain] || String(o.domain).replace(/_/g, ' ')) : '';

      card.innerHTML =
        '<div class="kt-obs-cell kt-obs-child">' + esc(o.child_name || '') + '</div>' +
        '<div class="kt-obs-cell kt-obs-muted">' + esc(fmt(o.observed_at)) + '</div>' +
        '<div class="kt-obs-cell kt-obs-muted">' + esc(clean(o.educator_name)) + '</div>' +
        '<div class="kt-obs-cell kt-obs-muted">' + esc(clean(o.centre_name) || clean(o.room_name)) + '</div>' +
        '<div class="kt-obs-cell">' + (domain ? '<span class="kt-obs-pill">' + esc(domain) + '</span>' : '') + '</div>' +
        '<div class="kt-obs-flags">' + badges +
          (o.ai_generated ? ' <span style="background:#E0F2FE;color:#0369A1;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;">AI</span>' : '') +
          (o.shared_with_family ? ' <span style="background:#DCFCE7;color:#166534;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;">SHARED</span>' : '') +
          ' <span class="kt-obs-chev">\u25b4</span>' +
        '</div>';

      // Open by default: the note is what people come to this screen to read, and
      // hiding it behind a click made the list scannable at the cost of being useful.
      // The row still collapses for anyone who wants the dense view.
      var body = document.createElement('div');
      body.className = 'kt-obs-body';
      body.innerHTML =
        '<p style="margin:0;">' + esc(o.family_summary || o.body || '') + '</p>' +
        (milestones.length > 0 ?
          '<div style="margin-top:10px;">' +
            '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--kt-text-faint);margin-bottom:6px;">Milestones</div>' +
            '<ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.6;">' +
              milestones.map(function (m) {
                return '<li>' + esc(m.milestone) + (m.evidence ? ' <em style="color:var(--kt-text-muted);">("' + esc(m.evidence) + '")</em>' : '') + '</li>';
              }).join('') +
            '</ul>' +
          '</div>' : '');
      card.appendChild(body);

      card.addEventListener('click', function () {
        body.hidden = !body.hidden;
        var chev = card.querySelector('.kt-obs-chev');
        if (chev) { chev.textContent = body.hidden ? '\u25be' : '\u25b4'; }
      });
      return card;
    }
    (window.KT && KT.cardPager)
      ? KT.cardPager(listEl, rows, renderObsCard, 10)
      : rows.forEach(function (o) { listEl.appendChild(renderObsCard(o)); });

    watchObsHead();
    }

    await loadList();
  }

  /* ============================================================
     NEW OBSERVATION (educator: type -> structure -> edit -> save)
     ============================================================ */
  /* An unsaved observation survives leaving the screen. Module scope, not nested in a
     render function: a declaration inside another function body is invisible to its
     siblings (see CONVENTIONS.md). */
  function draftUserId() {
    try {
      var raw = sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}';
      var u = JSON.parse(raw);
      if (u && u.user) { u = u.user; }
      return (u && u.id) ? String(u.id) : '';
    } catch (e) { return ''; }
  }

  function draftKey() {
    var uid = draftUserId();
    // No identifiable user means no draft. Better to lose a draft than to hand one
    // person's words to whoever signs in next on a shared tablet.
    return uid ? ('kt_obs_draft_u' + uid) : '';
  }

  function saveDraft(childId, rawText) {
    var k = draftKey();
    if (!k) { return; }
    try {
      if (!rawText || !rawText.trim()) { localStorage.removeItem(k); return; }
      localStorage.setItem(k, JSON.stringify({
        uid: draftUserId(), child_id: childId || '', raw_text: rawText, ts: Date.now(),
      }));
    } catch (e) {}
  }

  function loadDraft() {
    var k = draftKey();
    if (!k) { return null; }
    try {
      var d = JSON.parse(localStorage.getItem(k) || 'null');
      if (!d || !d.raw_text) { return null; }
      // Re-check ownership on READ as well as write: a stale key from an older build,
      // or a device that changed hands, must not surface somebody else's words.
      if (String(d.uid || '') !== draftUserId()) { localStorage.removeItem(k); return null; }
      // A fortnight is long enough to come back to; older than that it is clutter.
      if (d.ts && (Date.now() - d.ts) > 14 * 24 * 3600 * 1000) { localStorage.removeItem(k); return null; }
      return d;
    } catch (e) { return null; }
  }

  function clearDraft() {
    var k = draftKey();
    if (k) { try { localStorage.removeItem(k); } catch (e) {} }
  }

  async function renderObservationNew(main, ctx) {
    Dom.clear(main);

    // Load the children the educator can actually see.
    //
    // This used to read `room.children` off /provider/bootstrap — but bootstrap
    // returns plain room rows and has NO children array on them, so the list was
    // always empty and the child dropdown had nothing to pick. The empty catch
    // below hid it: the screen looked fine and simply refused to let you choose a
    // child. The roster lives on /provider/rooms/{id}/roster, one call per room
    // (an educator has a handful), which is also what the Today screen and the
    // Daily log use — and it respects room assignment.
    let children = [];
    let childLoadError = null;
    try {
      const boot = await Api.get('/provider/bootstrap');
      const rooms = (boot && boot.rooms) || [];
      const rosters = await Promise.all(rooms.map(function (room) {
        return Api.get('/provider/rooms/' + room.id + '/roster')
          .then(function (d) {
            return ((d && d.roster) || []).map(function (c) {
              return {
                id: c.id,
                first_name: c.first_name,
                last_name: c.last_name,
                display_name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.display_name,
                room_name: room.name,
                is_at_centre: !!c.is_at_centre,
              };
            });
          })
          .catch(function () { return []; });
      }));
      children = rosters.reduce(function (a, b) { return a.concat(b); }, []);

      // Children who are here now first — they're the ones you're observing.
      children.sort(function (a, b) {
        if (!!a.is_at_centre !== !!b.is_at_centre) return a.is_at_centre ? -1 : 1;
        return String(a.display_name).localeCompare(String(b.display_name));
      });
    } catch (e) {
      childLoadError = e && e.message ? e.message : 'Could not load your children.';
    }

    const wrap = document.createElement('div');
    main.appendChild(wrap);
    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs">' +
            '<a href="#observations" style="color:var(--kt-text-muted);">Observations</a>' +
            '<span class="sep">&gt;</span>' +
            '<span style="color:var(--kt-text-muted);">New</span>' +
          '</div>' +
          '<h1>New observation</h1>' +
          '<div class="sub">Step 1: type what you observed. Step 2: AI structures it. Step 3: review and save.</div>' +
        '</div>' +
      '</div>' +

      '<div id="kt-step-1" style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:760px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:14px;">Step 1: Capture the moment</h2>' +

        '<div class="form-row">' +
          '<label>Child *</label>' +
          '<select id="kt-child" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
            '<option value="">- select -</option>' +
            children.map(function (c) {
              // Say where they are and whether they're here — an educator covering
              // two rooms needs to tell two Aidans apart.
              var label = esc(c.display_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim());
              if (c.is_at_centre) label += ' · here now';
              if (c.room_name) label += ' · ' + esc(c.room_name);
              return '<option value="' + c.id + '">' + label + '</option>';
            }).join('') +
          '</select>' +
          // Never leave an empty dropdown standing there with no explanation.
          (children.length ? '' :
            '<div style="color:#B45309;font-size:12.5px;margin-top:6px;">'
            + (childLoadError
                ? 'Could not load your children: ' + esc(childLoadError)
                : 'No children found in your rooms. Ask your director to assign you to a room.')
            + '</div>') +
        '</div>' +

        '<div class="form-row" style="margin-top:14px;">' +
          '<label>What did you observe? *</label>' +
          '<textarea id="kt-raw" rows="6" required minlength="10" maxlength="3000" placeholder="Be factual and specific. Example: \'Aria stacked five blocks in a vertical tower, knocked them down, and laughed. She tried again, this time arranging them in a circle and asked Sophia to join her.\'" style="padding:12px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; line-height:1.5; width:100%;"></textarea>' +
          '<div style="font-size:11px; color:var(--kt-text-faint); margin-top:6px;">Tip: write what you saw, not how you felt about it. The AI will surface skills.</div>' +
        '</div>' +

        '<div style="margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;">' +
          '<button class="btn btn-primary" id="kt-structure">Structure with AI</button>' +
          '<button class="btn btn-secondary" id="kt-manual">Write manually (no AI)</button>' +
          '<button class="btn btn-ghost" id="kt-cancel">Cancel</button>' +
        '</div>' +

        '<div id="kt-status" style="margin-top:14px; font-size:13px;"></div>' +
      '</div>' +

      '<div id="kt-step-2" style="display:none; margin-top:14px;"></div>'
    );

    // Persist as they type. Cheap, and the only thing standing between a written
    // observation and losing it to an idle sign-out.
    var rawEl = wrap.querySelector('#kt-raw');
    var childEl = wrap.querySelector('#kt-child');
    if (rawEl) {
      rawEl.addEventListener('input', function () {
        saveDraft(childEl ? childEl.value : '', rawEl.value);
      });
    }
    if (childEl) {
      childEl.addEventListener('change', function () {
        if (rawEl && rawEl.value.trim()) { saveDraft(childEl.value, rawEl.value); }
      });
    }

    // Offer back anything left unfinished.
    (function () {
      var d = loadDraft();
      if (!d || !rawEl || rawEl.value.trim()) { return; }
      var note = document.createElement('div');
      note.style.cssText = 'background:#EFF6FF; border:1px solid #BFDBFE; color:#1E40AF; border-radius:10px; padding:10px 12px; font-size:13px; margin-bottom:12px;';
      note.innerHTML = '<strong>You have an unfinished observation.</strong> '
        + '<span style="opacity:.85;">Started ' + esc(new Date(d.ts).toLocaleString()) + '.</span> '
        + '<button type="button" id="kt-draft-restore" style="margin-left:8px; background:#1E40AF; color:#fff; border:0; border-radius:7px; padding:5px 11px; font-size:12.5px; cursor:pointer;">Restore it</button> '
        + '<button type="button" id="kt-draft-discard" style="margin-left:4px; background:none; border:0; color:#1E40AF; text-decoration:underline; font-size:12.5px; cursor:pointer;">Discard</button>';
      rawEl.parentNode.insertBefore(note, rawEl);
      note.querySelector('#kt-draft-restore').addEventListener('click', function () {
        rawEl.value = d.raw_text;
        if (childEl && d.child_id) { childEl.value = d.child_id; }
        note.remove();
        rawEl.focus();
      });
      note.querySelector('#kt-draft-discard').addEventListener('click', function () {
        clearDraft();
        note.remove();
      });
    })();

    wrap.querySelector('#kt-cancel').addEventListener('click', function () {
      window.location.hash = '#observations';
    });

    /* v22p72: manual fallback — save a moment without the AI structure step
       (works even when Anthropic credits are exhausted) */
    wrap.querySelector('#kt-manual').addEventListener('click', function () {
      const childId = wrap.querySelector('#kt-child').value;
      const rawText = wrap.querySelector('#kt-raw').value.trim();
      const status = wrap.querySelector('#kt-status');
      if (!childId) { status.innerHTML = '<span style="color:#DC2626;">Please select a child.</span>'; return; }
      if (rawText.length < 10) { status.innerHTML = '<span style="color:#DC2626;">Need at least 10 characters.</span>'; return; }
      renderStep2(wrap, {
        structured: { domain: 'social_emotional', hdlh_milestones: [], parent_summary: rawText },
        meta: { model: 'manual (no AI)' }
      }, childId, rawText);
    });

    wrap.querySelector('#kt-structure').addEventListener('click', async function () {
      const childId = wrap.querySelector('#kt-child').value;
      const rawText = wrap.querySelector('#kt-raw').value.trim();
      const status = wrap.querySelector('#kt-status');
      const btn = wrap.querySelector('#kt-structure');

      if (!childId) { status.innerHTML = '<span style="color:#DC2626;">Please select a child.</span>'; return; }
      if (rawText.length < 10) { status.innerHTML = '<span style="color:#DC2626;">Need at least 10 characters.</span>'; return; }

      btn.disabled = true; btn.textContent = 'AI is structuring...';
      status.innerHTML = '<span style="color:var(--kt-text-muted);">Calling Claude... usually 5-10 seconds.</span>';

      try {
        const res = await Api.post('/provider/observations/structure', {
          child_id: parseInt(childId, 10),
          raw_text: rawText,
        });
        renderStep2(wrap, res, childId, rawText);
      } catch (e) {
        let detail = (e && e.message) || 'Server error';
        if (e && e.body) {
          try {
            const parsed = typeof e.body === 'string' ? JSON.parse(e.body) : e.body;
            detail = parsed.detail || parsed.error || detail;
          } catch (_) {}
        }
        status.innerHTML = '<span style="color:#DC2626;">AI failed: ' + esc(detail) + '</span>';
        btn.disabled = false; btn.textContent = 'Structure with AI';
      }
    });
  }

  function renderStep2(wrap, res, childId, rawText) {
    const step1 = wrap.querySelector('#kt-step-1');
    const step2 = wrap.querySelector('#kt-step-2');
    if (!step1 || !step2) { return; }
    step1.style.opacity = '0.6';
    step1.style.pointerEvents = 'none';

    const structured = (res && res.structured) || {};
    const milestones = Array.isArray(structured.hdlh_milestones) ? structured.hdlh_milestones : [];
    const meta = (res && res.meta) || {};

    step2.style.display = 'block';
    step2.innerHTML =
      '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:760px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:6px;">Step 2: Review and save</h2>' +
        // Said in the affirmative, because the previous step dims itself and reads as
        // finished. An observation was structured and then lost this way: the educator
        // never saw this panel on a phone, and assumed it had saved.
        '<div style="background:#FEF3C7; border:1px solid #FDE68A; color:#92400E; border-radius:10px; padding:10px 12px; font-size:13px; margin-bottom:12px;">' +
          '<strong>Not saved yet.</strong> Check it over and press <em>Save observation</em> at the bottom.' +
        '</div>' +
        '<div style="font-size:12px; color:var(--kt-text-faint); margin-bottom:14px;">' +
          'Powered by ' + esc(meta.model || 'Claude') + (meta.tokens_used ? ' &middot; ' + meta.tokens_used + ' tokens' : '') +
        '</div>' +

        '<div class="form-row">' +
          '<label>Domain</label>' +
          '<select id="kt-domain" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
            /* The vocabulary the rest of the platform stores and groups by — lesson
               plans, reports and 439 of 446 existing observations. `language` and
               `creative_expression` were offered here and exist nowhere else, so
               choosing either filed the observation outside every other screen. */
            [['social_emotional','Social & emotional'],
             ['physical','Physical'],
             ['language_literacy','Language & literacy'],
             ['cognitive','Cognitive'],
             ['creative_arts','Creative arts']].map(function (d) {
              return '<option value="' + d[0] + '"' + (d[0] === structured.domain ? ' selected' : '') + '>' + esc(d[1]) + '</option>';
            }).join('') +
          '</select>' +
        '</div>' +

        '<div class="form-row" style="margin-top:14px;">' +
          '<label>Family summary (parent will see this)</label>' +
          '<textarea id="kt-summary" rows="4" style="padding:12px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; line-height:1.5; width:100%;">' + esc(structured.parent_summary || '') + '</textarea>' +
          '<div style="font-size:11px; color:var(--kt-text-faint); margin-top:6px;">You can edit before publishing.</div>' +
        '</div>' +

        (milestones.length > 0 ?
          '<div style="margin-top:14px;">' +
            '<label style="font-size:13px; font-weight:600;">HDLH Milestones detected</label>' +
            '<div style="margin-top:8px;">' +
              milestones.map(function (m) {
                return '<div style="background:var(--kt-bg); padding:10px; border-radius:8px; margin-bottom:6px;">' +
                  foundationBadge(m.foundation) +
                  ' <strong style="font-size:13px;">' + esc(m.milestone) + '</strong>' +
                  (m.evidence ? '<div style="font-size:12px; color:var(--kt-text-muted); margin-top:4px;"><em>Evidence: ' + esc(m.evidence) + '</em></div>' : '') +
                '</div>';
              }).join('') +
            '</div>' +
          '</div>' : '<div style="margin-top:14px; padding:12px; background:var(--kt-bg); border-radius:8px; font-size:13px; color:var(--kt-text-muted);">No specific HDLH milestones detected. The observation will still save.</div>') +

        '<div style="margin-top:18px; display:flex; gap:10px; align-items:center;">' +
          '<label style="display:flex; align-items:center; gap:6px; cursor:pointer;">' +
            '<input type="checkbox" id="kt-share" checked>' +
            '<span style="font-size:13px;">Share with family right away</span>' +
          '</label>' +
        '</div>' +

        '<div style="margin-top:14px; display:flex; gap:10px;">' +
          '<button class="btn btn-primary" id="kt-save">Save observation</button>' +
          '<button class="btn btn-secondary" id="kt-redo">Restart</button>' +
        '</div>' +

        '<div id="kt-save-status" style="margin-top:12px; font-size:13px;"></div>' +
      '</div>';

    // Bring it on screen. Step 2 renders below step 1, which is off the bottom of a
    // phone — the button existed but was never seen.
    try {
      step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      try { step2.scrollIntoView(); } catch (_) {}
    }

    step2.querySelector('#kt-redo').addEventListener('click', function () {
      step1.style.opacity = '1';
      step1.style.pointerEvents = 'auto';
      step2.style.display = 'none';
      wrap.querySelector('#kt-structure').disabled = false;
      wrap.querySelector('#kt-structure').textContent = 'Structure with AI';
      wrap.querySelector('#kt-status').innerHTML = '';
    });

    step2.querySelector('#kt-save').addEventListener('click', async function () {
      const btn = step2.querySelector('#kt-save');
      const status = step2.querySelector('#kt-save-status');
      const domain = step2.querySelector('#kt-domain').value;
      const summary = step2.querySelector('#kt-summary').value.trim();
      const share = step2.querySelector('#kt-share').checked;

      if (!summary) { status.innerHTML = '<span style="color:#DC2626;">Summary cannot be empty.</span>'; return; }
      btn.disabled = true; btn.textContent = 'Saving...';

      try {
        await Api.post('/provider/observations/save', {
          child_id: parseInt(childId, 10),
          raw_text: rawText,
          structured: {
            domain: domain,
            hdlh_milestones: milestones,
            parent_summary: summary,
          },
          shared_with_family: share,
          ai_generated: (meta.model || '').indexOf('manual') === -1,
          ai_model_used: meta.model,
          ai_tokens_used: meta.tokens_used,
        });
        clearDraft();   // it is a record now, not an unfinished note
        Dom.toast('Observation saved', 'success');
        setTimeout(function () { window.location.hash = '#observations'; }, 500);
      } catch (e) {
        status.innerHTML = '<span style="color:#DC2626;">' + esc((e && e.message) || 'Could not save') + '</span>';
        btn.disabled = false; btn.textContent = 'Save observation';
      }
    });
  }

  /* ============================================================
     Register
     ============================================================ */
  window.KT = window.KT || {};
  window.KT.renderObservations    = renderObservations;
  window.KT.renderObservationNew  = renderObservationNew;
  if (Shell && Shell.registerScreen) {
    ['educator', 'centre_director', 'agency_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':observations',     renderObservations);
      Shell.registerScreen(r + ':observation-new',  renderObservationNew);
    });
  }
})(window);
