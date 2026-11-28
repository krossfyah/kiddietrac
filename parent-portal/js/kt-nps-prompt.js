/* NPS prompt in the portal (2026-11-28). When the signed-in person has an open NPS invite (the survey also went by
   email + bell, App\Support\NpsSurvey), a small card asks the one question once per session: 0-10, optional comment,
   Send (POST /engagement/nps {invite_id}) or Not now. Nothing shows for anyone without an open invite. */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});
  var shown = false;
  function ss(k, v) { try { if (v === undefined) return w.sessionStorage.getItem(k); w.sessionStorage.setItem(k, v); } catch (e) { return null; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function check() {
    if (shown || !KT.Api || !KT.Api.get) return;
    var tok = ss('kt_token'); try { tok = tok || w.localStorage.getItem('kt_token'); } catch (e) {}
    if (!tok || ss('kt_nps_later') === '1') return;
    shown = true;
    KT.Api.get('/engagement/nps/pending', { quiet: true }).then(function (r) {
      if (r && r.pending) draw(r.pending);
    }).catch(function () {});
  }
  function draw(p) {
    var t = KT.t || function (s) { return s; };
    var q = p.audience === 'staff' ? 'How likely are you to recommend ' + p.agency + ' as a place to work?' : 'How likely are you to recommend ' + p.agency + ' to a friend or family member?';
    var box = document.createElement('div');
    box.id = 'kt-nps-prompt';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', 'Quick survey');
    box.style.cssText = 'position:fixed;right:16px;bottom:88px;z-index:9000;width:min(380px,calc(100vw - 32px));background:#fff;color:#0F172A;border:1px solid #E2E8F0;border-radius:16px;box-shadow:0 18px 40px -18px rgba(15,23,42,.45);padding:14px 14px 12px;font-family:inherit;';
    var nums = '';
    for (var i = 0; i <= 10; i++) nums += '<button type="button" data-n="' + i + '" style="height:32px;border-radius:8px;border:1px solid #E2E8F0;background:#F8FAFC;font-weight:800;font-size:13px;cursor:pointer;padding:0;color:#0F172A;">' + i + '</button>';
    box.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#64748B;">' + esc(t('QUICK SURVEY')) + '</div>'
      + '<button type="button" data-x aria-label="Close" style="border:0;background:none;font-size:16px;cursor:pointer;color:#64748B;line-height:1;">✕</button></div>'
      + '<div style="font-size:14.5px;font-weight:700;margin:6px 0 10px;line-height:1.4;">' + esc(q) + '</div>'
      + '<div style="display:grid;grid-template-columns:repeat(11,1fr);gap:4px;">' + nums + '</div>'
      + '<div style="display:flex;justify-content:space-between;font-size:11px;color:#64748B;margin:4px 0 8px;"><span>' + esc(t('Not at all likely')) + '</span><span>' + esc(t('Extremely likely')) + '</span></div>'
      + '<textarea data-c placeholder="' + esc(t('Anything you would like to tell us? (optional)')) + '" maxlength="2000" style="display:none;width:100%;box-sizing:border-box;min-height:64px;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;"></textarea>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;"><button type="button" data-later class="kt-btn">' + esc(t('Not now')) + '</button>'
      + '<button type="button" data-send class="kt-btn kt-btn-primary" disabled>' + esc(t('Send')) + '</button></div>'
      + '<div data-m style="font-size:12.5px;color:#B91C1C;margin-top:6px;"></div>';
    document.body.appendChild(box);
    var cur = null;
    function close() { ss('kt_nps_later', '1'); if (box.parentNode) box.parentNode.removeChild(box); }
    box.querySelector('[data-x]').onclick = close;
    box.querySelector('[data-later]').onclick = close;
    box.querySelectorAll('[data-n]').forEach(function (b) {
      b.onclick = function () {
        cur = +b.getAttribute('data-n');
        box.querySelectorAll('[data-n]').forEach(function (x) { var on = x === b; x.style.background = on ? '#1F6080' : '#F8FAFC'; x.style.color = on ? '#fff' : '#0F172A'; });
        box.querySelector('[data-c]').style.display = 'block';
        box.querySelector('[data-send]').disabled = false;
      };
    });
    box.querySelector('[data-send]').onclick = function () {
      var btn = this; if (cur === null) return;
      btn.disabled = true;
      KT.Api.post('/engagement/nps', { invite_id: p.invite_id, score: cur, comment: box.querySelector('[data-c]').value }).then(function () {
        box.innerHTML = '<div style="text-align:center;padding:10px 4px;font-size:14px;"><div style="font-size:30px;">💛</div><b>' + esc(t('Thank you!')) + '</b><div style="color:#475569;margin-top:4px;">' + esc(t('Your answer was sent to the team.')) + '</div></div>';
        ss('kt_nps_later', '1');
        setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 3500);
      }).catch(function (e) { btn.disabled = false; box.querySelector('[data-m]').textContent = (e && e.message) || t('That did not send. Please try again.'); });
    };
  }
  // After the portal has settled (sign-in, first screen); never on the public pages.
  if (/dashboard\.html/.test(w.location.pathname) || w.location.pathname === '/') w.setTimeout(check, 9000);
})(window);
