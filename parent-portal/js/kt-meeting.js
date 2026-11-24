/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — MEETINGS, LIKE OUTLOOK (2026-10-25).

   Anthony: "add ability to add a meeting in the calendar with adding others, teams meeting etc
   (like how outlook does it). Under bookings add the ability to add a meeting from there as well
   with teams". One composer and one view for every calendar:

     KT.Meeting.compose({ scope: 'agency'|'platform', date, start_time, end_time, title,
                          attendees: [{email,name,user_id,kind}], booking_id, lead_id,
                          meeting (to edit), onSaved(meeting) })
     KT.Meeting.view(idOrMeeting, { onChange() })

   Platform (Sales Calendar, Bookings): a Teams meeting is made on the sales@ calendar.
   Agency (the agency Calendar): paste the Teams / Zoom / Google Meet link.
   Every attendee gets an email invitation with Accept / Maybe / Decline and a calendar file;
   changes and cancellations reach them too (MeetingController / App\Support\Meetings).
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  if (w.KT_MEETING) return; w.KT_MEETING = true;
  var KT = (w.KT = w.KT || {});
  var d = document;

  function el(tag, attrs, kids) {
    var n = d.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'style') n.style.cssText = v;
      else if (k === 'html') n.innerHTML = v;
      else if (k.slice(0, 2) === 'on' && typeof v === 'function') n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c == null) return; n.appendChild(typeof c === 'string' ? d.createTextNode(c) : c); });
    return n;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(i, t, m, c) { if (KT.toast) KT.toast(i, t, m || '', c); }
  function api() { return KT.Api; }
  function said(e, fb) { var m = (e && e.message) || ''; return m && !/fetch|network|undefined|null|\bat\b/i.test(m) ? m : fb; }

  var CSS = ''
    + '.ktm{display:flex;flex-direction:column;gap:12px}'
    + '.ktm label.ktm-l{display:block;font-size:11.5px;font-weight:800;letter-spacing:.3px;color:#475569;margin:0 0 4px}'
    + '.ktm input[type=text],.ktm input[type=url],.ktm input[type=date],.ktm input[type=time],.ktm select,.ktm textarea{width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:0 10px;font:inherit;font-size:13.5px;background:#fff;color:#0F172A}'
    + '.ktm textarea{padding:8px 10px;min-height:84px;resize:vertical}'
    + '.ktm .ktm-title{font-size:16px!important;font-weight:700}'
    + '.ktm-row{display:grid;gap:10px;align-items:end}'
    + '.ktm-when{grid-template-columns:minmax(140px,1.3fr) minmax(110px,1fr) minmax(110px,1fr) auto}'
    + '.ktm-two{grid-template-columns:1fr 1fr}'
    + '@media (max-width:640px){.ktm-when{grid-template-columns:1fr 1fr}.ktm-two{grid-template-columns:1fr}}'
    + '.ktm-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid #CBD5E1;border-radius:10px;padding:5px 6px;min-height:40px;box-sizing:border-box;background:#fff;position:relative}'
    + '.ktm-chip{display:inline-flex;align-items:center;gap:6px;background:#EEF4F8;border:1px solid #D5E3EC;border-radius:999px;padding:2px 4px 2px 9px;font-size:12.5px;color:#0F172A;max-width:100%}'
    + '.ktm-chip.opt{background:#fff;border-style:dashed}'
    + '.ktm-chip b{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}'
    + '.ktm-chip small{color:#64748B}'
    + '.ktm-chip button{border:0;background:none;cursor:pointer;color:#64748B;font-size:14px;line-height:1;padding:2px 5px;border-radius:999px}'
    + '.ktm-chip button:hover{background:#DCE7EF;color:#0F172A}'
    + '.ktm-chips input{flex:1 1 180px;border:0!important;outline:0;min-width:140px;height:28px!important;min-height:28px!important;padding:0 4px!important;font-size:13.5px;background:transparent}'
    + '.ktm-res{position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 14px 30px -12px rgba(15,23,42,.35);max-height:240px;overflow:auto;z-index:30}'
    + '.ktm-res button{display:flex;gap:8px;align-items:center;width:100%;text-align:left;border:0;border-bottom:1px solid #F1F5F9;background:#fff;padding:7px 10px;cursor:pointer;font:inherit}'
    + '.ktm-res button:hover,.ktm-res button.on{background:#F1F5F9}'
    + '.ktm-res b{font-size:13px;color:#0F172A}.ktm-res small{display:block;font-size:11.5px;color:#64748B}'
    + '.ktm-k{font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;border-radius:999px;padding:1px 7px;flex:0 0 auto}'
    + '.ktm-seg{display:inline-flex;border:1px solid #CBD5E1;border-radius:9px;overflow:hidden}'
    + '.ktm-seg button{border:0;background:#fff;padding:0 12px;height:32px;font:inherit;font-size:13px;font-weight:700;color:#334155;cursor:pointer;border-right:1px solid #E2E8F0}'
    + '.ktm-seg button:last-child{border-right:0}.ktm-seg button.on{background:#1F6080;color:#fff}'
    + '.ktm-dur{display:flex;gap:6px;flex-wrap:wrap}.ktm-dur button{height:26px;padding:0 9px;border:1px solid #CBD5E1;background:#fff;border-radius:999px;font-size:12px;font-weight:700;color:#475569;cursor:pointer}'
    + '.ktm-dur button.on{border-color:#1F6080;color:#1F6080;background:#EEF6FA}'
    + '.ktm-note{font-size:12px;color:#64748B;line-height:1.5}'
    + '.ktm-v .ktm-who{display:flex;align-items:center;gap:8px;padding:6px 0;border-top:1px solid #F1F5F9;font-size:13.5px}'
    + '.ktm-v .ktm-dot{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;flex:0 0 auto}';
  function css() { if (!d.getElementById('kt-meeting-css')) { var s = el('style', { id: 'kt-meeting-css' }); s.textContent = CSS; d.head.appendChild(s); } }

  var KIND = { staff: ['Staff', '#E0F2FE', '#075985'], parent: ['Parent', '#FCE7F3', '#9D174D'], lead: ['Lead', '#FEF3C7', '#92400E'], contact: ['Contact', '#EDE9FE', '#5B21B6'], guest: ['Guest', '#F1F5F9', '#475569'] };
  var RESP = { accepted: ['✓', '#DCFCE7', '#15803D', 'Accepted'], tentative: ['?', '#FEF3C7', '#B45309', 'Maybe'], declined: ['✕', '#FEE2E2', '#B91C1C', 'Declined'], pending: ['…', '#F1F5F9', '#64748B', 'No reply yet'] };
  function kindTag(k) { var x = KIND[k] || KIND.guest; return el('span', { class: 'ktm-k', style: 'background:' + x[1] + ';color:' + x[2] }, [x[0]]); }

  function pad(n) { return ('0' + n).slice(-2); }
  function addMin(hm, mins) { var p = hm.split(':'), t = (+p[0]) * 60 + (+p[1]) + mins; t = ((t % 1440) + 1440) % 1440; return pad(Math.floor(t / 60)) + ':' + pad(t % 60); }
  function diffMin(a, b) { var x = a.split(':'), y = b.split(':'); return (+y[0] * 60 + +y[1]) - (+x[0] * 60 + +x[1]); }
  function nextHalfHour() { var n = new Date(), m = n.getHours() * 60 + n.getMinutes(); m = Math.ceil((m + 1) / 30) * 30; if (m >= 1440) m = 1410; return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
  function today() { return KT.agencyToday ? KT.agencyToday() : new Date().toISOString().slice(0, 10); }

  /* ── The composer ────────────────────────────────────────────────── */
  function compose(o) {
    css(); o = o || {};
    var M = KT.Shell && KT.Shell.Modal; if (!M) return;
    var mt = o.meeting || null, isEdit = !!mt;
    var scope = mt ? mt.scope : (o.scope || 'agency');
    var people = (mt ? mt.attendees : (o.attendees || [])).map(function (a) { return { email: a.email, name: a.name || '', user_id: a.user_id || null, kind: a.kind || 'guest', optional: !!a.optional, response: a.response }; });

    var title = el('input', { type: 'text', class: 'ktm-title', maxlength: '200', placeholder: 'Add a title', value: mt ? mt.title : (o.title || '') });
    var chips = el('div', { class: 'ktm-chips', 'data-kt-nopack': '1' });
    var who = el('input', { type: 'text', autocomplete: 'off', placeholder: 'Invite people: type a name or an email address', 'aria-label': 'Invite people' });
    var res = el('div', { class: 'ktm-res', hidden: true });
    var start = o.start_time || nextHalfHour();
    var date = el('input', { type: 'date', value: mt ? mt.date : (o.date || today()) });
    var t1 = el('input', { type: 'time', step: '300', value: mt ? mt.start_time : start });
    var t2 = el('input', { type: 'time', step: '300', value: mt ? mt.end_time : (o.end_time || addMin(start, 30)) });
    var allDay = el('input', { type: 'checkbox' }); allDay.checked = mt ? mt.all_day : false;
    var endDate = el('input', { type: 'date', value: mt ? mt.end_date : '' });
    var dur = el('div', { class: 'ktm-dur' });
    var videoMode = mt ? mt.video : (scope === 'platform' ? 'teams' : 'none');
    var seg = el('div', { class: 'ktm-seg', role: 'group', 'aria-label': 'Online meeting' });
    var link = el('input', { type: 'url', placeholder: scope === 'platform' ? 'https://teams.microsoft.com/…  or a Zoom / Meet link' : 'Paste your Teams, Zoom or Google Meet link (https://…)', value: mt && mt.video === 'link' ? (mt.join_url || '') : '' });
    var linkWrap = el('div', {}, [link]);
    var teamsNote = el('div', { class: 'ktm-note' });
    var loc = el('input', { type: 'text', maxlength: '255', placeholder: 'Room, address or "Phone call" (optional)', value: mt ? (mt.location || '') : '' });
    var desc = el('textarea', { maxlength: '5000', placeholder: 'Agenda or notes for everyone invited (optional)' }); desc.value = mt ? (mt.description || '') : '';
    var send = el('input', { type: 'checkbox' }); send.checked = true;
    var msg = el('div', { style: 'font-size:12.5px;color:#B91C1C;min-height:0' });

    function paintChips() {
      [].slice.call(chips.querySelectorAll('.ktm-chip')).forEach(function (c) { c.remove(); });
      people.forEach(function (p, i) {
        var c = el('span', { class: 'ktm-chip' + (p.optional ? ' opt' : ''), title: p.email + (p.optional ? ' · optional' : ' · required') + ' — click the name to switch' }, [
          kindTag(p.kind), el('b', { style: 'cursor:pointer', onclick: function () { p.optional = !p.optional; paintChips(); } }, [p.name || p.email]),
          p.optional ? el('small', {}, ['optional']) : null,
          el('button', { type: 'button', 'aria-label': 'Remove ' + (p.name || p.email), onclick: function () { people.splice(i, 1); paintChips(); who.focus(); } }, ['×']),
        ]);
        chips.insertBefore(c, who);
      });
    }
    chips.appendChild(who); chips.appendChild(res);
    function addPerson(p) {
      var e = String(p.email || '').trim().toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) { msg.textContent = 'That is not an email address.'; return false; }
      if (people.some(function (x) { return x.email === e; })) { who.value = ''; res.hidden = true; return true; }
      people.push({ email: e, name: p.name || '', user_id: p.user_id || null, kind: p.kind || 'guest', optional: false });
      msg.textContent = ''; who.value = ''; res.hidden = true; paintChips(); return true;
    }
    var tSearch = null, rows = [], hi = -1;
    function search() {
      clearTimeout(tSearch);
      var q = who.value.trim();
      tSearch = setTimeout(function () {
        api().get('/meetings/people?scope=' + scope + '&q=' + encodeURIComponent(q)).then(function (j) {
          rows = (j.people || []).filter(function (p) { return !people.some(function (x) { return x.email === p.email; }); }).slice(0, 25);
          res.innerHTML = ''; hi = -1;
          if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(q) && !rows.some(function (r) { return r.email === q.toLowerCase(); })) rows.unshift({ email: q.toLowerCase(), name: '', kind: 'guest', sub: 'Invite this address' });
          if (!rows.length) { res.hidden = true; return; }
          rows.forEach(function (r, i) {
            res.appendChild(el('button', { type: 'button', onmousedown: function (ev) { ev.preventDefault(); addPerson(r); }, 'data-i': String(i) }, [
              kindTag(r.kind), el('span', { style: 'min-width:0' }, [el('b', {}, [r.name || r.email]), el('small', {}, [(r.name ? r.email : '') + (r.sub ? (r.name ? ' · ' : '') + r.sub : '')])]),
            ]));
          });
          res.hidden = false;
        }).catch(function () { res.hidden = true; });
      }, 220);
    }
    who.addEventListener('input', search);
    who.addEventListener('focus', search);
    who.addEventListener('blur', function () { setTimeout(function () { res.hidden = true; }, 150); });
    who.addEventListener('keydown', function (e) {
      var btns = res.hidden ? [] : res.querySelectorAll('button');
      if (e.key === 'ArrowDown' && btns.length) { e.preventDefault(); hi = Math.min(btns.length - 1, hi + 1); }
      else if (e.key === 'ArrowUp' && btns.length) { e.preventDefault(); hi = Math.max(0, hi - 1); }
      else if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
        if (hi >= 0 && rows[hi]) { e.preventDefault(); addPerson(rows[hi]); return; }
        if (who.value.trim()) { e.preventDefault(); addPerson({ email: who.value.trim() }); return; }
        return;
      } else if (e.key === 'Backspace' && !who.value && people.length) { people.pop(); paintChips(); return; }
      else return;
      [].forEach.call(btns, function (b, i) { b.classList.toggle('on', i === hi); });
    });
    who.addEventListener('paste', function (e) {
      var t = (e.clipboardData || w.clipboardData).getData('text') || '';
      var found = t.match(/[^\s@,;<>"]+@[^\s@,;<>"]+\.[^\s@,;<>"]+/g);
      if (found && found.length > 1) { e.preventDefault(); found.forEach(function (x) { addPerson({ email: x }); }); }
    });
    chips.addEventListener('click', function (e) { if (e.target === chips) who.focus(); });

    [15, 30, 45, 60, 90].forEach(function (m) {
      dur.appendChild(el('button', { type: 'button', 'data-m': String(m), onclick: function () { t2.value = addMin(t1.value || '09:00', m); paintDur(); } }, [m < 60 ? m + ' min' : (m / 60) + (m === 60 ? ' hour' : ' hours')]));
    });
    function paintDur() { var m = diffMin(t1.value || '00:00', t2.value || '00:00'); [].forEach.call(dur.children, function (b) { b.classList.toggle('on', +b.getAttribute('data-m') === m); }); }
    var lastLen = diffMin(t1.value, t2.value) > 0 ? diffMin(t1.value, t2.value) : 30;
    t1.addEventListener('change', function () { t2.value = addMin(t1.value, lastLen); paintDur(); });
    t2.addEventListener('change', function () { var m = diffMin(t1.value, t2.value); if (m > 0) lastLen = m; paintDur(); });
    paintDur();

    var whenRow = el('div', { class: 'ktm-row ktm-when', 'data-kt-nopack': '1' }, [
      el('div', {}, [el('label', { class: 'ktm-l' }, ['Date']), date]),
      el('div', { class: 'ktm-t' }, [el('label', { class: 'ktm-l' }, ['Start']), t1]),
      el('div', { class: 'ktm-t' }, [el('label', { class: 'ktm-l' }, ['End']), t2]),
      el('label', { style: 'display:flex;gap:6px;align-items:center;font-size:13px;font-weight:700;color:#334155;height:32px;white-space:nowrap' }, [allDay, 'All day']),
    ]);
    var untilRow = el('div', { style: 'max-width:220px' }, [el('label', { class: 'ktm-l' }, ['Until (for a meeting over several days)']), endDate]);
    function paintAllDay() {
      [].forEach.call(whenRow.querySelectorAll('.ktm-t'), function (x) { x.style.visibility = allDay.checked ? 'hidden' : ''; });
      dur.style.display = allDay.checked ? 'none' : '';
      untilRow.style.display = allDay.checked ? '' : 'none';
    }
    allDay.addEventListener('change', paintAllDay);

    var MODES = scope === 'platform' ? [['teams', '🟣 Teams meeting'], ['link', '🔗 Other link'], ['none', 'In person / phone']] : [['link', '🔗 Teams / Zoom / Meet link'], ['none', 'In person / phone']];
    if (scope !== 'platform' && videoMode === 'teams') videoMode = 'link';
    MODES.forEach(function (m) { seg.appendChild(el('button', { type: 'button', 'data-v': m[0], onclick: function () { videoMode = m[0]; paintVideo(); } }, [m[1]])); });
    function paintVideo() {
      [].forEach.call(seg.children, function (b) { b.classList.toggle('on', b.getAttribute('data-v') === videoMode); });
      linkWrap.style.display = videoMode === 'link' ? '' : 'none';
      teamsNote.textContent = videoMode === 'teams' ? (isEdit && mt.video === 'teams' && mt.join_url ? 'The Teams link stays the same.' : 'A Microsoft Teams link is created when you send, and goes in every invitation.') : '';
    }
    paintVideo();

    var body = el('div', { class: 'ktm', 'data-kt-nogrid': '1', 'data-kt-nopack': '1', 'data-kt-nofit': '1' }, [
      title,
      el('div', {}, [el('label', { class: 'ktm-l' }, ['Attendees']), chips, el('div', { class: 'ktm-note', style: 'margin-top:4px' }, [scope === 'platform' ? 'KiddieTrac team, leads and contacts — or any email address. Click a name to make it optional.' : 'Your staff and families — or any email address. Click a name to make it optional.'])]),
      whenRow, dur, untilRow,
      el('div', {}, [el('label', { class: 'ktm-l' }, ['Online meeting']), seg, el('div', { style: 'margin-top:8px' }, [linkWrap, teamsNote])]),
      el('div', {}, [el('label', { class: 'ktm-l' }, ['Location']), loc]),
      el('div', {}, [el('label', { class: 'ktm-l' }, ['Details']), desc]),
      el('label', { style: 'display:flex;gap:8px;align-items:center;font-size:13px;color:#334155' }, [send, isEdit ? 'Email the changes to everyone invited' : 'Email the invitation to everyone invited']),
      msg,
    ]);
    paintChips(); paintAllDay();

    M.open({ title: isEdit ? '✏️ Edit meeting' : '🤝 New meeting', body: body, large: true, actions: [
      { label: 'Cancel' },
      { label: isEdit ? 'Save & update' : 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
        if (who.value.trim()) addPerson({ email: who.value.trim() });
        msg.textContent = '';
        if (!title.value.trim()) { msg.textContent = 'Add a title.'; title.focus(); return false; }
        if (!date.value) { msg.textContent = 'Choose a date.'; return false; }
        if (!allDay.checked && diffMin(t1.value, t2.value) <= 0) { msg.textContent = 'The meeting has to end after it starts.'; return false; }
        if (videoMode === 'link' && !/^https:\/\/\S+$/i.test(link.value.trim())) { msg.textContent = 'Paste the full meeting link, starting with https://.'; link.focus(); return false; }
        var payload = { scope: scope, title: title.value.trim(), date: date.value, end_date: allDay.checked ? (endDate.value || date.value) : date.value,
          start_time: allDay.checked ? null : t1.value, end_time: allDay.checked ? null : t2.value, all_day: allDay.checked,
          video: videoMode, join_url: videoMode === 'link' ? link.value.trim() : null, location: loc.value.trim() || null, description: desc.value.trim() || null,
          attendees: people.map(function (p) { return { email: p.email, name: p.name || null, user_id: p.user_id || null, kind: p.kind, optional: p.optional }; }),
          booking_id: o.booking_id || null, lead_id: o.lead_id || null, send: send.checked };
        var call = isEdit ? api().put('/meetings/' + mt.id, payload) : api().post('/meetings', payload);
        return call.then(function (r) {
          // The emails and the Teams link go from the server's queue within the minute (2026-11-13); a problem lands on the bell.
          var n = r && r.queued;
          toast(isEdit ? '✅' : '📨', isEdit ? 'Meeting updated' : 'Meeting scheduled',
            (send.checked ? (n ? 'Emailing ' + n + (n === 1 ? ' person' : ' people') + ' now' : 'No one to email') : 'Saved without emails')
            + (r && r.teams_pending ? ' · the Teams link is added in a minute' : ''));
          if (o.onSaved) o.onSaved(r && r.meeting);
        }).catch(function (e) { msg.textContent = said(e, 'The meeting could not be saved. Please try again.'); return false; });
      } },
    ] });
    setTimeout(function () { try { title.focus(); } catch (e) {} }, 60);
  }

  /* ── The meeting, opened from a calendar ─────────────────────────── */
  function view(idOrM, o) {
    css(); o = o || {};
    var M = KT.Shell && KT.Shell.Modal; if (!M) return;
    var id = typeof idOrM === 'object' ? idOrM.id : idOrM;
    api().get('/meetings/' + id).then(function (j) {
      var m = j.meeting, cancelled = m.status === 'cancelled';
      var box = el('div', { class: 'ktm-v', 'data-kt-nogrid': '1' });
      box.appendChild(el('div', { style: 'font-size:13.5px;color:#334155;line-height:1.7' }, [
        el('div', {}, ['🗓 ', el('b', {}, [m.when])]),
        m.location ? el('div', {}, ['📍 ' + m.location]) : null,
        el('div', {}, ['👤 Organiser: ' + ((m.organizer && m.organizer.name) || '—')]),
        cancelled ? el('div', { style: 'color:#B91C1C;font-weight:800' }, ['Cancelled' + (m.cancel_reason ? ': ' + m.cancel_reason : '')]) : null,
      ]));
      if (m.join_url && !cancelled) {
        box.appendChild(el('a', { href: m.join_url, target: '_blank', rel: 'noopener', style: 'display:inline-flex;align-items:center;gap:6px;margin:10px 0 4px;padding:0 14px;height:34px;border-radius:9px;background:' + (m.video === 'teams' ? '#4F52B2' : '#1F6080') + ';color:#fff;font-weight:800;font-size:13.5px;text-decoration:none' },
          [m.video === 'teams' ? '🟣 Join Teams meeting' : '🔗 Join the meeting']));
      }
      if (m.description) box.appendChild(el('div', { style: 'white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:6px 12px;margin:10px 0;font-size:13.5px;color:#334155' }, [m.description]));
      var c = m.counts || {};
      box.appendChild(el('div', { style: 'font-size:11.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#64748B;margin:12px 0 4px' },
        ['Attendees · ' + (m.attendees || []).length + (c.accepted ? ' · ' + c.accepted + ' accepted' : '') + (c.tentative ? ' · ' + c.tentative + ' maybe' : '') + (c.declined ? ' · ' + c.declined + ' declined' : '')]));
      (m.attendees || []).forEach(function (a) {
        var r = RESP[a.response] || RESP.pending;
        box.appendChild(el('div', { class: 'ktm-who' }, [el('span', { class: 'ktm-dot', title: r[3], style: 'background:' + r[1] + ';color:' + r[2] }, [r[0]]),
          el('span', { style: 'flex:1;min-width:0' }, [el('b', {}, [a.name || a.email]), a.name ? el('span', { style: 'color:#64748B;font-size:12px' }, [' · ' + a.email]) : null, a.optional ? el('span', { style: 'color:#64748B;font-size:12px' }, [' · optional']) : null]),
          kindTag(a.kind), el('span', { style: 'font-size:12px;color:' + r[2] + ';font-weight:700;min-width:84px;text-align:right' }, [r[3]])]));
      });
      if (!(m.attendees || []).length) box.appendChild(el('div', { style: 'font-size:13px;color:#64748B' }, ['No one else is invited.']));
      if (m.my_response !== null && m.my_response !== undefined && !cancelled) {
        var mine = el('div', { style: 'display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap' }, [el('span', { style: 'font-size:13px;font-weight:700;color:#334155' }, ['Your answer:'])]);
        [['accepted', '✓ Accept'], ['tentative', '? Maybe'], ['declined', '✕ Decline']].forEach(function (x) {
          mine.appendChild(el('button', { type: 'button', style: 'height:30px;padding:0 12px;border-radius:8px;font-weight:700;cursor:pointer;border:1px solid ' + (m.my_response === x[0] ? '#1F6080' : '#CBD5E1') + ';background:' + (m.my_response === x[0] ? '#1F6080' : '#fff') + ';color:' + (m.my_response === x[0] ? '#fff' : '#334155'),
            onclick: function () { api().post('/meetings/' + m.id + '/respond', { response: x[0] }).then(function () { toast('✅', 'Answer sent', x[1].slice(2)); view(m.id, o); if (o.onChange) o.onChange(); }).catch(function (e) { toast('⚠️', 'Not saved', said(e, 'Please try again.'), '#B91C1C'); }); } }, [x[1]]));
        });
        box.appendChild(mine);
      }
      var actions = [{ label: 'Close' }];
      if (j.meeting.can_change && !cancelled) {
        actions.unshift({ label: '🚫 Cancel meeting', style: 'btn-secondary', handler: function () {
          var ask = KT.prompt ? KT.prompt({ title: 'Cancel this meeting?', description: 'Everyone invited is told, and it comes off their calendars.', okLabel: 'Cancel meeting', cancelLabel: 'Keep it',
              fields: [{ key: 'value', label: 'Reason (optional, goes in the email)', placeholder: 'e.g. Moved to next week' }] })
            : Promise.resolve(w.confirm('Cancel this meeting? Everyone invited is told.') ? '' : null);
          return Promise.resolve(ask).then(function (reason) {
            if (reason === null || reason === undefined || reason === false) return false;
            return api().post('/meetings/' + m.id + '/cancel', { reason: typeof reason === 'string' ? reason : '' }).then(function (r) {
              toast('🚫', 'Meeting cancelled', r && r.queued ? 'Telling ' + r.queued + (r.queued === 1 ? ' person' : ' people') + ' now' : ''); if (o.onChange) o.onChange();
            });
          });
        } });
        actions.unshift({ label: '✏️ Edit', style: 'btn-secondary', handler: function () { setTimeout(function () { compose({ meeting: m, onSaved: function () { if (o.onChange) o.onChange(); } }); }, 0); } });
      }
      M.open({ title: (cancelled ? '🚫 ' : '🤝 ') + m.title, body: box, large: true, actions: actions });
    }).catch(function (e) { toast('⚠️', 'Could not open the meeting', said(e, 'Please try again.'), '#B91C1C'); });
  }

  KT.Meeting = { compose: compose, view: view };
})(window);
