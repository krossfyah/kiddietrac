/* Reference ID pill — "ILH-C-0042" with a copy button (2026-10-14).

   Anthony: "does kiddietrac have a unique id assigned to each user/child/staff? can this be
   made visible on documents, records, portal etc?" Every child, family, staff member and
   parent has one (App\Support\RefCode). This is the one way a screen shows it: small,
   monospace, and one tap copies it — so it can be read over the phone or pasted into an
   email, and typed into the search bar to jump straight back to the record.

     KT.refPill(code [, {label}])      -> element (null when there is no code)
     KT.refPillHtml(code [, {label}])  -> HTML string ('' when there is no code)
*/
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.refPill) return;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  (function css() {
    if (document.getElementById('kt-refid-css')) return;
    var st = document.createElement('style');
    st.id = 'kt-refid-css';
    st.textContent =
      '.kt-ref{display:inline-flex;align-items:center;gap:4px;height:22px;padding:0 4px 0 8px;border-radius:6px;'
      + 'background:#F1F5F9;border:1px solid #E2E8F0;color:#334155;font:600 11.5px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
      + 'letter-spacing:.2px;white-space:nowrap;vertical-align:middle;}'
      + '.kt-ref .kt-ref-l{font-family:inherit;color:#94A3B8;font-weight:600;margin-right:1px;}'
      + '.kt-ref button{all:unset;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:4px;font-size:11px;color:#64748B;}'
      + '.kt-ref button:hover{background:#E2E8F0;color:#0F172A;}'
      + '.kt-ref.is-copied{background:#ECFDF5;border-color:#A7F3D0;color:#047857;}'
      + '@media (prefers-color-scheme: dark){html.kt-dark .kt-ref{background:#1E293B;border-color:#334155;color:#E2E8F0;}}';
    (document.head || document.documentElement).appendChild(st);
  })();

  function html(code, opts) {
    if (!code) return '';
    opts = opts || {};
    return '<span class="kt-ref" data-kt-ref="' + esc(code) + '" title="Reference ID — tap the copy button to copy">'
      + (opts.label ? '<span class="kt-ref-l">' + esc(opts.label) + '</span>' : '')
      + '<span class="kt-ref-c">' + esc(code) + '</span>'
      + '<button type="button" data-kt-ref-copy aria-label="Copy ID ' + esc(code) + '">⧉</button></span>';
  }

  function el(code, opts) {
    if (!code) return null;
    var t = document.createElement('span');
    t.innerHTML = html(code, opts);
    return t.firstChild;
  }

  function copy(text) {
    if (w.navigator && navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
    return new Promise(function (res, rej) {
      try {
        var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); res();
      } catch (e) { rej(e); }
    });
  }

  // One listener for every pill, wherever it is drawn (lists re-render often).
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-kt-ref-copy]') : null;
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    var pill = b.closest('.kt-ref');
    var code = pill && pill.getAttribute('data-kt-ref');
    if (!code) return;
    copy(code).then(function () {
      pill.classList.add('is-copied'); b.textContent = '✓';
      setTimeout(function () { pill.classList.remove('is-copied'); b.textContent = '⧉'; }, 1400);
      try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast('Copied ' + code, 'success'); } catch (x) {}
    }).catch(function () {});
  }, true);

  KT.refPill = el;
  KT.refPillHtml = html;
})(window);
