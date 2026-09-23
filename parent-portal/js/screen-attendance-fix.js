/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — correcting a missed check-in or check-out.

   Every live path stamps the time as "now", which is right at the door and wrong the
   next afternoon. A director who spots a missing sign-in could previously only sign
   the child in at the moment they noticed, writing a false arrival time into the
   record that ratios and billing are built on.

   This dialog records what actually happened, at the time it happened. It is not a
   quiet edit: the entry is flagged, carries who typed it, and is audited with both
   the time it happened and the time it was entered.

   Directors and admins only — the server checks that again regardless of who can
   reach this.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});

  function api(method, path, body) {
    var tok = null;
    try { tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) {}
    var h = { 'Authorization': 'Bearer ' + tok, 'Accept': 'application/json' };
    var aa = null;
    try { aa = sessionStorage.getItem('kt_active_agency_id'); } catch (e) {}
    if (aa) { h['X-Active-Agency-Id'] = aa; }
    if (body) { h['Content-Type'] = 'application/json'; }
    var base = (KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    return fetch(base + path, {
      method: method, headers: h, body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error(j.message || ('That did not work (' + r.status + ')')); }
        return j;
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
      + '-' + String(d.getDate()).padStart(2, '0');
  }

  /**
   * @param {{id:number, name:string}} child
   * @param {function=} onDone  called after anything changes, so the caller can refresh
   */
  function open(child, onDone, opts) {
    if (!child || !child.id) { return; }
    opts = opts || {};
    var startDate = opts.date || todayISO();

    var scrim = document.createElement('div');
    scrim.className = 'kt-scrim';
    scrim.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;'
      + 'display:flex;align-items:center;justify-content:center;padding:16px;';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;'
      + 'max-height:90vh;overflow:auto;box-shadow:0 20px 50px rgba(15,23,42,.25);';
    scrim.appendChild(box);

    var inp = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;'
      + 'border-radius:9px;font-size:15px;font-family:inherit;margin-top:4px;';
    var lbl = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin-top:14px;';

    box.innerHTML =
      '<div style="padding:20px 22px 0;">'
      + '<h3 style="margin:0 0 2px;font-size:18px;color:#0F172A;">Add a missed sign in or out</h3>'
      + '<div style="color:#64748B;font-size:13px;">' + esc(child.name || 'This child')
      + ' — recorded at the time it actually happened.</div>'
      + '<label style="' + lbl + '">Which day</label>'
      + '<input id="af-date" type="date" max="' + todayISO() + '" value="' + esc(startDate) + '" style="' + inp + '">'
      + '<div id="af-day" style="margin-top:12px;font-size:13px;"></div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
      +   '<div><label style="' + lbl + '">Arrived</label>'
      +     '<input id="af-in" type="time" style="' + inp + '"></div>'
      +   '<div><label style="' + lbl + '">Left</label>'
      +     '<input id="af-out" type="time" style="' + inp + '"></div>'
      + '</div>'
      + '<div style="font-size:11.5px;color:#64748B;margin-top:6px;">'
      +   'Fill in whichever is missing — you do not have to give both.</div>'
      + '<label style="' + lbl + '">Why is this being added late? '
      +   '<span style="color:#DC2626;">*</span></label>'
      + '<input id="af-reason" type="text" maxlength="300" required style="' + inp + '"'
      +   ' placeholder="e.g. Educator forgot to sign her in at drop-off">'
      + '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">'
      +   'Required. This goes on the permanent record with your name against it.</div>'
      + '<div style="margin-top:12px;padding:10px 12px;background:#FEF3C7;color:#92400E;'
      +   'border-radius:9px;font-size:12px;">This is recorded as a later correction, with your '
      +   'name and the time you entered it. The family is not notified.</div>'
      + '<div id="af-msg" style="font-size:13px;margin-top:10px;min-height:18px;"></div>'
      + '</div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;padding:16px 22px 20px;">'
      +   '<button id="af-cancel" type="button" style="padding:9px 16px;border-radius:9px;'
      +     'border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:13.5px;cursor:pointer;">Cancel</button>'
      +   '<button id="af-save" type="button" style="padding:9px 16px;border-radius:9px;border:0;'
      +     'background:#1F6080;color:#fff;font-weight:700;font-size:13.5px;cursor:pointer;">Save</button>'
      + '</div>';

    document.body.appendChild(scrim);
    var $ = function (id) { return box.querySelector('#' + id); };
    var msg = $('af-msg');
    var changed = false;

    /* Saying no, visibly.

       The dialog scrolls on a phone, and the message line sits near the bottom — so a
       refusal ("you have not said why") was written into a part of the page the reader
       could not see while looking at the time fields. From the other side of the glass
       that is a Save button that does nothing at all, which is exactly how it was
       reported. So a refusal now moves the page to the problem, marks the field, and
       raises a toast where the app has one. */
    function refuse(text, fieldId) {
      msg.style.color = '#B91C1C';
      msg.textContent = text;
      var el = fieldId ? $(fieldId) : null;
      if (el) {
        el.style.borderColor = '#DC2626';
        el.style.background = '#FEF2F2';
        try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
        try { el.focus({ preventScroll: true }); } catch (e) { try { el.focus(); } catch (e2) {} }
        var clear = function () {
          el.style.borderColor = '#CBD5E1';
          el.style.background = '';
          el.removeEventListener('input', clear);
          el.removeEventListener('change', clear);
        };
        el.addEventListener('input', clear);
        el.addEventListener('change', clear);
      } else {
        try { msg.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
      }
      try { if (window.KT && KT.Dom && KT.Dom.toast) { KT.Dom.toast(text, 'error'); } } catch (e) {}
    }

    function close() {
      try { document.body.removeChild(scrim); } catch (e) {}
      if (changed && typeof onDone === 'function') { onDone(); }
    }
    scrim.addEventListener('click', function (e) { if (e.target === scrim) { close(); } });
    $('af-cancel').addEventListener('click', close);

    /* What is already on file for that day. Shown before anything is typed, because
       the commonest correction is against a day that is half-recorded, and the server
       will refuse a duplicate anyway — better to see why first. */
    function loadDay() {
      var day = $('af-day');
      day.innerHTML = '<span style="color:#94A3B8;">Checking that day…</span>';
      api('GET', '/director/attendance/day?child_id=' + child.id + '&date=' + $('af-date').value)
        .then(function (d) {
          var evts = d.events || [];
          if (!evts.length) {
            day.innerHTML = '<span style="color:#64748B;">Nothing recorded for that day.</span>';
            return;
          }
          day.innerHTML = '<div style="font-weight:700;color:#334155;margin-bottom:4px;">Already recorded</div>';
          evts.forEach(function (e) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 0;'
              + 'border-top:1px solid #F1F5F9;';
            row.innerHTML = '<span style="flex:1;">'
              + (e.event_type === 'check_in' ? '→ Arrived ' : '← Left ') + esc(e.local_time)
              + (e.backdated ? ' <span style="color:#92400E;font-size:11.5px;">(added later)</span>' : '')
              + '<span style="color:#94A3B8;font-size:11.5px;"> · ' + esc(e.recorded_by || 'unknown') + '</span></span>';
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.textContent = 'Remove';
            rm.style.cssText = 'padding:4px 9px;border-radius:7px;border:1px solid #FECACA;'
              + 'background:#fff;color:#B91C1C;font-size:11.5px;font-weight:700;cursor:pointer;';
            rm.addEventListener('click', function () {
              if (!window.confirm('Remove this entry? The removal is recorded in the audit log.')) { return; }
              rm.disabled = true;
              api('DELETE', '/director/attendance/' + e.id)
                .then(function () { changed = true; loadDay(); })
                .catch(function (err) {
                  rm.disabled = false;
                  msg.style.color = '#B91C1C';
                  msg.textContent = err.message;
                });
            });
            row.appendChild(rm);
            day.appendChild(row);
          });
        })
        .catch(function (e) {
          day.innerHTML = '<span style="color:#B91C1C;">' + esc(e.message) + '</span>';
        });
    }
    $('af-date').addEventListener('change', loadDay);
    loadDay();

    $('af-save').addEventListener('click', function () {
      var btn = $('af-save');
      var body = {
        child_id: child.id,
        date: $('af-date').value,
        reason: $('af-reason').value.trim(),
      };
      if ($('af-in').value) { body.check_in = $('af-in').value; }
      if ($('af-out').value) { body.check_out = $('af-out').value; }

      if (!body.check_in && !body.check_out) {
        refuse('Give an arrival time, a departure time, or both.', 'af-in');
        return;
      }
      if (body.reason.length < 3) {
        refuse('Say why this is being added late — it goes on the record.', 'af-reason');
        return;
      }

      btn.disabled = true;
      var was = btn.textContent;
      btn.textContent = 'Saving…';
      msg.style.color = '#64748B';
      msg.textContent = '';
      api('POST', '/director/attendance/manual', body)
        .then(function (r) {
          /* Close it. Leaving the dialog open after a successful save was meant to let
             somebody add the other half of the day without reopening, but it reads as
             the save not having worked — the form is still sitting there. Confirm
             through a toast and get out of the way; the roster underneath refreshes
             via onDone. */
          changed = true;
          try {
            if (window.KT && KT.Dom && KT.Dom.toast) {
              KT.Dom.toast(r.message || 'Attendance recorded.', 'success');
            }
          } catch (e) {}
          close();
        })
        .catch(function (e) { refuse(e.message); })
        .then(function () { btn.disabled = false; btn.textContent = was; });
    });
  }

  KT.AttendanceFix = { open: open };
})(window);
