/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — "we closed your shift for you" notice.

   Shown once, on the next sign-in after an automatic sign-off. The point is not to
   tell somebody off: the closing time is the agency's cut-off, not the minute they
   actually left, and it is the figure their hours are counted from. They can only
   correct it while they still remember the day.

   Fires after the app has a token and stands down entirely when there is nothing to
   say — a modal that appears on every login for no reason is one people learn to
   dismiss without reading, including on the day it matters.
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.clockoutReminderLoaded) return;
  KT.clockoutReminderLoaded = true;

  function apiBase() { return (w.KT && w.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function token() {
    try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; }
  }
  function esc(v) {
    return v == null ? '' : String(v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function show(data) {
    var shifts = data.shifts || [];
    var many = shifts.length > 1;

    var ov = d.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:13000;display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:430px;width:100%;padding:22px;box-shadow:0 20px 52px rgba(0,0,0,.3);">'
      + '<div style="font-size:34px;line-height:1;margin-bottom:8px;">🕰️</div>'
      + '<div style="font-size:18px;font-weight:800;color:#0D1B2A;margin:0 0 6px;">'
      +   (many ? 'A few of your shifts were closed for you' : 'We closed your shift for you') + '</div>'
      + '<div style="font-size:14px;line-height:1.6;color:#334155;margin:0 0 12px;">'
      +   'No harm done — but the time below is our end-of-day cut-off, not when you actually left. '
      +   'Your hours are counted from it, so it is worth a quick look while the day is still fresh.</div>'
      + '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:11px;padding:12px 14px;margin:0 0 14px;">'
      +   shifts.map(function (s) {
            return '<div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px;padding:3px 0;">'
              + '<span style="font-weight:700;color:#0F172A;">' + esc(s.date) + '</span>'
              + '<span style="color:#475569;">in ' + esc(s.in) + (s.closed_at ? ' · closed ' + esc(s.closed_at) : '') + '</span></div>';
          }).join('')
      + '</div>'
      + '<div style="font-size:13px;line-height:1.55;color:#475569;margin:0 0 16px;">'
      +   'Clocking out at the end of your shift keeps your hours right and keeps us on the correct side of our ratio records. '
      +   'If any of these look wrong, your director can put them straight.</div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
      +   '<button id="kt-co-tell" style="background:#fff;color:#1F6080;border:1px solid #CFE3EB;padding:10px 16px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;">Tell my director</button>'
      +   '<button id="kt-co-ok" style="background:#1F6080;color:#fff;border:0;padding:10px 18px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;">Got it</button>'
      + '</div></div>';
    d.body.appendChild(ov);

    function ack(then) {
      // Acknowledged up to this punch, so it is a reminder and not a nag.
      try {
        fetch(apiBase() + '/auth/me/auto-signoff-notice/ack', {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + token(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ last_id: data.last_id }),
        }).catch(function () {}).then(function () { if (then) then(); });
      } catch (e) { if (then) then(); }
      ov.remove();
    }

    ov.querySelector('#kt-co-ok').addEventListener('click', function () { ack(); });
    ov.querySelector('#kt-co-tell').addEventListener('click', function () {
      // Dismiss and drop them into Messages, where they can say what actually happened.
      ack(function () { try { w.location.hash = '#chat'; } catch (e) {} });
    });
  }

  function check(tries) {
    var tok = token();
    if (!tok) {
      // Runs at script load, before sign-in on a cold start. Wait, then stop trying.
      if ((tries || 0) < 20) { setTimeout(function () { check((tries || 0) + 1); }, 500); }
      return;
    }
    fetch(apiBase() + '/auth/me/auto-signoff-notice', {
      headers: { Authorization: 'Bearer ' + tok, Accept: 'application/json' },
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (j && j.pending > 0 && (j.shifts || []).length) { show(j); }
    }).catch(function () {});
  }

  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', function () { check(0); }); }
  else { check(0); }
})(window, document);
