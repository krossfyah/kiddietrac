/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — "add" shortcuts between screens

   The calendar can send you to another screen to add the thing you were looking at
   the calendar to add: a closure, a vacation hold, a field trip, conference slots.
   Rather than the calendar growing its own copy of four forms — four copies to drift
   from the originals, four sets of validation to get subtly wrong — it links to the
   screen that owns the record and opens that screen's own dialog.

   The link carries ?add=1 (and optionally &date=YYYY-MM-DD). This file watches for
   that, finds the add control on the screen that just rendered, clicks it, and fills
   in the start date.

   Both the button and the date field are named explicitly per screen. An earlier
   version guessed the date field ("first date input in the last fixed-position
   element") and quietly filled nothing, because not every dialog on this platform is
   built the same way. Naming them is four lines and cannot be wrong.

   The knowledge lives here rather than as attributes on those four screens: being
   linked to is the caller's idea, and a screen should not carry markup for it.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  var TARGETS = {
    'closures':       { open: '#cl-new', date: '#cl-date'  },
    'vacation-holds': { open: '#vh-add', date: '#vh-start' },
    'field-trips':    { open: '#ft-new', date: '#ft-date'  },
    'conferences':    { open: '#cf-new', date: '#cf-date'  },
  };

  function screenOf() {
    return String(w.location.hash || '').replace(/^#/, '').split('/')[0].split('?')[0];
  }

  function params() {
    var h = String(w.location.hash || '');
    var q = h.indexOf('?');
    var out = {};
    if (q === -1) { return out; }
    h.slice(q + 1).split('&').forEach(function (p) {
      var kv = p.split('=');
      if (kv[0]) { out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); }
    });
    return out;
  }

  /* Only an empty field is filled. A value already there was put there by the screen,
     which knows more about its own form than a query string does. */
  function fillDate(sel, ymd, attempt) {
    var f = d.querySelector(sel);
    if (!f) {
      if (attempt < 15) { setTimeout(function () { fillDate(sel, ymd, attempt + 1); }, 120); }
      return;
    }
    if (f.value) { return; }
    f.value = ymd;
    f.dispatchEvent(new Event('input', { bubbles: true }));
    f.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function tryOpen(attempt) {
    var p = params();
    if (p.add !== '1') { return; }
    var t = TARGETS[screenOf()];
    if (!t) { return; }

    var btn = d.querySelector(t.open);
    if (!btn) {
      // These screens fetch their data before drawing anything, so the button is not
      // there on arrival. Keep looking for a few seconds, then give up quietly — the
      // screen is still the right screen, just without the dialog opened for you.
      if (attempt < 25) { setTimeout(function () { tryOpen(attempt + 1); }, 200); }
      return;
    }

    // Drop the marker from the URL FIRST. Otherwise a re-render, a refresh or a Back
    // reopens a dialog somebody has already closed.
    var clean = screenOf();
    try { w.history.replaceState(null, '', w.location.pathname + '#' + clean); } catch (e) {}

    btn.click();
    if (p.date && t.date) { fillDate(t.date, p.date, 0); }
  }

  w.addEventListener('hashchange', function () { setTimeout(function () { tryOpen(0); }, 140); });
  if (d.readyState === 'loading') {
    w.addEventListener('DOMContentLoaded', function () { setTimeout(function () { tryOpen(0); }, 700); });
  } else {
    setTimeout(function () { tryOpen(0); }, 700);
  }
})(window);
