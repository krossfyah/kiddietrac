/* TEXT CONVERSATIONS - one module, every place (2026-11-02).

   Anthony: "Safia should see this response to her announcement in the message centre", then: "i should be able to
   reply to anyone text message and make this into a popup style chat like how we have the other chat with the table
   view as well". A text to the agency's number is filed against the text it answers (sms_messages.owner_user_id /
   announcement_id, App\Support\SmsInbound), and this module shows the conversations:

     LIST    one row per number: who, number, the last message either way, when (agency time), unread.
             Drawn by Message Centre's own inbox row and card (KT.Chat.renderTexts, screen-chat.js) - the same
             avatar, name, last line, status, time and unread badge as every chat, no look-alike (2026-11-02:
             "make this look like how the inbox lists out the chats").
     THREAD  opens like every other conversation: in the floating chat dock on a computer (KT.ChatDock - minimise,
             close, restored after a reload as 'sms:<id>', listed in the dock's conversation list), full screen on a
             phone (kt-thread-header / -body / -compose, so kt-mobile-app.css hides the bottom bar; the window follows
             the visual viewport so the reply box stays above the keyboard). Its header is the ONE popup header
             (navy-to-teal band, teal line) with the close X inside it. Inside, it is the family chat's thread:
             the light name bar, lilac / teal bubbles with the time inside, and the same round typing box and
             send button (.kt-thread-compose / .kt-compose-input / .kt-send-btn, kt-chat-composer.css).

   Used by Message Centre -> 📱 Texts (screen-chat.js), SMS -> Replies (screen-v22p51.js), the dock, and the alert
   link '#chat?sms=<id>'.

   The texts stay in sms_messages - the one record of what went over the carrier; nothing is copied into a chat table
   pretending the person used the app. One read state: opening a conversation reads its texts there, their "Text
   reply from ..." alerts, and the same text bridged into a family's chat. Anyone who texted the agency can be
   answered ('texted_us', as the KiddieTrac line does); never after STOP, on do-not-contact, or with texting off -
   the server decides and says why in words.

   GET /sms-replies (rows + conversations), GET /sms-replies/{id}/thread, POST /sms-replies/{id}/read,
   POST /sms-replies/{id}/reply   (SmsRepliesController; App\Support\SmsReplies::visible decides who sees what). */
