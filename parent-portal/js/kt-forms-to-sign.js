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
          + (f.draft_values ? '<div style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#0F766E;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:999px;padding:3px 10px;">💾 Draft saved</div>' : '')
          + '<div style="display:flex;gap:10px;margin-top:13px;flex-wrap:wrap;">'
          + '<button class="mf-view" data-u="' + esc(fileUrl(f.file_url)) + '" type="button" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:10px;padding:10px 16px;font-weight:700;font-size:13px;cursor:pointer;">📄 Read the form</button>'
          + (f.fillable
              // Fillable: typing and signing are one journey, so one primary action.
              ? '<button class="mf-fill" data-id="' + f.id + '" data-t="' + esc(f.title) + '" data-u="' + esc(fileUrl(f.file_url)) + '" type="button" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;">📝 Fill &amp; sign</button>'
              : '<button class="mf-sign" data-id="' + f.id + '" data-t="' + esc(f.title) + '" type="button" style="background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;">✍️ Sign</button>')
          + '</div></div>';
      }).join('');
      el.querySelectorAll('.mf-view').forEach(function (b) { b.addEventListener('click', function () { openUrl(b.getAttribute('data-u')); }); });
      el.querySelectorAll('.mf-sign').forEach(function (b) { b.addEventListener('click', function () { signForm(b.getAttribute('data-id'), b.getAttribute('data-t'), el); }); });
      el.querySelectorAll('.mf-fill').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!KT.formFiller) { if (KT.toast) KT.toast('⚠️', 'Unavailable', 'The form filler is not available.', '#B91C1C'); return; }
          var id = b.getAttribute('data-id');
          // Look the form up in the payload rather than serialising a whole draft
          // into a data- attribute.
          var rec = null;
          for (var i = 0; i < forms.length; i++) { if (String(forms[i].id) === String(id)) { rec = forms[i]; break; } }
          KT.formFiller.open({
            id: id,
            title: b.getAttribute('data-t'),
            fileUrl: b.getAttribute('data-u'),
            draftValues: rec && rec.draft_values ? rec.draft_values : null,
          }).then(function () {
            // ALWAYS reload, not only on submit. The card list is rendered once from
            // one /assigned payload; after saving a draft that payload still says
            // draft_values:null, so reopening the form restored nothing and the work
            // looked lost. Reloading re-reads the draft from the server.
            load(el);
          });
        });
      });
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

  /* ── Count badge on the "Forms to sign" tile + educator Today alert ── */
  var _cnt = null, _fetching = false;
  function fetchCount() {
    if (_fetching) return; _fetching = true;
    Api.get('/managed-forms/assigned').then(function (d) { _cnt = (d && d.count) || 0; _fetching = false; paint(); }).catch(function () { _fetching = false; });
  }
  KT.refreshMyFormsCount = function () { _cnt = null; fetchCount(); };
  function paint() {
    if (_cnt == null) return;
    // Tile badge (any role's home).
    document.querySelectorAll('a.kt-tile[href="#my-forms"]').forEach(function (a) {
      var b = a.querySelector('.kt-mf-badge');
      if (_cnt > 0) {
        if (!b) {
          b = document.createElement('span'); b.className = 'kt-mf-badge';
          b.style.cssText = 'position:absolute;top:8px;right:8px;min-width:20px;height:20px;padding:0 5px;border-radius:11px;background:#DC2626;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(220,38,38,.45);z-index:2;';
          if (getComputedStyle(a).position === 'static') a.style.position = 'relative';
          a.appendChild(b);
        }
        b.textContent = _cnt > 9 ? '9+' : String(_cnt);
      } else if (b) { b.remove(); }
    });
    // Educator "Today at a glance" alert banner.
    try {
      var brief = document.getElementById('kt-day-brief');
      var existing = document.getElementById('kt-mf-alert');
      if (brief && _cnt > 0) {
        if (!existing) {
          var al = document.createElement('div');
          al.id = 'kt-mf-alert';
          al.style.cssText = 'background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:11px 14px;margin:0 0 12px;font-size:13.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;';
          al.innerHTML = '✍️ You have ' + _cnt + ' form' + (_cnt === 1 ? '' : 's') + ' that need your signature — tap to review.';
          al.onclick = function () { location.hash = 'my-forms'; };
          brief.parentNode.insertBefore(al, brief);
        }
      } else if (existing) { existing.remove(); }
    } catch (e) {}
  }
  function tick() { if (_cnt == null) fetchCount(); else paint(); }
  if (window.KT && KT.sweepBus && KT.sweepBus.on) { KT.sweepBus.on(paint); }
  setInterval(tick, 5000);
  window.addEventListener('hashchange', function () { setTimeout(tick, 300); });
  setTimeout(fetchCount, 1500);

  // Re-count after a successful sign so the badge/alert clear.
  var _origSign = signForm;
  signForm = function (id, title, el) {
    if (!KT.signaturePad) { if (KT.toast) KT.toast('⚠️', 'Unavailable', 'Signature pad is not available.', '#B91C1C'); return; }
    KT.signaturePad({ title: 'Sign: ' + title, subtitle: 'Draw your signature below to confirm you have read this form.', okLabel: 'Submit signature' }).then(function (dataUrl) {
      if (!dataUrl) return;
      Api.post('/managed-forms/' + id + '/sign', { signature: dataUrl }).then(function () {
        if (KT.toast) KT.toast('✅', 'Signed', 'Thank you — your signature has been recorded.', '#16A34A');
        _cnt = null; fetchCount(); load(el);
      }).catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Could not submit', e.message || '', '#B91C1C'); });
    });
  };
})();
