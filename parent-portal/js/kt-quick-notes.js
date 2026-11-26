/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — QUICK NOTES (2026-11-26).
   Anthony: "add a notepad widget option and a shortcut at the top bar for quick notes that are logged for each user
   or the agency - full sweep".
   • Two kinds, chosen per note: "Just me" (private: only the person who wrote it, ever - super admins included) and
     "Agency" (every staff member who can see it may read and edit it; agency-wide or one centre). Server rules:
     App\Support\QuickNotes. Parents never have a notepad.
   • Where it shows: the 📃 top-bar button (admins, directors, super admins) opens a drawer (a bottom sheet on a
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
      + '.qn-add .qn-add-title{width:100%;height:30px;border-color:transparent;background:transparent;padding:0 6px;font-weight:800;font-size:13.5px;}'
      + '.qn-add .qn-add-title:focus{outline:none;}'
      + '.qn-add textarea.qn-in{border-color:transparent;background:transparent;padding:4px 6px;border-top:1px solid var(--qn-line);border-radius:0;resize:none;overflow-y:hidden;}'
      + '.qn-add textarea.qn-in:focus{outline:none;}'
      + '@media (max-width:700px){.qn-add .qn-add-title{height:36px;font-size:15px;}.qn-add textarea.qn-in{font-size:15px;}}'
      + '.qn-add-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:4px;}'
      + '.qn-hint{font-size:11px;color:var(--qn-sub);margin-left:auto;}'
      + '.qn-save{display:none;margin-left:auto;}.qn-add.has-pics .qn-save{display:inline-block;}.qn-b:disabled{opacity:.5;cursor:default;}'
      + '@media (hover:none) and (pointer:coarse){.qn-hint{display:none;}.qn-save{display:inline-block;}}'
      + '.qn-b{height:28px;padding:0 11px;border-radius:8px;border:1px solid var(--qn-inb);background:var(--qn-card);color:var(--qn-ink);font-weight:700;font-size:12.5px;cursor:pointer;white-space:nowrap;}'
      + '.qn-b.p{background:#0F766E;border-color:#0F766E;color:#fff;}'
      /* pictures: thumbnails in the composer and the note popup; a 🖼 count on the list */
      + '.qn-pics{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 2px;}'
      + '.qn-pics:empty{display:none;}'
      + '.qn-th{position:relative;width:64px;height:64px;border-radius:8px;overflow:hidden;border:1px solid var(--qn-line);background:var(--qn-chip);cursor:zoom-in;flex:0 0 auto;}'
      + '.qn-th img{width:100%;height:100%;object-fit:cover;display:block;}'
      + '.qn-th.loading:after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.35),transparent);animation:qn-sh 1s linear infinite;}'
      + '@keyframes qn-sh{from{transform:translateX(-100%)}to{transform:translateX(100%)}}'
      + '.qn-th-x{position:absolute;top:3px;right:3px;width:22px;height:22px;border-radius:50%;background:rgba(15,23,42,.78);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;}'
      + '.qn-th-x svg{width:12px;height:12px;}'
      + '.qn-add.drop,.qn-e-drop.drop{outline:2px dashed var(--qn-accent);outline-offset:2px;}'
      + '@media (hover:none) and (pointer:coarse){.qn-th{width:72px;height:72px;}.qn-th-x{width:26px;height:26px;}}'
      + '.qn-seg{display:inline-flex;border:1px solid var(--qn-inb);border-radius:8px;overflow:hidden;}'
      + '.qn-seg button{height:26px;padding:0 9px;border:0;background:var(--qn-card);color:var(--qn-sub);font-weight:700;font-size:12px;cursor:pointer;}'
      + '.qn-seg button.on{background:#0F766E;color:#fff;}'
      + '.qn-dots{display:inline-flex;gap:4px;align-items:center;flex-wrap:wrap;}'
      + '.qn-dot{flex:0 0 auto;width:16px;height:16px;min-height:0;min-width:0;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;box-sizing:border-box;}'
      + '.qn-dot.on{border-color:var(--qn-ink);}'
      + '.qn-dot.none{background:var(--qn-card);border:1px dashed var(--qn-inb);}'
      + '.qn-dot.none.on{border:2px solid var(--qn-ink);}'
      + 'body .qn-dots>.qn-dot[class]{border:2px solid transparent !important;}'
      + 'body .qn-dots>.qn-dot.on[class]{border-color:var(--qn-ink) !important;}'
      + 'body .qn-dots>.qn-dot.none[class]{border:1.5px dashed var(--qn-inb) !important;}'
      + 'body .qn-dots>.qn-dot.none.on[class]{border:2px solid var(--qn-ink) !important;}'
      + '#appMain .qn-seg>button[data-qn-vis],.kt-modal .qn-seg>button[data-qn-vis],body .qn-seg>button[data-qn-vis]{padding:0 9px !important;}'
      + '.qn-list{display:flex;flex-direction:column;gap:8px;}'
      + '.qn-grid .qn-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));align-items:start;}'
      + '.qn-card{position:relative;border:1px solid var(--qn-line);border-left:4px solid var(--qn-line);border-radius:10px;padding:8px 10px 6px;background:var(--qn-card);}'
      /* The list shows each note's TITLE only, one line (Anthony: "should only show the title and not the contents
         as this gets messy"). Tapping it opens the note. */
      + '.qn-card .qn-t{display:block;padding-right:22px;color:var(--qn-ink);font-weight:800;font-size:13.5px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;}'
      + '.qn-card .qn-t:hover{text-decoration:underline;text-underline-offset:2px;}'
      + '.qn-card .qn-t:focus-visible{outline:2px solid var(--qn-accent);outline-offset:2px;border-radius:4px;}'
      + '.qn-pin{position:absolute;top:6px;right:8px;font-size:13px;}'
      + '.qn-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px;font-size:11.5px;color:var(--qn-sub);}'
      + '.qn-chip{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:999px;background:var(--qn-chip);color:var(--qn-sub);font-size:11.5px;font-weight:700;white-space:nowrap;border:0;font-family:inherit;}'
      + 'button.qn-chip{cursor:pointer;}'
      + '.qn-chip.due{background:#FEE2E2;color:#B91C1C;}'
      + '.qn-chip.soon{background:#FEF3C7;color:#92400E;}'
      + '.qn-acts{display:flex;gap:2px;margin-left:auto;align-items:center;}'
      /* min-height:0 with an id-level selector: the phone stylesheet gives every #appMain button a 42px floor
         (meant for form buttons), which made these 26x42 while the delete icon kept 26 - a ragged row. */
      + '.qn-ib,#appMain .qn-root .qn-acts .qn-ib,.kt-modal .qn-root .qn-acts .qn-ib{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;min-height:0;min-width:0;border-radius:7px;border:0;background:transparent;cursor:pointer;font-size:14px;line-height:1;padding:0;margin:0;color:var(--qn-sub);vertical-align:middle;}'
      + '@media (hover:none) and (pointer:coarse){.qn-ib,#appMain .qn-root .qn-acts .qn-ib{width:34px;height:34px;font-size:16px;}.qn-acts{gap:4px;}'
      + '.qn-dots{gap:10px;}.qn-dot{width:24px;height:24px;}'
      + '.qn-seg button{height:34px;font-size:13px;}'
      + '#appMain .qn-seg>button[data-qn-vis],.kt-modal .qn-seg>button[data-qn-vis],body .qn-seg>button[data-qn-vis]{padding:0 14px !important;}}'
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
      + '@media (max-width:700px){.qn-wrap{padding:12px;}'
      /* phone composer: who-sees-it on its own row (both halves equal), then the colours and Save */
      + '.qn-add-row{row-gap:10px;}.qn-add-row .qn-seg{display:flex;flex:1 1 100%;}.qn-add-row .qn-seg button{flex:1 1 0;}'
      + '.qn-add-row select.qn-in{flex:1 1 100%;max-width:none!important;height:34px!important;}'
      + '.qn-panel{padding:10px;}}'
      + '.qn-panel{background:var(--qn-card);border:1px solid var(--qn-line);border-radius:12px;padding:12px 14px;margin-top:14px;}'
      /* record card */
      + '.qn-rec{border:1px solid var(--qn-line);border-radius:12px;padding:10px 12px;margin-top:12px;background:var(--qn-card);}'
      + '.qn-rec-h{display:flex;align-items:center;gap:8px;font-weight:800;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:var(--qn-sub);}';
    document.head.appendChild(s);
  }
  /* A note's title for every list: its own title, or else the first line of its words with markdown marks taken
     off, cut at 60 characters. Old notes (saved before the Title line) read the same way: nothing to migrate. */
  function titleOf(n) {
    var tt = String((n && n.title) || '').trim();
    if (tt) return tt;
    var lines = String((n && n.body) || '').split('\n'), line = '';
    for (var i = 0; i < lines.length && !line; i++) line = stripMd(lines[i]);
    if (!line) return T('Untitled note');
    return line.length > 60 ? line.slice(0, 59).replace(/\s+\S*$/, '') + '…' : line;
  }
  function stripMd(s) {
    return String(s || '')
      .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+\[[ xX]\]\s+|[-*+]\s+|\d+[.)]\s+)/, '')
      .replace(/(\*\*|__|~~|`)/g, '').replace(/(^|\s)[*_](\S[^*_]*\S|\S)[*_](?=\s|$)/g, '$1$2')
      .replace(/\s+/g, ' ').trim();
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
    if (n.pictures && n.pictures.length) chips.push('<span class="qn-chip" data-kttip="' + esc(T('Pictures')) + '" aria-label="' + esc(n.pictures.length + ' ' + T('pictures')) + '">🖼 ' + n.pictures.length + '</span>');
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
      + '<div class="qn-t" role="link" tabindex="0" data-qn-act="open" data-no-i18n title="' + esc(titleOf(n)) + '">' + esc(titleOf(n)) + '</div>'
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
    var st = { tab: opts.tab || 'mine', q: '', notes: [], colour: '', vis: opts.link ? 'agency' : null, centre: '', pics: [] };
    var root = document.createElement('div'); root.className = 'qn-root' + (opts.grid ? ' qn-grid' : '');
    host.innerHTML = ''; host.appendChild(root);
    var timer = null, seq = 0;

    function addBoxHtml() {
      var ctx = lastCtx || {};
      var share = ctx.can_share;
      var vis = st.vis || (st.tab === 'agency' ? 'agency' : 'private');
      var centres = (ctx.centres || []);
      var phone = narrow(), rows = phone ? 4 : 5, minH = rows * (phone ? 21 : 19) + 10;
      // min-height inline: kt-polish-v22 sizes textareas that carry no inline min-height of their own.
      var h = '<form class="qn-add' + (st.pics.length ? ' has-pics' : '') + '" autocomplete="off">'
        + '<input class="qn-in qn-add-title" name="title" maxlength="160" placeholder="' + esc(T('Title (optional)')) + '" aria-label="' + esc(T('Title')) + '">'
        + '<textarea class="qn-in" name="body" rows="' + rows + '" data-qn-grow="' + (phone ? 260 : 320) + '" style="min-height:' + minH + 'px" maxlength="5000" placeholder="' + esc(opts.link ? T('Add a note about this record…') : T('Write a quick note…')) + '" aria-label="' + esc(T('New note')) + '"></textarea>'
        + '<div class="qn-pics">' + thumbsHtml([], st.pics, true) + '</div>'
        + '<div class="qn-add-row">';
      if (share) h += '<span class="qn-seg" role="group" aria-label="' + esc(T('Who sees it')) + '"><button type="button" data-qn-vis="private" aria-pressed="' + (vis === 'private' ? 'true' : 'false') + '" class="' + (vis === 'private' ? 'on' : '') + '" data-kttip="' + esc(T('Only you can see this note')) + '">🔒 ' + esc(T('Just me')) + '</button><button type="button" data-qn-vis="agency" aria-pressed="' + (vis === 'agency' ? 'true' : 'false') + '" class="' + (vis === 'agency' ? 'on' : '') + '" data-kttip="' + esc(T('Staff can see and edit it')) + '">👥 ' + esc(T('Agency')) + '</button></span>';
      if (share && vis === 'agency' && !opts.link && centres.length > 1) {
        h += '<select class="qn-in" name="centre" aria-label="' + esc(T('Centre')) + '" style="height:28px;max-width:170px"><option value="">' + esc(T('Agency-wide')) + '</option>'
          + centres.map(function (c) { return '<option value="' + c.id + '"' + (String(st.centre) === String(c.id) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>';
      }
      h += dotsHtml(st.colour) + picButtonHtml()
        + '<span class="qn-hint">' + esc(T('Enter saves · Shift+Enter new line')) + '</span>'
        + '<button type="button" class="qn-b p qn-save" data-qn-save data-kt-always-save>' + esc(T('Save')) + '</button></div></form>';
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
      var oT = root.querySelector('.qn-add input[name="title"]'), oB = root.querySelector('.qn-add textarea');
      var draft = { t: oT ? oT.value : '', b: oB ? oB.value : '', f: document.activeElement === oT ? 't' : (document.activeElement === oB ? 'b' : '') };
      root.innerHTML = h;
      var nT = root.querySelector('.qn-add input[name="title"]'), nB = root.querySelector('.qn-add textarea');
      if (nT && draft.t) nT.value = draft.t;
      if (nB && draft.b) { nB.value = draft.b; grow(nB); }
      if (draft.f === 't' && nT) nT.focus(); else if (draft.f === 'b' && nB) nB.focus();
      saveState();
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
      var ta = form.querySelector('textarea'), ti = form.querySelector('input[name="title"]');
      var body = (ta.value || '').trim() || null, title = ti ? (ti.value || '').trim() || null : null;
      var pics = st.pics.slice();
      if (!body && !title && !pics.length) { ta.focus(); return; }
      if (!body && !title) title = T('Pictures');
      var vis = (lastCtx && lastCtx.can_share) ? (st.vis || (st.tab === 'agency' ? 'agency' : 'private')) : 'private';
      var o = { title: title, body: body, visibility: vis, colour: st.colour || null };
      var cs = form.querySelector('select[name="centre"]'); if (cs && cs.value && vis === 'agency') o.centre_id = +cs.value;
      if (opts.link) { o.linked_type = opts.link.type; o.linked_id = opts.link.id; }
      ta.disabled = true; if (ti) ti.disabled = true;
      save(null, o).then(function (note) {
        return (pics.length && note ? uploadPictures(note.id, pics).then(function () { changed(); }, function (e) {
          toast('⚠️', T('The note is saved, but a picture could not be added'), errMsg(e), '#DC2626'); changed();
        }) : Promise.resolve()).then(function () { return note; });
      }).then(function () {
        st.pics.forEach(function (p) { try { URL.revokeObjectURL(p.url); } catch (e) {} }); st.pics = [];
        toast('📃', T('Note saved'), vis === 'agency' ? T('Shared with staff') : T('Only you can see it'), '#0F766E');
        if (!opts.link && !opts.compact && st.tab !== (vis === 'agency' ? 'agency' : 'mine')) st.tab = vis === 'agency' ? 'agency' : 'mine';
        ta.value = ''; if (ti) ti.value = '';
        st.vis = null; load().then(function () { var t2 = root.querySelector('.qn-add textarea'); if (t2) t2.focus(); });
      }, function (e) { ta.disabled = false; if (ti) ti.disabled = false; toast('⚠️', T('Could not save the note'), errMsg(e), '#DC2626'); });
    }

    root.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.qn-card .qn-t, .qn-th, .qn-th-x')) { e.preventDefault(); e.target.click(); return; }
      if (e.target.matches('.qn-add .qn-add-title') && e.key === 'Enter' && !e.isComposing) { e.preventDefault(); var b = e.target.form.querySelector('textarea'); if (b) b.focus(); return; }
      // Touch screens: Enter is a new line (there is no Shift on a phone keyboard); the Save button saves.
      if (e.target.matches('.qn-add textarea') && e.key === 'Enter' && !e.shiftKey && !e.isComposing && !touchOnly()) { e.preventDefault(); submit(e.target.form); }
    });
    root.addEventListener('submit', function (e) { e.preventDefault(); submit(e.target); });
    root.addEventListener('click', function (e) { var sb = e.target.closest && e.target.closest('[data-qn-save]'); if (sb && root.contains(sb)) { e.preventDefault(); submit(sb.form || sb.closest('form')); } });
    function saveState() {
      var f = root.querySelector('.qn-add'); if (!f) return;
      var sb = f.querySelector('[data-qn-save]'), ta = f.querySelector('textarea'), ti = f.querySelector('input[name="title"]');
      var has = !!((ta && ta.value.trim()) || (ti && ti.value.trim()) || st.pics.length);
      if (sb && !(ta && ta.disabled)) sb.disabled = !has;
    }
    root.addEventListener('input', function (e) {
      if (e.target.matches && e.target.matches('.qn-add textarea, .qn-add input[name="title"]')) { if (e.target.tagName === 'TEXTAREA') grow(e.target); saveState(); return; }
      if (e.target.name !== 'q') return;
      clearTimeout(timer); var v = e.target.value;
      timer = setTimeout(function () { st.q = v.trim(); load(); }, 250);
    });
    root.addEventListener('change', function (e) {
      if (e.target.name === 'centre') st.centre = e.target.value;
      if (e.target.matches && e.target.matches('.qn-add [data-qn-file]')) { var fl = e.target.files; addPending(fl); e.target.value = ''; }
    });
    function addPending(files) { takePictures(files, PIC_MAX - st.pics.length, function (got) { st.pics = st.pics.concat(got); keepDraft(draw); }); }
    root.addEventListener('paste', function (e) {
      if (!e.target.closest || !e.target.closest('.qn-add')) return;
      var fs = clipFiles(e); if (!fs.length) return;
      e.preventDefault(); addPending(fs);
    });
    root.addEventListener('dragover', function (e) { var f = e.target.closest && e.target.closest('.qn-add'); if (!f) return; e.preventDefault(); f.classList.add('drop'); });
    root.addEventListener('dragleave', function (e) { var f = e.target.closest && e.target.closest('.qn-add'); if (f && !f.contains(e.relatedTarget)) f.classList.remove('drop'); });
    root.addEventListener('drop', function (e) {
      var f = e.target.closest && e.target.closest('.qn-add'); if (!f) return;
      e.preventDefault(); f.classList.remove('drop'); addPending(clipFiles(e));
    });
    root.addEventListener('click', function (e) {
      var t = e.target;
      var tab = t.closest('[data-qn-tab]'); if (tab) { st.tab = tab.getAttribute('data-qn-tab'); st.vis = null; load(); return; }
      var vb = t.closest('[data-qn-vis]'); if (vb) { st.vis = vb.getAttribute('data-qn-vis'); keepDraft(draw); return; }
      if (t.closest('.qn-add [data-qn-addpic]')) { var fi = root.querySelector('.qn-add [data-qn-file]'); if (fi) fi.click(); return; }
      var up = t.closest('.qn-add [data-qn-unpend]'); if (up) { var gone = st.pics.splice(+up.getAttribute('data-qn-unpend'), 1)[0]; try { URL.revokeObjectURL(gone.url); } catch (er) {} keepDraft(draw); return; }
      var pv = t.closest('.qn-add [data-qn-pend]'); if (pv) { var pp = st.pics[+pv.getAttribute('data-qn-pend')]; if (pp) viewPicture(pp.blob); return; }
      var dot = t.closest('.qn-add [data-qn-colour]'); if (dot) { st.colour = dot.getAttribute('data-qn-colour'); keepDraft(draw); return; }
      var go = t.closest('[data-qn-go]'); if (go) { goRecord(go.getAttribute('data-qn-go')); return; }
      if (t.closest('[data-qn-all]')) { openDrawer(); return; }
      var act = t.closest('[data-qn-act]'); if (!act) return;
      var card = act.closest('[data-qn-id]'), n = card && byId(+card.getAttribute('data-qn-id')); if (!n) return;
      var a = act.getAttribute('data-qn-act');
      if (a === 'pin') save(n.id, { pinned: !n.pinned }).then(load, function (er) { toast('⚠️', T('Could not save the note'), errMsg(er), '#DC2626'); });
      else if (a === 'colour') pickColour(act, n, load);
      else if (a === 'edit' || a === 'open') openEditor(n, load);
      else if (a === 'history') openHistory(n);
      else if (a === 'delete') del(n, load);
    });
    // Redraw without losing what they were typing in the quick-add box.
    function keepDraft(fn) {
      var ta = root.querySelector('.qn-add textarea'), v = ta ? ta.value : '';
      var ti = root.querySelector('.qn-add input[name="title"]'), tv = ti ? ti.value : '';
      fn();
      var t3 = root.querySelector('.qn-add input[name="title"]'); if (t3) t3.value = tv;
      var t2 = root.querySelector('.qn-add textarea'); if (t2) { t2.value = v; grow(t2); t2.focus(); }
      saveState();
    }
    var onChange = function () { if (document.body.contains(root)) load(); else window.removeEventListener('kt:quick-notes-changed', onChange); };
    window.addEventListener('kt:quick-notes-changed', onChange);
    draw(); load();
    return { reload: load, root: root };
  }
  function narrow() { try { return matchMedia('(max-width:700px)').matches; } catch (e) { return false; } }
  // Grow the quick-add box with its words, up to its cap; past that it scrolls.
  function grow(ta) {
    if (!ta) return;
    var cap = +ta.getAttribute('data-qn-grow') || 320;
    ta.style.height = 'auto';
    var h = Math.min(ta.scrollHeight + 2, cap);
    ta.style.height = h + 'px';
    ta.style.overflowY = ta.scrollHeight + 2 > cap ? 'auto' : 'hidden';
  }
  /* ── pictures (2026-11-26, Anthony: "ability to add screenshots to notes as well wire this up") ──────────────
     Paste (Ctrl/Cmd+V), drop, or the 🖼 Add picture button (camera or gallery on a phone). Each picture is shrunk on
     the device to 1920px and redrawn through a canvas, which drops EXIF (location) before it leaves; the server
     re-encodes again (App\Support\QuickNotePictures). Pictures are fetched signed in, never from a public link. */
  var PIC_MAX = 5, PIC_BYTES = 10 * 1024 * 1024, PIC_SIDE = 1920;
  var X_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  function picErr(msg) { toast('⚠️', T('Could not add the picture'), T(msg), '#DC2626'); }
  function isPicture(f) { return f && (/^image\//i.test(f.type || '') || /\.(heic|heif|png|jpe?g|webp|gif)$/i.test(f.name || '')); }
  function decode(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return viaImg(file); });
    }
    return viaImg(file);
  }
  function viaImg(file) {
    return new Promise(function (res, rej) {
      var u = URL.createObjectURL(file), im = new Image();
      im.onload = function () { res(im); }; im.onerror = function () { URL.revokeObjectURL(u); rej(new Error('decode')); };
      im.src = u;
    });
  }
  // Shrink to PIC_SIDE and redraw: the canvas copy carries no EXIF. HEIC is read wherever the phone can (Safari).
  function prepPicture(file) {
    if (!isPicture(file)) return Promise.reject(new Error('Only pictures can be added: PNG, JPG, WebP or GIF.'));
    if (file.size > PIC_BYTES) return Promise.reject(new Error('That picture is too big. Pictures can be up to 10 MB.'));
    return decode(file).then(function (img) {
      var w = img.width || img.naturalWidth, h = img.height || img.naturalHeight;
      if (!w || !h) throw new Error('That picture could not be read. Try a PNG or JPG.');
      var k = Math.min(1, PIC_SIDE / Math.max(w, h)), cw = Math.max(1, Math.round(w * k)), ch = Math.max(1, Math.round(h * k));
      var c = document.createElement('canvas'); c.width = cw; c.height = ch;
      c.getContext('2d').drawImage(img, 0, 0, cw, ch);
      try { if (img.close) img.close(); } catch (e) {}
      return new Promise(function (res, rej) {
        c.toBlob(function (b) {
          if (b && b.type === 'image/webp') return res(b);
          c.toBlob(function (j) { j ? res(j) : rej(new Error('That picture could not be read. Try a PNG or JPG.')); }, 'image/jpeg', 0.86);
        }, 'image/webp', 0.86);
      });
    }, function () {
      throw new Error(/\.(heic|heif)$/i.test(file.name || '') || /hei[cf]/i.test(file.type || '')
        ? 'This phone picture (HEIC) cannot be read here. Share it as a JPG, or take a screenshot of it.'
        : 'That picture could not be read. Try a PNG or JPG.');
    });
  }
  // Turn picked / pasted / dropped files into ready blobs, up to the room left. Calls back once with the new ones.
  function takePictures(files, room, cb) {
    files = [].slice.call(files || []).filter(function (f) { return f && (f.kind ? f.kind === 'file' : true); });
    if (!files.length) return;
    if (room <= 0) { picErr('A note can have up to ' + PIC_MAX + ' pictures.'); return; }
    if (files.length > room) { picErr('A note can have up to ' + PIC_MAX + ' pictures.'); files = files.slice(0, room); }
    var out = [];
    files.reduce(function (pr, f) {
      return pr.then(function () { return prepPicture(f).then(function (b) { out.push({ blob: b, url: URL.createObjectURL(b) }); }, function (e) { picErr(e.message); }); });
    }, Promise.resolve()).then(function () { if (out.length) cb(out); });
  }
  function clipFiles(e) {
    var dt = e.clipboardData || e.dataTransfer; if (!dt) return [];
    var fs = [].slice.call(dt.files || []);
    if (!fs.length && dt.items) [].forEach.call(dt.items, function (it) { if (it.kind === 'file') { var f = it.getAsFile(); if (f) fs.push(f); } });
    return fs.filter(isPicture);
  }
  function uploadPictures(noteId, list) {
    return list.reduce(function (pr, p) {
      return pr.then(function () {
        var fd = new FormData(); fd.append('picture', p.blob, 'picture.' + (p.blob.type === 'image/webp' ? 'webp' : 'jpg'));
        return api().post('/quick-notes/' + noteId + '/pictures', fd, { quiet: true });
      });
    }, Promise.resolve());
  }
  var picCache = {};
  function picBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  // A saved picture, fetched signed in and in the active agency (the server re-checks the note every time).
  function picBlob(id) {
    if (picCache[id]) return picCache[id];
    var h = { Accept: 'image/*' };
    try {
      var tk = (KT.Auth && KT.Auth.token && KT.Auth.token()) || sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
      if (tk) h.Authorization = 'Bearer ' + tk;
      var ag = sessionStorage.getItem('kt_active_agency_id'); if (ag) h['X-Active-Agency-Id'] = ag;
    } catch (e) {}
    var pr = fetch(picBase() + '/quick-notes/pictures/' + id, { headers: h, credentials: 'omit' }).then(function (r) {
      if (!r.ok) throw new Error(r.status === 404 ? 'That picture is no longer available.' : 'Could not load the picture.');
      return r.blob();
    });
    picCache[id] = pr; pr.catch(function () { delete picCache[id]; });
    return pr;
  }
  function viewPicture(blobOrId, title) {
    Promise.resolve(typeof blobOrId === 'number' ? picBlob(blobOrId) : blobOrId).then(function (b) {
      if (KT.viewBlob) KT.viewBlob(b, { title: title || T('Picture'), filename: 'note-picture.' + (b.type === 'image/webp' ? 'webp' : 'jpg') });
      else window.open(URL.createObjectURL(b), '_blank', 'noopener');
    }, function (e) { toast('⚠️', T('Could not open the picture'), T(e.message), '#DC2626'); });
  }
  // Thumbnails: saved ({id}) and not yet saved ({url}). removable adds the round ✕.
  function thumbsHtml(saved, pending, removable) {
    var h = '';
    (saved || []).forEach(function (p) {
      h += '<div class="qn-th loading" role="button" tabindex="0" data-qn-pic="' + p.id + '" aria-label="' + esc(T('Open the picture')) + '"><img alt="" data-qn-pid="' + p.id + '">'
        + (removable ? '<span class="qn-th-x" role="button" tabindex="0" data-qn-unpic="' + p.id + '" aria-label="' + esc(T('Remove picture')) + '" data-kttip="' + esc(T('Remove picture')) + '">' + X_SVG + '</span>' : '') + '</div>';
    });
    (pending || []).forEach(function (p, i) {
      h += '<div class="qn-th" role="button" tabindex="0" data-qn-pend="' + i + '" aria-label="' + esc(T('Open the picture')) + '"><img alt="" src="' + esc(p.url) + '">'
        + (removable ? '<span class="qn-th-x" role="button" tabindex="0" data-qn-unpend="' + i + '" aria-label="' + esc(T('Remove picture')) + '" data-kttip="' + esc(T('Remove picture')) + '">' + X_SVG + '</span>' : '') + '</div>';
    });
    return h;
  }
  function fillThumbs(host) {
    [].forEach.call(host.querySelectorAll('img[data-qn-pid]:not([src])'), function (im) {
      picBlob(+im.getAttribute('data-qn-pid')).then(function (b) {
        im.src = URL.createObjectURL(b); var th = im.closest('.qn-th'); if (th) th.classList.remove('loading');
      }, function () { var th = im.closest('.qn-th'); if (th) th.classList.remove('loading'); });
    });
  }
  function picButtonHtml() {
    return '<button type="button" class="qn-b qn-pic-btn" data-qn-addpic data-kttip="' + esc(T('Paste, drop or pick a picture')) + '">🖼 ' + esc(T('Add picture')) + '</button>'
      + '<input type="file" accept="image/*" multiple hidden data-qn-file aria-hidden="true" tabindex="-1">';
  }
  function touchOnly() { try { return matchMedia('(hover: none) and (pointer: coarse)').matches; } catch (e) { return false; } }
  function dotsHtml(sel) {
    return '<span class="qn-dots" role="group" aria-label="' + esc(T('Colour')) + '">'
      + '<button type="button" class="qn-dot none' + (!sel ? ' on' : '') + '" aria-pressed="' + (!sel ? 'true' : 'false') + '" data-qn-colour="" data-kttip="' + esc(T('No colour')) + '" aria-label="' + esc(T('No colour')) + '"></button>'
      + Object.keys(COLOURS).map(function (k) { return '<button type="button" class="qn-dot' + (sel === k ? ' on' : '') + '" aria-pressed="' + (sel === k ? 'true' : 'false') + '" data-qn-colour="' + k + '" style="background:' + COLOURS[k].bar + '" data-kttip="' + esc(T(COLOURS[k].l)) + '" aria-label="' + esc(T(COLOURS[k].l)) + '"></button>'; }).join('')
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
      + '<span class="qn-l">' + esc(T('Pictures')) + '</span><div id="qn-e-pics" class="qn-pics"></div><input type="hidden" id="qn-e-picsig" value="">'
      + '<div class="qn-row" id="qn-e-picrow">' + picButtonHtml() + '<span class="qn-sub" id="qn-e-pichint"></span></div>'
      + (ctx.can_share ? '<span class="qn-l">' + esc(T('Linked to')) + '</span><div id="qn-e-link"></div>' : '')
      + '<div class="qn-sub" id="qn-e-msg" style="margin-top:8px;min-height:16px"></div>';
    var linkHost = body.querySelector('#qn-e-link');
    var savedPics = (n.pictures || []).slice(), dropPics = [], newPics = [];
    function drawPics() {
      var keep = savedPics.filter(function (p) { return dropPics.indexOf(p.id) < 0; });
      var host = body.querySelector('#qn-e-pics');
      host.innerHTML = thumbsHtml(keep, newPics, true); fillThumbs(host);
      var left = PIC_MAX - keep.length - newPics.length;
      body.querySelector('[data-qn-addpic]').style.display = left > 0 ? '' : 'none';
      body.querySelector('#qn-e-pichint').textContent = left > 0 ? (touchOnly() ? '' : T('or paste / drop one into the note')) : T('A note can have up to 5 pictures.');
      var sig = body.querySelector('#qn-e-picsig'), nv = dropPics.join(',') + '|' + newPics.length;
      if (sig && sig.value !== (nv === '|0' ? '' : nv)) { sig.value = nv === '|0' ? '' : nv; try { sig.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {} }
    }
    function roomLeft() { return PIC_MAX - savedPics.filter(function (p) { return dropPics.indexOf(p.id) < 0; }).length - newPics.length; }
    function addNew(files) { takePictures(files, roomLeft(), function (got) { newPics = newPics.concat(got); drawPics(); }); }
    body.classList.add('qn-e-drop');
    body.addEventListener('paste', function (e) { var fs = clipFiles(e); if (!fs.length) return; e.preventDefault(); addNew(fs); });
    body.addEventListener('dragover', function (e) { e.preventDefault(); body.classList.add('drop'); });
    body.addEventListener('dragleave', function (e) { if (!body.contains(e.relatedTarget)) body.classList.remove('drop'); });
    body.addEventListener('drop', function (e) { e.preventDefault(); body.classList.remove('drop'); addNew(clipFiles(e)); });
    body.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-qn-file]')) { addNew(e.target.files); e.target.value = ''; } });
    body.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.qn-th, .qn-th-x')) { e.preventDefault(); e.target.click(); } });
    drawPics();
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
      if (t.closest('[data-qn-addpic]')) { body.querySelector('[data-qn-file]').click(); return; }
      var ux = t.closest('[data-qn-unpic]'); if (ux) { dropPics.push(+ux.getAttribute('data-qn-unpic')); drawPics(); return; }
      var un = t.closest('[data-qn-unpend]'); if (un) { var g = newPics.splice(+un.getAttribute('data-qn-unpend'), 1)[0]; try { URL.revokeObjectURL(g.url); } catch (er) {} drawPics(); return; }
      var sp = t.closest('[data-qn-pic]'); if (sp) { viewPicture(+sp.getAttribute('data-qn-pic'), titleOf(n)); return; }
      var np = t.closest('[data-qn-pend]'); if (np) { var q2 = newPics[+np.getAttribute('data-qn-pend')]; if (q2) viewPicture(q2.blob, titleOf(n)); return; }
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
      var hasPics = savedPics.length - dropPics.length + newPics.length > 0;
      if (!o.title && !o.body && !hasPics) { msg.style.color = '#B91C1C'; msg.textContent = T('Write something first.'); return false; }
      if (!o.title && !o.body) o.title = T('Pictures');
      if (vis === 'agency') { var cs = body.querySelector('#qn-e-centre'); o.centre_id = cs && cs.value ? +cs.value : null; }
      if (linkHost) { o.linked_type = link ? link.type : null; o.linked_id = link ? link.id : null; }
      if (n.id && n.visibility === 'agency') delete o.visibility;
      return save(n.id, o).then(function (saved) {
        var nid = n.id || (saved && saved.id);
        var drops = dropPics.slice(), adds = newPics.slice();
        if (!nid || (!drops.length && !adds.length)) return;
        return drops.reduce(function (pr, pid) { return pr.then(function () { delete picCache[pid]; return api().delete('/quick-notes/pictures/' + pid, { quiet: true }); }); }, Promise.resolve())
          .then(function () { return uploadPictures(nid, adds); })
          .then(function () { changed(); }, function (e) { changed(); toast('⚠️', T('The note is saved, but a picture could not be changed'), errMsg(e), '#DC2626'); });
      }).then(function () { toast('📃', T('Note saved'), '', '#0F766E'); done && done(); },
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
      var head = '<div style="font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-bottom:6px;border-bottom:1px solid var(--qn-line)" data-no-i18n>' + esc(titleOf(n)) + '</div>';
      if (!rows.length) { body.innerHTML = head + '<div class="qn-empty">' + esc(T('No history yet.')) + '</div>'; return; }
      body.innerHTML = head + rows.map(function (r) {
        var what = r.action === 'picture_added' ? T('Added a picture') : r.action === 'picture_removed' ? T('Removed a picture') : r.action === 'created' ? T('Created the note') : (r.action === 'deleted' ? T('Deleted the note') : T('Changed') + ' ' + r.fields.map(function (f) { return T(FIELD[f] || f); }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', '));
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
    el.innerHTML = '<div class="qn-head"><h2>📃 ' + esc(T('Quick notes')) + '</h2><div class="qn-hs">' + esc(T('Just for you, or shared with your staff')) + '</div>'
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
      + '<div class="kt-hero-greet">📃 ' + esc(T('NOTEPAD')) + '</div><h1>' + esc(T('Notes')) + '</h1>'
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
    wrap.innerHTML = '<div class="qn-rec-h">📃 ' + esc(T('Quick notes')) + '</div><div></div>';
    if (!id) return wrap;
    mountList(wrap.lastChild, { link: { type: type, id: +id }, clamp: true });
    return wrap;
  }

  KT.quickNotes = { titleOf: titleOf, toggle: toggle, open: open, openDrawer: openDrawer, closeDrawer: closeDrawer, widget: widget, recordCard: recordCard, render: render, edit: openEditor };

  function registerScreens() {
    if (!KT.Shell || !KT.Shell.registerScreen) return false;
    ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor', 'sales_rep'].forEach(function (r) { KT.Shell.registerScreen(r + ':notes', render); });
    return true;
  }
  if (!registerScreens()) {
    var tries = 0, iv = setInterval(function () { if (registerScreens() || ++tries > 80) clearInterval(iv); }, 100);
  }
})(window);
