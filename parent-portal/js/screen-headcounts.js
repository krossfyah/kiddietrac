/* ═══════════════════════════════════════════════════════════════════════
   Headcounts (#headcounts) + the name-to-face checklist — 2026-10-13

   Ontario O. Reg. 137/15 s.11 as amended by O. Reg. 197/26 (in force 1 Jan 2027): the
   supervision policy says when, how and how often children are counted — transitions,
   washroom trips, arrival and dismissal, outings, the playground gates — and what happens
   when a child is unaccounted for. New s.6.1(2): it names who acts for the supervisor.

   KT.Headcount.open({ room_id | trip_id })  — the phone-first checklist (educator Today,
                                               the walk pill, this screen)
   KT.Headcount.editActing(centreId, done)   — acting supervisor(s) for a centre
   KT.Headcount.dashboardCard(container)     — a small "Supervision" card for the director
   KT.Headcount.todayBar(getRoomId)          — the Headcount button on the educator's Today

   The checklist is its own full-screen layer on <body>, not a Shell.Modal: the roster
   re-renders #appMain every 30 seconds and a backdrop tap must never throw away a count
   half done. It only closes from its own buttons.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function absUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//.test(u)) return u;
    var base = (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    return base.replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? u : '/' + u);
  }
  function face(c, size) {
    if (KT.avatar) return KT.avatar(c.name, { size: size, photoUrl: c.photo_url ? absUrl(c.photo_url) : '', sex: c.gender, kind: 'child' });
    return '<span style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:#7C3AED;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;">' + esc(c.initials || '?') + '</span>';
  }
  function ago(m) { if (m == null) return ''; if (m < 1) return 'just now'; if (m < 60) return m + ' min ago'; var h = Math.floor(m / 60); return h + 'h ' + (m % 60) + 'm ago'; }
  function errMsg(e) { return (e && (e.message || (e.data && e.data.message))) || 'Something went wrong.'; }

  var CSS_ID = 'kt-headcount-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      /* the checklist layer */
      + '.hc-ov{position:fixed;inset:0;z-index:2147480000;background:#F8FAFC;display:flex;flex-direction:column;font-family:inherit;color:#0F172A;}'
      + '.hc-ov *{box-sizing:border-box;}'
      + '.hc-top{display:flex;align-items:center;gap:10px;padding:calc(10px + env(safe-area-inset-top,0px)) 14px 10px;background:#0F172A;color:#fff;}'
      + '.hc-top .t{flex:1;min-width:0;}.hc-top .t b{display:block;font-size:16px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.hc-top .t span{font-size:12px;opacity:.75;}'
      + '.hc-x{height:32px;padding:0 12px;border-radius:8px;border:1px solid rgba(255,255,255,.3);background:transparent;color:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.hc-count{padding:12px 14px 8px;background:#fff;border-bottom:1px solid #E2E8F0;}'
      + '.hc-count .n{font-size:26px;font-weight:900;line-height:1;}.hc-count .n small{font-size:14px;font-weight:700;color:#475569;}'
      + '.hc-bar{height:8px;border-radius:99px;background:#E2E8F0;margin-top:8px;overflow:hidden;}.hc-bar i{display:block;height:100%;background:#16A34A;transition:width .15s;}'
      + '.hc-ctx{display:flex;gap:6px;overflow-x:auto;padding:8px 14px;background:#fff;border-bottom:1px solid #E2E8F0;-webkit-overflow-scrolling:touch;}'
      + '.hc-chip{flex:0 0 auto;height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;color:#334155;cursor:pointer;}'
      + '.hc-chip.on{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + '.hc-list{flex:1;overflow-y:auto;padding:10px 12px 16px;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}'
      + '.hc-row{display:flex;align-items:center;gap:12px;width:100%;min-height:62px;padding:8px 12px;margin-bottom:8px;border-radius:14px;border:2px solid #E2E8F0;background:#fff;text-align:left;cursor:pointer;-webkit-tap-highlight-color:transparent;font:inherit;color:inherit;}'
      + '.hc-row .kt-avatar,.hc-row span{pointer-events:none;}'
      + '.hc-row .nm{flex:1;min-width:0;font-size:16px;font-weight:700;}'
      + '.hc-row .tk{width:32px;height:32px;border-radius:50%;border:2px solid #CBD5E1;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;color:transparent;flex:0 0 auto;}'
      + '.hc-row.on{border-color:#16A34A;background:#F0FDF4;}.hc-row.on .tk{background:#16A34A;border-color:#16A34A;color:#fff;}'
      + '.hc-row.new{border-color:#F59E0B;}'
      + '.hc-more{background:#fff;border-top:1px solid #E2E8F0;padding:8px 14px;}'
      + '.hc-more summary{font-size:13px;font-weight:700;color:#475569;cursor:pointer;}'
      + '.hc-in{width:100%;height:36px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:16px;margin-top:6px;background:#fff;}'
      + '.hc-foot{padding:10px 14px calc(10px + env(safe-area-inset-bottom,0px));background:#fff;border-top:1px solid #E2E8F0;}'
      + '.hc-save{width:100%;height:48px;border-radius:12px;border:0;font-size:16px;font-weight:800;color:#fff;background:#16A34A;cursor:pointer;}'
      + '.hc-save.warn{background:#B91C1C;}.hc-save:disabled{opacity:.6;}'
      + '.hc-res{flex:1;overflow-y:auto;padding:16px;}'
      + '.hc-ok{text-align:center;padding:40px 16px;}.hc-ok .big{font-size:56px;}.hc-ok h2{margin:8px 0 4px;font-size:22px;color:#15803D;}'
      + '.hc-red{background:#B91C1C;color:#fff;border-radius:16px;padding:16px;}'
      + '.hc-red h2{margin:0 0 4px;font-size:20px;}.hc-red p{margin:0;font-size:13.5px;opacity:.92;}'
      + '.hc-miss{display:flex;align-items:center;gap:10px;background:#fff;color:#0F172A;border-radius:12px;padding:8px 10px;margin-top:8px;}'
      + '.hc-miss b{flex:1;font-size:16px;}'
      + '.hc-miss a{font-size:12px;font-weight:700;color:#B91C1C;text-decoration:underline;white-space:nowrap;}'
      + '.hc-alert{width:100%;height:52px;margin-top:14px;border-radius:12px;border:3px solid #fff;background:#7F1D1D;color:#fff;font-size:17px;font-weight:900;cursor:pointer;}'
      + '.hc-alert:disabled{opacity:.75;}'
      + '.hc-box{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:12px;margin-top:12px;font-size:13.5px;}'
      + '.hc-box h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#475569;}'
      + '.hc-b2{height:40px;padding:0 14px;border-radius:10px;border:1px solid #CBD5E1;background:#fff;font-weight:800;font-size:14px;cursor:pointer;}'
      + '.hc-b2.g{background:#16A34A;border-color:#16A34A;color:#fff;}'
      /* the screen */
      + '.hcs-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.hcs-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.hcs-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.hcs-i{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.hcs-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;}'
      + '.hcs-b.p{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + '.hcs-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.hcs-room{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #F1F5F9;flex-wrap:wrap;}'
      + '.hcs-room .rn{font-weight:700;font-size:14px;min-width:120px;flex:1;}'
      + '.hcs-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.hcs-t th,.hcs-t td{padding:8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.hcs-t th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.hcs-l{display:block;font-size:11px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}'
      + '.hc-today{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0;padding:8px 10px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;}'
      + '.hc-today .go{height:40px;padding:0 18px;border-radius:10px;border:0;background:#0F172A;color:#fff;font-weight:800;font-size:15px;cursor:pointer;}'
      + '.hc-today .meta{font-size:12.5px;color:#475569;flex:1;min-width:140px;}'
      + '@media(max-width:600px){.hcs-wrap{padding:10px 0;}.hc-today .go{flex:1 1 100%;}}';
    document.head.appendChild(s);
  }

  // ─── the checklist ─────────────────────────────────────────────────
  var _open = null;
  function close() { if (_open) { try { _open.remove(); } catch (e) {} _open = null; document.documentElement.style.overflow = ''; } }

  async function open(opts) {
    opts = opts || {};
    css(); close();
    var ov = document.createElement('div'); ov.className = 'hc-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
    ov.innerHTML = '<div class="hc-top"><div class="t"><b>Headcount</b><span>Loading…</span></div><button class="hc-x" data-x>Close</button></div>'
      + '<div class="hc-res" style="color:#64748B;">Loading the children signed in…</div>';
    document.body.appendChild(ov); _open = ov; document.documentElement.style.overflow = 'hidden';
    ov.querySelector('[data-x]').onclick = close;
    var q = opts.trip_id ? 'trip_id=' + encodeURIComponent(opts.trip_id) : 'room_id=' + encodeURIComponent(opts.room_id || '');
    var d;
    try { d = await Api.get('/headcounts/start?' + q + '&t=' + Date.now()); }
    catch (e) { ov.querySelector('.hc-res').innerHTML = '<div style="color:#B91C1C;font-weight:700;">' + esc(errMsg(e)) + '</div>'; return; }
    if (_open !== ov) return;
    var st = { seen: {}, ctx: opts.context || d.default_context || 'routine', children: d.children || [], fresh: {}, location: '', note: '' };
    paintList(ov, d, st, opts);
  }

  function paintList(ov, d, st, opts) {
    var total = st.children.length;
    var h = '<div class="hc-top"><div class="t"><b>👀 Headcount · ' + esc(d.label) + '</b><span>' + esc(d.centre_name || '')
      + (d.last ? ' · last count ' + esc(ago(d.last.minutes_ago)) : '') + '</span></div><button class="hc-x" data-x>Close</button></div>';
    if (!total) {
      h += '<div class="hc-res"><div class="hc-ok"><div class="big">🫥</div><h2 style="color:#334155;">No children ' + (d.kind === 'trip' ? 'on this walk' : 'signed in to this room') + '</h2>'
        + '<p style="color:#64748B;">A headcount lists the children who are signed in right now.</p></div></div>';
      ov.innerHTML = h; ov.querySelector('[data-x]').onclick = close; return;
    }
    h += '<div class="hc-count"><div class="n" data-n></div><div class="hc-bar"><i data-bar></i></div></div>'
      + '<div class="hc-ctx">' + Object.keys(d.contexts || {}).map(function (k) { return '<button class="hc-chip' + (k === st.ctx ? ' on' : '') + '" data-ctx="' + esc(k) + '">' + esc(d.contexts[k]) + '</button>'; }).join('') + '</div>'
      + '<div class="hc-list">' + st.children.map(function (c) {
          return '<button type="button" class="hc-row' + (st.seen[c.id] ? ' on' : '') + (st.fresh[c.id] ? ' new' : '') + '" data-id="' + c.id + '">' + face(c, 44)
            + '<span class="nm">' + esc(c.name) + (st.fresh[c.id] ? ' <span style="font-size:11px;color:#B45309;">just signed in</span>' : '') + '</span><span class="tk">✓</span></button>';
        }).join('') + '</div>'
      + '<details class="hc-more"' + (st.location || st.note ? ' open' : '') + '><summary>Where / note (optional)</summary>'
      + '<input class="hc-in" data-loc maxlength="200" placeholder="Where, e.g. playground gate, washroom, parking lot" value="' + esc(st.location) + '">'
      + '<input class="hc-in" data-note maxlength="2000" placeholder="Note" value="' + esc(st.note) + '"></details>'
      + '<div class="hc-foot"><button class="hc-save" data-save></button></div>';
    ov.innerHTML = h;
    var $ = function (sel) { return ov.querySelector(sel); };
    $('[data-x]').onclick = close;
    function refresh() {
      var n = st.children.filter(function (c) { return st.seen[c.id]; }).length;
      $('[data-n]').innerHTML = n + ' <small>of ' + total + ' accounted for</small>';
      $('[data-bar]').style.width = Math.round(n * 100 / total) + '%';
      var b = $('[data-save]'); var miss = total - n;
      b.className = 'hc-save' + (miss ? ' warn' : '');
      b.textContent = miss ? 'Save — ' + miss + ' not seen' : 'Save — all ' + total + ' accounted for';
    }
    ov.querySelectorAll('[data-ctx]').forEach(function (b) {
      b.onclick = function () { st.ctx = b.getAttribute('data-ctx'); ov.querySelectorAll('[data-ctx]').forEach(function (x) { x.classList.toggle('on', x === b); }); };
    });
    ov.querySelectorAll('.hc-row').forEach(function (r) {
      r.onclick = function () { var id = +r.getAttribute('data-id'); st.seen[id] = !st.seen[id]; r.classList.toggle('on', !!st.seen[id]); refresh(); };
    });
    refresh();
    $('[data-save]').onclick = async function () {
      var btn = this;
      /* NOBODY TICKED (2026-10-19). A save with 0 of 3 ticked was filed as three children
         missing and corrected to 3 of 3 twenty-four seconds later: saved before ticking.
         A zero count now asks once, on the button itself, before it is filed. */
      var ticked = st.children.filter(function (c) { return st.seen[c.id]; }).length;
      if (!ticked && total > 0 && !btn._ktZeroOk) {
        btn._ktZeroOk = true;
        btn.textContent = 'Nobody ticked — tap again to save as ' + total + ' not seen';
        setTimeout(function () { btn._ktZeroOk = false; if (!btn.disabled) refresh(); }, 5000);
        return;
      }
      btn._ktZeroOk = false;
      st.location = ($('[data-loc]').value || '').trim(); st.note = ($('[data-note]').value || '').trim();
      btn.disabled = true; btn.textContent = 'Saving…';
      var body = { context: st.ctx, location: st.location || null, note: st.note || null,
        seen_ids: Object.keys(st.seen).filter(function (k) { return st.seen[k]; }).map(Number),
        shown_ids: st.children.map(function (c) { return c.id; }) };
      if (d.trip_id) body.trip_id = d.trip_id; else body.room_id = d.room_id;
      var r;
      try { r = await Api.post('/headcounts', body); }
      catch (e) {
        var data = e && (e.data || e.body || e.response);
        if (data && data.refresh && data.added) {
          data.added.forEach(function (c) { if (!st.children.some(function (x) { return x.id === c.id; })) { st.children.push(c); st.fresh[c.id] = 1; } });
          toast('👀', 'More children signed in', data.message || '', '#D97706');
          paintList(ov, d, st, opts); return;
        }
        if (e && e.status === 409) {   // the API helper may not expose the body: reload the list, keep the ticks
          try { var again = await Api.get('/headcounts/start?' + (d.trip_id ? 'trip_id=' + d.trip_id : 'room_id=' + d.room_id) + '&t=' + Date.now());
            (again.children || []).forEach(function (c) { if (!st.children.some(function (x) { return x.id === c.id; })) { st.children.push(c); st.fresh[c.id] = 1; } });
            toast('👀', 'More children signed in', 'Find them too, then save.', '#D97706'); paintList(ov, d, st, opts); return;
          } catch (e2) {}
        }
        btn.disabled = false; refresh(); toast('⚠️', 'Could not save the headcount', errMsg(e), '#DC2626'); return;
      }
      if (_open !== ov) return;
      try { if (typeof opts.onSaved === 'function') opts.onSaved(r); } catch (e) {}
      paintResult(ov, d, st, r, opts);
    };
  }

  function paintResult(ov, d, st, r, opts) {
    var head = '<div class="hc-top"><div class="t"><b>👀 Headcount · ' + esc(d.label) + '</b><span>' + esc(d.centre_name || '') + '</span></div><button class="hc-x" data-x>Close</button></div>';
    if (r.all_accounted) {
      ov.innerHTML = head + '<div class="hc-res"><div class="hc-ok"><div class="big">✅</div><h2>All ' + r.expected + ' accounted for</h2>'
        + '<p style="color:#475569;">' + esc(r.context_label) + ' · saved</p><button class="hc-b2" data-x2 style="margin-top:18px;">Done</button></div></div>';
      ov.querySelector('[data-x]').onclick = close; ov.querySelector('[data-x2]').onclick = close;
      var me = ov; setTimeout(function () { if (_open === me) close(); }, 2200);
      return;
    }
    var missN = (r.missing || []).length;
    var desc = 'Headcount at ' + (r.local || '').replace('T', ' ') + ' (' + (r.context_label || '') + ', ' + d.label + '): ' + r.counted + ' of ' + r.expected + ' accounted for. Not seen: ';
    var h = head + '<div class="hc-res">'
      + '<div class="hc-red"><h2>⚠️ ' + missN + ' ' + (missN === 1 ? 'child' : 'children') + ' not accounted for</h2>'
      + '<p>' + r.counted + ' of ' + r.expected + ' seen. Search now: the last place seen, washrooms, cubbies, the playground and gates, the parking lot. Follow your centre\'s missing-child procedure.</p>'
      + (r.missing || []).map(function (c) {
          var link = '#incident-new?child_id=' + c.id + '&type=serious_occurrence&severity=high&occurred_at=' + encodeURIComponent(r.local || '')
            + '&location=' + encodeURIComponent(r.location || d.label) + '&description=' + encodeURIComponent(desc + c.name + '.');
          return '<div class="hc-miss">' + face(c, 40) + '<b>' + esc(c.name) + '</b><a href="' + esc(link) + '" data-inc>Serious occurrence report</a></div>';
        }).join('')
      + '<button class="hc-alert" data-alert>🚨 Alert director now</button>'
      + '<div data-alerted style="font-size:13px;margin-top:8px;font-weight:700;"></div></div>'
      + '<div class="hc-box" data-acting>' + actingHtml(r.acting) + '</div>'
      + '<div class="hcs-row" style="margin-top:14px;gap:10px;">'
      + '<button class="hc-b2 g" data-found>✅ Found — all accounted for</button>'
      + '<button class="hc-b2" data-again>Count again</button></div></div>';
    ov.innerHTML = h;
    ov.querySelector('[data-x]').onclick = close;
    ov.querySelectorAll('[data-inc]').forEach(function (a) { a.addEventListener('click', function () { setTimeout(close, 0); }); });
    var ab = ov.querySelector('[data-alert]');
    ab.onclick = async function () {
      ab.disabled = true; ab.textContent = 'Alerting…';
      try {
        var x = await Api.post('/headcounts/' + r.id + '/alert', {});
        ab.textContent = '🚨 Alert sent — send again';
        ab.disabled = false;
        ov.querySelector('[data-alerted]').textContent = 'Alerted ' + x.notified + ' ' + (x.notified === 1 ? 'person' : 'people') + ' (director, agency admins, acting supervisors) by bell and push.';
        if (x.acting) ov.querySelector('[data-acting]').innerHTML = actingHtml(x.acting);
      } catch (e) { ab.disabled = false; ab.textContent = '🚨 Alert director now'; toast('⚠️', 'Could not send the alert', errMsg(e), '#DC2626'); }
    };
    ov.querySelector('[data-found]').onclick = async function () {
      var note = KT.prompt ? await KT.prompt({ title: 'Everyone found?', description: 'Where was the child found? This goes on the record and tells anyone you alerted.', placeholder: 'e.g. found in the washroom with an educator', okLabel: 'Mark found' }) : '';
      if (note === null || note === undefined || note === false) return;
      if (typeof note === 'object') note = note.value || '';
      try { await Api.post('/headcounts/' + r.id + '/resolve', { note: String(note || '').trim() || null }); toast('✅', 'Marked as found', '', '#16A34A'); close(); }
      catch (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); }
    };
    ov.querySelector('[data-again]').onclick = function () { st.fresh = {}; open({ room_id: d.room_id, trip_id: d.trip_id, context: st.ctx, onSaved: opts.onSaved }); };
  }

  function actingHtml(list) {
    list = list || [];
    if (!list.length) return '<h4>If the supervisor is away</h4><div style="color:#64748B;">No acting supervisor is named for this centre yet. A director or agency admin sets this on the Headcounts screen.</div>';
    return '<h4>If the supervisor is away, contact</h4>' + list.map(function (p, i) {
      return '<div style="padding:3px 0;"><b>' + (i + 1) + '. ' + esc(p.name) + '</b>' + (p.phone ? ' · <a href="tel:' + esc(String(p.phone).replace(/[^\d+]/g, '')) + '">' + esc(p.phone) + '</a>' : '') + '</div>';
    }).join('');
  }

  // ─── acting supervisor editor ──────────────────────────────────────
  async function editActing(centreId, done) {
    css();
    var d;
    try { d = await Api.get('/headcounts/acting/' + centreId); } catch (e) { toast('⚠️', 'Could not load', errMsg(e), '#DC2626'); return; }
    var picked = (d.acting || []).map(function (p) { return p.id; });
    var byId = {}; (d.candidates || []).forEach(function (c) { byId[c.id] = c; });
    var body = document.createElement('div');
    function paint() {
      body.innerHTML = '<p style="margin:0 0 10px;font-size:13px;color:#475569;">Who acts for the supervisor when the supervisor is off-site and something urgent needs doing. In order: the first name is called first. Shown on every missing-child alert.</p>'
        + (picked.length ? picked.map(function (id, i) {
            var c = byId[id] || { name: '#' + id, role: '' };
            return '<div class="hcs-row" style="padding:6px 0;border-top:1px solid #F1F5F9;"><b style="width:18px;">' + (i + 1) + '.</b><span style="flex:1;"><b>' + esc(c.name) + '</b> <span style="color:#64748B;font-size:12px;">' + esc(c.role) + '</span></span>'
              + '<button type="button" class="hcs-b" data-up="' + i + '"' + (i ? '' : ' disabled') + ' title="Move up">↑</button>'
              + '<button type="button" class="hcs-b" data-rm="' + i + '">Remove</button></div>';
          }).join('') : '<div style="color:#64748B;font-size:13px;padding:6px 0;">Nobody named yet.</div>')
        + '<div class="hcs-row" style="margin-top:10px;"><select class="hcs-i" data-add style="flex:1;min-width:180px;"><option value="">Add someone at this centre…</option>'
        + (d.candidates || []).filter(function (c) { return picked.indexOf(c.id) < 0; }).map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + ' — ' + esc(c.role) + '</option>'; }).join('')
        + '</select></div>';
      body.querySelectorAll('[data-up]').forEach(function (b) { b.onclick = function () { var i = +b.getAttribute('data-up'); var t = picked[i - 1]; picked[i - 1] = picked[i]; picked[i] = t; paint(); }; });
      body.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { picked.splice(+b.getAttribute('data-rm'), 1); paint(); }; });
      body.querySelector('[data-add]').onchange = function () { var v = +this.value; if (v && picked.indexOf(v) < 0) picked.push(v); paint(); };
    }
    paint();
    KT.Shell.Modal.open({ title: 'Acting supervisor — ' + (d.centre_name || ''), body: body, actions: [
      { label: 'Cancel' },
      { label: 'Save', primary: true, handler: async function () {
          var r = await Api.post('/headcounts/acting/' + centreId, { user_ids: picked });
          toast('✅', 'Acting supervisor saved', (r.acting || []).map(function (p) { return p.name; }).join(', ') || 'None named', '#16A34A');
          if (typeof done === 'function') done(r.acting || []);
        } },
    ] });
  }

  // ─── small pieces for other screens ────────────────────────────────
  function lastChip(room) {
    var l = room.last;
    if (!l) return '<span class="hcs-chip" data-kt-no-tone style="background:#F1F5F9;color:#64748B;">No count today</span>';
    if (!l.all_accounted && !l.resolved) return '<span class="hcs-chip" data-kt-no-tone style="background:#FEF2F2;color:#B91C1C;">⚠️ ' + l.counted + '/' + l.expected + ' at ' + esc(l.local) + '</span>';
    var stale = l.minutes_ago > 60;
    return '<span class="hcs-chip" data-kt-no-tone style="background:' + (stale ? '#FFFBEB' : '#ECFDF5') + ';color:' + (stale ? '#B45309' : '#047857') + ';">✓ ' + esc(ago(l.minutes_ago)) + '</span>';
  }

  function todayBar(getRoomId) {
    css();
    var el = document.createElement('div'); el.className = 'hc-today';
    el.innerHTML = '<button type="button" class="go">👀 Headcount</button><span class="meta">Name-to-face count of the children signed in. Do one at every transition, washroom trip, outdoor in/out and dismissal.</span>';
    function meta() {
      var rid = getRoomId(); if (!rid) return;
      Api.get('/headcounts/start?room_id=' + rid + '&t=' + Date.now()).then(function (d) {
        var m = el.querySelector('.meta'); if (!m) return;
        m.innerHTML = '<b>' + (d.children || []).length + '</b> signed in · '
          + (d.last ? 'last count ' + esc(ago(d.last.minutes_ago)) + (d.last.all_accounted ? ' ✓' : ' <b style="color:#B91C1C;">(' + d.last.counted + '/' + d.last.expected + ')</b>') : 'no count yet today');
      }).catch(function () {});
    }
    el.querySelector('.go').onclick = function () { var rid = getRoomId(); if (rid) open({ room_id: rid, onSaved: function () { setTimeout(meta, 300); } }); };
    meta();
    return el;
  }

  async function dashboardCard(container) {
    css();
    var box = document.createElement('div'); box.className = 'hcs-card'; box.style.marginTop = '18px';
    container.appendChild(box);
    var d;
    try { d = await Api.get('/headcounts/overview'); } catch (e) { box.remove(); return; }
    if (!d.centres || !d.centres.length) { box.remove(); return; }
    box.innerHTML = '<div class="hcs-row" style="margin-bottom:6px;"><div style="font-weight:800;font-size:15px;flex:1;">👀 Supervision</div><a href="#headcounts" class="hcs-b" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit;">Headcounts</a></div>'
      + d.centres.map(function (c) {
          return '<div style="padding:6px 0;border-top:1px solid #F1F5F9;"><div style="font-size:13px;"><b>' + esc(c.name) + '</b> · Acting supervisor: '
            + (c.acting.length ? esc(c.acting.map(function (p) { return p.name; }).join(' → ')) : '<span style="color:#B45309;font-weight:700;">not named</span>')
            + (c.today.open ? ' · <b style="color:#B91C1C;">' + c.today.open + ' missing-child count open</b>' : '') + '</div>'
            + '<div class="hcs-row" style="margin-top:4px;">' + c.rooms.filter(function (r) { return r.present > 0 || r.last; }).map(function (r) {
                return '<span style="font-size:12.5px;">' + esc(r.name) + ' (' + r.present + ') ' + lastChip(r) + '</span>';
              }).join(' ') + '</div></div>';
        }).join('');
  }

  // ─── the screen ────────────────────────────────────────────────────
  var state = { from: '', to: '', centre: '', room: '', context: '', result: '' };

  async function render(main, ctx) {
    css();
    var params = (ctx && ctx.params) || {};
    main.innerHTML = '<div class="hcs-wrap" style="color:#64748B;">Loading headcounts…</div>';
    var q = [];
    ['from', 'to', 'context', 'result'].forEach(function (k) { if (state[k]) q.push(k + '=' + encodeURIComponent(state[k])); });
    if (state.centre) q.push('centre_id=' + state.centre);
    if (state.room) q.push('room_id=' + state.room);
    if (params.id) q.push('id=' + encodeURIComponent(params.id));
    var ov, lg;
    try { var res = await Promise.all([Api.get('/headcounts/overview'), Api.get('/headcounts' + (q.length ? '?' + q.join('&') : ''))]); ov = res[0]; lg = res[1]; }
    catch (e) { main.innerHTML = '<div class="hcs-wrap" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    state.from = lg.from; state.to = lg.to;
    var on = ov.jurisdiction === 'CA-ON';

    var h = '<div class="hcs-wrap"><div class="kt-hero" style="background:linear-gradient(135deg,#0F172A 0%,#1E3A8A 60%,#2563EB 100%);">'
      + '<div class="kt-hero-greet">👀 SUPERVISION</div><h1>Headcounts</h1><div class="kt-hero-sub">'
      + (on ? 'O. Reg. 137/15 s.11 (from 1 January 2027): count children at transitions, washroom trips, arrival and dismissal, outings and the playground gates, and know who acts for the supervisor. '
            : 'Name-to-face counts at transitions, washroom trips, arrival and dismissal, outings and the playground gates. ')
      + 'Times are in ' + esc(lg.timezone) + '.</div></div>';

    /* SUB-TABS (2026-10-15, Anthony: "headcounts section add subtab for logs"): Rooms now
       (take counts, acting supervisor) and Logs (the history and its filters). The filters
       re-render the whole page, so the open tab is kept in state; a link to one headcount
       (?id=) or ?tab=logs opens Logs. */
    if (params.id || params.tab === 'logs') state.tab = 'logs';
    if (!state.tab) { try { state.tab = sessionStorage.getItem('kt_hc_tab') || 'now'; } catch (e) { state.tab = 'now'; } }
    h += '<div class="kt-subtabs" role="tablist" style="display:flex;gap:6px;border-bottom:1px solid #E2E8F0;margin:12px 0 2px;">'
      + [['now', '👀 Rooms now'], ['logs', '📜 Logs']].map(function (t) {
          var on = state.tab === t[0];
          return '<button type="button" role="tab" data-hc-tab="' + t[0] + '" aria-selected="' + on + '" style="background:none;border:0;border-bottom:2px solid '
            + (on ? '#1F6FB2' : 'transparent') + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B') + ';cursor:pointer;">' + t[1] + '</button>';
        }).join('') + '</div>';

    // rooms now
    h += '<div class="hcs-card" data-hc-pane="now"><div style="font-weight:800;font-size:15px;margin-bottom:4px;">Rooms now</div>';
    (ov.centres || []).forEach(function (c) {
      h += '<div style="padding:8px 0 2px;border-top:2px solid #F1F5F9;margin-top:6px;"><div class="hcs-row"><b style="font-size:14px;">' + esc(c.name) + '</b>'
        + '<span style="font-size:12.5px;color:#475569;flex:1;">Acting supervisor: ' + (c.acting.length ? esc(c.acting.map(function (p) { return p.name; }).join(' → ')) : '<b style="color:#B45309;">not named</b>') + '</span>'
        + (c.can_manage ? '<button class="hcs-b" data-acting="' + c.id + '">Acting supervisor…</button>' : '') + '</div>'
        + '<div style="font-size:12px;color:#64748B;margin:2px 0 4px;">Today: ' + c.today.total + ' count' + (c.today.total === 1 ? '' : 's') + (c.today.missed ? ', <b style="color:#B91C1C;">' + c.today.missed + ' with someone not seen</b>' + (c.today.open ? ' (' + c.today.open + ' open)' : '') : '') + '</div>';
      (c.rooms || []).forEach(function (r) {
        h += '<div class="hcs-room"><span class="rn">' + esc(r.name) + '</span><span style="font-size:12.5px;color:#475569;">' + r.present + ' here</span>' + lastChip(r)
          + '<button class="hcs-b p" data-take="' + r.id + '"' + (r.present ? '' : ' disabled title="Nobody is signed in"') + '>👀 Headcount</button></div>';
      });
      if (!(c.rooms || []).length) h += '<div style="font-size:12.5px;color:#64748B;padding:6px 0;">No rooms.</div>';
    });
    h += '</div>';

    // log
    var ctxOpts = Object.keys(lg.contexts || {}).map(function (k) { return '<option value="' + k + '"' + (state.context === k ? ' selected' : '') + '>' + esc(lg.contexts[k]) + '</option>'; }).join('');
    h += '<div class="hcs-card" data-hc-pane="logs"><div class="hcs-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;min-width:120px;">Logs' + (params.id ? ' — one headcount <a href="#headcounts" style="font-size:12px;">show all</a>' : '') + '</div>'
      + '<input class="hcs-i" type="date" data-f="from" value="' + esc(lg.from) + '" title="From"><input class="hcs-i" type="date" data-f="to" value="' + esc(lg.to) + '" title="To">'
      + '<select class="hcs-i" data-f="centre"><option value="">All centres</option>' + (lg.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>'
      + '<select class="hcs-i" data-f="room"><option value="">All rooms</option>' + (lg.rooms || []).filter(function (r) { return !state.centre || String(r.centre_id) === String(state.centre); }).map(function (r) { return '<option value="' + r.id + '"' + (String(r.id) === String(state.room) ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') + '</select>'
      + '<select class="hcs-i" data-f="context"><option value="">Any moment</option>' + ctxOpts + '</select>'
      + '<select class="hcs-i" data-f="result"><option value="">Any result</option><option value="missed"' + (state.result === 'missed' ? ' selected' : '') + '>Someone not seen</option><option value="ok"' + (state.result === 'ok' ? ' selected' : '') + '>All accounted for</option></select></div>'
      + '<div style="font-size:12.5px;color:#475569;margin-bottom:6px;">' + lg.summary.total + ' headcount' + (lg.summary.total === 1 ? '' : 's') + (lg.summary.missed ? ' · <b style="color:#B91C1C;">' + lg.summary.missed + ' with someone not seen</b>' : '') + '</div>'
      + ((lg.headcounts || []).length
        ? '<div style="overflow-x:auto;"><table class="hcs-t" data-kt-no-bulk><thead><tr><th>When</th><th>Where</th><th>Moment</th><th>Result</th><th>Not seen</th><th>Counted by</th><th>Follow-up</th><th></th></tr></thead><tbody>'
          + lg.headcounts.map(function (r) {
              var res = r.all_accounted ? '<span class="hcs-chip" data-kt-no-tone style="background:#ECFDF5;color:#047857;">' + r.counted + '/' + r.expected + ' ✓</span>'
                : '<span class="hcs-chip" data-kt-no-tone style="background:#FEF2F2;color:#B91C1C;">' + r.counted + '/' + r.expected + '</span>';
              var fu = [];
              if (r.alerted) fu.push('🚨 Alerted ' + esc(r.alerted));
              if (r.resolved) fu.push('✅ Found ' + esc(r.resolved) + (r.resolution_note ? ' — ' + esc(r.resolution_note) : ''));
              if (!r.all_accounted && !r.resolved) fu.push('<b style="color:#B91C1C;">Open</b>');
              return '<tr><td style="white-space:nowrap;">' + esc(r.local) + '</td><td>' + esc(r.where) + '<div style="font-size:11.5px;color:#64748B;">' + esc(r.centre_name) + (r.location ? ' · ' + esc(r.location) : '') + '</div></td>'
                + '<td>' + esc(r.context_label) + (r.note ? '<div style="font-size:11.5px;color:#64748B;">' + esc(r.note) + '</div>' : '') + '</td><td>' + res + '</td>'
                + '<td>' + (r.missing.length ? '<b style="color:#B91C1C;">' + esc(r.missing.join(', ')) + '</b>' : '—') + '</td><td>' + esc(r.taken_by) + '</td><td style="font-size:12.5px;">' + (fu.join('<br>') || '—') + '</td>'
                + '<td style="white-space:nowrap;">' + (r.can_act ? '<button class="hcs-b" data-alert="' + r.id + '" type="button">Alert director</button> <button class="hcs-b" data-found="' + r.id + '" type="button">Mark found</button>' : '') + '</td></tr>';
            }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No headcounts in this period.</div>')
      + '</div>';
    h += '<div class="hcs-card" data-hc-pane="logs" style="font-size:13.5px;color:#475569;">📄 Need it as a document? <a href="#reports" style="font-weight:700;">Reports → Headcounts</a> gives one day (or any range) as PDF or Excel, and can email it to you daily.</div>';

    /* The reminder setting lives in Settings → Room Controls → Headcounts since
       2026-10-15 (Anthony); the page says where, for the people who can change it. */
    if (ov.is_admin) {
      h += '<div class="hcs-card" data-hc-pane="now" style="font-size:13.5px;color:#475569;">🔔 Routine headcount reminders are '
        + (ov.reminders && ov.reminders.enabled ? '<b>on, every ' + esc(String(ov.reminders.minutes)) + ' minutes</b>' : '<b>off</b>')
        + '. <a href="#educator-rooms?tab=headcounts" style="font-weight:700;">Change in Room Controls →</a></div>';
    }
    h += '</div>';
    main.innerHTML = h;
    function showTab(t) {
      state.tab = t;
      try { sessionStorage.setItem('kt_hc_tab', t); } catch (e) {}
      main.querySelectorAll('[data-hc-pane]').forEach(function (p) { p.style.display = p.getAttribute('data-hc-pane') === t ? '' : 'none'; });
      main.querySelectorAll('[data-hc-tab]').forEach(function (b) {
        var on = b.getAttribute('data-hc-tab') === t;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.style.borderBottomColor = on ? '#1F6FB2' : 'transparent';
        b.style.color = on ? '#0F172A' : '#64748B';
      });
    }
    main.querySelectorAll('[data-hc-tab]').forEach(function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-hc-tab')); }); });
    showTab(state.tab);

    function reload() { render(main, { params: {} }); }
    main.querySelectorAll('[data-take]').forEach(function (b) { b.onclick = function () { open({ room_id: +b.getAttribute('data-take'), onSaved: function () { setTimeout(reload, 400); } }); }; });
    main.querySelectorAll('[data-acting]').forEach(function (b) { b.onclick = function () { editActing(+b.getAttribute('data-acting'), reload); }; });
    main.querySelectorAll('[data-f]').forEach(function (el) {
      el.addEventListener('change', function () { var k = el.getAttribute('data-f'); state[k] = el.value; if (k === 'centre') state.room = ''; if (params.id) location.hash = '#headcounts'; else reload(); });
    });
    main.querySelectorAll('[data-alert]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: 'Send the missing-child alert?', description: 'Bells and pushes this centre\'s director(s), the agency admins and the acting supervisors now.', okLabel: 'Send alert' }) : true;
        if (!ok) return;
        try { var x = await Api.post('/headcounts/' + b.getAttribute('data-alert') + '/alert', {}); toast('🚨', 'Alert sent', x.notified + ' notified', '#B91C1C'); reload(); } catch (e) { toast('⚠️', 'Could not send', errMsg(e), '#DC2626'); }
      });
    });
    main.querySelectorAll('[data-found]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var note = KT.prompt ? await KT.prompt({ title: 'Everyone found?', description: 'Where was the child found?', placeholder: 'e.g. in the washroom with an educator', okLabel: 'Mark found' }) : '';
        if (note === null || note === undefined || note === false) return;
        if (typeof note === 'object') note = note.value || '';
        try { await Api.post('/headcounts/' + b.getAttribute('data-found') + '/resolve', { note: String(note || '').trim() || null }); toast('✅', 'Marked as found', '', '#16A34A'); reload(); } catch (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); }
      });
    });
    var rem = main.querySelector('[data-rem]'), remMin = main.querySelector('[data-rem-min]');
    function saveRem() {
      Api.post('/headcounts/settings', { enabled: rem.checked, minutes: +remMin.value })
        .then(function () { toast('🔔', rem.checked ? 'Headcount reminders on' : 'Headcount reminders off', rem.checked ? 'Every ' + remMin.value + ' minutes without a count' : '', '#16A34A'); })
        .catch(function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
    }
    if (rem) { rem.addEventListener('change', saveRem); remMin.addEventListener('change', function () { if (rem.checked) saveRem(); }); }

    if (params.take) { var t = params.take; params.take = null; try { history.replaceState(null, '', '#headcounts'); } catch (e) {} open({ room_id: +t, onSaved: function () { setTimeout(reload, 400); } }); }
  }

  /* Settings → Room Controls → Headcounts (screen-educator-rooms.js). */
  async function paintSettings(pane) {
    css();
    var ov;
    try { ov = await Api.get('/headcounts/overview'); } catch (e) { pane.innerHTML = '<div class="kt-card" style="color:#B91C1C;">' + esc(errMsg(e)) + '</div>'; return; }
    var r = ov.reminders || { enabled: false, minutes: 30 };
    var mins = [15, 20, 30, 45, 60, 90, 120];
    var ed = !!ov.is_admin, dis = ed ? '' : ' disabled';
    pane.innerHTML = '<div class="kt-card" style="max-width:760px;"><div class="kt-card-header"><h3 class="kt-card-title">👀 Routine headcount reminders</h3></div>'
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-4px 0 10px;">When a room has gone this long without a headcount, its clocked-in educators get a bell and a push. Applies to every centre in the agency. Off by default.</div>'
      + '<div class="hcs-row" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" data-rem' + (r.enabled ? ' checked' : '') + dis + '> Remind educators after</label>'
      + '<select class="hcs-i" data-rem-min' + dis + '>' + mins.map(function (m) { return '<option value="' + m + '"' + (r.minutes === m ? ' selected' : '') + '>' + m + ' minutes</option>'; }).join('') + '</select>'
      + '<span style="font-size:13.5px;">without a count.</span></div>'
      + (ed ? '' : '<div style="font-size:12.5px;color:#64748B;margin-top:12px;">Only agency administrators can change this.</div>')
      + '<div style="font-size:12.5px;margin-top:12px;"><a href="#headcounts">Open Headcounts →</a></div></div>';
    if (!ed) return;
    var rem2 = pane.querySelector('[data-rem]'), min2 = pane.querySelector('[data-rem-min]');
    function save2() {
      Api.post('/headcounts/settings', { enabled: rem2.checked, minutes: +min2.value })
        .then(function () { toast('🔔', rem2.checked ? 'Headcount reminders on' : 'Headcount reminders off', rem2.checked ? 'Every ' + min2.value + ' minutes without a count' : '', '#16A34A'); })
        .catch(function (e) { toast('⚠️', 'Could not save', errMsg(e), '#DC2626'); });
    }
    rem2.addEventListener('change', save2);
    min2.addEventListener('change', function () { if (rem2.checked) save2(); });
  }

  KT.Headcount = { open: open, close: close, editActing: editActing, dashboardCard: dashboardCard, todayBar: todayBar, paintSettings: paintSettings };
  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) { KT.Shell.registerScreen(r + ':headcounts', render); });
})(window);
