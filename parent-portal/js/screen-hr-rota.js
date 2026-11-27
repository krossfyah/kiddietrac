/* ═══════════════════════════════════════════════════════════════════════
   KiddieTrac HR Phase 4, batch 8 (2026-11-27): window.KTHrRota — a lazy dependency of screen-schedule.js,
   screen-hr.js and screen-hr-talent.js.

   Rota & shifts (kt-hr-sections.js section "rota"):
     Schedule tab (screen-schedule.js)  drafts laid over the published week (Draft / Changed - not published / To be
                                        removed), Publish, Copy (last week, a person's week, a day onto other days),
                                        shift presets in the shift form, acceptance marks, the week against the budget
     Shift tools  rotatools             the publish step + "staff must accept", shift presets, weekly labour budgets
                                        (pay viewers only), shifts waiting for staff to accept
   Clock-ins & timesheets (section "time"):
     Clock-in fixes  punchfix           staff requests to fix a clock-in; a director / admin approves (never their own)
   My HR: "Shifts to accept" and "Fix my clock-in".
   The server decides everything (App\Support\HrRota, HrRotaController, HrPunchFixController). Without the HR add-on
   none of this loads anything: screen-schedule.js behaves exactly as before.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function saved(m) { toast('✅', 'Saved', m || '', '#16A34A'); }
  function errMsg(e) {
    var m = (e && ((e.data && e.data.message) || e.message)) || '';
    return m && !/fetch|network|undefined|null|status \d/i.test(m) ? m : 'That did not work. Please try again.';
  }
  function fail(t, e) { toast('⚠️', t, errMsg(e), '#DC2626'); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  function pd(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function addDays(s, n) { var d = pd(s); d.setDate(d.getDate() + n); return ymd(d); }
  function wday(s) { var d = pd(s); return d ? DOW[d.getDay()] + ', ' + MON[d.getMonth()] + ' ' + d.getDate() : ''; }
  function t12(hm) { var m = /^(\d{1,2}):(\d{2})/.exec(hm || ''); if (!m) return hm || ''; var h = +m[1]; return (h % 12 || 12) + ':' + m[2] + (h < 12 ? ' AM' : ' PM'); }
  function span(a, b) { return t12(a) + ' – ' + t12(b); }
  function money(n) { var v = Number(n) || 0; return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function hrs(n) { var v = Math.round((Number(n) || 0) * 100) / 100; return v + ' h'; }
  function hh(min) { if (min == null) return '—'; var m = Math.round(min), h = Math.floor(Math.abs(m) / 60), r = Math.abs(m) % 60; return (m < 0 ? '-' : '') + h + 'h' + (r ? ' ' + String(r).padStart(2, '0') + 'm' : ''); }
  function pill(bg, fg, t) { return '<span data-kt-no-tone style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' + bg + ';color:' + fg + ';white-space:nowrap;">' + esc(t) + '</span>'; }
  function modal(o) { return KT.Shell.Modal.open(o); }
  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
  function val(box, k) { var e = box.querySelector('[data-f="' + k + '"]'); if (!e) return null; if (e.type === 'checkbox') return e.checked; var v = String(e.value || '').trim(); return v === '' ? null : v; }
  function msg(box, t) { var m = box.querySelector('[data-msg]'); if (m) m.textContent = t || ''; }
  function field(label, inner, wide) { return '<div' + (wide ? ' style="grid-column:1/-1;"' : '') + '><label class="hr-l">' + esc(label) + '</label>' + inner + '</div>'; }
  function input(k, v, type, extra) { return '<input class="hr-i" data-f="' + k + '" type="' + (type || 'text') + '" value="' + esc(v == null ? '' : v) + '" style="width:100%;"' + (extra || '') + '>'; }
  function check(k, on, label, dis) { return '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;cursor:pointer;"><input type="checkbox" data-f="' + k + '"' + (on ? ' checked' : '') + (dis ? ' disabled' : '') + ' style="margin-top:2px;"><span>' + esc(label) + '</span></label>'; }
  var ERR = '<div data-msg style="color:#B91C1C;font-size:13px;margin-top:8px;"></div>';
  function user() { try { return (KT.Auth && KT.Auth.user && KT.Auth.user()) || JSON.parse(sessionStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; } }
  function hrOn() { var u = user(); return !(u && Array.isArray(u.hidden_screens) && u.hidden_screens.indexOf('hr') !== -1); }
  var ROLES = { lead: 'Lead', support: 'Support', floater: 'Floater', volunteer: 'Volunteer' };

  var CSS_ID = 'kt-hrrota-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = ''
      + '.rt-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px;padding:8px 12px;border:1px solid #BAE6FD;background:#F0F9FF;border-radius:12px;font-size:13px;color:#0C4A6E;}'
      + '.rt-bar.warn{border-color:#FDE68A;background:#FFFBEB;color:#78350F;}'
      + '.rt-bar b{font-weight:800;}'
      + '.rt-tag{display:inline-block;white-space:nowrap;margin-top:3px;padding:0 6px;border-radius:999px;font-size:9.5px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;line-height:16px;}'
      + '.rt-tag.d{background:#E0E7FF;color:#3730A3;}.rt-tag.c{background:#FEF3C7;color:#92400E;}.rt-tag.x{background:#FEE2E2;color:#991B1B;}'
      + '.rt-tag.ok{background:#DCFCE7;color:#166534;}.rt-tag.wait{background:#F1F5F9;color:#475569;}.rt-tag.no{background:#FEE2E2;color:#991B1B;}'
      + '.kt-shift-card.rt-draft,.kt-mini-shift.rt-draft{outline:1.5px dashed #6366F1;outline-offset:-1.5px;}'
      + '.kt-shift-card.rt-cancel,.kt-mini-shift.rt-cancel{opacity:.6;text-decoration:line-through;}'
      + '.rt-dot{display:inline-block;width:10px;height:10px;border-radius:50%;vertical-align:middle;margin-right:6px;}'
      + '.rt-days{display:flex;gap:6px;flex-wrap:wrap;}.rt-days label{display:inline-flex;gap:5px;align-items:center;border:1px solid #CBD5E1;border-radius:999px;padding:3px 10px;font-size:12.5px;cursor:pointer;}'
      + '.rt-sw{display:flex;gap:6px;flex-wrap:wrap;}.rt-sw button{width:26px;height:26px;border-radius:50%;border:2px solid transparent;cursor:pointer;}.rt-sw button.on{border-color:#0F172A;}'
      + 'html.kt-dark .rt-bar{background:#0C2533;border-color:#155E75;color:#BAE6FD;}html.kt-dark .rt-bar.warn{background:#2A2106;border-color:#78350F;color:#FDE68A;}';
    document.head.appendChild(s);
  }

  // ══ SCHEDULE GRID OVERLAY (screen-schedule.js) ═════════════════════════════
  /* overlay(centreId, from, to) -> the drafts, presets, acceptance marks and budget, or null (no HR, or not allowed). */
  async function overlay(centreId, from, to) {
    if (!hrOn() || !Api) return null;
    try { return await Api.get('/hr/rota/week?centre_id=' + centreId + '&from=' + from + '&to=' + to); } catch (e) { return null; }
  }
  /* Lay the overlay onto the grid's days {date: {shifts: [...]}}: published shifts get their pending change / removal and
     acceptance mark; draft additions join their day. Cards keep the grid's own shape. */
  function merge(days, ov) {
    if (!ov || !days) return;
    var byShift = {};
    (ov.drafts || []).forEach(function (d) { if (d.shift_id) byShift[d.shift_id] = d; });
    var pre = {}; (ov.presets || []).forEach(function (p) { pre[p.id] = p; });
    Object.keys(days).forEach(function (date) {
      (days[date].shifts || []).forEach(function (s) {
        var d = byShift[s.id];
        if (d && d.kind === 'change') { s._change = d; ['starts_at', 'ends_at', 'starts_hm', 'ends_hm', 'role', 'room_id', 'room_name', 'user_id', 'user_name', 'preset_id'].forEach(function (k) { s['_was_' + k] = s[k]; s[k] = d[k]; }); }
        if (d && d.kind === 'cancel') s._cancel = d;
        var a = ov.acceptance && ov.acceptance[s.id]; if (a) s._acc = a.status;
        if (s.preset_id && pre[s.preset_id]) s._colour = pre[s.preset_id].colour;
      });
    });
    (ov.drafts || []).forEach(function (d) {
      if (d.kind !== 'add' || !days[d.date]) return;
      var s = Object.assign({}, d, { id: 'd' + d.id, status: 'scheduled', _draft: d });
      if (d.preset_id && pre[d.preset_id]) s._colour = pre[d.preset_id].colour;
      days[d.date].shifts.push(s);
      days[d.date].shifts.sort(function (x, y) { return String(x.starts_at).localeCompare(String(y.starts_at)); });
    });
  }
  /* Extra classes + the small tag a card carries. */
  function cardMarks(s) {
    var cls = '', tag = '';
    if (s._draft) { cls = ' rt-draft'; tag = '<span class="rt-tag d">Draft</span>'; }
    else if (s._cancel) { cls = ' rt-cancel'; tag = '<span class="rt-tag x">To be removed</span>'; }
    else if (s._change) { cls = ' rt-draft'; tag = '<span class="rt-tag c">Changed · not published</span>'; }
    if (!s._draft && !s._cancel && s._acc) tag += ' <span class="rt-tag ' + ({ accepted: 'ok', pending: 'wait', declined: 'no' }[s._acc] || 'wait') + '">' + ({ accepted: 'Accepted', pending: 'Not accepted yet', declined: 'Declined' }[s._acc] || '') + '</span>';
    return { cls: cls, tag: tag };
  }
  /* The banner over the grid: drafts waiting + Publish, and the week against the labour budget. */
  function banner(ov) {
    if (!ov) return '';
    css();
    var h = '';
    var p = ov.pending || {};
    if (ov.draft_mode) {
      h += '<div class="rt-bar' + (p.total ? ' warn' : '') + '" data-rt-banner>' + (p.total
        ? '📝 <span><b>' + p.total + '</b> change' + (p.total === 1 ? '' : 's') + ' not published yet (' + (p.add || 0) + ' new, ' + (p.change || 0) + ' changed, ' + (p.cancel || 0) + ' to remove). Staff, clock-in, ratios and payroll only see published shifts.</span>'
        : '✅ <span>Everything on this rota is published.' + (ov.require_accept ? ' Staff accept each new shift in My HR.' : '') + '</span>')
        + '<span style="flex:1"></span>' + (p.total ? '<button type="button" data-rt="publish" data-kt-iconized="1" style="height:28px;padding:0 14px;border-radius:8px;border:none;background:#0E7490;color:#fff;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;">Publish ' + p.total + '</button>' : '') + '</div>';
    }
    var b = ov.budget;
    if (ov.can_see_pay && b) {
      var weekly = b.budget, days = b.days || 7, budget = weekly != null ? weekly * days / 7 : null;
      var pct = budget ? Math.round(b.cost / budget * 100) : null;
      h += '<div class="rt-bar' + (pct != null && pct > 100 ? ' warn' : '') + '">💵 <span>Planned <b>' + esc(hrs(b.hours)) + '</b>' + (b.drafts_hours ? ' (' + esc(hrs(b.drafts_hours)) + ' in drafts)' : '')
        + ' · projected <b>' + esc(money(b.cost)) + '</b>' + (budget != null ? ' of a <b>' + esc(money(budget)) + '</b> budget (' + pct + '%)' : ' · no labour budget set')
        + (b.salaried || b.no_rate ? ' · ' + (b.salaried ? b.salaried + ' salaried' : '') + (b.salaried && b.no_rate ? ', ' : '') + (b.no_rate ? b.no_rate + ' with no pay rate' : '') + ' not costed' : '') + '</span></div>';
    }
    return h;
  }
  function presetSelect(ov, sel) {
    var ps = (ov && ov.presets) || [];
    if (!ps.length) return '';
    return '<label style="font-size:13px;font-weight:600;">Shift preset</label><select name="preset_id" data-rt-preset style="border:1px solid #D1D5DB;border-radius:8px;background:white;color:#0F172A;"><option value="">— None —</option>'
      + ps.map(function (p) { return '<option value="' + p.id + '"' + (String(sel) === String(p.id) ? ' selected' : '') + ' data-s="' + esc(p.start) + '" data-e="' + esc(p.end) + '" data-r="' + esc(p.role) + '">' + esc(p.name + ' · ' + span(p.start, p.end)) + '</option>'; }).join('') + '</select>';
  }
  /* A chosen preset fills the times (keeping the day) and the role. */
  function wirePreset(form) {
    var s = form.querySelector('[data-rt-preset]'); if (!s) return;
    s.addEventListener('change', function () {
      var o = s.options[s.selectedIndex]; if (!o || !o.value) return;
      var st = form.querySelector('[name="starts_at"]'), en = form.querySelector('[name="ends_at"]'), ro = form.querySelector('[name="role"]');
      var day = String(st.value || '').slice(0, 10);
      if (day) { st.value = day + 'T' + o.getAttribute('data-s'); en.value = (o.getAttribute('data-e') <= o.getAttribute('data-s') ? addDays(day, 1) : day) + 'T' + o.getAttribute('data-e'); }
      if (ro && o.getAttribute('data-r')) ro.value = o.getAttribute('data-r');
    });
  }

  function openPublish(ctx) {
    var p = (ctx.ov && ctx.ov.pending) || {};
    var box = el('<div class="hr-sub" style="font-size:13.5px;line-height:1.6;"><b>' + (p.add || 0) + '</b> new, <b>' + (p.change || 0) + '</b> changed and <b>' + (p.cancel || 0) + '</b> to remove, at this centre (every date).'
      + '<br>Each person hears about their own shifts in one notice' + (ctx.ov.require_accept ? ', and accepts each new shift in My HR' : '') + '. A draft that no longer fits (the person left, or it now overlaps another shift) stays as a draft and is listed.</div>' + ERR);
    modal({ title: 'Publish the rota', body: box, actions: [
      { label: 'Discard all drafts', handler: function () {
        return (KT.confirm ? KT.confirm({ title: 'Discard every draft?', description: 'New shifts in draft are dropped and pending changes are undone. Published shifts stay as they are.', okLabel: 'Discard drafts' }) : Promise.resolve(true)).then(function (ok) {
          if (!ok) return false;
          return Api.post('/hr/rota/discard-all', { centre_id: ctx.centreId }).then(function (r) { saved(r.discarded + ' draft' + (r.discarded === 1 ? '' : 's') + ' discarded.'); ctx.reload(); }, function (e) { msg(box, errMsg(e)); return false; });
        });
      } },
      { label: 'Cancel' },
      { label: 'Publish', primary: true, busyLabel: 'Publishing…', handler: function () {
        return Api.post('/hr/rota/publish', { centre_id: ctx.centreId }).then(function (r) {
          toast('✅', 'Rota published', r.message || '', '#16A34A');
          if ((r.kept || []).length) showSkipped('Kept as drafts', r.kept.map(function (k) { return { person: k.person, date: k.date, why: span(k.start, k.end) + ': ' + k.why }; }));
          ctx.reload();
        }, function (e) { msg(box, errMsg(e)); return false; });
      } }] });
  }
  function showSkipped(title, list) {
    modal({ title: title, body: el('<div class="kt-card"><table data-kt-no-bulk style="width:100%;"><thead><tr><th>Person</th><th>Day</th><th>Why</th></tr></thead><tbody>' + list.map(function (s) {
      return '<tr><td data-no-i18n>' + esc(s.person) + '</td><td style="white-space:nowrap;" data-no-i18n>' + esc(wday(s.date)) + '</td><td>' + esc(s.why) + '</td></tr>';
    }).join('') + '</tbody></table></div>'), actions: [{ label: 'Close', primary: true }] });
  }
  /* Copy: last week into this one, one person's week, or one day onto other days of the week shown. */
  function openCopy(ctx) {
    var wk = ctx.weekStart, days = [];
    for (var i = 0; i < 7; i++) days.push(addDays(wk, i));
    var staff = ctx.staff || [];
    var box = el('<div class="hr-g">' + field('What to copy', '<select class="hr-i" data-f="mode" style="width:100%;"><option value="week">Last week into this week (everyone)</option><option value="person">One person\'s week</option><option value="day">One day onto other days</option></select>', true)
      + '<div data-m="person" style="grid-column:1/-1;display:none;" class="hr-g">' + field('Person', '<select class="hr-i" data-f="user_id" style="width:100%;"><option value="">— Choose —</option>' + staff.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('') + '</select>')
      + field('Copy their week starting', input('src_week', addDays(wk, -7), 'date')) + '</div>'
      + '<div data-m="day" style="grid-column:1/-1;display:none;">' + field('Copy this day', '<select class="hr-i" data-f="src_day" style="width:100%;">' + days.map(function (d) { return '<option value="' + d + '">' + esc(wday(d)) + '</option>'; }).join('') + '</select>', true)
      + '<label class="hr-l" style="margin-top:8px;">Onto</label><div class="rt-days" data-days></div></div></div>'
      + '<div class="hr-sub" style="margin-top:8px;">Copies what the grid shows into the week of <b data-no-i18n>' + esc(wday(wk)) + '</b>. Closure days, days the centre is not open, approved leave or unavailability, overlapping shifts and times already past are left out and listed.'
      + (ctx.ov.draft_mode ? ' The copies are drafts until you publish.' : ' Staff hear about their new shifts in one notice each.') + '</div>' + ERR);
    var mode = box.querySelector('[data-f="mode"]');
    var drawDays = function () {
      var src = val(box, 'src_day');
      box.querySelector('[data-days]').innerHTML = days.filter(function (d) { return d !== src; }).map(function (d) {
        var dw = pd(d).getDay();
        return '<label><input type="checkbox" data-day="' + d + '"' + (dw >= 1 && dw <= 5 ? ' checked' : '') + '>' + esc(wday(d)) + '</label>';
      }).join('');
    };
    mode.addEventListener('change', function () { box.querySelectorAll('[data-m]').forEach(function (m) { m.style.display = m.getAttribute('data-m') === mode.value ? '' : 'none'; }); if (mode.value === 'day') drawDays(); });
    box.querySelector('[data-f="src_day"]').addEventListener('change', drawDays);
    modal({ title: 'Copy shifts', body: box, actions: [{ label: 'Cancel' }, { label: 'Copy', primary: true, busyLabel: 'Copying…', handler: function () {
      var m = mode.value, b = { centre_id: ctx.centreId, mode: m };
      if (m === 'week') { b.source = addDays(wk, -7); b.target = wk; }
      if (m === 'person') { b.user_id = +val(box, 'user_id') || null; b.source = val(box, 'src_week'); b.target = wk; if (!b.user_id) { msg(box, 'Choose a person.'); return false; } }
      if (m === 'day') { b.source = val(box, 'src_day'); b.targets = [].map.call(box.querySelectorAll('[data-day]:checked'), function (x) { return x.getAttribute('data-day'); }); if (!b.targets.length) { msg(box, 'Tick at least one day to copy onto.'); return false; } }
      return Api.post('/hr/rota/copy', b).then(function (r) {
        toast(r.added ? '✅' : 'ℹ️', 'Copy shifts', r.message || '', r.added ? '#16A34A' : undefined);
        if ((r.skipped || []).length) showSkipped('Left out', r.skipped);
        ctx.reload();
      }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ SHIFT TOOLS TAB ════════════════════════════════════════════════════════
  async function drawTools(host) {
    var d; try { d = await Api.get('/hr/rota/settings'); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawTools(host); };
    var s = d.settings || {}, admin = !!d.is_admin, cmap = {};
    (d.centres || []).forEach(function (c) { cmap[c.id] = c.name; });
    var pend = d.pending || {}, pendTxt = Object.keys(pend).map(function (k) { return (cmap[k] || '') + ': ' + pend[k]; });
    var h = '<div class="hrt-sec" style="margin-top:4px;">Publish step</div><div class="kt-card" style="padding:12px 14px;" data-kt-nogrid>'
      + '<div style="display:grid;gap:8px;">' + check('draft_mode', s.draft_mode, 'Build the rota as a draft and publish it when it is ready (staff, clock-in, ratio alerts and payroll only ever see published shifts)', !admin)
      + check('require_accept', s.require_accept, 'Staff must accept each shift they are given when it is published (My HR > Shifts to accept)', !admin || !s.draft_mode) + '</div>'
      + '<div class="hr-sub" style="margin-top:8px;">Off by default: the rota publishes as you go, exactly as before. With the publish step on, new shifts, changes and removals on the Schedule wait as drafts until someone presses Publish; every shift already on the rota counts as published. The nightly fill and standing hours still publish straight away.'
      + (pendTxt.length ? ' <b>Drafts waiting:</b> <span data-no-i18n>' + esc(pendTxt.join(' · ')) + '</span>.' : '') + '</div>'
      + (admin ? '<div class="hr-bar" style="margin:10px 0 0;"><button type="button" class="hr-b p" data-rt="savesettings">Save</button></div>' : '<div class="hr-note" style="margin-top:8px;">Only an agency admin can change this.</div>') + ERR + '</div>';
    var ps = d.presets || [];
    h += '<div class="hrt-sec">Shift presets</div><div class="hr-bar"><span class="hr-sub">Named shifts with a colour: pick one in the shift form to fill the times and role, and the shift shows in its colour on the Schedule.</span><span style="flex:1"></span><button type="button" class="hr-b p" data-rt="newpreset" data-kt-iconized="1">+ New shift preset</button></div>'
      + '<div class="kt-card">' + (ps.length ? '<table data-hrr="presets" style="width:100%;"><thead><tr><th>Shift</th><th>Times</th><th>Role</th><th>Centre</th><th></th></tr></thead><tbody>' + ps.map(function (p) {
        return '<tr' + (p.archived ? ' style="opacity:.55;"' : '') + '><td><span class="rt-dot" style="background:' + esc(p.colour) + ';"></span><b data-no-i18n>' + esc(p.name) + '</b>' + (p.archived ? ' ' + pill('#F1F5F9', '#475569', 'Archived') : '') + '</td>'
          + '<td style="white-space:nowrap;">' + esc(span(p.start, p.end)) + ' <span class="hr-sub">(' + esc(hrs(p.hours)) + ')</span></td><td>' + esc(ROLES[p.role] || p.role) + '</td><td data-no-i18n>' + esc(p.centre || 'Every centre') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;"><button type="button" data-pe="' + p.id + '">Edit</button><button type="button" data-pa="' + p.id + '">' + (p.archived ? 'Restore' : 'Archive') + '</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No shift presets yet, for example “Early 7:00 – 3:00” or “Late 10:00 – 6:00”.</div>') + '</div>';
    if (d.can_see_pay) {
      var bs = d.budgets || [];
      h += '<div class="hrt-sec">Weekly labour budget</div><div class="hr-sub" style="margin:-2px 0 8px;">The planned week (published shifts and drafts) × each person\'s hourly pay rate, against the centre\'s weekly budget. Only people who can see pay see this.</div>'
        + '<div class="kt-card"><table data-hrr="budgets" style="width:100%;"><thead><tr><th>Centre</th><th style="text-align:right;">Weekly budget</th><th style="text-align:right;">This week planned</th><th style="text-align:right;">Projected cost</th><th style="text-align:right;">Of budget</th><th></th></tr></thead><tbody>'
        + bs.map(function (b) {
          var w = b.this_week || {}, pct = b.weekly_amount ? Math.round(w.cost / b.weekly_amount * 100) : null;
          return '<tr><td data-no-i18n><b>' + esc(b.centre) + '</b></td><td style="text-align:right;" data-no-i18n>' + (b.weekly_amount != null ? esc(money(b.weekly_amount)) : '<span class="hr-sub">Not set</span>') + '</td>'
            + '<td style="text-align:right;" data-no-i18n>' + esc(hrs(w.hours)) + '</td><td style="text-align:right;" data-no-i18n>' + esc(money(w.cost)) + (w.salaried || w.no_rate ? '<div class="hr-sub">' + ((w.salaried || 0) + (w.no_rate || 0)) + ' shifts not costed</div>' : '') + '</td>'
            + '<td style="text-align:right;">' + (pct == null ? '<span class="hr-sub">—</span>' : pct > 100 ? pill('#FEF2F2', '#B91C1C', pct + '%') : pill('#ECFDF5', '#047857', pct + '%')) + '</td>'
            + '<td style="text-align:center;">' + (admin ? '<button type="button" data-be="' + b.centre_id + '">Set budget</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    var acc = d.acceptance || [];
    if (s.require_accept || acc.length) {
      h += '<div class="hrt-sec">Waiting for staff to accept</div><div class="kt-card">' + (acc.length ? '<table data-hrr="accept" style="width:100%;"><thead><tr><th>Person</th><th>Shift</th><th>Centre</th><th>Status</th></tr></thead><tbody>' + acc.map(function (a) {
        return '<tr' + (a.status === 'declined' ? ' style="background:#FEF2F2;"' : '') + '><td data-no-i18n><b>' + esc(a.person) + '</b></td><td style="white-space:nowrap;" data-no-i18n>' + esc(wday(a.date) + ', ' + span(a.start, a.end)) + '</td><td data-no-i18n>' + esc(a.centre) + '</td>'
          + '<td>' + (a.status === 'declined' ? pill('#FEE2E2', '#991B1B', 'Can\'t do it') + (a.note ? '<div class="hr-sub" data-no-i18n>' + esc(a.note) + '</div>' : '') : pill('#F1F5F9', '#475569', 'Not accepted yet')) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">Every published shift has been accepted.</div>') + '</div>';
    }
    host.innerHTML = h;
    var sv = host.querySelector('[data-rt="savesettings"]');
    if (sv) {
      var dm = host.querySelector('[data-f="draft_mode"]'), ra = host.querySelector('[data-f="require_accept"]');
      dm.addEventListener('change', function () { ra.disabled = !dm.checked; if (!dm.checked) ra.checked = false; });
      sv.addEventListener('click', function () {
        sv.disabled = true;
        Api.put('/hr/rota/settings', { draft_mode: dm.checked, require_accept: dm.checked && ra.checked }).then(function () { saved('Rota settings saved.'); reload(); }, function (e) { sv.disabled = false; fail('Could not save', e); });
      });
    }
    var byP = {}; ps.forEach(function (p) { byP[p.id] = p; });
    host.querySelector('[data-rt="newpreset"]').addEventListener('click', function () { editPreset(null, d, reload); });
    host.querySelectorAll('[data-pe]').forEach(function (b) { b.addEventListener('click', function () { editPreset(byP[b.getAttribute('data-pe')], d, reload); }); });
    host.querySelectorAll('[data-pa]').forEach(function (b) { b.addEventListener('click', function () { var p = byP[b.getAttribute('data-pa')]; Api.post('/hr/rota/presets/' + p.id + '/archive', { archived: !p.archived }).then(function () { saved(p.archived ? 'Restored.' : 'Archived. Shifts made from it keep their colour.'); reload(); }, function (e) { fail('Could not change it', e); }); }); });
    host.querySelectorAll('[data-be]').forEach(function (b) { b.addEventListener('click', function () { var x = (d.budgets || []).filter(function (y) { return String(y.centre_id) === b.getAttribute('data-be'); })[0]; if (x) editBudget(x, reload); }); });
  }
  function editPreset(p, d, reload) {
    var colour = (p && p.colour) || (d.colours || ['#0E7490'])[0];
    var admin = !!d.is_admin;
    var box = el('<div class="hr-g">' + field('Name', input('name', p ? p.name : '', 'text', ' maxlength="60" placeholder="e.g. Early"'), true)
      + field('Starts', input('start', p ? p.start : '07:00', 'time')) + field('Ends', input('end', p ? p.end : '15:00', 'time'))
      + field('Role', '<select class="hr-i" data-f="role" style="width:100%;">' + Object.keys(ROLES).map(function (k) { return '<option value="' + k + '"' + ((p ? p.role : 'support') === k ? ' selected' : '') + '>' + ROLES[k] + '</option>'; }).join('') + '</select>')
      + field('Centre', '<select class="hr-i" data-f="centre_id" style="width:100%;">' + (admin ? '<option value="">Every centre</option>' : '') + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (p && String(p.centre_id) === String(c.id) ? ' selected' : '') + ' data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>')
      + field('Colour', '<div class="rt-sw" data-sw>' + (d.colours || []).map(function (c) { return '<button type="button" data-c="' + c + '" aria-label="Colour ' + c + '" data-kt-iconized="1" style="background:' + c + ';"' + (c === colour ? ' class="on"' : '') + '></button>'; }).join('') + '</div>', true) + '</div>' + ERR);
    box.querySelectorAll('[data-c]').forEach(function (b) { b.addEventListener('click', function () { colour = b.getAttribute('data-c'); box.querySelectorAll('[data-c]').forEach(function (x) { x.classList.toggle('on', x === b); }); }); });
    modal({ title: p ? 'Edit shift preset' : 'New shift preset', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var o = { name: val(box, 'name'), start: val(box, 'start'), end: val(box, 'end'), role: val(box, 'role'), colour: colour, centre_id: val(box, 'centre_id') ? +val(box, 'centre_id') : null };
      if (!o.name) { msg(box, 'Give the shift a name.'); return false; }
      return (p ? Api.put('/hr/rota/presets/' + p.id, o) : Api.post('/hr/rota/presets', o)).then(function () { saved(); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }
  function editBudget(b, reload) {
    var box = el('<div class="hr-g">' + field('Weekly labour budget for ' + b.centre, input('amt', b.weekly_amount != null ? b.weekly_amount : '', 'number', ' min="0" step="50" placeholder="e.g. 6500"'), true) + '</div><div class="hr-sub" style="margin-top:6px;">Leave it empty to remove the budget.</div>' + ERR);
    modal({ title: 'Labour budget', body: box, actions: [{ label: 'Cancel' }, { label: 'Save', primary: true, busyLabel: 'Saving…', handler: function () {
      var v = val(box, 'amt');
      return Api.put('/hr/rota/budgets/' + b.centre_id, { weekly_amount: v === null ? null : Number(v) }).then(function () { saved(); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ CLOCK-IN FIXES TAB ═════════════════════════════════════════════════════
  var PF = { status: 'pending' };
  function lt(x) { return x ? wday(x.date) + ', ' + t12(x.time) : '—'; }
  function fixWhat(r) {
    var was = r.kind === 'missing' ? '<span class="hr-sub">No clock-in recorded</span>' : '<span data-no-i18n>' + esc(lt(r.was_in)) + ' – ' + esc(r.was_out ? t12(r.was_out.time) : 'no clock-out') + '</span>';
    var want = '<b data-no-i18n>' + esc(lt(r.want_in)) + ' – ' + esc(r.want_out ? t12(r.want_out.time) : 'no clock-out') + '</b>';
    return { was: was, want: want };
  }
  function fixPill(r) {
    if (r.status === 'pending') return r.locked ? pill('#FEF2F2', '#B91C1C', 'Locked timesheet') : pill('#FEF3C7', '#92400E', 'Waiting');
    return { approved: pill('#DCFCE7', '#166534', 'Fixed'), declined: pill('#FEE2E2', '#991B1B', 'Declined'), withdrawn: pill('#F1F5F9', '#475569', 'Withdrawn') }[r.status] || esc(r.status);
  }
  async function drawFixes(host) {
    var d; try { d = await Api.get('/hr/punch-fixes?status=' + PF.status); } catch (e) { host.innerHTML = '<div class="hr-note">' + esc(errMsg(e)) + '</div>'; return; }
    if (!host.isConnected) return;
    var reload = function () { drawFixes(host); };
    var rows = d.rows || [];
    var h = '<div class="hr-note">Staff ask here when a clock-in is wrong or missing (My HR > Fix my clock-in). Approving changes the clock-in, marked as corrected, and the hours follow everywhere. Nobody approves their own, and a day inside an approved, locked timesheet cannot be changed until an agency admin unlocks it.</div>'
      + '<div class="hrt-chips"><button type="button" class="hrt-chip' + (PF.status === 'pending' ? ' on' : '') + '" data-pf="pending"' + (PF.status === 'pending' ? ' aria-current="true"' : '') + '>Waiting <span class="n">' + (d.pending || 0) + '</span></button>'
      + '<button type="button" class="hrt-chip' + (PF.status === 'decided' ? ' on' : '') + '" data-pf="decided"' + (PF.status === 'decided' ? ' aria-current="true"' : '') + '>Decided</button></div>'
      + '<div class="kt-card">' + (rows.length ? '<table data-hrr="fixes" style="width:100%;"><thead><tr><th>Person</th><th>Recorded</th><th>Asked for</th><th>Why</th><th>Status</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
        var w = fixWhat(r);
        return '<tr><td><b data-no-i18n>' + esc(r.person) + '</b><div class="hr-sub" data-no-i18n>' + esc(r.centre) + '</div></td><td>' + w.was + '</td><td>' + w.want + '</td><td data-no-i18n>' + esc(r.reason) + '</td>'
          + '<td>' + fixPill(r) + (r.status !== 'pending' && r.decided_by ? '<div class="hr-sub"><span data-no-i18n>' + esc(r.decided_by) + '</span>' + (r.decision_comment ? ': <span data-no-i18n>' + esc(r.decision_comment) + '</span>' : '') + '</div>' : '') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;">' + (r.status === 'pending' && r.user_id !== d.me ? '<button type="button" data-fa="' + r.id + '">Approve</button><button type="button" data-fd="' + r.id + '">Decline</button>' : '') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:16px;" class="hr-sub">' + (PF.status === 'pending' ? 'No clock-in fixes waiting.' : 'No decided requests yet.') + '</div>') + '</div>';
    host.innerHTML = h;
    host.querySelectorAll('[data-pf]').forEach(function (b) { b.addEventListener('click', function () { PF.status = b.getAttribute('data-pf'); reload(); }); });
    var byId = {}; rows.forEach(function (r) { byId[r.id] = r; });
    host.querySelectorAll('[data-fa]').forEach(function (b) { b.addEventListener('click', function () { decideFix(byId[b.getAttribute('data-fa')], 'approve', reload); }); });
    host.querySelectorAll('[data-fd]').forEach(function (b) { b.addEventListener('click', function () { decideFix(byId[b.getAttribute('data-fd')], 'decline', reload); }); });
  }
  function decideFix(r, dec, reload) {
    var w = fixWhat(r);
    var box = el('<div class="hr-kv"><span>Recorded</span><b>' + w.was + '</b></div><div class="hr-kv"><span>Asked for</span>' + w.want + '</div><div class="hr-kv"><span>Why</span><b data-no-i18n>' + esc(r.reason) + '</b></div>'
      + '<div class="hr-g" style="margin-top:10px;">' + field(dec === 'approve' ? 'Note (optional)' : 'Why it is declined', '<textarea class="hr-i" data-f="comment" rows="2" style="width:100%;min-height:48px;"></textarea>', true) + '</div>' + ERR);
    modal({ title: (dec === 'approve' ? 'Approve clock-in fix: ' : 'Decline clock-in fix: ') + r.person, body: box, actions: [{ label: 'Cancel' }, { label: dec === 'approve' ? 'Approve and fix' : 'Decline', primary: true, busyLabel: 'Saving…', handler: function () {
      var c = val(box, 'comment');
      if (dec === 'decline' && !c) { msg(box, 'Say why the fix is declined.'); return false; }
      return Api.post('/hr/punch-fixes/' + r.id + '/decide', { decision: dec, comment: c }).then(function () { saved(dec === 'approve' ? 'Clock-in fixed. They have been told.' : 'Declined. They have been told.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  // ══ MY HR ══════════════════════════════════════════════════════════════════
  async function mine(b) {
    css();
    var holder = document.createElement('div'); holder.setAttribute('data-rt-mine', '1');
    b.appendChild(holder);
    var a = null, f = null;
    try { a = await Api.get('/hr/me/shifts-to-accept'); } catch (e) {}
    try { f = await Api.get('/hr/me/punch-fixes'); } catch (e) {}
    if (!holder.isConnected || (!(a && a.is_staff) && !(f && f.is_staff))) return;
    var reload = function () { var p = holder.parentNode; holder.remove(); if (p) mine(p); };
    var h = '';
    if (a && a.is_staff) {
      var sh = a.shifts || [];
      h += '<div class="hrt-sec">Shifts to accept</div><div class="kt-card">' + (sh.length ? '<table data-hrr="myaccept" style="width:100%;"><thead><tr><th>Shift</th><th>Where</th><th style="text-align:right;">Hours</th><th></th></tr></thead><tbody>' + sh.map(function (s) {
        return '<tr><td style="white-space:nowrap;"><b data-no-i18n>' + esc(wday(s.date)) + '</b><div class="hr-sub" data-no-i18n>' + esc(span(s.start, s.end)) + '</div></td><td data-no-i18n>' + esc(s.room) + '<div class="hr-sub">' + esc(s.centre) + '</div></td>'
          + '<td style="text-align:right;" data-no-i18n>' + esc(hrs(s.hours)) + '</td><td style="text-align:center;white-space:nowrap;"><button type="button" data-acc="' + s.shift_id + '">Accept</button><button type="button" data-dec="' + s.shift_id + '">I can\'t do this shift</button></td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No shifts waiting for you to accept.</div>') + '</div>';
    }
    if (f && f.is_staff) {
      var ps = f.punches || [], rq = (f.requests || []).filter(function (r) { return r.status === 'pending' || (Date.now() - new Date(String(r.created_at).replace(' ', 'T')).getTime()) < 30 * 864e5; });
      h += '<div class="hrt-sec">Fix my clock-in</div><div class="hr-bar"><span class="hr-sub">Clock-in time wrong, or forgot to clock out? Ask for a fix: your director checks it.</span><span style="flex:1"></span><button type="button" class="hr-b" data-missing>A clock-in is missing</button></div>';
      if (rq.length) {
        h += '<div class="kt-card" style="margin-bottom:10px;"><table data-hrr="myfixes" style="width:100%;"><thead><tr><th>Recorded</th><th>Asked for</th><th>Status</th><th></th></tr></thead><tbody>' + rq.map(function (r) {
          var w = fixWhat(r);
          return '<tr><td>' + w.was + '</td><td>' + w.want + '</td><td>' + fixPill(r) + (r.status === 'declined' && r.decision_comment ? '<div class="hr-sub" data-no-i18n>' + esc(r.decision_comment) + '</div>' : '') + '</td>'
            + '<td style="text-align:center;">' + (r.status === 'pending' ? '<button type="button" data-wd="' + r.id + '">Withdraw</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      }
      h += '<div class="kt-card">' + (ps.length ? '<table data-hrr="mypunches" style="width:100%;"><thead><tr><th>Clock-in</th><th>Clock-out</th><th style="text-align:right;">Hours</th><th></th></tr></thead><tbody>' + ps.slice(0, 20).map(function (p) {
        return '<tr><td style="white-space:nowrap;" data-no-i18n><b>' + esc(lt(p.in)) + '</b><div class="hr-sub">' + esc(p.centre) + '</div></td><td style="white-space:nowrap;">' + (p.out ? '<span data-no-i18n>' + esc(t12(p.out.time)) + (p.out.date !== p.in.date ? ' (' + esc(wday(p.out.date)) + ')' : '') + '</span>' : pill('#FEE2E2', '#991B1B', 'No clock-out')) + (p.auto ? ' ' + pill('#F1F5F9', '#475569', 'Clocked out automatically') : '') + '</td>'
          + '<td style="text-align:right;" data-no-i18n>' + (p.minutes != null ? esc(hh(p.minutes)) : '—') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;">' + (p.pending ? pill('#FEF3C7', '#92400E', 'Fix asked') : p.locked ? pill('#F1F5F9', '#475569', 'Locked for payroll') : '<button type="button" data-fix="' + p.id + '">Ask for a fix</button>') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="padding:14px;" class="hr-sub">No clock-ins in the last month.</div>') + '</div>';
    }
    holder.innerHTML = h;
    holder.querySelectorAll('[data-acc]').forEach(function (x) { x.addEventListener('click', function () { x.disabled = true; Api.post('/hr/me/shifts/' + x.getAttribute('data-acc') + '/respond', { decision: 'accept' }).then(function () { saved('Shift accepted.'); reload(); }, function (e) { x.disabled = false; fail('Could not accept', e); }); }); });
    holder.querySelectorAll('[data-dec]').forEach(function (x) {
      x.addEventListener('click', function () {
        var box = el('<div class="hr-g">' + field('Why can\'t you do it?', '<textarea class="hr-i" data-f="note" rows="2" style="width:100%;min-height:48px;" maxlength="500"></textarea>', true) + '</div><div class="hr-sub" style="margin-top:6px;">Your director is told and sorts out cover. The shift stays yours until they change it.</div>' + ERR);
        modal({ title: 'I can\'t do this shift', body: box, actions: [{ label: 'Cancel' }, { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
          var n = val(box, 'note'); if (!n) { msg(box, 'Say why, so your director can sort it out.'); return false; }
          return Api.post('/hr/me/shifts/' + x.getAttribute('data-dec') + '/respond', { decision: 'decline', note: n }).then(function () { saved('Your director has been told.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
        } }] });
      });
    });
    var byP = {}; ((f && f.punches) || []).forEach(function (p) { byP[p.id] = p; });
    holder.querySelectorAll('[data-fix]').forEach(function (x) { x.addEventListener('click', function () { askFix(byP[x.getAttribute('data-fix')], f, reload); }); });
    var mi = holder.querySelector('[data-missing]'); if (mi) mi.addEventListener('click', function () { askFix(null, f, reload); });
    holder.querySelectorAll('[data-wd]').forEach(function (x) { x.addEventListener('click', function () { Api.post('/hr/punch-fixes/' + x.getAttribute('data-wd') + '/withdraw', {}).then(function () { saved('Withdrawn.'); reload(); }, function (e) { fail('Could not withdraw', e); }); }); });
  }
  function askFix(p, f, reload) {
    var cs = f.centres || [];
    if (!p && !cs.length) { toast('ℹ️', 'Fix my clock-in', 'You are not placed at a centre yet. Ask your director.'); return; }
    var box = el((p ? '<div class="hr-kv"><span>Recorded</span><b data-no-i18n>' + esc(lt(p.in)) + ' – ' + esc(p.out ? t12(p.out.time) : 'no clock-out') + '</b></div>' : '')
      + '<div class="hr-g" style="margin-top:6px;">' + (p ? '' : field('Centre', '<select class="hr-i" data-f="centre_id" style="width:100%;">' + cs.map(function (c) { return '<option value="' + c.id + '" data-no-i18n>' + esc(c.name) + '</option>'; }).join('') + '</select>', true))
      + field('Day', input('date', p ? p.in.date : (f.today || ''), 'date', ' max="' + esc(f.today || '') + '"'), true)
      + field('Started', input('in', p ? p.in.time : '', 'time')) + field('Finished', input('out', p && p.out ? p.out.time : '', 'time'))
      + field('What went wrong', '<textarea class="hr-i" data-f="reason" rows="2" style="width:100%;min-height:48px;" maxlength="500" placeholder="e.g. The tablet was off when I arrived"></textarea>', true) + '</div>'
      + '<div class="hr-sub" style="margin-top:6px;">A finish time earlier than the start counts as the next morning.</div>' + ERR);
    modal({ title: p ? 'Ask for a clock-in fix' : 'A clock-in is missing', body: box, actions: [{ label: 'Cancel' }, { label: 'Send to my director', primary: true, busyLabel: 'Sending…', handler: function () {
      var o = { date: val(box, 'date'), in: val(box, 'in'), out: val(box, 'out'), reason: val(box, 'reason') };
      if (p) o.punch_id = p.id; else o.centre_id = +val(box, 'centre_id');
      if (!o.date || !o.in) { msg(box, 'Enter the day and the time you started.'); return false; }
      if (!p && !o.out) { msg(box, 'Enter when you finished too.'); return false; }
      if (!o.reason) { msg(box, 'Say what went wrong.'); return false; }
      return Api.post('/hr/punch-fixes', o).then(function () { saved('Sent. Your director has been told.'); reload(); }, function (e) { msg(box, errMsg(e)); return false; });
    } }] });
  }

  window.KTHrRota = {
    tabs: ['rotatools', 'punchfix'],
    has: function (t) { return this.tabs.indexOf(t) >= 0; },
    draw: function (tab, host) {
      css();
      if (tab === 'rotatools') return drawTools(host);
      if (tab === 'punchfix') return drawFixes(host);
    },
    mine: mine,
    // screen-schedule.js
    on: hrOn, overlay: overlay, merge: merge, cardMarks: cardMarks, banner: banner, presetSelect: presetSelect, wirePreset: wirePreset,
    openPublish: openPublish, openCopy: openCopy
  };
})(window);
