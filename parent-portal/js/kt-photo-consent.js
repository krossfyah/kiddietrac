/* ═══════════════════════════════════════════════════════════════════
   kt-photo-consent.js: photo permission, per child (2026-11-06).

   Anthony: "wire this up and add guards for all new agencies as well so we
   never run into this issue ever again". Onboarding asked each parent "OK
   with classroom photos?" and nothing ever read the answer. Now every parent
   answers for each of their children (API: MediaConsentController, rule:
   App\Support\MediaConsent), in every agency:
     - Do not photograph my child
     - Photos for my family only
     - Photos, including group photos other families see
   plus a separate yes/no for marketing. Any No from any parent wins.

   1. THE ASK. A parent with a child they have not answered for gets this
      popup once the portal is up. It has no ✕ and no "not now": until they
      answer, the child is kept out of every photo another family sees, so
      silence is never taken as consent. It steps aside for anything urgent
      (Safe Arrival, an emergency, an update, the lock screen, another
      required gate) and comes back when that is gone. A super admin viewing
      as a parent is never asked.
   2. My settings → Photos (screen-settings.js calls KT.photoConsent.renderInto): change the
      answer any time.
   3. The sign-up wizard asks too (screen-onboarding.js, its own step, source "onboarding").
   ONLY THE PRIMARY PARENT CHOOSES (2026-11-06, App\Support\MediaConsent::decidersFor). Another
   parent is never asked; their screen shows what applies and who chose it, read only. #photo-permissions still works for a parent and opens that tab
      (Anthony, 2026-11-06: "move into My settings").
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktPhotoConsent) return; window.__ktPhotoConsent = true;

  var ID = 'kt-photo-consent';
  var PATH = '/me/photo-consent';
  var HEAD = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';
  var ORDER = ['group', 'family', 'none'];
  var HINT = {
    group: 'They can appear in group photos the other families in their room see.',
    family: 'Only your family sees photos of them. They are left out of group photos.',
    none: 'Staff will not take or share photos or videos of them.',
  };

  function T(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function api() { return window.KT && KT.Api; }
  function toast(icon, title, msg, colour) { try { if (window.KT && KT.toast) KT.toast(icon, title, msg || '', colour); } catch (e) {} }

  function styles() {
    return '<style>'
      + '#' + ID + '{position:fixed;inset:0;z-index:2147482000;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:16px;}'
      + '#' + ID + ' .kpc-card{background:#fff;color:#0F172A;border-radius:16px;width:100%;max-width:560px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}'
      + '#' + ID + ' .kpc-head{background:' + HEAD + ';box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;padding:14px 18px 15px;flex:0 0 auto;}'
      + '#' + ID + ' .kpc-head h2{margin:0;font-size:17px;font-weight:700;color:#fff;}'
      + '#' + ID + ' .kpc-head div{font-size:12.5px;opacity:.9;margin-top:3px;}'
      + '#' + ID + ' .kpc-body{padding:14px 18px;overflow:auto;flex:1 1 auto;scrollbar-width:thin;}'
      + '#' + ID + ' .kpc-foot{padding:10px 18px 14px;border-top:1px solid #E7EBF0;display:flex;gap:10px;align-items:center;flex:0 0 auto;}'
      + '.kpc-kid{border:1px solid #E7EBF0;border-radius:12px;padding:10px 12px;margin:0 0 10px;}'
      + '.kpc-kid h3{margin:0 0 6px;font-size:14px;font-weight:700;color:#0F2540;}'
      + '.kpc-opt{display:flex;gap:8px;align-items:flex-start;padding:6px 8px;border-radius:8px;cursor:pointer;font-size:13px;}'
      + '.kpc-opt:hover{background:#F1F5F9;}'
      + '.kpc-opt input{flex:0 0 auto;width:16px;height:16px;margin:2px 0 0;}'
      + '.kpc-opt small{display:block;color:#64748B;font-size:12px;margin-top:1px;}'
      + '.kpc-mk{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#334155;margin:6px 8px 0;}'
      + '.kpc-mk input{flex:0 0 auto;width:15px;height:15px;margin:1px 0 0;}'
      + '.kpc-note{font-size:12px;color:#AE7015;margin:6px 8px 0;}'
      + '.kpc-save{height:32px;padding:0 16px;border-radius:8px;border:1px solid #0F2540;background:#0F2540;color:#fff;font-size:13px;font-weight:700;cursor:pointer;}'
      + '.kpc-save[disabled]{opacity:.45;cursor:default;}'
      + '.kpc-msg{font-size:12.5px;color:#64748B;flex:1 1 auto;}'
      + '</style>';
  }

  /* The form for every child: one radio group each, plus marketing. Shared by the ask, the screen and the sign-up
     wizard. A child this parent does not choose for (not the primary parent) is a read-only card instead. */
  function readOnlyHtml(k, L) {
    var lvl = k.level || k.effective || 'family';
    var who = (k.chosen_by || []).join(' and ');
    return '<div class="kpc-kid" data-kpc-ro="' + k.id + '"><h3>' + esc(k.first_name) + '</h3>'
      + '<div style="font-size:13px;font-weight:700;color:#0F2540;margin:0 8px;">' + esc(T(L[lvl] || lvl)) + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin:2px 8px 0;">' + esc(T(k.level ? HINT[lvl] : 'Not chosen yet, so ' + k.first_name + ' is in photos for your family only.')) + '</div>'
      + (k.level && lvl !== 'none' ? '<div style="font-size:12px;color:#64748B;margin:4px 8px 0;">' + esc(T(k.marketing ? 'Marketing use: yes' : 'Marketing use: no')) + '</div>' : '')
      + '<div style="font-size:12px;color:#0E7C90;font-weight:600;margin:8px 8px 0;">🔒 ' + esc(T(who ? who + ', the primary parent, chooses this.' : 'The primary parent chooses this.')) + '</div>'
      + '</div>';
  }
  function canAnswer(data) { return (data.children || []).some(function (k) { return k.can_answer !== false; }); }
  function formHtml(data) {
    var L = data.labels || {};
    return (data.children || []).map(function (k) {
      if (k.can_answer === false) return readOnlyHtml(k, L);
      var opts = ORDER.map(function (lvl) {
        return '<label class="kpc-opt"><input type="radio" name="kpc-' + k.id + '" value="' + lvl + '"' + (k.level === lvl ? ' checked' : '') + '>'
          + '<span><b>' + esc(T(L[lvl] || lvl)) + '</b><small>' + esc(T(HINT[lvl])) + '</small></span></label>';
      }).join('');
      return '<div class="kpc-kid" data-kpc-kid="' + k.id + '"><h3>' + esc(k.first_name) + '</h3>' + opts
        + '<label class="kpc-mk"><input type="checkbox" data-kpc-mk' + (k.marketing ? ' checked' : '') + (k.level === 'none' ? ' disabled' : '') + '>'
        + '<span>' + esc(T('The centre may also use photos of ' + k.first_name + ' in its marketing (website, social media, brochures).')) + '</span></label>'
        + (k.stricter_elsewhere ? '<div class="kpc-note">' + esc(T('Another parent chose a more careful option, so theirs applies. The most careful answer always wins.')) + '</div>' : '')
        + '</div>';
    }).join('');
  }

  /* Wire the form inside root. onChange(allAnswered). Returns a function that reads the answers. */
  function wire(root, onChange) {
    function sync() {
      var all = true;
      root.querySelectorAll('[data-kpc-kid]').forEach(function (box) {
        var picked = box.querySelector('input[type=radio]:checked');
        var mk = box.querySelector('[data-kpc-mk]');
        if (!picked) all = false;
        if (mk) { mk.disabled = !!picked && picked.value === 'none'; if (mk.disabled) mk.checked = false; }
      });
      if (onChange) onChange(all);
    }
    root.addEventListener('change', sync);
    sync();
    return function answers() {
      var out = [];
      root.querySelectorAll('[data-kpc-kid]').forEach(function (box) {
        var picked = box.querySelector('input[type=radio]:checked');
        if (!picked) return;
        var mk = box.querySelector('[data-kpc-mk]');
        out.push({ child_id: +box.getAttribute('data-kpc-kid'), level: picked.value, marketing: !!(mk && mk.checked) });
      });
      return out;
    };
  }

  /* ── 1. the ask ──────────────────────────────────────────────────────────────────────────── */

  // Anything that must come first, or anything urgent: the ask waits for it to go.
  function yieldNow() {
    var sel = '#kt-splash:not(.kt-hide), #kt-launch, #kt-pin-lock, #kt-bio-lock, #kt-bio-ov, #kt-agree, #kt-pw-gate, #kt-force-password, #kt-mfa-gate, '
      + '#kt-upd, #kt-appupdate, #kt-update-required, #kt-urgent, .kt-urgent, #kt-session-timeout, #kt-session-warning, #kt-notify-gate, '
      + '.kt-dialer-call, #kt-call-screen, #kt-dpop, #kt-onboarding-overlay';
    var els = document.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      if ((els[i].offsetWidth > 0 || els[i].offsetHeight > 0) && getComputedStyle(els[i]).display !== 'none' && getComputedStyle(els[i]).opacity !== '0') return true;
    }
    if (document.documentElement.classList.contains('kt-pw-locked')) return true;
    // The sign-up wizard and the "waiting for your centre" screen come first.
    return /^#(safe-arrival|headcounts?|emergency|onboarding|pending)/.test(location.hash || '');
  }

  var asking = false, lastLook = 0;
  function isParent() {
    var u = user();
    return [].concat(u.roles || [], u.primary_role || []).indexOf('guardian') >= 0;
  }

  function look(force) {
    if (asking || !token() || !api() || !isParent() || document.getElementById(ID)) return;
    if (!force && Date.now() - lastLook < 60000) return;
    if (yieldNow()) { setTimeout(function () { look(true); }, 5000); return; }
    lastLook = Date.now();
    asking = true;
    api().get(PATH).then(function (data) {
      asking = false;
      if (!data || data.viewing_as || !data.pending) return;
      if (yieldNow()) { setTimeout(function () { look(true); }, 5000); return; }
      show(data);
    }).catch(function () { asking = false; });
  }

  function show(data) {
    // Ask only for the children still unanswered; the rest are on the screen under Account.
    var todo = { labels: data.labels, children: (data.children || []).filter(function (k) { return !k.level && k.can_answer !== false; }) };
    if (!todo.children.length) return;
    var many = todo.children.length > 1;
    var g = document.createElement('div');
    g.id = ID;
    g.setAttribute('role', 'dialog'); g.setAttribute('aria-modal', 'true'); g.setAttribute('aria-labelledby', ID + '-t');
    g.setAttribute('data-kt-no-close', '1'); g.setAttribute('data-kt-centre-always', '1'); g.setAttribute('data-no-modal-guard', '');
    g.innerHTML = styles()
      + '<div class="kpc-card"><div class="kpc-head"><h2 id="' + ID + '-t">🤳 ' + esc(T('Photo permission')) + '</h2>'
      + '<div>' + esc(T(many ? 'One answer for each of your children' : 'One quick question before you carry on')) + '</div></div>'
      + '<div class="kpc-body"><p style="margin:0 0 10px;font-size:13px;color:#334155;">'
      + esc(T('Educators share photos and videos of the day. Choose what is right for your family. You can change it any time in My settings → Photos.')) + '</p>'
      + formHtml(todo) + '</div>'
      + '<div class="kpc-foot"><div class="kpc-msg" aria-live="polite"></div><button type="button" class="kpc-save" disabled>' + esc(T('Save')) + '</button></div></div>';
    document.body.appendChild(g);
    var save = g.querySelector('.kpc-save'), msg = g.querySelector('.kpc-msg');
    var read = wire(g.querySelector('.kpc-body'), function (all) {
      save.disabled = !all;
      msg.textContent = all ? '' : T(many ? 'Choose an option for each child.' : 'Choose an option.');
    });
    g.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); } }, true);
    save.onclick = function () {
      save.disabled = true; msg.style.color = '#64748B'; msg.textContent = T('Saving…');
      api().post(PATH, { answers: read() }).then(function (res) {
        g.remove();
        toast('🤳', T('Thank you'), T('Your photo permission is saved.'), '#1E8E60');
        // A parent with more children still to answer (a new one added meanwhile) is asked again.
        if (res && res.pending) setTimeout(function () { look(true); }, 1500);
      }).catch(function (e) {
        save.disabled = false; msg.style.color = '#BE4038'; msg.textContent = (e && e.message) || T('That did not save. Try again.');
      });
    };
    setTimeout(function () { var f = g.querySelector('input'); if (f) try { f.focus(); } catch (e) {} }, 50);
  }

  (function boot() {
    var n = 0;
    (function wait() {
      if (token() && document.getElementById('appMain') && api()) { setTimeout(function () { look(true); }, 3000); return; }
      if (++n < 120) setTimeout(wait, 1000);
    })();
    window.addEventListener('hashchange', function () { setTimeout(function () { look(false); }, 1200); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) look(false); });
  })();

  /* ── 2. the parent's screen ─────────────────────────────────────────────────────────────── */

  /* Draw the parent's answers into a container: My settings → Photos. */
  function renderInto(main) {
    var head = '<div style="margin:0 0 12px;"><div style="font-size:15px;font-weight:700;color:#0F2540;">🤳 ' + esc(T('Photo permissions')) + '</div>'
      + '<div style="font-size:13px;color:#64748B;margin-top:2px;">'
      + esc(T('What educators may do with photos and videos of each of your children. If two parents choose differently, the more careful answer applies.')) + '</div></div>';
    main.innerHTML = '<div class="kt-section">' + head + '<p style="color:#64748B;font-size:13px;">' + esc(T('Loading…')) + '</p></div>';
    api().get(PATH).then(function (data) {
      if (!data.children || !data.children.length) {
        main.innerHTML = '<div class="kt-section">' + head + '<p style="color:#64748B;font-size:13px;">' + esc(T('No children are linked to your account yet.')) + '</p></div>';
        return;
      }
      main.innerHTML = '<div class="kt-section">' + head + styles()
        + '<div data-kpc-form style="max-width:640px;">' + formHtml(data) + '</div>'
        + '<div style="display:flex;gap:10px;align-items:center;max-width:640px;" data-kt-nofb><div class="kpc-msg" aria-live="polite"></div>'
        + (canAnswer(data) ? '<button type="button" class="kpc-save"' + (data.viewing_as ? ' disabled title="' + esc(T('Only the parent can change this.')) + '"' : '') + '>' + esc(T('Save')) + '</button>' : '') + '</div></div>';
      var save = main.querySelector('.kpc-save'), msg = main.querySelector('.kpc-msg');
      if (!save) return;   // not the primary parent for any child: nothing to save
      var read = wire(main.querySelector('[data-kpc-form]'), function (all) {
        if (data.viewing_as) return;
        save.disabled = !all; msg.textContent = all ? '' : T('Choose an option for each child.');
      });
      save.onclick = function () {
        save.disabled = true; msg.style.color = '#64748B'; msg.textContent = T('Saving…');
        api().post(PATH, { answers: read() }).then(function () {
          toast('🤳', T('Saved'), T('Your photo permissions are updated.'), '#1E8E60');
          renderInto(main);
        }).catch(function (e) {
          save.disabled = false; msg.style.color = '#BE4038'; msg.textContent = (e && e.message) || T('That did not save. Try again.');
        });
      };
    }).catch(function (e) {
      main.innerHTML = '<div class="kt-section">' + head + '<p style="color:#BE4038;font-size:13px;">' + esc((e && e.message) || T('Could not load your photo permissions.')) + '</p></div>';
    });
  }

  window.KT = window.KT || {};
  KT.photoConsent = { ask: function () { look(true); }, formHtml: formHtml, wire: wire, styles: styles, renderInto: renderInto, canAnswer: canAnswer, HINT: HINT };
  // The old address (and any link to it) opens the tab in My settings.
  if (KT.Shell && KT.Shell.registerScreen) KT.Shell.registerScreen('guardian:photo-permissions', function () { location.replace('#settings/photos'); });
})();
