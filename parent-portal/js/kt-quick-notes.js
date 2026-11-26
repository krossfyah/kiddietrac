/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — QUICK NOTES (2026-11-26).
   Anthony: "add a notepad widget option and a shortcut at the top bar for quick notes that are logged for each user
   or the agency - full sweep".
   • Two kinds, chosen per note: "Just me" (private: only the person who wrote it, ever - super admins included) and
     "Agency" (every staff member who can see it may read and edit it; agency-wide or one centre). Server rules:
     App\Support\QuickNotes. Parents never have a notepad.
   • Where it shows: the 📓 top-bar button (admins, directors, super admins) opens a drawer (a bottom sheet on a
     phone); the Notes screen (#notes) in every staff role's menu (educators and home visitors: their top nav); the
     "Quick notes" dashboard widget; a Notes card on child, family and staff records; reminders in the right panel.
   • Quick add: type and press Enter (Shift+Enter = new line). Pin, colour, search, edit, history, delete per note.
   KT.quickNotes = { toggle, open(id), openDrawer, closeDrawer, widget(), recordCard(type, id), render(main) }
   Every change fires window 'kt:quick-notes-changed' so the right panel and the other open views refresh.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  if (KT.quickNotes) return;
  var document = window.document;

  function T(s) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function api() { return KT.Api; }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function errMsg(e) { return (e && ((e.data && e.data.message) || e.message)) || T('Something went wrong.'); }
  function changed() { try { window.dispatchEvent(new CustomEvent('kt:quick-notes-changed')); } catch (e) {} }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function roles() { return (user().roles || []); }
  function isWideRole() { var r = roles(); return r.indexOf('platform_admin') !== -1 || r.indexOf('agency_admin') !== -1 || r.indexOf('centre_director') !== -1; }

  var COLOURS = {
    yellow: { l: 'Yellow', bar: '#F59E0B', bg: '#FFFBEB' }, green: { l: 'Green', bar: '#16A34A', bg: '#F0FDF4' },
    blue: { l: 'Blue', bar: '#2563EB', bg: '#EFF6FF' }, pink: { l: 'Pink', bar: '#DB2777', bg: '#FDF2F8' },
    purple: { l: 'Purple', bar: '#7C3AED', bg: '#F5F3FF' }, orange: { l: 'Orange', bar: '#EA580C', bg: '#FFF7ED' }
  };
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  // "2026-11-26 14:05" (already agency time) -> "Nov 26, 2:05 PM"; a bare date -> "Nov 26".
  function when(s, withYear) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(s || ''); if (!m) return '';
    var out = T(MON[+m[2] - 1]) + ' ' + (+m[3]) + (withYear ? ', ' + m[1] : '');
    if (m[4] != null) { var h = +m[4]; out += (withYear ? ' ' : ', ') + ((h % 12) || 12) + ':' + m[5] + ' ' + (h < 12 ? 'AM' : 'PM'); }
    return out;
  }

  /* ── styles (tokens on .qn-root). Dark only when the PORTAL is dark (html.kt-dark / data-theme=dark), as the right
     panel: the desktop portal has no OS-dark mode, so following the OS drew a dark drawer on a light page. ──────── */
  function css() {
    if (document.getElementById('kt-qn-css')) return;
    var s = document.createElement('style'); s.id = 'kt-qn-css';
    var light = '--qn-bg:#FFFFFF;--qn-card:#FFFFFF;--qn-line:#E2E8F0;--qn-ink:#0F172A;--qn-sub:#64748B;--qn-in:#FFFFFF;--qn-inb:#CBD5E1;--qn-soft:#F8FAFC;--qn-chip:#F1F5F9;--qn-accent:#0F766E;';
    var dark = '--qn-bg:#0F172A;--qn-card:#111C31;--qn-line:#24324A;--qn-ink:#E2E8F0;--qn-sub:#94A3B8;--qn-in:#0B1426;--qn-inb:#334155;--qn-soft:#0B1426;--qn-chip:#1E293B;--qn-accent:#5EEAD4;';
    s.textContent = ''
      + '.qn-root{' + light + 'color:var(--qn-ink);font-size:13px;}'
      + 'html.kt-dark .qn-root,html[data-theme="dark"] .qn-root{' + dark + '}'
      + '.qn-tabs{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
      + '.qn-tab{height:28px;padding:0 12px;border-radius:999px;border:1px solid var(--qn-line);background:var(--qn-card);color:var(--qn-sub);font-weight:700;font-size:12.5px;cursor:pointer;}'
      + '.qn-tab.on{background:#0F172A;border-color:#0F172A;color:#fff;}'
      + 'html.kt-dark .qn-tab.on,html[data-theme="dark"] .qn-tab.on{background:#5EEAD4;border-color:#5EEAD4;color:#0F172A;}'
      + '.qn-in{height:30px;padding:0 10px;border:1px solid var(--qn-inb);border-radius:8px;background:var(--qn-in);color:var(--qn-ink);font:inherit;font-size:13px;box-sizing:border-box;min-width:0;}'
      + 'textarea.qn-in{height:auto;min-height:64px;padding:7px 10px;resize:vertical;line-height:1.4;width:100%;}'
      + '.qn-search{flex:1;min-width:120px;}'
      + '.qn-add{border:1px solid var(--qn-line);border-radius:12px;padding:8px;background:var(--qn-soft);margin:8px 0 10px;}'
      + '.qn-add textarea.qn-in{min-height:44px;border-color:transparent;background:transparent;padding:4px 6px;}'
      + '.qn-add textarea.qn-in:focus{outline:none;}'
      + '.qn-add-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:4px;}'
      + '.qn-hint{font-size:11px;color:var(--qn-sub);margin-left:auto;}'
      + '.qn-b{height:28px;padding:0 11px;border-radius:8px;border:1px solid var(--qn-inb);background:var(--qn-card);color:var(--qn-ink);font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;}'
      + '.qn-b.p{background:#0F766E;border-color:#0F766E;color:#fff;}'
      + '.qn-seg{display:inline-flex;border:1px solid var(--qn-inb);border-radius:8px;overflow:hidden;}'
      + '.qn-seg button{height:26px;padding:0 9px;border:0;background:var(--qn-card);color:var(--qn-sub);font-weight:700;font-size:12px;cursor:pointer;}'
      + '.qn-seg button.on{background:#0F766E;color:#fff;}'
      + '.qn-dots{display:inline-flex;gap:4px;align-items:center;}'
      + '.qn-dot{width:16px;height:16px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;box-sizing:border-box;}'
      + '.qn-dot.on{border-color:var(--qn-ink);}'
      + '.qn-dot.none{background:var(--qn-card);border:1px dashed var(--qn-inb);}'
      + '.qn-dot.none.on{border:2px solid var(--qn-ink);}'
      + '.qn-list{display:flex;flex-direction:column;gap:8px;}'
      + '.qn-grid .qn-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));align-items:start;}'
      + '.qn-card{position:relative;border:1px solid var(--qn-line);border-left:4px solid var(--qn-line);border-radius:10px;padding:8px 10px 6px;background:var(--qn-card);}'
      + '.qn-card .qn-t{font-weight:800;font-size:13.5px;line-height:1.3;word-break:break-word;padding-right:22px;}'
      + '.qn-card .qn-body{white-space:pre-wrap;word-break:break-word;line-height:1.4;margin-top:2px;}'
      + '.qn-card.clamp .qn-body{display:-webkit-box;-webkit-line-clamp:6;-webkit-box-orient:vertical;overflow:hidden;}'
      + '.qn-pin{position:absolute;top:6px;right:8px;font-size:13px;}'
      + '.qn-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px;font-size:11.5px;color:var(--qn-sub);}'
      + '.qn-chip{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:999px;background:var(--qn-chip);color:var(--qn-sub);font-size:11.5px;font-weight:700;white-space:nowrap;border:0;font-family:inherit;}'
      + 'button.qn-chip{cursor:pointer;}'
      + '.qn-chip.due{background:#FEE2E2;color:#B91C1C;}'
      + '.qn-chip.soon{background:#FEF3C7;color:#92400E;}'
      + '.qn-acts{display:flex;gap:2px;margin-left:auto;}'
      + '.qn-ib{width:26px;height:26px;border-radius:7px;border:0;background:transparent;cursor:pointer;font-size:13px;line-height:26px;padding:0;color:var(--qn-sub);}'
      + '.qn-ib:hover{background:var(--qn-chip);}'
      + '.qn-empty{color:var(--qn-sub);text-align:center;padding:18px 8px;font-size:13px;}'
      + '.qn-sub{font-size:12px;color:var(--qn-sub);}'
      + '.qn-l{display:block;font-size:11px;font-weight:800;color:var(--qn-sub);text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px;}'
      + '.qn-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
      + '.qn-res{border:1px solid var(--qn-line);border-radius:8px;margin-top:4px;max-height:160px;overflow:auto;}'
      + '.qn-res button{display:block;width:100%;text-align:left;padding:6px 10px;border:0;background:var(--qn-card);color:var(--qn-ink);cursor:pointer;font:inherit;}'
      + '.qn-res button:hover{background:var(--qn-chip);}'
      /* the drawer / bottom sheet */
      + '.qn-dim{position:fixed;inset:0;z-index:9400;background:rgba(8,28,65,.18);opacity:0;transition:opacity .14s ease;}'
      + '.qn-dim.on{opacity:1;}'
      + '.qn-drawer{position:fixed;top:0;right:0;bottom:0;width:400px;max-width:100vw;z-index:9401;display:flex;flex-direction:column;background:var(--qn-bg);box-shadow:-12px 0 40px rgba(15,23,42,.22);transform:translateX(100%);transition:transform .16s ease;}'
      + '.qn-drawer.on{transform:none;}'
      + '.qn-head{position:relative;flex:0 0 auto;padding:12px 44px 11px 16px;color:#fff;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '.qn-head h2{margin:0;font-size:15px;font-weight:800;color:#fff;}'
      + '.qn-head .qn-hs{font-size:11.5px;opacity:.85;margin-top:1px;}'
      + '.qn-x{position:absolute;top:9px;right:10px;width:28px;height:28px;border-radius:8px;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:15px;cursor:pointer;line-height:28px;padding:0;}'
      + '.qn-x:hover{background:rgba(255,255,255,.26);}'
      + '.qn-scroll{flex:1 1 auto;overflow:auto;padding:10px 12px 16px;scrollbar-width:thin;}'
      + '.qn-foot{flex:0 0 auto;border-top:1px solid var(--qn-line);padding:7px 12px;display:flex;justify-content:flex-end;}'
      + '@media (max-width:700px){.qn-drawer{top:auto;left:0;width:auto;height:86vh;height:86dvh;border-radius:18px 18px 0 0;transform:translateY(100%);box-shadow:0 -12px 40px rgba(15,23,42,.25);}'
      + '.qn-drawer .qn-head{border-radius:18px 18px 0 0;}'
      + '.qn-drawer .qn-head:before{content:"";position:absolute;top:5px;left:50%;width:36px;height:4px;margin-left:-18px;border-radius:4px;background:rgba(255,255,255,.45);}}'
      /* the screen */
      + '.qn-wrap{padding:20px 24px;margin:0 auto;}'
      + '@media (max-width:700px){.qn-wrap{padding:12px;}}'
      + '.qn-panel{background:var(--qn-card);border:1px solid var(--qn-line);border-radius:12px;padding:12px 14px;margin-top:14px;}'
      /* record card */
      + '.qn-rec{border:1px solid var(--qn-line);border-radius:12px;padding:10px 12px;margin-top:12px;background:var(--qn-card);}'
      + '.qn-rec-h{display:flex;align-items:center;gap:8px;font-weight:800;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:var(--qn-sub);}';
    document.head.appendChild(s);
  }
  function colourCss(n) {
    var c = n.colour && COLOURS[n.colour];
    if (!c) return '';
    return 'border-left-color:' + c.bar + ';';
  }

  /* ── data ─────────────────────────────────────────────────────────────────── */
  var lastCtx = null;
  function list(params) {
    var q = [];
    Object.keys(params || {}).forEach(function (k) { if (params[k] != null && params[k] !== '') q.push(k + '=' + encodeURIComponent(params[k])); });
    return api().get('/quick-notes' + (q.length ? '?' + q.join('&') : '')).then(function (j) { if (j && j.context) lastCtx = j.context; return j || { notes: [] }; });
  }
  function save(id, body) {
    return (id ? api().patch('/quick-notes/' + id, body, { quiet: true }) : api().post('/quick-notes', body, { quiet: true }))
      .then(function (j) { changed(); return j && j.note; });
  }

  /* ── one note card ────────────────────────────────────────────────────────── */
  function noteHtml(n, opts) {
    var today = (lastCtx && lastCtx.today) || '';
    var chips = [];
    if (n.visibility === 'private') chips.push('<span class="qn-chip" data-kttip="' + esc(T('Only you can see this note')) + '">🔒 ' + esc(T('Just me')) + '</span>');
    else chips.push('<span class="qn-chip" data-kttip="' + esc(n.centre_name ? T('Staff at this centre can see and edit it') : T('All staff can see and edit it')) + '">👥 ' + esc(n.centre_name || T('Agency-wide')) + '</span>');
    if (n.remind_on) {
      var cls = n.remind_on <= today ? ' due' : (n.remind_on <= addDays(today, 2) ? ' soon' : '');
      chips.push('<span class="qn-chip' + cls + '" data-kttip="' + esc(T('Reminder')) + '">⏰ ' + esc(when(n.remind_on)) + '</span>');
    }
    if (n.link && !(opts && opts.onRecord)) {
      var ic = { child: '🧒', family: '🏠', staff: '🪪' }[n.link.type] || '🔗';
      chips.push(n.link.id ? '<button type="button" class="qn-chip" data-qn-go="' + esc(n.link.type) + ':' + n.link.id + '" data-kttip="' + esc(T('Open the record')) + '"><span data-no-i18n>' + ic + ' ' + esc(n.link.label) + '</span></button>'
        : '<span class="qn-chip">' + ic + ' ' + esc(T('A record you cannot open')) + '</span>');
    }
    var who = n.visibility === 'agency' ? ((n.updated_by && n.updated_by !== n.author ? T('Edited by') + ' ' + n.updated_by : (n.author ? n.author : '')) + ' · ') : '';
    var acts = '<span class="qn-acts">'
      + '<button type="button" class="qn-ib" data-qn-act="pin" data-kttip="' + esc(n.pinned ? T('Unpin') : T('Pin to top')) + '" aria-label="' + esc(n.pinned ? T('Unpin') : T('Pin to top')) + '">' + (n.pinned ? '📍' : '📌') + '</button>'
      + '<button type="button" class="qn-ib" data-qn-act="colour" data-kttip="' + esc(T('Colour')) + '" aria-label="' + esc(T('Colour')) + '">🎨</button>'
      + '<button type="button" class="qn-ib" data-qn-act="edit" data-kttip="' + esc(T('Edit')) + '" aria-label="' + esc(T('Edit')) + '">✏️</button>'
      + (n.visibility === 'agency' ? '<button type="button" class="qn-ib" data-qn-act="history" data-kttip="' + esc(T('History')) + '" aria-label="' + esc(T('History')) + '">🕘</button>' : '')
      + (n.can_delete ? '<button type="button" class="qn-ib" data-qn-act="delete" data-kttip="' + esc(T('Delete')) + '" aria-label="' + esc(T('Delete')) + '">🗑️</button>' : '')
      + '</span>';
    return '<div class="qn-card' + (opts && opts.clamp ? ' clamp' : '') + '" data-qn-id="' + n.id + '" style="' + colourCss(n) + '">'
      + (n.pinned ? '<span class="qn-pin" aria-label="' + esc(T('Pinned')) + '">📌</span>' : '')
      + (n.title ? '<div class="qn-t" data-no-i18n>' + esc(n.title) + '</div>' : '')
      + (n.body ? '<div class="qn-body" data-no-i18n>' + esc(n.body) + '</div>' : '')
      + '<div class="qn-meta">' + chips.join('') + '</div>'
      + '<div class="qn-meta"><span data-no-i18n>' + esc(who) + esc(when(n.updated_local)) + '</span>' + acts + '</div>'
      + '</div>';
  }
  function addDays(d, n) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || ''); if (!m) return d;
    var t = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3] + n));
    return t.getUTCFullYear() + '-' + ('0' + (t.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + t.getUTCDate()).slice(-2);
  }

  function goRecord(spec) {
    var p = String(spec || '').split(':'), type = p[0], id = +p[1];
    if (!id) return;
    closeDrawer();
    if (type === 'child') location.hash = (isWideRole() ? '#child-detail?id=' : '#child-record?id=') + id;
    else if (type === 'family') { if (KT.openFamilyRecord) KT.openFamilyRecord(id); else location.hash = '#admin-families'; }
    else if (type === 'staff' && KT.openUserRecord) KT.openUserRecord(id);
  }

  /* ── the list view (drawer, screen, widget and record card all use it) ─────── */
  // opts: { tab:'mine'|'agency'|'all', grid, limit, link:{type,id}, compact, onRecord }
  function mountList(host, opts) {
    css();
    opts = opts || {};
    var st = { tab: opts.tab || 'mine', q: '', notes: [], colour: '', vis: opts.link ? 'agency' : null, centre: '' };
    var root = document.createElement('div'); root.className = 'qn-root' + (opts.grid ? ' qn-grid' : '');
    host.innerHTML = ''; host.appendChild(root);
    var timer = null, seq = 0;

    function addBoxHtml() {
      var ctx = lastCtx || {};
      var share = ctx.can_share;
      var vis = st.vis || (st.tab === 'agency' ? 'agency' : 'private');
      var centres = (ctx.centres || []);
      var h = '<form class="qn-add" autocomplete="off">'
        + '<textarea class="qn-in" name="body" rows="2" maxlength="5000" placeholder="' + esc(opts.link ? T('Add a note about this record…') : T('Write a quick note…')) + '" aria-label="' + esc(T('New note')) + '"></textarea>'
        + '<div class="qn-add-row">';
      if (share) h += '<span class="qn-seg" role="group" aria-label="' + esc(T('Who sees it')) + '"><button type="button" data-qn-vis="private" class="' + (vis === 'private' ? 'on' : '') + '" data-kttip="' + esc(T('Only you can see this note')) + '">🔒 ' + esc(T('Just me')) + '</button><button type="button" data-qn-vis="agency" class="' + (vis === 'agency' ? 'on' : '') + '" data-kttip="' + esc(T('Staff can see and edit it')) + '">👥 ' + esc(T('Agency')) + '</button></span>';
      if (share && vis === 'agency' && !opts.link && centres.length > 1) {
        h += '<select class="qn-in" name="centre" aria-label="' + esc(T('Centre')) + '" style="height:28px;max-width:170px"><option value="">' + esc(T('Agency-wide')) + '</option>'
          + centres.map(function (c) { return '<option value="' + c.id + '"' + (String(st.centre) === String(c.id) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>';
      }
      h += dotsHtml(st.colour)
        + '<span class="qn-hint">' + esc(T('Enter saves · Shift+Enter new line')) + '</span></div></form>';
      return h;
    }
    function draw() {
      var ctx = lastCtx || {};
      var h = '';
      if (!opts.link && !opts.compact) {
        h += '<div class="qn-tabs">'
          + '<button type="button" class="qn-tab' + (st.tab === 'mine' ? ' on' : '') + '" data-qn-tab="mine">🔒 ' + esc(T('My notes')) + '</button>'
          + (ctx.can_share ? '<button type="button" class="qn-tab' + (st.tab === 'agency' ? ' on' : '') + '" data-qn-tab="agency">👥 ' + esc(T('Agency notes')) + '</button>' : '')
          + '</div>'
          // Its own row: the portal's tab-band script wraps a tab row, and an input inside it got squeezed.
          + '<div class="qn-row" style="margin-top:8px"><input class="qn-in qn-search" type="search" name="q" value="' + esc(st.q) + '" placeholder="' + esc(T('Search notes')) + '" aria-label="' + esc(T('Search notes')) + '"></div>';
        if (!ctx.can_share && lastCtx) h += '<div class="qn-sub" style="margin-top:6px">' + esc(T('Pick an agency to see and share agency notes.')) + '</div>';
      }
      h += addBoxHtml();
      var notes = opts.limit ? st.notes.slice(0, opts.limit) : st.notes;
      h += '<div class="qn-list">';
      if (!notes.length) h += '<div class="qn-empty">' + esc(st.q ? T('No notes match.') : (opts.link ? T('No notes on this record yet.') : (st.tab === 'agency' ? T('No agency notes yet.') : T('No notes yet. Write one above.')))) + '</div>';
      notes.forEach(function (n) { h += noteHtml(n, { clamp: opts.compact || opts.limit, onRecord: !!opts.link }); });
      h += '</div>';
      if (opts.limit && st.notes.length > opts.limit) h += '<div style="margin-top:8px;text-align:right"><button type="button" class="qn-b" data-qn-all>' + esc(T('All notes')) + ' (' + st.notes.length + ')</button></div>';
      var keepFocus = document.activeElement && root.contains(document.activeElement) && document.activeElement.name === 'q';
      root.innerHTML = h;
      if (keepFocus) { var qi = root.querySelector('input[name="q"]'); if (qi) { qi.focus(); try { qi.setSelectionRange(qi.value.length, qi.value.length); } catch (e) {} } }
    }
    function load() {
      var my = ++seq;
      var p = opts.link ? { linked_type: opts.link.type, linked_id: opts.link.id } : { tab: opts.compact ? '' : st.tab, q: st.q };
      return list(p).then(function (j) { if (my !== seq) return; st.notes = j.notes || []; draw(); }, function (e) {
        if (my !== seq) return; root.innerHTML = '<div class="qn-empty">' + esc(errMsg(e)) + '</div>';
      });
    }
    function byId(id) { for (var i = 0; i < st.notes.length; i++) if (st.notes[i].id === id) return st.notes[i]; return null; }
    function submit(form) {
      var ta = form.querySelector('textarea'), text = (ta.value || '').trim();
      if (!text) return;
      var lines = text.split('\n'), title = null, body = text;
      if (lines.length > 1 && lines[0].length <= 80) { title = lines[0].trim(); body = lines.slice(1).join('\n').trim() || null; }
      var vis = (lastCtx && lastCtx.can_share) ? (st.vis || (st.tab === 'agency' ? 'agency' : 'private')) : 'private';
      var o = { title: title, body: body, visibility: vis, colour: st.colour || null };
      var cs = form.querySelector('select[name="centre"]'); if (cs && cs.value && vis === 'agency') o.centre_id = +cs.value;
      if (opts.link) { o.linked_type = opts.link.type; o.linked_id = opts.link.id; }
      ta.disabled = true;
      save(null, o).then(function () {
        toast('📓', T('Note saved'), vis === 'agency' ? T('Shared with staff') : T('Only you can see it'), '#0F766E');
        if (!opts.link && !opts.compact && st.tab !== (vis === 'agency' ? 'agency' : 'mine')) st.tab = vis === 'agency' ? 'agency' : 'mine';
        st.vis = null; load().then(function () { var t2 = root.querySelector('.qn-add textarea'); if (t2) t2.focus(); });
      }, function (e) { ta.disabled = false; toast('⚠️', T('Could not save the note'), errMsg(e), '#DC2626'); });
    }

    root.addEventListener('keydown', function (e) {
      if (e.target.matches('.qn-add textarea') && e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(e.target.form); }
    });
    root.addEventListener('submit', function (e) { e.preventDefault(); submit(e.target); });
    root.addEventListener('input', function (e) {
      if (e.target.name !== 'q') return;
      clearTimeout(timer); var v = e.target.value;
      timer = setTimeout(function () { st.q = v.trim(); load(); }, 250);
    });
    root.addEventListener('change', function (e) { if (e.target.name === 'centre') st.centre = e.target.value; });
    root.addEventListener('click', function (e) {
      var t = e.target;
      var tab = t.closest('[data-qn-tab]'); if (tab) { st.tab = tab.getAttribute('data-qn-tab'); st.vis = null; load(); return; }
      var vb = t.closest('[data-qn-vis]'); if (vb) { st.vis = vb.getAttribute('data-qn-vis'); keepDraft(draw); return; }
      var dot = t.closest('.qn-add [data-qn-colour]'); if (dot) { st.colour = dot.getAttribute('data-qn-colour'); keepDraft(draw); return; }
      var go = t.closest('[data-qn-go]'); if (go) { goRecord(go.getAttribute('data-qn-go')); return; }
      if (t.closest('[data-qn-all]')) { openDrawer(); return; }
      var act = t.closest('[data-qn-act]'); if (!act) return;
      var card = act.closest('[data-qn-id]'), n = card && byId(+card.getAttribute('data-qn-id')); if (!n) return;
      var a = act.getAttribute('data-qn-act');
      if (a === 'pin') save(n.id, { pinned: !n.pinned }).then(load, function (er) { toast('⚠️', T('Could not save the note'), errMsg(er), '#DC2626'); });
      else if (a === 'colour') pickColour(act, n, load);
      else if (a === 'edit') openEditor(n, load);
      else if (a === 'history') openHistory(n);
      else if (a === 'delete') del(n, load);
    });
    // Redraw without losing what they were typing in the quick-add box.
    function keepDraft(fn) {
      var ta = root.querySelector('.qn-add textarea'), v = ta ? ta.value : '';
      fn();
      var t2 = root.querySelector('.qn-add textarea'); if (t2) { t2.value = v; t2.focus(); }
    }
    var onChange = function () { if (document.body.contains(root)) load(); else window.removeEventListener('kt:quick-notes-changed', onChange); };
    window.addEventListener('kt:quick-notes-changed', onChange);
    draw(); load();
    return { reload: load, root: root };
  }
  function dotsHtml(sel) {
    return '<span class="qn-dots" role="group" aria-label="' + esc(T('Colour')) + '">'
      + '<button type="button" class="qn-dot none' + (!sel ? ' on' : '') + '" data-qn-colour="" data-kttip="' + esc(T('No colour')) + '" aria-label="' + esc(T('No colour')) + '"></button>'
      + Object.keys(COLOURS).map(function (k) { return '<button type="button" class="qn-dot' + (sel === k ? ' on' : '') + '" data-qn-colour="' + k + '" style="background:' + COLOURS[k].bar + '" data-kttip="' + esc(T(COLOURS[k].l)) + '" aria-label="' + esc(T(COLOURS[k].l)) + '"></button>'; }).join('')
      + '</span>';
  }

  function pickColour(anchor, n, done) {
    var items = [{ icon: '⚪', label: T('No colour'), run: function () { save(n.id, { colour: null }).then(done); } }].concat(Object.keys(COLOURS).map(function (k) {
      return { icon: '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + COLOURS[k].bar + '"></span>', label: T(COLOURS[k].l), run: function () { save(n.id, { colour: k }).then(done, function (e) { toast('⚠️', T('Could not save the note'), errMsg(e), '#DC2626'); }); } };
    }));
    if (KT.quickPop) KT.quickPop({ anchor: anchor, items: items, label: T('Colour'), width: 200 });
  }

  function del(n, done) {
    var go = function () {
      api().delete('/quick-notes/' + n.id, { quiet: true }).then(function () { changed(); toast('🗑️', T('Note deleted'), '', '#475569'); done && done(); },
        function (e) { toast('⚠️', T('Could not delete the note'), errMsg(e), '#DC2626'); });
    };
    var p = KT.confirm ? KT.confirm({ title: T('Delete this note?'), description: n.visibility === 'agency' ? T('Staff will no longer see it. Its history stays in the audit log.') : T('This note will be gone for good.'), okLabel: T('Delete'), tone: 'danger' }) : Promise.resolve(true);
    Promise.resolve(p).then(function (ok) { if (ok) go(); });
  }

  /* ── the editor (a standard popup: forms are never quick popovers) ─────────── */
  function openEditor(n, done, preset) {
    css();
    var ctx = lastCtx || {};
    n = n || Object.assign({ id: null, visibility: ctx.can_share ? 'private' : 'private', title: '', body: '', colour: null, pinned: false, remind_on: null, centre_id: null, link: null, mine: true }, preset || {});
    var body = document.createElement('div'); body.className = 'qn-root';
    var link = n.link && n.link.id ? { type: n.link.type, id: n.link.id, label: n.link.label } : null;
    var colour = n.colour || '';
    var vis = n.visibility;
    function centreOpts() {
      return '<option value="">' + esc(T('Agency-wide')) + '</option>' + (ctx.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (+n.centre_id === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('');
    }
    body.innerHTML = ''
      + '<label class="qn-l" for="qn-e-title">' + esc(T('Title')) + '</label><input class="qn-in" id="qn-e-title" maxlength="160" style="width:100%" value="' + esc(n.title || '') + '">'
      + '<label class="qn-l" for="qn-e-body">' + esc(T('Note')) + '</label><textarea class="qn-in" id="qn-e-body" maxlength="5000" style="min-height:140px">' + esc(n.body || '') + '</textarea>'
      + '<div class="qn-row" style="gap:14px;align-items:flex-start">'
      + '<div><span class="qn-l">' + esc(T('Who sees it')) + '</span>' + (ctx.can_share && (vis === 'private' ? n.mine : true)
          ? '<span class="qn-seg" id="qn-e-vis"><button type="button" data-v="private" class="' + (vis === 'private' ? 'on' : '') + '"' + (vis === 'agency' && n.id ? ' disabled data-kttip="' + esc(T('A shared note stays shared')) + '"' : '') + '>🔒 ' + esc(T('Just me')) + '</button><button type="button" data-v="agency" class="' + (vis === 'agency' ? 'on' : '') + '">👥 ' + esc(T('Agency')) + '</button></span>'
          : '<span class="qn-chip">🔒 ' + esc(T('Just me')) + '</span>') + '</div>'
      + '<div id="qn-e-cw"' + (vis === 'agency' ? '' : ' style="display:none"') + '><span class="qn-l">' + esc(T('Centre')) + '</span><select class="qn-in" id="qn-e-centre">' + centreOpts() + '</select></div>'
      + '<div><span class="qn-l">' + esc(T('Reminder')) + '</span><input class="qn-in" type="date" id="qn-e-rem" value="' + esc(n.remind_on || '') + '"></div>'
      + '<div><span class="qn-l">' + esc(T('Colour')) + '</span><span id="qn-e-col">' + dotsHtml(colour) + '</span></div>'
      + '<div><span class="qn-l">' + esc(T('Pinned')) + '</span><label class="qn-row" style="height:30px"><input type="checkbox" id="qn-e-pin"' + (n.pinned ? ' checked' : '') + '> ' + esc(T('Pin to top')) + '</label></div>'
      + '</div>'
      + (ctx.can_share ? '<span class="qn-l">' + esc(T('Linked to')) + '</span><div id="qn-e-link"></div>' : '')
      + '<div class="qn-sub" id="qn-e-msg" style="margin-top:8px;min-height:16px"></div>';
    var linkHost = body.querySelector('#qn-e-link');
    function drawLink() {
      if (!linkHost) return;
      if (link) {
        linkHost.innerHTML = '<span class="qn-row"><span class="qn-chip" data-no-i18n>' + ({ child: '🧒', family: '🏠', staff: '🪪' }[link.type] || '🔗') + ' ' + esc(link.label) + '</span><button type="button" class="qn-b" data-unlink>' + esc(T('Remove link')) + '</button></span>';
        return;
      }
      linkHost.innerHTML = '<div class="qn-row"><select class="qn-in" id="qn-e-lt" aria-label="' + esc(T('Record type')) + '"><option value="child">' + esc(T('Child')) + '</option><option value="family">' + esc(T('Family')) + '</option><option value="staff">' + esc(T('Staff')) + '</option></select>'
        + '<input class="qn-in" id="qn-e-lq" style="flex:1;min-width:140px" placeholder="' + esc(T('Search by name (optional)')) + '" aria-label="' + esc(T('Search by name')) + '"></div><div id="qn-e-lr"></div>';
    }
    drawLink();
    var lt = null;
    body.addEventListener('input', function (e) {
      if (e.target.id !== 'qn-e-lq') return;
      clearTimeout(lt); var q = e.target.value.trim(), type = body.querySelector('#qn-e-lt').value, out = body.querySelector('#qn-e-lr');
      if (q.length < 2) { out.innerHTML = ''; return; }
      lt = setTimeout(function () {
        api().get('/quick-notes/link-search?type=' + type + '&q=' + encodeURIComponent(q)).then(function (j) {
          var r = (j && j.results) || [];
          out.innerHTML = r.length ? '<div class="qn-res">' + r.map(function (x) { return '<button type="button" data-pick="' + x.id + '" data-label="' + esc(x.label) + '" data-type="' + x.type + '" data-no-i18n>' + esc(x.label) + '</button>'; }).join('') + '</div>'
            : '<div class="qn-sub" style="margin-top:4px">' + esc(T('Nobody you can see matches.')) + '</div>';
        }, function () { out.innerHTML = ''; });
      }, 250);
    });
    body.addEventListener('click', function (e) {
      var t = e.target;
      var pk = t.closest('[data-pick]'); if (pk) { link = { type: pk.getAttribute('data-type'), id: +pk.getAttribute('data-pick'), label: pk.getAttribute('data-label') }; drawLink(); return; }
      if (t.closest('[data-unlink]')) { link = null; drawLink(); return; }
      var d = t.closest('#qn-e-col [data-qn-colour]'); if (d) { colour = d.getAttribute('data-qn-colour'); body.querySelector('#qn-e-col').innerHTML = dotsHtml(colour); return; }
      var v = t.closest('#qn-e-vis [data-v]'); if (v && !v.disabled) {
        vis = v.getAttribute('data-v');
        [].forEach.call(body.querySelectorAll('#qn-e-vis [data-v]'), function (b) { b.classList.toggle('on', b === v); });
        body.querySelector('#qn-e-cw').style.display = vis === 'agency' ? '' : 'none';
      }
    });
    var actions = [{ label: T('Cancel') }];
    actions.push({ label: n.id ? T('Save') : T('Add note'), style: 'btn-primary', busyLabel: T('Saving…'), handler: function () {
      var msg = body.querySelector('#qn-e-msg');
      var o = {
        title: body.querySelector('#qn-e-title').value.trim() || null, body: body.querySelector('#qn-e-body').value.trim() || null,
        colour: colour || null, pinned: body.querySelector('#qn-e-pin').checked, remind_on: body.querySelector('#qn-e-rem').value || null,
        visibility: vis
      };
      if (!o.title && !o.body) { msg.style.color = '#B91C1C'; msg.textContent = T('Write something first.'); return false; }
      if (vis === 'agency') { var cs = body.querySelector('#qn-e-centre'); o.centre_id = cs && cs.value ? +cs.value : null; }
      if (linkHost) { o.linked_type = link ? link.type : null; o.linked_id = link ? link.id : null; }
      if (n.id && n.visibility === 'agency') delete o.visibility;
      return save(n.id, o).then(function () { toast('📓', T('Note saved'), '', '#0F766E'); done && done(); },
        function (e) { msg.style.color = '#B91C1C'; msg.textContent = errMsg(e); return false; });
    } });
    KT.Shell.Modal.open({ title: n.id ? T('Edit note') : T('New note'), body: body, actions: actions });
    setTimeout(function () { var b = body.querySelector(n.id ? '#qn-e-body' : '#qn-e-title'); if (b) b.focus(); }, 60);
  }

  var FIELD = { title: 'Title', body: 'Note', colour: 'Colour', pinned: 'Pinned', remind_on: 'Reminder', centre_id: 'Centre', linked_type: 'Linked to', linked_id: 'Linked to', visibility: 'Who sees it', agency_id: 'Who sees it' };
  function openHistory(n) {
    css();
    var body = document.createElement('div'); body.className = 'qn-root'; body.innerHTML = '<div class="qn-empty">' + esc(T('Loading…')) + '</div>';
    KT.Shell.Modal.open({ title: T('Note history'), body: body, actions: [{ label: T('Close') }] });
    api().get('/quick-notes/' + n.id + '/history').then(function (j) {
      var rows = (j && j.history) || [];
      if (!rows.length) { body.innerHTML = '<div class="qn-empty">' + esc(T('No history yet.')) + '</div>'; return; }
      body.innerHTML = rows.map(function (r) {
        var what = r.action === 'created' ? T('Created the note') : (r.action === 'deleted' ? T('Deleted the note') : T('Changed') + ' ' + r.fields.map(function (f) { return T(FIELD[f] || f); }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', '));
        var detail = '';
        if (r.action === 'updated' && r.changes && r.changes.body) {
          detail = '<div class="qn-sub" style="white-space:pre-wrap;margin-top:3px;border-left:3px solid var(--qn-line);padding-left:8px" data-no-i18n>' + esc(String(r.changes.body.from || '').slice(0, 400)) + '</div>';
        }
        return '<div style="padding:8px 0;border-bottom:1px solid var(--qn-line)"><b data-no-i18n>' + esc(r.who) + '</b> · <span class="qn-sub">' + esc(when(r.at, true)) + '</span><div>' + esc(what) + '</div>'
          + (detail ? '<div class="qn-sub" style="margin-top:4px">' + esc(T('Before:')) + '</div>' + detail : '') + '</div>';
      }).join('');
    }, function (e) { body.innerHTML = '<div class="qn-empty">' + esc(errMsg(e)) + '</div>'; });
  }

  /* ── the drawer (bottom sheet on a phone) ──────────────────────────────────── */
  var drawer = null;
  function openDrawer() {
    css();
    if (drawer) return;
    var dim = document.createElement('div'); dim.className = 'qn-dim';
    var el = document.createElement('aside'); el.className = 'qn-drawer qn-root'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-label', T('Quick notes'));
    el.innerHTML = '<div class="qn-head"><h2>📓 ' + esc(T('Quick notes')) + '</h2><div class="qn-hs">' + esc(T('Just for you, or shared with your staff')) + '</div>'
      + '<button type="button" class="qn-x" aria-label="' + esc(T('Close')) + '" data-kttip="' + esc(T('Close')) + '">✕</button></div>'
      + '<div class="qn-scroll"></div>'
      + '<div class="qn-foot"><button type="button" class="qn-b" data-qn-screen>' + esc(T('Open Notes')) + ' →</button></div>';
    document.body.appendChild(dim); document.body.appendChild(el);
    drawer = { el: el, dim: dim };
    mountList(el.querySelector('.qn-scroll'), { tab: 'mine', compact: false });
    requestAnimationFrame(function () { dim.classList.add('on'); el.classList.add('on'); });
    dim.addEventListener('click', closeDrawer);
    el.querySelector('.qn-x').addEventListener('click', closeDrawer);
    el.querySelector('[data-qn-screen]').addEventListener('click', function () { closeDrawer(); location.hash = '#notes'; });
    el.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.querySelector('.modal.show, .modal-backdrop')) closeDrawer(); });
    if (KT.pushOverlay) KT.pushOverlay(el, closeDrawer);
    setTimeout(function () { var t = el.querySelector('.qn-add textarea'); if (t) t.focus(); }, 180);
    setPressed(true);
  }
  function closeDrawer() {
    if (!drawer) return;
    var d = drawer; drawer = null;
    d.dim.classList.remove('on'); d.el.classList.remove('on');
    setTimeout(function () { try { d.dim.remove(); d.el.remove(); } catch (e) {} }, 180);
    setPressed(false);
  }
  function setPressed(on) { var b = document.getElementById('kt-tb-notes'); if (b) b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  function toggle() { if (drawer) closeDrawer(); else openDrawer(); }

  /* Open one note (from the right panel's calendar or a link): fetch it, then the editor. */
  function open(id, o) {
    api().get('/quick-notes/' + id).then(function (j) {
      if (j && j.context) lastCtx = j.context;
      if (j && j.note) openEditor(j.note, o && o.onChange);
    }, function (e) { toast('⚠️', T('Could not open the note'), errMsg(e), '#DC2626'); });
  }

  /* ── the Notes screen ───────────────────────────────────────────────────────── */
  function render(main) {
    css();
    main.innerHTML = '<div class="qn-wrap qn-root"><div class="kt-hero" style="background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);">'
      + '<div class="kt-hero-greet">📓 ' + esc(T('NOTEPAD')) + '</div><h1>' + esc(T('Notes')) + '</h1>'
      + '<div class="kt-hero-sub">' + esc(T('Quick notes just for you, or shared with your staff. Pin, colour, link to a child, family or staff member, and set a reminder.')) + '</div></div>'
      + '<div class="qn-panel"></div></div>';
    mountList(main.querySelector('.qn-panel'), { tab: 'mine', grid: true });
  }

  /* ── dashboard widget ───────────────────────────────────────────────────────── */
  function widget() {
    css();
    var host = document.createElement('div'); host.style.cssText = 'flex:1;min-width:0;';
    mountList(host, { compact: true, limit: 4, tab: '' });
    return host;
  }

  /* ── Notes card on a child, family or staff record ──────────────────────────── */
  function recordCard(type, id) {
    css();
    var wrap = document.createElement('div'); wrap.className = 'qn-rec qn-root';
    wrap.innerHTML = '<div class="qn-rec-h">📓 ' + esc(T('Quick notes')) + '</div><div></div>';
    if (!id) return wrap;
    mountList(wrap.lastChild, { link: { type: type, id: +id }, clamp: true });
    return wrap;
  }

  KT.quickNotes = { toggle: toggle, open: open, openDrawer: openDrawer, closeDrawer: closeDrawer, widget: widget, recordCard: recordCard, render: render, edit: openEditor };

  function registerScreens() {
    if (!KT.Shell || !KT.Shell.registerScreen) return false;
    ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor', 'sales_rep'].forEach(function (r) { KT.Shell.registerScreen(r + ':notes', render); });
    return true;
  }
  if (!registerScreens()) {
    var tries = 0, iv = setInterval(function () { if (registerScreens() || ++tries > 80) clearInterval(iv); }, 100);
  }
})(window);
