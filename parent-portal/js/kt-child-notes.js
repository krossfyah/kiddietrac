/* ═══════════════════════════════════════════════════════════════════════
   kt-child-notes.js — staff notes on a child's record (2026-10-09)

   Anthony: "allow educators to add notes to child record and see time/days and can edit if
   required which will trigger a notification to admins/directors (email as well)" — and for
   home visitors too. One renderer, used by the educator/home-visitor child record and by the
   director/admin child screen's Notes tab:
     KT.renderChildNotes(container, childId)
   Each note shows who wrote it and the day and time (agency timezone); an edited note says so,
   with its history one tap away. Adding and editing notify admins/directors (server side).
   Staff only — parents never see these.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function api() { return KT.Api; }
  function when(t, tz) {
    if (!t) return '';
    try {
      var d = KT.parseTs ? KT.parseTs(t) : new Date(String(t).replace(' ', 'T') + 'Z');
      return new Intl.DateTimeFormat('en-CA', { timeZone: tz || (KT.tz ? KT.tz() : 'America/Toronto'), weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(d);
    } catch (e) { return String(t); }
  }
  var ROLE = { educator: 'Educator', home_visitor: 'Home visitor', centre_director: 'Director', agency_admin: 'Admin', platform_admin: 'Admin' };
  var BTN = 'height:30px;padding:0 12px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;';

  KT.renderChildNotes = function (container, childId) {
    container.innerHTML = '<div style="color:#64748B;font-size:13px;padding:6px 0;">Loading notes…</div>';
    var tz = null;

    function load() {
      api().get('/provider/children/' + childId + '/notes').then(function (d) {
        tz = d.tz; paint(d.notes || []);
      }).catch(function (e) {
        container.innerHTML = '<div style="color:#B91C1C;font-size:13px;">' + esc((e && e.message) || 'Could not load notes.') + '</div>';
      });
    }

    function paint(notes) {
      container.innerHTML = ''
        + '<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;">'
        +   '<textarea data-new rows="2" placeholder="Add a note — what happened, what you noticed, what the next educator should know…" '
        +     'style="flex:1;min-width:0;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:8px 10px;font-size:13.5px;font-family:inherit;resize:vertical;"></textarea>'
        +   '<button type="button" data-add style="' + BTN + 'background:#1F6080;color:#fff;border:0;">Add note</button>'
        + '</div>'
        + '<div data-msg style="font-size:12px;min-height:14px;margin:-4px 0 6px;"></div>'
        + '<div style="font-size:11.5px;color:#94A3B8;margin-bottom:8px;">Staff only — parents never see notes. Directors and admins are notified when a note is added or edited.</div>'
        + (notes.length ? '' : '<div style="color:#64748B;font-size:13px;">No notes yet.</div>')
        + notes.map(function (n) {
            return '<div data-note="' + n.id + '" style="border-top:1px solid #F1F5F9;padding:10px 0;">'
              + '<div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:12px;color:#64748B;">'
              +   '<b style="color:#0F172A;font-size:13px;">' + esc(n.author) + '</b>'
              +   (n.author_role && ROLE[n.author_role] ? '<span>' + ROLE[n.author_role] + '</span>' : '')
              +   '<span>· ' + esc(when(n.created_at, tz)) + '</span>'
              +   (n.edit_count ? '<button type="button" data-hist="' + n.id + '" style="border:0;background:none;color:#B45309;font-size:12px;cursor:pointer;padding:0;text-decoration:underline;">Edited ' + esc(when(n.edited_at, tz)) + (n.edited_by ? ' by ' + esc(n.edited_by) : '') + '</button>' : '')
              +   (n.can_edit ? '<button type="button" data-edit="' + n.id + '" style="margin-left:auto;border:0;background:none;color:#1F6080;font-weight:700;font-size:12.5px;cursor:pointer;">Edit</button>' : '')
              + '</div>'
              + '<div data-body style="font-size:14px;color:#0F172A;line-height:1.55;margin-top:4px;white-space:pre-wrap;overflow-wrap:anywhere;">' + esc(n.body) + '</div>'
              + '</div>';
          }).join('');
      var ta = container.querySelector('[data-new]'), msg = container.querySelector('[data-msg]');
      container.querySelector('[data-add]').onclick = function () {
        var b = this, body = (ta.value || '').trim();
        if (body.length < 2) { msg.style.color = '#B91C1C'; msg.textContent = 'Write a note first.'; ta.focus(); return; }
        b.disabled = true;
        api().post('/provider/children/' + childId + '/notes', { body: body }).then(function () {
          if (KT.toast) KT.toast('📝', 'Note added', 'Directors and admins have been told.', '#16A34A');
          load();
        }).catch(function (e) { b.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save.'; });
      };
      container.querySelectorAll('[data-edit]').forEach(function (btn) {
        btn.onclick = function () {
          var id = btn.getAttribute('data-edit');
          var row = container.querySelector('[data-note="' + id + '"]');
          var cur = (notes.filter(function (n) { return String(n.id) === id; })[0] || {}).body || '';
          var bodyEl = row.querySelector('[data-body]');
          bodyEl.innerHTML = '<textarea rows="3" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:8px 10px;font-size:13.5px;font-family:inherit;">' + esc(cur) + '</textarea>'
            + '<div style="display:flex;gap:6px;margin-top:6px;align-items:center;flex-wrap:wrap;"><button type="button" data-save style="' + BTN + 'background:#1F6080;color:#fff;border:0;">Save change</button>'
            + '<button type="button" data-cancel style="' + BTN + 'background:#fff;color:#334155;border:1px solid #CBD5E1;">Cancel</button>'
            + '<span style="font-size:11.5px;color:#B45309;">Directors and admins will see the before and after.</span></div>';
          bodyEl.querySelector('[data-cancel]').onclick = function () { load(); };
          bodyEl.querySelector('[data-save]').onclick = function () {
            var v = (bodyEl.querySelector('textarea').value || '').trim();
            if (v.length < 2) return;
            this.disabled = true;
            api().patch('/provider/child-notes/' + id, { body: v }).then(function (r) {
              if (KT.toast) KT.toast('✏️', r && r.unchanged ? 'No change' : 'Note updated', r && r.unchanged ? '' : 'Directors and admins have been told.', '#16A34A');
              load();
            }).catch(function (e) { if (KT.toast) KT.toast('⚠️', 'Could not save', (e && e.message) || '', '#DC2626'); load(); });
          };
        };
      });
      container.querySelectorAll('[data-hist]').forEach(function (btn) {
        btn.onclick = function () {
          var id = btn.getAttribute('data-hist');
          api().get('/provider/child-notes/' + id + '/history').then(function (d) {
            var M = KT.Shell && KT.Shell.Modal; if (!M) return;
            var box = document.createElement('div');
            box.innerHTML = (d.revisions || []).map(function (r) {
              return '<div style="border-top:1px solid #F1F5F9;padding:9px 0;font-size:13px;">'
                + '<div style="color:#64748B;font-size:12px;">' + esc(r.editor || 'Staff') + ' · ' + esc(when(r.created_at, tz)) + '</div>'
                + '<div style="margin-top:4px;background:#FEF2F2;border-radius:7px;padding:6px 8px;white-space:pre-wrap;color:#7F1D1D;">' + esc(r.body_before) + '</div>'
                + '<div style="margin-top:4px;background:#F0FDF4;border-radius:7px;padding:6px 8px;white-space:pre-wrap;color:#14532D;">' + esc(r.body_after) + '</div></div>';
            }).join('') || '<div style="color:#64748B;">No earlier versions.</div>';
            M.open({ title: 'Note history', body: box, actions: [{ label: 'Close', primary: true }] });
          }).catch(function () {});
        };
      });
    }
    load();
  };
})(window);
