/* KT CALCULATOR (2026-10-13, Anthony: "add calculator function to the portal as a popup on
   the screen using the top bar with a shortcut").

   A small floating calculator, opened from the 🧮 button in the top bar (kt-topbar.js) or
   from anywhere with Alt+C (⌥C on a Mac). Esc or the × closes it; Alt+C toggles it.

   - Sits ABOVE the page and any open dialog, and does not take focus away from a form
     until you click into it, so you can work out an amount while a dialog is open.
   - "Insert" puts the result into the field you were last in (amount boxes in billing,
     payroll, refunds…) and fires input/change so the screen picks it up.
   - The tape keeps the last 12 sums for this tab (sessionStorage); click a line to reuse it.
   - Maths is parsed here (+ − × ÷, brackets, %); it never runs eval/Function.
     % works like a desk calculator: 200 × 15% = 30, 200 + 15% = 230, 200 − 15% = 170.
   - Draggable by its header on a desktop; the spot is remembered (localStorage, a viewer
     convenience only). On a phone it docks to the bottom of the screen.

   Public: KT.openCalculator(), KT.closeCalculator(), KT.toggleCalculator(). */
(function () {
  'use strict';
  if (window.__ktCalcLoaded) return;
  window.__ktCalcLoaded = true;
  var KT = window.KT = window.KT || {};

  var TAPE_KEY = 'kt_calc_tape', POS_KEY = 'kt_calc_pos';
  var box = null, exprEl = null, outEl = null, tapeEl = null, insBtn = null;
  var expr = '', justEvaluated = false, lastField = null;

  function ssGet(k) { try { return JSON.parse(sessionStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

  /* ── The parser: expr = term (± term)* ; term = factor (×÷ factor)* ;
        factor = unary %? ; unary = −unary | number | ( expr ) ─────────────────────── */
  function evaluate(src) {
    var s = String(src).replace(/\s+/g, '').replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/,/g, '');
    var i = 0;
    function fail() { throw new Error('bad'); }
    function peek() { return s.charAt(i); }
    function number() {
      var m = /^(\d+\.?\d*|\.\d+)(e[+-]?\d+)?/i.exec(s.slice(i));
      if (!m) fail();
      i += m[0].length;
      return parseFloat(m[0]);
    }
    function unary() {
      if (peek() === '-') { i++; return -unary(); }
      if (peek() === '+') { i++; return unary(); }
      if (peek() === '(') {
        i++; var v = sum();
        if (peek() === ')') i++; // a missing close bracket at the end is forgiven
        return v;
      }
      return number();
    }
    function factor() {
      var v = unary(), pct = false;
      while (peek() === '%') { i++; v = v / 100; pct = true; }
      return { v: v, pct: pct };
    }
    function term() {
      var f = factor(), v = f.v, pct = f.pct, n = 0;
      while (peek() === '*' || peek() === '/') {
        var op = s.charAt(i++), r = factor().v; n++;
        v = op === '*' ? v * r : v / r;
      }
      return { v: v, pct: pct && n === 0 };
    }
    function sum() {
      var v = term().v;
      while (peek() === '+' || peek() === '-') {
        var op = s.charAt(i++), t = term();
        var r = t.pct ? v * t.v : t.v; // "200 + 15%" means 15% OF 200
        v = op === '+' ? v + r : v - r;
      }
      return v;
    }
    if (!s) return null;
    var out = sum();
    if (i !== s.length) fail();
    if (!isFinite(out)) throw new Error(isNaN(out) ? 'bad' : 'div0');
    return parseFloat(out.toPrecision(12)); // 0.1 + 0.2 shows 0.3, not 0.30000000000000004
  }
  function fmt(n) {
    if (n == null) return '0';
    if (Math.abs(n) >= 1e15 || (n !== 0 && Math.abs(n) < 1e-9)) return String(n);
    try { return n.toLocaleString(undefined, { maximumFractionDigits: 10 }); } catch (e) { return String(n); }
  }
  function pretty(e) { return e.replace(/\*/g, '×').replace(/\//g, '÷').replace(/-/g, '−'); }

  /* ── Remember the field you were in, for Insert ─────────────────────────────────── */
  function isEntryField(el) {
    if (!el || !el.tagName) return false;
    if (el.tagName === 'TEXTAREA') return !el.readOnly && !el.disabled;
    if (el.tagName !== 'INPUT') return false;
    var t = (el.type || 'text').toLowerCase();
    return ['text', 'number', 'tel', 'search', ''].indexOf(t) !== -1 && !el.readOnly && !el.disabled;
  }
  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (box && box.contains(t)) return;
    if (isEntryField(t)) lastField = t;
  }, true);
  function insertTarget() { return lastField && lastField.isConnected && isEntryField(lastField) ? lastField : null; }
  function refreshInsert() {
    if (!insBtn) return;
    var t = insertTarget();
    insBtn.disabled = !t;
    var lab = t && ((t.labels && t.labels[0] && t.labels[0].textContent) || t.getAttribute('aria-label') || t.placeholder || t.name || '');
    insBtn.title = t ? 'Put the result into ' + (lab ? '“' + String(lab).trim().slice(0, 40) + '”' : 'the field you were in') : 'Click into a field on the page first';
  }
  function insertResult() {
    var t = insertTarget(); if (!t) return;
    var v; try { v = evaluate(expr); } catch (e) { return; }
    if (v == null) return;
    var raw = String(v);
    if (t.type === 'number' && t.step && /^\d*\.?\d+$/.test(t.step) && t.step.indexOf('.') !== -1) {
      raw = v.toFixed(t.step.split('.')[1].length);
    }
    var proto = t.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    var setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    setter.call(t, raw); // the native setter, so frameworks watching the field see it
    t.dispatchEvent(new Event('input', { bubbles: true }));
    t.dispatchEvent(new Event('change', { bubbles: true }));
    try { t.focus({ preventScroll: true }); } catch (e) { t.focus(); }
    flash(t);
  }
  function flash(el) {
    var o = el.style.boxShadow;
    el.style.transition = 'box-shadow .2s';
    el.style.boxShadow = '0 0 0 3px rgba(14,124,144,.45)';
    setTimeout(function () { el.style.boxShadow = o; }, 700);
  }

  /* ── State + display ────────────────────────────────────────────────────────────── */
  function preview() {
    try { var v = evaluate(expr); return v == null ? null : v; } catch (e) { return undefined; }
  }
  function render() {
    if (!box) return;
    exprEl.textContent = pretty(expr) || ' ';
    var v = preview();
    outEl.classList.toggle('kt-calc-dim', !justEvaluated);
    outEl.textContent = v === undefined ? (justEvaluated ? 'Error' : '…') : fmt(v == null ? 0 : v);
    refreshInsert();
  }
  function tape() { return ssGet(TAPE_KEY) || []; }
  function renderTape() {
    if (!tapeEl) return;
    var t = tape();
    tapeEl.innerHTML = '';
    tapeEl.hidden = !t.length;
    t.slice().reverse().forEach(function (row) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'kt-calc-tl';
      b.title = 'Use this result';
      var a = document.createElement('span'); a.textContent = pretty(row.e) + ' =';
      var r = document.createElement('b'); r.textContent = fmt(row.r);
      b.appendChild(a); b.appendChild(r);
      b.addEventListener('click', function () { expr = String(row.r); justEvaluated = true; render(); });
      tapeEl.appendChild(b);
    });
  }
  var OPS = '+-*/';
  function press(k) {
    if (k === 'C') { expr = ''; justEvaluated = false; return render(); }
    if (k === 'BS') { if (justEvaluated) { expr = ''; justEvaluated = false; } else expr = expr.slice(0, -1); return render(); }
    if (k === '=') {
      var v; try { v = evaluate(expr); } catch (e) { justEvaluated = true; outEl.textContent = e.message === 'div0' ? 'Can’t divide by 0' : 'Error'; return; }
      if (v == null) return;
      if (String(v) !== expr) {
        var t = tape(); t.push({ e: expr, r: v }); while (t.length > 12) t.shift(); ssSet(TAPE_KEY, t); renderTape();
      }
      expr = String(v); justEvaluated = true; return render();
    }
    if (k === '±') {
      // Negate the last number typed (or the whole result).
      var m = /(\d*\.?\d+%?|\d+\.)$/.exec(expr);
      if (!m) return;
      var at = m.index, before = expr.slice(0, at);
      if (before.slice(-1) === '-' && (at === 1 || OPS.indexOf(before.slice(-2, -1)) !== -1 || before.slice(-2, -1) === '(')) {
        expr = before.slice(0, -1) + m[0];
      } else {
        expr = before + '-' + m[0];
      }
      justEvaluated = false; return render();
    }
    if (justEvaluated && /[\d.(]/.test(k)) expr = '';  // a new number starts a new sum
    justEvaluated = false;
    if (OPS.indexOf(k) !== -1) {
      if (!expr && k !== '-') expr = '0';
      if (OPS.indexOf(expr.slice(-1)) !== -1 && expr.length > 1) expr = expr.slice(0, -1); // swap the operator
    }
    if (k === '.') {
      var last = /[\d.]*$/.exec(expr)[0];
      if (last.indexOf('.') !== -1) return;
      if (!last) k = '0.';
    }
    expr += k;
    render();
  }

  /* ── Build ──────────────────────────────────────────────────────────────────────── */
  function injectStyle() {
    if (document.getElementById('kt-calc-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-calc-css';
    s.textContent = [
      '.kt-calc{position:fixed;z-index:2147481000;width:268px;background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:16px;',
      '  box-shadow:0 22px 50px -14px rgba(15,23,42,.38),0 2px 8px rgba(15,23,42,.08);font-family:inherit;color:#0F172A;outline:none;',
      '  animation:kt-calc-in .16s ease-out both;user-select:none;-webkit-user-select:none;}',
      '@keyframes kt-calc-in{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}',
      '@media (prefers-reduced-motion:reduce){.kt-calc{animation:none}}',
      '.kt-calc-hd{display:flex;align-items:center;gap:6px;padding:8px 8px 6px 12px;cursor:move;touch-action:none;}',
      '.kt-calc-tt{font-size:12px;font-weight:800;color:#475569;letter-spacing:.3px;flex:1;}',
      '.kt-calc-tt kbd{font:600 10.5px ui-monospace,Consolas,monospace;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:4px;padding:0 4px;margin-left:5px;color:#64748B;}',
      '.kt-calc-hb{width:26px;height:26px;border:0;border-radius:7px;background:transparent;color:#64748B;cursor:pointer;font-size:14px;line-height:1;}',
      '.kt-calc-hb:hover{background:#F1F5F9;color:#0F172A;}',
      '.kt-calc-sc{margin:0 10px;padding:8px 10px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:10px;text-align:right;user-select:text;-webkit-user-select:text;}',
      '.kt-calc-ex{font:500 12px ui-monospace,Consolas,monospace;color:#64748B;min-height:16px;word-break:break-all;}',
      '.kt-calc-out{font-size:26px;font-weight:800;line-height:1.15;color:#0F172A;word-break:break-all;}',
      '.kt-calc-out.kt-calc-dim{color:#94A3B8;}',
      '.kt-calc-tape{margin:6px 10px 0;max-height:84px;overflow:auto;display:flex;flex-direction:column;gap:1px;}',
      '.kt-calc-tl{display:flex;justify-content:space-between;gap:8px;border:0;background:none;padding:2px 4px;border-radius:5px;cursor:pointer;',
      '  font:500 11.5px ui-monospace,Consolas,monospace;color:#64748B;text-align:left;}',
      '.kt-calc-tl span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '.kt-calc-tl b{color:#0E7C90;font-weight:700;white-space:nowrap;}',
      '.kt-calc-tl:hover{background:#F1F5F9;}',
      '.kt-calc-keys{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:10px;}',
      '.kt-calc-k{height:38px;border:1px solid #E2E8F0;border-radius:9px;background:#fff;font:700 15px inherit;font-family:inherit;color:#0F172A;cursor:pointer;',
      '  transition:background .1s,transform .06s;}',
      '.kt-calc-k:hover{background:#F1F5F9;}',
      '.kt-calc-k:active{transform:scale(.96);background:#E2E8F0;}',
      '.kt-calc-k.op{color:#0E7C90;background:#F0FAFB;border-color:#CDEBEF;}',
      '.kt-calc-k.fn{color:#475569;background:#F8FAFC;}',
      '.kt-calc-k.eq{background:linear-gradient(135deg,#0E7C90,#14A1A8);border-color:transparent;color:#fff;}',
      '.kt-calc-ft{display:flex;gap:6px;padding:0 10px 10px;}',
      '.kt-calc-fb{flex:1;height:30px;border:1px solid #E2E8F0;border-radius:8px;background:#fff;font:700 12px inherit;font-family:inherit;color:#334155;cursor:pointer;}',
      '.kt-calc-fb:hover:not(:disabled){background:#F1F5F9;}',
      '.kt-calc-fb:disabled{opacity:.45;cursor:default;}',
      '.kt-calc-fb.pri:not(:disabled){color:#0E7C90;border-color:#CDEBEF;background:#F0FAFB;}',
      '@media (max-width:600px){.kt-calc{left:8px !important;right:8px !important;top:auto !important;bottom:calc(8px + env(safe-area-inset-bottom,0px)) !important;width:auto;}',
      '  .kt-calc-hd{cursor:default;}.kt-calc-k{height:44px;font-size:17px;}.kt-calc-tape{max-height:60px;}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  var KEYS = [
    ['C', 'C', 'fn', 'Clear (Delete)'], ['BS', '⌫', 'fn', 'Backspace'], ['%', '%', 'fn', 'Percent'], ['/', '÷', 'op', 'Divide (/)'],
    ['7', '7'], ['8', '8'], ['9', '9'], ['*', '×', 'op', 'Multiply (*)'],
    ['4', '4'], ['5', '5'], ['6', '6'], ['-', '−', 'op', 'Subtract (-)'],
    ['1', '1'], ['2', '2'], ['3', '3'], ['+', '+', 'op', 'Add (+)'],
    ['±', '±', 'fn', 'Plus / minus'], ['0', '0'], ['.', '.'], ['=', '=', 'eq', 'Equals (Enter)']
  ];

  function build() {
    injectStyle();
    box = document.createElement('div');
    box.className = 'kt-calc'; box.id = 'kt-calc';
    /* role=region, NOT dialog: kt-live, the shell and the mobile nav all treat any
       [role="dialog"] in the document as "a modal is open" and hold refreshes. The
       calculator is a tool beside the page, not a modal. */
    box.setAttribute('role', 'region'); box.setAttribute('aria-label', 'Calculator'); box.tabIndex = -1;
    var mac = /Mac|iPhone|iPad/.test(navigator.platform || '');
    box.innerHTML =
      '<div class="kt-calc-hd"><span class="kt-calc-tt">🧮 Calculator<kbd>' + (mac ? '⌥C' : 'Alt+C') + '</kbd></span>'
      + '<button type="button" class="kt-calc-hb" data-act="close" aria-label="Close calculator" title="Close (Esc)">✕</button></div>'
      + '<div class="kt-calc-sc"><div class="kt-calc-ex"></div><div class="kt-calc-out" aria-live="polite">0</div></div>'
      + '<div class="kt-calc-tape" hidden></div>'
      + '<div class="kt-calc-keys"></div>'
      + '<div class="kt-calc-ft"><button type="button" class="kt-calc-fb" data-act="copy" title="Copy the result">Copy</button>'
      + '<button type="button" class="kt-calc-fb pri" data-act="insert">Insert ↵</button></div>';
    exprEl = box.querySelector('.kt-calc-ex');
    outEl = box.querySelector('.kt-calc-out');
    tapeEl = box.querySelector('.kt-calc-tape');
    insBtn = box.querySelector('[data-act="insert"]');
    var keys = box.querySelector('.kt-calc-keys');
    KEYS.forEach(function (k) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'kt-calc-k' + (k[2] ? ' ' + k[2] : '');
      b.textContent = k[1]; b.setAttribute('data-k', k[0]);
      if (k[3]) { b.title = k[3]; b.setAttribute('aria-label', k[3]); }
      keys.appendChild(b);
    });
    // No focus ring on the key buttons; the panel itself takes the focus (and so the keys).
    box.addEventListener('mousedown', function (e) {
      if (e.target.closest('.kt-calc-sc')) return; // allow selecting the result text
      e.preventDefault();
    });
    box.addEventListener('click', function (e) {
      var k = e.target.closest('[data-k]');
      if (k) { press(k.getAttribute('data-k')); box.focus({ preventScroll: true }); return; }
      var a = e.target.closest('[data-act]'); if (!a) return;
      var act = a.getAttribute('data-act');
      if (act === 'close') close();
      else if (act === 'insert') insertResult();
      else if (act === 'copy') copy(a);
    });
    box.addEventListener('keydown', onBoxKey);
    makeDraggable(box.querySelector('.kt-calc-hd'));
    document.body.appendChild(box);
    place();
    renderTape();
  }
  function copy(btn) {
    var v = preview(); if (v === undefined || v == null) return;
    var txt = String(v);
    var done = function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy'; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(done, function () {});
  }

  /* Keys typed while the calculator has focus. When you are typing in a form field the
     calculator leaves your keys alone. */
  var KEYMAP = { 'Enter': '=', '=': '=', 'Backspace': 'BS', 'Delete': 'C', 'x': '*', 'X': '*' };
  function onBoxKey(e) {
    if (e.ctrlKey || e.metaKey || e.altKey) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C') && !String(window.getSelection() || '')) { copy(box.querySelector('[data-act="copy"]')); e.preventDefault(); }
      return;
    }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    var k = KEYMAP[e.key] || e.key;
    if (/^[\d.+\-*/%()]$/.test(k) || k === '=' || k === 'BS' || k === 'C') {
      e.preventDefault(); e.stopPropagation();
      press(k);
      var b = box.querySelector('[data-k="' + (k === '(' || k === ')' ? '__' : k) + '"]');
      // A Web Animation, not a class + timer: timers are throttled in background tabs and
      // the class stayed on, leaving typed keys grey.
      if (b && b.animate) b.animate([{ transform: 'scale(.94)', background: '#E2E8F0' }, { transform: 'none' }], { duration: 140, easing: 'ease-out' });
    }
  }

  /* ── Position: under the top bar's button, or where it was last dragged ─────────── */
  function clampPos(x, y) {
    var w = box.offsetWidth || 268, h = box.offsetHeight || 420;
    return { x: Math.max(8, Math.min(window.innerWidth - w - 8, x)), y: Math.max(8, Math.min(window.innerHeight - h - 8, y)) };
  }
  function place() {
    var p = lsGet(POS_KEY), pt;
    if (p && typeof p.x === 'number') pt = clampPos(p.x, p.y);
    else {
      var btn = document.getElementById('kt-tb-calc');
      var r = btn && btn.getBoundingClientRect();
      pt = r && r.width ? clampPos(r.right - (box.offsetWidth || 268), r.bottom + 10) : clampPos(window.innerWidth - 290, 76);
    }
    box.style.left = pt.x + 'px'; box.style.top = pt.y + 'px';
  }
  function makeDraggable(handle) {
    var sx, sy, ox, oy, on = false;
    handle.addEventListener('pointerdown', function (e) {
      if (e.target.closest('button') || window.matchMedia('(max-width:600px)').matches) return;
      on = true; sx = e.clientX; sy = e.clientY;
      var r = box.getBoundingClientRect(); ox = r.left; oy = r.top;
      try { handle.setPointerCapture(e.pointerId); } catch (x) {}
    });
    handle.addEventListener('pointermove', function (e) {
      if (!on) return;
      var p = clampPos(ox + e.clientX - sx, oy + e.clientY - sy);
      box.style.left = p.x + 'px'; box.style.top = p.y + 'px';
    });
    var end = function () {
      if (!on) return; on = false;
      lsSet(POS_KEY, { x: parseInt(box.style.left, 10), y: parseInt(box.style.top, 10) });
    };
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }
  window.addEventListener('resize', function () {
    if (!isOpen()) return;
    var p = clampPos(parseInt(box.style.left, 10) || 0, parseInt(box.style.top, 10) || 0);
    box.style.left = p.x + 'px'; box.style.top = p.y + 'px';
  });

  /* ── Open / close ───────────────────────────────────────────────────────────────── */
  /* Closed = out of the document (the sum and tape are kept in memory/sessionStorage), so
     nothing that scans the page ever trips over a hidden calculator. */
  function isOpen() { return !!(box && box.isConnected); }
  function open() {
    if (isOpen()) { document.body.appendChild(box); return; } // already open: bring it to the front
    build();
    render();
    // Take the keys only if you were not in the middle of typing in a field.
    if (!isEntryField(document.activeElement)) box.focus({ preventScroll: true });
    syncButton(true);
  }
  function close() {
    if (!isOpen()) return;
    var had = box.contains(document.activeElement);
    box.remove(); box = exprEl = outEl = tapeEl = insBtn = null;
    syncButton(false);
    var t = insertTarget();
    if (t && had) { try { t.focus({ preventScroll: true }); } catch (e) {} }
  }
  function toggle() { if (isOpen()) close(); else open(); }
  function syncButton(on) {
    var b = document.getElementById('kt-tb-calc');
    if (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.style.background = on ? 'rgba(14,124,144,.14)' : ''; }
  }

  // Alt+C (⌥C) from anywhere in the portal, even mid-typing. e.code, because ⌥C types "ç"
  // on a Mac. Signed-in only: the login page has no use for it.
  document.addEventListener('keydown', function (e) {
    if (!e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || e.code !== 'KeyC' || e.repeat) return;
    var signedIn = false;
    try { signedIn = !!(sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')); } catch (x) {}
    if (!signedIn) return;
    e.preventDefault(); e.stopPropagation();
    toggle();
  }, true);

  KT.openCalculator = open;
  KT.closeCalculator = close;
  KT.toggleCalculator = toggle;
  KT._calcEval = evaluate; // for tests
})();
