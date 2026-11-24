/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Room Controls → Walks (2026-10-21)

   Anthony: "Yes, add a per-agency switch" for background walk detection.
   The KiddieTrac Android app can notice an educator leaving the centre even with the
   phone in a pocket — but only by watching location in the background, which Android
   shows as a permanent notification on the educator's own phone. That is a choice about
   staff privacy, so it is per agency and OFF unless an administrator turns it on.
   With it off, detection still runs whenever the app is open or brought back.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  async function paintSettings(pane) {
    pane.innerHTML = '<div class="kt-card" style="max-width:720px;">Loading…</div>';
    var d;
    try { d = await KT.Api.get('/admin/walk-settings'); }
    catch (e) { pane.innerHTML = ''; return; }   // 403: Room Controls says "only administrators"
    var on = !!(d.settings && d.settings.background_detect), ed = !!d.can_edit;
    var missing = d.centres_missing_coordinates || [];
    pane.innerHTML = '<div class="kt-card" style="max-width:720px;">'
      + '<div style="font-weight:800;font-size:15px;color:#0F172A;margin-bottom:6px;">🚶 Walk detection</div>'
      + '<p style="font-size:13px;color:#475569;line-height:1.55;margin:0 0 12px;">When children are signed in and an educator’s phone '
      + 'leaves the centre, KiddieTrac offers to start a walk; when they come back with a walk still running, it offers to end it. '
      + 'This always works while the app is open or brought back to the screen.</p>'
      + '<label style="display:flex;gap:12px;align-items:flex-start;border:1.5px solid ' + (on ? '#0E7C90' : '#E2E8F0') + ';border-radius:12px;padding:12px 14px;cursor:' + (ed ? 'pointer' : 'default') + ';">'
      + '<input type="checkbox" id="ws-bg"' + (on ? ' checked' : '') + (ed ? '' : ' disabled') + ' style="width:18px;height:18px;margin-top:2px;flex:0 0 auto;">'
      + '<span><strong style="color:#0F172A;">Detect walks in the background (Android app)</strong>'
      + '<span style="display:block;font-size:12.5px;color:#64748B;line-height:1.5;margin-top:3px;">The KiddieTrac Android app watches the educator’s location '
      + 'in the background so a walk is noticed even with the phone in a pocket. Android asks each educator to allow location “all the time” '
      + 'and shows a <strong>permanent location notification</strong> on their phone while it runs. Locations stay on the phone — only '
      + '“you’ve left / you’re back” is worked out, nothing is sent. Changes apply the next time the app is opened. Off by default.</span></span></label>'
      + (missing.length ? '<div style="margin-top:12px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:9px 12px;font-size:12.5px;">'
          + 'Walk detection is off at ' + esc(missing.join(', ')) + ': no map location is saved for ' + (missing.length === 1 ? 'that centre' : 'those centres') + ' (check the address).</div>' : '')
      + (ed ? '<div style="display:flex;gap:10px;align-items:center;margin-top:14px;"><button type="button" id="ws-save" class="kt-btn kt-btn-primary" data-kt-iconized="1">Save</button>'
          + '<span id="ws-msg" style="font-size:13px;color:#64748B;"></span></div>'
          : '<div style="font-size:12.5px;color:#94A3B8;margin-top:10px;">Only an agency administrator can change this.</div>')
      + '</div>';
    if (!ed) { return; }
    pane.querySelector('#ws-save').onclick = function () {
      var btn = this, msg = pane.querySelector('#ws-msg');
      btn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      KT.Api.post('/admin/walk-settings', { background_detect: pane.querySelector('#ws-bg').checked })
        .then(function () { msg.style.color = '#1E8E60'; msg.textContent = '✓ Saved'; })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save.'; })
        .then(function () { btn.disabled = false; });
    };
  }

  KT.WalkSettings = { paintSettings: paintSettings };
})(window);
