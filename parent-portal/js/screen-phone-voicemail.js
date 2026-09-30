/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Reseller → Phone & voicemail  (platform admin)

   The toll-free line's greeting (voice, wording, message length) and its voicemails:
   how many came in, were emailed, listened to or deleted, with a player. Saving
   republishes the greeting to Telnyx and only reports success once Telnyx serves it.
   Backend: PhoneLineController (/platform/phone*).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var days = '30';

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function when(ts) { return KT.fmtDateTime ? KT.fmtDateTime(ts) : String(ts || '').slice(0, 16); }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  function phone(n) { var d = String(n || '').replace(/\D/g, ''); if (d.length === 11 && d[0] === '1') { d = d.slice(1); } return d.length === 10 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : (n || 'Unknown / hidden'); }
  function secs(s) { s = Number(s) || 0; return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's'; }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  var CSS = '<style id="pv-css">'
    + '.pv-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;margin-bottom:12px}.pv-card h3{margin:0 0 4px;font-size:15px;color:#0F172A}'
    + '.pv-sub{font-size:12.5px;color:#64748B;margin-bottom:10px}'
    + '.pv-f{display:grid;grid-template-columns:minmax(0,1fr) 160px;gap:10px}.pv-f .full{grid-column:1/-1}.pv-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '#appMain #pv-set .pv-f select,#appMain #pv-set .pv-f input,#appMain #pv-set .pv-f textarea{width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit;max-width:none!important}.pv-f select,.pv-f input{height:32px}'
    + '.pv-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:8px 0 12px}.pv-tiles div{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;font-size:11.5px;color:#64748B;font-weight:600}.pv-tiles b{display:block;font-size:18px;color:#0F172A}'
    + '.pv-t{width:100%;border-collapse:collapse;font-size:13px}.pv-t th{text-align:left;font-size:11.5px;color:#64748B;font-weight:700;padding:6px 8px;border-bottom:1px solid #E2E8F0}.pv-t td{padding:7px 8px;border-bottom:1px solid #F1F5F9;vertical-align:middle}'
    + '.pv-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700;white-space:nowrap}'
    + '.pv-t audio{height:30px;max-width:230px}'
    + '@media (max-width:700px){.pv-f{grid-template-columns:minmax(0,1fr)}.pv-t .hm{display:none}}'
    + '</style>';

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div style="padding:14px 24px;max-width:1100px;"><div class="kt-page-hero"><h2>📞 Phone & voicemail</h2>'
      + '<p>What callers hear on the toll-free line, and the voicemails they leave.</p></div>'
      + '<div id="pv-set">Loading…</div><div id="pv-vm"></div></div>';
    await Promise.all([settings(main), voicemails(main)]);
  }

  async function settings(main) {
    var box = main.querySelector('#pv-set'), d;
    try { d = await KT.Api.get('/platform/phone'); }
    catch (e) { box.innerHTML = '<div class="pv-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    var s = d.settings || {}, groups = {};
    (d.voices || []).forEach(function (v) { (groups[v.group] = groups[v.group] || []).push(v); });
    var opts = Object.keys(groups).map(function (g) {
      return '<optgroup label="' + esc(g) + '">' + groups[g].map(function (v) { return '<option value="' + esc(v.id) + '"' + (v.id === (s.voice || '') ? ' selected' : '') + '>' + esc(v.label) + '</option>'; }).join('') + '</optgroup>';
    }).join('');
    box.innerHTML = '<div class="pv-card"><h3>Greeting</h3><div class="pv-sub">Callers to <strong>' + esc(d.number) + '</strong> hear this, then leave a message that is emailed to ' + esc(d.inbox) + '.'
      + (d.published_at ? ' Last published ' + esc(when(d.published_at)) + (d.published_by ? ' by ' + esc(d.published_by) : '') + '.' : '') + '</div>'
      + '<div class="pv-f">'
      + '<div><label>Voice</label><select data-k="voice">' + opts + '</select></div>'
      + '<div><label>Longest message</label><select data-k="max_length">' + [60, 120, 180, 240, 300].map(function (n) { return '<option value="' + n + '"' + (Number(s.max_length) === n ? ' selected' : '') + '>' + (n / 60) + ' minute' + (n === 60 ? '' : 's') + '</option>'; }).join('') + '</select></div>'
      + '<div class="full"><label>Greeting (before the beep)</label><textarea data-k="greeting" rows="3" maxlength="1000">' + esc(s.greeting) + '</textarea></div>'
      + '<div class="full"><label>If the caller leaves no message</label><input data-k="no_message" maxlength="500" value="' + esc(s.no_message) + '"></div>'
      + '<div class="full"><label>After a message is left</label><input data-k="goodbye" maxlength="300" value="' + esc(s.goodbye) + '"></div>'
      + '</div><div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">'
      + '<span style="font-size:12px;color:#64748B;flex:1;min-width:200px;">Tip: write numbers and emails the way they should be spoken, e.g. "info at kiddietrac dot com". Call the line after saving to hear it.</span>'
      + '<button class="kt-btn kt-btn-primary" data-save>Save and publish</button></div></div>';
    box.querySelector('[data-save]').addEventListener('click', async function () {
      var b = this, v = {};
      box.querySelectorAll('[data-k]').forEach(function (el) { v[el.getAttribute('data-k')] = el.value; });
      v.max_length = parseInt(v.max_length, 10);
      b.disabled = true; b.textContent = 'Publishing…';
      try { await KT.Api.put('/platform/phone', v); toast('Greeting published. Call ' + d.number + ' to hear it.', 'success'); await settings(main); }
      catch (e) { toast(e.message || 'Could not publish', 'error'); b.disabled = false; b.textContent = 'Save and publish'; }
    });
  }

  async function voicemails(main) {
    var box = main.querySelector('#pv-vm'), d;
    try { d = await KT.Api.get('/platform/phone/voicemails?days=' + days); } catch (e) { box.innerHTML = '<div class="pv-card" style="color:#B91C1C">Could not load voicemails: ' + esc(e.message || e) + '</div>'; return; }
    var st = d.stats || {}, rows = d.voicemails || [];
    function tile(l, v, c) { return '<div>' + l + '<b' + (c ? ' style="color:' + c + '"' : '') + '>' + v + '</b></div>'; }
    box.innerHTML = '<div class="pv-card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="flex:1">Voicemails</h3>'
      + '<select data-days style="height:30px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px">' + [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days'], ['365', 'Last year'], ['all', 'All time']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === days ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>'
      + '<div class="pv-tiles">' + tile('Received', st.received) + tile('Emailed', st.emailed, '#166534') + tile('Email failed', st.email_failed, st.email_failed ? '#B91C1C' : '') + tile('Not yet heard', st.unheard, st.unheard ? '#B45309' : '')
      + tile('Listened here', st.listened) + tile('Deleted', st.deleted) + tile('Total length', secs(st.total_seconds)) + '</div>'
      + (rows.length ? '<div style="overflow-x:auto"><table class="pv-t" data-kt-no-kebab data-kt-no-controls data-kt-no-filter><thead><tr><th>Caller</th><th>When</th><th class="hm">Length</th><th class="hm">Email</th><th>Recording</th><th></th></tr></thead><tbody>'
        + rows.map(function (r) {
          var em = r.email_status === 'sent' ? ['Emailed', '#DCFCE7', '#166534'] : r.email_status === 'failed' ? ['Failed', '#FEE2E2', '#991B1B'] : ['—', '#F1F5F9', '#475569'];
          var rec = r.deleted_at ? '<span style="color:#94A3B8;font-size:12px">Deleted ' + esc(when(r.deleted_at)) + (r.deleted_by_first ? ' by ' + esc(r.deleted_by_first) : '') + '</span>'
            : r.has_audio ? '<button class="kt-btn kt-btn-sm" data-play="' + r.id + '">▶ Play</button>' + (r.listened_at ? ' <span style="font-size:11.5px;color:#64748B">heard' + (r.listened_by_first ? ' by ' + esc(r.listened_by_first) : '') + '</span>' : ' <span class="pv-pill" style="background:#FEF3C7;color:#92400E">New</span>')
            : '<span style="color:#B45309;font-size:12px">Not downloaded (see the email)</span>';
          return '<tr' + (r.deleted_at ? ' style="opacity:.6"' : '') + '><td><strong>' + esc(phone(r.from_number)) + '</strong></td><td>' + esc(when(r.created_at)) + '</td><td class="hm">' + secs(r.duration) + '</td>'
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
          if (!res.ok) { throw new Error('Could not load the recording (' + res.status + ')'); }
          var url = URL.createObjectURL(await res.blob());
          var cell = box.querySelector('[data-rec="' + id + '"]');
          cell.innerHTML = '<audio controls autoplay src="' + url + '"></audio>';
        } catch (e) { toast(e.message, 'error'); b.disabled = false; }
      });
    });
    box.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: 'Delete this voicemail?', description: 'The recording is removed from KiddieTrac and from Telnyx. The copy already emailed to the inbox is not affected. It still counts in the totals as deleted.', okLabel: 'Delete' }) : false;
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
    KT.Shell.registerScreen('platform_admin:phone-voicemail', render);
    KT.Shell.registerScreen('agency_admin:phone-voicemail', render);
  }
  reg(0);
})(window);
