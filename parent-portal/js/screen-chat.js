/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v12-big — Chat screen (parent + provider)
   - List of conversations with unread badges
   - Thread view with bubbles (mine = right, theirs = left)
   - Polling every 15 sec for new messages
   - Auto-mark-read when opening a thread
   - Compose box at the bottom
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  /* "MESSAGE CENTRE" ONCE (2026-11-02, Anthony's iPhone recording): back from a conversation, the list is
     rebuilt under the screen's banner, which already reads "Message Centre" - and the shell's tidy-up that
     drops a repeated title only runs when a screen first renders, so the title showed twice. The list
     leaves its own title out whenever that banner is up. */
  function mcTitle(h2) {
    try {
      var hero = document.querySelector('#appMain .kt-hero h1, #appMain .kt-hero-title');
      if (hero && /message\s*cent(re|er)/i.test(hero.textContent || '')) return '';
    } catch (e) {}
    return h2;
  }

  const POLL_INTERVAL_MS = 15000;
  /* Adaptive cadence for an open thread. A flat 10s made a live exchange feel
     laggy; a flat 2s would hammer the API for every idle open tab. These follow
     the conversation instead. */
  const THREAD_POLL_MS = 10000;        // legacy fallback, still used elsewhere
  const THREAD_POLL_ACTIVE_MS = 2000;  // someone spoke in the last 45s
  const THREAD_POLL_WARM_MS = 5000;    // spoke in the last 3 minutes
  const THREAD_POLL_IDLE_MS = 12000;   // quiet — back off
  const THREAD_ACTIVE_FOR_MS = 45000;
  const THREAD_WARM_FOR_MS = 180000;
  let pollTimer = null;
  let threadPollTimer = null;
  let openThreadId = null;
  let myRole = 'guardian';
  /* THE LIST YOU JUST SAW, SHOWN AGAIN AT ONCE (2026-10-29). Anthony, phone recording: "look at
     message centre when i navigate and how its loading, its stutters". Every visit started from
     nothing: blank, "Loading conversations...", the list fading in, then settling - four steps for
     a list that was on screen seconds ago. The last list (per person, agency and role) is kept
     here and painted immediately; the fresh one is fetched behind it and the screen is repainted
     only if something changed. Anything that changes a thread (a send, archive, delete, opening
     one, which marks it read) clears it, so the copy is never the wrong state after an action. */
  let LIST_CACHE = {};
  let listNoRefreshOnce = false;
  const LIST_CACHE_MS = 10 * 60000;
  /* Keyed on the sign-in (token), the agency being viewed, View-as and the role (2026-11-03): a copy can never be
     shown to another session, agency or view. */
  function listCacheKey() {
    var u = {}; try { u = getUser() || {}; } catch (e) {}
    var a = '', va = '', tk = '';
    try { a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || ''; } catch (e) {}
    try { va = sessionStorage.getItem('kt_view_as') || ''; } catch (e) {}
    try { tk = String(sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || '').slice(-24); } catch (e) {}
    return [u.id || '', a, va, myRole, tk].join('|');
  }
  /* OPENING A CONVERSATION NO LONGER THROWS THE LIST AWAY (2026-11-03, Anthony's iPhone recording: "Loading
     conversations..." every time he came back to the list, even straight back from a chat). api() emptied
     LIST_CACHE on every GET of one thread - and an open thread polls itself every few seconds - so the list
     painted from memory only when you arrived from another screen, never when you came back from a chat,
     which is the common case. The copy was dropped because opening a thread marks it read and the copy's
     unread badge would be wrong. Now:
       - reading a thread (and its polls) zeroes that conversation's unread count in the copy and keeps it;
       - a write INSIDE a thread (send, react, typing, nudge, rename, delete one message) keeps the copy -
         every paint from the copy is followed by a quiet re-read that repaints only if something changed,
         which brings the new preview / time in;
       - a write that changes WHICH conversations are listed (archive, delete a conversation, start, group,
         leave, members, broadcast) still empties it, because the list screen paints those itself and a
         copy from before would briefly show the row back. */
  var LIST_STRUCTURAL = /^\/chat-archive|\/(broadcast|start|group|leave|participants)(\/|\?|$)/;
  function listReadInCopy(kind, id) {
    Object.keys(LIST_CACHE).forEach(function (k) {
      var e = LIST_CACHE[k], hit = false;
      try {
        var arr = kind === 'chats'
          ? ((e.both[0] && e.both[0].conversations) || [])
          : ((e.both[1] && e.both[1].threads) || []);
        arr.forEach(function (c) {
          if (String(c.id) !== String(id)) { return; }
          if (kind === 'chats' && c.unread_count) { c.unread_count = 0; hit = true; }
          if (kind !== 'chats' && c.unread) { c.unread = 0; hit = true; }
        });
      } catch (err) {}
      if (hit) { e.sig = JSON.stringify(e.both); }   // so the re-read does not repaint for a change already shown
    });
  }
  let lastUnread = null;
  let lastThreadMsgId = 0;

  // Loud, bright two-note "ding-dong" chime (WebAudio) + vibration to grab
  // attention on a new message — much louder + fuller than the old single beep.
  function playPing() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        var ctx = playPing._ctx || (playPing._ctx = new Ctx());
        if (ctx.state === 'suspended') { ctx.resume(); }
        var master = ctx.createGain(); master.gain.value = 0.95; master.connect(ctx.destination);
        var tone = function (freq, start, dur, peak) {
          var o = ctx.createOscillator(), g = ctx.createGain();
          o.type = 'triangle'; o.frequency.value = freq;
          var t = ctx.currentTime + start;
          g.gain.setValueAtTime(0.0001, t);
          g.gain.exponentialRampToValueAtTime(peak, t + 0.015);
          g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
          o.connect(g); g.connect(master);
          o.start(t); o.stop(t + dur + 0.03);
        };
        // E6+E5 then B5+B4 — a doorbell-like chime, played twice as loud as before.
        tone(1318.5, 0.00, 0.36, 0.75); tone(659.3, 0.00, 0.36, 0.4);
        tone(987.8, 0.19, 0.52, 0.75);  tone(493.9, 0.19, 0.52, 0.4);
      }
    } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([90, 50, 130]); } catch (e) {}
  }
  // Exposed so other screens (e.g. the parent chat) can play the same loud chime.
  try { (window.KT = window.KT || {}).playPing = playPing; } catch (e) {}

  function $(sel, root = document) { return root.querySelector(sel); }
  function $$(sel, root = document) { return root.querySelectorAll(sel); }

  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); }
    catch (e) { return {}; }
  }
  /* WHO THIS PERSON IS — resolvable at any moment, not only once a screen mounts.

     `myRole` is declared 'guardian' at the top of this file and was assigned in exactly
     ONE place: mount(), which runs when the Message Centre screen renders. Everything else in
     here branches on it, including the two functions that choose an ENDPOINT:

         endpointBase() -> /parent/chats   vs /provider/chats
         threadBase()   -> /parent/threads vs /provider/team-threads

     The chat dock restores its remembered conversation at LOGIN, from a module-scope
     opener, long before any screen has mounted. At that moment myRole is still the
     declared default, so a director's colleague thread was fetched from
     /parent/threads/<id> — an endpoint that has never heard of it. Hence, on every single
     login: "Could not load this conversation: API 404". The thread was fine; we asked the
     wrong door. The dock then FORGOT it, so the restore was lost as well as broken.

     Resolved from storage on demand, so any entry point gets the right answer.
     kt_view_as first, because impersonation must win over the account's own role — it is
     the same order mount() uses. (Anthony, 2026-09-09) */
  function syncRole(explicit) {
    try {
      if (explicit) { myRole = String(explicit); return myRole; }
      var va = sessionStorage.getItem('kt_view_as');
      if (va) { myRole = String(va); return myRole; }
      var u = getUser();
      myRole = u.primary_role || (Array.isArray(u.roles) && u.roles[0]) || 'guardian';
    } catch (e) {}
    return myRole;
  }
  // Module load: the dock can open a thread before any screen renders.
  syncRole();

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  // Make a photo path absolute against the API host (relative /storage/... paths
  // don't resolve against the SPA origin). Absolute URLs pass through untouched.
  function absPhotoUrl(u) {
    if (!u) return '';
    if (/^https?:\/\//i.test(u) || u.indexOf('data:') === 0) return u;
    var origin = apiBase().replace(/\/api\/v1\/?$/, '');
    return origin + (u.charAt(0) === '/' ? '' : '/') + u;
  }

  async function api(method, path, body) {
    if (method === 'GET') {
      var rd = String(path).match(/\/(chats|threads|team-threads)\/(\d+)(\/|\?|$)/);
      if (rd) { listReadInCopy(rd[1], rd[2]); }
    } else if (LIST_STRUCTURAL.test(path)
        || (method === 'DELETE' && /^\/(parent|provider)\/(chats|threads|team-threads)\/\d+$/.test(path))) {
      LIST_CACHE = {};
    }
    const opts = {
      method: method,
      headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(apiBase() + path, opts);
    if (!res.ok) {
      /* Never show the reply body (2026-10-20, Closed Testing Report issue 5): it read
         `API 403: {"password_change_required":true,…}`. Keep the server's own sentence when
         there is one; the status and body still go to the console for support. */
      const errText = await res.text();
      let j = null;
      try { j = JSON.parse(errText); } catch (_) {}
      try { console.warn('[chat] API ' + res.status + ' ' + path, errText.substring(0, 300)); } catch (_) {}
      const err = new Error((j && j.message) || (res.status === 403
        ? 'You do not have access to this right now.'
        : 'Something went wrong. Please try again.'));
      err.status = res.status;
      err.passwordChange = !!(j && j.password_change_required);
      throw err;
    }
    return res.json();
  }

  function endpointBase() {
    return myRole === 'guardian' ? '/parent/chats' : '/provider/chats';
  }

  /* Private 1:1 threads. Same split as endpointBase(): a guardian reaches them
     through /parent/threads, which exposes only the participation-guarded
     read/send methods — never contacts() or start(). */
  function threadBase() {
    return myRole === 'guardian' ? '/parent/threads' : '/provider/team-threads';
  }

  /* REPLY TO ONE MESSAGE (kt-chat-reply.js). Null when that script is not on the page, and every caller
     then simply draws and sends what it always did. */
  function RP() { return (window.KT && KT.chatReply) || null; }

  /* YOUR OWN MESSAGES ARE A SOFT TEAL (2026-11-27, Anthony: "lighten up the darker teal colour for the chat"). The
     colleague thread drew your bubbles in the dark navy-teal #1F6080 with white text; it now matches the family
     thread's #DCF1F4 with dark ink (15:1). A white-label agency keeps its own look: its bubble stays the brand colour
     that branding-loader paints over the inline #1F6080 (html.kt-tinted is set only for a white-label brand), so
     only plain KiddieTrac gets the light bubble. Dark mode gets a deep teal with light ink (9:1). */
  function ensureBubbleCss() {
    if (document.getElementById('kt-chat-bubble-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-chat-bubble-css';
    st.textContent =
      'html:not(.kt-tinted) .kt-st-mine{background:#DCF1F4!important;color:#0D1B2A!important;border-color:#C9E7EC!important}'
      + 'html:not(.kt-tinted) .kt-st-mine .kt-rp-q.kt-rp-dark{background:rgba(15,23,42,.06)!important;border-left-color:#159FB4!important}'
      + 'html:not(.kt-tinted) .kt-st-mine .kt-rp-q.kt-rp-dark b{color:#0E7490!important}'
      + ':is(html.kt-dark,html[data-theme="dark"]):not(.kt-tinted) :is(.kt-st-mine,.kt-msg-mine){background:#134E5E!important;color:#E6FFFB!important;border-color:#134E5E!important}'
      + ':is(html.kt-dark,html[data-theme="dark"]):not(.kt-tinted) :is(.kt-st-mine,.kt-msg-mine) .kt-rp-q{background:rgba(255,255,255,.12)!important;border-left-color:#7DD3FC!important}'
      + ':is(html.kt-dark,html[data-theme="dark"]):not(.kt-tinted) :is(.kt-st-mine,.kt-msg-mine) .kt-rp-q b{color:#BAE6FD!important}';
    document.head.appendChild(st);
  }

  /* Ask the server who this reaches, then send. Two steps on purpose: the count
     comes back from the same resolver that will do the sending, so the number on the
     button is the number that gets the message — not a guess made in the browser. */
  function openBroadcast(container) {
    var m = document.createElement('div');
    m.setAttribute('data-no-modal-guard', '1');
    m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;'
      + 'display:flex;align-items:center;justify-content:center;padding:20px;';
    m.innerHTML = '<div style="background:#fff;padding:26px;border-radius:14px;max-width:540px;'
      + 'width:100%;max-height:calc(100vh - 40px);overflow-y:auto;">'
      + '<h3 style="margin:0 0 6px;">Message everyone</h3>'
      + '<p style="margin:0 0 16px;font-size:13.5px;color:#64748B;line-height:1.55;">'
      + 'Each person gets this in their own thread, so anyone who replies is replying '
      + 'privately &mdash; not to the whole group.</p>'
      + '<label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Send to</label>'
      + '<select id="bc-aud" style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;">'
      + '<option value="parents">All parents</option>'
      + '<option value="educators">All educators</option>'
      + '<option value="staff">All staff</option>'
      + '<option value="contractors">All contractors</option>'
      + '<option value="admins">All admins and directors</option>'
      + '<option value="all">Everyone</option>'
      + '</select>'
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Subject (optional)</label>'
      + '<input id="bc-subj" placeholder="e.g. Closure on Friday" '
      + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">'
      + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Message</label>'
      + '<textarea id="bc-body" rows="5" placeholder="What do they need to know?" '
      + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;'
      + 'font-family:inherit;box-sizing:border-box;"></textarea>'
      + '<div id="bc-msg" style="font-size:13px;margin-top:12px;min-height:18px;color:#64748B;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
      + '<button id="bc-cancel" class="kt-btn kt-btn-secondary" type="button">Cancel</button>'
      + '<button id="bc-send" class="kt-btn kt-btn-primary" type="button">Check who this reaches</button>'
      + '</div></div>';
    document.body.appendChild(m);

    var msg = m.querySelector('#bc-msg');
    var btn = m.querySelector('#bc-send');
    var confirmed = false;

    m.querySelector('#bc-cancel').onclick = function () { m.remove(); };

    // Changing the audience invalidates a count already shown for the previous one.
    m.querySelector('#bc-aud').addEventListener('change', function () {
      confirmed = false;
      btn.textContent = 'Check who this reaches';
      msg.style.color = '#64748B';
      msg.textContent = '';
    });

    btn.onclick = async function () {
      var body = (m.querySelector('#bc-body').value || '').trim();
      if (!body) {
        msg.style.color = '#B91C1C';
        msg.textContent = 'Write the message first.';
        return;
      }
      var payload = {
        audience: m.querySelector('#bc-aud').value,
        subject: (m.querySelector('#bc-subj').value || '').trim() || null,
        body: body,
      };
      btn.disabled = true;

      try {
        if (!confirmed) {
          var p = await api('POST', '/provider/chats/broadcast', payload);
          var bits = [];
          if (p.families) { bits.push(p.families + ' family thread' + (p.families === 1 ? '' : 's')); }
          if (p.staff) { bits.push(p.staff + ' staff'); }
          if (!bits.length) {
            msg.style.color = '#B45309';
            msg.textContent = 'That group has nobody in it right now.';
            btn.disabled = false;
            return;
          }
          confirmed = true;
          msg.style.color = '#0F172A';
          msg.textContent = 'This goes to ' + bits.join(' and ') + '. There is no undo.';
          btn.textContent = 'Send to ' + bits.join(' and ');
          btn.disabled = false;
          return;
        }

        payload.confirm = true;
        msg.style.color = '#64748B';
        msg.textContent = 'Sending…';
        var r = await api('POST', '/provider/chats/broadcast', payload);
        m.remove();
        if (window.KT && KT.Dom && KT.Dom.toast) {
          KT.Dom.toast('Sent to ' + r.label
            + (r.families ? ' — ' + r.families + ' thread' + (r.families === 1 ? '' : 's') : ''), 'success');
        }
        renderList(container);
      } catch (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not send that.';
      }
    };
  }

  /* Delivery state of the last thing the CENTRE said, as a tick rather than a word.
     After a broadcast to 41 families the question is who has actually seen it, and
     opening 41 threads to find out is not an answer. Nothing is shown against a
     message the family sent us — a tick there would imply we had told them something
     we had not. */
  /**
   * The role badge on a 1:1 thread row.
   *
   * Prints who the person actually is — Parent, Educator, Admin — instead of the flat
   * word "Colleague" that every staff thread used to carry. A parent thread labelled
   * "Colleague" is not a cosmetic slip: these rows are how you pick the right person out
   * of several accounts sharing a name, and the wrong label sends the message to the
   * wrong one.
   *
   * Parents are tinted differently from staff, because that is the distinction that
   * actually changes how you write the message.
   */
  function staffRoleChip(role) {
    var r = String(role || '').trim();
    var isParent = /parent|guardian|family/i.test(r);
    var label = r || 'Colleague';
    var tip = isParent
      ? 'A one-to-one conversation with a parent or guardian'
      : (r ? 'A one-to-one conversation with a colleague (' + r + ')'
           : 'A one-to-one conversation with a colleague — not a family thread');
    var bg = isParent ? 'rgba(31,96,128,.12)' : 'rgba(100,116,139,.12)';
    var fg = isParent ? '#1F6080' : '#475569';
    return ' <span title="' + escapeHtml(tip) + '" style="margin-left:5px;padding:1px 6px;'
      + 'border-radius:9px;background:' + bg + ';color:' + fg + ';font-size:10px;font-weight:800;'
      + 'letter-spacing:.03em;text-transform:uppercase;vertical-align:middle;">'
      + escapeHtml(label) + '</span>';
  }

  function chipHtml(icon, label, color, tip) {
    return '<span title="' + tip + '" style="display:inline-flex;align-items:center;gap:4px;'
      + 'font-size:11.5px;font-weight:700;color:' + color + ';white-space:nowrap;">'
      + '<span style="font-size:12px;letter-spacing:-1px;">' + icon + '</span>' + label + '</span>';
  }
  /* Never blank. Ticks mean what ticks mean, but only for a message the CENTRE sent —
     we cannot claim "read" on a message the family sent us. So when the family sent
     last, the cell reports the one status that is true from our side: whether we have
     opened it. Returning '' there made a working column look broken. */
  function statusChip(c) {
    var st = c && c.last_status;
    if (!st && c && c.last_from === 'family') {
      /* They sent last, so they have unquestionably read what we sent — a reply is a
         stronger receipt than any tick. "Opened" read as a claim about THEM when it
         actually described us opening their message. */
      return (c.unread_count > 0)
        ? chipHtml('●', 'New reply', '#8EC73C', 'They replied — nobody here has opened it yet')
        : chipHtml('↩', 'Replied', '#159FB4', 'They replied, so they have read your messages');
    }
    var map = {
      sent:      { icon: '✓',  label: 'Sent',      color: '#94A3B8', tip: 'Sent — not read yet' },
      delivered: { icon: '✓✓', label: 'Delivered', color: '#94A3B8', tip: 'Delivered to their device' },
      read:      { icon: '✓✓', label: 'Read',      color: '#159FB4', tip: 'Opened by the family' },
    };
    var m = map[st];
    // A staff-to-staff thread carries no delivery receipts — an em dash, not a blank.
    if (!m) { return '<span style="color:#CBD5E1;font-size:12px;" title="No delivery receipt for this thread">—</span>'; }
    /* The tick describes YOUR latest message. Whether they have replied is a separate
       fact, shown alongside it — otherwise a thread where they replied and you answered
       afterwards looked identical to one they never answered at all. */
    /* Two separate facts, shown as two separate things. The tick is about your
       LATEST message; the badge is about the relationship. "Sent + ACTIVE" means they
       do answer you but have not seen the newest thing you sent — which reads very
       differently from a bare "Sent". */
    var replied = (c && c.has_replied)
      ? '<span title="This person has replied in this thread before" style="display:inline-flex;'
        + 'align-items:center;margin-left:6px;padding:1px 6px;border-radius:9px;background:rgba(21,159,180,.12);'
        + 'color:#0F7C8C;font-size:10px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;'
        + 'white-space:nowrap;">Active</span>'
      : '';
    return '<span style="display:inline-flex;align-items:center;white-space:nowrap;">'
      + chipHtml(m.icon, m.label, m.color, m.tip) + replied + '</span>';
  }
  /* A TAP OUTSIDE CLOSES IT (2026-11-05, Anthony: "cannot get out of the emoji picker by clicking outside of the
     window"). The pickers and menus here closed on a document 'click' in the bubbling phase. Most of a conversation
     on a phone is buttons that stop their click (each bubble's ⋯, reply, the reactions), so a tap there never reached
     document and the picker stayed open; only the header closed it. A capture-phase pointerdown runs before any
     handler can stop it. keep: elements a tap inside does not close (the popover, its button). close() returning
     true = finished (a one-shot popover): the listener goes. It also goes once the popover is gone from the page, so
     re-rendered threads do not pile up listeners. */
  function closeOnOutsideTap(keep, close, isOpen) {
    function h(ev) {
      if (!keep[0] || !keep[0].isConnected) { document.removeEventListener('pointerdown', h, true); return; }
      if (isOpen && !isOpen()) return;
      for (var i = 0; i < keep.length; i++) if (keep[i] && keep[i].contains(ev.target)) return;
      if (close() === true) document.removeEventListener('pointerdown', h, true);
    }
    document.addEventListener('pointerdown', h, true);
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* AN EMOJI ON ITS OWN SHOWS BIG (2026-11-05, Anthony's phone video: a lone 😀 sat tiny in a full-size bubble).
     One to three emoji and nothing else: the text is .kt-emo-big and the bubble .kt-emo-bubble, with no fill
     (kt-chat-composer.css), like every phone's messages app. Letters and digits never count, though some are
     emoji "components". Built with new RegExp so an old WebView without \p{} just skips it. */
  var EMO_RE = null;
  try { EMO_RE = [new RegExp('^(?:\\p{Extended_Pictographic}|\\p{Emoji_Component}|\\u200d|\\ufe0f|\\s)+$', 'u'), new RegExp('\\p{Extended_Pictographic}', 'gu')]; } catch (e) {}
  function emojiOnly(t) {
    t = String(t || '').trim();
    if (!EMO_RE || !t || t.length > 40 || /[0-9A-Za-z#*]/.test(t) || !EMO_RE[0].test(t)) return false;
    var n;
    try { n = Array.from(new Intl.Segmenter().segment(t.replace(/\s+/g, ''))).length; }
    catch (e) { n = (t.match(EMO_RE[1]) || []).length; }
    return n >= 1 && n <= 3;
  }

  /* ── Times are the AGENCY's, never the device's ──────────────────────────────
     These all parsed UTC correctly and then formatted with the device's zone, so a
     director in Alberta read a Toronto centre's messages two hours out. "Today" was
     decided by the device calendar too, which could file a late-evening message under
     the wrong day.

     kt-tz.js owns the zone (from the signed-in user's agency_timezone) and loads before
     this file. The fallbacks keep the old behaviour if it is ever missing — a message
     with a slightly wrong time still beats a message with no time. */
  function _chatTz() {
    try { return (window.KT && KT.tz) ? KT.tz() : undefined; } catch (e) { return undefined; }
  }
  function _chatParse(iso) {
    try { if (window.KT && KT.parseTs) { return KT.parseTs(iso); } } catch (e) {}
    return new Date(String(iso).replace(' ', 'T') + 'Z');
  }
  function _chatIsToday(d) {
    try {
      if (window.KT && KT.agencyToday && KT.agencyDateOf) {
        return KT.agencyDateOf(d) === KT.agencyToday();
      }
    } catch (e) {}
    return new Date().toDateString() === d.toDateString();
  }
  function _chatClock(d) {
    return d.toLocaleTimeString([], { timeZone: _chatTz(), hour: 'numeric', minute: '2-digit' });
  }
  function _chatDay(d) {
    return d.toLocaleDateString([], { timeZone: _chatTz(), month: 'short', day: 'numeric' });
  }

  /* The time if it happened today, the date if it did not — the messaging-app
     convention, and what the phone conversation card shows. */
  function formatTime(iso) {
    if (!iso) return '';
    const d = _chatParse(iso);
    if (!d || isNaN(d)) return '';

    return _chatIsToday(d) ? _chatClock(d) : _chatDay(d);
  }
  // Date AND time together, for the inbox Date column.
  function formatDateTime(iso) {
    if (!iso) return '';
    const d = _chatParse(iso);
    if (!d || isNaN(d)) return '';
    const day = _chatIsToday(d) ? 'Today' : _chatDay(d);

    return day + ', ' + _chatClock(d);
  }

  /* ─── ONE inbox row, ONE inbox card (2026-11-02) ─────────────────────────────
     Anthony: "text conversations has a coloured band make this look like how the inbox lists out the chats".
     The Message Centre list row (desktop) and card (phone) are built here, at module level, so the 📱 Texts tab
     and SMS -> Replies (KT.Chat.renderTexts, called by kt-sms-replies.js) draw text conversations with the very
     same markup as a family or colleague chat - not a look-alike that drifts. */
  function chatAvatar(nm, photo, size) {
    const p = absPhotoUrl(photo);
    if (p && window.KT && KT.avatar) {
      return `<span style="flex-shrink:0;display:inline-flex;">${KT.avatar(nm || '?', { size: size, photoUrl: p })}</span>`;
    }
    return `<span style="width:${size}px;height:${size}px;border-radius:50%;background:${senderColor(nm)};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:${Math.round(size * 0.38)}px;font-weight:800;flex-shrink:0;">${escapeHtml((String(nm || '?').charAt(0) || '?').toUpperCase())}</span>`;
  }
  // "You: " / "Safia (staff): " before the last line. A text conversation's staff sender is plainly a colleague.
  function lastFromHtml(c, myId) {
    if (c.last_sender_id != null && c.last_sender_id == myId) { return '<span style="color:#64748B;">You: </span>'; }
    if (!c.last_sender_name) { return ''; }
    if (c.kind === 'sms') { return `<span style="color:#1F6080;font-weight:700;">${escapeHtml(String(c.last_sender_name).split(' ')[0])}: </span>`; }
    return `<span style="color:#1F6080;font-weight:700;" title="${escapeHtml(String(c.last_sender_name))} is staff, not a member of this family">${escapeHtml(String(c.last_sender_name).split(' ')[0])} <span style="font-weight:600;color:#94A3B8;font-size:.92em;">(staff)</span>: </span>`;
  }
  // The small chip a colleague row carries (staffRoleChip), saying TEXT, with what it answers as its tip.
  function smsChip(c) {
    const tip = c.sms_ctx || 'A conversation by text message';
    return ' <span class="kt-mc-txchip" title="' + escapeHtml(tip) + '" style="margin-left:5px;padding:1px 6px;'
      + 'border-radius:9px;background:rgba(100,116,139,.12);color:#475569;font-size:10px;font-weight:800;'
      + 'letter-spacing:.03em;text-transform:uppercase;vertical-align:middle;">Text</span>';
  }
  function convStatusHtml(c) {
    if (c.sms_notsent) { return chipHtml('⚠', 'Not sent', '#B45309', 'Your last text to this number was not sent - open it to see why'); }
    return statusChip(c);
  }
  /* Text conversations (KT.smsTexts) in the inbox's conversation shape. null = not loaded yet. */
  function textConvs(myId, raw) {
    const T = window.KT && KT.smsTexts;
    const list = raw || (T && T.conversations ? T.conversations() : null);
    if (!list) { return null; }
    return list.map(function (t) {
      const person = String(t.person || '').trim();
      const out = t.last_dir === 'out';
      const notSent = out && ['skipped', 'failed', 'undelivered'].indexOf(t.last_status) !== -1;
      const event = t.last_dir === 'in' && t.last_kind && t.last_kind !== 'message';
      return {
        kind: 'sms',
        id: 'sms:' + t.id,
        sms_id: +t.id,
        family_name: person || t.phone || 'Unknown number',
        child_name: person ? (t.phone || '') : '',
        photo_url: '',
        preview: event && T && T.kindLabel ? T.kindLabel(t.last_kind) : (t.last || ''),
        last_message_at: t.last_at || '',
        unread_count: +t.unread || 0,
        last_from: out ? null : 'family',
        last_status: out && !notSent ? (t.last_status === 'delivered' ? 'delivered' : 'sent') : null,
        sms_notsent: notSent,
        last_sender_id: out && t.last_by === 'You' ? myId : null,
        last_sender_name: out && t.last_by && t.last_by !== 'You' ? t.last_by : '',
        sms_ctx: T && T.context ? T.context(t) : '',
      };
    });
  }
  // o: { name, archived, myId }
  function convRowHtml(c, o) {
    const nm = o.name, unread = c.unread_count > 0, sms = c.kind === 'sms';
    return `<tr class="kt-msg-row" data-cid="${c.id}" style="border-top:1px solid #F1F3F5;cursor:pointer;">
          <td style="padding:10px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <span style="display:inline-flex;align-items:center;gap:9px;max-width:100%;">
              ${chatAvatar(nm, c.photo_url || c.child_photo_url, 30)}
              <span style="font-weight:${unread ? '800' : '600'};color:#111827;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(nm)}${c.child_name ? ` <span style="color:#64748B;font-weight:400;">· ${escapeHtml(c.child_name)}</span>` : ''}${c.kind === 'staff' ? staffRoleChip(c.other_role) : ''}${sms ? smsChip(c) : ''}</span>
            </span>
          </td>
          <td style="padding:10px 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#6B7280;font-weight:${unread ? '600' : '400'};">
            ${lastFromHtml(c, o.myId)}${escapeHtml(c.preview || '(no messages yet)')}
            ${unread ? `<span style="background:#1F6080;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;margin-left:6px;">${c.unread_count}</span>` : ''}
          </td>
          <td style="padding:10px 14px;white-space:nowrap;">${convStatusHtml(c)}</td>
          <td style="padding:10px 14px;text-align:right;color:#64748B;white-space:nowrap;font-weight:${unread ? '700' : '400'};">${formatDateTime(c.last_message_at)}</td>
          <td style="padding:10px 6px;text-align:center;white-space:nowrap;">
            <button class="kt-conv-act kt-conv-open" data-cid="${c.id}" type="button" title="Open">Open</button>
            ${sms ? '' : `<button class="kt-conv-act kt-conv-arch" data-cid="${c.id}" type="button" title="${o.archived ? 'Restore' : 'Archive'}">${o.archived ? 'Restore' : 'Archive'}</button>`}
            ${(c.kind === 'staff' || sms) ? '' : `<button class="kt-conv-act kt-conv-del" data-cid="${c.id}" type="button" title="Delete">Delete</button>`}
          </td>
        </tr>`;
  }
  // o: { name, myId, kebab (html, '' for none) }
  function convCardHtml(c, o) {
    const nm = o.name, unread = c.unread_count > 0;
    return `<div class="kt-msg-card" data-cid="${c.id}" style="width:100%;text-align:left;display:flex;align-items:center;gap:12px;border:1px solid #E7EBF0;background:#fff;cursor:pointer;padding:12px 13px;margin-bottom:9px;border-radius:14px;box-shadow:0 1px 3px rgba(15,23,42,.06);">
            ${chatAvatar(nm, c.photo_url || c.child_photo_url, 44)}
            <span style="flex:1;min-width:0;">
              <span style="display:flex;align-items:center;gap:8px;">
                <span style="font-weight:${unread ? '800' : '700'};font-size:14.5px;color:#0D1B2A;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(nm)}${c.child_name ? ` · <span style="color:#64748B;font-weight:600;">${escapeHtml(c.child_name)}</span>` : ''}${c.kind === 'sms' ? smsChip(c) : ''}</span>
                <span style="font-size:11px;color:#64748B;flex-shrink:0;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">${convStatusHtml(c)}${formatTime(c.last_message_at)}</span>
              </span>
              <span style="display:flex;align-items:center;gap:6px;margin-top:3px;">
                <span style="flex:1;min-width:0;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${unread ? 'color:#0D1B2A;font-weight:600;' : 'color:#64748B;'}">${lastFromHtml(c, o.myId)}${escapeHtml(c.preview || '(no messages yet)')}</span>
                ${unread ? `<span style="background:#8EC73C;color:#fff;font-size:11px;font-weight:800;min-width:20px;height:20px;padding:0 6px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${c.unread_count}</span>` : ''}
              </span>
            </span>
            ${o.kebab || ''}
          </div>`;
  }
  // The inbox's zebra + hover on a rendered tbody.
  function zebraRows(tbody, onOpen) {
    let z = 0;
    tbody.querySelectorAll('.kt-msg-row').forEach(row => {
      const base = (z++ % 2) ? '#F7F9FB' : '#FFFFFF';
      row.dataset.base = base; row.style.background = base;
      row.addEventListener('mouseenter', () => { row.style.background = '#EEF4F7'; });
      row.addEventListener('mouseleave', () => { row.style.background = row.dataset.base; });
      row.addEventListener('click', () => onOpen(row.dataset.cid));
    });
  }
  function isPhoneShaped() { return window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native'); }
  /* KT.Chat.renderTexts(host, { convs, empty, onOpen(id) }): text conversations drawn as the inbox draws chats -
     the table on a computer, the cards on a phone. kt-sms-replies.js paints SMS -> Replies with it. */
  function renderTexts(host, o) {
    o = o || {};
    const myId = (getUser() || {}).id;
    const list = (textConvs(myId, o.convs || []) || []).slice()
      .sort((a, b) => (_chatParse(b.last_message_at) || 0) - (_chatParse(a.last_message_at) || 0));
    const open = (cid) => { const id = parseInt(String(cid).replace('sms:', ''), 10); if (id && o.onOpen) { o.onOpen(id); } };
    if (!list.length) {
      host.innerHTML = `<div style="text-align:center;color:#64748B;padding:30px;font-size:13px;">${escapeHtml(o.empty || 'No text conversations yet.')}</div>`;
      return;
    }
    if (isPhoneShaped()) {
      host.innerHTML = list.map(c => convCardHtml(c, { name: c.family_name, myId: myId, kebab: '' })).join('');
      host.querySelectorAll('.kt-msg-card').forEach(row => row.addEventListener('click', () => open(row.dataset.cid)));
      return;
    }
    const th = (label, extra) => `<th style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label}</th>`;
    host.innerHTML = `<table data-kt-filtered="1" data-kt-noexport="1" style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
        <thead><tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
          ${th('From', { w: '250px' })}${th('Message')}${th('Status', { w: '190px' })}${th('Date', { w: '140px', right: true })}<th style="width:46px;padding:10px 6px;"></th>
        </tr></thead>
        <tbody>${list.map(c => convRowHtml(c, { name: c.family_name, archived: false, myId: myId })).join('')}</tbody>
      </table>`;
    const tbody = host.querySelector('tbody');
    zebraRows(tbody, open);
    tbody.querySelectorAll('.kt-conv-open').forEach(b => b.addEventListener('click', (e) => { e.stopPropagation(); open(b.getAttribute('data-cid')); }));
  }

  /* ─── List view ─────────────────────────────────────────────── */
  // A notification can deep-link straight to a thread: #chat?c=8. Without this,
  // tapping "new message from Anthony" dropped you on the conversation LIST and
  // you had to find the thread again — which is the one thing the tap was for.
  /* #chat?c=<id> for a family conversation, #chat?c=staff:<id> for a colleague thread.

     Only the digits form existed, which is why a colleague notification could not link
     to its own thread — and why TeamChatController had settled for '#team-messages', a
     screen that is HIDDEN (the colleague UI lives here, in Message Centre). Tapping a team
     message therefore landed nowhere. The id shape mirrors the conversation list, where
     a staff row is already 'staff:' + id so that conversation 7 and thread 7 stay
     distinct. (Anthony, 2026-09-09) */
  function deepLinkedConversationId() {
    var h = window.location.hash || '';
    var wb = h.match(/[?&]web=(\d+)/);
    if (wb) { return 'web:' + wb[1]; }
    var st = h.match(/[?&]c=staff:(\d+)/);
    if (st) { return 'staff:' + st[1]; }
    var m = h.match(/[?&]c=(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }

  /* The Website tab survives a repaint of the list: coming back from a website chat, and the list's own quiet
     revalidation (which re-renders when the data changed and used to drop you back on Inbox). */
  let webTabOn = false;
  /* 📱 TEXTS (2026-11-02). Anthony: "Safia should see this response to her announcement in the message
     centre". Texts people send back to the agency's number - answering an announcement sent by text, or a
     staff reply - are listed here for the person whose text they answer (and for admins / directors).
     The list and conversation are KT.smsTexts (kt-sms-replies.js), the same module SMS -> Replies uses.
     '#chat?sms=<id>' (the "Text reply from ..." alert and push) opens this tab with that text open. */
  let textsTabOn = false;
  let textsOpenId = 0;
  function textsOn() { return !!(window.KT && KT.smsTexts && KT.smsTexts.enabled(myRole)); }
  async function renderList(container) {
    /* Tell the global refreshers to leave this screen alone.
       Message Centre polls its own list and its open thread; kt-live.js used to tear the
       whole screen down 600ms after every send (a POST rings its bus) and
       kt-auto-refresh.js did it again every 45s. That is the flashing — and on desktop
       the re-mount orphaned the thread held in the floating dock, so the message you
       had just sent never rendered. Both refreshers honour this attribute. */
    try { container.setAttribute('data-kt-self-live', '1'); } catch (e) {}

    var smsLink = (window.location.hash || '').match(/[?&]sms=(\d+)/);
    if (smsLink) {
      textsTabOn = true; webTabOn = false; textsOpenId = parseInt(smsLink[1], 10) || 0;
      try { history.replaceState(null, '', location.pathname + location.search + '#chat'); } catch (e) {}
    }
    var deepId = smsLink ? null : deepLinkedConversationId();
    if (deepId) {
      // Strip the parameter so a later re-render doesn't re-open it on top.
      try { history.replaceState(null, '', location.pathname + location.search + '#chat'); } catch (e) {}
      // openThread() handles a family conversation and a website chat; a colleague thread has its own opener.
      if (typeof deepId === 'string' && deepId.indexOf('web:') === 0) { return openThread(deepId, container); }
      return (typeof deepId === 'string' && deepId.indexOf('staff:') === 0)
        ? openStaffThread(parseInt(deepId.slice(6), 10), container)
        : openThread(deepId, container);
    }

    /* The list endpoint pages at 25 and reports `meta.pages`. This screen filters and
       sorts across the WHOLE inbox client-side, so it needs the whole inbox — paging
       server-side would quietly turn the filter box into "search the current page".
       Pull page 1, then the rest in parallel. Capped at 40 pages (4,000 threads); past
       that the screen needs a server-side redesign, not a bigger loop. */
    async function fetchAllConvs() {
      const first = await api('GET', endpointBase() + '?page=1&per_page=100');
      let out = (first && first.conversations) || [];
      const meta = (first && first.meta) || null;
      if (meta && meta.pages > 1) {
        const rest = [];
        for (let p = 2; p <= Math.min(meta.pages, 40); p++) { rest.push(p); }
        // One slow page must not empty the inbox — a failed page drops out, the rest show.
        const more = await Promise.all(rest.map(p =>
          api('GET', endpointBase() + '?page=' + p + '&per_page=100').catch(() => null)));
        more.forEach(r => { if (r && r.conversations) { out = out.concat(r.conversations); } });
      }
      return { conversations: out, meta: meta };
    }

    var listKey = listCacheKey();
    var listHit = LIST_CACHE[listKey];
    if (listHit && Date.now() - listHit.at > LIST_CACHE_MS) { listHit = null; }
    var listRevalidate = !!listHit && !listNoRefreshOnce;
    listNoRefreshOnce = false;
    if (!listHit) {
      container.innerHTML = '<div class="kt-chat-loading" style="text-align:center;padding:32px;color:#6B7280;">Loading conversations…</div>';
    }
    try {
      // Families and colleagues in one list. Staff threads are a separate endpoint with
      // its own row shape, so they are normalised into the conversation shape the three
      // render paths below already understand. A failed staff fetch must not empty the
      // inbox — it degrades to families only.
      const fetchBoth = () => Promise.all([
        fetchAllConvs(),
        // Parents have private threads too now — a broadcast lands in one.
        api('GET', threadBase()).catch(function () { return null; }),
        // Archived is per person, so it travels with the list rather than being baked
        // into it. A failure here must not hide the inbox — it just shows everything.
        api('GET', '/chat-archive').catch(function () { return null; }),
      ]);
      const both = listHit ? listHit.both : await fetchBoth();
      if (!listHit) { LIST_CACHE[listKey] = { both: both, at: Date.now(), sig: JSON.stringify(both) }; }
      if (listRevalidate) {
        fetchBoth().then(function (fresh) {
          var sig = JSON.stringify(fresh), had = LIST_CACHE[listKey];
          LIST_CACHE[listKey] = { both: fresh, at: Date.now(), sig: sig };
          if (had && had.sig === sig) { return; }            // nothing changed: leave the screen alone
          // Repaint only the list that is still showing - not a thread, not mid-typing in its search.
          if (!document.contains(container) || openThreadId) { return; }
          var ae = document.activeElement;
          if (ae && container.contains(ae) && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)) { return; }
          listNoRefreshOnce = true;
          renderList(container);
        }).catch(function () {});
      }
      const archived = {
        family: ((both[2] && both[2].family) || []).map(String),
        staff: ((both[2] && both[2].staff) || []).map(String),
      };
      const isArchived = function (c) {
        return c.kind === 'staff'
          ? archived.staff.indexOf(String(c.id).replace('staff:', '')) !== -1
          : archived.family.indexOf(String(c.id)) !== -1;
      };
      const data = both[0] || {};
      const convs = (data.conversations || []).map(function (c) { c.kind = 'family'; return c; })
        .concat(((both[1] && both[1].threads) || []).map(function (t) {
          return {
            kind: 'staff',
            // Namespaced: conversation 7 and thread 7 are different things.
            id: 'staff:' + t.id,
            family_name: t.name,
            centre_name: t.name,
            photo_url: t.photo_url || '',
            preview: t.preview || '',
            last_message_at: t.at,
            unread_count: t.unread || 0,
            /* The person's REAL role. The row used to print the word "Colleague" on
               every staff thread regardless of who was in it, so a message to a PARENT
               was labelled Colleague — Anthony, 2026-08-26. The API has always returned
               this; the mapping simply dropped it on the floor.

               It also disambiguates: there are five accounts here named "Safia Ali"
               (parent, educator, agency admin, and two closed ones — see the
               multi-account model). Three of them show up in this list as identical
               rows, and the role is the only thing that tells them apart. */
            /* A group has no single counterpart, so there is no one role to print.
               "4 members" is the honest label and the one that tells a room full of
               people apart from a colleague at a glance. */
            other_role: t.is_group ? ((t.member_count || 0) + ((t.member_count || 0) === 1 ? ' member' : ' members')) : (t.role || t.other_role || ''),
            is_group: !!t.is_group,
            // Carried through so a colleague row ticks like a family row.
            last_status: t.last_status || null,
            last_from: t.last_from || null,
            has_replied: !!t.has_replied,
          };
        }));
      const isProvider = myRole !== 'guardian';
      const newChatBtnHtml = isProvider
        ? `<button id="kt-new-chat-btn" class="kt-btn kt-btn-primary kt-btn-sm">+ New chat</button>`
        : '';
      /* Reaching every family at once is an admin/director action. An educator
         messages the families they work with, one thread at a time. */
      const canBroadcast = (myRole === 'agency_admin' || myRole === 'centre_director'
        || myRole === 'platform_admin');
      const broadcastBtnHtml = canBroadcast
        ? `<button id="kt-broadcast-btn" class="kt-btn kt-btn-secondary kt-btn-sm"
             title="Send one message to a whole group"><span class="kt-l">📣 Message everyone</span><span class="kt-s">📣 Everyone</span></button>`
        : '';
      // v22p16: notifications-enable button for any role that hasn't subscribed yet.
      ensureChatActsCss();
      const notifBtnHtml = `<button id="kt-notif-btn" class="kt-notif-state" style="background:white;color:#1F6080;border:1px solid #1F6080;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:none;" title="Get an OS notification when a new message arrives, even when this tab is closed.">🔔 Enable notifications</button>`;
      if (convs.length === 0) {
        container.innerHTML = `
          <div class="kt-chat-header" style="padding:16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;">
            ${mcTitle('<h2 style="font-size:20px;margin:0;">💬 Message Centre</h2>')}
            <div style="display:flex;gap:8px;align-items:center;">${notifBtnHtml}${broadcastBtnHtml}${newChatBtnHtml}</div>
          </div>
          <div style="text-align:center;padding:48px 16px;color:#6B7280;">
            <div style="font-size:48px;margin-bottom:12px;">💬</div>
            <p>No conversations yet.</p>
            ${isProvider ? '<p style="font-size:14px;margin-top:8px;">Click <strong>+ New chat</strong> above to message a family or a colleague.</p>' : '<p style="font-size:14px;margin-top:8px;">Your centre will reach out about your child\'s day.</p>'}
          </div>
          <div id="kt-msg-texts" hidden style="padding:0 14px 14px;"></div>`;
        // No conversations yet, but texts may have come back: they are still shown (KT.smsTexts).
        if (textsOn()) {
          const tx = $('#kt-msg-texts', container);
          const showTx = () => { if (!container.isConnected || !tx) { return; } if (KT.smsTexts.count() || textsTabOn) { tx.hidden = false; if (!tx.firstChild) { KT.smsTexts.render(tx, { place: 'chat', openId: textsOpenId }); textsOpenId = 0; } } };
          KT.smsTexts.onChange(showTx);
          showTx();
        }
        var b = $('#kt-new-chat-btn', container);
        // Bound alongside the new-chat button because this header is rebuilt in
        // several branches; binding once would work in one state only.
        var _bcBtn = $('#kt-broadcast-btn', container);
        if (_bcBtn) { _bcBtn.onclick = function () { openBroadcast(container); }; }
        if (b) b.addEventListener('click', function () { openNewChatModal(container); });
        wireNotifBtn(container);
        return;
      }
      const myId = getUser().id;
      const nameOf = (c) => (myRole === 'guardian' ? c.centre_name : c.family_name) || 'Conversation';
      // showArchived flips the list between the live inbox and the archived pile.
      const state = { sort: 'date', dir: -1, q: '', showArchived: false, web: webTabOn, texts: textsTabOn, page: 1, perPage: 25, total: 0 };

      /* Pagination, client-side over the fully-fetched list. Anything that changes WHICH
         rows qualify (search, sort, archive) resets to page 1 — otherwise you filter to
         three results while parked on page 2 and the screen looks empty. */
      const pageSlice = (list) => {
        state.total = list.length;
        const pages = Math.max(1, Math.ceil(list.length / state.perPage));
        if (state.page > pages) { state.page = pages; }
        if (state.page < 1) { state.page = 1; }
        const start = (state.page - 1) * state.perPage;
        return list.slice(start, start + state.perPage);
      };
      const pagerHtml = () => {
        const total = state.total, per = state.perPage;
        const pages = Math.max(1, Math.ceil(total / per));
        const start = total ? ((state.page - 1) * per + 1) : 0;
        const end = Math.min(total, state.page * per);
        /* The SAME button as every other pager in the portal (kt-table-filter's pageBtn,
           and kt-card-pager). Message Centre had its own metrics and, uniquely, a "25 / page"
           dropdown — so pagination looked like two different products depending on which
           screen you were on (Anthony, 2026-08-30: "some pages it has it numbered in
           pages and some has a drop down"). Numbered pages is the portal's answer
           everywhere else, so this is the one that changes. */
        // The bar itself is KT.pagerBar (kt-card-pager.js), filled in by wirePager —
        // the same windowed numbers + Go to as every other pager in the portal.
        const controls = pages > 1 ? '<span class="kt-pg-host"></span>' : '';
        /* Nothing to page through: say so, and stop. Offering "25 / page" for a table
           with no rows is a control that cannot do anything — Anthony, 2026-08-30:
           "why is there a button there since there is not data shown in that table". */
        if (!total) {
          return `<div style="padding:9px 14px;border-top:1px solid #E9EDF1;background:#FBFCFD;
              font-size:12.5px;color:#64748B;">No conversations</div>`;
        }
        return `<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;
            flex-wrap:wrap;padding:9px 14px;border-top:1px solid #E9EDF1;background:#FBFCFD;">
            <span style="font-size:12.5px;color:#64748B;">${'Showing <strong style="color:#334155;">' + start + '–' + end + '</strong> of <strong style="color:#334155;">' + total + '</strong>'}</span>
            <span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">${controls}</span>
          </div>`;
      };
      const wirePager = (root, repaintFn) => {
        const host = root.querySelector('.kt-pg-host');
        if (!host || !(window.KT && KT.pagerBar)) { return; }
        host.addEventListener('click', (e) => e.stopPropagation());
        KT.pagerBar(host, state.page, Math.max(1, Math.ceil(state.total / state.perPage)), (p) => {
          state.page = p;
          repaintFn();
        });
        host.style.margin = '0';
      };
      /* 📱 Texts lists text conversations IN this list, drawn by the same row and card as every chat. */
      const visible = () => (state.texts ? (textConvs(myId) || []) : convs.filter(c => isArchived(c) === state.showArchived));
      const pool = () => (state.texts ? (textConvs(myId) || []) : convs);
      const emptyText = (archivedText) => {
        if (state.texts) {
          if (textConvs(myId) === null) { return 'Loading texts…'; }
          if (state.q.trim()) { return 'No matching conversations.'; }
          return (window.KT && KT.smsTexts && KT.smsTexts.emptyText) ? KT.smsTexts.emptyText() : 'No text conversations yet.';
        }
        return state.showArchived ? archivedText : 'No matching conversations.';
      };
      const archivedCount = () => convs.filter(isArchived).length;

      /* INBOX | ARCHIVED SUBTABS (2026-10-16). Anthony: "in message centre where is the kebab
         at the bottom right in the middle for archived chats ... have a button where all the
         buttons are or a subtab for archive chats". The switch used to be a button in a
         full-width table row at the foot of the list ("Back to Active" in one at the top).
         kt-row-actions collapses the buttons in a row's last cell into a ⋮, so each drew as a
         lone kebab. It is a subtab strip above the list now, on the phone too. */
      const tabsHtml = () => {
        const n = archivedCount();
        const tab = (arch, label, count) => {
          const on = !state.web && !state.texts && state.showArchived === arch;
          return `<button type="button" class="kt-msg-tab" data-arch="${arch ? 1 : 0}" data-kt-iconized="1" data-kt-inpage="1"${on ? ' aria-current="true"' : ''}`
            + ` style="background:none;border:0;border-bottom:2px solid ${on ? '#1F6080' : 'transparent'};color:${on ? '#1F6080' : '#64748B'};`
            + `font-weight:${on ? '800' : '600'};font-size:13.5px;padding:8px 12px;cursor:pointer;border-radius:8px 8px 0 0;display:inline-flex;align-items:center;gap:6px;">`
            + `${label}<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:1px 7px;background:${on ? '#E0EEF4' : '#F1F5F9'};color:${on ? '#1F6080' : '#64748B'};">${count}</span></button>`;
        };
        const W = window.KT && KT.webChats;
        const webOn = !!(W && W.enabled());
        if (!webOn) state.web = webTabOn = false;
        const T = window.KT && KT.smsTexts;
        const txOn = textsOn();
        // Not offered (yet): this paint shows the inbox, but a Texts tab asked for by a link waits for it.
        if (!txOn) state.texts = false;
        // Inbox / Archived are "on" only while the Website and Texts tabs are not.
        let out = tab(false, '💬 Inbox', convs.length - n) + tab(true, '📥 Archived', n);
        if (txOn) {
          const ton = state.texts, tu = T.unread(), tc = T.count();
          out += `<button type="button" class="kt-msg-tab" data-texts="1" data-kt-iconized="1" data-kt-inpage="1"${ton ? ' aria-current="true"' : ''}`
          + ` style="background:none;border:0;border-bottom:2px solid ${ton ? '#1F6080' : 'transparent'};color:${ton ? '#1F6080' : '#64748B'};`
          + `font-weight:${ton ? '800' : '600'};font-size:13.5px;padding:8px 12px;cursor:pointer;border-radius:8px 8px 0 0;display:inline-flex;align-items:center;gap:6px;">`
          + `📱 Texts<span${tu ? ` aria-label="${tu} unread"` : ''} style="font-size:11.5px;font-weight:700;border-radius:999px;padding:1px 7px;background:${tu ? '#DC2626' : (ton ? '#E0EEF4' : '#F1F5F9')};color:${tu ? '#FFFFFF' : (ton ? '#1F6080' : '#64748B')};">${tu || tc}</span></button>`;
        }
        if (!webOn) return out;
        const won = state.web, wc = W.count(), wu = W.unread();
        return out
          + `<button type="button" class="kt-msg-tab" data-web="1" data-kt-iconized="1" data-kt-inpage="1"${won ? ' aria-current="true"' : ''}`
          + ` style="background:none;border:0;border-bottom:2px solid ${won ? '#1F6080' : 'transparent'};color:${won ? '#1F6080' : '#64748B'};`
          + `font-weight:${won ? '800' : '600'};font-size:13.5px;padding:8px 12px;cursor:pointer;border-radius:8px 8px 0 0;display:inline-flex;align-items:center;gap:6px;">`
          + `🌐 Website<span style="font-size:11.5px;font-weight:700;border-radius:999px;padding:1px 7px;background:${wu ? '#FEF3C7' : (won ? '#E0EEF4' : '#F1F5F9')};color:${wu ? '#B45309' : (won ? '#1F6080' : '#64748B')};">${wc}</span></button>`;
      };
      /* The Website tab swaps the list for the website chats (kt-webchat-strip.js draws them). */
      const syncWeb = (repaintFn) => {
        const W = window.KT && KT.webChats;
        const web = $('#kt-msg-web', container), list = $('#kt-msg-cards', container) || $('#kt-msg-listwrap', container);
        if (!web || !list) { return; }
        const off = state.web;
        web.hidden = !state.web; list.style.display = off ? 'none' : '';
        if (window.KT && KT.smsTexts) {
          KT.smsTexts.onChange(() => {
            if (!container.isConnected) { return; }
            // A link asked for the Texts tab before it was on offer (the list answers a moment later): show it now.
            if (textsTabOn && !state.texts && !state.web && textsOn()) { state.texts = true; state.page = 1; repaintFn(); return; }
            // On the Texts tab the list IS the text conversations: a new text, a read or a reply repaints it.
            if (state.texts) { repaintFn(); return; }
            paintTabsOnly(repaintFn);
          });
          if (state.texts && textsOn()) {
            if (KT.smsTexts.conversations() === null) { KT.smsTexts.load().catch(() => {}); }
            if (textsOpenId) { const id = textsOpenId; textsOpenId = 0; KT.smsTexts.openLink(id); }
          }
        }
        if (W && W.enabled()) {
          // The search box filters the website chats too (it used to be hidden on this tab).
          W.onChange(() => { if (!container.isConnected) { return; } paintTabsOnly(repaintFn); if (state.web) { W.render(web, state.q); } });
          if (state.web) { W.render(web, state.q); }
        }
      };
      /* THE TABS ARE UPDATED, NOT REBUILT (2026-11-12, Anthony's phone video: "look at the subtab behaviour").
         Every tap - and every quiet refresh of the website chats and texts - threw the four tab buttons away and
         drew new ones. On a phone a band whose buttons are replaced loses its sideways scroll (back to Inbox), the
         new buttons were faded in from blank as "new content" (kt-smooth-tabs), and then the band slid back to the
         tab you tapped: a blank flash and a jump on every tap. The same buttons now stay; only what changed on them
         (the highlight, the counts) is written. The band's own classes (kt-tab-band.js) are left alone. */
      const tabKey = (b) => [b.getAttribute('data-arch'), b.getAttribute('data-texts'), b.getAttribute('data-web')].join('|');
      const morph = (o, n) => {
        Array.prototype.forEach.call(n.attributes, (a) => { if (a.name !== 'class' && o.getAttribute(a.name) !== a.value) { o.setAttribute(a.name, a.value); } });
        // Only what this screen sets comes off: the highlight, and a count's "n unread" (a button's own aria-label
        // may have been added by the icon-button pass).
        ['aria-current', 'aria-label'].forEach((k) => { if (o.hasAttribute(k) && !n.hasAttribute(k) && (k === 'aria-current' || o.tagName === 'SPAN')) { o.removeAttribute(k); } });
        const oc = o.childNodes, nc = n.childNodes;
        const same = oc.length === nc.length && Array.prototype.every.call(nc, (k, i) => k.nodeType === oc[i].nodeType && k.nodeName === oc[i].nodeName);
        if (!same) { o.innerHTML = n.innerHTML; return; }
        Array.prototype.forEach.call(nc, (k, i) => {
          if (k.nodeType === 3) { if (oc[i].nodeValue !== k.nodeValue) { oc[i].nodeValue = k.nodeValue; } }
          else if (k.nodeType === 1) { morph(oc[i], k); }
        });
      };
      const paintTabsOnly = (repaintFn) => {
        const host = $('#kt-msg-tabs', container);
        if (!host) { return; }
        host.__ktRepaint = repaintFn;   // the tab handlers repaint whichever list (phone cards / desktop table) is up
        const next = document.createElement('div');
        next.innerHTML = tabsHtml();
        const want = Array.prototype.slice.call(next.children);
        const have = Array.prototype.slice.call(host.children);
        if (have.length && have.length === want.length && have.every((b, i) => b.classList.contains('kt-msg-tab') && tabKey(b) === tabKey(want[i]))) {
          have.forEach((b, i) => morph(b, want[i]));
          return;
        }
        host.innerHTML = '';
        want.forEach((b) => { host.appendChild(b); wireTab(b, host); });
      };
      const wireTab = (b, host) => {
        b.addEventListener('click', (e) => {
          e.stopPropagation();
          const repaintFn = host.__ktRepaint;
          if (b.getAttribute('data-texts') === '1') {
            if (state.texts) { return; }
            state.texts = textsTabOn = true; state.web = webTabOn = false; state.page = 1; repaintFn(); return;
          }
          if (b.getAttribute('data-web') === '1') {
            if (state.web) { return; }
            state.web = webTabOn = true; state.texts = textsTabOn = false; paintTabsOnly(repaintFn); syncWeb(repaintFn); return;
          }
          const want = b.getAttribute('data-arch') === '1';
          if (!state.web && !state.texts && state.showArchived === want) { return; }
          state.web = webTabOn = false; state.texts = textsTabOn = false; state.showArchived = want; state.page = 1; repaintFn();
        });
      };
      const paintTabs = (repaintFn) => { paintTabsOnly(repaintFn); syncWeb(repaintFn); };

      // A real photo when there is one, the coloured initial when there is not. The
      // thread view below has always done this; the list never did.
      const avatarFor = (nm, photo, size) => chatAvatar(nm, photo, size);

      const kebabBtn = (c) => `<button class="kt-conv-kebab" data-cid="${c.id}" type="button" title="More" aria-label="More actions" style="background:none;border:none;cursor:pointer;color:#64748B;font-size:17px;line-height:1;padding:5px 7px;border-radius:6px;">⋮</button>`;

      /* MOBILE ONLY. The desktop table gets its kebab from kt-row-actions.js, which
         collapses the last cell's buttons automatically — building one there produces
         a kebab inside a kebab. See CONVENTIONS.md.

         KEPT DELIBERATELY, 2026-09-03. The old note here said a card list "is not a
         table, so nothing collapses it". That is no longer true: [data-kt-list] gets
         a card list the same shared kebab, and screen-reports.js was converted to it.
         This one was left alone on purpose, and the reasons are worth stating:
           - the whole card is a click target that opens the conversation, so every
             action button needs to stop propagation or a mis-tap opens a thread;
           - the menu contents depend on the conversation (staff vs family, archived
             or not), so the row must emit different buttons per state;
           - it is the most-used screen in the portal and has a history of subtle
             regressions (chat dock identity, archived threads swallowing replies).
         The gain would be styling consistency on a phone card list. Not worth it as
         part of a cosmetic sweep — but if this file is being reworked anyway, convert
         it then. */
      function openKebab(anchor, c) {
        document.querySelectorAll('.kt-conv-menu').forEach(m => m.remove());
        const staff = c.kind === 'staff';
        const arch = isArchived(c);
        const menu = document.createElement('div');
        menu.className = 'kt-conv-menu';
        menu.style.cssText = 'position:absolute;z-index:12000;background:#fff;border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 8px 26px rgba(15,23,42,.16);padding:5px;min-width:172px;';
        const item = (label, danger) => `<button class="kt-cm-item" data-act="${label.toLowerCase()}" style="display:block;width:100%;text-align:left;background:none;border:none;cursor:pointer;padding:9px 12px;border-radius:7px;font-size:13.5px;color:${danger ? '#C0453B' : '#0D1B2A'};">${label}</button>`;
        menu.innerHTML = item('Open')
          + item(arch ? 'Restore' : 'Archive')
          // No delete for staff threads: /provider/team-threads has no delete endpoint.
          + (staff ? '' : item('Delete', true));
        document.body.appendChild(menu);
        const r = anchor.getBoundingClientRect();
        menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
        menu.style.left = (window.scrollX + Math.min(r.left, window.innerWidth - menu.offsetWidth - 12)) + 'px';

        menu.querySelectorAll('.kt-cm-item').forEach(b => {
          b.addEventListener('mouseenter', () => { b.style.background = '#F1F5F9'; });
          b.addEventListener('mouseleave', () => { b.style.background = 'none'; });
          b.addEventListener('click', async (e) => {
            e.stopPropagation();
            const act = b.getAttribute('data-act');
            menu.remove();
            if (act === 'open') { return openThread(c.id, container); }
            if (act === 'delete') { return deleteConv(c); }
            return setArchived(c, act === 'archive');
          });
        });
        closeOnOutsideTap([menu], () => { menu.remove(); return true; });
      }

      async function setArchived(c, want) {
        const bucket = c.kind === 'staff' ? archived.staff : archived.family;
        const raw = String(c.id).replace('staff:', '');
        try {
          await api('POST', '/chat-archive', { kind: c.kind === 'staff' ? 'staff' : 'family', id: parseInt(raw, 10), archived: want });
          if (want) { bucket.push(raw); } else { bucket.splice(bucket.indexOf(raw), 1); }
          repaint();
          if (window.KT && KT.toast) KT.toast(want ? '📥' : '↩️', want ? 'Archived' : 'Restored');
        } catch (err) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not update', (err && err.message) || '', '#DC2626');
        }
      }

      async function deleteConv(c) {
        if (!await KT.confirm('Delete this entire conversation? It is removed for everyone and can\'t be undone from the app.')) return;
        try {
          await api('DELETE', endpointBase() + '/' + c.id);
          const idx = convs.findIndex(x => String(x.id) === String(c.id));
          if (idx >= 0) convs.splice(idx, 1);
          repaint();
          if (window.KT && KT.toast) KT.toast('🗑', 'Conversation deleted');
        } catch (err) {
          if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', (err && err.message) || '', '#DC2626');
        }
      }

      // Set by whichever render path runs below, so the menu can refresh the list
      // without caring which one is on screen.
      let repaint = function () {};

      // ── Mobile / APK: a card list matching the parent chat inbox ──────────
      // The desktop <table> below stacks its From/Message/Date columns into an
      // ugly label:value block on a phone; the parent inbox uses clean cards, so
      // the two chats looked like different products. Render cards ≤600px wide.
      /* PHONE-SHAPED, not "under 600 CSS pixels".

         The APK WebView reports an inflated width — 601-768px on the devices we see —
         so a bare `<= 600` is false in the app and the DESKTOP TABLE rendered on a
         phone. Measured at 700px: the MESSAGE column gets 153px with nowrap +
         ellipsis, so the newest message is cut to a few characters and an ellipsis.
         That is the "Message Centre doesn't show the last message received" report: the
         message was there, clipped to nothing, in a five-column table on a phone.

         kt-native is set by dashboard.html for native AND phone-sized, which is the
         test screen-announcements / screen-incidents / v22p51 / v22p58 already use.
         (Anthony, 2026-09-09) */
      if (window.innerWidth <= 700 || document.documentElement.classList.contains('kt-native')) {
        const sortValM = (c) => new Date(String(c.last_message_at || '').replace(' ', 'T')).getTime() || 0;
        container.innerHTML = `
          <div style="padding:12px 0 4px;">
            ${mcTitle('<h2 style="font-size:19px;font-weight:800;color:#0D1B2A;margin:0 0 8px;">💬 Message Centre</h2>')}
            <div class="kt-chat-acts${(broadcastBtnHtml ? 1 : 0) + (newChatBtnHtml ? 1 : 0) === 1 ? ' one' : ''}">${broadcastBtnHtml}${newChatBtnHtml}${notifBtnHtml}</div>
            <input id="kt-msg-filter" type="search" placeholder="🔍 Search messages…" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #D1D5DB;border-radius:12px;font-size:14px;margin-bottom:10px;">
            <div id="kt-msg-tabs" class="kt-subtabs" style="display:flex;gap:4px;border-bottom:1px solid #E5E7EB;margin:0 0 10px;overflow-x:auto;"></div>
            <div id="kt-msg-web" hidden></div>
            <div id="kt-msg-texts" hidden></div>
            <div id="kt-msg-cards"></div>
          </div>`;
        const cardsWrap = $('#kt-msg-cards', container);
        const cardHtml = (c) => convCardHtml(c, { name: nameOf(c), myId: myId, kebab: c.kind === 'sms' ? '' : kebabBtn(c) });
        const paintM = () => {
          const q = state.q.trim().toLowerCase();
          let listx = visible();
          if (q) listx = listx.filter(c => (nameOf(c) + ' ' + (c.child_name || '') + ' ' + (c.preview || '')).toLowerCase().indexOf(q) !== -1);
          listx.sort((a, b) => sortValM(b) - sortValM(a));
          listx = pageSlice(listx);
          paintTabs(paintM);
          cardsWrap.innerHTML = (listx.length ? listx.map(cardHtml).join('')
            : `<div style="text-align:center;color:#64748B;padding:30px;font-size:13px;">${escapeHtml(emptyText('Nothing archived. Archive a conversation from its ⋮ to tidy your inbox.'))}</div>`)
            + pagerHtml();
          wirePager(cardsWrap, paintM);
          cardsWrap.querySelectorAll('.kt-msg-card').forEach(row => row.addEventListener('click', () => openThread(row.dataset.cid, container)));
          cardsWrap.querySelectorAll('.kt-conv-kebab').forEach(b => b.addEventListener('click', (e) => {
            e.stopPropagation();
            const c = convs.find(x => String(x.id) === String(b.getAttribute('data-cid')));
            if (c) openKebab(b, c);
          }));
        };
        repaint = paintM;
        const fiM = $('#kt-msg-filter', container);
        if (fiM) fiM.addEventListener('input', () => { state.q = fiM.value || ''; state.page = 1; paintM(); });
        paintM();
        const nbM = $('#kt-new-chat-btn', container);
        // Bound alongside the new-chat button because this header is rebuilt in
        // several branches; binding once would work in one state only.
        var _bcBtn = $('#kt-broadcast-btn', container);
        if (_bcBtn) { _bcBtn.onclick = function () { openBroadcast(container); }; }
        if (nbM) nbM.addEventListener('click', () => openNewChatModal(container));
        wireNotifBtn(container);
        return;
      }

      const th = (key, label, extra) => `<th data-sort="${key}" class="kt-msg-th" style="text-align:${extra && extra.right ? 'right' : 'left'};padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;cursor:pointer;user-select:none;${extra && extra.w ? 'width:' + extra.w + ';' : ''}">${label} <span class="kt-ar" style="color:#64748B;"></span></th>`;
      container.innerHTML = `
        <div style="display:flex;flex-direction:column;">
          <div class="kt-chat-header" style="padding:14px 16px;border-bottom:1px solid #E5E7EB;background:white;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            ${mcTitle('<h2 style="font-size:20px;margin:0;">💬 Message Centre</h2>')}
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input id="kt-msg-filter" type="search" placeholder="🔍 Filter…" style="padding:8px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;min-width:180px;">
              ${notifBtnHtml}${broadcastBtnHtml}${newChatBtnHtml}
            </div>
          </div>
          <div id="kt-msg-tabs" class="kt-subtabs" style="display:flex;gap:4px;padding:4px 14px 0;border-bottom:1px solid #E5E7EB;background:#fff;"></div>
          <div id="kt-msg-web" hidden style="padding:12px 14px;background:#F8FAFC;"></div>
          <div id="kt-msg-texts" hidden style="padding:12px 14px;background:#F8FAFC;"></div>
          <div id="kt-msg-listwrap" style="background:#fff;">
            <table data-kt-filtered="1" data-kt-noexport="1" style="width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;">
              <thead>
                <tr style="position:sticky;top:0;z-index:1;background:#F9FAFB;box-shadow:inset 0 -1px 0 #E5E7EB;">
                  ${th('name', 'From', { w: '250px' })}
                  ${th('preview', 'Message')}
                  <th style="text-align:left;padding:10px 14px;font-size:12px;color:#6B7280;font-weight:600;width:190px;">Status</th>
                  ${th('date', 'Date', { w: '140px', right: true })}
                  <th style="width:46px;padding:10px 6px;"></th>
                </tr>
              </thead>
              <tbody id="kt-msg-tbody"></tbody>
            </table>
            <div id="kt-msg-pager"></div>
          </div>
        </div>
      `;
      const tbody = $('#kt-msg-tbody', container);
      const sortVal = (c) => {
        if (state.sort === 'name') return nameOf(c).toLowerCase();
        if (state.sort === 'preview') return (c.preview || '').toLowerCase();
        return new Date(String(c.last_message_at || '').replace(' ', 'T')).getTime() || 0;
      };
      const rowHtml = (c) => convRowHtml(c, { name: nameOf(c), archived: isArchived(c), myId: myId });
      const paint = () => {
        const q = state.q.trim().toLowerCase();
        let list = visible();
        if (q) list = list.filter(c => (nameOf(c) + ' ' + (c.child_name || '') + ' ' + (c.preview || '')).toLowerCase().indexOf(q) !== -1);
        list.sort((a, b) => { const va = sortVal(a), vb = sortVal(b); return (va < vb ? -1 : va > vb ? 1 : 0) * state.dir; });
        list = pageSlice(list);
        paintTabs(paint);
        tbody.innerHTML = list.length ? list.map(rowHtml).join('')
          : `<tr><td colspan="5" style="padding:26px;text-align:center;color:#64748B;">${escapeHtml(emptyText('Nothing archived. Archive a conversation from its row menu to tidy your inbox.'))}</td></tr>`;
        zebraRows(tbody, (cid) => openThread(cid, container));
        // Wired straight onto the buttons. kt-row-actions.js forwards a real click
        // from its menu item to the hidden button, so these fire either way.
        const convOf = (b) => pool().find(x => String(x.id) === String(b.getAttribute('data-cid')));
        tbody.querySelectorAll('.kt-conv-open').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) openThread(c.id, container);
        }));
        tbody.querySelectorAll('.kt-conv-arch').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) setArchived(c, !isArchived(c));
        }));
        tbody.querySelectorAll('.kt-conv-del').forEach(b => b.addEventListener('click', (e) => {
          e.stopPropagation(); const c = convOf(b); if (c) deleteConv(c);
        }));
        // Drawn after the slice, because the slice is what sets state.total.
        const pgBox = $('#kt-msg-pager', container);
        if (pgBox) { pgBox.innerHTML = pagerHtml(); wirePager(pgBox, paint); }
        container.querySelectorAll('.kt-msg-th').forEach(h => { const ar = h.querySelector('.kt-ar'); if (ar) ar.textContent = (h.getAttribute('data-sort') === state.sort) ? (state.dir < 0 ? '▾' : '▴') : ''; });
      };
      container.querySelectorAll('.kt-msg-th').forEach(h => h.addEventListener('click', () => {
        const k = h.getAttribute('data-sort');
        if (state.sort === k) state.dir = -state.dir; else { state.sort = k; state.dir = (k === 'date') ? -1 : 1; }
        state.page = 1;
        paint();
      }));
      const fi = $('#kt-msg-filter', container);
      if (fi) fi.addEventListener('input', () => { state.q = fi.value || ''; state.page = 1; paint(); });
      repaint = paint;
      paint();
      var newBtn = $('#kt-new-chat-btn', container);
      // Bound alongside the new-chat button because this header is rebuilt in
      // several branches; binding once would work in one state only.
      var _bcBtn = $('#kt-broadcast-btn', container);
      if (_bcBtn) { _bcBtn.onclick = function () { openBroadcast(container); }; }
      if (newBtn) newBtn.addEventListener('click', function () { openNewChatModal(container); });
      wireNotifBtn(container);
    } catch (e) {
      container.innerHTML = '<div style="padding:22px 18px;text-align:center;">'
        + '<div style="font-size:30px;">' + (e && e.passwordChange ? '\u{1F511}' : '\u{1F4AC}') + '</div>'
        + '<div style="font-weight:800;color:#0F172A;margin-top:6px;">'
        + (e && e.passwordChange ? 'Choose a new password' : 'Could not load your conversations') + '</div>'
        + '<div style="font-size:13.5px;color:#475569;margin:6px auto 14px;max-width:340px;line-height:1.45;">' + escapeHtml(e && e.message || '') + '</div>'
        + (e && e.passwordChange
          ? '<a href="#settings?tab=security" style="display:inline-block;background:#1F6080;color:#fff;font-weight:700;padding:10px 18px;border-radius:10px;text-decoration:none;">Change password</a>'
          : '<button type="button" id="kt-chat-retry" style="background:#1F6080;color:#fff;font-weight:700;padding:10px 18px;border:0;border-radius:10px;cursor:pointer;">Try again</button>')
        + '</div>';
      var _rt = container.querySelector('#kt-chat-retry');
      if (_rt) _rt.addEventListener('click', function () { renderList(container); });
    }
  }

  // v22p16/17.1: notification status indicator — ALWAYS visible, showing the
  // current state so the user is never in the dark. Three states:
  //   unsubscribed → 🔔 "Enable notifications" (clickable)
  //   subscribed   → ✓ "Notifications on" (clickable to disable)
  //   denied       → 🚫 "Notifications blocked" (read-only with hint)
  //   unsupported  → hidden entirely (browser too old)
  function chatToast(text) {
    try { if (KT.Dom && KT.Dom.toast) { KT.Dom.toast(text, 'error'); return; } } catch (e) {}
    try { if (KT.toast) KT.toast('⚠️', text, '', '#B91C1C'); } catch (e) {}
  }

  /* The phone row of Message Centre actions (2026-10-22). Anthony's screenshot: with the
     title taken into the page banner, the buttons sat right-aligned and wrapped one at a
     time — "Notifications blocked" and "Message everyone" on one line, "New chat" alone
     under them. Now: the action buttons share one row equally, and the notification
     status is a full-width line beneath them. */
  function ensureChatActsCss() {
    if (document.getElementById('kt-chat-acts-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-chat-acts-css';
    st.textContent =
      '.kt-chat-acts{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);gap:8px;margin:0 0 10px;width:100%}'
      + '.kt-chat-acts>button{width:100%!important;min-width:0!important;height:34px!important;min-height:34px!important;max-height:34px!important;margin:0!important;'
      + 'padding:0 10px!important;display:inline-flex!important;align-items:center;justify-content:center;gap:6px;'
      + 'font-size:13.5px!important;font-weight:700!important;border-radius:10px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-sizing:border-box}'
      + '.kt-chat-acts>#kt-notif-btn{font-size:12.5px!important;opacity:1!important}'
      + '#kt-broadcast-btn .kt-s,#kt-notif-btn .kt-s{display:none}'   // desktop moves these buttons out of the row
      + '@media (max-width:600px){.kt-chat-acts>button{font-size:13px!important;padding:0 8px!important}'
      + '.kt-chat-acts.kt-n3 .kt-l,.kt-chat-acts>#kt-notif-btn .kt-l{display:none!important}.kt-chat-acts.kt-n3 .kt-s,.kt-chat-acts>#kt-notif-btn .kt-s{display:inline!important}}'
      + '.kt-chat-acts>#kt-notif-btn[style*="display: none"],.kt-chat-acts>#kt-notif-btn[style*="display:none"]{display:none!important}'
      + '.kt-notif-state{cursor:pointer;opacity:1!important}';
    document.head.appendChild(st);
  }

  async function wireNotifBtn(container) {
    var btn = $('#kt-notif-btn', container);
    if (!btn) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || typeof Notification === 'undefined') {
      return; // browser doesn't support push at all — hide silently
    }

    // Determine current state
    var status = 'unknown';
    try {
      status = (window.KT && KT.Push && KT.Push.status) ? await KT.Push.status() : 'unknown';
    } catch (e) { status = 'unknown'; }

    var apply = function (state) {
      btn.style.display = 'inline-flex';
      btn.disabled = false;
      if (state === 'subscribed') {
        btn.innerHTML = '<span class="kt-l">✓ Notifications on</span><span class="kt-s">✓ Alerts on</span>';
        btn.title = 'OS notifications are enabled. Click to disable.';
        btn.style.background = '#ECFDF5';
        btn.style.color = '#065F46';
        btn.style.borderColor = '#16A34A';
      } else if (state === 'denied') {
        /* Tappable (2026-10-22): it was disabled, with its only help in a hover tooltip
           a phone never shows. Now it opens the steps for this device. */
        btn.innerHTML = '<span class="kt-l">🔕 Notifications blocked · How to fix</span><span class="kt-s">🔕 Fix alerts</span>';
        btn.title = 'Your browser is blocking notifications from KiddieTrac. Tap to see how to turn them back on.';
        btn.style.background = '#FEF2F2';
        btn.style.color = '#991B1B';
        btn.style.borderColor = '#FCA5A5';
      } else if (state === 'not_configured') {
        btn.innerHTML = '<span class="kt-l">Push not configured</span><span class="kt-s">No push</span>';
        btn.style.background = '#F3F4F6';
        btn.style.color = '#6B7280';
        btn.disabled = true;
      } else {
        btn.innerHTML = '<span class="kt-l">🔔 Enable notifications</span><span class="kt-s">🔔 Alerts</span>';
        btn.title = 'Get an OS notification when a new message arrives, even when this tab is closed.';
        btn.style.background = 'white';
        btn.style.color = '#1F6080';
        btn.style.borderColor = '#1F6080';
      }
    };

    var apply0 = apply;
    apply = function (state) {
      apply0(state);
      var row = btn.parentElement;
      if (row && row.classList.contains('kt-chat-acts')) {
        var n = [].filter.call(row.children, function (c) { return c.style.display !== 'none'; }).length;
        row.classList.toggle('kt-n3', n >= 3);
      }
    };
    apply(Notification.permission === 'denied' ? 'denied' : status);
    if (KT.Push && KT.Push.onChange && !btn.__ktPermWatch) {
      btn.__ktPermWatch = true;
      KT.Push.onChange(function () {
        if (!btn.isConnected) return;
        KT.Push.status().then(function (s) { apply(Notification.permission === 'denied' ? 'denied' : s); }, function () {});
      });
    }

    btn.addEventListener('click', async function () {
      if (Notification.permission === 'denied') {
        if (KT.Push && KT.Push.helpBlocked) KT.Push.helpBlocked();
        return;
      }
      if (btn.__ktBusy) return;
      var current = btn.textContent;
      try {
        // If already subscribed, treat click as Disable
        var s = await KT.Push.status();
        if (s === 'subscribed') {
          if (!await KT.confirm('Turn off browser notifications? You will only see new messages when the dashboard tab is open.')) return;
          btn.__ktBusy = true; btn.textContent = '⏳ …';
          await KT.Push.unsubscribe();
          btn.__ktBusy = false;
          apply('unsubscribed');
          if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Notifications turned off', 'success');
          return;
        }
        // Otherwise subscribe
        btn.__ktBusy = true; btn.textContent = '⏳ Asking…';
        var r = await KT.Push.subscribe(true);
        btn.__ktBusy = false;
        if (r.status === 'subscribed')        apply('subscribed');
        else if (r.status === 'denied')       apply('denied');
        else if (r.status === 'not_configured') apply('not_configured');
        else {
          btn.textContent = current;
          chatToast('Could not turn notifications on just now. Please try again in a moment.');
        }
      } catch (e) {
        btn.__ktBusy = false; btn.textContent = current;
        chatToast('Could not turn notifications on just now. Please try again in a moment.');
      }
    });
  }

  // v22p15.1: provider-side "Start a new chat" modal.
  // Loads families the caller has access to, lets them pick one, write a subject
  // + first message, and POSTs to /provider/chats/start. On success, opens the
  // resulting thread directly.
  /**
   * Pick several colleagues at once — used by "Add people" on a thread and by the group
   * option in the new-conversation dialog. Colleagues only: staff_thread groups are
   * vetted server-side against the staff list, and a group holding two families would
   * put one family's messages in front of another.
   *
   * opts: {title, sub, exclude[], needName, min, okLabel, historyNote, onPick(ids,title)}
   */
  async function pickColleaguesModal(opts) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px;';
    var modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:14px;padding:22px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);';
    modal.innerHTML = '<h3 style="margin:0 0 10px;font-size:18px;">' + escapeHtml(opts.title) + '</h3>'
      + '<div style="color:#6B7280;font-size:13px;">Loading colleagues…</div>';
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

    var res = await api('GET', '/provider/team-contacts').catch(function () { return null; });
    var exclude = opts.exclude || [];
    var people = ((res && res.contacts) || []).filter(function (c) {
      return exclude.indexOf(parseInt(c.id, 10)) === -1;
    });
    if (! people.length) {
      modal.innerHTML = '<h3 style="margin:0 0 10px;font-size:18px;">' + escapeHtml(opts.title) + '</h3>'
        + '<div style="color:#6B7280;font-size:13.5px;">Everyone you can message is already in this conversation.</div>'
        + '<div style="display:flex;justify-content:flex-end;margin-top:14px;">'
        + '<button id="kt-pk-x" style="background:#F1F5F9;border:1px solid #D1D5DB;border-radius:8px;padding:9px 16px;font-weight:600;cursor:pointer;">Close</button></div>';
      modal.querySelector('#kt-pk-x').addEventListener('click', function () { overlay.remove(); });
      return;
    }

    var ORDER = ['Admin', 'Director', 'Home visitor', 'Educator', 'Auditor', 'Parent', 'Staff'];
    people.sort(function (a, b) {
      var ra = ORDER.indexOf(a.role || 'Staff'), rb = ORDER.indexOf(b.role || 'Staff');
      if (ra < 0) ra = 9; if (rb < 0) rb = 9;
      if (ra !== rb) return ra - rb;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });

    modal.innerHTML = '<h3 style="margin:0 0 4px;font-size:18px;">' + escapeHtml(opts.title) + '</h3>'
      + '<div style="color:#6B7280;font-size:12.5px;margin-bottom:10px;">' + escapeHtml(opts.sub || '') + '</div>'
      + (opts.needName ? '<input id="kt-pk-name" maxlength="80" placeholder="Group name (e.g. Toddler Room Team)" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:8px;">' : '')
      + '<input id="kt-pk-q" placeholder="Search colleagues…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;margin-bottom:8px;">'
      + '<div id="kt-pk-list" style="max-height:42vh;overflow:auto;border:1px solid #EEF2F7;border-radius:9px;padding:4px;">'
      + people.map(function (p) {
          return '<label class="kt-pk-row" data-hay="' + escapeHtml(((p.name || '') + ' ' + (p.role || '') + ' ' + (p.account || '')).toLowerCase()) + '" '
            + 'style="display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:7px;cursor:pointer;">'
            + '<input type="checkbox" value="' + p.id + '" data-name="' + escapeHtml(p.name || '') + '" style="width:16px;height:16px;flex-shrink:0;cursor:pointer;">'
            + '<span style="flex:1;min-width:0;font-size:14px;color:#0F172A;font-weight:600;">' + escapeHtml(p.name || '')
            + ' <span style="font-weight:500;color:#64748B;">(' + escapeHtml((p.role || 'Staff') + (p.account ? ' · ' + p.account : '')) + ')</span></span></label>';
        }).join('')
      + '</div>'
      + '<div id="kt-pk-sel" style="font-size:12.5px;color:#64748B;margin-top:8px;">No one selected yet.</div>'
      + (opts.historyNote
          ? '<div style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:8px;padding:8px 11px;font-size:12.5px;margin-top:8px;">'
            + '\u{1F4D6} Anyone you add can read the whole conversation, including everything said before they joined.</div>'
          : '')
      + '<div id="kt-pk-err" style="color:#DC2626;font-size:13px;margin-top:8px;min-height:16px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
      + '<button id="kt-pk-cancel" style="background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>'
      + '<button id="kt-pk-ok" disabled style="background:#1F6080;color:white;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;">' + escapeHtml(opts.okLabel || 'Add') + '</button></div>';

    var list = modal.querySelector('#kt-pk-list'), okBtn = modal.querySelector('#kt-pk-ok');
    var selBox = modal.querySelector('#kt-pk-sel'), nameEl = modal.querySelector('#kt-pk-name');
    function chosen() { return [].slice.call(list.querySelectorAll('input:checked')); }
    function refresh() {
      var c = chosen();
      var ok = c.length >= (opts.min || 1) && (! opts.needName || (nameEl && nameEl.value.trim() !== ''));
      okBtn.disabled = ! ok; okBtn.style.opacity = ok ? '1' : '.5';
      selBox.textContent = c.length
        ? c.length + ' selected: ' + c.map(function (i) { return i.getAttribute('data-name'); }).join(', ')
        : ((opts.min || 1) > 1 ? 'Pick at least ' + opts.min + ' colleagues.' : 'No one selected yet.');
    }
    list.addEventListener('change', refresh);
    if (nameEl) nameEl.addEventListener('input', refresh);
    modal.querySelector('#kt-pk-q').addEventListener('input', function (e) {
      var q = e.target.value.toLowerCase();
      modal.querySelectorAll('.kt-pk-row').forEach(function (r) {
        r.style.display = r.getAttribute('data-hay').indexOf(q) > -1 ? '' : 'none';
      });
    });
    modal.querySelector('#kt-pk-cancel').addEventListener('click', function () { overlay.remove(); });
    okBtn.addEventListener('click', async function () {
      var c = chosen();
      okBtn.disabled = true; okBtn.textContent = 'Working…';
      try {
        await opts.onPick(c.map(function (i) { return parseInt(i.value, 10); }), nameEl ? nameEl.value.trim() : null);
        overlay.remove();
      } catch (err) {
        okBtn.disabled = false; okBtn.textContent = opts.okLabel || 'Add';
        modal.querySelector('#kt-pk-err').textContent = (err && err.message) || 'Could not do that.';
      }
    });
    refresh();
    setTimeout(function () { (nameEl || modal.querySelector('#kt-pk-q')).focus(); }, 40);
  }

  /**
   * Everything you can do to a group once it exists, in one place: rename it, see who is
   * in it, remove somebody, leave. Split across the header it would be four affordances
   * competing for a bar that also has to fit the member list.
   *
   * `data` is the thread payload — it already carries participants, can_manage and title,
   * so this opens instantly rather than re-fetching what the caller just read.
   * onChanged() repaints the thread; onGone() is for when you are no longer in it.
   */
  function openGroupDetails(tid, data, onChanged, onGone) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px;';
    var modal = document.createElement('div');
    modal.style.cssText = 'background:white;border-radius:14px;padding:22px;max-width:480px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);';
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

    var people = data.participants || [];
    var canManage = !!data.can_manage;

    modal.innerHTML =
      '<h3 style="margin:0 0 12px;font-size:18px;">\u{1F465} Group details</h3>' +
      '<label style="display:block;font-size:12.5px;font-weight:700;color:#475569;margin-bottom:4px;">Name</label>' +
      '<div style="display:flex;gap:7px;margin-bottom:16px;">' +
        '<input id="kt-gd-name" maxlength="80" value="' + escapeHtml(data.title || '') + '" style="flex:1;min-width:0;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;">' +
        '<button id="kt-gd-rename" style="flex:0 0 auto;background:#1F6080;color:#fff;border:none;border-radius:8px;padding:0 14px;font-size:13px;font-weight:700;cursor:pointer;">Save</button>' +
      '</div>' +
      '<div style="font-size:12.5px;font-weight:700;color:#475569;margin-bottom:6px;">' + people.length + (people.length === 1 ? ' member' : ' members') + '</div>' +
      '<div id="kt-gd-people" style="max-height:34vh;overflow:auto;border:1px solid #EEF2F7;border-radius:9px;padding:4px;margin-bottom:12px;">' +
        people.map(function (p) {
          return '<div class="kt-gd-row" data-uid="' + p.id + '" style="display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:7px;">' +
            '<span style="flex:1;min-width:0;font-size:14px;color:#0F172A;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
              escapeHtml(p.name) + (p.me ? ' <span style="font-weight:500;color:#64748B;">(you)</span>' : '') +
              (String(p.id) === String(data.created_by) ? ' <span style="font-weight:500;color:#94A3B8;">\u00B7 created this</span>' : '') +
            '</span>' +
            /* No remove button against yourself — that is Leave, which is its own thing
               below and needs no permission. */
            ((canManage && !p.me)
              ? '<button class="kt-gd-rm" data-uid="' + p.id + '" data-name="' + escapeHtml(p.name) + '" title="Remove from group" style="flex:0 0 auto;background:none;border:none;color:#B91C1C;font-size:16px;cursor:pointer;padding:0 4px;line-height:1;">\u00D7</button>'
              : '') +
          '</div>';
        }).join('') +
      '</div>' +
      (canManage ? '' : '<div style="font-size:12px;color:#94A3B8;margin:-6px 0 12px;">Only the person who created this group, or a director in it, can remove members.</div>') +
      '<div id="kt-gd-err" style="color:#DC2626;font-size:13px;min-height:16px;margin-bottom:4px;"></div>' +
      '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">' +
        '<button id="kt-gd-leave" style="background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Leave group</button>' +
        '<button id="kt-gd-close" style="background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Close</button>' +
      '</div>';

    var err = modal.querySelector('#kt-gd-err');
    function fail(e) { err.textContent = (e && e.message) || 'Could not do that.'; }

    modal.querySelector('#kt-gd-rename').addEventListener('click', async function () {
      var btn = this, name = modal.querySelector('#kt-gd-name').value.trim();
      if (!name) { fail({ message: 'Give the group a name.' }); return; }
      btn.disabled = true; btn.textContent = 'Saving…'; err.textContent = '';
      try {
        await api('PATCH', '/provider/team-threads/' + tid, { title: name });
        overlay.remove();
        if (onChanged) onChanged();
      } catch (e) { btn.disabled = false; btn.textContent = 'Save'; fail(e); }
    });

    modal.querySelector('#kt-gd-people').addEventListener('click', async function (e) {
      var b = e.target.closest && e.target.closest('.kt-gd-rm');
      if (!b) return;
      var uid = b.getAttribute('data-uid'), nm = b.getAttribute('data-name');
      /* The portal's own confirm, not window.confirm — this one is losing somebody their
         access to a conversation, and it should read like the rest of the product. */
      var go = (window.KT && KT.confirm)
        ? await KT.confirm({ title: 'Remove ' + nm + '?', description: 'They will no longer see this conversation or anything posted in it.', tone: 'danger' })
        : true;
      if (!go) return;
      b.disabled = true; err.textContent = '';
      try {
        await api('DELETE', '/provider/team-threads/' + tid + '/participants/' + uid);
        overlay.remove();
        if (onChanged) onChanged();
      } catch (ex) { b.disabled = false; fail(ex); }
    });

    modal.querySelector('#kt-gd-leave').addEventListener('click', async function () {
      var go = (window.KT && KT.confirm)
        ? await KT.confirm({ title: 'Leave this group?', description: 'You will stop receiving its messages and will not be able to open it again unless somebody adds you back.', tone: 'danger' })
        : true;
      if (!go) return;
      var btn = this; btn.disabled = true; btn.textContent = 'Leaving…'; err.textContent = '';
      try {
        await api('POST', '/provider/team-threads/' + tid + '/leave', {});
        overlay.remove();
        if (onGone) onGone();
      } catch (e) { btn.disabled = false; btn.textContent = 'Leave group'; fail(e); }
    });

    modal.querySelector('#kt-gd-close').addEventListener('click', function () { overlay.remove(); });
  }

  function openAddPeopleModal(tid, existingIds, onDone) {
    pickColleaguesModal({
      title: '\uFF0B Add people', sub: 'They will join this conversation.',
      exclude: existingIds, min: 1, okLabel: 'Add', historyNote: true,
      onPick: async function (ids) {
        await api('POST', '/provider/team-threads/' + tid + '/participants', { user_ids: ids });
        if (onDone) onDone();
      }
    });
  }

  /* NEW MESSAGE, THE SAME POPUP AS A PARENT'S (2026-11-06, Anthony: "make sure all other roles such as home visitor
     view are consistent with the design across the board"). Staff got a bare white box with a plain title, no ✕ and
     no way to add an emoji, a photo or a voice note until the thread was open. Now it is the parent's card: navy-to-
     teal header with ✕ inside it, one scroll region, Send pinned at the foot, and the same emoji grid and recorder.
     The start endpoints take text only, so a photo or voice note follows as the thread's second message. */
  var NC_EMOJI = ['😀','😄','😊','🙂','😉','😍','🥳','😅','🤔','😴','🙌','👍','👏','🙏','💪','🤝',
                  '❤️','🔥','✨','⭐','🎉','☀️','🌈','✅','❗','❓','📌','📎','🕗','🍎','🧸','👶'];
  function ncEl(tag, attrs, html) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (html != null) e.innerHTML = html;
    return e;
  }

  async function openNewChatModal(container) {
    var overlay = ncEl('div', { 'data-kt-scroll': '1', style: 'position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.5);display:flex;align-items:center;'
      + 'justify-content:center;padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 16px) 12px calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 16px);'
      + 'box-sizing:border-box;' });
    var modal = ncEl('div', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'New message', 'data-kt-scroll': '1',
      style: 'width:min(560px,100%);max-height:100%;background:#fff;border-radius:16px;box-shadow:0 24px 60px -20px rgba(15,23,42,.5);'
        + 'display:flex;flex-direction:column;overflow:hidden;' });
    overlay.appendChild(modal);
    function close() { if (window.KT && KT.popOverlay) KT.popOverlay(overlay); else overlay.remove(); }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    var head = ncEl('div', { class: 'kt-dlg-head', style: 'display:flex;align-items:center;gap:10px;padding:13px 14px 15px 16px;flex-shrink:0;'
      + 'background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;' },
      '<div style="flex:1;min-width:0;font-weight:800;font-size:16px;color:#fff;">✉️ New message</div>'
      + '<button type="button" id="kt-nc-x" aria-label="Close" data-kt-iconized="1" style="background:rgba(255,255,255,.14);border:none;width:30px;height:30px;'
      + 'border-radius:8px;font-size:16px;color:#fff;cursor:pointer;line-height:1;flex-shrink:0;">✕</button>');
    modal.appendChild(head);
    head.querySelector('#kt-nc-x').addEventListener('click', close);

    var bodyWrap = ncEl('div', { 'data-kt-scroll': '1', style: 'flex:1;min-height:0;overflow-y:auto;padding:16px;' },
      /* A group is a different shape of thing — several colleagues and a name — so it
         gets its own button here rather than a mode toggle bolted onto the To picker,
         which would have to hide the Subject field and swap the select for a checkbox
         list and still post to a different endpoint. */
      '<button id="kt-nc-group" type="button" data-kt-iconized="1" style="display:flex;align-items:center;gap:9px;width:100%;text-align:left;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;margin-bottom:14px;cursor:pointer;font-family:inherit;">' +
        '<span style="font-size:19px;">\u{1F465}</span>' +
        '<span><span style="display:block;font-weight:700;font-size:13.5px;color:#0F172A;">New group</span>' +
        '<span style="display:block;font-size:12px;color:#64748B;">Several colleagues in one conversation</span></span>' +
      '</button>' +
      '<div id="kt-new-chat-form-body" style="color:#6B7280;font-size:14px;">Loading families…</div>');
    modal.appendChild(bodyWrap);
    var foot = ncEl('div', { style: 'flex-shrink:0;border-top:1px solid #F1F5F9;padding:10px 16px 14px;display:flex;flex-direction:column;gap:8px;background:#fff;' },
      '<div id="kt-nc-err" style="color:#DC2626;font-size:13px;"></div>'
      + '<div style="display:flex;gap:10px;">'
      + '<button id="kt-nc-cancel" type="button" data-kt-iconized="1" style="background:#fff;color:#374151;border:1px solid #D1D5DB;height:40px;padding:0 16px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;">Cancel</button>'
      + '<button id="kt-nc-send" type="button" data-kt-iconized="1" disabled style="flex:1;border:0;cursor:pointer;height:40px;border-radius:10px;font-size:15px;font-weight:800;color:#fff;background:#159FB4;font-family:inherit;">Send message</button>'
      + '</div>');
    modal.appendChild(foot);
    foot.querySelector('#kt-nc-cancel').addEventListener('click', close);
    document.body.appendChild(overlay);
    if (window.KT && KT.pushOverlay) KT.pushOverlay(overlay);

    modal.querySelector('#kt-nc-group').addEventListener('click', function () {
      close();
      pickColleaguesModal({
        title: '\u{1F465} New group', sub: 'Name the group and pick who is in it.',
        needName: true, min: 2, okLabel: 'Create group',
        onPick: async function (ids, title) {
          var r = await api('POST', '/provider/team-threads/group', { user_ids: ids, title: title });
          if (r && r.thread_id) { openThread('staff:' + r.thread_id, container); }
          else { renderList(container); }
        }
      });
    });

    // Pick the right families endpoint based on role. platform_admin was missing and
    // fell through to "not available for your role".
    var famPath = (myRole === 'agency_admin' || myRole === 'platform_admin') ? '/admin/families'
                : (myRole === 'educator' || myRole === 'home_visitor') ? '/provider/chat-families'   // Message Centre rules (2026-10-09)
                : (myRole === 'centre_director') ? '/director/families'
                : null;

    /* Disambiguate people who share a name within the same role group. Only adds a
       qualifier when it is actually needed, so the common case stays clean. */
    function dupeLabel(p, role, group) {
      // p.account: this person has another account here, so say which one this is (TeamChatController::contacts).
      var base = p.name + ' (' + role + (p.account ? ' · ' + p.account : '') + ')';
      if (p.account) return base;
      var sameName = (group || []).filter(function (x) { return String(x.name) === String(p.name); });
      if (sameName.length < 2) return base;
      var extra = p.centre_name || p.email || ('#' + p.id);
      return p.name + ' (' + role + ' · ' + extra + ')';
    }

    // Colleagues too. This dialog only ever offered families, so a director or admin was
    // unreachable from Messages — the complaint that started this. Staff threads are a
    // different backend (keyed on a user, not a family), so both lists are loaded here and
    // the send routes to whichever the chosen row belongs to.
    var families = [], staff = [];
    var loaded = await Promise.all([
      famPath ? api('GET', famPath).catch(function () { return null; }) : Promise.resolve(null),
      api('GET', '/provider/team-contacts').catch(function () { return null; }),
    ]);
    if (loaded[0]) { families = loaded[0].data || loaded[0].families || []; }
    if (loaded[1]) { staff = loaded[1].contacts || []; }

    if (! families.length && ! staff.length) {
      modal.querySelector('#kt-new-chat-form-body').innerHTML = '<div style="color:#6B7280;">Nobody to message yet.</div>';
      return;
    }

    var LBL = 'display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:6px;';
    var FLD = 'width:100%;padding:8px 10px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;font-family:inherit;background:#fff;';
    var TOOL = 'display:inline-flex;align-items:center;gap:6px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;'
      + 'height:32px;padding:0 11px;font-size:13px;font-weight:700;color:#374151;cursor:pointer;font-family:inherit;';
    modal.querySelector('#kt-new-chat-form-body').innerHTML =
      '<div style="margin-bottom:14px;">' +
        '<label style="' + LBL + '">To</label>' +
        '<select id="kt-nc-family" style="' + FLD + '">' +
          // Colleagues first, grouped by role, each labelled — a flat list mixing parents
          // and directors cannot be read at a glance. The value carries the KIND because
          // the two halves post to different endpoints.
          (function () {
            var order = ['Admin', 'Director', 'Home visitor', 'Educator', 'Auditor', 'Parent', 'Staff'];
            var byRole = {};
            staff.forEach(function (p) {
              var r = p.role || 'Staff';
              (byRole[r] = byRole[r] || []).push(p);
            });
            var groups = order.filter(function (r) { return byRole[r] && byRole[r].length; })
              .concat(Object.keys(byRole).filter(function (r) { return order.indexOf(r) === -1; }));
            return groups.map(function (role) {
              return '<optgroup label="' + escapeHtml(role === 'Admin' ? 'Admins' : role + 's') + '">' +
                byRole[role].sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); })
                  .map(function (p) {
                    /* One person can legitimately hold several accounts here — the same
                       name appears as an educator, an admin and a parent. Showing four
                       identical "Safia Ali (Educator)" rows makes the list unusable, and
                       picking the wrong one starts a colleague thread with someone you
                       meant to reach as a parent. Add whatever tells them apart.
                       (Anthony, 2026-08-26) */
                    return '<option value="user:' + p.id + '">' + escapeHtml(dupeLabel(p, role, byRole[role])) + '</option>';
                  }).join('') + '</optgroup>';
            }).join('');
          })() +
          (families.length
            ? '<optgroup label="Families">' + families.map(function (f) {
                return '<option value="family:' + f.id + '">' + escapeHtml(f.family_name || ('Family #' + f.id)) + ' (Family)</option>';
              }).join('') + '</optgroup>'
            : '') +
        '</select>' +
      '</div>' +
      '<div id="kt-nc-subj-row" style="margin-bottom:14px;">' +
        '<label style="' + LBL + '">Subject (optional)</label>' +
        '<input id="kt-nc-subject" type="text" placeholder="e.g. Pickup change today" style="' + FLD + '">' +
      '</div>' +
      '<label style="' + LBL + '">Message</label>' +
      '<textarea id="kt-nc-body" rows="5" placeholder="Write your message…" style="display:block;' + FLD + 'padding:12px 14px;font-size:16px;resize:vertical;"></textarea>' +
      '<div id="kt-nc-tools" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">' +
        '<button type="button" id="kt-nc-emo" data-kt-iconized="1" aria-expanded="false" style="' + TOOL + '"><span>😊</span><span>Emoji</span></button>' +
        '<button type="button" id="kt-nc-photo" data-kt-iconized="1" style="' + TOOL + '"><span>📷</span><span>Photo</span></button>' +
        '<button type="button" id="kt-nc-voice" data-kt-iconized="1" style="' + TOOL + '"><span>🎤</span><span>Voice note</span></button>' +
      '</div>' +
      // .kt-emoji-panel.kt-emoji-bar: the one emoji grid (kt-chat-composer.css), as in an open conversation.
      '<div id="kt-nc-emoji" class="kt-emoji-panel kt-emoji-bar" style="display:none;flex-wrap:wrap;gap:2px;margin-top:8px;padding:6px;background:#fff;border:1px solid #E2E8F0;border-radius:10px;max-height:150px;overflow-y:auto;">' +
        NC_EMOJI.map(function (em) { return '<button type="button" data-em="' + em + '" data-kt-iconized="1" aria-label="' + em + '" style="background:none;border:none;font-size:22px;line-height:1;padding:6px;cursor:pointer;border-radius:8px;">' + em + '</button>'; }).join('') +
      '</div>' +
      '<input id="kt-nc-file" type="file" accept="image/*" multiple style="display:none;">' +
      '<div id="kt-nc-picked" style="margin-top:10px;"></div>';

    var ta = modal.querySelector('#kt-nc-body');
    /* The global "tight and clean" rule caps textarea heights with !important; an inline !important outranks it. */
    ta.style.setProperty('min-height', '140px', 'important');
    ta.style.setProperty('height', 'auto', 'important');
    var sel = modal.querySelector('#kt-nc-family');
    // Full width like the fields under it; the global control rules otherwise cap a select narrower than the card.
    sel.style.setProperty('width', '100%', 'important'); sel.style.setProperty('max-width', 'none', 'important');
    var sendBtn = modal.querySelector('#kt-nc-send');
    var errBox = modal.querySelector('#kt-nc-err');
    // A colleague thread has no subject on the server, so the field only shows for a family.
    function syncSubj() { modal.querySelector('#kt-nc-subj-row').style.display = String(sel.value).indexOf('user:') === 0 ? 'none' : ''; }
    sel.addEventListener('change', syncSubj); syncSubj();
    function syncSend() { sendBtn.disabled = !ta.value.trim(); sendBtn.style.opacity = sendBtn.disabled ? '.55' : ''; }
    ta.addEventListener('input', syncSend); syncSend();

    var emoBtn = modal.querySelector('#kt-nc-emo'), emoPanel = modal.querySelector('#kt-nc-emoji');
    function showEmoji(on) { emoPanel.style.display = on ? 'flex' : 'none'; emoBtn.setAttribute('aria-expanded', on ? 'true' : 'false'); }
    emoBtn.addEventListener('click', function () { showEmoji(emoPanel.style.display === 'none'); });
    closeOnOutsideTap([emoPanel, emoBtn], function () { showEmoji(false); }, function () { return emoPanel.style.display !== 'none'; });
    emoPanel.addEventListener('click', function (e) {
      var b = e.target.closest('[data-em]'); if (!b) return;
      var em = b.getAttribute('data-em');
      var a = ta.selectionStart == null ? ta.value.length : ta.selectionStart;
      var z = ta.selectionEnd == null ? a : ta.selectionEnd;
      ta.value = ta.value.slice(0, a) + em + ta.value.slice(z);
      ta.selectionStart = ta.selectionEnd = a + em.length;
      ta.focus(); syncSend();
    });

    // One attachment, a photo or a voice note; picking one replaces the other.
    var pickedFile = null;
    var pickedMore = [];   // the 2nd, 3rd... photo when several are picked at once (2026-11-13)
    var photoBtn = modal.querySelector('#kt-nc-photo'), voiceBtn = modal.querySelector('#kt-nc-voice');
    var fileInput = modal.querySelector('#kt-nc-file'), picked = modal.querySelector('#kt-nc-picked');
    function showPicked() {
      picked.innerHTML = '';
      photoBtn.lastChild.textContent = 'Photo'; voiceBtn.lastChild.textContent = 'Voice note';
      if (!pickedFile) return;
      var isAudio = (pickedFile.type || '').indexOf('audio') === 0;
      var row = ncEl('div', { style: 'display:flex;align-items:center;gap:8px;' });
      var media = isAudio
        ? ncEl('audio', { controls: '', preload: 'metadata', style: 'width:230px;max-width:100%;height:40px;' })
        : ncEl('img', { style: 'max-width:170px;border-radius:10px;display:block;' });
      media.src = URL.createObjectURL(pickedFile);
      row.appendChild(media);
      pickedMore.forEach(function (mf) {
        var mi = ncEl('img', { style: 'max-width:80px;max-height:80px;border-radius:10px;display:block;object-fit:cover;' });
        mi.src = URL.createObjectURL(mf);
        row.appendChild(mi);
      });
      row.style.flexWrap = 'wrap';
      (isAudio ? voiceBtn : photoBtn).lastChild.textContent = isAudio ? 'Record again' : (pickedMore.length ? 'Change photos' : 'Change photo');
      var rm = ncEl('button', { type: 'button', 'data-kt-iconized': '1', 'aria-label': isAudio ? 'Remove voice note' : 'Remove photo', 'data-kttip': 'Remove',
        style: 'flex-shrink:0;width:30px;height:30px;border-radius:8px;border:1px solid #E2E8F0;background:#fff;color:#64748B;cursor:pointer;font-size:14px;' }, '✕');
      rm.addEventListener('click', function () { pickedFile = null; pickedMore = []; showPicked(); });
      row.appendChild(rm);
      picked.appendChild(row);
    }
    photoBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      var all = Array.prototype.slice.call(fileInput.files || [], 0, 10);
      if (all.length) { pickedFile = all[0]; pickedMore = all.slice(1); fileInput.value = ''; showPicked(); }
    });
    voiceBtn.addEventListener('click', async function () {
      if (!(window.KT && KT.recordVoiceNote)) { errBox.textContent = 'Voice messages aren’t supported on this device.'; return; }
      showEmoji(false);
      voiceBtn.disabled = true;
      try {
        // The recorder stands in for the tools row while it is up, then gives it back.
        var file = await KT.recordVoiceNote({ anchor: modal.querySelector('#kt-nc-tools'), acceptLabel: 'Use' });
        if (file) { pickedFile = file; pickedMore = []; showPicked(); }
      } finally { voiceBtn.disabled = false; }
    });

    sendBtn.addEventListener('click', async function () {
      // Values are still prefixed "family:" so the picker can grow back to carrying
      // staff without the id meaning two different things.
      var picked = String(sel.value || '');
      var toStaff = picked.indexOf('user:') === 0;
      var fid = parseInt(picked.replace(/^(user|family):/, ''), 10);
      var subj = modal.querySelector('#kt-nc-subject').value.trim();
      var body = ta.value.trim();
      errBox.textContent = '';
      if (!body) { errBox.textContent = pickedFile ? 'Add a few words to start the conversation; the attachment goes with them.' : 'Please type a message.'; return; }
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      try {
        var resp = toStaff
          ? await api('POST', '/provider/team-threads/start', { recipient_user_id: fid, body: body })
          : await api('POST', '/provider/chats/start', { family_id: fid, subject: subj || null, body: body });
        var newId = toStaff ? (resp && (resp.thread_id || resp.id)) : (resp && resp.conversation_id);
        if (pickedFile && newId) {
          // One message per photo, in the order picked - the chat carries one attachment per message.
          var lost = 0, sendFiles = [pickedFile].concat(pickedMore);
          for (var sf = 0; sf < sendFiles.length; sf++) {
            // Multipart, not api(): that helper sets a JSON content-type, which would corrupt a FormData body.
            var fd = new FormData();
            fd.append('body', '');
            fd.append('attachment', sendFiles[sf]);
            var res = await fetch(apiBase() + (toStaff ? '/provider/team-threads' : '/provider/chats') + '/' + newId + '/send', {
              method: 'POST', headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' }, body: fd,
            }).catch(function () { return { ok: false }; });
            if (!res.ok) { lost++; }
          }
          if (lost) { ktToastSafe('Message sent, but ' + (lost === 1 ? 'an attachment' : lost + ' attachments') + ' could not be added. Open the conversation to try again.'); }
        }
        close();
        if (newId) { openThread(toStaff ? 'staff:' + newId : newId, container); }
        else { renderList(container); }
      } catch (e) {
        sendBtn.disabled = false; sendBtn.textContent = 'Send message';
        errBox.textContent = e.message || 'Could not send. Please try again.';
      }
    });

    setTimeout(function () { ta.focus(); }, 40);
  }
  function ktToastSafe(msg) {
    if (window.KT && KT.toast) { KT.toast('⚠️', msg); }
  }

  /* ─── Colleague thread ──────────────────────────────────────────
     Deliberately thinner than the family thread below: /provider/team-threads has no
     reactions, no attachments and no delete, so this offers none of them. An affordance
     that 404s is worse than an absent one. */
  var staffPoll = null;
  /* The visibility listener for the OPEN colleague thread. Module scope so re-opening a
     thread replaces it instead of stacking another one on every open. */
  var staffWakeFn = null;
  function stopStaffWake() {
    if (staffWakeFn) { document.removeEventListener('visibilitychange', staffWakeFn); staffWakeFn = null; }
  }
  function stopStaffPoll() {
    if (staffPoll) { clearInterval(staffPoll); staffPoll = null; }
    stopStaffWake();
  }

  var staffOtherReadAt = null;   // when the colleague last opened this thread

  async function openStaffThread(tid, container) {
    staffOtherReadAt = null;
    openThreadId = 'staff:' + tid;
    if (window.KT && KT.reportBlock) KT.reportBlock.onChange = function () { paint(true, true); };   // Report + Block
    threadListContainer = container;
    threadDocked = !!(KT.ChatDock && KT.ChatDock.enabled());
    var target = threadDocked ? KT.ChatDock.contentEl() : container;
    target.innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280;">Loading…</div>';
    var cleanup = function () { stopStaffPoll(); closeThreadCleanup(); };

    /* Tell kt-back a full-screen thread is open, so Android's hardware/gesture back
       returns to the conversation LIST instead of leaving Message Centre. kt-back's own
       header lists "chat thread" as the case it exists for; the family thread registers
       and this one never did.

       The CONTAINER is registered, not the thread root: paint() rewrites the markup on
       every poll tick, so a root would be replaced and then pruned, and back would stop
       working after the first poll. The container survives every re-paint, and when the
       screen itself is re-rendered it leaves the DOM and kt-back prunes this entry.

       Not when docked: the dock owns its own close, and the header carrying the ←
       button is not rendered at all. */
    if (!threadDocked && window.KT && KT.pushOverlay) {
      staffBackEl = container;
      KT.pushOverlay(container, staffLeaveThread);
    }
    if (threadDocked) {
      KT.ChatDock.show('Chat', cleanup);
      if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread('staff:' + tid, 'Chat'); }
    }

    var lastCount = -1;
    var lastSeenId = -1;
    /* A send in flight, for the WHOLE thread (not per render). The server stores the message before it
       answers, so on a slow reply the 2.5s poll saw it arrive and redrew the composer mid-send: the
       draft was copied into a NEW box with a live ➤, and the send then cleared the old, detached box.
       The text stayed put after sending, and a second tap sent it twice. The poll waits while this is set. */
    var stSendInFlight = false;
    /* SEND AS FAST AS YOU TYPE (2026-11-11, Anthony: "when chat messages are sent there is a pause before it acutally
       gets sent - can you make this real time to send multiple messages quickly"). Each ➤ shows its bubble at once and
       joins a queue that posts in order; the box never waits. stQ = bubbles still going; stChain = the queue.
       stShownIds = the ids drawn in the thread, so a new arrival is APPENDED instead of redrawing everything (that
       redraw, composer included, was ~0.8s on a phone each time and closed the keyboard: ticket #248). */
    var stQ = [];
    var stChain = Promise.resolve();
    var stShownIds = [];
    var stBodyDirty = false;   // our own early bubbles are in the thread: the next refresh redraws the messages

    /* Reactions on a COLLEAGUE thread (2026-08-25).
       Emoji were only ever built for family conversations. When broadcasts moved to 1:1
       staff threads the feature silently came away with them - "it was once there and now
       it isn't". Same six emoji, same chip, same toggle as the family thread, so the two
       do not drift into behaving differently. */
    var ST_EMOJI = ['\u{1F44D}', '\u2764\uFE0F', '\u{1F602}', '\u{1F62E}', '\u{1F622}', '\u{1F64F}'];

    function staffChip(mid, rx) {
      return '<button type="button" class="kt-st-chip" data-react-mid="' + mid + '" data-emoji="'
        + escapeHtml(rx.emoji) + '" style="border:1px solid ' + (rx.mine ? '#1F6080' : '#E2E8F0')
        + ';background:' + (rx.mine ? '#EFF6FF' : '#fff')
        + ';border-radius:10px;padding:0 6px;font-size:11.5px;cursor:pointer;line-height:1.55;display:inline-flex;align-items:center;white-space:nowrap;">'
        + escapeHtml(rx.emoji) + ' ' + rx.count + '</button>';
    }

    function staffApply(mid, reactions) {
      var row = target.querySelector('[data-mid="' + mid + '"]');
      if (!row) return;
      var wrap = row.querySelector('.kt-st-reactions');
      if (!wrap) return;
      var html = (reactions && reactions.length)
        ? reactions.map(function (rx) { return staffChip(mid, rx); }).join('') : '';
      wrap.innerHTML = html;
      wrap.style.display = html ? 'flex' : 'none';
    }

    function staffReact(mid, emoji) {
      api('POST', threadBase() + '/' + tid + '/messages/' + mid + '/react', { emoji: emoji })
        .then(function (r) { staffApply(mid, r && r.reactions); })
        .catch(function (err) {
          if (window.KT && KT.toast) KT.toast('\u26A0\uFE0F', 'Could not react', (err && err.message) || '', '#DC2626');
        });
    }

    /* Delegated on `target`, which survives paint()'s innerHTML rewrites - binding to the
       message list itself would come undone on the next poll. */
    target.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      var chip = e.target.closest('.kt-st-chip');
      if (chip) { staffReact(chip.getAttribute('data-react-mid'), chip.getAttribute('data-emoji')); return; }
      var rb = e.target.closest('.kt-st-react');
      if (!rb) return;
      var open = document.querySelector('.kt-st-pop'); if (open) open.remove();
      var mid = rb.getAttribute('data-react-mid');
      var pop = document.createElement('div');
      pop.className = 'kt-st-pop';
      pop.style.cssText = 'position:fixed;background:#fff;border:1px solid #E5E7EB;border-radius:20px;'
        + 'box-shadow:0 6px 20px rgba(0,0,0,.16);padding:4px 6px;display:flex;gap:2px;z-index:100000;';
      pop.innerHTML = ST_EMOJI.map(function (em) {
        return '<button type="button" class="kt-st-pick" data-emoji="' + em + '" style="background:none;'
          + 'border:none;font-size:20px;cursor:pointer;padding:2px 5px;border-radius:8px;line-height:1;">' + em + '</button>';
      }).join('');
      var rect = rb.getBoundingClientRect();
      pop.style.left = Math.max(6, Math.min(rect.left - 40, window.innerWidth - 230)) + 'px';
      pop.style.top = Math.max(6, rect.top - 46) + 'px';
      document.body.appendChild(pop);
      pop.querySelectorAll('.kt-st-pick').forEach(function (pb) {
        pb.addEventListener('click', function () { staffReact(mid, pb.getAttribute('data-emoji')); pop.remove(); });
      });
      closeOnOutsideTap([pop, rb], function () { pop.remove(); return true; });
    });

    function bubble(m) {
      var html = bubbleBase(m);
      return (m.is_emergency && !m.system) ? emergencyRow(!!m.mine) + html : html;
    }
    function bubbleBase(m) {
      var mine = !!m.mine;
      ensureBubbleCss();
      // Their photo beside their bubble, matching the family thread. Own messages get
      // none — you know who you are.
      /* "Emily added Olivia" is the conversation reporting what happened, not somebody
         speaking. Rendered as a bubble it looked like a colleague announcing themselves
         in the third person, and — because the adder is often you — as YOUR message. */
      if (m.system) {
        return '<div data-mid="' + m.id + '" style="text-align:center;margin:12px 0;">'
          + '<span style="display:inline-block;background:#EDF2F7;color:#64748B;font-size:11.5px;'
          + 'font-weight:600;border-radius:20px;padding:4px 12px;">' + escapeHtml(m.body || '') + '</span></div>';
      }
      var pic = '';
      if (!mine) {
        var url = absPhotoUrl(m.photo);
        pic = (url && window.KT && KT.avatar)
          ? '<span style="flex-shrink:0;align-self:flex-end;display:inline-flex;margin-right:8px;">' + KT.avatar(m.sender || '?', { size: 30, photoUrl: url }) + '</span>'
          : '<span style="flex-shrink:0;align-self:flex-end;margin-right:8px;width:30px;height:30px;border-radius:50%;background:' + senderColor(m.sender) + ';color:#fff;font-size:12px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;">' + escapeHtml((String(m.sender || '?').charAt(0) || '?').toUpperCase()) + '</span>';
      }
      /* Same receipt the family thread shows: ✓✓ once they have opened the thread
         since this message was sent, ✓ until then. Only on our own messages — a tick
         on theirs would be telling them what they already know. */
      var tick = '';
      if (mine) {
        // This payload timestamps messages as `at`; `created_at` is the family shape.
        var seen = staffOtherReadAt && m.at
          && (new Date(String(staffOtherReadAt).replace(' ', 'T') + 'Z')
              >= new Date(String(m.at).replace(' ', 'T') + 'Z'));
        tick = seen
          ? ' <span title="Seen" style="color:#7DD3FC;font-weight:700;">✓✓</span>'
          : ' <span title="Sent — not read yet" style="opacity:.85;">✓</span>';
      }
      // '+' and inline chips - same reasoning as the family bubble.
      var rBtn = (RP() && RP().reactBtn) ? RP().reactBtn(m.id, 'kt-st-react')
        : '<button type="button" class="kt-st-react" data-react-mid="' + m.id + '" title="Add a reaction" aria-label="Add a reaction" style="background:none;border:none;cursor:pointer;color:#94A3B8;font-size:15px;font-weight:700;line-height:1;padding:0 2px;margin-left:6px;">\uFF0B</button>';
      var rHtml = '<span class="kt-st-reactions" style="display:contents;">'
        + ((m.reactions && m.reactions.length)
            ? m.reactions.map(function (rx) { return staffChip(m.id, rx); }).join('') : '')
        + '</span>';
      var rp = RP();
      var rpBtn = rp ? rp.btn(m.id, mine ? 'You' : m.sender, m.body, m.attachments) : '';
      var quote = rp ? rp.quote(m, { dark: mine }) : '';
      var emo = !quote && !(m.attachments && m.attachments.length) && emojiOnly(m.body);
      return '<div data-mid="' + m.id + '" style="display:flex;align-items:flex-end;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';margin-bottom:10px;">' +
        pic +
        '<div style="max-width:78%;">' +
          (mine ? '' : '<div style="font-size:11px;color:#64748B;font-weight:700;margin:0 0 3px 4px;">' + escapeHtml(m.sender || '') + '</div>') +
          '<div data-kt-rp-bubble="1"' + (emo ? ' class="kt-emo-bubble"' : (mine ? ' class="kt-st-mine"' : '')) + ' style="background:' + (mine ? '#1F6080' : '#FFFFFF') + ';color:' + (mine ? '#FFFFFF' : '#0D1B2A') + ';' +
            'border:1px solid ' + (mine ? '#1F6080' : '#E7EBF0') + ';border-radius:14px;padding:9px 12px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;">' +
            quote +
            (emo ? '<span class="kt-emo-big">' + escapeHtml(String(m.body).trim()) + '</span>' : escapeHtml(m.body || '')) +
            (function () {
              // A photo shows as a photo and a voice note as a player. The server has
              // already normalised a browser voice note's video/* container to audio/*,
              // so mime can be trusted here.
              var atts = m.attachments || [];
              return atts.map(function (a) {
                var url = absPhotoUrl(a.url);
                if (/^image\//.test(a.mime || '')) {
                  return '<div style="margin-top:6px;"><img data-kt-media-group="chat" src="' + escapeHtml(url) + '" alt="' + escapeHtml(a.name || 'Photo') + '" style="max-width:100%;border-radius:9px;display:block;cursor:zoom-in;"></div>';
                }
                if (/^audio\//.test(a.mime || '')) {
                  return '<div style="margin-top:6px;"><audio controls preload="none" src="' + escapeHtml(url) + '" style="width:210px;max-width:100%;"></audio></div>';
                }
                return '<div style="margin-top:6px;"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">📎 ' + escapeHtml(a.name || 'Attachment') + '</a></div>';
              }).join('');
            })() + '</div>' +
          '<div style="font-size:10.5px;color:#94A3B8;margin:3px 4px 0;display:flex;flex-wrap:wrap;align-items:center;gap:3px;justify-content:'
            + (mine ? 'flex-end' : 'flex-start') + ';">'
            + '<span style="white-space:nowrap;">' + formatDateTime(m.at) + tick + '</span>' + rHtml + rBtn + rpBtn
            // ⋯ on a colleague's message: report it, or block them (kt-report-block.js, 2026-11-03).
            + ((!mine && m.sender_id && window.KT && KT.reportBlock) ? KT.reportBlock.btnHtml({ kind: 'staff', conv: tid, mid: m.id, uid: m.sender_id, name: m.sender, blocked: m.blocked }) : '')
            + '</div>' +
        '</div></div>';
    }

    async function paint(force, full) {
      var data;
      try { data = await api('GET', threadBase() + '/' + tid); }
      catch (e) {
        stopStaffPoll();
        /* A 404 here is almost always a thread the dock REMEMBERED that no longer
           resolves for you — you switched agency, or it was deleted. That is not an
           error the person caused, and re-opening it every load is worse than not
           opening it. Forget it and close quietly. */
        if (/\b(403|404)\b/.test(String(e && e.message))) {
          if (KT.ChatDock && KT.ChatDock.forget) { KT.ChatDock.forget(); }
          closeThreadCleanup();
          if (threadDocked && KT.ChatDock && KT.ChatDock.hide) { KT.ChatDock.hide(); }
          else { target.innerHTML = ''; }
          return;
        }
        target.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load this conversation: ' + escapeHtml(e.message) + '</div>';
        return;
      }
      staffOtherReadAt = data.other_read_at || null;
      /* The await above took time, and the person may have opened somebody else while
         it was in flight. Writing now would put THIS thread's messages under THEIR name.
         Checked after every await, not only before the fetch. */
      if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }

      /* IS THE THING WE ARE ABOUT TO PAINT INTO STILL ON SCREEN?

         `target` is captured once when the thread opens. A global re-render that replaces
         #appMain leaves this reference pointing at a detached node, and every later paint
         then writes the new messages into a node nobody can see — the thread appears
         frozen on the message it had when the swap happened, forever, while the poll
         happily keeps working. Same shape as the detached-container bug in the sweep.
         Nothing to repair from here: the screen that owns the container has gone, so stop
         burning a request every 2.5s. */
      if (!document.contains(target)) { stopStaffPoll(); return; }

      var msgs = data.messages || [];
      /* Only repaint when something actually arrived — a blind repaint every few seconds
         would wipe whatever the person is part-way through typing.

         Compared on the LAST ID as well as the count: a count alone cannot see an edit,
         or a delete and an arrival landing between two ticks, and if a render ever throws
         after lastCount was assigned the thread would be wedged on a stale view for good. */
      var lastId = msgs.length ? msgs[msgs.length - 1].id : 0;
      if (!force && msgs.length === lastCount && lastId === lastSeenId) { return; }
      if (!force && stSendInFlight) { return; }   // the send repaints itself once it has finished
      lastSeenId = lastId;

      /* ONLY THE MESSAGES CHANGE. Once this thread's frame is drawn, a new message touches the message list and
         nothing else: the header, the box you are typing in, the keyboard and every button stay exactly as they
         are. Only a change of who is in it (full) or of read-only redraws the whole frame. */
      var shellBody = target.querySelector('#kt-st-body');
      if (!full && shellBody && target.getAttribute('data-st-shell') === String(tid)
          && (data.read_only ? '1' : '0') === target.getAttribute('data-st-ro')) {
        lastCount = msgs.length;
        var nearBottom = shellBody.scrollTop + shellBody.clientHeight >= shellBody.scrollHeight - 120;
        var prevTop = shellBody.scrollTop;
        var ids = msgs.map(function (m) { return m.id; });
        var appendOnly = !stBodyDirty && stShownIds.length && ids.length > stShownIds.length
          && stShownIds.every(function (id, i) { return ids[i] === id; });
        if (appendOnly) {
          shellBody.insertAdjacentHTML('beforeend', msgs.slice(stShownIds.length).map(bubble).join(''));
        } else {
          shellBody.innerHTML = msgs.length ? msgs.map(bubble).join('')
            : '<div style="text-align:center;color:#64748B;padding:26px;font-size:13px;">No messages yet. Say hello.</div>';
          stBodyDirty = false;
        }
        stShownIds = ids;
        stQ.forEach(function (it) { if (it.el) { shellBody.appendChild(it.el); stBodyDirty = true; } });   // still going: stay last
        if (nearBottom || force) { pinToBottom(shellBody); } else { shellBody.scrollTop = prevTop; }
        return;
      }
      var draft = '';
      var oldBox = target.querySelector('#kt-st-input');
      if (oldBox) { draft = oldBox.value; }
      lastCount = msgs.length;
      if (threadDocked) {
        KT.ChatDock.show(data.name || 'Chat', cleanup);
        // Save the REAL name against this thread's key. show() no longer saves, so
        // without this the dock would come back titled 'Chat'.
        if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread('staff:' + tid, data.name || 'Chat'); }
      }

      target.innerHTML =
        '<div style="display:flex;flex-direction:column;height:100%;min-height:0;background:#F7F9FB;">' +
          (threadDocked ? '' :
            '<div class="kt-thread-header" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-bottom:1px solid #E5E7EB;flex:0 0 auto;">' +
              '<button id="kt-st-back" style="background:none;border:none;font-size:24px;color:#1F6080;cursor:pointer;padding:0 4px;line-height:1;">‹</button>' +
              '<strong style="font-size:16px;color:#0D1B2A;">' + escapeHtml(data.name || 'Colleague') + '</strong>' +
            '</div>') +
          /* Who can see what you are about to type. In a group that question must never
             be answered by scanning the bubbles, and on a 1:1 this is the affordance that
             turns it into a group. Rendered in the dock too, where the header is just a
             title and there is nowhere else for it to live. */
          (function () {
            var ppl = data.participants || [];
            var others = ppl.filter(function (p) { return !p.me; });
            var names = others.map(function (p) { return p.name; }).join(', ');
            return '<div style="display:flex;align-items:center;gap:8px;padding:7px 12px;background:#F1F5F9;border-bottom:1px solid #E5E7EB;flex:0 0 auto;font-size:12px;color:#475569;">' +
              (data.is_group
                ? (function () {
                    /* "1 members ·" with nothing after it — what this reads as once
                       everyone else has gone. Both halves have to survive that. */
                    var n = data.member_count || ppl.length;
                    return '<button id="kt-st-details" type="button" title="Group details — rename, remove, leave" style="min-width:0;flex:1;text-align:left;background:none;border:none;padding:0;cursor:pointer;font:inherit;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
                      '\u{1F465} <strong>' + escapeHtml(String(n)) + (n === 1 ? ' member' : ' members') + '</strong>' +
                      (names ? ' · ' + escapeHtml(names) : ' · just you') +
                    '</button>';
                  })()
                : '<span style="min-width:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Private with ' + escapeHtml(names || 'a colleague') + '</span>') +
              '<button id="kt-st-addppl" type="button" style="flex:0 0 auto;background:#fff;border:1px solid #CBD5E1;border-radius:7px;padding:3px 9px;font-size:12px;font-weight:700;color:#1F6080;cursor:pointer;white-space:nowrap;">\uFF0B Add people</button>' +
            '</div>';
          })() +
          /* kt-thread-body: the same class the family thread uses, so the phone rules
             that make THIS the scroller (rather than the page) apply here too. */
          /* data-kt-scroll: this is a REAL inner scroller and must stay one. kt-mobile-app.css
             flattens inline overflow on phones to stop cards scrolling inside themselves,
             and it matches on the inline style string — which silently included this. */
          '<div id="kt-st-body" class="kt-thread-body" data-kt-scroll="1" style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:14px;">' +
            (msgs.length ? msgs.map(bubble).join('')
              : '<div style="text-align:center;color:#64748B;padding:26px;font-size:13px;">No messages yet. Say hello.</div>') +
          '</div>' +
          /* kt-thread-compose is what every mobile chat rule keys on — the full-screen
             takeover, hiding the bottom bar so it stops covering this composer, and the
             safe-area padding. Without the class a staff thread got none of it. */
          '<div class="kt-thread-compose kt-compose-wrap" style="flex:0 0 auto;position:relative;background:#fff;border-top:1px solid #E5E7EB;">' +
            '<div class="kt-rp-slot"></div>' +
            '<div id="kt-st-pending" style="display:none;align-items:center;gap:8px;padding:8px 10px 0;font-size:12.5px;color:#475569;"></div>' +
            '<div id="kt-st-emoji" style="display:none;position:absolute;bottom:56px;left:8px;background:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);padding:8px;width:264px;max-height:170px;overflow-y:auto;z-index:40;font-size:22px;line-height:1.5;"></div>' +
            /* THE ONE COMPOSER ROW (.kt-compose-row, kt-chat-composer.css): the box, the ➤ straight after it */
            // 👋 beside the ＋ (kt-compose-plus.js): the endpoint is all it needs.
            '<div class="kt-compose-row" data-kt-nudge="' + threadBase() + '/' + tid + '/nudge" style="display:flex;align-items:center;gap:6px;padding:10px 12px;">' +
              '<button id="kt-st-emoji-btn" type="button" title="Emoji" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:21px;padding:3px 5px;">😊</button>' +
              '<button id="kt-st-photo-btn" type="button" title="Send a photo" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:20px;padding:3px 5px;">📷</button>' +
              '<button id="kt-st-mic-btn" type="button" title="Record a voice note" style="flex:0 0 auto;background:transparent;border:none;cursor:pointer;font-size:20px;padding:3px 5px;">🎤</button>' +
              '<input id="kt-st-file" type="file" accept="image/*" multiple style="display:none;">' +
              '<textarea id="kt-st-input" rows="1" placeholder="Type a message…" style="flex:1;min-width:0;padding:9px 11px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;font-family:inherit;resize:none;max-height:110px;box-sizing:border-box;"></textarea>' +
              '<button id="kt-st-send" type="button" class="kt-send-one" title="Send" aria-label="Send" disabled>➤</button>' +
            '</div>' +
          '</div>' +
        '</div>';

      var body = target.querySelector('#kt-st-body');
      target.setAttribute('data-st-shell', String(tid));
      target.setAttribute('data-st-ro', data.read_only ? '1' : '0');
      stShownIds = msgs.map(function (m) { return m.id; });
      stBodyDirty = false;
      stQ.forEach(function (it) { if (it.el && body) { body.appendChild(it.el); stBodyDirty = true; } });
      pinToBottom(body);        // colleague threads carry photos too
      if (data.read_only) applyReadOnly(target, data.read_only_reason);
      var input = target.querySelector('#kt-st-input');
      if (input) { input.value = draft; }
      var rpKey = 'staff:' + tid;
      if (RP()) RP().wire(target, { key: rpKey, input: function () { return target.querySelector('#kt-st-input'); } });

      var back = target.querySelector('#kt-st-back');
      if (back) {
        back.addEventListener('click', function () {
          /* Leave through kt-back when we registered, so the entry is WITHDRAWN as well
             as dismissed. Closing directly would leave a stale registration against a
             container that is still in the DOM — prune() would never drop it, and the
             next back press would be swallowed dismissing a thread already closed.
             popOverlay() pops the entry and calls the same staffLeaveThread. */
          if (staffBackEl && window.KT && KT.popOverlay) { KT.popOverlay(staffBackEl); return; }
          staffLeaveThread();
        });
      }

      var detailsBtn = target.querySelector('#kt-st-details');
      if (detailsBtn) {
        detailsBtn.addEventListener('click', function () {
          openGroupDetails(tid, data, function () { paint(true, true); }, function () {
            /* No longer a member. The pane must not sit there with a working composer
               posting into a thread the server will now 404 — which is exactly what it
               did when this only called hide() behind a `threadDocked` guard. forget()
               first so the dock does not restore it on the next load, then close()
               (hide() only collapses it), unconditionally. */
            stopStaffPoll();
            closeThreadCleanup();
            try {
              if (KT.ChatDock) {
                if (KT.ChatDock.forget) { KT.ChatDock.forget(); }
                if (KT.ChatDock.close) { KT.ChatDock.close(); }
                else if (KT.ChatDock.hide) { KT.ChatDock.hide(); }
              }
            } catch (e) {}
            if (threadListContainer) { renderList(threadListContainer); }
          });
        });
      }

      var addPplBtn = target.querySelector('#kt-st-addppl');
      if (addPplBtn) {
        addPplBtn.addEventListener('click', function () {
          openAddPeopleModal(tid, (data.participants || []).map(function (p) { return p.id; }), function () {
            paint(true, true);
          });
        });
      }

      // ── emoji ─────────────────────────────────────────────────────────
      var EMOJI = ['😀','😄','😊','🙂','😉','😍','🥳','😅','🤔','😴','🙌','👍','👏','🙏','💪','🤝',
                   '❤️','🔥','✨','⭐','🎉','☀️','🌈','✅','❗','❓','📌','📎','🕗','🍎','🧸','👶'];
      var emojiPanel = target.querySelector('#kt-st-emoji');
      var emojiBtn = target.querySelector('#kt-st-emoji-btn');
      emojiPanel.innerHTML = EMOJI.map(function (e) {
        return '<button type="button" data-em="' + e + '" style="background:none;border:none;font-size:21px;cursor:pointer;padding:2px 4px;border-radius:7px;line-height:1;">' + e + '</button>';
      }).join('');
      emojiBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        emojiPanel.style.display = emojiPanel.style.display === 'none' ? 'block' : 'none';
      });
      closeOnOutsideTap([emojiPanel, emojiBtn], function () { emojiPanel.style.display = 'none'; },
        function () { return emojiPanel.style.display === 'block'; });
      emojiPanel.addEventListener('click', function (e) {
        var b = e.target.closest('[data-em]');
        if (!b) { return; }
        input.value = (input.value || '') + b.getAttribute('data-em');
        /* AN EMOJI ON ITS OWN IS A MESSAGE (2026-11-05, Anthony: "chat won't let me send an emoji on its
           own"). Setting .value from script fires no 'input' event, and 'input' is the only thing that
           wakes the send arrow (syncSend), so a picked emoji left it faded until a letter was typed.
           Announce the change the way typing does, so every listener on the box hears it. */
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
      });

      // ── the file waiting to go, photo or voice note ───────────────────────
      var pendingFile = null;
      var pendingMore = [];   // more photos picked with it; each goes as its own message (2026-11-13)
      var pendingBox = target.querySelector('#kt-st-pending');
      var pendingThumb = null;
      function setPending(f, label) {
        pendingFile = f;
        pendingMore = [];
        syncSend();
        if (pendingThumb) { URL.revokeObjectURL(pendingThumb); pendingThumb = null; }
        if (!f) { pendingBox.style.display = 'none'; pendingBox.innerHTML = ''; return; }
        pendingBox.style.display = 'flex';
        // A picture shows itself, so you can see it is the right screenshot before it goes.
        if (/^image\//.test(f.type || '')) { pendingThumb = URL.createObjectURL(f); }
        /* ONE CHIP (2026-11-03, Anthony: "recording voice note in the messanger the attach button looks horrible").
           It was the label as loose text and a bold red "Remove" off its baseline. Now a rounded chip: the picture or a
           ▶ to hear the voice note, its name (and length), and a quiet ✕ to take it off. */
        var isAudio = /^audio\//.test(f.type || '') || /^🎤/.test(label || '');
        pendingBox.innerHTML = '<div style="display:inline-flex;align-items:center;gap:8px;max-width:100%;padding:4px 4px 4px ' + (pendingThumb || isAudio ? '4px' : '12px')
          + ';background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;font-size:13px;font-weight:600;color:#334155;box-sizing:border-box;">'
          + (pendingThumb ? '<img src="' + pendingThumb + '" alt="" style="width:30px;height:30px;object-fit:cover;border-radius:50%;flex:0 0 auto;">' : '')
          + (isAudio ? '<button type="button" id="kt-st-play" data-kt-iconized="1" data-kt-btn-free="1" title="Play it back" aria-label="Play it back" style="flex:0 0 auto;width:30px;height:30px;min-height:0;padding:0;border-radius:50%;border:none;background:#E0F2F5;color:#0E7490;font-size:12px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;">' + '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 1.5v9l7.5-4.5z" fill="currentColor"/></svg>' + '</button>' : '')
          + '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(isAudio ? 'Voice note' : (label || f.name || 'Attachment').replace(/^[^\w\s]+\s+/, '')) + '</span>'
          + (isAudio ? '<span id="kt-st-len" style="font-weight:700;color:#64748B;font-variant-numeric:tabular-nums;"></span>' : '')
          + '<button type="button" id="kt-st-clear" title="Remove" data-kt-iconized="1" data-kt-btn-free="1" aria-label="Remove" style="flex:0 0 auto;width:26px;height:26px;min-height:0;padding:0;border-radius:50%;border:none;background:#fff;color:#64748B;font-size:13px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 0 0 1px #E2E8F0;">✕</button>'
          + '</div>';
        pendingBox.querySelector('#kt-st-clear').addEventListener('click', function () { setPending(null); });
        if (isAudio) {
          var au = new Audio(), auUrl = URL.createObjectURL(f), play = pendingBox.querySelector('#kt-st-play'), len = pendingBox.querySelector('#kt-st-len');
          pendingThumb = auUrl;   // revoked with the chip
          au.preload = 'metadata'; au.src = auUrl;
          var mmss = function (s) { s = Math.max(0, Math.round(s || 0)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };
          au.addEventListener('loadedmetadata', function () { if (isFinite(au.duration)) { len.textContent = mmss(au.duration); } });
          au.addEventListener('timeupdate', function () { if (!au.paused) { len.textContent = mmss(au.currentTime); } });
          au.addEventListener('ended', function () { play.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 1.5v9l7.5-4.5z" fill="currentColor"/></svg>'; if (isFinite(au.duration)) { len.textContent = mmss(au.duration); } });
          play.addEventListener('click', function () { if (au.paused) { au.play().catch(function () {}); play.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 1.5h2.5v9H2.5zM7 1.5h2.5v9H7z" fill="currentColor"/></svg>'; } else { au.pause(); play.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 1.5v9l7.5-4.5z" fill="currentColor"/></svg>'; } });
        }
      }

      var fileInput = target.querySelector('#kt-st-file');
      target.querySelector('#kt-st-photo-btn').addEventListener('click', function () { fileInput.click(); });
      fileInput.addEventListener('change', function () {
        var all = Array.prototype.slice.call(fileInput.files || [], 0, 10);
        fileInput.value = '';
        if (!all.length) { return; }
        setPending(all[0], '📷 ' + all[0].name + (all.length > 1 ? ' + ' + (all.length - 1) + ' more' : ''));
        pendingMore = all.slice(1);
      });

      /* PASTE A SCREENSHOT (Anthony, 2026-11-03). The family composer has taken a pasted image
         since it was built; the colleague one ignored it, and staff swap screenshots more than
         anyone. Ctrl/⌘-V, or a phone keyboard's paste, stages the picture like 📷 does; Send sends
         it with or without words. Text on the clipboard pastes as text, untouched. 10 MB is the
         server's limit (App\Support\ChatAttachments). */
      input.addEventListener('paste', function (e) {
        var items = (e.clipboardData && e.clipboardData.items) || [];
        for (var i = 0; i < items.length; i++) {
          if (items[i].kind !== 'file' || !/^image\//.test(items[i].type)) { continue; }
          var blob = items[i].getAsFile();
          if (!blob) { continue; }
          e.preventDefault();
          if (blob.size > 10 * 1024 * 1024) {
            if (window.KT && KT.toast) { KT.toast('⚠️', 'Picture too large', 'Pasted pictures can be up to 10 MB.', '#DC2626'); }
            return;
          }
          var ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
          setPending(new File([blob], 'screenshot-' + Date.now() + '.' + ext, { type: blob.type }), '📋 Pasted screenshot');
          return;
        }
      });

      // ── voice notes ───────────────────────────────────────────────────────
      /* Same recorder as the family composer above — one microphone experience
         for the whole portal. This one ATTACHES rather than sends: the colleague
         composer lets you put a line of text with the note, so the send button
         stays the thing that sends. It also had no way to hear the recording
         before staging it, which is half of why the shared recorder exists. */
      var micBtn = target.querySelector('#kt-st-mic-btn');
      micBtn.addEventListener('click', async function () {
        if (!(window.KT && KT.recordVoiceNote)) { return; }
        micBtn.disabled = true;
        try {
          var file = await KT.recordVoiceNote({ anchor: micBtn.parentElement, acceptLabel: 'Attach' });
          if (file) { setPending(file, '🎤 Voice note'); }
        } finally {
          micBtn.disabled = false;
        }
      });

      var sendBtn = target.querySelector('#kt-st-send');
      var emBtn = (window.KT && KT.emergency && sendBtn) ? KT.emergency.button() : null;
      if (emBtn) input.parentNode.insertBefore(emBtn, input);   // with the tools; the ➤ stays beside the box
      // The ONE send button: faded until there is something to send (text, a photo or a voice note).
      // The ➤ is faded only while there is nothing to send. It never waits for an earlier send (stChain queues them).
      function syncSend() { if (sendBtn) { sendBtn.disabled = !(input.value || '').trim() && !pendingFile; } }
      input.addEventListener('input', syncSend);
      syncSend();
      function doSend() {
        var text = (input.value || '').trim();
        // An attachment on its own is a message — a photo of the rota needs no caption.
        var file = pendingFile;
        if (!text && !file) { return; }
        var emergency = !!(emBtn && KT.emergency.isOn(emBtn));
        var replyTo = RP() ? RP().peek(rpKey) : null;
        /* WORDS SHOW THE MOMENT YOU TAP, AND THE NEXT ONE CAN FOLLOW AT ONCE (2026-11-03 "a slight delay";
           2026-11-11 "make this real time to send multiple messages quickly"). The box, the photo chip and the
           reply clear on tap and the keyboard stays up; a faded bubble goes in and turns solid when the server
           has it. Sends post one after another in the order they were tapped. A failed one takes its bubble
           away and puts its words (or its photo) back. */
        input.value = '';
        setPending(null);
        if (RP()) RP().clear(rpKey, target);
        if (emBtn) KT.emergency.reset(emBtn);
        syncSend();
        try { input.focus(); } catch (e0) {}
        var item = { el: null };
        var stBody = target.querySelector('#kt-st-body');
        if (stBody) {
          var label = text || (file && /^audio\//.test(file.type || '') ? '🎤 Voice note' : '📷 Photo');
          var wrap = document.createElement('div');   // one node, even when an emergency adds its banner row
          wrap.setAttribute('data-kt-pending', '1');
          wrap.style.opacity = '.6';
          wrap.innerHTML = bubble({ id: 0, mine: true, body: label + (file ? ' · sending…' : ''), attachments: [],
            is_emergency: emergency, at: new Date().toISOString().slice(0, 19).replace('T', ' ') });
          stBody.appendChild(wrap);
          item.el = wrap;
          stBodyDirty = true;
          pinToBottom(stBody);
        }
        stQ.push(item);
        stSendInFlight = true;
        sendBtn.setAttribute('aria-busy', 'true');
        stChain = stChain.then(async function () {
          try {
            if (file) {
              // Multipart, not api(): that helper sets a JSON content-type, which would
              // corrupt a FormData body.
              var fd = new FormData();
              fd.append('body', text);
              fd.append('attachment', file);
              if (emergency) fd.append('emergency', '1');
              if (replyTo) fd.append('reply_to_id', String(replyTo));
              var res = await fetch(apiBase() + threadBase() + '/' + tid + '/send', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
                body: fd,
              });
              if (!res.ok) {
                var err = await res.json().catch(function () { return {}; });
                throw new Error(err.message || ('Upload failed (HTTP ' + res.status + ')'));
              }
            } else {
              var payload = emergency ? { body: text, emergency: true } : { body: text };
              if (replyTo) payload.reply_to_id = replyTo;
              await api('POST', threadBase() + '/' + tid + '/send', payload);
            }
            if (item.el) { item.el.style.opacity = ''; item.el.removeAttribute('data-kt-pending'); }
          } catch (e) {
            // Not sent: take the early bubble away and put the words back, ahead of anything typed since.
            if (item.el && item.el.parentNode) { item.el.parentNode.removeChild(item.el); }
            var back = target.querySelector('#kt-st-input') || input;
            if (text) { back.value = text + (back.value ? ' ' + back.value : ''); }
            if (file && !pendingFile) { setPending(file, /^audio\//.test(file.type || '') ? '🎤 Voice note' : '📷 ' + (file.name || 'Photo')); }
            syncSend();
            if (window.KT && KT.toast) { KT.toast('⚠️', 'Could not send', (e && e.message) || '', '#DC2626'); }
          }
          stQ.splice(stQ.indexOf(item), 1);
          if (stQ.length) { return; }
          // The last one is in: one refresh draws the real messages (ids, photos, ticks) in place of the early ones.
          stSendInFlight = false;
          sendBtn.removeAttribute('aria-busy');
          lastCount = -1;          // force the next paint to show it
          /* Re-assert which thread is open before repainting. paint() returns SILENTLY
             when openThreadId does not match, so anything that reset it — a re-mount of
             the screen while the dock stayed open — made the sender's own message vanish
             from the view even though the server had accepted it. You cannot send into a
             thread you are not looking at, so at this point this IS the open thread. */
          openThreadId = 'staff:' + tid;
          try { await paint(true); } catch (e2) {}
          // If the poll had already given up, bring it back — otherwise the thread
          // renders once and then goes quiet again.
          if (!staffPoll) {
            staffPoll = setInterval(function () {
              if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }
              if (document.hidden) { return; }
              paint(false);
            }, 2500);
          }
        });
      }
      /* SEVERAL PHOTOS AT ONCE (2026-11-13): the words go with the first, then one message per photo, queued in
         the order picked by the same chain every send uses. */
      function doSendAll() {
        var more = pendingMore.slice();
        doSend();
        more.forEach(function (mf) { setPending(mf, '📷 ' + mf.name); doSend(); });
      }
      sendBtn.addEventListener('click', doSendAll);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSendAll(); }
      });
    }

    await paint(true, true);   // a fresh open always draws its own frame and composer
    stopStaffPoll();
    /* Guarded exactly like the family poll below. Without this the interval outlived
       the thread that started it and kept repainting ITS messages into the dock after
       the user had opened somebody else — one person's name over another's words. */
    staffPoll = setInterval(function () {
      if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }
      // Skip a backgrounded tab: nobody is reading it, and the wake below catches up.
      if (document.hidden) { return; }
      paint(false);
    }, 2500);

    /* CATCH UP THE MOMENT THE APP COMES BACK.

       The tick above skips while hidden, and the comment beside it used to promise that
       "openStaffThread's visibility handling catches up when it returns" — there was no
       such handling. The family thread has had a threadWake() on visibilitychange all
       along; the colleague thread never got one.

       On a phone that gap is constant, not rare: the screen locks, you answer a call, you
       swap apps, you pull down the notification shade — each one hides the document, and
       a backgrounded WebView has its timers frozen or throttled to a crawl rather than
       merely slowed. You come back to a thread sitting exactly where you left it, with
       the reply already delivered and invisible. Reported as "the message screen doesn't
       update with the last message sent or received" — on a thread with Safia Ali, which
       is a colleague thread, which is this code path. (Anthony, 2026-09-09) */
    stopStaffWake();
    staffWakeFn = function () {
      if (document.hidden) { return; }
      if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }
      // A frozen interval may also have been dropped outright — put it back.
      if (!staffPoll) {
        staffPoll = setInterval(function () {
          if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }
          if (document.hidden) { return; }
          paint(false);
        }, 2500);
      }
      paint(false);
    };
    document.addEventListener('visibilitychange', staffWakeFn);
  }

  /* ─── Thread view ───────────────────────────────────────────── */
  let threadDocked = false;      // desktop: the open thread lives in the floating dock
  let threadListContainer = null; // the #appMain list container, for closing back to it
  /* Re-opening after a reload: the dock remembers WHICH thread was open, and asks this
     screen to open it again for real. A detached container is passed because the dock is
     the render target on desktop — the container is only used by the non-docked path. */
  (function registerDockOpener() {
    if (!(window.KT && KT.ChatDock && KT.ChatDock.setOpener)) { return; }
    KT.ChatDock.setOpener(function (key) {
      // The dock opens threads without the screen; resolve the role first or this asks
      // the guardian endpoints on a staff account and 404s. See syncRole().
      syncRole();
      var host = document.getElementById('appMain') || document.createElement('div');
      return openThread(key, host);
    });
  })();

  /* The dock's contact list. Registered at MODULE scope, not inside the Messenger
     screen's load() — that only runs when the screen itself renders, so opening a
     chat from a notification or the login pop-out left the list unregistered and the
     panel stuck on "Open Message Centre once". It fetches its own data, and reuses the
     screen's rows when they happen to be there. */
  KT.Chat = KT.Chat || {};
  KT.Chat.conversationsForSwitcher = async function () {
    syncRole();          // module-scope entry point — same reason as the opener above
    var rows = [];
    try {
      var both = await Promise.all([
        api('GET', endpointBase() + '?page=1&per_page=100').catch(function () { return null; }),
        myRole === 'guardian'
          ? api('GET', '/parent/threads').catch(function () { return null; })
          : api('GET', '/provider/team-threads').catch(function () { return null; }),
        /* Archived conversations, so the dock can leave them out. A failure here
           must not hide the inbox -- it just means everything shows. */
        api('GET', '/chat-archive').catch(function () { return null; }),
      ]);
      ((both[0] && both[0].conversations) || []).forEach(function (c) {
        rows.push({
          id: c.id,
          name: (myRole === 'guardian' ? c.centre_name : c.family_name) || 'Conversation',
          preview: c.preview || '',
          unread_count: c.unread_count || 0,
          at: c.last_message_at || '',
          photo: c.photo_url || c.child_photo_url || '',
          presence: c.presence || '',
        });
      });
      // Text conversations (kt-sms-replies.js): people who texted the agency's number, beside the chats.
      if (myRole !== 'guardian' && window.KT && KT.smsTexts && KT.smsTexts.listForDock) {
        (await KT.smsTexts.listForDock()).forEach(function (t) { rows.push(t); });
      }
      if (myRole !== 'guardian' && window.KT && KT.webChats && KT.webChats.enabled && KT.webChats.enabled()) {
        (await KT.webChats.listForDock()).forEach(function (w) {
          rows.push({
            id: 'web:' + w.id,
            name: w.name || w.email || 'Website visitor',
            preview: (w.wants_human ? 'Wants a person · ' : '') + (w.preview || ''),
            /* Unread = a visitor message nobody has opened. "Wants a person" stays in the preview but is not
               a badge: it never clears, so a chat read in full kept its 1 (2026-11-01). */
            unread_count: w.unread ? (Number(w.unread) || 1) : 0,
            at: w.at || '',
            photo: '',
            presence: w.present ? 'online' : '',
            web: true,
          });
        });
      }
      ((both[1] && both[1].threads) || []).forEach(function (t) {
        rows.push({
          id: 'staff:' + t.id,
          // A group row that says only "Toddler Room Team" reads like one more colleague;
          // the member count is what tells you it is a room full of people.
          name: (t.name || 'Colleague') + (t.is_group ? ' · ' + (t.member_count || 0) + ((t.member_count || 0) === 1 ? ' member' : ' members') : ''),
          preview: t.preview || '',
          unread_count: t.unread || 0,
          at: t.at || '',
          photo: t.photo_url || '',
          presence: t.presence || '',
        });
      });
      /* Drop what this person has archived. Without this the row reappeared the
         moment the list refreshed, which is why archiving looked broken. */
      var arch = both[2] || {};
      var archFamily = (arch.family || []).map(String);
      var archStaff = (arch.staff || []).map(String);
      var archWeb = (arch.web || []).map(String);
      var archSms = (arch.sms || []).map(String);
      rows = rows.filter(function (r) {
        var id = String(r.id);
        if (id.indexOf('web:') === 0) { return archWeb.indexOf(id.slice(4)) === -1; }
        if (id.indexOf('sms:') === 0) { return archSms.indexOf(id.slice(4)) === -1; }
        return id.indexOf('staff:') === 0
          ? archStaff.indexOf(id.slice(6)) === -1
          : archFamily.indexOf(id) === -1;
      });

      /* Unread first, then newest first within each group. The list still reads
         chronologically; it just puts what needs answering where the eye lands. */
      rows.sort(function (a, b) {
        var au = (a.unread_count || 0) > 0 ? 1 : 0;
        var bu = (b.unread_count || 0) > 0 ? 1 : 0;
        if (au !== bu) { return bu - au; }
        return String(b.at).localeCompare(String(a.at));
      });
    } catch (e) { /* an empty list is better than a broken dock */ }

    return rows;
  };

  /* A WEBSITE CHAT, OPENED LIKE ANY CONVERSATION (2026-10-30, Anthony: "web site chat on mobile and desktop should be
     the popout style like we have it with the regular"). The thread itself is KT.webChats.mountThread
     (kt-webchat-strip.js) - one renderer for the dock, the phone and the Website chat screen. */
  function openWebThread(id, container) {
    stopStaffPoll();
    closeThreadCleanup();
    var W = window.KT && KT.webChats;
    if (!W || !W.mountThread) { return; }
    if (KT.ChatDock && KT.ChatDock.enabled()) { W.openInDock(id); return; }
    threadListContainer = container;
    var leave = function () { webTabOn = true; renderList(container); };
    var h = W.mountThread(container, id, { onBack: leave, phone: true });
    try { if (window.KT && KT.pushOverlay && h && h.root) { KT.pushOverlay(h.root, function () { h.stop(); leave(); }); } } catch (e) {}
  }

  async function openThread(cid, container) {
    // Report + Block (kt-report-block.js): after a block or unblock, draw this conversation again.
    if (window.KT && KT.reportBlock) KT.reportBlock.onChange = function () { openThread(cid, container); };
    if (String(cid).indexOf('web:') === 0) {
      return openWebThread(parseInt(String(cid).slice(4), 10), container);
    }
    /* A TEXT CONVERSATION (2026-11-02, 'sms:<id>'): the dock's list, a restored dock, opened by KT.smsTexts -
       the dock on a computer, full screen on a phone (kt-sms-replies.js). */
    if (String(cid).indexOf('sms:') === 0) {
      if (window.KT && KT.webChats && KT.webChats.stopDocked) { KT.webChats.stopDocked(); }
      stopStaffPoll();
      closeThreadCleanup();
      if (window.KT && KT.smsTexts) { return KT.smsTexts.open(parseInt(String(cid).slice(4), 10), {}); }
      return;
    }
    if (window.KT && KT.smsTexts && KT.smsTexts.stopDocked) { KT.smsTexts.stopDocked(); }
    // A website chat in the dock stops polling before the dock takes another conversation.
    if (window.KT && KT.webChats && KT.webChats.stopDocked) { KT.webChats.stopDocked(); }
    // "staff:12" goes to the colleague thread; anything else is a family conversation.
    if (String(cid).indexOf('staff:') === 0) {
      return openStaffThread(parseInt(String(cid).slice(6), 10), container);
    }
    // A colleague thread's poll must not keep writing into the dock we are about to
    // fill with a family conversation.
    stopStaffPoll();
    openThreadId = cid;
    threadListContainer = container;
    threadDocked = !!(KT.ChatDock && KT.ChatDock.enabled());
    var target = threadDocked ? KT.ChatDock.contentEl() : container;
    target.innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280;">Loading…</div>';
    if (threadDocked) {
      KT.ChatDock.show('Chat', closeThreadCleanup);
      if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread(cid, 'Chat'); }
    }
    try {
      const data = await api('GET', endpointBase() + '/' + cid);
      // Same race as the colleague path: they may have switched during the fetch.
      if (String(openThreadId) !== String(cid)) { return; }
      renderThread(data, target);
      if (threadDocked) {
        var c0 = data.conversation || {};
        var label0 = (myRole === 'guardian' ? c0.centre_name : c0.family_name) || 'Chat';
        KT.ChatDock.show(label0, closeThreadCleanup);
        // Same key, real title — the pair is written together or not at all.
        if (KT.ChatDock.rememberThread) { KT.ChatDock.rememberThread(cid, label0); }
      }
      // Refresh badge after opening (now zero unread)
      if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
    } catch (e) {
      /* 403 or 404 here means the remembered thread is not reachable by THIS session —
         another agency, or another of the accounts sharing this email. The dock
         restores it on every load, so without this the person meets the same red error
         each refresh for a conversation they cannot open anyway. Forget it and close
         quietly; it comes back when they switch back. */
      if (/\b(403|404)\b/.test(String(e && e.message))) {
        if (KT.ChatDock && KT.ChatDock.forget) { KT.ChatDock.forget(); }
        closeThreadCleanup();
        if (threadDocked && KT.ChatDock && KT.ChatDock.hide) { KT.ChatDock.hide(); }
        else { target.innerHTML = ''; }
        return;
      }
      target.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load thread: ' + escapeHtml(e.message) + '</div>';
    }
  }

  // Stop the open thread's poll + clear state. The dock's × runs this via onClose;
  // it must NOT re-close the dock (the dock is already closing) to avoid recursion.
  /* The element registered with kt-back for the OPEN colleague thread, or null.
     It is the screen CONTAINER, not the thread root: paint() rewrites the thread's
     markup on every poll tick, so a root registered here would be replaced seconds
     later and pruned, and hardware back would quietly stop working. */
  var staffBackEl = null;

  /* The one way out of a colleague thread — used by the ← button AND by Android's
     hardware/gesture back, so the two can never drift apart. */
  function staffLeaveThread() {
    staffBackEl = null;
    stopStaffPoll();
    /* Read BEFORE cleanup: closeThreadCleanup() sets threadDocked = false, so asking
       afterwards always answers "not docked". */
    var wasDocked = threadDocked;
    closeThreadCleanup();
    /* And put the list back. closeThreadCleanup() only resets state; on a phone there
       is no dock, so the thread REPLACED the list in this container and clearing state
       leaves the markup exactly where it was. */
    if (!wasDocked && threadListContainer) { renderList(threadListContainer); }
  }

  function closeThreadCleanup() {
    openThreadId = null;
    if (threadPollTimer) { clearTimeout(threadPollTimer); threadPollTimer = null; }
    threadDocked = false;
  }

  /* KEEP THE NEWEST MESSAGE IN VIEW WHILE THE THREAD IS STILL GROWING.

     `body.scrollTop = body.scrollHeight` once, immediately after innerHTML, is not
     enough. Attachment images are rendered as `<img style="max-width:100%;
     max-height:280px">` with NO width/height attributes, so each one is ZERO pixels
     tall at that moment and expands to as much as 280px when it decodes. A thread with
     three photos therefore grows by up to ~840px AFTER we pinned — and the thread body
     is only about 480px tall on a phone, so the message we were pinning to ends up well
     below the fold.

     That is the second half of "I get the notification but opening the chat the message
     does not appear": it was on screen in a thread of plain text, and pushed under the
     fold in a thread with photos. Flaky by nature — it depends entirely on whether the
     images were already in cache.

     So we re-pin as each image settles, and stop the moment the reader scrolls up:
     yanking somebody back to the bottom while they are reading history would be its own
     bug. NEAR_BOTTOM_PX is the "they are still at the end" tolerance.
     (Anthony, 2026-09-09) */
  var NEAR_BOTTOM_PX = 80;
  function atBottom(el) {
    return (el.scrollHeight - el.clientHeight - el.scrollTop) <= NEAR_BOTTOM_PX;
  }

  /* "STICK TO THE BOTTOM" IS A USER INTENT, NOT A MEASUREMENT.

     My first attempt re-pinned only when the body still measured as near the bottom,
     which sounds right and is exactly wrong. The image decodes ABOVE the newest message,
     so the instant it takes up space the distance-from-bottom jumps by its full height —
     280px here, far past any sane tolerance — and the guard then concludes the reader has
     scrolled away and declines to re-pin. Measured: the listener fired, and the last
     message still sat 280px below the fold. The growth is what we are compensating for,
     so it cannot also be the thing that vetoes the compensation.

     So the flag is only ever cleared by a real gesture — wheel, touch drag, or a
     navigation key. Content growing never clears it; a reader scrolling up to read
     history always does, and they are then left alone. */
  function stickOn(el) { try { el.dataset.ktStick = '1'; } catch (e) {} }
  function sticking(el) { return el.dataset.ktStick !== '0'; }
  function wireStick(el) {
    if (el.dataset.ktStickWired === '1') { return; }
    el.dataset.ktStickWired = '1';
    var reassess = function () {
      // After the gesture settles: at the end still means "follow along".
      setTimeout(function () { el.dataset.ktStick = atBottom(el) ? '1' : '0'; }, 80);
    };
    el.addEventListener('wheel', reassess, { passive: true });
    el.addEventListener('touchmove', reassess, { passive: true });
    el.addEventListener('keydown', reassess);
  }

  /* PIN, THEN KEEP PINNING UNTIL THE BOX STOPS CHANGING SIZE.

     One synchronous `scrollTop = scrollHeight` right after innerHTML is not enough on a
     phone, and this is why the thread opened at the TOP there while desktop was fine.

     On a phone the thread is turned into a full-screen panel by a CSS rule keyed on
     `body:has(.kt-thread-compose)` — position:fixed, height:100dvh — and `:has()`
     restyling is invalidated lazily. At the instant we pin, the container can still be
     auto-height, which means the message body is exactly as tall as its content and has
     NO overflow at all: scrollHeight === clientHeight, so `scrollTop = scrollHeight`
     clamps to 0 and is silently a no-op. A beat later the rule lands, the container
     becomes 100dvh, the body is suddenly 500px tall holding 4000px of messages — and the
     scroll is still sitting at 0. You open a 52-message thread and read the messages from
     August, with today's replies far below the fold. Reported as "the chat doesn't update
     and doesn't show Safia's replies": everything was there, just 4000px down.

     So the pin repeats while the geometry is still moving — a ResizeObserver for the
     container finally getting its height, a few bounded frames for everything else, and
     the image listeners below for attachments that decode late. All of it defers to
     `sticking`, so a reader who scrolls up is never dragged back. (Anthony, 2026-09-09) */
  function pinToBottom(el) {
    if (!el) { return; }
    wireStick(el);
    stickOn(el);

    var settle = function () {
      if (!el.isConnected || !sticking(el)) { return; }
      el.scrollTop = el.scrollHeight;
    };
    settle();

    /* The container gaining its real height changes THIS element's box, which is exactly
       what a ResizeObserver on it reports. One per element, kept for the element's life. */
    if (!el.__ktRO && typeof ResizeObserver === 'function') {
      try {
        el.__ktRO = new ResizeObserver(function () { settle(); });
        el.__ktRO.observe(el);
      } catch (e) {}
    }

    /* Belt and braces for engines where the restyle lands without a resize we observe.
       Bounded and short — this is only about the first moments after a render.
       rAF is paired with timers deliberately: rAF does not fire in a background tab. */
    try { requestAnimationFrame(function () { settle(); requestAnimationFrame(settle); }); } catch (e) {}
    setTimeout(settle, 0);
    setTimeout(settle, 60);
    setTimeout(settle, 200);
    setTimeout(settle, 500);

    var imgs = el.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) {
      var im = imgs[i];
      if (im.complete || im.dataset.ktPinned === '1') { continue; }
      im.dataset.ktPinned = '1';
      im.addEventListener('load', settle, { once: true });
      im.addEventListener('error', settle, { once: true });
    }
  }

  /* READ-ONLY HISTORY (2026-10-09, App\Support\MessagingRules). When the agency's Message
     Centre rules no longer let this person write in a conversation, it stays readable but
     the composer is replaced by one line saying why. The server refuses a send anyway. */
  function applyReadOnly(root, reason) {
    if (!root) return;
    root.querySelectorAll('.kt-thread-compose, .kt-also-row, .kt-attach-preview').forEach(function (el) { el.style.display = 'none'; });
    if (root.querySelector('.kt-ro-banner')) return;
    var b = document.createElement('div');
    b.className = 'kt-ro-banner';
    b.setAttribute('role', 'note');
    b.style.cssText = 'flex:0 0 auto;padding:10px 14px;border-top:1px solid #E5E7EB;background:#F8FAFC;color:#475569;font-size:13px;line-height:1.45;display:flex;gap:8px;align-items:flex-start;';
    b.innerHTML = '<span aria-hidden="true">🔒</span><span>' + escapeHtml(reason || 'This conversation is read-only.') + '</span>';
    var compose = root.querySelector('.kt-thread-compose');
    if (compose && compose.parentNode) compose.parentNode.insertBefore(b, compose); else root.appendChild(b);
  }

  async function openTextEmail(c) {
    const M = window.KT && KT.Shell && KT.Shell.Modal;
    if (!M) return;
    let d;
    try { d = await api('GET', '/provider/chats/' + c.id + '/parent-contacts'); }
    catch (e) { if (KT.toast) KT.toast('⚠️', 'Could not open', (e && e.message) || '', '#DC2626'); return; }
    const gs = d.guardians || [];
    const max = d.sms_max || 480;
    const box = document.createElement('div');
    box.innerHTML = '<div style="font-size:12.5px;color:#64748B;line-height:1.5;margin:-2px 0 10px;">Sent from the agency\'s number and email, not yours. Replies go to the agency.'
      + (d.sms_thread ? ' This conversation is already a text conversation, so its messages reach the parent\'s phone anyway.' : '') + '</div>'
      + '<div style="font-size:11.5px;font-weight:800;letter-spacing:.4px;color:#334155;text-transform:uppercase;margin-bottom:4px;">To</div>'
      + (gs.length ? gs.map((g) => '<label style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;border-top:1px solid #F1F5F9;cursor:pointer;">'
          + '<input type="checkbox" data-g="' + g.user_id + '" checked style="margin-top:3px;">'
          + '<span style="flex:1;min-width:0;"><b style="font-size:13.5px;color:#0F172A;">' + escapeHtml(g.name) + '</b>'
          + '<span style="font-size:12px;color:#64748B;"> · ' + escapeHtml(g.relationship || 'Parent') + '</span><br>'
          + '<span style="font-size:12px;color:' + (g.sms_ok ? '#047857' : '#B45309') + ';">📱 ' + (g.sms_ok ? 'Can be texted' : escapeHtml(g.sms_note || 'Cannot be texted')) + '</span>'
          + '<span style="font-size:12px;color:' + (g.email_ok ? '#047857' : '#B45309') + ';"> · ✉️ ' + (g.email_ok ? 'Can be emailed' : 'No email on file') + '</span></span></label>').join('')
        : '<div style="color:#64748B;font-size:13px;">No parents with an account in this family.</div>')
      + '<div style="display:flex;gap:8px;margin:12px 0 8px;flex-wrap:wrap;">'
      + ['sms', 'email', 'all'].map((k) => '<button type="button" data-ch="' + k + '" style="height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-size:13px;font-weight:700;cursor:pointer;">'
          + (k === 'sms' ? '📱 Text' : k === 'email' ? '✉️ Email' : 'All') + '</button>').join('')
      + '</div>'
      + '<textarea data-body rows="5" placeholder="Write your message…" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:9px 11px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div data-count style="font-size:11.5px;color:#64748B;text-align:right;margin-top:3px;"></div>'
      + '<label style="display:flex;gap:7px;align-items:center;font-size:13px;color:#334155;margin-top:6px;cursor:pointer;"><input type="checkbox" data-post> Also post it in this Message Centre conversation</label>'
      + '<div data-out style="font-size:13px;margin-top:8px;"></div>';
    const chosen = new Set(gs.some((g) => g.sms_ok) ? ['sms'] : ['email']);
    const ta = box.querySelector('[data-body]'), cnt = box.querySelector('[data-count]'), out = box.querySelector('[data-out]');
    function paint() {
      box.querySelectorAll('[data-ch]').forEach((b) => {
        const k = b.getAttribute('data-ch');
        const on = k === 'all' ? chosen.size === 2 : chosen.has(k);
        b.style.background = on ? '#159FB4' : '#fff'; b.style.color = on ? '#fff' : '#334155'; b.style.borderColor = on ? '#159FB4' : '#CBD5E1';
      });
      const n = (ta.value || '').trim().length;
      cnt.textContent = n + (chosen.has('sms') ? ' / ' + max + ' (text)' : ' characters');
      cnt.style.color = chosen.has('sms') && n > max ? '#B91C1C' : '#64748B';
    }
    box.querySelectorAll('[data-ch]').forEach((b) => b.addEventListener('click', () => {
      const k = b.getAttribute('data-ch');
      if (k === 'all') { if (chosen.size === 2) { chosen.clear(); chosen.add('email'); } else { chosen.add('sms'); chosen.add('email'); } }
      else if (chosen.has(k)) { if (chosen.size > 1) chosen.delete(k); } else chosen.add(k);
      paint();
    }));
    ta.addEventListener('input', paint); paint();
    let done = false;
    M.open({
      title: '📱✉️ Text / Email — ' + (d.family_name || c.family_name || 'family'), body: box,
      actions: [
        { label: 'Close' },
        { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
            if (done) return;
            const ids = [].map.call(box.querySelectorAll('[data-g]:checked'), (x) => +x.getAttribute('data-g'));
            const body = (ta.value || '').trim();
            out.style.color = '#B91C1C';
            if (!ids.length) { out.textContent = 'Choose at least one parent.'; return false; }
            if (body.length < 2) { out.textContent = 'Write a message.'; ta.focus(); return false; }
            if (chosen.has('sms') && body.length > max) { out.textContent = 'A text can be at most ' + max + ' characters — shorten it or send by email only.'; return false; }
            const post = !!box.querySelector('[data-post]').checked;
            return api('POST', '/provider/chats/' + c.id + '/text-email', { guardian_ids: ids, channels: [...chosen], body: body, post_in_thread: post }).then((r) => {
              const res = (r && r.results) || [];
              out.style.color = '#0F172A';
              out.innerHTML = res.map((x) => {
                const ok = x.status === 'sent';
                return '<div style="color:' + (ok ? '#047857' : '#B45309') + ';">' + (ok ? '✓ ' : '• ') + escapeHtml(x.name || '') + ' — ' + (x.channel === 'sms' ? 'text' : 'email') + ': '
                  + (ok ? 'sent' : escapeHtml(x.status) + (x.note ? ' (' + escapeHtml(x.note) + ')' : '')) + '</div>';
              }).join('') + (post && r.message ? '<div style="color:#047857;">✓ Posted in this conversation</div>' : '');
              done = true;
              if (KT.toast) KT.toast('💬', r.sent ? 'Sent' : 'Nothing was sent', r.sent ? (r.sent + ' text/email sent') : 'See the reasons in the window', r.sent ? '#16A34A' : '#B45309');
              return false;
            }).catch((e) => { out.style.color = '#B91C1C'; out.textContent = (e && e.message) || 'Could not send.'; return false; });
          } },
      ],
    });
  }

  function renderThread(data, container) {
    const c = data.conversation || {};
    const messages = data.messages || [];
    const headerLabel = myRole === 'guardian' ? c.centre_name : c.family_name;
    const subLabel = c.child_name ? '· ' + c.child_name : '';

    container.innerHTML = `
      <div style="display:flex;flex-direction:column;height:calc(100vh - 110px);max-height:calc(100vh - 110px);background:#F9FAFB;">
        <div class="kt-thread-header" style="padding:14px 16px;border-bottom:1px solid rgba(31,96,128,.12);background:linear-gradient(135deg,#EAF3FB,#F3F0FF);display:flex;align-items:center;gap:10px;flex-shrink:0;">
          <button class="kt-back" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:#1F6080;padding:4px 4px;flex-shrink:0;">←</button>
          ${(window.KT && KT.avatar && c.child_name) ? `<span style="flex-shrink:0;display:inline-flex;">${KT.avatar(c.child_name, { size: 38, photoUrl: c.child_photo_url || '' })}</span>` : ''}
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(headerLabel)} ${escapeHtml(subLabel)}</div>
            <div style="font-size:13px;color:#6B7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(c.subject || (c.child_name ? 'About ' + c.child_name : 'Conversation'))}</div>
          </div>
          ${myRole !== 'guardian' ? `<button class="kt-textemail" type="button" title="Text or email the parents" style="background:rgba(31,96,128,.12);border:none;height:40px;padding:0 12px;border-radius:20px;font-size:13px;font-weight:800;color:#1F6080;cursor:pointer;flex-shrink:0;white-space:nowrap;">📱✉️ Text / Email</button>` : ''}
        </div>
        <div class="kt-thread-body" data-kt-scroll="1" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;">
          ${messages.map(m => bubble(m)).join('')}
        </div>
        <div class="kt-attach-preview" style="display:none;padding:8px 12px;border-top:1px solid #E5E7EB;background:#F9FAFB;flex-shrink:0;">
          <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;background:white;border:1px solid #E5E7EB;border-radius:10px;font-size:12px;">
            <span class="kt-attach-name" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            <button class="kt-attach-remove" style="background:none;border:none;color:#DC2626;cursor:pointer;font-size:14px;line-height:1;">✕</button>
          </div>
        </div>
        <div class="kt-rp-slot" style="flex-shrink:0;"></div>
        <div class="kt-thread-compose kt-compose-row" data-kt-nudge="${endpointBase()}/${c.id}/nudge" style="padding:10px;border-top:1px solid #E5E7EB;background:white;display:flex;gap:6px;flex-shrink:0;align-items:center;position:relative;">
          <input class="kt-attach-input" type="file" accept="image/jpeg,image/jpg,image/png,image/webp,image/gif" multiple style="display:none;" />
          <button class="kt-emoji-btn" type="button" title="Emoji" style="background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;">😊</button>
          <button class="kt-attach-btn" type="button" title="Attach a photo or video" style="background:transparent;border:none;color:#6B7280;cursor:pointer;font-size:22px;padding:4px 6px;">📷</button>
          <!-- Mic sits with emoji + camera (transparent, no teal). The send arrow is always
               beside the box, faded until there is something to send (like the text popup). -->
          <button class="kt-mic-btn" type="button" title="Record a voice note" style="background:transparent;border:none;cursor:pointer;font-size:22px;padding:4px 6px;">🎤</button>
          <input class="kt-compose-input" type="text" placeholder="Type a message…" style="flex:1;min-width:0;padding:12px 14px;border:1px solid #D1D5DB;border-radius:24px;font-size:15px;font-family:inherit;" />
          <button class="kt-send-btn kt-send-one" type="button" title="Send" aria-label="Send" disabled>➤</button>
          <div class="kt-emoji-panel" style="display:none;position:absolute;bottom:58px;left:8px;background:white;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.16);padding:8px;width:280px;max-height:180px;overflow-y:auto;z-index:40;font-size:23px;line-height:1.5;"></div>
        </div>
      </div>
    `;
    const body = $('.kt-thread-body', container);
    pinToBottom(body);          // survives images that decode after this line
    if (data.read_only) applyReadOnly(container, data.read_only_reason);

    // Delete own messages — delegated so it also catches poll-appended bubbles.
    body.addEventListener('click', async function (e) {
      var db = e.target && e.target.closest && e.target.closest('.kt-msg-del');
      if (!db) return;
      var mid = db.getAttribute('data-del-mid');
      if (!mid || !await KT.confirm('Delete this message?')) return;
      db.disabled = true;
      api('DELETE', endpointBase() + '/' + c.id + '/messages/' + mid).then(function () {
        var row = body.querySelector('[data-mid="' + mid + '"]');
        if (row) row.outerHTML = '<div data-mid="' + mid + '" style="display:flex;justify-content:flex-end;gap:8px;align-items:flex-end;"><div style="max-width:78%;padding:9px 13px;border-radius:16px;background:#EEF1F5;color:#64748B;font-style:italic;font-size:13.5px;">🚫 Message deleted</div></div>';
      }).catch(function (err) {
        db.disabled = false;
        if (window.KT && KT.toast) KT.toast('⚠️', 'Could not delete', (err && err.message) || '', '#DC2626'); else alert('Could not delete the message.');
      });
    });

    // React to a message: tapping an existing chip toggles that reaction; 😊 opens
    // a quick picker. Both POST to the react endpoint and re-render the chips.
    var REACT_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    function applyReactions(mid, reactions) {
      var row = body.querySelector('[data-mid="' + mid + '"]');
      if (!row) return;
      var html = (reactions && reactions.length) ? reactions.map(function (rx) {
        return '<button type="button" class="kt-msg-react-chip" data-react-mid="' + mid + '" data-emoji="' + escapeHtml(rx.emoji) + '" style="border:1px solid ' + (rx.mine ? '#1F6080' : '#E2E8F0') + ';background:' + (rx.mine ? '#EFF6FF' : '#fff') + ';border-radius:10px;padding:0 6px;font-size:11.5px;cursor:pointer;line-height:1.55;display:inline-flex;align-items:center;white-space:nowrap;">' + escapeHtml(rx.emoji) + ' ' + rx.count + '</button>';
      }).join('') : '';
      /* The span already sits in the meta row of every rendered bubble, so the normal
         path is simply to fill it. Reacting must never move the message. */
      var existing = row.querySelector('.kt-msg-reactions');
      if (existing) { existing.innerHTML = html; return; }
      if (!html) return;
      // Older markup with no span: put it in the meta row, still inline - never
      // appended underneath the bubble.
      var meta = row.querySelector('div[style*="font-size:10.5px"]');
      if (!meta) return;
      var wrap = document.createElement('span');
      wrap.className = 'kt-msg-reactions';
      wrap.innerHTML = html;
      meta.appendChild(wrap);
    }
    function toggleReaction(mid, emoji) {
      api('POST', endpointBase() + '/' + c.id + '/messages/' + mid + '/react', { emoji: emoji })
        .then(function (r) { applyReactions(mid, r && r.reactions); })
        .catch(function (err) { if (window.KT && KT.toast) KT.toast('⚠️', 'Could not react', (err && err.message) || '', '#DC2626'); });
    }
    body.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      var chip = e.target.closest('.kt-msg-react-chip');
      if (chip) { toggleReaction(chip.getAttribute('data-react-mid'), chip.getAttribute('data-emoji')); return; }
      var rb = e.target.closest('.kt-msg-react');
      if (!rb) return;
      var openPop = document.querySelector('.kt-msg-react-pop'); if (openPop) openPop.remove();
      var mid = rb.getAttribute('data-react-mid');
      var pop = document.createElement('div');
      pop.className = 'kt-msg-react-pop';
      pop.style.cssText = 'position:fixed;background:#fff;border:1px solid #E5E7EB;border-radius:20px;box-shadow:0 6px 20px rgba(0,0,0,.16);padding:4px 6px;display:flex;gap:2px;z-index:100000;';
      pop.innerHTML = REACT_EMOJI.map(function (em) {
        return '<button type="button" class="kt-react-pick" data-emoji="' + em + '" style="background:none;border:none;font-size:20px;cursor:pointer;padding:2px 5px;border-radius:8px;line-height:1;">' + em + '</button>';
      }).join('');
      var rect = rb.getBoundingClientRect();
      pop.style.left = Math.max(6, Math.min(rect.left - 40, window.innerWidth - 230)) + 'px';
      pop.style.top = Math.max(6, rect.top - 46) + 'px';
      pop.querySelectorAll('.kt-react-pick').forEach(function (pb) {
        pb.addEventListener('click', function () { toggleReaction(mid, pb.getAttribute('data-emoji')); pop.remove(); });
      });
      document.body.appendChild(pop);
      closeOnOutsideTap([pop, rb], function () { pop.remove(); return true; });
    });

    function leaveThread() {
      if (threadDocked && KT.ChatDock) { KT.ChatDock.close(); return; }  // close() → onClose → closeThreadCleanup
      openThreadId = null;
      if (threadPollTimer) { clearTimeout(threadPollTimer); threadPollTimer = null; }
      renderList(container);
    }

    // Register the open thread as a "back overlay" so the Android hardware /
    // gesture back button (and the ← button) returns to the conversation LIST
    // instead of exiting Messages to the previous screen. The thread root leaves
    // the DOM when renderList runs, so kt-back auto-prunes this entry.
    try {
      var threadRoot = container.firstElementChild;
      if (window.KT && KT.pushOverlay && threadRoot) KT.pushOverlay(threadRoot, leaveThread);
    } catch (e) {}

    $('.kt-back', container).addEventListener('click', leaveThread);

    /* 👋 NUDGE: beside the ＋ in the composer now, for families too (kt-compose-plus.js + App\\Support\\ChatNudge,
       2026-11-04). It was a staff-only header button. */
    /* TEXT / EMAIL — A SEPARATE FUNCTION AND POPUP (2026-10-10, Anthony: "this needs to be
       seperated out from within the chat popup into a seperate function and popup"). The
       composer below only posts Message Centre messages. */
    const teBtn = $('.kt-textemail', container);
    if (teBtn) teBtn.addEventListener('click', () => openTextEmail(c));
    const input = $('.kt-compose-input', container);
    const send = $('.kt-send-btn', container);
    const rpKey = 'fam:' + c.id;
    if (RP()) RP().wire(container, { key: rpKey, input: function () { return $('.kt-compose-input', container); } });
    // 🚨 Emergency toggle (kt-emergency.js): this one message rings on their phones.
    const emBtn = (window.KT && KT.emergency && send) ? KT.emergency.button() : null;
    if (emBtn) input.parentNode.insertBefore(emBtn, input);   // with the tools; the ➤ stays beside the box
    let pendingFile = null;   // staged image/voice attachment (declared early so paintAction can read it)

    // The ➤ is always beside the box (THE ONE COMPOSER ROW, kt-chat-composer.css): faded while there is nothing to
    // send, live once there is text OR a staged image / voice note (a pasted screenshot needs no caption). The mic
    // stays with the tools. (It used to hide the arrow and swap in the mic, so an empty box had no arrow at all.)
    const micToggle = $('.kt-mic-btn', container);
    const paintAction = () => {
      const typing = !!(input.value || '').trim() || !!pendingFile;
      if (send) {
        send.style.removeProperty('display');
        send.disabled = !typing;   // never waits on an earlier send: they queue (doSend)
      }
      if (micToggle) micToggle.style.removeProperty('display');
    };
    input.addEventListener('input', paintAction);
    input.addEventListener('focus', paintAction);   // a draft put back by script fires no 'input'
    paintAction();

    // Typing ping — tell the server we're typing, throttled to once / 2.5s so a
    // burst of keystrokes is a single request. The other side sees it via the
    // thread poll's typing_users.
    let lastTypingPing = 0;
    const pingTyping = () => {
      const now = Date.now();
      if ((now - lastTypingPing) < 2500) return;
      lastTypingPing = now;
      api('POST', endpointBase() + '/' + c.id + '/typing').catch(() => {});
    };
    input.addEventListener('input', () => { if ((input.value || '').trim()) pingTyping(); });
    const attachBtn = $('.kt-attach-btn', container);
    const attachInput = $('.kt-attach-input', container);
    const attachPreview = $('.kt-attach-preview', container);
    const attachName = $('.kt-attach-name', container);
    const attachRemove = $('.kt-attach-remove', container);

    let pendingMore = [];   // more photos picked with it; each goes as its own message (2026-11-13)
    function setPending(file) {
      pendingFile = file;
      pendingMore = [];
      if (file) {
        attachName.textContent = '📎 ' + file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
        attachPreview.style.display = 'block';
      } else {
        attachPreview.style.display = 'none';
        attachName.textContent = '';
        attachInput.value = '';
      }
      paintAction();   // reveal/hide the send button when an attachment is staged/cleared
    }
    attachBtn.addEventListener('click', () => attachInput.click());
    attachInput.addEventListener('change', () => {
      const all = Array.prototype.slice.call(attachInput.files || [], 0, 10);
      if (!all.length) return;
      for (const f of all) {
        if (f.size > 5 * 1024 * 1024) { alert('“' + f.name + '” is over 5 MB. Images must be under 5 MB.'); attachInput.value = ''; return; }
        if (!/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { alert('“' + f.name + '”: only JPG, PNG, WEBP, or GIF.'); attachInput.value = ''; return; }
      }
      setPending(all[0]);
      pendingMore = all.slice(1);
      if (pendingMore.length) attachName.textContent += ' + ' + pendingMore.length + ' more';
    });
    // Paste a screenshot / image straight into the compose box (Ctrl/⌘-V).
    input.addEventListener('paste', (e) => {
      const items = (e.clipboardData && e.clipboardData.items) || [];
      for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
          const blob = items[i].getAsFile();
          if (!blob) continue;
          if (blob.size > 5 * 1024 * 1024) { alert('Pasted image must be under 5 MB.'); return; }
          const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
          const file = new File([blob], 'pasted-' + Date.now() + '.' + ext, { type: blob.type });
          setPending(file);
          e.preventDefault();
          if (window.KT && KT.toast) KT.toast('📎', 'Image pasted', 'Press send to share it.', '#159FB4');
          return;
        }
      }
    });
    attachRemove.addEventListener('click', () => setPending(null));

    /* SEND AS FAST AS YOU TYPE (2026-11-11, Anthony: "when chat messages are sent there is a pause before it acutally
       gets sent - can you make this real time to send multiple messages quickly"). The box used to lock until the
       server answered. Now the words, photo chip, reply and emergency flag clear on tap, a faded bubble goes in at once
       and turns into the real message when the server has it, and the next message can follow straight away: sends
       queue (famChain) and post in the order they were tapped. A failed send takes its bubble away and puts its words
       (or its photo) back in the box. */
    let famChain = Promise.resolve();
    let famPending = 0;
    const doSend = () => {
      const body = input.value.trim();
      const file = pendingFile;
      if (!body && !file) return;
      const emergency = !!(emBtn && KT.emergency.isOn(emBtn));
      const replyTo = RP() ? RP().peek(rpKey) : null;
      input.value = '';
      setPending(null);
      if (RP()) RP().clear(rpKey, container);
      if (emBtn) KT.emergency.reset(emBtn);
      paintAction();
      try { input.focus(); } catch (e0) {}
      const bodyEl = $('.kt-thread-body', container);
      let ghost = null;
      if (bodyEl) {
        ghost = document.createElement('div');   // one node, even when an emergency adds its banner row
        ghost.setAttribute('data-kt-pending', '1');
        ghost.style.opacity = '.6';
        ghost.innerHTML = bubble({ id: 0, body: body || ((file && /^audio\//.test(file.type || '')) ? '🎤 Voice note · sending…' : '📷 Photo · sending…'),
          attachments: [], sender_name: getUser().first_name || 'You', is_me: true, is_emergency: emergency,
          created_at: new Date().toISOString().slice(0, 19).replace('T', ' ') });
        bodyEl.appendChild(ghost);
        pinToBottom(bodyEl);
      }
      famPending++;
      send.setAttribute('aria-busy', 'true');
      famChain = famChain.then(async () => {
        try {
          let msg;
          if (file) {
            // v22p17: multipart submission when an attachment is attached
            const fd = new FormData();
            fd.append('body', body);
            fd.append('attachment', file);
            if (emergency) fd.append('emergency', '1');
            if (replyTo) fd.append('reply_to_id', String(replyTo));
            const res = await fetch(apiBase() + endpointBase() + '/' + c.id + '/send', {
              method: 'POST',
              headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' },
              body: fd,
            });
            if (!res.ok) {
              const err = await res.json().catch(() => ({}));
              throw new Error(err.message || ('Upload failed (HTTP ' + res.status + ')'));
            }
            msg = await res.json();
          } else {
            const payload = emergency ? { body: body, emergency: true } : { body: body };
            if (replyTo) payload.reply_to_id = replyTo;
            msg = await api('POST', endpointBase() + '/' + c.id + '/send', payload);
          }
          // The poll may have drawn it already (it saw the message land first): then the early bubble just goes.
          const live = $('.kt-thread-body', container) || bodyEl;
          const drawn = msg && msg.id && live && live.querySelector('[data-mid="' + msg.id + '"]');
          const real = drawn ? '' : bubble({
            id: msg.id,
            body: msg.body,
            attachments: msg.attachments || [],
            sender_id: msg.sender_id,
            sender_name: getUser().first_name || 'You',
            is_me: true,
            is_emergency: emergency,
            /* Your own message, just sent: deletable now, not after a reopen. The poll
               never redraws this bubble (it skips ids already on screen), so without this the 🗑
               only appeared once the thread was opened again. The server still decides. */
            can_delete: true,
            created_at: msg.created_at,
            reply_to_id: msg.reply_to_id || null,
            reply_to: msg.reply_to || null,
          });
          if (ghost && ghost.parentNode) {
            if (real) ghost.insertAdjacentHTML('beforebegin', real);
            ghost.parentNode.removeChild(ghost);
          } else if (real && live) {
            live.insertAdjacentHTML('beforeend', real);
          }
          if (live) pinToBottom(live);   // a photo you just sent has no height yet either
        } catch (e) {
          if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
          if (body) input.value = body + (input.value ? ' ' + input.value : '');
          if (file && !pendingFile) setPending(file);
          // Say why in words, not a browser alert.
          if (window.KT && KT.toast) { KT.toast('⚠️', 'Not sent', (e && e.message) || 'Try again in a moment.', '#DC2626'); }
          else { alert('Could not send: ' + ((e && e.message) || '')); }
        } finally {
          famPending--;
          if (!famPending) send.removeAttribute('aria-busy');
          paintAction();
        }
      });
    };
    /* SEVERAL PHOTOS AT ONCE (2026-11-13): the words go with the first, then one message per photo, queued in the
       order picked by the same famChain every send uses. */
    const doSendAll = () => {
      const more = pendingMore.slice();
      doSend();
      more.forEach((mf) => { setPending(mf); doSend(); });
    };
    send.addEventListener('click', doSendAll);
    input.addEventListener('keypress', (e) => { if (e.key === 'Enter') doSendAll(); });

    // ── Emoji picker ──────────────────────────────────────────
    const emojiBtn = $('.kt-emoji-btn', container);
    const emojiPanel = $('.kt-emoji-panel', container);
    if (emojiBtn && emojiPanel) {
      const EMOJIS = ('😀 😁 😂 🤣 😊 😍 😘 😉 😎 🥰 🤗 🤔 👍 👎 👏 🙏 💪 🎉 ❤️ 🧡 💛 💚 💙 💜 🔥 ⭐ ✨ 🌈 ☀️ 🌙 😢 😭 😴 🤒 🤕 🤧 😷 👶 🍼 🧸 🎈 🚌 🏫 📚 ✏️ 🍎 🥪 🧃 👌 🙌').split(' ');
      emojiPanel.innerHTML = EMOJIS.map(e => '<span class="kt-emoji" role="button" style="cursor:pointer;display:inline-block;padding:2px 4px;">' + e + '</span>').join('');
      emojiBtn.addEventListener('click', (ev) => { ev.stopPropagation(); emojiPanel.style.display = (emojiPanel.style.display === 'none' ? 'block' : 'none'); });
      emojiPanel.addEventListener('click', (ev) => { const s = ev.target.closest('.kt-emoji'); if (!s) return; input.value += s.textContent; input.focus(); paintAction(); });
      closeOnOutsideTap([emojiPanel, emojiBtn], () => { emojiPanel.style.display = 'none'; }, () => emojiPanel.style.display === 'block');
    }

    /* ── Voice notes ──────────────────────────────────────────────────────
       Handed to KT.recordVoiceNote (kt-voice-recorder.js), which owns the
       microphone, the level meter and the review step for every composer in the
       portal.

       This used to run its own MediaRecorder inline, and pressing stop called
       doSend() immediately — the recording was gone before the person who made
       it had heard a second of it. A cough, a false start or a silent microphone
       went to a parent with no way to catch it. Now nothing leaves until they
       have played it back and pressed Send; the promise resolves to null when
       they cancel, and this sends nothing. */
    const micBtn = $('.kt-mic-btn', container);
    if (micBtn) {
      micBtn.addEventListener('click', async () => {
        if (!KT.recordVoiceNote) return;
        micBtn.disabled = true;
        try {
          // The recorder stands in for the composer row the mic button sits on.
          const file = await KT.recordVoiceNote({ anchor: micBtn.parentElement, acceptLabel: 'Send' });
          if (file) { setPending(file); doSend(); }
        } finally {
          micBtn.disabled = false;
        }
      });
    }

    // ── Realtime: poll the OPEN thread, append new messages, alert on incoming ──
    lastThreadMsgId = messages.length ? Math.max.apply(null, messages.map(m => m.id || 0)) : 0;
    if (threadPollTimer) { clearTimeout(threadPollTimer); threadPollTimer = null; }
    /* Last time anything happened in this thread. Seeded to now, so opening a
       conversation starts fast and settles down if nobody speaks. */
    var lastThreadActivity = Date.now();
    var threadPollBusy = false;

    function threadPollDelay() {
      var since = Date.now() - lastThreadActivity;
      if (since < THREAD_ACTIVE_FOR_MS) { return THREAD_POLL_ACTIVE_MS; }
      if (since < THREAD_WARM_FOR_MS) { return THREAD_POLL_WARM_MS; }
      return THREAD_POLL_IDLE_MS;
    }

    var threadTick = async () => {
      if (openThreadId !== c.id) { clearTimeout(threadPollTimer); threadPollTimer = null; return; }
      try {
        const fresh = await api('GET', endpointBase() + '/' + c.id);
        const msgs = (fresh && fresh.messages) || [];
        const bodyEl = $('.kt-thread-body', container);
        if (!bodyEl) return;
        let added = false, gotIncoming = false;
        msgs.forEach(m => {
          if ((m.id || 0) > lastThreadMsgId) {
            if (!bodyEl.querySelector('[data-mid="' + m.id + '"]')) {
              // Above our own early bubbles: they are still on their way, so they stay last.
              const firstPending = bodyEl.querySelector('[data-kt-pending]');
              if (firstPending) firstPending.insertAdjacentHTML('beforebegin', bubble(m)); else bodyEl.insertAdjacentHTML('beforeend', bubble(m));
            }
            lastThreadMsgId = m.id; added = true;
            if (!m.is_me) gotIncoming = true;
          }
        });
        if (added) pinToBottom(bodyEl);
        if (added) { lastThreadActivity = Date.now(); }
        if (gotIncoming) {
          playPing();
          if (window.KT && window.KT.refreshUnreadBadge) window.KT.refreshUnreadBadge();
          if (KT.ChatDock) KT.ChatDock.flashIncoming();   // flash the dock if minimised
        }
        setTyping(bodyEl, (fresh && fresh.typing_users) || []);
      } catch (e) {}
      threadPollBusy = false;
    };

    /* One tick, then schedule the next at whatever the cadence now calls for.
       A hidden tab is skipped rather than polled: nobody is reading it, and the
       catch-up happens the moment it comes back into focus. */
    function scheduleThreadPoll() {
      if (openThreadId !== c.id) { return; }
      threadPollTimer = setTimeout(async function () {
        if (openThreadId !== c.id) { return; }
        if (!document.hidden && !threadPollBusy) {
          threadPollBusy = true;
          await threadTick();
        }
        scheduleThreadPoll();
      }, document.hidden ? THREAD_POLL_IDLE_MS : threadPollDelay());
    }
    scheduleThreadPoll();

    /* Do not make someone wait for the next tick when they have just come back to
       the tab, or when anything in the portal reported a change. */
    var threadWake = function () {
      if (openThreadId !== c.id || document.hidden || threadPollBusy) { return; }
      lastThreadActivity = Date.now();
      threadPollBusy = true;
      threadTick();
    };
    try {
      window.addEventListener('focus', threadWake);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { threadWake(); }
      });
      window.addEventListener('kt:data-changed', threadWake);
    } catch (e) {}

    input.focus();
  }

  // Typing indicator — a violet "other person" bubble with three animated dots,
  // pinned as the last row of the thread body. Matches the parent chat. names is
  // the server's typing_users (others only; the server excludes the caller).
  function setTyping(bodyEl, names) {
    if (!bodyEl) return;
    if (!document.getElementById('kt-chat-typing-style')) {
      const st = document.createElement('style');
      st.id = 'kt-chat-typing-style';
      st.textContent = '@keyframes kt-typedot{0%,60%,100%{opacity:.25;transform:translateY(0);}30%{opacity:1;transform:translateY(-3px);}}';
      document.head.appendChild(st);
    }
    let row = bodyEl.querySelector('.kt-typing-row');
    if (!names || !names.length) { if (row) row.remove(); return; }
    const label = names.length === 1 ? (names[0] + ' is typing') : 'Several people are typing';
    const dot = 'display:inline-block;width:6px;height:6px;border-radius:50%;background:#7C6BB0;';
    const inner =
      '<div style="display:flex;justify-content:flex-start;">' +
        '<div style="background:#EFEAFB;color:#1E1B34;border-radius:16px;border-bottom-left-radius:5px;padding:9px 13px;max-width:78%;">' +
          '<div style="font-size:11px;font-weight:800;color:#7C6BB0;margin-bottom:3px;">' + escapeHtml(label) + '</div>' +
          '<div style="display:flex;gap:4px;align-items:center;height:8px;">' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite;"></span>' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite .2s;"></span>' +
            '<span style="' + dot + 'animation:kt-typedot 1.2s infinite .4s;"></span>' +
          '</div>' +
        '</div>' +
      '</div>';
    if (!row) {
      row = document.createElement('div');
      row.className = 'kt-typing-row';
      row.style.margin = '4px 0';
      bodyEl.appendChild(row);
    } else {
      bodyEl.appendChild(row); // keep it last, below any freshly-appended messages
    }
    row.innerHTML = inner;
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  // v22p74: read receipt — ✓ delivered, ✓✓ (blue) read
  function readReceipt(m) {
    if (m.pending) return ' <span style="opacity:.6;">⌁</span>';
    if (m.read_at) return ' <span title="Seen ' + escapeHtml(formatTime(m.read_at)) + '" style="color:#7DD3FC;font-weight:700;">✓✓</span>';
    return ' <span title="Delivered" style="opacity:.85;">✓</span>';
  }

  /* 🚨 label above an emergency message (KT.emergency, kt-emergency.js). */
  function emergencyRow(mine) {
    return (window.KT && KT.emergency) ? '<div style="display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start') + ';padding:0 38px;margin:6px 0 -4px;">' + KT.emergency.tagHtml() + '</div>' : '';
  }
  function bubble(m) {
    const html = bubbleBase(m);
    return (m.is_emergency && !m.deleted) ? emergencyRow(!!m.is_me) + html : html;
  }
  function bubbleBase(m) {
    if (m.is_system && !m.deleted) {
      return '<div data-mid="' + m.id + '" style="text-align:center;margin:8px 0;">'
        + '<span style="display:inline-block;background:#FFF7E6;color:#92400E;font-size:11.5px;font-weight:700;border-radius:20px;padding:4px 12px;">'
        + escapeHtml(m.body || '') + ' · ' + escapeHtml(formatTime(m.created_at)) + '</span></div>';
    }
    const mine = m.is_me;
    const attachments = Array.isArray(m.attachments) ? m.attachments : [];
    const attachmentsHtml = attachments.map(a => {
      // Attachment URLs come back relative (/storage/...); resolve against the API
      // host so images render and voice notes actually PLAY inline (a relative src
      // 404s against the SPA origin, which is why voice notes didn't play here).
      const url = absPhotoUrl(a.url);
      // Detect the KIND by mime OR type OR file extension. Voice notes sent from the
      // PARENT app store {type:'audio', url} with NO mime — the old mime-only check
      // rendered them as a plain "📎 attachment" link instead of a playable audio
      // element (that's why voice notes "stopped showing" in the staff chat).
      var u = String(a.url || '');
      var isAudio = (a.mime && a.mime.indexOf('audio/') === 0) || a.type === 'audio' || /\.(webm|mp3|m4a|ogg|wav|aac)$/i.test(u);
      var isImage = (a.mime && a.mime.indexOf('image/') === 0) || a.type === 'image' || /\.(jpe?g|png|gif|webp|bmp|heic)$/i.test(u);
      if (isAudio) {
        // Voice note — inline audio player.
        return `<div style="margin:6px 0;">🎤 <audio controls preload="metadata" src="${escapeHtml(url)}" style="max-width:230px;height:38px;vertical-align:middle;"></audio></div>`;
      }
      if (isImage) {
        // Image attachment — render inline as a click-to-zoom thumbnail
        return `<div style="margin:6px 0;"><a href="${escapeHtml(url)}" target="_blank" rel="noopener" style="display:block;"><img data-kt-media-group="chat" src="${escapeHtml(url)}" alt="${escapeHtml(a.name || 'image')}" style="max-width:100%;max-height:280px;border-radius:10px;display:block;background:rgba(0,0,0,.04);"></a></div>`;
      }
      // Non-image fallback (future: pdf, etc.)
      return `<div style="margin:6px 0;"><a href="${escapeHtml(url)}" target="_blank" rel="noopener" style="color:${mine ? '#0E7C90' : '#1F6080'};text-decoration:underline;font-size:13px;">📎 ${escapeHtml(a.name || 'attachment')}</a></div>`;
    }).join('');
    // Same bubbles as the parent app: a pale teal tint for your own messages with
    // dark text, not solid brand-blue with white text. The two chats sat side by
    // side looking like different products, and the solid fill also inherited the
    // agency's brand colour — which on one agency is neon lime.
    // Per-sender colour + avatar so a multi-person thread is easy to follow.
    const col = senderColor(m.sender_name);
    const initial = escapeHtml((String(m.sender_name || '?').charAt(0) || '?').toUpperCase());
    // A real photo when the thread carries one (matches the parent chat), else a
    // coloured initial in the sender's colour.
    let avatar = '';
    if (!mine) {
      // Relative photo paths (/storage/...) resolve against the API host, not the
      // SPA host — the parent chat does the same via absUrl. Without this the photo
      // 404s and every message fell back to a coloured initial.
      const photo = absPhotoUrl(m.sender_photo_url);
      if (photo && window.KT && KT.avatar) {
        avatar = `<span style="flex-shrink:0;align-self:flex-end;display:inline-flex;">${KT.avatar(m.sender_name || '?', { size: 30, photoUrl: photo })}</span>`;
      } else {
        avatar = `<div style="width:30px;height:30px;border-radius:50%;background:${col};color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;align-self:flex-end;">${initial}</div>`;
      }
    }
    if (m.deleted) {
      // A removed message leaves a tombstone (same as the parent chat).
      return `
      <div data-mid="${m.id}" style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};gap:8px;align-items:flex-end;">
        ${avatar}
        <div style="max-width:78%;padding:9px 13px;border-radius:16px;background:#EEF1F5;color:#64748B;font-style:italic;font-size:13.5px;">🚫 Message deleted</div>
      </div>`;
    }
    // Own messages get a small delete control (wired via delegation on the thread body).
    const delBtn = (mine && m.can_delete)
      ? `<button type="button" class="kt-msg-del" data-del-mid="${m.id}" title="Delete message" style="background:none;border:none;cursor:pointer;color:rgba(13,27,42,.4);font-size:12.5px;line-height:1;padding:0 2px;margin-left:6px;">🗑</button>`
      : '';
    /* ⋯ on somebody else's message: report it, or block them (kt-report-block.js, 2026-11-03). */
    const moreBtn = (!mine && !m.deleted && m.sender_id && window.KT && KT.reportBlock)
      ? KT.reportBlock.btnHtml({ kind: 'family', conv: openThreadId, mid: m.id, uid: m.sender_id, name: m.sender_name, blocked: m.blocked })
      : '';
    /* '+' rather than a smiley. A smiley reads as "send a smiley", which is not what
       the control does - it OFFERS a choice. A plus is the universal "add one of
       these", and it stops the button competing with the reactions beside it, which
       are themselves emoji. (Anthony, 2026-09-09) */
    const reactBtn = (RP() && RP().reactBtn) ? RP().reactBtn(m.id, 'kt-msg-react')
      : `<button type="button" class="kt-msg-react" data-react-mid="${m.id}" title="Add a reaction" aria-label="Add a reaction" style="background:none;border:none;cursor:pointer;color:rgba(13,27,42,.45);font-size:15px;font-weight:700;line-height:1;padding:0 2px;margin-left:6px;">＋</button>`;
    /* Reactions live IN the meta row, beside the time - not in a block of their own
       underneath. A separate row cost a whole line of height per reacted message and
       read as a reply to the bubble rather than a mark ON it. Inline, a reaction
       belongs to the message the way a read tick does. */
    const reactionsHtml = (m.reactions && m.reactions.length)
      ? m.reactions.map(function (rx) {
          return `<button type="button" class="kt-msg-react-chip" data-react-mid="${m.id}" data-emoji="${escapeHtml(rx.emoji)}" style="border:1px solid ${rx.mine ? '#1F6080' : '#E2E8F0'};background:${rx.mine ? '#EFF6FF' : '#fff'};border-radius:10px;padding:0 6px;font-size:11.5px;cursor:pointer;line-height:1.55;display:inline-flex;align-items:center;white-space:nowrap;">${escapeHtml(rx.emoji)} ${rx.count}</button>`;
        }).join('')
      : '';
    const rp = RP();
    const rpBtn = rp ? rp.btn(m.id, mine ? 'You' : m.sender_name, m.body, attachments) : '';
    const quote = rp ? rp.quote(m) : '';
    const emo = !quote && !attachmentsHtml && emojiOnly(m.body);   // a lone 😀 shows big (emojiOnly)
    return `
      <div data-mid="${m.id}" style="display:flex;justify-content:${mine ? 'flex-end' : 'flex-start'};gap:8px;align-items:flex-end;">
        ${avatar}
        <div data-kt-rp-bubble="1"${emo ? ' class="kt-emo-bubble"' : (mine ? ' class="kt-msg-mine"' : '')} style="max-width:78%;padding:10px 13px;border-radius:16px;background:${mine ? '#DCF1F4' : '#EFEAFB'};color:${mine ? '#0D1B2A' : '#1E1B34'};${mine ? 'border-bottom-right-radius:5px;' : 'border-bottom-left-radius:5px;'}">
          ${!mine ? `<div style="font-size:11px;font-weight:800;color:${col};margin-bottom:2px;">${escapeHtml(m.sender_name)}</div>` : ''}
          ${quote}
          ${attachmentsHtml}
          ${m.body ? `<div${emo ? ' class="kt-emo-big"' : ''} style="font-size:15px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;">${escapeHtml(emo ? String(m.body).trim() : m.body)}</div>` : ''}
          <!-- One flex line: time, ticks, reactions, +. It WRAPS rather than stacks -
               a short bubble is only ~116px wide, and letting inline content wrap there
               produced a 100px tower of one item per line. -->
          <div style="font-size:10.5px;color:rgba(13,27,42,.5);margin-top:4px;display:flex;flex-wrap:wrap;align-items:center;gap:3px;justify-content:${mine ? 'flex-end' : 'flex-start'};"><span style="white-space:nowrap;">${formatTime(m.created_at)}${mine ? readReceipt(m) : ''}</span>${smsBadge(m)}<span class="kt-msg-reactions" style="display:contents;">${reactionsHtml}</span>${reactBtn}${rpBtn}${delBtn}${moreBtn}</div>
        </div>
      </div>
    `;
  }
  /* A LINE THAT CAME BY TEXT SAYS SO (2026-09-24).

     Anthony: "show up as a chat from the SMS user and allow for a two way chat".

     The thread holds both kinds now - a parent texting from a bus stop and the same
     parent typing in the app - and they are not interchangeable to whoever is
     replying. A text reaches a handset; an in-app message waits for them to open the
     app. Without the mark the reader cannot tell which they are answering, and a reply
     typed here only leaves the building if the parent is opted in.

     In the meta line beside the time, not a banner: it is a fact about the message,
     and a full-width badge per bubble would bury the conversation. */
  function smsBadge(m) {
    if (!m || m.via !== 'sms') { return ''; }
    return '<span title="Arrived as a text message" style="white-space:nowrap;'
      + 'background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;border-radius:999px;'
      + 'padding:0 6px;font-size:9.5px;font-weight:800;letter-spacing:.3px;">SMS</span>';
  }

  // Deterministic colour per participant name — same person, same colour.
  function senderColor(s) {
    var pal = ['#1F6FB2', '#0FA3B1', '#E0699A', '#7C3AED', '#F59E0B', '#10B981', '#EF6C4D', '#0891B2', '#DB2777', '#4F8A3D'];
    s = String(s || ''); var h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return pal[h % pal.length];
  }

  /* ─── Unread badge polling ─────────────────────────────────── */
  async function refreshUnreadBadge() {
    if (!token()) return;
    try {
      // One answer for every badge (kt-badges.js, 2026-10-30): no request of its own when it is there.
      const data = (window.KT && window.KT.badges)
        ? await window.KT.badges.get().then(function (b) { return b ? { unread: +b.chats || 0 } : {}; })
        : await api('GET', '/chats/unread-count');
      // Sound + vibrate alert when a new unread arrives (not on first load).
      if (lastUnread !== null && typeof data.unread === 'number' && data.unread > lastUnread) {
        playPing();
      }
      lastUnread = (typeof data.unread === 'number') ? data.unread : lastUnread;
      const badge = $('#kt-chat-nav-badge');
      if (badge) {
        if (data.unread > 0) {
          badge.textContent = data.unread;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      }
    } catch (e) {
      // Ignore — endpoint may not exist yet on older deploys
    }
  }

  function startPolling() {
    if (pollTimer) return;
    refreshUnreadBadge();
    pollTimer = setInterval(refreshUnreadBadge, POLL_INTERVAL_MS);
    window.addEventListener('kt:badges', function () { refreshUnreadBadge(); });   // real time
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (threadPollTimer) { clearTimeout(threadPollTimer); threadPollTimer = null; }
  }

  /* ─── Public mount API ─────────────────────────────────────── */
  function mount(container, options) {
    options = options || {};
    syncRole(options.role);   // options.role wins, then view-as, then the account

    /* Do NOT clear openThreadId while a thread is live in the DOCK.
       On desktop an open conversation lives in the floating dock, which deliberately
       outlives this screen — it sits above the whole portal. But paint() and both polls
       key on openThreadId:

           if (openThreadId !== 'staff:' + tid) { stopStaffPoll(); return; }

       so every re-mount of #chat orphaned the conversation that was already open. The
       symptom is precise and misleading: the POST still succeeds and the message is
       saved, but the repaint after it bails silently and the 2.5s poll stops — so the
       thread looks frozen and nothing you type ever appears, while the server has it all
       along. (Anthony, 2026-08-26)

       Cleared only when there is no live dock thread to orphan. */
    var dockLive = false;
    try {
      dockLive = !!(KT.ChatDock && KT.ChatDock.enabled && KT.ChatDock.enabled()
                    && KT.ChatDock.isActive && KT.ChatDock.isActive());
    } catch (e) { dockLive = false; }
    if (!dockLive) { openThreadId = null; }

    renderList(container);
  }

  // Expose
  window.KT = window.KT || {};
  /* openThread is exposed so an incoming message can pop its own conversation open
     (kt-toasts calls it). It needs a container; the dock is the right home on desktop,
     and mount() has already put one there whenever Message Centre has been visited. */
  /* Preserve anything already on KT.Chat — this used to be a plain assignment,
     which wiped the dock's contact-list provider defined above. */
  window.KT.Chat = Object.assign({}, window.KT.Chat || {}, {
    renderTexts: renderTexts,
    avatarHtml: chatAvatar,
    senderColor: senderColor,
    mount: mount,
    refreshUnreadBadge: refreshUnreadBadge,
    openThread: function (cid) {
      try {
        var host = (window.KT.ChatDock && window.KT.ChatDock.contentEl)
          ? window.KT.ChatDock.contentEl()
          : document.getElementById('appMain');
        if (!host) { location.hash = '#chat'; return; }
        if (window.KT.ChatDock && window.KT.ChatDock.show) { window.KT.ChatDock.show(); }
        return openThread(cid, host);
      } catch (e) {
        location.hash = '#chat';
      }
    },
  });
  window.KT.refreshUnreadBadge = refreshUnreadBadge;

  // Auto-start polling once user is loaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPolling);
  } else {
    startPolling();
  }
  window.addEventListener('storage', (e) => { if (e.key === 'kt_token') startPolling(); });
})(window);
