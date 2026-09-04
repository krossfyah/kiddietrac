/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Manual payroll run.
   Pay several people for one period, in one pass.  KT.ManualPayroll.open()

   Payroll has only ever arrived from outside — iLearn pushes documents in through the
   integration. This is how an agency pays somebody the platform itself knows about: a
   casual shift, a one-off bonus, the bookkeeper's invoice, or an agency that does not
   run iLearn at all.

   THE HOURS ARE NEVER TYPED. The clock already knows what each person worked in the
   period; the screen reads it and puts it beside a rate, so the only thing entered by
   hand is money. Every punch behind a total travels onto the payslip with it.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var C = {
    ink: '#0F172A', muted: '#64748B', faint: '#94A3B8', rule: '#E2E8F0', line: '#F1F5F9',
    accent: '#2563EB', good: '#16A34A', warn: '#B45309', bad: '#B91C1C'
  };

  /* Three groups, because they are paid for different reasons and the form each one
     needs is different. Educators and other staff have a clock; contractors do not —
     they invoice, and there is nothing to read. */
  var TABS = [
    { k: 'educators', label: 'Educators' },
    { k: 'other', label: 'Other staff' },
    { k: 'contractors', label: 'Contractors' }
  ];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function money(n) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'CAD' }).format(n); }
    catch (e) { return '$' + n.toFixed(2); }
  }
  function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
      + '-' + String(d.getDate()).padStart(2, '0');
  }

  /* An educator is an educator; everyone else who holds a staff role is "other". The
     split follows the staff_group the payroll documents already use. */
  function groupOf(row) {
    return /educator|provider/i.test(row.role_label || '') ? 'educators' : 'other';
  }

  function open(onDone) {
    var wrap = Dom.el('div', { style: 'width:100%;' });
    var lbl = 'display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:' + C.muted + ';margin-bottom:4px;';
    var fld = 'width:100%;padding:8px 11px;border:1px solid ' + C.rule + ';border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;';

    var now = new Date();
    var firstOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    var lastOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    wrap.innerHTML =
      '<p style="margin:0 0 14px;font-size:13.5px;color:#334155;line-height:1.6;">'
      + 'Choose the period. The hours each person worked are read from the clock — you enter the money.</p>'
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
      + '<div style="flex:1;min-width:150px;"><label for="mp-from" style="' + lbl + '">Period start</label>'
      + '<input id="mp-from" type="date" value="' + ymd(firstOfMonth) + '" style="' + fld + '"></div>'
      + '<div style="flex:1;min-width:150px;"><label for="mp-to" style="' + lbl + '">Period end</label>'
      + '<input id="mp-to" type="date" value="' + ymd(lastOfMonth) + '" style="' + fld + '"></div>'
      + '</div>'
      + '<div id="mp-msg" style="margin-top:12px;font-size:13px;color:' + C.bad + ';"></div>';

    Shell.Modal.open({
      title: 'Generate payroll',
      body: wrap,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Read the clock', style: 'btn-primary', busyLabel: 'Reading…',
          handler: function () {
            var from = wrap.querySelector('#mp-from').value;
            var to = wrap.querySelector('#mp-to').value;
            var msg = wrap.querySelector('#mp-msg');
            if (!from || !to) { msg.textContent = 'Both dates are needed.'; return false; }

            return Api.get('/admin/payroll/manual/prepare?period_start=' + from + '&period_end=' + to)
              .then(function (d) {
                setTimeout(function () { openRun(d, from, to, onDone); }, 60);

                return true;
              })
              .catch(function (e) {
                var why = (e && e.data && e.data.message) || (e && e.message) || 'The clock could not be read.';
                msg.textContent = why;
                throw new Error(why);
              });
          },
        },
      ],
    });
  }

  function openRun(data, from, to, onDone) {
    /* One row per payable person, held here until Save. Nothing is written while this
       is open, so closing it leaves no half-made payroll behind. */
    var rows = {};
    (data.staff || []).forEach(function (s) {
      rows['u' + s.user_id] = {
        key: 'u' + s.user_id, kind: 'staff', group: groupOf(s),
        user_id: s.user_id, name: s.name, sub: s.role_label,
        hours: s.hours, shifts: s.shifts, open_punches: s.open_punches,
        hours_detail: s.hours_detail, centre_id: s.centre_id,
        /* Only ever an HOURLY rate — the server refuses to carry forward a flat
           payout, because hours x flat-amount proposes six-figure pay. */
        rate: s.last_rate || null, last_paid: s.last_paid || null,
        gross: null, net: null,
        lines: [], notes: '', picked: false
      };
    });
    (data.contractors || []).forEach(function (c) {
      rows['c' + c.payee_name] = {
        key: 'c' + c.payee_name, kind: 'contractor', group: 'contractors',
        payee_name: c.payee_name, name: c.payee_name,
        sub: c.documents + ' paid before · last ' + money(c.last_net),
        hours: null, rate: null, gross: null, net: null,
        lines: [], notes: '', picked: false
      };
    });

    var tab = 'educators';
    /* Which person's lines are open, if any. Shell.Modal.open() REPLACES the modal
       rather than stacking, so a second dialog would destroy this run and lose every
       amount typed so far — the detail is drawn in the same body instead. */
    var detailFor = null;
    var wrap = Dom.el('div', { style: 'width:100%;' });

    function inGroup(k) {
      return Object.keys(rows).map(function (x) { return rows[x]; })
        .filter(function (r) { return r.group === k; });
    }
    function picked() {
      return Object.keys(rows).map(function (x) { return rows[x]; }).filter(function (r) { return r.picked; });
    }
    function lineTotal(r) {
      return (r.lines || []).reduce(function (a, l) { return a + (Number(l.amount) || 0); }, 0);
    }

    function paint() {
      if (detailFor) { paintDetail(rows[detailFor]); return; }
      var list = inGroup(tab);
      var chosen = picked();
      var totalGross = chosen.reduce(function (a, r) { return a + (Number(r.gross) || 0); }, 0);

      var tabs = TABS.map(function (t) {
        var n = inGroup(t.k).length;
        var sel = inGroup(t.k).filter(function (r) { return r.picked; }).length;
        var on = t.k === tab;
        return '<button type="button" data-tab="' + t.k + '" style="border:none;background:none;cursor:pointer;'
          + 'padding:8px 12px;font-size:12.5px;font-weight:700;border-bottom:2px solid '
          + (on ? C.accent : 'transparent') + ';color:' + (on ? C.accent : C.muted) + ';">'
          + t.label + ' <span style="opacity:.6;">' + n + '</span>'
          + (sel ? ' <span style="color:' + C.good + ';">· ' + sel + ' picked</span>' : '') + '</button>';
      }).join('');

      var body = list.length ? list.map(function (r) {
        var cells =
          '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';">'
          + '<input type="checkbox" data-pick="' + esc(r.key) + '"' + (r.picked ? ' checked' : '') + ' style="width:16px;height:16px;"></td>'
          + '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';">'
          + '<strong>' + esc(r.name) + '</strong>'
          + '<div style="font-size:11.5px;color:' + C.muted + ';">' + esc(r.sub || '') + '</div></td>';

        if (r.kind === 'staff') {
          cells += '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';white-space:nowrap;">'
            + (r.hours ? '<strong>' + r.hours.toFixed(2) + ' h</strong><div style="font-size:11px;color:' + C.muted + ';">'
                + r.shifts + ' shift(s)</div>'
              : '<span style="color:' + C.faint + ';">no shifts</span>')
            /* An open punch has no duration, so it is excluded from the total — said
               out loud, because a total that looks short with no explanation is worse
               than one that is wrong. */
            + (r.open_punches ? '<div style="font-size:11px;color:' + C.warn + ';">'
                + r.open_punches + ' still clocked in — not counted</div>' : '')
            /* Context, never arithmetic: what they were last paid in total, so a
               sensible figure can be chosen without it ever being multiplied. */
            + (r.last_paid ? '<div style="font-size:11px;color:' + C.faint + ';">last paid '
                + money(r.last_paid.net) + '</div>' : '')
            + '</td>'
            + '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';">'
            + '<input type="number" step="0.01" min="0" data-rate="' + esc(r.key) + '" value="' + (r.rate == null ? '' : r.rate) + '" '
            + 'placeholder="rate" style="width:88px;padding:5px 8px;border:1px solid ' + C.rule + ';border-radius:6px;font-size:13px;text-align:right;"></td>';
        } else {
          cells += '<td colspan="2" style="padding:8px 10px;border-top:1px solid ' + C.line + ';color:' + C.faint + ';font-size:12px;">'
            + 'Invoiced — no clock to read</td>';
        }

        cells += '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';text-align:right;">'
          + '<input type="number" step="0.01" min="0" data-gross="' + esc(r.key) + '" value="' + (r.gross == null ? '' : r.gross) + '" '
          + 'placeholder="amount" style="width:110px;padding:5px 8px;border:1px solid ' + C.rule + ';border-radius:6px;font-size:13px;text-align:right;">'
          + (lineTotal(r) ? '<div style="font-size:11px;color:' + C.muted + ';">incl. ' + money(lineTotal(r)) + ' in lines</div>' : '')
          + '</td>'
          + '<td style="padding:8px 10px;border-top:1px solid ' + C.line + ';text-align:right;white-space:nowrap;">'
          + '<button type="button" data-detail="' + esc(r.key) + '" style="border:1px solid ' + C.rule + ';background:#fff;'
          + 'border-radius:6px;padding:4px 9px;font-size:12px;cursor:pointer;">Lines & notes</button></td>';

        return '<tr>' + cells + '</tr>';
      }).join('') : '<tr><td colspan="6" style="padding:26px;text-align:center;color:' + C.faint
        + ';">Nobody in this group.</td></tr>';

      var th = 'padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:' + C.muted
        + ';text-transform:uppercase;letter-spacing:.5px;background:#F8FAFC;';

      wrap.innerHTML =
        '<div style="font-size:13px;color:#334155;margin-bottom:10px;">Period '
        + esc(from) + ' to ' + esc(to) + '. Tick who is being paid, then enter what they are paid.</div>'
        + '<div style="display:flex;gap:4px;border-bottom:1px solid ' + C.rule + ';margin-bottom:10px;flex-wrap:wrap;">' + tabs + '</div>'
        + '<div style="max-height:44vh;overflow:auto;border:1px solid ' + C.rule + ';border-radius:10px;">'
        + '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>'
        + '<th style="' + th + '"></th><th style="' + th + '">Who</th>'
        + '<th style="' + th + '">Worked</th><th style="' + th + '">Rate</th>'
        + '<th style="' + th + 'text-align:right;">Pay</th><th style="' + th + '"></th>'
        + '</tr></thead><tbody>' + body + '</tbody></table></div>'
        + '<div style="display:flex;align-items:center;gap:14px;margin-top:12px;flex-wrap:wrap;">'
        + '<span style="font-size:13px;color:#334155;">' + chosen.length + ' selected across all groups'
        + (chosen.length ? ' · <strong>' + money(totalGross) + '</strong>' : '') + '</span>'
        + '</div>'
        + '<div id="mp-rmsg" style="margin-top:10px;font-size:13px;color:' + C.bad + ';"></div>';

      wrap.querySelectorAll('button[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); paint(); });
      });
      wrap.querySelectorAll('input[data-pick]').forEach(function (el) {
        el.addEventListener('change', function () {
          var r = rows[el.getAttribute('data-pick')];
          r.picked = el.checked;
          /* Seed the pay from hours × rate the first time somebody is ticked. It is a
             starting point they can overwrite, never a figure that files itself. */
          if (r.picked && r.gross == null && r.hours && r.rate) {
            r.gross = Math.round(r.hours * r.rate * 100) / 100;
          }
          paint();
        });
      });
      wrap.querySelectorAll('input[data-rate]').forEach(function (el) {
        el.addEventListener('change', function () {
          var r = rows[el.getAttribute('data-rate')];
          r.rate = el.value === '' ? null : parseFloat(el.value);
          if (r.hours && r.rate) { r.gross = Math.round(r.hours * r.rate * 100) / 100; }
          paint();
        });
      });
      wrap.querySelectorAll('input[data-gross]').forEach(function (el) {
        el.addEventListener('change', function () {
          rows[el.getAttribute('data-gross')].gross = el.value === '' ? null : parseFloat(el.value);
          paint();
        });
      });
      wrap.querySelectorAll('button[data-detail]').forEach(function (b) {
        b.addEventListener('click', function () { detailFor = b.getAttribute('data-detail'); paint(); });
      });
    }

    /* One person's line items and note, in the same body as the run. Every edit writes
       straight into the row object the run already holds, so returning loses nothing. */
    function paintDetail(row) {
      var total = (row.lines || []).reduce(function (a, l) { return a + (Number(l.amount) || 0); }, 0);
      wrap.innerHTML =
        '<button type="button" id="mp-back" style="border:1px solid ' + C.rule + ';background:#fff;color:#334155;'
        + 'border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:700;cursor:pointer;margin-bottom:12px;">'
        + '\u2039 Return to the run</button>'
        + '<p style="margin:0 0 12px;font-size:13.5px;color:#334155;"><strong>' + esc(row.name) + '</strong>'
        + (row.hours ? ' \u00b7 ' + row.hours.toFixed(2) + ' h from the clock' : '') + '</p>'
        + ((row.lines || []).length
          ? '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px;">'
            + row.lines.map(function (l, i) {
              return '<tr><td style="padding:5px 0;"><input data-l="' + i + '" value="' + esc(l.label) + '" '
                + 'placeholder="what it is for" style="width:100%;padding:5px 8px;border:1px solid ' + C.rule + ';border-radius:6px;font-size:13px;"></td>'
                + '<td style="padding:5px 0 5px 8px;width:120px;"><input data-la="' + i + '" type="number" step="0.01" value="' + l.amount + '" '
                + 'style="width:110px;padding:5px 8px;border:1px solid ' + C.rule + ';border-radius:6px;font-size:13px;text-align:right;"></td>'
                + '<td style="padding:5px 0 5px 8px;width:34px;"><button type="button" data-lx="' + i + '" '
                + 'style="border:1px solid #FECACA;background:#fff;color:' + C.bad + ';border-radius:6px;padding:3px 8px;font-size:12px;cursor:pointer;">\u00d7</button></td></tr>';
            }).join('') + '</table>'
          : '<div style="font-size:12.5px;color:' + C.faint + ';margin-bottom:8px;">No line items yet. '
            + 'Add one for anything the pay is made of — mileage, a top-up, a deduction.</div>')
        + '<button type="button" id="mp-addline" style="border:1px solid ' + C.rule + ';background:#fff;color:#334155;'
        + 'border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:700;cursor:pointer;">+ Add a line</button>'
        + (total ? '<span style="margin-left:12px;font-size:13px;color:' + C.muted + ';">Lines total <strong>'
            + money(total) + '</strong></span>' : '')
        + '<label for="mp-notes" style="display:block;font-size:11px;font-weight:800;text-transform:uppercase;'
        + 'letter-spacing:.5px;color:' + C.muted + ';margin:14px 0 4px;">Notes on this payslip</label>'
        + '<textarea id="mp-notes" rows="3" style="width:100%;padding:8px 11px;border:1px solid ' + C.rule
        + ';border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;resize:vertical;">'
        + esc(row.notes || '') + '</textarea>';

      wrap.querySelector('#mp-back').addEventListener('click', function () {
        var n = wrap.querySelector('#mp-notes');
        if (n) { row.notes = n.value.trim(); }
        row.lines = (row.lines || []).filter(function (l) { return String(l.label).trim() !== ''; });
        detailFor = null;
        paint();
      });
      wrap.querySelectorAll('input[data-l]').forEach(function (el) {
        el.addEventListener('change', function () { row.lines[+el.getAttribute('data-l')].label = el.value; });
      });
      wrap.querySelectorAll('input[data-la]').forEach(function (el) {
        el.addEventListener('change', function () {
          row.lines[+el.getAttribute('data-la')].amount = parseFloat(el.value) || 0;
          paint();
        });
      });
      wrap.querySelectorAll('button[data-lx]').forEach(function (el) {
        el.addEventListener('click', function () { row.lines.splice(+el.getAttribute('data-lx'), 1); paint(); });
      });
      wrap.querySelector('#mp-addline').addEventListener('click', function () {
        row.lines = row.lines || [];
        row.lines.push({ label: '', amount: 0 });
        paint();
      });
    }

    paint();

    Shell.Modal.open({
      title: 'Payroll run — ' + from + ' to ' + to,
      body: wrap,
      large: true,
      actions: [
        { label: 'Cancel' },
        {
          label: 'Approve and create payslips', style: 'btn-primary', busyLabel: 'Creating…',
          handler: function () {
            /* Approving from inside somebody's line items would save a run the
               person cannot see. Return to it first. */
            if (detailFor) { detailFor = null; paint(); return false; }
            var chosen = picked();
            var msg = wrap.querySelector('#mp-rmsg');
            if (!chosen.length) { msg.textContent = 'Nobody is selected.'; return false; }
            var missing = chosen.filter(function (r) { return !(Number(r.gross) > 0); });
            if (missing.length) {
              msg.textContent = missing.length + ' selected row(s) have no amount: '
                + missing.slice(0, 3).map(function (r) { return r.name; }).join(', ')
                + (missing.length > 3 ? '…' : '');
              return false;
            }

            return Api.post('/admin/payroll/manual', {
              period_start: from,
              period_end: to,
              rows: chosen.map(function (r) {
                var row = {
                  gross: Number(r.gross),
                  notes: r.notes || null,
                  lines: r.lines && r.lines.length ? r.lines : null,
                  staff_group: r.group,
                };
                if (r.kind === 'contractor') { row.payee_name = r.payee_name; }
                else {
                  row.user_id = r.user_id;
                  row.hours = r.hours;
                  row.rate = r.rate;
                  row.centre_id = r.centre_id;
                  row.hours_detail = r.hours_detail;
                }

                return row;
              }),
            }).then(function (res) {
              if (Dom.toast) {
                Dom.toast(res.created + ' payslip(s) created · ' + money(res.total_net) + ' net');
              }
              /* A skipped row is not a failure to hide — it is usually somebody who
                 already has a payslip for this period, which is exactly what the
                 duplicate guard is for. */
              if (res.skipped && res.skipped.length && Dom.toast) {
                Dom.toast(res.skipped.length + ' row(s) skipped — ' + (res.skipped[0].why || ''), 'error');
              }
              if (typeof onDone === 'function') { onDone(); }

              return true;
            }).catch(function (e) {
              var why = (e && e.data && e.data.message) || (e && e.message) || 'The run could not be saved.';
              msg.textContent = why;
              throw new Error(why);
            });
          },
        },
      ],
    });
  }

  KT.ManualPayroll = { open: open };
})(window);
