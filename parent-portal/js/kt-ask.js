/* ═══════════════════════════════════════════════════════════════════
   kt-ask.js — "Ask KiddieTrac", the admin AI assistant (2026-11-03, AI Bundle)

   Agency admins, centre directors and platform admins (inside the agency they are viewing)
   ask plain questions about their own agency: who owes money, who is not in yet, which
   certifications expire this month, how many children are in a room...

   The model never touches the database. The server (AskController + App\Support\AskTools)
   answers through a fixed set of read-only tools, each scoped in PHP to the ACTIVE agency and,
   for a director, to their own centres; closed and withdrawn records are left out. This file is
   only the window: a slide-over from the top bar (✨) and the full page #ask, sharing one
   conversation per browser session (sessionStorage, cleared with the tab).

   House rules followed: one navy-to-teal header with the ✕ inside it, one scroll region (the
   conversation), the one send button (.kt-send-one), spell check left to kt-spellcheck.js,
   no raw errors (KT.Api cleans them), dates come back from the server in the agency zone.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.ask) return;

  var ADMIN = ['agency_admin', 'centre_director', 'platform_admin'];
  var MAX_TURNS = 12;               // kept in the window; the server only reads the last few
  var SUGGEST = [
    'Which families owe money?',
    'Who isn\u2019t in yet today?',
    'Which staff certifications expire this month?',
    'How many children are enrolled in each room?',
    'Which families have autopay off?',
    'What incidents happened this week?',
    'How do I add a closure day?'
  ];

  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function roleNow() {
    var u = user();
    var r = u.role_key || (u.roles && u.roles[0]) || '';
    try { var va = sessionStorage.getItem('kt_view_as') || ''; if (va) r = va; } catch (e) {}
    return r;
  }
  function canSee() { return ADMIN.indexOf(roleNow()) !== -1; }
  function hasBundle() { return !!user().ai_bundle; }
  /* The top bar's ONE 🤖 opens this when it can answer; otherwise the help assistant, so nobody loses "how do I…". */
  function available() { return canSee() && hasBundle(); }
  function agencyId() { try { return sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || ''; } catch (e) { return ''; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* ── conversation, per session and per agency ─────────────────────── */
  function key() { return 'kt_ask_hist:' + (user().id || 0) + ':' + agencyId(); }
  function load() { try { var v = JSON.parse(sessionStorage.getItem(key()) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function save(h) { try { sessionStorage.setItem(key(), JSON.stringify(h.slice(-MAX_TURNS * 2))); } catch (e) {} }
  var hist = null;
  function H() { if (!hist || hist._k !== key()) { hist = load(); hist._k = key(); } return hist; }

  /* ── a small, safe renderer: text is escaped first, then **bold**, bullets and pipe tables ── */
  function inline(s) {
    return esc(s).replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
  }
  function render(md) {
    var lines = String(md || '').replace(/\r/g, '').split('\n'), out = [], i = 0;
    while (i < lines.length) {
      var ln = lines[i];
      if (/^\s*\|.*\|\s*$/.test(ln) && i + 1 < lines.length && /^\s*\|[\s:\-|]+\|\s*$/.test(lines[i + 1])) {
        var head = cells(ln); i += 2; var rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(cells(lines[i])); i++; }
        // A small answer table, not a screen table: no filter box / sort / export bar from the page enhancers
        // (kt-table-filter skips data-kt-no-filter; kt-table-export skips a table with no <thead>).
        out.push('<div class="kt-ask-tblw" data-kt-allow-overflow><table class="kt-ask-tbl" data-kt-no-filter data-kt-noexport><tbody><tr>' + head.map(function (c) { return '<th scope="col">' + inline(c) + '</th>'; }).join('') +
          '</tr>' + rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td' + (/\s/.test(c.trim()) ? '' : ' class="kt-ask-nw"') + '>' + inline(c) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>');
        continue;
      }
      if (/^\s*[-*•]\s+/.test(ln)) {
        var items = [];
        while (i < lines.length && /^\s*[-*•]\s+/.test(lines[i])) { items.push('<li>' + inline(lines[i].replace(/^\s*[-*•]\s+/, '')) + '</li>'); i++; }
        out.push('<ul>' + items.join('') + '</ul>'); continue;
      }
      if (/^\s*#{1,4}\s+/.test(ln)) { out.push('<div class="kt-ask-h">' + inline(ln.replace(/^\s*#+\s+/, '')) + '</div>'); i++; continue; }
      if (ln.trim() === '') { i++; continue; }
      out.push('<p>' + inline(ln) + '</p>'); i++;
    }
    return out.join('');
    function cells(l) { return l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); }); }
  }

  /* ── styles (scoped to .kt-ask; ids lift the typing box over the portal's form-field rules) ── */
  function css() {
    if (d.getElementById('kt-ask-css')) return;
    var s = d.createElement('style'); s.id = 'kt-ask-css';
    s.textContent = [
      '#kt-ask-ov{position:fixed;inset:0;z-index:12000;background:rgba(15,23,42,.38);display:flex;justify-content:flex-end;overflow:hidden;}',
      '#kt-ask-ov .kt-ask-card{width:min(480px,100vw);height:100%;border-radius:16px 0 0 16px;box-shadow:-12px 0 36px rgba(15,23,42,.28);animation:kt-ask-in .2s ease-out;}',
      '@keyframes kt-ask-in{from{transform:translateX(40px);opacity:.4}to{transform:none;opacity:1}}',
      '@media(max-width:600px){#kt-ask-ov .kt-ask-card{width:100vw;border-radius:0;}}',
      '.kt-ask-card{background:#fff;display:flex;flex-direction:column;overflow:hidden;min-height:0;}',
      '.kt-ask-page .kt-ask-card{border:1px solid #E2E8F0;border-radius:16px;height:calc(100vh - 210px);min-height:440px;}',
      '@media(max-width:768px){.kt-ask-page .kt-ask-card{height:calc(100vh - 170px);min-height:380px;}}',
      '.kt-ask-head{flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:12px 12px 12px 18px;color:#fff;background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}',
      '.kt-ask-head h2{margin:0;font-size:16px;font-weight:800;color:#fff;flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.kt-ask-head button{background:transparent;border:none;color:rgba(255,255,255,.92);cursor:pointer;font-size:15px;line-height:1;width:30px;height:30px;border-radius:8px;flex:0 0 auto;}',
      '.kt-ask-head button:hover{background:rgba(255,255,255,.14);}',
      '.kt-ask-head .kt-ask-x{font-size:22px;}',
      '.kt-ask-body{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:14px 16px 8px;background:#F8FAFC;}',
      '.kt-ask-msg{margin:0 0 12px;max-width:100%;}',
      '.kt-ask-q{display:flex;justify-content:flex-end;}',
      '.kt-ask-q>div{background:#DDF3F1;color:#0F3B3A;border-radius:14px 14px 4px 14px;padding:8px 12px;font-size:14px;max-width:85%;white-space:pre-wrap;word-break:break-word;}',
      '.kt-ask-a>div.kt-ask-bub{background:#fff;border:1px solid #E5EAF0;border-radius:14px 14px 14px 4px;padding:9px 12px;font-size:13.5px;color:#0F172A;line-height:1.45;}',
      '.kt-ask-a p{margin:0 0 6px;}.kt-ask-a p:last-child{margin-bottom:0;}.kt-ask-a ul{margin:4px 0 6px;padding-left:18px;}.kt-ask-a li{margin:1px 0;}',
      '.kt-ask-h{font-weight:800;font-size:12.5px;color:#0E7C90;margin:6px 0 3px;}',
      '.kt-ask-tblw{overflow-x:auto;margin:6px 0;}',
      '.kt-ask-tbl{border-collapse:collapse;font-size:12.5px;width:100%;}',
      '.kt-ask-tbl th{background:#F1F5F9;color:#334155;font-weight:700;text-align:left;padding:4px 7px;border-bottom:1px solid #E2E8F0;white-space:nowrap;}',
      '.kt-ask-tbl td{padding:4px 7px;border-bottom:1px solid #F1F5F9;vertical-align:top;}',
      '.kt-ask-tbl td.kt-ask-nw{white-space:nowrap;}',   /* one-word cells (TA-F-0011, $1,015.00) never break mid-code on a phone */
      '.kt-ask-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;}',
      '.kt-ask-chip{border:1px solid #CBD5E1;background:#fff;color:#0F4C5C;border-radius:14px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;line-height:1.3;text-align:left;}',
      '.kt-ask-chip:hover{background:#ECFEFF;border-color:#7FD3CC;}',
      '.kt-ask-link{border-color:#7FD3CC;background:#F0FDFA;color:#0F766E;}',
      '.kt-ask-err>div.kt-ask-bub{border-color:#FECACA;background:#FEF2F2;color:#991B1B;}',
      '.kt-ask-intro{font-size:13.5px;color:#334155;margin:2px 0 12px;line-height:1.45;}',
      '.kt-ask-meta{font-size:11px;color:#94A3B8;margin-top:4px;}',
      '.kt-ask-busy{display:inline-flex;gap:4px;padding:4px 2px;}.kt-ask-busy i{width:7px;height:7px;border-radius:50%;background:#94A3B8;animation:kt-ask-dot 1s infinite ease-in-out;}',
      '.kt-ask-busy i:nth-child(2){animation-delay:.15s}.kt-ask-busy i:nth-child(3){animation-delay:.3s}',
      '@keyframes kt-ask-dot{0%,80%,100%{opacity:.25}40%{opacity:1}}',
      '.kt-ask-foot{flex:0 0 auto;border-top:1px solid #E5EAF0;background:#fff;padding:9px 12px 10px;}',
      '.kt-ask-foot .kt-compose-row{display:flex;align-items:flex-end;gap:8px;}',
      '#kt-ask-ov textarea.kt-compose-input.kt-compose-input,.kt-ask-page textarea.kt-compose-input.kt-compose-input{font-family:inherit !important;flex:1 1 auto !important;min-width:0 !important;max-width:none !important;box-sizing:border-box !important;height:44px;min-height:44px !important;max-height:132px !important;padding:11px 16px !important;font-size:15px !important;line-height:1.4 !important;color:#0F172A !important;background:#F4F7FA !important;border:1.5px solid #D5DEE7 !important;border-radius:22px !important;background-image:none !important;box-shadow:none !important;resize:none !important;overflow-y:auto;scrollbar-width:none;}',
      '#kt-ask-ov textarea.kt-compose-input.kt-compose-input:focus,.kt-ask-page textarea.kt-compose-input.kt-compose-input:focus{border-color:#159FB4 !important;background:#fff !important;outline:none !important;}',
      '.kt-ask-note{font-size:10.5px;color:#94A3B8;margin-top:6px;text-align:center;}',
      '.kt-ask-up{padding:22px 20px;font-size:14px;color:#334155;line-height:1.5;}',
      '.kt-ask-up b{color:#0F172A;}',
      '.kt-ask-reset{background:none;border:none;color:#64748B;font-size:12px;cursor:pointer;padding:0;margin:0 0 10px;text-decoration:underline;}'
    ].join('\n');
    d.head.appendChild(s);
  }

  /* ── one chat view, mounted into the slide-over card or the page card ── */
  function mount(card, opts) {
    opts = opts || {};
    card.classList.add('kt-ask-card');
    card.innerHTML =
      // The slide-over carries the popup header; on the #ask page the screen hero is the title (the shell lifts a
      // page's first heading into the hero, which left an empty gradient band here).
      (opts.panel ? '<div class="kt-ask-head kt-dlg-head">' +
        '<h2>\ud83e\udd16 AI assistant</h2>' +
        '<button type="button" class="kt-ask-full" aria-label="Open as a page" data-kttip="Open as a page">\u2922</button>' +
        '<button type="button" class="kt-ask-x" aria-label="Close">\u00d7</button>' +
      '</div>' : '') +
      '<div class="kt-ask-body kt-dlg-scroll" data-kt-scroll role="log" aria-live="polite"></div>' +
      '<div class="kt-ask-foot"></div>';
    var body = card.querySelector('.kt-ask-body'), foot = card.querySelector('.kt-ask-foot');

    if (!hasBundle()) {
      var admin = roleNow() === 'agency_admin' || roleNow() === 'platform_admin';
      body.innerHTML = '<div class="kt-ask-up"><p><b>Ask KiddieTrac</b> answers questions about your centres in plain words: who owes money, who is not in yet, which certifications are expiring, how full each room is.</p>' +
        '<p>It is part of the <b>AI Bundle</b>, which your agency does not have switched on.</p>' +
        (admin ? '<p><button type="button" data-kt-iconized="1" class="kt-ask-chip kt-ask-link" data-go="#licence">See the AI Bundle \u2192</button></p>' : '<p>Ask your agency administrator about adding the AI Bundle.</p>') + '</div>';
      foot.style.display = 'none';
      wire(card, opts, body, null);
      return;
    }

    foot.innerHTML = '<div class="kt-compose-row"><textarea class="kt-compose-input" rows="1" maxlength="600" placeholder="Ask about your centres, or how to do something\u2026" aria-label="Your question"></textarea>' +
      '<button type="button" class="kt-send-one" aria-label="Ask" disabled>\u27a4</button></div>' +
      '<div class="kt-ask-note">Answers come from your agency\u2019s records and the KiddieTrac help articles. Check before acting on them.</div>';
    var ta = foot.querySelector('textarea'), send = foot.querySelector('.kt-send-one');
    var busy = false;

    function paint() {
      var h = H(), html = '';
      if (!h.length) {
        html = '<div class="kt-ask-intro">Ask about your centres, or how to do something in KiddieTrac. For example:</div>' +
          '<div class="kt-ask-chips">' + SUGGEST.map(function (q) { return '<button type="button" data-kt-iconized="1" class="kt-ask-chip" data-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') + '</div>';
      } else {
        html = '<button type="button" data-kt-iconized="1" class="kt-ask-reset">Start a new conversation</button>';
        h.forEach(function (m, i) {
          if (m.role === 'user') { html += '<div class="kt-ask-msg kt-ask-q"><div>' + esc(m.text) + '</div></div>'; return; }
          var last = i === h.length - 1;
          html += '<div class="kt-ask-msg kt-ask-a' + (m.error ? ' kt-ask-err' : '') + '"><div class="kt-ask-bub">' + (m.error ? esc(m.text) : render(m.text)) + '</div>' +
            ((m.links && m.links.length) || (last && m.followups && m.followups.length) ? '<div class="kt-ask-chips">' +
              (m.links || []).map(function (l) { return '<button type="button" data-kt-iconized="1" class="kt-ask-chip kt-ask-link" data-go="' + esc(l.hash) + '">' + esc(l.label) + ' \u2192</button>'; }).join('') +
              (last ? (m.followups || []).map(function (q) { return '<button type="button" data-kt-iconized="1" class="kt-ask-chip" data-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') : '') +
            '</div>' : '') + '</div>';
        });
      }
      if (busy) html += '<div class="kt-ask-msg kt-ask-a"><div class="kt-ask-bub"><span class="kt-ask-busy" aria-label="Looking it up"><i></i><i></i><i></i></span></div></div>';
      body.innerHTML = html;
      body.scrollTop = body.scrollHeight;
    }

    function grow() { ta.style.height = '44px'; ta.style.height = Math.min(132, Math.max(44, ta.scrollHeight)) + 'px'; }
    function sync() { send.disabled = busy || !ta.value.trim(); }
    ta.addEventListener('input', function () { grow(); sync(); });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(); } });
    send.addEventListener('click', ask);

    function ask(text) {
      var q = (typeof text === 'string' ? text : ta.value).trim();
      if (!q || busy) return;
      var h = H();
      var prior = h.filter(function (m) { return !m.error; }).slice(-6).map(function (m) { return { role: m.role, text: String(m.text || '').slice(0, 1500) }; });
      h.push({ role: 'user', text: q }); save(h);
      if (typeof text !== 'string') { ta.value = ''; grow(); }
      busy = true; send.setAttribute('aria-busy', 'true'); sync(); paint();
      KT.Api.post('/ai/assist/ask', { question: q, history: prior }).then(function (r) {
        h.push({ role: 'assistant', text: (r && r.answer) || 'I could not find an answer to that.', links: (r && r.links) || [], followups: (r && r.followups) || [] });
      }).catch(function (e) {
        h.push({ role: 'assistant', error: true, text: (e && e.message) || 'Ask KiddieTrac is not available right now. Please try again in a moment.' });
      }).then(function () {
        busy = false; send.removeAttribute('aria-busy'); save(h); sync(); paint();
        try { ta.focus({ preventScroll: true }); } catch (x) {}
      });
    }

    wire(card, opts, body, ask, function () { var h = H(); h.length = 0; save(h); paint(); });
    paint();
    if (opts.panel) setTimeout(function () { try { ta.focus({ preventScroll: true }); } catch (x) {} }, 60);
  }

  function wire(card, opts, body, ask, reset) {
    card.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('button'); if (!t) return;
      if (t.classList.contains('kt-ask-x')) { close(); return; }
      if (t.classList.contains('kt-ask-full')) { close(); location.hash = '#ask'; return; }
      if (t.classList.contains('kt-ask-reset') && reset) { reset(); return; }
      if (t.hasAttribute('data-q') && ask) { ask(t.getAttribute('data-q')); return; }
      if (t.hasAttribute('data-go')) { var g = t.getAttribute('data-go'); if (opts.panel) close(); location.hash = g; }
    });
  }

  /* ── the slide-over ── */
  function onKey(e) { if (e.key === 'Escape') close(); }
  function close() {
    var ov = d.getElementById('kt-ask-ov'); if (ov) ov.remove();
    d.removeEventListener('keydown', onKey, true);
  }
  function open() {
    if (!canSee()) return;
    css();
    if (d.getElementById('kt-ask-ov')) { close(); return; }
    /* data-kt-no-close: the ✕ is already in this header's top-right. Without it kt-modal-guard laid its own ✕ over
       ours and tinted it while the panel was still sliding in, so on a desktop it came out grey on the gradient.
       data-kt-nomove: a slide-over is pinned to the right edge, not a card to drag or resize. */
    var ov = d.createElement('div'); ov.id = 'kt-ask-ov'; ov.setAttribute('data-no-modal-guard', '1'); ov.setAttribute('data-kt-no-close', '1'); ov.setAttribute('data-kt-nomove', '1');
    var card = d.createElement('div'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'AI assistant');
    ov.appendChild(card); d.body.appendChild(ov);
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    d.addEventListener('keydown', onKey, true);
    mount(card, { panel: true });
  }

  /* ── the page (#ask) ── */
  function page(main) {
    css();
    if (!canSee()) { main.innerHTML = '<div class="kt-section"><p>Ask KiddieTrac is for administrators and centre directors.</p></div>'; return; }
    main.innerHTML = '<div class="kt-section kt-ask-page"><div class="kt-ask-host"></div></div>';
    mount(main.querySelector('.kt-ask-host'), { panel: false });
  }

  KT.ask = { open: open, close: close, canSee: canSee, available: available, render: render };
  /* No button of its own any more: the top bar's 🤖 decides at the tap (kt-topbar.js), so load order no longer
     matters. A ✨ left by an older top bar still in the page is taken away. */
  function dropOldBtn() { var b = d.getElementById('kt-tb-ask'); if (b) b.remove(); }
  dropOldBtn();
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', dropOldBtn);
  function reg() {
    if (!KT.Shell || !KT.Shell.registerScreen) return false;
    ADMIN.forEach(function (r) { KT.Shell.registerScreen(r + ':ask', page); });
    return true;
  }
  if (!reg()) d.addEventListener('DOMContentLoaded', reg);
})(window, document);
