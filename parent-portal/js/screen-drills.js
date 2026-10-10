/* ═══════════════════════════════════════════════════════════════════════
   Drill log (#drills) — 2026-10-09

   Fire, severe weather, lockdown, vehicle and shelter drills: record one, see a month of
   them, and see which drills are still owed (or were missed) under the agency's province or
   state — Ohio: fire monthly, weather monthly March–September, lockdown quarterly; Ontario
   and the other provinces: fire monthly. Admins see every centre, directors and educators
   their own. Times are in the agency's timezone.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
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
      + '.dr-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}'
      + '.dr-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}';
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
      + '<div class="kt-hero-sub">' + esc(d.jurisdiction.label) + ' rules: ' + esc((d.rules || []).join(' · ') || 'fire drills monthly')
      + '. Record each drill as soon as it is done; times are in ' + esc(d.timezone) + '.</div></div>';

    // what's owed, per centre
    h += '<div class="dr-card"><div style="font-weight:800;font-size:15px;margin-bottom:8px;">Due and missed</div>';
    (d.status || []).forEach(function (c) {
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
    h += '</div>';

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
      + '<div><label class="dr-l">Evacuation time (seconds)</label><input class="dr-i" id="dr-s" type="number" min="0" style="width:100%;"></div>'
      + '<div><label class="dr-l">Children present</label><input class="dr-i" id="dr-k" type="number" min="0" style="width:100%;"></div>'
      + '</div>'
      + '<div class="dr-g" style="margin-top:10px;"><div><label class="dr-l">Staff present</label><input class="dr-i" id="dr-st" maxlength="500" placeholder="Names" style="width:100%;"></div>'
      + '<div><label class="dr-l">Notes</label><input class="dr-i" id="dr-n" maxlength="2000" placeholder="Exit used, anything to fix" style="width:100%;"></div></div>'
      + '<div class="dr-row" style="margin-top:10px;"><button class="dr-b p" id="dr-save">Record drill</button><span id="dr-msg" style="font-size:13px;"></span></div></div>';

    // list
    h += '<div class="dr-card"><div class="dr-row" style="margin-bottom:8px;"><div style="font-weight:800;font-size:15px;flex:1;">Drills in</div>'
      + '<input class="dr-i" id="dr-m" type="month" value="' + esc(d.month) + '">'
      + '<select class="dr-i" id="dr-fc"><option value="">All my centres</option>' + (d.centres || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(state.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>'
      + (d.drills && d.drills.length
        ? '<div style="overflow-x:auto;"><table class="dr-t"><thead><tr><th>When</th><th>Type</th><th>Centre / room</th><th>Evacuation</th><th>Children</th><th>Staff</th><th>Notes</th><th>By</th><th></th></tr></thead><tbody>'
          + d.drills.map(function (r) {
              return '<tr><td style="white-space:nowrap;">' + esc(r.local) + '</td><td>' + esc(r.type_label) + '</td><td>' + esc(r.centre_name) + (r.room_name ? ' · ' + esc(r.room_name) : '') + '</td>'
                + '<td>' + (r.evacuation_seconds != null ? esc(Math.floor(r.evacuation_seconds / 60) + 'm ' + (r.evacuation_seconds % 60) + 's') : '—') + '</td>'
                + '<td>' + (r.children_count != null ? esc(r.children_count) : '—') + '</td><td>' + esc(r.staff_present || '') + '</td><td>' + esc(r.notes || '') + '</td><td>' + esc(r.recorded_by_name) + '</td>'
                + '<td><button class="dr-b" data-del="' + r.id + '" style="height:26px;font-size:12px;">Remove</button></td></tr>';
            }).join('') + '</tbody></table></div>'
        : '<div style="color:#64748B;font-size:13px;">No drills recorded this month.</div>')
      + '</div>';

    if (isAdmin) {
      h += '<div class="dr-card"><label style="display:flex;gap:8px;align-items:center;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="dr-rem"' + (d.reminders ? ' checked' : '') + '> '
        + 'Email directors on weekday mornings when a drill is still owed or was missed (off by default)</label></div>';
    }
    h += '</div>';
    main.innerHTML = h;

    var $ = function (id) { return main.querySelector('#' + id); };
    function fillRooms() {
      var cid = $('dr-c').value;
      $('dr-r').innerHTML = '<option value="">Whole centre</option>' + (d.rooms || []).filter(function (r) { return String(r.centre_id) === String(cid); }).map(function (r) { return '<option value="' + r.id + '">' + esc(r.name) + '</option>'; }).join('');
    }
    $('dr-c').addEventListener('change', fillRooms); fillRooms();
    $('dr-save').addEventListener('click', async function () {
      var b = this, msg = $('dr-msg'); b.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try {
        await Api.post('/drills', { centre_id: +$('dr-c').value, room_id: $('dr-r').value ? +$('dr-r').value : null, type: $('dr-t').value, drilled_at: $('dr-at').value,
          evacuation_seconds: $('dr-s').value === '' ? null : +$('dr-s').value, children_count: $('dr-k').value === '' ? null : +$('dr-k').value,
          staff_present: $('dr-st').value.trim() || null, notes: $('dr-n').value.trim() || null });
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
    var rem = $('dr-rem');
    if (rem) rem.addEventListener('change', function () {
      Api.post('/drills/reminders', { enabled: rem.checked }).then(function () { toast('🔔', rem.checked ? 'Drill reminders on' : 'Drill reminders off', '', '#16A34A'); })
        .catch(function (e) { rem.checked = !rem.checked; toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); });
    });
  }

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) { KT.Shell.registerScreen(r + ':drills', render); });
})(window);
