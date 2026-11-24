/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Reseller → Phone & voicemail  (platform admin)

   The toll-free line: its call menu (departments, phones to ring, hours, mailboxes —
   2026-10-22), its greeting (voice, wording, message length) and its voicemails: how many
   came in, were emailed, listened to or deleted, with a player. Saving republishes the
   script to Telnyx and only reports success once Telnyx serves it.
   Backend: PhoneLineController (/platform/phone*), PhoneMenuController (/platform/phone/menu).

   Subtabs (house pattern, CONVENTIONS.md): the strip is drawn once; a tab click
   re-renders only #pv-pane. The content box is .kt-section — no private max-width.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var days = '30';
  var tab = 'menu';
  try { tab = sessionStorage.getItem('kt_pv_tab') || 'menu'; } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function when(ts) { return KT.fmtDateTime ? KT.fmtDateTime(ts) : String(ts || '').slice(0, 16); }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  function phone(n) { var d = String(n || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') { d = d.slice(1); } return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : (n || 'Unknown / hidden'); }
  function secs(s) { s = Number(s) || 0; return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's'; }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  var CSS = '<style id="pv-css">'
    + '.pv-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:12px}.pv-card h3{margin:0 0 4px;font-size:15px;color:#0F172A}'
    + '.pv-sub{font-size:12.5px;color:#64748B;margin-bottom:10px;line-height:1.5}'
    + '.pv-f{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}.pv-f .full{grid-column:1/-1}.pv-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '#appMain .pv-f select,#appMain .pv-f input:not([type=checkbox]),#appMain .pv-f textarea{width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit;max-width:none!important}.pv-f select,.pv-f input:not([type=checkbox]){height:32px}'
    + '.pv-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:8px 0 12px}.pv-tiles div{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;font-size:11.5px;color:#64748B;font-weight:600}.pv-tiles b{display:block;font-size:18px;color:#0F172A}'
    + '.pv-t{width:100%;border-collapse:collapse;font-size:13px}.pv-t th{text-align:left;font-size:11.5px;color:#64748B;font-weight:700;padding:6px 8px;border-bottom:1px solid #E2E8F0}.pv-t td{padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:middle}'
    + '.pv-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700;white-space:nowrap}'
    + '.pv-t audio{height:30px;max-width:230px}'
    + '.pv-chk{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#0F172A;margin:2px 12px 2px 0;cursor:pointer}'
    + '.pv-dept{border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin:10px 0;background:#FBFDFF}'
    + '.pv-depts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));gap:0 12px}'
    + '.pv-note{font-size:12px;color:#64748B;margin-top:3px}'
    + '@media (max-width:700px){.pv-f{grid-template-columns:minmax(0,1fr)}.pv-t .hm{display:none}}'
    + '</style>';

  var TABS = [['menu', '☎️ Call menu'], ['greeting', '🗣️ Greeting'], ['voicemails', '📼 Voicemails']];

  function paintTabs(main) {
    var bar = main.querySelector('#pv-tabs');
    bar.innerHTML = TABS.map(function (t) {
      var on = tab === t[0];
      return '<button type="button" data-pv-tab="' + t[0] + '" data-kt-iconized="1" style="background:none;border:0;border-bottom:2px solid ' + (on ? '#1F6FB2' : 'transparent')
        + ';padding:9px 13px;font-size:13.5px;font-weight:700;color:' + (on ? '#0F172A' : '#64748B') + ';cursor:pointer;border-radius:8px 8px 0 0;white-space:nowrap;">' + esc(t[1]) + '</button>';
    }).join('');
    bar.querySelectorAll('[data-pv-tab]').forEach(function (b) {
      b.onclick = function () { tab = b.getAttribute('data-pv-tab'); try { sessionStorage.setItem('kt_pv_tab', tab); } catch (e) {} paintTabs(main); pane(main); };
    });
  }

  function pane(main) {
    var p = main.querySelector('#pv-pane');
    if (tab === 'greeting') { p.innerHTML = '<div id="pv-set">Loading…</div>'; settings(main); }
    else if (tab === 'voicemails') { p.innerHTML = '<div id="pv-vm">Loading…</div>'; voicemails(main); }
    else { p.innerHTML = '<div id="pv-menu">Loading…</div>'; menu(main); }
  }

  /* MOVED INTO SETTINGS (Anthony, 2026-10-22: "phone and voice mail should be moved to
     settings not reseller and combine with Carrier settings and subtabbed where required").
     It now lives in Settings → Phone & carriers (#sms-settings) as the "Toll-free line"
     tab, which calls renderInto(); its own Call menu / Greeting / Voicemails subtabs sit
     inside that tab. #phone-voicemail still works (bookmarks, alert links): it opens there. */
  function renderInto(box) {
    box.innerHTML = CSS
      + '<div class="pv-sub" style="margin:2px 0 10px;">KiddieTrac’s own toll-free line, 1-855-400-9996 — not this agency’s number. Only super admins see this tab.</div>'
      + '<div id="pv-tabs" class="kt-subtabs" style="display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #E2E8F0;margin:0 0 14px;padding:0 0 2px;"></div>'
      + '<div id="pv-pane"></div>';
    paintTabs(box);
    pane(box);
  }

  function render() {
    try { sessionStorage.setItem('kt_sv_tab', 'tollfree'); } catch (e) {}
    if (KT.Shell && KT.Shell.navigate) { KT.Shell.navigate('sms-settings'); } else { location.hash = '#sms-settings'; }
  }

  /* ── Call menu (2026-10-22) ─────────────────────────────────────────────── */
  var DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']];

  function deptHtml(dp, i, people) {
    var keys = '';
    for (var k = 1; k <= 9; k++) keys += '<option value="' + k + '"' + (String(dp.key) === String(k) ? ' selected' : '') + '>' + k + '</option>';
    return '<div class="pv-dept" data-dept="' + i + '">'
      + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">'
      + '<label class="pv-chk"><input type="checkbox" data-d="enabled"' + (dp.enabled ? ' checked' : '') + '> In the menu</label>'
      + '<span style="font-size:13px;color:#475569;">Press</span><select data-d="key" style="height:30px;border:1px solid #CBD5E1;border-radius:8px;">' + keys + '</select>'
      + '<span style="font-size:13px;color:#475569;">for</span><input data-d="name" maxlength="40" value="' + esc(dp.name) + '" style="height:30px;border:1px solid #CBD5E1;border-radius:8px;padding:0 10px;font-weight:700;min-width:0;flex:1 1 140px;">'
      + '<button type="button" class="kt-btn kt-btn-sm" data-remove="' + i + '" title="Remove this department" aria-label="Remove department">🗑</button></div>'
      + '<div class="pv-f">'
      + '<div class="full"><label>Where it rings</label>'
      + '<label class="pv-chk"><input type="checkbox" data-d="ring_phones"' + (dp.ring_phones ? ' checked' : '') + '> Staff phones</label>'
      + '<label class="pv-chk" title="Rings in the KiddieTrac portal for this department’s people while they have it open"><input type="checkbox" data-d="ring_portal"' + (dp.ring_portal ? ' checked' : '') + '> In the portal</label>'
      + '<div class="pv-note">Both ring at the same time; the first to answer gets the call. Turn both off to send this department straight to voicemail. The portal rings the people ticked below while they have KiddieTrac open (they answer with their computer’s microphone).</div></div>'
      + '<div><label>Phones to ring (all at once)</label><textarea data-d="numbers" rows="2" placeholder="One per line, e.g. 416 555 0123">' + esc((dp.numbers || []).map(phone).join('\n')) + '</textarea>'
      + '<div class="pv-note">Each phone hears “press 1 to accept”, so a personal voicemail can’t take the call.</div></div>'
      + '<div><label>Rings before voicemail</label><select data-d="rings">' + [1, 2, 3, 4, 5, 6, 7, 8, 10].map(function (n) { return '<option value="' + n + '"' + (Number(dp.rings) === n ? ' selected' : '') + '>' + n + ' ring' + (n === 1 ? '' : 's') + ' (about ' + (n * 6) + ' sec)</option>'; }).join('') + '</select></div>'
      + '<div class="full"><label>Voicemail greeting for ' + esc(dp.name) + '</label><textarea data-d="greeting" rows="2" maxlength="600">' + esc(dp.greeting) + '</textarea></div>'
      + '<div><label>Email voicemails to</label><input data-d="emails" value="' + esc((dp.emails || []).join(', ')) + '" placeholder="sales@kiddietrac.com, …"></div>'
      + '<div><label>This department’s people (voicemail alerts, and portal ringing)</label><div>' + (people.length ? people.map(function (p) {
          return '<label class="pv-chk"><input type="checkbox" data-alert value="' + p.id + '"' + ((dp.alert_user_ids || []).indexOf(Number(p.id)) !== -1 ? ' checked' : '') + '> ' + esc(((p.first_name || '') + ' ' + (p.last_name || '')).trim()) + '</label>';
        }).join('') : '<span class="pv-note">No platform staff found.</span>') + '</div>'
      + '<label class="pv-chk" style="margin-top:4px;"><input type="checkbox" data-d="text_alert"' + (dp.text_alert ? ' checked' : '') + '> Also text them (if they agreed to texts)</label></div>'
      + '</div></div>';
  }

  async function menu(main) {
    var box = main.querySelector('#pv-menu'), d;
    try { d = await KT.Api.get('/platform/phone/menu'); }
    catch (e) { box.innerHTML = '<div class="pv-card" style="color:#B91C1C">' + esc(e.message || 'Could not load the call menu.') + '</div>'; return; }
    var m = d.menu || {}, h = m.hours || {}, people = d.people || [];
    var depts = (m.departments || []).slice();

    function draw() {
      box.innerHTML = '<div class="pv-card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between;">'
        + '<div><h3>Call menu</h3><div class="pv-sub" style="margin:0;">Callers to <strong>' + esc(d.number) + '</strong> choose a department; its phones ring, then its voicemail. Pressing nothing leaves a general message, as today.</div></div>'
        + '<label class="pv-chk" style="font-size:14px;"><input type="checkbox" data-menu-on' + (m.enabled ? ' checked' : '') + '> Menu is ' + (m.enabled ? '<b style="color:#166534;">ON</b>' : '<b style="color:#B91C1C;">OFF</b>') + '</label></div>'
        + '<div class="pv-f" style="margin-top:12px;">'
        + '<div class="full"><label>Opening line</label><input data-m="intro" maxlength="400" value="' + esc(m.intro) + '"></div>'
        + '<div class="full pv-note" style="margin-top:-4px;">Then the menu is read out: <i data-preview>' + esc(d.prompt || '') + '</i></div>'
        + '<div><label>When a caller presses a wrong key</label><input data-m="invalid" maxlength="200" value="' + esc(m.invalid) + '"></div>'
        + '<div><label>While their call is connected</label><input data-m="connecting" maxlength="200" value="' + esc(m.connecting) + '"><div class="pv-note">{dept} is replaced by the department’s name.</div></div>'
        + '<div class="full"><label>Business hours (phones ring only then; after hours the menu goes to voicemail)</label>'
        + '<div style="display:flex;flex-wrap:wrap;gap:4px 16px;">' + DAYS.map(function (x) { return '<label class="pv-chk" style="margin:0;"><input type="checkbox" data-day value="' + x[0] + '"' + ((h.days || []).indexOf(x[0]) !== -1 ? ' checked' : '') + '> ' + x[1] + '</label>'; }).join('') + '</div>'
        + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;"><input type="time" data-h="open" value="' + esc(h.open || '09:00') + '" style="width:130px!important;"> to <input type="time" data-h="close" value="' + esc(h.close || '17:00') + '" style="width:130px!important;">'
        + '<select data-h="tz" style="width:auto!important;">' + ['America/Toronto', 'America/Vancouver', 'America/Edmonton', 'America/Winnipeg', 'America/Halifax', 'America/Phoenix'].map(function (z) { return '<option' + (z === (h.tz || 'America/Toronto') ? ' selected' : '') + '>' + z + '</option>'; }).join('') + '</select>'
        + '<span class="pv-note">' + (d.open_now ? 'Open right now.' : 'Closed right now.') + '</span></div></div>'
        + '</div></div>'
        + '<div class="pv-card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;"><h3>Departments</h3>'
        + '<button type="button" class="kt-btn kt-btn-sm" data-add' + (depts.length >= 9 ? ' disabled' : '') + '>+ Add department</button></div>'
        + '<div class="pv-depts">' + depts.map(function (dp, i) { return deptHtml(dp, i, people); }).join('') + '</div>'
        + (depts.length ? '' : '<div class="pv-note">No departments yet.</div>') + '</div>'
        + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:4px 0 16px;">'
        + '<span style="font-size:12px;color:#64748B;flex:1;min-width:220px;">Saving republishes the line. With the menu ON, call ' + esc(d.number) + ' afterwards to test each option.</span>'
        + '<button class="kt-btn kt-btn-primary" data-save-menu>Save and publish</button></div>';

      box.querySelector('[data-add]').onclick = function () {
        collect();
        var used = depts.map(function (x) { return String(x.key); }), key = '1';
        for (var k = 1; k <= 9; k++) { if (used.indexOf(String(k)) === -1) { key = String(k); break; } }
        depts.push({ key: key, name: 'New department', enabled: true, ring_phones: true, ring_portal: false, numbers: [], rings: 4, greeting: '', emails: ['info@kiddietrac.com'], alert_user_ids: [], text_alert: false });
        draw();
      };
      box.querySelectorAll('[data-remove]').forEach(function (b) {
        b.onclick = function () { collect(); depts.splice(Number(b.getAttribute('data-remove')), 1); draw(); };
      });
      box.querySelector('[data-save-menu]').onclick = save;
    }

    function collect() {
      m.enabled = box.querySelector('[data-menu-on]').checked;
      box.querySelectorAll('[data-m]').forEach(function (el) { m[el.getAttribute('data-m')] = el.value; });
      h = { tz: box.querySelector('[data-h="tz"]').value, open: box.querySelector('[data-h="open"]').value, close: box.querySelector('[data-h="close"]').value,
        days: Array.from(box.querySelectorAll('[data-day]:checked')).map(function (c) { return Number(c.value); }) };
      box.querySelectorAll('[data-dept]').forEach(function (el) {
        var i = Number(el.getAttribute('data-dept')), dp = depts[i];
        var v = function (k) { return el.querySelector('[data-d="' + k + '"]'); };
        dp.enabled = v('enabled').checked; dp.key = v('key').value; dp.name = v('name').value.trim() || 'Department';
        dp.ring_phones = v('ring_phones').checked; dp.ring_portal = v('ring_portal').checked; dp.text_alert = v('text_alert').checked;
        dp.numbers = v('numbers').value.split(/[\n,;]+/).map(function (x) { return x.trim(); }).filter(Boolean);
        dp.rings = Number(v('rings').value); dp.greeting = v('greeting').value.trim();
        dp.emails = v('emails').value.split(/[\s,;]+/).map(function (x) { return x.trim(); }).filter(Boolean);
        dp.alert_user_ids = Array.from(el.querySelectorAll('[data-alert]:checked')).map(function (c) { return Number(c.value); });
      });
    }

    async function save() {
      collect();
      var b = box.querySelector('[data-save-menu]');
      var keys = depts.filter(function (x) { return x.enabled; }).map(function (x) { return x.key; });
      if (keys.length !== new Set(keys).size) { toast('Two departments use the same key — give each its own number.', 'error'); return; }
      b.disabled = true; b.textContent = 'Publishing…';
      try {
        d = await KT.Api.put('/platform/phone/menu', { enabled: m.enabled, intro: m.intro, invalid: m.invalid, connecting: m.connecting, hours: h, departments: depts });
        m = d.menu; h = m.hours; depts = m.departments.slice();
        draw();
        toast(m.enabled ? 'Call menu published. Call ' + d.number + ' to try it.' : 'Saved. The menu is off — callers hear the greeting and leave a message, as before.', 'success');
        if (d.portal_problem) toast(d.portal_problem, 'warning');
        try { if (KT.portalPhone) KT.portalPhone.restart(); } catch (e) {}   // your own portal picks up the change now
      } catch (e) { toast(e.message || 'Could not publish', 'error'); b.disabled = false; b.textContent = 'Save and publish'; }
    }

    draw();
  }

  /* ── Greeting ─────────────────────────────────────────────────────────── */
  async function settings(main) {
    var box = main.querySelector('#pv-set'), d;
    try { d = await KT.Api.get('/platform/phone'); }
    catch (e) { box.innerHTML = '<div class="pv-card" style="color:#B91C1C">' + esc(e.message || 'Could not load the greeting.') + '</div>'; return; }
    var s = d.settings || {}, groups = {};
    (d.voices || []).forEach(function (v) { (groups[v.group] = groups[v.group] || []).push(v); });
    var opts = Object.keys(groups).map(function (g) {
      return '<optgroup label="' + esc(g) + '">' + groups[g].map(function (v) { return '<option value="' + esc(v.id) + '"' + (v.id === (s.voice || '') ? ' selected' : '') + '>' + esc(v.label) + '</option>'; }).join('') + '</optgroup>';
    }).join('');
    box.innerHTML = '<div class="pv-card"><h3>Greeting</h3><div class="pv-sub">Callers to <strong>' + esc(d.number) + '</strong> who don’t choose a department hear this, then leave a message that is emailed to ' + esc(d.inbox) + '. The voice is used for the whole line, menu included.'
      + ' <span data-published>' + (d.published_at ? 'Last published ' + esc(when(d.published_at)) + (d.published_by ? ' by ' + esc(d.published_by) : '') + '.' : '') + '</span></div>'
      + '<div class="pv-f">'
      + '<div><label>Voice</label><select data-k="voice">' + opts + '</select></div>'
      + '<div><label>Longest message</label><select data-k="max_length">' + [60, 120, 180, 300, 600, 900, 1200, 1800].map(function (n) { return '<option value="' + n + '"' + (Number(s.max_length) === n ? ' selected' : '') + '>' + (n / 60) + ' minute' + (n === 60 ? '' : 's') + '</option>'; }).join('') + '</select></div>'
      + '<div class="full"><label>Greeting (before the beep)</label><textarea data-k="greeting" rows="3" maxlength="1000">' + esc(s.greeting) + '</textarea></div>'
      + '<div class="full"><label>If the caller leaves no message</label><input data-k="no_message" maxlength="500" value="' + esc(s.no_message) + '"></div>'
      + '<div class="full"><label>After a message is left</label><input data-k="goodbye" maxlength="300" value="' + esc(s.goodbye) + '"></div>'
      + '</div><div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">'
      + '<span style="font-size:12px;color:#64748B;flex:1;min-width:200px;">Tip: write numbers and emails the way they should be spoken, e.g. "info at kiddietrac dot com". Call the line after saving to hear it.</span>'
      + '<button class="kt-btn kt-btn-primary" data-save>Save and publish</button></div></div>'
      + '<div class="pv-card" id="pv-reply" style="margin-top:14px">Loading…</div>';
    replyCard(main);
    box.querySelector('[data-save]').addEventListener('click', async function () {
      var b = this, v = {};
      box.querySelectorAll('[data-k]').forEach(function (el) { v[el.getAttribute('data-k')] = el.value; });
      v.max_length = parseInt(v.max_length, 10);
      b.disabled = true; b.textContent = 'Publishing…';
      try {
        var r = await KT.Api.put('/platform/phone', v);
        var meta = box.querySelector('[data-published]');
        if (meta && r && r.published_at) { meta.textContent = 'Last published ' + when(r.published_at) + (r.published_by ? ' by ' + r.published_by : '') + '.'; }
        b.textContent = '✓ Published';
        toast('Greeting published. Call ' + d.number + ' to hear it.', 'success');
        setTimeout(function () { b.disabled = false; b.textContent = 'Save and publish'; }, 1800);
      }
      catch (e) { toast(e.message || 'Could not publish', 'error'); b.disabled = false; b.textContent = 'Save and publish'; }
    });
  }

  /* ── Text the caller back (2026-10-23) ────────────────────────────────────
     Anthony: "with new voicemails - wire up a text to reply saying someone from the kiddietrac
     sales or support team will be in touch". App\Support\VoicemailReply sends it from the
     line, once per caller per day, never after STOP. {team} = the department they reached. */
  async function replyCard(main) {
    var box = main.querySelector('#pv-reply'); if (!box) return;
    var d; try { d = await KT.Api.get('/platform/phone/voicemail-reply'); } catch (e) { box.innerHTML = '<span style="color:#B91C1C">' + esc(e.message || 'Could not load.') + '</span>'; return; }
    box.innerHTML = '<h3>Text the caller back</h3>'
      + '<div class="pv-sub">After someone leaves a voicemail, they get this text from <strong>1-855-400-9996</strong> so they know it arrived and who will reply. Once per caller per day, never to anyone who has texted STOP. ' + (d.sent_30d ? d.sent_30d + ' sent in the last 30 days.' : '') + '</div>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:700;margin:10px 0;cursor:pointer;"><input type="checkbox" data-r-on' + (d.enabled ? ' checked' : '') + '> Send the text after every voicemail</label>'
      + '<div class="pv-f"><div class="full"><label>The text <span style="font-weight:500;color:#64748B">· {team} becomes the department they reached (sales, support), or “sales or support”</span></label>'
      + '<textarea data-r-text rows="3" maxlength="480">' + esc(d.text) + '</textarea>'
      + '<div style="font-size:12px;color:#64748B;margin-top:4px;" data-r-count></div></div></div>'
      + '<div style="font-size:12.5px;color:#334155;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;margin-top:10px;"><div style="font-weight:800;margin-bottom:4px;">What a Sales caller gets</div><div data-r-prev style="white-space:pre-wrap;">' + esc(d.preview_sales) + '</div></div>'
      + '<div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">'
      + '<button class="kt-btn kt-btn-ghost" data-r-reset type="button">Use the standard wording</button><span style="flex:1"></span>'
      + '<button class="kt-btn kt-btn-primary" data-r-save type="button">Save</button></div>';
    var ta = box.querySelector('[data-r-text]'), cnt = box.querySelector('[data-r-count]'), prev = box.querySelector('[data-r-prev]');
    var paint = function () {
      var t = ta.value.replace(/\{team\}/g, 'sales').replace(/\{department\}/g, 'Sales').replace(/\{number\}/g, '1-855-400-9996');
      prev.textContent = t;
      var n = t.length, parts = n <= 160 ? 1 : Math.ceil(n / 153);
      cnt.textContent = n + ' characters · ' + parts + ' text message' + (parts === 1 ? '' : 's') + (/stop/i.test(t) ? '' : ' · tip: keep “Reply STOP to opt out”');
    };
    ta.addEventListener('input', paint); paint();
    box.querySelector('[data-r-reset]').onclick = function () { ta.value = d.default_text; paint(); };
    box.querySelector('[data-r-save]').onclick = async function () {
      var b = this; b.disabled = true;
      try { d = await KT.Api.put('/platform/phone/voicemail-reply', { enabled: box.querySelector('[data-r-on]').checked, text: ta.value }); toast('Voicemail reply text saved', 'success'); }
      catch (e) { toast(e.message || 'Could not save', 'error'); }
      b.disabled = false;
    };
  }

  /* ── Voicemails ───────────────────────────────────────────────────────── */
  async function voicemails(main) {
    var box = main.querySelector('#pv-vm'), d;
    try { d = await KT.Api.get('/platform/phone/voicemails?days=' + days); } catch (e) { box.innerHTML = '<div class="pv-card" style="color:#B91C1C">' + esc(e.message || 'Could not load voicemails.') + '</div>'; return; }
    var st = d.stats || {}, rows = d.voicemails || [];
    function tile(l, v, c) { return '<div>' + l + '<b' + (c ? ' style="color:' + c + '"' : '') + '>' + v + '</b></div>'; }
    box.innerHTML = '<div class="pv-card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="flex:1">Voicemails</h3>'
      + '<select data-days style="height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px">' + [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days'], ['365', 'Last year'], ['all', 'All time']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === days ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>'
      + '<div class="pv-tiles">' + tile('Received', st.received) + tile('Emailed', st.emailed, '#166534') + tile('Email failed', st.email_failed, st.email_failed ? '#B91C1C' : '') + tile('Not yet heard', st.unheard, st.unheard ? '#B45309' : '')
      + tile('Listened here', st.listened) + tile('Deleted', st.deleted) + tile('Total length', secs(st.total_seconds)) + '</div>'
      + (rows.length ? '<div style="overflow-x:auto"><table class="pv-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th>Caller</th><th>Department</th><th>When</th><th class="hm">Length</th><th class="hm">Email</th><th>Recording</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          var em = r.email_status === 'sent' ? ['Emailed', '#DCFCE7', '#166534'] : r.email_status === 'failed' ? ['Failed', '#FEE2E2', '#991B1B'] : ['—', '#F1F5F9', '#475569'];
          var rec = r.deleted_at ? '<span style="color:#94A3B8;font-size:12px">Deleted ' + esc(when(r.deleted_at)) + (r.deleted_by_first ? ' by ' + esc(r.deleted_by_first) : '') + '</span>'
            : r.has_audio ? '<button class="kt-btn kt-btn-sm" data-play="' + r.id + '">▶ Play</button>' + (r.listened_at ? ' <span style="font-size:11.5px;color:#64748B">heard' + (r.listened_by_first ? ' by ' + esc(r.listened_by_first) : '') + '</span>' : ' <span class="pv-pill" style="background:#FEF3C7;color:#92400E">New</span>')
            : '<span style="color:#B45309;font-size:12px">Not downloaded (see the email)</span>';
          return '<tr' + (r.deleted_at ? ' style="opacity:.6"' : '') + '><td><strong>' + esc(phone(r.from_number)) + '</strong></td><td>' + (r.department ? '<span class="pv-pill" style="background:#E0F2FE;color:#075985">' + esc(r.department) + '</span>' : '<span style="color:#94A3B8;font-size:12px">General</span>') + '</td><td>' + esc(when(r.created_at)) + '</td><td class="hm">' + secs(r.duration) + '</td>'
            + '<td class="hm"><span class="pv-pill" style="background:' + em[1] + ';color:' + em[2] + '">' + em[0] + '</span></td><td data-rec="' + r.id + '">' + rec + '</td>'
            + '<td style="text-align:right">' + (r.deleted_at ? '' : '<button class="kt-btn kt-btn-sm" data-del="' + r.id + '" title="Delete this voicemail" aria-label="Delete voicemail">🗑</button>') + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13.5px">No voicemails in this period.</div>') + '</div>';

    box.querySelector('[data-days]').onchange = function () { days = this.value; voicemails(main); };
    box.querySelectorAll('[data-play]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var id = b.getAttribute('data-play'); b.disabled = true;
        try {
          var res = await fetch(apiBase() + '/platform/phone/voicemails/' + id + '/audio', { headers: { Authorization: 'Bearer ' + token() } });
          if (!res.ok) { throw new Error('The recording could not be loaded just now. Please try again.'); }
          var url = URL.createObjectURL(await res.blob());
          var cell = box.querySelector('[data-rec="' + id + '"]');
          cell.innerHTML = '<audio controls autoplay src="' + url + '"></audio>';
        } catch (e) { toast(e.message, 'error'); b.disabled = false; }
      });
    });
    box.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: 'Delete this voicemail?', description: 'The recording is removed from KiddieTrac and from Telnyx. The copy already emailed is not affected. It still counts in the totals as deleted.', okLabel: 'Delete' }) : false;
        if (!ok) { return; }
        b.disabled = true;
        try { var r = await KT.Api.delete('/platform/phone/voicemails/' + b.getAttribute('data-del')); toast(r.telnyx_deleted ? 'Voicemail deleted' : 'Deleted here; Telnyx did not confirm its copy was removed', r.telnyx_deleted ? 'success' : 'warning'); voicemails(main); }
        catch (e) { toast(e.message || 'Could not delete', 'error'); b.disabled = false; }
      });
    });
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    KT.PhoneLine = { renderInto: renderInto };
    KT.Shell.registerScreen('platform_admin:phone-voicemail', render);
    KT.Shell.registerScreen('agency_admin:phone-voicemail', render);
  }
  reg(0);
})(window);
