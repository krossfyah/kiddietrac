/* kt-webchat-strip.js — WEBSITE CHATS IN THE MESSAGE CENTRE (2026-10-30).
 *
 * Anthony: "website chat should show up in my message centre for superadmin", then "add a subtab in message
 * centre for website site". The website's live chats (Sales → Website chat) are a third subtab of the Message
 * Centre - 💬 Inbox | 📥 Archived | 🌐 Website - for super admins: who, the last line, waiting / wants a person /
 * on the site, and one click opens that conversation in the website-chat screen (join, reply, nudge, add as lead).
 * screen-chat.js draws the tab and asks this file for the list (KT.webChats). Refreshed every 20s while #chat is open.
 * Super admins only (the /sales/web-chats API is the same gate).
 * 2026-10-30: a website chat opens like every other conversation - the chat dock on a computer, full screen in
 * Message Centre on a phone - through mountThread()/open() below (the one thread renderer for website chats).
 */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};
  var timer = null, chats = null, loading = false, listeners = [], archived = [];   // archived: website chats this person removed (the ✕)

  function isSuper() {
    try {
      if (sessionStorage.getItem('kt_view_as')) return false;
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {};
      return (u.roles || []).indexOf('platform_admin') !== -1 || u.primary_role === 'platform_admin';
    } catch (e) { return false; }
  }
  function onChat() { return /^#chat(\?|$)/.test(location.hash || ''); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function ago(iso) {
    var t = Date.parse(iso || ''); if (!t) return '';
    var m = Math.round((Date.now() - t) / 60000);
    return m < 1 ? 'now' : m < 60 ? m + 'm' : m < 1440 ? Math.round(m / 60) + 'h' : Math.round(m / 1440) + 'd';
  }

  /* q: the Message Centre's search box (2026-11-06, Anthony: "clicking on website subtab the filter field is missing
     compared to the other subtabs"). The box was hidden on this tab because it only searched the conversation list; it
     now searches website chats by name, email and last message. Kept on the host so Undo's own redraw keeps it. */
  function render(host, q) {
    if (!host) return;
    if (q !== undefined) host.__ktWcQ = String(q || '');
    q = String(host.__ktWcQ || '').trim().toLowerCase();
    if (chats === null) { host.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;font-size:13px;">Loading website chats…</div>'; load(); return; }
    var shown = !q ? chats : chats.filter(function (c) { return ((c.name || '') + ' ' + (c.email || '') + ' ' + (c.preview || '') + ' ' + (c.agent_name || '')).toLowerCase().indexOf(q) !== -1; });
    var head = '<div style="display:flex;align-items:center;gap:8px;padding:4px 2px 8px;"><span style="font-size:12.5px;color:#64748B;">'
      + (!chats.length ? 'No open website chats' : (q ? shown.length + ' of ' + chats.length + ' match' : chats.length + ' open on the website')) + '</span>'
      + '<a href="#sales-webchat" style="margin-left:auto;font-size:12.5px;font-weight:700;color:#1F6080;text-decoration:none;">Open the website-chat inbox →</a></div>';
    if (!shown.length) {
      host.innerHTML = head + '<div style="padding:26px;text-align:center;color:#64748B;font-size:13px;border:1px dashed #E2E8F0;border-radius:12px;background:#fff;">'
        + (chats.length ? 'No matching website chats.' : 'When someone starts a chat on kiddietrac.com it shows here.') + '</div>';
      return;
    }
    host.innerHTML = head + '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;overflow:hidden;">' + shown.map(function (c, i) {
      var who = c.name || c.email || 'Website visitor';
      var tags = (c.wants_human ? '<span style="font-size:10.5px;font-weight:800;color:#B45309;background:#FEF3C7;border-radius:999px;padding:1px 7px;">Wants a person</span>' : '')
        + (c.present ? '<span style="font-size:10.5px;font-weight:800;color:#047857;background:#DCFCE7;border-radius:999px;padding:1px 7px;">On the site</span>' : '')
        + (c.agent_name ? '<span style="font-size:10.5px;color:#475569;">with ' + esc(c.agent_name) + '</span>' : '');
      return '<a href="#chat?web=' + c.id + '" data-wc-id="' + c.id + '" style="display:flex;align-items:center;gap:10px;padding:10px 12px;' + (i ? 'border-top:1px solid #EEF2F6;' : '') + 'text-decoration:none;color:inherit;">'
        + '<span style="width:34px;height:34px;flex:0 0 34px;border-radius:50%;background:#E0EEF4;color:#1F6080;display:inline-flex;align-items:center;justify-content:center;font-size:16px;">🌐</span>'
        + '<span style="flex:1;min-width:0;"><span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;"><span style="font-weight:' + (c.unread ? 800 : 600) + ';color:#0F172A;font-size:13.5px;">' + esc(who) + '</span>' + tags + '</span>'
        + '<span style="display:block;font-size:12.5px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(c.preview || '') + '</span></span>'
        + (c.unread ? '<span style="background:#1F6080;color:#fff;font-size:11px;font-weight:800;min-width:20px;height:20px;padding:0 6px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;">' + c.unread + '</span>' : '')
        + '<span style="font-size:11.5px;color:#94A3B8;flex:0 0 auto;">' + esc(ago(c.at)) + '</span>'
        /* The same ✕ as every other conversation (2026-11-01): off MY list (/chat-archive, kind web), with
           Undo; the visitor's chat is not ended, and it comes back if they write again. */
        + '<span class="kt-wc-x" data-wc-x="' + c.id + '" role="button" title="Remove from list" aria-label="Remove from list" '
        + 'style="flex:0 0 auto;color:#CBD5E1;font-size:17px;line-height:1;padding:4px 6px;cursor:pointer;">&times;</span></a>';
    }).join('') + '</div>';
    Array.prototype.forEach.call(host.querySelectorAll('a[data-wc-id]'), function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (e.target && e.target.closest && e.target.closest('.kt-wc-x')) { archiveRow(a, a.getAttribute('data-wc-id'), host); return; }
        open(a.getAttribute('data-wc-id'));
      });
    });
  }

  /* Six seconds to change your mind, like the chat dock's ✕ (kt-chat-dock.js offerUndo). */
  function archiveRow(a, id, host) {
    id = parseInt(id, 10); if (!id || !KT.Api) return;
    a.style.opacity = '.4';
    KT.Api.post('/chat-archive', { kind: 'web', id: id, archived: true }, { quiet: true }).then(function () {
      archived.push(String(id));
      a.style.opacity = '';
      a.innerHTML = '<span style="flex:1;font-size:12.5px;color:#64748B;">Removed</span>'
        + '<button type="button" class="kt-wc-undo" style="background:none;border:0;color:#1F6080;font:inherit;font-size:12.5px;font-weight:800;cursor:pointer;padding:2px 6px;">Undo</button>';
      var gone = setTimeout(function () { chats = (chats || []).filter(function (c) { return String(c.id) !== String(id); }); render(host); notify(); }, 6000);
      var u = a.querySelector('.kt-wc-undo');
      u.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation(); clearTimeout(gone); u.disabled = true; u.textContent = 'Restoring…';
        KT.Api.post('/chat-archive', { kind: 'web', id: id, archived: false }, { quiet: true }).then(function () {
          archived = archived.filter(function (x) { return x !== String(id); }); render(host);
        }).catch(function () { u.disabled = false; u.textContent = 'Undo'; });
      });
    }).catch(function (e) { a.style.opacity = ''; toast('⚠️', 'Could not remove that chat', e && e.message, '#DC2626'); });
  }
  function notify() { listeners.forEach(function (f) { try { f(); } catch (e) {} }); }

  function load() {
    clearTimeout(timer); timer = null;
    if (!onChat() || !isSuper() || !KT.Api || loading) return;
    loading = true;
    Promise.all([KT.Api.get('/sales/web-chats', { status: 'open' }), KT.Api.get('/chat-archive').catch(function () { return null; })])
      .then(function (both) {
        var r = both[0];
        // What this person removed from their list (the ✕) stays off it, here and in the badge counts.
        if (both[1]) archived = ((both[1] && both[1].web) || []).map(String);
        var next = ((r && r.chats) || []).filter(function (c) { return archived.indexOf(String(c.id)) === -1; });
        var changed = JSON.stringify(next) !== JSON.stringify(chats);
        chats = next;
        if (changed) listeners.forEach(function (f) { try { f(); } catch (e) {} });
      })
      .catch(function () { if (chats === null) chats = []; })
      .then(function () { loading = false; if (onChat()) timer = setTimeout(load, d.hidden ? 60000 : 20000); });
  }


  /* ── ONE WEBSITE-CHAT THREAD, WHEREVER IT OPENS (2026-10-30, Anthony: "web site chat on mobile and desktop should
     be the popout style like we have it with the regular"). A website chat used to open only as a pane of the
     Website chat screen. It now opens like any other conversation: in the floating chat dock on a computer (beside
     the conversation list, minimise / close, restored after a reload as 'web:<id>'), and full screen in Message
     Centre on a phone. mountThread() is the one renderer - the dock, the phone and the Website chat screen all use
     it: header (who, on the site / left, with whom) + Join / Take over, Nudge, Hand back to Maya, Add as lead /
     Open lead, Close; messages; "typing…"; reply box (Enter sends, typing is sent to the visitor). It polls itself
     every 2.5 s while it is on the page. */
  function css() {
    if (d.getElementById('kt-wcx-css')) return;
    var s = d.createElement('style'); s.id = 'kt-wcx-css';
    s.textContent = ''
      + '.kt-wcx{display:flex;flex-direction:column;min-height:0;height:100%;background:#fff}'
      + '.kt-wcx-head{flex:0 0 auto;padding:10px 12px;border-bottom:1px solid #EEF2F6;display:flex;flex-direction:column;gap:7px;min-width:0}'
      + '.kt-wcx-top{display:flex;gap:9px;align-items:center;min-width:0}'
      + '.kt-wcx-av{flex:0 0 34px;width:34px;height:34px;border-radius:50%;background:#E0EEF4;color:#1F6080;display:inline-flex;align-items:center;justify-content:center;font-size:16px;position:relative}'
      + '.kt-wcx-av i{position:absolute;right:-1px;bottom:-1px;width:10px;height:10px;border-radius:50%;background:#22C55E;border:2px solid #fff}'
      + '.kt-wcx-name{font-weight:800;font-size:14.5px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
      + '.kt-wcx-sub{font-size:12px;color:#64748B;overflow-wrap:anywhere}'
      + '.kt-wcx-sub a{color:#0E7490}'
      + '.kt-wcx-acts{display:flex;gap:6px;flex-wrap:wrap}'
      + '.kt-wcx-acts button{border:1px solid #D9E2EA;background:#fff;color:#0F172A;border-radius:8px;font:inherit;font-size:12px;font-weight:700;padding:5px 10px;cursor:pointer;min-height:0;line-height:1.2}'
      + '.kt-wcx-acts button.p{background:#1F6080;border-color:#1F6080;color:#fff}'
      + '.kt-wcx-acts button.x{color:#B91C1C;border-color:#FCA5A5}'
      + '.kt-wcx-acts button[disabled]{opacity:.55;cursor:default}'
      + '.kt-wcx-back{background:none;border:0;color:#1F6080;font-size:22px;line-height:1;cursor:pointer;padding:4px 4px 4px 0;min-height:0}'
      + '.kt-wcx-msgs{flex:1 1 auto;min-height:0;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#FBFCFD}'
      + '.kt-wcx-m{display:flex;flex-direction:column;max-width:82%}.kt-wcx-m.l{align-self:flex-start;align-items:flex-start}.kt-wcx-m.r{align-self:flex-end;align-items:flex-end}'
      + '.kt-wcx-who{font-size:10.5px;color:#94A3B8;margin:0 5px 2px;font-weight:700}'
      + '.kt-wcx-b{padding:8px 12px;border-radius:14px;font-size:13.5px;line-height:1.4;white-space:pre-wrap;overflow-wrap:anywhere}'
      /* The team's own replies: the same soft teal as every other thread's "mine" bubble (screen-chat.js .kt-st-mine,
         2026-11-27), not the dark #1F6080. A white-label agency keeps its own tint (branding-loader recolours the inline
         colour and sets html.kt-tinted, which this leaves alone). */
      + 'html:not(.kt-tinted) .kt-wcx-b.kt-wcx-mine{background:#DCF1F4!important;color:#0D1B2A!important;border:1px solid #C9E7EC!important}'
      + 'html:not(.kt-tinted) .kt-wcx-mine .kt-rp-q.kt-rp-dark{background:rgba(15,23,42,.06)!important;border-left-color:#159FB4!important}'
      + 'html:not(.kt-tinted) .kt-wcx-mine .kt-rp-q.kt-rp-dark b{color:#0E7490!important}'
      + ':is(html.kt-dark,html[data-theme="dark"]):not(.kt-tinted) .kt-wcx-b.kt-wcx-mine{background:#134E5E!important;color:#E6FFFB!important;border-color:#134E5E!important}'
      + '.kt-wcx-sys{align-self:center;font-size:11.5px;color:#64748B;background:#F1F5F9;border-radius:999px;padding:3px 11px;text-align:center;max-width:90%}'
      + '.kt-wcx-typing{flex:0 0 auto;font-size:12px;color:#64748B;padding:0 14px 4px;min-height:17px}'
      + '.kt-wcx-comp{flex:0 0 auto;display:flex;gap:6px;padding:10px;border-top:1px solid #E5E7EB;align-items:center;background:#fff}'
      + '.kt-wcx-comp textarea{flex:1;min-width:0;max-width:none!important;resize:none;height:40px;max-height:120px;padding:9px 11px;border:1px solid #D9E2EA;border-radius:10px;font:inherit;font-size:14px;box-sizing:border-box}'
      /* the send arrow is THE ONE send button, .kt-send-one (kt-chat-composer.css), and the box is the shared
         round typing box (.kt-compose-input) - the website chat types like every other chat (2026-11-02) */
      + '#kt-chat-dock .kt-wcx-back{display:none!important}'
      /* On a phone it carries the regular thread's classes (kt-thread-header / -body / -compose), so kt-mobile-app.css
         gives it the same full-screen presentation: fixed over the screen, bottom bar hidden, safe-area padding. */
      + '.kt-wcx-phone .kt-wcx-head{background:linear-gradient(135deg,#EAF3FB,#F3F0FF);border-bottom-color:rgba(31,96,128,.12)}'
      /* The phone's 42-44px button floor (#appMain button) would make every action a slab. */
      + '#appMain .kt-wcx .kt-wcx-acts button{min-height:0!important;height:30px!important;padding:0 10px!important;font-size:12px!important;width:auto!important}'
      + '#appMain .kt-wcx .kt-wcx-back{min-height:0!important;min-width:0!important;width:auto!important;height:auto!important}'
      + '';
    d.head.appendChild(s);
  }
  function clock(ts) {
    if (!ts) return '';
    if (KT.fmtTime) { try { return KT.fmtTime(ts); } catch (e) {} }
    try { return new Date(ts).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
  }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c || '#1F6080'); } catch (e) {} }
  function mk(tag, attrs, html) {
    var e = d.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'style') e.style.cssText = attrs[k]; else e.setAttribute(k, attrs[k]); });
    if (html != null) e.innerHTML = html;
    return e;
  }

  function mountThread(host, id, opts) {
    opts = opts || {};
    css();
    id = parseInt(id, 10);
    var st = { chat: null, lastId: 0, dead: false, busy: false, bottom: true };
    host.innerHTML = '';
    var root = mk('div', { class: 'kt-wcx', 'data-kt-self-live': '1', 'data-kt-no-autorefresh': '1', 'data-kt-no-tips': '1', 'data-kt-noband': '1', 'data-kt-btn-free': '1' });
    if (opts.phone) root.classList.add('kt-wcx-phone');
    var head = mk('div', { class: 'kt-wcx-head' + (opts.phone ? ' kt-thread-header' : '') });
    var msgs = mk('div', { class: 'kt-wcx-msgs' + (opts.phone ? ' kt-thread-body' : '') }, '<div class="kt-wcx-sys">Loading the conversation…</div>');
    var typing = mk('div', { class: 'kt-wcx-typing' });
    var ta = mk('textarea', { rows: '1', class: 'kt-compose-input', placeholder: 'Reply…', 'aria-label': 'Reply' });
    var send = mk('button', { type: 'button', class: 'kt-send-one', title: 'Send', 'aria-label': 'Send', 'data-kt-iconized': '1', disabled: 'disabled' }, '➤');
    var comp = mk('div', { class: 'kt-wcx-comp kt-compose-row' + (opts.phone ? ' kt-thread-compose' : '') });
    comp.appendChild(ta); comp.appendChild(send);
    // Above the composer: "Replying to …" while a reply is being written (kt-chat-reply.js).
    var rpSlot = mk('div', { class: 'kt-rp-slot', style: 'flex:0 0 auto' });
    root.appendChild(head); root.appendChild(msgs); root.appendChild(typing); root.appendChild(rpSlot); root.appendChild(comp);
    host.appendChild(root);
    var RP = KT.chatReply || null, rpKey = 'web:' + id;
    if (RP) RP.wire(root, { key: rpKey, input: function () { return ta; } });
    var first = true;

    function alive() { return !st.dead && d.body.contains(root); }
    function who(m) {
      if (m.sender === 'visitor') return (st.chat && (st.chat.name || st.chat.email)) || 'Visitor';
      if (m.sender === 'bot') return 'Maya · virtual assistant';
      return m.author || 'Team';
    }
    function bubble(m) {
      var body = String(m.body || '');
      if (m.sender === 'system') return mk('div', { class: 'kt-wcx-sys' }, esc(body) + ' · ' + esc(clock(m.at)));
      if (m.sender === 'visitor' && body.indexOf('[chat started]') === 0) return mk('div', { class: 'kt-wcx-sys' }, 'Chat started · ' + esc(clock(m.at)));
      var mine = m.sender === 'agent' || m.sender === 'bot';
      var bg = m.sender === 'visitor' ? 'background:#fff;border:1px solid #E2E8F0;color:#0F172A;border-bottom-left-radius:5px'
        : m.sender === 'bot' ? 'background:#F3F0FF;border:1px solid #E4DCFF;color:#3B2F6B;border-bottom-right-radius:5px'
        : 'background:#1F6080;color:#fff;border-bottom-right-radius:5px';
      var w = mk('div', { class: 'kt-wcx-m ' + (mine ? 'r' : 'l'), 'data-mid': String(m.id) });
      w.appendChild(mk('div', { class: 'kt-wcx-who' }, esc(who(m)) + ' · ' + esc(clock(m.at))
        + (RP ? RP.btn(m.id, who(m), body) : '')));
      w.appendChild(mk('div', { class: 'kt-wcx-b' + (m.sender === 'agent' ? ' kt-wcx-mine' : ''), style: bg }, (RP ? RP.quote(m, { dark: m.sender === 'agent' }) : '')
        + esc(body.replace(/\*\*([^*]+)\*\*/g, '$1'))));
      return w;
    }
    function append(list) {
      if (!list || !list.length) return;
      if (first) { msgs.innerHTML = ''; first = false; }
      var near = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 80;
      list.forEach(function (m) { if (m.id > st.lastId) { msgs.appendChild(bubble(m)); st.lastId = m.id; } });
      if (near || st.bottom) { msgs.scrollTop = msgs.scrollHeight; st.bottom = false; }
    }
    function act(label, cls, fn) {
      var b = mk('button', { type: 'button', class: cls || '', 'data-kt-iconized': '1' }, esc(label));
      b.addEventListener('click', function (e) { e.stopPropagation(); fn(b); });
      return b;
    }
    // Chat actions (join, close, hand back, reply, nudge, lead): the chat shows the result - no "Saved" (kt-progress).
    function post(path, body) { return KT.Api.post('/sales/web-chats/' + id + path, body || {}, { quiet: true }); }
    function changed() { try { opts.onChange && opts.onChange(st.chat); } catch (e) {} if (KT.webChats && KT.webChats.load) KT.webChats.load(true); }
    function paintHead() {
      var c = st.chat; if (!c) return;
      var name = c.name || c.email || 'Website visitor';
      var status = c.status !== 'open' ? 'Closed' : (c.present ? '● On the website now' : 'Left the page');
      var withWho = c.agent_name ? ' · with ' + (c.mine ? 'you' : c.agent_name) : (c.status === 'open' ? ' · Maya is answering' : '');
      head.innerHTML = '';
      var top = mk('div', { class: 'kt-wcx-top' });
      if (opts.onBack) {
        var back = mk('button', { type: 'button', class: 'kt-wcx-back kt-back', 'data-kt-iconized': '1', 'aria-label': 'Back' }, '←');
        back.addEventListener('click', function (e) { e.stopPropagation(); stop(); opts.onBack(); });
        top.appendChild(back);
      }
      var av = mk('span', { class: 'kt-wcx-av', title: c.present ? 'On the website now' : '' }, '🌐' + (c.present && c.status === 'open' ? '<i></i>' : ''));
      top.appendChild(av);
      top.appendChild(mk('div', { style: 'flex:1;min-width:0' }, '<div class="kt-wcx-name">' + esc(name) + '</div>'
        + '<div class="kt-wcx-sub">Website chat' + (c.email ? ' · <a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a>' : '') + ' · ' + esc(status + withWho) + (c.page ? ' · ' + esc(c.page) : '') + '</div>'));
      head.appendChild(top);
      var acts = mk('div', { class: 'kt-wcx-acts' });
      if (c.status === 'open' && !c.mine) acts.appendChild(act(c.agent_id ? 'Take over' : 'Join', 'p', function () { post('/join').then(function () { refresh(true); changed(); ta.focus(); }).catch(function (e) { toast('⚠️', 'Could not join', e.message, '#DC2626'); }); }));
      if (c.status === 'open') acts.appendChild(act('👋 Nudge', '', function (b) {
        b.disabled = true;
        post('/nudge').then(function () { toast('👋', 'Nudged', 'They will see it in the chat on the website.'); refresh(); }).catch(function (e) { toast('⚠️', 'Could not nudge', e.message, '#DC2626'); });
        setTimeout(function () { b.disabled = false; }, 60000);
      }));
      if (c.status === 'open' && c.mine) acts.appendChild(act('Hand back to Maya', '', function () { post('/release').then(function () { refresh(true); changed(); }); }));
      if (c.lead) acts.appendChild(act('🎯 Open lead', '', function () { location.hash = '#sales-lead?id=' + c.lead.id; }));
      else acts.appendChild(act('🎯 Add as lead', '', function (b) {
        b.disabled = true;
        post('/lead').then(function (r) { toast('🎯', r && r.created ? 'Lead created' : 'Added to the existing lead', 'The transcript is on the lead as a note.'); refresh(true); changed(); })
          .catch(function (e) { toast('⚠️', 'Could not add lead', e.message, '#DC2626'); b.disabled = false; });
      }));
      if (c.status === 'open') acts.appendChild(act('Close chat', 'x', function () { post('/close').then(function () { refresh(true); changed(); }); }));
      head.appendChild(acts);
      ta.placeholder = c.status !== 'open' ? 'Closed - writing here reopens it if they are still on the page' : (c.mine ? 'Reply to ' + (c.name || 'the visitor') + '…' : 'Reply - sending takes the chat from Maya');
      try { opts.onTitle && opts.onTitle(name); } catch (e) {}
    }
    function refresh(repaint) {
      if (!alive() || st.busy) return Promise.resolve();
      st.busy = true;
      return KT.Api.get('/sales/web-chats/' + id, { after: st.lastId }).then(function (r) {
        st.busy = false;
        if (!alive() || !r || !r.chat) return;
        var p = st.chat; st.chat = r.chat;
        append(r.messages || []);
        if (first) { msgs.innerHTML = '<div class="kt-wcx-sys">No messages yet.</div>'; first = false; }
        if (repaint || !p || p.status !== r.chat.status || p.agent_id !== r.chat.agent_id || p.present !== r.chat.present || !!p.lead !== !!r.chat.lead) paintHead();
        typing.textContent = r.chat.typing ? (r.chat.name || 'The visitor') + ' is typing…' : '';
      }).catch(function (e) {
        st.busy = false;
        if (!st.chat) {
          msgs.innerHTML = '<div class="kt-wcx-sys" style="color:#B91C1C;">Could not open this chat: ' + esc(e && e.message || e) + '</div>';
          try { opts.onError && opts.onError(e); } catch (x) {}
        }
      });
    }
    var lastTyping = 0;
    function syncSend() { send.disabled = sending || !ta.value.trim(); }
    ta.addEventListener('input', function () {
      syncSend();
      ta.style.height = '46px'; ta.style.height = Math.min(120, Math.max(46, ta.scrollHeight + 2)) + 'px';
      var now = Date.now();
      if (ta.value.trim() && now - lastTyping > 3000 && st.chat && st.chat.status === 'open') { lastTyping = now; post('/typing').catch(function () {}); }
    });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } });
    send.addEventListener('click', function (e) { e.stopPropagation(); doSend(); });
    /* Its own in-flight flag, not send.disabled: the portal's double-click guard disables a button in the capture
       phase, before this runs, so "is the button disabled?" always said yes and nothing was ever sent. */
    var sending = false;
    function doSend() {
      var t = ta.value.trim(); if (!t || sending) return;
      sending = true;
      send.disabled = true; send.setAttribute('aria-busy', 'true');
      var replyTo = RP ? RP.peek(rpKey) : null;
      post('/reply', replyTo ? { body: t, reply_to_id: replyTo } : { body: t }).then(function (r) {
        // Sent: only now does the box empty (a failure keeps the draft where it was typed).
        if (ta.value.trim() === t) { ta.value = ''; ta.style.height = '46px'; }
        st.bottom = true;
        if (RP) RP.clear(rpKey, root);
        return refresh(true).then(function () { if (r && r.message) append([r.message]); changed(); });
      }).catch(function (e) { toast('⚠️', 'Not sent', e && e.message, '#DC2626'); })
        .then(function () { sending = false; send.removeAttribute('aria-busy'); syncSend(); try { ta.focus(); } catch (x) {} });
    }
    var timer = setInterval(function () { if (!alive()) { stop(); return; } refresh(); }, 2500);
    function stop() { st.dead = true; clearInterval(timer); }
    refresh(true);
    return { stop: stop, id: id, root: root };
  }

  /* Open a website chat the way every conversation opens: the chat dock on a computer, Message Centre full screen
     on a phone (#chat?web=<id> - screen-chat.js routes it here with a way back to the Website tab). */
  var docked = null;
  function open(id) {
    id = parseInt(id, 10); if (!id) return;
    if (KT.ChatDock && KT.ChatDock.enabled && KT.ChatDock.enabled()) { openInDock(id); return; }
    location.hash = '#chat?web=' + id;
  }
  function openInDock(id) {
    if (docked) { docked.stop(); docked = null; }
    var host = KT.ChatDock.contentEl();
    var key = 'web:' + id;
    KT.ChatDock.show('Website chat', function () { if (docked) { docked.stop(); docked = null; } });
    if (KT.ChatDock.rememberThread) KT.ChatDock.rememberThread(key, 'Website chat');
    docked = mountThread(host, id, {
      onTitle: function (name) {
        if (!docked || docked.id !== id) return;
        KT.ChatDock.show('🌐 ' + name, function () { if (docked) { docked.stop(); docked = null; } });
        if (KT.ChatDock.rememberThread) KT.ChatDock.rememberThread(key, '🌐 ' + name);
      },
      onError: function (e) {
        // A chat this account cannot open (gone, or not a super admin any more): forget it, as regular threads do.
        if (/\b(403|404)\b/.test(String(e && e.message))) { if (KT.ChatDock.forget) KT.ChatDock.forget(); if (KT.ChatDock.hide) KT.ChatDock.hide(); }
      },
    });
    return docked;
  }
  function stopDocked() { if (docked) { docked.stop(); docked = null; } }

  /* The open website chats for the chat dock's conversation list (a fresh read; [] for anyone who is not a super admin). */
  function listForDock() {
    if (!isSuper() || !KT.Api) return Promise.resolve([]);
    return KT.Api.get('/sales/web-chats', { status: 'open' }).then(function (r) { return (r && r.chats) || []; }).catch(function () { return []; });
  }

  KT.webChats = {
    enabled: isSuper,
    open: open,
    openInDock: openInDock,
    stopDocked: stopDocked,
    mountThread: mountThread,
    listForDock: listForDock,
    count: function () { return chats ? chats.length : 0; },
    unread: function () { return (chats || []).filter(function (c) { return c.unread; }).length; },
    render: render,
    onChange: function (f) { listeners = [f]; },   // the Message Centre screen that is up now
    load: load,
  };
  w.addEventListener('hashchange', function () { if (onChat()) setTimeout(load, 300); });
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { if (onChat()) load(); }); else if (onChat()) load();
})(window, document);
