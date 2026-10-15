/* ═══════════════════════════════════════════════════════════════════════
   Seasonal email headers (#email-seasons) — platform admins — 2026-10-09

   Anthony: "change all email templates using the existing header logo to use the new
   header logo during seasonal changes that is configurable from a set time and will
   change automatically ... I can add more later through the gui". Each season: a banner,
   a start and an end (Toronto time), on/off, and "every year". Outside every season the
   standard banner is used. White-label agencies keep their own logo.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(m, t) { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(m, t || 'info'); }
  // The stored value IS Toronto wall-clock ("2026-10-01T00:00"); show it as written, never through the browser's zone.
  function nice(v, yearly) {
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || ''); if (!m) return '—';
    var M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1];
    var h = +m[4], ap = h < 12 ? 'a.m.' : 'p.m.'; h = h % 12 || 12;
    return M + ' ' + (+m[3]) + (yearly ? '' : ', ' + m[1]) + ', ' + h + ':' + m[5] + ' ' + ap;
  }
  function apiBase() { return KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token') || ''; }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:1000px;"><div id="es-body" style="color:#64748B;">Loading…</div></div>';
    var body = main.querySelector('#es-body');
    var d;
    try { d = await Api.get('/admin/email-header-seasons'); }
    catch (e) { body.innerHTML = '<div class="kt-card">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    var rows = d.seasons || [];
    body.innerHTML = '<div class="kt-card" style="margin-bottom:14px;"><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">'
      + '<img src="' + esc(d.current ? d.current.image_url : d.default_url) + '" alt="" style="width:260px;max-width:100%;border-radius:10px;border:1px solid #E2E8F0;">'
      + '<div style="flex:1;min-width:220px;"><div style="font-size:11px;font-weight:800;color:#64748B;letter-spacing:.5px;text-transform:uppercase;">In use right now</div>'
      + '<div style="font-size:17px;font-weight:800;margin-top:2px;">' + esc(d.current ? d.current.name : 'Standard header') + '</div>'
      + '<div style="font-size:13px;color:#64748B;margin-top:4px;line-height:1.5;">Every KiddieTrac email uses this banner. It switches automatically at the start and end of each season (' + esc(d.tz) + '). White-label agencies keep their own logo.</div></div>'
      + '<button type="button" id="es-add" class="kt-btn kt-btn-primary">＋ Add a season</button></div></div>'
      + (rows.length ? rows.map(function (s, i) {
          return '<div class="kt-card" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:10px;' + (s.active ? '' : 'opacity:.6;') + '">'
            + '<img src="' + esc(s.image_url) + '" alt="" style="width:220px;max-width:100%;border-radius:10px;border:1px solid #E2E8F0;">'
            + '<div style="flex:1;min-width:200px;"><div style="font-size:15px;font-weight:800;">' + esc(s.name)
            + (d.current && d.current.id === s.id ? ' <span style="font-size:11px;font-weight:800;background:#ECFDF5;color:#047857;border-radius:999px;padding:2px 8px;">LIVE NOW</span>' : '')
            + (s.active ? '' : ' <span style="font-size:11px;font-weight:800;background:#F1F5F9;color:#64748B;border-radius:999px;padding:2px 8px;">OFF</span>') + '</div>'
            + '<div style="font-size:13px;color:#475569;margin-top:3px;">' + esc(nice(s.starts_local, s.repeat_yearly)) + ' → ' + esc(nice(s.ends_local, s.repeat_yearly)) + (s.repeat_yearly ? ' · every year' : '') + '</div></div>'
            + '<div style="white-space:nowrap;"><button type="button" class="kt-btn" data-prev="' + i + '" data-kttip="Preview email">👁 Preview email</button>'
            + '<button type="button" class="kt-btn" data-edit="' + i + '" data-kttip="Edit">✏️ Edit</button>'
            + '<button type="button" class="kt-btn" data-del="' + i + '" data-kttip="Delete">🗑 Delete</button></div></div>';
        }).join('') : '<div class="kt-card" style="color:#64748B;text-align:center;">No seasons yet — the standard header is used all year.</div>');

    body.querySelector('#es-add').addEventListener('click', function () { editDialog(null, function () { render(main); }); });
    body.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { editDialog(rows[+b.getAttribute('data-edit')], function () { render(main); }); }); });
    body.querySelectorAll('[data-prev]').forEach(function (b) { b.addEventListener('click', function () { preview(rows[+b.getAttribute('data-prev')].image_url); }); });
    body.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var s = rows[+b.getAttribute('data-del')];
        var ok = !KT.confirm || await KT.confirm({ title: 'Delete “' + s.name + '”?', description: 'Emails go back to the standard header during these dates.', okLabel: 'Delete' });
        if (!ok) return;
        Api.delete('/admin/email-header-seasons/' + s.id).then(function () { toast('Season deleted', 'success'); render(main); }).catch(function (e) { toast((e && e.message) || 'Could not delete', 'error'); });
      });
    });
  }

  async function preview(url) {
    var r;
    try { r = await Api.post('/admin/email-header-seasons/preview', { image_url: url }); } catch (e) { toast((e && e.message) || 'Could not preview', 'error'); return; }
    var ov = document.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1'); ov.className = 'kt-scrim';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:14px;width:100%;max-width:720px;height:min(88vh,900px);display:flex;flex-direction:column;overflow:hidden;">'
      + '<div style="padding:12px 16px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;"><div style="flex:1;font-weight:800;">Email preview — nothing is sent</div><button type="button" class="kt-btn" data-close>Close</button></div>'
      + '<iframe sandbox="" style="flex:1;width:100%;border:0;background:#F4F6F8;"></iframe></div>';
    ov.querySelector('iframe').srcdoc = r.html || '';
    ov.querySelector('[data-close]').addEventListener('click', function () { ov.remove(); });
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
  }

  function editDialog(s, done) {
    var F = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;font-family:inherit;';
    var L = 'display:block;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 5px;';
    var box = document.createElement('div');
    box.innerHTML = '<label style="' + L + 'margin-top:0;">Name</label><input id="es-name" style="' + F + '" value="' + esc(s ? s.name : '') + '" placeholder="e.g. Fall & Halloween">'
      + '<label style="' + L + '">Banner image ' + (s ? '(optional — leave empty to keep the current one)' : '') + '</label>'
      + '<input id="es-img" type="file" accept="image/png,image/jpeg,image/webp" style="' + F + '">'
      + '<div style="font-size:12px;color:#64748B;margin-top:4px;">Same shape as the standard header works best (about 1920 × 820). It is resized for email automatically.</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><div><label style="' + L + '">Starts</label><input id="es-start" type="datetime-local" style="' + F + '" value="' + esc(s ? s.starts_local : '') + '"></div>'
      + '<div><label style="' + L + '">Ends</label><input id="es-end" type="datetime-local" style="' + F + '" value="' + esc(s ? s.ends_local : '') + '"></div></div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:14px;"><input id="es-yearly" type="checkbox"' + (!s || s.repeat_yearly ? ' checked' : '') + '> Repeat every year (the year in the dates is ignored)</label>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:14px;"><input id="es-on" type="checkbox"' + (!s || s.active ? ' checked' : '') + '> On</label>'
      + '<div id="es-msg" style="font-size:13px;color:#B91C1C;margin-top:10px;min-height:16px;"></div>';
    KT.Shell.Modal.open({
      title: s ? 'Edit season' : 'Add a season',
      body: box,
      actions: [{ label: 'Cancel' }, {
        label: s ? 'Save' : 'Add season', primary: true, busyLabel: 'Saving…',
        onClick: async function () {
          var msg = box.querySelector('#es-msg');
          var fd = new FormData();
          var name = box.querySelector('#es-name').value.trim(), st = box.querySelector('#es-start').value, en = box.querySelector('#es-end').value;
          var file = box.querySelector('#es-img').files[0];
          if (!name || !st || !en) { msg.textContent = 'Name, start and end are needed.'; return false; }
          if (!s && !file) { msg.textContent = 'Choose the banner image.'; return false; }
          fd.append('name', name); fd.append('starts_local', st); fd.append('ends_local', en);
          fd.append('repeat_yearly', box.querySelector('#es-yearly').checked ? '1' : '0');
          fd.append('active', box.querySelector('#es-on').checked ? '1' : '0');
          if (file) fd.append('image', file);
          try {
            var res = await fetch(apiBase() + '/admin/email-header-seasons' + (s ? '/' + s.id : ''), { method: 'POST', body: fd,
              headers: { Authorization: 'Bearer ' + token(), Accept: 'application/json' } });
            var j = await res.json().catch(function () { return {}; });
            if (!res.ok) { msg.textContent = j.message || ('Could not save (' + res.status + ')'); return false; }
            toast(s ? 'Season saved' : 'Season added', 'success'); done();
          } catch (e) { msg.textContent = 'Network problem — try again.'; return false; }
        },
      }],
    });
  }

  /* Lives in Branding as a sub-tab since 2026-10-15 (screen-admin-branding.js draws it
     there). The hash still works for bookmarks. */
  KT.EmailSeasons = { render: render };
  ['platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':email-seasons', render); });
})(window);
