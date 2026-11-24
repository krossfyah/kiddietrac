/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — notification sounds while the portal is OPEN (2026-10-21).

   Anthony: "does the desktop make a sound when a chat, announcement or any other
   notifications and allow for customizable sounds through the user settings as well?"
   It did not: only floor staff heard one fixed synthesised chime; directors, admins and
   parents heard nothing, and new-message toasts were silent.

   Now every role hears the tone they chose for that kind of notification (Settings ›
   Notifications › Sounds & vibration — the same choices the phone app uses), on a
   desktop browser and inside the app.

   HOW IT HEARS ABOUT THINGS — without adding polling. Several modules already fetch
   /notifications and /chats/unread-count (the bell, the top bar, toasts, the urgent
   alert). This wraps fetch and READS those responses: a notification row newer than any
   seen before, or a rise in unread chats, plays that category's tone. Only when nothing
   has fetched /notifications for 40 s does it poll by itself (every 30 s).

   NEVER TWICE:
   - one tab plays: a leader is elected in localStorage (the tab focused most recently,
     with a heartbeat), and every sound has a key recorded for two minutes;
   - one sound per category per 8 s (a chat makes a notification row AND raises the
     unread count — that is one event);
   - inside the app, a push that just arrived has already rung on the phone
     (noteNative), so the same category stays quiet here for 30 s.

   Browsers refuse to play audio before the person has interacted with the page; until
   then this stays silent rather than throwing. Per device: on/off (kt_snd_on), volume
   (kt_snd_vol 0-100) and "mute while I'm typing in chat" (kt_snd_typing_mute).
   Exposes KT.sounds.

   VIBRATION (2026-10-30, Anthony: "notifications on the phone should also use vibrate including chat messenger
   etc"). The phone app's pushes always vibrated (each category's Android channel), but anything that rang while
   KiddieTrac was OPEN - a chat, an announcement, Safe Arrival - only played a tone. Now the same event also
   vibrates with the pattern the person chose for that category (Settings › Notifications › Sounds & vibration;
   "No vibration" turns it off per category) through navigator.vibrate: Android app (VIBRATE permission) and
   Android Chrome. iPhones have no web vibration - their pushes buzz the iOS way. Per device: kt_vib_on
   ("Vibrate" on the same card; default on). Vibration does not need sound: with sounds off it still buzzes,
   like a phone on silent. The emergency takeover (opts.force) vibrates on its own, so it is skipped here.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (window.__ktSounds) return; window.__ktSounds = true;
  var KT = window.KT || (window.KT = {});

  function apiBase() { return (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1'; }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  function now() { return Date.now(); }

  // ── Device settings ───────────────────────────────────────────────
  /* Inside the website's demo phone (kt-demo-frame.js) the phone's own mute switch and volume rocker decide, kept in
     this tab only - never the visitor's real KiddieTrac settings on this browser. */
  function demoS(k) { if (!window.__KT_DEMO_FRAME) return null; try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function isOn() { if (demoS('kt_demo_mute') === '1') return false; return ls('kt_snd_on') !== '0'; }
  function volume() { var dv = parseInt(demoS('kt_demo_vol'), 10); if (isFinite(dv)) return Math.max(0, Math.min(100, dv)) / 100; var v = parseInt(ls('kt_snd_vol'), 10); return isFinite(v) ? Math.max(0, Math.min(100, v)) / 100 : 0.8; }
  function typingMute() { return ls('kt_snd_typing_mute') === '1'; }
  // The demo phone's silent switch silences sounds, not the motor - a phone on silent still vibrates (2026-10-30).
  function vibOn() { return ls('kt_vib_on') !== '0'; }

  // ── May we make a noise yet? (browser autoplay rule) ──────────────
  var interacted = false;
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (e) {
    // Only a real person counts: the portal itself dispatches synthetic key/pointer events.
    window.addEventListener(e, function (ev) { if (ev && ev.isTrusted === false) return; interacted = true; warm(); }, { capture: true, passive: true });
  });
  function mayPlay() {
    try { if (navigator.userActivation && navigator.userActivation.hasBeenActive) return true; } catch (e) {}
    return interacted;
  }

  // ── Typing in chat ────────────────────────────────────────────────
  var lastChatKey = 0;
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t) return;
    var editable = t.tagName === 'TEXTAREA' || t.isContentEditable || (t.tagName === 'INPUT' && /text|search/.test(t.type || 'text'));
    var sec = (location.hash || '').replace('#', '').split('?')[0];
    if (editable && /chat|message|team|conversation|inbox/i.test(sec + ' ' + (t.closest && t.closest('[class*="chat"],[class*="compose"],[class*="thread"]') ? 'chat' : ''))) lastChatKey = now();
  }, true);
  function typingNow() { return typingMute() && now() - lastChatKey < 5000; }

  // ── One tab plays ─────────────────────────────────────────────────
  var TAB = Math.random().toString(36).slice(2);
  var LEAD = 'kt_snd_leader';
  function leader() { try { return JSON.parse(ls(LEAD) || 'null'); } catch (e) { return null; } }
  function claim() { ls(LEAD, JSON.stringify({ id: TAB, ts: now() })); }
  function isLeader() {
    if (window.__KT_DEMO_FRAME) return true;   // the demo phone is its own device: it rings for its own (demo) account
    var l = leader();
    if (!l || now() - (l.ts || 0) > 12000) { claim(); return true; }   // nobody alive: take it
    return l.id === TAB;
  }
  function onFocus() { if (!document.hidden) claim(); }
  window.addEventListener('focus', onFocus);
  document.addEventListener('visibilitychange', onFocus);
  setInterval(function () { var l = leader(); if (l && l.id === TAB) claim(); }, 4000);
  if (!document.hidden) claim();

  var KEYS = 'kt_snd_keys';
  function seenKey(key) {
    try {
      var m = JSON.parse(ls(KEYS) || '{}'), t = now(), out = {}, hit = false;
      Object.keys(m).forEach(function (k) { if (t - m[k] < 120000) out[k] = m[k]; });
      if (out[key]) hit = true; else out[key] = t;
      ls(KEYS, JSON.stringify(out));
      return hit;
    } catch (e) { return false; }
  }

  // ── The person's choices + the library ────────────────────────────
  var prefs = null, prefsAt = 0, loading = null;
  function loadPrefs() {
    if (prefs && now() - prefsAt < 600000) return Promise.resolve(prefs);
    if (loading) return loading;
    var t = tok(); if (!t) return Promise.resolve(null);
    loading = fetch(apiBase() + '/me/notification-sounds', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        loading = null;
        if (!d) return prefs;
        var urls = {}, vibs = {};
        (d.tones || []).forEach(function (x) { urls[x.key] = x.url; });
        (d.vibrations || []).forEach(function (x) { vibs[x.key] = x.pattern || []; });
        prefs = { choices: d.choices || {}, urls: urls, vibs: vibs };
        prefsAt = now();
        return prefs;
      }).catch(function () { loading = null; return prefs; });
    return loading;
  }
  function refresh() { prefsAt = 0; return loadPrefs(); }

  var cache = {};
  function audioFor(url) {
    if (!cache[url]) { var a = new Audio(url); a.preload = 'auto'; cache[url] = a; }
    return cache[url];
  }
  // After the first interaction, fetch the chosen tones so the first alert is instant.
  var warmed = false;
  function warm() {
    if (warmed || !tok()) return; warmed = true;
    loadPrefs().then(function (p) {
      if (!p) return;
      Object.keys(p.choices).forEach(function (c) { var u = p.urls[p.choices[c].tone]; if (u) { try { audioFor(u).load(); } catch (e) {} } });
    });
  }

  function playUrl(url) {
    if (!url || !mayPlay()) return false;
    try {
      var a = audioFor(url);
      a.volume = volume();
      a.currentTime = 0;
      var pr = a.play();
      if (pr && pr.catch) pr.catch(function () {});
      lastPlayed = now();
      return true;
    } catch (e) { return false; }
  }

  /* The person's pattern for this category. Android patterns start with a wait ([0, 250, 120, 250]);
     navigator.vibrate starts with a buzz, so the leading wait is dropped. Without prefs: the same defaults as
     App\Support\NotificationSounds (chat double tap, Safe Arrival long, emergency insistent). */
  var DEF_VIB = { chat: [250, 120, 250], announcement: [250, 120, 250], staff: [250, 120, 250], general: [250, 120, 250],
    safety: [900, 300, 900], emergency: [900, 300, 900, 300, 1200, 300, 1200],
    headcount: [150, 100, 150, 600, 150, 100, 150] };
  function patternFor(cat, p) {
    var ch = p && p.choices && p.choices[cat];
    var pat = ch && p.vibs && p.vibs[ch.vibration];
    if (!pat) pat = ch && ch.vibration === 'off' ? [] : (DEF_VIB[cat] || [250]);
    pat = pat.slice();
    if (pat.length && pat[0] === 0) pat = pat.slice(1); else if (pat.length && p && p.vibs && ch && p.vibs[ch.vibration]) pat = [0].concat(pat);
    return pat;
  }
  var lastVib = 0;
  function buzz(pattern) {
    if (!pattern || !pattern.length || !navigator.vibrate || !vibOn()) return false;
    try { lastVib = now(); return navigator.vibrate(pattern) !== false; } catch (e) { return false; }
  }

  var lastPlayed = 0, lastByCat = {}, nativeUntil = {};
  function nativeQuiet(cat) { return (nativeUntil['*'] || 0) > now() || (nativeUntil[cat] || 0) > now(); }

  /**
   * Play this category's tone. opts.key de-duplicates across polls and tabs;
   * opts.force skips the 8-second category cooldown (repeating emergency ring).
   */
  function trace(m) { if (window.__ktSoundLog) window.__ktSoundLog.push({ trace: m, at: now() }); }
  function play(cat, opts) {
    opts = opts || {};
    cat = cat || 'general';
    trace('play ' + cat + ' ' + (opts.key || '') + ' on=' + isOn() + ' may=' + mayPlay() + ' lead=' + isLeader());
    if (!isLeader()) return Promise.resolve(false);
    /* The key is spent even when the sound is skipped below (no interaction yet, muted,
       typing): an event that arrived before the person touched the page must not sound
       later, when a stale poll response replays it. */
    if (!opts.force && opts.key && seenKey(opts.key)) return Promise.resolve(false);
    var vib = !opts.force && !opts.noVibrate && vibOn() && !!navigator.vibrate;
    if ((!isOn() && !vib) || !tok() || !mayPlay()) return Promise.resolve(false);
    if (cat !== 'emergency' && (typingNow() || nativeQuiet(cat))) return Promise.resolve(false);
    if (!opts.force && (lastByCat[cat] || 0) > now() - 8000) return Promise.resolve(false);
    lastByCat[cat] = now();
    return loadPrefs().then(function (p) {
      // opts.tone: this one source's own sound (a mailbox's new-mail tone, 2026-11-05), else the category's.
      var tone = (opts.tone && /^[a-z0-9_]{1,40}$/.test(opts.tone) && opts.tone) || (p && p.choices[cat] && p.choices[cat].tone) || 'kt_notify';
      var url = (p && p.urls[tone]) || ('/sounds/' + tone + '.wav');
      var pat = vib ? patternFor(cat, p) : null;
      if (window.__ktSoundLog) window.__ktSoundLog.push({ cat: cat, tone: tone, at: now(), key: opts.key || null, vib: pat });
      var buzzed = vib ? buzz(pat) : false;
      return (isOn() ? playUrl(url) : false) || buzzed;
    });
  }

  // ── Notification type → category (in step with App\Support\NotificationSounds) ──
  var TYPE = {
    chat: 'chat', message: 'chat', team_message: 'chat', sms_reply: 'chat', nudge: 'nudge', call: 'call', call_missed: 'chat',
    announcement: 'announcement', closure: 'announcement', menu: 'announcement', birthday: 'announcement',
    email: 'email',
    checkin: 'checkin', checkin_reminder: 'checkin', children_still_in: 'checkin', late_pickup: 'checkin',
    transfer: 'checkin', attendance: 'checkin',
    safe_arrival: 'safety', absence: 'safety', walk_started: 'safety', walk_ended: 'safety', walk_quiet: 'safety', walk_auto_closed: 'safety', ratio_breach: 'safety',
    immunization_record: 'safety', immunization_reminder: 'safety', incident: 'safety',
    photo: 'photos', child_note: 'photos', care: 'photos', moment: 'photos', observation: 'photos',
    invoice: 'billing', pfcc_copay_unpaid: 'billing', payment_plan: 'billing', payment: 'billing',
    form_correction: 'forms', countersign_reminder: 'forms', family_file: 'forms', form: 'forms',
    clock: 'staff', clock_reminder: 'staff', headcount_due: 'headcount', time_off: 'staff',
    time_off_reminder: 'staff', bio_reminder: 'staff', award: 'staff', records_reminder: 'staff',
  };
  function rowData(n) { var d = n && n.data; try { if (typeof d === 'string') d = JSON.parse(d); } catch (e) { d = null; } return d || {}; }
  function categoryOf(n) {
    var d = rowData(n);
    if (d.emergency === 1 || d.emergency === '1' || d.emergency === true) return 'emergency';
    if (d.kt_category) return String(d.kt_category);
    return TYPE[String(n && n.type || '')] || 'general';
  }

  // ── Reading what other modules fetched ────────────────────────────
  var maxId = null, lastUnread = null, lastNotifSeen = 0;
  function onNotifications(json) {
    var rows = json && (json.data || json.notifications || (Array.isArray(json) ? json : null));
    if (!Array.isArray(rows)) return;
    lastNotifSeen = now();
    var top = 0, newest = null, fresh = [];
    rows.forEach(function (n) {
      var id = parseInt(n && n.id, 10) || 0;
      if (id > top) top = id;
      if (maxId !== null && id > maxId && !n.read_at && (!newest || id > newest.id)) newest = { id: id, row: n };
      if (maxId !== null && id > maxId && !n.read_at) fresh.push(n);
    });
    /* Tell the page about each new row (2026-10-31): the website's demo phone shows them as banners. */
    fresh.sort(function (a, b) { return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0); }).forEach(function (n) {
      try { window.dispatchEvent(new CustomEvent('kt:notification', { detail: { id: n.id, title: n.title, body: n.body, data: n.data, type: n.type, cat: categoryOf(n) } })); } catch (e) {}
    });
    trace('notif top=' + top + ' max=' + maxId + ' newest=' + (newest && newest.id));
    if (maxId === null) { maxId = top; return; }     // first look: baseline, no noise
    if (top > maxId) maxId = top;
    if (newest) {
      var cat = categoryOf(newest.row);
      // The emergency takeover (kt-urgent-alert) rings for itself, repeatedly.
      if (cat === 'emergency' && KT.urgentAlert) return;
      play(cat, { key: 'n' + newest.id, tone: rowData(newest.row).kt_tone || null });
    }
  }
  function onUnread(json) {
    if (!json) return;
    var n = json.unread_count != null ? json.unread_count : (json.count != null ? json.count : json.unread);
    n = parseInt(n, 10); if (!isFinite(n)) return;
    if (lastUnread !== null && n > lastUnread) {
      var sec = (location.hash || '').replace('#', '').split('?')[0];
      // Reading the conversation already: the message is on screen.
      if (!(sec === 'chat' && !document.hidden)) play('chat', { key: 'c' + n });
    }
    lastUnread = n;
  }

  var match = /\/api\/v1\/(notifications(\?[^#]*)?|chats\/unread-count[^#]*)$/;
  var _fetch = window.fetch;
  if (_fetch) {
    window.fetch = function (input, init) {
      var p = _fetch.apply(this, arguments);
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var path = url.split('#')[0];
        if (method === 'GET' && path.indexOf(apiBase()) === 0 && match.test(path.replace(/&?_=[0-9]+/, ''))) {
          var isUnread = /chats\/unread-count/.test(path);
          var isPage = /[?&](page|unread_only|type)=/.test(path) && !/[?&]page=1(&|$)/.test(path);
          p.then(function (r) {
            if (!r || !r.ok) return;
            r.clone().json().then(function (j) {
              if (isUnread) onUnread(j);
              else if (!isPage) onNotifications(j);
            }).catch(function () {});
          }).catch(function () {});
        }
      } catch (e) {}
      return p;
    };
  }

  /* Unread chats from the one badges answer (2026-10-30): the separate /chats/unread-count polls are gone. */
  // Every kind of message (2026-11-01): b.chats alone left staff team messages and website chats silent.
  window.addEventListener('kt:badges', function (e) { var d = e && e.detail; if (d && d.chats != null) onUnread({ unread: (KT.badges && KT.badges.msgs) ? KT.badges.msgs(d) : (+d.chats || 0) }); });
  // Nobody else fetched the notification list lately: look ourselves, slowly. (Not with KT.badges: it fetches the list
  // whenever a new notification exists.)
  setInterval(function () {
    if (KT.badges || !tok() || document.hidden || now() - lastNotifSeen < 40000) return;
    window.fetch(apiBase() + '/notifications', { headers: { 'Authorization': 'Bearer ' + tok(), 'Accept': 'application/json' } }).catch(function () {});
  }, 30000);

  KT.sounds = {
    play: play,
    categoryOf: categoryOf,
    refresh: refresh,
    isOn: isOn, setOn: function (on) { ls('kt_snd_on', on ? '1' : '0'); },
    volume: function () { return Math.round(volume() * 100); },
    setVolume: function (v) { ls('kt_snd_vol', String(Math.max(0, Math.min(100, v | 0)))); },
    typingMute: typingMute, setTypingMute: function (on) { ls('kt_snd_typing_mute', on ? '1' : '0'); },
    /** Vibration on this device (default on), separate from sound. canVibrate: false on iPhone / desktop. */
    vibOn: vibOn, setVibOn: function (on) { ls('kt_vib_on', on ? '1' : '0'); },
    canVibrate: function () { return !!navigator.vibrate; },
    /** Buzz a category's chosen pattern now (incoming call ring, a chat ping drawn by its own screen). */
    vibrate: function (cat) {
      if (!vibOn() || !navigator.vibrate) return Promise.resolve(false);
      return loadPrefs().then(function (p) { return buzz(patternFor(cat || 'general', p)); });
    },
    /** Buzz an explicit pattern (respects the device's Vibrate setting); [] or 0 stops. */
    buzz: function (pattern) { if (pattern === 0 || (pattern && !pattern.length)) { try { navigator.vibrate && navigator.vibrate(0); } catch (e) {} return true; } return buzz(pattern); },
    /** Preview a tone at the device volume (settings ▶), regardless of leader/cooldown. */
    preview: function (url) { if (!url) return false; var ok = mayPlay(); if (!ok) interacted = true; return playUrl(url); },
    /** A native notification just rang for this (app): stay quiet for that category. */
    noteNative: function (cat) { nativeUntil[cat || '*'] = now() + 30000; },
    /** For kt-urgent-alert: did anything play in the last ms? */
    recentlyPlayed: function (ms) { return now() - lastPlayed < (ms || 1500); },
  };
})(window);
