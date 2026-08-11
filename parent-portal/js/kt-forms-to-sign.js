/* ═══════════════════════════════════════════════════════════════════
   Forms to sign (parent / educator / home-visitor). Lists the managed forms
   assigned to the caller's role that they haven't signed yet; each is read
   (PDF) then e-signed on the signature pad. Backend: GET /managed-forms/assigned,
   POST /managed-forms/{id}/sign. Registered as "<role>:my-forms".
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var Api = KT.Api;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var API_HOST = ((KT.API_BASE || 'https://api.kiddietrac.com/api/v1')).replace(/\/api\/v1\/?$/, '');
  function fileUrl(u) { return u ? (/^https?:/.test(u) ? u : API_HOST + u) : ''; }
  function openUrl(u) {
    try { if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.Browser) { Capacitor.Plugins.Browser.open({ url: u }); return; } } catch (e) {}
    window.open(u, '_blank');
  }

  function render(main) {
    main.innerHTML =
      '<div style="padding:20px;max-width:640px;margin:0 auto;">'
      + '<div style="text-align:center;margin-bottom:14px;"><div style="font-size:40px;line-height:1;">✍️</div>'
      + '<h2 style="margin:6px 0 2px;color:#0F172A;">Forms to sign</h2>'
      + '<p style="color:#64748B;font-size:13.5px;margin:0;">Read each form, then sign to confirm.</p></div>'
      + '<div id="mf-list"><div style="padding:26px;text-align:center;color:#94A3B8;">Loading…</div></div></div>';
    load(main.querySelector('#mf-list'));
  }

  function load(el) {
    Api.get('/managed-forms/assigned').then(function (d) {
      var forms = (d && d.forms) || [];
      if (!forms.length) {
        el.innerHTML = '<div style="padding:32px 20px;text-align:center;color:#166534;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:14px;font-weight:600;">🎉 You’re all caught up — nothing to sign right now.</div>';
        return;
      }
      el.innerHTML = forms.map(function (f) {
        return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px 18px;margin-bottom:12px;box-shadow:0 1px 4px rgba(15,23,42,.05);">'
          + '<div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(f.title) + '</div>'
          + (f.description ? '<div style="font-size:13px;color:#64748B;margin-top:3px;line-height:1.5;">' + esc(f.description) + '</div>' : '')
          + '<div style="display:flex;gap:10px;margin-top:13px;flex-wrap:wrap;">'
          + '<button class="mf-view" data-u="' + esc(fileUrl(f.file_url)) + '" type="button" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:10px;padding:10px 16px;font-weight:700;font-size:13px;cursor:pointer;">📄 Read the form</button>'
          + '<button class="mf-sign" data-id="' + f.id + '" data-t="' + esc(f.title) + '" type="button" style="background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;">✍️ Sign</button>'
          + '</div></div>';
      }).join('');
      el.querySelectorAll('.mf-view').forEach(function (b) { b.addEventListener('click', function () { openUrl(b.getAttribute('data-u')); }); });
      el.querySelectorAll('.mf-sign').forEach(function (b) { b.addEventListener('click', function () { signForm(b.getAttribute('data-id'), b.getAttribute('data-t'), el); }); });
    }).catch(function (e) { el.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: ' + esc(e.message || '') + '</div>'; });
  }

  function signForm(id, title, el) {
    if (!KT.signaturePad) { if (KT.toast) KT.toast('⚠️', 'Unavailable', 'Signature pad is not available.', '#B91C1C'); return; }
    KT.signaturePad({ title: 'Sign: ' + title, subtitle: 'Draw your signature below to confirm you have read this form.', okLabel: 'Submit signature' }).then(function (dataUrl) {
      if (!dataUrl) return;
      Api.post('/managed-forms/' + id + '/sign', { signature: dataUrl }).then(function () {
        if (KT.toast) KT.toast('✅', 'Signed', 'Thank you — your signature has been recorded.', '#16A34A');
        load(el);
      }).catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Could not submit', e.message || '', '#B91C1C'); });
    });
  }

  try {
    ['guardian', 'educator', 'home_visitor'].forEach(function (r) {
      if (KT.Shell && KT.Shell.registerScreen) KT.Shell.registerScreen(r + ':my-forms', render);
    });
  } catch (e) {}
  KT.renderMyForms = render;
})();
