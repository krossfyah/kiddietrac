/* ───────────────────────────────────────────────────────────────────
   KIDDIETRAC — reusable password strength + suggest component.
   Auto-attaches to the first new-password field in each form (any
   input[type=password][autocomplete=new-password]) and injects a
   "Suggest a strong password" link, a strength bar, and a requirement
   checklist. A second new-password field in the same form is treated as
   the confirm field (filled by Suggest). Self-contained — safe to drop
   on any page with a <script> tag, no markup changes needed.
   ─────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  function checks(v) {
    return { len: v.length >= 8, upper: /[A-Z]/.test(v), num: /[0-9]/.test(v), spec: /[^A-Za-z0-9]/.test(v) };
  }
  function strength(v) {
    if (!v) return { pct: 0, label: '', color: '#E5E7EB' };
    var c = checks(v), score = 0;
    if (c.len) score++; if (c.upper) score++; if (c.num) score++; if (c.spec) score++;
    if (v.length >= 12) score++;
    var map = [
      { pct: 18, label: 'Too weak', color: '#DC2626' },
      { pct: 38, label: 'Weak', color: '#F59E0B' },
      { pct: 60, label: 'Fair', color: '#F59E0B' },
      { pct: 80, label: 'Good', color: '#3BBBBE' },
      { pct: 100, label: 'Strong 💪', color: '#16A34A' }
    ];
    return map[Math.min(score, 5) - 1] || map[0];
  }
  function generate() {
    var U = 'ABCDEFGHJKLMNPQRSTUVWXYZ', L = 'abcdefghijkmnpqrstuvwxyz', N = '23456789', S = '!@#$%^&*?-+';
    var all = U + L + N + S, out = [
      U[(Math.random() * U.length) | 0], L[(Math.random() * L.length) | 0],
      N[(Math.random() * N.length) | 0], S[(Math.random() * S.length) | 0]
    ];
    for (var i = 0; i < 8; i++) out.push(all[(Math.random() * all.length) | 0]);
    for (var j = out.length - 1; j > 0; j--) { var k = (Math.random() * (j + 1)) | 0; var t = out[j]; out[j] = out[k]; out[k] = t; }
    return out.join('');
  }

  function attach(primary, confirm) {
    if (primary.dataset.ktStrengthAttached) return;
    primary.dataset.ktStrengthAttached = '1';

    var box = document.createElement('div');
    box.style.cssText = 'margin:-6px 0 12px;font-family:inherit;';
    box.innerHTML =
      '<button type="button" class="ktps-suggest" style="background:none;border:none;color:#7C3AED;font-weight:700;font-size:12px;cursor:pointer;padding:2px 0;">✨ Suggest a strong password</button>' +
      '<div class="ktps-meter" style="height:6px;border-radius:4px;background:#EEF0F4;overflow:hidden;margin:6px 0 4px;"><div style="height:100%;width:0;border-radius:4px;transition:width .25s,background .25s;"></div></div>' +
      '<div class="ktps-label" style="font-size:11px;font-weight:700;min-height:14px;"></div>' +
      '<ul class="ktps-reqs" style="list-style:none;font-size:11px;color:#64748B;margin:4px 0 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;">' +
        '<li data-r="len">8+ characters</li><li data-r="upper">Uppercase letter</li>' +
        '<li data-r="num">A number</li><li data-r="spec">Special character</li>' +
      '</ul>';
    if (primary.parentNode) primary.parentNode.insertBefore(box, primary.nextSibling);

    var bar = box.querySelector('.ktps-meter > div');
    var label = box.querySelector('.ktps-label');
    function refresh() {
      var v = primary.value, c = checks(v), s = strength(v);
      bar.style.width = s.pct + '%'; bar.style.background = s.color;
      label.textContent = s.label; label.style.color = s.color;
      ['len', 'upper', 'num', 'spec'].forEach(function (k) {
        var li = box.querySelector('[data-r="' + k + '"]');
        li.style.color = c[k] ? '#16A34A' : '#94A3B8';
        li.textContent = (c[k] ? '✓ ' : '○ ') + li.textContent.replace(/^[✓○]\s/, '');
      });
    }
    primary.addEventListener('input', refresh);
    box.querySelector('.ktps-suggest').addEventListener('click', function () {
      var p = generate();
      primary.value = p; if (confirm) confirm.value = p;
      try { primary.type = 'text'; if (confirm) confirm.type = 'text'; } catch (e) {}
      refresh();
    });
  }

  function init() {
    var forms = document.querySelectorAll('form');
    var scopes = forms.length ? forms : [document];
    scopes.forEach(function (scope) {
      var fields = scope.querySelectorAll('input[type="password"][autocomplete="new-password"], input[type="password"][data-kt-strength]');
      if (!fields.length) return;
      attach(fields[0], fields[1] || null);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
