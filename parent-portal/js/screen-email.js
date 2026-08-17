/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Email client (Phase 2: read-UI shell, 2026-07-09).
   A 3-pane mail client (folders │ message list │ reading pane) modelled on the
   iLearn portal's email client, rebuilt natively for KiddieTrac's JS SPA.

   PHASE 2 = UI + interactions against STUB data. When the Graph backend (Phase 1)
   lands, replace loadFolders()/loadMessages()/loadBody() with the real API calls
   (GET /email/folders, /email/messages?folder=, /email/messages/{id}) — the UI
   and action wiring stay the same. Actions currently toast "coming soon".
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Shell = KT.Shell;
  var Dom = KT.Dom || {};
  function toast(msg, kind) { try { if (Dom.toast) return Dom.toast(msg, kind || 'info'); if (KT.toast) return KT.toast('✉️', msg, '', '#0E7C90'); } catch (e) {} }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  // ── STUB data (Phase 2). Replace with API calls in Phase 1. ─────────
  var FOLDERS = [
    { key: 'inbox', label: 'Inbox', icon: '📥', unread: 3, total: 12 },
    { key: 'starred', label: 'Starred', icon: '⭐', unread: 0, total: 2 },
    { key: 'sent', label: 'Sent', icon: '📤', unread: 0, total: 8 },
    { key: 'drafts', label: 'Drafts', icon: '📝', unread: 0, total: 1 },
    { key: 'junk', label: 'Junk', icon: '🚫', unread: 1, total: 4 },
    { key: 'trash', label: 'Trash', icon: '🗑️', unread: 0, total: 3 }
  ];
  function stubMessages(folder) {
    var base = [
      { id: 1, from: 'Maria Gonzalez', email: 'maria.g@example.com', subject: 'Field trip permission — Ava Thompson', preview: 'Hi, just confirming Ava can attend the aquarium trip on Friday. Please find the signed form attached.', date: '9:41 AM', unread: true, starred: false, hasAttach: true },
      { id: 2, from: 'Little Explorers Payroll', email: 'payroll@littleexplorers.ca', subject: 'Your July pay stub is ready', preview: 'Your electronic pay statement for the period ending July 5 is now available to view.', date: '8:12 AM', unread: true, starred: true, hasAttach: false },
      { id: 3, from: 'Ministry of Education', email: 'noreply@ontario.ca', subject: 'CWELCC quarterly reporting reminder', preview: 'This is a reminder that your Q2 CWELCC funding report is due by July 31. Log in to submit.', date: 'Yesterday', unread: true, starred: false, hasAttach: false },
      { id: 4, from: 'David Chen', email: 'david.chen@example.com', subject: 'Re: Waitlist for infant room', preview: 'Thank you for the update. We would love to keep our spot on the waitlist for September.', date: 'Yesterday', unread: false, starred: false, hasAttach: false },
      { id: 5, from: 'Sunny Meadows Childcare', email: 'admin@sunnymeadows.ca', subject: 'Staff meeting moved to Thursday', preview: 'Please note the monthly staff meeting has been moved to Thursday 3 PM in the main room.', date: 'Jul 6', unread: false, starred: false, hasAttach: false }
    ];
    if (folder === 'starred') return base.filter(function (m) { return m.starred; });
    if (folder === 'junk') return [{ id: 9, from: 'Big Savings Club', email: 'deals@promo.example', subject: 'You have won a $500 gift card!!!', preview: 'Claim your reward now before it expires. Click here to verify your details.', date: 'Jul 5', unread: true, starred: false, hasAttach: false }];
    if (folder === 'sent') return base.map(function (m) { return { id: m.id + 100, from: 'You', email: 'you@kiddietrac.com', subject: 'Re: ' + m.subject, preview: 'Thanks — noted and actioned on our end.', date: m.date, unread: false, starred: false, hasAttach: false }; });
    if (folder === 'inbox') return base;
    return base.slice(0, 2);
  }
  function stubBody(m) {
    return '<p>Hi there,</p><p>' + esc(m.preview) + '</p><p>This is a preview of the KiddieTrac email client. Once your Microsoft 365 mailbox is connected, your real messages will appear here with full formatting, attachments, reply, and forward.</p><p>Warm regards,<br>' + esc(m.from) + '</p>';
  }
  // Data layer: real IMAP when a mailbox is connected (STATE.account), else the
  // sample preview. The three callbacks may receive (data, errorString).
  function loadFolders(cb) {
    if (!STATE.account) { setTimeout(function () { cb(FOLDERS); }, 60); return; }
    api().get('/email/folders?account_id=' + STATE.account.id).then(function (r) {
      STATE.folders = (r && r.folders) || [];
      cb(STATE.folders);
    }).catch(function (e) { cb([], (e && e.message) || 'Could not load folders'); });
  }
  function loadMessages(folder, cb) {
    if (!STATE.account) { setTimeout(function () { cb(stubMessages(folder)); }, 120); return; }
    api().get('/email/messages?account_id=' + STATE.account.id + '&folder=' + encodeURIComponent(folder)).then(function (r) {
      const msgs = ((r && r.messages) || []).map(function (m) { m.id = m.uid; return m; });
      cb(msgs);
    }).catch(function (e) { cb([], (e && e.message) || 'Could not load messages'); });
  }
  function loadBody(m, cb) {
    if (!STATE.account) { setTimeout(function () { cb(stubBody(m), []); }, 120); return; }
    api().get('/email/messages/' + m.uid + '?account_id=' + STATE.account.id + '&folder=' + encodeURIComponent(STATE.folder)).then(function (r) {
      cb((r && r.html) || '<p style="color:#94A3B8;">(This message has no displayable content.)</p>', (r && r.attachments) || []);
    }).catch(function (e) { cb('<p style="color:#B91C1C;">Could not load message: ' + esc((e && e.message) || 'error') + '</p>', []); });
  }
  function fmtBytes(n) { n = +n || 0; if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(0) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
  function attachStrip(attachments) {
    if (!attachments || !attachments.length) return '';
    var iconFor = function (t) { t = (t || '').toLowerCase(); if (/pdf/.test(t)) return '📄'; if (/(jpe?g|png|gif|webp|image)/.test(t)) return '🖼️'; if (/(zip|rar|7z)/.test(t)) return '🗜️'; if (/(doc|word)/.test(t)) return '📝'; if (/(xls|sheet|csv)/.test(t)) return '📊'; return '📎'; };
    return '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;padding:10px 0;border-bottom:1px solid #EEF1F4;">'
      + attachments.map(function (a) {
        var dl = (STATE.account && a.section) ? ' class="kt-attach-chip" data-section="' + esc(a.section) + '" data-name="' + esc(a.name) + '" title="Download ' + esc(a.name) + '"' : ' title="' + esc(a.name) + '"';
        return '<span' + dl + ' style="display:inline-flex;align-items:center;gap:6px;background:#F1F5F8;border:1px solid #E2E8F0;border-radius:8px;padding:6px 10px;font-size:12px;color:#334155;max-width:220px;' + ((STATE.account && a.section) ? 'cursor:pointer;' : '') + '">'
          + iconFor(a.type) + '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(a.name) + '</span>'
          + '<span style="color:#94A3B8;flex-shrink:0;">' + fmtBytes(a.size) + '</span>' + ((STATE.account && a.section) ? '<span style="color:#0E7C90;flex-shrink:0;">⬇</span>' : '') + '</span>';
      }).join('') + '</div>';
  }
  // Attachments are binary (not JSON) + need the auth header, so fetch → blob → save.
  function downloadAttachment(uid, section, name) {
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var base = (KT && KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
    var url = base + '/email/messages/' + uid + '/attachment?account_id=' + STATE.account.id + '&folder=' + encodeURIComponent(STATE.folder) + '&section=' + encodeURIComponent(section);
    toast('Downloading ' + (name || 'attachment') + '…', 'info');
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (blob) {
        var u = URL.createObjectURL(blob);
        var a = document.createElement('a'); a.href = u; a.download = name || 'attachment';
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(u); a.remove(); }, 1500);
      }).catch(function (e) { toast('Could not download: ' + ((e && e.message) || 'error'), 'info'); });
  }

  var STATE = { folder: 'inbox', open: null, messages: [], account: null, folders: [] };
  function labelForFolder(key) {
    const list = STATE.account ? STATE.folders : FOLDERS;
    const f = list.filter(function (x) { return x.key === key; })[0];
    return f ? f.label : (STATE.account ? key : 'Inbox');
  }

  function styles() {
    if (document.getElementById('kt-email-style')) return;
    var s = document.createElement('style'); s.id = 'kt-email-style';
    s.textContent = [
      '.ec-root{display:grid;grid-template-columns:220px 300px 1fr;gap:0;height:calc(100vh - 150px);min-height:460px;background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}',
      '.ec-folders{border-right:1px solid #EEF1F4;padding:12px 10px;overflow:auto;background:#FAFBFC;}',
      '.ec-compose{width:100%;background:#0E7C90;color:#fff;border:none;border-radius:10px;padding:10px;font-weight:800;font-size:13px;cursor:pointer;margin-bottom:12px;box-shadow:0 6px 14px -6px rgba(14,124,144,.6);}',
      '.ec-folder{display:flex;align-items:center;gap:9px;width:100%;background:transparent;border:none;border-radius:9px;padding:8px 10px;cursor:pointer;font-size:13px;color:#334155;text-align:left;font-family:inherit;}',
      '.ec-folder:hover{background:#EEF2F5;}',
      '.ec-folder.on{background:#E1F0F3;color:#0C6070;font-weight:700;}',
      '.ec-folder .lbl{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-folder .cnt{font-size:11px;font-weight:800;background:#0E7C90;color:#fff;border-radius:9px;padding:1px 7px;min-width:18px;text-align:center;}',
      '.ec-sec{font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;margin:14px 6px 6px;}',
      '.ec-list{border-right:1px solid #EEF1F4;display:flex;flex-direction:column;min-width:0;}',
      '.ec-list-head{padding:11px 14px;border-bottom:1px solid #EEF1F4;display:flex;align-items:center;justify-content:space-between;gap:8px;}',
      '.ec-list-title{font-weight:800;font-size:14px;color:#0D1B2A;}',
      '.ec-search{border:1px solid #E5E7EB;border-radius:8px;padding:6px 10px;font-size:12px;width:130px;font-family:inherit;}',
      '.ec-list-scroll{overflow:auto;flex:1;}',
      '.ec-item{display:block;width:100%;text-align:left;border:none;border-bottom:1px solid #F3F4F6;background:#fff;padding:11px 14px;cursor:pointer;font-family:inherit;}',
      '.ec-item:hover{background:#F8FAFB;}',
      '.ec-item.on{background:#E1F0F3;}',
      '.ec-item.unread .ec-from,.ec-item.unread .ec-subj{font-weight:800;color:#0D1B2A;}',
      '.ec-item-top{display:flex;align-items:center;gap:6px;justify-content:space-between;}',
      '.ec-from{font-size:13px;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;}',
      '.ec-time{font-size:11px;color:#64748B;white-space:nowrap;flex-shrink:0;}',
      '.ec-subj{font-size:12.5px;color:#475569;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-prev{font-size:11.5px;color:#64748B;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-dot{width:8px;height:8px;border-radius:50%;background:#0E7C90;flex-shrink:0;}',
      '.ec-read{display:flex;flex-direction:column;min-width:0;}',
      '.ec-read-empty{flex:1;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:14px;flex-direction:column;gap:10px;padding:30px;text-align:center;}',
      '.ec-read-head{padding:16px 20px;border-bottom:1px solid #EEF1F4;}',
      '.ec-read-subject{font-size:18px;font-weight:800;color:#0D1B2A;margin:0 0 8px;}',
      '.ec-read-meta{display:flex;align-items:center;gap:10px;font-size:12.5px;color:#64748B;}',
      '.ec-avatar{width:38px;height:38px;border-radius:50%;background:#0E7C90;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;flex-shrink:0;}',
      '.ec-actions{display:flex;flex-wrap:wrap;gap:7px;padding:11px 20px;border-bottom:1px solid #EEF1F4;}',
      '.ec-btn{display:inline-flex;align-items:center;gap:5px;background:#F1F5F8;border:1px solid #E2E8F0;border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:600;color:#334155;cursor:pointer;font-family:inherit;}',
      '.ec-btn:hover{background:#E7EEF2;}',
      '.ec-btn.primary{background:#0E7C90;color:#fff;border-color:#0E7C90;}',
      '.ec-body{padding:20px;overflow:auto;flex:1;font-size:14px;line-height:1.6;color:#1F2937;}',
      '.ec-body p{margin:0 0 12px;}',
      '.ec-mb-back{display:none;}',
      '@media(max-width:900px){',
      '.ec-root{grid-template-columns:1fr;height:calc(100vh - 130px);}',
      '.ec-folders,.ec-list,.ec-read{display:none;}',
      '.ec-root[data-mpane="folders"] .ec-folders{display:block;}',
      '.ec-root[data-mpane="list"] .ec-list{display:flex;}',
      '.ec-root[data-mpane="read"] .ec-read{display:flex;}',
      '.ec-mb-back{display:inline-flex;align-items:center;gap:5px;background:transparent;border:none;color:#0E7C90;font-weight:700;font-size:13px;cursor:pointer;padding:4px 2px;font-family:inherit;}',
      '}'
    ].join('');
    document.head.appendChild(s);
  }

  function initials(name) { return (name || '?').split(/\s+/).map(function (p) { return p[0]; }).join('').slice(0, 2).toUpperCase(); }
  // Deterministic sender-avatar colour (same name → same colour), so the message
  // list reads like a real mail client instead of a flat wall of text.
  function ecAvatarColor(s) {
    var palette = ['#0E7C90', '#7C3AED', '#E91E8C', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#0f9d6b', '#DB2777'];
    var h = 0; s = String(s || '');
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return palette[h % palette.length];
  }
  function setMobilePane(root, pane) { if (window.matchMedia && window.matchMedia('(max-width:900px)').matches) root.setAttribute('data-mpane', pane); }

  function renderFolders(root, pane) {
    pane.innerHTML = '';
    var compose = document.createElement('button'); compose.className = 'ec-compose'; compose.textContent = '✏️  Compose';
    compose.addEventListener('click', function () { toast('Sending isn’t set up yet — you can read mail here; compose &amp; reply turn on once outbound email is configured.', 'info'); });
    pane.appendChild(compose);
    var mk = function (f) {
      var b = document.createElement('button'); b.className = 'ec-folder' + (f.key === STATE.folder ? ' on' : '');
      b.innerHTML = '<span>' + (f.icon || f.special || '📁') + '</span><span class="lbl">' + esc(f.label) + '</span>' + (f.unread ? '<span class="cnt">' + f.unread + '</span>' : '');
      b.addEventListener('click', function () { STATE.folder = f.key; STATE.open = null; renderFolders(root, pane); loadList(root); setMobilePane(root, 'list'); });
      return b;
    };
    loadFolders(function (folders, err) {
      if (err) { var e = document.createElement('div'); e.style.cssText = 'font-size:12px;color:#B91C1C;padding:8px 6px;'; e.textContent = err; pane.appendChild(e); return; }
      (folders || []).forEach(function (f) { pane.appendChild(mk(f)); });
      if (!STATE.account) {
        var sec = document.createElement('div'); sec.className = 'ec-sec'; sec.textContent = 'Custom folders'; pane.appendChild(sec);
        var nf = document.createElement('button'); nf.className = 'ec-folder'; nf.innerHTML = '<span>＋</span><span class="lbl">New folder</span>';
        nf.addEventListener('click', function () { toast('Custom folders arrive in a later phase.', 'info'); });
        pane.appendChild(nf);
      }
    });
  }

  function loadList(root) {
    var listScroll = root.querySelector('.ec-list-scroll');
    var listTitle = root.querySelector('.ec-list-title');
    var fLabel = labelForFolder(STATE.folder);
    if (listTitle) listTitle.textContent = fLabel;
    if (listScroll) listScroll.innerHTML = '<div style="padding:22px;color:#64748B;font-size:13px;">Loading…</div>';
    loadMessages(STATE.folder, function (msgs, err) {
      STATE.messages = msgs || [];
      if (!listScroll) return;
      if (err) { listScroll.innerHTML = '<div style="padding:24px;color:#B91C1C;font-size:12.5px;text-align:center;">' + esc(err) + '</div>'; return; }
      msgs = STATE.messages;
      listScroll.innerHTML = '';
      if (!msgs.length) { listScroll.innerHTML = '<div style="padding:26px;color:#64748B;font-size:13px;text-align:center;">No messages in ' + esc(fLabel) + '.</div>'; return; }
      msgs.forEach(function (m) {
        var it = document.createElement('button');
        it.className = 'ec-item' + (m.unread ? ' unread' : '') + (STATE.open && STATE.open.id === m.id ? ' on' : '');
        var _who = m.from || m.email || '?';
        it.innerHTML =
          '<div style="display:flex;gap:10px;align-items:flex-start;">' +
            '<span style="flex-shrink:0;width:36px;height:36px;border-radius:50%;background:' + ecAvatarColor(_who) + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;margin-top:1px;">' + esc(initials(_who)) + '</span>' +
            '<div style="flex:1;min-width:0;">' +
              '<div class="ec-item-top">' + (m.unread ? '<span class="ec-dot"></span>' : '') +
              '<span class="ec-from">' + (m.starred ? '⭐ ' : '') + esc(m.from) + '</span>' +
              '<span class="ec-time">' + esc(m.date) + '</span></div>' +
              '<div class="ec-subj">' + (m.hasAttach ? '📎 ' : '') + esc(m.subject) + '</div>' +
              (m.preview ? '<div class="ec-prev">' + esc(m.preview) + '</div>' : '') +
            '</div>' +
          '</div>';
        it.addEventListener('click', function () { openMessage(root, m); });
        listScroll.appendChild(it);
      });
    });
  }

  function openMessage(root, m) {
    STATE.open = m; m.unread = false;
    var read = root.querySelector('.ec-read');
    loadList(root); // refresh highlight + unread
    setMobilePane(root, 'read');
    read.innerHTML = '';
    var back = document.createElement('button'); back.className = 'ec-mb-back'; back.textContent = '‹ Back'; back.style.margin = '8px 0 0 12px';
    back.addEventListener('click', function () { setMobilePane(root, 'list'); });
    read.appendChild(back);

    var head = document.createElement('div'); head.className = 'ec-read-head';
    head.innerHTML = '<h2 class="ec-read-subject">' + esc(m.subject) + '</h2>' +
      '<div class="ec-read-meta"><div class="ec-avatar">' + initials(m.from) + '</div>' +
      '<div style="min-width:0;"><div style="font-weight:700;color:#0D1B2A;">' + esc(m.from) + ' <span style="font-weight:400;color:#64748B;">&lt;' + esc(m.email) + '&gt;</span></div>' +
      '<div style="font-size:11.5px;color:#64748B;">' + esc(m.date) + '</div></div></div>';
    read.appendChild(head);

    var acts = document.createElement('div'); acts.className = 'ec-actions';
    [['↩️ Reply', 'primary'], ['↩️ Reply all', ''], ['➡️ Forward', ''], [(m.starred ? '⭐ Unstar' : '☆ Star'), ''], ['🚫 Junk', ''], ['🗑️ Trash', ''], ['🗂️ Archive', '']].forEach(function (a) {
      var b = document.createElement('button'); b.className = 'ec-btn' + (a[1] ? ' ' + a[1] : ''); b.textContent = a[0];
      b.addEventListener('click', function () {
        if (/Star/.test(a[0])) {
          m.starred = !m.starred;
          // Persist to the real mailbox (IMAP \Flagged) when connected.
          if (STATE.account) api().post('/email/messages/' + m.uid + '/action', { account_id: STATE.account.id, folder: STATE.folder, action: m.starred ? 'star' : 'unstar' }).catch(function () {});
          openMessage(root, m); return;
        }
        toast(a[0].replace(/^[^ ]+ /, '') + ' isn’t available yet — this mailbox is read-only for now.', 'info');
      });
      acts.appendChild(b);
    });
    read.appendChild(acts);

    var body = document.createElement('div'); body.className = 'ec-body'; body.innerHTML = '<div style="color:#64748B;">Loading…</div>';
    read.appendChild(body);
    loadBody(m, function (html, attachments) {
      body.innerHTML = attachStrip(attachments) + html;
      [].forEach.call(body.querySelectorAll('.kt-attach-chip'), function (el) {
        el.addEventListener('click', function () { downloadAttachment(m.uid, el.getAttribute('data-section'), el.getAttribute('data-name')); });
      });
    });
  }

  function render(main) {
    styles();
    main.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'padding:16px 20px;max-width:1500px;margin:0 auto;';
    // No hand-rolled banner. A custom .kt-page-hero here used an off-brand teal
    // gradient that didn't match the rest of the platform — the shell adds the
    // standard, consistent auto-hero (using the nav label + icon) for this screen.
    wrap.innerHTML = '';

    var note = document.createElement('div');
    note.style.cssText = 'margin:12px 0;padding:10px 14px;background:#FEF6E7;border:1px solid #F6D98A;border-radius:10px;color:#8A6100;font-size:12.5px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;';
    var noteTxt = document.createElement('div'); noteTxt.style.flex = '1';
    noteTxt.innerHTML = '⚙️ <strong>Preview.</strong> Sample messages shown below. Connect a mailbox to set up your signature and out-of-office — live mail arrives once your mailbox sync is enabled.';
    var noteBtn = document.createElement('button'); noteBtn.className = 'ec-btn primary'; noteBtn.textContent = '✉️ Manage accounts'; noteBtn.style.cssText += 'flex-shrink:0;';
    noteBtn.addEventListener('click', function () { openAccounts(); });
    note.appendChild(noteTxt); note.appendChild(noteBtn);
    wrap.appendChild(note);

    var root = document.createElement('div'); root.className = 'ec-root';
    // mobile toolbar (back to folders/list)
    root.innerHTML =
      '<aside class="ec-folders"></aside>' +
      '<div class="ec-list">' +
      '<div class="ec-list-head"><button class="ec-mb-back" data-to="folders">☰ Folders</button>' +
      '<span class="ec-list-title">Inbox</span>' +
      '<input class="ec-search" placeholder="Search…"></div>' +
      '<div class="ec-list-scroll"></div></div>' +
      '<section class="ec-read"><div class="ec-read-empty"><div style="font-size:40px;">✉️</div><div>Select a message to read it here.</div></div></section>';
    wrap.appendChild(root);
    main.appendChild(wrap);

    root.querySelector('[data-to="folders"]').addEventListener('click', function () { setMobilePane(root, 'folders'); });
    root.querySelector('.ec-search').addEventListener('input', function (e) {
      var q = e.target.value.toLowerCase();
      [].forEach.call(root.querySelectorAll('.ec-item'), function (it) { it.style.display = it.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
    });

    function bootMail() {
      renderFolders(root, root.querySelector('.ec-folders'));
      loadList(root);
      setMobilePane(root, 'list');
    }
    // Boot: switch to a LIVE connected mailbox if one exists; else sample preview.
    api().get('/email/accounts').then(function (r) {
      var accts = (r && r.accounts) || [];
      var pick = accts.filter(function (a) { return a.is_default; })[0]
        || accts.filter(function (a) { return a.status === 'connected'; })[0]
        || accts[0];
      if (pick) {
        STATE.account = pick; STATE.folder = 'INBOX';
        noteTxt.innerHTML = '📬 <strong>' + esc(pick.display_name || pick.email_address) + '</strong> · live mailbox';
        note.style.background = '#F0FAFC'; note.style.borderColor = '#CFE8EE'; note.style.color = '#0C6070';
        if (accts.length > 1) {
          var sw = document.createElement('select'); sw.style.cssText = 'padding:6px 8px;border:1px solid #CFE8EE;border-radius:8px;font-size:12px;background:#fff;';
          accts.forEach(function (a) { var o = document.createElement('option'); o.value = a.id; o.textContent = a.display_name || a.email_address; if (a.id === pick.id) o.selected = true; sw.appendChild(o); });
          sw.addEventListener('change', function () { STATE.account = accts.filter(function (a) { return String(a.id) === sw.value; })[0]; STATE.folder = 'INBOX'; STATE.open = null; bootMail(); });
          note.insertBefore(sw, noteBtn);
        }
      }
      bootMail();
    }).catch(function () { bootMail(); });
  }

  // ══════════════════════════════════════════════════════════════════
  //  ACCOUNT SETUP WIZARD + account manager (multi-account, signature, OOO)
  //  Talks to the real backend: /email/accounts (CRUD + /test).
  // ══════════════════════════════════════════════════════════════════
  function api() { return KT.Api || window.Api; }
  var PROVIDERS = [
    { key: 'microsoft', label: 'Microsoft 365 / Outlook', icon: '🟦', hint: 'outlook.com, office365, hosted Exchange' },
    { key: 'google', label: 'Google Workspace / Gmail', icon: '🔴', hint: 'gmail.com or a Google-hosted domain' },
    { key: 'imap', label: 'IMAP / SMTP', icon: '✉️', hint: 'Any other provider — enter server settings' },
    { key: 'other', label: 'Other', icon: '⚙️', hint: 'Manual configuration' }
  ];
  function providerLabel(k) { var p = PROVIDERS.filter(function (x) { return x.key === k; })[0]; return p ? p.label : k; }
  function fld(label, el) {
    var w = document.createElement('div'); w.style.cssText = 'margin-bottom:12px;';
    var l = document.createElement('label'); l.style.cssText = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:5px;'; l.textContent = label;
    w.appendChild(l); w.appendChild(el); return w;
  }
  function inp(val, ph, type) {
    var i = document.createElement('input'); i.type = type || 'text'; if (ph) i.placeholder = ph; if (val != null) i.value = val;
    i.style.cssText = 'width:100%;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13.5px;box-sizing:border-box;font-family:inherit;';
    return i;
  }
  function statusBadge(s) {
    var map = { connected: ['#DCFCE7', '#166534', '● Connected'], error: ['#FEE2E2', '#B91C1C', '● Error'], pending: ['#FEF3C7', '#92400E', '● Not tested'] };
    var c = map[s] || map.pending;
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;white-space:nowrap;">' + c[2] + '</span>';
  }

  // ── Account manager modal ──
  function openAccounts() {
    var body = document.createElement('div');
    body.innerHTML = '<div style="color:#64748B;font-size:13px;padding:8px;">Loading accounts…</div>';
    Shell.Modal.open({ title: '✉️ Email accounts', body: body, large: true });
    refreshAccounts(body);
  }
  function refreshAccounts(body) {
    api().get('/email/accounts').then(function (r) {
      var accts = (r && r.accounts) || [];
      body.innerHTML = '';
      var intro = document.createElement('div');
      intro.style.cssText = 'font-size:12.5px;color:#64748B;margin:2px 0 14px;line-height:1.5;';
      intro.textContent = 'Connect one or more mailboxes to send and receive from within KiddieTrac. Each account has its own signature and out-of-office reply.';
      body.appendChild(intro);
      if (!accts.length) {
        var empty = document.createElement('div');
        empty.style.cssText = 'text-align:center;color:#64748B;font-size:13px;padding:24px;border:1.5px dashed #CBD5E1;border-radius:12px;margin-bottom:14px;';
        empty.innerHTML = '<div style="font-size:34px;margin-bottom:8px;">📭</div>No accounts connected yet.';
        body.appendChild(empty);
      }
      accts.forEach(function (a) { body.appendChild(accountCard(a, body)); });
      var add = document.createElement('button');
      add.className = 'ec-btn primary'; add.textContent = '＋ Add account'; add.style.cssText += 'margin-top:6px;padding:10px 16px;font-size:13.5px;';
      add.addEventListener('click', function () { openWizard(body); });
      body.appendChild(add);
    }).catch(function () { body.innerHTML = '<div style="color:#B91C1C;padding:10px;">Could not load accounts.</div>'; });
  }
  function accountCard(a, body) {
    var card = document.createElement('div');
    card.style.cssText = 'border:1px solid #E5E7EB;border-radius:12px;padding:13px 15px;margin-bottom:10px;display:flex;align-items:center;gap:12px;';
    card.innerHTML =
      '<div class="ec-avatar" style="background:#0E7C90;">' + esc(initials(a.display_name || a.email_address)) + '</div>' +
      '<div style="flex:1;min-width:0;">' +
        '<div style="font-weight:800;color:#0D1B2A;font-size:14px;">' + esc(a.display_name || a.email_address) + (a.is_default ? ' <span title="Default" style="color:#F59E0B;">★</span>' : '') + '</div>' +
        '<div style="font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(a.email_address) + ' · ' + esc(providerLabel(a.provider)) + '</div>' +
        '<div style="margin-top:5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' + statusBadge(a.status) +
          (a.ooo_enabled ? '<span style="background:#EDE9FE;color:#6D28D9;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;">🌴 Out of office</span>' : '') + '</div>' +
      '</div>';
    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;flex-direction:column;gap:6px;flex-shrink:0;';
    var testB = document.createElement('button'); testB.className = 'ec-btn'; testB.textContent = 'Test'; testB.style.padding = '6px 12px';
    testB.addEventListener('click', function () {
      testB.textContent = 'Testing…'; testB.disabled = true;
      api().post('/email/accounts/' + a.id + '/test', {}).then(function (res) {
        toast(res && res.ok ? 'Connection reachable ✓' : 'Some servers unreachable — check settings.', res && res.ok ? 'success' : 'info');
        refreshAccounts(body);
      }).catch(function () { testB.textContent = 'Test'; testB.disabled = false; toast('Test failed.', 'info'); });
    });
    var editB = document.createElement('button'); editB.className = 'ec-btn'; editB.textContent = 'Settings'; editB.style.padding = '6px 12px';
    editB.addEventListener('click', function () { openAccountSettings(a, body); });
    actions.appendChild(testB); actions.appendChild(editB);
    card.appendChild(actions);
    return card;
  }

  // ── Add-account wizard (2 steps: provider → details) ──
  function openWizard(managerBody) {
    var state = { step: 0, provider: null };
    var body = document.createElement('div');
    Shell.Modal.open({ title: '✉️ Add an email account', body: body, large: true });
    drawWizard();

    function drawWizard() {
      body.innerHTML = '';
      if (state.step === 0) {
        var h = document.createElement('div'); h.style.cssText = 'font-size:13px;color:#475569;margin-bottom:12px;'; h.textContent = 'Which kind of mailbox are you connecting?';
        body.appendChild(h);
        PROVIDERS.forEach(function (p) {
          var b = document.createElement('button');
          b.style.cssText = 'display:flex;align-items:center;gap:12px;width:100%;text-align:left;border:1.5px solid #E2E8F0;border-radius:12px;padding:13px 15px;margin-bottom:9px;background:#fff;cursor:pointer;font-family:inherit;';
          b.innerHTML = '<span style="font-size:22px;">' + p.icon + '</span><span style="flex:1;"><span style="display:block;font-weight:800;color:#0D1B2A;font-size:14px;">' + esc(p.label) + '</span><span style="display:block;font-size:12px;color:#64748B;margin-top:2px;">' + esc(p.hint) + '</span></span><span style="color:#94A3B8;">›</span>';
          b.addEventListener('mouseenter', function () { b.style.borderColor = '#0E7C90'; b.style.background = '#F0FAFC'; });
          b.addEventListener('mouseleave', function () { b.style.borderColor = '#E2E8F0'; b.style.background = '#fff'; });
          b.addEventListener('click', function () { state.provider = p.key; state.step = 1; drawWizard(); });
          body.appendChild(b);
        });
      } else {
        drawDetails();
      }
    }

    function drawDetails() {
      var manual = state.provider === 'imap' || state.provider === 'other';
      var back = document.createElement('button'); back.className = 'ec-btn'; back.textContent = '‹ Back'; back.style.marginBottom = '12px';
      back.addEventListener('click', function () { state.step = 0; drawWizard(); });
      body.appendChild(back);

      var chip = document.createElement('div'); chip.style.cssText = 'font-size:12.5px;color:#0C6070;font-weight:700;margin-bottom:12px;';
      chip.textContent = 'Connecting: ' + providerLabel(state.provider);
      body.appendChild(chip);

      var name = inp('', 'e.g. Front Office (optional)');
      var email = inp('', 'you@yourcentre.com', 'email');
      var user = inp('', 'Usually the same as the email');
      var pass = inp('', manual ? 'Mailbox password' : 'Password or app password', 'password');
      body.appendChild(fld('Display name', name));
      body.appendChild(fld('Email address *', email));
      body.appendChild(fld('Username', user));
      body.appendChild(fld('Password *', pass));

      var imapHost, imapPort, imapEnc, smtpHost, smtpPort, smtpEnc;
      if (manual) {
        var grid = document.createElement('div'); grid.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;';
        imapHost = inp('', 'imap.yourhost.com'); imapPort = inp('993', 'Port'); imapEnc = selEnc('ssl');
        grid.appendChild(fld('IMAP host', imapHost)); grid.appendChild(fld('Port', imapPort)); grid.appendChild(fld('Security', imapEnc));
        body.appendChild(grid);
        var grid2 = document.createElement('div'); grid2.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;';
        smtpHost = inp('', 'smtp.yourhost.com'); smtpPort = inp('587', 'Port'); smtpEnc = selEnc('tls');
        grid2.appendChild(fld('SMTP host', smtpHost)); grid2.appendChild(fld('Port', smtpPort)); grid2.appendChild(fld('Security', smtpEnc));
        body.appendChild(grid2);
      } else {
        var pre = document.createElement('div'); pre.style.cssText = 'font-size:12px;color:#64748B;background:#F8FAFC;border:1px solid #EEF1F4;border-radius:9px;padding:9px 12px;margin-bottom:12px;';
        pre.textContent = state.provider === 'microsoft'
          ? 'Server settings are pre-filled for Microsoft 365 (outlook.office365.com / smtp.office365.com).'
          : 'Server settings are pre-filled for Google (imap.gmail.com / smtp.gmail.com). Use an app password if 2-step verification is on.';
        body.appendChild(pre);
      }

      var status = document.createElement('div'); status.style.cssText = 'font-size:12.5px;min-height:18px;margin:6px 0;color:#B91C1C;';
      body.appendChild(status);
      var save = document.createElement('button'); save.className = 'ec-btn primary'; save.textContent = 'Connect account'; save.style.cssText += 'padding:10px 18px;font-size:13.5px;';
      save.addEventListener('click', function () {
        var em = email.value.trim();
        if (!em || em.indexOf('@') < 1) { status.textContent = 'A valid email address is required.'; email.focus(); return; }
        if (!pass.value) { status.textContent = 'A password is required to connect.'; pass.focus(); return; }
        var payload = { email_address: em, provider: state.provider, display_name: name.value.trim() || null, username: user.value.trim() || em, secret: pass.value };
        if (manual) {
          payload.imap_host = imapHost.value.trim(); payload.imap_port = parseInt(imapPort.value, 10) || null; payload.imap_encryption = imapEnc.value;
          payload.smtp_host = smtpHost.value.trim(); payload.smtp_port = parseInt(smtpPort.value, 10) || null; payload.smtp_encryption = smtpEnc.value;
        }
        save.disabled = true; save.textContent = 'Connecting…'; status.textContent = '';
        api().post('/email/accounts', payload).then(function (r) {
          var id = r && r.account && r.account.id;
          // Immediately run a reachability test so the card shows a live status.
          if (id) { api().post('/email/accounts/' + id + '/test', {}).catch(function () {}).then(function () { Shell.Modal.close(); openAccounts(); }); }
          else { Shell.Modal.close(); openAccounts(); }
          toast('Account added.', 'success');
        }).catch(function (e) { save.disabled = false; save.textContent = 'Connect account'; status.textContent = 'Could not add: ' + ((e && e.message) || 'try again'); });
      });
      body.appendChild(save);
    }
  }
  function selEnc(val) {
    var s = document.createElement('select'); s.style.cssText = 'width:100%;padding:9px 8px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13px;background:#fff;font-family:inherit;';
    [['ssl', 'SSL'], ['tls', 'STARTTLS'], ['none', 'None']].forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (o[0] === val) op.selected = true; s.appendChild(op); });
    return s;
  }

  // ── Account settings (signature, out-of-office, default, servers, delete) ──
  function openAccountSettings(a, managerBody) {
    var body = document.createElement('div');
    Shell.Modal.open({ title: 'Settings — ' + (a.display_name || a.email_address), body: body, large: true });

    // Signature
    body.appendChild(sectionTitle('Signature'));
    var sig = document.createElement('textarea');
    sig.style.cssText = 'width:100%;min-height:90px;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box;resize:vertical;';
    sig.value = a.signature_html || '';
    sig.placeholder = 'e.g. Warm regards,\nThe Front Office\nYour Child Care';
    body.appendChild(sig);
    var sigOn = mkToggle('Append my signature to new messages', a.signature_enabled !== false);
    body.appendChild(sigOn.wrap);

    // Out of office
    body.appendChild(sectionTitle('Out of office / auto-reply'));
    var oooOn = mkToggle('Send automatic replies', !!a.ooo_enabled);
    body.appendChild(oooOn.wrap);
    var oooSubj = inp(a.ooo_subject || '', 'Subject — e.g. Out of office');
    var oooMsg = document.createElement('textarea');
    oooMsg.style.cssText = 'width:100%;min-height:70px;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:9px;font-size:13px;font-family:inherit;box-sizing:border-box;resize:vertical;';
    oooMsg.value = a.ooo_message || ''; oooMsg.placeholder = 'I’m away and will reply when I return…';
    var oooDates = document.createElement('div'); oooDates.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:8px;';
    var oooStart = inp((a.ooo_start || '').slice(0, 10), '', 'date'); var oooEnd = inp((a.ooo_end || '').slice(0, 10), '', 'date');
    oooDates.appendChild(fld('From (optional)', oooStart)); oooDates.appendChild(fld('Until (optional)', oooEnd));
    body.appendChild(fld('Subject', oooSubj)); body.appendChild(fld('Message', oooMsg)); body.appendChild(oooDates);

    // Default account
    body.appendChild(sectionTitle('Preferences'));
    var defOn = mkToggle('Use this as my default sending account', !!a.is_default);
    body.appendChild(defOn.wrap);

    var status = document.createElement('div'); status.style.cssText = 'font-size:12.5px;min-height:18px;margin:8px 0;';
    body.appendChild(status);
    var row = document.createElement('div'); row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;margin-top:6px;';
    var del = document.createElement('button'); del.className = 'ec-btn'; del.textContent = '🗑️ Remove account'; del.style.color = '#B91C1C'; del.style.borderColor = '#FECACA';
    del.addEventListener('click', function () {
      if (del.dataset.armed !== '1') { del.dataset.armed = '1'; del.textContent = 'Click again to remove'; return; }
      api().delete('/email/accounts/' + a.id).then(function () { Shell.Modal.close(); openAccounts(); toast('Account removed.', 'info'); })
        .catch(function () { toast('Could not remove.', 'info'); });
    });
    var save = document.createElement('button'); save.className = 'ec-btn primary'; save.textContent = 'Save settings'; save.style.padding = '9px 18px';
    save.addEventListener('click', function () {
      save.disabled = true; save.textContent = 'Saving…'; status.textContent = '';
      var payload = {
        signature_html: sig.value, signature_enabled: sigOn.on(),
        ooo_enabled: oooOn.on(), ooo_subject: oooSubj.value.trim() || null, ooo_message: oooMsg.value.trim() || null,
        ooo_start: oooStart.value || null, ooo_end: oooEnd.value || null, is_default: defOn.on()
      };
      api().patch('/email/accounts/' + a.id, payload).then(function () {
        status.style.color = '#16A34A'; status.textContent = '✓ Saved.';
        // Modals replace (not stack), so reopen the manager fresh rather than
        // refreshing the now-detached manager body.
        setTimeout(function () { openAccounts(); }, 450);
      }).catch(function (e) { save.disabled = false; save.textContent = 'Save settings'; status.style.color = '#B91C1C'; status.textContent = 'Could not save: ' + ((e && e.message) || 'try again'); });
    });
    row.appendChild(del); row.appendChild(save);
    body.appendChild(row);
  }
  function sectionTitle(t) { var d = document.createElement('div'); d.style.cssText = 'font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0C6070;margin:16px 0 8px;'; d.textContent = t; return d; }
  function mkToggle(label, on) {
    var wrap = document.createElement('label'); wrap.style.cssText = 'display:flex;align-items:center;gap:9px;margin:8px 0;cursor:pointer;font-size:13px;color:#334155;';
    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!on; cb.style.cssText = 'width:17px;height:17px;';
    var sp = document.createElement('span'); sp.textContent = label;
    wrap.appendChild(cb); wrap.appendChild(sp);
    return { wrap: wrap, on: function () { return cb.checked; } };
  }

  KT.EmailClient = { render: render, openAccounts: openAccounts };
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) {
      Shell.registerScreen(r + ':email', render);
    });
  }
})(window);
