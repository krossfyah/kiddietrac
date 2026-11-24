/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — signed-in devices (2026-11-09, Anthony: "Sign out a lost phone or kiosk remotely in one click ...
   wire this up for kiddietrac").

     KT.devices.open()                         your own devices (Settings → Security)
     KT.devices.open({ userId, name })         a person's devices (an administrator, from their profile)

   One popup: every phone, tablet and browser signed in to the account, when each was last used, and Sign out on each,
   plus Sign out everywhere. Signing a device out ends its session on the server (DeviceSessionController): its next
   tap is the sign-in page, Face ID / PIN unlock there stops working, and it stops getting notifications. Kiosk
   tablets sign in with the centre's link instead: Centres → Kiosk mode → Sign out all kiosk tablets.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  var ICON = { app: '📱', browser: '💻', admin_view: '👁' };

  function open(opts) {
    opts = opts || {};
    var Api = KT.Api, Modal = KT.Shell && KT.Shell.Modal;
    if (!Api || !Modal) return;
    var other = !!opts.userId;
    var base = other ? '/admin/users/' + opts.userId + '/devices' : '/me/devices';
    var first = String(opts.name || '').split(' ')[0] || 'this person';
    var body = document.createElement('div');
    body.setAttribute('data-kt-nospin', '');
    body.innerHTML = '<div style="color:#64748B;font-size:13px;padding:6px 0;">Loading devices…</div>';
    Modal.open({ title: '📱 Signed-in devices' + (other ? ' · ' + first : ''), body: body, actions: [{ label: 'Close' }] });

    function paint(list) {
      var h = '<div style="font-size:12.5px;color:#64748B;margin-bottom:10px;line-height:1.5;">'
        + (other ? 'Every phone, tablet and browser ' + esc(first) + ' is signed in on. ' : 'Every phone, tablet and browser you are signed in on. ')
        + 'Lost a phone? Sign it out: it goes back to the sign-in page, Face ID and PIN unlock stop working there, and it stops getting notifications.</div>';
      if (!list.length) h += '<div style="text-align:center;color:#64748B;font-size:13px;padding:18px;border:1.5px dashed #CBD5E1;border-radius:12px;">Not signed in anywhere.</div>';
      h += list.map(function (d) {
        return '<div style="display:flex;align-items:center;gap:10px;padding:9px 11px;border:1px solid ' + (d.current ? '#99F6E4' : '#E2E8F0') + ';background:' + (d.current ? '#F0FDFA' : '#fff') + ';border-radius:10px;margin-bottom:7px;">'
          + '<span style="font-size:20px;" aria-hidden="true">' + (ICON[d.kind] || '💻') + '</span>'
          + '<div style="flex:1;min-width:0;"><div style="font-weight:700;font-size:13.5px;color:#0F172A;overflow-wrap:anywhere;">' + esc(d.label)
          + (d.current ? ' <span style="font-size:11px;font-weight:800;color:#0F766E;">· this device</span>' : '') + '</div>'
          + '<div style="font-size:11.5px;color:#64748B;">Last used ' + esc(d.last_used || '—') + ' · signed in ' + esc(d.signed_in || '—') + (d.push ? ' · gets notifications' : '') + '</div></div>'
          + (d.current ? '' : '<button type="button" data-out="' + d.id + '" data-kt-iconized="1" style="height:30px;padding:0 12px;border:1px solid #FCA5A5;background:#fff;color:#B91C1C;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">Sign out</button>')
          + '</div>';
      }).join('');
      var others = list.filter(function (d) { return !d.current; }).length;
      if (others > 1 || (other && others)) {
        h += '<button type="button" id="kt-dev-all" data-kt-iconized="1" style="margin-top:6px;height:32px;padding:0 14px;border:0;background:#B91C1C;color:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">'
          + (other ? 'Sign ' + esc(first) + ' out everywhere' : 'Sign out of every other device') + '</button>';
      }
      body.innerHTML = h;
      body.querySelectorAll('[data-out]').forEach(function (b) {
        b.addEventListener('click', async function () {
          var d = list.filter(function (x) { return String(x.id) === b.getAttribute('data-out'); })[0] || {};
          var ok = KT.confirm ? await KT.confirm({ title: 'Sign out ' + (d.label || 'this device') + '?', description: 'It goes back to the sign-in page straight away. ' + (other ? first + ' can sign in again with their password.' : 'You can sign in on it again with your password.'), okLabel: 'Sign out' }) : true;
          if (!ok) return;
          b.disabled = true; b.textContent = 'Signing out…';
          try { await Api.post(base + '/sign-out', { token_id: d.id }); toast('🔒', 'Signed out', d.label || '', '#16A34A'); load(); if (typeof opts.onChange === 'function') opts.onChange(); }
          catch (e) { b.disabled = false; b.textContent = 'Sign out'; toast('⚠️', 'Could not sign it out', (e && e.message) || '', '#DC2626'); }
        });
      });
      var all = body.querySelector('#kt-dev-all');
      if (all) all.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: other ? 'Sign ' + first + ' out everywhere?' : 'Sign out of every other device?',
          description: other ? 'Every phone, tablet and browser ' + first + ' uses goes back to the sign-in page. They can sign in again with their password.' : 'Every phone, tablet and browser except this one goes back to the sign-in page.',
          okLabel: 'Sign out everywhere' }) : true;
        if (!ok) return;
        all.disabled = true;
        try {
          var r = await Api.post(base + '/sign-out', other ? {} : { others: true });
          toast('🔒', 'Signed out of ' + ((r && r.signed_out) || 0) + ' device' + ((r && r.signed_out) === 1 ? '' : 's'), '', '#16A34A'); load();
          if (typeof opts.onChange === 'function') opts.onChange();
        } catch (e) { all.disabled = false; toast('⚠️', 'Could not sign out', (e && e.message) || '', '#DC2626'); }
      });
    }
    function load() {
      Api.get(base).then(function (r) { paint((r && r.devices) || []); })
        .catch(function (e) { body.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc((e && e.message) || 'Could not load the devices.') + '</div>'; });
    }
    load();
  }

  KT.devices = { open: open };
})(window);
