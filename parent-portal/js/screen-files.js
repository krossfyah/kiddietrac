/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Files between educators and families (#files), 2026-10-09
   Anthony: "add a send/receive files for educators and parents where they can exchange
   files such as printables and homework etc".

   Received / Sent tabs. Staff send to the families they work with (one, several, or
   everyone in their room); a parent sends back to their child's provider. Files are
   private: they are fetched with the signed-in session and shown in the portal's own
   viewer (KT.viewBlob) or saved. Row actions are plain buttons in the last cell —
   kt-row-actions.js turns them into the ⋮ menu on wide screens (CONVENTIONS.md).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT || (window.KT = {});

  function api() { return KT.Api; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, t, m || '', c || '#1F6080'); } catch (e) {} }
  function size(n) { n = +n || 0; return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function icon(name, mime) {
    var e = String(name || '').split('.').pop().toLowerCase();
    if (/^image\//.test(mime || '') || /^(png|jpe?g|gif|webp|heic)$/.test(e)) return '🖼️';
    if (e === 'pdf') return '📕';
    if (/^(docx?|rtf|txt)$/.test(e)) return '📝';
    if (/^(xlsx?|csv)$/.test(e)) return '📊';
    if (/^pptx?$/.test(e)) return '📽️';
    return '📎';
  }
  function when(t) {
    if (!t) return '';
    try {
      var d = KT.parseTs ? KT.parseTs(t) : new Date(String(t).replace(' ', 'T') + 'Z');
      return new Intl.DateTimeFormat('en-CA', { timeZone: KT.tz ? KT.tz() : 'America/Toronto', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(d);
    } catch (e) { return t; }
  }
  function headers() {
    var h = { Accept: '*/*' };
    var t = (KT.Auth && KT.Auth.token && KT.Auth.token()) || sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    if (t) h.Authorization = 'Bearer ' + t;
    var aa = sessionStorage.getItem('kt_active_agency_id'); if (aa) h['X-Active-Agency-Id'] = aa;
    return h;
  }
  function apiBase() { return (KT.API_BASE || 'https://api.kiddietrac.com/api/v1'); }

  var CSS = 'kt-files-css';
  function ensureCss() {
    if (document.getElementById(CSS)) return;
    var s = document.createElement('style'); s.id = CSS;
    s.textContent = ''
      + '.ff-wrap{padding:20px 24px;max-width:1800px;margin:0 auto;}'
      + '.ff-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 0 10px;}'
      + '.ff-tab{height:32px;padding:0 14px;border-radius:999px;border:1px solid #CBD5E1;background:#fff;color:#334155;font-weight:700;font-size:13px;cursor:pointer;}'
      + '.ff-tab.on{background:#1F6080;border-color:#1F6080;color:#fff;}'
      + '.ff-b{height:30px;padding:0 12px;border-radius:8px;border:1px solid #CBD5E1;background:#fff;font-weight:700;font-size:12.5px;cursor:pointer;color:#0F172A;}'
      + '.ff-b.p{background:#1F6080;border-color:#1F6080;color:#fff;}'
      + '.ff-b.d{color:#B91C1C;}'
      + '.ff-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;}'
      + '.ff-tbl{width:100%;border-collapse:collapse;font-size:13.5px;}'
      + '.ff-tbl th{background:#F8FAFC;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;text-align:left;padding:8px 12px;}'
      + '.ff-tbl td{padding:9px 12px;border-top:1px solid #EEF2F6;vertical-align:middle;}'
      + '.ff-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2EA9AC;margin-right:6px;vertical-align:middle;}'
      + '.ff-drop{border:2px dashed #CBD5E1;border-radius:12px;padding:18px;text-align:center;color:#64748B;font-size:13px;cursor:pointer;}'
      + '.ff-drop.over{border-color:#2EA9AC;background:#F0FDFA;}'
      + '.ff-l{display:block;font-size:11.5px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 4px;}'
      + '.ff-i{width:100%;box-sizing:border-box;height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;background:#fff;font-family:inherit;}'
      + '.ff-sm-meta{display:none;}'
      + '@media (max-width:600px){.ff-wrap{padding:12px 10px;}.ff-tbl .ff-hide-sm{display:none;}.ff-sm-meta{display:inline;}}';
    document.head.appendChild(s);
  }

  var state = { box: 'received' };
  function render(main, ctx) {
    ensureCss();
    var role = (ctx && ctx.role) || '';
    var isParent = role === 'guardian';
    var base = isParent ? '/parent/files' : '/provider/files';
    main.innerHTML = '<div class="ff-wrap">'
      + '<div class="kt-hero" style="background:linear-gradient(135deg,#0F3A4F 0%,#1F6080 55%,#2EA9AC 100%);">'
      +   '<div class="kt-hero-greet">📎 FILES</div><h1>Files</h1>'
      +   '<div class="kt-hero-sub">' + (isParent
            ? 'Printables, homework and notes from your child\'s provider, and anything you send back.'
            : 'Send printables, homework and notes to the families you work with, and see what they send back.') + '</div></div>'
      + '<div class="ff-bar"><button type="button" class="ff-tab" data-box="received">Received</button><button type="button" class="ff-tab" data-box="sent">Sent</button>'
      + '<span style="flex:1;"></span><button type="button" class="ff-b p" data-send>📤 Send files</button></div>'
      + '<div id="ff-list" class="ff-card"><div style="padding:18px;color:#64748B;">Loading…</div></div></div>';
    main.querySelectorAll('.ff-tab').forEach(function (b) { b.onclick = function () { state.box = b.getAttribute('data-box'); load(); }; });
    main.querySelector('[data-send]').onclick = function () { openSend(isParent, base, load); };

    function load() {
      main.querySelectorAll('.ff-tab').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-box') === state.box); });
      var box = main.querySelector('#ff-list');
      api().get(base + '?box=' + state.box).then(function (d) {
        var rows = d.files || [];
        var recvBtn = main.querySelector('[data-box="received"]');
        if (recvBtn) recvBtn.textContent = 'Received' + (d.unread ? ' (' + d.unread + ' new)' : '');
        if (!rows.length) {
          box.innerHTML = '<div style="padding:26px;text-align:center;color:#64748B;font-size:13.5px;">' + (state.box === 'sent'
            ? 'Nothing sent yet. Use <b>Send files</b> to share a printable or homework.' : 'No files yet.') + '</div>';
          return;
        }
        box.innerHTML = '<div style="overflow-x:auto;"><table class="ff-tbl"><thead><tr><th>File</th><th class="ff-hide-sm">' + (state.box === 'sent' ? 'To' : 'From') + '</th><th class="ff-hide-sm">Date</th><th class="ff-hide-sm">Size</th><th></th></tr></thead><tbody>'
          + rows.map(function (f, i) {
              var who = state.box === 'sent' ? ((f.family ? f.family + ' family' : 'Your provider') + (f.child ? ' · ' + f.child : '')) : (f.from + (!isParent && f.family ? ' · ' + f.family + ' family' : ''));
              return '<tr data-i="' + i + '"><td><div style="display:flex;gap:9px;align-items:flex-start;">'
                + '<span style="font-size:20px;line-height:1;">' + icon(f.name, f.mime) + '</span><div style="min-width:0;">'
                + '<div style="font-weight:700;color:#0F172A;">' + (f.new ? '<span class="ff-dot" title="New"></span>' : '') + esc(f.title) + '</div>'
                + (f.note ? '<div style="font-size:12.5px;color:#475569;margin-top:2px;">' + esc(f.note) + '</div>' : '')
                + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(f.name) + '<span class="ff-sm-meta"> · ' + esc(who) + ' · ' + esc(when(f.created_at)) + '</span></div></div></div></td>'
                + '<td class="ff-hide-sm" style="color:#334155;">' + esc(who) + '</td>'
                + '<td class="ff-hide-sm" style="white-space:nowrap;color:#475569;">' + esc(when(f.created_at)) + '</td>'
                + '<td class="ff-hide-sm" style="white-space:nowrap;color:#64748B;">' + size(f.size) + '</td>'
                + '<td style="white-space:nowrap;text-align:right;">'
                +   '<button type="button" class="ff-b" data-act="view" data-i="' + i + '">View</button> '
                +   '<button type="button" class="ff-b" data-act="dl" data-i="' + i + '">Download</button>'
                +   (f.mine ? ' <button type="button" class="ff-b d" data-act="del" data-i="' + i + '">Delete</button>' : '')
                + '</td></tr>';
            }).join('') + '</tbody></table></div>';
        box.querySelectorAll('[data-act]').forEach(function (b) {
          b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var f = rows[+b.getAttribute('data-i')], act = b.getAttribute('data-act');
            if (act === 'del') return remove(f);
            fetchFile(f, act === 'view');
          });
        });
      }).catch(function (e) { box.innerHTML = '<div style="padding:18px;color:#B91C1C;">' + esc((e && e.message) || 'Could not load files.') + '</div>'; });
    }

    function fetchFile(f, view) {
      fetch(apiBase() + base + '/' + f.id + '/download' + (view ? '?inline=1' : ''), { headers: headers() })
        .then(function (r) { if (!r.ok) throw new Error(r.status === 410 ? 'This file is no longer available.' : 'HTTP ' + r.status); return r.blob(); })
        .then(function (blob) {
          var canView = /pdf|image\//.test(f.mime || blob.type || '');
          if (view && canView && KT.viewBlob) { KT.viewBlob(blob, { title: f.title, label: f.name }); }
          else {
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = f.name;
            document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 800);
            if (view && !canView) toast('📥', 'Downloaded', 'This kind of file opens in its own app.');
          }
          if (f.new) { f.new = false; setTimeout(load, 400); }
        })
        .catch(function (e) { toast('⚠️', 'Could not open the file', (e && e.message) || '', '#DC2626'); });
    }

    async function remove(f) {
      var ok = KT.confirm ? await KT.confirm({ title: 'Delete this file?', description: '"' + f.title + '" will be removed for everyone it was sent to.', okLabel: 'Delete' }) : window.confirm('Delete this file?');
      if (!ok) return;
      api().delete(base + '/' + f.id).then(function () { toast('🗑', 'File deleted'); load(); }).catch(function (e) { toast('⚠️', 'Could not delete', (e && e.message) || '', '#DC2626'); });
    }

    load();
  }

  /* The send window: files (drop or pick, up to 10, 20 MB each), a title and note, and —
     for staff — who to send to. Optional pre-pick: { familyId, childId, childName }. */
  async function openSend(isParent, base, done, pre) {
    var M = KT.Shell && KT.Shell.Modal;
    if (!M) return;
    pre = pre || {};
    var rec = null;
    if (!isParent) {
      try { rec = await api().get(base + '/recipients'); } catch (e) { toast('⚠️', 'Could not load your families', (e && e.message) || '', '#DC2626'); return; }
      if (!rec.families || !rec.families.length) { toast('ℹ️', 'No families to send to', 'You can send files to the families of children in your rooms.'); return; }
    }
    var picked = [];
    var box = document.createElement('div');
    box.innerHTML = '<div class="ff-drop" data-drop>📄 Drop files here, or <u>choose files</u><div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">PDF, pictures, Word, Excel, PowerPoint or text · up to 10 files, 20 MB each</div></div>'
      + '<input type="file" multiple data-pick style="display:none" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.heic,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf">'
      + '<div data-chosen style="margin-top:8px;font-size:13px;"></div>'
      + '<label class="ff-l">Title (optional)</label><input class="ff-i" data-title maxlength="150" placeholder="e.g. Pumpkin colouring page">'
      + '<label class="ff-l">Note (optional)</label><textarea class="ff-i" data-note rows="3" maxlength="1000" style="height:auto;padding:8px 10px;" placeholder="' + (isParent ? 'e.g. Homework for Monday' : 'e.g. Something fun to colour in over the weekend') + '"></textarea>'
      + (isParent ? '<div style="font-size:12.5px;color:#64748B;margin-top:10px;">Goes to your child\'s provider.</div>'
        : '<label class="ff-l">Send to</label>'
          + ((rec.my_rooms || []).length ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">' + rec.my_rooms.map(function (r) {
                return '<button type="button" class="ff-b" data-room="' + r.id + '">All families in ' + esc(r.name) + '</button>'; }).join('') + '</div>' : '')
          + '<div data-fams style="max-height:220px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:4px 10px;">'
          + rec.families.map(function (f) {
              var on = pre.familyId && +pre.familyId === f.family_id;
              return '<label style="display:flex;gap:8px;align-items:center;padding:6px 0;border-top:1px solid #F1F5F9;font-size:13.5px;cursor:pointer;">'
                + '<input type="checkbox" data-fam="' + f.family_id + '" data-rooms="' + (f.room_ids || []).join(',') + '"' + (on ? ' checked' : '') + '>'
                + '<span><b>' + esc(f.family || 'Family') + '</b> <span style="color:#64748B;">· ' + esc(f.children.map(function (c) { return c.name; }).join(', ')) + '</span></span></label>';
            }).join('') + '</div>')
      + '<div data-out style="font-size:13px;margin-top:8px;"></div>';
    var drop = box.querySelector('[data-drop]'), pick = box.querySelector('[data-pick]'), chosen = box.querySelector('[data-chosen]'), out = box.querySelector('[data-out]');
    function add(list) {
      [].forEach.call(list || [], function (f) {
        if (picked.length >= 10) return;
        if (!picked.some(function (p) { return p.name === f.name && p.size === f.size; })) picked.push(f);
      });
      chosen.innerHTML = picked.map(function (f, i) {
        return '<div style="display:flex;gap:8px;align-items:center;padding:3px 0;"><span>' + icon(f.name, f.type) + '</span><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(f.name)
          + '</span><span style="color:' + (f.size > 20971520 ? '#B91C1C' : '#94A3B8') + ';">' + size(f.size) + '</span><button type="button" data-x="' + i + '" style="border:0;background:none;color:#94A3B8;cursor:pointer;font-size:16px;">×</button></div>';
      }).join('');
      chosen.querySelectorAll('[data-x]').forEach(function (b) { b.onclick = function () { picked.splice(+b.getAttribute('data-x'), 1); add([]); }; });
    }
    drop.onclick = function () { pick.click(); };
    pick.onchange = function () { add(pick.files); pick.value = ''; };
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { add(e.dataTransfer && e.dataTransfer.files); });
    var roomPicked = null;
    box.querySelectorAll('[data-room]').forEach(function (b) {
      b.onclick = function () {
        var rid = b.getAttribute('data-room');
        roomPicked = roomPicked === rid ? null : rid;
        box.querySelectorAll('[data-room]').forEach(function (x) { x.classList.toggle('p', x.getAttribute('data-room') === roomPicked); });
        box.querySelectorAll('[data-fam]').forEach(function (c) { if (roomPicked && (',' + c.getAttribute('data-rooms') + ',').indexOf(',' + roomPicked + ',') > -1) c.checked = true; });
      };
    });
    M.open({
      title: '📤 Send files', body: box,
      actions: [{ label: 'Cancel' }, { label: 'Send', primary: true, busyLabel: 'Sending…', handler: function () {
        out.style.color = '#B91C1C';
        if (!picked.length) { out.textContent = 'Choose at least one file.'; return false; }
        if (picked.some(function (f) { return f.size > 20971520; })) { out.textContent = 'Each file can be at most 20 MB — remove the large one.'; return false; }
        var fd = new FormData();
        picked.forEach(function (f) { fd.append('files[]', f); });
        fd.append('title', box.querySelector('[data-title]').value || '');
        fd.append('note', box.querySelector('[data-note]').value || '');
        if (!isParent) {
          var ids = [].map.call(box.querySelectorAll('[data-fam]:checked'), function (c) { return c.getAttribute('data-fam'); });
          if (!ids.length && !roomPicked) { out.textContent = 'Choose who to send to.'; return false; }
          ids.forEach(function (id) { fd.append('family_ids[]', id); });
          if (roomPicked) fd.append('room_id', roomPicked);
          if (pre.childId && ids.length === 1) fd.append('child_id', pre.childId);
        }
        return fetch(apiBase() + base, { method: 'POST', headers: headers(), body: fd }).then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; });
        }).then(function (j) {
          toast('📎', 'Sent', j.files + ' file' + (j.files === 1 ? '' : 's') + (isParent ? ' to your provider' : ' to ' + j.families + ' famil' + (j.families === 1 ? 'y' : 'ies')), '#16A34A');
          state.box = 'sent';
          if (done) done();
        }).catch(function (e) { out.textContent = (e && e.message) || 'Could not send.'; return false; });
      } }],
    });
  }

  /* For other screens (the educator child record): open the send window with this family picked. */
  KT.sendFamilyFile = function (opts) { openSend(false, '/provider/files', function () { location.hash = '#files'; }, opts || {}); };

  function reg() {
    if (!(KT.Shell && KT.Shell.registerScreen)) { setTimeout(reg, 200); return; }
    ['guardian', 'educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'].forEach(function (r) {
      KT.Shell.registerScreen(r + ':files', function (main, ctx) { render(main, Object.assign({}, ctx || {}, { role: r })); });
    });
  }
  reg();
})(window);
