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
  // API stubs — swap these three for real fetches in Phase 1.
  function loadFolders(cb) { setTimeout(function () { cb(FOLDERS); }, 60); }
  function loadMessages(folder, cb) { setTimeout(function () { cb(stubMessages(folder)); }, 120); }
  function loadBody(m, cb) { setTimeout(function () { cb(stubBody(m)); }, 120); }

  var STATE = { folder: 'inbox', open: null, messages: [] };

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
      '.ec-sec{font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#94A3B8;margin:14px 6px 6px;}',
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
      '.ec-time{font-size:11px;color:#94A3B8;white-space:nowrap;flex-shrink:0;}',
      '.ec-subj{font-size:12.5px;color:#475569;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-prev{font-size:11.5px;color:#94A3B8;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-dot{width:8px;height:8px;border-radius:50%;background:#0E7C90;flex-shrink:0;}',
      '.ec-read{display:flex;flex-direction:column;min-width:0;}',
      '.ec-read-empty{flex:1;display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:14px;flex-direction:column;gap:10px;padding:30px;text-align:center;}',
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
  function setMobilePane(root, pane) { if (window.matchMedia && window.matchMedia('(max-width:900px)').matches) root.setAttribute('data-mpane', pane); }

  function renderFolders(root, pane) {
    pane.innerHTML = '';
    var compose = document.createElement('button'); compose.className = 'ec-compose'; compose.textContent = '✏️  Compose';
    compose.addEventListener('click', function () { toast('Compose arrives in Phase 4 (send/reply/forward).', 'info'); });
    pane.appendChild(compose);
    var mk = function (f) {
      var b = document.createElement('button'); b.className = 'ec-folder' + (f.key === STATE.folder ? ' on' : '');
      b.innerHTML = '<span>' + f.icon + '</span><span class="lbl">' + esc(f.label) + '</span>' + (f.unread ? '<span class="cnt">' + f.unread + '</span>' : '');
      b.addEventListener('click', function () { STATE.folder = f.key; STATE.open = null; renderFolders(root, pane); loadList(root); setMobilePane(root, 'list'); });
      return b;
    };
    loadFolders(function (folders) {
      folders.forEach(function (f) { pane.appendChild(mk(f)); });
      var sec = document.createElement('div'); sec.className = 'ec-sec'; sec.textContent = 'Custom folders'; pane.appendChild(sec);
      var nf = document.createElement('button'); nf.className = 'ec-folder'; nf.innerHTML = '<span>＋</span><span class="lbl">New folder</span>';
      nf.addEventListener('click', function () { toast('Custom folders arrive in Phase 5.', 'info'); });
      pane.appendChild(nf);
    });
  }

  function loadList(root) {
    var listScroll = root.querySelector('.ec-list-scroll');
    var listTitle = root.querySelector('.ec-list-title');
    var fLabel = (FOLDERS.filter(function (f) { return f.key === STATE.folder; })[0] || {}).label || 'Inbox';
    if (listTitle) listTitle.textContent = fLabel;
    if (listScroll) listScroll.innerHTML = '<div style="padding:22px;color:#94A3B8;font-size:13px;">Loading…</div>';
    loadMessages(STATE.folder, function (msgs) {
      STATE.messages = msgs;
      if (!listScroll) return;
      listScroll.innerHTML = '';
      if (!msgs.length) { listScroll.innerHTML = '<div style="padding:26px;color:#94A3B8;font-size:13px;text-align:center;">No messages in ' + esc(fLabel) + '.</div>'; return; }
      msgs.forEach(function (m) {
        var it = document.createElement('button');
        it.className = 'ec-item' + (m.unread ? ' unread' : '') + (STATE.open && STATE.open.id === m.id ? ' on' : '');
        it.innerHTML =
          '<div class="ec-item-top">' + (m.unread ? '<span class="ec-dot"></span>' : '') +
          '<span class="ec-from">' + (m.starred ? '⭐ ' : '') + esc(m.from) + '</span>' +
          '<span class="ec-time">' + esc(m.date) + '</span></div>' +
          '<div class="ec-subj">' + (m.hasAttach ? '📎 ' : '') + esc(m.subject) + '</div>' +
          '<div class="ec-prev">' + esc(m.preview) + '</div>';
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
      '<div style="min-width:0;"><div style="font-weight:700;color:#0D1B2A;">' + esc(m.from) + ' <span style="font-weight:400;color:#94A3B8;">&lt;' + esc(m.email) + '&gt;</span></div>' +
      '<div style="font-size:11.5px;color:#94A3B8;">' + esc(m.date) + '</div></div></div>';
    read.appendChild(head);

    var acts = document.createElement('div'); acts.className = 'ec-actions';
    [['↩️ Reply', 'primary'], ['↩️ Reply all', ''], ['➡️ Forward', ''], [(m.starred ? '⭐ Unstar' : '☆ Star'), ''], ['🚫 Junk', ''], ['🗑️ Trash', ''], ['🗂️ Archive', '']].forEach(function (a) {
      var b = document.createElement('button'); b.className = 'ec-btn' + (a[1] ? ' ' + a[1] : ''); b.textContent = a[0];
      b.addEventListener('click', function () {
        if (/Star/.test(a[0])) { m.starred = !m.starred; openMessage(root, m); return; }
        toast(a[0].replace(/^[^ ]+ /, '') + ' — wired up in Phase 3–4.', 'info');
      });
      acts.appendChild(b);
    });
    read.appendChild(acts);

    var body = document.createElement('div'); body.className = 'ec-body'; body.innerHTML = '<div style="color:#94A3B8;">Loading…</div>';
    read.appendChild(body);
    loadBody(m, function (html) { body.innerHTML = html; });
  }

  function render(main) {
    styles();
    main.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'padding:16px 20px;max-width:1500px;margin:0 auto;';
    wrap.innerHTML =
      '<div class="kt-page-hero kt-banner-fx" style="background:linear-gradient(135deg,#0E5A6B 0%,#0E7C90 55%,#3BBBBE 100%);">' +
      '<div class="kt-hero-greet">📧 MAILBOX</div><h1>Email</h1>' +
      '<div class="kt-hero-sub">Your Microsoft 365 inbox, right inside KiddieTrac.</div></div>';

    var note = document.createElement('div');
    note.style.cssText = 'margin:12px 0;padding:9px 14px;background:#FEF6E7;border:1px solid #F6D98A;border-radius:10px;color:#8A6100;font-size:12.5px;';
    note.innerHTML = '⚙️ <strong>Preview.</strong> This is the email experience — showing sample messages. It goes live once your Microsoft 365 mailbox is connected (Phase 1).';
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

    renderFolders(root, root.querySelector('.ec-folders'));
    loadList(root);
    setMobilePane(root, 'list');
  }

  KT.EmailClient = { render: render };
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) {
      Shell.registerScreen(r + ':email', render);
    });
  }
})(window);
