/* KiddieTrac — Settings → Calendar → "Add to my calendar" (2026-11-03, cal1103).

   A private subscription link (webcal:// + https .ics) so the centre's closures, events and trips,
   and the child's own days away, show in the family's Apple / Google / Outlook calendar and stay up
   to date. Staff get their centre's closures and events plus their own shifts and time off.

   Loaded on demand by screen-settings.js when the Calendar tab opens:
     KT.calSync.render(host, { role, mkSwitch, CARD, SECT })
   Server: GET/PATCH /me/calendar-feed, POST /me/calendar-feed/reset (CalendarFeedController).
   Every choice saves on its own, like the contact preferences beside it. Reset uses the house
   KT.confirm. No popups of our own; the help is an inline panel (phones: no keyboard, no overlay). */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var Api = KT.Api;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.setAttribute('style', attrs[k]);
      else if (k === 'text') e.textContent = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function said(e, d) { return (e && (e.message || (e.data && e.data.message))) || d; }
  function toast(icon, t, b, c) { try { if (KT.toast) KT.toast(icon, t, b, c); } catch (e) {} }
  function native() { try { var C = window.Capacitor; return !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative)); } catch (e) { return false; } }
  function platform() {
    var ua = navigator.userAgent || '';
    if (/android/i.test(ua)) return 'android';
    if (/iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
    if (/Macintosh/.test(ua)) return 'mac';
    return 'other';
  }
  // The native apps hand the page to the system browser; a browser opens a new tab. (Not
  // window.open(..., 'noopener'): that always returns null, which reads as "blocked" and would
  // navigate the portal itself away to Google.)
  function openOut(url) {
    if (native() && KT.openDocumentExternally) { KT.openDocumentExternally(url); return; }
    var t = null;
    try { t = window.open(url, '_blank'); if (t) t.opener = null; } catch (e) {}
    if (!t) window.location.href = url;
  }
  function copyText(txt) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(txt).catch(function () { return legacyCopy(txt); });
    }
    return legacyCopy(txt);
  }
  function legacyCopy(txt) {
    return new Promise(function (res, rej) {
      var ta = el('textarea', { readonly: 'readonly', style: 'position:fixed;top:-1000px;left:0;opacity:0;' });
      ta.value = txt; document.body.appendChild(ta); ta.select();
      var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
      ta.remove(); ok ? res() : rej(new Error('copy'));
    });
  }
  function when(ts) {
    if (!ts) return '';
    var s = String(ts); if (s.indexOf('T') === -1) s = s.replace(' ', 'T'); if (!/[zZ]|[+-]\d\d:?\d\d$/.test(s)) s += 'Z';
    var d = new Date(s); if (isNaN(d)) return '';
    try {
      return new Intl.DateTimeFormat(undefined, { timeZone: (KT.tz && KT.tz()) || undefined, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(d);
    } catch (e) { return d.toLocaleString(); }
  }

  var BTN = '-webkit-appearance:none;appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:7px;height:32px;min-height:32px;padding:0 13px;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;box-sizing:border-box;white-space:nowrap;text-decoration:none;';
  var BTN_MAIN = BTN + 'border:0;background:#1F6080;color:#fff;';
  var BTN_SOFT = BTN + 'border:1.5px solid #CBD5E1;background:#fff;color:#1F6080;';
  var BTN_RED = BTN + 'border:1.5px solid #FCA5A5;background:#fff;color:#B91C1C;';

  var LABELS = {
    parent: [
      ['closures', 'Closures and holidays', 'Days the centre is closed, as all-day events.'],
      ['events', 'Events and trips', 'Dated announcements, field trips, events you are invited to, and booked conferences.'],
      ['absences', 'My child’s days away', 'Absences you or the centre recorded, and approved vacations.'],
      ['schedule', 'Weekly drop-off and pick-up', 'A repeating reminder on your child’s regular days. Closed days and absences are skipped.']
    ],
    staff: [
      ['closures', 'Closures and holidays', 'Days your centre is closed.'],
      ['events', 'Events and trips', 'Dated announcements for staff, field trips and conferences booked with you.'],
      ['shifts', 'My shifts', 'Your scheduled shifts.'],
      ['timeoff', 'My time off', 'Approved time off, and requests still waiting.']
    ]
  };

  /* ── OUTLOOK / GOOGLE CALENDAR (2026-11-25, Anthony: "make it so the calendar is connected to the email that will be
     setup via the mail client ... (optional)"). Opt-in: nothing changes until "Connect calendar". Uses a mailbox the
     person already signed in to in Email (Microsoft 365 or Google) and asks Microsoft / Google for the calendar too.
     Their own calendar: its events show in the My day panel, and meetings they organise go on it (Microsoft / Google
     send the invitations). The agency's calendar (an agency admin connects it): its events show for the agency's
     admins and directors - private ones as "Busy" - and the agency's meetings are copied onto it.
     Server: /me/calendar-connections (CalendarConnectController, App\Support\ExternalCalendar). The sign-in comes
     back to #settings/calendar with ?kcal= (or ?kcal_error=), finished here. ── */
  function takeReturn() {
    var q; try { q = new URLSearchParams(window.location.search); } catch (e) { return null; }
    var n = q.get('kcal'), err = q.get('kcal_error');
    if (!n && !err) return null;
    q.delete('kcal'); q.delete('kcal_error');
    var rest = q.toString();
    try { history.replaceState(history.state, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash); } catch (e) {}
    return { nonce: n, error: err };
  }
  function renderExternal(host, o) {
    var CARD = o.CARD || 'background:#fff;border-radius:16px;padding:16px;margin-bottom:14px;';
    var SECT = o.SECT || 'font-size:16px;font-weight:800;color:#0f172a;margin:0 0 10px;';
    var card = el('div', { style: CARD, 'data-kt-nofit': '1', class: 'kt-xcal' });
    card.appendChild(el('div', { style: SECT, text: 'Outlook or Google calendar' }));
    var body = el('div', {}, [el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0;', text: 'Loading…' })]);
    card.appendChild(body);
    var back = takeReturn();
    var first = back && back.nonce
      ? Api.post('/me/calendar-connections/finish', { nonce: back.nonce }).then(function (r) {
          toast('📅', 'Calendar connected', r && r.scope === 'agency' ? 'The agency calendar now shows in My day.' : 'Your calendar now shows in My day, and your meetings go on it.', '#16A34A');
        }, function (e) { toast('⚠️', 'Calendar not connected', said(e, 'Please try again.'), '#B91C1C'); })
      : Promise.resolve(back && back.error ? toast('⚠️', 'Calendar not connected', back.error, '#B91C1C') : null);
    return first.then(function () { return Api.get('/me/calendar-connections'); }).then(function (d) {
      if (!d || !d.available) return;   // not a role that has it: no card at all
      host.appendChild(card);
      paintX(body, d);
    }).catch(function () {});
  }
  function paintX(body, d) {
    body.innerHTML = '';
    body.appendChild(el('div', { style: 'font-size:13.5px;color:#475569;line-height:1.5;margin:0 0 12px;', text:
      'Optional. Connect the calendar of a mailbox you use in Email: its events show in your My day panel, and meetings you organise in KiddieTrac go on it, with the invitations sent from your own mailbox. Nothing changes until you connect it.' }));
    var accts = (d.accounts || []).filter(function (a) { return a.signed_in; });
    function row(title, sub, link, scope) {
      var r = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #F1F5F9;flex-wrap:wrap;' });
      var left = el('div', { style: 'min-width:0;flex:1 1 220px;' }, [el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;', text: title }), el('div', { style: 'font-size:12.5px;color:#64748B;line-height:1.45;', text: sub })]);
      r.appendChild(left);
      var right = el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' });
      if (link) {
        var st = link.status === 'connected' ? ['#DCFCE7', '#166534', '● Connected'] : (link.status === 'reconnect' ? ['#FFEDD5', '#9A3412', '● Needs sign-in'] : ['#FEE2E2', '#B91C1C', '● Problem']);
        left.appendChild(el('div', { style: 'font-size:12.5px;color:#334155;margin-top:3px;' }, [
          el('span', { style: 'background:' + st[0] + ';color:' + st[1] + ';border-radius:20px;padding:1px 8px;font-size:11px;font-weight:800;margin-right:6px;', text: st[2] }),
          (link.provider === 'google' ? 'Google · ' : 'Microsoft · ') + (link.address || '')]));
        if (link.detail) left.appendChild(el('div', { style: 'font-size:12px;color:#B91C1C;margin-top:3px;', text: link.detail }));
        if (link.status !== 'connected') right.appendChild(connectBtn(scope, 'Reconnect', link.account_id));
        var off = el('button', { type: 'button', style: BTN_RED, 'data-kt-iconized': '1' }, ['Disconnect']);
        off.addEventListener('click', function () {
          var ask = KT.confirm ? KT.confirm({ title: 'Disconnect this calendar?', description: 'KiddieTrac stops reading it and stops adding meetings to it. Meetings already added stay on it. To withdraw the permission completely, remove KiddieTrac in your Microsoft or Google account settings.', okLabel: 'Disconnect', tone: 'danger' })
            : Promise.resolve(window.confirm('Disconnect this calendar?'));
          Promise.resolve(ask).then(function (ok) {
            if (!ok) return;
            off.disabled = true;
            Api.del ? Api.del('/me/calendar-connections/' + link.id).then(reload, fail) : Api.delete('/me/calendar-connections/' + link.id).then(reload, fail);
            function fail(e) { off.disabled = false; toast('⚠️', 'Could not disconnect', said(e, 'Please try again.'), '#B91C1C'); }
          });
        });
        right.appendChild(off);
      } else {
        right.appendChild(connectBtn(scope, 'Connect calendar'));
      }
      r.appendChild(right);
      return r;
    }
    function connectBtn(scope, label, preferId) {
      var b = el('button', { type: 'button', style: BTN_MAIN, 'data-kt-iconized': '1', 'data-xcal-connect': scope }, [label]);
      b.addEventListener('click', function () {
        if (!accts.length) {
          toast('✉️', 'Connect a mailbox first', 'Add your Microsoft 365 or Gmail mailbox in Email (Manage accounts), then come back here.', '#1F6080');
          return;
        }
        var go = function (id) {
          b.disabled = true; b.textContent = 'Opening…';
          Api.post('/me/calendar-connections/connect', { account_id: id, scope: scope }).then(function (r) {
            if (r && r.url) { window.location.href = r.url; return; }
            throw new Error('Could not start the sign-in.');
          }).catch(function (e) { b.disabled = false; b.textContent = label; toast('⚠️', 'Could not start', said(e, 'Please try again.'), '#B91C1C'); });
        };
        var pref = preferId && accts.filter(function (a) { return a.id === preferId; })[0];
        if (pref || accts.length === 1) { go((pref || accts[0]).id); return; }
        if (KT.quickPop) {
          KT.quickPop({ anchor: b, label: 'Which mailbox?', items: accts.map(function (a) { return { icon: a.provider === 'google' ? '🟢' : '🟦', label: a.address, sub: a.provider === 'google' ? 'Google' : 'Microsoft 365', run: function () { go(a.id); } }; }) });
        } else { go(accts[0].id); }
      });
      return b;
    }
    body.appendChild(row('My calendar', 'Only you see its events. Meetings you organise go on it.', d.personal, 'personal'));
    if (d.agency || d.can_set_agency) {
      body.appendChild(row('Agency calendar' + (d.agency_name ? ' · ' + d.agency_name : ''),
        'The agency’s own calendar (for example a shared office mailbox). Admins and directors see its events; private ones show as “Busy”. The agency’s meetings are copied onto it.',
        d.agency, 'agency'));
      if (!d.can_set_agency && !d.agency) body.lastChild.style.display = 'none';
      if (!d.can_set_agency && d.agency) { [].forEach.call(body.lastChild.querySelectorAll('button'), function (x) { x.style.display = 'none'; }); }
    }
    if (!accts.length) body.appendChild(el('div', { style: 'font-size:12.5px;color:#64748B;margin-top:8px;line-height:1.5;', text: 'You have no Microsoft 365 or Gmail mailbox signed in to Email yet. Add one in Email (Manage accounts) to connect its calendar.' }));
    body.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin-top:10px;line-height:1.5;', text: 'Microsoft or Google asks you to allow KiddieTrac to see and change the calendar. KiddieTrac reads events for the days the panel shows and keeps them for a few minutes; it never shares them with anyone else.' }));
    function reload() { Api.get('/me/calendar-connections').then(function (nd) { paintX(body, nd); }).catch(function () {}); }
  }

  function render(host, o) {
    o = o || {};
    var kind = o.role === 'guardian' ? 'parent' : 'staff';
    var CARD = o.CARD || 'background:#fff;border-radius:16px;padding:16px;margin-bottom:14px;';
    var SECT = o.SECT || 'font-size:16px;font-weight:800;color:#0f172a;margin:0 0 10px;';
    host.innerHTML = '';
    var card = el('div', { style: CARD, 'data-kt-nofit': '1', class: 'kt-cal-sync' });
    card.appendChild(el('div', { style: SECT, text: 'Add to my calendar' }));
    var body = el('div', {}, [el('div', { style: 'color:#64748B;font-size:13px;padding:6px 0;', text: 'Loading…' })]);
    card.appendChild(body);
    host.appendChild(card);
    if (kind === 'staff') renderExternal(host, o);   // Outlook / Google (2026-11-25): only for roles the server says

    function load() {
      return Api.get('/me/calendar-feed?as=' + kind).then(paint).catch(function (e) {
        body.innerHTML = '';
        body.appendChild(el('div', { style: 'color:#B91C1C;font-size:13.5px;padding:6px 0;', text: said(e, 'Could not load your calendar link. Please try again.') }));
      });
    }

    function paint(d) {
      body.innerHTML = '';
      if (!d || !d.available) {
        body.appendChild(el('div', { style: 'font-size:13.5px;color:#475569;line-height:1.5;padding:4px 0;', text: d && d.reason === 'off'
          ? 'Calendar sync is turned off for your centre. Ask the office if you would like it.'
          : (kind === 'parent' ? 'Your calendar link appears here once your child is enrolled.' : 'Your calendar link appears here once you are assigned to a centre.') }));
        return;
      }
      var u = d.urls || {};
      body.appendChild(el('div', { style: 'font-size:13.5px;color:#475569;line-height:1.5;margin:0 0 12px;', text: kind === 'parent'
        ? 'See closures, events, trips and your child’s days away in your own calendar. It updates by itself. The link is private to you: anyone who has it can see these dates, so only share it with family.'
        : 'See your centre’s closures and events, your shifts and your time off in your own calendar. It updates by itself. The link is private to you.' }));

      // ── the buttons: the platform's own calendar first ──
      var row = el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px;' });
      var help = el('div', { style: 'display:none;margin:0 0 12px;padding:11px 13px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC;font-size:13px;color:#334155;line-height:1.55;' });
      function showHelp(which) {
        var t = {
          google: [
            'Google Calendar',
            'The Google button opens “Add calendar” on Google Calendar in your browser. Tap Add, and it shows on your phone too.',
            'On a phone, Google sometimes only adds a calendar link from a computer: open calendar.google.com, choose Other calendars → + → From URL, paste your link and choose Add calendar.',
            'Google checks the link a few times a day, so a change can take some hours to appear.'
          ],
          apple: [
            'Apple Calendar (iPhone, iPad, Mac)',
            'Tap Apple Calendar, then Subscribe. If nothing opens, copy the link, then on iPhone go to Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar and paste it.'
          ],
          outlook: [
            'Outlook',
            'The Outlook button opens Outlook on the web for a personal account (outlook.com, Hotmail). For a work or school account, open Outlook, choose Add calendar → Subscribe from web, paste your link and name it.'
          ]
        }[which];
        help.innerHTML = '';
        help.appendChild(el('div', { style: 'font-weight:800;color:#0f172a;margin:0 0 4px;', text: t[0] }));
        t.slice(1).forEach(function (p) { help.appendChild(el('div', { style: 'margin:0 0 4px;', text: p })); });
        help.style.display = '';
      }
      var apple = el('a', { href: u.webcal, style: BTN_MAIN, 'data-kt-iconized': '1', 'data-kt-cal': 'apple' }, ['Apple Calendar']);
      apple.addEventListener('click', function (ev) {
        showHelp('apple');
        if (native()) { ev.preventDefault(); try { window.location.href = u.webcal; } catch (e) {} }
      });
      var google = el('button', { type: 'button', style: BTN_MAIN, 'data-kt-iconized': '1', 'data-kt-cal': 'google' }, ['Google Calendar']);
      google.addEventListener('click', function () { showHelp('google'); openOut(u.google); });
      var outlook = el('button', { type: 'button', style: BTN_SOFT, 'data-kt-iconized': '1', 'data-kt-cal': 'outlook' }, ['Outlook']);
      outlook.addEventListener('click', function () { showHelp('outlook'); openOut(u.outlook); });
      var copy = el('button', { type: 'button', style: BTN_SOFT, 'data-kt-iconized': '1', 'data-kt-cal': 'copy' }, ['Copy link']);
      copy.addEventListener('click', function () {
        copyText(u.https).then(function () {
          copy.textContent = '✓ Copied'; setTimeout(function () { copy.textContent = 'Copy link'; }, 2200);
          toast('📋', 'Link copied', 'Paste it into your calendar app’s “subscribe” or “from URL” option.', '#1F6080');
        }, function () { field.select(); toast('⚠️', 'Could not copy', 'Select the link and copy it yourself.', '#B91C1C'); });
      });
      var p = platform();
      var order = p === 'android' ? [google, outlook, apple, copy] : (p === 'ios' || p === 'mac') ? [apple, google, outlook, copy] : [google, outlook, apple, copy];
      order.forEach(function (b) { row.appendChild(b); });
      // Primary looks only for the first one; the rest are quieter.
      order.forEach(function (b, i) { if (b !== copy && b !== outlook) b.setAttribute('style', i === 0 ? BTN_MAIN : BTN_SOFT); });
      body.appendChild(row);
      body.appendChild(help);

      var field = el('input', { type: 'text', readonly: 'readonly', 'aria-label': 'Your private calendar link', 'data-kt-nospell': '1', inputmode: 'none',
        style: 'width:100%;box-sizing:border-box;height:32px;min-height:32px;padding:0 10px;font-size:12.5px;font-family:ui-monospace,Menlo,Consolas,monospace;color:#334155;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;' });
      field.value = u.https || '';
      field.addEventListener('click', function () { try { field.select(); } catch (e) {} });
      body.appendChild(field);
      var meta = el('div', { style: 'font-size:12px;color:#64748B;margin:5px 0 4px;min-height:15px;' });
      var bits = [];
      if (d.agencies && d.agencies.length > 1) bits.push('Includes ' + d.agencies.join(' and ') + '.');
      bits.push(d.last_polled_at ? 'Last checked by a calendar app: ' + when(d.last_polled_at) : 'Not added to a calendar yet.');
      meta.textContent = bits.join(' ');
      body.appendChild(meta);

      // ── what it shows ──
      body.appendChild(el('div', { style: 'font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin:14px 0 2px;', text: 'What it shows' }));
      var opts = d.options || {};
      (LABELS[kind] || []).forEach(function (L) {
        var r = el('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #F1F5F9;' });
        r.appendChild(el('div', { style: 'min-width:0;' }, [
          el('div', { style: 'font-weight:700;font-size:14px;color:#0f172a;', text: L[1] }),
          el('div', { style: 'font-size:12px;color:#64748B;', text: L[2] })
        ]));
        var sw = o.mkSwitch ? o.mkSwitch() : null;
        if (!sw) return;
        sw.setAttribute('aria-label', L[1]);
        sw.setAttribute('data-kt-cal-opt', L[0]);
        sw.kt_set(!!opts[L[0]]);
        sw.addEventListener('click', function () {
          if (sw.disabled) return;
          var next = sw.getAttribute('aria-pressed') !== 'true';
          sw.kt_set(next); sw.kt_disabled(true);
          var payload = { as: kind, options: {} }; payload.options[L[0]] = next;
          Api.patch('/me/calendar-feed', payload).then(function () {
            sw.kt_disabled(false);
            toast('✓', 'Saved', 'Your calendar updates the next time your calendar app checks.', '#16A34A');
          }).catch(function (e) {
            sw.kt_disabled(false); sw.kt_set(!next);
            toast('⚠️', 'Could not save', said(e, 'Please try again.'), '#B91C1C');
          });
        });
        r.appendChild(sw);
        body.appendChild(r);
      });
      body.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin:8px 0 0;line-height:1.5;', text: 'Shows the last ' + ((d.window && d.window.back_days) || 30) + ' days and the next ' + ((d.window && d.window.ahead_months) || 12) + ' months, in your centre’s time zone.' }));

      // ── reset ──
      var reset = el('button', { type: 'button', style: BTN_RED + 'margin-top:14px;', 'data-kt-iconized': '1', 'data-kt-cal': 'reset' }, ['Reset link']);
      reset.addEventListener('click', function () {
        var ask = KT.confirm ? KT.confirm({ title: 'Reset your calendar link?', description: 'Your current link stops working straight away, in every calendar it was added to. You will get a new link to add again.', okLabel: 'Reset link', tone: 'danger' })
          : Promise.resolve(window.confirm('Reset your calendar link? The current link stops working straight away.'));
        Promise.resolve(ask).then(function (ok) {
          if (!ok) return;
          reset.disabled = true; reset.textContent = 'Resetting…';
          Api.post('/me/calendar-feed/reset', { as: kind }).then(function (nd) {
            toast('🔄', 'New link made', 'The old link has stopped. Add the new one to your calendar.', '#1F6080');
            paint(nd);
          }).catch(function (e) {
            reset.disabled = false; reset.textContent = 'Reset link';
            toast('⚠️', 'Could not reset', said(e, 'Please try again.'), '#B91C1C');
          });
        });
      });
      body.appendChild(reset);
      body.appendChild(el('div', { style: 'font-size:12px;color:#64748B;margin:6px 0 0;', text: 'Use this if the link was shared with someone who should not have it.' }));
    }

    return load();
  }

  KT.calSync = { render: render };
})(window);
