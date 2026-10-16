/* ═══════════════════════════════════════════════════════════════════════
   Drill log (#drills) — 2026-10-09

   Fire, severe weather, lockdown, vehicle and shelter drills: record one, see a month of
   them, and see which drills are still owed (or were missed) under the agency's province or
   state — Ohio: fire monthly, weather monthly March–September, lockdown quarterly; Ontario
   and the other provinces: fire monthly. Admins see every centre, directors and educators
   their own. Times are in the agency's timezone.

   ONTARIO (2026-10-13, O. Reg. 137/15 s.68 / s.68.2): a "Drill schedule" card per centre —
   last missing-child drill, next due (every 3 months), and the fire drill (monthly, or 3 a
   term for a school-based program). Missing child is a drill type there, with its own
   fields (area, scenario, who led it, time to locate, 911 / parent call simulated).
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  // A bare YYYY-MM-DD is a calendar day, formatted from its own parts (never new Date()).
  function day(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? MON[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : ''; }
  function mmss(sec) { return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's'; }
  var CAD = { ok: ['#ECFDF5', '#047857', 'On track'], due: ['#F1F5F9', '#475569', 'Due'], due_soon: ['#FFFBEB', '#B45309', 'Due soon'], overdue: ['#FEF2F2', '#B91C1C', 'Overdue'] };
  function cadLine(c) {
    var t = CAD[c.state] || CAD.due;
    var bits = [c.last_date ? 'last ' + day(c.last_date) : 'none on record'];
    if (c.count != null) bits.push(c.count + ' of ' + c.needed + ' this term');
    if (c.state === 'overdue' && c.missed_month) bits.push('none in ' + c.missed_month);
    else bits.push((c.state === 'overdue' ? 'was due by ' : (c.never ? 'first one due by ' : 'next due by ')) + day(c.due_on));
    return '<div class="dr-cad"><div style="min-width:0;"><div style="font-weight:700;font-size:13px;">' + esc(c.label) + '</div>'
      + '<div style="font-size:12px;color:#64748B;">' + esc(c.rule) + '</div></div>'
      + '<div style="font-size:12.5px;color:#334155;">' + esc(bits.join(' · ')) + '</div>'
      + '<span class="dr-chip" data-kt-no-tone style="background:' + t[0] + ';color:' + t[1] + ';justify-self:start;">' + esc(t[2]) + '</span></div>';
  }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c); } catch (e) {} }
  function role() { try { return (JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}').roles || []); } catch (e) { return []; } }
  var CSS_ID = 'kt-drills-css';
  function css() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style'); s.id = CSS_ID;
    s.textContent = '.dr-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.dr-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-top:14px;}'
      + '.dr-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
      + '.dr-i{height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box;}'
      + '.dr-b{height:32px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.dr-b.p{background:#B91C1C;border-color:#B91C1C;color:#fff;}'
      + '.dr-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;}'
      + '.dr-t{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.dr-t th,.dr-t td{padding:8px;border-top:1px solid #EEF2F6;text-align:left;vertical-align:top;}'
      + '.dr-t th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-top:0;}'
      + '.dr-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(230px,100%),1fr));gap:10px;}'
      + '.dr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}'
      + '.dr-cad{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1.6fr) auto;gap:4px 12px;align-items:center;padding:6px 0;}'
      + '@media (max-width:640px){.dr-cad{grid-template-columns:minmax(0,1fr);}}'
      + '.dr-ck{display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer;height:32px;}';
    document.head.appendChild(s);
  }

  var state = { month: null, centre: '' };

  async function render(main) {
    css();
    main.innerHTML = '<div class="dr-wrap" style="color:#64748B;">Loading drills…</div>';
    var q = [];
    if (state.month) q.push('month=' + state.month);
    if (state.centre) q.push('centre_id=' + state.centre);
    var d;
    try { d = await Api.get('/drills' + (q.length ? '?' + q.join('&') : '')); }
    catch (e) { main.innerHTML = '<div class="dr-wrap" style="color:#B91C1C;">' + esc(e.message || 'Could not load drills.') + '</div>'; return; }
    state.month = d.month;
    var isAdmin = role().some(function (r) { return r === 'agency_admin' || r === 'platform_admin'; });

    var h = '<div class="dr-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#450A0A 0%,#991B1B 55%,#DC2626 100%);">'
      + '<div class="kt-hero-greet">🚒 SAFETY</div><h1>Drill log</h1>'
      + '<div class="kt-hero-sub">' + esc(d.jurisdiction.label) + ' rules: ' + esc(((d.rules || []).concat(d.ontario ? ['Missing child drill — at least every 3 months (from Jan 1, 2027)'] : [])).join(' · ') || 'fire drills monthly')
      + '. Record each drill as soon as it is done; times are in ' + esc(d.timezone) + '.</div></div>';

    // Ontario: rolling schedule per centre (s.68 / s.68.2)
    if (d.ontario) {
      h += '<div class="dr-card"><div style="font-weight:800;font-size:15px;margin-bottom:2px;">Drill schedule</div>'
        + '<div style="font-size:12.5px;color:#64748B;margin-bottom:6px;">Missing-child drills at least every 3 months, fire drills monthly. Keep records at least 12 months — KiddieTrac keeps them.</div>';
      (d.status || []).forEach(function (c) {
        var cad = c.cadence || {};
        h += '<div style="padding:8px 0;border-top:1px solid #F1F5F9;"><div class="dr-row" style="justify-content:space-between;"><div style="font-weight:800;font-size:13.5px;">' + esc(c.centre_name) + '</div>'
          + (c.can_manage ? '<label style="font-size:12px;color:#64748B;display:flex;gap:6px;align-items:center;">Fire drills <select class="dr-i" data-cad="' + c.centre_id + '" style="height:28px;font-size:12px;width:auto;max-width:260px;">'
              + Object.keys(d.fire_cadences || {}).map(function (k) { return '<option value="' + k + '"' + (k === c.fire_cadence ? ' selected' : '') + '>' + esc(d.fire_cadences[k]) + '</option>'; }).join('') + '</select></label>' : '')
          + '</div>' + (cad.missing_child ? cadLine(cad.missing_child) : '') + (cad.fire ? cadLine(cad.fire) : '') + '</div>';
      });
      h += '</div>';
    }

    // what's owed, per centre
    if (!d.ontario) h += '<div class="dr-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Due and missed</div>';
    if (!d.ontario) (d.status || []).forEach(function (c) {
      var chips = (c.rules || []).map(function (r) {
        var col, txt;
        if (r.previous.missed) { col = ['#FEF2F2', '#B91C1C']; txt = r.label.split(' — ')[0] + ': missed ' + r.previous.period; }
        else if (!r.current.owed) { col = ['#F1F5F9', '#64748B']; txt = r.label.split(' — ')[0] + ': not due this period'; }
        else if (r.current.done_at) { col = ['#ECFDF5', '#047857']; txt = r.label.split(' — ')[0] + ': done for ' + r.current.period; }
        else { col = ['#FFFBEB', '#B45309']; txt = r.label.split(' — ')[0] + ': due ' + r.current.period; }
        return '<span class="dr-chip" data-kt-no-tone style="background:' + col[0] + ';color:' + col[1] + ';">' + esc(txt) + (r.optional ? ' (if you transport children)' : '') + '</span>';
      }).join(' ');
      h += '<div style="padding:7px 0;border-top:1px solid #F1F5F9;"><div style="font-weight:700;font-size:13.5px;margin-bottom:4px;">' + esc(c.centre_name) + '</div><div class="dr-row">' + (chips || '<span style="color:#64748B;font-size:12.5px;">No drill rules for this jurisdiction yet.</span>') + '</div></div>';
    });
    if (!d.ontario) h += '</div>';

    // record
    // "Now" on the AGENCY's clock, not the device's.
    var local = '';
    try {
      var parts = {}; new Intl.DateTimeFormat('en-CA', { timeZone: d.timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date()).forEach(function (x) { parts[x.type] = x.value; });
      local = parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
    } catch (e) { local = ''; }
    h += '<div class="dr-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Record a drill</div><div class="dr-g">'
      + '<div><label class="dr-l">Centre</label><select class="dr-i" id="dr-c" style="width:100%;">' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="dr-l">Room (optional)</label><select class="dr-i" id="dr-r" style="width:100%;"></select></div>'
      + '<div><label class="dr-l">Type</label><select class="dr-i" id="dr-t" style="width:100%;">' + Object.keys(d.types || {}).map(function (k) { return '<option value="' + k + '">' + esc(d.types[k]) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="dr-l">When</label><input class="dr-i" id="dr-at" type="datetime-local" value="' + local + '" style="width:100%;"></div>'
      + '<div id="dr-s-w"><label class="dr-l">Evacuation time (seconds)</label><input class="dr-i" id="dr-s" type="number" min="0" style="width:100%;"></div>'
      + '<div><label class="dr-l">Children present</label><input class="dr-i" id="dr-k" type="number" min="0" style="width:100%;"></div>'
      + '</div>'
      + '<div class="dr-g" style="margin-top:10px;"><div><label class="dr-l">Staff present</label><input class="dr-i" id="dr-st" maxlength="500" placeholder="Names" style="width:100%;"></div>'
      + '<div><label class="dr-l">Notes</label><input class="dr-i" id="dr-n" maxlength="2000" placeholder="Exit used, anything to fix" style="width:100%;"></div></div>'
      // Missing-child drill (Ontario s.68.2) — shown only for that type.
      + '<div id="dr-mc" hidden><div class="dr-g" style="margin-top:10px;">'
      + '<div><label class="dr-l">Room(s) / area</label><input class="dr-i" id="dr-area" maxlength="255" placeholder="e.g. Toddler room, playground" style="width:100%;"></div>'
      + '<div><label class="dr-l">Led by</label><input class="dr-i" id="dr-led" maxlength="160" placeholder="Name" style="width:100%;"></div>'
      + '<div><label class="dr-l">Time until child located (seconds)</label><input class="dr-i" id="dr-loc" type="number" min="0" style="width:100%;"></div>'
      + '</div><div class="dr-g" style="margin-top:10px;"><div><label class="dr-l">Scenario</label><input class="dr-i" id="dr-scn" maxlength="2000" placeholder="What was practised" style="width:100%;"></div>'
      + '<div><label class="dr-l">What to improve</label><input class="dr-i" id="dr-imp" maxlength="2000" placeholder="Lessons learned" style="width:100%;"></div></div>'
      + '<div class="dr-row" style="margin-top:6px;"><label class="dr-ck"><input type="checkbox" id="dr-911"> 911 call simulated</label><label class="dr-ck"><input type="checkbox" id="dr-pc"> Parent call simulated</label></div></div>'
      + '<div class="dr-row" style="margin-top:10px;"><button class="dr-b p" id="dr-save">Record drill</button><span id="dr-msg" style="font-size:13px;"></span></div></div>';

    // list
    h += '<div class="dr-card"><div class="dr-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Drills in</div>'
      + '<input class="dr-i" id="dr-m" type="month" value="' + esc(d.month) + '">'
      + '<select class="dr-i" id="dr-fc"><option value="">All my centres</option>' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + (d.drills && d.drills.length
        ? '<div style="overflow-x:auto;"><table class="dr-t"><thead><tr><th>When</th><th>Type</th><th>Centre / room</th><th>Evacuation / located</th><th>Children</th><th>Staff</th><th>Notes</th><th>By</th><th></th></tr></thead><tbody>'
          + d.drills.map(function (r) {
              return '<tr><td style="white-space:nowrap;">' + esc(r.local) + '</td><td>' + esc(r.type_label) + '</td><td>' + esc(r.centre_name) + (r.room_name ? ' · ' + esc(r.room_name) : '') + '</td>'
                + '<td>' + (r.type === 'missing_child' ? (r.located_seconds != null ? 'Located in ' + esc(mmss(r.located_seconds)) : '—') : (r.evacuation_seconds != null ? esc(mmss(r.evacuation_seconds)) : '—')) + '</td>'
                + '<td>' + (r.children_count != null ? esc(r.children_count) : '—') + '</td><td>' + esc(r.staff_present || '') + '</td><td>'
                + (r.type === 'missing_child' ? [r.area ? 'Area: ' + esc(r.area) : '', r.scenario ? 'Scenario: ' + esc(r.scenario) : '', r.led_by ? 'Led by ' + esc(r.led_by) : '',
                    (r.simulated_911 ? '911 simulated' : (r.simulated_911 === 0 || r.simulated_911 === false ? '911 not simulated' : '')),
                    (r.simulated_parent_call ? 'parent call simulated' : (r.simulated_parent_call === 0 || r.simulated_parent_call === false ? 'parent call not simulated' : '')),
                    r.improvements ? 'Improve: ' + esc(r.improvements) : '', esc(r.notes || '')].filter(Boolean).join('<br>') : esc(r.notes || '')) + '</td><td>' + esc(r.recorded_by_name) + '</td>'
                + '<td><button class="dr-b" data-del="' + r.id + '" style="height:26px;font-size:12px;">Remove</button></td></tr>';
            }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No drills recorded this month.</div>')
      + '</div>';

    if (isAdmin) {
      h += '<div class="dr-card"><label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="dr-rem"' + (d.reminders ? ' checked' : '') + '> '
        + 'Email directors on weekday mornings when a drill is still owed or was missed (off by default)</label>'
        + (d.ontario ? '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;margin-top:8px;"><input type="checkbox" id="dr-crem"' + (d.cadence_reminders ? ' checked' : '') + '> '
          + 'Ontario schedule reminders: tell directors when a missing-child drill is due within 14 days or overdue, or a fire drill is overdue — once per change, admins on anything overdue (on by default)</label>' : '')
        + '</div>';
    }
    h += '</div>';
    main.innerHTML = h;

    var $ = function (id) { return main.querySelector('#' + id); };
    function fillRooms() {
      var cid = $('dr-c').value;
      $('dr-r').innerHTML = '<option value="">Whole centre</option>' + (d.rooms || []).filter(function (r) { return String(r.centre_id) === String(cid); }).map(function (r) { return '<option value="' + r.id + '">' + esc(r.name) + '</option>'; }).join('');
    }
    $('dr-c').addEventListener('change', fillRooms); fillRooms();
    function typeFields() { var mc = $('dr-t').value === 'missing_child'; $('dr-mc').hidden = !mc; $('dr-s-w').style.display = mc ? 'none' : ''; }
    $('dr-t').addEventListener('change', typeFields); typeFields();
    $('dr-save').addEventListener('click', async function () {
      var b = this, msg = $('dr-msg'); if (!msg || !$('dr-t')) return; var mc = $('dr-t').value === 'missing_child'; b.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try {
        await Api.post('/drills', { centre_id: +$('dr-c').value, room_id: $('dr-r').value ? +$('dr-r').value : null, type: $('dr-t').value, drilled_at: $('dr-at').value,
          evacuation_seconds: mc || $('dr-s').value === '' ? null : +$('dr-s').value, children_count: $('dr-k').value === '' ? null : +$('dr-k').value,
          staff_present: $('dr-st').value.trim() || null, notes: $('dr-n').value.trim() || null,
          area: mc ? ($('dr-area').value.trim() || null) : null, led_by: mc ? ($('dr-led').value.trim() || null) : null,
          located_seconds: mc && $('dr-loc').value !== '' ? +$('dr-loc').value : null, scenario: mc ? ($('dr-scn').value.trim() || null) : null,
          improvements: mc ? ($('dr-imp').value.trim() || null) : null, simulated_911: mc ? $('dr-911').checked : null, simulated_parent_call: mc ? $('dr-pc').checked : null });
        toast('🚒', 'Drill recorded', '', '#16A34A'); render(main);
      } catch (e) { b.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save.'; }
    });
    $('dr-m').addEventListener('change', function () { state.month = this.value; render(main); });
    $('dr-fc').addEventListener('change', function () { state.centre = this.value; render(main); });
    main.querySelectorAll('[data-del]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        var ok = KT.confirm ? await KT.confirm({ title: 'Remove this drill?', description: 'It is taken off the log. The removal is recorded in the audit log.', okLabel: 'Remove' }) : true;
        if (!ok) return;
        try { await Api.delete('/drills/' + btn.getAttribute('data-del')); render(main); } catch (e) { toast('⚠️', 'Could not remove', (e && e.message) || '', '#DC2626'); }
      });
    });
    main.querySelectorAll('[data-cad]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        Api.post('/drills/cadence', { centre_id: +sel.getAttribute('data-cad'), fire: sel.value }).then(function () { toast('🚒', 'Fire drill schedule saved', '', '#16A34A'); render(main); })
          .catch(function (e) { toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); render(main); });
      });
    });
    var crem = $('dr-crem');
    if (crem) crem.addEventListener('change', function () {
      Api.post('/drills/cadence-reminders', { enabled: crem.checked }).then(function () { toast('🔔', crem.checked ? 'Schedule reminders on' : 'Schedule reminders off', '', '#16A34A'); })
        .catch(function (e) { crem.checked = !crem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
    var rem = $('dr-rem');
    if (rem) rem.addEventListener('change', function () {
      Api.post('/drills/reminders', { enabled: rem.checked }).then(function () { toast('🔔', rem.checked ? 'Drill reminders on' : 'Drill reminders off', '', '#16A34A'); })
        .catch(function (e) { rem.checked = !rem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
  }

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) { KT.Shell.registerScreen(r + ':drills', render); });
})(window);
