/* ═══════════════════════════════════════════════════════════════════════
   New announcements, in front of the person when they sign in (2026-11-09)

   Anthony: "when announcements are sent out it should popup when logging into the portal or
   app and have button that show I have read (or word this better) - full sweep".

   On sign-in (every role, portal and app: this file is in dashboard.html, which the apps load)
   the unread announcements that were sent TO this person come up one at a time, newest first,
   from GET /announcements/popup (App\Support\AnnouncementInbox, the same unread the
   Announcements screen and the top-bar 📣 count). Two answers:
     ✓ I've read this   marks it read through the screen's own state endpoint, so the screen,
                        the 📣 and the bell all clear together, then shows the next one;
     Later              puts it away for this sign-in; it comes back next time until it is read.
   One that arrives while the person is signed in comes up when the 📣 count goes up.

   Never on top of another popup or a sign-in gate (it waits), never while impersonating
   someone, and only once per sign-in for anything put off with Later.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var later = {};       // keys put off this sign-in
  var busy = false, lastCount = null, tries = 0;

  function ss(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function scope() {
    var u = {}; try { u = JSON.parse(ss('kt_user') || '{}'); } catch (e) {}
    return [u.id || '', ss('kt_active_agency_id') || '', ss('kt_view_as') || ''].join('|');
  }
  function loadLater() { try { later = JSON.parse(ss('kt_ann_later_' + scope()) || '{}') || {}; } catch (e) { later = {}; } }
  function saveLater() { try { sessionStorage.setItem('kt_ann_later_' + scope(), JSON.stringify(later)); } catch (e) {} }

  // The words of an announcement: formatting kept, anything active dropped.
  var KEEP = /^(P|BR|B|STRONG|I|EM|U|UL|OL|LI|A|H1|H2|H3|H4|BLOCKQUOTE|SPAN|DIV|HR|SMALL|SUB|SUP|S)$/;
  function clean(html) {
    var box = document.createElement('div');
    box.innerHTML = String(html || '');
    (function walk(n) {
      Array.prototype.slice.call(n.children).forEach(function (el) {
        if (!KEEP.test(el.tagName)) { el.replaceWith(document.createTextNode(el.textContent || '')); return; }
        Array.prototype.slice.call(el.attributes).forEach(function (at) {
          if (!(el.tagName === 'A' && at.name === 'href')) el.removeAttribute(at.name);
        });
        if (el.tagName === 'A') {
          if (!/^(https?:|mailto:|tel:|#)/i.test(el.getAttribute('href') || '')) el.removeAttribute('href');
          el.setAttribute('target', '_blank'); el.setAttribute('rel', 'noopener noreferrer');
        }
        walk(el);
      });
    })(box);
    return box.innerHTML;
  }
  function when(s) {
    if (!s) return '';
    try {
      var t = String(s).replace(' ', 'T'); if (!/[zZ]|[+-]\d\d:?\d\d$/.test(t)) t += 'Z';
      var tz = (KT.agencyTz && KT.agencyTz()) || undefined;
      return new Date(t).toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) { return ''; }
  }

  // Something else is on screen that must be dealt with first.
  function blocked() {
    if (ss('kt_impersonating')) return true;
    // Still starting up: the loading splash is up, or the first screen has not drawn.
    var sp = document.getElementById('kt-splash');
    if (sp && sp.getClientRects().length && getComputedStyle(sp).opacity !== '0' && getComputedStyle(sp).visibility !== 'hidden') return true;
    if (document.querySelector('.ktpf-note')) return true;
    var main = document.getElementById('appMain');
    if (!main || !main.children.length) return true;
    var root = document.getElementById('modalRoot');
    if (root && root.firstChild) return true;
    // Sign-in gates (password change, MFA, PIN / Face ID lock, clock-in), the idle warning, an emergency, any dialog.
    var gates = document.querySelectorAll('.kt-cf, [aria-labelledby="kfp-title"], #kt-mfa-gate, #kt-bio-lock, #kt-pin-lock, #kt-clockgate, .kt-idle-card, #kt-urgent, [data-kt-gate], [role="dialog"], [aria-modal="true"]');
    for (var i = 0; i < gates.length; i++) { if (gates[i].getClientRects().length) return true; }
    return false;
  }

  function show(items, i) {
    var a = items[i];
    if (!a) { busy = false; return; }
    var M = KT.Shell && KT.Shell.Modal;
    if (!M) { busy = false; return; }
    var body = document.createElement('div');
    body.innerHTML =
      '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12.5px;color:#64748B;margin:0 0 10px;">'
      + '<span>' + esc([a.sender ? 'From ' + a.sender : '', when(a.sent_at)].filter(Boolean).join(' · ')) + '</span>'
      + (items.length > 1 ? '<span style="font-weight:700;">' + (i + 1) + ' of ' + items.length + '</span>' : '') + '</div>'
      + '<div style="font-size:18px;font-weight:800;color:#0F172A;line-height:1.3;margin:0 0 10px;">' + esc(a.title || 'Announcement') + '</div>'
      + '<div class="kt-ann-body" style="font-size:14.5px;color:#334155;line-height:1.6;overflow-wrap:anywhere;">' + clean(a.body) + '</div>'
      + (a.pdf_url ? '<div style="margin-top:12px;"><button type="button" class="kt-btn kt-btn-ghost" data-ann-pdf style="height:32px;">📎 ' + esc(a.pdf_name || 'Open the attachment') + '</button></div>' : '');
    var pdf = body.querySelector('[data-ann-pdf]');
    if (pdf) pdf.addEventListener('click', function () {
      if (KT.openFile) KT.openFile(a.pdf_url, { title: a.pdf_name || a.title, mime: 'application/pdf' });
      else if (KT.viewDocument) KT.viewDocument(a.pdf_url, { title: a.pdf_name || a.title, mime: 'application/pdf' });
    });
    var next = function () { setTimeout(function () { show(items, i + 1); }, 60); };
    M.open({
      title: '📢 New announcement',
      body: body,
      onClose: function () { if (!a.__done) { later[a.key] = 1; saveLater(); } if (!a.__next) busy = false; },
      actions: [
        { label: 'Later', handler: function () { a.__done = true; later[a.key] = 1; saveLater(); a.__next = true; next(); } },
        { label: "✓ I've read this", primary: true, busyLabel: 'Saving…', handler: function () {
          var path = (a.audience === 'parent' ? '/parent' : '/provider') + '/announcements/state';
          return KT.Api.post(path, { keys: [a.key], action: 'read' }).then(function () {
            a.__done = true; a.__next = true; next();
            try { KT.badges && KT.badges.refresh && KT.badges.refresh(); } catch (e) {}
            try { window.dispatchEvent(new CustomEvent('kt:announcement-read', { detail: { key: a.key } })); } catch (e) {}
          }).catch(function (e) {
            try { KT.toast && KT.toast('⚠️', 'Could not save', (e && e.message) || 'Please try again.', '#DC2626'); } catch (x) {}
            return false;
          });
        } },
      ],
    });
  }

  function check() {
    if (busy || !KT.Api || !ss('kt_token')) return;
    if (blocked()) { if (tries++ < 40) setTimeout(check, 3000); return; }
    busy = true;
    loadLater();
    KT.Api.get('/announcements/popup').then(function (d) {
      var items = ((d && d.items) || []).filter(function (a) { return a && a.key && !later[a.key]; });
      if (!items.length || blocked()) { busy = false; return; }
      show(items, 0);
    }).catch(function () { busy = false; });
  }

  // Sign-in: once the shell has drawn its first screen.
  function boot() {
    if (!ss('kt_token')) return;
    setTimeout(check, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // While signed in: the 📣 count went up, so something new arrived.
  window.addEventListener('kt:badges', function (e) {
    var n = +((e.detail && e.detail.announcements) || 0);
    if (lastCount !== null && n > lastCount) { tries = 0; check(); }
    lastCount = n;
  });

  KT.annPopup = { check: check };
})(window);
