/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Privacy requests (2026-11-10, PIPEDA review).

   A person asks to see what the agency holds about them or their child,
   to correct it, to have it deleted, or to withdraw consent. PIPEDA
   Principle 4.9 and s. 8: answer within 30 days, one extension of up to
   30 more with notice, and a missed deadline counts as a refusal.
   This screen is the tracker: the list with due dates, one record per
   request (who asked, about whom, identity check, what was asked, the
   answer), a timeline, and a download of that person's or child's
   records as one file to work from.

   Agency admins only (API: PrivacyRequestController, scoped to the active
   agency). Nothing is ever deleted.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  var BASE = '/compliance/privacy-requests';
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var STATUS = { open: ['Open', '#BE4038', '#FBE9E6'], in_progress: ['In progress', '#AE7015', '#FBF1DC'], completed: ['Completed', '#1E8E60', '#E7F4EC'],
    refused: ['Refused', '#475569', '#F1F5F9'], withdrawn: ['Withdrawn', '#475569', '#F1F5F9'] };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  // YYYY-MM-DD from its parts - never new Date(date), which shifts a day west of UTC.
  function day(ymd) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
    return m ? MONTHS[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1] : '—';
  }
  function pill(status) {
    var s = STATUS[status] || [status, '#475569', '#F1F5F9'];
    return '<span style="font:700 11px/1 system-ui,sans-serif;padding:4px 9px;border-radius:100px;background:' + s[2] + ';color:' + s[1] + ';white-space:nowrap;">' + esc(s[0]) + '</span>';
  }
  function dueText(r) {
    if (r.done) return '<span style="color:#64748B;">' + (r.completed_on ? 'Answered ' + day(r.completed_on) : '—') + '</span>';
    var n = r.days_left;
    if (n < 0) return '<b style="color:#BE4038;">' + Math.abs(n) + ' day' + (Math.abs(n) === 1 ? '' : 's') + ' overdue</b>';
    return '<span style="color:' + (n <= 7 ? '#AE7015' : '#334155') + ';">' + day(r.answer_by) + (n <= 7 ? ' · ' + n + ' day' + (n === 1 ? '' : 's') + ' left' : '') + '</span>';
  }
  function toast(icon, title, msg, colour) { if (KT.toast) KT.toast(icon, title, msg || '', colour); }

  var INPUT = 'width:100%;box-sizing:border-box;height:30px;padding:4px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;background:#fff;color:#0F172A;';
  var AREA = 'width:100%;box-sizing:border-box;min-height:60px;padding:6px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#0F172A;';
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1E293B;font-size:12.5px;font-weight:600;cursor:pointer;';

  function field(label, html, wide, hint) {
    return '<label style="display:block;' + (wide ? 'grid-column:1/-1;' : '') + '"><span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">' + esc(label)
      + (hint ? ' <span style="font-weight:400;color:#94A3B8;">' + esc(hint) + '</span>' : '') + '</span>' + html + '</label>';
  }
  function text(name, v, max) { return '<input name="' + name + '" value="' + esc(v) + '" maxlength="' + (max || 200) + '" style="' + INPUT + '">'; }
  function date(name, v) { return '<input type="date" name="' + name + '" value="' + esc(v ? String(v).slice(0, 10) : '') + '" style="' + INPUT + '">'; }
  function area(name, v, rows) { return '<textarea name="' + name + '" rows="' + (rows || 3) + '" style="' + AREA + '">' + esc(v) + '</textarea>'; }
  function select(name, v, opts) {
    return '<select name="' + name + '" style="' + INPUT + '">' + opts.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(v == null ? '' : v) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }
  function section(title, intro, inner) {
    return '<div style="margin:0 0 16px;"><div style="font-size:13.5px;font-weight:700;color:#0F2540;margin:0 0 2px;">' + esc(title) + '</div>'
      + (intro ? '<div style="font-size:12px;color:#64748B;margin:0 0 8px;">' + intro + '</div>' : '')
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 14px;">' + inner + '</div></div>';
  }

  function formHtml(r, ctx) {
    r = r || {};
    var kinds = Object.keys(ctx.kinds || {}).map(function (k) { return [k, ctx.kinds[k]]; });
    // Whose information: a search over this agency's people and children. Hidden inputs carry the choice.
    var who = '<div data-pr-who style="grid-column:1/-1;" data-kt-nofb>'
      + '<span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">Whose information <span style="font-weight:400;color:#94A3B8;">a person or a child in this agency</span></span>'
      + '<input type="hidden" name="subject_type" value="' + esc(r.subject_type || '') + '"><input type="hidden" name="subject_id" value="' + esc(r.subject_id || '') + '">'
      + '<div data-pr-chosen style="display:' + (r.subject_id ? 'flex' : 'none') + ';align-items:center;gap:8px;margin:0 0 6px;font-size:13px;color:#0F172A;">'
      + '<b data-pr-chosen-label>' + esc(r.subject_label || '') + '</b><button type="button" data-pr-clear style="' + BTN + 'height:26px;padding:0 9px;">Change</button></div>'
      + '<div data-pr-search style="display:' + (r.subject_id ? 'none' : 'block') + ';"><input data-pr-q placeholder="Type a name…" autocomplete="off" style="' + INPUT + '">'
      + '<div data-pr-results style="margin:4px 0 0;"></div></div></div>';

    return section('The request', '', ''
        + field('What they are asking', select('kind', r.kind || 'access', kinds))
        + field('Received on', date('received_on', r.received_on || ctx.today), false, 'starts the 30 days')
        + field('Status', select('status', r.status || 'open', [['open', 'Open'], ['in_progress', 'In progress'], ['completed', 'Completed'], ['refused', 'Refused'], ['withdrawn', 'Withdrawn']]))
        + field('Asked by', text('requester_name', r.requester_name, 160))
        + field('They are', select('requester_relation', r.requester_relation || 'self', [['self', 'The person themselves'], ['parent', 'Parent or guardian of the child'], ['representative', 'Someone acting for them']]))
        + who
        + field('What they asked for', area('details', r.details, 3), true))
      + section('Identity', 'Confirm who is asking before giving anything out, using details you already hold. Do not collect more than you need to check.', ''
        + field('Confirmed on', date('identity_verified_on', r.identity_verified_on))
        + field('How', text('identity_method', r.identity_method, 200), false, 'e.g. in person with photo ID'))
      + section('Time', 'Answer within 30 days. You can extend once, by up to 30 more days, if the request is large or needs consultation; tell the person why and that they may complain to the Privacy Commissioner.', ''
        + field('Extended to', date('extended_to', r.extended_to))
        + field('Why it was extended', area('extension_reason', r.extension_reason, 2), true))
      + section('Answer', 'What was given, corrected or deleted - or, for a refusal, the reasons and that they may complain to the Privacy Commissioner.', ''
        + field('Answer given', area('response', r.response, 3), true)
        + field('Answered on', date('completed_on', r.completed_on)));
  }

  function wireWho(root) {
    var box = root.querySelector('[data-pr-who]');
    if (!box) return;
    var q = box.querySelector('[data-pr-q]'), out = box.querySelector('[data-pr-results]');
    var type = box.querySelector('[name="subject_type"]'), sid = box.querySelector('[name="subject_id"]');
    var timer = null, seq = 0;
    function choose(t, id, label) {
      type.value = t || ''; sid.value = id || '';
      box.querySelector('[data-pr-chosen-label]').textContent = label || '';
      box.querySelector('[data-pr-chosen]').style.display = id ? 'flex' : 'none';
      box.querySelector('[data-pr-search]').style.display = id ? 'none' : 'block';
      out.innerHTML = ''; q.value = '';
    }
    box.querySelector('[data-pr-clear]').onclick = function () { choose('', '', ''); q.focus(); };
    q.oninput = function () {
      clearTimeout(timer);
      var term = q.value.trim();
      if (term.length < 2) { out.innerHTML = ''; return; }
      timer = setTimeout(function () {
        var mine = ++seq;
        Api.get(BASE + '/subjects?q=' + encodeURIComponent(term)).then(function (r) {
          if (mine !== seq) return;
          var all = (r.people || []).concat(r.children || []);
          out.innerHTML = all.length ? all.map(function (s, i) {
            return '<button type="button" data-i="' + i + '" style="display:block;width:100%;text-align:left;padding:6px 8px;border:0;border-top:1px solid #EEF2F6;background:#fff;font-size:13px;color:#0F172A;cursor:pointer;">'
              + (s.type === 'child' ? '🧒 ' : '👤 ') + esc(s.label) + '</button>';
          }).join('') : '<div style="font-size:12.5px;color:#64748B;padding:6px 2px;">No one in this agency by that name.</div>';
          out.querySelectorAll('[data-i]').forEach(function (b) {
            b.onclick = function () { var s = all[+b.getAttribute('data-i')]; choose(s.type, s.id, s.label); };
          });
        }).catch(function () {});
      }, 250);
    };
  }

  function collect(root) {
    var out = {};
    root.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (el) {
      var v = el.value;
      if (el.name === 'subject_id') v = v === '' ? null : parseInt(v, 10);
      else if (v === '' && ['requester_name', 'details', 'received_on'].indexOf(el.name) < 0) v = null;
      out[el.name] = v;
    });
    return out;
  }

  function timelineHtml(events) {
    if (!events || !events.length) return '<div style="font-size:12.5px;color:#64748B;">Nothing yet.</div>';
    return events.slice().reverse().map(function (e) {
      var icon = e.kind === 'note' ? '📝' : (e.kind === 'created' ? '🆕' : (e.kind === 'exported' ? '⤓' : '✏️'));
      return '<div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid #EEF2F6;">'
        + '<div style="flex:0 0 auto;font-size:14px;">' + icon + '</div><div style="min-width:0;">'
        + '<div style="font-size:12px;color:#64748B;">' + esc(e.at || '') + ' · ' + esc(e.by || '') + '</div>'
        + '<div style="font-size:13px;color:#0F172A;white-space:pre-wrap;word-break:break-word;">' + esc(e.body) + '</div></div></div>';
    }).join('');
  }

  function download(btn, r, after) {
    if (!KT.fetchFileBlob) return;
    var base = KT.API_BASE || 'https://api.kiddietrac.com/api/v1';
    btn.disabled = true;
    KT.fetchFileBlob(base + BASE + '/' + r.id + '/export').then(function (b) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([b], { type: 'application/json' }));
      a.download = 'privacy-request-' + r.ref + '.json';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
      toast('✅', 'Records downloaded', 'Review it and remove other people’s information before giving it out.', '#16A34A');
      if (after) after();
    }).catch(function (e) { toast('⚠️', 'Download failed', e && e.message, '#DC2626'); })
      .then(function () { btn.disabled = false; });
  }

  function openRecord(main, ctx, id) {
    var wrap = document.createElement('div');
    var isNew = !id;
    function reload() {
      Api.get(BASE + '/' + id).then(function (r) { paint(r.request, r.events); }).catch(function () {});
    }
    function paint(r, events) {
      var due = (r && r.to_do) || [];
      wrap.innerHTML = (r ? '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px;font-size:12.5px;color:#475569;">'
            + '<b style="font-family:ui-monospace,monospace;color:#0F2540;">' + esc(r.ref) + '</b>' + pill(r.status)
            + '<span>' + (r.done ? 'Closed' : 'Answer by <b>' + day(r.answer_by) + '</b>') + '</span>'
            + '<button type="button" data-pr-export style="' + BTN + 'margin-left:auto;"' + (r.subject_id ? '' : ' disabled title="Choose whose information it is about first"')
            + ' data-kttip="Their records in this agency as one file, to review before giving out">⤓ Download their records</button></div>'
            + (due.length ? '<div style="background:#FBF1DC;border:1px solid #F0DCA8;color:#7A4F0E;border-radius:9px;padding:8px 12px;font-size:12.5px;margin:0 0 14px;"><b>Still to do:</b> ' + due.map(esc).join(' · ') + '</div>' : '')
          : '<div style="font-size:12.5px;color:#475569;margin:0 0 12px;">Log the request the day it arrives - by email, letter, phone or in person. '
            + 'The 30 days start then. Fill in what you know; you can come back and add to it.</div>')
        + '<form data-pr-form onsubmit="return false">' + formHtml(r, ctx) + '</form>'
        + (r ? '<div style="margin:6px 0 0;"><div style="font-size:13.5px;font-weight:700;color:#0F2540;margin:0 0 6px;">Steps and changes</div>'
            + '<div style="display:flex;gap:8px;margin:0 0 6px;" data-kt-nofb><textarea data-pr-note rows="2" maxlength="4000" placeholder="Add a step, e.g. Sent the records by email" style="' + AREA + 'min-height:30px;flex:1;"></textarea>'
            + '<button type="button" data-pr-addnote style="' + BTN + 'flex:0 0 auto;align-self:flex-start;">Add step</button></div>'
            + '<div data-pr-timeline>' + timelineHtml(events) + '</div></div>' : '');
      wireWho(wrap);
      var xb = wrap.querySelector('[data-pr-export]');
      if (xb) xb.onclick = function () { download(xb, r, reload); };
      var add = wrap.querySelector('[data-pr-addnote]');
      if (add) add.onclick = function () {
        var ta = wrap.querySelector('[data-pr-note]'), body = (ta.value || '').trim();
        if (!body) { ta.focus(); return; }
        add.disabled = true;
        Api.post(BASE + '/' + r.id + '/notes', { body: body }).then(function (res) {
          ta.value = '';
          wrap.querySelector('[data-pr-timeline]').innerHTML = timelineHtml(res.events);
          render(main);
        }).catch(function (e) { toast('⚠️', 'Could not add the step', e && e.message, '#DC2626'); })
          .then(function () { add.disabled = false; });
      };
    }

    var modal = Shell.Modal.open({
      title: isNew ? 'Log a privacy request' : 'Privacy request',
      body: wrap,
      large: true,
      actions: [
        { label: 'Close' },
        { label: isNew ? 'Log request' : 'Save changes', primary: true, busyLabel: 'Saving…', handler: function () {
          var data = collect(wrap.querySelector('[data-pr-form]'));
          if (!data.requester_name || !data.details || !data.received_on) {
            toast('⚠️', 'A few things are needed', 'Asked by, Received on and What they asked for.', '#DC2626');
            return false;
          }
          var call = isNew ? Api.post(BASE, data) : Api.patch(BASE + '/' + id, data);
          return call.then(function (res) {
            toast('✅', isNew ? 'Request logged' : 'Saved', res && res.request ? res.request.ref : '', '#16A34A');
            render(main);
            if (isNew && res && res.request) { setTimeout(function () { openRecord(main, ctx, res.request.id); }, 0); return; }
            paint(res.request, res.events);
            return false;
          }).catch(function (e) { toast('⚠️', 'Could not save', e && e.message, '#DC2626'); return false; });
        } }
      ]
    });

    if (isNew) { paint(null, []); return modal; }
    wrap.innerHTML = '<div style="padding:20px;color:#64748B;font-size:13px;">Loading…</div>';
    Api.get(BASE + '/' + id).then(function (r) { paint(r.request, r.events); })
      .catch(function (e) { wrap.innerHTML = '<div style="padding:20px;color:#BE4038;font-size:13px;">' + esc((e && e.message) || 'Could not open this request.') + '</div>'; });
    return modal;
  }

  function tile(n, label, colour) {
    return '<div style="background:#fff;border:1px solid #E7EBF0;border-radius:12px;padding:10px 16px;min-width:130px;">'
      + '<div style="font:600 24px/1 ui-monospace,monospace;color:' + colour + ';">' + n + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:5px;">' + esc(label) + '</div></div>';
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    var head = '<div class="kt-page-hero"><h2>📥 Privacy requests</h2><p>Requests to see, correct or delete personal information, or to withdraw consent. '
      + 'PIPEDA gives 30 days to answer; a missed deadline counts as a refusal.</p></div>';
    if (!main.querySelector('[data-pr-root]')) main.innerHTML = '<div class="kt-section" data-pr-root>' + head + '<p style="color:#64748B;font-size:13px;">Loading…</p></div>';
    var data;
    try { data = await Api.get(BASE); }
    catch (e) {
      main.innerHTML = '<div class="kt-section" data-pr-root>' + head
        + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:14px 16px;max-width:640px;font-size:13px;">'
        + esc((e && e.message) || 'Only an agency admin can see privacy requests.') + '</div></div>';
      return;
    }
    var list = data.requests || [], c = data.counts || {};
    var rows = list.length ? list.map(function (r) {
      return '<tr>'
        + '<td style="font-family:ui-monospace,monospace;font-size:12.5px;white-space:nowrap;">' + esc(r.ref) + '</td>'
        + '<td><b style="font-size:13px;">' + esc(r.kind_label) + '</b><div style="font-size:12px;color:#64748B;">' + esc(r.subject_label || 'Not chosen yet') + '</div></td>'
        + '<td style="white-space:nowrap;">' + day(r.received_on) + '</td>'
        + '<td>' + pill(r.status) + '</td>'
        + '<td style="white-space:nowrap;">' + dueText(r) + '</td>'
        + '<td style="text-align:right;"><button type="button" data-pr-open="' + r.id + '" style="' + BTN + '">Open ' + esc(r.ref) + '</button></td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="6" style="padding:28px;text-align:center;color:#64748B;">No privacy requests yet. When someone asks to see, correct or delete their information, log it here the same day.</td></tr>';

    var warn = c.overdue ? '<div style="background:#FBE9E6;border:1px solid #F2C9C3;color:#8F2A23;border-radius:10px;padding:9px 14px;font-size:13px;margin:0 0 10px;">'
      + '<b>' + c.overdue + '</b> request' + (c.overdue === 1 ? ' is' : 's are') + ' past the deadline. Under PIPEDA that counts as a refusal - answer as soon as possible.</div>' : '';

    main.innerHTML = '<div class="kt-section" data-pr-root>' + head
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 14px;">'
      +   tile(c.open || 0, 'Open', c.open ? '#AE7015' : '#1E8E60')
      +   tile(c.due_soon || 0, 'Due within 7 days', c.due_soon ? '#AE7015' : '#1E8E60')
      +   tile(c.overdue || 0, 'Overdue', c.overdue ? '#BE4038' : '#1E8E60')
      +   tile(c.kept || 0, 'On record', '#0F2540')
      +   '<div style="margin-left:auto;display:flex;gap:8px;">'
      +     '<button type="button" data-pr-new style="' + BTN + 'background:#0F2540;border-color:#0F2540;color:#fff;">+ Log a request</button>'
      +   '</div>'
      + '</div>'
      + warn
      + '<div class="kt-card"><table><thead><tr><th>Ref</th><th>Request</th><th>Received</th><th>Status</th><th>Answer by</th><th></th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div></div>';

    var ctx = { kinds: data.kinds, today: data.today };
    main.querySelector('[data-pr-new]').onclick = function () { openRecord(main, ctx, null); };
    main.querySelectorAll('[data-pr-open]').forEach(function (b) {
      b.onclick = function () { openRecord(main, ctx, +b.getAttribute('data-pr-open')); };
    });
    // The bell links to #privacy-requests?id=N: open that record once, then drop the id.
    var m = /[?&]id=(\d+)/.exec(location.hash || '');
    if (m) {
      try { history.replaceState(null, '', location.pathname + location.search + '#privacy-requests'); } catch (e) {}
      openRecord(main, ctx, +m[1]);
    }
  }

  ['agency_admin', 'platform_admin'].forEach(function (role) {
    Shell.registerScreen(role + ':privacy-requests', render);
  });
})(window);
