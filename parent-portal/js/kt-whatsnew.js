/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — "What's new" changelog (2026-07-08).
   A top-bar 🎁 button (admins + centre directors only) opening a popup that
   lists recently-added features (last 3 months — older ones auto-archive) and
   what's coming soon, each with the date introduced. Content lives in
   /whats-new.json. An unseen-entry badge shows until the user opens it.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktWhatsNew) return; window.__ktWhatsNew = true;
  var URL = '/whats-new.json';
  var ADMIN = ['platform_admin', 'agency_admin', 'centre_director'];
  var DATA = null;

  function user() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function isAdmin() { var r = myRoles(); return ADMIN.some(function (x) { return r.indexOf(x) !== -1; }); }

  function myRoles() {
    var u = user();
    var r = (u.roles && u.roles.length) ? u.roles.slice() : [];
    // role_key is what the portal actually gates on elsewhere; a kt_user rebuilt by
    // hand often has it and no roles array at all.
    if (u.role_key && r.indexOf(u.role_key) === -1) r.push(u.role_key);
    return r;
  }

  /**
   * The entries THIS person should be told about.
   *
   * An entry may name the roles it matters to (`roles: ["educator", ...]`). One that
   * does not is admin news, which is what every entry written before this was — so the
   * default keeps the existing 45 exactly where they were rather than suddenly showing
   * an educator three months of billing changes.
   *
   * This is why the panel is no longer admin-only: an educator now has features of
   * their own to hear about (Anthony, 2026-08-31: "add them to the new feature list for
   * all roles that are relevant to the new features"), and a release note nobody in that
   * role can open is a release note that was not written.
   */
  function forMe(entries) {
    var mine = myRoles();
    var admin = isAdmin();
    return (entries || []).filter(function (e) {
      if (! e.roles || ! e.roles.length) return admin;
      for (var i = 0; i < e.roles.length; i++) {
        if (mine.indexOf(e.roles[i]) !== -1) return true;
      }
      return false;
    });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function within3mo(d) { if (!d) return false; var t = new Date(d + 'T00:00:00'); if (isNaN(t)) return false; return (Date.now() - t.getTime()) <= 92 * 86400000; }
  // Date-only: see KT.dayLabel.
  function fmtDate(s) { return (window.KT && KT.dayLabel) ? KT.dayLabel(s) : s; }
  function newest(d) { var ns = forMe(d.entries).filter(function (e) { return e.type === 'new' && e.date; }).map(function (e) { return e.date; }).sort(); return ns.length ? ns[ns.length - 1] : ''; }
  function seenDate() { try { return localStorage.getItem('kt_whatsnew_seen') || ''; } catch (e) { return ''; } }
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  /* Mark it read — and never with an empty string.

     This used to store newest(d) directly, and newest() returns '' whenever
     forMe() matches nothing, which happens whenever myRoles() finds no kt_user in
     storage: during boot, across a role switch, or for a moment after a hard
     reload. Storing '' means every later tick compares a real date against '' and
     shows the dot again — the badge coming back after it had been read, however
     many times it was read.

     So: fall back to today, and never move the marker backwards. */
  function markSeen(d) {
    try {
      var n = d ? newest(d) : '';
      var cur = seenDate();
      var v = n || cur || todayISO();
      if (cur && v < cur) { v = cur; }
      localStorage.setItem('kt_whatsnew_seen', v);
    } catch (e) { /* private mode — the dot is not worth an exception */ }
  }

  function load(cb) {
    if (DATA) { cb(DATA); return; }
    fetch(URL + '?t=' + Date.now()).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { DATA = d; cb(d); }).catch(function () { cb(null); });
  }
  function badge(show) { var b = document.getElementById('kt-tb-wn-badge'); if (b) b.hidden = !show; }

  function openWhatsNew() {
    /* Cleared the moment it is opened. It used to clear only after the JSON came
       back, so a failed or slow fetch left the dot sitting there on a panel the
       user had just read. */
    badge(false);
    load(function (d) {
      if (!d) { markSeen(null); return; }
      var news = forMe(d.entries).filter(function (e) { return e.type === 'new' && within3mo(e.date); }).sort(function (a, b) { return a.date < b.date ? 1 : -1; });
      var up = forMe(d.entries).filter(function (e) { return e.type === 'upcoming'; });
      var entry = function (e) {
        return '<div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #F1F5F9;">' +
          '<span style="font-size:22px;flex-shrink:0;line-height:1.2;">' + (e.icon || '✨') + '</span>' +
          '<div style="min-width:0;"><div style="font-weight:700;font-size:14px;color:#0D1B2A;">' + esc(e.title) +
          (e.date ? '<span style="font-weight:600;font-size:11px;color:#64748B;margin-left:8px;">' + fmtDate(e.date) + '</span>' : '') + '</div>' +
          '<div style="font-size:12.5px;color:#64748B;margin-top:2px;">' + esc(e.body || '') + '</div></div></div>';
      };
      var overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:12001;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;';
      var modal = document.createElement('div');
      modal.style.cssText = 'background:#fff;border-radius:16px;max-width:540px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 16px 40px rgba(0,0,0,.28);';
      modal.innerHTML =
        '<div style="padding:16px 22px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#0E7C90,#1F6FB2);color:#fff;position:sticky;top:0;">' +
          '<h2 style="margin:0;font-size:18px;">🎁 What’s new</h2>' +
          '<button id="kt-wn-x" aria-label="Close" style="background:transparent;border:none;font-size:22px;color:rgba(255,255,255,.92);cursor:pointer;line-height:1;">×</button></div>' +
        '<div style="padding:14px 22px 20px;">' +
          (news.length
            ? '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#0E7C90;margin:2px 0 2px;">✨ Recently added</div>' + news.map(entry).join('')
            : '<div style="color:#64748B;font-size:13px;padding:10px 0;">No new features in the last 3 months.</div>') +
          (up.length ? '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#B45309;margin:20px 0 2px;">🔜 Coming soon</div>' + up.map(entry).join('') : '') +
          '<div style="font-size:11px;color:#B6C2CE;margin-top:16px;text-align:center;">New features stay listed here for 3 months.</div>' +
        '</div>';
      overlay.appendChild(modal); document.body.appendChild(overlay);
      var close = function () { overlay.remove(); };
      modal.querySelector('#kt-wn-x').onclick = close;
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
      markSeen(d);
      badge(false);
    });
  }

  window.KT = window.KT || {};
  window.KT.openWhatsNew = openWhatsNew;
  window.KT.whatsNewIsAdmin = isAdmin;

  /* Anyone with something addressed to them, not just admins — and the badge clears
     itself when there is nothing left to say, which the old one never did. */
  function tick() {
    load(function (d) {
      if (! d) return;
      var n = newest(d);
      badge(!!n && n > seenDate());
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(tick) : setInterval(tick, 2500);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
})();