(function () {
  'use strict';
  var KT = window.KT = window.KT || {};
  if (KT.smsTexts) return;
  var d = document;

  function api() { return KT.Api || window.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function stamp(ts) {
    if (!ts) return '';
    try { if (KT.fmtDateTime) return KT.fmtDateTime(ts); } catch (e) {}
    return String(ts);
  }
  function stampFull(ts) {
    try {
      var dt = KT.parseTs ? KT.parseTs(ts) : new Date(ts);
      if (!dt || isNaN(dt)) return '';
      return new Intl.DateTimeFormat(undefined, { timeZone: KT.tz ? KT.tz() : undefined,
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(dt);
    } catch (e) { return ''; }
  }
  // Phone-shaped: the same test Message Centre uses (the APK WebView reports an inflated width).
  function phoneish() { return window.innerWidth <= 700 || d.documentElement.classList.contains('kt-native'); }
  function docked() { return !!(KT.ChatDock && KT.ChatDock.enabled && KT.ChatDock.enabled()); }
  function last10(p) { return String(p || '').replace(/\D/g, '').slice(-10); }
  function toast(i, t, m, c) { try { if (KT.toast) KT.toast(i, t, m || '', c || '#1F6080'); } catch (e) {} }
  function isGuardian() {
    var va = ''; try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    if (va) return va === 'guardian';
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {};
      var r = u.primary_role || (Array.isArray(u.roles) && u.roles[0]) || '';
      return !r || r === 'guardian';
    } catch (e) { return false; }
  }
  function kindLabel(k) {
    return k === 'stop' ? 'Opted out of texts (STOP)' : k === 'start' ? 'Opted back in to texts (START)' : k === 'help' ? 'Asked for help (HELP)' : '';
  }
  // What the conversation answers, in words: "Reply to your announcement "...", "Reply to Safia's text".
  function context(m) {
    var who = m.mine ? 'your' : (m.owner_first ? m.owner_first + '’s' : '');
    if (m.announcement_title) return 'Reply to ' + (who || 'an') + ' announcement “' + m.announcement_title + '”';
    if (who) return 'Reply to ' + who + ' text';
    return '';
  }

  /* ---- shared state ---- */
  var st = { rows: null, convs: null, unread: 0, oversees: false, allowed: null, loading: null, open: 0 };
  var listeners = [];
  function changed() { listeners.slice().forEach(function (f) { try { f(); } catch (e) {} }); }

  function load() {
    var A = api();
    if (!A) return Promise.resolve(null);
    if (st.loading) return st.loading;
    st.loading = A.get('/sms-replies').then(function (r) {
      st.loading = null;
      st.allowed = true;
      st.rows = (r && r.data) || [];
      st.convs = (r && r.conversations) || [];
      st.oversees = !!(r && r.oversees);
      st.unread = +(r && r.unread) || 0;
      paintList();
      changed();
      return r;
    }, function (e) {
      st.loading = null;
      var s = e && e.status;
      // Not staff here, no agency chosen, or no texting on the plan: nothing is offered.
      // 400 (no agency chosen yet) and 401 can pass: asked again later. 402/403 are an answer.
      if (s === 402 || s === 403) { st.allowed = false; st.rows = []; st.convs = []; changed(); }
      throw e;
    });
    return st.loading;
  }

  // The badge feed (GET /badges sms_replies) says when a text came in; the list follows.
  window.addEventListener('kt:badges', function (e) {
    var n = e && e.detail ? (+e.detail.sms_replies || 0) : null;
    if (n == null) return;
    var grew = n > st.unread;
    st.unread = n;
    if (grew && st.allowed) {
      load().catch(function () {});
      // The open conversation shows it now too (it otherwise waits for its own 8 s check).
      try { if (current && current.h && current.h.refresh) current.h.refresh(); } catch (e) {}
    }
    else changed();
  });

  /* ---- CSS ---- */
  var HEAD_BAND = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';   // the ONE popup header
  function css() {
    if (d.getElementById('kt-smx-css')) return;
    var s = d.createElement('style');
    s.id = 'kt-smx-css';
    s.textContent = ''
      /* list: the inbox's rows and cards (KT.Chat.renderTexts) sit in a plain white panel, no band */
      + '.kt-smt{background:#FFFFFF;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;min-width:0;}'
      + '.kt-smt-q{display:block;width:100%;box-sizing:border-box;margin:0 0 10px;padding:9px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;background:#fff;}'
      + '.kt-smt-q:focus{outline:none;border-color:#1F6080;}'
      + '.kt-smt-empty{padding:28px 16px;text-align:center;color:#64748B;font-size:13px;}'
      + '.kt-smt.kt-smt-phone{background:transparent;border:0;border-radius:0;overflow:visible;}'
      /* thread: the family chat's look (screen-chat.js openThread) */
      + '.kt-smx{display:flex;flex-direction:column;min-height:0;height:100%;background:#F9FAFB;}'
      + '.kt-smx-head{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:14px 16px;min-width:0;'
      +   'border-bottom:1px solid rgba(31,96,128,.12);background:linear-gradient(135deg,#EAF3FB,#F3F0FF);}'
      + '.kt-smx-av{flex:0 0 38px;width:38px;height:38px;border-radius:50%;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;}'
      + '.kt-smx-who{flex:1 1 auto;min-width:0;}'
      + '.kt-smx-name{font-weight:700;font-size:16px;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.kt-smx-sub{font-size:13px;color:#6B7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.kt-smx-sub b{color:#1F6080;font-weight:700;}'
      + '.kt-smx-x{flex:0 0 auto;width:32px;height:32px;border:0;border-radius:8px;background:rgba(255,255,255,.16);color:#fff;font-size:20px;line-height:1;cursor:pointer;display:none;align-items:center;justify-content:center;padding:0;}'
      + '.kt-smx-x:hover{background:rgba(255,255,255,.28);}'
      /* phone: the thread IS the popup, so its header is the ONE popup header with the X inside it */
      + '.kt-smx-phone .kt-smx-head{background:' + HEAD_BAND + ';box-shadow:inset 0 -3px 0 #7FD3CC;border-bottom:0;padding:10px 12px 13px;}'
      + '.kt-smx-phone .kt-smx-name{color:#fff;}'
      + '.kt-smx-phone .kt-smx-sub{color:rgba(255,255,255,.85);}'
      + '.kt-smx-phone .kt-smx-sub b{color:#fff;}'
      // The portal turns phone numbers into tap-to-call links (kt-tel); on the band they must stay white.
      + '.kt-smx-phone .kt-smx-sub a.kt-tel,.kt-smx-phone .kt-smx-sub a{color:#fff!important;text-decoration:underline;text-decoration-color:rgba(255,255,255,.5);}'
      + '.kt-smx-phone .kt-smx-av{box-shadow:0 0 0 2px rgba(255,255,255,.35);}'
      + '.kt-smx-phone .kt-smx-x{display:inline-flex;}'
      + '.kt-smx-msgs{flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:#F9FAFB;}'
      + '.kt-smx-m{display:flex;gap:8px;align-items:flex-end;}'
      + '.kt-smx-m.in{justify-content:flex-start;}'
      + '.kt-smx-m.out{justify-content:flex-end;}'
      + '.kt-smx-mav{flex:0 0 30px;width:30px;height:30px;border-radius:50%;color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;align-self:flex-end;}'
      + '.kt-smx-b{max-width:78%;padding:10px 13px;border-radius:16px;min-width:0;}'
      + '.kt-smx-m.in .kt-smx-b{background:#EFEAFB;color:#1E1B34;border-bottom-left-radius:5px;}'
      + '.kt-smx-m.out .kt-smx-b{background:#DCF1F4;color:#0D1B2A;border-bottom-right-radius:5px;}'
      + '.kt-smx-m.out.notsent .kt-smx-b{background:#FFF7ED;color:#7C2D12;box-shadow:inset 0 0 0 1px #FED7AA;}'
      + '.kt-smx-m.hit .kt-smx-b{box-shadow:0 0 0 2px #2F8C86;}'
      + '.kt-smx-who2{font-size:11px;font-weight:800;margin-bottom:2px;}'
      + '.kt-smx-txt{font-size:15px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;}'
      + '.kt-smx-meta{font-size:10.5px;color:rgba(13,27,42,.5);margin-top:4px;}'
      + '.kt-smx-m.out .kt-smx-meta{text-align:right;}'
      + '.kt-smx-m.notsent .kt-smx-meta{color:#B45309;font-weight:700;}'
      + '.kt-smx-ev{align-self:center;font-size:11.5px;font-weight:700;border-radius:999px;padding:3px 11px;text-align:center;max-width:92%;}'
      + '.kt-smx-ev.stop{background:#FEF2F2;color:#B91C1C;}'
      + '.kt-smx-ev.start{background:#ECFDF5;color:#047857;}'
      + '.kt-smx-ev.help{background:#F1F5F9;color:#475569;}'
      + '.kt-smx-sys{align-self:center;font-size:12px;color:#64748B;background:#F1F5F9;border-radius:999px;padding:4px 12px;text-align:center;max-width:92%;}'
      /* the typing bar is the family chat's (.kt-thread-compose, styled by kt-chat-composer.css) */
      + '.kt-smx-comp{flex:0 0 auto;display:flex;gap:6px;align-items:center;padding:10px;border-top:1px solid #E5E7EB;background:#fff;}'
      /* the send arrow is THE ONE send button, .kt-send-one (kt-chat-composer.css) */
      /* kt-chat-composer.css puts the box on its own line in the popout and on phones, so the emoji / photo / mic
         tools fit beneath it. A text reply has no tools: box and send share ONE line (no lone arrow on a row). */
      + '#kt-chat-dock .kt-smx-comp.kt-thread-compose,#appMain .kt-smx-comp.kt-thread-compose.kt-thread-compose{flex-wrap:nowrap!important;}'
      + '#kt-chat-dock .kt-smx-comp.kt-thread-compose>input.kt-compose-input.kt-compose-input,'
      +   '#appMain .kt-smx-comp.kt-thread-compose>input.kt-compose-input.kt-compose-input{order:0!important;flex:1 1 auto!important;width:auto!important;}'
      + '#kt-chat-dock .kt-smx-comp.kt-thread-compose>button.kt-send-btn,#appMain .kt-smx-comp.kt-thread-compose>button.kt-send-btn{margin-left:0!important;}'
      + '.kt-smx-hint{flex:0 0 auto;padding:6px 14px;font-size:11px;color:#64748B;line-height:1.35;background:#F9FAFB;border-top:1px solid #EEF2F6;}'
      + '.kt-smx-hint[hidden]{display:none;}'
      + '.kt-smx-hint .kt-smx-err{color:#B91C1C;font-weight:700;}'
      + '.kt-smx-out:not(:empty){display:block;margin-top:3px;}'
      + '.kt-smx-note{flex:0 0 auto;margin:0;padding:10px 12px;font-size:12.5px;line-height:1.45;color:#92400E;background:#FFFBEB;border-top:1px solid #FDE68A;}'
      /* the phone's 42-44px button floor and textarea rules would make these slabs */
      + '#appMain .kt-smx .kt-smx-x{min-height:0!important;min-width:0!important;width:32px!important;height:32px!important;padding:0!important;}'
      + ''
      /* the phone popup follows the visual viewport, so the keyboard never covers the reply box */
      + 'body:has(.kt-thread-compose) #appMain div.kt-smx.kt-smx-phone{top:var(--kt-smx-top,0px)!important;bottom:auto!important;'
      +   'height:var(--kt-smx-h,100dvh)!important;max-height:var(--kt-smx-h,100dvh)!important;z-index:9450!important;}'
      + '.kt-smx-phone .kt-smx-head.kt-thread-header{padding-top:calc(var(--kt-safe-top, env(safe-area-inset-top, 0px)) + 10px)!important;}';
    d.head.appendChild(s);
  }

  /* ---- the LIST ---- */
  var view = null;   // { host, opts }

  function emptyText() {
    return st.oversees
      ? 'No texts have come in to your agency’s number yet. When someone texts it, the conversation shows here.'
      : 'No replies yet. When someone texts back after an announcement or message you sent by text, it shows here.';
  }
  // A link to one text ('#chat?sms=<id>', '#sms?tab=replies&id=<id>'): open it once the list says it is ours.
  function openLink(want) {
    want = +want || 0;
    if (!want) return;
    load().then(function () {
      if ((st.rows || []).some(function (x) { return +x.id === want; })) open(want, { fromLink: true });
      else toast('⚠️', 'That text is not one of your conversations here', 'It may belong to another agency: switch agency at the top, then open it again.', '#B45309');
    }).catch(function () {});
  }

  function render(host, opts) {
    css();
    opts = opts || {};
    view = { host: host, opts: opts, q: '' };
    /* SMS -> Replies gets the search box every other SMS tab has (2026-11-06, Anthony: sub-tabs missing the
       filter - full sweep). The Message Centre's Texts tab has none here: its own search box filters the list. */
    var withQ = opts.place === 'sms';
    host.innerHTML = (withQ ? '<input type="search" class="kt-smt-q" placeholder="🔍  Search texts…" aria-label="Search texts" autocomplete="off">' : '')
      + '<div class="kt-smt' + (phoneish() ? ' kt-smt-phone' : '') + '" data-kt-noband="1">'
      + '<div class="kt-smt-list"><div class="kt-smt-empty">Loading…</div></div></div>';
    var qi = host.querySelector('.kt-smt-q');
    if (qi) qi.addEventListener('input', function () { if (view && view.host === host) { view.q = qi.value || ''; paintList(); } });
    if (st.convs) paintList();
    var want = +opts.openId || 0;
    load().then(function () {
      if (!view || view.host !== host || !host.isConnected) return;
      if (want) openLink(want);
    }).catch(function (e) {
      if (!view || view.host !== host) return;
      var box = host.querySelector('.kt-smt-list');
      if (box) box.innerHTML = '<div class="kt-smt-empty" style="color:#B91C1C;">' + esc((e && e.status === 400) ? 'Select an agency first.' : (e && e.status === 403) ? 'Text conversations are for staff of this agency.' : 'Could not load texts. Try again in a moment.') + '</div>';
    });
  }
  function live() { return view && view.host && view.host.isConnected ? view.host : null; }

  function paintList() {
    var host = live();
    if (!host) return;
    var box = host.querySelector('.kt-smt-list');
    if (!box) return;
    if (!(KT.Chat && KT.Chat.renderTexts)) { box.innerHTML = '<div class="kt-smt-empty">Loading…</div>'; return; }
    var q = String(view.q || '').trim().toLowerCase(), convs = st.convs || [];
    if (q) convs = convs.filter(function (c) { return [c.person, c.phone, c.last, c.child, c.family].join(' ').toLowerCase().indexOf(q) !== -1 || last10(c.phone).indexOf(q.replace(/\D/g, '') || '~') !== -1; });
    KT.Chat.renderTexts(box, { convs: convs, empty: q ? 'No matching conversations.' : emptyText(), onOpen: function (id) { open(id, {}); } });
  }

  // Opening a conversation reads it: this number's texts, their alerts and the bridged chat copy.
  function markRead(id) {
    // Opened before the list arrived (a restored dock, a link): read it once the list says what is unread.
    if (!st.rows) { load().then(function () { if (st.rows) markRead(id); }).catch(function () {}); return; }
    var row = (st.rows || []).find(function (x) { return +x.id === id; });
    var conv = (st.convs || []).find(function (c) { return +c.id === id; });
    if (!(row && row.unread) && !(conv && conv.unread)) return;
    api().post('/sms-replies/' + id + '/read', {}).then(function (res) {
      var dg = last10((row || conv || {}).to_phone || (conv || {}).phone);
      (st.rows || []).forEach(function (x) { if (+x.id === id || (dg.length >= 7 && last10(x.to_phone) === dg)) x.unread = false; });
      (st.convs || []).forEach(function (c) { if (+c.id === id || (dg.length >= 7 && last10(c.phone) === dg)) c.unread = 0; });
      if (res && res.unread != null) st.unread = +res.unread || 0;
      paintList();
      changed();
      try { if (KT.badges && KT.badges.refresh) KT.badges.refresh(); } catch (e) {}
    }).catch(function () {});
  }

  /* ---- the THREAD (one renderer for the dock and the phone) ---- */
  function colorOf(s) {
    try { if (KT.Chat && KT.Chat.senderColor) return KT.Chat.senderColor(s); } catch (e) {}
    return '#1F6FB2';
  }
  function initial(s) { return esc((String(s || '?').trim().charAt(0) || '?').toUpperCase()); }
  function mk(tag, cls, html) { var e = d.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  function mountThread(host, id, opts) {
    css();
    opts = opts || {};
    id = +id;
    var phone = !!opts.phone;
    var stt = { dead: false, lastSig: '', data: null };
    host.innerHTML = '';
    var root = mk('div', 'kt-smx' + (phone ? ' kt-smx-phone' : ''));
    ['data-kt-self-live', 'data-kt-no-autorefresh', 'data-kt-no-tips', 'data-kt-noband', 'data-kt-btn-free'].forEach(function (a) { root.setAttribute(a, '1'); });
    root.setAttribute('role', phone ? 'dialog' : 'region');
    root.setAttribute('aria-label', 'Text conversation');
    var head = mk('div', 'kt-smx-head' + (phone ? ' kt-thread-header' : ''));
    var msgs = mk('div', 'kt-smx-msgs' + (phone ? ' kt-thread-body' : ''), '<div class="kt-smx-sys">Loading the conversation…</div>');
    msgs.setAttribute('data-kt-scroll', '1');
    // The hint / error line sits above the typing bar; the bar stays the last child (the phone rules key on it).
    var tip = mk('div', 'kt-smx-hint');
    tip.hidden = true;
    var foot = mk('div', 'kt-smx-comp kt-thread-compose kt-compose-row');
    root.appendChild(head); root.appendChild(msgs); root.appendChild(tip); root.appendChild(foot);
    host.appendChild(root);

    function alive() { return !stt.dead && d.body.contains(root); }
    function stop() { stt.dead = true; clearInterval(timer); }

    function paintHead(t) {
      var rep = (t && t.reply) || {};
      var name = (rep.person || '').trim() || 'Unknown number';
      var ctx = context(rep);
      stt.person = name;
      head.innerHTML = '<span class="kt-smx-av" aria-hidden="true" style="background:' + colorOf(name) + ';">' + initial(name) + '</span>'
        + '<div class="kt-smx-who"><div class="kt-smx-name">' + esc(name) + '</div>'
        + '<div class="kt-smx-sub" title="' + esc(ctx) + '">📱 ' + esc(rep.phone || '') + ' · Text messages</div>'
        + (ctx ? '<div class="kt-smx-sub"><b>' + esc(ctx) + '</b></div>' : '') + '</div>'
        + '<button type="button" class="kt-smx-x" aria-label="Close" title="Close" data-kt-iconized="1">×</button>';
      var x = head.querySelector('.kt-smx-x');
      if (x) x.addEventListener('click', function (e) { e.stopPropagation(); if (opts.onClose) opts.onClose(); });
      if (opts.onTitle) opts.onTitle(name);
    }

    function bubble(m) {
      var when = esc(stamp(m.created_at));
      var hit = +m.id === id ? ' hit' : '';
      if (m.direction === 'in' && m.kind && m.kind !== 'message') {
        return '<div class="kt-smx-ev ' + esc(m.kind) + '" data-smx-m="' + (+m.id) + '">' + esc(kindLabel(m.kind)) + ' · ' + when + '</div>';
      }
      if (m.direction === 'in') {
        var who = stt.person || 'Them', col = colorOf(who);
        return '<div class="kt-smx-m in' + hit + '" data-smx-m="' + (+m.id) + '">'
          + '<span class="kt-smx-mav" aria-hidden="true" style="background:' + col + ';">' + initial(who) + '</span>'
          + '<div class="kt-smx-b"><div class="kt-smx-who2" style="color:' + col + ';">' + esc(who) + '</div>'
          + '<div class="kt-smx-txt">' + esc(m.body || '') + '</div><div class="kt-smx-meta">' + when + '</div></div></div>';
      }
      var notSent = ['skipped', 'failed', 'undelivered'].indexOf(m.status) !== -1;
      var stx = notSent ? (m.error_plain || (m.status === 'skipped' ? 'Not sent' : 'Failed')) : (m.status === 'delivered' ? 'Delivered' : 'Sent');
      var by = (m.sent_by || '').trim();
      return '<div class="kt-smx-m out' + (notSent ? ' notsent' : '') + hit + '" data-smx-m="' + (+m.id) + '"><div class="kt-smx-b">'
        + '<div class="kt-smx-txt">' + esc(m.body || '') + '</div>'
        + '<div class="kt-smx-meta">' + when + (by ? ' · ' + esc(by) : '') + ' · ' + (notSent ? '⚠️ ' : '') + esc(stx) + '</div></div></div>';
    }

    var ta = null, send = null, hint = null, sending = false, maxLen = 300, keepDraft = '';
    function paintFoot(t) {
      var can = t.can_reply || { ok: false, reason: '' };
      var first = (t.sender_first || '').trim();
      var prefix = first ? first + ': ' : '';
      maxLen = Math.max(20, 300 - prefix.length);
      var sig = (can.ok ? 'ok:' + (can.basis || '') : 'no:' + (can.reason || '')) + '|' + prefix;
      if (foot.getAttribute('data-sig') === sig) return;
      if (ta) keepDraft = ta.value;
      foot.setAttribute('data-sig', sig);
      if (!can.ok) {
        foot.className = 'kt-smx-note' + (phone ? ' kt-thread-compose' : '');
        foot.innerHTML = esc(can.reason || 'A reply cannot be texted to this number.');
        tip.hidden = true; tip.innerHTML = '';
        ta = send = hint = null;
        return;
      }
      foot.className = 'kt-smx-comp kt-thread-compose kt-compose-row';
      var name = ((t.reply || {}).person || '').trim().split(' ')[0] || 'them';
      foot.innerHTML = '<input class="kt-compose-input" type="text" maxlength="' + maxLen + '" placeholder="Text ' + esc(name) + '…" aria-label="Reply by text" autocomplete="off">'
        + '<button type="button" class="kt-send-btn kt-send-one" title="Send" aria-label="Send" data-kt-iconized="1" data-kt-btn-free="1" disabled>➤</button>';
      tip.hidden = false;
      tip.innerHTML = ((t.reply || {}).line ? 'Goes out as a text from the KiddieTrac line.'   // LineTexts (2026-11-04)
        : 'Goes out as a text from your agency’s number as “' + esc(prefix) + '…”.')
        + (can.basis === 'texted_us' ? ' They texted your agency, so you can answer them.' : '')
        + ' <span class="kt-smx-n">0 / ' + maxLen + '</span><span class="kt-smx-out"></span>';
      ta = foot.querySelector('.kt-compose-input'); send = foot.querySelector('.kt-send-btn'); hint = tip.querySelector('.kt-smx-out');
      var n = tip.querySelector('.kt-smx-n');
      ta.value = keepDraft || '';
      var fit = function () {
        if (n) n.textContent = ta.value.length + ' / ' + maxLen;
        send.disabled = !ta.value.trim() || sending;
      };
      ta.addEventListener('input', fit);
      fit();
      ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } });
      send.addEventListener('click', function (e) { e.stopPropagation(); doSend(); });
    }

    function doSend() {
      if (!ta || sending) return;
      var body = ta.value.trim();
      if (!body) return;
      sending = true;
      send.disabled = true;
      send.setAttribute('aria-busy', 'true');
      if (hint) hint.innerHTML = 'Sending…';
      api().post('/sms-replies/' + id + '/reply', { body: body }).then(function () {
        keepDraft = '';
        if (ta) { ta.value = ''; ta.dispatchEvent(new Event('input')); }
        if (hint) hint.innerHTML = '<b style="color:#047857;">Sent</b>';
        try { if (KT.badges && KT.badges.refresh) KT.badges.refresh(); } catch (e) {}
        load().catch(function () {});
      }, function (e) {
        var msg = (e && e.message) || 'The reply was not sent.';
        if (hint) hint.innerHTML = '<span class="kt-smx-err">' + esc(msg) + '</span>';
        toast('⚠️', 'Text not sent', msg, '#DC2626');
      }).then(function () {
        sending = false;
        if (send) send.removeAttribute('aria-busy');
        if (send && ta) send.disabled = !ta.value.trim();
        return refresh(true);
      });
    }

    var first = true;
    function refresh(force) {
      if (!alive()) return Promise.resolve();
      return api().get('/sms-replies/' + id + '/thread').then(function (t) {
        if (!alive()) return;
        stt.data = t;
        var list = t.thread || [];
        var sig = list.map(function (m) { return m.id + ':' + m.status; }).join(',');
        if (first || force) paintHead(t);
        paintFoot(t);
        if (sig !== stt.lastSig || first) {
          var nearBottom = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 80;
          stt.lastSig = sig;
          msgs.innerHTML = list.length ? list.map(bubble).join('') : '<div class="kt-smx-sys">No messages in this conversation yet.</div>';
          if (first) {
            var hitEl = msgs.querySelector('.hit');
            try { msgs.scrollTop = hitEl && opts.fromLink ? Math.max(0, hitEl.offsetTop - 60) : msgs.scrollHeight; } catch (e) {}
          } else if (nearBottom || force) {
            msgs.scrollTop = msgs.scrollHeight;
          }
        }
        first = false;
      }).catch(function (e) {
        if (!alive() || !first) return;
        msgs.innerHTML = '<div class="kt-smx-sys" style="color:#B91C1C;">Could not open this conversation. Try again in a moment.</div>';
        if (opts.onError) opts.onError(e);
      });
    }
    // A new text in, or a delivery report, shows without reopening (it is cheap: one small read).
    var timer = setInterval(function () { if (!alive()) { stop(); return; } if (!d.hidden) refresh(false); }, 8000);
    refresh(true);
    markRead(id);
    return { stop: stop, id: id, root: root, refresh: function () { if (alive()) refresh(false); } };
  }

  /* ---- OPEN: the dock on a computer, full screen on a phone ---- */
  var current = null;   // { h, wrap, docked }

  function setOn(id) {
    st.open = id;
    var host = live();
    if (host) host.querySelectorAll('[data-smt-id]').forEach(function (el) { el.classList.toggle('on', +el.getAttribute('data-smt-id') === id); });
  }
  function closeCurrent() {
    var c = current; current = null;
    if (!c) return;
    try { c.h.stop(); } catch (e) {}
    if (c.wrap && c.wrap.parentNode) c.wrap.parentNode.removeChild(c.wrap);
    if (c.vv) { try { window.visualViewport.removeEventListener('resize', c.vv); window.visualViewport.removeEventListener('scroll', c.vv); } catch (e) {} }
  }

  function open(id, o) {
    id = +id; if (!id) return null;
    o = o || {};
    closeCurrent();
    setOn(id);
    try { if (view && view.opts.address) view.opts.address(id); } catch (e) {}
    var row = (st.rows || []).find(function (x) { return +x.id === id; }) || (st.convs || []).find(function (c) { return +c.id === id; }) || {};
    var title = '📱 ' + (((row.person || '').trim()) || 'Text conversation');
    if (docked()) return openInDock(id, title, o);

    // Phone: a full-screen popup over the screen (kt-mobile-app.css: fixed, bottom bar hidden).
    var main = d.getElementById('appMain') || d.body;
    var wrap = mk('div', 'kt-smx-wrap');
    main.appendChild(wrap);
    var dismiss = function () {
      closeCurrent();
      setOn(0);
      try { if (view && view.opts.address) view.opts.address(0); } catch (e) {}
    };
    var h = mountThread(wrap, id, { phone: true, fromLink: o.fromLink, onClose: function () { if (KT.popOverlay) KT.popOverlay(wrap); else dismiss(); } });
    current = { h: h, wrap: wrap };
    // Follow the visual viewport: when the keyboard opens, the window shrinks with it and the reply box stays in view.
    var vvFit = function () {
      try {
        var vv = window.visualViewport; if (!vv || !h.root.isConnected) return;
        h.root.style.setProperty('--kt-smx-h', Math.round(vv.height) + 'px');
        h.root.style.setProperty('--kt-smx-top', Math.round(vv.offsetTop) + 'px');
      } catch (e) {}
    };
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', vvFit);
      window.visualViewport.addEventListener('scroll', vvFit);
      current.vv = vvFit;
      vvFit();
    }
    try { if (KT.pushOverlay) KT.pushOverlay(wrap, dismiss); } catch (e) {}
    return h;
  }

  var dockedH = null;
  function openInDock(id, title, o) {
    if (dockedH) { dockedH.stop(); dockedH = null; }
    try { if (KT.webChats && KT.webChats.stopDocked) KT.webChats.stopDocked(); } catch (e) {}
    var key = 'sms:' + id;
    var onClose = function () { if (dockedH) { dockedH.stop(); dockedH = null; } setOn(0); try { if (view && view.opts.address) view.opts.address(0); } catch (e) {} };
    KT.ChatDock.show(title, onClose);
    if (KT.ChatDock.rememberThread) KT.ChatDock.rememberThread(key, title);
    dockedH = mountThread(KT.ChatDock.contentEl(), id, {
      fromLink: o && o.fromLink,
      onTitle: function (name) {
        if (!dockedH || dockedH.id !== id) return;
        var t = '📱 ' + name;
        KT.ChatDock.show(t, onClose);
        if (KT.ChatDock.rememberThread) KT.ChatDock.rememberThread(key, t);
      },
      onError: function (e) {
        // Not one this account can open (another agency, or gone): forget it, as other threads do.
        var s = e && e.status;
        if (s === 403 || s === 404 || /\b(403|404)\b/.test(String(e && e.message))) { if (KT.ChatDock.forget) KT.ChatDock.forget(); }
      },
    });
    return dockedH;
  }
  function stopDocked() { if (dockedH) { dockedH.stop(); dockedH = null; } }

  /* The dock's conversation list: text conversations beside the chats (screen-chat.js conversationsForSwitcher). */
  function listForDock() {
    if (isGuardian() || st.allowed === false) return Promise.resolve([]);
    var p = st.convs ? Promise.resolve() : load();
    return p.then(function () {
      return (st.convs || []).map(function (c) {
        var by = c.last_dir === 'out' ? ((c.last_by || 'Sent') + ': ') : '';
        return { id: 'sms:' + c.id, name: ((c.person || '').trim() || c.phone || 'Text'), preview: c.last_dir === 'in' && c.last_kind !== 'message' ? kindLabel(c.last_kind) : by + (c.last || ''),
          unread_count: +c.unread || 0, at: c.last_at || '', photo: '', presence: '', sms: true };
      });
    }).catch(function () { return []; });
  }

  KT.smsTexts = {
    /* Offered to staff only; the first call asks the server, and a refusal (not staff here, no texting on the
       plan) takes the tab away again through onChange. */
    enabled: function (role) {
      if (role ? role === 'guardian' : isGuardian()) return false;
      if (st.allowed === null && !st.loading) load().catch(function () {});
      return st.allowed !== false;
    },
    count: function () { return st.convs ? st.convs.length : 0; },
    conversations: function () { return st.convs; },   // null until the first answer
    context: context,
    kindLabel: kindLabel,
    emptyText: emptyText,
    openLink: openLink,
    unread: function () { return st.rows ? st.rows.filter(function (x) { return x.unread; }).length : st.unread; },
    onChange: function (f) { listeners = f ? [f] : []; },   // the Message Centre screen that is up now
    render: render,
    load: load,
    open: open,
    openInDock: function (id) { return openInDock(+id, '📱 Text conversation', {}); },
    stopDocked: stopDocked,
    closePopup: closeCurrent,
    mountThread: mountThread,
    listForDock: listForDock,
    openId: function () { return st.open; },
    close: function () { st.open = 0; },
  };
})();
