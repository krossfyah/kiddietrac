/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Photo permissions, staff view (2026-11-06).

   Every child the person can see, with the photo permission that applies
   (App\Support\MediaConsent): the most careful answer any parent gave, or
   "family only" while nobody has answered. Educators read it, because they
   hold the camera; admins and directors can also record a parent's signed
   paper form and show photos again that were hidden for no permission once
   the family allows it. API: MediaConsentController (/admin/photo-consent).
   Parents answer in the portal (kt-photo-consent.js asks them).
   Only the PRIMARY parent's answer counts (2026-11-06, MediaConsent::decidersFor); another parent's
   earlier answer is listed greyed as "not counted". KT.photoConsentPanel draws the same rows for one
   family or one parent inside their record (screen-admin.js).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || {};
  var Shell = KT.Shell, Api = KT.Api;
  if (!Shell || !Api) return;

  var BASE = '/admin/photo-consent';
  var LEVEL = {
    none: ['Do not photograph', '#BE4038', '#FBE9E6', '🚫'],
    family: ['Family only', '#AE7015', '#FBF1DC', '👪'],
    group: ['Group photos OK', '#1E8E60', '#E7F4EC', '✅'],
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function toast(icon, title, msg, colour) { if (KT.toast) KT.toast(icon, title, msg || '', colour); }
  function pill(level) {
    var l = LEVEL[level] || [level, '#475569', '#F1F5F9', ''];
    return '<span style="font:700 11px/1 system-ui,sans-serif;padding:4px 9px;border-radius:100px;background:' + l[2] + ';color:' + l[1] + ';white-space:nowrap;">' + l[3] + ' ' + esc(l[0]) + '</span>';
  }
  var INPUT = 'width:100%;box-sizing:border-box;height:30px;padding:4px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;background:#fff;color:#0F172A;';
  var BTN = 'height:28px;padding:0 10px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;color:#1E293B;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;';

  function tile(n, label, colour, key, on) {
    return '<button type="button" data-pc-filter="' + key + '" style="text-align:left;background:#fff;border:1px solid ' + (on ? colour : '#E7EBF0') + ';box-shadow:' + (on ? 'inset 0 0 0 1px ' + colour : 'none')
      + ';border-radius:12px;padding:10px 16px;min-width:130px;cursor:pointer;">'
      + '<div style="font:600 24px/1 ui-monospace,monospace;color:' + colour + ';">' + n + '</div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:5px;">' + esc(label) + '</div></button>';
  }

  var filter = 'all', query = '';

  function answersText(k) {
    var prim = (k.guardians || []).filter(function (g) { return g.decides; }).map(function (g) { return g.first_name || 'Parent'; }).join(' / ');
    if (!k.answers.length) return '<span style="color:#AE7015;">' + esc(prim ? prim + ' (primary) has not answered' : 'Nobody yet') + '</span>';
    return k.answers.map(function (a) {
      var l = LEVEL[a.level] || [a.level];
      if (a.counts === false) {
        return '<div style="white-space:nowrap;color:#94A3B8;" data-kttip="Only the primary parent\'s answer counts">' + esc(a.first_name || 'Parent') + ': ' + esc(l[0]) + ' (not counted)</div>';
      }
      return '<div style="white-space:nowrap;">' + esc(a.first_name || 'Parent') + ((k.guardians || []).some(function (g) { return g.user_id === a.user_id && g.primary; }) ? ' <span style="color:#0E7C90;">(primary)</span>' : '') + ': ' + esc(l[0])
        + (a.source === 'paper' ? ' <span style="color:#94A3B8;" data-kttip="Recorded from a signed paper form">(paper)</span>' : '')
        + (a.source === 'onboarding' ? ' <span style="color:#94A3B8;" data-kttip="From their answer when they joined">(sign-up)</span>' : '') + '</div>';
    }).join('') + (!k.answered && prim ? '<div style="color:#AE7015;">' + esc(prim) + ' (primary) has not answered</div>' : '');
  }
  function deciders(k) { return (k.guardians || []).filter(function (g) { return g.decides; }); }

  function openPaper(main, k, labels, after) {
    var body = document.createElement('div');
    body.innerHTML = '<p style="margin:0 0 12px;font-size:13px;color:#334155;">Record the answer the primary parent gave on a signed paper form for <b>' + esc(k.name)
      + '</b>. It counts the same as answering in the portal. Only the primary parent chooses photo permission.</p>'
      + '<label style="display:block;margin:0 0 10px;"><span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">Primary parent</span>'
      + '<select name="user_id" style="' + INPUT + '">' + deciders(k).map(function (g) { return '<option value="' + g.user_id + '">' + esc(g.first_name || 'Parent') + '</option>'; }).join('') + '</select></label>'
      + '<div style="font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">Their answer</div>'
      + ['group', 'family', 'none'].map(function (lv) {
        return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;padding:4px 0;"><input type="radio" name="level" value="' + lv + '" style="width:15px;height:15px;margin:0;">' + esc(labels[lv] || lv) + '</label>';
      }).join('')
      + '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;color:#334155;margin:8px 0 10px;"><input type="checkbox" name="marketing" style="width:15px;height:15px;margin:0;">They also agreed to marketing use</label>'
      + '<label style="display:block;"><span style="display:block;font-size:12px;font-weight:600;color:#475569;margin:0 0 4px;">Where the form is <span style="font-weight:400;color:#94A3B8;">e.g. "signed form dated Nov 6, in the child\'s file"</span></span>'
      + '<input name="note" maxlength="300" style="' + INPUT + '"></label>'
      + '<div data-pc-err style="display:none;margin-top:10px;color:#BE4038;font-size:12.5px;"></div>';
    body.addEventListener('change', function () {
      var lv = body.querySelector('input[name=level]:checked'), mk = body.querySelector('input[name=marketing]');
      mk.disabled = !!lv && lv.value === 'none'; if (mk.disabled) mk.checked = false;
    });
    Shell.Modal.open({
      title: 'Record a paper form',
      body: body,
      actions: [
        { label: 'Cancel' },
        { label: 'Save', primary: true, busyLabel: 'Saving…', handler: async function () {
          var lv = body.querySelector('input[name=level]:checked'), note = body.querySelector('input[name=note]').value.trim(), err = body.querySelector('[data-pc-err]');
          if (!lv || !note) { err.textContent = !lv ? 'Choose their answer.' : 'Say where the signed form is kept.'; err.style.display = ''; return false; }
          try {
            await Api.post(BASE + '/' + k.id, { user_id: +body.querySelector('select[name=user_id]').value, level: lv.value, marketing: body.querySelector('input[name=marketing]').checked, note: note });
          } catch (e) { err.textContent = (e && e.message) || 'That did not save.'; err.style.display = ''; return false; }
          toast('🤳', 'Recorded', 'The paper form is on record.', '#1E8E60');
          (after || render)(main);
        } },
      ],
    });
  }

  async function showPhotos(main, k, btn, after) {
    btn.disabled = true;
    try {
      var r = await Api.post(BASE + '/' + k.id + '/show-photos', {});
      toast('🤳', 'Done', r.shown ? r.shown + ' photo' + (r.shown === 1 ? ' is' : 's are') + ' showing again.' : 'Nothing to show: another child on those photos still has no permission.', '#1E8E60');
      (after || render)(main);
    } catch (e) { btn.disabled = false; toast('⚠️', 'Not done', e && e.message, '#DC2626'); }
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    var head = '<div class="kt-page-hero"><h2>🤳 Photo permissions</h2><p>What each family allows for photos and videos of their child. '
      + 'The primary parent chooses; until they answer, the child is in photos for their own family only.</p></div>';
    if (!main.querySelector('[data-pc-root]')) main.innerHTML = '<div class="kt-section" data-pc-root>' + head + '<p style="color:#64748B;font-size:13px;">Loading…</p></div>';
    var data;
    try { data = await Api.get(BASE); }
    catch (e) {
      main.innerHTML = '<div class="kt-section" data-pc-root>' + head + '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:12px;padding:14px 16px;max-width:640px;font-size:13px;">'
        + esc((e && e.message) || 'Could not load photo permissions.') + '</div></div>';
      return;
    }
    draw(main, data, head);
  }

  function draw(main, data, head) {
    var all = data.children || [], c = data.counts || {}, q = query.toLowerCase();
    var list = all.filter(function (k) {
      if (filter === 'unanswered' ? k.answered !== 0 : (filter !== 'all' && k.level !== filter)) return false;
      return !q || (k.name + ' ' + (k.centre || '')).toLowerCase().indexOf(q) >= 0;
    });
    var rows = list.length ? list.map(function (k) {
      var acts = '';
      if (data.can_edit && deciders(k).length) acts += '<button type="button" data-pc-paper="' + k.id + '" style="' + BTN + '" data-kttip="Record a signed paper form">✍ Paper form</button>';
      if (data.can_edit && k.hidden_photos && k.level !== 'none') acts += ' <button type="button" data-pc-show="' + k.id + '" style="' + BTN + '">Show ' + k.hidden_photos + ' hidden</button>';
      return '<tr>'
        + '<td><b style="font-size:13px;">' + esc(k.name) + '</b>' + (k.centre ? '<div style="font-size:12px;color:#64748B;">' + esc(k.centre) + '</div>' : '') + '</td>'
        + '<td>' + pill(k.level) + (k.answered === 0 ? '<div style="font-size:11.5px;color:#AE7015;margin-top:4px;">until the primary parent answers</div>' : '') + '</td>'
        + '<td style="font-size:12.5px;">' + answersText(k) + '</td>'
        + '<td style="font-size:12.5px;">' + (k.marketing ? 'Yes' : '<span style="color:#94A3B8;">No</span>') + '</td>'
        + '<td style="font-size:12.5px;">' + (k.hidden_photos ? '<span data-kttip="Hidden from every family, kept on file">' + k.hidden_photos + ' hidden</span>' : '<span style="color:#94A3B8;">—</span>') + '</td>'
        + '<td style="text-align:right;white-space:nowrap;">' + acts + '</td></tr>';
    }).join('') : '<tr><td colspan="6" style="padding:28px;text-align:center;color:#64748B;">No children match.</td></tr>';

    main.innerHTML = '<div class="kt-section" data-pc-root>' + head
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 12px;" data-kt-nofb>'
      +   tile(all.length, 'All children', '#0F2540', 'all', filter === 'all')
      +   tile(c.none || 0, 'Do not photograph', '#BE4038', 'none', filter === 'none')
      +   tile(c.family || 0, 'Family only', '#AE7015', 'family', filter === 'family')
      +   tile(c.group || 0, 'Group photos OK', '#1E8E60', 'group', filter === 'group')
      +   tile(c.unanswered || 0, 'Primary parent not answered', '#2C7BA0', 'unanswered', filter === 'unanswered')
      +   '<input type="search" data-pc-q placeholder="Find a child…" value="' + esc(query) + '" style="' + INPUT + 'width:220px;margin-left:auto;">'
      + '</div>'
      + '<div class="kt-card"><table><thead><tr><th>Child</th><th>Photo permission</th><th>Parents\' answers</th><th>Marketing</th><th>Photos</th><th></th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div></div>';

    main.querySelectorAll('[data-pc-filter]').forEach(function (b) { b.onclick = function () { filter = b.getAttribute('data-pc-filter'); draw(main, data, head); }; });
    var qi = main.querySelector('[data-pc-q]'), t = null;
    qi.oninput = function () {
      clearTimeout(t);
      t = setTimeout(function () {
        query = qi.value.trim(); draw(main, data, head);
        var n = main.querySelector('[data-pc-q]'); if (n) { n.focus(); n.setSelectionRange(n.value.length, n.value.length); }
      }, 200);
    };
    var byId = {}; all.forEach(function (k) { byId[k.id] = k; });
    main.querySelectorAll('[data-pc-paper]').forEach(function (b) { b.onclick = function () { openPaper(main, byId[+b.getAttribute('data-pc-paper')], data.labels || {}); }; });
    main.querySelectorAll('[data-pc-show]').forEach(function (b) { b.onclick = function () { showPhotos(main, byId[+b.getAttribute('data-pc-show')], b); }; });
  }

  /* PHOTO PERMISSIONS IN A RECORD (2026-11-06, Anthony: "add those details to the families and user profile as
     well"). The same rows as the screen for one family ({family_id}) or one parent's children ({user_id}), with the
     paper form and show-hidden buttons for admins and directors. host is emptied and filled. */
  async function panel(host, q) {
    var qs = q.family_id ? 'family_id=' + encodeURIComponent(q.family_id) : 'user_id=' + encodeURIComponent(q.user_id);
    var again = function () { panel(host, q); };
    host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">Loading photo permissions…</div>';
    var data;
    try { data = await Api.get(BASE + '?' + qs); }
    catch (e) { host.innerHTML = '<div style="color:#BE4038;font-size:13px;padding:8px 0;">' + esc((e && e.message) || 'Could not load photo permissions.') + '</div>'; return; }
    var kids = data.children || [];
    if (!kids.length) { host.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px 0;">No children are linked yet.</div>'; return; }
    host.innerHTML = '<div style="font-size:12.5px;color:#64748B;margin:0 0 8px;">The primary parent chooses. Until they answer, a child is in photos for their own family only.</div>'
      + kids.map(function (k) {
        var acts = '';
        if (data.can_edit && deciders(k).length) acts += '<button type="button" data-pc-paper="' + k.id + '" style="' + BTN + '" data-kttip="Record a signed paper form">✍ Paper form</button>';
        if (data.can_edit && k.hidden_photos && k.level !== 'none') acts += ' <button type="button" data-pc-show="' + k.id + '" style="' + BTN + '">Show ' + k.hidden_photos + ' hidden</button>';
        return '<div style="border:1px solid #E7EBF0;border-radius:12px;padding:10px 12px;margin:0 0 8px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:flex-start;">'
          + '<div style="flex:1 1 160px;min-width:0;"><b style="font-size:13.5px;color:#0F2540;">' + esc(k.name) + '</b>'
          +   '<div style="margin-top:5px;">' + pill(k.level) + '</div>'
          +   (k.answered === 0 ? '<div style="font-size:11.5px;color:#AE7015;margin-top:4px;">until the primary parent answers</div>' : '') + '</div>'
          + '<div style="flex:1 1 180px;font-size:12.5px;"><div style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Answers</div>' + answersText(k) + '</div>'
          + '<div style="flex:0 0 auto;font-size:12.5px;"><div style="font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Marketing</div>' + (k.marketing ? 'Yes' : '<span style="color:#94A3B8;">No</span>') + '</div>'
          + (acts ? '<div style="flex:0 0 auto;display:flex;gap:6px;align-self:center;" data-kt-view-ok>' + acts + '</div>' : '')
          + '</div>';
      }).join('');
    var byId = {}; kids.forEach(function (k) { byId[k.id] = k; });
    host.querySelectorAll('[data-pc-paper]').forEach(function (b) { b.onclick = function () { openPaper(host, byId[+b.getAttribute('data-pc-paper')], data.labels || {}, again); }; });
    host.querySelectorAll('[data-pc-show]').forEach(function (b) { b.onclick = function () { showPhotos(host, byId[+b.getAttribute('data-pc-show')], b, again); }; });
  }
  KT.photoConsentPanel = panel;

  ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (role) {
    Shell.registerScreen(role + ':photo-permissions', render);
  });
})(window);
