/* ═══════════════════════════════════════════════════════════════════════════
   KiddieTrac — readable date and time pickers on phones and in the APK.

   THE SAME BUG AS THE DROPDOWNS, in a different control. Android does not let the page
   draw the picker for <input type="date">: it hands the job to the OS, which opens its
   own dialog using the APP's theme colours. On a device in dark mode that came out with
   pale text on a pale ground — the calendar was there, but you could not read which day
   you were tapping. No page CSS can reach it, because that dialog is not ours.

   So on touch devices we draw the calendar in the page, where our colours apply. The
   real <input> keeps its value and still fires `input` and `change`, so every screen
   that listens keeps working and none of the 70 pickers across the portal had to change.

   Covers date, time, datetime-local and month. Desktop is untouched — a mouse-driven
   picker renders fine and people expect the native one.

   Opt out on a single field with data-kt-native-date.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.dateSheetLoaded) return;
  KT.dateSheetLoaded = true;

  function coarse() {
    try { return w.matchMedia && w.matchMedia('(pointer: coarse)').matches; }
    catch (e) { return false; }
  }
  if (!coarse()) return;

  var Z = 2147483000;                       // above the portal's own modals
  var TYPES = { date: 1, time: 1, 'datetime-local': 1, month: 1 };
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
  var DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

  function eligible(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    if (!TYPES[el.type]) return false;
    if (el.disabled || el.readOnly) return false;
    if (el.hasAttribute('data-kt-native-date')) return false;
    return true;
  }

  var sheet = null;
  /* A tap is pointerdown → mousedown → click, and the click lands on whatever is under
     the finger BY THEN — which is the sheet this tap just opened. Without this guard it
     hits the backdrop and shuts again, so the field looks dead. Learned the hard way on
     the select sheet. */
  var openedAt = 0;
  function fromOpeningTap() { return (Date.now() - openedAt) < 450; }

  function close() {
    if (!sheet) return;
    var el = sheet;
    sheet = null;
    el.style.opacity = '0';
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 160);
    d.removeEventListener('keydown', onKey, true);
  }
  function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(y, m, day) { return y + '-' + pad(m + 1) + '-' + pad(day); }

  /* Parse whatever the field already holds, so opening a filled field lands on the month
     it is showing rather than on today. */
  function currentParts(input) {
    var v = String(input.value || '');
    var now = new Date();
    var out = { y: now.getFullYear(), m: now.getMonth(), day: now.getDate(), hh: 9, mm: 0, hasDate: false, hasTime: false };
    var dm = v.match(/^(\d{4})-(\d{2})(?:-(\d{2}))?/);
    if (dm) {
      out.y = +dm[1]; out.m = +dm[2] - 1;
      if (dm[3]) { out.day = +dm[3]; }
      out.hasDate = true;
    }
    var tm = v.match(/(\d{2}):(\d{2})/);
    if (tm) { out.hh = +tm[1]; out.mm = +tm[2]; out.hasTime = true; }
    return out;
  }

  // min/max are real constraints on these fields (a request cannot start yesterday), so
  // the calendar has to respect them rather than letting someone pick a day the form
  // will then reject.
  function outOfRange(input, dateStr) {
    var min = input.getAttribute('min');
    var max = input.getAttribute('max');
    if (min && dateStr < String(min).slice(0, 10)) return true;
    if (max && dateStr > String(max).slice(0, 10)) return true;
    return false;
  }

  function shell() {
    var wrap = d.createElement('div');
    wrap.className = 'kt-date-sheet';
    wrap.setAttribute('data-no-modal-guard', '1');
    wrap.style.cssText = 'position:fixed;inset:0;z-index:' + Z + ';background:rgba(15,23,42,.5);'
      + 'display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;'
      + 'transition:opacity .16s ease;-webkit-tap-highlight-color:transparent;';

    var panel = d.createElement('div');
    // Every colour stated outright — inheriting is what made the native one unreadable.
    panel.style.cssText = 'background:#fff;color:#0F172A;width:100%;max-width:360px;'
      + 'border-radius:18px;box-shadow:0 12px 40px rgba(15,23,42,.3);overflow:hidden;'
      + 'display:flex;flex-direction:column;max-height:90vh;';
    wrap.appendChild(panel);
    return { wrap: wrap, panel: panel };
  }

  function headerBar(title, onClose) {
    var h = d.createElement('div');
    h.style.cssText = 'padding:14px 16px;border-bottom:1px solid #E2E8F0;display:flex;'
      + 'align-items:center;gap:10px;flex:0 0 auto;';
    var t = d.createElement('div');
    t.style.cssText = 'flex:1;min-width:0;font-size:15px;font-weight:700;color:#0F172A;';
    t.textContent = title;
    h.appendChild(t);
    var x = d.createElement('button');
    x.type = 'button';
    x.textContent = '✕';
    x.setAttribute('aria-label', 'Close');
    x.style.cssText = 'width:32px;height:32px;border-radius:50%;border:1px solid #E2E8F0;'
      + 'background:#F8FAFC;color:#475569;font-size:14px;cursor:pointer;flex:0 0 auto;';
    x.addEventListener('click', function (e) { e.preventDefault(); onClose(); });
    h.appendChild(x);
    return h;
  }

  function footerBar(input, apply, onClose) {
    var f = d.createElement('div');
    f.style.cssText = 'padding:12px 16px;border-top:1px solid #E2E8F0;display:flex;gap:8px;'
      + 'flex:0 0 auto;background:#FCFDFE;';
    var clear = d.createElement('button');
    clear.type = 'button';
    clear.textContent = 'Clear';
    clear.style.cssText = 'flex:0 0 auto;padding:11px 16px;border-radius:10px;border:1px solid #E2E8F0;'
      + 'background:#fff;color:#475569;font-size:14px;font-weight:600;cursor:pointer;';
    clear.addEventListener('click', function () { commit(input, ''); onClose(); });
    var ok = d.createElement('button');
    ok.type = 'button';
    ok.textContent = 'Done';
    ok.style.cssText = 'flex:1;padding:11px 16px;border-radius:10px;border:0;'
      + 'background:#1F6080;color:#fff;font-size:15px;font-weight:700;cursor:pointer;';
    ok.addEventListener('click', function () { apply(); onClose(); });
    f.appendChild(clear);
    f.appendChild(ok);
    return f;
  }

  function commit(input, value) {
    if (input.value === value) { return; }
    input.value = value;
    // The real field stays the source of truth, so existing validation and dependent
    // fields carry on working untouched.
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /* ── the calendar ─────────────────────────────────────────────────────── */
  function buildCalendar(input, state, onPick) {
    var box = d.createElement('div');
    box.style.cssText = 'padding:12px 14px 4px;flex:1 1 auto;overflow-y:auto;';

    var nav = d.createElement('div');
    nav.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:10px;';
    var mk = function (label, delta) {
      var b = d.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.style.cssText = 'width:36px;height:36px;border-radius:10px;border:1px solid #E2E8F0;'
        + 'background:#fff;color:#334155;font-size:16px;cursor:pointer;flex:0 0 auto;';
      b.addEventListener('click', function () {
        state.m += delta;
        while (state.m < 0) { state.m += 12; state.y--; }
        while (state.m > 11) { state.m -= 12; state.y++; }
        redraw();
      });
      return b;
    };
    var label = d.createElement('div');
    label.style.cssText = 'flex:1;text-align:center;font-size:15px;font-weight:800;color:#0F172A;';
    nav.appendChild(mk('‹', -1));
    nav.appendChild(label);
    nav.appendChild(mk('›', 1));
    box.appendChild(nav);

    var grid = d.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:3px;';
    box.appendChild(grid);

    function redraw() {
      label.textContent = MONTHS[state.m] + ' ' + state.y;
      grid.innerHTML = '';

      DOW.forEach(function (dw) {
        var h = d.createElement('div');
        h.style.cssText = 'text-align:center;font-size:11px;font-weight:800;color:#94A3B8;padding:4px 0;';
        h.textContent = dw;
        grid.appendChild(h);
      });

      var first = new Date(state.y, state.m, 1).getDay();
      var days = new Date(state.y, state.m + 1, 0).getDate();
      var today = new Date();
      var todayStr = ymd(today.getFullYear(), today.getMonth(), today.getDate());

      for (var i = 0; i < first; i++) { grid.appendChild(d.createElement('div')); }

      for (var day = 1; day <= days; day++) {
        (function (day) {
          var ds = ymd(state.y, state.m, day);
          var on = state.hasDate && ds === ymd(state.y, state.m, state.day)
            && state.selY === state.y && state.selM === state.m;
          var isToday = ds === todayStr;
          var blocked = outOfRange(input, ds);

          var b = d.createElement('button');
          b.type = 'button';
          b.textContent = String(day);
          b.disabled = blocked;
          b.style.cssText = 'height:40px;border-radius:10px;font-size:15px;cursor:pointer;'
            + 'font-family:inherit;'
            + 'border:' + (isToday && !on ? '1.5px solid #159FB4' : '1px solid transparent') + ';'
            + 'background:' + (on ? '#1F6080' : 'transparent') + ';'
            + 'color:' + (blocked ? '#CBD5E1' : (on ? '#fff' : '#0F172A')) + ';'
            + 'font-weight:' + (on || isToday ? '800' : '500') + ';';
          if (!blocked) {
            b.addEventListener('click', function () {
              state.day = day; state.selY = state.y; state.selM = state.m; state.hasDate = true;
              redraw();
              if (onPick) onPick();
            });
          }
          grid.appendChild(b);
        })(day);
      }
    }

    redraw();
    return box;
  }

  /* ── the time picker ──────────────────────────────────────────────────── */
  function buildTime(state) {
    var box = d.createElement('div');
    box.style.cssText = 'padding:14px;display:flex;gap:10px;align-items:stretch;flex:1 1 auto;'
      + 'min-height:0;';

    function column(values, current, fmt, onSet) {
      var col = d.createElement('div');
      col.style.cssText = 'flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;max-height:250px;'
        + 'border:1px solid #E2E8F0;border-radius:12px;padding:4px;';
      var selected = null;
      values.forEach(function (v) {
        var b = d.createElement('button');
        b.type = 'button';
        b.textContent = fmt(v);
        var on = v === current;
        b.style.cssText = 'display:block;width:100%;padding:11px 8px;border:0;border-radius:9px;'
          + 'font-size:15px;font-family:inherit;cursor:pointer;text-align:center;'
          + 'background:' + (on ? '#F0F9FF' : 'transparent') + ';'
          + 'color:' + (on ? '#0369A1' : '#0F172A') + ';font-weight:' + (on ? '800' : '500') + ';';
        if (on) { selected = b; }
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(col.children, function (c) {
            c.style.background = 'transparent'; c.style.color = '#0F172A'; c.style.fontWeight = '500';
          });
          b.style.background = '#F0F9FF'; b.style.color = '#0369A1'; b.style.fontWeight = '800';
          onSet(v);
        });
        col.appendChild(b);
      });
      // Scroll the current value into view once it has a height to measure.
      setTimeout(function () {
        if (selected) { col.scrollTop = Math.max(0, selected.offsetTop - 90); }
      }, 0);
      return col;
    }

    var hours = [];
    for (var h = 0; h < 24; h++) { hours.push(h); }
    var mins = [];
    for (var m = 0; m < 60; m += 5) { mins.push(m); }
    // Keep an odd stored minute selectable rather than snapping it away silently.
    if (mins.indexOf(state.mm) === -1) { mins.push(state.mm); mins.sort(function (a, b) { return a - b; }); }

    box.appendChild(column(hours, state.hh, function (v) {
      var ap = v >= 12 ? 'PM' : 'AM';
      var hh = v % 12; if (hh === 0) { hh = 12; }
      return hh + ' ' + ap;
    }, function (v) { state.hh = v; }));

    box.appendChild(column(mins, state.mm, function (v) { return pad(v); },
      function (v) { state.mm = v; }));

    return box;
  }

  function open(input) {
    close();

    var type = input.type;
    var p = currentParts(input);
    var state = {
      y: p.y, m: p.m, day: p.day, hh: p.hh, mm: p.mm,
      hasDate: p.hasDate, selY: p.y, selM: p.m,
    };

    var s = shell();
    var label = '';
    try {
      var lab = (input.id && d.querySelector('label[for="' + (w.CSS && CSS.escape ? CSS.escape(input.id) : input.id) + '"]'))
        || (input.closest ? input.closest('label') : null);
      if (lab) { label = (lab.textContent || '').trim().slice(0, 40); }
    } catch (e) { /* unlabelled is fine */ }
    if (!label) {
      label = type === 'time' ? 'Pick a time' : (type === 'month' ? 'Pick a month' : 'Pick a date');
    }

    s.panel.appendChild(headerBar(label, close));

    function apply() {
      if (type === 'time') {
        commit(input, pad(state.hh) + ':' + pad(state.mm));
      } else if (type === 'month') {
        commit(input, state.y + '-' + pad(state.m + 1));
      } else if (type === 'datetime-local') {
        commit(input, ymd(state.selY, state.selM, state.day) + 'T' + pad(state.hh) + ':' + pad(state.mm));
      } else {
        commit(input, ymd(state.selY, state.selM, state.day));
      }
    }

    if (type === 'time') {
      s.panel.appendChild(buildTime(state));
    } else if (type === 'month') {
      // A month field wants a month, so a whole calendar is more work than the answer.
      var mbox = d.createElement('div');
      mbox.style.cssText = 'padding:14px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;';
      MONTHS.forEach(function (name, idx) {
        var b = d.createElement('button');
        b.type = 'button';
        b.textContent = name.slice(0, 3);
        var on = idx === state.m;
        b.style.cssText = 'padding:14px 6px;border-radius:11px;font-size:14px;font-family:inherit;'
          + 'cursor:pointer;border:1px solid ' + (on ? 'transparent' : '#E2E8F0') + ';'
          + 'background:' + (on ? '#1F6080' : '#fff') + ';color:' + (on ? '#fff' : '#0F172A') + ';'
          + 'font-weight:' + (on ? '800' : '600') + ';';
        b.addEventListener('click', function () {
          state.m = idx;
          Array.prototype.forEach.call(mbox.children, function (c) {
            c.style.background = '#fff'; c.style.color = '#0F172A';
            c.style.border = '1px solid #E2E8F0'; c.style.fontWeight = '600';
          });
          b.style.background = '#1F6080'; b.style.color = '#fff';
          b.style.border = '1px solid transparent'; b.style.fontWeight = '800';
        });
        mbox.appendChild(b);
      });
      s.panel.appendChild(mbox);
    } else {
      s.panel.appendChild(buildCalendar(input, state, null));
      if (type === 'datetime-local') {
        var sep = d.createElement('div');
        sep.style.cssText = 'padding:6px 14px 0;font-size:11.5px;font-weight:800;letter-spacing:.4px;'
          + 'text-transform:uppercase;color:#94A3B8;';
        sep.textContent = 'Time';
        s.panel.appendChild(sep);
        s.panel.appendChild(buildTime(state));
      }
    }

    s.panel.appendChild(footerBar(input, apply, close));

    s.wrap.addEventListener('click', function (e) {
      if (fromOpeningTap()) return;          // the tap that opened this, still arriving
      if (e.target === s.wrap) close();
    });

    d.body.appendChild(s.wrap);
    sheet = s.wrap;
    openedAt = Date.now();
    requestAnimationFrame(function () { s.wrap.style.opacity = '1'; });
    d.addEventListener('keydown', onKey, true);
  }

  /* One press event, not three — opening on more than one builds the sheet twice per
     tap. Pointer events cover mouse and touch alike. */
  var PRESS = w.PointerEvent ? 'pointerdown' : 'mousedown';

  function intercept(e) {
    var el = e.target;
    if (!eligible(el)) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.type !== PRESS) return;
    try {
      // Stops the OS picker following the focus in some WebViews.
      el.blur();
      open(el);
    } catch (err) {
      // Never leave somebody with a field that does nothing — an unreadable native
      // picker beats a dead one.
      el.setAttribute('data-kt-native-date', '1');
      if (w.console && console.warn) { console.warn('[kt-date-sheet] native fallback:', err); }
    }
  }

  d.addEventListener(PRESS, intercept, true);
  if (PRESS !== 'mousedown') { d.addEventListener('mousedown', intercept, true); }
  d.addEventListener('click', intercept, true);
  d.addEventListener('focus', function (e) {
    // Some WebViews open the OS picker on focus alone.
    if (eligible(e.target) && !sheet) { e.target.blur(); }
  }, true);

  w.addEventListener('popstate', function () { if (sheet) close(); });
})(window, document);
