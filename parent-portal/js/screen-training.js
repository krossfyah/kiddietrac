/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Training (2026-10-01, assignments 2026-10-02).
   Hash: #training · every role · shown INSIDE Help & guides (Guides | Training).

   A narrated tutorial video for each function in Help & guides, grouped into
   courses the way the guides are grouped. Each person sees the videos for the
   guides their role can read, plus anything assigned to them, with progress:
   a bar while part-watched, ✓ when done.

   Admins and directors also get "Assign & track": assign videos to people
   (optionally with a due date — the person is emailed, and reminded before it
   is due and once if overdue) and follow everyone's progress.

   Platform admins get "Review drafts": recorded-but-unpublished videos, with
   Publish. KT.Training.play(video) is the player, also used by a guide's
   "Watch the tutorial" button; KT.Training.tabs(active) is the Guides |
   Training switch both screens share.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Shell = KT.Shell;

  var TEAL = '#0E7C90';
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function mmss(s) { s = Math.max(0, Math.round(s || 0)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
  function abs(u) {
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    return location.origin + (u.charAt(0) === '/' ? u : '/' + u);
  }
  function fmtDate(d) {
    if (!d) return '';
    var p = String(d).slice(0, 10).split('-');
    var dt = new Date(+p[0], +p[1] - 1, +p[2]);      // a calendar date, read locally (never as UTC)
    return dt.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function toast(msg, kind) { try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind); } catch (e) {} }

  /* ── Guides | Training — the one switch both screens show ──────── */
  function tabs(active) {
    var bar = document.createElement('div');
    bar.className = 'kt-help-tabs';
    bar.style.cssText = 'display:flex;gap:6px;margin:12px 0 4px;';
    [['guides', '📖 Guides', '#help'], ['training', '🎓 Training', '#training']].forEach(function (t) {
      var b = document.createElement('a');
      b.href = t[2];
      b.textContent = t[1];
      var on = t[0] === active;
      b.style.cssText = 'display:inline-flex;align-items:center;height:32px;padding:0 14px;border-radius:16px;font-weight:800;font-size:13px;text-decoration:none;'
        + (on ? 'background:' + TEAL + ';color:#fff;' : 'background:#F1F5F9;color:#334155;border:1px solid #E2E8F0;');
      bar.appendChild(b);
    });
    return bar;
  }

  /* ── the player ─────────────────────────────────────────────────── */
  function play(v, onDone) {
    if (!v || !v.video_url) return;
    var ov = document.createElement('div');
    ov.className = 'kt-lightbox kt-training-player';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', v.title);
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483200;background:rgba(8,20,36,.94);display:flex;align-items:center;justify-content:center;'
      + 'padding:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 12px) 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)) + 12px);box-sizing:border-box;';

    var box = document.createElement('div');
    box.style.cssText = 'width:min(1100px,100%);max-height:100%;display:flex;flex-direction:column;gap:10px;';
    ov.appendChild(box);

    var head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;gap:10px;color:#E2E8F0;';
    head.innerHTML = '<div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:800;letter-spacing:.6px;color:#7DD3FC;">TRAINING'
      + (v.status === 'draft' ? ' · DRAFT' : '') + '</div><div style="font-size:16px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
      + esc(v.title) + '</div></div>';
    var x = document.createElement('button');
    x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.setAttribute('data-kt-iconized', '1');
    x.textContent = '✕';
    x.style.cssText = 'flex:0 0 auto;width:34px;height:34px;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:15px;cursor:pointer;';
    head.appendChild(x);
    box.appendChild(head);

    var vid = document.createElement('video');
    vid.controls = true; vid.playsInline = true; vid.setAttribute('playsinline', ''); vid.preload = 'metadata';
    vid.crossOrigin = 'anonymous';
    vid.src = abs(v.video_url);
    if (v.poster_url) vid.poster = abs(v.poster_url);
    vid.style.cssText = 'width:100%;max-height:calc(100vh - 220px);border-radius:12px;background:#000;';
    if (v.captions_url) {
      var tr = document.createElement('track');
      tr.kind = 'captions'; tr.srclang = 'en'; tr.label = 'English'; tr.default = true; tr.src = abs(v.captions_url);
      vid.appendChild(tr);
    }
    box.appendChild(vid);

    var ch = (v.chapters || []).filter(function (c) { return c && c.label; });
    if (ch.length) {
      var list = document.createElement('div');
      list.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;';
      ch.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button'; b.setAttribute('data-kt-iconized', '1');
        b.textContent = mmss(c.t) + '  ' + c.label;
        b.style.cssText = 'height:28px;padding:0 10px;border-radius:14px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);color:#E2E8F0;font-size:12px;cursor:pointer;';
        b.addEventListener('click', function () { try { vid.currentTime = c.t; vid.play(); } catch (e) {} });
        list.appendChild(b);
      });
      box.appendChild(list);
    }

    vid.addEventListener('loadedmetadata', function () {
      if (!v.completed && v.position_sec > 5 && v.position_sec < (vid.duration || 0) - 5) {
        try { vid.currentTime = v.position_sec; } catch (e) {}
      }
    });

    var lastSent = 0, done = !!v.completed;
    function send(force) {
      var pos = vid.currentTime || 0;
      if (!force && Math.abs(pos - lastSent) < 10) return;
      lastSent = pos;
      Api.post('/training/' + v.id + '/progress', { position: Math.floor(pos), duration: Math.floor(vid.duration || v.duration_sec || 0) })
        .then(function (r) { if (r && r.completed && !done) { done = true; v.completed = true; if (onDone) onDone(v); } })
        .catch(function () {});
    }
    vid.addEventListener('timeupdate', function () { send(false); });
    vid.addEventListener('pause', function () { send(true); });
    vid.addEventListener('ended', function () { send(true); });

    var closed = false;
    function close() {
      if (closed) return; closed = true;
      try { send(true); vid.pause(); } catch (e) {}
      document.removeEventListener('keydown', onKey, true);
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      try { if (KT.popOverlay) KT.popOverlay(ov); } catch (e) {}
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } }
    x.addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', onKey, true);

    document.body.appendChild(ov);
    try { if (KT.pushOverlay) KT.pushOverlay(ov, close); } catch (e) {}
    try { var p = vid.play(); if (p && p.catch) p.catch(function () {}); } catch (e) {}
  }

  /* ── a video card ───────────────────────────────────────────────── */
  function card(v, onPlay, extraRow) {
    var pct = v.completed ? 100 : (v.duration_sec ? Math.min(99, Math.round(100 * v.position_sec / v.duration_sec)) : 0);
    var el = document.createElement('div');
    el.className = 'kt-card kt-training-card';
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;cursor:pointer;';
    var due = '';
    if (v.due_on) {
      due = v.completed ? '' : '<span style="position:absolute;left:8px;top:8px;background:' + (v.overdue ? '#DC2626' : '#0F172A')
        + ';color:#fff;font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:6px;">' + (v.overdue ? 'OVERDUE · ' : 'DUE ') + esc(fmtDate(v.due_on)) + '</span>';
    }
    el.innerHTML =
      '<div style="position:relative;padding-top:56.25%;background:#0F172A center/cover no-repeat;'
      + (v.poster_url ? 'background-image:url(' + esc(abs(v.poster_url)) + ');' : '') + '">'
      + '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">'
      + '<span style="width:44px;height:44px;border-radius:50%;background:rgba(14,124,144,.92);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 4px 14px rgba(0,0,0,.35);">▶</span></span>'
      + '<span style="position:absolute;right:8px;bottom:8px;background:rgba(15,23,42,.8);color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;">' + mmss(v.duration_sec) + '</span>'
      + (v.status === 'draft' ? '<span style="position:absolute;left:8px;top:8px;background:#F59E0B;color:#fff;font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:6px;letter-spacing:.4px;">DRAFT · ' + esc(v.audience) + '</span>' : due)
      + (v.completed ? '<span style="position:absolute;left:8px;bottom:8px;background:#16A34A;color:#fff;font-size:11px;font-weight:800;padding:2px 8px;border-radius:6px;">✓ Watched</span>' : '')
      + '</div>'
      + '<div style="height:3px;background:#E2E8F0;"><div style="height:3px;width:' + pct + '%;background:' + (v.completed ? '#16A34A' : TEAL) + ';"></div></div>'
      + '<div style="padding:10px 12px 12px;font-weight:700;font-size:13.5px;color:#0F172A;line-height:1.35;">' + esc(v.title)
      + (v.note ? '<div style="font-weight:500;font-size:12px;color:#64748B;margin-top:4px;">“' + esc(v.note) + '”</div>' : '') + '</div>';
    el.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-tr-publish]')) return;
      onPlay(v);
    });
    if (extraRow) el.appendChild(extraRow);
    return el;
  }

  var GRID = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;';
  function heading(text, sub, colour) {
    var h = document.createElement('h3');
    h.style.cssText = 'margin:18px 2px 10px;font-size:15px;color:' + (colour || '#0F172A') + ';';
    h.innerHTML = esc(text) + (sub ? ' <span style="font-weight:600;color:#94A3B8;font-size:12.5px;">· ' + esc(sub) + '</span>' : '');
    return h;
  }

  /* ── the screen ─────────────────────────────────────────────────── */
  var view = 'mine';     // mine | team

  async function render(main) {
    main.innerHTML = '';
    main.appendChild(tabs('training'));
    var body = document.createElement('div');
    body.id = 'tr-body';
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#64748B;">Loading…</div>';
    main.appendChild(body);

    var d;
    try { d = await Api.get('/training'); } catch (e) {
      body.innerHTML = '<div class="kt-card" style="padding:20px;color:#B91C1C;">Could not load training: ' + esc((e && e.message) || 'error') + '</div>';
      return;
    }
    if (!body.isConnected) return;
    body.innerHTML = '';

    if (d.can_assign) {
      var sw = document.createElement('div');
      sw.style.cssText = 'display:flex;gap:6px;margin:10px 0 2px;border-bottom:1px solid #E2E8F0;';
      [['mine', 'My training'], ['team', 'Assign & track']].forEach(function (t) {
        var b = document.createElement('button');
        b.type = 'button'; b.setAttribute('data-kt-iconized', '1');
        b.textContent = t[1];
        var on = view === t[0];
        b.style.cssText = 'height:34px;padding:0 12px;border:0;background:none;cursor:pointer;font-weight:800;font-size:13.5px;'
          + 'border-bottom:3px solid ' + (on ? TEAL : 'transparent') + ';color:' + (on ? TEAL : '#64748B') + ';margin-bottom:-1px;';
        b.addEventListener('click', function () { view = t[0]; render(main); });
        sw.appendChild(b);
      });
      body.appendChild(sw);
    }
    if (d.can_assign && view === 'team') { renderTeam(main, body); return; }

    var again = function () { render(main); };

    // Assigned to you, first: it is what somebody asked you to do.
    var assigned = d.assigned || [];
    if (assigned.length) {
      var open = assigned.filter(function (v) { return !v.completed; }).length;
      body.appendChild(heading('Assigned to you', open ? open + ' to do' : 'all done ✓', '#0F172A'));
      var ga = document.createElement('div'); ga.style.cssText = GRID;
      assigned.forEach(function (v) { ga.appendChild(card(v, function (x) { play(x, again); })); });
      body.appendChild(ga);
    }

    var all = [];
    (d.courses || []).forEach(function (c) { all = all.concat(c.videos); });
    var watched = all.filter(function (v) { return v.completed; }).length;
    if (all.length) {
      var sum = document.createElement('div');
      sum.className = 'kt-card';
      sum.style.cssText = 'padding:12px 16px;margin:16px 0 4px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;';
      var pct = Math.round(100 * watched / all.length);
      sum.innerHTML = '<div style="font-weight:800;color:#0F172A;">Your library: ' + watched + ' of ' + all.length + ' watched</div>'
        + '<div style="flex:1;min-width:140px;height:8px;background:#E2E8F0;border-radius:4px;overflow:hidden;"><div style="height:8px;width:' + pct + '%;background:' + TEAL + ';"></div></div>'
        + '<div style="font-size:12.5px;color:#64748B;">' + pct + '%</div>';
      body.appendChild(sum);
    }
    (d.courses || []).forEach(function (c) {
      var n = c.videos.filter(function (v) { return v.completed; }).length;
      body.appendChild(heading(c.category, n + '/' + c.videos.length));
      var g = document.createElement('div'); g.style.cssText = GRID;
      c.videos.forEach(function (v) { g.appendChild(card(v, function (x) { play(x, again); })); });
      body.appendChild(g);
    });

    if (!all.length && !assigned.length && !(d.review || []).length) {
      var empty = document.createElement('div');
      empty.className = 'kt-card';
      empty.style.cssText = 'padding:34px 20px;text-align:center;color:#64748B;margin-top:14px;';
      empty.innerHTML = '<div style="font-size:36px;">🎬</div><div style="font-weight:800;color:#0F172A;margin-top:8px;">Videos are on the way</div>'
        + '<div style="font-size:13px;margin-top:4px;">Tutorials for your part of KiddieTrac are being recorded. Meanwhile, the Guides tab covers everything step by step.</div>';
      body.appendChild(empty);
    }

    if (d.can_publish && (d.review || []).length) {
      body.appendChild(heading('Review drafts (' + d.review.length + ')', 'only platform admins see these', '#92400E'));
      var g2 = document.createElement('div'); g2.style.cssText = GRID;
      d.review.forEach(function (v) {
        var row = document.createElement('div');
        row.style.cssText = 'padding:0 12px 12px;display:flex;gap:8px;';
        var pub = document.createElement('button');
        pub.type = 'button'; pub.setAttribute('data-tr-publish', '1'); pub.setAttribute('data-kt-iconized', '1');
        pub.textContent = 'Publish';
        pub.style.cssText = 'height:30px;padding:0 14px;border:0;border-radius:8px;background:#16A34A;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;';
        pub.addEventListener('click', function () {
          pub.disabled = true; pub.textContent = 'Publishing…';
          Api.post('/training/' + v.id + '/publish', { published: true })
            .then(function () { toast('Published: ' + v.title); render(main); })
            .catch(function (e) { pub.disabled = false; pub.textContent = 'Publish'; toast((e && e.message) || 'Could not publish', 'error'); });
        });
        row.appendChild(pub);
        g2.appendChild(card(v, function (x) { play(x, again); }, row));
      });
      body.appendChild(g2);
    }
  }

  /* ── Assign & track ─────────────────────────────────────────────── */
  var STATUS = {
    not_started: ['Not started', '#F1F5F9', '#475569'],
    in_progress: ['In progress', '#E0F2FE', '#0369A1'],
    completed: ['Completed', '#DCFCE7', '#166534'],
    overdue: ['Overdue', '#FEE2E2', '#B91C1C'],
  };
  var filt = { status: '', q: '' };

  async function renderTeam(main, body) {
    var bar = document.createElement('div');
    bar.className = 'kt-card';
    bar.style.cssText = 'padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0 12px;';
    bar.innerHTML = '<input id="tr-q" type="search" placeholder="Search person or video…" value="' + esc(filt.q) + '" '
      + 'style="flex:1 1 220px;min-width:180px;height:32px;padding:0 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + '<select id="tr-st" style="height:32px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + '<option value="">All statuses</option>'
      + Object.keys(STATUS).map(function (k) { return '<option value="' + k + '"' + (filt.status === k ? ' selected' : '') + '>' + STATUS[k][0] + '</option>'; }).join('')
      + '</select><span id="tr-count" style="font-size:12.5px;color:#64748B;"></span>'
      + '<button type="button" id="tr-assign" data-kt-iconized="1" style="margin-left:auto;height:32px;padding:0 14px;border:0;border-radius:8px;background:' + TEAL
      + ';color:#fff;font-weight:800;font-size:13px;cursor:pointer;">+ Assign training</button>';
    body.appendChild(bar);

    var holder = document.createElement('div');
    holder.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading…</div>';
    body.appendChild(holder);

    bar.querySelector('#tr-assign').addEventListener('click', function () { openAssign(function () { render(main); }); });

    var data;
    try { data = await Api.get('/training/assignments'); } catch (e) {
      holder.innerHTML = '<div class="kt-card" style="padding:18px;color:#B91C1C;">Could not load progress: ' + esc((e && e.message) || 'error') + '</div>';
      return;
    }
    var rows = data.assignments || [];

    function paint() {
      var q = filt.q.toLowerCase();
      var list = rows.filter(function (r) {
        return (!filt.status || r.status === filt.status)
          && (!q || (r.person + ' ' + r.title + ' ' + r.role).toLowerCase().indexOf(q) !== -1);
      });
      bar.querySelector('#tr-count').textContent = list.length + ' assignment' + (list.length === 1 ? '' : 's');
      if (!rows.length) {
        holder.innerHTML = '<div class="kt-card" style="padding:30px 18px;text-align:center;color:#64748B;"><div style="font-size:32px;">🎓</div>'
          + '<div style="font-weight:800;color:#0F172A;margin-top:6px;">Nothing assigned yet</div>'
          + '<div style="font-size:13px;margin-top:4px;">Use <strong>Assign training</strong> to give people videos to watch. They get an email, and you can follow their progress here.</div></div>';
        return;
      }
      var th = 'text-align:left;padding:9px 12px;font-size:10.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;background:#F8FAFC;white-space:nowrap;';
      var td = 'padding:9px 12px;font-size:13px;color:#334155;border-top:1px solid #F1F5F9;vertical-align:middle;';
      holder.innerHTML = '<div class="kt-card" style="padding:0;overflow:hidden;"><div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:760px;">'
        + '<thead><tr>' + ['Person', 'Video', 'Progress', 'Due', 'Assigned', ''].map(function (h) { return '<th style="' + th + '">' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
        + list.map(function (r) {
          var s = STATUS[r.status] || STATUS.not_started;
          return '<tr><td style="' + td + '"><div style="font-weight:700;color:#0F172A;">' + esc(r.person) + '</div><div style="font-size:11.5px;color:#64748B;">' + esc(r.role) + '</div></td>'
            + '<td style="' + td + '">' + esc(r.title) + (r.note ? '<div style="font-size:11.5px;color:#94A3B8;">“' + esc(r.note) + '”</div>' : '') + '</td>'
            + '<td style="' + td + 'min-width:150px;"><span style="display:inline-block;background:' + s[1] + ';color:' + s[2] + ';font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;">' + s[0] + '</span>'
            + '<div style="height:5px;background:#E2E8F0;border-radius:3px;margin-top:6px;overflow:hidden;"><div style="height:5px;width:' + r.percent + '%;background:' + (r.status === 'completed' ? '#16A34A' : TEAL) + ';"></div></div>'
            + '<div style="font-size:11px;color:#94A3B8;margin-top:3px;">' + r.percent + '%' + (r.completed_at ? ' · done ' + esc(fmtDate(r.completed_at)) : '') + '</div></td>'
            + '<td style="' + td + 'white-space:nowrap;' + (r.status === 'overdue' ? 'color:#B91C1C;font-weight:700;' : '') + '">' + (r.due_on ? esc(fmtDate(r.due_on)) : '<span style="color:#94A3B8;">No due date</span>') + '</td>'
            + '<td style="' + td + 'white-space:nowrap;font-size:12px;color:#64748B;">' + esc(fmtDate(r.assigned_at)) + (r.assigned_by ? '<div>by ' + esc(r.assigned_by) + '</div>' : '') + (r.emailed ? '' : '<div style="color:#B45309;">not emailed</div>') + '</td>'
            /* Plain buttons in the last cell — kt-row-actions folds them into the house ⋮. */
            + '<td style="' + td + 'text-align:right;white-space:nowrap;"><button type="button" class="tr-cancel" data-id="' + r.id + '" data-who="' + esc(r.person) + '" data-what="' + esc(r.title) + '">✕ Remove assignment</button></td></tr>';
        }).join('')
        + '</tbody></table></div></div>';
      holder.querySelectorAll('.tr-cancel').forEach(function (b) {
        b.addEventListener('click', function () {
          KT.confirm({ title: 'Remove this assignment?', description: b.getAttribute('data-who') + ' will no longer be asked to watch “' + b.getAttribute('data-what') + '”. Their progress so far is kept.', okLabel: 'Remove', tone: 'danger' })
            .then(function (ok) {
              if (!ok) return;
              Api.delete('/training/assignments/' + b.getAttribute('data-id'))
                .then(function () { rows = rows.filter(function (r) { return String(r.id) !== b.getAttribute('data-id'); }); paint(); toast('Assignment removed'); })
                .catch(function (e) { toast((e && e.message) || 'Could not remove it', 'error'); });
            });
        });
      });
      try { if (KT.sweepRowActions) KT.sweepRowActions(); } catch (e) {}
    }
    var qi = bar.querySelector('#tr-q');
    qi.addEventListener('input', function () { filt.q = qi.value.trim(); paint(); });
    bar.querySelector('#tr-st').addEventListener('change', function (e) { filt.status = e.target.value; paint(); });
    paint();
  }

  /* ── the Assign dialog ──────────────────────────────────────────── */
  async function openAssign(onDone) {
    var data;
    try { data = await Api.get('/training/assignable'); } catch (e) { toast((e && e.message) || 'Could not load', 'error'); return; }
    var people = data.people || [], videos = data.videos || [];
    if (!videos.length) {
      KT.confirm({ title: 'No published videos yet', description: 'Training videos can be assigned once they are published.', okLabel: 'OK' });
      return;
    }
    var pickP = {}, pickV = {};
    var wrap = document.createElement('div');
    var lbl = 'display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748B;margin:0 0 6px;';
    var listBox = 'max-height:220px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:4px;';
    var roles = Array.from(new Set(people.map(function (p) { return p.role; }))).sort();
    wrap.innerHTML =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">'
      + '<div><label style="' + lbl + '">People <span id="as-pc" style="color:' + TEAL + ';"></span></label>'
      + '<div style="display:flex;gap:6px;margin-bottom:6px;"><input id="as-pq" type="search" placeholder="Search people…" style="flex:1;min-width:0;height:30px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">'
      + '<select id="as-pr" style="height:30px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;"><option value="">Everyone</option>'
      + roles.map(function (r) { return '<option>' + esc(r) + '</option>'; }).join('') + '</select></div>'
      + '<div id="as-pl" style="' + listBox + '"></div>'
      + '<button type="button" id="as-pall" data-kt-iconized="1" style="margin-top:6px;height:26px;padding:0 10px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;font-size:12px;cursor:pointer;">Select all shown</button></div>'
      + '<div><label style="' + lbl + '">Videos <span id="as-vc" style="color:' + TEAL + ';"></span></label>'
      + '<input id="as-vq" type="search" placeholder="Search videos…" style="width:100%;box-sizing:border-box;height:30px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;margin-bottom:6px;">'
      + '<div id="as-vl" style="' + listBox + '"></div></div></div>'
      + '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:14px;">'
      + '<div style="flex:0 0 180px;"><label style="' + lbl + '" for="as-due">Due date (optional)</label><input id="as-due" type="date" style="width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;"></div>'
      + '<div style="flex:1 1 260px;"><label style="' + lbl + '" for="as-note">Note to include (optional)</label><input id="as-note" maxlength="500" placeholder="e.g. Please watch before your first shift" style="width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;"></div></div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-top:10px;">Each person gets one email listing their videos, with a link to start. With a due date, they\'re reminded two days before and once if it\'s overdue.</div>'
      + '<div id="as-msg" style="margin-top:8px;font-size:13px;color:#B91C1C;"></div>';
    var today = new Date(); wrap.querySelector('#as-due').min = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

    function row(id, title, sub, picked) {
      return '<label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:7px;cursor:pointer;font-size:13px;' + (picked ? 'background:#ECFEFF;' : '') + '">'
        + '<input type="checkbox" data-id="' + id + '"' + (picked ? ' checked' : '') + ' style="width:16px;height:16px;">'
        + '<span style="flex:1;min-width:0;"><span style="font-weight:600;color:#0F172A;">' + esc(title) + '</span>'
        + (sub ? '<span style="display:block;font-size:11.5px;color:#64748B;">' + esc(sub) + '</span>' : '') + '</span></label>';
    }
    function shownPeople() {
      var q = wrap.querySelector('#as-pq').value.trim().toLowerCase(), r = wrap.querySelector('#as-pr').value;
      return people.filter(function (p) { return (!r || p.role === r) && (!q || (p.name + ' ' + (p.email || '')).toLowerCase().indexOf(q) !== -1); });
    }
    function paintP() {
      var list = shownPeople();
      wrap.querySelector('#as-pl').innerHTML = list.length ? list.slice(0, 300).map(function (p) { return row(p.id, p.name, p.role + (p.email ? ' · ' + p.email : ''), pickP[p.id]); }).join('')
        : '<div style="padding:12px;color:#94A3B8;font-size:13px;">Nobody matches.</div>';
      var n = Object.keys(pickP).length; wrap.querySelector('#as-pc').textContent = n ? '· ' + n + ' selected' : '';
    }
    function paintV() {
      var q = wrap.querySelector('#as-vq').value.trim().toLowerCase();
      var list = videos.filter(function (v) { return !q || (v.title + ' ' + v.audience).toLowerCase().indexOf(q) !== -1; });
      wrap.querySelector('#as-vl').innerHTML = list.map(function (v) { return row(v.id, v.title, v.audience.replace('_', ' ') + ' · ' + mmss(v.duration_sec), pickV[v.id]); }).join('');
      var n = Object.keys(pickV).length; wrap.querySelector('#as-vc').textContent = n ? '· ' + n + ' selected' : '';
    }
    wrap.querySelector('#as-pl').addEventListener('change', function (e) { var id = e.target.getAttribute('data-id'); if (e.target.checked) pickP[id] = 1; else delete pickP[id]; paintP(); });
    wrap.querySelector('#as-vl').addEventListener('change', function (e) { var id = e.target.getAttribute('data-id'); if (e.target.checked) pickV[id] = 1; else delete pickV[id]; paintV(); });
    wrap.querySelector('#as-pq').addEventListener('input', paintP);
    wrap.querySelector('#as-pr').addEventListener('change', paintP);
    wrap.querySelector('#as-vq').addEventListener('input', paintV);
    wrap.querySelector('#as-pall').addEventListener('click', function () { shownPeople().forEach(function (p) { pickP[p.id] = 1; }); paintP(); });
    paintP(); paintV();

    Shell.Modal.open({
      title: 'Assign training',
      body: wrap,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Assign & email', style: 'btn-primary', busyLabel: 'Assigning…',
          handler: function () {
            var msg = wrap.querySelector('#as-msg');
            var uids = Object.keys(pickP).map(Number), vids = Object.keys(pickV).map(Number);
            if (!uids.length) { msg.textContent = 'Choose at least one person.'; return false; }
            if (!vids.length) { msg.textContent = 'Choose at least one video.'; return false; }
            var body = { user_ids: uids, video_ids: vids };
            var due = wrap.querySelector('#as-due').value, note = wrap.querySelector('#as-note').value.trim();
            if (due) body.due_on = due;
            if (note) body.note = note;
            return Api.post('/training/assignments', body).then(function (r) {
              toast((r.created || 0) + ' assigned' + (r.updated ? ', ' + r.updated + ' updated' : '') + ' · ' + (r.people_emailed || 0) + ' emailed');
              if (onDone) onDone();
              return true;
            }).catch(function (e) {
              var why = (e && e.data && e.data.message) || (e && e.message) || 'Could not assign.';
              msg.textContent = why; throw new Error(why);
            });
          },
        },
      ],
    });
  }

  KT.Training = { play: play, render: render, tabs: tabs };
  if (Shell && Shell.registerScreen) {
    ['guardian', 'educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin', 'sales_rep', 'auditor'].forEach(function (r) {
      Shell.registerScreen(r + ':training', render);
    });
  }
})(window);
