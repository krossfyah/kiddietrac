/* ═══════════════════════════════════════════════════════════════════
   kt-tz-global.js — make the agency timezone the default everywhere.

   THE BUG. Time-off requests (and much else) displayed hours out. The cause is
   not formatting, it is PARSING. MySQL hands back "2026-08-15 00:53:04" with no
   zone marker, and the server stores UTC. JavaScript reads a zone-less
   date-TIME string as DEVICE-LOCAL, so on a Toronto machine that instant is
   read as 00:53 Toronto instead of 00:53 UTC — every timestamp lands four or
   five hours ahead of the truth.

   kt-tz.js has always had the fix (KT.parseTs appends 'Z'), and 12 of 170 files
   use it. The other 38 that format dates roll their own fmtDate and call
   new Date(ts) directly, so the standing rule — every displayed time in the
   AGENCY timezone — held only where somebody remembered it.

   THE LEVER. Rather than edit 38 files and miss some, this patches the two
   places the mistake is actually made:

     1. new Date("YYYY-MM-DD HH:MM:SS")  →  treated as UTC, not device-local.
     2. .toLocaleTimeString() / .toLocaleString()  →  rendered in the agency's
        zone unless the caller named one.

   WHAT IT DELIBERATELY DOES NOT TOUCH.

   Date-only strings ("2026-08-15"). The language already parses those as UTC;
   appending 'Z' twice, or shifting them, would move dates by a day.

   Strings that already carry a zone (…Z, …+05:00). Nothing ambiguous about
   them, and re-stamping would double-shift.

   toLocaleDateString, unless KT.tzStrictDates is set. A calendar grid built
   from LOCAL parts — new Date(2026, 7, 15) — formatted in a zone west of the
   device renders as the previous day. With the parse fix in place a device in
   the agency's own zone is already correct, which is every device we have.
   Turn it on for genuinely remote staff; it is off by default because a
   silently wrong calendar is worse than a date shown in the reader's own zone.

   Loaded immediately after kt-tz.js, before any screen, because it has to be
   in place before the first timestamp is read.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});
  var NativeDate = window.Date;

  // A zone-less date-TIME, which is the ambiguous case. Date-only is excluded on
  // purpose: the spec already treats it as UTC.
  var AMBIGUOUS = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?$/;

  function asUtc(s) {
    return s.trim().replace(' ', 'T') + 'Z';
  }

  function agencyTz() {
    try {
      if (typeof KT.tz === 'function') { return KT.tz(); }
    } catch (e) {}
    return 'America/Toronto';
  }

  /* ── 1. parsing ────────────────────────────────────────────────────
     A Proxy rather than a wrapper function, so Date.now, Date.UTC,
     Date.prototype and `x instanceof Date` all keep working untouched —
     a hand-rolled subclass gets at least one of those wrong. */
  if (typeof window.Proxy === 'function' && !KT._tzGlobalInstalled) {
    window.Date = new window.Proxy(NativeDate, {
      construct: function (target, args) {
        if (args.length === 1 && typeof args[0] === 'string' && AMBIGUOUS.test(args[0].trim())) {
          return new target(asUtc(args[0]));
        }
        return new (Function.prototype.bind.apply(target, [null].concat(args)))();
      },
      apply: function (target, thisArg, args) {
        // Date() called as a function returns a string and ignores its arguments,
        // exactly as the language specifies.
        return target.apply(thisArg, args);
      },
      get: function (target, prop, recv) {
        if (prop === 'parse') {
          return function (s) {
            return (typeof s === 'string' && AMBIGUOUS.test(s.trim()))
              ? target.parse(asUtc(s))
              : target.parse(s);
          };
        }
        return Reflect.get(target, prop, recv);
      },
    });
  }

  /* ── 2. rendering ──────────────────────────────────────────────────
     Only the time-bearing formatters by default. See the note above on
     toLocaleDateString. */
  function patchFormatter(method) {
    var native = NativeDate.prototype[method];
    if (!native || native.__ktTz) { return; }
    var patched = function (locales, options) {
      var opts = options || {};
      if (!opts.timeZone) {
        try {
          opts = Object.assign({}, opts, { timeZone: agencyTz() });
        } catch (e) { opts = options; }
      }
      return native.call(this, locales, opts);
    };
    patched.__ktTz = true;
    NativeDate.prototype[method] = patched;
  }

  if (!KT._tzGlobalInstalled) {
    patchFormatter('toLocaleTimeString');
    patchFormatter('toLocaleString');
    if (KT.tzStrictDates) { patchFormatter('toLocaleDateString'); }
  }

  KT._tzGlobalInstalled = true;

  /** Turn the date formatter on too, for a device outside the agency's zone. */
  KT.tzStrictDatesOn = function () {
    KT.tzStrictDates = true;
    patchFormatter('toLocaleDateString');
  };

  /* A way to see what this is doing without reading the source, since the whole
     point is that it works invisibly:  KT.tzDebug('2026-08-15 00:53:04')  */
  KT.tzDebug = function (ts) {
    var d = new window.Date(ts);
    return {
      input: ts,
      agencyTz: agencyTz(),
      deviceTz: (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || 'unknown',
      parsedIso: d.toISOString(),
      shownTime: d.toLocaleTimeString(),
      shownDate: d.toLocaleDateString(),
    };
  };
})(window);
