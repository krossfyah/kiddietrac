/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — the outbound dialer (2026-10-23)

   Anthony: "build outbound dialer" — configurable (the platform team by default; an
   agency with its own number chooses who may dial), the 1-855 line as caller ID,
   startable from a dial pad, from sales leads and website chats, and from any phone
   number in the portal, recorded after a spoken notice.

     KT.dialer.call(number, {name, lead_id, chat_id, context})
     #phone                     the Phone screen: dial pad, recent calls, settings
     any phone number shown     becomes a link; tapping it offers "Call with KiddieTrac"

   The call itself: POST /voice/dialer/call; the server rings this portal's own phone
   first (kt-portal-phone.js answers it by itself and shows the "Calling…" card), then
   the person being called. App\Support\Dialer has the whole story.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.__ktDialer) return; w.__ktDialer = true;
  var KT = w.KT = w.KT || {};
  var dial = null;            // {from, label, record, scope} once the phone session says so
  /* THE PHONE ICON IS THERE FROM THE FIRST FRAME (2026-10-29). Anthony: "when the portal
     refreshed and loads the telephone icon at the top bar pops in with a delay". The icon is
     hidden until html.kt-can-dial, which only lands when the phone session answers - a request
     that waits its turn behind the boot reads, so the bar re-laid itself out a second or more
     after it painted. The last answer (per person and agency, on this device) is applied at
     once; the live answer still sets or clears it, so a revoked dial right only lasts a moment. */
  function dialKey() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {};
      var a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id') || '';
      return u.id ? 'kt_can_dial:' + u.id + ':' + a : null;
    } catch (e) { return null; }
  }
  try { var _dk = dialKey(); if (_dk && localStorage.getItem(_dk) === '1') document.documentElement.classList.add('kt-can-dial'); } catch (e) {}

  function api() { return (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function headers(json) {
    var h = { Authorization: 'Bearer ' + tok(), Accept: 'application/json' };
    if (json) h['Content-Type'] = 'application/json';
    try { var a = sessionStorage.getItem('kt_active_agency_id') || localStorage.getItem('kt_active_agency_id'); if (a) h['X-Active-Agency-Id'] = a; } catch (e) {}
    return h;
  }
  async function req(method, path, body) {
    var r = await fetch(api() + path, { method: method, headers: headers(!!body), body: body ? JSON.stringify(body) : undefined });
    var j = await r.json().catch(function () { return {}; });
    if (!r.ok) { var e = new Error(j.message || 'Something went wrong. Please try again.'); e.status = r.status; e.code = j.code; e.match = j.match; throw e; }
    return j;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(icon, title, msg, colour) { try { if (KT.toast) KT.toast(icon, title, msg, colour || '#0E7C90'); } catch (e) {} }
  function digits(s) { var d = String(s || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') d = d.slice(1); return d; }
  function pretty(s) { var d = digits(s); return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : String(s || ''); }
  function valid(s) { return /^[2-9]\d{2}[2-9]\d{6}$/.test(digits(s)); }

  w.addEventListener('kt:phone-session', function (e) {
    dial = (e.detail && e.detail.dial) || null;
    document.documentElement.classList.toggle('kt-can-dial', !!dial);
    try { var k = dialKey(); if (k) { var v = dial ? '1' : '0'; if (localStorage.getItem(k) !== v) localStorage.setItem(k, v); } } catch (e2) {}
    sweepSoon();
  });

  /* ── placing a call ── */
  async function call(number, meta) {
    meta = meta || {};
    if (!dial) { toast('📞', 'Calling is not switched on for you', 'An admin can turn it on in Phone → Settings.', '#B45309'); return; }
    if (!valid(number)) { toast('⚠️', 'Not a number we can call', 'Use a Canadian or US number with its area code.', '#B91C1C'); return; }
    var pp = KT.portalPhone;
    if (!pp) { toast('⚠️', 'The portal phone is not loaded', 'Reload the page and try again.', '#B91C1C'); return; }
    if (pp.state().busy) { toast('📞', 'You are already on a call', 'Hang up first.', '#B45309'); return; }
    // The microphone is asked for now, not in the middle of connecting - and if it is off, the
    // portal phone says how to switch it on (app settings, app update, or the browser).
    if (pp.ensureMic) { if (!(await pp.ensureMic())) return; }
    else {
      try { var st = await navigator.mediaDevices.getUserMedia({ audio: true }); st.getTracks().forEach(function (t) { t.stop(); }); }
      catch (e) { toast('🎙️', 'Microphone needed', 'Allow the microphone, then call again.', '#B91C1C'); return; }
    }
    if (!(await pp.whenReady())) { toast('⚠️', 'The portal phone is not connected', 'Check your internet connection and try again.', '#B91C1C'); return; }
    var leadId = meta.lead_id, chatId = meta.chat_id;
    var h = location.hash || '';
    var lm = /^#sales-lead\?id=(\d+)/.exec(h); if (!leadId && lm) leadId = +lm[1];
    var cm = /^#sales-webchat\?id=(\d+)/.exec(h); if (!chatId && cm) chatId = +cm[1];
    // Who it is, from the contacts book, when the caller did not say.
    if (!meta.name) {
      try { var who = await Promise.race([lookup(number), new Promise(function (ok) { setTimeout(function () { ok(null); }, 1500); })]); if (who) meta.name = who.name; } catch (e) {}
    }
    try {
      var r = await req('POST', '/voice/dialer/call', { flow: 'browser', to: number, name: meta.name || null, lead_id: leadId || null, chat_id: chatId || null,
        context: (meta.context || h.replace('#', '').split('?')[0] || 'phone').slice(0, 80) });
      try { localStorage.setItem('kt_dial_last', JSON.stringify({ n: number, name: meta.name || '' })); } catch (e) {}
      if (!pp.dialOut || !pp.dialOut(r.call)) {
        req('POST', '/voice/dialer/calls/' + r.call.id + '/hangup').catch(function () {});
        toast('⚠️', 'The portal phone is not connected', 'Reload the page and try again.', '#B91C1C');
        return;
      }
      try { w.dispatchEvent(new CustomEvent('kt:dialer-called', { detail: r.call })); } catch (e) {}
    } catch (e) {
      toast('⚠️', 'Call not placed', e.message, '#B91C1C');
    }
  }

  /* WHO IS THIS NUMBER (Anthony, 2026-10-23: "when dialing number and it matches anyone in
     the contacts/phone book - present that persons name"). The contacts book is searched
     by the last four digits and matched on the whole number here, so only what this person
     may already see in Contacts can answer; the platform team also has the sales leads. */
  var lookCache = {};
  async function lookup(number) {
    var d = digits(number);
    if (d.length !== 10) return null;
    if (lookCache[d] !== undefined) return lookCache[d];
    var found = null, last4 = d.slice(-4);
    var match = function (p) { return p && digits(p) === d; };
    try {
      var j = await req('GET', '/contacts?search=' + last4 + '&per_page=100');
      (j.contacts || []).some(function (c) {
        if (match(c.phone) || match(c.mobile)) {
          found = { name: c.display_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.company || '', kind: c.category || c.company || '' };
          return true;
        }
        return false;
      });
    } catch (e) {}
    if (!found && dial && dial.scope === 'platform') {
      try {
        var l = await req('GET', '/sales/leads?q=' + last4);
        (l.leads || []).some(function (x) {
          if (match(x.phone)) { found = { name: x.name || x.company || '', kind: 'Lead' + (x.company && x.company !== x.name ? ' · ' + x.company : '') }; return true; }
          return false;
        });
      } catch (e) {}
    }
    if (found && !found.name) found = null;
    lookCache[d] = found;
    return found;
  }

  /* ── any phone number on screen becomes a link (for people who may dial) ── */
  var PHONE_RX = /(?:\+?1[\s.\-]?)?(?:\(\s*[2-9]\d{2}\s*\)\s*|[2-9]\d{2}[\s.\-])[2-9]\d{2}[\s.\-]\d{4}\b/g;
  var SKIP = 'a,button,input,textarea,select,script,style,code,pre,[contenteditable="true"],[data-kt-no-tel],.kt-dp,#kt-pp';
  function sweep(root) {
    if (!dial || !root || !root.querySelectorAll) return;
    var tw = document.createTreeWalker(root, 4, null), n, list = [];
    while ((n = tw.nextNode())) {
      var v = n.nodeValue;
      if (!v || v.length > 400 || !/\d{3}/.test(v)) continue;
      PHONE_RX.lastIndex = 0;
      if (!PHONE_RX.test(v)) continue;
      var p = n.parentElement;
      if (!p || (p.closest && p.closest(SKIP))) continue;
      list.push(n);
    }
    list.forEach(function (node) {
      var v = node.nodeValue, frag = document.createDocumentFragment(), last = 0, m;
      PHONE_RX.lastIndex = 0;
      while ((m = PHONE_RX.exec(v))) {
        if (!valid(m[0])) continue;
        frag.appendChild(document.createTextNode(v.slice(last, m.index)));
        var a = document.createElement('a');
        a.href = 'tel:+1' + digits(m[0]); a.className = 'kt-tel'; a.textContent = m[0];
        frag.appendChild(a);
        last = m.index + m[0].length;
      }
      if (!last) return;
      frag.appendChild(document.createTextNode(v.slice(last)));
      node.parentNode.replaceChild(frag, node);
    });
  }
  var sweepT = null;
  function sweepSoon() { clearTimeout(sweepT); sweepT = setTimeout(function () { sweep(document.getElementById('appMain')); sweep(document.getElementById('modalRoot')); }, 250); }
  if (KT.observeMain) KT.observeMain(function () { sweepSoon(); }, { childList: true, subtree: true });

  /* Tapping a phone link: KiddieTrac (recorded, from the business line) or this device. */
  function nameNear(a) {
    var row = a.closest && a.closest('tr,li,.kt-card,[data-name]');
    if (!row) return '';
    if (row.getAttribute && row.getAttribute('data-name')) return row.getAttribute('data-name');
    var first = row.querySelector && row.querySelector('td,strong,b,h3,h4');
    var t = first ? (first.textContent || '').trim() : '';
    return t && t.length < 60 && !/\d{3}/.test(t) ? t : '';
  }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest && e.target.closest('a[href^="tel:"]');
    if (!a || !dial || a.closest('#kt-pp')) return;
    e.preventDefault(); e.stopPropagation();
    chooser(a, a.getAttribute('href').replace(/^tel:/, ''), nameNear(a));
  }, true);
  function chooser(anchor, number, name) {
    var old = document.getElementById('kt-dial-choose'); if (old) old.remove();
    var box = document.createElement('div');
    box.id = 'kt-dial-choose';
    // The line is KiddieTrac's own number: the white-label renamer must leave it alone.
    box.setAttribute('data-kt-keep-brand', '1');
    var r = anchor.getBoundingClientRect();
    box.style.cssText = 'position:fixed;z-index:2147483001;background:#fff;border:1px solid #E2E8F0;border-radius:12px;box-shadow:0 14px 34px rgba(15,23,42,.22);padding:6px;min-width:230px;font-size:13.5px;'
      + 'left:' + Math.max(8, Math.min(r.left, innerWidth - 250)) + 'px;top:' + Math.min(r.bottom + 6, innerHeight - 110) + 'px;';
    box.innerHTML = '<button type="button" data-k style="display:flex;width:100%;align-items:center;gap:8px;border:0;background:#F0FDFA;color:#0F766E;font-weight:800;padding:9px 10px;border-radius:9px;cursor:pointer;text-align:left;">📞 Call with KiddieTrac'
      + (dial.record ? '<span style="margin-left:auto;font-size:11px;color:#B91C1C;font-weight:700;">● Recorded</span>' : '') + '</button>'
      + '<a data-d href="tel:' + esc(number) + '" style="display:flex;align-items:center;gap:8px;color:#334155;padding:9px 10px;border-radius:9px;text-decoration:none;">📱 Use this device</a>'
      + '<div style="font-size:11.5px;color:#64748B;padding:2px 10px 6px;">From ' + esc(dial.label) + '</div>';
    document.body.appendChild(box);
    var close = function () { box.remove(); document.removeEventListener('mousedown', out, true); };
    var out = function (ev) { if (!box.contains(ev.target)) close(); };
    setTimeout(function () { document.addEventListener('mousedown', out, true); }, 0);
    box.querySelector('[data-k]').onclick = function () { close(); call(number, { name: name }); };
    box.querySelector('[data-d]').onclick = function () { close(); };
  }

  /* ── a Phone link in the menu, for people who may dial ── */
  /* The sidebar Phone entry is part of the menu now (app-v2-shell.js), shown by html.kt-can-dial
     (app-v2.css). It used to be cloned in here after the session answered, and re-added every 4s. */

  /* ── the Phone screen ── */
  function fmtWhen(iso) { try { return KT.fmtDateTime ? KT.fmtDateTime(iso) : new Date(String(iso).replace(' ', 'T') + 'Z').toLocaleString(); } catch (e) { return iso || ''; } }
  function fmtDur(s) { s = +s || 0; return s ? Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) : ''; }
  // Who ended it, in words (Anthony: "real statuses").
  var STATUS = { 'hung-up': ['You hung up', '#15803D', '#DCFCE7'], 'ended-by-them': ['They hung up', '#15803D', '#DCFCE7'],
    completed: ['Talked', '#15803D', '#DCFCE7'], 'no-answer': ['No answer', '#B45309', '#FEF3C7'], busy: ['Busy', '#B45309', '#FEF3C7'],
    failed: ['Couldn’t connect', '#B91C1C', '#FEE2E2'], canceled: ['Hung up before they answered', '#475569', '#F1F5F9'],
    dialing: ['Connecting', '#1D4ED8', '#DBEAFE'], ringing: ['Ringing', '#1D4ED8', '#DBEAFE'], answered: ['On a call', '#15803D', '#DCFCE7'] };

  /* PHONE CONTACTS (2026-10-31): fill a number from the phone's own address book, for a call or a
     new text. The KiddieTrac apps (Android 3.12.12, iOS 3.12.10) carry a KtContacts plugin that
     opens the system picker with no Contacts permission; Chrome on Android has navigator.contacts.
     Resolves { digits, name } or null (cancelled / nothing usable, already explained by a toast). */
  function nativeContacts() { try { var c = w.Capacitor; return c && c.Plugins && c.Plugins.KtContacts && c.isPluginAvailable && c.isPluginAvailable('KtContacts') ? c.Plugins.KtContacts : null; } catch (e) { return null; } }
  function canPickPhone() { return !!(nativeContacts() || (w.navigator && navigator.contacts && navigator.contacts.select && w.ContactsManager)); }
  function pickPhoneContact() {
    var nat = nativeContacts();
    var p = nat
      ? nat.pick().then(function (r) { return r && r.picked ? [{ name: r.name || '', tels: [r.phone] }] : []; })
      : navigator.contacts.select(['name', 'tel'], { multiple: false }).then(function (l) { return (l || []).map(function (c) { return { name: (c.name && c.name[0]) || '', tels: c.tel || [] }; }); });
    return p.then(function (list) {
      var c = list[0]; if (!c) return null;
      var tel = c.tels.map(digits).filter(function (d) { return d.length === 10; })[0];
      if (!tel) { toast('⚠️', 'Not a number we can use', (c.name ? c.name + ' has' : 'That contact has') + ' no 10-digit Canada / US number.', '#B45309'); return null; }
      return { digits: tel, name: c.name };
    }).catch(function () { toast('⚠️', 'Couldn’t open your contacts', 'Type the number instead.', '#B45309'); return null; });
  }
  var BOOK = '<svg viewBox=\"0 0 24 24\" width=\"22\" height=\"22\" aria-hidden=\"true\" style=\"display:block\"><path fill=\"currentColor\" d=\"M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1H3a1 1 0 0 1 0-2h1v-2H3a1 1 0 0 1 0-2h1v-2H3a1 1 0 0 1 0-2h1V7H3a1 1 0 0 1 0-2h1V4a2 2 0 0 1 2-2zm6 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM7.5 17h9c0-2-2-3.5-4.5-3.5S7.5 15 7.5 17z\"/></svg>';

  /* ── THE DIAL PAD, shared by the Phone screen and the floating popup ── */
  var HANDSET = '<svg viewBox=\"0 0 24 24\" width=\"26\" height=\"26\" aria-hidden=\"true\" style=\"display:block\"><path fill=\"#fff\" d=\"M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z\"/></svg>';
  function padHtml(compact) {
    var kh = compact ? 40 : 46;
    return '<input class="kt-dp-num" inputmode="tel" autocomplete="off" placeholder="Enter a number" style="width:100%;box-sizing:border-box;height:' + (compact ? 40 : 44) + 'px;font-size:' + (compact ? 19 : 22) + 'px;font-weight:700;text-align:center;border:1px solid #CBD5E1;border-radius:10px;letter-spacing:.5px;">'
      + '<div class="kt-dp-who" style="min-height:18px;margin-top:5px;font-size:12.5px;color:#047857;font-weight:700;text-align:center;"></div>'
      + '<input class="kt-dp-name" placeholder="Who (optional)" style="width:100%;box-sizing:border-box;height:30px;margin-top:4px;font-size:13px;border:1px solid #E2E8F0;border-radius:8px;padding:0 10px;">'
      + '<div class="kt-dp-keys" style="display:grid;grid-template-columns:repeat(3,1fr);gap:' + (compact ? 6 : 8) + 'px;margin-top:10px;--kh:' + kh + 'px;"></div>'
      + '<div class="kt-dp-actions" style="display:flex;gap:8px;margin-top:10px;">'
      + '<button type="button" class="kt-dp-redial" title="Call the last number again" aria-label="Redial" style="flex:0 0 auto;height:' + (compact ? 40 : 44) + 'px;padding:0 12px;border:1px solid #CBD5E1;border-radius:11px;background:#fff;color:#0F172A;font-size:13.5px;font-weight:800;cursor:pointer;"><span class="ci">↻</span><span class="ct"> Redial</span></button>'
      + '<button type="button" class="kt-dp-call" aria-label="Call" style="flex:1;height:' + (compact ? 40 : 44) + 'px;border:0;border-radius:11px;background:#16A34A;color:#fff;font-size:15px;font-weight:800;cursor:pointer;"><span class="ci">' + HANDSET + '</span><span class="ct"> Call</span></button>'
      + (canPickPhone() ? '<button type="button" class="kt-dp-redial kt-dp-book" title="Pick from your phone’s contacts" aria-label="Phone contacts" style="flex:0 0 auto;height:' + (compact ? 40 : 44) + 'px;padding:0 12px;border:1px solid #CBD5E1;border-radius:11px;background:#fff;color:#0F172A;font-size:13.5px;font-weight:800;cursor:pointer;"><span class="ci">' + BOOK + '</span><span class="ct"> Contacts</span></button>' : '')
      + '</div>';
  }
  function wirePad(root, context) {
    var num = root.querySelector('.kt-dp-num'), nm = root.querySelector('.kt-dp-name'), keys = root.querySelector('.kt-dp-keys');
    var LETTERS = { '2': 'ABC', '3': 'DEF', '4': 'GHI', '5': 'JKL', '6': 'MNO', '7': 'PQRS', '8': 'TUV', '9': 'WXYZ', '0': '+' };
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '⌫'].forEach(function (k) {
      var b = document.createElement('button'); b.type = 'button';
      b.setAttribute('aria-label', k === '⌫' ? 'Delete' : k);
      b.innerHTML = '<span class="kd">' + k + '</span><span class="kl">' + (LETTERS[k] || '') + '</span>';
      b.onclick = function () {
        // A tap you can feel, like a phone's keypad (2026-10-23). Android only: WebKit has no vibrate.
        try { if (navigator.vibrate) navigator.vibrate(k === '⌫' ? 8 : 14); } catch (e) {}
        num.value = k === '⌫' ? num.value.slice(0, -1) : num.value + k;
        // On a phone the system keyboard stays shut: the keypad IS the keyboard.
        if (!(innerWidth <= 768)) num.focus();
        num.dispatchEvent(new Event('input'));
      };
      keys.appendChild(b);
    });
    var go = function () { call(num.value, { name: nm.value.trim(), context: context }); };
    root.querySelector('.kt-dp-call').onclick = go;
    num.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
    // The name of whoever has this number, as it is typed.
    var whoEl = root.querySelector('.kt-dp-who'), autoName = '', lt = null;
    var showWho = function () {
      clearTimeout(lt);
      var d = digits(num.value);
      if (d.length !== 10 || !valid(num.value)) { whoEl.textContent = ''; if (nm.value === autoName) { nm.value = ''; autoName = ''; } return; }
      lt = setTimeout(function () {
        lookup(num.value).then(function (f) {
          if (digits(num.value) !== d) return;
          whoEl.textContent = f ? '📇 ' + f.name + (f.kind ? ' · ' + f.kind : '') : pretty(num.value);
          if (f && (!nm.value || nm.value === autoName)) { nm.value = f.name; autoName = f.name; }
        });
      }, 250);
    };
    num.addEventListener('input', showWho);
    // Redial: the last number this person called, from this device.
    root.querySelector('.kt-dp-redial').onclick = function () {
      var last = null; try { last = JSON.parse(localStorage.getItem('kt_dial_last') || 'null'); } catch (e) {}
      if (!last || !last.n) { toast('↻', 'Nothing to redial yet', 'Your last call will be here.', '#475569'); return; }
      num.value = pretty(last.n); nm.value = last.name || ''; autoName = '';
      showWho();
      call(last.n, { name: last.name || '', context: 'redial' });
    };
    var book = root.querySelector('.kt-dp-book');
    if (book) book.onclick = function () {
      pickPhoneContact().then(function (c) {
        if (!c) return;
        num.value = pretty(c.digits); nm.value = c.name || ''; autoName = '';
        showWho();
      });
    };
    var paint = function () { var b = root.querySelector('.kt-dp-call'); if (b) { b.disabled = !dial; b.style.opacity = dial ? 1 : .5; } };
    paint();
    w.addEventListener('kt:phone-session', paint);
    return { num: num, name: nm };
  }
  function padCss() {
    if (document.getElementById('kt-dp-css')) return;
    var st = document.createElement('style'); st.id = 'kt-dp-css';
    st.textContent = '.kt-dp-keys button{height:var(--kh,46px);border:1px solid #E2E8F0;background:#F8FAFC;border-radius:10px;font-size:19px;font-weight:700;color:#0F172A;cursor:pointer}'
      + '.kt-dp-keys button:active{background:#E2E8F0}'
      + '.kt-dp-actions .kt-dp-book{grid-column:3 !important;display:inline-flex;align-items:center;justify-content:center}.kt-dp-book .ci{display:inline-flex}'
      /* The Phone banner: its own colours, and a handset that rings with sound waves. */
      + '.kt-phone-hero{position:relative;overflow:hidden;border-radius:18px;padding:22px 24px;margin:0 0 16px;color:#fff;'
      + 'background:linear-gradient(120deg,#064E3B 0%,#047857 42%,#0EA5E9 100%) !important;box-shadow:0 10px 28px -12px rgba(4,120,87,.55)}'
      + '.kt-phone-hero .kp-eyebrow{font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#A7F3D0}'
      + '.kt-phone-hero h1{margin:1px 0;font-size:19px;font-weight:800;color:#fff;letter-spacing:-.2px}'
      + '.kt-phone-hero .kp-sub{font-size:12px;color:#D1FAE5;max-width:560px;line-height:1.35}'
      + '.kt-phone-hero .kt-hero-svg{position:absolute;right:22px;top:50%;width:118px;height:118px;transform:translateY(-50%);pointer-events:none}'
      + '.kp-ring{transform-origin:60px 60px;animation:kpWave 2.4s ease-out infinite;opacity:0}'
      + '.kp-ring.r2{animation-delay:.8s}.kp-ring.r3{animation-delay:1.6s}'
      + '@keyframes kpWave{0%{transform:scale(.55);opacity:.75}100%{transform:scale(1.25);opacity:0}}'
      + '.kp-handset{transform-origin:60px 60px;animation:kpRing 3.2s ease-in-out infinite}'
      + '@keyframes kpRing{0%,58%,100%{transform:rotate(0)}62%{transform:rotate(-14deg)}66%{transform:rotate(12deg)}70%{transform:rotate(-12deg)}74%{transform:rotate(10deg)}78%{transform:rotate(-6deg)}82%{transform:rotate(0)}}'
      + '.kt-phone-hero.on-call .kp-handset{animation:none}.kt-phone-hero.on-call .kp-ring{animation-duration:1.4s;stroke:#FCA5A5}'
      + '.kt-phone-hero.on-call .kp-dot{fill:#EF4444}'
      + '@media (max-width:640px){.kt-phone-hero{padding:18px}.kt-phone-hero .kt-hero-svg{width:84px;height:84px;right:12px}.kt-phone-hero h1{font-size:22px}.kt-phone-hero .kp-sub{max-width:calc(100% - 92px)}}'
      + '@media (prefers-reduced-motion:reduce){.kp-ring,.kp-handset{animation:none}.kp-ring{opacity:.35}}'
      /* ALWAYS ITS OWN (Anthony: "make this unique always"). The portal paints every banner
         (an agency's white-label colours, the shared banner gradient) and caps banner
         illustrations at 62px, both with !important. The Phone banner outranks them. */
      + 'html body #appMain .kt-hero.kt-phone-hero{background:linear-gradient(120deg,#064E3B 0%,#047857 42%,#0EA5E9 100%) !important;background-image:linear-gradient(120deg,#064E3B 0%,#047857 42%,#0EA5E9 100%) !important;background-size:200% 200% !important}'
      + 'html body #appMain .kt-hero.kt-phone-hero .kt-hero-svg{width:118px !important;height:118px !important;top:50% !important;bottom:auto !important;right:22px !important;opacity:1 !important;transform:translateY(-50%) !important}'
      + 'html body #appMain .kt-hero.kt-phone-hero h1{color:#fff !important;font-size:26px !important}'
      + '@media (max-width:640px){html body #appMain .kt-hero.kt-phone-hero .kt-hero-svg{width:84px !important;height:84px !important;right:12px !important}html body #appMain .kt-hero.kt-phone-hero h1{font-size:22px !important}}'
      /* The floating dial pad. */
      + '#kt-dpop{position:fixed;z-index:9400;width:300px;height:520px;min-width:250px;min-height:300px;max-width:calc(100vw - 16px);max-height:calc(100vh - 16px);'
      + 'background:#fff;border:1px solid #E2E8F0;border-radius:16px;box-shadow:0 22px 50px -12px rgba(15,23,42,.45);display:flex;flex-direction:column;overflow:hidden;resize:both}'
      + '#kt-dpop .kdp-head{display:flex;align-items:center;gap:8px;padding:9px 10px 9px 14px;background:linear-gradient(120deg,#064E3B,#047857 55%,#0EA5E9);color:#fff;cursor:move;user-select:none;touch-action:none;flex-shrink:0}'
      + '#kt-dpop .kdp-title{font-weight:800;font-size:14px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '#kt-dpop .kdp-btn{width:28px;height:28px;border:0;border-radius:8px;background:rgba(255,255,255,.16);color:#fff;font-size:15px;line-height:1;cursor:pointer;flex-shrink:0}'
      + '#kt-dpop .kdp-btn:hover{background:rgba(255,255,255,.28)}'
      + '#kt-dpop .kdp-body{flex:1;overflow:auto;padding:12px}'
      + '#kt-dpop .kdp-line{font-size:11.5px;color:#64748B;margin:8px 0 0;line-height:1.4}'
      + '#kt-dpop .kdp-recent{margin-top:12px;border-top:1px solid #F1F5F9;padding-top:8px}'
      + '#kt-dpop .kdp-recent button{display:flex;width:100%;justify-content:space-between;gap:8px;border:0;background:none;padding:6px 2px;font-size:12.5px;cursor:pointer;color:#0F172A;text-align:left;border-radius:6px}'
      + '#kt-dpop .kdp-recent button:hover{background:#F8FAFC}'
      + '#kt-dpop.max{left:50% !important;top:5vh !important;transform:translateX(-50%);width:min(880px,94vw) !important;height:88vh !important;resize:none}'
      + '#kt-dpop.max .kdp-body[data-pane="pad"]{display:grid;grid-template-columns:300px minmax(0,1fr);gap:18px;align-content:start}'
      + '#kt-dpop.max .kdp-recent{margin-top:0;border-top:0;padding-top:0;grid-row:1 / span 3;grid-column:2}'
      + '#kt-dpop .kdp-open{display:block;margin-top:10px;font-size:12px;color:#0F766E;font-weight:700;text-decoration:none}'
      + '#kt-dpop.min{min-width:0 !important;min-height:0 !important;height:auto !important;width:auto !important;min-width:0;min-height:0;resize:none;border-radius:999px;transform:none}'
      + '#kt-dpop.min .kdp-body,#kt-dpop.min .kdp-tabs{display:none !important}#kt-dpop.min .kdp-head{border-radius:999px;padding:7px 8px 7px 14px}'
      + '#kt-dp-fab{position:fixed;left:14px;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 84px);z-index:9300;width:52px;height:52px;border-radius:50%;border:0;color:#fff;font-size:22px;cursor:pointer;'
      + 'background:linear-gradient(135deg,#047857,#0EA5E9);box-shadow:0 10px 24px -8px rgba(4,120,87,.7);display:none}'
      + '@media (max-width:768px){html.kt-can-dial #kt-dp-fab{display:block}#kt-dpop{left:8px !important;right:8px;width:auto !important;top:auto !important;bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 80px);height:min(520px,calc(100vh - 140px)) !important;resize:none}}'
      + 'html:not(.kt-can-dial) #kt-tb-phone{display:none !important}'
      + '@media (max-width:768px){#kt-dpop.min,#kt-dpop .kdp-min{display:none !important}}'
      // Docked in the phone header, left of the settings gear (kt-mobilenav.js dockDialFab sets top + the class).
      + 'body.kt-dp-docked #kt-dp-fab{left:auto !important;right:60px !important;bottom:auto !important;width:40px;height:40px;font-size:18px;z-index:9450;box-shadow:0 2px 10px rgba(15,23,42,.2)}'
      // With Phone on the bottom bar, the floating 📞 would be a second copy of it.
      + 'html:has(#kt-b-phone) #kt-dp-fab{display:none !important}'
      /* Keypad · Recents · Missed · Voicemail (2026-10-23). */
      + '.kdp-tabs{display:flex;gap:2px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;flex-shrink:0}'
      + '.kdp-tabs button{flex:1;position:relative;border:0;background:none;padding:8px 2px 7px;font-size:12px;font-weight:700;color:#64748B;cursor:pointer;border-bottom:2px solid transparent;display:flex;flex-direction:column;align-items:center;gap:1px}'
      + '.kdp-tabs button{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'   // six fit at 300px (Texts added 2026-10-23)
      + '.kdp-tabs button .ti{font-size:15px;line-height:1;height:20px;display:flex;align-items:center;justify-content:center}'
      + '.kdp-tabs button.on{color:#047857;border-bottom-color:#047857}'
      + '.kdp-tabs .tc{position:absolute;top:3px;left:calc(50% + 6px);min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#DC2626;color:#fff;font-size:10px;font-weight:800;line-height:16px;text-align:center}'
      + '.kdp-pane[hidden],.kdp-tabs .tc[hidden],.kt-dp-tabs2 .tc[hidden]{display:none !important}'
      + '.kdp-tabs button .ti svg{width:17px;height:17px}'
      + '.kt-ib-row{display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #F1F5F9}'
      + '.kt-ib-row .ib-ico{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:#FEE2E2}'
      + '.kt-ib-row.vm .ib-ico{background:#E0F2FE}.kt-ib-row.mine .ib-ico{background:#DCFCE7}'
      + '.kt-ib-row .ib-main{flex:1;min-width:0}'
      + '.kt-ib-row .ib-who{font-weight:700;color:#0F172A;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '.kt-ib-row.miss .ib-who{color:#B91C1C}'
      + '.kt-ib-row .ib-sub{color:#64748B;font-size:11.5px;margin-top:1px}'
      + '.kt-ib-row .ib-new{display:inline-block;width:8px;height:8px;border-radius:50%;background:#DC2626;margin-right:5px;vertical-align:1px}'
      + '.kt-ib-row .ib-acts{display:flex;gap:6px;flex-shrink:0;align-items:center}'
      + '.kt-ib-row .ib-acts button{height:30px;min-width:30px;padding:0 9px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;cursor:pointer;font-size:12px;font-weight:700;color:#0F172A}'
      + '.kt-ib-row .ib-acts button.cb{border:0;background:#16A34A;color:#fff;padding:0;width:30px}'
      + '.kt-ib-row .ib-acts button.cb svg{width:15px;height:15px}'
      + '.kt-ib-row{flex-wrap:wrap}.kt-ib-row audio{flex:1 0 100%;width:100%;height:36px;margin-top:6px}'
      + '.kt-ib-empty{padding:26px 10px;text-align:center;color:#64748B;font-size:13px}'
      + '.kt-ct-search{position:sticky;top:0;z-index:1;background:#fff;padding:2px 0 8px}'
      + '.kt-ct-search input{width:100%;box-sizing:border-box;height:36px;border:1px solid #CBD5E1;border-radius:999px;padding:0 14px;font-size:14px}'
      + '.kt-ct-meta{font-size:11.5px;color:#64748B;margin:0 2px 2px}'
      + '.kt-ib-row.kt-ctrow .ib-ico{background:#E0E7FF;color:#3730A3;font-size:12.5px;font-weight:800}'
      + '.kt-ib-row.kt-ctrow{cursor:pointer}'
      + '#kt-dpop .kdp-tabs button{font-size:11px}'
      + '#kt-dp-fab .kt-fab-badge,#kt-tb-phone-badge{background:#DC2626}'
      + '#kt-dp-fab .kt-fab-badge{position:absolute;top:-3px;right:-3px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;color:#fff;font-size:11px;font-weight:800;line-height:20px;border:2px solid #fff}'
      + '#kt-dp-fab{position:fixed}'
      + '.kt-dp-tabs2{display:flex;gap:6px;flex-wrap:wrap}'
      + '.kt-dp-tabs2 button{height:30px;padding:0 12px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;font-size:12.5px;font-weight:700;color:#334155;cursor:pointer;position:relative}'
      + '.kt-dp-tabs2 button.on{background:#047857;border-color:#047857;color:#fff}'
      + '.kt-dp-tabs2 .tc{display:inline-block;margin-left:5px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#DC2626;color:#fff;font-size:10px;line-height:16px}'
      + '.kt-dp-keys button{display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1}'
      + '.kt-dp-call .ci{display:inline-flex;vertical-align:middle}.kt-dp-call .ci svg{width:18px;height:18px}'
      + '#kt-dpop:not(.min) .kt-dp-call .ci svg,.kt-dp-grid .kt-dp-call .ci svg{width:28px;height:28px}'
      + '.kt-dp-keys .kl{font-size:9px;letter-spacing:.14em;color:#94A3B8;font-weight:700;margin-top:2px;min-height:9px}'
      /* ON A PHONE (and in the app): the dial pad fills the screen like the phone's own
         dialer (Anthony, 2026-10-23): big number, round keys with letters, a round green
         call button between Redial and the keypad. Minimised it is still a pill. */
      + '@media (max-width:768px){'
      + '#kt-dpop:not(.min){left:0 !important;top:var(--kt-vv-top,0px) !important;right:0 !important;bottom:auto !important;width:100% !important;height:calc(var(--kt-vv-h,100vh) - var(--kt-nav-h,0px)) !important;max-width:none !important;max-height:none !important;border-radius:0 !important;border:0 !important;resize:none !important;transform:none !important;z-index:2147482000 !important}'
      + '#kt-dpop:not(.min) .kdp-head{padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 12px) 12px 12px 18px;cursor:default}'
      + '#kt-dpop:not(.min) .kdp-full{display:none}'
      + '#kt-dpop:not(.min) .kdp-body{display:flex;flex-direction:column;padding:6px 20px 14px;overflow:auto}'
      + '#kt-dpop:not(.min) .kdp-pad{display:flex;flex-direction:column;flex:1;width:100%;max-width:360px;margin:0 auto}'
      + '#kt-dpop:not(.min) .kt-dp-num{margin-top:auto;border:0 !important;background:transparent;height:62px !important;font-size:34px !important;font-weight:600 !important;letter-spacing:1px;color:#0F172A;caret-color:#16A34A}'
      + '#kt-dpop:not(.min) .kt-dp-num:focus{outline:none !important;box-shadow:none !important}'
      + '#kt-dpop:not(.min) .kt-dp-who{font-size:14px;min-height:20px}'
      + '#kt-dpop:not(.min) .kt-dp-name{border:0 !important;background:#F1F5F9;text-align:center;height:34px !important;border-radius:999px !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys{gap:14px 0 !important;margin-top:20px !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys button,.kt-dp-grid .kt-dp-keys button{height:72px !important;width:72px;margin:0 auto;border-radius:50% !important;border:0 !important;background:#F1F5F9 !important;font-size:30px !important;font-weight:500 !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys .kl,.kt-dp-grid .kt-dp-keys .kl{font-size:10px}'
      + '#kt-dpop:not(.min) .kt-dp-actions,.kt-dp-grid .kt-dp-actions{justify-content:center;align-items:center;gap:30px !important;margin-top:22px !important}'
      + '#kt-dpop:not(.min) .kt-dp-call,.kt-dp-grid .kt-dp-call{flex:0 0 72px !important;width:72px;height:72px !important;border-radius:50% !important;font-size:28px !important;box-shadow:0 10px 24px -8px rgba(22,163,74,.7)}'
      + '#kt-dpop:not(.min) .kt-dp-redial,.kt-dp-grid .kt-dp-redial{width:54px;height:54px !important;border-radius:50% !important;padding:0 !important;font-size:22px !important}'
      + '#kt-dpop:not(.min) .ct,.kt-dp-grid .ct{display:none}'
      + '#kt-dpop:not(.min) .kdp-line,#kt-dpop:not(.min) .kdp-open{text-align:center}'
      + '#kt-dpop:not(.min) .kdp-recent{display:none}'
      + '#kt-dpop:not(.min) .kdp-tabs{order:9;border-bottom:0;border-top:1px solid #E2E8F0;background:#fff;border-radius:0 !important;padding-bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 2px) !important}'
      + '#kt-dpop:not(.min) .kdp-tabs button{padding:9px 2px 10px;border-bottom:0;border-top:2px solid transparent;font-size:11.5px}'
      + '#kt-dpop:not(.min) .kdp-tabs button.on{border-top-color:#047857}'
      + '#kt-dpop:not(.min) .kdp-tabs button .ti{font-size:19px}'
      + '#kt-dpop:not(.min) .kdp-body{padding-bottom:12px}'
      + 'html.kt-dpop-full #kt-dp-fab{display:none !important}'
      /* PAD POLISH (2026-10-27, Anthony's screenshot: "look how the dialpad screen looks - make
         this look better"). With the KiddieTrac bar now under the pad, the tab row's own
         system-inset padding became an empty white band; the call button sat off-centre; the
         placeholder shouted in grey; the header icon was a dark emoji on the dark gradient. */
      + '#kt-dpop:not(.min) .kdp-head{order:-2}'
      + '#kt-dpop:not(.min) .kdp-tabs{order:-1 !important;border-top:0 !important;border-bottom:1px solid #E2E8F0 !important;padding-bottom:0 !important;box-shadow:0 6px 14px -12px rgba(15,23,42,.35);background:#fff}'
      + '#kt-dpop:not(.min) .kdp-tabs button{padding:8px 2px 7px !important;border-top:0 !important;border-bottom:2px solid transparent !important}'
      + '#kt-dpop:not(.min) .kdp-tabs button.on{border-bottom-color:#047857 !important;background:#F0FDF4}'
      + '#kt-dpop:not(.min) .kdp-body{background:linear-gradient(180deg,#F0FDF4 0,#fff 140px)}'
      + '#kt-dpop:not(.min) .kt-dp-num{height:58px !important;font-size:32px !important;font-weight:600 !important;letter-spacing:1.5px}'
      + '#kt-dpop:not(.min) .kt-dp-num::placeholder{color:#CBD5E1;font-size:24px;font-weight:600;letter-spacing:0}'
      + '#kt-dpop:not(.min) .kt-dp-who:empty{min-height:0 !important;margin:0 !important}'
      + '#kt-dpop:not(.min) .kt-dp-name{display:block;max-width:220px;margin:8px auto 0 !important;height:32px !important;font-size:13px !important;color:#334155;border:1px solid #E2E8F0 !important;background:#fff !important}'
      + '#kt-dpop:not(.min) .kt-dp-name::placeholder{color:#94A3B8}'
      + '#kt-dpop:not(.min) .kt-dp-keys{gap:12px 0 !important;margin-top:18px !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys button{width:70px;height:70px !important;color:#0F172A;background:linear-gradient(180deg,#F8FAFC,#EEF2F7) !important;box-shadow:0 1px 2px rgba(15,23,42,.06),inset 0 0 0 1px #E2E8F0;transition:transform .08s ease,background .12s ease}'
      + '#kt-dpop:not(.min) .kt-dp-keys button:active{background:#DCFCE7 !important;transform:scale(.94)}'
      + '#kt-dpop:not(.min) .kt-dp-keys .kl{color:#64748B;letter-spacing:.16em}'
      + '#kt-dpop:not(.min) .kt-dp-actions{display:grid !important;grid-template-columns:1fr 72px 1fr;align-items:center;gap:0 !important;margin-top:20px !important}'
      + '#kt-dpop:not(.min) .kt-dp-redial{grid-column:1;justify-self:center;width:52px;height:52px !important;color:#047857 !important;border-color:#BBF7D0 !important;background:#F0FDF4 !important}'
      + '#kt-dpop:not(.min) .kt-dp-call{grid-column:2;background:linear-gradient(135deg,#22C55E,#15803D) !important}'
      + '#kt-dpop:not(.min) .kt-dp-call:active{transform:scale(.95)}'
      + '#kt-dpop:not(.min) .kdp-line{font-size:12px;color:#64748B;margin-top:14px}'
      + '#kt-dpop:not(.min) .kdp-open{display:none !important}'
      /* THE SEAM WITH THE BAR (2026-10-27, Anthony circled it): the bar's upward shadow spread a
         grey band over the pad and the caption sat squeezed against it, so the bar looked like a
         layer laid on top. While the pad is open the bar has a hairline instead of the shadow, and
         the caption sits under the Who field - below the call row there is only the clear strip. */
      // The bar is drawn ABOVE the pad while it is open: the raised Add button rises past the bar's
      // top edge, and under the pad that part was cut off flat at the hairline (Anthony, 1:25 PM).
      // (Doubled id: body.kt-dialog-open #kt-mobilenav sets 500 !important for every dialog and won.)
      + 'html.kt-dpop-full body #kt-mobilenav#kt-mobilenav{box-shadow:none !important;border-top:1px solid #E5E7EB !important;z-index:2147482100 !important}'
      // The keypad block sits in the middle of the space, not pushed down onto the bar.
      // ...and the pad's own drop shadow fell ONTO the bar (the grey band he circled): none on a phone.
      + '#kt-dpop:not(.min){box-shadow:none !important}'
      + '#kt-dpop:not(.min) .kdp-pad{justify-content:center}'
      + '#kt-dpop:not(.min) .kdp-pad > .kt-dp-num{margin-top:0 !important}'
      + '#kt-dpop:not(.min) .kdp-body[data-pane="pad"]{padding-bottom:calc(var(--kt-dp-clear,34px) + 18px) !important}'
      + '#kt-dpop:not(.min) .kdp-pad > .kt-dp-keys,#kt-dpop:not(.min) .kdp-pad > .kt-dp-actions{order:2}'
      + '#kt-dpop:not(.min) .kdp-line{order:1;margin:10px 0 0 !important;font-size:11.5px !important;color:#94A3B8 !important}'
      + '#kt-dpop:not(.min) .kdp-body[data-pane="pad"]{padding-bottom:calc(var(--kt-dp-clear,34px) + 6px) !important;overflow:hidden !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys button{width:var(--kdp-k,70px) !important;height:var(--kdp-k,70px) !important;font-size:calc(var(--kdp-k,70px) * .42) !important}'
      + '#kt-dpop:not(.min) .kt-dp-keys{gap:calc(var(--kdp-k,70px) * .17) 0 !important;margin-top:calc(var(--kdp-k,70px) * .2) !important}'
      + '#kt-dpop:not(.min) .kt-dp-call{width:calc(var(--kdp-k,70px) + 2px) !important;height:calc(var(--kdp-k,70px) + 2px) !important;flex-basis:calc(var(--kdp-k,70px) + 2px) !important}'
      + '#kt-dpop:not(.min) .kt-dp-actions{grid-template-columns:1fr calc(var(--kdp-k,70px) + 2px) 1fr !important;margin-top:calc(var(--kdp-k,70px) * .22) !important}'
      /* The Phone screen's own keypad (.kt-dp-grid), same look. kt-mobile-app.css turns every
         inline repeat(3..5) grid into TWO columns on a phone (meant for metric tiles); that
         re-laid the keypad as 1 2 / 3 4 / 5 6. A keypad is three across, always. */
      + '#appMain .kt-dp-keys[style],#kt-dpop .kt-dp-keys[style]{grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:12px 0 !important}'
      + '#appMain .kt-dp-grid .kt-dp-keys[style]{margin-top:16px !important;max-width:340px;margin-left:auto !important;margin-right:auto !important}'
      + '.kt-dp-grid .kt-dp-keys button{width:70px !important;height:70px !important;color:#0F172A;background:linear-gradient(180deg,#F8FAFC,#EEF2F7) !important;box-shadow:0 1px 2px rgba(15,23,42,.06),inset 0 0 0 1px #E2E8F0}'
      + '.kt-dp-grid .kt-dp-keys button:active{background:#DCFCE7 !important;transform:scale(.94)}'
      + 'html body #appMain .kt-dp-grid input.kt-dp-num.kt-dp-num{border:0 !important;background:transparent !important;box-shadow:none !important;height:56px !important;font-size:30px !important;font-weight:600 !important}'
      + '.kt-dp-grid .kt-dp-num::placeholder{color:#CBD5E1;font-size:22px}'
      + 'html body #appMain .kt-dp-grid input.kt-dp-name.kt-dp-name{display:block;width:220px !important;max-width:70% !important;margin:6px auto 0 !important;height:32px !important;border-radius:999px !important;text-align:center;font-size:13px !important;background:#fff !important}'
      + '.kt-dp-grid .kt-dp-who:empty{min-height:0 !important;margin:0 !important}'
      // The Phone screen's call lists: a number is one line (it wrapped as (343) / 297- / 9959).
      + '#appMain .kt-dp-list table,#appMain .kt-dp-list tbody{display:block !important;width:100% !important}#appMain .kt-dp-list thead{display:none !important}'
      + '#appMain .kt-dp-list tbody tr{display:flex !important;flex-wrap:wrap;align-items:center;column-gap:10px;row-gap:3px;padding:9px 4px !important;border-bottom:1px solid #F1F5F9}'
      + '#appMain .kt-dp-list tbody td{display:block !important;padding:0 !important;border:0 !important;width:auto !important;min-width:0}'
      + '#appMain .kt-dp-list tbody td:nth-child(1){order:1;flex:0 0 30px}'
      + '#appMain .kt-dp-list tbody td:nth-child(2){order:2;flex:1 1 calc(100% - 100px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '#appMain .kt-dp-list tbody td:nth-child(4){order:3;flex:0 0 auto}'
      + '#appMain .kt-dp-list tbody td:nth-child(3){order:4;margin-left:40px;white-space:nowrap}#appMain .kt-dp-list tbody td:nth-child(3) *{white-space:nowrap !important}'
      + '#appMain .kt-dp-grid .kt-dp-actions[style]{display:grid !important;grid-template-columns:1fr 72px 1fr !important;align-items:center;gap:0 !important;max-width:340px;margin-left:auto !important;margin-right:auto !important}'
      + '.kt-dp-grid .kt-dp-redial{grid-column:1;justify-self:center;color:#047857 !important;border-color:#BBF7D0 !important;background:#F0FDF4 !important}'
      + '.kt-dp-grid .kt-dp-call{grid-column:2;background:linear-gradient(135deg,#22C55E,#15803D) !important}'
      + '#kt-dpop:not(.min) .kt-dp-num{background:transparent !important;border:0 !important;box-shadow:none !important}'
      + '#kt-dpop:not(.min) .kt-dp-name{width:220px !important;max-width:70% !important}'
      // Voicemail: the buttons go on their own line so the caller's name is not cut to "Caroline ...".
      + '#kt-dpop:not(.min) .kt-ib-row.vm .ib-acts{flex:1 0 100%;justify-content:flex-start;padding-left:42px;margin-top:6px;flex-wrap:wrap}'
      + '#kt-dpop:not(.min) .kt-ib-row.vm .ib-who{white-space:normal}'
      // Texts opens full screen: it keeps the KiddieTrac bar too, like the pad.
      + '#kt-txfull{height:calc(var(--kt-vv-h,100vh) - var(--kt-nav-h,0px)) !important}'
      + '}'
      // The header handset: white on the gradient, at every size (was a dark 📞 emoji).
      + '#kt-dpop .kdp-ico{display:inline-flex;width:20px;height:20px;align-items:center;justify-content:center;color:#fff}'
      + '#kt-dpop .kdp-ico svg{width:18px;height:18px;fill:currentColor}'
      + '';
    document.head.appendChild(st);
  }
  /* The banner illustration: a handset with sound waves (pure SVG, so the portal's banner
     decorators leave it alone; the colours are this screen's own). */
  function heroSvg() {
    return '<svg class="kt-hero-svg" viewBox="0 0 120 120" aria-hidden="true">'
      + '<circle class="kp-ring" cx="60" cy="60" r="44" fill="none" stroke="#A7F3D0" stroke-width="3"/>'
      + '<circle class="kp-ring r2" cx="60" cy="60" r="44" fill="none" stroke="#A7F3D0" stroke-width="3"/>'
      + '<circle class="kp-ring r3" cx="60" cy="60" r="44" fill="none" stroke="#A7F3D0" stroke-width="3"/>'
      + '<circle cx="60" cy="60" r="30" fill="#fff" fill-opacity=".96"/>'
      + '<g class="kp-handset"><path d="M48.5 44.5c1.6-1.6 4.1-1.4 5.4.4l3.3 4.6c1 1.4.9 3.3-.3 4.5l-2.2 2.2c1.6 3.4 4.3 6.1 7.7 7.7l2.2-2.2c1.2-1.2 3.1-1.3 4.5-.3l4.6 3.3c1.8 1.3 2 3.8.4 5.4l-2.6 2.6c-2.2 2.2-5.6 2.7-8.4 1.3-7.9-4.1-14.3-10.5-18.4-18.4-1.4-2.8-.9-6.2 1.3-8.4z" fill="#047857"/></g>'
      + '<circle class="kp-dot" cx="80" cy="40" r="6" fill="#FBBF24" stroke="#fff" stroke-width="2"/></svg>';
  }

  /* ── THE FLOATING DIAL PAD (Anthony, 2026-10-23: "add shortcut to dialpad as a popout
     at the top bar next to the home button - floating popup, resizable, minimize, close").
     Drag it by its header, resize it from the corner, minimise it to a pill, close it.
     Where it sits and its size are remembered on this device. ── */
  var POP_KEY = 'kt_dialpad_pop';
  /* A phone (or the app): opening the dial pad must not throw up the system keyboard -
     the round keys are the keyboard, like the phone's own dialer (Anthony, 2026-10-23).
     The number field still takes a paste (long-press). */
  function touchPhone() {
    try { return innerWidth <= 768 || (matchMedia('(pointer: coarse)').matches && matchMedia('(hover: none)').matches); } catch (e) { return innerWidth <= 768; }
  }
  /* NO FLOATING WINDOWS ON A PHONE OR IN THE APP (2026-10-23, Anthony: "in mobile the dial
     pad is showing as a minimize chat floating bar (shouldnt be there for mobile or mobile apk
     at all"). There the pad is full screen; minimise closes it, and a pill is never shown. */
  function phoneMode() {
    try { var C = window.Capacitor; if (C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)) return true; } catch (e) {}
    return innerWidth <= 768;
  }
  w.addEventListener('resize', function () { var el = document.getElementById('kt-dpop'); if (el && el.classList.contains('min') && phoneMode()) closePop(); });
  function popState() { try { return JSON.parse(localStorage.getItem(POP_KEY) || '{}') || {}; } catch (e) { return {}; } }
  function savePop(p) { try { localStorage.setItem(POP_KEY, JSON.stringify(p)); } catch (e) {} }
  function closePop() { var el = document.getElementById('kt-dpop'); if (el) el.remove(); document.documentElement.classList.remove('kt-dpop-full'); var b = document.getElementById('kt-tb-phone'); if (b) b.setAttribute('aria-pressed', 'false'); }
  /* Opening the phone clears the 📞 badge (2026-10-23, Anthony: "clear the indicator once I
     open it"). Unplayed voicemails keep their dot in the list until someone plays them. */
  function markOpened() {
    if (!inbox.total) return;
    req('POST', '/voice/dialer/inbox/seen').then(function (c) { inbox = { allowed: true, voicemail: +c.voicemail || 0, missed: +c.missed || 0, total: +c.total || 0 }; paintBadge(); dropPhonePopup(); }).catch(function () {});
  }
  /* ON A PHONE THE DIAL PAD IS A SCREEN, NOT A LAYER (2026-10-27, Anthony: "look how the
     dialpad sits on top of the bottom bar and not within"). The full-screen pop-up was drawn
     OVER the app however it was fitted, so on a phone (and in the app) opening it now goes to
     the Phone screen: the keypad and its tabs as page content, in the app's own frame, above the
     KiddieTrac bar like every other screen. `tab` opens one of its tabs (missed, vm, texts...). */
  function openPhoneScreen(tab) {
    closePop();
    markOpened();
    var pick = function () {
      if (!tab) return;
      var t = document.querySelector('.kt-dp-tabs2 [data-tab="' + tab + '"]');
      if (t) t.click();
    };
    if (/^#phone(\?|$)/.test(location.hash)) { pick(); try { if (KT.scroll && KT.scroll.top) KT.scroll.top(); else { var m = document.getElementById('appMain'); if (m) m.scrollTop = 0; } } catch (e) {} return; }
    location.hash = '#phone';
    if (tab) setTimeout(pick, 900);
  }
  /* Fit the phone pad to the screen: keep clear of the bar's raised button, then shrink the
     keys (70 -> 48px) until the keypad, call row and caption fit with no scrolling. */
  function fitPad() {
    try {
      var el = document.getElementById('kt-dpop');
      if (!el || el.classList.contains('min') || !phoneMode()) return;
      var nav = document.getElementById('kt-mobilenav'), clear = 0;
      if (nav) {
        var top = nav.getBoundingClientRect().top;
        [].forEach.call(nav.querySelectorAll('*'), function (n) { var r = n.getBoundingClientRect(); if (r.height && r.top < top) clear = Math.max(clear, Math.round(top - r.top)); });
      }
      el.style.setProperty('--kt-dp-clear', Math.min(Math.max(clear, 0), 60) + 'px');
      var body = el.querySelector('.kdp-body[data-pane="pad"]');
      if (!body || body.hidden) return;
      var k = 70;
      el.style.setProperty('--kdp-k', k + 'px');
      while (k > 48 && body.scrollHeight > body.clientHeight + 1) { k -= 2; el.style.setProperty('--kdp-k', k + 'px'); }
    } catch (e) {}
  }
  if (w.visualViewport) w.visualViewport.addEventListener('resize', function () { setTimeout(fitPad, 60); });
  w.addEventListener('resize', function () { setTimeout(fitPad, 60); });
  function openPop(tab) {
    setTimeout(fitPad, 80); setTimeout(fitPad, 450);
    padCss();
    try { if (KT.__dpFit) KT.__dpFit(); } catch (e) {}
    markOpened();
    var el = document.getElementById('kt-dpop');
    if (el) {
      el.classList.remove('min');
      var p1 = popState(); if (p1.min) { p1.min = false; savePop(p1); }
      if (window.KT && KT.minimized) KT.minimized.arrange();
      var n0 = el.querySelector('.kt-dp-num'); if (n0 && !touchPhone()) n0.focus(); return;
    }
    var st = popState();
    el = document.createElement('div');
    el.id = 'kt-dpop'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Dial pad');
    el.setAttribute('data-kt-keep-brand', '1'); el.setAttribute('data-kt-no-tel', '1');
    el.setAttribute('data-kt-no-close', '1');   // it has its own close; no injected ✕
    el.setAttribute('data-kt-nosteady', '1');   // sized by the person, minimises to a pill: never floored (2026-10-23)
    var W = Math.min(st.w || 300, innerWidth - 16), Hh = Math.min(st.h || 520, innerHeight - 16);
    el.style.width = W + 'px'; el.style.height = Hh + 'px';
    el.style.left = Math.max(8, Math.min(st.x != null ? st.x : innerWidth - W - 24, innerWidth - W - 8)) + 'px';
    el.style.top = Math.max(8, Math.min(st.y != null ? st.y : 64, innerHeight - 60)) + 'px';
    el.innerHTML = '<div class="kdp-head"><span class="kdp-ico" aria-hidden="true">' + HANDSET + '</span><span class="kdp-title">Phone</span>'
      + '<button type="button" class="kdp-btn kdp-min" title="Minimise" aria-label="Minimise">–</button>'
      + '<button type="button" class="kdp-btn kdp-full" title="Maximise" aria-label="Maximise">⤢</button>'
      + '<button type="button" class="kdp-btn kdp-x" title="Close" aria-label="Close">×</button></div>'
      // Its own phone-style bottom bar: kt-tab-band.js would restyle it as a sub-tab band
      // with padding:5px !important, which pushed the labels under Android's nav strip.
      + '<div class="kdp-tabs" role="tablist" data-kt-noband="1">'
      + '<button type="button" data-tab="pad" class="on"><span class="ti">⌨️</span>Keypad</button>'
      + '<button type="button" data-tab="recent"><span class="ti">🕘</span>Recents</button>'
      + '<button type="button" data-tab="contacts"><span class="ti">👥</span>Contacts</button>'
      + '<button type="button" data-tab="missed" hidden><span class="ti">' + ARR_IN + '</span>Missed<span class="tc" hidden></span></button>'
      + '<button type="button" data-tab="vm" hidden><span class="ti">📼</span>Voicemail<span class="tc" hidden></span></button>'
      // Shortcut to Phone → Texts (2026-10-23, Anthony: "add to the dial pad the shortcut to the text messages").
      + '<button type="button" data-tab="texts" hidden title="Text messages on the KiddieTrac line"><span class="ti">💬</span>Texts<span class="tc" hidden></span></button></div>'
      + '<div class="kdp-body kdp-pane" data-pane="pad"><div class="kdp-pad">' + padHtml(true) + '<div class="kdp-line"></div><a class="kdp-open" href="#phone">Open the Phone screen →</a></div><div class="kdp-recent"></div></div>'
      + '<div class="kdp-body kdp-pane" data-pane="list" hidden data-kt-no-tel="1"></div>';
    document.body.appendChild(el);
    wirePad(el, 'dialpad');
    if (touchPhone()) { var nIn = el.querySelector('.kt-dp-num'); if (nIn) nIn.setAttribute('inputmode', 'none'); }
    var line = el.querySelector('.kdp-line');
    var paintLine = function () { line.textContent = dial ? ('From ' + dial.label + (dial.record ? ' · recorded' : '')) : 'Calling from the portal is not switched on for you here.'; };
    paintLine(); w.addEventListener('kt:phone-session', paintLine);
    loadPopRecent(el);
    wirePopTabs(el);
    // Opened = the window (see togglePop): a minimise from an earlier visit is forgotten.
    if (st.min) { var p0 = popState(); p0.min = false; savePop(p0); }
    if (st.max) el.classList.add('max');
    var b = document.getElementById('kt-tb-phone'); if (b) b.setAttribute('aria-pressed', 'true');
    var fullFlag = function () { document.documentElement.classList.toggle('kt-dpop-full', !!document.getElementById('kt-dpop') && !el.classList.contains('min')); };
    fullFlag();
    try { new MutationObserver(fullFlag).observe(el, { attributes: true, attributeFilter: ['class'] }); } catch (e) {}

    el.querySelector('.kdp-x').onclick = function (e) { e.stopPropagation(); closePop(); };
    /* MAXIMISE MEANS THE WINDOW (Anthony, 2026-10-23: "when phone is minimized and i hit
       maximize - maximize the popup"). From minimised it opens the popup again; open, it
       toggles a large view (dial pad and the full list of recent calls). The Phone screen
       is a link inside. */
    var fullBtn = el.querySelector('.kdp-full');
    var paintFull = function () { var big = el.classList.contains('max'); fullBtn.textContent = big ? '❐' : '⤢'; fullBtn.title = big ? 'Restore size' : 'Maximise'; fullBtn.setAttribute('aria-label', fullBtn.title); };
    fullBtn.onclick = function (e) {
      e.stopPropagation();
      var p = popState();
      if (el.classList.contains('min')) { el.classList.remove('min'); p.min = false; }
      else { el.classList.toggle('max'); p.max = el.classList.contains('max'); }
      savePop(p); paintFull(); loadPopRecent(el);
    };
    paintFull();
    el.querySelector('.kdp-open').onclick = function () { closePop(); };
    el.querySelector('.kdp-min').onclick = function (e) {
      e.stopPropagation();
      if (phoneMode()) { closePop(); return; }
      el.classList.toggle('min');
      var p = popState(); p.min = el.classList.contains('min'); savePop(p);
      if (window.KT && KT.minimized) KT.minimized.arrange();
    };
    // Minimised: tapping the pill opens it again.
    el.querySelector('.kdp-head').addEventListener('click', function (e) {
      if (el.classList.contains('min') && !e.target.closest('.kdp-btn') && !el.__dragged) { el.classList.remove('min'); var p = popState(); p.min = false; savePop(p); }
    });
    // Drag by the header (mouse and touch).
    var head = el.querySelector('.kdp-head');
    head.addEventListener('pointerdown', function (e) {
      // Minimised, the pill is moved by kt-minimized.js (and kept clear of other pills).
      if (e.target.closest('.kdp-btn') || innerWidth <= 768 || el.classList.contains('min')) return;
      var sx = e.clientX, sy = e.clientY, ox = el.offsetLeft, oy = el.offsetTop;
      el.__dragged = false;
      head.setPointerCapture(e.pointerId);
      var move = function (ev) {
        if (Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) > 3) el.__dragged = true;
        el.style.left = Math.max(0, Math.min(ox + ev.clientX - sx, innerWidth - 80)) + 'px';
        el.style.top = Math.max(0, Math.min(oy + ev.clientY - sy, innerHeight - 40)) + 'px';
      };
      var up = function () {
        head.removeEventListener('pointermove', move); head.removeEventListener('pointerup', up);
        var p = popState(); p.x = el.offsetLeft; p.y = el.offsetTop; savePop(p);
        setTimeout(function () { el.__dragged = false; }, 0);
      };
      head.addEventListener('pointermove', move); head.addEventListener('pointerup', up);
    });
    // Remember a resize (the CSS corner handle).
    try {
      new ResizeObserver(function () {
        if (el.classList.contains('min') || innerWidth <= 768) return;
        var p = popState(); p.w = el.offsetWidth; p.h = el.offsetHeight; savePop(p);
      }).observe(el);
    } catch (e) {}
    setTimeout(function () { var n = el.querySelector('.kt-dp-num'); if (n && !el.classList.contains('min') && !touchPhone()) n.focus(); }, 50);
  }
  function loadPopRecent(el) {
    var box = el.querySelector('.kdp-recent'); if (!box) return;
    req('GET', '/voice/dialer/calls?who=mine').then(function (j) {
      var rows = (j.calls || []).slice(0, el.classList.contains('max') ? 30 : 5);
      box.innerHTML = rows.length ? '<div style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin-bottom:2px;">Recent</div>'
        + rows.map(function (c) {
          return '<button type="button" data-n="' + esc(c.to) + '" data-nm="' + esc(c.name || '') + '"><span>' + esc(c.name || c.to_pretty) + '</span><span style="color:#16A34A;font-weight:800;">📞</span></button>';
        }).join('') : '';
      box.querySelectorAll('button[data-n]').forEach(function (b) { b.onclick = function () { call(b.getAttribute('data-n'), { name: b.getAttribute('data-nm'), context: 'dialpad-redial' }); }; });
    }).catch(function () { box.innerHTML = ''; });
  }
  /* THE PHONE BUTTON OPENS THE PHONE (2026-10-28, Anthony's desktop video: "clicking on the
     phone it shows minimized when it should just popup as default"). Two things made it so:
       1. openPop() restored the remembered minimise (kt_dialpad_pop.min), so a pad that was
          once minimised - and the Texts tab minimises it on purpose before it goes to
          #phone?tab=texts - came back as the green pill at the bottom of the screen on every
          later click of 📞;
       2. a click on 📞 while the pill was showing CLOSED it (toggle saw "exists" and shut it).
     Now a click on 📞 with the pad minimised brings the window back, and a fresh open is
     always the window; minimise is for this visit only. Measured before the fix in a desktop
     browser: kt_dialpad_pop {min:true} -> toggle() gave class "min"; toggle() again removed it. */
  function togglePop() {
    var el = document.getElementById('kt-dpop');
    if (el && el.classList.contains('min')) { openPop(); return; }
    if (el) closePop(); else openPop();
  }
  w.addEventListener('kt:dialer-called', function () { var el = document.getElementById('kt-dpop'); if (el) setTimeout(function () { loadPopRecent(el); }, 900); });

  /* On a phone the admin top bar is not there: a round 📞 button above the bottom bar. */
  function ensureFab() {
    padCss();
    if (document.getElementById('kt-dp-fab')) return;
    var b = document.createElement('button');
    b.id = 'kt-dp-fab'; b.type = 'button'; b.setAttribute('aria-label', 'Dial pad'); b.textContent = '📞';
    var fb = document.createElement('span'); fb.className = 'kt-fab-badge'; fb.hidden = true; b.appendChild(fb);
    b.onclick = togglePop;
    document.body.appendChild(b);
    paintBadge();
  }
  w.addEventListener('kt:phone-session', function () { if (dial) ensureFab(); });

  /* ── MISSED CALLS + VOICEMAIL (Anthony, 2026-10-23: "if there is a new voicemail show
     this as an indicator on the phone icon on the top bar and allow dialer to show missed
     calls and voicemails"). The toll-free line's calls (App\Support\InboundCalls): a
     platform admin sees them all, a department's alert people their department's. The
     badge = voicemails nobody has heard + missed calls since you last looked. ── */
  var inbox = { allowed: false, voicemail: 0, missed: 0, total: 0 };
  var textsUnread = 0;   // texts on the KiddieTrac line nobody has opened (2026-10-23)
  var HANDSET_SM = '<svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M6.6 10.8a15.2 15.2 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg>';
  var ARR_IN = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#DC2626" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 7 7 17M7 9v8h8"/></svg>', ARR_OUT = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#16A34A" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>';
  function setBadge(node, n) { if (!node) return; node.hidden = !n; node.textContent = n > 99 ? '99+' : String(n || ''); }
  function paintBadge() {
    var n = inbox.allowed ? inbox.total + textsUnread : 0;
    var tb = document.getElementById('kt-tb-phone-badge'); setBadge(tb, n);
    var btn = document.getElementById('kt-tb-phone');
    if (btn) btn.setAttribute('aria-label', n ? 'Phone · ' + (inbox.voicemail ? inbox.voicemail + ' new voicemail' + (inbox.voicemail === 1 ? '' : 's') : '') + (inbox.voicemail && inbox.missed ? ', ' : '') + (inbox.missed ? inbox.missed + ' missed call' + (inbox.missed === 1 ? '' : 's') : '') : 'Phone · dial pad');
    var fab = document.querySelector('#kt-dp-fab .kt-fab-badge'); setBadge(fab, n);
    setBadge(document.getElementById('kt-b-phone'), n);   // the superadmin's bottom-bar Phone (2026-10-23)
    var pop = document.getElementById('kt-dpop'); if (pop) paintTabCounts(pop.querySelector('.kdp-tabs'));
    try { w.dispatchEvent(new CustomEvent('kt:phone-inbox', { detail: inbox })); } catch (e) {}
  }
  function paintTabCounts(bar) {
    if (!bar) return;
    [['missed', inbox.missed], ['vm', inbox.voicemail], ['texts', textsUnread]].forEach(function (p) {
      var b = bar.querySelector('[data-tab="' + p[0] + '"]'); if (!b) return;
      b.hidden = !inbox.allowed;
      setBadge(b.querySelector('.tc'), p[1]);
    });
  }
  var countT = null, countBusy = false;
  function refreshCount() {
    if (!tok() || countBusy) return;
    countBusy = true;
    req('GET', '/voice/dialer/inbox/count').then(function (j) {
      var before = inbox.total;
      inbox = { allowed: !!j.allowed, voicemail: +j.voicemail || 0, missed: +j.missed || 0, total: +j.total || 0 };
      textsUnread = +j.texts || 0;
      try { maybePopup(j); } catch (e) {}
      if (inbox.allowed && document.documentElement.classList.contains('kt-can-dial')) ensureFab();
      paintBadge();
      if (inbox.total !== before) { try { w.dispatchEvent(new CustomEvent('kt:phone-inbox-changed')); } catch (e) {} }
    }).catch(function () {}).then(function () { countBusy = false; });
  }
  function startCount() {
    if (countT) return;
    refreshCount();
    countT = setInterval(function () { if (!document.hidden) refreshCount(); }, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshCount(); });
    // A voicemail push / bell while the page is open: look now, not in a minute.
    w.addEventListener('kt:notification', refreshCount);
    w.addEventListener('kt:phone-call-ended', function () { setTimeout(refreshCount, 4000); });
  }
  w.addEventListener('kt:phone-session', startCount);
  setTimeout(function () { if (tok()) startCount(); }, 4000);

  /* NEW VOICEMAIL / MISSED CALL POPUP (2026-10-23, Anthony: "popup in the portal that there is
     a new voice mail"). Raised once per item, for anything newer than the last one shown in
     this browser and still waiting (counted on the badge): a voicemail first, else a missed
     call. Listen / call back / dismiss; opening the phone dismisses it too. */
  var POPUP_KEY = 'kt_phone_popup';
  function popupMemo() { try { return JSON.parse(localStorage.getItem(POPUP_KEY) || '{}') || {}; } catch (e) { return {}; } }
  function maybePopup(j) {
    if (!j || !j.allowed || !j.latest) return;
    var m = popupMemo(), v = j.latest.voicemail, c = j.latest.missed, show = null;
    if (v && (+j.voicemail > 0) && !v.heard && v.id > (m.vm || 0)) show = ['vm', v];
    else if (c && (+j.missed > 0) && c.id > (m.missed || 0)) show = ['missed', c];
    if (v && v.id > (m.vm || 0) && !(show && show[0] === 'vm') && !(+j.voicemail > 0)) m.vm = v.id;   // already seen elsewhere
    if (!show) { try { localStorage.setItem(POPUP_KEY, JSON.stringify(m)); } catch (e) {} return; }
    m[show[0]] = show[1].id;
    try { localStorage.setItem(POPUP_KEY, JSON.stringify(m)); } catch (e) {}
    phonePopup(show[0], show[1]);
  }
  function dropPhonePopup() { var o = document.getElementById('kt-phone-pop'); if (o) o.remove(); }
  function phonePopup(kind, it) {
    dropPhonePopup();
    var vm = kind === 'vm';
    var el = document.createElement('div');
    el.id = 'kt-phone-pop'; el.setAttribute('role', 'alert'); el.setAttribute('data-kt-no-tel', '1');
    el.style.cssText = 'position:fixed;z-index:9450;right:16px;bottom:16px;width:min(340px,calc(100vw - 32px));background:#fff;border:1px solid #CFE3F1;border-radius:16px;box-shadow:0 18px 40px -16px rgba(15,23,42,.45);overflow:hidden;font-family:inherit;';
    if (innerWidth <= 768) { el.style.top = 'calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 64px)'; el.style.bottom = 'auto'; el.style.left = '16px'; el.style.width = 'auto'; }
    el.innerHTML = '<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:linear-gradient(120deg,#064E3B,#047857 55%,#0EA5E9);color:#fff;">'
      + '<span style="font-size:20px;">' + (vm ? '📼' : '📞') + '</span><div style="flex:1;min-width:0;"><div style="font-weight:800;font-size:14px;">' + (vm ? 'New voicemail' : 'Missed call') + '</div>'
      + '<div style="font-size:12px;opacity:.9;">' + esc(fmtWhen(it.created_at)) + (it.department ? ' · ' + esc(it.department) : '') + (vm && it.duration ? ' · ' + fmtDur(it.duration) : '') + '</div></div>'
      + '<button type="button" class="pp-x" aria-label="Dismiss" style="width:28px;height:28px;border:0;border-radius:8px;background:rgba(255,255,255,.18);color:#fff;font-size:16px;cursor:pointer;">×</button></div>'
      + '<div style="padding:12px 14px;"><div style="font-weight:800;font-size:15px;color:#0F172A;" class="pp-who">' + esc(it.from_pretty) + '</div>'
      + '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">'
      + '<button type="button" class="pp-open" style="flex:1;height:34px;border:0;border-radius:9px;background:#0F766E;color:#fff;font-weight:800;font-size:13px;cursor:pointer;">' + (vm ? '▶ Listen' : 'Open') + '</button>'
      + (valid(it.from) ? '<button type="button" class="pp-cb" style="flex:1;height:34px;border:1px solid #16A34A;border-radius:9px;background:#fff;color:#15803D;font-weight:800;font-size:13px;cursor:pointer;">📞 Call back</button>' : '')
      + '</div></div>';
    document.body.appendChild(el);
    nameFor(it.from, el.querySelector('.pp-who'));
    el.querySelector('.pp-x').onclick = dropPhonePopup;
    el.querySelector('.pp-open').onclick = function () {
      dropPhonePopup();
      openPop();
      var pop = document.getElementById('kt-dpop'); if (pop) { var t = pop.querySelector('.kdp-tabs [data-tab="' + (vm ? 'vm' : 'missed') + '"]'); if (t) t.click(); }
    };
    var cb = el.querySelector('.pp-cb'); if (cb) cb.onclick = function () { dropPhonePopup(); call(it.from, { context: vm ? 'voicemail-callback' : 'missed-callback' }); };
    try { if (navigator.vibrate) navigator.vibrate([60, 40, 60]); } catch (e) {}
    setTimeout(function () { if (el.isConnected) el.remove(); }, 45000);
  }

  /* VOICEMAIL → LEAD (2026-10-23, Anthony: "with voicemails received, I want to be able to
     convert this to a lead"). Name and company (the caller's name from Contacts is filled in
     when we know it), then POST /voice/dialer/voicemails/{id}/lead. If an open lead already
     has the number, offer to add the voicemail to it instead. */
  function vmToLead(b) {
    var id = b.getAttribute('data-lead'), row = b.closest('.kt-ib-row'), who = row && row.querySelector('[data-nm]');
    var known = who && who.getAttribute('title') ? who.textContent : '';
    var send = function (body) {
      b.disabled = true;
      return req('POST', '/voice/dialer/voicemails/' + id + '/lead', body).then(function (r) {
        toast('🎯', r.created ? 'Lead created' : 'Added to lead', r.name);
        b.outerHTML = '<a href="#sales-lead?id=' + r.lead_id + '" title="Open the lead" style="height:28px;display:inline-flex;align-items:center;padding:0 9px;border:1px solid #A7F3D0;background:#ECFDF5;color:#047857;border-radius:7px;font-size:12px;font-weight:800;text-decoration:none;">🎯 Lead</a>';
      }).catch(function (e) {
        b.disabled = false;
        if (e && e.code === 'match' && e.match) {
          var m = e.match;
          return (KT.confirm ? KT.confirm({ title: 'Already a lead?', description: '“' + m.name + '” has this phone number. Add this voicemail to that lead?', okLabel: 'Add to ' + m.name, cancelLabel: 'Create a new lead' }) : Promise.resolve(true))
            .then(function (ok) { return send(Object.assign({}, body, ok ? { existing_id: m.id } : { 'new': true })); });
        }
        toast('⚠️', 'Could not create the lead', (e && e.message) || '', '#B91C1C');
      });
    };
    var ask = KT.prompt ? KT.prompt({ title: 'Turn this voicemail into a lead', description: 'The recording is attached to the lead, and you can open it from Sales.', okLabel: 'Create lead',
      fields: [{ key: 'name', label: 'Caller’s name', value: known || '' }, { key: 'company', label: 'Centre / agency', value: '' }, { key: 'note', label: 'Note (what they asked for)', value: '', type: 'textarea', rows: 6, placeholder: 'What they called about, what they need, when to call back…' }] }) : Promise.resolve({});
    Promise.resolve(ask).then(function (v) { if (v === null || v === undefined || v === false) return; send(typeof v === 'object' ? v : {}); });
  }

  function when(iso) { return fmtWhen(iso); }
  var nameCache = {};
  function nameFor(number, node) {
    var d = digits(number); if (!valid(d)) return;
    var put = function (n) { if (n && node && node.isConnected) { node.textContent = n; node.setAttribute('title', pretty(d)); } };
    if (nameCache[d] !== undefined) { put(nameCache[d]); return; }
    nameCache[d] = null;
    lookup(d).then(function (f) { nameCache[d] = f ? f.name : null; put(nameCache[d]); }).catch(function () {});
  }
  /* Draw Missed or Voicemail into a box. kind = 'missed' | 'vm'. */
  /* TEXTS ON THE KIDDIETRAC LINE (2026-10-23, Anthony: "text message came in from prospect
     but how do i reply and see text messages"). Conversations on the left, the thread on the
     right, a reply box under it. Backend: App\Support\LineTexts (/voice/dialer/texts). */
  function textsCss() {
    if (document.getElementById('kt-tx-css')) return;
    var st = document.createElement('style'); st.id = 'kt-tx-css';
    /* ONE SIZE FOR EVERY CONVERSATION (2026-11-04): the pane is a fixed height and the messages scroll inside it, so the
       reply bar sits in the same place whichever thread is open - it used to grow and shrink with the thread. */
    st.textContent = '.kt-tx{display:grid;grid-template-columns:minmax(0,300px) minmax(0,1fr);grid-template-rows:minmax(0,1fr);gap:12px;height:clamp(420px,calc(100vh - 340px),760px)}'
      + '.kt-tx-list{border:1px solid #E2E8F0;border-radius:12px;overflow:auto;min-height:0}'
      + '.kt-tx-c{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #F1F5F9;background:#fff;padding:10px 12px;cursor:pointer;font:inherit}'
      + '.kt-tx-c:hover{background:#F8FAFC}.kt-tx-c.on{background:#EEF6FB;box-shadow:inset 3px 0 0 #1F6080}'
      + '.kt-tx-c b{display:flex;justify-content:space-between;gap:6px;font-size:13.5px;color:#0F172A}.kt-tx-c .u{background:#DC2626;color:#fff;border-radius:9px;font-size:11px;padding:0 6px;font-weight:800}'
      + '.kt-tx-c small{display:block;color:#64748B;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}'
      + '.kt-tx-th{border:1px solid #E2E8F0;border-radius:12px;display:flex;flex-direction:column;min-width:0;min-height:0;overflow:hidden}'
      /* one line, always: the name and number shorten with … rather than pushing Call / Open lead onto a second row */
      + '.kt-tx-hd{flex:0 0 auto;height:52px;box-sizing:border-box;padding:0 14px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:8px;flex-wrap:nowrap;min-width:0}'
      + '.kt-tx-hd>strong,.kt-tx-hd>span:not([style*="flex:1"]){white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}'
      + '.kt-tx-hd>strong{flex:0 1 auto}.kt-tx-hd>span:not([style*="flex:1"]){flex:0 1000 auto}.kt-tx-hd>a,.kt-tx-hd>button{flex:0 0 auto;white-space:nowrap}'
      + '.kt-tx-msgs{flex:1 1 auto;min-height:0;overflow:auto;padding:12px 14px;background:#F8FAFC;display:flex;flex-direction:column;gap:6px}'
      + '.kt-tx-m{max-width:78%;padding:8px 11px;border-radius:14px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word}'
      + '.kt-tx-m.in{align-self:flex-start;background:#fff;border:1px solid #E2E8F0;color:#0F172A;border-bottom-left-radius:4px}'
      + '.kt-tx-m.out{align-self:flex-end;background:#1F6080;color:#fff;border-bottom-right-radius:4px}.kt-tx-m.out.auto{background:#64748B}'
      + '.kt-tx-m .t{display:block;font-size:10.5px;opacity:.75;margin-top:3px}.kt-tx-m.failed{background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5}'
      /* the reply bar is one height in every thread (no drag handle); a long reply scrolls inside the box */
      + '.kt-tx-rp{flex:0 0 auto;border-top:1px solid #E2E8F0;padding:10px;display:flex;gap:8px;align-items:center;box-sizing:border-box;min-height:64px}'
      + '.kt-tx-rp textarea{flex:1;height:42px;min-height:42px;max-height:42px;resize:none;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:10px;padding:10px;font:inherit;font-size:13.5px;line-height:1.4}'
      + '.kt-tx-rp button{flex:0 0 auto;height:42px;padding:0 18px;border:0;border-radius:10px;background:#0F766E;color:#fff;font-weight:800;cursor:pointer}'
      + '.kt-tx-stop{flex:0 0 auto;min-height:64px;box-sizing:border-box;display:flex;align-items:center;padding:10px 14px;font-size:12.5px;color:#B45309;border-top:1px solid #E2E8F0}'
      + '@media (max-width:760px){.kt-tx{grid-template-columns:1fr;grid-template-rows:none;height:auto}.kt-tx-list{max-height:220px}.kt-tx-th{min-height:420px;max-height:68vh}}'
      /* TEXTS FULL SCREEN ON A PHONE AND IN THE APP (2026-10-23, Anthony: "the text message
         should show only that and not scrollable to anything else ... dont pop up the keyboard").
         The list, or one conversation with a Back button; nothing else on screen, nothing
         behind it scrolls, and the keyboard waits for a tap in the reply box. */
      + '#kt-txfull{position:fixed;left:0;right:0;top:var(--kt-vv-top,0px);height:var(--kt-vv-h,100%);z-index:2147482000;background:#fff;display:flex;flex-direction:column;overscroll-behavior:contain}'
      + '#kt-txfull .kt-txf-hd{display:flex;align-items:center;gap:10px;padding:calc(var(--kt-safe-top, env(safe-area-inset-top,0px)) + 10px) 12px 10px 16px;background:linear-gradient(120deg,#064E3B,#047857 55%,#0EA5E9);color:#fff;flex-shrink:0}'
      + '#kt-txfull .kt-txf-hd b{flex:1;font-size:17px}#kt-txfull .kt-txf-x{width:34px;height:34px;border:0;border-radius:9px;background:rgba(255,255,255,.18);color:#fff;font-size:20px;cursor:pointer}'
      + '#kt-txfull .kt-txf-body{flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column}'
      + '#kt-txfull .kt-tx{display:flex;flex-direction:column;flex:1;min-height:0;gap:0;height:auto}'
      + '#kt-txfull .kt-tx-list{border:0;border-radius:0;max-height:none;flex:1;min-height:0;overscroll-behavior:contain}'
      + '#kt-txfull .kt-tx-th{display:none;border:0;border-radius:0;max-height:none;flex:1;min-height:0}'
      + '#kt-txfull .kt-tx.thread .kt-tx-list{display:none}#kt-txfull .kt-tx.thread .kt-tx-th{display:flex}'
      + '#kt-txfull .kt-tx-msgs{overscroll-behavior:contain}#kt-txfull .kt-tx-rp{padding-bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 10px)}'
      + '#kt-txfull .kt-tx-rp textarea{font-size:16px}'
      + '#kt-txfull .kt-tx-back{height:30px;padding:0 10px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-weight:700;color:#0F172A;cursor:pointer}'
      + 'html.kt-txfull-open,html.kt-txfull-open body{overflow:hidden !important}'
      + '#kt-dpop .kdp-txhost{display:flex;flex-direction:column;padding:0 !important;overflow:hidden !important}'
      + '#kt-dpop .kdp-txhost .kt-tx{display:flex;flex-direction:column;flex:1;min-height:0;gap:0;height:auto}'
      + '#kt-dpop .kdp-txhost .kt-tx-list{border:0;border-radius:0;max-height:none;flex:1;min-height:0;overflow:auto;overscroll-behavior:contain}'
      + '#kt-dpop .kdp-txhost .kt-tx-th{display:none;border:0;border-radius:0;max-height:none;flex:1;min-height:0}'
      + '#kt-dpop .kdp-txhost .kt-tx.thread .kt-tx-list{display:none}#kt-dpop .kdp-txhost .kt-tx.thread .kt-tx-th{display:flex}'
      + '#kt-dpop .kdp-txhost .kt-tx-msgs{overscroll-behavior:contain}'
      + '#kt-dpop .kdp-txhost .kt-tx-rp textarea{font-size:16px}'
      + '#kt-dpop .kdp-txhost .kt-tx-back{height:30px;padding:0 10px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-weight:700;color:#0F172A;cursor:pointer}';
    document.head.appendChild(st);
  }
  /* NEW TEXTS (2026-10-24, Anthony: "how do i create a new text message and allow me to choose
     from my contacts list through kiddietrac and on my phone"). His rule: KiddieTrac contacts and
     leads can be texted; any other number asks "do you have their permission?" first, and the answer
     is recorded with the text (LineTexts::consentBasis). STOP always wins (the server refuses). */
  function sendLineText(digitsTo, body, attested) {
    return fetch(api() + '/voice/dialer/texts/' + digitsTo, { method: 'POST', headers: headers(true), body: JSON.stringify({ body: body, attested: !!attested }) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.ok) return j;
        if (j.needs_permission && !attested) {
          var ask = (KT.confirm ? KT.confirm({ title: 'Do you have their permission?', okLabel: 'Yes — send it',
            description: pretty(digitsTo) + ' is not a KiddieTrac contact or lead and has not texted the line. Only text people who have agreed to hear from KiddieTrac. Your answer is recorded with the text.' })
            : Promise.resolve(w.confirm('Do you have this person’s permission to text them?')));
          return Promise.resolve(ask).then(function (ok) { if (!ok) { var x = new Error('Not sent.'); x.cancelled = true; throw x; } return sendLineText(digitsTo, body, true); });
        }
        throw new Error(j.message || ('HTTP ' + r.status));
      }); });
  }

  function textsList(box) {
    textsCss();
    var cur = null;
    try { var m = (location.hash.split('?')[1] || '').match(/(?:^|&)n=(\d{10})/); if (m) cur = m[1]; } catch (e) {}
    box.innerHTML = '<div style="padding:20px;color:#64748B;">Loading…</div>';
    req('GET', '/voice/dialer/texts').then(function (j) {
      if (!j.allowed) { box.innerHTML = '<div style="padding:22px;color:#64748B;">Texts on the KiddieTrac line are for the line\u2019s team.</div>'; return; }
      textsUnread = +j.unread || 0; paintBadge();
      var cs = j.conversations || [];
      var full = !!box.closest('#kt-txfull, .kdp-txhost');
      if (!cur && cs.length && !full) cur = cs[0].digits;
      box.innerHTML = '<div class="kt-tx" data-kt-keep-brand="1" data-kt-noband="1"><div class="kt-tx-list" role="list"></div><div class="kt-tx-th"></div></div>';
      var list = box.querySelector('.kt-tx-list'), th = box.querySelector('.kt-tx-th'), wrapTx = box.querySelector('.kt-tx');
      function paintList() {
        list.innerHTML = '<div style="padding:10px;border-bottom:1px solid #F1F5F9;position:sticky;top:0;background:#fff;z-index:1;"><button type="button" class="kt-tx-new" style="width:100%;height:36px;border:0;border-radius:9px;background:#0F766E;color:#fff;font-weight:800;cursor:pointer;font-size:13.5px;">✏️ New text</button></div>'
          + (cs.length ? '' : '<div style="padding:18px;text-align:center;color:#64748B;font-size:13px;">No texts yet. Start one with “New text”, or wait for someone to text the KiddieTrac line.</div>')
          + cs.map(function (c) {
          return '<button type="button" data-kt-inpage class="kt-tx-c' + (c.digits === cur ? ' on' : '') + '" data-n="' + c.digits + '"><b><span>' + esc(c.name || c.pretty) + '</span>'
            + (c.unread ? '<span class="u">' + c.unread + '</span>' : '<span style="font-weight:500;color:#94A3B8;font-size:11.5px;">' + esc(fmtWhen(c.last_at)) + '</span>') + '</b>'
            + '<small>' + (c.name ? esc(c.pretty) + ' · ' : '') + (c.last_dir === 'out' ? 'You: ' : '') + esc(c.last_body) + '</small></button>';
        }).join('');
        list.querySelectorAll('[data-n]').forEach(function (b) { b.onclick = function () { cur = b.getAttribute('data-n'); paintList(); openThread(); }; });
        list.querySelector('.kt-tx-new').onclick = function () { cur = null; paintList(); openCompose(); };
      }
      /* The compose pane: who to (search KiddieTrac contacts and leads, type a number, or pick from
         the phone's contacts where the browser allows it), then the message. */
      function openCompose() {
        var PHONE_PICK = canPickPhone();
        if (full) wrapTx.classList.add('thread');
        th.innerHTML = '<div class="kt-tx-hd">' + (full ? '<button type="button" class="kt-tx-back">‹ Back</button>' : '') + '<strong style="font-size:15px;color:#0F172A;">✏️ New text</strong></div>'
          + '<div style="padding:12px 14px;border-bottom:1px solid #E2E8F0;position:relative;">'
          + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><label for="kt-tx-to" style="font-size:12.5px;font-weight:800;color:#475569;">To</label>'
          + '<input id="kt-tx-to" type="search" autocomplete="off" placeholder="Name, company or phone number" style="flex:1;min-width:180px;height:36px;border:1px solid #CBD5E1;border-radius:9px;padding:0 10px;font:inherit;font-size:14px;">'
          + (PHONE_PICK ? '<button type="button" class="kt-tx-phone" style="height:36px;padding:0 12px;border:1px solid #CBD5E1;background:#fff;border-radius:9px;font-weight:700;cursor:pointer;">📱 Phone contacts</button>' : '')
          + '</div><div class="kt-tx-who" style="margin-top:6px;font-size:12.5px;color:#0F766E;font-weight:700;"></div>'
          + '<div class="kt-tx-res" style="display:none;position:absolute;left:14px;right:14px;top:56px;z-index:5;background:#fff;border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 12px 28px -12px rgba(15,23,42,.35);max-height:260px;overflow:auto;"></div></div>'
          + '<div class="kt-tx-msgs" style="justify-content:center;align-items:center;color:#64748B;font-size:13px;text-align:center;">' + (PHONE_PICK ? 'Search your KiddieTrac contacts and leads, type a number, or pick from your phone.' : 'Search your KiddieTrac contacts and leads, or type a number.') + '</div>'
          + '<div class="kt-tx-rp"><textarea maxlength="1000" placeholder="Your message from the KiddieTrac line…"></textarea><button type="button">Send</button></div>';
        var to = th.querySelector('#kt-tx-to'), res = th.querySelector('.kt-tx-res'), who = th.querySelector('.kt-tx-who');
        var ta = th.querySelector('.kt-tx-rp textarea'), send = th.querySelector('.kt-tx-rp button');
        var picked = null;   // { digits, name }
        var back = th.querySelector('.kt-tx-back'); if (back) back.onclick = function () { wrapTx.classList.remove('thread'); paintList(); };
        var choose = function (d, name) { picked = { digits: d, name: name || '' }; to.value = name ? name + ' · ' + pretty(d) : pretty(d); who.textContent = '✓ ' + (name ? name + ' — ' : '') + pretty(d); res.style.display = 'none'; };
        var tSearch = null;
        to.addEventListener('input', function () {
          picked = null; who.textContent = '';
          var q = to.value.trim();
          clearTimeout(tSearch);
          if (valid(q)) { who.textContent = 'Number: ' + pretty(q); }
          tSearch = setTimeout(function () {
            req('GET', '/voice/dialer/text-contacts?q=' + encodeURIComponent(q)).then(function (j2) {
              var rows = (j2.contacts || []).slice(0, 30);
              if (!rows.length) { res.style.display = 'none'; return; }
              res.innerHTML = rows.map(function (c) {
                return '<button type="button" data-d="' + c.digits + '" data-nm="' + esc(c.name) + '" style="display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #F1F5F9;background:#fff;padding:8px 12px;cursor:pointer;font:inherit;">'
                  + '<b style="font-size:13.5px;color:#0F172A;">' + esc(c.name) + '</b><small style="display:block;color:#64748B;font-size:12px;">' + esc(c.pretty) + (c.sub ? ' · ' + esc(c.sub) : '') + '</small></button>';
              }).join('');
              res.style.display = 'block';
              res.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { choose(b.getAttribute('data-d'), b.getAttribute('data-nm')); }; });
            }).catch(function () { res.style.display = 'none'; });
          }, 250);
        });
        to.addEventListener('focus', function () { if (!to.value) to.dispatchEvent(new Event('input')); });
        document.addEventListener('mousedown', function closeRes(ev) { if (!th.isConnected) { document.removeEventListener('mousedown', closeRes); return; } if (!res.contains(ev.target) && ev.target !== to) res.style.display = 'none'; });
        var ph = th.querySelector('.kt-tx-phone');
        if (ph) ph.onclick = function () {
          pickPhoneContact().then(function (c) { if (c) choose(c.digits, c.name); });
        };
        var go = function () {
          var d = picked ? picked.digits : digits(to.value);
          var body = ta.value.trim();
          if (!valid(d)) { toast('⚠️', 'Who to?', 'Pick a contact or type a 10-digit number.', '#B45309'); return; }
          if (!body) { toast('⚠️', 'Write a message first', '', '#B45309'); return; }
          send.disabled = true; send.textContent = 'Sending…';
          sendLineText(d, body).then(function () {
            toast('💬', 'Text sent', pretty(d));
            var ex = cs.filter(function (c) { return c.digits === d; })[0];
            if (ex) { ex.last_body = body; ex.last_dir = 'out'; ex.last_at = new Date().toISOString(); }
            else cs.unshift({ digits: d, pretty: pretty(d), name: picked && picked.name, last_body: body, last_dir: 'out', last_at: new Date().toISOString(), unread: 0 });
            cur = d; paintList(); openThread();
          }).catch(function (e) { send.disabled = false; send.textContent = 'Send'; if (!e.cancelled) toast('⚠️', 'Not sent', e.message || '', '#B91C1C'); });
        };
        send.onclick = go;
        ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); go(); } });
      }
      /* THE OPEN CONVERSATION LIVES IN THE ADDRESS (2026-11-03). The Phone screen is rebuilt by the periodic
         refresh, and the rebuild opened the newest conversation again - "it doesnt show me that text message".
         n= in the hash is what this list already reads on mount, so a rebuild keeps the one being read. Only on
         the Phone screen itself; the pad's Texts tab sits over whatever screen is open. */
      function keepInHash(d) {
        try {
          if (!/^#phone(\?|$)/.test(location.hash) || box.closest('.kdp-txhost')) return;
          var q = (location.hash.split('?')[1] || '').split('&').filter(function (x) { return x && !/^n=/.test(x); });
          if (d) q.push('n=' + d);
          var h = '#phone' + (q.length ? '?' + q.join('&') : '');
          if (h !== location.hash) history.replaceState(history.state, '', h);
        } catch (e) {}
      }
      function openThread() {
        keepInHash(cur);
        th.innerHTML = '<div style="padding:20px;color:#64748B;">Loading…</div>';
        if (full) wrapTx.classList.add('thread');
        req('GET', '/voice/dialer/texts/' + cur).then(function (t) {
          textsUnread = +t.unread || 0; paintBadge();
          cs.forEach(function (c) { if (c.digits === cur) c.unread = 0; }); paintList();
          var canReply = !t.stopped;
          th.innerHTML = '<div class="kt-tx-hd">' + (full ? '<button type="button" class="kt-tx-back">‹ Back</button>' : '') + '<strong style="font-size:15px;color:#0F172A;">' + esc(t.name || t.pretty) + '</strong>'
            + (t.name ? '<span style="color:#64748B;font-size:12.5px;">' + esc(t.pretty) + (t.company && t.company !== t.name ? ' · ' + esc(t.company) : '') + '</span>' : '')
            + '<span style="flex:1"></span>'
            + (t.lead_id ? '<a href="#sales-lead?id=' + t.lead_id + '" style="font-size:12.5px;font-weight:700;color:#1F6080;">🎯 Open lead</a>' : '')
            + '<button type="button" data-call style="height:28px;padding:0 10px;border:0;background:#16A34A;color:#fff;border-radius:7px;font-weight:700;cursor:pointer;font-size:12.5px;">📞 Call</button></div>'
            + '<div class="kt-tx-msgs">' + (t.messages.length ? t.messages.map(function (x) {
                return '<div class="kt-tx-m ' + x.dir + (x.auto ? ' auto' : '') + (x.status === 'failed' ? ' failed' : '') + '">' + esc(x.body)
                  + '<span class="t">' + esc(fmtWhen(x.at)) + (x.dir === 'out' && x.by ? ' · ' + esc(x.by) : '') + (x.status === 'failed' ? ' · not delivered' + (x.error ? ': ' + esc(x.error) : '') : '') + '</span></div>';
              }).join('') : '<div style="color:#64748B;text-align:center;margin:auto;">No texts with this number yet.</div>') + '</div>'
            + (canReply ? '<div class="kt-tx-rp"><textarea maxlength="1000" placeholder="Reply from the KiddieTrac line…"></textarea><button type="button">Send</button></div>'
              : '<div class="kt-tx-stop">' + (t.stopped ? 'This number texted STOP, so it cannot be texted until it sends START.' : 'Only numbers that have texted the line can be answered here.') + '</div>');
          var ms = th.querySelector('.kt-tx-msgs'); ms.scrollTop = ms.scrollHeight;
          var back = th.querySelector('.kt-tx-back'); if (back) back.onclick = function () { wrapTx.classList.remove('thread'); cur = null; keepInHash(null); paintList(); };
          th.querySelector('[data-call]').onclick = function () { call(t.digits, { name: t.name || '', context: 'texts' }); };
          var ta = th.querySelector('.kt-tx-rp textarea'), send = th.querySelector('.kt-tx-rp button');
          if (!ta) return;
          var go = function () {
            var body = ta.value.trim(); if (!body) return;
            send.disabled = true; send.textContent = 'Sending…';
            sendLineText(t.digits, body).then(function () {
              cs.forEach(function (c) { if (c.digits === cur) { c.last_body = body; c.last_dir = 'out'; c.last_at = new Date().toISOString(); } });
              openThread();
            }).catch(function (e) { send.disabled = false; send.textContent = 'Send'; if (!e.cancelled) toast('⚠️', 'Not sent', e.message || '', '#B91C1C'); });
          };
          send.onclick = go;
          ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); go(); } });
          if (!touchPhone()) ta.focus();   // on a phone the keyboard waits for a tap in the box
        }).catch(function (e) { th.innerHTML = '<div style="padding:18px;color:#B91C1C;">' + esc(e.message) + '</div>'; });
      }
      paintList(); if (cur) openThread(); else if (!cs.length && !full) openCompose();
    }).catch(function (e) { box.innerHTML = '<div style="padding:18px;color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  function textsFull() {
    textsCss();
    var o = document.getElementById('kt-txfull');
    if (!o) {
      o = document.createElement('div'); o.id = 'kt-txfull'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-label', 'Texts');
      o.setAttribute('data-kt-keep-brand', '1'); o.setAttribute('data-kt-no-close', '1'); o.setAttribute('data-kt-nosteady', '1'); o.setAttribute('data-kt-noband', '1');
      o.innerHTML = '<div class="kt-txf-hd"><span style="font-size:20px;">💬</span><b>Texts</b><button type="button" class="kt-txf-x" aria-label="Close">×</button></div><div class="kt-txf-body"></div>';
      document.body.appendChild(o);
      o.querySelector('.kt-txf-x').onclick = function () {
        o.remove(); document.documentElement.classList.remove('kt-txfull-open');
        if (/[?&]tab=texts/.test(location.hash)) location.hash = '#phone';
      };
    }
    document.documentElement.classList.add('kt-txfull-open');
    textsList(o.querySelector('.kt-txf-body'));
  }
  // The bar stays under the pad now, so a tap on it to go elsewhere also closes the pad -
  // including a tap on the screen you are already on (no hashchange). Not the Phone item.
  document.addEventListener('click', function (e) {
    var nav = document.getElementById('kt-mobilenav');
    if (!nav || !e.target || !nav.contains(e.target)) return;
    var pad = document.getElementById('kt-dpop');
    if (!pad || pad.classList.contains('min')) return;
    var item = e.target.closest('a, button');
    if (item && /phone/i.test((item.textContent || '') + ' ' + (item.getAttribute('href') || '') + ' ' + (item.getAttribute('data-hash') || ''))) return;
    closePop();
  }, true);
  // The bar stays under the pad now, so a tap on it to go elsewhere also closes the pad.
  w.addEventListener('hashchange', function () {
    var pad = document.getElementById('kt-dpop');
    if (pad && !pad.classList.contains('min') && w.matchMedia && w.matchMedia('(max-width:768px)').matches) { closePop(); }
  });
  w.addEventListener('hashchange', function () { if (!/^#phone/.test(location.hash)) { var o = document.getElementById('kt-txfull'); if (o) { o.remove(); document.documentElement.classList.remove('kt-txfull-open'); } } });

  function inboxList(box, kind) {
    box.innerHTML = '<div class="kt-ib-empty">Loading…</div>';
    req('GET', '/voice/dialer/inbox').then(function (j) {
      if (!j.allowed) { box.innerHTML = '<div class="kt-ib-empty">Missed calls and voicemails on the KiddieTrac line show here for the people they are meant for.</div>'; return; }
      if (j.counts) { inbox = { allowed: true, voicemail: +j.counts.voicemail || 0, missed: +j.counts.missed || 0, total: +j.counts.total || 0 }; paintBadge(); }
      var rows = kind === 'vm' ? (j.voicemails || []) : (j.missed || []);
      if (!rows.length) { box.innerHTML = '<div class="kt-ib-empty">' + (kind === 'vm' ? '📼 No voicemails.' : '✓ No missed calls.') + '</div>'; return; }
      box.innerHTML = rows.map(function (r) {
        var isVm = kind === 'vm';
        var fresh = isVm ? !r.heard : r.new;
        var sub = (r.department ? esc(r.department) + ' · ' : '') + esc(when(r.created_at))
          + (isVm && r.duration ? ' · ' + fmtDur(r.duration) : '')
          + (!isVm && r.voicemail_id ? ' · 📼 left a voicemail' : '');
        // What they said (2026-10-23): the transcript, a line of it here, all of it on a tap.
        var said = isVm && r.transcript ? '<div class="ib-said" title="Tap to read it all" style="font-size:12px;color:#334155;margin-top:3px;line-height:1.4;cursor:pointer;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">“' + esc(r.transcript) + '”</div>' : '';
        return '<div class="kt-ib-row ' + (isVm ? 'vm' : 'miss') + '">'
          + '<div class="ib-ico">' + (isVm ? '📼' : ARR_IN) + '</div>'
          + '<div class="ib-main"><div class="ib-who">' + (fresh ? '<span class="ib-new" aria-label="New"></span>' : '') + '<span data-nm="' + esc(r.from) + '">' + esc(r.from_pretty) + '</span></div><div class="ib-sub">' + sub + '</div>' + said + '</div>'
          + '<div class="ib-acts">'
          + (isVm && r.has_audio ? '<button type="button" data-vm="' + r.id + '" title="Play">▶ Play</button>' : '')
          + (isVm ? (r.lead_id ? '<a href="#sales-lead?id=' + r.lead_id + '" title="Open the lead" style="height:28px;display:inline-flex;align-items:center;padding:0 9px;border:1px solid #A7F3D0;background:#ECFDF5;color:#047857;border-radius:7px;font-size:12px;font-weight:800;text-decoration:none;">🎯 Lead</a>'
                       : '<button type="button" data-lead="' + r.id + '" title="Turn this voicemail into a sales lead">➕ Lead</button>') : '')
          + (!isVm && r.voicemail_id ? '<button type="button" data-golist="vm" title="Open the voicemail">📼</button>' : '')
          + (isVm ? '<button type="button" class="del" data-del="' + r.id + '" title="Delete this voicemail" aria-label="Delete this voicemail">' + BIN + '</button>' : '')
          + (valid(r.from) ? '<button type="button" class="cb" data-cb="' + esc(r.from) + '" title="Call back" aria-label="Call back">' + HANDSET_SM + '</button>' : '')
          + '</div></div>';
      }).join('');
      box.querySelectorAll('[data-nm]').forEach(function (n) { nameFor(n.getAttribute('data-nm'), n); });
      box.querySelectorAll('[data-cb]').forEach(function (b) {
        b.onclick = function () { var nm = b.closest('.kt-ib-row').querySelector('[data-nm]'); call(b.getAttribute('data-cb'), { name: nm && nm.getAttribute('title') ? nm.textContent : '', context: kind === 'vm' ? 'voicemail-callback' : 'missed-callback' }); };
      });
      box.querySelectorAll('[data-vm]').forEach(function (b) { b.onclick = function () { playVm(b); }; });
      box.querySelectorAll('[data-lead]').forEach(function (b) { b.onclick = function () { vmToLead(b); }; });
      box.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { vmDelete(b, box); }; });
      box.querySelectorAll('.ib-said').forEach(function (d) { d.onclick = function () { var open = d.style.webkitLineClamp === 'unset'; d.style.webkitLineClamp = open ? '2' : 'unset'; d.style.display = open ? '-webkit-box' : 'block'; }; });
      box.querySelectorAll('[data-golist]').forEach(function (b) {
        b.onclick = function () {
          var pop = b.closest('#kt-dpop');
          if (pop) { var t = pop.querySelector('.kdp-tabs [data-tab="vm"]'); if (t) t.click(); }
          else { var t2 = document.querySelector('.kt-dp-tabs2 [data-tab="vm"]'); if (t2) t2.click(); }
        };
      });
      if (kind === 'missed' && rows.some(function (r) { return r.new; })) {
        req('POST', '/voice/dialer/inbox/seen').then(function (c) { inbox = { allowed: true, voicemail: +c.voicemail || 0, missed: +c.missed || 0, total: +c.total || 0 }; paintBadge(); }).catch(function () {});
      }
    }).catch(function (e) { box.innerHTML = '<div class="kt-ib-empty" style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }
  /* Delete a voicemail (2026-10-25). Asked first: the recording is removed for everyone,
     here and at the carrier, and cannot be brought back. */
  function vmDelete(b, box) {
    var id = b.getAttribute('data-del');
    var row = b.closest('.kt-ib-row');
    var who = row && row.querySelector('[data-nm]') ? row.querySelector('[data-nm]').textContent : 'this caller';
    var ask = KT.confirm ? KT.confirm({ title: 'Delete this voicemail?', okLabel: 'Delete', danger: true,
        description: 'The voicemail from ' + who + ' is removed for everyone on the line, with its recording. This cannot be undone.' })
      : Promise.resolve(w.confirm('Delete this voicemail? This cannot be undone.'));
    Promise.resolve(ask).then(function (ok) {
      if (!ok) return;
      b.disabled = true;
      req('DELETE', '/voice/dialer/voicemails/' + id).then(function () {
        if (row) row.remove();
        toast('🗑️', 'Voicemail deleted', who);
        if (box && !box.querySelector('.kt-ib-row')) box.innerHTML = '<div class="kt-ib-empty">📼 No voicemails.</div>';
        refreshCount();
      }).catch(function (e) { b.disabled = false; toast('⚠️', 'Not deleted', (e && e.message) || 'Please try again.', '#B91C1C'); });
    });
  }
  function playVm(b) {
    var id = b.getAttribute('data-vm');
    b.disabled = true; b.textContent = '…';
    fetch(api() + '/voice/dialer/voicemails/' + id + '/audio', { headers: headers() }).then(function (r) { if (!r.ok) throw new Error('That voicemail is not available.'); return r.blob(); })
      .then(function (blob) {
        var au = document.createElement('audio'); au.controls = true; au.autoplay = true; au.src = URL.createObjectURL(blob);
        var row = b.closest('.kt-ib-row');
        b.remove();
        if (row) row.appendChild(au); else document.body.appendChild(au);
        var dot = au.closest('.kt-ib-row') && au.closest('.kt-ib-row').querySelector('.ib-new'); if (dot) dot.remove();
        setTimeout(refreshCount, 600);
      }).catch(function (e) { b.disabled = false; b.textContent = '▶ Play'; toast('⚠️', 'Could not play', e.message, '#B91C1C'); });
  }
  /* The popup's tabs. */
  function wirePopTabs(el) {
    var bar = el.querySelector('.kdp-tabs'), pad = el.querySelector('[data-pane="pad"]'), list = el.querySelector('[data-pane="list"]');
    paintTabCounts(bar);
    bar.querySelectorAll('[data-tab]').forEach(function (b) {
      b.onclick = function () {
        var t = b.getAttribute('data-tab');
        // Texts need the room of the Phone screen: go there, and tuck the pad away.
        /* Phone: Texts open INSIDE the pad, under its tab row (2026-10-27, Anthony: "when
           navigating from the dialpad tool bar the toolbar disappears"). */
        if (t === 'texts' && phoneMode()) {
          bar.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
          el.setAttribute('data-tab', t);
          pad.hidden = true; list.hidden = false;
          list.classList.add('kdp-txhost');
          textsList(list);
          return;
        }
        list.classList.remove('kdp-txhost');
        if (t === 'texts') { if (phoneMode()) closePop(); else if (!el.classList.contains('min')) el.querySelector('.kdp-min').click(); location.hash = '#phone?tab=texts'; return; }   // the real minimise: it also lays the pill out
        bar.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
        el.setAttribute('data-tab', t);
        pad.hidden = t !== 'pad'; list.hidden = t === 'pad';
        if (t === 'pad') setTimeout(fitPad, 30);
        if (t === 'recent') recentList(list);
        else if (t === 'contacts') contactsList(list);
        else if (t !== 'pad') inboxList(list, t);
      };
    });
    w.addEventListener('kt:phone-inbox-changed', function () {
      var t = el.getAttribute('data-tab'); if (el.isConnected && (t === 'missed' || t === 'vm')) inboxList(list, t);
    });
  }
  /* EVERYONE IN THE PORTAL'S CONTACTS (Anthony, 2026-10-23: "include on the bottom bar
     contacts which brings up all contacts in the portal"). The same list as the Contacts
     screen - the address book, staff and parents of the agency you are in (GET /contacts,
     scoped there) - with a search box, and a tap to call. Entries with no phone number are
     left out (counted), since there is nothing to dial. */
  function contactsList(box) {
    box.innerHTML = '<div class="kt-ct-search"><input type="search" placeholder="Search contacts" aria-label="Search contacts" autocomplete="off"></div>'
      + '<div class="kt-ct-meta"></div><div class="kt-ct-rows"><div class="kt-ib-empty">Loading…</div></div>';
    var input = box.querySelector('input'), rows = box.querySelector('.kt-ct-rows'), meta = box.querySelector('.kt-ct-meta');
    var seq = 0, t = null;
    var initials = function (n) { var p = String(n || '').trim().split(/\s+/).filter(Boolean); return p.length ? (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() : '?'; };
    var load = function () {
      var my = ++seq, q = input.value.trim();
      req('GET', '/contacts?per_page=200' + (q ? '&search=' + encodeURIComponent(q) : '')).then(function (j) {
        if (my !== seq) return;
        var all = j.contacts || [];
        var list = all.map(function (c) { return { name: c.display_name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.company || 'Contact', sub: [c.category, c.company].filter(Boolean).join(' · '), phone: c.mobile || c.phone || '' }; })
          .filter(function (c) { return valid(c.phone); })
          .sort(function (a, b) { return a.name.localeCompare(b.name); });
        var hidden = all.length - list.length, total = (j.meta && j.meta.total) || all.length;
        meta.textContent = list.length + ' with a phone number' + (hidden > 0 ? ' · ' + hidden + ' without one' : '') + (total > all.length ? ' · showing the first ' + all.length + ' of ' + total + ' - search to narrow' : '');
        if (!list.length) { rows.innerHTML = '<div class="kt-ib-empty">' + (q ? 'Nobody matches “' + esc(q) + '”.' : 'No contacts with a phone number yet.') + '</div>'; return; }
        rows.innerHTML = list.map(function (c) {
          return '<div class="kt-ib-row kt-ctrow" data-cb="' + esc(c.phone) + '" data-name="' + esc(c.name) + '">'
            + '<div class="ib-ico">' + esc(initials(c.name)) + '</div>'
            + '<div class="ib-main"><div class="ib-who">' + esc(c.name) + '</div><div class="ib-sub">' + esc(pretty(c.phone)) + (c.sub ? ' · ' + esc(c.sub) : '') + '</div></div>'
            + '<div class="ib-acts"><button type="button" class="cb" title="Call" aria-label="Call ' + esc(c.name) + '">' + HANDSET_SM + '</button></div></div>';
        }).join('');
        rows.querySelectorAll('.kt-ib-row.kt-ctrow').forEach(function (r) {
          r.onclick = function () { call(r.getAttribute('data-cb'), { name: r.getAttribute('data-name'), context: 'contacts' }); };
        });
      }).catch(function (e) {
        if (my !== seq) return;
        rows.innerHTML = '<div class="kt-ib-empty">' + (/403|not allowed|permission/i.test(e.message || '') ? 'Your role does not have the contacts list.' : esc(e.message)) + '</div>';
      });
    };
    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 250); });
    load();
  }
  /* WHAT HAPPENED, AT A GLANCE (2026-10-25). One drawn icon per outcome, in the outcome's
     colour: [svg, circle background, text colour]. */
  var BIN = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#B91C1C" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';
  function svgI(path, col) { return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="' + col + '" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>'; }
  var PH = 'M5 4h3.5l1.5 4-2 1.5a11 11 0 0 0 5 5l1.5-2 4 1.5V18a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z';
  var CALL_ICONS = {
    talked:   [svgI('<path d="' + PH + '"/><path d="M15 3.5l2 2 3.5-3.5"/>', '#15803D'), '#DCFCE7', '#15803D'],            // a call that connected: phone + tick
    hungup:   [svgI('<path d="M3 15.5c5-4.5 13-4.5 18 0l-2.4 2.6-3.4-1.6v-2.4a11 11 0 0 0-6.4 0v2.4l-3.4 1.6z"/><path d="M12 3v6M9.5 6.5 12 9l2.5-2.5"/>', '#15803D'), '#DCFCE7', '#15803D'],   // you hung up: receiver down, arrow down
    theyhung: [svgI('<path d="M3 15.5c5-4.5 13-4.5 18 0l-2.4 2.6-3.4-1.6v-2.4a11 11 0 0 0-6.4 0v2.4l-3.4 1.6z"/><path d="M12 9V3M9.5 5.5 12 3l2.5 2.5"/>', '#0F766E'), '#CCFBF1', '#0F766E'],   // they hung up: receiver down, arrow up
    noanswer: [svgI('<path d="' + PH + '"/><path d="M17 3v4M17 9.5v.5"/>', '#B45309'), '#FEF3C7', '#B45309'],                 // phone + "!"
    busy:     [svgI('<path d="' + PH + '"/><path d="M14.5 4.5h6M14.5 7.5h6"/>', '#B45309'), '#FFEDD5', '#C2410C'],             // phone + busy lines
    failed:   [svgI('<path d="' + PH + '"/><path d="M15 3l6 6M21 3l-6 6"/>', '#B91C1C'), '#FEE2E2', '#B91C1C'],                // phone + cross
    canceled: [svgI('<path d="' + PH + '"/><path d="M15 6h6"/>', '#475569'), '#F1F5F9', '#475569'],                            // phone + dash: stopped before an answer
    live:     [svgI('<path d="' + PH + '"/><path d="M15 6.5a3 3 0 0 1 3 3M15 2.5a7 7 0 0 1 7 7"/>', '#1D4ED8'), '#DBEAFE', '#1D4ED8'],   // ringing / on a call
  };
  function callIcon(st) {
    var k = { completed: 'talked', answered: 'live', 'hung-up': 'hungup', 'ended-by-them': 'theyhung', 'no-answer': 'noanswer', busy: 'busy',
      failed: 'failed', canceled: 'canceled', dialing: 'live', ringing: 'live' }[st];
    return CALL_ICONS[k] || [ARR_OUT, '#DCFCE7', '#15803D'];
  }
  function recentList(box) {
    box.innerHTML = '<div class="kt-ib-empty">Loading…</div>';
    req('GET', '/voice/dialer/calls?who=mine').then(function (j) {
      var rows = j.calls || [];
      if (!rows.length) { box.innerHTML = '<div class="kt-ib-empty">No calls yet.</div>'; return; }
      box.innerHTML = rows.slice(0, 40).map(function (c) {
        var s = STATUS[c.status] || [c.status];
        var ic = callIcon(c.status);
        return '<div class="kt-ib-row mine"><div class="ib-ico" title="' + esc(s[0]) + '" style="background:' + ic[1] + ';">' + ic[0] + '</div><div class="ib-main"><div class="ib-who">' + esc(c.name || c.to_pretty) + '</div>'
          + '<div class="ib-sub"><span style="color:' + ic[2] + ';font-weight:700;">' + esc(s[0]) + '</span> · ' + esc(when(c.created_at)) + (c.duration ? ' · ' + fmtDur(c.duration) : '') + '</div></div>'
          + '<div class="ib-acts"><button type="button" class="cb" data-cb="' + esc(c.to) + '" data-name="' + esc(c.name || '') + '" title="Call again" aria-label="Call again">' + HANDSET_SM + '</button></div></div>';
      }).join('');
      box.querySelectorAll('[data-cb]').forEach(function (b) { b.onclick = function () { call(b.getAttribute('data-cb'), { name: b.getAttribute('data-name'), context: 'redial' }); }; });
    }).catch(function (e) { box.innerHTML = '<div class="kt-ib-empty" style="color:#B91C1C;">' + esc(e.message) + '</div>'; });
  }

  /* The banner's live state: a steady red pulse while a call is on. */
  function heroState() {
    var h = document.querySelector('.kt-phone-hero'); if (!h) return;
    var busy = !!(KT.portalPhone && KT.portalPhone.state().busy);
    h.classList.toggle('on-call', busy);
  }
  w.addEventListener('kt:dialer-called', function () { setTimeout(heroState, 50); });
  w.addEventListener('kt:phone-call-ended', heroState);

  function render(container) {
    var who = 'mine';
    padCss();
    markOpened();   // opening the Phone screen clears the 📞 badge too
    container.innerHTML = '<div class="kt-section" style="padding:18px 20px;">'
      // THE BANNER, this screen's own (Anthony: "phone hero banner needs to relate to the
      // phone icon and animation as well (make this unique always)").
      + '<div class="kt-hero kt-phone-hero" data-kt-iconized="1" data-kt-keep-brand="1" data-kt-no-tel="1">'
      + '<div class="kp-eyebrow">Calls from the portal</div><h1>📞 Phone</h1>'
      + '<div class="kp-sub kt-dp-line"></div>' + heroSvg() + '</div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin:-4px 0 12px;">'
      + '<button type="button" class="kt-dp-pop" style="height:32px;padding:0 12px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">⧉ Pop out</button>'
      + '<button type="button" class="kt-dp-alerts" style="display:none;height:32px;padding:0 12px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">🔔 Alerts</button>'
      + '<button type="button" class="kt-dp-set" style="display:none;height:32px;padding:0 12px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">⚙ Settings</button></div>'
      + '<div style="display:grid;grid-template-columns:minmax(0,300px) minmax(0,1fr);gap:18px;" class="kt-dp-grid">'
      + '<div class="kt-card kt-dp" style="padding:16px;border:1px solid #E2E8F0;border-radius:14px;background:#fff;align-self:start;">'
      + padHtml(false)
      + '<div class="kt-dp-note" style="font-size:11.5px;color:#64748B;margin-top:8px;line-height:1.45;"></div></div>'
      + '<div class="kt-card" style="padding:14px 16px;border:1px solid #E2E8F0;border-radius:14px;background:#fff;min-width:0;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;"><div class="kt-dp-tabs2">'
      + '<button type="button" data-tab="recent" class="on">Calls made</button>'
      + '<button type="button" data-tab="contacts">Contacts</button>'
      + '<button type="button" data-tab="missed" hidden>Missed<span class="tc" hidden></span></button>'
      + '<button type="button" data-tab="vm" hidden>Voicemail<span class="tc" hidden></span></button>'
      + '<button type="button" data-tab="texts" hidden>Texts<span class="tc" hidden></span></button></div>'
      + '<select class="kt-dp-who" style="height:30px;font-size:12.5px;border:1px solid #CBD5E1;border-radius:7px;display:none;"><option value="mine">My calls</option><option value="all">Everyone&rsquo;s</option></select></div>'
      /* ONE SEARCH BOX FOR EVERY TAB (2026-11-06, Anthony: sub-tabs missing the filter field - full sweep). Only
         Contacts could be searched; Calls made, Missed, Voicemail and Texts now filter as you type. Contacts keeps
         its own box, which searches the whole contacts book on the server. */
      + '<div class="kt-ct-search kt-dp-q" style="position:static;"><input type="search" placeholder="Search calls" aria-label="Search this list" autocomplete="off"></div>'
      + '<div class="kt-dp-list" data-kt-no-tel="1"><div style="padding:20px;color:#64748B;">Loading…</div></div></div></div>'
      + '<div class="kt-dp-alertbox" style="margin-top:18px;"></div>'
      + '<div class="kt-dp-settings" data-kt-keep-brand="1" style="margin-top:18px;"></div></div>'
      /* ONE PANEL SIZE FOR EVERY TAB (2026-11-04, Anthony: "calls made is no scrollable and the window view size for the
         content is not consistent to the other tabs such as texts, voice mail"). On a computer every list sits in the
         Texts pane's height and scrolls inside it; Texts brings its own scrolling, so its box does not scroll twice. */
      + '<style>@media (min-width:761px){.kt-dp-list{height:clamp(420px,calc(100vh - 340px),760px);overflow:auto;overscroll-behavior:contain;border:1px solid #E2E8F0;border-radius:12px}'
      + '.kt-dp-list.kt-dp-txmode{overflow:hidden;border:0;border-radius:0}}'
      + '@media (max-width:760px){.kt-dp-grid{grid-template-columns:1fr !important}.kt-dp-pop{display:none !important}'
      /* The Phone screen's own keypad on a phone, like the pad (2026-10-27 sweep): its key grid
         was being re-laid in TWO columns (1 2 / 3 4...) by the field packer, and the number and
         Who boxes were plain bordered inputs. Locked to three columns, same look as the pad. */
      + 'html body #appMain .kt-dp-grid .kt-dp-keys.kt-dp-keys{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:12px 0 !important;margin-top:16px !important;max-width:340px;margin-left:auto !important;margin-right:auto !important}'
      + '.kt-dp-grid .kt-dp-keys button{width:70px !important;height:70px !important;max-width:none !important;color:#0F172A;background:linear-gradient(180deg,#F8FAFC,#EEF2F7) !important;box-shadow:0 1px 2px rgba(15,23,42,.06),inset 0 0 0 1px #E2E8F0}'
      + '.kt-dp-grid .kt-dp-keys button:active{background:#DCFCE7 !important;transform:scale(.94)}'
      + 'html body #appMain .kt-dp-grid input.kt-dp-num.kt-dp-num{border:0 !important;background:transparent !important;box-shadow:none !important;height:56px !important;font-size:30px !important;font-weight:600 !important;max-width:none !important;width:100% !important}'
      + '.kt-dp-grid .kt-dp-num::placeholder{color:#CBD5E1;font-size:22px}'
      + 'html body #appMain .kt-dp-grid input.kt-dp-name.kt-dp-name{display:block;width:220px !important;max-width:70% !important;margin:6px auto 0 !important;height:32px !important;border-radius:999px !important;text-align:center;font-size:13px !important;background:#fff !important}'
      + '.kt-dp-grid .kt-dp-who:empty{min-height:0 !important;margin:0 !important}'
      + '.kt-dp-grid .kt-dp-actions{display:grid !important;grid-template-columns:1fr 72px 1fr;align-items:center;gap:0 !important;max-width:340px;margin-left:auto !important;margin-right:auto !important}'
      + '.kt-dp-grid .kt-dp-redial{grid-column:1;justify-self:center;color:#047857 !important;border-color:#BBF7D0 !important;background:#F0FDF4 !important}'
      + '.kt-dp-grid .kt-dp-call{grid-column:2;background:linear-gradient(135deg,#22C55E,#15803D) !important}'
      + '}</style>';
    wirePad(container, 'phone');
    container.querySelector('.kt-dp-pop').onclick = openPop;
    heroState();

    function paintLine() {
      var line = container.querySelector('.kt-dp-line'), note = container.querySelector('.kt-dp-note'), btn = container.querySelector('.kt-dp-call');
      if (dial) {
        line.textContent = 'Call families, staff and leads from your browser. They see ' + dial.label + (dial.record ? ', and hear that the call may be recorded.' : '.');
        note.textContent = 'Your browser rings first and connects by itself; then the person you are calling rings. Keep this tab open during the call.';
        btn.disabled = false; btn.style.opacity = 1;
      } else {
        line.textContent = 'Calling from the portal is not switched on for you here.';
        note.textContent = 'An admin turns it on, and chooses who may call, under ⚙ Settings.';
        btn.disabled = true; btn.style.opacity = .5;
      }
    }
    paintLine();
    w.addEventListener('kt:phone-session', paintLine);

    var tab = 'recent';
    var tabs = container.querySelector('.kt-dp-tabs2');
    tabs.querySelectorAll('[data-tab]').forEach(function (b) {
      b.onclick = function () {
        tab = b.getAttribute('data-tab');
        tabs.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
        whoSel.style.visibility = tab === 'recent' ? '' : 'hidden';
        load();
      };
    });
    var paintTabs = function () { paintTabCounts(tabs); };
    paintTabs(); w.addEventListener('kt:phone-inbox', paintTabs);
    // From a notification ("#phone?tab=vm") or a deep link, open on that list.
    try { var want = (location.hash.split('?')[1] || '').match(/tab=(missed|vm|texts)/); if (want) { tab = want[1]; } } catch (e) {}
    var qBox = container.querySelector('.kt-dp-q'), qIn = qBox.querySelector('input');
    var qPh = { recent: 'Search calls', missed: 'Search missed calls', vm: 'Search voicemails', texts: 'Search texts' };
    function applyQ() {
      var q = (qIn.value || '').trim().toLowerCase(), qd = q.replace(/\D/g, '');
      var lst = container.querySelector('.kt-dp-list');
      if (!lst || tab === 'contacts') return;
      lst.querySelectorAll('.kt-dp-list > table > tbody > tr, .kt-ib-row, .kt-tx-c').forEach(function (r) {
        var t = (r.textContent || '').toLowerCase();
        r.style.display = !q || t.indexOf(q) !== -1 || (qd.length >= 3 && t.replace(/\D/g, '').indexOf(qd) !== -1) ? '' : 'none';
      });
    }
    qIn.addEventListener('input', applyQ);
    // Rows arrive after a fetch, and the inbox repaints itself: filter whatever lands.
    try { new MutationObserver(function () { if (qIn.value) applyQ(); }).observe(container.querySelector('.kt-dp-list'), { childList: true, subtree: true }); } catch (e) {}
    function load() {
      var list = container.querySelector('.kt-dp-list');
      qBox.style.display = tab === 'contacts' ? 'none' : '';
      qIn.placeholder = qPh[tab] || 'Search';
      tabs.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-tab') === tab); });
      list.classList.toggle('kt-dp-txmode', tab === 'texts');
      list.scrollTop = 0;
      if (tab === 'contacts') { contactsList(list); return; }
      if (tab === 'texts') {
        if (phoneMode()) {   // full screen on a phone / in the app
          list.innerHTML = '<div style="padding:22px;text-align:center;"><button type="button" data-txf style="height:36px;padding:0 16px;border:0;border-radius:9px;background:#0F766E;color:#fff;font-weight:800;cursor:pointer;">💬 Open texts</button></div>';
          list.querySelector('[data-txf]').onclick = textsFull; textsFull(); return;
        }
        textsList(list); return;
      }
      if (tab !== 'recent') { inboxList(list, tab); return; }
      req('GET', '/voice/dialer/calls?who=' + who).then(function (j) {
        var rows = j.calls || [];
        if (!rows.length) { list.innerHTML = '<div style="padding:22px;text-align:center;color:#64748B;">No calls yet.</div>'; return; }
        list.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:13px;" data-kt-no-filter="1"><tbody>' + rows.map(function (c) {
          var s = STATUS[c.status] || [c.status, '#475569', '#F1F5F9'];
          var ic = callIcon(c.status);   // the same icons as the dial pad's Recents (2026-10-25)
          return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:8px 2px 8px 6px;width:38px;"><span title="' + esc(s[0]) + '" style="width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:' + ic[1] + ';">' + ic[0] + '</span></td>'
            + '<td style="padding:8px 6px;"><div style="font-weight:700;color:#0F172A;">' + esc(c.name || c.to_pretty) + '</div>'
            + '<div style="color:#64748B;font-size:12px;">' + esc(c.name ? c.to_pretty + ' · ' : '') + esc(fmtWhen(c.created_at)) + (who === 'all' && c.by ? ' · ' + esc(c.by) : '') + '</div></td>'
            + '<td style="padding:8px 6px;white-space:nowrap;"><span style="font-size:11px;font-weight:800;color:' + s[1] + ';background:' + s[2] + ';border-radius:6px;padding:2px 7px;">' + esc(s[0]) + '</span>'
            + (c.duration ? ' <span style="color:#64748B;font-size:12px;">' + fmtDur(c.duration) + '</span>' : '') + '</td>'
            + '<td style="padding:8px 6px;text-align:right;white-space:nowrap;">'
            + (c.has_recording ? '<button type="button" data-play="' + c.id + '" title="Play the recording" style="height:28px;padding:0 9px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;cursor:pointer;font-size:12px;">▶ Recording</button> ' : '')
            + '<button type="button" data-redial="' + esc(c.to) + '" data-name="' + esc(c.name || '') + '" title="Call again" style="height:28px;padding:0 9px;border:0;background:#16A34A;color:#fff;border-radius:7px;cursor:pointer;font-size:12px;font-weight:700;">📞</button></td></tr>';
        }).join('') + '</tbody></table>';
        list.querySelectorAll('[data-redial]').forEach(function (b) { b.onclick = function () { call(b.getAttribute('data-redial'), { name: b.getAttribute('data-name'), context: 'redial' }); }; });
        list.querySelectorAll('[data-play]').forEach(function (b) { b.onclick = function () { play(b); }; });
      }).catch(function (e) { list.innerHTML = '<div style="padding:18px;color:#B91C1C;">' + esc(e.message) + '</div>'; });
    }
    function play(b) {
      var id = b.getAttribute('data-play');
      b.disabled = true; b.textContent = 'Loading…';
      fetch(api() + '/voice/dialer/calls/' + id + '/recording', { headers: headers() }).then(function (r) { if (!r.ok) throw new Error('That recording is not available.'); return r.blob(); })
        .then(function (blob) {
          var au = document.createElement('audio'); au.controls = true; au.autoplay = true; au.src = URL.createObjectURL(blob); au.style.cssText = 'width:220px;height:30px;vertical-align:middle;';
          b.replaceWith(au);
        }).catch(function (e) { b.disabled = false; b.textContent = '▶ Recording'; toast('⚠️', 'Could not play', e.message, '#B91C1C'); });
    }
    load();
    var onCall = function () { setTimeout(load, 800); };
    w.addEventListener('kt:phone-inbox-changed', function () { if (tab !== 'recent' && container.isConnected) load(); });
    w.addEventListener('kt:dialer-called', onCall);
    w.addEventListener('kt:phone-call-ended', onCall);
    var whoSel = container.querySelector('.kt-dp-who');
    whoSel.onchange = function () { who = whoSel.value; load(); };

    /* 🔔 ALERTS: how this person hears about missed calls and voicemails with the app
       closed - app notification, email, text - each switchable (App\Support\CallAlerts,
       Anthony 2026-10-23). Shown to the people the line's calls are for. */
    req('GET', '/voice/dialer/alerts').then(function (a) {
      if (!a || !a.allowed) return;
      var btn = container.querySelector('.kt-dp-alerts'), box = container.querySelector('.kt-dp-alertbox'), open = false;
      btn.style.display = '';
      btn.onclick = function () { open = !open; box.innerHTML = open ? alertsHtml(a) : ''; if (open) wireAlerts(box, a); };
      try { if (/[?&]alerts=1/.test(location.hash)) btn.click(); } catch (e) {}
    }).catch(function () {});

    /* Settings, for whoever may change them (platform and/or this agency). */
    req('GET', '/voice/dialer/settings').then(function (s) {
      if (!s.platform && !s.agency) return;
      whoSel.style.display = '';
      var btn = container.querySelector('.kt-dp-set'); btn.style.display = '';
      var box = container.querySelector('.kt-dp-settings'), open = false;
      btn.onclick = function () { open = !open; box.innerHTML = open ? settingsHtml(s) : ''; if (open) wireSettings(box, s); };
    }).catch(function () {});
  }

  function alertsHtml(a) {
    var p = a.prefs || {}, rows = [['missed', 'Missed calls', 'Nobody answered and no message was left'], ['voicemail', 'Voicemails', 'A caller left a message']];
    var cell = function (k, w) { return '<td style="text-align:center;padding:8px 6px;"><input type="checkbox" data-k="' + k + '" data-w="' + w + '"' + (p[k] && p[k][w] ? ' checked' : '') + ' aria-label="' + k + ' ' + w + '" style="width:18px;height:18px;"></td>'; };
    return '<div class="kt-card" style="padding:14px 16px;border:1px solid #E2E8F0;border-radius:14px;background:#fff;">'
      + '<strong style="font-size:15px;color:#0F172A;">🔔 How you hear about calls on the KiddieTrac line</strong>'
      + '<div style="font-size:12.5px;color:#64748B;margin:4px 0 10px;line-height:1.5;">These reach you even when the app is closed. The portal only rings while it is open.</div>'
      + '<table style="border-collapse:collapse;width:100%;max-width:520px;font-size:13.5px;" data-kt-no-filter="1"><thead><tr style="color:#64748B;font-size:12px;">'
      + '<th style="text-align:left;padding:4px 6px;"></th><th style="padding:4px 6px;">📱 App</th><th style="padding:4px 6px;">✉️ Email</th><th style="padding:4px 6px;">💬 Text</th></tr></thead><tbody>'
      + rows.map(function (r) { return '<tr style="border-top:1px solid #F1F5F9;"><td style="padding:8px 6px;"><div style="font-weight:700;color:#0F172A;">' + r[1] + '</div><div style="font-size:11.5px;color:#64748B;">' + r[2] + '</div></td>' + cell(r[0], 'push') + cell(r[0], 'email') + cell(r[0], 'sms') + '</tr>'; }).join('')
      + '</tbody></table>'
      + '<div style="font-size:12px;color:#64748B;margin-top:8px;line-height:1.5;">Email goes to <strong>' + esc(a.email || 'no address on your account') + '</strong>; texts to <strong>' + esc(a.phone || 'no mobile number on your account') + '</strong>'
      + ' (only if you have agreed to texts, and never after STOP).'
      + (a.on_alert_list ? '' : '<br><span style="color:#B45309;">You are not on any department\u2019s alert list yet, so nothing is sent to you until an admin adds you (Platform → Phone &amp; voicemail).</span>') + '</div>'
      + '<div style="display:flex;gap:8px;align-items:center;margin-top:12px;"><button type="button" data-save style="height:32px;padding:0 14px;border:0;background:#0F766E;color:#fff;border-radius:8px;font-weight:700;cursor:pointer;">Save</button><span data-msg style="font-size:12.5px;"></span></div></div>';
  }
  function wireAlerts(box, a) {
    box.querySelector('[data-save]').onclick = function () {
      var body = { missed: {}, voicemail: {} }, msg = box.querySelector('[data-msg]');
      box.querySelectorAll('input[data-k]').forEach(function (c) { body[c.getAttribute('data-k')][c.getAttribute('data-w')] = c.checked; });
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      req('PUT', '/voice/dialer/alerts', body).then(function (r) { a.prefs = r.prefs; msg.style.color = '#15803D'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; });
    };
  }

  var ROLE_LABEL = { platform_admin: 'Platform admins', sales_rep: 'Sales reps', agency_admin: 'Agency admins', centre_director: 'Centre directors', educator: 'Educators' };
  function settingsCard(scope, title, c, roles, extra) {
    return '<div class="kt-card" data-scope="' + scope + '" style="padding:14px 16px;border:1px solid #E2E8F0;border-radius:14px;background:#fff;margin-bottom:12px;">'
      + '<strong style="font-size:15px;color:#0F172A;">' + title + '</strong>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px;font-size:13.5px;"><input type="checkbox" data-f="enabled"' + (c.enabled ? ' checked' : '') + '> Calling from the portal is on</label>'
      + '<div style="font-size:12.5px;font-weight:700;color:#475569;margin-top:10px;">Who may call</div><div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:4px;">'
      + roles.map(function (r) { return '<label style="display:flex;gap:6px;align-items:center;font-size:13.5px;"><input type="checkbox" data-role="' + r + '"' + (c.roles.indexOf(r) !== -1 ? ' checked' : '') + '> ' + ROLE_LABEL[r] + '</label>'; }).join('')
      + '</div>' + (extra || '')
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px;font-size:13.5px;"><input type="checkbox" data-f="record"' + (c.record ? ' checked' : '') + '> Record calls (the person called hears &ldquo;This call may be recorded&rdquo; first)</label>'
      + '<div style="display:flex;gap:8px;align-items:center;margin-top:12px;"><button type="button" data-save style="height:32px;padding:0 14px;border:0;background:#0F766E;color:#fff;border-radius:8px;font-weight:700;cursor:pointer;">Save</button><span data-msg style="font-size:12.5px;"></span></div></div>';
  }
  function settingsHtml(s) {
    var out = '';
    if (s.platform) out += settingsCard('platform', 'KiddieTrac line · ' + esc(s.platform.line), s.platform, ['platform_admin', 'sales_rep']);
    if (s.agency) {
      if (!s.agency.available) {
        out += '<div class="kt-card" style="padding:14px 16px;border:1px solid #E2E8F0;border-radius:14px;background:#fff;margin-bottom:12px;font-size:13.5px;color:#475569;"><strong style="color:#0F172A;">' + esc(s.agency.agency_name) + '</strong><br>'
          + 'Calling from your own number needs your agency&rsquo;s Telnyx account and a voice number first (Settings → Phone &amp; carriers).</div>';
      } else {
        var sel = '<div style="font-size:12.5px;font-weight:700;color:#475569;margin-top:10px;">Number people see</div><select data-f="caller_id" style="height:32px;margin-top:4px;border:1px solid #CBD5E1;border-radius:8px;">'
          + s.agency.numbers.map(function (n) { return '<option value="' + esc(n.value) + '"' + (n.value === s.agency.caller_id ? ' selected' : '') + '>' + esc(n.label) + '</option>'; }).join('') + '</select>';
        out += settingsCard('agency', esc(s.agency.agency_name) + ' · your own number', s.agency, ['agency_admin', 'centre_director', 'educator'], sel);
      }
    }
    return out;
  }
  function wireSettings(box, s) {
    box.querySelectorAll('[data-scope]').forEach(function (card) {
      card.querySelector('[data-save]').onclick = function () {
        var msg = card.querySelector('[data-msg]');
        var body = { scope: card.getAttribute('data-scope'), enabled: card.querySelector('[data-f="enabled"]').checked,
          record: card.querySelector('[data-f="record"]').checked, roles: [] };
        card.querySelectorAll('[data-role]').forEach(function (c) { if (c.checked) body.roles.push(c.getAttribute('data-role')); });
        var cid = card.querySelector('[data-f="caller_id"]'); if (cid) body.caller_id = cid.value;
        msg.style.color = '#64748B'; msg.textContent = 'Saving…';
        req('PUT', '/voice/dialer/settings', body).then(function () {
          msg.style.color = '#15803D'; msg.textContent = '✓ Saved';
          if (KT.portalPhone) KT.portalPhone.restart();
        }).catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; });
      };
    });
  }

  (function register() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(register, 300); return; }
    ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'sales_rep'].forEach(function (r) { KT.Shell.registerScreen(r + ':phone', render); });
  })();

  KT.dialer = { call: call, can: function () { return !!dial; }, sweep: sweepSoon, open: openPop, paint: paintBadge };
  KT.dialpad = { open: openPop, close: closePop, toggle: togglePop, refresh: refreshCount };

  /* THE VISIBLE SCREEN (2026-10-23). In the Android app the layout viewport can be taller
     than what is on screen (kt-mobilenav.js pins its bar to visualViewport for the same
     reason), so a full-screen panel sized to 100% ran under the navy strip over Android's
     own buttons and its bottom tabs were cut off. --kt-vv-h / --kt-vv-top = the visible
     area; the phone layouts here and the call screen (kt-portal-phone.js) use them. */
  (function trackVisible() {
    var vv = w.visualViewport, root = document.documentElement;
    var set = function () {
      var h = vv ? vv.height : w.innerHeight, t = vv ? vv.offsetTop : 0;
      root.style.setProperty('--kt-vv-h', Math.round(h) + 'px');
      root.style.setProperty('--kt-vv-top', Math.round(t) + 'px');
      // What the bottom bar covers of the visible screen, measured here (not its height):
      // the full-screen dial pad ends exactly where the bar starts.
      try {
        var n = document.getElementById('kt-mobilenav');
        var cover = (n && getComputedStyle(n).display !== 'none') ? Math.max(0, Math.round(t + h - n.getBoundingClientRect().top)) : 0;
        root.style.setProperty('--kt-nav-h', cover + 'px');
      } catch (e) {}
    };
    KT.__dpFit = set;
    set();
    if (vv) { vv.addEventListener('resize', set); vv.addEventListener('scroll', set); }
    w.addEventListener('resize', set);
    w.addEventListener('orientationchange', function () { setTimeout(set, 300); });
  })();
  // Styles now, so the top-bar 📞 stays hidden until the session says this person may dial.
  try { padCss(); } catch (e) {}
})(window);
