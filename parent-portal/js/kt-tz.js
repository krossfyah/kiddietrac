/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — agency-local time (2026-07-13).

   Every time in the platform should read in the AGENCY's timezone, not the
   device's. A director checking the roster from a holiday in another zone, or a
   phone whose clock is set elsewhere, must not see a different day than the
   centre is actually having — and "today" must mean the centre's today.

   The server is UTC; the agency's timezone comes back on the user
   (`agency_timezone`, from /auth/me) and is cached in sessionStorage.

     KT.tz()                  → 'America/Toronto'
     KT.fmtTime(ts)           → '8:07 PM'
     KT.fmtDate(ts)           → '13 Jul 2026'
     KT.fmtDateTime(ts)       → 'Today, 8:07 PM'  /  '13 Jul, 8:07 PM'
     KT.agencyToday()         → '2026-07-13' (the agency's date, not the device's)

   Timestamps from the API are MySQL-style ("2026-07-13 20:07:49") with no zone
   marker, which JS would otherwise parse as LOCAL time. They are UTC, so we
   append 'Z' unless the string already carries a zone.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  var DEFAULT_TZ = 'America/Toronto';

  function tz() {
    try {
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      return u.agency_timezone || DEFAULT_TZ;
    } catch (e) { return DEFAULT_TZ; }
  }

  // MySQL datetimes are UTC but carry no marker; without this they'd be read as
  // device-local and every time would be off by the UTC offset.
  function parse(ts) {
    if (!ts) return null;
    if (ts instanceof Date) return ts;
    var s = String(ts).trim();
    var d;
    if (/(Z|[+-]\d{2}:?\d{2})$/.test(s)) d = new Date(s.replace(' ', 'T'));
    else d = new Date(s.replace(' ', 'T') + 'Z');
    return isNaN(d) ? null : d;
  }

  function fmt(ts, opts) {
    var d = parse(ts);
    if (!d) return '';
    try {
      return new Intl.DateTimeFormat([], Object.assign({ timeZone: tz() }, opts)).format(d);
    } catch (e) {
      return d.toLocaleString();
    }
  }

  function fmtTime(ts) { return fmt(ts, { hour: 'numeric', minute: '2-digit' }); }
  function fmtDate(ts) { return fmt(ts, { day: 'numeric', month: 'short', year: 'numeric' }); }

  /** The agency's current date as YYYY-MM-DD — the basis for "is this today?". */
  function agencyToday() {
    var parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz(), year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date());
    return parts;   // en-CA gives YYYY-MM-DD
  }
  function agencyDateOf(ts) {
    var d = parse(ts);
    if (!d) return '';
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: tz(), year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(d);
  }

  function fmtDateTime(ts) {
    var d = parse(ts);
    if (!d) return '';
    var day = agencyDateOf(ts) === agencyToday()
      ? 'Today'
      : fmt(ts, { day: 'numeric', month: 'short' });
    return day + ', ' + fmtTime(ts);
  }

  KT.tz = tz;
  KT.parseTs = parse;
  KT.fmtTime = fmtTime;
  KT.fmtDate = fmtDate;
  KT.fmtDateTime = fmtDateTime;
  KT.agencyToday = agencyToday;
  KT.agencyDateOf = agencyDateOf;
  KT.isAgencyToday = function (ts) { return !!ts && agencyDateOf(ts) === agencyToday(); };
})(window);
